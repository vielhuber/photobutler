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
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=rating\nAI_SIMILAR_MODEL=fixture\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=fixture\n"
        );
        $this->index([
            $this->item('a', 'a.jpg', modified: '2026-09-01T10:00:00Z'),
            $this->item('b', 'b.jpg', modified: '2026-09-01T10:00:05Z'),
            $this->item('c', 'c.jpg', modified: '2026-09-01T11:00:00Z'),
            $this->item('d', 'd.jpg', modified: '2026-09-02T10:00:00Z'),
            $this->item('e', 'e.jpg', modified: '2026-09-01T10:00:09Z')
        ]);
        $this->library->oneDrive->previews([1, 2, 3, 4, 5]);
        foreach ([1, 2, 3, 4, 5] as $id) {
            mt_srand($id);
            $image = imagecreatetruecolor(180, 160);
            for ($cell = 0; $cell < 72; $cell++) {
                $shade = mt_rand(0, 255);
                $x = ($cell % 9) * 20;
                $y = intdiv($cell, 9) * 20;
                imagefilledrectangle(
                    $image,
                    $x,
                    $y,
                    $x + 19,
                    $y + 19,
                    imagecolorallocate($image, $shade, $shade, $shade)
                );
            }
            imagejpeg($image, $this->library->imagePath($id, cachedOnly: true));
        }
        mt_srand();
        $this->library->database->exec('UPDATE photos SET modified = modified + 1, duplicate_checked = 0');
        $this->library->duplicates->check([1, 2, 3, 4, 5]);
        $this->library->database
            ->exec("UPDATE photos SET priority = 1, ai_priority = 1, status = 'done', description = 'KI';
            UPDATE photos SET ai_priority = NULL WHERE id = 5;");
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
    }

    public function testAiKeepsTheBestOfEachSeriesWithinADayAndResetRestoresThem(): void
    {
        $this->assertSame(4, $this->library->jobs->all()['similar']['queued']);
        $this->withGateway('{"series":[{"photos":[1,2],"keep":2,"reason":"Schärfer"}]}', function (): void {
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
        $this->assertSame(3, substr_count(file_get_contents($payloads[0]), '"type":"image_url"'));
        $this->assertStringContainsString('"model":"fixture"', file_get_contents($payloads[0]));
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
        $this->withGateway('{"series":[{"photos":[1,4],"keep":1,"reason":"Schärfer"}]}', function (): void {
            $this->assertSame(0, $this->library->similar->run());
        });
        $state = $this->library->jobs->all()['similar'];
        $this->assertSame(1, $state['errors']);
        $this->assertSame(3, $state['queued']);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->assertSame(1, $this->library->photo($id)->priority);
        }
    }

    public function testCopiesFromOtherDaysKeepTheLargestVersionWithoutTheAi(): void
    {
        copy($this->library->imagePath(1, cachedOnly: true), $this->library->imagePath(4, cachedOnly: true));
        $this->library->database->exec('UPDATE photos SET width = 4000, height = 3000, priority = 0 WHERE id = 1;
            UPDATE photos SET width = 2000, height = 1500, priority = -1, modified = modified + 1, duplicate_checked = 0 WHERE id = 4;');
        $this->library->duplicates->check([4]);
        $this->assertSame(-1, $this->library->photo(4)->priority);
        $this->assertSame(
            1,
            $this->library->database->query('SELECT is_duplicate FROM photos WHERE id = 4')->fetchColumn()
        );
        $this->assertSame(
            1,
            $this->library->database->query('SELECT kept FROM duplicate_state WHERE photo_id = 4')->fetchColumn()
        );
        foreach (['all', 'relevant', 'unrated', 'excluded'] as $relevance) {
            $this->assertNotContains(4, array_column($this->library->photos(relevance: $relevance), 'id'));
            $this->assertSame([], $this->library->photos(relevance: $relevance, id: 4));
        }
        $this->assertSame(4, $this->library->photoCount());
        $this->assertSame(0, $this->library->photo(1)->priority);
        copy($this->library->imagePath(1, cachedOnly: true), $this->library->imagePath(2, cachedOnly: true));
        $distinct = imagecreatefromstring(file_get_contents($this->library->imagePath(2, cachedOnly: true)));
        imagefilledrectangle($distinct, x1: 80, y1: 80, x2: 87, y2: 87, color: 0xff_ff_ff);
        imagejpeg($distinct, $this->library->imagePath(2, cachedOnly: true));
        $this->library->database->exec(
            'UPDATE photos SET priority = 0, modified = modified + 1, duplicate_checked = 0 WHERE id = 2'
        );
        $this->library->duplicates->check([2]);
        $this->assertSame(
            0,
            $this->library->database->query('SELECT is_duplicate FROM photos WHERE id = 2')->fetchColumn()
        );
        $this->library->jobs->reset('similar');
        $this->library->jobs->reset('tag');
        $this->assertSame(
            1,
            $this->library->database->query('SELECT is_duplicate FROM photos WHERE id = 4')->fetchColumn()
        );
        copy($this->library->imagePath(1, cachedOnly: true), $this->library->imagePath(5, cachedOnly: true));
        $this->library->database->exec('UPDATE photos SET modified = modified + 1, duplicate_checked = 0 WHERE id = 5');
        $this->library->duplicates->check([5]);
        $this->assertSame(1, $this->library->photo(5)->priority);
        $this->assertSame(
            0,
            $this->library->database->query('SELECT is_duplicate FROM photos WHERE id = 5')->fetchColumn()
        );
        $this->assertSame(
            1,
            $this->library->database->query('SELECT is_duplicate FROM photos WHERE id = 1')->fetchColumn()
        );
    }

    public function testManualRatingsAndRatingResetLeaveNoStaleDecisions(): void
    {
        $this->library->database->exec("UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 1;
            INSERT INTO similar_state (photo_id, modified, bytes, status, kept, reason)
            SELECT id, modified, bytes, 'hidden', 2, 'Schärfer' FROM photos WHERE id = 1;");
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
            INSERT INTO similar_state (photo_id, modified, bytes, status, kept, reason)
            SELECT id, modified, bytes, CASE WHEN id = 1 THEN 'hidden' ELSE 'done' END, CASE WHEN id = 1 THEN 2 END, ''
            FROM photos WHERE id IN (1, 2, 3);");
        $this->index([['id' => 'b', 'deleted' => []]]);
        $this->assertNull($this->library->photo(2));
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame(
            [3],
            $this->library->database->query('SELECT photo_id FROM similar_state')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testHashDecisionsAreComparedAgainExceptHiddenPhotos(): void
    {
        $this->library->database->exec("UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 1;
            DROP TABLE similar_state;
            CREATE TABLE similar_state (photo_id INTEGER PRIMARY KEY, modified INTEGER NOT NULL, bytes INTEGER NOT NULL,
            hash BLOB NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempted INTEGER NOT NULL DEFAULT 0,
            kept INTEGER DEFAULT NULL, reason TEXT NOT NULL DEFAULT '');
            INSERT INTO similar_state (photo_id, modified, bytes, hash, status, kept, reason)
            SELECT id, modified, bytes, '', CASE WHEN id = 1 THEN 'hidden' ELSE 'done' END, CASE WHEN id = 1 THEN 2 END, ''
            FROM photos WHERE id IN (1, 2, 3, 4);");
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $state = $this->library->jobs->all()['similar'];
        $this->assertSame(1, $state['completed']);
        $this->assertSame(3, $state['queued']);
        $this->assertSame(-1, $this->library->photo(1)->priority);
        $this->assertNotContains(
            'hash',
            array_column($this->library->database->query('PRAGMA table_info(similar_state)')->fetchAll(), 'name')
        );
    }
}
