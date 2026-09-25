<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class JobResetTest extends TestCase
{
    private string $root;
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-reset-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/photos', 0700, true);
        mkdir($this->root . '/.data', 0700);
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" . json_encode([$this->root . '/photos']) . "'\n"
        );
        $image = imagecreatetruecolor(80, 60);
        foreach (['b', 'c'] as $name) {
            imagejpeg($image, $this->root . '/photos/' . $name . '.jpg');
            file_put_contents($this->root . '/photos/' . $name . '.jpg', $name, FILE_APPEND);
        }
        $this->library = new PhotoButler($this->root);
        $this->library->index();
        $this->library->favorite(1, true);
        $this->library->saveTags(1, 'Manuell');
        $this->library->saveTags(2, '');
        $this->library->database
            ->exec("UPDATE photos SET status = 'done', ai_tags = '[\"KI\"]', description = 'KI', attempted = 42;
            INSERT INTO persons (id, name, auto_match, title_face) VALUES (1, 'Manuell', 0, 1), (2, '', 1, 2), (3, '', 0, NULL);
            INSERT INTO person_separations VALUES (1, 3);
            INSERT INTO faces (id, photo_id, person_id, modified, bytes, model, box, embedding, crop, origin)
            SELECT id, id, id, modified, bytes, 'fixture', '[]', '[]', 'crop', CASE WHEN id = 1 THEN 'manual' ELSE 'auto' END FROM photos;
            INSERT INTO face_state SELECT id, modified, bytes, 'fixture', CASE WHEN id = 1 THEN 'excluded' ELSE 'done' END, 42 FROM photos;
            UPDATE jobs SET status = 'paused', completed = 2, total = 2, cursor = 2, maximum = 2, errors = 1;
            INSERT INTO job_timings SELECT job, 5 FROM jobs;");
    }

    protected function tearDown(): void
    {
        unset($this->library);
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            )
            as $file
        ) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }

    public function testCatalogResetRestoresIdentityAndManualMetadataAfterReloadAndManualImport(): void
    {
        $original = file_get_contents($this->root . '/photos/b.jpg');
        $thumbnail = $this->library->imagePath(1);
        $cached = file_get_contents($thumbnail);
        $this->library->database->exec("INSERT INTO scan_state VALUES (1, '{}')");
        $otherJobs = $this->library->database->query("SELECT * FROM jobs WHERE job <> 'scan'")->fetchAll();
        $state = $this->library->jobs->reset('scan');
        self::assertSame('idle', $state['status']);
        foreach (['photos', 'scan_state', 'import_files'] as $table) {
            self::assertSame(0, $this->library->database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        self::assertSame(
            $otherJobs,
            $this->library->database->query("SELECT * FROM jobs WHERE job <> 'scan'")->fetchAll()
        );
        $this->library = new PhotoButler($this->root);
        self::assertSame([], $this->library->photos());
        self::assertSame(0, $this->library->jobs->all()['scan']['completed']);
        copy($this->root . '/photos/b.jpg', $this->root . '/photos/a.jpg');
        file_put_contents($this->root . '/photos/a.jpg', 'a', FILE_APPEND);
        $run = $this->library->jobs->start('scan');
        do {
            $run = $this->library->jobs->step('scan', $run['token'], scanLimit: 1);
        } while ($run['status'] === 'running');
        self::assertSame('done', $run['status']);
        self::assertTrue($this->library->photo(1)->favorite);
        self::assertSame(
            '["Manuell"]',
            $this->library->database->query('SELECT manual_tags FROM photos WHERE id = 1')->fetchColumn()
        );
        self::assertSame(
            '[]',
            $this->library->database->query('SELECT manual_tags FROM photos WHERE id = 2')->fetchColumn()
        );
        self::assertSame('a.jpg', $this->library->photo(3)->name);
        self::assertSame($original, file_get_contents($this->library->imagePath(1, original: true)));
        self::assertSame($cached, file_get_contents($thumbnail));
        self::assertSame(2, $this->library->database->query('SELECT COUNT(*) FROM faces')->fetchColumn());
        $this->library->favorite(1, false);
        $this->library->saveTags(1, 'Korrigiert');
        $this->library->jobs->reset('scan');
        $this->library->index();
        self::assertFalse($this->library->photo(1)->favorite);
        self::assertSame(
            '["Korrigiert"]',
            $this->library->database->query('SELECT manual_tags FROM photos WHERE id = 1')->fetchColumn()
        );
    }

    public function testEachResetClearsOnlyItsDataAndInvalidatesItsRun(): void
    {
        $original = file_get_contents($this->root . '/photos/b.jpg');
        $thumbnail = $this->library->imagePath(1);
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
            $this->library = new PhotoButler($this->root);
            self::assertSame('idle', $this->library->jobs->all()[$job]['status']);
            self::assertTrue($this->library->photo(1)->favorite);
            self::assertSame(
                '["Manuell"]',
                $this->library->database->query('SELECT manual_tags FROM photos WHERE id = 1')->fetchColumn()
            );
            self::assertSame($original, file_get_contents($this->root . '/photos/b.jpg'));
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
                            "SELECT COUNT(*) FROM photos WHERE ai_tags = '[]' AND description = '' AND attempted = 0 AND status = 'pending'"
                        )
                        ->fetchColumn()
                );
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

    public function testTemporarilyMissingPhotosKeepTheirMetadataAndStickerOriginalsSurviveAllResets(): void
    {
        $original = file_get_contents($this->root . '/photos/b.jpg');
        $image = imagecreatetruecolor(32, 32);
        imagewebp($image, $this->root . '/photos/sticker.webp');
        $sticker = file_get_contents($this->root . '/photos/sticker.webp');
        $this->library->index();
        $this->library->jobs->reset('scan');
        unlink($this->root . '/photos/b.jpg');
        $this->library->index();
        self::assertNull($this->library->photo(1));
        $this->library->jobs->reset('scan');
        file_put_contents($this->root . '/photos/b.jpg', $original);
        $this->library->index();
        self::assertTrue($this->library->photo(1)->favorite);
        self::assertSame(
            '["Manuell"]',
            $this->library->database->query('SELECT manual_tags FROM photos WHERE id = 1')->fetchColumn()
        );
        foreach (['previews', 'tag', 'faces'] as $job) {
            $this->library->jobs->reset($job);
        }
        self::assertSame($sticker, file_get_contents($this->library->imagePath(3, original: true)));
    }

    public function testInFlightResourceLocksRejectResetsWithoutChangingDataOrJobs(): void
    {
        foreach (
            [
                'scan' => ['cli-scan', 'cli-previews', 'cli-tag', 'cli-faces', 'job-faces', 'index', 'thumbnail-0'],
                'tag' => ['cli-tag', 'job-tag', 'tag'],
                'faces' => ['cli-faces', 'job-faces', 'faces'],
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
