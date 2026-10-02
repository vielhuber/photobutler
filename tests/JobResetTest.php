<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class JobResetTest extends TestCase
{
    use CloudFixture;

    protected function setUp(): void
    {
        $this->createCloudLibrary();
        $this->index([$this->item('b', 'b.jpg'), $this->item('c', 'c.jpg')]);
        $this->library->oneDrive->previews([1, 2]);
        $this->library->favorite(1, true);
        $this->library->database->exec("UPDATE photos SET status = 'done', description = 'KI', attempted = 42;
            UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 2;
            INSERT INTO persons (id, name, auto_match, title_face) VALUES (1, 'Manuell', 0, 1), (2, '', 1, 2), (3, '', 0, NULL);
            INSERT INTO person_separations VALUES (1, 3);
            INSERT INTO faces (id, photo_id, person_id, modified, bytes, model, box, embedding, crop, origin)
            SELECT id, id, id, modified, bytes, 'fixture', '[]', '[]', 'crop', CASE WHEN id = 1 THEN 'manual' ELSE 'auto' END FROM photos;
            INSERT INTO face_state (photo_id, modified, bytes, model, status, attempted)
            SELECT id, modified, bytes, 'fixture', CASE WHEN id = 1 THEN 'excluded' ELSE 'done' END, 42 FROM photos;
            UPDATE jobs SET status = 'paused', completed = 2, total = 2, cursor = 2, maximum = 2, errors = 1;
            INSERT INTO job_timings SELECT job, 5 FROM jobs;");
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
    }

    public function testCatalogResetRestoresIdentityAndManualMetadataAfterReloadAndManualImport(): void
    {
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        $cached = file_get_contents($thumbnail);
        $this->library->database->exec("INSERT INTO scan_state VALUES (1, '{}')");
        $otherJobs = $this->library->database->query("SELECT * FROM jobs WHERE job <> 'scan'")->fetchAll();
        $state = $this->library->jobs->reset('scan');
        self::assertSame('idle', $state['status']);
        foreach (['photos', 'scan_state'] as $table) {
            self::assertSame(0, $this->library->database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        self::assertSame(
            $otherJobs,
            $this->library->database->query("SELECT * FROM jobs WHERE job <> 'scan'")->fetchAll()
        );
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        self::assertSame([], $this->library->photos());
        self::assertSame(0, $this->library->jobs->all()['scan']['completed']);
        $this->client->pages[] = [
            'value' => [$this->item('a', 'a.jpg')],
            '@odata.deltaLink' =>
                \vielhuber\photobutler\OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $run = $this->library->jobs->start('scan');
        do {
            $run = $this->library->jobs->step('scan', $run['token']);
        } while ($run['status'] === 'running');
        self::assertSame('done', $run['status']);
        self::assertTrue($this->library->photo(1)->favorite);
        self::assertSame(-1, $this->library->photo(2)->priority);
        self::assertSame('a.jpg', $this->library->photo(3)->name);
        self::assertSame($thumbnail, $this->library->imagePath(1, cachedOnly: true));
        self::assertSame($cached, file_get_contents($thumbnail));
        self::assertSame(['b', 'c'], $this->client->downloads);
        self::assertSame(2, $this->library->database->query('SELECT COUNT(*) FROM faces')->fetchColumn());
        $this->library->favorite(1, false);
        $this->library->jobs->reset('scan');
        $this->index([]);
        self::assertFalse($this->library->photo(1)->favorite);
    }

    public function testEachResetClearsOnlyItsDataAndInvalidatesItsRun(): void
    {
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        file_put_contents($thumbnail . '.webp', 'animated fixture');
        file_put_contents($thumbnail . '.detail.jpg', 'legacy medium fixture');
        foreach (['previews', 'tag', 'faces'] as $job) {
            $run = $this->library->jobs->start($job);
            $others = $this->library->database->query("SELECT * FROM jobs WHERE job <> '$job'")->fetchAll();
            $state = $this->library->jobs->reset($job);
            self::assertSame('idle', $state['status']);
            self::assertSame('', $state['token']);
            self::assertSame(0, $state['cursor']);
            self::assertSame(0, $state['errors']);
            self::assertSame(
                0,
                $this->library->database->query("SELECT COUNT(*) FROM job_timings WHERE job = '$job'")->fetchColumn()
            );
            self::assertSame(
                $others,
                $this->library->database->query("SELECT * FROM jobs WHERE job <> '$job'")->fetchAll()
            );
            self::assertSame('idle', $this->library->jobs->step($job, $run['token'])['status']);
            $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
            self::assertSame('idle', $this->library->jobs->all()[$job]['status']);
            self::assertTrue($this->library->photo(1)->favorite);
            self::assertSame(['b', 'c'], $this->client->downloads);
            if ($job === 'previews') {
                self::assertFileDoesNotExist($thumbnail);
                self::assertFileDoesNotExist($thumbnail . '.webp');
                self::assertFileExists($thumbnail . '.detail.jpg');
                self::assertSame(
                    2,
                    $this->library->database->query("SELECT COUNT(*) FROM photos WHERE status = 'done'")->fetchColumn()
                );
            }
            if ($job === 'tag') {
                self::assertSame(
                    2,
                    $this->library->database
                        ->query(
                            "SELECT COUNT(*) FROM photos WHERE ai_priority IS NULL AND description = '' AND attempted = 0 AND status = 'pending'"
                        )
                        ->fetchColumn()
                );
                self::assertSame(0, $this->library->photo(2)->priority);
                self::assertSame(2, $this->library->database->query('SELECT COUNT(*) FROM faces')->fetchColumn());
            }
        }
        self::assertSame([1], $this->library->database->query('SELECT id FROM faces')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(
            [1, 3],
            $this->library->database->query('SELECT id FROM persons ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame('excluded', $this->library->database->query('SELECT status FROM face_state')->fetchColumn());
        self::assertSame(1, $this->library->database->query('SELECT COUNT(*) FROM person_separations')->fetchColumn());
    }

    public function testRatingResetKeepsRatingsTheUserChanged(): void
    {
        $this->library->priority(2, 1);
        $this->library->jobs->reset('tag');
        self::assertSame(1, $this->library->photo(2)->priority);
        self::assertTrue($this->library->photo(1)->favorite);
    }

    public function testTemporarilyMissingPhotosKeepTheirMetadataAndStickerOriginalsSurviveAllResets(): void
    {
        $sticker = ['file' => ['mimeType' => 'image/webp']] + $this->item('sticker', 'sticker.webp');
        $this->index([$sticker]);
        $this->library->jobs->reset('scan');
        $this->index([['id' => 'b', 'deleted' => []]]);
        self::assertNull($this->library->photo(1));
        $this->library->jobs->reset('scan');
        $this->index([$this->item('b', 'b.jpg')]);
        self::assertTrue($this->library->photo(1)->favorite);
        foreach (['previews', 'tag', 'faces'] as $job) {
            $this->library->jobs->reset($job);
        }
        self::assertSame('sticker.webp', $this->library->photo(3)->name);
        self::assertSame(['b', 'c'], $this->client->downloads);
    }

    public function testInFlightResourceLocksRejectResetsWithoutChangingDataOrJobs(): void
    {
        foreach (
            [
                'scan' => ['cli-scan', 'cli-previews', 'cli-tag', 'cli-faces', 'job-faces', 'index', 'thumbnail-0'],
                'tag' => ['cli-tag', 'job-tag', 'tag', 'similar'],
                'faces' => ['cli-faces', 'job-faces', 'faces'],
                'similar' => ['cli-similar', 'job-similar', 'similar'],
                'previews' => ['cli-previews', 'job-previews', 'thumbnail-1']
            ]
            as $job => $names
        ) {
            foreach ($names as $name) {
                $lock = fopen($this->root . '/.data/' . $name . '.lock', 'c');
                flock($lock, LOCK_EX);
                $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
                try {
                    $this->library->jobs->reset($job);
                    self::fail('A reset must not race an in-flight step.');
                } catch (RuntimeException $exception) {
                    self::assertSame(409, $exception->getCode());
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                self::assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
                self::assertSame(2, count($this->library->photos()));
            }
        }
    }
}
