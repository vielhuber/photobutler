<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\FaceAnalyzer;
use vielhuber\photobutler\FaceStore;
use vielhuber\photobutler\PhotoButler;

final class FaceStoreTest extends TestCase
{
    private string $root;
    private PhotoButler $library;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-faces-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/photos', 0700, true);
        mkdir($this->root . '/.data', 0700);
        file_put_contents(
            $this->root . '/.data/.env',
            "PHOTO_PATHS='" . json_encode([$this->root . '/photos']) . "'\n"
        );
        for ($index = 1; $index <= 4; $index++) {
            imagejpeg(imagecreatetruecolor(80, 60), $this->root . '/photos/' . $index . '.jpg');
            file_put_contents($this->root . '/photos/' . $index . '.jpg', (string) $index, FILE_APPEND);
        }
        $this->library = new PhotoButler($this->root);
        $this->library->index();
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
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    private function photo(int $id): array
    {
        return $this->library->database->query('SELECT * FROM photos WHERE id = ' . $id)->fetch();
    }

    private function analysisResult(array $vectors = [], string $status = 'done'): stdClass
    {
        $result = new stdClass();
        $result->status = $status;
        $result->faces = [];
        foreach ($vectors as $index => $vector) {
            $face = new stdClass();
            $face->box = [0.1 + $index * 0.3, 0.1, 0.2, 0.2];
            $face->embedding = array_pad($vector, 128, 0.0);
            $face->crop = base64_encode(file_get_contents($this->root . '/photos/1.jpg'));
            $result->faces[] = $face;
        }
        return $result;
    }

