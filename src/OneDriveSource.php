<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class OneDriveSource
{
    /**
     * Keep cloud identities and import checkpoints independent of filesystem paths.
     */
    public function __construct(
        private readonly PhotoButler $library,
        private readonly string $dataPath,
        public readonly OneDriveClient $client
    ) {
        $library->database->exec("CREATE TABLE IF NOT EXISTS onedrive_items (id TEXT PRIMARY KEY, data TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS onedrive_pending (id TEXT PRIMARY KEY, data TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS onedrive_state (id INTEGER PRIMARY KEY CHECK(id=1), scope TEXT NOT NULL, delta TEXT, next TEXT, checked INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS onedrive_preview_fallbacks (photo_id INTEGER PRIMARY KEY, version TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS onedrive_photos (photo_id INTEGER PRIMARY KEY, item TEXT NOT NULL UNIQUE, version TEXT NOT NULL, relative_path TEXT NOT NULL);");
    }

    /**
     * Resolve the configured root once; never fall back to the Windows mount.
     */
    public function connection(): array
    {
        $path = $this->dataPath . '/onedrive-source.json';
        if (!is_file($path)) {
            throw new \RuntimeException('OneDrive einrichten: --onedrive-login ausführen.');
        }
        $connection = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (
            ($connection['folder_path'] ?? '') !== $this->library->getSetting('ONEDRIVE_FOLDER') ||
            ($connection['client_id'] ?? '') !== $this->library->getSetting('ONEDRIVE_CLIENT_ID')
        ) {
            throw new \RuntimeException('OneDrive-Konfiguration geändert. Verbindung erneut einrichten.');
        }
        $scope = $this->library->database->query('SELECT scope FROM onedrive_state WHERE id=1')->fetchColumn();
        if ($scope !== false && $scope !== $connection['drive'] . ':' . $connection['folder']) {
            throw new \RuntimeException('OneDrive-Verbindung passt nicht zum Katalog.');
        }
        return $connection;
    }

    /**
     * Bind a read-only drive/folder after interactive consent without importing photos.
     */
    public function connect(): void
    {
        $this->client->login();
        $drive = $this->client->get('/me/drive?$select=id');
        $folder = trim($this->library->getSetting('ONEDRIVE_FOLDER'), '/');
        if ($folder === '') {
            throw new \RuntimeException('ONEDRIVE_FOLDER muss den Fotoordner relativ zum Laufwerk angeben.');
        }
        $item = $this->client->get(
            '/drives/' .
                rawurlencode($drive['id']) .
                '/root:/' .
                implode('/', array_map('rawurlencode', explode('/', $folder))) .
                '?$select=id,folder,remoteItem'
        );
        if (!isset($item['folder']) || isset($item['remoteItem'])) {
            throw new \RuntimeException('Bitte einen eigenen OneDrive-Ordner wählen, keine geteilte Verknüpfung.');
        }
        $connection = [
            'drive' => $drive['id'],
            'folder' => $item['id'],
            'folder_path' => $this->library->getSetting('ONEDRIVE_FOLDER'),
            'client_id' => $this->library->getSetting('ONEDRIVE_CLIENT_ID')
        ];
        $scope = $drive['id'] . ':' . $item['id'];
        $oldScope = $this->library->database->query('SELECT scope FROM onedrive_state WHERE id=1')->fetchColumn();
        if ($oldScope !== false && $oldScope !== $scope) {
            throw new \RuntimeException(
                'Anderer OneDrive-Bestand. Vorhandene Zuordnung bleibt erhalten; separate Installation verwenden.'
            );
        }
        $path = $this->dataPath . '/onedrive-source.json';
        if (
            file_put_contents($path . '.tmp', json_encode($connection, JSON_THROW_ON_ERROR), LOCK_EX) === false ||
            !rename($path . '.tmp', $path)
        ) {
            throw new \RuntimeException('OneDrive-Verbindung konnte nicht gespeichert werden.');
        }
        chmod($path, 0600);
    }

    /**
     * Report the last complete catalog without network requests from the browser.
     */
    public function progress(): array
    {
        $count = (int) $this->library->database
            ->query('SELECT COUNT(*) FROM photos p JOIN onedrive_photos o ON o.photo_id=p.id WHERE p.available=1')
            ->fetchColumn();
        $state = $this->library->database->query('SELECT delta,next,checked FROM onedrive_state WHERE id=1')->fetch();
        $saved = $this->library->database->query('SELECT state FROM scan_state WHERE id=1')->fetchColumn();
        $scan = $saved === false ? null : json_decode($saved, flags: JSON_THROW_ON_ERROR);
        return [
            'checked' => (int) ($state['checked'] ?? 0),
            'scan_total' =>
                $state && $state['next'] === null ? (int) $state['checked'] : (int) ($scan->progress->total ?? 0),
            'total' => $count,
            'completed' => $count,
            'estimated' => (int) (!$state || $state['next'] !== null || $state['delta'] === null)
        ];
    }

    /**
     * Stage one delta page at a time; publish availability only after a complete traversal.
     */
    public function index(): int
    {
        $connection = $this->connection();
        $scope = $connection['drive'] . ':' . $connection['folder'];
        $database = $this->library->database;
        $state = $database->query('SELECT * FROM onedrive_state WHERE id=1')->fetch();
        if ($state && $state['scope'] !== $scope) {
            throw new \RuntimeException('OneDrive-Bestand stimmt nicht mit dem Katalog überein.');
        }
        if (!$state || $state['next'] === null) {
            $next =
                $state['delta'] ??
                '/drives/' .
                    rawurlencode($connection['drive']) .
                    '/root/delta?$select=id,name,parentReference,file,folder,deleted,remoteItem,size,lastModifiedDateTime,cTag,eTag,photo,image,video';
            $database->beginTransaction();
            try {
                $database->exec(
                    'DELETE FROM onedrive_pending; INSERT INTO onedrive_pending SELECT * FROM onedrive_items'
                );
                $database
                    ->prepare(
                        'INSERT INTO onedrive_state(id,scope,next,checked) VALUES(1,?,?,0) ON CONFLICT(id) DO UPDATE SET next=excluded.next,checked=0'
                    )
                    ->execute([$scope, $next]);
                $database->commit();
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
            }
            $state = $database->query('SELECT * FROM onedrive_state WHERE id=1')->fetch();
        }
        try {
            $page = $this->client->get($state['next']);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 410) {
                $database->beginTransaction();
                try {
                    $database->exec('DELETE FROM onedrive_pending');
                    $database
                        ->prepare('UPDATE onedrive_state SET delta=NULL,next=?,checked=0 WHERE id=1')
                        ->execute(['/drives/' . rawurlencode($connection['drive']) . '/root/delta']);
                    $database->commit();
                } finally {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                }
            }
            throw $exception;
        }
        if (!isset($page['value']) || (!isset($page['@odata.nextLink']) && !isset($page['@odata.deltaLink']))) {
            throw new \RuntimeException('OneDrive-Bestandsaufnahme unvollständig.');
        }
        $removed = [];
        $database->beginTransaction();
        try {
            $save = $database->prepare('INSERT OR REPLACE INTO onedrive_pending(id,data) VALUES(?,?)');
            $remove = $database->prepare('DELETE FROM onedrive_pending WHERE id=?');
            foreach ($page['value'] as $item) {
                if (isset($item['deleted'])) {
                    $remove->execute([$item['id']]);
                    continue;
                }
                unset($item['@microsoft.graph.downloadUrl']);
                $save->execute([$item['id'], json_encode($item, JSON_THROW_ON_ERROR)]);
            }
            $checked = (int) $state['checked'] + count($page['value']);
            $database
                ->prepare('UPDATE onedrive_state SET next=?,checked=? WHERE id=1')
                ->execute([$page['@odata.nextLink'] ?? null, $checked]);
            $progress = new \stdClass();
            $progress->checked = $checked;
            $progress->changed = 0;
            $knownChildren =
                $state['delta'] === null
                    ? (int) $database
                        ->query(
                            "SELECT COALESCE(SUM(json_extract(data, '$.folder.childCount')), 0) + 1 FROM onedrive_pending"
                        )
                        ->fetchColumn()
                    : 0;
            $progress->total = isset($page['@odata.nextLink'])
                ? max($knownChildren, $checked + max(1, count($page['value'])))
                : $checked;
            $this->library->scanProgress = $progress;
            if (isset($page['@odata.nextLink'])) {
                $scan = new \stdClass();
                $scan->progress = $progress;
                $database
                    ->prepare('INSERT OR REPLACE INTO scan_state(id,state) VALUES(1,?)')
                    ->execute([json_encode($scan, JSON_THROW_ON_ERROR)]);
            }
            if (isset($page['@odata.deltaLink'])) {
                $items = [];
                foreach (
                    $database->query(
                        "SELECT id,data FROM onedrive_pending WHERE json_type(data, '$.folder') IS NOT NULL"
                    )
                    as $row
                ) {
                    $items[$row['id']] = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
                }
                if (!isset($items[$connection['folder']]['folder'])) {
                    throw new \RuntimeException('OneDrive-Fotoordner nicht verfügbar. Katalog bleibt erhalten.');
                }
                $paths = [$connection['folder'] => ''];
                $seen = bin2hex(random_bytes(12));
                $nextId =
                    1 +
                    (int) $database
                        ->query(
                            'SELECT MAX(id) FROM (SELECT MAX(id) id FROM photos UNION ALL SELECT MAX(id) FROM photo_metadata)'
                        )
                        ->fetchColumn();
                foreach ($database->query('SELECT id,data FROM onedrive_pending') as $row) {
                    $itemId = $row['id'];
                    $item = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
                    if (
                        !isset($item['file']) ||
                        isset($item['remoteItem']) ||
                        !in_array(
                            strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)),
                            ['jpg', 'jpeg', 'png', 'webp', 'gif', 'm4v', 'mov', 'webm', '3gp', 'avi', 'mkv'],
                            true
                        )
                    ) {
                        continue;
                    }
                    $chain = [$itemId => $item['name']];
                    $parent = $item['parentReference']['id'] ?? '';
                    while (!array_key_exists($parent, $paths) && isset($items[$parent]) && !isset($chain[$parent])) {
                        $chain[$parent] = $items[$parent]['name'];
                        $parent = $items[$parent]['parentReference']['id'] ?? '';
                    }
                    if (!array_key_exists($parent, $paths)) {
                        continue;
                    }
                    $relative = $paths[$parent];
                    foreach (array_reverse($chain, true) as $child => $name) {
                        $relative = ltrim($relative . '/' . $name, '/');
                        if ((string) $child !== (string) $itemId) {
                            $paths[$child] = $relative;
                        }
                    }
                    $find = $database->prepare(
                        'SELECT o.*,p.path,p.modified,p.bytes FROM onedrive_photos o LEFT JOIN photos p ON p.id=o.photo_id WHERE o.item=?'
                    );
                    $find->execute([$itemId]);
                    $existing = $find->fetch();
                    $path =
                        $existing['path'] ??
                        'onedrive:/' .
                            rawurlencode($connection['drive']) .
                            '/' .
                            rawurlencode($itemId) .
                            '/' .
                            $item['name'];
                    $legacyRoot = rtrim($this->library->getSetting('ONEDRIVE_LEGACY_ROOT'), '/');
                    if (!$existing && $legacyRoot !== '') {
                        $path = $legacyRoot . '/' . $relative;
                    }
                    $find = $database->prepare(
                        'SELECT id,path,modified,bytes,priority FROM photos WHERE path=? UNION ALL SELECT id,path,modified,bytes,priority FROM photo_metadata WHERE path=? LIMIT 1'
                    );
                    $find->execute([$path, $path]);
                    $saved = $find->fetch();
                    if (!$existing && $saved) {
                        $mapped = $database->prepare('SELECT item FROM onedrive_photos WHERE photo_id=?');
                        $mapped->execute([$saved['id']]);
                        if ($mapped->fetchColumn() !== false) {
                            $saved = false;
                            $path =
                                'onedrive:/' .
                                rawurlencode($connection['drive']) .
                                '/' .
                                rawurlencode($itemId) .
                                '/' .
                                $item['name'];
                        }
                    }
                    if ($existing && !$saved) {
                        $find = $database->prepare('SELECT * FROM photo_metadata WHERE id=?');
                        $find->execute([$existing['photo_id']]);
                        $saved = $find->fetch();
                        $path = $saved['path'] ?? $path;
                    }
                    $id = $existing['photo_id'] ?? ($saved['id'] ?? $nextId++);
                    $version = isset($item['cTag'])
                        ? 'c:' . $item['cTag']
                        : (isset($item['eTag'])
                            ? 'e:' . $item['eTag']
                            : 'd:' . $item['lastModifiedDateTime'] . ':' . $item['size']);
                    $changed = $existing && $existing['version'] !== $version;
                    $modified = $changed
                        ? max(time(), (int) ($saved['modified'] ?? 0) + 1)
                        : $saved['modified'] ?? strtotime($item['lastModifiedDateTime']);
                    $captured = new CaptureDate()->resolve($item, $relative);
                    $taken = $captured ?? date('Y-m-d H:i:s', strtotime($item['lastModifiedDateTime']));
                    $dimensions = $item['image'] ?? ($item['video'] ?? []);
                    $priority = $saved['priority'] ?? 0;
                    $lower = '/' . mb_strtolower($relative);
                    if (
                        $priority === 0 &&
                        ($captured === null ||
                            $taken < '2025-01-01' ||
                            str_contains($lower, '/whatsapp animated gifs/') ||
                            (str_contains($lower, '/_whatsapp/') &&
                                (str_contains($lower, '/.statuses/') ||
                                    str_contains($lower, ' stickers/') ||
                                    str_ends_with($lower, '.gif'))))
                    ) {
                        $priority = -1;
                    }
                    $database
                        ->prepare(
                            "INSERT INTO photos(id,root,path,album,name,modified,bytes,width,height,taken,seen,priority) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                        ON CONFLICT(id) DO UPDATE SET root=excluded.root,name=excluded.name,album=excluded.album,seen=excluded.seen,available=1,priority=excluded.priority,
                        modified=excluded.modified,bytes=excluded.bytes,taken=excluded.taken,width=excluded.width,height=excluded.height"
                        )
                        ->execute([
                            $id,
                            'onedrive:/' . $connection['drive'],
                            $path,
                            dirname($relative) === '.' ? $items[$connection['folder']]['name'] : dirname($relative),
                            $item['name'],
                            $modified,
                            $item['size'],
                            (int) ($dimensions['width'] ?? 0),
                            (int) ($dimensions['height'] ?? 0),
                            $taken,
                            $seen,
                            $priority
                        ]);
                    if ($changed) {
                        $database->prepare('DELETE FROM onedrive_preview_fallbacks WHERE photo_id=?')->execute([$id]);
                        $database
                            ->prepare(
                                "UPDATE photos SET taken=?,status='pending',ai_tags='[]',description='',attempted=0 WHERE id=?"
                            )
                            ->execute([$taken, $id]);
                        foreach (['.jpg', '.jpg.webp'] as $suffix) {
                            $cache = $this->dataPath . '/thumbnails/' . hash('sha256', $path) . $suffix;
                            if (is_file($cache) && !unlink($cache)) {
                                throw new \RuntimeException('Veraltete Vorschau konnte nicht entfernt werden.');
                            }
                        }
                    }
                    $database
                        ->prepare(
                            'INSERT INTO onedrive_photos(photo_id,item,version,relative_path) VALUES(?,?,?,?) ON CONFLICT(photo_id) DO UPDATE SET version=excluded.version,relative_path=excluded.relative_path'
                        )
                        ->execute([$id, $itemId, $version, $relative]);
                    $progress->changed++;
                }
                // only a complete traversal proves a file was deleted or moved out of the folder
                $statement = $database->prepare('SELECT path FROM photos WHERE seen<>?');
                $statement->execute([$seen]);
                $removed = $statement->fetchAll(\PDO::FETCH_COLUMN);
                foreach (
                    [
                        'DELETE FROM faces WHERE photo_id IN (SELECT id FROM photos WHERE seen<>?)',
                        'DELETE FROM face_state WHERE photo_id IN (SELECT id FROM photos WHERE seen<>?)',
                        'DELETE FROM onedrive_photos WHERE photo_id IN (SELECT id FROM photos WHERE seen<>?)',
                        'DELETE FROM onedrive_preview_fallbacks WHERE photo_id IN (SELECT id FROM photos WHERE seen<>?)',
                        'DELETE FROM photo_metadata WHERE path IN (SELECT path FROM photos WHERE seen<>?)',
                        'DELETE FROM photos WHERE seen<>?'
                    ]
                    as $query
                ) {
                    $database->prepare($query)->execute([$seen]);
                }
                $database->exec("DELETE FROM persons WHERE name = '' AND auto_match = 1
                    AND NOT EXISTS (SELECT 1 FROM faces WHERE person_id = persons.id)
                    AND NOT EXISTS (SELECT 1 FROM person_separations WHERE person_a = persons.id OR person_b = persons.id);");
                $database->exec(
                    'DELETE FROM onedrive_items; INSERT INTO onedrive_items SELECT * FROM onedrive_pending; DELETE FROM onedrive_pending; DELETE FROM scan_state'
                );
                $database
                    ->prepare('UPDATE onedrive_state SET delta=? WHERE id=1')
                    ->execute([$page['@odata.deltaLink']]);
            }
            $database->commit();
            foreach ($removed as $path) {
                foreach (['.jpg', '.jpg.webp'] as $suffix) {
                    $cache = $this->dataPath . '/thumbnails/' . hash('sha256', $path) . $suffix;
                    if (is_file($cache) && !unlink($cache)) {
                        throw new \RuntimeException('Vorschau einer gelöschten Datei konnte nicht entfernt werden.');
                    }
                }
            }
            return $progress->changed;
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
        }
    }

    /**
     * Locate cloud-backed photos even after the local mount has been disconnected.
     */
    public function photo(int $id): ?array
    {
        $this->connection();
        $statement = $this->library->database->prepare(
            'SELECT p.*,o.item,o.version,f.photo_id AS preview_fallback FROM photos p JOIN onedrive_photos o ON o.photo_id=p.id
             LEFT JOIN onedrive_preview_fallbacks f ON f.photo_id=p.id AND f.version=o.version WHERE p.id=? AND p.available=1'
        );
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    /**
     * Populate missing caches from OneDrive previews without staging or rendering originals.
     */
    public function previews(array $ids): array
    {
        $connection = $this->connection();
        $results = [];
        $pending = [];
        $ids = array_slice($ids, 0, 100);
        foreach ($ids as $id) {
            $photo = $this->photo((int) $id);
            if ($photo === null) {
                throw new \RuntimeException('Galerie zuerst vollständig über OneDrive einlesen.');
            }
            if (
                $photo['preview_fallback'] !== null ||
                $this->library->imagePath((int) $id, cachedOnly: true) !== null
            ) {
                $results[$id] = true;
                continue;
            }
            $pending[$id] = [
                'item' => $photo['item'],
                'target' => $this->dataPath . '/thumbnails/' . hash('sha256', $photo['path']) . '.jpg',
                'bytes' => (int) $photo['bytes'],
                'version' => $photo['version']
            ];
        }
        if ($pending !== []) {
            if (
                disk_free_space($this->dataPath . '/thumbnails') <
                4 * OneDriveClient::MAX_THUMBNAIL_BYTES + 536870912
            ) {
                throw new \RuntimeException('Nicht genügend freier Speicher für Thumbnails und Arbeitsreserve.');
            }
            $locks = [];
            try {
                foreach ([0, 1] as $slot) {
                    $lock = fopen($this->dataPath . '/thumbnail-' . $slot . '.lock', 'c');
                    if ($lock === false) {
                        throw new \RuntimeException('Thumbnail-Speicher nicht verfügbar.');
                    }
                    $locks[] = $lock;
                    while (!flock($lock, LOCK_EX | LOCK_NB)) {
                        if ($this->client->cancelled) {
                            throw new JobInterrupted('Lauf abgebrochen.');
                        }
                        usleep(50000);
                    }
                }
                $this->client->thumbnails($connection['drive'], $pending, function (int $id, ?bool $saved) use (
                    &$results,
                    $ids,
                    $pending
                ): void {
                    if ($saved === null) {
                        $this->library->database
                            ->prepare(
                                'INSERT INTO onedrive_preview_fallbacks(photo_id,version) VALUES(?,?) ON CONFLICT(photo_id) DO UPDATE SET version=excluded.version'
                            )
                            ->execute([$id, $pending[$id]['version']]);
                        $this->library->jobs->log('previews', 'Foto ' . $id . ': Allgemeines Vorschaubild hinterlegt.');
                    }
                    $results[$id] = $saved !== false;
                    if ($saved === false) {
                        $message = 'Foto ' . $id . ': Keine gültige OneDrive-Vorschau verfügbar; übersprungen.';
                        $this->library->jobs->log('previews', $message);
                        if (PHP_SAPI === 'cli') {
                            echo $message . "\n";
                        }
                    }
                    $this->library->jobs->printProgress(
                        'previews',
                        phase: 'Download ' . count($results) . '/' . count($ids)
                    );
                });
            } finally {
                foreach ($locks as $lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
        ksort($results, SORT_NUMERIC);
        return $results;
    }
}
