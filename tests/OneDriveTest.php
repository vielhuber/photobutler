<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\OneDriveClient;
use vielhuber\photobutler\PhotoButler;

final class OneDriveTest extends TestCase
{
    use CloudFixture;

    protected function setUp(): void
    {
        $this->createCloudLibrary();
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
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

    public function testDeletedFolderRemovesItsDescendantsFromTheCatalog(): void
    {
        $this->index([
            ['id' => 'folder', 'name' => 'Other', 'folder' => [], 'parentReference' => ['id' => 'root']],
            $this->item('a', parent: 'folder'),
            $this->item('b', 'kept.jpg')
        ]);
        $this->library->priority(1, 1);
        $this->index([['id' => 'folder', 'deleted' => []]]);
        $this->assertNull($this->library->photo(1));
        $this->assertNotNull($this->library->photo(2));
        $this->assertSame(
            [2],
            array_map('intval', $this->library->database->query('SELECT id FROM photos')->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    public function testDeletedFileIsRemovedFromCatalogCachesAndFaceData(): void
    {
        $this->index([$this->item('a', 'deleted.mov'), $this->item('b', 'kept.jpg')]);
        $this->library->priority(1, 1);
        $this->library->oneDrive->previews([1, 2]);
        $deleted = $this->library->imagePath(1, cachedOnly: true);
        $kept = $this->library->imagePath(2, cachedOnly: true);
        $database = $this->library->database;
        $database->exec("INSERT INTO photo_metadata (id, path, modified, bytes, priority)
            SELECT id, path, modified, bytes, priority FROM photos WHERE id = 1;
            INSERT INTO persons (id, name) VALUES (1, ''), (2, 'Named'), (3, '');
            INSERT INTO faces (photo_id, person_id, modified, bytes, model, box, embedding, crop)
            SELECT id, CASE id WHEN 1 THEN 1 ELSE 3 END, modified, bytes, 'fixture', '[]', '[]', '' FROM photos;
            INSERT INTO faces (photo_id, person_id, modified, bytes, model, box, embedding, crop)
            SELECT id, 2, modified, bytes, 'fixture', '[]', '[]', '' FROM photos WHERE id = 1;
            INSERT INTO face_state (photo_id, modified, bytes, model, status) SELECT id, modified, bytes, 'fixture', 'done' FROM photos;");
        $this->index([['id' => 'a', 'deleted' => []]]);
        $this->assertNull($this->library->photo(1));
        $this->assertSame(1, $this->library->photoCount());
        foreach (['photos', 'onedrive_photos', 'face_state', 'faces'] as $table) {
            $column = $table === 'photos' ? 'id' : 'photo_id';
            $this->assertSame(
                0,
                (int) $database->query("SELECT COUNT(*) FROM $table WHERE $column = 1")->fetchColumn(),
                $table
            );
        }
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM photo_metadata')->fetchColumn());
        $this->assertFileDoesNotExist($deleted);
        $this->assertFileExists($kept);
        $this->assertSame(
            [[2, 'Named'], [3, '']],
            array_map(
                static fn(array $row): array => [(int) $row['id'], $row['name']],
                $database->query('SELECT id, name FROM persons ORDER BY id')->fetchAll()
            )
        );
        $this->index([$this->item('a', 'deleted.mov')]);
        $this->assertSame(0, $this->library->photo(3)->priority);
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
        $this->library->database
            ->prepare(
                "INSERT INTO photos (id, root, path, album, name, modified, bytes, width, height, taken, seen)
                VALUES (1, ?, ?, 'legacy', 'photo.jpg', 1, 1, 0, 0, '2026-09-01 12:00:00', 'legacy')"
            )
            ->execute([$this->root . '/legacy', $this->root . '/legacy/photo.jpg']);
        $this->library->priority(1, 1);
        $this->library->saveTags(1, 'retained');
        $thumbnail = $this->root . '/.data/thumbnails/' . hash('sha256', $this->root . '/legacy/photo.jpg') . '.jpg';
        file_put_contents($thumbnail, $this->client->jpeg);
        $configuration = file_get_contents($this->root . '/.data/.env');
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
        $this->assertNull($this->library->imagePath(1));
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

    public function testUnavailablePreviewsCompleteFaceAnalysisWithoutErrors(): void
    {
        $this->client->missingThumbnails = ['b'];
        $this->index([$this->item('a'), $this->item('b', 'video.mov')]);
        $this->library->oneDrive->previews([1, 2]);
        $state = $this->library->jobs->start('faces');
        while ($state['status'] === 'running') {
            $state = $this->library->jobs->step('faces', $state['token']);
        }
        $this->assertSame(1, $state['errors']);
        $this->assertSame(1, $state['completed']);
        $this->assertSame(
            ['1' => 'error', '2' => 'unsupported'],
            $this->library->database
                ->query('SELECT photo_id, status FROM face_state ORDER BY photo_id')
                ->fetchAll(PDO::FETCH_KEY_PAIR)
        );
        $this->assertSame(['a', 'b'], $this->client->downloads);
    }

    public function testFaceAnalysisSkipsHiddenPhotosUntilTheyAreShownAgain(): void
    {
        $this->client->missingThumbnails = ['a', 'b'];
        $this->index([$this->item('a'), $this->item('b', 'hidden.jpg')]);
        $this->library->oneDrive->previews([1, 2]);
        $this->library->priority(2, -1);
        $this->assertSame(1, $this->library->jobs->all()['faces']['total']);
        $state = $this->library->jobs->start('faces');
        while ($state['status'] === 'running') {
            $state = $this->library->jobs->step('faces', $state['token']);
        }
        $this->assertSame('done', $state['status']);
        $this->assertSame(100, $state['percent']);
        $this->assertSame(
            [1],
            array_map(
                'intval',
                $this->library->database->query('SELECT photo_id FROM face_state')->fetchAll(PDO::FETCH_COLUMN)
            )
        );
        $this->library->priority(2, 0);
        $state = $this->library->jobs->all()['faces'];
        $this->assertSame(2, $state['total']);
        $this->assertSame(1, $state['queued']);
    }

    public function testCaptureDatesFromNamesAndFoldersCorrectExistingEntriesAndExclusions(): void
    {
        $this->index([
            $this->folder('june', '2008.06'),
            $this->folder('recent', '2025'),
            $this->item('kodak', '100_0163.JPG', 'june', exif: false),
            $this->item('favorite', '100_0164.JPG', 'june', exif: false),
            $this->item('pixel', 'PXL_20250810_201051690.jpg', 'recent', exif: false),
            $this->item('undated', 'Bild (2).jpg', exif: false)
        ]);
        $database = $this->library->database;
        $database->exec("UPDATE photos SET taken = '2026-04-13 18:13:14', priority = 0");
        $this->library->priority(2, 1);
        $this->index([]);
        $this->assertSame(
            [
                ['100_0163.JPG', '2008-06-01 00:00:00', -1],
                ['100_0164.JPG', '2008-06-01 00:00:00', 1],
                ['PXL_20250810_201051690.jpg', '2025-08-10 20:10:51', 0],
                ['Bild (2).jpg', date('Y-m-d H:i:s', strtotime('2026-09-01T12:00:00Z')), -1]
            ],
            array_map(
                static fn(array $row): array => [$row['name'], $row['taken'], (int) $row['priority']],
                $database->query('SELECT name, taken, priority FROM photos ORDER BY id')->fetchAll()
            )
        );
    }

    public function testImportStoresOriginalDimensionsOfPhotosAndVideos(): void
    {
        $photo = $this->item('a', 'photo.jpg');
        $photo['image'] = ['width' => 4000, 'height' => 3000];
        $video = $this->item('b', 'clip.mov');
        $video['video'] = ['width' => 1920, 'height' => 1080];
        $this->index([$photo, $video, $this->item('c', 'sticker.webp')]);
        $this->assertSame([4000, 3000], [$this->library->photo(1)->width, $this->library->photo(1)->height]);
        $this->assertSame([1920, 1080], [$this->library->photo(2)->width, $this->library->photo(2)->height]);
        $this->assertSame([0, 0], [$this->library->photo(3)->width, $this->library->photo(3)->height]);
        $photo['cTag'] = 'v2';
        $photo['image'] = ['width' => 3000, 'height' => 4000];
        $this->index([$photo]);
        $this->assertSame([3000, 4000], [$this->library->photo(1)->width, $this->library->photo(1)->height]);
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
