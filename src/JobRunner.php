<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class JobRunner
{
    private const PREVIEW_BATCH_SIZE = 25;
    private const PREVIEW_STEP_SECONDS = 0.25;

    public const LABELS = [
        'scan' => 'Galerie einlesen',
        'previews' => 'Thumbnails generieren',
        'tag' => 'KI-Tagging',
        'faces' => 'Gesichtertagging'
    ];

    /**
     * Keep browser-independent checkpoints; only explicit starts issue a new run token.
     */
    public function __construct(private readonly PhotoButler $library, private readonly string $dataPath)
    {
        $library->database->exec("CREATE TABLE IF NOT EXISTS jobs (
            job TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT 'idle', token TEXT NOT NULL DEFAULT '',
            completed INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0,
            cursor INTEGER NOT NULL DEFAULT 0, maximum INTEGER NOT NULL DEFAULT 0,
            errors INTEGER NOT NULL DEFAULT 0, estimated INTEGER NOT NULL DEFAULT 0
        )");
        $library->database->exec(
            'CREATE TABLE IF NOT EXISTS job_timings (job TEXT PRIMARY KEY, seconds_per_file REAL NOT NULL)'
        );
        $library->database->exec('CREATE TABLE IF NOT EXISTS job_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT NOT NULL, time TEXT NOT NULL, message TEXT NOT NULL
        ); CREATE INDEX IF NOT EXISTS job_logs_job ON job_logs(job, id)');
        $insert = $library->database->prepare('INSERT OR IGNORE INTO jobs (job) VALUES (?)');
        foreach (array_keys(self::LABELS) as $job) {
            $insert->execute([$job]);
        }
    }

    /**
     * Retain a small per-job history without storing source paths, credentials or model output.
     */
    public function log(string $job, string $message): void
    {
        if (!isset(self::LABELS[$job])) {
            throw new \InvalidArgumentException('Unbekannter Job.');
        }
        $this->library->database
            ->prepare('INSERT INTO job_logs (job, time, message) VALUES (?, ?, ?)')
            ->execute([$job, date('d.m. H:i:s'), $message]);
        $this->library->database
            ->prepare(
                'DELETE FROM job_logs WHERE job = ? AND id NOT IN (SELECT id FROM job_logs WHERE job = ? ORDER BY id DESC LIMIT 30)'
            )
            ->execute([$job, $job]);
    }

    /**
     * Report percentages without discovering files or processing any queue.
     */
    public function all(): array
    {
        $jobs = [];
        foreach (array_keys(self::LABELS) as $job) {
            $jobs[$job] = $this->status($job);
            unset($jobs[$job]['token']);
        }
        return $jobs;
    }

    /**
     * Combine persisted scan/render checkpoints with current independent analysis counts.
     */
    private function status(string $job): array
    {
        if (!isset(self::LABELS[$job])) {
            throw new \InvalidArgumentException('Unbekannter Job.');
        }
        $statement = $this->library->database->prepare(
            "SELECT jobs.*, timing.seconds_per_file FROM jobs LEFT JOIN job_timings timing ON timing.job = CASE WHEN jobs.job = 'previews' THEN 'thumbnails' ELSE jobs.job END WHERE jobs.job = ?"
        );
        $statement->execute([$job]);
        $state = $statement->fetch();
        $statement->closeCursor();
        $statement = $this->library->database->prepare(
            'SELECT id, time, message FROM job_logs WHERE job = ? ORDER BY id DESC LIMIT 30'
        );
        $statement->execute([$job]);
        $state['log'] = array_reverse($statement->fetchAll());
        $statement->closeCursor();
        $state['warning'] = '';
        if ($job === 'scan') {
            try {
                $state = array_replace($state, $this->library->importProgress());
            } catch (\RuntimeException $exception) {
                $state['warning'] = $exception->getMessage();
                $state['estimated'] = 1;
            }
        }
        if ($job === 'previews' && $state['token'] === '') {
            $state['total'] = (int) $this->library->database
                ->query('SELECT COUNT(*) FROM photos WHERE available = 1')
                ->fetchColumn();
        }
        if (in_array($job, ['tag', 'faces'], true)) {
            $done =
                $job === 'tag'
                    ? "p.status = 'done'"
                    : "s.status = 'excluded' OR
                (s.status IN ('done', 'unsupported') AND s.modified = p.modified AND s.bytes = p.bytes AND s.model = :model)";
            $due =
                $job === 'tag'
                    ? "p.status = 'pending' OR (p.status = 'error' AND p.attempted < :retry)"
                    : FaceStore::DUE;
            $error =
                $job === 'tag'
                    ? "p.status = 'error'"
                    : "s.status = 'error' AND s.modified = p.modified AND s.bytes = p.bytes AND s.model = :model";
            $statement = $this->library->database->prepare("SELECT COUNT(*) AS total,
                COALESCE(SUM($done), 0) AS completed, COALESCE(SUM($error), 0) AS errors,
                COALESCE(SUM($due), 0) AS queued
                FROM photos p LEFT JOIN face_state s ON s.photo_id = p.id WHERE p.available = 1");
            $parameters = [':retry' => time() - 3600];
            if ($job === 'faces') {
                $parameters[':model'] = FaceStore::MODEL;
            }
            $statement->execute($parameters);
            $state = array_replace($state, $statement->fetch());
        }
        $state['percent'] =
            $state['total'] > 0
                ? min($state['estimated'] ? 99 : 100, (int) floor((100 * $state['completed']) / $state['total']))
                : ($state['status'] === 'done'
                    ? 100
                    : 0);
        $state['remaining_files'] = max(
            0,
            $state['queued'] ?? $state['total'] - $state['completed'] - $state['errors']
        );
        if ($job === 'scan' && $state['status'] !== 'done') {
            $saved = $this->library->database->query('SELECT state FROM scan_state WHERE id = 1')->fetchColumn();
            $scan = $saved === false ? null : json_decode($saved, flags: JSON_THROW_ON_ERROR);
            $state['checked'] = $scan->progress->checked ?? 0;
            $state['remaining_files'] = max(0, $state['total'] - $state['checked']);
        }
        $state['eta_seconds'] = null;
        $state['eta'] = 'Noch nicht abschätzbar';
        if ($state['seconds_per_file'] > 0 && !$state['estimated'] && $state['remaining_files'] > 0) {
            $state['eta_seconds'] = max(1, (int) ceil($state['remaining_files'] * $state['seconds_per_file']));
            $minutes = (int) ceil($state['eta_seconds'] / 60);
            $days = intdiv($minutes, 1440);
            $hours = intdiv($minutes % 1440, 60);
            $minutes %= 60;
            $parts = [];
            if ($days > 0) {
                $parts[] = $days . ($days === 1 ? ' Tag' : ' Tage');
            }
            if ($hours > 0) {
                $parts[] = $hours . ' Std.';
            }
            if ($minutes > 0) {
                $parts[] = $minutes . ' Min.';
            }
            $state['eta'] =
                $state['eta_seconds'] < 60 ? 'Unter 1 Min.' : 'ca. ' . implode(' ', array_slice($parts, 0, 2));
        }
        if ($state['status'] === 'done' && $state['remaining_files'] === 0 && !$state['estimated']) {
            $state['eta_seconds'] = 0;
            $state['eta'] = 'Abgeschlossen';
        }
        if ($state['status'] === 'error') {
            $state['eta_seconds'] = null;
            $state['eta'] = 'Fehler prüfen';
        }
        return $state;
    }

    /**
     * Serialize starts and steps per job without preventing an independent pause.
     */
    private function lock(string $job): mixed
    {
        if (!isset(self::LABELS[$job])) {
            throw new \InvalidArgumentException('Unbekannter Job.');
        }
        $lock = fopen($this->dataPath . '/job-' . $job . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Job nicht verfügbar.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Ein Schritt läuft noch.', 409);
        }
        return $lock;
    }

    /**
     * Resume a checkpoint or begin a new scan/render pass, never another job.
     */
    public function start(string $job): array
    {
        $lock = $this->lock($job);
        try {
            if ($job === 'scan') {
                $this->log($job, 'Aktualisiere den Gesamtbestand aus den Quellordnern …');
                $this->library->importProgress(refresh: true);
            }
            $state = $this->status($job);
            $database = $this->library->database;
            if (
                $job === 'previews' &&
                ($state['cursor'] === 0 || in_array($state['status'], ['idle', 'done', 'error'], true))
            ) {
                $database->exec("UPDATE jobs SET cursor = 0, completed = 0, errors = 0,
                    maximum = (SELECT COALESCE(MAX(id), 0) FROM photos WHERE available = 1),
                    total = (SELECT COUNT(*) FROM photos WHERE available = 1) WHERE job = 'previews'");
            }
            $database
                ->prepare("UPDATE jobs SET status = 'running', token = ? WHERE job = ?")
                ->execute([bin2hex(random_bytes(16)), $job]);
            $this->log($job, 'Manuell gestartet / fortgesetzt.');
            return $this->status($job);
        } catch (\RuntimeException | \JsonException $exception) {
            $this->log($job, 'Start fehlgeschlagen. Quellen und Konfiguration prüfen.');
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Stop subsequent requests, allowing the already executing step to finish.
     */
    public function pause(string $job, string $token = ''): array
    {
        $this->status($job);
        $statement = $this->library->database->prepare(
            "UPDATE jobs SET status = 'paused' WHERE job = ? AND (? = '' OR token = ?) AND status NOT IN ('paused', 'done', 'error')"
        );
        $statement->execute([$job, $token, $token]);
        if ($statement->rowCount() > 0) {
            $this->log($job, 'Pause angefordert. Ein laufender Schritt wird noch beendet.');
        }
        return $this->status($job);
    }

    /**
     * Reject in-flight writers before resetting one job, retaining manual identities and decisions.
     */
    public function reset(string $job): array
    {
        $locks = [$this->lock($job)];
        $database = $this->library->database;
        try {
            $names = match ($job) {
                'scan' => [
                    'job-previews',
                    'job-tag',
                    'job-faces',
                    'index',
                    'tag',
                    'faces',
                    'thumbnail-0',
                    'thumbnail-1'
                ],
                'previews' => ['thumbnail-0', 'thumbnail-1'],
                'tag' => ['tag'],
                'faces' => ['faces']
            };
            foreach ($names as $name) {
                $lock = fopen($this->dataPath . '/' . $name . '.lock', 'c');
                if ($lock === false) {
                    throw new \RuntimeException('Zurücksetzen nicht möglich. Schreibrechte prüfen.');
                }
                $locks[] = $lock;
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new \RuntimeException('Ein Schritt läuft noch. Bitte später erneut zurücksetzen.', 409);
                }
            }
            $database->exec('BEGIN IMMEDIATE');
            if ($job === 'scan') {
                $database->exec('INSERT OR REPLACE INTO photo_metadata (id, path, modified, bytes, manual_tags, priority)
                    SELECT id, path, modified, bytes, manual_tags, priority FROM photos;
                    DELETE FROM photos; DELETE FROM scan_state; DELETE FROM import_files; DELETE FROM import_inventory;');
            }
            if ($job === 'previews') {
                foreach (new \DirectoryIterator($this->dataPath . '/thumbnails') as $file) {
                    if ($file->isFile() && preg_match('/^[a-f0-9]{64}\.jpg(?:\.webp)?$/D', $file->getFilename())) {
                        if (!unlink($file->getPathname())) {
                            throw new \RuntimeException('Thumbnails konnten nicht vollständig gelöscht werden.');
                        }
                    }
                }
            }
            if ($job === 'tag') {
                $database->exec(
                    "UPDATE photos SET ai_tags = '[]', description = '', status = 'pending', attempted = 0"
                );
            }
            if ($job === 'faces') {
                $database->exec("DELETE FROM faces WHERE origin = 'auto';
                    DELETE FROM face_state WHERE status <> 'excluded';
                    DELETE FROM persons WHERE name = '' AND auto_match = 1
                        AND NOT EXISTS (SELECT 1 FROM faces WHERE person_id = persons.id)
                        AND NOT EXISTS (SELECT 1 FROM person_separations WHERE person_a = persons.id OR person_b = persons.id);
                    UPDATE persons SET title_face = NULL WHERE title_face NOT IN (SELECT id FROM faces);");
            }
            $database
                ->prepare(
                    "UPDATE jobs SET status = 'idle', token = '', completed = 0, total = 0,
                cursor = 0, maximum = 0, errors = 0, estimated = 0 WHERE job = ?"
                )
                ->execute([$job]);
            $database
                ->prepare('DELETE FROM job_timings WHERE job IN (?, ?)')
                ->execute([$job, $job === 'previews' ? 'thumbnails' : $job]);
            $database->prepare('DELETE FROM job_logs WHERE job = ?')->execute([$job]);
            $this->log($job, 'Daten zurückgesetzt. Wartet auf manuellen Start.');
            $database->commit();
            return $this->status($job);
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            foreach ($locks as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Execute one server-side unit only for the explicitly authorized run.
     */
    public function step(
        string $job,
        string $token,
        int $scanLimit = 1000,
        int $previewLimit = self::PREVIEW_BATCH_SIZE
    ): array {
        $lock = $this->lock($job);
        try {
            $state = $this->status($job);
            if ($state['status'] !== 'running' || $token === '' || !hash_equals($state['token'], $token)) {
                unset($state['token']);
                if ($state['status'] === 'running') {
                    $state['status'] = 'paused';
                }
                return $state;
            }
            $timingStarted = hrtime(true);
            $processed = 0;
            $database = $this->library->database;
            if (in_array($job, ['tag', 'faces'], true)) {
                $queuedBefore = $state['queued'];
                if ($job === 'tag') {
                    $this->library->tag(1);
                }
                if ($job === 'faces') {
                    $this->library->tagFaces(1);
                }
                $state = $this->status($job);
                $more = $state['queued'] > 0;
                $processed = max(0, $queuedBefore - $state['queued']);
            }
            if ($job === 'scan') {
                $this->log($job, 'Lese den nächsten Galerieabschnitt ein …');
                $this->library->index(limit: $scanLimit);
                $processed = max(0, $this->library->scanProgress->checked - ($state['checked'] ?? 0));
                $saved = $database->query('SELECT state FROM scan_state WHERE id = 1')->fetchColumn();
                $more = $saved !== false;
                $state = array_replace($state, $this->library->importProgress());
                $this->log($job, $processed . ' Dateien geprüft.');
            }
            if ($job === 'previews') {
                $started = hrtime(true);
                $cachedCount = 0;
                $this->log($job, 'Prüfe vorhandene Thumbnails ab Foto ' . ($state['cursor'] + 1) . ' …');
                $statement = $database->prepare(
                    'SELECT id FROM photos WHERE available = 1 AND id > ? AND id <= ? ORDER BY id LIMIT ?'
                );
                $statement->execute([
                    $state['cursor'],
                    $state['maximum'],
                    max(1, min(self::PREVIEW_BATCH_SIZE, $previewLimit))
                ]);
                $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
                $statement->closeCursor();
                for ($offset = 0; $offset < count($ids); ) {
                    $batch = [(int) $ids[$offset++]];
                    if (isset($ids[$offset]) && $ids[$offset] % 2 !== $batch[0] % 2) {
                        $batch[] = (int) $ids[$offset++];
                    }
                    if (
                        $database->query("SELECT status FROM jobs WHERE job = 'previews'")->fetchColumn() !== 'running'
                    ) {
                        break;
                    }
                    $results = [];
                    $pending = [];
                    foreach ($batch as $id) {
                        $cached = $this->library->imagePath((int) $id, animated: true, cachedOnly: true) !== null;
                        $results[$id] = $cached;
                        $cachedCount += (int) $cached;
                        if (!$cached) {
                            $pending[] = (int) $id;
                        }
                    }
                    if ($pending !== []) {
                        $this->log($job, 'Erzeuge Thumbnail für Foto ' . implode(', ', $pending) . ' …');
                        $results = array_replace($results, new PreviewPool($this->dataPath)->render($pending));
                    }
                    foreach ($batch as $id) {
                        if (!$results[$id]) {
                            $this->log(
                                $job,
                                'Foto ' .
                                    $id .
                                    ': Thumbnail konnte nicht erstellt werden. Original, Format und Schreibrechte prüfen.'
                            );
                        }
                        $state['errors'] += (int) !$results[$id];
                        $state['completed'] += (int) $results[$id];
                        $state['cursor'] = (int) $id;
                        $processed++;
                    }
                    if ((hrtime(true) - $started) / 1e9 >= self::PREVIEW_STEP_SECONDS) {
                        break;
                    }
                }
                $statement = $database->prepare(
                    'SELECT COUNT(*) FROM photos WHERE available = 1 AND id > ? AND id <= ?'
                );
                $statement->execute([$state['cursor'], $state['maximum']]);
                $remaining = (int) $statement->fetchColumn();
                $statement->closeCursor();
                $more = $remaining > 0;
                $state['total'] = $state['completed'] + $state['errors'] + $remaining;
                $this->log($job, $processed . ' Fotos geprüft, ' . $cachedCount . ' vorhandene Thumbnails übernommen.');
            }
            $database
                ->prepare(
                    "UPDATE jobs SET completed = ?, total = ?, cursor = ?, errors = ?, estimated = ?,
                status = CASE WHEN status = 'running' THEN ? ELSE status END WHERE job = ? AND token = ?"
                )
                ->execute([
                    $state['completed'],
                    $state['total'],
                    $state['cursor'],
                    $state['errors'],
                    $state['estimated'],
                    $more
                        ? 'running'
                        : ($state['errors'] > 0
                            ? 'error'
                            : ($job === 'scan' && $state['completed'] < $state['total']
                                ? 'paused'
                                : 'done')),
                    $job,
                    $token
                ]);
            if ($processed > 0) {
                $secondsPerFile = (hrtime(true) - $timingStarted) / 1e9 / $processed;
                $database
                    ->prepare(
                        'INSERT INTO job_timings (job, seconds_per_file) VALUES (?, ?)
                    ON CONFLICT(job) DO UPDATE SET seconds_per_file = (job_timings.seconds_per_file + excluded.seconds_per_file) / 2'
                    )
                    ->execute([$job === 'previews' ? 'thumbnails' : $job, $secondsPerFile]);
            }
            if (!$more) {
                $statement = $database->prepare('SELECT status FROM jobs WHERE job = ?');
                $statement->execute([$job]);
                $status = $statement->fetchColumn();
                $statement->closeCursor();
                $this->log(
                    $job,
                    match ($status) {
                        'done' => 'Abgeschlossen.',
                        'error' => 'Mit ' . $state['errors'] . ' Fehlern beendet.',
                        default => 'Pausiert. Wartet auf manuelle Fortsetzung.'
                    }
                );
            }
            return $this->status($job);
        } catch (\RuntimeException | \JsonException $exception) {
            $this->pause($job);
            $this->log(
                $job,
                'Schritt abgebrochen. Quellen, Konfiguration und Schreibrechte prüfen; anschließend manuell fortsetzen.'
            );
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
