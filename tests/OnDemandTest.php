<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class OnDemandTest extends TestCase
{
    use CloudFixture;

    protected function setUp(): void
    {
        $this->createCloudLibrary(
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=fixture\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=fixture\n"
        );
        $this->index([$this->item('a', 'a.jpg')]);
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
    }

    public function testMissingThumbnailsNeverCauseAnalysisToLoadOriginals(): void
    {
        $this->assertSame(0, $this->library->tag(1));
        $this->assertSame(0, $this->library->tagFaces(1));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
        $this->assertSame([], $this->client->downloads);
        $this->assertSame('error', $this->library->photo(1)->status);
        $this->assertSame('error', $this->library->photo(1)->face_status);
    }

    public function testFaceProcessReceivesCachedThumbnailEvenWhenOriginalIsAbsent(): void
    {
        $this->library->oneDrive->previews([1]);
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        mkdir($this->root . '/.data/face-runtime/packages/cv2', 0700, true);
        mkdir($this->root . '/.data/face-runtime/models', 0700);
        mkdir($this->root . '/bin', 0700);
        $python = $this->root . '/bin/python3.12';
        file_put_contents(
            $python,
            '#!' .
                PHP_BINARY .
                "\n<?php\nfile_put_contents(__DIR__ . '/input', \$argv[2] . '|' . getenv('PYTHONPATH')); echo '{\"status\":\"done\",\"faces\":[]}';"
        );
        chmod($python, 0700);
        $path = getenv('PATH');
        putenv('PATH=' . $this->root . '/bin:' . $path);
        try {
            $this->assertSame(1, $this->library->tagFaces(1));
        } finally {
            putenv('PATH=' . $path);
        }
        $this->assertSame(
            $thumbnail . '|' . $this->root . '/.data/face-runtime/packages',
            file_get_contents(dirname($python) . '/input')
        );
        $this->assertSame('done', $this->library->photo(1)->face_status);
        $this->assertSame(0, $this->library->tagFaces(1));
        $this->assertSame(['a'], $this->client->downloads);
    }

    #[TestWith(['jpeg'])]
    #[TestWith(['png'])]
    public function testAiGatewayReceivesOnlyTheCachedThumbnail(string $format): void
    {
        $this->library->oneDrive->previews([1]);
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        ('image' . $format)(imagecreatetruecolor(80, 60), $thumbnail);
        $encoded = base64_encode(file_get_contents($thumbnail));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $router = $this->root . '/gateway.php';
        file_put_contents(
            $router,
            <<<'PHP'
            <?php
            header('Content-Type: application/json');
            if (str_ends_with($_SERVER['REQUEST_URI'], '/models')) {
                echo json_encode(['data' => [['id' => 'fixture', 'name' => 'fixture', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'pricing' => ['prompt' => '0', 'completion' => '0']]]]);
                return;
            }
            file_put_contents(__DIR__ . '/payload.json', file_get_contents('php://input'));
            echo json_encode(['id' => 'fixture', 'model' => 'fixture', 'choices' => [['message' => ['role' => 'assistant', 'content' => '{"description":"Fixture","tags":["Fixture"]}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]]);
            PHP
        );
        file_put_contents($this->root . '/.data/.env', "\nAI_BASE_URL=http://" . $address . "/v1\n", FILE_APPEND);
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [1 => ['file', $this->root . '/gateway.log', 'w'], 2 => ['file', $this->root . '/gateway.log', 'a']],
            $pipes
        );
        try {
            $handle = curl_init('http://' . $address . '/v1/models');
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_TIMEOUT, 1);
            for ($attempt = 0; $attempt < 100 && curl_exec($handle) === false; $attempt++) {
                usleep(10000);
            }
            $this->assertSame(200, curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $this->assertSame(1, $this->library->tag(1));
            $payload = json_decode(file_get_contents($this->root . '/payload.json'), true);
            $images = [];
            foreach ($payload['messages'] as $message) {
                foreach (is_array($message['content']) ? $message['content'] : [] as $content) {
                    if ($content['type'] === 'image_url') {
                        $images[] = $content['image_url']['url'];
                    }
                }
            }
            $this->assertSame(['data:image/' . $format . ';base64,' . $encoded], $images);
            $this->assertSame(['Fixture'], $this->library->photo(1)->tags);
            $this->assertSame(['a'], $this->client->downloads);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testAnimationAloneDoesNotReplaceTheStaticAnalysisThumbnail(): void
    {
        $path = $this->library->database->query('SELECT path FROM photos WHERE id = 1')->fetchColumn();
        $thumbnail = $this->root . '/.data/thumbnails/' . hash('sha256', $path) . '.jpg';
        file_put_contents($thumbnail . '.webp', 'cached animation');
        $run = $this->library->jobs->start('previews');
        $this->assertSame('done', $this->library->jobs->step('previews', $run['token'])['status']);
        $this->assertFileExists($thumbnail);
        $this->assertSame('image/jpeg', getimagesize($thumbnail)['mime']);
    }
}
