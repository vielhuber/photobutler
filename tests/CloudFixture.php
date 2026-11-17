<?php
declare(strict_types=1);

use vielhuber\photobutler\OneDriveClient;
use vielhuber\photobutler\PhotoButler;

final class FixtureOneDriveClient extends OneDriveClient
{
    public array $pages = [];
    public array $downloads = [];
    public array $requests = [];
    public ?int $failDownload = null;
    public string $jpeg;
    public string $defaultJpeg;
    public array $missingThumbnails = [];
    public array $invalidThumbnails = [];

    public function get(string $path): array
    {
        $this->requests[] = $path;
        $page = array_shift($this->pages);
        if ($page instanceof RuntimeException) {
            throw $page;
        }
        if (!is_array($page)) {
            throw new RuntimeException('Unexpected fixture request');
        }
        return $page;
    }

    public function thumbnails(string $drive, array $photos, ?callable $progress = null): array
    {
        $results = [];
        foreach ($photos as $id => $photo) {
            $results[$id] = $this->thumbnail(
                $drive,
                $photo['item'],
                $photo['target'],
                $photo['bytes'],
                $photo['version']
            );
            if ($progress !== null) {
                $progress($id, $results[$id]);
            }
        }
        return $results;
    }

    public function thumbnail(string $drive, string $item, string $target, int $bytes, string $version): ?bool
    {
        $this->downloads[] = $item;
        if ($this->failDownload === count($this->downloads)) {
            throw new RuntimeException('Fixture transfer interrupted');
        }
        if (in_array($item, $this->missingThumbnails, true)) {
            return null;
        }
        if (in_array($item, $this->invalidThumbnails, true)) {
            return false;
        }
        $jpeg = $this->jpeg;
        if ($jpeg === $this->defaultJpeg) {
            $image = imagecreatetruecolor(width: 180, height: 160);
            for ($cell = 0; $cell < 72; $cell++) {
                $shade = ord(hash('sha256', $item . ':' . $cell, binary: true)[0]);
                $x = ($cell % 9) * 20;
                $y = intdiv($cell, num2: 9) * 20;
                imagefilledrectangle(
                    $image,
                    $x,
                    $y,
                    $x + 19,
                    $y + 19,
                    imagecolorallocate($image, $shade, $shade, $shade)
                );
            }
            ob_start();
            imagejpeg($image);
            $jpeg = ob_get_clean();
        }
        file_put_contents($target, $jpeg);
        return true;
    }
}

trait CloudFixture
{
    private string $root;
    private PhotoButler $library;
    private FixtureOneDriveClient $client;

    private function createCloudLibrary(string $settings = ''): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.data', 0700, true);
        file_put_contents(
            $this->root . '/.data/.env',
            "ONEDRIVE_CLIENT_ID=fixture\nONEDRIVE_FOLDER=FOTOS\n" . $settings
        );
        file_put_contents(
            $this->root . '/.data/onedrive-source.json',
            json_encode(['drive' => 'drive', 'folder' => 'root', 'folder_path' => 'FOTOS', 'client_id' => 'fixture'])
        );
        $this->client = new FixtureOneDriveClient($this->root . '/.data', 'fixture');
        ob_start();
        imagejpeg(imagecreatetruecolor(16, 12));
        $this->client->jpeg = ob_get_clean();
        $this->client->defaultJpeg = $this->client->jpeg;
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
    }

    private function removeCloudLibrary(): void
    {
        unset($this->library);
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            )
            as $file
        ) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function item(
        string $id,
        string $name = 'photo.jpg',
        string $parent = 'root',
        string $version = 'v1',
        string $modified = '2026-09-01T12:00:00Z',
        bool $exif = true
    ): array {
        $item = [
            'id' => $id,
            'name' => $name,
            'parentReference' => ['id' => $parent],
            'file' => ['mimeType' => 'image/jpeg'],
            'size' => strlen($this->client->jpeg),
            'lastModifiedDateTime' => $modified,
            'cTag' => $version
        ];
        if ($exif) {
            $item['photo'] = ['takenDateTime' => $modified];
        }
        return $item;
    }

    private function folder(string $id, string $name, string $parent = 'root'): array
    {
        return ['id' => $id, 'name' => $name, 'folder' => [], 'parentReference' => ['id' => $parent]];
    }

    private function index(array $items): void
    {
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => []], ...$items],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $this->library->index();
    }

    /**
     * Serve model responses or HTTP failures and retain every chat payload.
     */
    private function withGateway(string $answer, callable $callback, int $status = 200): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, remote: false);
        fclose($socket);
        $router = $this->root . '/gateway.php';
        file_put_contents(
            $router,
            <<<'PHP'
            <?php
            declare(strict_types=1);
            header('Content-Type: application/json');
            if (str_ends_with($_SERVER['REQUEST_URI'], '/models')) {
                echo json_encode(['data' => [['id' => 'fixture', 'name' => 'fixture', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'pricing' => ['prompt' => '0', 'completion' => '0']]]]);
                return;
            }
            http_response_code((int) file_get_contents(__DIR__ . '/status.txt'));
            file_put_contents(__DIR__ . '/payload-' . hrtime(true) . '.json', file_get_contents('php://input'));
            if (http_response_code() >= 400) {
                echo file_get_contents(__DIR__ . '/answer.txt');
                return;
            }
            echo json_encode(['id' => 'fixture', 'model' => 'fixture', 'choices' => [['message' => ['role' => 'assistant', 'content' => file_get_contents(__DIR__ . '/answer.txt')], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]]);
            PHP
        );
        file_put_contents($this->root . '/answer.txt', $answer);
        file_put_contents($this->root . '/status.txt', (string) $status);
        file_put_contents($this->root . '/.data/.env', "\nAI_BASE_URL=http://" . $address . "/v1\n", FILE_APPEND);
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [1 => ['file', $this->root . '/gateway.log', 'w'], 2 => ['file', $this->root . '/gateway.log', 'a']],
            $pipes
        );
        try {
            $handle = curl_init('http://' . $address . '/v1/models');
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, value: true);
            curl_setopt($handle, CURLOPT_TIMEOUT, value: 1);
            for ($attempt = 0; $attempt < 100 && curl_exec($handle) === false; $attempt++) {
                usleep(10_000);
            }
            $callback();
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
