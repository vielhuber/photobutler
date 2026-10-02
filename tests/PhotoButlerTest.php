<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\OneDriveClient;
use vielhuber\photobutler\PhotoButler;

final class PhotoButlerTest extends TestCase
{
    use CloudFixture;

    protected function setUp(): void
    {
        $this->createCloudLibrary(
            "AUTH_USERNAME=test-user\nAUTH_PASSWORD=test-password\nJWT_SECRET=photobutler-test-signing-secret-32-bytes\nCRON_SECRET=photobutler-test-cron-secret-with-32-bytes\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
    }

    private function indexMeer(): int
    {
        $this->index([$this->folder('urlaub', 'Urlaub'), $this->item('meer', 'Meer.jpg', 'urlaub')]);
        return 1;
    }

    private function queue(array $items, bool $complete = true): void
    {
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => []], ...$items],
            ...$complete
                ? ['@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture']
                : ['@odata.nextLink' => OneDriveClient::GRAPH . '/next']
        ];
    }

    private function thumbnailPath(int $id): string
    {
        $statement = $this->library->database->prepare('SELECT path FROM photos WHERE id = ?');
        $statement->execute([$id]);
        return $this->root . '/.data/thumbnails/' . hash('sha256', $statement->fetchColumn()) . '.jpg';
    }

    public function testPriorityMigrationRetainsFavoritesAndOnlyExcludesNeutralEntriesOnce(): void
    {
        $this->indexMeer();
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
        $this->index([
            $this->folder('urlaub', 'Urlaub'),
            $this->folder('whatsapp', '_WHATSAPP'),
            $this->folder('statuses', '.Statuses', 'whatsapp'),
            $this->folder('animated', 'WhatsApp Animated Gifs', 'whatsapp'),
            $this->folder('images', 'WhatsApp Images', 'whatsapp'),
            $this->folder('stickers', 'WhatsApp Stickers', 'whatsapp'),
            $this->folder('backup', 'WhatsApp Backup Excluded Stickers', 'whatsapp'),
            $this->folder('own', 'Stickers', 'urlaub'),
            $this->item('meer', 'Meer.jpg', 'urlaub'),
            $this->item('old', 'old.jpg', modified: '2024-12-31T12:00:00Z'),
            $this->item('story', 'story.jpg', 'statuses', modified: '2025-01-01T12:00:00Z'),
            $this->item('animation', 'animation.gif', 'animated', modified: '2025-01-01T12:00:00Z'),
            $this->item('other', 'other.GIF', 'images', modified: '2025-01-01T12:00:00Z'),
            $this->item('ordinary', 'ordinary.gif', modified: '2025-01-01T12:00:00Z'),
            $this->item('sticker', 'sticker.webp', 'stickers', modified: '2025-01-01T12:00:00Z'),
            $this->item('backup-sticker', 'backup.webp', 'backup', modified: '2025-01-01T12:00:00Z'),
            $this->item('own-sticker', 'own.webp', 'own', modified: '2025-01-01T12:00:00Z')
        ]);
        $priorities = $this->library->database
            ->query('SELECT name, priority FROM photos')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(-1, $priorities['old.jpg']);
        $this->assertSame(-1, $priorities['story.jpg']);
        $this->assertSame(-1, $priorities['animation.gif']);
        $this->assertSame(-1, $priorities['other.GIF']);
        $this->assertSame(0, $priorities['ordinary.gif']);
        $this->assertSame(-1, $priorities['sticker.webp']);
        $this->assertSame(-1, $priorities['backup.webp']);
        $this->assertSame(0, $priorities['own.webp']);
        $this->assertSame(0, $priorities['Meer.jpg']);
        $excluded = $this->library->database
            ->query('SELECT id FROM photos WHERE priority = -1')
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($excluded as $id) {
            $this->library->priority((int) $id, 1);
        }
        $this->index([$this->item('old', 'old.jpg', version: 'v2', modified: '2022-12-30T12:00:00Z')]);
        $this->assertCount(6, $this->library->photos(favorites: true));
        $photo = $this->library->photos(album: 'Urlaub')[0];
        $this->library->oneDrive->previews([$photo->id]);
        $cache = $this->library->imagePath($photo->id);
        $hash = hash_file('sha256', $cache);
        $this->library->priority($photo->id, -1);
        $this->index([]);
        $this->assertSame(-1, $this->library->photo($photo->id)->priority);
        $this->assertSame($hash, hash_file('sha256', $cache));
        $this->library->jobs->reset('scan');
        $this->index([]);
        $this->assertSame(-1, $this->library->photo($photo->id)->priority);
        $this->assertCount(6, $this->library->photos(favorites: true));
        foreach ($excluded as $id) {
            $this->library->priority((int) $id, 0);
        }
        $this->index([]);
        foreach ($excluded as $id) {
            $this->assertSame(-1, $this->library->photo((int) $id)->priority);
        }
    }

    public function testVisibilityFiltersCombineWithPriorityAndFavoriteFilters(): void
    {
        $this->indexMeer();
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
        $this->indexMeer();
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
        $this->indexMeer();
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

    public function testJobLogRetentionIsAtomicAndRespectsTheCallersTransaction(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->library->jobs->log('previews', 'Entry ' . $index);
        }
        $before = $this->library->database->query('SELECT * FROM job_logs')->fetchAll();
        $this->library->database->exec(
            "CREATE TRIGGER reject_log_cleanup BEFORE DELETE ON job_logs BEGIN SELECT RAISE(ABORT, 'fixture'); END"
        );
        try {
            $this->library->jobs->log('previews', 'Must roll back');
            $this->fail('Failed retention must not leave a partial write.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('fixture', $exception->getMessage());
        }
        $this->assertFalse($this->library->database->inTransaction());
        $this->assertSame($before, $this->library->database->query('SELECT * FROM job_logs')->fetchAll());
        $this->library->database->exec('DROP TRIGGER reject_log_cleanup');
        $this->library->database->beginTransaction();
        $this->library->jobs->log('previews', 'Caller-owned transaction');
        $this->assertTrue($this->library->database->inTransaction());
        $this->assertSame(30, (int) $this->library->database->query('SELECT COUNT(*) FROM job_logs')->fetchColumn());
        $this->library->database->rollBack();
        $this->assertSame($before, $this->library->database->query('SELECT * FROM job_logs')->fetchAll());
    }

    public function testEachJobLogsItsOwnProcessingPhaseAndOutcome(): void
    {
        $this->queue([$this->folder('urlaub', 'Urlaub'), $this->item('meer', 'Meer.jpg', 'urlaub')]);
        $jobs = $this->library->jobs;
        foreach (
            ['scan' => 'Galerieabschnitt', 'tag' => 'KI-Bewertung', 'faces' => 'analysiere Gesichter']
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
        $this->assertCount(5, $jobs->all());
        $this->assertSame('idle', $jobs->all()['scan']['status']);
        $this->assertSame(0, $jobs->step('scan', 'not-started')['completed']);
        $this->assertCount(0, $this->library->photos());
        $this->queue([$this->folder('urlaub', 'Urlaub'), $this->item('meer', 'Meer.jpg', 'urlaub')]);
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
        $this->assertSame([], $this->client->downloads);
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
        $reopened = new PhotoButler($this->root);
        $this->assertSame('done', $reopened->jobs->all()['previews']['status']);
        $this->assertSame('paused', $reopened->jobs->all()['tag']['status']);
    }

    public function testJobStatusRequiresAnActiveCliLockWithoutChangingPersistedCheckpoints(): void
    {
        $this->indexMeer();
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
        $this->indexMeer();
        $this->client->invalidThumbnails = ['meer'];
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
        $this->client->invalidThumbnails = [];
        $run = $jobs->start('previews');
        $run = $jobs->step('previews', $run['token']);
        $this->assertSame('done', $run['status']);
        $this->assertSame(0, $run['errors']);
        $this->assertSame(1, $run['completed']);
        $this->assertSame(['meer', 'meer'], $this->client->downloads);
        foreach (['scan', 'tag', 'faces'] as $job) {
            $this->assertSame($before[$job], $jobs->all()[$job]);
        }
    }

    public function testJobsRemainReadableWhenTheCloudSourceIsUnavailable(): void
    {
        $this->indexMeer();
        $this->library->database->exec("UPDATE jobs SET status = 'paused' WHERE job = 'scan'");
        $before = $this->library->database->query('SELECT * FROM jobs')->fetchAll();
        $source = $this->root . '/.data/onedrive-source.json';
        rename($source, $source . '.offline');
        $states = $this->library->jobs->all();
        $this->assertCount(5, $states);
        $this->assertSame('paused', $states['scan']['status']);
        $this->assertSame(1, $states['scan']['completed']);
        $this->assertSame(1, $states['scan']['total']);
        $this->assertSame($before, $this->library->database->query('SELECT * FROM jobs')->fetchAll());
        foreach (['scan', 'previews'] as $job) {
            $run = $this->library->jobs->start($job);
            try {
                $this->library->jobs->step($job, $run['token']);
                $this->fail('Jobs must still reject an unavailable source.');
            } catch (RuntimeException $exception) {
                $this->assertSame('OneDrive einrichten: --onedrive-login ausführen.', $exception->getMessage());
            }
            $this->assertSame('paused', $this->library->jobs->all()[$job]['status']);
        }
        $this->assertCount(1, $this->library->photos());
        rename($source . '.offline', $source);

        file_put_contents(
            $this->root . '/.data/.env',
            preg_replace('/^ONEDRIVE_.*\R/m', '', file_get_contents($this->root . '/.data/.env'))
        );
        $unconfigured = new PhotoButler($this->root);
        $this->assertNull($unconfigured->oneDrive);
        $message = 'OneDrive nicht konfiguriert. ONEDRIVE_CLIENT_ID in .data/.env setzen.';
        $states = $unconfigured->jobs->all();
        $this->assertSame($message, $states['scan']['warning']);
        $this->assertSame(1, $states['scan']['estimated']);
        $this->assertCount(1, $unconfigured->photos());
        $this->assertNull($unconfigured->imagePath(1));
        foreach (['scan', 'previews'] as $job) {
            $run = $unconfigured->jobs->start($job);
            try {
                $unconfigured->jobs->step($job, $run['token']);
                $this->fail('Jobs must name the missing OneDrive configuration.');
            } catch (RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testScanCheckpointPercentAndStaleRunsCannotRestartWork(): void
    {
        $this->queue([$this->item('a', 'Meer.jpg')], complete: false);
        $this->client->pages[] = [
            'value' => [$this->item('b', 'Zweiter.jpg')],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $jobs = $this->library->jobs;
        $scan = $jobs->start('scan');
        $oldToken = $scan['token'];
        $scan = $jobs->step('scan', $oldToken);
        $this->assertSame('running', $scan['status']);
        $this->assertSame(0, $scan['completed']);
        $this->assertLessThan(100, $scan['percent']);
        $this->assertSame(1, $scan['estimated']);
        $jobs->pause('scan');
        $this->assertSame($scan['completed'], $jobs->step('scan', $oldToken)['completed']);
        $reopened = new PhotoButler($this->root, oneDriveClient: $this->client);
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

    public function testPreviewJobRefreshesAnEmptyCheckpointAndReusesTheCache(): void
    {
        $jobs = $this->library->jobs;
        $jobs->start('previews');
        $jobs->pause('previews');
        $this->indexMeer();
        $this->index([$this->item('zweiter', 'Zweiter.jpg', 'urlaub')]);
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
        $this->assertSame(['meer', 'zweiter'], $this->client->downloads);
        $this->assertSame('pending', $this->library->photo(1)->status);
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testJobEstimatesPersistWithoutCountingPausesOrStartingWork(): void
    {
        $this->index([
            $this->folder('urlaub', 'Urlaub'),
            $this->item('a', 'Meer.jpg', 'urlaub'),
            $this->item('b', 'Second.jpg', 'urlaub'),
            $this->item('c', 'Third.jpg', 'urlaub')
        ]);
        foreach ($this->library->jobs->all() as $state) {
            $this->assertNull($state['eta_seconds']);
            $this->assertSame('Noch nicht abschätzbar', $state['eta']);
        }
        $this->queue([$this->item('a', 'Meer.jpg', 'urlaub')], complete: false);
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(3, $run['completed']);
        $this->assertSame(2, $run['remaining_files']);
        $this->assertGreaterThan(0, $run['eta_seconds']);
        $this->library->jobs->pause('scan');
        $reopened = new PhotoButler($this->root, oneDriveClient: $this->client);
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
        $this->index([$this->item('a')]);
        $this->client->pages[] = [
            'value' => [$this->item('a')],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $this->library->index();
        foreach (['scan', 'previews', 'tag', 'faces'] as $job) {
            $this->library->database
                ->prepare('INSERT INTO job_timings (job, seconds_per_file) VALUES (?, ?)')
                ->execute([$job === 'previews' ? 'thumbnails' : $job, 4800]);
        }
        foreach ($this->library->jobs->all() as $job => $state) {
            $this->assertSame($job === 'similar' ? 'Noch nicht abschätzbar' : 'ca. 1 Std. 20 Min.', $state['eta']);
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
        $this->library->database->exec('DELETE FROM onedrive_state');
        $this->assertSame('Noch nicht abschätzbar', $this->library->jobs->all()['scan']['eta']);
    }

    public function testPreviewBatchesAreBoundedAndRetainManualSnapshotCheckpoints(): void
    {
        $items = [];
        for ($i = 0; $i < 105; $i++) {
            $items[] = $this->item('p' . $i, $i . '.jpg');
        }
        $this->index($items);
        $run = $this->library->jobs->start('previews');
        $run = $this->library->jobs->step('previews', $run['token'], previewLimit: 1000);
        $this->assertSame(100, $run['completed']);
        $this->assertSame(105, $run['total']);
        $this->assertSame('running', $run['status']);
        $this->library->jobs->pause('previews');
        $reopened = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->assertSame(100, $reopened->jobs->all()['previews']['completed']);
        $this->assertSame('paused', $reopened->jobs->step('previews', $run['token'])['status']);
        $this->client->pages[] = [
            'value' => [$this->item('later', 'Later.jpg')],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $reopened->index();
        $run = $reopened->jobs->start('previews');
        $this->assertSame(100, $run['cursor']);
        $this->assertSame(105, $run['maximum']);
        $run = $reopened->jobs->step('previews', $run['token']);
        $this->assertSame(105, $run['completed']);
        $this->assertSame(100, $run['percent']);
        $this->assertSame('done', $run['status']);
        $this->assertCount(105, $this->client->downloads);
        $this->assertNotContains('later', $this->client->downloads);
        $this->assertSame(0, (int) $reopened->database->query('SELECT SUM(attempted) FROM photos')->fetchColumn());
        $this->assertSame(0, (int) $reopened->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testPreviewCheckpointAllowsConcurrentWritesBeforeSavingTiming(): void
    {
        $this->index([$this->item('a', 'Meer.jpg'), $this->item('b', 'Second.jpg')]);
        $database = $this->library->database;
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
        $this->index([$this->item('a', 'Meer.jpg'), $this->item('b', 'Zweiter.jpg')]);
        $this->library->oneDrive->previews([1, 2]);
        $run = function (array $arguments): array {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/photobutler-index', '--root=' . $this->root, ...$arguments],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output, $errors];
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
        [$code, , $errors] = $run(['--scan-only']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('OneDrive erneut anmelden', $errors);
        $this->assertSame('paused', $this->library->jobs->all()['scan']['status']);
        $this->assertSame(2, $this->library->photoCount());
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
        $this->assertSame(['a', 'b'], $this->client->downloads);
    }

    public function testScanProgressLineReportsCheckedEntriesSeparatelyFromTheCatalog(): void
    {
        $this->index([
            $this->folder('urlaub', 'Urlaub'),
            $this->item('a', 'Meer.jpg', 'urlaub'),
            $this->item('b', 'Second.jpg', 'urlaub'),
            $this->item('c', 'Third.jpg', 'urlaub')
        ]);
        $this->queue([$this->item('a', 'Meer.jpg', 'urlaub')], complete: false);
        $this->client->pages[] = [
            'value' => [$this->item('b', 'Second.jpg', 'urlaub')],
            '@odata.nextLink' => OneDriveClient::GRAPH . '/next'
        ];
        $this->client->pages[] = [
            'value' => [$this->item('c', 'Third.jpg', 'urlaub')],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $line = function (array $state): string {
            ob_start();
            $this->library->jobs->printProgress('scan', $state);
            return ob_get_clean();
        };
        $run = $this->library->jobs->start('scan');
        foreach ([[2, 50], [3, 75]] as [$checked, $percent]) {
            $output = $line($this->library->jobs->step('scan', $run['token']));
            $this->assertStringContainsString(
                'Galerie einlesen · Abgleich läuft · ca. ' .
                    $percent .
                    ' % · ' .
                    $checked .
                    '/ca. 4 Metadateneinträge geprüft · Katalog: 3 Fotos',
                $output
            );
            $this->assertStringContainsString('Restzeit:', $output);
        }
        $output = $line($this->library->jobs->step('scan', $run['token']));
        $this->assertStringContainsString(
            'Galerie einlesen · Abgeschlossen · 100 % · 4/4 Metadateneinträge geprüft · Katalog: 3 Fotos',
            $output
        );
        $this->assertStringNotContainsString('Restzeit:', $output);
        $this->assertSame('done', $this->library->jobs->all()['scan']['status']);
        $this->queue([['id' => 'a', 'deleted' => []], ['id' => 'b', 'deleted' => []], ['id' => 'c', 'deleted' => []]]);
        $run = $this->library->jobs->start('scan');
        $output = $line($this->library->jobs->step('scan', $run['token']));
        $this->assertStringContainsString(
            'Abgeschlossen · 100 % · 4/4 Metadateneinträge geprüft · Katalog: 0 Fotos',
            $output
        );
        $this->assertStringNotContainsString('Restzeit:', $output);
    }

    public function testCronResumesConfiguredJobsWithinOneRequestAndLeavesCliRunsAlone(): void
    {
        $this->queue([$this->folder('urlaub', 'Urlaub'), $this->item('meer', 'Meer.jpg', 'urlaub')]);
        $report = $this->library->jobs->cron();
        $this->assertStringContainsString("Galerie einlesen: done · 100 % · 0 Fehler\n", $report);
        $this->assertStringContainsString("Thumbnails downloaden: done · 100 % · 0 Fehler\n", $report);
        $this->assertStringContainsString("KI-Bewertung: übersprungen (KI nicht konfiguriert)\n", $report);
        $this->assertStringContainsString(
            "Gesichtertagging: übersprungen (Gesichtserkennung nicht installiert)\n",
            $report
        );
        $this->assertSame(['meer'], $this->client->downloads);
        $this->assertNotNull($this->library->imagePath(1, cachedOnly: true));
        $states = $this->library->jobs->all();
        $this->assertSame('idle', $states['tag']['status']);
        $this->assertSame('idle', $states['faces']['status']);
        $this->assertSame('pending', $this->library->photo(1)->status);

        $this->queue([$this->item('zweiter', 'Zweiter.jpg', 'urlaub')]);
        $lock = fopen($this->root . '/.data/cli-scan.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $report = $this->library->jobs->cron();
            $this->assertStringContainsString("Galerie einlesen: läuft bereits\n", $report);
            $this->assertStringContainsString("Thumbnails downloaden: done · 100 % · 0 Fehler\n", $report);
            $this->assertSame(1, $this->library->photoCount());
        } finally {
            fclose($lock);
        }
        $report = $this->library->jobs->cron();
        $this->assertStringContainsString("Galerie einlesen: done · 100 % · 0 Fehler\n", $report);
        $this->assertSame(2, $this->library->photoCount());
        $this->assertSame(['meer', 'zweiter'], $this->client->downloads);

        $report = $this->library->jobs->cron();
        $this->assertStringContainsString("Galerie einlesen: Unexpected fixture request\n", $report);
        $this->assertStringContainsString("Thumbnails downloaden: done · 100 % · 0 Fehler\n", $report);
        foreach ($this->library->jobs->all() as $state) {
            $this->assertNotSame('running', $state['status']);
        }
        $this->assertSame('paused', $this->library->jobs->all()['scan']['status']);
        $this->assertSame(2, $this->library->photoCount());
    }

    public function testFilteredPhotoCountsIncludeEveryPageAndUseTheListingFilters(): void
    {
        $this->indexMeer();
        $insert = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen, priority, manual_tags)
            SELECT root, path || ?, ?, ?, modified, bytes, width, height, taken, seen, ?, ? FROM photos WHERE id = 1");
        for ($id = 2; $id <= 72; $id++) {
            $insert->execute([
                (string) $id,
                $id % 2 === 0 ? 'Even' : 'Odd',
                'Item ' . $id,
                ($id % 3) - 1,
                $id % 2 === 0 ? '["Test"]' : '[]'
            ]);
        }
        $this->library->database->exec('UPDATE photos SET available = 0 WHERE id = 72');
        $this->assertSame(71, $this->library->photoCount());
        $this->assertCount(60, $this->library->photos());
        $this->assertCount(11, $this->library->photos(page: 2));
        $this->assertSame(24, $this->library->photoCount(relevance: 'unrated'));
        $this->assertSame(24, $this->library->photoCount(relevance: 'relevant'));
        $this->assertSame(23, $this->library->photoCount(relevance: 'excluded'));
        $this->assertSame(35, $this->library->photoCount(album: 'Even'));
        $this->assertSame(0, $this->library->photoCount(relevance: 'relevant', favorites: 'none'));
        foreach (['all', 'unrated', 'relevant', 'excluded'] as $relevance) {
            foreach ([false, true, 'none'] as $favorites) {
                $this->assertSame(
                    count($this->library->photos(album: 'Even', favorites: $favorites, relevance: $relevance)),
                    $this->library->photoCount(album: 'Even', favorites: $favorites, relevance: $relevance)
                );
            }
        }
        $this->assertSame(70, $this->library->photoCount(query: 'Item'));
        $this->assertSame(0, $this->library->photoCount(query: '%'));
        $this->library->database->exec("INSERT INTO persons (id, name) VALUES (1, 'Fixture');
            INSERT INTO faces (photo_id, person_id, modified, bytes, model, box, embedding, crop)
            SELECT id, 1, modified, bytes, 'fixture', '[]', '[]', '' FROM photos WHERE id IN (1, 2)");
        $this->assertSame(2, $this->library->photoCount(person: 1));
        $this->assertSame(1, $this->library->photoCount(person: 1, relevance: 'unrated'));
        $this->library->database->exec('UPDATE faces SET ignored = 1 WHERE photo_id = 2');
        $this->assertSame(1, $this->library->photoCount(person: 1));
        $this->library->database->exec('UPDATE faces SET bytes = 0 WHERE photo_id = 1');
        $this->assertSame(0, $this->library->photoCount(person: 1));
    }

    public function testTaggingNeverProcessesFaces(): void
    {
        $this->indexMeer();
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $this->assertSame(0, $this->library->tag(1));
        $this->assertSame(0, (int) $this->library->database->query('SELECT COUNT(*) FROM face_state')->fetchColumn());
    }

    public function testIndexIsIdempotentAndNeverDownloadsDuringImport(): void
    {
        $this->indexMeer();
        $this->index([]);
        $photos = $this->library->photos();
        $this->assertCount(1, $photos);
        $this->assertSame('Urlaub', $photos[0]->album);
        $this->assertSame([], $this->client->downloads);
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
    }

    public function testSearchAndFavorites(): void
    {
        $this->indexMeer();
        $id = $this->library->photos()[0]->id;
        $this->library->favorite($id, true);
        $this->assertCount(1, $this->library->photos(query: 'MEER', favorites: true));
        $this->assertCount(0, $this->library->photos(query: '%'));
    }

    public function testGalleryOnlyLinksToJobsAndOffersInfiniteLoading(): void
    {
        $this->indexMeer();
        $photos = array_fill(0, 60, $this->library->photos()[0]);
        $matchedPhotos = 61;
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
        $peopleView = false;
        $person = 0;
        $persons = [];
        $shownPersons = 0;
        $album = $query = $tag = '';
        $from = '2024-01-01';
        $to = '2024-12-31';
        $page = 1;
        $offset = 0;
        $tags = [];
        $pagination = [
            'q' => 'Meer & Strand',
            'album' => 'Urlaub',
            'tag' => 'Meer',
            'sort' => $sort,
            'from' => $from,
            'to' => $to
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
        $this->assertSame('61 von 61 Fotos', $document->querySelector('#gallery-count')->textContent);
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
        $this->assertNull($document->querySelector('#gallery-favorites'));
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
        $this->assertSame('2024-01-01', $document->querySelector('#gallery-from')->getAttribute('value'));
        $this->assertSame('2024-12-31', $document->querySelector('#gallery-to')->getAttribute('value'));
        $this->assertNotNull($document->querySelector('.reset'));
    }

    public function testDateFilterIsInclusiveAndCombinesWithListingsCountsAndViewerLookups(): void
    {
        $this->indexMeer();
        $insert = $this->library->database->prepare("INSERT INTO photos
            (root, path, album, name, modified, bytes, width, height, taken, seen, priority)
            VALUES ('/photos', ?, 'Urlaub', ?, 1, 1, 0, 0, ?, 'test', ?)");
        foreach (
            [
                ['before.jpg', '2023-12-31 23:59:59', 0],
                ['start.jpg', '2024-01-01 00:00:00', 0],
                ['end.jpg', '2024-12-31 23:59:59', 1],
                ['after.jpg', '2025-01-01 00:00:00', 0]
            ]
            as [$name, $taken, $priority]
        ) {
            $insert->execute(['/photos/' . $name, $name, $taken, $priority]);
        }
        $names = fn(array $photos): array => array_column($photos, 'name');
        $this->assertSame(
            ['end.jpg', 'start.jpg'],
            $names($this->library->photos(relevance: 'all', from: '2024-01-01', to: '2024-12-31'))
        );
        $this->assertSame(2, $this->library->photoCount(relevance: 'all', from: '2024-01-01', to: '2024-12-31'));
        $this->assertSame(
            ['Meer.jpg', 'after.jpg', 'end.jpg', 'start.jpg'],
            $names($this->library->photos(relevance: 'all', from: '2024-01-01'))
        );
        $this->assertSame(
            ['start.jpg', 'before.jpg'],
            $names($this->library->photos(relevance: 'unrated', to: '2024-06-30'))
        );
        $this->assertSame(1, $this->library->photoCount(relevance: 'relevant', from: '2024-12-31', to: '2024-12-31'));
        $id = (int) $this->library->database->query("SELECT id FROM photos WHERE name = 'after.jpg'")->fetchColumn();
        $this->assertCount(1, $this->library->photos(relevance: 'all', id: $id, from: '2025-01-01'));
        $this->assertCount(0, $this->library->photos(relevance: 'all', id: $id, to: '2024-12-31'));
    }

    public function testPhotoBatchesRetainFiltersAndDoNotOverlap(): void
    {
        $this->indexMeer();
        for ($number = 0; $number < 64; $number++) {
            $statement = $this->library->database->prepare("INSERT INTO photos
                (root, path, album, name, modified, bytes, width, height, taken, seen, ai_tags, priority)
                VALUES ('/photos', ?, 'Urlaub', 'Meer.jpg', 1, 1, 0, 0, '2026-01-01', 'test', '[\"Meer\"]', 1)");
            $statement->execute(['/photos/' . $number . '.jpg']);
        }
        $first = $this->library->photos(query: 'Meer', album: 'Urlaub', favorites: true);
        $second = $this->library->photos(query: 'Meer', album: 'Urlaub', favorites: true, page: 2);
        $this->assertCount(60, $first);
        $this->assertCount(4, $second);
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->assertSame([], $this->library->photos(favorites: true, page: 3));
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
        $this->indexMeer();
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
        $first = $this->library->photos(query: 'Meer-', favorites: true, relevance: 'relevant');
        $second = $this->library->photos(query: 'Meer-', favorites: true, relevance: 'relevant', page: 2);
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
        $this->indexMeer();
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
            $first = $this->library->photos(query: 'Meer', album: 'Urlaub', favorites: true, sort: $sort);
            $second = $this->library->photos(query: 'Meer', album: 'Urlaub', favorites: true, page: 2, sort: $sort);
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

    public function testSymlinkedCachesAndDeletedItemsAreNotServed(): void
    {
        $id = $this->indexMeer();
        file_put_contents($this->root . '/outside.jpg', $this->client->jpeg);
        symlink($this->root . '/outside.jpg', $this->thumbnailPath($id));
        $this->assertNull($this->library->imagePath($id));
        $this->assertNull($this->library->imagePath($id, cachedOnly: true));
        $this->index([['id' => 'meer', 'deleted' => []]]);
        $this->assertCount(0, $this->library->photos());
        $this->assertNull($this->library->imagePath($id));
    }

    public function testKnownItemKeepsAnalysisAndRatingWhenOnlyMetadataChanges(): void
    {
        $id = $this->indexMeer();
        $this->library->favorite($id, true);
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $this->index([$this->item('meer', 'Meer.jpg', 'urlaub', modified: '2026-09-02T12:00:00Z')]);
        $this->assertTrue($this->library->photo($id)->favorite);
        $this->assertSame('done', $this->library->photo($id)->status);
    }

    public function testAiResultRejectsUnknownDecisions(): void
    {
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '```json' . "\n" . '{"decision":"ausblenden","reason":" Meme mit Text "}' . "\n```"
        );
        $this->assertSame(-1, $result->priority);
        $this->assertSame('Meme mit Text', $result->reason);
        $this->expectException(UnexpectedValueException::class);
        new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke(
            $this->library,
            '{"decision":"vielleicht","reason":"x"}'
        );
    }

    public function testUnavailableSourcePreservesIndex(): void
    {
        $this->indexMeer();
        unlink($this->root . '/.data/onedrive-source.json');
        try {
            $this->library->index();
            $this->fail('Expected an unavailable source to abort indexing.');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->library->photos());
        }
    }

    public function testAihelperDecodedJsonResponseIsAccepted(): void
    {
        $response = json_decode('{"decision":"einblenden","reason":"Familienfoto am Strand"}');
        $result = new ReflectionMethod(PhotoButler::class, 'parseAiResponse')->invoke($this->library, $response);
        $this->assertSame(1, $result->priority);
        $this->assertSame('Familienfoto am Strand', $result->reason);
    }

    public function testPartialEnumerationDoesNotHideExistingPhotos(): void
    {
        $this->indexMeer();
        $this->queue([$this->item('berge', 'Berge.jpg', 'urlaub')], complete: false);
        $this->library->index();
        $this->assertCount(1, $this->library->photos());
        $this->index([$this->item('wald', 'Wald.jpg', 'urlaub')]);
        $this->assertCount(3, $this->library->photos());
    }

    public function testImportMapsFoldersToAlbumsWithoutDownloadingImages(): void
    {
        $items = [$this->folder('urlaub', 'Urlaub'), $this->item('meer', 'Meer.jpg', 'urlaub')];
        for ($number = 0; $number < 12; $number++) {
            $items[] = $this->folder('album-' . $number, 'album-' . $number);
            $items[] = $this->item('photo-' . $number, 'photo.jpg', 'album-' . $number);
        }
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => []], ...$items],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $this->assertSame(13, $this->library->index());
        $this->assertSame(
            13,
            (int) $this->library->database->query('SELECT COUNT(DISTINCT album) FROM photos')->fetchColumn()
        );
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*'));
        $this->assertSame([], $this->client->downloads);
    }

    public function testThumbnailJobLeavesLegacyMediumCacheUntouched(): void
    {
        $id = $this->indexMeer();
        $this->library->oneDrive->previews([$id]);
        $thumbnail = $this->library->imagePath($id);
        $legacy = $thumbnail . '.detail.jpg';
        file_put_contents($legacy, 'legacy medium fixture');
        touch($legacy, 1234567890);
        $this->library->database->exec("INSERT INTO job_timings VALUES ('previews', 9999)");
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->assertNull($this->library->jobs->all()['previews']['eta_seconds']);
        $job = $this->library->jobs->start('previews');
        $state = $this->library->jobs->step('previews', $job['token']);
        $this->assertSame(1, $state['completed']);
        $this->assertSame(1, $state['total']);
        $this->assertSame(0, $state['errors']);
        $this->assertSame('legacy medium fixture', file_get_contents($legacy));
        $this->assertSame(1234567890, filemtime($legacy));
        $this->assertSame(['meer'], $this->client->downloads);
        $this->library->jobs->reset('previews');
        $this->assertFileDoesNotExist($thumbnail);
        $this->assertSame([$legacy], glob($this->root . '/.data/thumbnails/*'));
    }

    public function testExistingThumbnailIsReusedWithoutRedownload(): void
    {
        $id = $this->indexMeer();
        $this->library->oneDrive->previews([$id]);
        $thumbnail = $this->library->imagePath($id);
        $hash = hash_file('sha256', $thumbnail);
        touch($thumbnail, 1234567890);
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->assertSame([$id => true], $this->library->oneDrive->previews([$id]));
        $this->assertSame($thumbnail, $this->library->imagePath($id));
        clearstatcache();
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertSame($hash, hash_file('sha256', $thumbnail));
        $this->assertSame(['meer'], $this->client->downloads);
    }

    public function testRepeatedThumbnailJobsReuseCachesAndRestoreMissingFiles(): void
    {
        $id = $this->indexMeer();
        $this->library->oneDrive->previews([$id]);
        $thumbnail = $this->library->imagePath($id);
        touch($thumbnail, 1234567890);
        $this->assertSame($thumbnail, $this->library->imagePath($id, cachedOnly: true));
        foreach ([1, 2] as $repeat) {
            $run = $this->library->jobs->start('previews');
            $run = $this->library->jobs->step('previews', $run['token']);
            $this->assertSame('done', $run['status']);
            $this->assertSame(1, $run['completed']);
            $this->assertSame(0, $run['errors']);
        }
        clearstatcache();
        $this->assertSame(1234567890, filemtime($thumbnail));
        $this->assertSame(['meer'], $this->client->downloads);
        unlink($thumbnail);
        $this->assertNull($this->library->imagePath($id, cachedOnly: true));
        $this->assertNull($this->library->imagePath($id));
        $run = $this->library->jobs->start('previews');
        $this->assertSame('done', $this->library->jobs->step('previews', $run['token'])['status']);
        $this->assertFileExists($thumbnail);
        $this->assertSame(['meer', 'meer'], $this->client->downloads);
    }

    public function testCachedOnlyLookupStillRejectsUnavailableOrUnmappedPhotos(): void
    {
        $id = $this->indexMeer();
        $this->library->oneDrive->previews([$id]);
        $this->assertNotNull($this->library->imagePath($id, cachedOnly: true));
        $this->library->database->exec('UPDATE photos SET available = 0');
        $this->assertNull($this->library->imagePath($id, cachedOnly: true));
        $this->library->database->exec('UPDATE photos SET available = 1; DELETE FROM onedrive_photos');
        $this->assertNull($this->library->imagePath($id, cachedOnly: true));
        $this->assertNull($this->library->imagePath(999, cachedOnly: true));
    }

    public function testMissingThumbnailPreservesAiRatingAndIsRestoredByTheThumbnailJob(): void
    {
        $id = $this->indexMeer();
        $this->library->oneDrive->previews([$id]);
        $thumbnail = $this->library->imagePath($id);
        $this->library->database->exec("UPDATE photos SET status = 'done', priority = 1, ai_priority = 1");
        unlink($thumbnail);
        $this->index([]);
        $this->assertSame('done', $this->library->photo($id)->status);
        $this->assertSame(1, $this->library->photo($id)->priority);
        $this->assertNull($this->library->imagePath($id));
        $this->assertSame(['meer'], $this->client->downloads);
        $run = $this->library->jobs->start('previews');
        $this->library->jobs->step('previews', $run['token']);
        $this->assertFileExists($this->library->imagePath($id));
    }

    public function testMissingPreviewIsDeferredWithoutBlockingOtherPhotos(): void
    {
        file_put_contents(
            $this->root . '/.data/.env',
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=test\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=test-only\n",
            FILE_APPEND
        );
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->index([$this->item('a', 'Meer.jpg'), $this->item('b', 'Zweiter.jpg')]);
        $this->library->oneDrive->previews([2]);
        $this->assertSame(0, $this->library->tag(limit: 1));
        $this->assertSame('error', $this->library->photo(1)->status);
        $this->assertSame('pending', $this->library->photo(2)->status);
        $stats = new ReflectionMethod(PhotoButler::class, 'photoStats')->invoke($this->library);
        $this->assertSame(2, $stats['queued']);
    }

    public function testAiRatingOnlyRequestsUnratedPhotos(): void
    {
        file_put_contents(
            $this->root . '/.data/.env',
            "AI_PROVIDER=cliproxyapi\nAI_MODEL=test\nAI_BASE_URL=http://127.0.0.1:1\nAI_API_KEY=test-only\n",
            FILE_APPEND
        );
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->index([$this->item('a', 'Meer.jpg'), $this->item('b', 'Zweiter.jpg')]);
        $this->library->oneDrive->previews([1, 2]);
        $this->library->priority(1, -1);
        $this->assertSame(0, $this->library->tag(limit: 5));
        $this->assertSame('pending', $this->library->photo(1)->status);
        $this->assertSame(-1, $this->library->photo(1)->priority);
        $this->assertSame('error', $this->library->photo(2)->status);
        $this->assertSame(1, $this->library->jobs->all()['tag']['total']);
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

    public function testInitializerGeneratesAMissingCronSecretOnce(): void
    {
        $envPath = $this->root . '/.data/.env';
        file_put_contents($envPath, preg_replace('/^CRON_SECRET=.*\R/m', '', file_get_contents($envPath)));
        $initialize = function (): void {
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
        };
        $initialize();
        $env = file_get_contents($envPath);
        $this->assertSame(1, preg_match_all('/^CRON_SECRET=[a-f0-9]{64}$/m', $env));
        $this->assertStringContainsString("JWT_SECRET=photobutler-test-signing-secret-32-bytes\n", $env);
        $initialize();
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
        $id = $this->indexMeer();
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
            [PHP_BINARY, '-d', 'session.save_path=' . $this->root, '-S', $address, '-t', $this->root . '/public'],
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
            foreach (['?cron=wrong', '?cron=', '?cron[]=photobutler-test-cron-secret-with-32-bytes'] as $cron) {
                $this->assertSame(403, $request($cron)[0]);
            }
            $this->assertSame([], $this->library->database->query('SELECT * FROM job_logs')->fetchAll());
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
            $source = $this->root . '/.data/onedrive-source.json';
            rename($source, $source . '.offline');
            try {
                [$status, $jobsBody] = $request('?view=jobs');
                $this->assertSame(200, $status);
                $jobsDocument = \Dom\HTMLDocument::createFromString($jobsBody, LIBXML_NOERROR);
                $this->assertSame(5, $jobsDocument->querySelectorAll('[data-job]')->length);
                [$status, $jobsBody] = $request('?jobs=1');
                $this->assertSame(200, $status);
                $this->assertCount(5, json_decode($jobsBody, true, flags: JSON_THROW_ON_ERROR));
            } finally {
                rename($source . '.offline', $source);
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
            $face = new stdClass();
            $face->box = [0.1, 0.1, 0.2, 0.2];
            $face->embedding = array_pad([1.0], 128, 0.0);
            $face->crop = base64_encode($this->client->jpeg);
            $analysis = new stdClass();
            $analysis->status = 'done';
            $analysis->faces = [$face];
            $this->library->faces->save(
                $this->library->database->query('SELECT * FROM photos WHERE id = ' . $id)->fetch(),
                $analysis
            );
            $detail = json_decode($request('?detail=' . $id)[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $detail['faces']);
            $this->assertSame([0.1, 0.1, 0.2, 0.2], $detail['faces'][0]['box']);
            $this->assertIsInt($detail['faces'][0]['person']);
            $this->assertArrayNotHasKey('embedding', $detail['faces'][0]);
            [$personsStatus, $personsBody] = $request('?view=persons');
            $this->assertSame(200, $personsStatus);
            $document = \Dom\HTMLDocument::createFromString($personsBody, LIBXML_NOERROR);
            $this->assertCount(0, $document->querySelectorAll('.person-grid .person-card'));
            $this->assertStringContainsString('Ausgeblendete Gruppen anzeigen (1)', $personsBody);
            [, $personsBody] = $request('?view=persons&hidden=1');
            $document = \Dom\HTMLDocument::createFromString($personsBody, LIBXML_NOERROR);
            $this->assertCount(1, $document->querySelectorAll('.person-grid .person-card'));
            $this->assertStringContainsString('Eingeblendete Personen anzeigen (0)', $personsBody);
            preg_match('/name="csrf-token" content="([^"]+)"/', $personsBody, $match);
            $person = (int) $this->library->database->query('SELECT person_id FROM faces')->fetchColumn();
            $this->library->faces->correct('rename', $person, 'Gast');
            $this->assertStringContainsString('Gast', $request('?view=persons')[1]);
            $this->assertStringNotContainsString('Gast', $request('?view=persons&hidden=1')[1]);
            [$hideStatus, $hideBody] = $request('', ['action' => 'face-hide', 'id' => $person, 'csrf' => $match[1]]);
            $this->assertSame(200, $hideStatus, $hideBody);
            $this->assertStringNotContainsString('Gast', $request('?view=persons')[1]);
            $this->assertStringContainsString('Gast', $request('?view=persons&hidden=1')[1]);
            [, $personBody] = $request('?view=persons&person=' . $person);
            $this->assertStringContainsString('Person einblenden', $personBody);
            $this->assertSame(200, $request('', ['action' => 'face-show', 'id' => $person, 'csrf' => $match[1]])[0]);
            $this->assertStringContainsString('Gast', $request('?view=persons')[1]);
            $document = \Dom\HTMLDocument::createFromString(
                $request('?relevance=all&person=' . $person)[1],
                LIBXML_NOERROR
            );
            $this->assertSame(
                (string) $person,
                $document->querySelector('#gallery-person option[selected]')?->getAttribute('value')
            );
            $this->assertCount(1, $document->querySelectorAll('.photo-card'));
            $friend = clone $face;
            $friend->box = [0.5, 0.1, 0.2, 0.2];
            $friend->embedding = array_pad([0.0, 1.0], 128, 0.0);
            $analysis->faces = [$face, $friend];
            $this->library->faces->reset($id, false);
            $this->library->faces->save(
                $this->library->database->query('SELECT * FROM photos WHERE id = ' . $id)->fetch(),
                $analysis
            );
            $other = (int) $this->library->database
                ->query('SELECT person_id FROM faces WHERE person_id <> ' . $person)
                ->fetchColumn();
            $this->library->faces->correct('rename', $other, 'Freund');
            [, $personBody] = $request('?view=persons&person=' . $person);
            $document = \Dom\HTMLDocument::createFromString($personBody, LIBXML_NOERROR);
            $this->assertCount(
                1 + count($this->library->faces->personFaces($person)),
                $document->querySelectorAll('.person-picker')
            );
            $this->assertCount(0, $document->querySelectorAll('.person-picker input'));
            $this->assertCount(
                count($this->library->faces->personFaces($person)),
                $document->querySelectorAll('.person-card form .person-picker')
            );
            $options = \Dom\HTMLDocument::createFromString(
                '<!doctype html><body>' . $document->querySelector('#person-picker-options')->innerHTML,
                LIBXML_NOERROR
            );
            $option = $options->querySelector('input[name="target"][value="' . $other . '"]');
            $this->assertNotNull($option);
            $this->assertStringStartsWith('?face=', $option->parentElement->querySelector('img')->getAttribute('src'));
            $this->assertStringContainsString('Freund', $option->parentElement->textContent);
            $this->assertNull($options->querySelector('input[value="' . $person . '"]'));
            foreach (
                [
                    'relevance=all&from=2026-09-01&to=2026-09-01' => ['2026-09-01', '2026-09-01', 1],
                    'relevance=all&from=2026-09-02' => ['2026-09-02', '', 0],
                    'relevance=all&from=2026-02-30&to=01.09.2026' => ['', '', 1],
                    'relevance=all&from[]=2026-09-02' => ['', '', 1]
                ]
                as $query => [$from, $to, $count]
            ) {
                [$dateStatus, $dateBody] = $request('?' . $query);
                $this->assertSame(200, $dateStatus);
                $document = \Dom\HTMLDocument::createFromString($dateBody, LIBXML_NOERROR);
                $this->assertSame($from, $document->querySelector('#gallery-from')->getAttribute('value'));
                $this->assertSame($to, $document->querySelector('#gallery-to')->getAttribute('value'));
                $this->assertCount($count, $document->querySelectorAll('.photo-card'));
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
            $this->assertSame(5, $document->querySelectorAll('[data-job]')->length);
            $this->assertSame(
                ['Galerie einlesen', 'Thumbnails downloaden', 'Gesichtertagging', 'KI-Bewertung', 'Ähnliche Fotos'],
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
            $this->assertSame(5, $document->querySelectorAll('[data-job-action="reset"]')->length);
            foreach (['scan', 'previews', 'tag', 'faces', 'similar'] as $job) {
                $command = $document->querySelector('[data-job="' . $job . '"] .job-command code')->textContent;
                $this->assertStringStartsWith('php ', $command);
                $this->assertStringContainsString('--root=' . escapeshellarg($this->root), $command);
                $this->assertStringEndsWith('--' . $job . '-only', $command);
            }
            $this->assertStringNotContainsString('photobutler-test-cron-secret', $body);
            $this->assertSame(404, $request('?photo=' . $id . '&size=thumb')[0]);
            $this->assertNull($this->library->imagePath($id, cachedOnly: true));
            $run = $this->library->jobs->start('previews');
            $this->assertSame('done', $this->library->jobs->step('previews', $run['token'])['status']);
            $this->assertSame(200, $request('?photo=' . $id . '&size=thumb')[0]);
            $etags = [];
            foreach (['thumb', 'display'] as $size) {
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
            $this->index([$this->item('meer', 'Meer.jpg', 'urlaub', version: 'v2')]);
            $this->assertSame(
                404,
                $request('?photo=' . $id . '&size=thumb', headers: ['If-None-Match: ' . $etags['thumb']])[0]
            );
            $image = imagecreatetruecolor(16, 12);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
            ob_start();
            imagejpeg($image);
            $this->client->jpeg = ob_get_clean();
            $run = $this->library->jobs->start('previews');
            $this->assertSame('done', $this->library->jobs->step('previews', $run['token'])['status']);
            foreach ($etags as $size => $etag) {
                [$status, , $headers] = $request(
                    '?photo=' . $id . '&size=' . $size,
                    headers: ['If-None-Match: ' . $etag]
                );
                $this->assertSame(200, $status);
                $this->assertNotSame($etag, $headers['etag']);
            }
            [, , $headers] = $request('?photo=' . $id . '&size=thumb&download=1');
            $this->assertStringContainsString('no-store', $headers['cache-control']);
            $this->assertStringStartsWith('attachment;', $headers['content-disposition']);
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
                    'priority',
                    'favorite',
                    'video',
                    'status',
                    'width',
                    'height',
                    'persons',
                    'face_status',
                    'similar',
                    'faces'
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

            $this->library->database
                ->prepare('UPDATE photos SET description = ? WHERE id = ?')
                ->execute(['<script>alert(1)</script>', $id]);
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
            [$status, $body, $headers] = $request('?cron=photobutler-test-cron-secret-with-32-bytes');
            $this->assertSame(200, $status);
            $this->assertStringContainsString('text/plain', $headers['content-type']);
            $this->assertStringContainsString("Galerie einlesen: OneDrive erneut anmelden: --onedrive-login.\n", $body);
            $this->assertStringContainsString("Thumbnails downloaden: done · 100 % · 0 Fehler\n", $body);
            $this->assertStringContainsString("KI-Bewertung: übersprungen (KI nicht konfiguriert)\n", $body);
            $this->assertStringContainsString(
                "Gesichtertagging: übersprungen (Gesichtserkennung nicht installiert)\n",
                $body
            );
            $this->assertSame('paused', $this->library->jobs->all()['scan']['status']);
            file_put_contents(
                $this->root . '/.data/.env',
                preg_replace('/^CRON_SECRET=.*$/m', 'CRON_SECRET=short', file_get_contents($this->root . '/.data/.env'))
            );
            $this->assertSame(503, $request('?cron=short')[0]);
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
