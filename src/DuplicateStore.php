<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class DuplicateStore
{
    /**
     * Keep content fingerprints and excluded source paths across catalog resets.
     */
    public function __construct(private readonly \PDO $database, private readonly string $dataPath)
    {
        $database->exec('CREATE TABLE IF NOT EXISTS photo_hashes (
            path TEXT PRIMARY KEY, modified INTEGER NOT NULL, bytes INTEGER NOT NULL, digest TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS photo_metadata_bytes ON photo_metadata(bytes);
            CREATE INDEX IF NOT EXISTS photos_bytes ON photos(bytes);
            CREATE TABLE IF NOT EXISTS photo_duplicates (
            path TEXT PRIMARY KEY, canonical_id INTEGER NOT NULL, digest TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS duplicate_cache_queue (path TEXT PRIMARY KEY, backup TEXT NOT NULL);');
    }

    /**
     * Read originals only during import or explicit cleanup, reusing unchanged fingerprints.
     */
    public function fingerprint(string $path, int $modified, int $bytes): string
    {
        $statement = $this->database->prepare(
            'SELECT digest FROM photo_hashes WHERE path = ? AND modified = ? AND bytes = ?'
        );
        $statement->execute([$path, $modified, $bytes]);
        $digest = $statement->fetchColumn();
        if ($digest !== false) {
            return $digest;
        }
        clearstatcache(true, $path);
        if (
            !is_file($path) ||
            realpath($path) !== $path ||
            filemtime($path) !== $modified ||
            filesize($path) !== $bytes
        ) {
            throw new \RuntimeException(
                'Original nicht verfügbar oder verändert. Kein Duplikatausschluss vorgenommen.'
            );
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $digest = hash_file('sha256', $path);
        } catch (\ErrorException $exception) {
            throw new \RuntimeException(
                'Original nicht lesbar. Kein Duplikatausschluss vorgenommen.',
                previous: $exception
            );
        } finally {
            restore_error_handler();
        }
        clearstatcache(true, $path);
        if ($digest === false || filemtime($path) !== $modified || filesize($path) !== $bytes) {
            throw new \RuntimeException('Original nicht vollständig lesbar oder während des Hashings verändert.');
        }
        $this->database
            ->prepare('INSERT OR REPLACE INTO photo_hashes VALUES (?, ?, ?, ?)')
            ->execute([$path, $modified, $bytes, $digest]);
        return $digest;
    }

    /**
     * Resolve the oldest retained identity, including identities protected by a catalog reset.
     */
    public function canonical(string $path, string $digest, int $bytes, array $roots): ?array
    {
        $statement = $this->database->prepare('SELECT p.* FROM (
            SELECT id,path,modified,bytes,manual_tags,priority FROM photos
            UNION ALL SELECT id,path,modified,bytes,manual_tags,priority FROM photo_metadata m
            WHERE NOT EXISTS (SELECT 1 FROM photos p WHERE p.id = m.id)
            ) p LEFT JOIN photo_duplicates d ON d.path = p.path
            LEFT JOIN photo_hashes h ON h.path = p.path AND h.modified = p.modified AND h.bytes = p.bytes
            WHERE p.bytes = ? AND p.path <> ? AND d.path IS NULL AND (h.digest IS NULL OR h.digest = ?) ORDER BY p.id');
        $statement->execute([$bytes, $path, $digest]);
        foreach ($statement->fetchAll() as $candidate) {
            if (
                in_array(
                    strtolower(pathinfo($candidate['path'], PATHINFO_EXTENSION)),
                    PhotoButler::VIDEO_EXTENSIONS,
                    true
                ) ||
                !array_any($roots, fn(string $root): bool => str_starts_with($candidate['path'], $root . '/'))
            ) {
                continue;
            }
            clearstatcache(true, $candidate['path']);
            if (!is_file($candidate['path'])) {
                $directory = dirname($candidate['path']);
                if (is_readable($directory) && is_dir($directory)) {
                    $entries = scandir($directory);
                    if ($entries !== false && !in_array(basename($candidate['path']), $entries, true)) {
                        continue;
                    }
                }
                throw new \RuntimeException('Möglicher Duplikatpartner nicht verfügbar. Import bleibt wiederholbar.');
            }
            if (realpath($candidate['path']) !== $candidate['path']) {
                throw new \RuntimeException('Duplikatpartner ist kein verfügbarer kanonischer Quellpfad.');
            }
            if (
                filemtime($candidate['path']) !== $candidate['modified'] ||
                filesize($candidate['path']) !== $candidate['bytes']
            ) {
                continue;
            }
            $known = $this->fingerprint($candidate['path'], $candidate['modified'], $candidate['bytes']);
            if ($known === $digest) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Persist suppression in the same transaction as the import checkpoint or cleanup.
     */
    public function exclude(string $path, int $canonicalId, string $digest): void
    {
        $this->database
            ->prepare('INSERT OR REPLACE INTO photo_duplicates VALUES (?, ?, ?)')
            ->execute([$path, $canonicalId, $digest]);
    }

    /**
     * Refuse active writers instead of waiting behind a running job.
     */
    private function lock(string $name): mixed
    {
        $lock = fopen($this->dataPath . '/' . $name . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Bereinigungssperre nicht verfügbar.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Ein Schritt läuft. Bereinigung nicht gestartet.', 409);
        }
        return $lock;
    }

    /**
     * Audit first; conflicting metadata or unavailable originals block the entire deletion phase.
     */
    public function clean(array $roots, bool $apply = false): array
    {
        $report = [
            'candidates' => 0,
            'groups' => 0,
            'duplicates' => 0,
            'removed' => 0,
            'thumbnails' => 0,
            'conflicts' => [],
            'unreadable' => [],
            'backup' => null
        ];
        $locks = [];
        try {
            foreach (['job-scan', 'index'] as $name) {
                $locks[] = $this->lock($name);
            }
            if ((int) $this->database->query("SELECT COUNT(*) FROM jobs WHERE status = 'running'")->fetchColumn() > 0) {
                throw new \RuntimeException('Jobs vor der Bereinigung manuell pausieren.', 409);
            }
            $candidates = $this->database
                ->query(
                    'SELECT * FROM photos WHERE bytes IN (SELECT bytes FROM photos GROUP BY bytes HAVING COUNT(*) > 1) ORDER BY id'
                )
                ->fetchAll();
            $groups = [];
            foreach ($candidates as $photo) {
                if (
                    in_array(
                        strtolower(pathinfo($photo['path'], PATHINFO_EXTENSION)),
                        PhotoButler::VIDEO_EXTENSIONS,
                        true
                    )
                ) {
                    continue;
                }
                $report['candidates']++;
                clearstatcache(true, $photo['path']);
                if (
                    !in_array($photo['root'], $roots, true) ||
                    !str_starts_with($photo['path'], $photo['root'] . '/') ||
                    realpath($photo['path']) !== $photo['path'] ||
                    filemtime($photo['path']) !== $photo['modified'] ||
                    filesize($photo['path']) !== $photo['bytes']
                ) {
                    $report['unreadable'][] = $photo['id'];
                    continue;
                }
                try {
                    $digest = $this->fingerprint($photo['path'], $photo['modified'], $photo['bytes']);
                } catch (\RuntimeException $exception) {
                    $report['unreadable'][] = $photo['id'];
                    continue;
                }
                $groups[$digest][] = $photo['id'];
            }
            foreach (['job-previews', 'job-tag', 'job-faces', 'tag', 'faces', 'thumbnail-0', 'thumbnail-1'] as $name) {
                $locks[] = $this->lock($name);
            }
            $this->database->exec('BEGIN IMMEDIATE');
            if ((int) $this->database->query("SELECT COUNT(*) FROM jobs WHERE status = 'running'")->fetchColumn() > 0) {
                throw new \RuntimeException('Jobs vor der Bereinigung manuell pausieren.', 409);
            }
            $plans = [];
            foreach ($groups as $digest => $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                $report['groups']++;
                $report['duplicates'] += count($ids) - 1;
                $select = $this->database->prepare('SELECT * FROM photos WHERE id = ?');
                $select->execute([$ids[0]]);
                $canonical = $select->fetch();
                foreach (array_slice($ids, 1) as $id) {
                    $select->execute([$id]);
                    $duplicate = $select->fetch();
                    $fields = [];
                    foreach (['priority', 'manual_tags', 'ai_tags', 'description'] as $field) {
                        if ($canonical[$field] !== $duplicate[$field]) {
                            $fields[] = $field;
                        }
                    }
                    $faces = $this->database->prepare(
                        'SELECT (SELECT COUNT(*) FROM faces WHERE photo_id = ?) + (SELECT COUNT(*) FROM face_state WHERE photo_id = ?)'
                    );
                    $faces->execute([$id, $id]);
                    if ((int) $faces->fetchColumn() > 0) {
                        $fields[] = 'faces';
                    }
                    if ($fields !== []) {
                        $report['conflicts'][] = [
                            'canonical' => $canonical['id'],
                            'duplicate' => $id,
                            'fields' => $fields
                        ];
                    }
                    $plans[] = [$canonical, $duplicate, $digest];
                }
            }
            if (!$apply || $report['conflicts'] !== [] || $report['unreadable'] !== []) {
                $this->database->rollBack();
                return $report;
            }
            if ($plans !== []) {
                $backup = $this->dataPath . '/duplicate-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
                if (!mkdir($backup, 0700)) {
                    throw new \RuntimeException('Sicherung nicht möglich.');
                }
                // A separate WAL reader snapshots committed data while this connection excludes writers.
                $reader = new \PDO('sqlite:' . $this->dataPath . '/database.sqlite');
                $reader->exec('VACUUM INTO ' . $reader->quote($backup . '/database.sqlite'));
                chmod($backup . '/database.sqlite', 0600);
                $verification = new \PDO('sqlite:' . $backup . '/database.sqlite');
                if (
                    $verification->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' ||
                    (int) $verification->query('SELECT COUNT(*) FROM photos')->fetchColumn() !==
                        (int) $this->database->query('SELECT COUNT(*) FROM photos')->fetchColumn()
                ) {
                    throw new \RuntimeException('Sicherung konnte nicht verifiziert werden.');
                }
                $report['backup'] = $backup;
                foreach ($plans as [$canonical, $duplicate, $digest]) {
                    foreach ([$canonical, $duplicate] as $photo) {
                        clearstatcache(true, $photo['path']);
                        if (
                            realpath($photo['path']) !== $photo['path'] ||
                            filemtime($photo['path']) !== $photo['modified'] ||
                            filesize($photo['path']) !== $photo['bytes']
                        ) {
                            throw new \RuntimeException('Original seit Prüfung verändert. Bereinigung abgebrochen.');
                        }
                    }
                    $this->exclude($duplicate['path'], $canonical['id'], $digest);
                    $this->database
                        ->prepare(
                            'INSERT OR REPLACE INTO photo_metadata (id,path,modified,bytes,manual_tags,priority) SELECT id,path,modified,bytes,manual_tags,priority FROM photos WHERE id = ?'
                        )
                        ->execute([$duplicate['id']]);
                    $this->database
                        ->prepare('UPDATE photo_duplicates SET canonical_id = ? WHERE canonical_id = ?')
                        ->execute([$canonical['id'], $duplicate['id']]);
                    $this->database
                        ->prepare('INSERT OR IGNORE INTO duplicate_cache_queue VALUES (?, ?)')
                        ->execute([$duplicate['path'], $backup]);
                    $this->database->prepare('DELETE FROM photos WHERE id = ?')->execute([$duplicate['id']]);
                    $report['removed']++;
                }
            }
            $this->database->commit();
            $retained = [];
            foreach ($this->database->query('SELECT path FROM photos')->fetchAll(\PDO::FETCH_COLUMN) as $path) {
                $retained[hash('sha256', $path)] = true;
            }
            foreach ($this->database->query('SELECT * FROM duplicate_cache_queue')->fetchAll() as $entry) {
                $key = hash('sha256', $entry['path']);
                if (isset($retained[$key])) {
                    continue;
                }
                foreach (['.jpg', '.jpg.webp'] as $suffix) {
                    $source = $this->dataPath . '/thumbnails/' . $key . $suffix;
                    $target = $entry['backup'] . '/' . $key . $suffix;
                    if (is_link($source)) {
                        throw new \RuntimeException(
                            'Unerwarteter Cache-Symlink; Index gesichert, Cachebereinigung offen.'
                        );
                    }
                    if (is_file($source)) {
                        if (file_exists($target) || !rename($source, $target)) {
                            throw new \RuntimeException('Cachebereinigung offen. Erneuter Aufruf setzt sie fort.');
                        }
                        $report['thumbnails']++;
                    }
                }
                $this->database->prepare('DELETE FROM duplicate_cache_queue WHERE path = ?')->execute([$entry['path']]);
            }
            return $report;
        } finally {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            foreach ($locks as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
