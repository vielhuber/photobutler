<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class FaceStore
{
    public const MODEL = 'yunet-2023mar-sface-2021dec-opencv-4.13.0-v1';
    public const MATCH_THRESHOLD = 0.5;
    public const MATCH_MARGIN = 0.04;
    public const MATCH_FLOOR = 0.3;
    public const CURRENT_FACE = 'f.active = 1 AND p.available = 1 AND f.modified = p.modified AND f.bytes = p.bytes';
    public const DUE = "(s.status IS NULL OR (s.status <> 'excluded' AND
        (s.modified <> p.modified OR s.bytes <> p.bytes OR s.model <> :model OR
        s.status = 'pending' OR (s.status = 'error' AND s.attempted < :retry))))";

    /**
     * Version the biometric schema independently of the existing photo index.
     */
    public function __construct(private readonly \PDO $database)
    {
        $database->exec('PRAGMA secure_delete = ON');
        $database->exec('CREATE TABLE IF NOT EXISTS face_migrations (version INTEGER PRIMARY KEY)');
        if ((int) $database->query('SELECT COALESCE(MAX(version), 0) FROM face_migrations')->fetchColumn() >= 1) {
            return;
        }
        $database->exec('BEGIN IMMEDIATE');
        try {
            $database->exec("CREATE TABLE IF NOT EXISTS persons (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', title_face INTEGER,
                auto_match INTEGER NOT NULL DEFAULT 1);
                CREATE TABLE IF NOT EXISTS face_state (
                photo_id INTEGER PRIMARY KEY, modified INTEGER NOT NULL, bytes INTEGER NOT NULL,
                model TEXT NOT NULL, status TEXT NOT NULL, attempted INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE IF NOT EXISTS faces (
                id INTEGER PRIMARY KEY AUTOINCREMENT, photo_id INTEGER NOT NULL, person_id INTEGER,
                modified INTEGER NOT NULL, bytes INTEGER NOT NULL, model TEXT NOT NULL,
                box TEXT NOT NULL, embedding TEXT NOT NULL, crop BLOB NOT NULL,
                origin TEXT NOT NULL DEFAULT 'auto', ignored INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1);
                CREATE INDEX IF NOT EXISTS faces_person ON faces(person_id, active, ignored);
                CREATE INDEX IF NOT EXISTS faces_photo ON faces(photo_id, active);
                CREATE TABLE IF NOT EXISTS person_separations (person_a INTEGER NOT NULL, person_b INTEGER NOT NULL,
                PRIMARY KEY(person_a, person_b));
                INSERT OR IGNORE INTO face_migrations VALUES (1);");
            $database->commit();
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
        }
    }

    /**
     * Count only current available photos, retaining manually named and archived groups.
     */
    public function persons(): array
    {
        return $this->database
            ->query(
                'SELECT persons.id, persons.name, COUNT(DISTINCT p.id) AS total,
            COALESCE(MAX(CASE WHEN f.id = persons.title_face AND p.id IS NOT NULL THEN f.id END),
            MIN(CASE WHEN p.id IS NOT NULL THEN f.id END)) AS cover
            FROM persons LEFT JOIN faces f ON f.person_id = persons.id AND f.ignored = 0 AND f.active = 1
            LEFT JOIN photos p ON p.id = f.photo_id AND p.available = 1 AND p.modified = f.modified AND p.bytes = f.bytes
            GROUP BY persons.id ORDER BY unicode_lower(persons.name), persons.id'
            )
            ->fetchAll();
    }

    /**
     * Never expose embeddings, paths or image payloads through gallery metadata.
     */
    public function photoPersons(int $id): array
    {
        $query = $this->database->prepare(
            'SELECT persons.id, persons.name, MIN(f.id) AS cover
            FROM faces f JOIN photos p ON p.id = f.photo_id JOIN persons ON persons.id = f.person_id
            WHERE f.photo_id = ? AND f.ignored = 0 AND ' .
                self::CURRENT_FACE .
                ' GROUP BY persons.id'
        );
        $query->execute([$id]);
        return $query->fetchAll();
    }

    /**
     * Keep outdated manual decisions visible for review without counting stale photos.
     */
    public function personFaces(int $id): array
    {
        $query = $this->database->prepare(
            'SELECT f.id, f.photo_id, f.ignored, f.origin,
            (' .
                self::CURRENT_FACE .
                ') AS current FROM faces f JOIN photos p ON p.id = f.photo_id
            WHERE f.person_id = ? ORDER BY current DESC, f.id'
        );
        $query->execute([$id]);
        return $query->fetchAll();
    }

    /**
     * Serve stored crops only while the source is available and inside configured storage.
     */
    public function crop(int $id): ?array
    {
        $query = $this->database->prepare('SELECT f.crop, f.photo_id FROM faces f JOIN photos p ON p.id = f.photo_id
            WHERE f.id = ? AND p.available = 1');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    /**
     * Store a completed attempt only if both disk and index still match its input version.
     */
    public function save(array $photo, \stdClass $result): bool
    {
        if (
            !in_array($result->status ?? '', ['done', 'unsupported', 'error'], true) ||
            !is_array($result->faces ?? null)
        ) {
            throw new \UnexpectedValueException('Ungültige Gesichtsanalyse.');
        }
        foreach ($result->faces as $face) {
            if (
                !is_array($face->embedding ?? null) ||
                count($face->embedding) !== 128 ||
                !is_array($face->box ?? null) ||
                count($face->box) !== 4 ||
                !is_string($face->crop ?? null)
            ) {
                throw new \UnexpectedValueException('Ungültige Gesichtsanalyse.');
            }
            foreach (array_merge($face->embedding, $face->box) as $value) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \UnexpectedValueException('Ungültige Gesichtsanalyse.');
                }
            }
            $crop = base64_decode($face->crop, true);
            if ($crop === false || strlen($crop) > 100000 || !str_starts_with($crop, "\xff\xd8")) {
                throw new \UnexpectedValueException('Ungültiger Gesichtsausschnitt.');
            }
        }
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->database->prepare('SELECT p.*, s.status AS face_status, s.model AS face_model,
                s.modified AS face_modified, s.bytes AS face_bytes FROM photos p LEFT JOIN face_state s ON s.photo_id = p.id WHERE p.id = ?');
            $query->execute([$photo['id']]);
            $current = $query->fetch();
            clearstatcache(true, $photo['path']);
            if (
                !$current ||
                !$current['available'] ||
                $current['modified'] !== $photo['modified'] ||
                $current['bytes'] !== $photo['bytes'] ||
                $current['face_status'] === 'excluded' ||
                (in_array($current['face_status'], ['done', 'unsupported'], true) &&
                    $current['face_model'] === self::MODEL &&
                    $current['face_modified'] === $photo['modified'] &&
                    $current['face_bytes'] === $photo['bytes'])
            ) {
                return false;
            }
            $unchanged =
                is_file($photo['path']) &&
                filemtime($photo['path']) === $photo['modified'] &&
                filesize($photo['path']) === $photo['bytes'];
            if (!$unchanged) {
                $result = new \stdClass();
                $result->status = 'error';
                $result->faces = [];
            }
            if ($result->status !== 'error') {
                $old = $this->database->prepare("SELECT * FROM faces WHERE photo_id = ? AND origin = 'manual'");
                $old->execute([$photo['id']]);
                $manual = $old->fetchAll();
                $this->database
                    ->prepare("DELETE FROM faces WHERE photo_id = ? AND origin = 'auto'")
                    ->execute([$photo['id']]);
                $this->database->prepare('UPDATE faces SET active = 0 WHERE photo_id = ?')->execute([$photo['id']]);
                foreach ($result->faces as $face) {
                    $matched = null;
                    foreach ($manual as $key => $previous) {
                        if ($previous['modified'] !== $photo['modified'] || $previous['bytes'] !== $photo['bytes']) {
                            continue;
                        }
                        $box = json_decode($previous['box'], true, flags: JSON_THROW_ON_ERROR);
                        $distance = array_sum(array_map(fn($a, $b) => abs($a - $b), $box, $face->box));
                        if ($distance < 0.02) {
                            $matched = $previous;
                            unset($manual[$key]);
                            break;
                        }
                    }
                    if ($matched !== null) {
                        $this->database
                            ->prepare(
                                'UPDATE faces SET active = 1, model = ?, box = ?, embedding = ?, crop = ? WHERE id = ?'
                            )
                            ->execute([
                                self::MODEL,
                                json_encode($face->box),
                                json_encode($face->embedding),
                                base64_decode($face->crop),
                                $matched['id']
                            ]);
                        continue;
                    }
                    $person = $this->match($face->embedding);
                    if ($person === null) {
                        $this->database->exec('INSERT INTO persons DEFAULT VALUES');
                        $person = (int) $this->database->lastInsertId();
                    }
                    $this->database
                        ->prepare(
                            'INSERT INTO faces (photo_id, person_id, modified, bytes, model, box, embedding, crop) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        )
                        ->execute([
                            $photo['id'],
                            $person,
                            $photo['modified'],
                            $photo['bytes'],
                            self::MODEL,
                            json_encode($face->box),
                            json_encode($face->embedding),
                            base64_decode($face->crop)
                        ]);
                    $faceId = (int) $this->database->lastInsertId();
                    $this->database
                        ->prepare('UPDATE persons SET title_face = COALESCE(title_face, ?) WHERE id = ?')
                        ->execute([$faceId, $person]);
                }
            }
            $this->database
                ->prepare(
                    'INSERT INTO face_state (photo_id, modified, bytes, model, status, attempted) VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(photo_id) DO UPDATE SET modified=excluded.modified, bytes=excluded.bytes, model=excluded.model, status=excluded.status, attempted=excluded.attempted'
                )
                ->execute([$photo['id'], $photo['modified'], $photo['bytes'], self::MODEL, $result->status, time()]);
            $this
                ->database->exec("DELETE FROM persons WHERE name = '' AND NOT EXISTS (SELECT 1 FROM faces WHERE person_id = persons.id)
                AND NOT EXISTS (SELECT 1 FROM person_separations WHERE person_a = persons.id OR person_b = persons.id)");
            $this->database->commit();
            return $unchanged;
        } finally {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        }
    }

    /**
     * Use the complete group's normalized centroid while rejecting incompatible outliers.
     */
    private function match(array $embedding): ?int
    {
        $candidate = $this->profile([$embedding], false);
        $scores = [];
        foreach ($this->profiles($this->groupRows()) as $person => $group) {
            $scores[$person] = $this->similarity($candidate, $group);
        }
        arsort($scores);
        $values = array_values($scores);
        return ($values[0] ?? -1) >= self::MATCH_THRESHOLD && $values[0] - ($values[1] ?? -1) >= self::MATCH_MARGIN
            ? (int) array_key_first($scores)
            : null;
    }

    /**
     * Include manual ownership in the snapshot so concurrent corrections invalidate a merge plan.
     */
    private function groupRows(): array
    {
        $query = $this->database->prepare(
            "SELECT f.id, f.person_id, f.photo_id, f.embedding, persons.name,
            (persons.name <> '' OR EXISTS (SELECT 1 FROM faces owned WHERE owned.person_id = persons.id AND owned.origin = 'manual')) AS protected
            FROM faces f JOIN photos p ON p.id = f.photo_id JOIN persons ON persons.id = f.person_id
            WHERE " .
                self::CURRENT_FACE .
                " AND f.ignored = 0 AND f.model = ? AND persons.auto_match = 1
            AND NOT EXISTS (SELECT 1 FROM person_separations WHERE person_a = persons.id OR person_b = persons.id)
            ORDER BY f.id"
        );
        $query->execute([self::MODEL]);
        return $query->fetchAll();
    }

    /**
     * Build one profile per current eligible group without reading image payloads.
     */
    private function profiles(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $person = $row['person_id'];
            $groups[$person]['vectors'][] = json_decode($row['embedding'], true, flags: JSON_THROW_ON_ERROR);
            $groups[$person]['protected'] = (bool) $row['protected'];
        }
        foreach ($groups as $person => $group) {
            $groups[$person] = $this->profile($group['vectors'], $group['protected']);
        }
        return $groups;
    }

    /**
     * Normalize the sum so larger groups do not win merely by containing more faces.
     */
    private function profile(array $vectors, bool $protected): array
    {
        $center = array_fill(0, 128, 0.0);
        foreach ($vectors as $vector) {
            foreach ($vector as $dimension => $value) {
                $center[$dimension] += $value;
            }
        }
        $length = sqrt(array_sum(array_map(fn($value) => $value * $value, $center)));
        $center = array_map(fn($value) => $length > 0 ? $value / $length : 0.0, $center);
        return ['vectors' => $vectors, 'protected' => $protected, 'center' => $center];
    }

    /**
     * Prevent conflicting manual groups and transitive similarity chains from merging.
     */
    private function similarity(array $left, array $right): float
    {
        if ($left['protected'] && $right['protected']) {
            return -1.0;
        }
        $score = array_sum(array_map(fn($a, $b) => $a * $b, $left['center'], $right['center']));
        if ($score < self::MATCH_THRESHOLD - self::MATCH_MARGIN) {
            return -1.0;
        }
        foreach ($left['vectors'] as $first) {
            foreach ($right['vectors'] as $second) {
                $pair = 0.0;
                foreach ($first as $dimension => $value) {
                    $pair += $value * $second[$dimension];
                }
                if ($pair < self::MATCH_FLOOR) {
                    return -1.0;
                }
            }
        }
        return $score;
    }

    /**
     * Keep equally plausible but mutually incompatible alternatives unresolved.
     */
    private function conflictingAlternative(array $candidates, array $otherScores, int $other, float $score): bool
    {
        foreach ($candidates as $competitor => $alternative) {
            if (
                $competitor !== $other &&
                $alternative > $score - self::MATCH_MARGIN &&
                ($otherScores[$competitor] ?? -1) < self::MATCH_THRESHOLD
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Consolidate existing fragments without inference, tag changes or overwriting manual ownership.
     */
    public function regroup(): int
    {
        $snapshot = $this->groupRows();
        $groups = $this->profiles($snapshot);
        $scores = [];
        foreach ($groups as $first => $left) {
            foreach ($groups as $second => $right) {
                if ($first < $second) {
                    $scores[$first][$second] = $scores[$second][$first] = $this->similarity($left, $right);
                }
            }
        }
        $merges = [];
        while (true) {
            $best = null;
            $bestScore = self::MATCH_THRESHOLD;
            foreach ($scores as $first => $candidates) {
                foreach ($candidates as $second => $score) {
                    if ($first >= $second || $score < $bestScore) {
                        continue;
                    }
                    if (
                        $this->conflictingAlternative($scores[$first], $scores[$second], $second, $score) ||
                        $this->conflictingAlternative($scores[$second], $scores[$first], $first, $score)
                    ) {
                        continue;
                    }
                    $best = [$first, $second];
                    $bestScore = $score;
                }
            }
            if ($best === null) {
                break;
            }
            [$target, $source] = $best;
            if ($groups[$source]['protected']) {
                [$target, $source] = [$source, $target];
            }
            $merges[] = [$target, $source];
            $groups[$target] = $this->profile(
                array_merge($groups[$target]['vectors'], $groups[$source]['vectors']),
                $groups[$target]['protected']
            );
            unset($groups[$source], $scores[$source]);
            foreach ($groups as $person => $group) {
                unset($scores[$person][$source]);
                if ($person !== $target) {
                    $scores[$target][$person] = $scores[$person][$target] = $this->similarity($groups[$target], $group);
                }
            }
        }
        if (!$merges) {
            return 0;
        }
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            if ($this->groupRows() !== $snapshot) {
                throw new \RuntimeException('Gesichtsgruppen wurden zwischenzeitlich geändert. Erneut starten.', 409);
            }
            $move = $this->database->prepare('UPDATE faces SET person_id = ? WHERE person_id = ?');
            $delete = $this->database->prepare('DELETE FROM persons WHERE id = ?');
            foreach ($merges as [$target, $source]) {
                $move->execute([$target, $source]);
                $delete->execute([$source]);
            }
            $this->database->commit();
            return count($merges);
        } finally {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        }
    }

    /**
     * Apply explicit corrections atomically; same names alone never merge identities.
     */
    public function correct(string $action, int $id, string $name = '', int $target = 0): int
    {
        if (mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Namen dürfen höchstens 100 Zeichen lang sein.');
        }
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $table = in_array($action, ['rename', 'merge'], true) ? 'persons' : 'faces';
            $query = $this->database->prepare("SELECT * FROM $table WHERE id = ?");
            $query->execute([$id]);
            $row = $query->fetch();
            if (!$row) {
                throw new \InvalidArgumentException('Person oder Gesicht nicht mehr vorhanden.');
            }
            if ($target > 0) {
                $query = $this->database->prepare('SELECT id FROM persons WHERE id = ?');
                $query->execute([$target]);
                if (!$query->fetch()) {
                    throw new \InvalidArgumentException('Zielperson nicht mehr vorhanden.');
                }
            }
            $person = (int) ($row['person_id'] ?? $id);
            if ($action === 'rename') {
                $this->database->prepare('UPDATE persons SET name = ? WHERE id = ?')->execute([trim($name), $id]);
                $this->database->prepare("UPDATE faces SET origin = 'manual' WHERE person_id = ?")->execute([$id]);
            } elseif ($action === 'merge') {
                if ($target === 0 || $target === $id) {
                    throw new \InvalidArgumentException('Eine andere Zielperson auswählen.');
                }
                $this->database
                    ->prepare("UPDATE faces SET person_id = ?, origin = 'manual' WHERE person_id IN (?, ?)")
                    ->execute([$target, $id, $target]);
                $this->database
                    ->prepare('UPDATE persons SET auto_match = MIN(auto_match, ?) WHERE id = ?')
                    ->execute([$row['auto_match'], $target]);
                $this->database
                    ->prepare(
                        'INSERT OR IGNORE INTO person_separations SELECT ?, person_b FROM person_separations WHERE person_a = ? AND person_b <> ?'
                    )
                    ->execute([$target, $id, $target]);
                $this->database
                    ->prepare(
                        'INSERT OR IGNORE INTO person_separations SELECT person_a, ? FROM person_separations WHERE person_b = ? AND person_a <> ?'
                    )
                    ->execute([$target, $id, $target]);
                $this->database
                    ->prepare('DELETE FROM person_separations WHERE person_a = ? OR person_b = ?')
                    ->execute([$id, $id]);
                $this->database->prepare('DELETE FROM persons WHERE id = ?')->execute([$id]);
                $person = $target;
            } elseif (in_array($action, ['split', 'move', 'ignore'], true)) {
                if ($action === 'split') {
                    $this->database->exec('INSERT INTO persons (auto_match) VALUES (0)');
                    $target = (int) $this->database->lastInsertId();
                }
                if ($action !== 'ignore') {
                    if ($target === 0 || $target === $person) {
                        throw new \InvalidArgumentException('Eine andere Zielperson auswählen.');
                    }
                    $this->database
                        ->prepare('INSERT OR IGNORE INTO person_separations VALUES (?, ?)')
                        ->execute([$person, $target]);
                    $this->database
                        ->prepare('UPDATE persons SET auto_match = 0 WHERE id IN (?, ?)')
                        ->execute([$person, $target]);
                    $this->database
                        ->prepare("UPDATE faces SET origin = 'manual' WHERE person_id IN (?, ?)")
                        ->execute([$person, $target]);
                    $this->database
                        ->prepare("UPDATE faces SET person_id = ?, origin = 'manual', ignored = 0 WHERE id = ?")
                        ->execute([$target, $id]);
                }
                if ($action === 'ignore') {
                    $this->database
                        ->prepare("UPDATE faces SET ignored = 1, origin = 'manual' WHERE id = ?")
                        ->execute([$id]);
                }
            } else {
                throw new \InvalidArgumentException('Unbekannte Korrektur.');
            }
            $this->database->commit();
            return $person;
        } finally {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        }
    }

    /**
     * Erase photo biometrics and exclude them from automatic recreation until explicitly retried.
     */
    public function reset(int $photo, bool $erase): void
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            if ($erase) {
                $this->database->prepare('DELETE FROM faces WHERE photo_id = ?')->execute([$photo]);
                $this->database->exec(
                    'DELETE FROM persons WHERE NOT EXISTS (SELECT 1 FROM faces WHERE person_id = persons.id)'
                );
                $this->database->exec(
                    'DELETE FROM person_separations WHERE person_a NOT IN (SELECT id FROM persons) OR person_b NOT IN (SELECT id FROM persons)'
                );
            }
            $this->database
                ->prepare(
                    "INSERT INTO face_state (photo_id, modified, bytes, model, status)
                SELECT id, modified, bytes, ?, ? FROM photos WHERE id = ?
                ON CONFLICT(photo_id) DO UPDATE SET status=excluded.status"
                )
                ->execute([self::MODEL, $erase ? 'excluded' : 'pending', $photo]);
            $this->database->commit();
        } finally {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        }
    }
}
