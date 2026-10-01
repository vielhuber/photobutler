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
        file_put_contents($target, $this->jpeg);
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
}
