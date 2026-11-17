<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class DuplicatePhotos
{
    public const REASON = 'Gleiches Foto in höherer Auflösung vorhanden';
    public const FRESH = 'd.modified = p.modified AND d.bytes = p.bytes';
    public const DUE = 'p.duplicate_checked = 0 OR d.photo_id IS NULL OR NOT (' . self::FRESH . ')';
    private const COPY_DISTANCE = 4;
    private const COLOR_DISTANCE = 3;
    private const DETAIL_DISTANCE = 2;
    private const DETAIL_MAX_DISTANCE = 20;

    /**
     * Keep copy decisions independent of AI ratings and index hash bands for bounded candidate lookups.
     */
    public function __construct(private readonly PhotoButler $library, private readonly string $dataPath)
    {
        $database = $library->database;
        if (
            !in_array(
                needle: 'duplicate_checked',
                haystack: array_column($database->query('PRAGMA table_info(photos)')->fetchAll(), 'name'),
                strict: true
            )
        ) {
            $database->exec('BEGIN IMMEDIATE');
            try {
                foreach (['photos', 'photo_metadata'] as $table) {
                    $database->exec("ALTER TABLE {$table} ADD COLUMN duplicate_checked INTEGER NOT NULL DEFAULT 1");
                }
                $database->commit();
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
            }
        }
        $database->exec('CREATE TABLE IF NOT EXISTS duplicate_state (
            photo_id INTEGER PRIMARY KEY, modified INTEGER NOT NULL, bytes INTEGER NOT NULL,
            fingerprint INTEGER, signature BLOB, kept INTEGER
        )');
        $database->exec('CREATE INDEX IF NOT EXISTS duplicate_kept ON duplicate_state (kept)');
        foreach ([0, 13, 26, 39, 52] as $shift) {
            $database->exec(
                "CREATE INDEX IF NOT EXISTS duplicate_band_{$shift} ON duplicate_state ((fingerprint >> {$shift}) & 8191)"
            );
        }
        $database->exec("INSERT OR IGNORE INTO duplicate_state (photo_id, modified, bytes, kept)
            SELECT s.photo_id, s.modified, s.bytes, s.kept FROM similar_state s JOIN photos p ON p.id = s.photo_id
            WHERE p.is_duplicate = 1 AND s.status = 'hidden' AND s.modified = p.modified AND s.bytes = p.bytes");
    }

    /**
     * Publish successful previews only after comparing them with current copies; favorites always stay visible.
     *
     * @param list<int> $ids
     */
    public function check(array $ids): void
    {
        $database = $this->library->database;
        $lock = fopen($this->dataPath . '/duplicates.lock', mode: 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Duplikatprüfung läuft bereits.', 409);
        }
        try {
            $find = $database->prepare(
                'SELECT p.* FROM photos p LEFT JOIN duplicate_state d ON d.photo_id = p.id
                WHERE p.id = ? AND p.available = 1 AND (' .
                    self::DUE .
                    ')'
            );
            $candidates = $database->prepare(
                'SELECT p.id, p.priority, p.width * p.height AS pixels, p.bytes, d.fingerprint, d.signature
                FROM duplicate_state d JOIN photos p ON p.id = d.photo_id
                WHERE p.available = 1 AND p.is_duplicate = 0 AND p.id <> :id AND ' .
                    self::FRESH .
                    '
                AND p.id IN (
                    SELECT photo_id FROM duplicate_state WHERE ((fingerprint >> 0) & 8191) = :band0
                    UNION SELECT photo_id FROM duplicate_state WHERE ((fingerprint >> 13) & 8191) = :band13
                    UNION SELECT photo_id FROM duplicate_state WHERE ((fingerprint >> 26) & 8191) = :band26
                    UNION SELECT photo_id FROM duplicate_state WHERE ((fingerprint >> 39) & 8191) = :band39
                    UNION SELECT photo_id FROM duplicate_state WHERE ((fingerprint >> 52) & 8191) = :band52)
                ORDER BY (p.priority = 1) DESC, pixels DESC, p.bytes DESC, p.id'
            );
            foreach ($ids as $id) {
                $find->execute([$id]);
                $photo = $find->fetch();
                $find->closeCursor();
                if (!$photo) {
                    continue;
                }
                $path = $this->library->imagePath($id, cachedOnly: true);
                $image = $path === null ? false : imagecreatefromstring(file_get_contents($path));
                $fingerprint = null;
                $signature = null;
                if ($image !== false) {
                    $small = imagecreatetruecolor(width: 9, height: 8);
                    imagecopyresampled(
                        $small,
                        $image,
                        dst_x: 0,
                        dst_y: 0,
                        src_x: 0,
                        src_y: 0,
                        dst_width: 9,
                        dst_height: 8,
                        src_width: imagesx($image),
                        src_height: imagesy($image)
                    );
                    $fingerprint = 0;
                    for ($y = 0; $y < 8; $y++) {
                        $previous = null;
                        for ($x = 0; $x < 9; $x++) {
                            $color = imagecolorat($small, $x, $y);
                            $luminance =
                                (($color >> 16) & 255) * 299 + (($color >> 8) & 255) * 587 + ($color & 255) * 114;
                            if ($previous !== null) {
                                $fingerprint = ($fingerprint << 1) | (int) ($previous > $luminance);
                            }
                            $previous = $luminance;
                        }
                    }
                    $signature = $this->colorSignature($image, size: 16);
                }
                $database->exec('BEGIN IMMEDIATE');
                try {
                    $find->execute([$id]);
                    $current = $find->fetch();
                    $find->closeCursor();
                    if (
                        !$current ||
                        $current['modified'] !== $photo['modified'] ||
                        $current['bytes'] !== $photo['bytes']
                    ) {
                        continue;
                    }
                    $save = $database->prepare('INSERT INTO duplicate_state (photo_id, modified, bytes, fingerprint, signature)
                        VALUES (?, ?, ?, ?, ?) ON CONFLICT(photo_id) DO UPDATE SET modified = excluded.modified,
                        bytes = excluded.bytes, fingerprint = excluded.fingerprint, signature = excluded.signature, kept = NULL');
                    $save->bindValue(1, $id, \PDO::PARAM_INT);
                    $save->bindValue(2, $photo['modified'], \PDO::PARAM_INT);
                    $save->bindValue(3, $photo['bytes'], \PDO::PARAM_INT);
                    $save->bindValue(4, $fingerprint, $fingerprint === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
                    $save->bindValue(5, $signature, $signature === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
                    $save->execute();
                    if ($fingerprint !== null && !$current['is_duplicate']) {
                        $parameters = [':id' => $id];
                        foreach ([0, 13, 26, 39, 52] as $shift) {
                            $parameters[':band' . $shift] = ($fingerprint >> $shift) & 8191;
                        }
                        foreach ($parameters as $key => $value) {
                            $candidates->bindValue($key, $value, \PDO::PARAM_INT);
                        }
                        $candidates->execute();
                        $matches = $candidates->fetchAll();
                        $candidates->closeCursor();
                        $detailSignature = null;
                        foreach ($matches as $other) {
                            if (
                                substr_count(decbin($fingerprint ^ (int) $other['fingerprint']), needle: '1') >
                                    self::COPY_DISTANCE ||
                                $other['signature'] === null
                            ) {
                                continue;
                            }
                            $difference = 0;
                            for ($index = 0; $index < strlen($signature); $index++) {
                                $difference += abs(ord($signature[$index]) - ord($other['signature'][$index]));
                            }
                            if ($difference > strlen($signature) * self::COLOR_DISTANCE) {
                                continue;
                            }
                            $otherPath = $this->library->imagePath((int) $other['id'], cachedOnly: true);
                            $otherImage =
                                $otherPath === null ? false : imagecreatefromstring(file_get_contents($otherPath));
                            if ($otherImage === false) {
                                continue;
                            }
                            $detailSignature ??= $this->colorSignature($image, size: 64);
                            $otherSignature = $this->colorSignature($otherImage, size: 64);
                            $difference = 0;
                            $maximum = 0;
                            for ($index = 0; $index < strlen($detailSignature); $index++) {
                                $delta = abs(ord($detailSignature[$index]) - ord($otherSignature[$index]));
                                $difference += $delta;
                                $maximum = max($maximum, $delta);
                            }
                            if (
                                $difference > 64 * 64 * 3 * self::DETAIL_DISTANCE ||
                                $maximum > self::DETAIL_MAX_DISTANCE
                            ) {
                                continue;
                            }
                            $photoWins = [
                                (int) ($current['priority'] === 1),
                                (int) $photo['width'] * (int) $photo['height'],
                                (int) $photo['bytes'],
                                -$id
                            ] > [
                                (int) ($other['priority'] === 1),
                                (int) $other['pixels'],
                                (int) $other['bytes'],
                                -(int) $other['id']
                            ];
                            $copy = $photoWins ? $other : $current;
                            $kept = $photoWins ? $id : (int) $other['id'];
                            $changed = $database->prepare(
                                'UPDATE photos SET is_duplicate = 1 WHERE id = ? AND priority IN (-1, 0) AND is_duplicate = 0'
                            );
                            $changed->execute([$copy['id']]);
                            if ($changed->rowCount() > 0) {
                                $database
                                    ->prepare('UPDATE duplicate_state SET kept = ? WHERE photo_id = ? OR kept = ?')
                                    ->execute([$kept, $copy['id'], $copy['id']]);
                            }
                            if (!$photoWins) {
                                break;
                            }
                        }
                    }
                    $database->prepare('UPDATE photos SET duplicate_checked = 1 WHERE id = ?')->execute([$id]);
                    $database->commit();
                } finally {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Normalize preview colors without storing or changing source images.
     */
    private function colorSignature(\GdImage $image, int $size): string
    {
        $small = imagecreatetruecolor($size, $size);
        imagecopyresampled(
            $small,
            $image,
            dst_x: 0,
            dst_y: 0,
            src_x: 0,
            src_y: 0,
            dst_width: $size,
            dst_height: $size,
            src_width: imagesx($image),
            src_height: imagesy($image)
        );
        $signature = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $color = imagecolorat($small, $x, $y);
                $signature .= chr(($color >> 16) & 255) . chr(($color >> 8) & 255) . chr($color & 255);
            }
        }
        return $signature;
    }
}
