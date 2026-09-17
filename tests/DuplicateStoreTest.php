<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;
use vielhuber\photobutler\DuplicateStore;

final class DuplicateStoreTest extends TestCase
{
    private string $root;
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-duplicates-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/photos', 0700, true);
        mkdir($this->root . '/.data', 0700);
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" . json_encode([$this->root . '/photos']) . "'\n"
        );
        imagejpeg(imagecreatetruecolor(8, 8), $this->root . '/photos/a.jpg');
        $this->library = new PhotoButler($this->root);
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
        rmdir($this->root);
    }

    public function testImportSuppressesCopiesAcrossRestartAndCatalogReset(): void
    {
        copy($this->root . '/photos/a.jpg', $this->root . '/photos/b.jpg');
        $original = hash_file('sha256', $this->root . '/photos/a.jpg');
        $this->assertSame(1, $this->library->index());
        $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        $this->assertSame(2, (int) $this->library->importProgress(true)['completed']);
        $this->library = new PhotoButler($this->root);
        $this->assertSame(0, $this->library->index());
        $this->library->jobs->reset('scan');
        $this->assertSame(1, $this->library->index());
        $this->assertSame(1, (int) $this->library->database->query('SELECT id FROM photos')->fetchColumn());
        $this->assertSame($original, hash_file('sha256', $this->root . '/photos/b.jpg'));
    }

    public function testExistingDuplicatesRequireBackupAndKeepCanonicalAndMediumCaches(): void
    {
        $this->seedExistingDuplicate();
        $cache = $this->root . '/.data/thumbnails/';
        $canonical = hash('sha256', $this->root . '/photos/a.jpg') . '.jpg';
        $duplicate = hash('sha256', $this->root . '/photos/b.jpg') . '.jpg';
        foreach ([$canonical, $duplicate, $duplicate . '.webp', $duplicate . '.detail.jpg'] as $name) {
            file_put_contents($cache . $name, $name);
        }
        $store = new DuplicateStore($this->library->database, $this->root . '/.data');
        $report = $store->clean([$this->root . '/photos']);
        $this->assertSame(1, $report['duplicates']);
        $this->assertSame(0, $report['removed']);
        $report = $store->clean([$this->root . '/photos'], true);
        $this->assertSame(1, $report['removed']);
        $this->assertSame(2, $report['thumbnails']);
        $backup = new PDO('sqlite:' . $report['backup'] . '/database.sqlite');
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(2, (int) $backup->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        $this->assertFileExists($cache . $canonical);
        $this->assertFileExists($cache . $duplicate . '.detail.jpg');
        $this->assertFileDoesNotExist($cache . $duplicate);
        $this->assertFileExists($report['backup'] . '/' . $duplicate);
        $this->assertSame(
            hash_file('sha256', $this->root . '/photos/a.jpg'),
            hash_file('sha256', $this->root . '/photos/b.jpg')
        );
        $this->library = new PhotoButler($this->root);
        $this->assertSame(0, $this->library->index());
        $this->assertSame(0, $store->clean([$this->root . '/photos'], true)['removed']);
    }

    public function testConflictingRatingsAndFaceMetadataPreventAnyDeletion(): void
    {
        $this->seedExistingDuplicate();
        $this->library->database->exec('UPDATE photos SET priority = CASE id WHEN 1 THEN 1 ELSE -1 END');
        $store = new DuplicateStore($this->library->database, $this->root . '/.data');
        $report = $store->clean([$this->root . '/photos'], true);
        $this->assertNotEmpty($report['conflicts']);
        $this->assertSame(0, $report['removed']);
        $this->assertSame(2, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        $this->library->database->exec(
            "UPDATE photos SET priority = 1; INSERT INTO face_state VALUES (2,1,1,'test','excluded',0)"
        );
        $this->assertNotEmpty($store->clean([$this->root . '/photos'], true)['conflicts']);
        $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testOldUnhashedCatalogRejectsNewCopyAndChangedCopyBecomesIndependent(): void
    {
        $this->library->index();
        $this->library->database->exec('DELETE FROM photo_hashes');
        copy($this->root . '/photos/a.jpg', $this->root . '/photos/b.jpg');
        $this->assertSame(0, $this->library->index());
        file_put_contents($this->root . '/photos/b.jpg', 'changed', FILE_APPEND);
        clearstatcache();
        $this->assertSame(1, $this->library->index());
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT COUNT(*) FROM photo_duplicates')->fetchColumn()
        );
        $this->assertSame(2, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
    }

    public function testFingerprintReuseDoesNotReadTheOriginalAgain(): void
    {
        $path = $this->root . '/photos/a.jpg';
        $modified = filemtime($path);
        $bytes = filesize($path);
        $store = $this->library->duplicates;
        $digest = $store->fingerprint($path, $modified, $bytes);
        rename($path, $path . '.offline');
        $this->assertSame($digest, $store->fingerprint($path, $modified, $bytes));
    }

    public function testDeletionFailureRollsBackIndexAliasesAndQueue(): void
    {
        $this->seedExistingDuplicate();
        $this->library->database->exec(
            "CREATE TRIGGER reject_duplicate_delete BEFORE DELETE ON photos BEGIN SELECT RAISE(ABORT, 'fixture'); END"
        );
        try {
            $this->library->duplicates->clean([$this->root . '/photos'], true);
            $this->fail('Deletion must fail.');
        } catch (PDOException) {
            $this->assertSame(2, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
            $this->assertSame(
                0,
                (int) $this->library->database->query('SELECT COUNT(*) FROM photo_duplicates')->fetchColumn()
            );
            $this->assertSame(
                0,
                (int) $this->library->database->query('SELECT COUNT(*) FROM duplicate_cache_queue')->fetchColumn()
            );
        }
        $this->library->database->exec('DROP TRIGGER reject_duplicate_delete');
        $this->assertSame(1, $this->library->duplicates->clean([$this->root . '/photos'], true)['removed']);
    }

    public function testInterruptedCacheRemovalResumesWithoutTouchingSharedOriginal(): void
    {
        $this->seedExistingDuplicate();
        $source = $this->root . '/photos/b.jpg';
        $before = hash_file('sha256', $source);
        $cache = $this->root . '/.data/thumbnails/' . hash('sha256', $source) . '.jpg';
        symlink($source, $cache);
        try {
            $this->library->duplicates->clean([$this->root . '/photos'], true);
            $this->fail('Unsafe cache must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
            $this->assertSame(
                1,
                (int) $this->library->database->query('SELECT COUNT(*) FROM duplicate_cache_queue')->fetchColumn()
            );
            $this->assertSame($before, hash_file('sha256', $source));
        }
        unlink($cache);
        file_put_contents($cache, 'thumbnail');
        $this->library = new PhotoButler($this->root);
        $report = $this->library->duplicates->clean([$this->root . '/photos'], true);
        $this->assertSame(0, $report['removed']);
        $this->assertSame(1, $report['thumbnails']);
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT COUNT(*) FROM duplicate_cache_queue')->fetchColumn()
        );
    }

    public function testMissingSourceAndRunningJobBlockCleanupWithoutDiscardingMetadata(): void
    {
        $this->seedExistingDuplicate();
        $this->library->database->exec(
            "UPDATE photos SET priority = 1, manual_tags = '[\"manual\"]', ai_tags = '[\"ai\"]', description = 'description'"
        );
        rename($this->root . '/photos/b.jpg', $this->root . '/photos/b.offline');
        $report = $this->library->duplicates->clean([$this->root . '/photos'], true);
        $this->assertSame([2], $report['unreadable']);
        $this->assertSame(0, $report['removed']);
        rename($this->root . '/photos/b.offline', $this->root . '/photos/b.jpg');
        $jobs = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
        $report = $this->library->duplicates->clean([$this->root . '/photos'], true);
        $this->assertSame(1, $report['removed']);
        $this->assertSame($jobs, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
        $this->assertSame(
            '["manual"]',
            $this->library->database->query('SELECT manual_tags FROM photos')->fetchColumn()
        );
        $this->assertSame('["ai"]', $this->library->database->query('SELECT ai_tags FROM photos')->fetchColumn());
        $this->assertSame(1, (int) $this->library->database->query('SELECT priority FROM photos')->fetchColumn());
        $lock = fopen($this->root . '/.data/job-scan.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->library->duplicates->clean([$this->root . '/photos'], true);
            $this->fail('Running job must block cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertSame(409, $exception->getCode());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testCanonicalRatingsCanChangeAfterCleanupAndSurviveReset(): void
    {
        $this->seedExistingDuplicate();
        $this->library->duplicates->clean([$this->root . '/photos'], true);
        $this->library->priority(1, 1);
        $this->library->saveTags(1, 'retained');
        $this->assertSame(0, $this->library->index());
        $this->library->jobs->reset('scan');
        $this->library = new PhotoButler($this->root);
        $this->assertSame(1, $this->library->index());
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame(
            '["retained"]',
            $this->library->database->query('SELECT manual_tags FROM photos')->fetchColumn()
        );
    }

    public function testProtectedUnmappedMetadataAndDifferentTagsBlockSuppression(): void
    {
        $this->seedExistingDuplicate();
        $this->library->saveTags(2, 'different');
        $report = $this->library->duplicates->clean([$this->root . '/photos'], true);
        $this->assertSame(['manual_tags'], $report['conflicts'][0]['fields']);
        $this->library->jobs->reset('scan');
        try {
            $this->library->index();
            $this->fail('Protected metadata must not be discarded.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
            $this->assertSame(
                2,
                (int) $this->library->database->query('SELECT COUNT(*) FROM photo_metadata')->fetchColumn()
            );
        }
    }

    public function testSameSizeSimilarImagesWithDifferentBytesRemainSeparate(): void
    {
        $first = $this->root . '/photos/a.jpg';
        file_put_contents($first, 'a', FILE_APPEND);
        $second = $this->root . '/photos/b.jpg';
        file_put_contents($second, substr(file_get_contents($first), 0, -1) . 'b');
        $this->assertSame(filesize($first), filesize($second));
        $this->assertSame(2, $this->library->index());
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT COUNT(*) FROM photo_duplicates')->fetchColumn()
        );
    }

    public function testChangedCanonicalDoesNotSuppressTheRemainingOldContent(): void
    {
        rename($this->root . '/photos/a.jpg', $this->root . '/photos/z.jpg');
        $this->library->index();
        copy($this->root . '/photos/z.jpg', $this->root . '/photos/a.jpg');
        $this->assertSame(0, $this->library->index());
        file_put_contents($this->root . '/photos/z.jpg', 'changed', FILE_APPEND);
        clearstatcache();
        $this->assertSame(2, $this->library->index());
        $this->assertSame(2, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT COUNT(*) FROM photo_duplicates')->fetchColumn()
        );
    }

    public function testOutOfScopeCatalogEntryCannotSuppressCurrentSource(): void
    {
        $this->library->index();
        $this->library->database->exec('DELETE FROM photo_hashes');
        mkdir($this->root . '/different');
        copy($this->root . '/photos/a.jpg', $this->root . '/different/b.jpg');
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" . json_encode([$this->root . '/different']) . "'\n"
        );
        $this->library = new PhotoButler($this->root);
        $this->assertSame(1, $this->library->index());
        $this->assertSame(1, (int) $this->library->importProgress(true)['completed']);
        $this->assertSame(1, (int) $this->library->database->query('SELECT SUM(available) FROM photos')->fetchColumn());
    }

    public function testResetCannotHideDuplicateFaceDecisionsDuringReimport(): void
    {
        $this->seedExistingDuplicate();
        $this->library->database->exec("INSERT INTO face_state VALUES (2,1,1,'fixture','excluded',0)");
        $this->library->jobs->reset('scan');
        try {
            $this->library->index();
            $this->fail('Face exclusions must not become orphaned.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
            $this->assertSame(
                'excluded',
                $this->library->database->query('SELECT status FROM face_state')->fetchColumn()
            );
        }
    }

    public function testCliAuditsBeforeApplyingAndLeavesJobsStopped(): void
    {
        $this->seedExistingDuplicate();
        foreach ([false, true, true] as $apply) {
            $command = [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-deduplicate', '--root=' . $this->root];
            if ($apply) {
                $command[] = '--apply';
            }
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $report = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $this->assertSame([], $report['conflicts']);
            $this->assertSame(
                $apply ? 1 : 2,
                (int) $this->library->database->query('SELECT COUNT(*) FROM photos')->fetchColumn()
            );
        }
        $this->assertSame(
            0,
            (int) $this->library->database->query("SELECT COUNT(*) FROM jobs WHERE status <> 'idle'")->fetchColumn()
        );
    }

    private function seedExistingDuplicate(): void
    {
        $this->library->index();
        copy($this->root . '/photos/a.jpg', $this->root . '/photos/b.jpg');
        touch($this->root . '/photos/b.jpg', filemtime($this->root . '/photos/a.jpg'));
        $this->library->database
            ->prepare(
                "INSERT INTO photos (id,root,path,album,name,modified,bytes,width,height,taken,seen,manual_tags,priority) SELECT 2,root,?,album,'b.jpg',modified,bytes,width,height,taken,seen,manual_tags,priority FROM photos WHERE id=1"
            )
            ->execute([$this->root . '/photos/b.jpg']);
    }
}
