<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class SimilarPhotosTest extends TestCase
{
    use CloudFixture;

    protected function setUp(): void
    {
        $this->createCloudLibrary(
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=fixture\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=fixture\n"
        );
        $this->index([
            $this->item('a', 'a.jpg', modified: '2026-09-01T10:00:00Z'),
            $this->item('b', 'b.jpg', modified: '2026-09-01T10:00:05Z'),
            $this->item('c', 'c.jpg', modified: '2026-09-01T11:00:00Z'),
            $this->item('d', 'd.jpg', modified: '2026-09-02T10:00:00Z'),
            $this->item('e', 'e.jpg', modified: '2026-09-01T10:00:09Z')
        ]);
        $this->library->oneDrive->previews([1, 2, 3, 4, 5]);
        foreach ([1 => false, 2 => false, 3 => true, 4 => false, 5 => false] as $id => $inverted) {
            $image = imagecreatetruecolor(170, 160);
            for ($x = 0; $x < 170; $x++) {
                $shade = (int) ((($x * 37) % 170) * 1.5);
                $shade = $inverted ? 255 - $shade : $shade;
                imagefilledrectangle($image, $x, 0, $x, 159, imagecolorallocate($image, $shade, $shade, $shade));
            }
            imagesetpixel($image, $id, $id, imagecolorallocate($image, 255, 0, 0));
            imagejpeg($image, $this->library->imagePath($id, cachedOnly: true));
        }
        $this->library->database
            ->exec("UPDATE photos SET priority = 1, ai_priority = 1, status = 'done', description = 'KI';
            UPDATE photos SET ai_priority = NULL WHERE id = 5;");
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
    }

    public function testAiKeepsTheBestOfSameDayDuplicatesAndResetRestoresThem(): void
    {
        $this->assertSame(4, $this->library->jobs->all()['similar']['queued']);
        $this->withGateway('{"keep":[2],"reason":"Schärfer"}', function (): void {
            $run = $this->library->jobs->start('similar');
            do {
                $run = $this->library->jobs->step('similar', $run['token']);
            } while ($run['status'] === 'running');
            $this->assertSame('done', $run['status']);
            $this->assertSame(4, $run['completed']);
            $this->assertSame(0, $run['errors']);
        });
        $payloads = glob($this->root . '/payload-*.json');
        $this->assertCount(1, $payloads);
        $this->assertSame(2, substr_count(file_get_contents($payloads[0]), '"type":"image_url"'));
        $this->assertSame(-1, $this->library->photo(1)->priority);
        $this->assertSame('Schärfer', $this->library->photo(1)->similar);
        $this->assertSame('KI', $this->library->photo(1)->description);
        foreach ([2, 3, 4, 5] as $id) {
            $this->assertSame(1, $this->library->photo($id)->priority);
            $this->assertSame('', $this->library->photo($id)->similar);
        }
        $this->library->jobs->reset('similar');
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame('', $this->library->photo(1)->similar);
        $this->assertSame(4, $this->library->jobs->all()['similar']['queued']);
    }

    public function testInvalidAiAnswerHidesNothingAndRetriesAfterAnHour(): void
    {
        $this->withGateway('{"keep":[2],"reason": Schärfer"}', function (): void {
            $this->assertSame(4, $this->library->similar->run());
            $this->assertSame(0, $this->library->similar->run());
        });
        $state = $this->library->jobs->all()['similar'];
        $this->assertSame(1, $state['errors']);
        $this->assertSame(3, $state['queued']);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->assertSame(1, $this->library->photo($id)->priority);
        }
    }

    public function testManualRatingsAndRatingResetLeaveNoStaleDecisions(): void
    {
        $this->library->database->exec("UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 1;
            INSERT INTO similar_state (photo_id, modified, bytes, hash, status, kept, reason)
            SELECT id, modified, bytes, '', 'hidden', 2, 'Schärfer' FROM photos WHERE id = 1;");
        $this->assertSame('Schärfer', $this->library->photo(1)->similar);
        $this->library->priority(1, 1);
        $this->assertSame('', $this->library->photo(1)->similar);
        $this->library->jobs->reset('tag');
        $this->assertSame(0, $this->library->database->query('SELECT COUNT(*) FROM similar_state')->fetchColumn());
        $this->assertSame(1, $this->library->photo(5)->priority);
        $this->assertSame(0, $this->library->photo(2)->priority);
    }

    public function testPhotosHiddenInFavorOfARemovedPhotoBecomeVisibleAgain(): void
    {
        $this->library->database->exec("UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 1;
            INSERT INTO similar_state (photo_id, modified, bytes, hash, status, kept, reason)
            SELECT id, modified, bytes, '', CASE WHEN id = 1 THEN 'hidden' ELSE 'done' END, CASE WHEN id = 1 THEN 2 END, ''
            FROM photos WHERE id IN (1, 2, 3);");
        $this->index([['id' => 'b', 'deleted' => []]]);
        $this->assertNull($this->library->photo(2));
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame(
            [3],
            $this->library->database->query('SELECT photo_id FROM similar_state')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * Answer every chat request with the given model output and store each request payload.
     */
    private function withGateway(string $answer, callable $callback): void
    {
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
            file_put_contents(__DIR__ . '/payload-' . hrtime(true) . '.json', file_get_contents('php://input'));
            echo json_encode(['id' => 'fixture', 'model' => 'fixture', 'choices' => [['message' => ['role' => 'assistant', 'content' => file_get_contents(__DIR__ . '/answer.txt')], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]]);
            PHP
        );
        file_put_contents($this->root . '/answer.txt', $answer);
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
            $callback();
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
