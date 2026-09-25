<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class PhotoButlerTest extends TestCase
{
    private string $root;
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/photos/Urlaub', 0700, true);
        mkdir($this->root . '/.data', 0700);
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" .
                json_encode([$this->root . '/photos']) .
                "'\nAUTH_USERNAME=test-user\nAUTH_PASSWORD=test-password\nJWT_SECRET=photobutler-test-signing-secret-32-bytes\n"
        );
        $image = imagecreatetruecolor(80, 60);
        imagejpeg($image, $this->root . '/photos/Urlaub/Meer.jpg');
        $this->library = new PhotoButler($this->root);
    }

    private function copyDistinctPhoto(string $source, string $target): void
    {
        copy($source, $target);
        file_put_contents($target, basename($target), FILE_APPEND);
    }

    protected function tearDown(): void
    {
        unset($this->library);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testPriorityMigrationRetainsFavoritesAndOnlyExcludesNeutralEntriesOnce(): void
    {
        $this->library->index();
        $database = $this->library->database;
        $database->exec("ALTER TABLE photos RENAME COLUMN priority TO favorite;
            ALTER TABLE photo_metadata RENAME COLUMN priority TO favorite;
            UPDATE photos SET favorite = 1, taken = '2024-01-01';");
        $insert = $database->prepare("INSERT INTO photos
            (root,path,album,name,modified,bytes,width,height,taken,seen,favorite)
            VALUES ('/photos',?,'test','test.jpg',1,1,0,0,?,'test',?)");
        foreach (
            [
                ['/_WHATSAPP/.Statuses/a.jpg', '2024-01-01', 0],
                ['/old.jpg', '2022-12-31', 0],
                ['/_WHATSAPP/WhatsApp Animated Gifs/a.gif', '2024-01-01', 0],
                ['/_WHATSAPP/.Statuses/favorite.jpg', '2024-01-01', 1],
                ['/old-favorite.jpg', '2022-12-31', 1],
                ['/_WHATSAPP/WhatsApp Images/favorite.gif', '2024-01-01', 1]
            ]
            as [$path, $taken, $favorite]
        ) {
            $insert->execute([$path, $taken, $favorite]);
        }
        $this->library = new PhotoButler($this->root);
        $this->assertSame(
            [1, -1, -1, -1, 1, 1, 1],
            array_column($database->query('SELECT priority FROM photos ORDER BY id')->fetchAll(), 'priority')
        );
        $this->assertNotContains(
            'favorite',
            array_column($database->query('PRAGMA table_info(photos)')->fetchAll(), 'name')
        );
        $this->library->priority(2, 0);
        $this->library = new PhotoButler($this->root);
        $this->assertSame(0, $this->library->photo(2)->priority);
    }

    public function testImportAssignsExclusionsAndRetainsManualPriorityAndCaches(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        foreach (
            [
                'old.jpg',
                '_WHATSAPP/.Statuses/story.jpg',
                '_WHATSAPP/WhatsApp Animated Gifs/animation.gif',
                '_WHATSAPP/WhatsApp Images/other.GIF',
                'ordinary.gif'
            ]
            as $path
        ) {
            $target = $this->root . '/photos/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $this->copyDistinctPhoto($source, $target);
            touch($target, strtotime($path === 'old.jpg' ? '2022-12-31' : '2024-01-01'));
        }
        $this->library->index();
        $priorities = $this->library->database
            ->query('SELECT name, priority FROM photos')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(-1, $priorities['old.jpg']);
        $this->assertSame(-1, $priorities['story.jpg']);
        $this->assertSame(-1, $priorities['animation.gif']);
        $this->assertSame(-1, $priorities['other.GIF']);
        $this->assertSame(0, $priorities['ordinary.gif']);
        $excluded = $this->library->database
            ->query('SELECT id FROM photos WHERE priority = -1')
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($excluded as $id) {
            $this->library->priority((int) $id, 1);
        }
        touch($this->root . '/photos/old.jpg', strtotime('2022-12-30'));
        $this->library->index();
        $this->assertCount(4, $this->library->photos(favorites: true));
        $photo = $this->library->photos(album: 'Urlaub')[0];
        $cache = $this->library->imagePath($photo->id);
        $hash = hash_file('sha256', $cache);
        $this->library->priority($photo->id, -1);
        $this->library->index();
        $this->assertSame(-1, $this->library->photo($photo->id)->priority);
        $this->assertSame($hash, hash_file('sha256', $cache));
        $this->library->jobs->reset('scan');
        $this->library->index();
        $this->assertSame(-1, $this->library->photo($photo->id)->priority);
        $this->assertCount(4, $this->library->photos(favorites: true));
        foreach ($excluded as $id) {
            $this->library->priority((int) $id, 0);
        }
        $this->library->index();
        foreach ($excluded as $id) {
            $this->assertSame(-1, $this->library->photo((int) $id)->priority);
        }
    }

    public function testVisibilityFiltersCombineWithPriorityAndFavoriteFilters(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        foreach ([-1, 0, 1] as $priority) {
            $this->library->priority($id, $priority);
            $this->assertCount(1, $this->library->photos(relevance: 'all'));
            $this->assertCount($priority === 0 ? 1 : 0, $this->library->photos(relevance: 'unrated'));
            $this->assertCount(0, $this->library->photos(relevance: 'unrated', favorites: true));
            $this->assertCount(
                $priority === 0 ? 1 : 0,
                $this->library->photos(relevance: 'unrated', favorites: 'none', id: $id)
            );
            $this->assertCount($priority === 1 ? 1 : 0, $this->library->photos(relevance: 'relevant'));
            $this->assertCount($priority === 1 ? 1 : 0, $this->library->photos(relevance: 'relevant', id: $id));
            $this->assertCount(0, $this->library->photos(relevance: 'relevant', favorites: 'none'));
            $this->assertCount($priority === -1 ? 1 : 0, $this->library->photos(relevance: 'excluded'));
            $this->assertCount(0, $this->library->photos(relevance: 'excluded', favorites: true));
            $this->assertCount(
                $priority === -1 ? 1 : 0,
                $this->library->photos(relevance: 'excluded', favorites: 'none', id: $id)
            );
        }
    }

    public function testPriorityIsExclusiveAndValidated(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        foreach ([-1, 0, 1] as $priority) {
            $this->library->priority($id, $priority);
            $photo = $this->library->photo($id);
            $this->assertSame($priority, $photo->priority);
            $this->assertSame($priority === 1, $photo->favorite);
            $this->assertCount($priority === 1 ? 1 : 0, $this->library->photos(relevance: 'relevant'));
        }
        $this->expectException(InvalidArgumentException::class);
        $this->library->priority($id, 2);
    }

    public function testJobLogsAreIndependentBoundedPersistentAndResetWithTheirJob(): void
    {
        $this->library->index();
        $jobs = $this->library->jobs;
        foreach (array_keys(\vielhuber\photobutler\JobRunner::LABELS) as $job) {
            $this->assertSame([], $jobs->all()[$job]['log']);
        }
        for ($index = 0; $index < 35; $index++) {
            $jobs->log('previews', 'Eintrag ' . $index);
        }
        $log = new PhotoButler($this->root)->jobs->all()['previews']['log'];
        $this->assertCount(30, $log);
        $this->assertSame('Eintrag 5', $log[0]['message']);
        $this->assertSame('Eintrag 34', $log[29]['message']);
        $this->assertSame([], $jobs->all()['tag']['log']);
        $jobs->log('tag', 'Unabhängiger Eintrag');
        $run = $jobs->start('previews');
        $state = $jobs->step('previews', $run['token']);
        $this->assertSame('done', $state['status']);
        $messages = implode(' ', array_column($state['log'], 'message'));
        $this->assertStringContainsString('Foto 1', $messages);
        $this->assertStringContainsString('Abgeschlossen', $messages);
        $jobs->reset('previews');
        $this->assertCount(1, $jobs->all()['previews']['log']);
        $this->assertSame(
            'Daten zurückgesetzt. Wartet auf manuellen Start.',
            $jobs->all()['previews']['log'][0]['message']
        );
        $this->assertSame('Unabhängiger Eintrag', $jobs->all()['tag']['log'][0]['message']);
    }

    public function testEachJobLogsItsOwnProcessingPhaseAndOutcome(): void
    {
        $jobs = $this->library->jobs;
        foreach (
            ['scan' => 'Galerieabschnitt', 'tag' => 'KI-Verschlagwortung', 'faces' => 'analysiere Gesichter']
            as $job => $phase
        ) {
            $run = $jobs->start($job);
            $state = $jobs->step($job, $run['token']);
            $this->assertStringContainsString($phase, implode(' ', array_column($state['log'], 'message')));
            $this->assertStringContainsString(
                $job === 'scan' ? 'Abgeschlossen' : 'Fehler',
                $state['log'][count($state['log']) - 1]['message']
            );
        }
        $this->assertSame([], $jobs->all()['previews']['log']);
    }

    public function testJobsRequireExplicitStartsAndStayIndependent(): void
    {
        $jobs = $this->library->jobs;
        $this->assertCount(4, $jobs->all());
        $this->assertSame('idle', $jobs->all()['scan']['status']);
        $this->assertSame(0, $jobs->step('scan', 'not-started')['completed']);
        $this->assertCount(0, $this->library->photos());
        $scan = $jobs->start('scan');
        $jobs->pause('scan');
        $jobs->step('scan', $scan['token']);
        $this->assertCount(0, $this->library->photos());
        $scan = $jobs->start('scan');
        $scan = $jobs->step('scan', $scan['token']);
        $this->assertSame(100, $scan['percent']);
        $this->assertSame('done', $scan['status']);
        foreach (['tag', 'faces', 'previews'] as $job) {
            $this->assertSame('idle', $jobs->all()[$job]['status']);
        }
        $this->assertSame('pending', $this->library->photo(1)->status);
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
        $previews = $jobs->start('previews');
        $lock = fopen($this->root . '/.data/cli-previews.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $jobs->pause('tag');
            $this->assertSame('running', $jobs->all()['previews']['status']);
        } finally {
            fclose($lock);
        }
        $this->assertSame('paused', $jobs->all()['previews']['status']);
        $previews = $jobs->step('previews', $previews['token']);
        $this->assertSame(100, $previews['percent']);
        $this->assertSame('done', $previews['status']);
        $this->assertFileExists($this->library->imagePath(1));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.detail*'));
        $reopened = new PhotoButler($this->root);
        $this->assertSame('done', $reopened->jobs->all()['previews']['status']);
        $this->assertSame('paused', $reopened->jobs->all()['tag']['status']);
    }

    public function testJobStatusRequiresAnActiveCliLockWithoutChangingPersistedCheckpoints(): void
    {
        $this->library->index();
        $this->library->database->exec("UPDATE jobs SET status = 'running', token = 'private-run', completed = 1");
        $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
        foreach (['scan', 'previews', 'tag', 'faces'] as $job) {
            $this->assertSame('paused', $this->library->jobs->all()[$job]['status']);
            $lock = fopen($this->root . '/.data/cli-' . $job . '.lock', 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                $states = new PhotoButler($this->root)->jobs->all();
                $this->assertSame('running', $states[$job]['status']);
                foreach ($states as $name => $state) {
                    $this->assertArrayNotHasKey('token', $state);
                    if ($name !== $job) {
                        $this->assertSame('paused', $state['status']);
                    }
                }
            } finally {
                fclose($lock);
            }
            $this->assertSame('paused', new PhotoButler($this->root)->jobs->all()[$job]['status']);
        }
        $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
    }

    public function testThumbnailFailuresCanRetryImmediatelyDespiteRecentTagErrors(): void
    {
        $this->library->index();
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        $original = file_get_contents($path);
        file_put_contents($path, '');
        $this->library->database->exec("UPDATE photos SET status = 'error', attempted = " . time());
        $jobs = $this->library->jobs;
        $tag = $jobs->start('tag');
        $jobs->step('tag', $tag['token']);
        $before = $jobs->all();
        $this->assertSame(0, $before['tag']['queued']);
        $run = $jobs->start('previews');
        $run = $jobs->step('previews', $run['token']);
        $this->assertSame('error', $run['status']);
        $this->assertSame(1, $run['errors']);
        file_put_contents($path, $original);
        $run = $jobs->start('previews');
        $run = $jobs->step('previews', $run['token']);
        $this->assertSame('done', $run['status']);
        $this->assertSame(0, $run['errors']);
        $this->assertSame(1, $run['completed']);
        $this->assertSame($original, file_get_contents($path));
        foreach (['scan', 'tag', 'faces'] as $job) {
            $this->assertSame($before[$job], $jobs->all()[$job]);
        }
    }

    public function testImportProgressCountsExistingIndexInsteadOfCurrentPass(): void
    {
        $this->library->index();
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Zweiter.jpg');
        file_put_contents($this->root . '/photos/Urlaub/notes.txt', 'unsupported');
        $before = $this->library->jobs->all()['scan'];
        $this->assertSame(1, $before['completed']);
        $this->assertSame(1, $before['estimated']);
        $run = $this->library->jobs->start('scan');
        $this->assertSame(1, $run['completed']);
        $this->assertSame(2, $run['total']);
        $this->assertSame(50, $run['percent']);
        $this->assertSame(0, $run['estimated']);
        $run = $this->library->jobs->step('scan', $run['token'], scanLimit: 1);
        $this->assertSame(1, $run['completed']);
        $this->library->jobs->pause('scan');
        $checkpoint = $this->library->database->query('SELECT state FROM scan_state')->fetchColumn();
        $reopened = new PhotoButler($this->root);
        $this->assertSame(50, $reopened->jobs->all()['scan']['percent']);
        $this->assertSame($checkpoint, $reopened->database->query('SELECT state FROM scan_state')->fetchColumn());
        $run = $reopened->jobs->start('scan');
        $this->assertSame($checkpoint, $reopened->database->query('SELECT state FROM scan_state')->fetchColumn());
        $run = $reopened->jobs->step('scan', $run['token']);
        $this->assertSame(100, $run['percent']);
        $this->assertSame(2, $run['completed']);
        $this->assertSame(100, new PhotoButler($this->root)->jobs->all()['scan']['percent']);
        $this->assertSame(100, $reopened->jobs->start('scan')['percent']);
        $this->assertSame(0, (int) $reopened->database->query('SELECT SUM(attempted) FROM photos')->fetchColumn());
        $this->assertSame(0, (int) $reopened->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testImportInventoryRefreshHandlesAddedRemovedAndOverlappingSources(): void
    {
        $this->library->index();
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(100, $run['percent']);
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Zweiter.JPG');
        $this->assertSame(1, new PhotoButler($this->root)->jobs->all()['scan']['total']);
        $run = $this->library->jobs->start('scan');
        $this->assertSame(2, $run['total']);
        $this->assertSame(50, $run['percent']);
        $this->assertCount(1, $this->library->photos());
        $this->library->jobs->pause('scan');
        unlink($this->root . '/photos/Urlaub/Meer.jpg');
        $run = $this->library->jobs->start('scan');
        $this->assertSame(1, $run['total']);
        $this->assertSame(0, $run['completed']);
        $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(100, new PhotoButler($this->root)->jobs->all()['scan']['percent']);

        $configuration = file_get_contents($this->root . '/.data/.env');
        file_put_contents(
            $this->root . '/.data/.env',
            preg_replace(
                '/^PHOTO_PATHS=.*$/m',
                "PHOTO_PATHS='" . json_encode([$this->root . '/photos', $this->root . '/photos/Urlaub']) . "'",
                $configuration
            )
        );
        $overlapping = new PhotoButler($this->root);
        $run = $overlapping->jobs->start('scan');
        $this->assertSame(1, $run['total']);
        $this->assertSame(1, $run['completed']);
        $this->assertSame(100, $overlapping->jobs->step('scan', $run['token'])['percent']);
    }

    public function testImportInventoryBootstrapRejectsStaleOutOfScopeAndLinkedIndexPaths(): void
    {
        mkdir($this->root . '/photos-old');
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos-old/Alt.jpg');
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Entfernt.jpg');
        $configuration = file_get_contents($this->root . '/.data/.env');
        file_put_contents(
            $this->root . '/.data/.env',
            preg_replace(
                '/^PHOTO_PATHS=.*$/m',
                "PHOTO_PATHS='" . json_encode([$this->root . '/photos', $this->root . '/photos-old']) . "'",
                $configuration
            )
        );
        new PhotoButler($this->root)->index();
        unlink($this->root . '/photos/Urlaub/Entfernt.jpg');
        file_put_contents($this->root . '/.data/.env', $configuration);
        symlink($this->root . '/photos-old', $this->root . '/photos/linked');
        symlink($this->root . '/photos-old/Alt.jpg', $this->root . '/photos/linked.jpg');
        file_put_contents($this->root . '/photos/notes.TXT', 'unsupported');
        $reopened = new PhotoButler($this->root);
        $progress = $reopened->jobs->all()['scan'];
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(1, $progress['total']);
        $this->assertSame(1, $progress['estimated']);
        $this->assertSame(3, (int) $reopened->database->query('SELECT COUNT(*) FROM photos')->fetchColumn());
        $run = $reopened->jobs->start('scan');
        $this->assertSame(1, $run['total']);
        $this->assertSame(100, $run['percent']);
        $reopened->jobs->pause('scan');
        $before = $reopened->jobs->all()['scan'];
        rename($this->root . '/photos', $this->root . '/temporarily-unavailable');
        try {
            $this->assertSame($before, new PhotoButler($this->root)->jobs->all()['scan']);
            try {
                $reopened->jobs->start('scan');
                $this->fail('An unavailable source must not replace a valid inventory with zero.');
            } catch (RuntimeException) {
                $after = $reopened->jobs->all()['scan'];
                $this->assertSame(
                    [
                        ...array_column($before['log'], 'message'),
                        'Aktualisiere den Gesamtbestand aus den Quellordnern …',
                        'Start fehlgeschlagen. Quellen und Konfiguration prüfen.'
                    ],
                    array_column($after['log'], 'message')
                );
                unset($before['log'], $after['log']);
                $this->assertSame($before, $after);
            }
        } finally {
            rename($this->root . '/temporarily-unavailable', $this->root . '/photos');
        }
    }

    public function testJobsRemainReadableWithoutInventoryWhenSourceIsUnavailable(): void
    {
        $this->library->index();
        $this->library->database->exec(
            "UPDATE jobs SET status = 'paused', completed = 1, total = 2 WHERE job = 'scan'"
        );
        $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
        rename($this->root . '/photos', $this->root . '/temporarily-unavailable');
        $states = $this->library->jobs->all();
        $this->assertCount(4, $states);
        $this->assertSame('paused', $states['scan']['status']);
        $this->assertSame(1, $states['scan']['completed']);
        $this->assertSame(2, $states['scan']['total']);
        $this->assertSame(1, $states['scan']['estimated']);
        $this->assertSame(
            'Fotoquelle nicht verfügbar. Gespeicherter Bestand bleibt erhalten.',
            $states['scan']['warning']
        );
        $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT COUNT(*) FROM import_inventory')->fetchColumn()
        );
        try {
            $this->library->jobs->start('scan');
            $this->fail('Starting an import must still reject an unavailable source.');
        } catch (RuntimeException $exception) {
            $this->assertSame($states['scan']['warning'], $exception->getMessage());
        }
        $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
        rename($this->root . '/temporarily-unavailable', $this->root . '/photos');
        $this->assertSame('', $this->library->jobs->all()['scan']['warning']);
    }

    public function testScanCheckpointPercentAndStaleRunsCannotRestartWork(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Zweiter.jpg');
        $jobs = $this->library->jobs;
        $scan = $jobs->start('scan');
        $oldToken = $scan['token'];
        $scan = $jobs->step('scan', $oldToken, scanLimit: 1);
        $this->assertSame(1, $scan['completed']);
        $this->assertLessThan(100, $scan['percent']);
        $this->assertSame(0, $scan['estimated']);
        $jobs->pause('scan');
        $this->assertSame($scan['completed'], $jobs->step('scan', $oldToken)['completed']);
        $reopened = new PhotoButler($this->root);
        $this->assertSame('paused', $reopened->jobs->all()['scan']['status']);
        $newRun = $reopened->jobs->start('scan');
        $this->assertNotSame($oldToken, $newRun['token']);
        $this->assertSame('paused', $jobs->step('scan', $oldToken)['status']);
        $lock = fopen($this->root . '/.data/cli-scan.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $jobs->pause('scan', $oldToken);
            $this->assertSame('running', $reopened->jobs->all()['scan']['status']);
        } finally {
            fclose($lock);
        }
        $this->assertSame('paused', $reopened->jobs->all()['scan']['status']);
        $completed = $reopened->jobs->step('scan', $newRun['token']);
        $this->assertSame(2, $completed['completed']);
        $this->assertSame(100, $completed['percent']);
        $this->assertSame(0, $completed['estimated']);
        $this->assertSame('done', $jobs->pause('scan')['status']);
    }

    public function testPreviewJobRefreshesAnEmptyCheckpointAndReusesBothCaches(): void
    {
        $jobs = $this->library->jobs;
        $jobs->start('previews');
        $jobs->pause('previews');
        $this->library->index();
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Zweiter.jpg');
        $this->library->index();
        $run = $jobs->start('previews');
        $this->assertSame(2, $run['total']);
        $run = $jobs->step('previews', $run['token'], previewLimit: 1);
        $this->assertSame(50, $run['percent']);
        $jobs->pause('previews');
        $run = $jobs->start('previews');
        $this->assertSame(1, $run['cursor']);
        $run = $jobs->step('previews', $run['token'], previewLimit: 1);
        $this->assertSame(100, $run['percent']);
        $thumbnail = $this->library->imagePath(1);
        touch($thumbnail, 1234567890);
        $run = $jobs->start('previews');
        $jobs->step('previews', $run['token'], previewLimit: 1);
        clearstatcache();
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertSame('pending', $this->library->photo(1)->status);
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testJobEstimatesPersistWithoutCountingPausesOrStartingWork(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Second.jpg');
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Third.jpg');
        $this->library->index();
        foreach ($this->library->jobs->all() as $state) {
            $this->assertNull($state['eta_seconds']);
            $this->assertSame('Noch nicht abschätzbar', $state['eta']);
        }
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token'], scanLimit: 1);
        $this->assertSame(3, $run['completed']);
        $this->assertSame(2, $run['remaining_files']);
        $this->assertGreaterThan(0, $run['eta_seconds']);
        $this->library->jobs->pause('scan');
        $reopened = new PhotoButler($this->root);
        $this->assertSame($run['eta_seconds'], $reopened->jobs->all()['scan']['eta_seconds']);
        $run = $reopened->jobs->start('previews');
        $run = $reopened->jobs->step('previews', $run['token'], previewLimit: 1);
        $this->assertGreaterThan(0, $run['eta_seconds']);
        $reopened->jobs->pause('previews');
        $this->assertSame($run['eta_seconds'], new PhotoButler($this->root)->jobs->all()['previews']['eta_seconds']);
        $run = $reopened->jobs->start('previews');
        $run = $reopened->jobs->step('previews', $run['token']);
        $this->assertSame(0, $run['eta_seconds']);
        $this->assertSame('Abgeschlossen', $run['eta']);
        $this->assertSame(0, (int) $reopened->database->query('SELECT SUM(attempted) FROM photos')->fetchColumn());
    }

    public function testAllJobEstimatesFormatKnownDurationsAndKeepUnknownsHonest(): void
    {
        $this->library->index();
        $this->library->importProgress(refresh: true);
        foreach (['scan', 'previews', 'tag', 'faces'] as $job) {
            $this->library->database
                ->prepare('INSERT INTO job_timings (job, seconds_per_file) VALUES (?, ?)')
                ->execute([$job === 'previews' ? 'thumbnails' : $job, 4800]);
        }
        foreach ($this->library->jobs->all() as $state) {
            $this->assertSame('ca. 1 Std. 20 Min.', $state['eta']);
        }
        foreach (
            [1 => 'Unter 1 Min.', 60 => 'ca. 1 Min.', 3600 => 'ca. 1 Std.', 90000 => 'ca. 1 Tag 1 Std.']
            as $seconds => $expected
        ) {
            $this->library->database->exec('UPDATE job_timings SET seconds_per_file = ' . $seconds);
            $this->assertSame($expected, $this->library->jobs->all()['previews']['eta']);
        }
        $this->library->database->exec("UPDATE jobs SET status = 'error', errors = 1 WHERE job = 'previews'");
        $state = $this->library->jobs->all()['previews'];
        $this->assertNull($state['eta_seconds']);
        $this->assertSame('Fehler prüfen', $state['eta']);
        $this->library->database->exec('DELETE FROM import_inventory');
        $this->assertSame('Noch nicht abschätzbar', $this->library->jobs->all()['scan']['eta']);
    }

    public function testPreviewBatchesAreBoundedAndRetainManualSnapshotCheckpoints(): void
    {
        for ($i = 0; $i < 29; $i++) {
            $this->copyDistinctPhoto(
                $this->root . '/photos/Urlaub/Meer.jpg',
                $this->root . '/photos/Urlaub/' . $i . '.jpg'
            );
        }
        $this->library->index();
        foreach ($this->library->photos() as $photo) {
            $this->library->imagePath($photo->id);
        }
        $run = $this->library->jobs->start('previews');
        $run = $this->library->jobs->step('previews', $run['token'], previewLimit: 1000);
        $this->assertSame(25, $run['completed']);
        $this->assertSame(30, $run['total']);
        $this->assertSame('running', $run['status']);
        $this->library->jobs->pause('previews');
        $reopened = new PhotoButler($this->root);
        $this->assertSame(25, $reopened->jobs->all()['previews']['completed']);
        $this->assertSame('paused', $reopened->jobs->step('previews', $run['token'])['status']);
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Later.jpg');
        $reopened->index();
        $run = $reopened->jobs->start('previews');
        $this->assertSame(25, $run['cursor']);
        $this->assertSame(30, $run['maximum']);
        $run = $reopened->jobs->step('previews', $run['token']);
        $this->assertSame(30, $run['completed']);
        $this->assertSame(100, $run['percent']);
        $this->assertSame('done', $run['status']);
        $this->assertSame(0, (int) $reopened->database->query('SELECT SUM(attempted) FROM photos')->fetchColumn());
        $this->assertSame(0, (int) $reopened->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testPreviewCheckpointAllowsConcurrentWritesBeforeSavingTiming(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Second.jpg');
        $this->library->index();
        foreach ($this->library->photos() as $photo) {
            $this->library->imagePath($photo->id);
        }
        $database = $this->library->database;
        unlink($this->root . '/photos/Urlaub/Meer.jpg');
        unlink($this->root . '/photos/Urlaub/Second.jpg');
        $writer = new PDO('sqlite:' . $this->root . '/.data/database.sqlite');
        $database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ConcurrentJobCheckpointStatement::class, [$writer]]);
        try {
            $run = $this->library->jobs->start('previews');
            $state = $this->library->jobs->step('previews', $run['token'], previewLimit: 1);
            $this->assertSame('running', $state['status']);
            $this->assertSame(1, $state['completed']);
            $this->assertSame(1, $state['cursor']);
            $this->assertSame(50, $state['percent']);
            $this->assertSame(0, $state['errors']);
            $this->assertGreaterThan(0, $state['seconds_per_file']);
            $this->assertNotNull($state['eta_seconds']);
            $this->library->jobs->pause('previews');
            $reopened = new PhotoButler($this->root);
            $this->assertSame('paused', $reopened->jobs->all()['previews']['status']);
            $this->assertSame(1, $reopened->jobs->all()['previews']['cursor']);
            $run = $this->library->jobs->start('previews');
            $state = $this->library->jobs->step('previews', $run['token']);
            $this->assertSame('done', $state['status']);
            $this->assertSame(2, $state['completed']);
            $this->assertSame(100, $state['percent']);
            $this->assertSame(0, $state['eta_seconds']);
            $this->assertSame(2, (int) $writer->query("SELECT completed FROM jobs WHERE job = 'scan'")->fetchColumn());
            $this->assertStringNotContainsString(
                'Schritt abgebrochen',
                implode(' ', array_column($state['log'], 'message'))
            );
        } finally {
            $database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
    }

    public function testPreviewBatchYieldsAfterAnExpensivePair(): void
    {
        foreach (['Second', 'Third'] as $name) {
            $this->copyDistinctPhoto(
                $this->root . '/photos/Urlaub/Meer.jpg',
                $this->root . '/photos/Urlaub/' . $name . '.jpg'
            );
        }
        $this->library->index();
        $process = proc_open(
            [
                'php',
                '-r',
                '$lock=fopen($argv[1],"c"); flock($lock,LOCK_EX); echo "ready\n"; usleep(1000000);',
                $this->root . '/.data/thumbnail-1.lock'
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            $run = $this->library->jobs->start('previews');
            $run = $this->library->jobs->step('previews', $run['token']);
        } finally {
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process));
        }
        $this->assertSame(2, $run['completed']);
        $this->assertSame('running', $run['status']);
        $this->assertNull($this->library->imagePath(3, cachedOnly: true));
        $this->library->jobs->pause('previews');
        $this->assertSame(2, $this->library->jobs->step('previews', $run['token'])['completed']);
    }

    public function testPreviewBatchHonorsPauseBetweenPairs(): void
    {
        foreach (['Second', 'Third'] as $name) {
            $this->copyDistinctPhoto(
                $this->root . '/photos/Urlaub/Meer.jpg',
                $this->root . '/photos/Urlaub/' . $name . '.jpg'
            );
        }
        $this->library->index();
        $this->library->database->exec(
            "CREATE TRIGGER pause_preview AFTER UPDATE OF width ON photos BEGIN UPDATE jobs SET status = 'paused' WHERE job = 'previews'; END"
        );
        $run = $this->library->jobs->start('previews');
        $run = $this->library->jobs->step('previews', $run['token']);
        $this->assertSame(2, $run['completed']);
        $this->assertSame('paused', $run['status']);
        $this->assertSame(66, $run['percent']);
        $this->assertNull($this->library->imagePath(3, cachedOnly: true));
    }

    public function testJobLocksAreIndependentAndResetProtectsBothAnalysisQueues(): void
    {
        $jobs = $this->library->jobs;
        $lock = fopen($this->root . '/.data/job-faces.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame('running', $jobs->start('tag')['status']);
            $this->assertSame('paused', $jobs->pause('faces')['status']);
            try {
                $jobs->start('faces');
                $this->fail('Overlapping face steps must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
            try {
                $this->library->resetAnalysis();
                $this->fail('Reset must not race a face step.');
            } catch (RuntimeException $exception) {
                $this->assertSame(409, $exception->getCode());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->library->resetAnalysis();
        $this->assertSame('paused', $jobs->all()['tag']['status']);
        $this->assertSame('paused', $jobs->all()['faces']['status']);
    }

    public function testCliRequiresOneExplicitJobAndPersistsBoundedProgress(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Zweiter.jpg');
        $run = function (array $arguments): array {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-index', '--root=' . $this->root, ...$arguments],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output];
        };
        $this->assertSame(1, $run([])[0]);
        $this->assertSame(1, $run(['--scan-only', '--tag-only'])[0]);
        foreach (['--limit=0', '--limit=-1', '--limit=invalid', '--scan-limit=0'] as $limit) {
            $this->assertSame(1, $run(['--scan-only', $limit])[0]);
        }
        foreach (['scan', 'previews', 'tag', 'faces'] as $job) {
            $lock = fopen($this->root . '/.data/cli-' . $job . '.lock', 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
            try {
                $this->assertSame(1, $run(['--' . $job . '-only'])[0]);
                $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
            } finally {
                fclose($lock);
            }
        }
        $this->assertCount(0, $this->library->photos());
        $this->assertSame(0, $run(['--scan-only', '--scan-limit=1'])[0]);
        $this->assertSame('paused', $this->library->jobs->all()['scan']['status']);
        $this->assertSame(1, $this->library->jobs->all()['scan']['completed']);
        [$code, $output] = $run(['--scan-only']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('100 %', $output);
        $this->assertSame('idle', $this->library->jobs->all()['tag']['status']);
        $this->assertSame('idle', $this->library->jobs->all()['faces']['status']);
        [$code, $output] = $run(['--previews-only', '--limit=1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('50 %', $output);
        $this->assertSame('paused', $this->library->jobs->all()['previews']['status']);
        $this->assertSame(0, $run(['--previews-only', '--limit=1'])[0]);
        $this->assertSame('done', $this->library->jobs->all()['previews']['status']);
        $this->assertSame(1, $run(['--tag-only', '--limit=1'])[0]);
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
        $this->assertSame(1, $run(['--faces-only', '--limit=1'])[0]);
        $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
        $this->assertSame(1, $this->library->jobs->all()['tag']['errors']);
    }

    public function testTaggingNeverProcessesFaces(): void
    {
        $this->library->index();
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $this->assertSame(0, $this->library->tag(1));
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testIndexIsIdempotentAndKeepsOriginals(): void
    {
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        $hash = hash_file('sha256', $path);
        $this->library->index();
        $this->library->index();
        $photos = $this->library->photos();
        $this->assertCount(1, $photos);
        $this->assertSame('Urlaub', $photos[0]->album);
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertFileExists($this->library->imagePath($photos[0]->id));
    }

    public function testStickerArchiveRendersPreviewAndAnimationWithoutChangingOriginal(): void
    {
        $source = $this->root . '/photos/Urlaub/sticker.webp';
        $archive = new ZipArchive();
        $archive->open($source, ZipArchive::CREATE);
        $archive->addFromString('animation/animation.json', file_get_contents(__DIR__ . '/fixtures/sticker.json'));
        $archive->close();
        $hash = hash_file('sha256', $source);
        $this->library->index();
        $id = $this->library->photos(query: 'sticker')[0]->id;
        $preview = $this->library->imagePath($id);
        $this->assertNotNull($preview);
        $this->assertSame('image/jpeg', getimagesize($preview)['mime']);
        $this->assertSame(64, $this->library->photo($id)->width);
        $animation = $this->library->imagePath($id, animated: true);
        $this->assertSame('image/webp', getimagesize($animation)['mime']);
        $this->assertStringContainsString('ANIM', file_get_contents($animation));
        $this->assertSame($source, $this->library->imagePath($id, original: true));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.detail*'));
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertSame($animation, $this->library->imagePath($id, animated: true));
        unlink($preview);
        $this->assertFileExists($this->library->imagePath($id));
        imagejpeg(imagecreatetruecolor(64, 64), $source);
        clearstatcache();
        $this->library->index();
        $this->assertFileDoesNotExist($animation);
        $this->assertSame($this->library->imagePath($id), $this->library->imagePath($id, animated: true));
    }

    public function testAnimatedWebpGetsAnAiPreviewAndKeepsPlaying(): void
    {
        $source = $this->root . '/photos/Urlaub/animated.webp';
        copy(__DIR__ . '/fixtures/animated-sticker.webp', $source);
        $hash = hash_file('sha256', $source);
        $this->library->index();
        $id = $this->library->photos(query: 'animated')[0]->id;
        $preview = $this->library->imagePath($id);
        $this->assertNotNull($preview);
        $this->assertSame('image/jpeg', getimagesize($preview)['mime']);
        $this->assertSame(32, $this->library->photo($id)->width);
        $this->assertStringContainsString('ANIM', file_get_contents($this->library->imagePath($id, animated: true)));
        $this->assertSame($source, $this->library->imagePath($id, original: true));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.detail*'));
        $this->assertSame($hash, hash_file('sha256', $source));
    }

    public function testOtherZipEntriesAreNeverExtracted(): void
    {
        $source = $this->root . '/photos/Urlaub/sticker.webp';
        $archive = new ZipArchive();
        $archive->open($source, ZipArchive::CREATE);
        $archive->addFromString('../escaped.json', '{}');
        $archive->addFromString('animation/animation.json', str_repeat(' ', 4194305));
        $archive->close();
        $this->library->index();
        $id = $this->library->photos(query: 'sticker')[0]->id;
        $this->assertNull($this->library->imagePath($id));
        $this->assertFileDoesNotExist($this->root . '/escaped.json');
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
    }

    public function testSearchTagsAndFavorites(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, ' Küste, Meer, Meer ');
        $this->library->favorite($id, true);
        $this->assertCount(1, $this->library->photos(query: 'KÜSTE', favorites: true));
        $this->assertCount(1, $this->library->photos(tag: 'Meer'));
        $this->assertCount(0, $this->library->photos(query: '%'));
        $this->assertSame(['Küste', 'Meer'], $this->library->photo($id)->tags);
    }

    public function testGalleryOnlyLinksToJobsAndOffersInfiniteLoading(): void
    {
        $this->library->index();
        $photos = array_fill(0, 60, $this->library->photos()[0]);
        $stats = [
            'total' => 61,
            'tagged' => 0,
            'errors' => 0,
            'favorites' => 0,
            'queued' => 61,
            'face_done' => 0,
            'face_errors' => 0
        ];
        $csrf = 'test-csrf';
        $title = 'Fotos';
        $sort = 'month_asc';
        $relevance = 'all';
        $seed = '';
        $galleryPreferences = http_build_query(['sort' => $sort, 'relevance' => $relevance]);
        $selectedPhoto = 0;
        $favorites = '0';
        $peopleView = false;
        $person = 0;
        $persons = [];
        $album = $query = $tag = '';
        $page = 1;
        $offset = 0;
        $tags = [];
        $pagination = [
            'q' => 'Meer & Strand',
            'album' => 'Urlaub',
            'tag' => 'Meer',
            'favorites' => '1',
            'sort' => $sort
        ];
        $escape = fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        ob_start();
        require dirname(__DIR__) . '/templates/gallery.php';
        $html = ob_get_clean();
        $this->assertStringContainsString(' · photobutler</title>', $html);
        $this->assertStringNotContainsString('Photobutler', $html);
        $this->assertStringContainsString('type="module" src="?asset=navigation.js"', $html);
        $this->assertLessThan(strpos($html, '?asset=app.css'), strpos($html, '?asset=preferences.js'));
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame(7, $document->querySelectorAll('#gallery-columns option')->length);
        foreach (range(3, 9) as $columns) {
            $this->assertSame(
                (string) $columns,
                $document->querySelector('#gallery-columns option[value="' . $columns . '"]')->textContent
            );
        }
        $this->assertSame('5', $document->querySelector('#gallery-columns option[selected]')->getAttribute('value'));
        $this->assertSame(5, $document->querySelectorAll('#gallery-sort option')->length);
        $this->assertSame($sort, $document->querySelector('#gallery-sort option[selected]')->getAttribute('value'));
        $this->assertNull($document->querySelector('.search'));
        $this->assertNull($document->querySelector('input[name="q"]'));
        $this->assertNull($document->querySelector('.sidebar a[href*="favorites="]'));
        $this->assertSame('all', $document->querySelector('#gallery-relevance option')->getAttribute('value'));
        $this->assertSame(
            'Eingeblendete Fotos',
            $document->querySelector('#gallery-relevance option[value="relevant"]')->textContent
        );
        $this->assertSame(
            'Ausgeblendete Fotos',
            $document->querySelector('#gallery-relevance option[value="excluded"]')->textContent
        );
        $this->assertSame(3, $document->querySelectorAll('#gallery-favorites option')->length);
        $this->assertSame('0', $document->querySelector('#gallery-favorites option[selected]')->getAttribute('value'));
        $this->assertSame('Spalten', $document->querySelector('#gallery-columns')->getAttribute('aria-label'));
        $this->assertSame('Sortierung', $document->querySelector('#gallery-sort')->getAttribute('aria-label'));
        $this->assertSame(
            [
                'Neueste zuerst',
                'Älteste zuerst',
                'Kalendermonat Januar–Dezember',
                'Kalendermonat Dezember–Januar',
                'Zufällig'
            ],
            array_map(
                fn($option): string => $option->textContent,
                iterator_to_array($document->querySelectorAll('#gallery-sort option'))
            )
        );
        foreach ($document->querySelectorAll('.nav-item:not([href="?view=jobs"]), .brand') as $link) {
            $this->assertStringContainsString('sort=' . $sort, $link->getAttribute('href'));
        }
        $this->assertNotNull($document->querySelector('.sidebar a[href="?view=jobs"]'));
        foreach (['#tag-count', '#worker-message', '#worker-progress', '#scan-start', '#tag-pending'] as $selector) {
            $this->assertNull($document->querySelector($selector));
        }
        $this->assertNull($document->querySelector('.pagination'));
        $this->assertNull($document->querySelector('.album-nav'));
        $this->assertNull($document->querySelector('.albums-section'));
        $this->assertStringNotContainsString('Alben', $html);
        $this->assertStringNotContainsString('Selbst gehostet', $html);
        $this->assertNull($document->querySelector('.private-badge'));
        $this->assertSame(4, $document->querySelectorAll('#gallery-relevance option')->length);
        $this->assertSame(
            'all',
            $document->querySelector('#gallery-relevance option[selected]')->getAttribute('value')
        );
        $this->assertNotNull($document->querySelector('#gallery-slideshow'));
        $this->assertTrue($document->querySelector('#slideshow-stop')->hasAttribute('hidden'));

        $this->assertSame(
            '?photo=' . $photos[0]->id . '&size=display',
            $document->querySelector('.photo-card img')->getAttribute('src')
        );
        parse_str(
            parse_url($document->querySelector('#photo-loader')->getAttribute('data-next'), PHP_URL_QUERY),
            $next
        );
        $this->assertSame($pagination + ['page' => '2', 'offset' => '60'], $next);
    }

    public function testPhotoBatchesRetainFiltersAndDoNotOverlap(): void
    {
        $this->library->index();
        for ($number = 0; $number < 64; $number++) {
            $statement = $this->library->database->prepare("INSERT INTO photos
                (root, path, album, name, modified, bytes, width, height, taken, seen, ai_tags, priority)
                VALUES ('/photos', ?, 'Urlaub', 'Meer.jpg', 1, 1, 0, 0, '2026-01-01', 'test', '[\"Meer\"]', 1)");
            $statement->execute(['/photos/' . $number . '.jpg']);
        }
        $first = $this->library->photos(query: 'Meer', album: 'Urlaub', tag: 'Meer', favorites: true);
        $second = $this->library->photos(query: 'Meer', album: 'Urlaub', tag: 'Meer', favorites: true, page: 2);
        $this->assertCount(60, $first);
        $this->assertCount(4, $second);
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->assertSame([], $this->library->photos(tag: 'Meer', favorites: true, page: 3));
    }

    public function testOffsetPaginationRetainsEveryRemainingPhotoAfterRatings(): void
    {
        $insert = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen)
            VALUES ('/photos', ?, 'Urlaub', 'Meer', 1, 1, 80, 60, '2024-01-01', 1)");
        for ($number = 0; $number < 125; $number++) {
            $insert->execute(['/photos/' . $number . '.jpg']);
        }
        foreach (['newest', 'oldest', 'month_asc', 'month_desc', 'random'] as $sort) {
            foreach (['relevant', 'unrated', 'excluded'] as $relevance) {
                $initialPriority = match ($relevance) {
                    'relevant' => 1,
                    'excluded' => -1,
                    default => 0
                };
                $this->library->database->exec('UPDATE photos SET priority = ' . $initialPriority);
                $first = $this->library->photos(sort: $sort, relevance: $relevance, seed: 'a');
                $expectedNext = $this->library->photos(page: 2, sort: $sort, relevance: $relevance, seed: 'a');
                foreach (array_slice($first, 0, 2) as $photo) {
                    $this->library->priority($photo->id, $initialPriority === -1 ? 0 : -1);
                }
                $next = $this->library->photos(page: 2, sort: $sort, relevance: $relevance, seed: 'a', offset: 58);
                $this->assertSame(array_column($expectedNext, 'id'), array_column($next, 'id'));
                $last = $this->library->photos(sort: $sort, relevance: $relevance, seed: 'a', offset: 118);
                $this->assertCount(5, $last);
                $this->assertCount(
                    123,
                    array_unique(array_column([...array_slice($first, 2), ...$next, ...$last], 'id'))
                );
            }
        }
    }

    public function testFavoritesFilterSupportsAllOnlyAndExcludedFavorites(): void
    {
        $this->library->index();
        $this->library->database->exec("UPDATE photos SET priority = 1, taken = '2024-01-01'");
        $this->assertCount(1, $this->library->photos());
        $this->assertCount(1, $this->library->photos(favorites: '0'));
        $this->assertCount(1, $this->library->photos(favorites: '1'));
        $this->assertCount(1, $this->library->photos(favorites: true));
        $this->assertSame([], $this->library->photos(favorites: 'none'));
        $this->library->database->exec('UPDATE photos SET priority = 0');
        $this->assertCount(0, $this->library->photos(favorites: 'none', relevance: 'relevant'));
        $this->assertCount(1, $this->library->photos(favorites: false));
        $this->assertSame([], $this->library->photos(favorites: '1'));
        $this->library->database->exec("UPDATE photos SET taken = '2022-12-31', priority = -1");
        $this->assertSame([], $this->library->photos(favorites: 'none', relevance: 'relevant'));
    }

    public function testRelevanceUsesPriorityBeforePagination(): void
    {
        $insert = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen, priority, ai_tags)
            VALUES ('/photos', ?, ?, ?, 1, 1, 80, 60, ?, 1, 1, json_array('Meer'))");
        foreach (
            [
                ['Urlaub/old.jpg', '2022-12-31 23:59:59', false],
                ['Urlaub/boundary.jpg', '2023-01-01 00:00:00', true],
                ['_WHATSAPP/.Statuses/story.jpg', '2025-01-01', false],
                ['_WHATSAPP/.Statuses/nested/story.jpg', '2025-01-01', false],
                ['_WHATSAPP/WhatsApp Animated Gifs/Sent/animation.jpg', '2025-01-01', false],
                ['_WHATSAPP/WhatsApp Images/animation.GIF', '2025-01-01', false],
                ['_WHATSAPP/WhatsApp Images/photo.jpg', '2025-01-01', true],
                ['_WHATSAPP/WhatsApp Images/Private/private.jpg', '2025-01-01', true],
                ['_WHATSAPP/WhatsApp Stickers/sticker.webp', '2025-01-01', true],
                ['Urlaub/animation.gif', '2025-01-01', true],
                ['_WHATSAPP/.Statuses-backup/other.jpg', '2025-01-01', true]
            ]
            as [$path, $date, $relevant]
        ) {
            $insert->execute(['/photos/' . $path, dirname($path), basename($path), $date]);
            $id = (int) $this->library->database->lastInsertId();
            $this->library->priority($id, $relevant ? 1 : -1);
            $this->assertSame(
                $relevant,
                in_array($id, array_column($this->library->photos(relevance: 'relevant'), 'id'), true)
            );
        }
        $this->assertCount(11, $this->library->photos());
        for ($number = 0; $number < 65; $number++) {
            $insert->execute(['/photos/Urlaub/Meer-' . $number . '.jpg', 'Urlaub', 'Meer-' . $number, '2024-01-01']);
        }
        $first = $this->library->photos(query: 'Meer-', tag: 'Meer', favorites: true, relevance: 'relevant');
        $second = $this->library->photos(query: 'Meer-', tag: 'Meer', favorites: true, relevance: 'relevant', page: 2);
        $this->assertCount(60, $first);
        $this->assertCount(5, $second);
        $this->assertCount(65, array_unique(array_column([...$first, ...$second], 'id')));
    }

    public function testRandomSortingKeepsASeededOrderAcrossFilteredPages(): void
    {
        $insert = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen, priority)
            VALUES ('/photos', ?, 'Urlaub', 'Meer', 1, 1, 80, 60, '2024-01-01', 1, 1)");
        for ($number = 0; $number < 125; $number++) {
            $insert->execute(['/photos/' . $number . '.jpg']);
        }
        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $photos = $this->library->photos(
                query: 'Meer',
                page: $page,
                sort: 'random',
                seed: 'a',
                relevance: 'relevant'
            );
            $this->assertEquals(
                $photos,
                $this->library->photos(query: 'Meer', page: $page, sort: 'random', seed: 'a', relevance: 'relevant')
            );
            $ids = [...$ids, ...array_column($photos, 'id')];
        }
        $this->assertCount(125, array_unique($ids));
        $this->assertNotSame(range(1, 125), $ids);
        $this->assertNotSame(
            array_slice($ids, 0, 60),
            array_column($this->library->photos(sort: 'random', seed: 'b'), 'id')
        );
        $this->assertSame([], $this->library->photos(query: 'missing', sort: 'random', seed: 'a'));
    }

    public function testDateSortingAppliesToAllFilteredPhotosBeforePagination(): void
    {
        $this->library->index();
        $dates = [
            '2024-01-20 10:00:00',
            '2025-12-05 12:00:00',
            '2026-02-10 08:00:00',
            '2023-12-01 00:00:00',
            '2026-01-02 09:00:00'
        ];
        $expected = [];
        $statement = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen, ai_tags, priority)
            VALUES ('/photos', ?, 'Urlaub', 'Meer.jpg', 1, 1, 0, 0, ?, 'test', '[\"Meer\"]', 1)");
        for ($number = 0; $number < 65; $number++) {
            $date = $dates[$number % count($dates)];
            $statement->execute(['/photos/sorting-' . $number . '.jpg', $date]);
            $expected[] = ['id' => (int) $this->library->database->lastInsertId(), 'taken' => $date];
        }
        foreach (['newest', 'oldest', 'month_asc', 'month_desc', 'invalid; DROP TABLE photos'] as $sort) {
            usort($expected, static function (array $first, array $second) use ($sort): int {
                if (str_starts_with($sort, 'month_')) {
                    $comparison = strcmp(substr($first['taken'], 5, 2), substr($second['taken'], 5, 2));
                    if ($comparison !== 0) {
                        return $sort === 'month_asc' ? $comparison : -$comparison;
                    }
                }
                $comparison = strcmp($first['taken'], $second['taken']) ?: $first['id'] <=> $second['id'];
                return $sort === 'oldest' ? $comparison : -$comparison;
            });
            $first = $this->library->photos(query: 'Meer', album: 'Urlaub', tag: 'Meer', favorites: true, sort: $sort);
            $second = $this->library->photos(
                query: 'Meer',
                album: 'Urlaub',
                tag: 'Meer',
                favorites: true,
                page: 2,
                sort: $sort
            );
            $this->assertCount(60, $first);
            $this->assertCount(5, $second);
            $this->assertSame(array_column($expected, 'id'), array_column([...$first, ...$second], 'id'), $sort);
        }
        $this->assertEquals($this->library->photos(sort: 'newest'), $this->library->photos());
    }

    public function testViewerLoadsOriginalAndDownloadsOriginal(): void
    {
        $script = file_get_contents(dirname(__DIR__) . '/assets/app.js');
        $this->assertStringContainsString('$image.src = `?photo=${id}&size=original`;', $script);
        $this->assertStringNotContainsString(
            '$image.src = `?photo=${$cards[index].dataset.photo}&size=thumb`;',
            $script
        );
        $this->assertStringContainsString('$download.href = `?photo=${photo.id}&size=original&download=1`;', $script);
    }

    public function testOriginalImageAccessKeepsFullResolutionWithoutGeneratingPreview(): void
    {
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        imagejpeg(imagecreatetruecolor(3200, 2400), $path, 100);
        $hash = hash_file('sha256', $path);
        $this->library->index();
        $original = $this->library->imagePath($this->library->photos()[0]->id, original: true);
        $this->assertSame($path, $original);
        $this->assertSame([3200, 2400], array_slice(getimagesize($original), 0, 2));
        $this->assertSame($hash, hash_file('sha256', $original));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.jpg'));
        $thumbnail = $this->library->imagePath($this->library->photos()[0]->id);
        $this->assertSame([640, 480], array_slice(getimagesize($thumbnail), 0, 2));
        $this->assertSame($hash, hash_file('sha256', $original));
    }

    public function testMissingAndEscapingFilesAreNotServed(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        rename($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/outside.jpg');
        symlink($this->root . '/outside.jpg', $this->root . '/photos/Urlaub/Meer.jpg');
        $this->assertNull($this->library->imagePath($id, original: true));
        $this->assertNull($this->library->imagePath($id));
        $this->library->index();
        $this->assertCount(0, $this->library->photos());
    }

    public function testChangedFileIsQueuedAgainWithoutLosingManualTags(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, 'Familie');
        $this->library->favorite($id, true);
        touch($this->root . '/photos/Urlaub/Meer.jpg', time() + 10);
        $this->library->index();
        $this->assertSame(['Familie'], $this->library->photo($id)->tags);
        $this->assertTrue($this->library->photo($id)->favorite);
        $this->assertSame('pending', $this->library->photo($id)->status);
    }

    public function testAiResultRejectsMalformedAndOversizedTags(): void
    {
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '```json' . "\n" . '{"description":"Ein Strand.","tags":["Meer","Meer"," Strand "]}' . "\n```"
        );
        $this->assertSame(['Meer', 'Strand'], $result->tags);
        $this->expectException(UnexpectedValueException::class);
        new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '{"description":"x","tags":[{"bad":true}]}'
        );
    }

    public function testUnavailableRootPreservesIndex(): void
    {
        $this->library->index();
        rename($this->root . '/photos', $this->root . '/offline');
        try {
            $this->library->index();
            $this->fail('Expected an unavailable source to abort indexing.');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->library->photos());
        }
    }

    public function testAihelperDecodedJsonResponseIsAccepted(): void
    {
        $response = json_decode('{"description":"Ein Strand.","tags":["Meer","Strand"]}');
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke($this->library, $response);
        $this->assertSame('Ein Strand.', $result->description);
        $this->assertSame(['Meer', 'Strand'], $result->tags);
    }

    public function testLimitedScanDoesNotHideUnvisitedPhotos(): void
    {
        $this->library->index();
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Berge.jpg');
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Wald.jpg');
        $this->assertSame(1, $this->library->index(limit: 1));
        $this->assertCount(2, $this->library->photos());
        $this->library->index();
        $this->assertCount(3, $this->library->photos());
    }

    public function testScanFingerprintsAlbumsWithoutDecodingImages(): void
    {
        for ($number = 0; $number < 12; $number++) {
            mkdir($this->root . '/photos/album-' . $number);
            file_put_contents($this->root . '/photos/album-' . $number . '/photo.jpg', 'not decoded ' . $number);
        }
        $this->assertSame(13, $this->library->index());
        $this->assertSame(
            13,
            (int) $this->library->database->query('SELECT COUNT(DISTINCT album) FROM photos')->fetchColumn()
        );
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.jpg'));
    }

    public function testScanProgressCountsUnchangedPhotosAcrossResumedBatches(): void
    {
        $this->library->index();
        mkdir($this->root . '/photos/2026+');
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/2026+/new.jpg');
        $this->assertSame(1, $this->library->index(limit: 1));
        $scan = json_decode($this->library->database->query('SELECT state FROM scan_state')->fetchColumn());
        $this->assertSame(1, $scan->progress->checked);
        $this->assertSame(1, $scan->progress->changed);
        $this->assertSame('2026+', $scan->progress->folder);
        $this->library = new PhotoButler($this->root);
        $this->assertSame(0, $this->library->index(limit: 1));
        $scan = json_decode($this->library->database->query('SELECT state FROM scan_state')->fetchColumn());
        $this->assertSame(2, $scan->progress->checked);
        $this->assertSame(1, $scan->progress->changed);
        $this->assertFalse($scan->progress->partial);
        $this->assertSame('Urlaub', $scan->progress->folder);
    }

    public function testLegacyScanProgressExplicitlyCountsOnlySinceContinuation(): void
    {
        $this->library->index(limit: 1);
        $saved = json_decode($this->library->database->query('SELECT state FROM scan_state')->fetchColumn());
        unset($saved->progress);
        $this->library->database->prepare('UPDATE scan_state SET state = ?')->execute([json_encode($saved)]);
        $this->library = new PhotoButler($this->root);
        $this->library->index();
        $progress = new ReflectionProperty(PhotoButler::class, 'scanProgress')->getValue($this->library);
        $this->assertTrue($progress->partial);
        $this->assertSame(0, $progress->checked);
        $this->assertSame(0, $progress->changed);
    }

    public function testScanResumesAcrossInstancesAndFinishesUnchangedBatches(): void
    {
        for ($number = 0; $number < 5; $number++) {
            $this->copyDistinctPhoto(
                $this->root . '/photos/Urlaub/Meer.jpg',
                $this->root . '/photos/Urlaub/photo-' . $number . '.jpg'
            );
        }
        $this->assertSame(2, $this->library->index(limit: 2));
        $this->library = new PhotoButler($this->root);
        $this->assertSame(2, $this->library->index(limit: 2));
        $this->library->index();
        $this->assertCount(6, $this->library->photos());
        unlink($this->root . '/photos/Urlaub/photo-4.jpg');
        $this->assertSame(0, $this->library->index(limit: 2));
        $this->assertCount(6, $this->library->photos());
        $this->assertSame(1, (int) $this->library->database->query('SELECT COUNT(*) FROM scan_state')->fetchColumn());
        $this->library->index();
        $this->assertCount(5, $this->library->photos());
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM scan_state')->fetchColumn());
    }

    public function testPhotoPreviewsAreLimitedTo640Pixels(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        imagejpeg(imagecreatetruecolor(1920, 1080), $source);
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $size = getimagesize($this->library->imagePath($id));
        $this->assertSame(640, $size[0]);
        $this->assertSame(360, $size[1]);
        $this->assertSame(1920, $this->library->photo($id)->width);
    }

    public function testThumbnailIsBoundedCachedAndInvalidatedWithoutChangingOriginal(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        $image = imagecreatetruecolor(2400, 1600);
        for ($y = 0; $y < 1600; $y++) {
            for ($x = 0; $x < 2400; $x++) {
                imagesetpixel($image, $x, $y, (($x * 73856093) ^ ($y * 19349663)) & 0xffffff);
            }
        }
        imagejpeg($image, $source, 100);
        clearstatcache();
        $hash = hash_file('sha256', $source);
        $this->assertGreaterThan(500000, filesize($source));
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
        $thumbnail = $this->library->imagePath($id);
        $this->assertNotNull($thumbnail);
        [$width, $height] = getimagesize($thumbnail);
        $this->assertSame(640, $width);
        $this->assertEqualsWithDelta(1.5, $width / $height, 0.005);
        touch($thumbnail, 1234567890);
        $this->assertSame($thumbnail, $this->library->imagePath($id));
        clearstatcache();
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertSame($source, $this->library->imagePath($id, original: true));
        imagejpeg(imagecreatetruecolor(100, 60), $source);
        touch($source, time() + 2);
        clearstatcache();
        $this->library->index();
        $this->assertFileDoesNotExist($thumbnail);
        $this->assertSame([100, 60], array_slice(getimagesize($this->library->imagePath($id)), 0, 2));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.tmp'));
    }

    public function testThumbnailJobLeavesLegacyMediumCacheAndOriginalUntouched(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        imagejpeg(imagecreatetruecolor(2400, 1800), $source);
        $originalHash = hash_file('sha256', $source);
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $thumbnail = $this->library->imagePath($id);
        $legacy = $thumbnail . '.detail.jpg';
        file_put_contents($legacy, 'legacy medium fixture');
        touch($legacy, 1234567890);
        $this->library->database->exec("INSERT INTO job_timings VALUES ('previews', 9999)");
        $this->library = new PhotoButler($this->root);
        $this->assertNull($this->library->jobs->all()['previews']['eta_seconds']);
        $job = $this->library->jobs->start('previews');
        $state = $this->library->jobs->step('previews', $job['token']);
        $this->assertSame(1, $state['completed']);
        $this->assertSame(1, $state['total']);
        $this->assertSame(0, $state['errors']);
        $this->assertSame('legacy medium fixture', file_get_contents($legacy));
        $this->assertSame(1234567890, filemtime($legacy));
        $this->assertSame($originalHash, hash_file('sha256', $source));
        touch($source, time() + 2);
        clearstatcache();
        $this->library->index();
        $this->assertFileDoesNotExist($thumbnail);
        $this->assertSame('legacy medium fixture', file_get_contents($legacy));
        $this->assertSame([640, 480], array_slice(getimagesize($this->library->imagePath($id)), 0, 2));
        $this->assertSame([$legacy], glob($this->root . '/.data/thumbnails/*.detail*'));
    }

    public function testExistingThumbnailIsReusedWithoutRegeneration(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $thumbnail = $this->library->imagePath($id);
        $hash = hash_file('sha256', $thumbnail);
        touch($thumbnail, 1234567890);
        $this->library = new PhotoButler($this->root);
        $this->assertSame($thumbnail, $this->library->imagePath($id, animated: true));
        clearstatcache();
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertSame($hash, hash_file('sha256', $thumbnail));
    }

    public function testCachedOnlyLookupAndRepeatJobDoNotRequireTheOriginal(): void
    {
        $this->library->index();
        $thumbnail = $this->library->imagePath(1);
        $original = $this->root . '/photos/Urlaub/Meer.jpg';
        rename($original, $original . '.offline');
        touch($thumbnail, 1234567890);

        $this->assertSame($thumbnail, $this->library->imagePath(1, cachedOnly: true));
        $this->assertNull($this->library->imagePath(1));
        $this->assertNull($this->library->imagePath(1, original: true, cachedOnly: true));
        foreach ([1, 2] as $repeat) {
            $run = $this->library->jobs->start('previews');
            $run = $this->library->jobs->step('previews', $run['token']);
            $this->assertSame('done', $run['status']);
            $this->assertSame(1, $run['completed']);
            $this->assertSame(0, $run['errors']);
        }
        $this->assertSame([], glob($this->root . '/.data/preview-worker-*.socket'));
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertNull($this->library->imagePath(1, animated: true, cachedOnly: false));
        file_put_contents($thumbnail . '.webp', 'cached animation');
        $this->assertSame($thumbnail . '.webp', $this->library->imagePath(1, animated: true, cachedOnly: true));
        unlink($thumbnail);
        $this->assertSame($thumbnail . '.webp', $this->library->imagePath(1, animated: true, cachedOnly: true));
        $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        unlink($thumbnail . '.webp');
        $this->assertNull($this->library->imagePath(1, animated: true, cachedOnly: true));

        rename($original . '.offline', $original);
        $run = $this->library->jobs->start('previews');
        $this->assertSame('done', $this->library->jobs->step('previews', $run['token'])['status']);
        $this->assertFileExists($thumbnail);
    }

    public function testCachedOnlyLookupStillRejectsUnavailableOrUnconfiguredPhotos(): void
    {
        $this->library->index();
        $this->library->imagePath(1);
        $this->library->database->exec('UPDATE photos SET available = 0');
        $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        $this->library->database->exec("UPDATE photos SET available = 1, root = '/unconfigured'");
        $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        $this->assertNull($this->library->imagePath(999, cachedOnly: true));
    }

    public function testMissingThumbnailPreservesAiTagsAndIsRebuiltOnDemand(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $thumbnail = $this->library->imagePath($id);
        $this->library->database->exec("UPDATE photos SET status = 'done', ai_tags = '[\"Meer\"]'");
        unlink($thumbnail);
        $this->assertSame(0, $this->library->index());
        $this->assertSame('done', $this->library->photo($id)->status);
        $this->assertSame(['Meer'], $this->library->photo($id)->tags);
        $this->assertFileExists($this->library->imagePath($id));
    }

    public function testUnreadablePreviewIsDeferredWithoutBlockingOtherPhotos(): void
    {
        file_put_contents($this->root . '/photos/Urlaub/Meer.jpg', 'unavailable image contents');
        imagejpeg(imagecreatetruecolor(80, 60), $this->root . '/photos/Urlaub/Zweiter.jpg');
        file_put_contents(
            $this->root . '/.data/.env',
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=test\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=test-only\n",
            FILE_APPEND
        );
        $this->library = new PhotoButler($this->root);
        $this->library->index();
        $this->assertSame(0, $this->library->tag(limit: 1));
        $this->assertSame('error', $this->library->photo(1)->status);
        $this->assertSame('pending', $this->library->photo(2)->status);
        $stats = new ReflectionMethod(PhotoButler::class, 'photoStats')->invoke($this->library);
        $this->assertSame(2, $stats['queued']);
    }

    public function testOversizedManualTagsAreRejectedWithoutReplacingExistingTags(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->saveTags($id, 'Meer');
        try {
            $this->library->saveTags($id, str_repeat('a', 61));
            $this->fail('Expected oversized tags to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(['Meer'], $this->library->photo($id)->tags);
        }
    }

    public function testPreviewWorkersProcessTwoImagesWithoutWaitingForTheFirst(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Second.jpg');
        $this->library->index();
        $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        $this->assertSame([], glob($this->root . '/.data/preview-worker-*.socket'));
        $lock = fopen($this->root . '/.data/thumbnail-1.lock', 'c');
        flock($lock, LOCK_EX);
        $process = proc_open(
            [
                'php',
                '-r',
                'require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; echo json_encode((new \\vielhuber\\photobutler\\PreviewPool(' .
                var_export($this->root . '/.data', true) .
                '))->render([1, 2]));'
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        try {
            $deadline = microtime(true) + 10;
            do {
                usleep(20000);
                clearstatcache();
                $second = $this->library->imagePath(2, cachedOnly: true);
            } while ($second === null && microtime(true) < $deadline);
            $this->assertNotNull($second, 'The second image must complete while the first image is blocked.');
            $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
        $this->assertSame([1 => true, 2 => true], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        $this->expectException(InvalidArgumentException::class);
        new \vielhuber\photobutler\PreviewPool($this->root . '/.data')->render([1, 2, 3]);
    }

    public function testPreviewWorkersAreReusedAndExpireWithoutAutomaticWork(): void
    {
        $this->copyDistinctPhoto($this->root . '/photos/Urlaub/Meer.jpg', $this->root . '/photos/Urlaub/Second.jpg');
        $this->library->index();
        $pool = new \vielhuber\photobutler\PreviewPool($this->root . '/.data');
        $this->assertSame([], glob($this->root . '/.data/preview-worker-*.socket'));
        $this->assertSame([1 => true, 2 => true], $pool->render([1, 2]));
        $files = glob($this->root . '/.data/preview-worker-*.socket');
        $this->assertCount(2, $files);
        $sockets = array_map('file_get_contents', $files);
        $this->assertSame(
            [1 => true, 2 => true],
            new \vielhuber\photobutler\PreviewPool($this->root . '/.data')->render([1, 2])
        );
        $this->assertSame($sockets, array_map('file_get_contents', $files));
        $reopened = new PhotoButler($this->root);
        $this->assertSame('idle', $reopened->jobs->all()['previews']['status']);
        $deadline = microtime(true) + 10;
        do {
            usleep(100000);
            clearstatcache();
        } while (glob($this->root . '/.data/preview-worker-*.socket') !== [] && microtime(true) < $deadline);
        $this->assertSame([], glob($this->root . '/.data/preview-worker-*.socket'));
        foreach ($sockets as $socket) {
            $this->assertFileDoesNotExist($socket);
            $this->assertDirectoryDoesNotExist(dirname($socket));
        }
        $run = $reopened->jobs->start('previews');
        $this->assertSame('done', $reopened->jobs->step('previews', $run['token'])['status']);
        $this->assertSame([], glob($this->root . '/.data/preview-worker-*.socket'));
    }

    public function testPreviewWorkersReloadSourcesAndRecoverAfterCacheRemoval(): void
    {
        $this->library->index();
        $pool = new \vielhuber\photobutler\PreviewPool($this->root . '/.data');
        $directory = sys_get_temp_dir() . '/photobutler-preview-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($this->root . '/.data/preview-worker-1.socket', $directory . '/worker.sock');
        $this->assertSame([1 => true], $pool->render([1]));
        $this->assertDirectoryDoesNotExist($directory);
        $registry = $this->root . '/.data/preview-worker-1.socket';
        $socket = file_get_contents($registry);
        foreach (glob($this->root . '/.data/thumbnails/*') as $cache) {
            unlink($cache);
        }
        $this->assertSame([1 => true], $pool->render([1]));
        $this->assertCount(1, glob($this->root . '/.data/thumbnails/*'));
        $config = file_get_contents($this->root . '/.data/.env');
        mkdir($this->root . '/other');
        file_put_contents($this->root . '/.data/.env', str_replace('/photos', '/other', $config));
        $this->assertSame([1 => false], $pool->render([1]));
        file_put_contents($this->root . '/.data/.env', $config);
        $this->assertSame([1 => true], $pool->render([1]));
        $this->assertSame($socket, file_get_contents($registry));
        $this->assertSame('idle', $this->library->jobs->all()['previews']['status']);
    }

    public function testPhotoRendererDetectsReplacedSourcesAndRecoversAfterInvalidImages(): void
    {
        $renderer = new \vielhuber\photobutler\PhotoRenderer();
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        $target = $this->root . '/.data/rendered.jpg';
        $renderer->render(source: $source, target: $target, edge: 640, quality: 65);
        $this->assertSame([80, 60], array_slice(getimagesize($target), 0, 2));
        imagejpeg(imagecreatetruecolor(120, 90), $source);
        $renderer->render(source: $source, target: $target, edge: 640, quality: 65);
        $this->assertSame([120, 90], array_slice(getimagesize($target), 0, 2));
        file_put_contents($source, 'invalid');
        try {
            $renderer->render(source: $source, target: $target, edge: 640, quality: 65);
            $this->fail('Invalid images must not reuse the previous pixel buffer.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($target . '.tmp');
        }
        imagejpeg(imagecreatetruecolor(160, 120), $source);
        $renderer->render(source: $source, target: $target, edge: 640, quality: 65);
        $this->assertSame([160, 120], array_slice(getimagesize($target), 0, 2));
    }

    public function testLargePhotoRendererPreservesEveryExifOrientation(): void
    {
        $this->library = new PhotoButler($this->root, photoRenderer: new \vielhuber\photobutler\PhotoRenderer());
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        $image = imagecreatetruecolor(3200, 2400);
        imagefilledrectangle($image, 0, 0, 1599, 1199, 0xf00000);
        imagejpeg($image, $source, 100);
        $jpeg = file_get_contents($source);
        foreach (
            [1 => [0, 0], 2 => [1, 0], 3 => [1, 1], 4 => [0, 1], 5 => [0, 0], 6 => [1, 0], 7 => [1, 1], 8 => [0, 1]]
            as $orientation => [$right, $bottom]
        ) {
            $exif =
                "Exif\0\0II" . pack('vVv', 42, 8, 1) . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
            file_put_contents(
                $source,
                substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2)
            );
            touch($source, time() + $orientation);
            clearstatcache();
            $this->library->index();
            $id = $this->library->photos()[0]->id;
            $thumbnail = $this->library->imagePath($id);
            $size = $orientation >= 5 ? [480, 640] : [640, 480];
            $this->assertSame($size, array_slice(getimagesize($thumbnail), 0, 2));
            $preview = imagecreatefromjpeg($thumbnail);
            $color = imagecolorsforindex(
                $preview,
                imagecolorat($preview, $right ? $size[0] - 10 : 10, $bottom ? $size[1] - 10 : 10)
            );
            $this->assertGreaterThan(200, $color['red']);
            $this->assertArrayNotHasKey('Orientation', exif_read_data($thumbnail));
        }
    }

    public function testJpegPixelLimitStaysBoundedAndOtherFormatsKeepTheirLimit(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        $jpeg = file_get_contents($source);
        $marker = strpos($jpeg, "\xff\xc0");
        $this->assertNotFalse($marker);
        $jpeg = substr_replace($jpeg, pack('nn', 9000, 15000), $marker + 5, 4);
        file_put_contents($source, $jpeg);
        $this->library->index();
        $this->assertNull($this->library->imagePath(1));
        $this->assertSame($jpeg, file_get_contents($source));

        imagepng(imagecreatetruecolor(1, 1), $source);
        $png = substr_replace(file_get_contents($source), pack('NN', 12000, 9000), 16, 8);
        file_put_contents($source, $png);
        clearstatcache();
        $this->library->index();
        $this->assertNull($this->library->imagePath(1));
        $this->assertSame($png, file_get_contents($source));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
    }

    public function testBrokenOptionalExifDoesNotBlockGdOrWorkerThumbnails(): void
    {
        $source = $this->root . '/photos/Urlaub/Meer.jpg';
        foreach ([80, 1600] as $width) {
            imagejpeg(imagecreatetruecolor($width, 60), $source);
            $jpeg = file_get_contents($source);
            $exif = 'broken metadata';
            $original = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
            file_put_contents($source, $original);
            touch($source, 1234567890 + $width);
            clearstatcache();
            $this->library->index();
            $run = $this->library->jobs->start('previews');
            $state = $this->library->jobs->step('previews', $run['token']);
            $this->assertSame('done', $state['status']);
            $this->assertSame(0, $state['errors']);
            $this->assertNotNull($this->library->imagePath(1, cachedOnly: true));
            $this->assertSame(date('Y-m-d H:i:s', 1234567890 + $width), $this->library->photo(1)->taken);
            $this->assertSame($original, file_get_contents($source));
        }
    }

    public function testExifTransposeAndMetadataStripping(): void
    {
        $path = $this->root . '/photos/Urlaub/Meer.jpg';
        $image = imagecreatetruecolor(80, 60);
        imagefilledrectangle($image, 0, 0, 39, 29, imagecolorallocate($image, 240, 0, 0));
        imagejpeg($image, $path, 100);
        $jpeg = file_get_contents($path);
        $exif = "Exif\0\0II" . pack('vVv', 42, 8, 1) . pack('vvVv', 0x0112, 3, 1, 5) . "\0\0" . pack('V', 0);
        file_put_contents(
            $path,
            substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2)
        );
        $this->assertSame(5, exif_read_data($path)['Orientation']);
        $this->library->index();
        $photo = $this->library->photos()[0];
        $thumbnail = $this->library->imagePath($photo->id);
        $photo = $this->library->photo($photo->id);
        $this->assertSame(60, $photo->width);
        $this->assertSame(80, $photo->height);
        $preview = imagecreatefromjpeg($thumbnail);
        $color = imagecolorsforindex($preview, imagecolorat($preview, 5, 5));
        $this->assertGreaterThan(200, $color['red']);
        $this->assertArrayNotHasKey('Orientation', exif_read_data($thumbnail));
    }

    public function testInitializerPreservesIndentedCredentials(): void
    {
        $envPath = $this->root . '/.data/.env';
        $env = str_replace('AUTH_USERNAME=test-user', '  AUTH_USERNAME = test-user', file_get_contents($envPath));
        file_put_contents($envPath, $env);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-init'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->root . '/init.log', 'a'],
                2 => ['file', $this->root . '/init.log', 'a']
            ],
            $pipes,
            $this->root
        );
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame($env, file_get_contents($envPath));
    }

    public function testConfigurationUsesDotenvQuotingAndComments(): void
    {
        file_put_contents(
            $this->root . '/.data/.env',
            "AUTH_USERNAME=test-user # account\nAUTH_PASSWORD='spaces # and " . '$' . "igns'\n"
        );
        $configuration = new PhotoButler($this->root);
        $this->assertSame('test-user', $configuration->getSetting('AUTH_USERNAME'));
        $this->assertSame('spaces # and ' . '$' . 'igns', $configuration->getSetting('AUTH_PASSWORD'));
    }

    public function testInitializerMigratesOnlyUnmodifiedLegacyEntryPoints(): void
    {
        mkdir($this->root . '/public');
        $entry = file_get_contents(dirname(__DIR__) . '/public/index.php');
        $legacy = str_replace(
            'new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__))->run();',
            'new \\vielhuber\\photobutler\\WebApp(new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();',
            $entry
        );
        $custom = $legacy . "\n// Custom deployment settings.\n";
        foreach ([$legacy, $custom, $entry] as $existing) {
            file_put_contents($this->root . '/public/index.php', $existing);
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-init'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $this->root . '/init.log', 'a'],
                    2 => ['file', $this->root . '/init.log', 'a']
                ],
                $pipes,
                $this->root
            );
            fclose($pipes[0]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame(
                $existing === $custom ? $custom : $entry,
                file_get_contents($this->root . '/public/index.php')
            );
        }
    }

    public function testInvalidConfigurationDoesNotExposeItsContents(): void
    {
        file_put_contents($this->root . '/.data/.env', 'AUTH_PASSWORD=private-test-value invalid space');
        try {
            new PhotoButler($this->root);
            $this->fail('Expected malformed dotenv syntax to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-test-value', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testWebAuthenticationCsrfAndPhotoAccess(): void
    {
        $this->library->index();
        $id = $this->library->photos()[0]->id;
        $this->library->priority($id, 1);
        mkdir($this->root . '/public');
        file_put_contents(
            $this->root . '/public/index.php',
            '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; (new \\vielhuber\\photobutler\\PhotoButler(dirname(__DIR__)))->run();'
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $this->root . '/public'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->root . '/server.log', 'a'],
                2 => ['file', $this->root . '/server.log', 'a']
            ],
            $pipes
        );
        fclose($pipes[0]);
        $accessToken = '';
        $request = function (string $path, ?array $post = null, array $headers = []) use (
            $address,
            &$accessToken
        ): array {
            $responseHeaders = [];
            $handle = curl_init('http://' . $address . '/' . $path);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEJAR => $this->root . '/cookies',
                CURLOPT_COOKIEFILE => $this->root . '/cookies',
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                }
            ]);
            if ($accessToken !== '') {
                curl_setopt($handle, CURLOPT_COOKIE, 'access_token=' . $accessToken);
            }
            if ($post !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $body = curl_exec($handle);
            return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $responseHeaders];
        };
        try {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                [$status, $body] = $request('');
                if ($status === 200) {
                    break;
                }
                usleep(100000);
            }
            $this->assertSame(200, $status);
            preg_match('/name="csrf" value="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            foreach (['scan', 'tag', 'job-start', 'job-pause', 'job-step'] as $action) {
                $this->assertSame(401, $request('', ['action' => $action, 'csrf' => $csrf])[0]);
            }
            $this->assertSame(401, $request('?jobs=1')[0]);
            $this->assertSame(401, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(404, $request('.data/.env')[0]);
            $this->assertSame(404, $request('vendor/autoload.php')[0]);
            $this->assertSame(
                403,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'test-password',
                    'csrf' => 'wrong'
                ])[0]
            );
            $this->assertSame(
                401,
                $request('index.php/login', ['username' => 'test-user', 'password' => 'wrong', 'csrf' => $csrf])[0]
            );
            $passwordHash = $this->library->database->query('SELECT password FROM users')->fetchColumn();
            $this->assertSame(
                401,
                $request('index.php/login', [
                    'username' => 'wrong-user',
                    'password' => 'test-password',
                    'csrf' => $csrf
                ])[0]
            );
            $this->assertSame(
                $passwordHash,
                $this->library->database->query('SELECT password FROM users')->fetchColumn()
            );
            [$status, $body] = $request('index.php/login', [
                'username' => 'test-user',
                'password' => 'test-password',
                'csrf' => $csrf
            ]);
            $this->assertSame(200, $status);
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['success']);
            $accessToken = $result['data']['access_token'];
            $this->assertSame(401, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(
                401,
                $request('', ['action' => 'login', 'access_token' => 'invalid', 'csrf' => $csrf])[0]
            );
            $this->assertSame(
                200,
                $request('', ['action' => 'login', 'access_token' => $accessToken, 'csrf' => $csrf])[0]
            );
            $accessToken = '';
            $this->assertSame(403, $request('', ['action' => 'logout', 'csrf' => $csrf])[0]);
            $this->assertStringContainsString('#HttpOnly_', file_get_contents($this->root . '/cookies'));
            [$status, $body] = $request('');
            $this->assertSame(200, $status);
            $this->assertStringContainsString('data-photo="' . $id . '"', $body);
            rename($this->root . '/photos', $this->root . '/temporarily-unavailable');
            try {
                [$status, $jobsBody] = $request('?view=jobs');
                $this->assertSame(200, $status);
                $jobsDocument = \Dom\HTMLDocument::createFromString($jobsBody, LIBXML_NOERROR);
                $this->assertSame(4, $jobsDocument->querySelectorAll('[data-job]')->length);
                $this->assertStringContainsString(
                    'Fotoquelle nicht verfügbar.',
                    $jobsDocument->querySelector('[data-job="scan"] [data-job-message]')->textContent
                );
                [$status, $jobsBody] = $request('?jobs=1');
                $this->assertSame(200, $status);
                $this->assertCount(4, json_decode($jobsBody, true, flags: JSON_THROW_ON_ERROR));
            } finally {
                rename($this->root . '/temporarily-unavailable', $this->root . '/photos');
            }
            foreach (['newest', 'oldest', 'month_asc', 'month_desc', 'invalid', 'sort[]=oldest'] as $sort) {
                $query = $sort === 'sort[]=oldest' ? $sort : 'sort=' . $sort;
                [$sortStatus, $sortBody] = $request('?' . $query);
                $this->assertSame(200, $sortStatus);
                $document = \Dom\HTMLDocument::createFromString($sortBody, LIBXML_NOERROR);
                $this->assertSame(
                    in_array($sort, ['invalid', 'sort[]=oldest'], true) ? 'newest' : $sort,
                    $document->querySelector('#gallery-sort option[selected]')->getAttribute('value')
                );
            }
            preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            foreach (['scan', 'tag', 'job-start', 'job-pause', 'job-step'] as $action) {
                $this->assertSame(403, $request('', ['action' => $action, 'csrf' => 'wrong'])[0]);
            }
            $this->assertSame(410, $request('', ['action' => 'tag', 'csrf' => $csrf])[0]);
            $this->assertSame(410, $request('', ['action' => 'scan', 'csrf' => $csrf])[0]);
            $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
            foreach (['tag', 'faces', 'scan', 'previews'] as $job) {
                foreach (['job-start', 'job-step', 'job-pause'] as $action) {
                    $this->assertSame(403, $request('', ['action' => $action, 'job' => $job, 'csrf' => 'wrong'])[0]);
                    [$status, $body, $headers] = $request('', ['action' => $action, 'job' => $job, 'csrf' => $csrf]);
                    $this->assertSame(410, $status);
                    $this->assertStringContainsString('application/json', $headers['content-type']);
                    $this->assertStringContainsString(
                        'PHP-Konsolenbefehl',
                        json_decode($body, true, flags: JSON_THROW_ON_ERROR)['error']
                    );
                }
            }
            $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
            [$status, $body] = $request('?view=jobs');
            $this->assertSame(200, $status);
            $document = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR);
            $this->assertSame(4, $document->querySelectorAll('[data-job]')->length);
            $this->assertSame(
                ['Galerie einlesen', 'Thumbnails generieren', 'KI-Tagging', 'Gesichtertagging'],
                array_map(
                    fn($heading): string => $heading->textContent,
                    iterator_to_array($document->querySelectorAll('[data-job] h2'))
                )
            );
            $this->assertSame(0, $document->querySelectorAll('.sidebar [data-job]')->length);
            $this->assertSame(410, $request('', ['action' => 'job-start', 'job' => 'unknown', 'csrf' => $csrf])[0]);
            $this->assertSame(
                0,
                $document->querySelectorAll('[data-job-action="start"], [data-job-action="pause"], [data-job-log]')
                    ->length
            );
            $this->assertSame(4, $document->querySelectorAll('[data-job-action="reset"]')->length);
            foreach (['scan', 'previews', 'tag', 'faces'] as $job) {
                $command = $document->querySelector('[data-job="' . $job . '"] .job-command code')->textContent;
                $this->assertStringStartsWith('php ', $command);
                $this->assertStringContainsString('--root=' . escapeshellarg($this->root), $command);
                $this->assertStringEndsWith('--' . $job . '-only', $command);
            }
            $this->assertSame(200, $request('?photo=' . $id . '&size=thumb')[0]);
            $etags = [];
            foreach (['thumb', 'display', 'detail', 'original'] as $size) {
                $url = '?photo=' . $id . '&size=' . $size;
                [$status, $body, $headers] = $request($url);
                $this->assertSame(200, $status);
                $this->assertSame('private, no-cache', $headers['cache-control']);
                $etag = '"' . hash('sha256', $body) . '"';
                $etags[$size] = $etag;
                $this->assertSame($etag, $headers['etag']);
                foreach ([$etag, 'W/' . $etag, '"old", ' . $etag, '*'] as $condition) {
                    [$status, $body, $headers] = $request($url, headers: ['If-None-Match: ' . $condition]);
                    $this->assertSame(304, $status);
                    $this->assertSame('', $body);
                    $this->assertSame($etag, $headers['etag']);
                }
                $this->assertSame(200, $request($url, headers: ['If-None-Match: "outdated"'])[0]);
            }
            $source = $this->root . '/photos/Urlaub/Meer.jpg';
            $image = imagecreatetruecolor(80, 60);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
            imagejpeg($image, $source);
            touch($source, time() + 2);
            clearstatcache();
            $this->library->index();
            foreach ($etags as $size => $etag) {
                [$status, $body, $headers] = $request(
                    '?photo=' . $id . '&size=' . $size,
                    headers: ['If-None-Match: ' . $etag]
                );
                $this->assertSame(200, $status);
                $this->assertNotSame($etag, $headers['etag']);
            }
            $this->assertStringContainsString(
                'no-store',
                $request('?photo=' . $id . '&size=original&download=1')[2]['cache-control']
            );
            [$status, $body] = $request('?detail=' . $id);
            $this->assertSame(200, $status);
            $detail = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($id, $detail['id']);
            $this->assertSame(
                [
                    'id',
                    'name',
                    'album',
                    'taken',
                    'description',
                    'tags',
                    'priority',
                    'favorite',
                    'video',
                    'status',
                    'width',
                    'height',
                    'persons',
                    'face_status'
                ],
                array_keys($detail)
            );
            $this->assertSame(404, $request('?photo=999999&size=original')[0]);
            $this->assertSame(403, $request('', ['action' => 'favorite', 'id' => $id, 'favorite' => '1'])[0]);
            [$status, $body] = $request('', ['action' => 'favorite', 'id' => $id, 'favorite' => '1', 'csrf' => $csrf]);
            $this->assertSame(200, $status);
            $this->assertTrue(json_decode($body, true)['favorite']);
            $this->assertSame(403, $request('', ['action' => 'priority', 'id' => $id, 'priority' => '-1'])[0]);
            foreach ([-1, 0, 1] as $priority) {
                [$status, $body] = $request('', [
                    'action' => 'priority',
                    'id' => $id,
                    'priority' => (string) $priority,
                    'csrf' => $csrf
                ]);
                $this->assertSame(200, $status);
                $this->assertSame($priority, json_decode($body, true)['priority']);
            }
            foreach (['', '2', '-2', 'foo', '1.5'] as $priority) {
                $this->assertSame(
                    422,
                    $request('', ['action' => 'priority', 'id' => $id, 'priority' => $priority, 'csrf' => $csrf])[0]
                );
            }
            $this->assertSame(1, $this->library->photo($id)->priority);

            $this->assertSame(
                200,
                $request('', [
                    'action' => 'tags',
                    'id' => $id,
                    'tags' => '<script>alert(1)</script>',
                    'csrf' => $csrf
                ])[0]
            );
            $this->assertStringNotContainsString('<script>alert(1)</script>', $request('')[1]);
            file_put_contents(
                $this->root . '/.data/.env',
                str_replace(
                    'AUTH_PASSWORD=test-password',
                    'AUTH_PASSWORD=updated-password',
                    file_get_contents($this->root . '/.data/.env')
                )
            );
            foreach (['thumb', 'detail', 'original'] as $size) {
                $this->assertSame(
                    401,
                    $request('?photo=' . $id . '&size=' . $size, headers: ['If-None-Match: ' . $etag])[0]
                );
            }
            $this->assertSame(
                401,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'test-password',
                    'csrf' => $csrf
                ])[0]
            );
            [$status, $body] = $request('index.php/login', [
                'username' => 'test-user',
                'password' => 'updated-password',
                'csrf' => $csrf
            ]);
            $this->assertSame(200, $status);
            $token = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data']['access_token'];
            $this->assertSame(200, $request('', ['action' => 'login', 'access_token' => $token, 'csrf' => $csrf])[0]);
            [, $body] = $request('');
            preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match);
            $csrf = $match[1];
            $this->assertSame(200, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertSame(303, $request('', ['action' => 'logout', 'csrf' => $csrf])[0]);
            $accessToken = '';
            $this->assertSame(401, $request('?photo=' . $id . '&size=original')[0]);
            [, $body] = $request('');
            preg_match('/name="csrf" value="([^"]+)"/', $body, $match);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->assertSame(
                    401,
                    $request('index.php/login', [
                        'username' => 'test-user',
                        'password' => 'wrong',
                        'csrf' => $match[1]
                    ])[0]
                );
            }
            $this->assertSame(
                429,
                $request('index.php/login', [
                    'username' => 'test-user',
                    'password' => 'updated-password',
                    'csrf' => $match[1]
                ])[0]
            );
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

final class ConcurrentJobCheckpointStatement extends PDOStatement
{
    protected function __construct(private readonly PDO $writer) {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (str_starts_with($this->queryString, 'UPDATE jobs SET completed =')) {
            $this->writer->exec("UPDATE jobs SET completed = completed + 1 WHERE job = 'scan'");
        }
        return $result;
    }
}