    public function testMigrationIdempotenceGroupsMultiplePeopleAndNoFaceCompletion(): void
    {
        $manifest = json_decode(
            file_get_contents(dirname(__DIR__) . '/scripts/face-models.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame(FaceStore::MODEL, $manifest['version']);
        new FaceStore($this->library->database);
        $this->assertSame(
            1,
            (int) $this->library->database->query('SELECT COUNT(*) FROM face_migrations')->fetchColumn()
        );
        $this->assertTrue($this->library->faces->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0]])));
        $this->assertTrue($this->library->faces->save($this->photo(2), $this->analysisResult([[1.0], [0.0, 1.0]])));
        $persons = $this->library->faces->persons();
        $this->assertCount(2, $persons);
        $this->assertSame([2, 2], array_column($persons, 'total'));
        $this->assertFalse($this->library->faces->save($this->photo(2), $this->analysisResult([[1.0]])));
        $this->assertTrue($this->library->faces->save($this->photo(3), $this->analysisResult()));
        $this->assertFalse($this->library->faces->save($this->photo(3), $this->analysisResult()));
        $this->assertSame([], $this->library->photo(3)->persons);
        $this->library->database->exec('UPDATE photos SET available = 0 WHERE id = 2');
        $this->assertSame([1, 1], array_column($this->library->faces->persons(), 'total'));
        $this->assertNull($this->library->faces->crop(3));
    }

    public function testAmbiguousAndDifferentFacesDoNotChainGroups(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[sqrt(0.5), sqrt(0.5)]]));
        $store->save($this->photo(3), $this->analysisResult([[0.0, 0.0, 1.0]]));
        $this->assertCount(4, $store->persons());
        $this->assertSame([1, 1, 1, 1], array_column($store->persons(), 'total'));
    }

    public function testPoseVariationUsesTheWholeGroupInsteadOfItsWorstRepresentative(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[0.51, sqrt(1 - 0.51 ** 2)]]));
        $store->save($this->photo(3), $this->analysisResult([[0.35, sqrt(1 - 0.35 ** 2)]]));
        $this->assertCount(1, $store->persons());
        $this->assertSame(3, $store->persons()[0]['total']);
    }

    public function testRegroupRepairsExistingFragmentsWithoutChangingFaceData(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[0.0, 1.0]]));
        $this->library->database
            ->prepare('UPDATE faces SET embedding = ? WHERE id = 2')
            ->execute([json_encode(array_pad([0.51, sqrt(1 - 0.51 ** 2)], 128, 0.0))]);
        $before = $this->library->database
            ->query('SELECT id, photo_id, embedding, crop, origin FROM faces ORDER BY id')
            ->fetchAll();
        $store->correct('rename', 2, 'Alex');
        $before[1]['origin'] = 'manual';
        $this->assertSame(1, $store->regroup());
        $this->assertSame(0, $store->regroup());
        $this->assertSame(
            [2, 2],
            array_column(
                $this->library->database->query('SELECT person_id FROM faces ORDER BY id')->fetchAll(),
                'person_id'
            )
        );
        $this->assertSame('Alex', $store->persons()[0]['name']);
        $this->assertSame(
            $before,
            $this->library->database
                ->query('SELECT id, photo_id, embedding, crop, origin FROM faces ORDER BY id')
                ->fetchAll()
        );
    }

    public function testRepeatedPortraitsOnOneAlbumPageShareAPerson(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0], [0.9, sqrt(0.19)]]));
        $this->assertCount(2, $store->persons());
        $this->assertSame(
            [1, 2, 1],
            array_column(
                $this->library->database->query('SELECT person_id FROM faces ORDER BY id')->fetchAll(),
                'person_id'
            )
        );
        $this->assertSame([1, 1], array_column($store->persons(), 'total'));
        $store->save($this->photo(2), $this->analysisResult([[1.0], [1.0]]));
        $this->assertSame(2, $store->persons()[0]['total']);
        $this->assertCount(2, $this->library->photos(person: 1));
        $store->correct('split', 3);
        $this->assertSame(0, $store->regroup());
        $this->assertCount(3, $store->persons());
    }

    public function testRegroupKeepsAmbiguousPeopleSeparateButRepairsAlbumPageFragments(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[sqrt(0.5), sqrt(0.5)]]));
        $this->assertSame(0, $store->regroup());
        $this->assertCount(3, $store->persons());
        $this->library->database->exec('UPDATE faces SET embedding = (SELECT embedding FROM faces WHERE id = 1)');
        $this->assertSame(2, $store->regroup());
        $this->assertCount(1, $store->persons());
        $this->assertSame(2, $store->persons()[0]['total']);
    }

    public function testRegroupDoesNotBridgeIncompatibleExtremesOrUndoManualDecisions(): void
    {
        $store = $this->library->faces;
        foreach ([1, 2, 3] as $id) {
            $vector = array_fill(0, 3, 0.0);
            $vector[$id - 1] = 1.0;
            $store->save($this->photo($id), $this->analysisResult([$vector]));
        }
        $query = $this->library->database->prepare('UPDATE faces SET embedding = ? WHERE id = ?');
        $query->execute([json_encode(array_pad([0.8, 0.6], 128, 0.0)), 2]);
        $query->execute([json_encode(array_pad([0.2, sqrt(0.96)], 128, 0.0)), 3]);
        $this->assertSame(1, $store->regroup());
        $this->assertCount(2, $store->persons());
        $store->correct('split', 2);
        $this->assertSame(0, $store->regroup());
        $store->correct('rename', 1, 'Alex');
        $store->correct('rename', 3, 'Alex');
        $this->assertSame(0, $store->regroup());
    }

    public function testRegroupDoesNotCombineTwoNamedGroupsEvenWithIdenticalEmbeddings(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[0.0, 1.0]]));
        $store->correct('rename', 1, 'Alex');
        $store->correct('rename', 2, 'Alex');
        $this->library->database->exec('UPDATE faces SET embedding = (SELECT embedding FROM faces WHERE id = 1)');
        $this->assertSame(0, $store->regroup());
        $this->assertCount(2, $store->persons());
    }

    public function testRegroupExcludesIgnoredUnavailableAndObsoleteFaces(): void
    {
        $store = $this->library->faces;
        foreach ([1, 2, 3, 4] as $id) {
            $vector = array_fill(0, 4, 0.0);
            $vector[$id - 1] = 1.0;
            $store->save($this->photo($id), $this->analysisResult([$vector]));
        }
        $this->library->database->exec("UPDATE faces SET embedding = (SELECT embedding FROM faces WHERE id = 1);
            UPDATE faces SET ignored = 1 WHERE id = 2;
            UPDATE faces SET model = 'obsolete-model' WHERE id = 3;
            UPDATE photos SET available = 0 WHERE id = 4");
        $this->assertSame(0, $store->regroup());
        $this->assertCount(4, $store->persons());
        $this->library->database->exec('UPDATE faces SET ignored = 0; UPDATE photos SET available = 1');
        $this->library->database->prepare('UPDATE faces SET model = ?')->execute([FaceStore::MODEL]);
        $this->assertSame(3, $store->regroup());
        $this->assertSame(4, $store->persons()[0]['total']);
    }

    public function testGlobalResetClearsAnalysisAndKeepsOriginalsAndUserMetadata(): void
    {
        $store = $this->library->faces;
        $photo = $this->photo(1);
        $hash = hash_file('sha256', $photo['path']);
        $store->save($photo, $this->analysisResult([[1.0], [0.0, 1.0]]));
        $store->correct('rename', 1, 'Alex');
        $store->correct('split', 2);
        $store->correct('ignore', 1);
        $this->library->database->exec(
            "UPDATE photos SET ai_tags = '[" .
                '"KI"' .
                "]', description = 'Beschreibung',
            status = 'done', attempted = 123, manual_tags = '[" .
                '"Manuell"' .
                "]', priority = 1"
        );
        $this->assertSame(4, $this->library->resetAnalysis());
        foreach (['faces', 'face_state', 'persons', 'person_separations'] as $table) {
            $this->assertSame(
                0,
                (int) $this->library->database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
            );
        }
        $reset = $this->photo(1);
        $this->assertSame('[]', $reset['ai_tags']);
        $this->assertSame('', $reset['description']);
        $this->assertSame('pending', $reset['status']);
        $this->assertSame(0, $reset['attempted']);
        $this->assertSame('["Manuell"]', $reset['manual_tags']);
        $this->assertSame(1, $reset['priority']);
        $this->assertSame($hash, hash_file('sha256', $photo['path']));
        $this->assertSame(4, $this->library->resetAnalysis());
    }

    public function testGlobalResetRefusesAnActiveTagWorkerAndRollsBackDatabaseFailures(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $lock = fopen($this->root . '/.data/tag.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->library->resetAnalysis();
            $this->fail('An active worker must prevent resetting.');
        } catch (RuntimeException $exception) {
            $this->assertSame(409, $exception->getCode());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->library->database->exec(
            "CREATE TRIGGER refuse_reset BEFORE DELETE ON faces BEGIN SELECT RAISE(ABORT, 'blocked'); END"
        );
        try {
            $this->library->resetAnalysis();
            $this->fail('The failing transaction must roll back.');
        } catch (PDOException) {
            $this->assertSame('done', $this->photo(1)['status']);
            $this->assertCount(1, $store->persons());
        }
        $this->library->database->exec('DROP TRIGGER refuse_reset');
        $this->assertSame(4, $this->library->resetAnalysis());
    }

    public function testManualNamesMergesSplitsMovesAndIgnoredFacesSurviveRetry(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0]]));
        $store->correct('rename', 1, 'Alex');
        $store->correct('rename', 2, 'Alex');
        $this->assertCount(2, $store->persons());
        $store->correct('merge', 2, target: 1);
        $this->assertCount(1, $store->persons());
        $this->assertSame(1, $store->persons()[0]['total']);
        $store->correct('split', 2);
        $split = (int) $this->library->database->query('SELECT person_id FROM faces WHERE id = 2')->fetchColumn();
        $this->assertNotSame(1, $split);
        $this->assertSame(
            1,
            (int) $this->library->database->query('SELECT COUNT(*) FROM person_separations')->fetchColumn()
        );
        $store->correct('ignore', 1);
        $store->reset(1, false);
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0]]));
        $this->assertSame(
            1,
            (int) $this->library->database->query('SELECT ignored FROM faces WHERE id = 1')->fetchColumn()
        );
        $this->assertSame(
            $split,
            (int) $this->library->database->query('SELECT person_id FROM faces WHERE id = 2')->fetchColumn()
        );
        $store->save($this->photo(2), $this->analysisResult([[0.0, 1.0]]));
        $new = $this->library->photo(2)->persons[0]['id'];
        $this->assertNotSame($split, $new);
        $store->correct('move', 3, target: $split);
        $this->assertSame($split, $this->library->photo(2)->persons[0]['id']);
    }

    public function testChangedFilesStaleResultsModelVersionsAndArchivedCorrections(): void
    {
        $store = $this->library->faces;
        $photo = $this->photo(1);
        $store->save($photo, $this->analysisResult([[1.0]]));
        $store->correct('ignore', 1);
        $this->library->database->exec("UPDATE face_state SET model = 'old'; UPDATE faces SET model = 'old'");
        $store->save($photo, $this->analysisResult([[1.0]]));
        $this->assertSame(
            1,
            (int) $this->library->database->query('SELECT ignored FROM faces WHERE id = 1')->fetchColumn()
        );
        touch($photo['path'], $photo['modified'] + 5);
        clearstatcache();
        $store->reset(1, false);
        $this->assertFalse($store->save($photo, $this->analysisResult([[1.0]])));
        $this->library->index();
        $this->assertTrue($store->save($this->photo(1), $this->analysisResult([[0.0, 1.0]])));
        $this->assertSame(0, $store->personFaces(1)[0]['current']);
        $this->assertSame('manual', $store->personFaces(1)[0]['origin']);
        $this->assertSame(1, $store->personFaces(1)[0]['ignored']);
        $this->assertCount(1, $this->library->photo(1)->persons);
    }

    public function testFiltersCountDistinctPhotosBeforeSortingAndPagination(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [1.0]]));
        $this->assertCount(1, $store->persons());
        $this->library->favorite(1, true);
        $this->library->saveTags(1, 'Test');
        $this->assertCount(
            1,
            $this->library->photos(query: '1', album: 'photos', tag: 'Test', favorites: true, person: 1)
        );
        $this->assertCount(0, $this->library->photos(tag: 'Andere', person: 1));
        $this->assertCount(0, $this->library->photos(person: 999));
        for ($number = 5; $number <= 70; $number++) {
            $path = $this->root . '/photos/' . $number . '.jpg';
            copy($this->root . '/photos/1.jpg', $path);
            file_put_contents($path, (string) $number, FILE_APPEND);
        }
        $this->library->index();
        foreach ($this->library->database->query('SELECT * FROM photos WHERE id >= 5')->fetchAll() as $photo) {
            $store->save($photo, $this->analysisResult([[1.0]]));
        }
        $oldest = $this->library->photos(person: 1, sort: 'oldest');
        $next = $this->library->photos(person: 1, sort: 'oldest', page: 2);
        $newest = $this->library->photos(person: 1);
        $this->assertCount(60, $oldest);
        $this->assertCount(7, $next);
        $this->assertSame($newest[0]->id, $next[6]->id);
        $this->assertSame([], array_intersect(array_column($oldest, 'id'), array_column($next, 'id')));
    }

    public function testDeletionCannotBeUndoneByInFlightResultsAndRetryKeepsTags(): void
    {
        $store = $this->library->faces;
        $this->library->database->exec("UPDATE photos SET status = 'done', ai_tags = '[\"Test\"]'");
        $photo = $this->photo(1);
        $store->save($photo, $this->analysisResult([[1.0]]));
        $store->reset(1, true);
        $this->assertFalse($store->save($photo, $this->analysisResult([[1.0]])));
        $this->assertNull($store->crop(1));
        $store->reset(1, false);
        $this->assertTrue($store->save($photo, $this->analysisResult(status: 'error')));
        $this->assertSame('done', $this->library->photo(1)->status);
        $this->assertSame(['Test'], $this->library->photo(1)->tags);
        $store->reset(1, false);
        $this->assertTrue($store->save($photo, $this->analysisResult()));
        $this->assertSame(
            'done',
            $this->library->database->query('SELECT status FROM face_state WHERE photo_id = 1')->fetchColumn()
        );
    }

    public function testSplitProtectsBothGroupsAndMissingFilesBackOff(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[1.0]]));
        $store->correct('split', 2);
        $store->reset(1, false);
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $this->assertSame(1, $this->library->photo(1)->persons[0]['id']);
        $this->assertSame(2, $this->library->photo(2)->persons[0]['id']);
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $photo = $this->photo(3);
        unlink($photo['path']);
        $this->assertSame(0, $this->library->tagFaces(1));
        $state = $this->library->database->query('SELECT * FROM face_state WHERE photo_id = 3')->fetch();
        $this->assertSame('error', $state['status']);
        $this->assertGreaterThan(time() - 10, $state['attempted']);
        $stats = new ReflectionMethod(PhotoButler::class, 'photoStats')->invoke($this->library);
        $this->assertSame(1, $stats['queued']);
    }

    public function testInvalidCorrectionRollsBackAndWorkersCannotOverlap(): void
    {
        $this->library->faces->save($this->photo(1), $this->analysisResult([[1.0]]));
        try {
            $this->library->faces->correct('merge', 1, target: 999);
            $this->fail('Missing targets must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertCount(1, $this->library->faces->persons());
            $this->assertFalse($this->library->database->inTransaction());
        }
        $lock = fopen($this->root . '/.data/tag.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->library->tag(1);
            $this->fail('A second worker must not process the same queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame(409, $exception->getCode());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testLocalCpuBackfillRequiresNoAiConfigurationAndCompletesBlankImages(): void
    {
        $runtime = getenv('PHOTOBUTLER_TEST_FACE_RUNTIME');
        if (!$runtime) {
            $this->markTestSkipped('Set PHOTOBUTLER_TEST_FACE_RUNTIME to an installed CPU runtime for real inference.');
        }
        symlink($runtime, $this->root . '/.data/face-runtime');
        $this->library->database->exec("UPDATE photos SET status = 'done', ai_tags = '[\"Unverändert\"]'");
        $hash = hash_file('sha256', $this->photo(1)['path']);
        $this->assertSame(4, $this->library->tagFaces(4));
        $this->assertSame(0, $this->library->tagFaces(4));
        $this->assertSame(
            4,
            (int) $this->library->database
                ->query("SELECT COUNT(*) FROM face_state WHERE status = 'done'")
                ->fetchColumn()
        );
        $this->assertSame(['Unverändert'], $this->library->photo(1)->tags);
        $this->assertSame($hash, hash_file('sha256', $this->photo(1)['path']));
        $archive = new ZipArchive();
        $source = $this->root . '/photos/unsupported.webp';
        $archive->open($source, ZipArchive::CREATE);
        $archive->addFromString('unrelated.txt', 'unsupported sticker');
        $archive->close();
        $this->library->index();
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $this->assertSame(1, $this->library->tagFaces(1));
        $this->assertSame('unsupported', $this->library->photo(5)->face_status);
        $this->assertSame(0, $this->library->tagFaces(1));
        $this->library->database->exec("UPDATE photos SET status = 'pending' WHERE id = 1");
        $this->assertSame(0, $this->library->tag(1));
        $this->assertSame(
            'done',
            $this->library->database->query('SELECT status FROM face_state WHERE photo_id = 1')->fetchColumn()
        );
    }
}
