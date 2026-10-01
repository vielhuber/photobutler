<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\FaceAnalyzer;
use vielhuber\photobutler\FaceStore;
use vielhuber\photobutler\PhotoButler;

final class FaceStoreTest extends TestCase
{
    use CloudFixture;

    private function photoItems(int $count): array
    {
        return array_map(fn(int $number): array => $this->item((string) $number, $number . '.jpg'), range(1, $count));
    }

    protected function setUp(): void
    {
        $this->createCloudLibrary();
        $this->index($this->photoItems(4));
    }

    protected function tearDown(): void
    {
        $this->removeCloudLibrary();
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
            $face->crop = base64_encode($this->client->jpeg);
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
            3,
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

    public function testTinyFacesAreStoredWithoutCreatingOrJoiningPersons(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $tiny = $this->analysisResult([[1.0]]);
        $tiny->faces[0]->box = [0.1, 0.1, FaceStore::MIN_FACE_WIDTH - 0.001, 0.05];
        $store->save($this->photo(2), $tiny);
        $this->assertSame([1], array_column($store->persons(), 'total'));
        $this->assertSame(
            [null],
            $this->library->database
                ->query('SELECT person_id FROM faces WHERE photo_id = 2')
                ->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame([], $this->library->photo(2)->persons);
    }

    public function testExistingDatabasesGainLaterFaceColumnsOnce(): void
    {
        $database = $this->library->database;
        $database->exec('ALTER TABLE persons DROP COLUMN hidden; ALTER TABLE face_state DROP COLUMN detection;
            DELETE FROM face_migrations WHERE version > 1;');
        new FaceStore($database);
        new FaceStore($database);
        $this->assertSame(
            [1, 2, 3],
            array_map(
                'intval',
                $database->query('SELECT version FROM face_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
            )
        );
        $this->assertContains(
            'hidden',
            array_column($database->query('PRAGMA table_info(persons)')->fetchAll(), 'name')
        );
        $this->assertContains(
            'detection',
            array_column($database->query('PRAGMA table_info(face_state)')->fetchAll(), 'name')
        );
    }

    public function testPersonsAreSortedByPhotoCountThenName(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0], [0.0, 1.0], [0.0, 0.0, 1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[0.0, 1.0]]));
        $ids = $this->library->database
            ->query('SELECT person_id FROM faces WHERE photo_id = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        $store->correct('rename', (int) $ids[1], 'Zora');
        $store->correct('rename', (int) $ids[0], 'Anna');
        $this->assertSame(
            [[(int) $ids[1], 2], [(int) $ids[2], 1], [(int) $ids[0], 1]],
            array_map(
                static fn(array $person): array => [(int) $person['id'], (int) $person['total']],
                $store->persons()
            )
        );
    }

    public function testPhotoFacesExposePositionsWithoutIgnoredOrHiddenPersons(): void
    {
        $store = $this->library->faces;
        $result = $this->analysisResult([[1.0], [0.0, 1.0], [0.0, 0.0, 1.0], [0.0, 0.0, 0.0, 1.0]]);
        $result->faces[3]->box = [0.9, 0.9, 0.02, 0.02];
        $store->save($this->photo(1), $result);
        $ids = array_map(
            'intval',
            $this->library->database->query('SELECT id FROM faces ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        $people = array_map(
            'intval',
            $this->library->database
                ->query('SELECT person_id FROM faces WHERE person_id IS NOT NULL ORDER BY id')
                ->fetchAll(PDO::FETCH_COLUMN)
        );
        $store->correct('rename', $people[0], 'Anna');
        $store->correct('ignore', $ids[1]);
        $store->correct('hide', $people[2]);
        $this->assertSame(
            [
                ['id' => $ids[0], 'box' => [0.1, 0.1, 0.2, 0.2], 'person' => $people[0], 'name' => 'Anna'],
                ['id' => $ids[3], 'box' => [0.9, 0.9, 0.02, 0.02], 'person' => null, 'name' => '']
            ],
            $store->photoFaces(1)
        );
    }

    public function testNamedPersonsWinClearMatchesButNeverAgainstCloserHiddenFaces(): void
    {
        $store = $this->library->faces;
        $database = $this->library->database;
        $insert = $database->prepare("INSERT INTO photos (id, root, path, album, name, modified, bytes, width, height, taken, seen)
            VALUES (?, '/photos', ?, 'Album', 'photo.jpg', 1, 1, 0, 0, '2026-01-01 12:00:00', 'test')");
        foreach (range(30, 36) as $id) {
            $insert->execute([$id, '/photos/' . $id . '.jpg']);
        }
        $person = fn(int $photo): ?int => ($id = $database
            ->query('SELECT person_id FROM faces WHERE photo_id = ' . $photo)
            ->fetchColumn()) === null
            ? null
            : (int) $id;
        $store->save($this->photo(30), $this->analysisResult([[1.0]]));
        $anna = $person(30);
        $store->correct('rename', $anna, 'Anna');
        $database->exec("UPDATE persons SET auto_match = 0 WHERE id = $anna");
        $store->save($this->photo(31), $this->analysisResult([[0.9, sqrt(0.19)]]));
        $this->assertSame($anna, $person(31));
        $store->save($this->photo(32), $this->analysisResult([[0.0, 1.0]]));
        $stranger = $person(32);
        $store->correct('hide', $stranger);
        $store->save($this->photo(33), $this->analysisResult([[0.3, 0.9, sqrt(0.1)]]));
        $this->assertSame($stranger, $person(33));
        $store->save($this->photo(34), $this->analysisResult([[0.42, 0.0, 0.0, sqrt(1 - 0.42 ** 2)]]));
        $this->assertNotContains($person(34), [$anna, $stranger]);
        $database->exec("INSERT INTO persons (name) VALUES ('')");
        $fragment = (int) $database->lastInsertId();
        $database
            ->prepare(
                "INSERT INTO faces (photo_id, person_id, modified, bytes, model, box, embedding, crop) VALUES (35, ?, 1, 1, ?, '[0.1,0.1,0.2,0.2]', ?, '')"
            )
            ->execute([$fragment, FaceStore::MODEL, json_encode(array_pad([0.8, 0.0, 0.0, 0.0, 0.6], 128, 0.0))]);
        $store->save($this->photo(36), $this->analysisResult([[0.85, 0.0, 0.0, 0.0, sqrt(1 - 0.85 ** 2)]]));
        $this->assertSame($anna, $person(36));
    }

    public function testNewDetectionPassOnlyAddsFacesAndKeepsEveryExistingAssignment(): void
    {
        $store = $this->library->faces;
        $database = $this->library->database;
        $vectors = [[1.0], [0.0, 1.0], [0.0, 0.0, 1.0]];
        $store->save($this->photo(1), $this->analysisResult($vectors));
        $ids = array_map('intval', $database->query('SELECT id FROM faces ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $people = fn(): array => $database
            ->query('SELECT person_id FROM faces ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        $store->correct('rename', (int) $people()[0], 'Anna');
        $store->correct('ignore', $ids[1]);
        $store->correct('hide', (int) $people()[2]);
        $current = $this->library->jobs->all()['faces'];
        $database->exec('UPDATE face_state SET detection = 1');
        $before = $database->query('SELECT * FROM faces ORDER BY id')->fetchAll();
        $this->assertSame($current['queued'] + 1, $this->library->jobs->all()['faces']['queued']);
        $this->assertSame($current['completed'] - 1, $this->library->jobs->all()['faces']['completed']);
        $this->assertTrue($store->save($this->photo(1), $this->analysisResult([...$vectors, [0.0, 0.0, 0.0, 1.0]])));
        $after = $database->query('SELECT * FROM faces ORDER BY id')->fetchAll();
        $this->assertSame($before, array_slice($after, 0, 3));
        $this->assertCount(4, $after);
        $this->assertEqualsWithDelta([1.0, 0.1, 0.2, 0.2], json_decode($after[3]['box'], true), 1e-9);
        $this->assertSame(
            FaceStore::DETECTION,
            (int) $database->query('SELECT detection FROM face_state')->fetchColumn()
        );
        $this->assertSame($current['queued'], $this->library->jobs->all()['faces']['queued']);
        $this->assertSame($current['completed'], $this->library->jobs->all()['faces']['completed']);
        $this->assertFalse($store->save($this->photo(1), $this->analysisResult([...$vectors, [0.0, 0.0, 0.0, 1.0]])));
        $store->reset(1, false);
        $this->assertTrue($store->save($this->photo(1), $this->analysisResult([[0.0, 0.0, 0.0, 0.0, 1.0]])));
        $this->assertSame($after, $database->query('SELECT * FROM faces ORDER BY id')->fetchAll());
    }

    public function testCoverIsTheNewestClearlyVisibleFace(): void
    {
        $store = $this->library->faces;
        $database = $this->library->database;
        $insert = $database->prepare("INSERT INTO photos (id, root, path, album, name, modified, bytes, width, height, taken, seen)
            VALUES (?, '/photos', ?, 'Album', 'photo.jpg', 1, 1, 0, 0, ?, 'test')");
        foreach ([20 => '2025-01-01', 21 => '2025-06-01', 22 => '2026-01-01', 23 => '2026-01-01'] as $id => $taken) {
            $insert->execute([$id, '/photos/' . $id . '.jpg', $taken . ' 12:00:00']);
        }
        $face = function (int $photo, float $width) use ($store): int {
            $result = $this->analysisResult([[1.0]]);
            $result->faces[0]->box = [0.1, 0.1, $width, $width];
            $store->save($this->photo($photo), $result);
            return (int) $this->library->database->query('SELECT MAX(id) FROM faces')->fetchColumn();
        };
        $old = $face(20, 0.5);
        $this->assertSame($old, (int) $store->persons()[0]['cover']);
        $clear = $face(21, 0.2);
        $this->assertSame($clear, (int) $store->persons()[0]['cover']);
        $face(22, 0.05);
        $this->assertSame($clear, (int) $store->persons()[0]['cover']);
        $larger = $face(23, 0.3);
        $this->assertSame($larger, (int) $store->persons()[0]['cover']);
        $database->exec("UPDATE faces SET ignored = 1 WHERE id = $larger");
        $this->assertSame($clear, (int) $store->persons()[0]['cover']);
    }

    public function testHiddenPersonsAreNotListedOrShownButKeepCollectingTheirFaces(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $person = (int) $this->library->database->query('SELECT person_id FROM faces')->fetchColumn();
        $store->correct('rename', $person, 'Nachbar');
        $listed = fn(): array => array_column(
            array_filter($store->persons(), static fn(array $item): bool => (bool) $item['listed']),
            'id'
        );
        $this->assertSame([$person], $listed());
        $this->assertSame($person, $store->correct('hide', $person));
        $this->assertSame([], $listed());
        $this->assertSame(1, (int) $store->persons()[0]['hidden']);
        $this->assertSame([], $this->library->photo(1)->persons);
        $store->save($this->photo(2), $this->analysisResult([[1.0]]));
        $this->assertSame(
            [$person],
            array_map(
                'intval',
                array_unique(
                    $this->library->database->query('SELECT person_id FROM faces')->fetchAll(PDO::FETCH_COLUMN)
                )
            )
        );
        $store->correct('show', $person);
        $this->assertSame([$person], $listed());
        $this->assertSame([$person], array_column($this->library->photo(1)->persons, 'id'));
    }

    public function testOnlyRepeatedlySeenOrCuratedGroupsAreListed(): void
    {
        $store = $this->library->faces;
        $database = $this->library->database;
        $insert = $database->prepare("INSERT INTO photos (id, root, path, album, name, modified, bytes, width, height, taken, seen)
            VALUES (?, '/photos', ?, 'Album', 'photo.jpg', 1, 1, 0, 0, ?, 'test')");
        foreach ([10 => '2025-01-01', 11 => '2025-01-01', 12 => '2025-01-01', 13 => '2025-02-01'] as $id => $taken) {
            $insert->execute([$id, '/photos/' . $id . '.jpg', $taken . ' 12:00:00']);
        }
        foreach ([10, 11, 12] as $id) {
            $store->save($this->photo($id), $this->analysisResult([[1.0]]));
        }
        $listed = fn(): array => array_column(
            array_filter($store->persons(), static fn(array $person): bool => (bool) $person['listed']),
            'total'
        );
        $this->assertSame([], $listed());
        $store->save($this->photo(13), $this->analysisResult([[1.0]]));
        $this->assertSame([4], $listed());
        $store->save($this->photo(1), $this->analysisResult([[0.0, 1.0]]));
        $this->assertSame([4], $listed());
        $store->correct(
            'rename',
            (int) $database->query('SELECT person_id FROM faces WHERE photo_id = 1')->fetchColumn(),
            'Gast'
        );
        $this->assertSame([4, 1], $listed());
    }

    public function testOpenCvRecommendedThresholdJoinsModerateButNotWeakSimilarity(): void
    {
        $store = $this->library->faces;
        $store->save($this->photo(1), $this->analysisResult([[1.0]]));
        $store->save($this->photo(2), $this->analysisResult([[0.4, sqrt(1 - 0.4 ** 2)]]));
        $this->assertCount(1, $store->persons());
        $store->save($this->photo(3), $this->analysisResult([[0.0, 0.0, 1.0]]));
        $store->save($this->photo(4), $this->analysisResult([[0.0, 0.0, 0.3, sqrt(1 - 0.3 ** 2)]]));
        $this->assertSame([2, 1, 1], array_column($store->persons(), 'total'));
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
        $query->execute([json_encode(array_pad([0.0, 1.0], 128, 0.0)), 3]);
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
        $store->save($photo, $this->analysisResult([[1.0], [0.0, 1.0]]));
        $store->correct('rename', 1, 'Alex');
        $store->correct('split', 2);
        $store->correct('ignore', 1);
        $this->library->database->exec(
            "UPDATE photos SET description = 'Begründung', status = 'done', attempted = 123, priority = 1;
            UPDATE photos SET priority = -1, ai_priority = -1 WHERE id = 2"
        );
        $this->assertSame(4, $this->library->resetAnalysis());
        foreach (['faces', 'face_state', 'persons', 'person_separations'] as $table) {
            $this->assertSame(
                0,
                (int) $this->library->database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
            );
        }
        $reset = $this->photo(1);
        $this->assertSame('', $reset['description']);
        $this->assertSame('pending', $reset['status']);
        $this->assertSame(0, $reset['attempted']);
        $this->assertSame(1, $reset['priority']);
        $this->assertSame(0, $this->photo(2)['priority']);
        $this->assertNull($this->photo(2)['ai_priority']);
        $this->assertSame([], $this->client->downloads);
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
        $this->library->database->exec('UPDATE photos SET modified = modified + 5 WHERE id = 1');
        $store->reset(1, false);
        $this->assertFalse($store->save($photo, $this->analysisResult([[1.0]])));
        $this->index($this->photoItems(4));
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
        $this->assertCount(1, $this->library->photos(query: '1', album: 'FOTOS', favorites: true, person: 1));
        $this->assertCount(0, $this->library->photos(album: 'Andere', person: 1));
        $this->assertCount(0, $this->library->photos(person: 999));
        $this->index($this->photoItems(70));
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

    public function testDeletionCannotBeUndoneByInFlightResultsAndRetryKeepsTheRating(): void
    {
        $store = $this->library->faces;
        $this->library->database->exec("UPDATE photos SET status = 'done', priority = 1, ai_priority = 1");
        $photo = $this->photo(1);
        $store->save($photo, $this->analysisResult([[1.0]]));
        $store->reset(1, true);
        $this->assertFalse($store->save($photo, $this->analysisResult([[1.0]])));
        $this->assertNull($store->crop(1));
        $store->reset(1, false);
        $this->assertTrue($store->save($photo, $this->analysisResult(status: 'error')));
        $this->assertSame('done', $this->library->photo(1)->status);
        $this->assertSame(1, $this->library->photo(1)->priority);
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
        $this->library->database->exec("UPDATE photos SET status = 'done', description = 'Unverändert'");
        $this->library->oneDrive->previews(range(1, 4));
        foreach (range(1, 4) as $id) {
            $this->assertNotNull($this->library->imagePath($id, cachedOnly: true));
        }
        imagepng(imagecreatetruecolor(80, 60), $this->library->imagePath(1, cachedOnly: true));
        $this->assertSame(4, $this->library->tagFaces(4));
        $this->assertSame(0, $this->library->tagFaces(4));
        $this->assertSame(
            4,
            (int) $this->library->database
                ->query("SELECT COUNT(*) FROM face_state WHERE status = 'done'")
                ->fetchColumn()
        );
        $this->assertSame('Unverändert', $this->library->photo(1)->description);
        $this->assertSame(['1', '2', '3', '4'], $this->client->downloads);
        $this->index($this->photoItems(5));
        $this->library->database->exec("UPDATE photos SET status = 'done'");
        $this->assertSame(0, $this->library->tagFaces(1));
        $this->assertSame('error', $this->library->photo(5)->face_status);
        $this->assertSame(0, $this->library->tagFaces(1));
        $this->library->database->exec("UPDATE photos SET status = 'pending' WHERE id = 1");
        $this->assertSame(0, $this->library->tag(1));
        $this->assertSame(
            'done',
            $this->library->database->query('SELECT status FROM face_state WHERE photo_id = 1')->fetchColumn()
        );
    }
}
