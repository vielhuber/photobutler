<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
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
        file_put_contents($target, $this->jpeg);
        return true;
    }
}

final class OneDriveTest extends TestCase
{
    private string $root;
    private PhotoButler $library;
    private FixtureOneDriveClient $client;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-cloud-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.data', 0700, true);
        file_put_contents($this->root . '/.data/.env', "ONEDRIVE_CLIENT_ID=fixture\nONEDRIVE_FOLDER=FOTOS\n");
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
        string $version = 'v1'
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'parentReference' => ['id' => $parent],
            'file' => ['mimeType' => 'image/jpeg'],
            'size' => strlen($this->client->jpeg),
            'lastModifiedDateTime' => '2026-09-01T12:00:00Z',
            'cTag' => $version
        ];
    }

    private function index(array $items): void
    {
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => []], ...$items],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
        ];
        $this->library->index();
    }

    public function testPreviewWorkerWaitsForBoundSocketToStartListening(): void
    {
        mkdir($this->root . '/commands');
        file_put_contents(
            $this->root . '/commands/listener.py',
            <<<'PYTHON'
            import os, socket, sys, time
            path = sys.argv[1]
            server = socket.socket(socket.AF_UNIX)
            try:
                server.bind(path)
                time.sleep(0.25)
                server.listen(1)
                server.settimeout(5)
                connection, _ = server.accept()
                connection.recv(32)
                connection.sendall(b"1\n")
                connection.close()
            finally:
                server.close()
                os.unlink(path)
                os.rmdir(os.path.dirname(path))
            PYTHON
        );
        file_put_contents(
            $this->root . '/commands/node',
            "#!/bin/sh\npython3 " .
                escapeshellarg($this->root . '/commands/listener.py') .
                ' "$7" >/dev/null 2>&1 &' .
                "\n"
        );
        chmod($this->root . '/commands/node', 0700);
        $path = getenv('PATH');
        putenv('PATH=' . $this->root . '/commands:' . $path);
        try {
            $this->assertSame([1 => true], new \vielhuber\photobutler\PreviewPool($this->root . '/.data')->render([1]));
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testLegacyWindowsThumbnailJobsAreBlockedBeforeTouchingTheMount(): void
    {
        file_put_contents($this->root . '/.data/.env', "PHOTO_PATHS='[\"/mnt/o/FOTOS\"]'\n");
        $library = new PhotoButler($this->root);
        $this->expectExceptionCode(412);
        $this->expectException(RuntimeException::class);
        $library->jobs->start('previews');
    }

    public function testMetadataProgressEstimatesTheScanSeparatelyAndSurvivesResume(): void
    {
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => ['childCount' => 100]], $this->item('a')],
            '@odata.nextLink' => OneDriveClient::GRAPH . '/next'
        ];
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(2, $run['checked']);
        $this->assertSame(101, $run['scan_total']);
        $this->assertSame(0, $run['completed']);
        $this->assertGreaterThan(0, $run['eta_seconds']);
        ob_start();
        $this->library->jobs->printProgress('scan', $run);
        $output = ob_get_clean();
        $this->assertStringContainsString('ca. 1 %', $output);
        $this->assertStringContainsString('2/ca. 101 Metadateneinträge geprüft', $output);
        $this->assertStringContainsString('Katalog: 0 Fotos', $output);
        $this->assertStringContainsString('Restzeit:', $output);
        $this->library->jobs->pause('scan', $run['token']);
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->assertSame(101, $this->library->jobs->all()['scan']['scan_total']);
        $this->client->pages[] = ['value' => [], '@odata.deltaLink' => OneDriveClient::GRAPH . '/delta'];
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame('done', $run['status']);
        $this->assertSame(2, $run['scan_total']);
        $this->assertSame(0, $run['eta_seconds']);
        ob_start();
        $this->library->jobs->printProgress('scan', $run);
        $output = ob_get_clean();
        $this->assertStringContainsString('100 %', $output);
        $this->assertStringNotContainsString('Restzeit:', $output);
        $this->assertSame([], $this->client->downloads);
    }

    public function testProcessingJobsShareProgressBarsCountsErrorsAndRemainingTime(): void
    {
        foreach (['previews', 'tag', 'faces'] as $job) {
            ob_start();
            $this->library->jobs->printProgress(
                $job,
                [
                    'status' => 'running',
                    'completed' => 200,
                    'total' => 1000,
                    'estimated' => 0,
                    'errors' => 2,
                    'eta' => 'ca. 3 Min.'
                ],
                'Batch · Download 10/100'
            );
            $output = ob_get_clean();
            $this->assertStringContainsString('20 % · 200/1.000 Fotos · 2 Fehler · Restzeit: ca. 3 Min.', $output);
            $this->assertStringContainsString('Batch · Download 10/100 [====----------------]', $output);
        }
    }

    public function testMetadataEstimatesExpandAndNeverFinishBeforeTheLastPage(): void
    {
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'name' => 'FOTOS', 'folder' => ['childCount' => 2]]],
            '@odata.nextLink' => OneDriveClient::GRAPH . '/next'
        ];
        $run = $this->library->jobs->start('scan');
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(3, $run['scan_total']);
        $this->client->pages[] = [
            'value' => [
                [
                    'id' => 'folder',
                    'name' => 'More',
                    'parentReference' => ['id' => 'root'],
                    'folder' => ['childCount' => 100]
                ]
            ],
            '@odata.nextLink' => OneDriveClient::GRAPH . '/next2'
        ];
        $run = $this->library->jobs->step('scan', $run['token']);
        $this->assertSame(103, $run['scan_total']);
        $this->assertSame('running', $run['status']);
        ob_start();
        $this->library->jobs->printProgress('scan', $run);
        $output = ob_get_clean();
        $this->assertStringNotContainsString('100 %', $output);
        $this->assertStringContainsString('ca. ', $output);
    }

    public function testIndexStagesPagesWithoutDownloadsAndKeepsSameNamesSeparate(): void
    {
        $this->index([$this->item('a')]);
        $this->client->pages[] = [
            'value' => [$this->item('b', 'photo.jpg', 'folder')],
            '@odata.nextLink' => OneDriveClient::GRAPH . '/next'
        ];
        $this->client->pages[] = [
            'value' => [['id' => 'folder', 'name' => 'Other', 'folder' => [], 'parentReference' => ['id' => 'root']]],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/delta'
        ];
        $this->library->index();
        $this->assertSame(1, $this->library->photoCount());
        $this->assertSame(1, $this->library->importProgress()['estimated']);
        $this->library->index();
        $this->assertSame(2, $this->library->photoCount());
        $this->assertSame(['FOTOS', 'Other'], array_column($this->library->photos(sort: 'oldest'), 'album'));
        $this->assertSame([], $this->client->downloads);
        $this->assertSame(0, $this->library->importProgress()['estimated']);
    }

    public function testRenamePreservesIdRatingAndCacheButContentChangeInvalidatesOnlyItsPreview(): void
    {
        $this->index([$this->item('a'), $this->item('b', 'second.jpg')]);
        $this->library->priority(1, 1);
        $this->library->saveTags(1, 'manual');
        $paths = $this->library->database->query('SELECT path FROM photos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($paths as $path) {
            file_put_contents($this->root . '/.data/thumbnails/' . hash('sha256', $path) . '.jpg', $this->client->jpeg);
        }
        $thumbnail = $this->library->imagePath(1, cachedOnly: true);
        $this->index([$this->item('a', 'renamed.jpg')]);
        $this->assertSame('renamed.jpg', $this->library->photo(1)->name);
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame($thumbnail, $this->library->imagePath(1, cachedOnly: true));
        $this->index([$this->item('a', 'renamed.jpg', version: 'v2')]);
        $this->assertNull($this->library->imagePath(1, cachedOnly: true));
        $this->assertNotNull($this->library->imagePath(2, cachedOnly: true));
        $this->assertSame(['manual'], $this->library->photo(1)->tags);
    }

    public function testMissingSourceAndInterruptedEnumerationNeverHideExistingPhotos(): void
    {
        $this->index([$this->item('a')]);
        $this->client->pages[] = [
            'value' => [['id' => 'root', 'deleted' => []]],
            '@odata.deltaLink' => OneDriveClient::GRAPH . '/delta'
        ];
        try {
            $this->library->index();
            $this->fail('Missing folder accepted');
        } catch (RuntimeException) {
        }
        $this->assertNotNull($this->library->photo(1));
        $this->assertSame([], $this->client->downloads);
    }

    public function testDeletedFolderRemovesDescendantsFromAvailabilityWithoutRemovingMetadata(): void
    {
        $this->index([
            ['id' => 'folder', 'name' => 'Other', 'folder' => [], 'parentReference' => ['id' => 'root']],
            $this->item('a', parent: 'folder')
        ]);
        $this->library->priority(1, 1);
        $this->index([['id' => 'folder', 'deleted' => []]]);
        $this->assertNull($this->library->photo(1));
        $this->assertSame(
            1,
            (int) $this->library->database->query('SELECT priority FROM photos WHERE id=1')->fetchColumn()
        );
    }

    public function testDeletedFileLeavesTheGalleryWithoutDeletingItsMetadata(): void
    {
        $this->index([$this->item('a', 'deleted.mov')]);
        $this->library->saveTags(1, 'Keep');
        $this->index([['id' => 'a', 'deleted' => []]]);
        $this->assertNull($this->library->photo(1));
        $this->assertSame(0, $this->library->photoCount());
        $this->assertSame(
            0,
            (int) $this->library->database->query('SELECT available FROM photos WHERE id=1')->fetchColumn()
        );
        $this->assertSame(
            '["Keep"]',
            $this->library->database->query('SELECT manual_tags FROM photos WHERE id=1')->fetchColumn()
        );
    }

    public function testExpiredDeltaRestartsMetadataOnlyAndPreservesCatalog(): void
    {
        $this->index([$this->item('a')]);
        $this->client->pages[] = new RuntimeException('expired', 410);
        try {
            $this->library->index();
            $this->fail('Expected delta expiry');
        } catch (RuntimeException $exception) {
            $this->assertSame(410, $exception->getCode());
        }
        $this->assertSame(1, $this->library->photoCount());
        $this->index([$this->item('a')]);
        $this->assertSame(1, $this->library->photoCount());
        $this->assertSame([], $this->client->downloads);
    }

    public function testCatalogResetRestoresCloudIdentityAndManualData(): void
    {
        $this->index([$this->item('a')]);
        $this->library->priority(1, 1);
        $this->library->saveTags(1, 'manual');
        $this->library->jobs->reset('scan');
        $this->assertSame(0, $this->library->photoCount());
        $this->index([]);
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame(['manual'], $this->library->photo(1)->tags);
    }

    public function testMigrationPreservesLocalIdsCachesAndMetadataWithoutAccessingOldMount(): void
    {
        mkdir($this->root . '/legacy');
        file_put_contents($this->root . '/legacy/photo.jpg', $this->client->jpeg);
        $configuration = file_get_contents($this->root . '/.data/.env');
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" . json_encode([$this->root . '/legacy']) . "'\n"
        );
        $local = new PhotoButler($this->root);
        $local->index();
        $local->priority(1, 1);
        $local->saveTags(1, 'retained');
        $thumbnail = $local->imagePath(1);
        unlink($this->root . '/legacy/photo.jpg');
        rmdir($this->root . '/legacy');
        file_put_contents(
            $this->root . '/.data/.env',
            $configuration . 'ONEDRIVE_LEGACY_ROOT=' . $this->root . "/legacy\n"
        );
        $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
        $this->index([$this->item('a')]);
        $this->assertSame(1, $this->library->photoCount());
        $this->assertSame(1, $this->library->photo(1)->priority);
        $this->assertSame(['retained'], $this->library->photo(1)->tags);
        $this->assertSame($thumbnail, $this->library->imagePath(1, cachedOnly: true));
        $this->assertSame([], $this->client->downloads);
        $this->index([['id' => 'a', 'deleted' => []], $this->item('b')]);
        $this->assertNull($this->library->photo(1));
        $this->assertNotNull($this->library->photo(2));
        $this->assertNull($this->library->imagePath(2, cachedOnly: true));
    }

    public function testFolderRenameChangesAlbumsWithoutInvalidatingThumbnails(): void
    {
        $folder = ['id' => 'folder', 'name' => 'Old', 'folder' => [], 'parentReference' => ['id' => 'root']];
        $this->index([$folder, $this->item('a', parent: 'folder')]);
        $path = $this->library->database->query('SELECT path FROM photos WHERE id=1')->fetchColumn();
        $thumbnail = $this->root . '/.data/thumbnails/' . hash('sha256', $path) . '.jpg';
        file_put_contents($thumbnail, $this->client->jpeg);
        $folder['name'] = 'New';
        $this->index([$folder]);
        $this->assertSame('New', $this->library->photo(1)->album);
        $this->assertSame($thumbnail, $this->library->imagePath(1, cachedOnly: true));
        $this->assertSame([], $this->client->downloads);
    }

    public function testUnmappedCatalogPhotosStopTheBatchInsteadOfRepeatingForever(): void
    {
        $this->index([$this->item('old')]);
        $this->library->database->exec('DELETE FROM onedrive_photos');
        $id = (int) $this->library->database->query('SELECT id FROM photos')->fetchColumn();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Galerie zuerst vollständig über OneDrive einlesen.');
        $this->library->oneDrive->previews([$id]);
    }

    public function testMissingThumbnailsNeverDownloadFromAnalysisOrGallery(): void
    {
        $this->index([$this->item('a')]);
        $this->assertNull($this->library->imagePath(1, generate: false));
        $this->assertNull($this->library->imagePath(1, original: true));
        $this->library->tag(1);
        $this->library->tagFaces(1);
        $this->assertSame([], $this->client->downloads);
    }

    public function testThumbnailsAreDownloadedForEveryFormatWithoutOriginalsOrRendering(): void
    {
        $items = [];
        foreach (['jpg', 'png', 'gif', 'webp', 'mov', 'avi', '3gp'] as $number => $extension) {
            $item = $this->item('item-' . $number, 'photo.' . $extension);
            $item['size'] = 3000000000;
            $items[] = $item;
        }
        $this->index($items);
        $state = $this->library->jobs->start('previews');
        ob_start();
        try {
            $state = $this->library->jobs->step('previews', $state['token']);
            $this->assertSame('done', $state['status']);
            $this->assertSame(7, $state['completed']);
            $this->assertSame(0, $state['errors']);
            foreach (range(1, 7) as $id) {
                $this->assertSame(
                    $this->client->jpeg,
                    file_get_contents($this->library->imagePath($id, cachedOnly: true))
                );
            }
            $this->assertDirectoryDoesNotExist($this->root . '/.data/onedrive-batch');
            $this->assertSame([], glob($this->root . '/.data/preview-worker-*'));
            $this->assertSame(array_fill_keys(range(1, 7), true), $this->library->oneDrive->previews(range(1, 7)));
            $this->assertCount(7, $this->client->downloads);
        } finally {
            ob_end_clean();
        }
    }

    public function testInterruptedThumbnailDownloadResumesUsingAlreadySavedThumbnails(): void
    {
        $this->index([$this->item('a'), $this->item('b')]);
        $this->client->failDownload = 2;
        ob_start();
        try {
            try {
                $this->library->oneDrive->previews([1, 2]);
                $this->fail('Expected interrupted download');
            } catch (RuntimeException) {
            }
            $this->assertNotNull($this->library->imagePath(1, cachedOnly: true));
            $this->assertNull($this->library->imagePath(2, cachedOnly: true));
            $this->client->failDownload = null;
            $this->assertSame([1 => true, 2 => true], $this->library->oneDrive->previews([1, 2]));
            $this->assertSame(['a', 'b', 'b'], $this->client->downloads);
        } finally {
            ob_end_clean();
        }
    }

    public function testUnavailablePreviewsPersistAsSuccessfulFallbacksWithoutRepeatDownloads(): void
    {
        $this->index([$this->item('a'), $this->item('b'), $this->item('c')]);
        $this->client->missingThumbnails = ['b'];
        ob_start();
        try {
            foreach (range(1, 2) as $attempt) {
                $this->library = new PhotoButler($this->root, oneDriveClient: $this->client);
                $run = $this->library->jobs->start('previews');
                $run = $this->library->jobs->step('previews', $run['token']);
                $this->assertSame(['a', 'b', 'c'], $this->client->downloads);
                $this->assertSame(3, $run['completed']);
                $this->assertSame(0, $run['errors']);
                $this->assertSame('done', $run['status']);
                $this->assertSame('image/svg+xml', mime_content_type($this->library->imagePath(2)));
                $this->assertNull($this->library->imagePath(2, cachedOnly: true));
                $this->assertNull($this->library->imagePath(2, original: true));
                $this->assertNotNull($this->library->imagePath(3, cachedOnly: true));
                $this->assertSame([2 => true], $this->library->oneDrive->previews([2]));
                $this->assertSame(['a', 'b', 'c'], $this->client->downloads);
            }
            $this->index([$this->item('b', 'renamed.jpg')]);
            $this->assertNotNull($this->library->imagePath(2));
            $this->index([$this->item('b', 'renamed.jpg', version: 'v2')]);
            $this->assertNull($this->library->imagePath(2));
            $this->library->oneDrive->previews([2]);
            $this->assertNotNull($this->library->imagePath(2));
            $this->library->jobs->reset('previews');
            $this->assertNull($this->library->imagePath(2));
        } finally {
            ob_end_clean();
        }
    }

    public function testFastCachePassDownloadsOnlyGapsAndCompletesInOneStep(): void
    {
        $this->index(array_map(fn(int $id): array => $this->item('item-' . $id), range(1, 250)));
        foreach ($this->library->database->query('SELECT id, path FROM photos') as $row) {
            if (in_array($row['id'], [3, 249], true)) {
                continue;
            }
            file_put_contents(
                $this->root . '/.data/thumbnails/' . hash('sha256', $row['path']) . '.jpg',
                $this->client->jpeg
            );
        }
        $this->client->requests = [];
        $state = $this->library->jobs->start('previews');
        ob_start();
        try {
            $state = $this->library->jobs->step('previews', $state['token'], previewLimit: PHP_INT_MAX);
        } finally {
            ob_end_clean();
        }
        $this->assertSame('done', $state['status']);
        $this->assertSame(250, $state['completed']);
        $this->assertSame(250, $state['cursor']);
        $this->assertSame(0, $state['errors']);
        $this->assertCount(2, $this->client->downloads);
        $this->assertSame([], $this->client->requests);
        $this->assertSame($this->client->jpeg, file_get_contents($this->library->imagePath(1, cachedOnly: true)));
    }

    public function testFastCachePassPreservesPhotoLimitsAndDoesNotDistortDownloadTimings(): void
    {
        $this->index(array_map(fn(int $id): array => $this->item('item-' . $id), range(1, 250)));
        foreach ($this->library->database->query('SELECT path FROM photos') as $row) {
            file_put_contents(
                $this->root . '/.data/thumbnails/' . hash('sha256', $row['path']) . '.jpg',
                $this->client->jpeg
            );
        }
        $this->library->database->exec("INSERT INTO job_timings VALUES ('thumbnails', 12.5)");
        $state = $this->library->jobs->start('previews');
        ob_start();
        try {
            $state = $this->library->jobs->step('previews', $state['token'], previewLimit: 17);
            $this->assertSame(17, $state['completed']);
            $this->assertSame(17, $state['cursor']);
            $this->assertSame('running', $state['status']);
            $this->library->jobs->pause('previews', $state['token']);
            $state = $this->library->jobs->start('previews');
            $state = $this->library->jobs->step('previews', $state['token'], previewLimit: PHP_INT_MAX);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(250, $state['completed']);
        $this->assertSame('done', $state['status']);
        $this->assertSame([], $this->client->downloads);
        $this->assertSame(
            12.5,
            (float) $this->library->database
                ->query("SELECT seconds_per_file FROM job_timings WHERE job='thumbnails'")
                ->fetchColumn()
        );
    }

    public function testFastCachePassRetainsDownloadBatchLimitAndCatalogValidation(): void
    {
        $this->index(array_map(fn(int $id): array => $this->item('item-' . $id), range(1, 250)));
        $state = $this->library->jobs->start('previews');
        ob_start();
        try {
            $state = $this->library->jobs->step('previews', $state['token'], previewLimit: PHP_INT_MAX);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(100, $state['completed']);
        $this->assertSame('running', $state['status']);
        $this->assertCount(100, $this->client->downloads);
        $this->library->database->exec('DELETE FROM onedrive_photos WHERE photo_id=101');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Galerie zuerst vollständig über OneDrive einlesen.');
        $this->library->jobs->step('previews', $state['token'], previewLimit: PHP_INT_MAX);
    }

    public function testFastCachePassRemainsCancellableBeforeAnyDownload(): void
    {
        $this->index([$this->item('a')]);
        $state = $this->library->jobs->start('previews');
        $this->client->cancelled = true;
        try {
            $this->library->jobs->step('previews', $state['token'], previewLimit: PHP_INT_MAX);
            $this->fail('Expected cancellation');
        } catch (\vielhuber\photobutler\JobInterrupted) {
            $this->assertSame('paused', $this->library->jobs->all()['previews']['status']);
            $this->assertSame([], $this->client->downloads);
        }
    }
}
