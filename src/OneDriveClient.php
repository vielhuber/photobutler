<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

class OneDriveClient
{
    public const MAX_THUMBNAIL_BYTES = 16777216;
    public const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const THUMBNAIL_ATTEMPTS = 8;
    private const THUMBNAIL_RETRY_SECONDS = 5;
    private const THUMBNAIL_MAX_RETRY_SECONDS = 60;

    private ?\CurlHandle $handle = null;
    private ?\CurlMultiHandle $thumbnailHandle = null;
    public bool $cancelled = false;

    /**
     * Keep delegated credentials outside the public directory and serialize refreshes.
     */
    public function __construct(
        private readonly string $dataPath,
        private readonly string $clientId,
        private readonly string $tenant = 'consumers'
    ) {
        if ($clientId === '' || !preg_match('/^[a-zA-Z0-9.-]+$/D', $tenant)) {
            throw new \RuntimeException('OneDrive: Client-ID und Kontotyp konfigurieren.');
        }
    }

    /**
     * Authorize this installation without a client secret or a callback server.
     */
    public function login(): void
    {
        $endpoint = 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/';
        $response = $this->request(
            'POST',
            $endpoint . 'devicecode',
            form: [
                'client_id' => $this->clientId,
                'scope' => 'https://graph.microsoft.com/Files.Read offline_access'
            ]
        );
        $device = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || !isset($device['device_code'], $device['message'])) {
            throw new \RuntimeException('OneDrive-Anmeldung nicht verfügbar. App-Registrierung prüfen.');
        }
        echo $device['message'] . "\n";
        $deadline = time() + (int) $device['expires_in'];
        $interval = max(1, (int) ($device['interval'] ?? 5));
        while (time() < $deadline) {
            sleep($interval);
            $response = $this->request(
                'POST',
                $endpoint . 'token',
                form: [
                    'client_id' => $this->clientId,
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'device_code' => $device['device_code']
                ]
            );
            $token = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if ($response['status'] === 200 && isset($token['access_token'], $token['refresh_token'])) {
                $lock = fopen($this->dataPath . '/onedrive-token.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX)) {
                    throw new \RuntimeException('OneDrive-Zugang konnte nicht gespeichert werden.');
                }
                try {
                    $this->saveToken($token);
                } finally {
                    fclose($lock);
                }
                return;
            }
            if (($token['error'] ?? '') === 'slow_down') {
                $interval += 5;
                continue;
            }
            if (($token['error'] ?? '') !== 'authorization_pending') {
                throw new \RuntimeException('OneDrive-Anmeldung abgebrochen oder abgelaufen.');
            }
        }
        throw new \RuntimeException('OneDrive-Anmeldung abgelaufen. Erneut starten.');
    }

    /**
     * Persist rotated tokens atomically, bound to their application registration.
     */
    private function saveToken(array $token): void
    {
        $token['expires_at'] = time() + (int) $token['expires_in'];
        $token['client_id'] = $this->clientId;
        $token['tenant'] = $this->tenant;
        $path = $this->dataPath . '/onedrive-token.json';
        $temporary = $path . '.' . bin2hex(random_bytes(8));
        $oldMask = umask(0077);
        try {
            if (
                file_put_contents($temporary, json_encode($token, JSON_THROW_ON_ERROR), LOCK_EX) === false ||
                !rename($temporary, $path)
            ) {
                throw new \RuntimeException('OneDrive-Zugang konnte nicht gespeichert werden.');
            }
        } finally {
            umask($oldMask);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Reuse valid access tokens and refresh once across concurrent PHP processes.
     */
    private function token(bool $refresh = false): string
    {
        $lock = fopen($this->dataPath . '/onedrive-token.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('OneDrive-Zugang nicht verfügbar.');
        }
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                $this->checkCancelled();
                usleep(50000);
            }
            $this->checkCancelled();
            $path = $this->dataPath . '/onedrive-token.json';
            $token = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            if (
                ($token['client_id'] ?? '') !== $this->clientId ||
                ($token['tenant'] ?? '') !== $this->tenant ||
                empty($token['refresh_token'])
            ) {
                throw new \RuntimeException('OneDrive erneut anmelden: --onedrive-login.');
            }
            if (!$refresh && ($token['expires_at'] ?? 0) > time() + 60) {
                return $token['access_token'];
            }
            $response = $this->request(
                'POST',
                'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/token',
                form: [
                    'client_id' => $this->clientId,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token['refresh_token']
                ]
            );
            $updated = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if ($response['status'] !== 200 || !isset($updated['access_token'])) {
                throw new \RuntimeException('OneDrive-Zugang abgelaufen oder gesperrt. --onedrive-login ausführen.');
            }
            $updated['refresh_token'] ??= $token['refresh_token'];
            $this->saveToken($updated);
            return $updated['access_token'];
        } finally {
            fclose($lock);
        }
    }

    /**
     * Follow opaque Graph pagination links without disclosing tokens to other hosts.
     */
    public function get(string $path): array
    {
        $url = str_starts_with($path, '/') ? self::GRAPH . $path : $path;
        if (!str_starts_with($url, self::GRAPH . '/')) {
            throw new \RuntimeException('Ungültige OneDrive-Folgeadresse.');
        }
        return $this->graphRequest('GET', $url);
    }

    /**
     * Apply the same delegated authentication and retry policy to GET and JSON batches.
     */
    private function graphRequest(string $method, string $url, array|string $form = []): array
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->checkCancelled();
            $response = $this->request(
                $method,
                $url,
                headers: [
                    'Authorization: Bearer ' . $this->token($attempt === 1 && ($response['status'] ?? 0) === 401)
                ],
                form: $form
            );
            if ($response['status'] === 200) {
                return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            }
            if ($response['status'] === 401 && $attempt === 0) {
                continue;
            }
            if (in_array($response['status'], [429, 503], true) && $attempt < 3) {
                $this->waitForRetry((int) ($response['headers']['retry-after'] ?? 2 ** $attempt));
                continue;
            }
            throw new \RuntimeException(
                'OneDrive-Anfrage fehlgeschlagen (HTTP ' . $response['status'] . ').',
                $response['status']
            );
        }
        throw new \RuntimeException('OneDrive nicht verfügbar.');
    }

    /**
     * Save the provider's preview atomically; null means no preview is available, false means a failure.
     */
    public function thumbnail(string $drive, string $item, string $target, int $bytes, string $version): ?bool
    {
        $metadata = $this->get('/drives/' . rawurlencode($drive) . '/items/' . rawurlencode($item));
        $this->validateVersion($metadata, $bytes, $version);
        try {
            $thumbnails = $this->get(
                '/drives/' . rawurlencode($drive) . '/items/' . rawurlencode($item) . '/thumbnails'
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 406) {
                return null;
            }
            throw $exception;
        }
        $url = $thumbnails['value'][0]['large']['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        $response = $this->thumbnailTransfers([0 => $url])[0];
        return $this->saveThumbnail($response, $target);
    }

    /**
     * Reject a changed remote file before associating its preview with an old catalog version.
     */
    private function validateVersion(array $metadata, int $bytes, string $version): void
    {
        $currentVersion = match (substr($version, 0, 2)) {
            'c:' => 'c:' . ($metadata['cTag'] ?? ''),
            'e:' => 'e:' . ($metadata['eTag'] ?? ''),
            default => 'd:' . ($metadata['lastModifiedDateTime'] ?? '') . ':' . ($metadata['size'] ?? '')
        };
        if ((int) ($metadata['size'] ?? -1) !== $bytes || $currentVersion !== $version) {
            throw new \RuntimeException('OneDrive-Datei geändert. Galerie erneut einlesen.');
        }
    }

    /**
     * Batch twenty metadata requests and fetch at most four signed previews concurrently.
     *
     * @param array<int, array{item: string, target: string, bytes: int, version: string}> $photos
     * @return array<int, bool|null>
     */
    public function thumbnails(string $drive, array $photos, ?callable $progress = null): array
    {
        $results = [];
        foreach (array_chunk($photos, 10, true) as $group) {
            $this->checkCancelled();
            $requests = [];
            foreach ($group as $id => $photo) {
                $path = '/drives/' . rawurlencode($drive) . '/items/' . rawurlencode($photo['item']);
                $requests[] = ['id' => $id . '-metadata', 'method' => 'GET', 'url' => $path];
                $requests[] = ['id' => $id . '-thumbnail', 'method' => 'GET', 'url' => $path . '/thumbnails'];
            }
            $responses = [];
            for ($attempt = 0; $requests !== []; $attempt++) {
                $batch = $this->graphRequest(
                    'POST',
                    self::GRAPH . '/$batch',
                    json_encode(['requests' => $requests], JSON_THROW_ON_ERROR)
                );
                $indexed = [];
                foreach ($batch['responses'] ?? [] as $response) {
                    if (!isset($response['id'], $response['status']) || isset($indexed[$response['id']])) {
                        throw new \RuntimeException('Ungültige OneDrive-Batchantwort.');
                    }
                    $indexed[$response['id']] = $response;
                }
                $retry = [];
                $delay = 0;
                $refresh = false;
                foreach ($requests as $request) {
                    $response = $indexed[$request['id']] ?? null;
                    if ($response === null) {
                        throw new \RuntimeException('Unvollständige OneDrive-Batchantwort.');
                    }
                    $status = (int) $response['status'];
                    if (in_array($status, [401, 429, 503, 504], true)) {
                        if ($attempt >= 3) {
                            throw new \RuntimeException('OneDrive-Batch vorübergehend nicht verfügbar.', $status);
                        }
                        $retry[] = $request;
                        $refresh = $refresh || $status === 401;
                        $headers = array_change_key_case($response['headers'] ?? []);
                        $delay = max($delay, (int) ($headers['retry-after'] ?? 2 ** $attempt));
                        continue;
                    }
                    if (!in_array($status, [200, 404, 406], true)) {
                        throw new \RuntimeException(
                            'OneDrive-Batchanfrage fehlgeschlagen (HTTP ' . $status . ').',
                            $status
                        );
                    }
                    $responses[$request['id']] = $response;
                }
                $requests = $retry;
                if ($refresh) {
                    $this->token(true);
                }
                if ($requests !== []) {
                    $this->waitForRetry($delay);
                }
            }
            $urls = [];
            foreach ($group as $id => $photo) {
                $metadata = $responses[$id . '-metadata'];
                $thumbnail = $responses[$id . '-thumbnail'];
                if ((int) $metadata['status'] === 200) {
                    $this->validateVersion($metadata['body'], $photo['bytes'], $photo['version']);
                }
                $url = $thumbnail['body']['value'][0]['large']['url'] ?? null;
                if (
                    (int) $metadata['status'] !== 200 ||
                    (int) $thumbnail['status'] !== 200 ||
                    !is_string($url) ||
                    $url === ''
                ) {
                    $results[$id] =
                        (int) $metadata['status'] === 200 && in_array((int) $thumbnail['status'], [200, 406], true)
                            ? null
                            : false;
                    if ($progress !== null) {
                        $progress($id, $results[$id]);
                    }
                    continue;
                }
                $urls[$id] = $url;
            }
            foreach (array_chunk($urls, 4, true) as $wave) {
                foreach ($this->thumbnailTransfers($wave) as $id => $response) {
                    $this->checkCancelled();
                    $results[$id] = $this->saveThumbnail($response, $group[$id]['target']);
                    if ($progress !== null) {
                        $progress($id, $results[$id]);
                    }
                }
            }
        }
        ksort($results, SORT_NUMERIC);
        return $results;
    }

    /**
     * Keep signed downloads credential-free, bounded and interruptible, including retries.
     */
    protected function thumbnailTransfers(array $urls): array
    {
        if (count($urls) > 4) {
            throw new \InvalidArgumentException('Höchstens vier Thumbnail-Downloads gleichzeitig.');
        }
        $responses = [];
        for ($attempt = 0; $urls !== []; $attempt++) {
            $this->checkCancelled();
            $multi = $this->thumbnailHandle ??= curl_multi_init();
            $transfers = [];
            try {
                foreach ($urls as $id => $url) {
                    $this->validateUrl($url);
                    $handle = curl_init($url);
                    $transfers[$id] = ['handle' => $handle, 'body' => '', 'headers' => [], 'status' => 0];
                    curl_setopt_array($handle, [
                        CURLOPT_CONNECTTIMEOUT => 20,
                        CURLOPT_TIMEOUT => 90,
                        CURLOPT_PROTOCOLS_STR => 'https',
                        CURLOPT_REDIR_PROTOCOLS_STR => 'https',
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 3,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_XFERINFOFUNCTION => fn(): int => (int) $this->cancelled,
                        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$transfers, $id): int {
                            if (preg_match('~^HTTP/\S+ (\d+)~', $line, $match)) {
                                $transfers[$id]['status'] = (int) $match[1];
                                $transfers[$id]['headers'] = [];
                                $transfers[$id]['body'] = '';
                            }
                            if (str_contains($line, ':')) {
                                [$key, $value] = explode(':', trim($line), 2);
                                $transfers[$id]['headers'][strtolower($key)] = trim($value);
                            }
                            return strlen($line);
                        },
                        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$transfers, $id): int {
                            if ($transfers[$id]['status'] !== 200) {
                                return strlen($chunk);
                            }
                            if (strlen($transfers[$id]['body']) + strlen($chunk) > self::MAX_THUMBNAIL_BYTES) {
                                return 0;
                            }
                            $transfers[$id]['body'] .= $chunk;
                            return strlen($chunk);
                        }
                    ]);
                    curl_multi_add_handle($multi, $handle);
                }
                do {
                    $this->checkCancelled();
                    $status = curl_multi_exec($multi, $running);
                    if ($status !== CURLM_OK) {
                        throw new \RuntimeException('Paralleler Thumbnail-Download fehlgeschlagen.');
                    }
                    if ($running > 0 && curl_multi_select($multi, 0.2) === -1) {
                        usleep(10000);
                    }
                } while ($running > 0);
                $this->checkCancelled();
                $retry = [];
                $delay = 0;
                $retryStatuses = [];
                foreach ($transfers as $id => $transfer) {
                    if (in_array($transfer['status'], [429, 503, 504], true)) {
                        if ($attempt + 1 >= self::THUMBNAIL_ATTEMPTS) {
                            throw new \RuntimeException(
                                'OneDrive-Thumbnail nach ' .
                                    self::THUMBNAIL_ATTEMPTS .
                                    ' Versuchen nicht verfügbar (HTTP ' .
                                    $transfer['status'] .
                                    '). Später fortsetzen.',
                                $transfer['status']
                            );
                        }
                        $retry[$id] = $urls[$id];
                        $retryStatuses[] = $transfer['status'];
                        $delay = max(
                            $delay,
                            (int) ($transfer['headers']['retry-after'] ??
                                min(self::THUMBNAIL_MAX_RETRY_SECONDS, self::THUMBNAIL_RETRY_SECONDS * 2 ** $attempt))
                        );
                        continue;
                    }
                    $responses[$id] = [
                        'status' => curl_errno($transfer['handle']) === 0 ? $transfer['status'] : 0,
                        'body' => $transfer['body']
                    ];
                }
            } finally {
                foreach ($transfers as $transfer) {
                    curl_multi_remove_handle($multi, $transfer['handle']);
                    curl_reset($transfer['handle']);
                }
                unset($transfers, $transfer, $handle);
            }
            $urls = $retry;
            if ($urls !== []) {
                if (PHP_SAPI === 'cli' && $delay <= 120) {
                    echo 'OneDrive HTTP ' .
                        implode('/', array_unique($retryStatuses)) .
                        ': Warte ' .
                        max(1, $delay) .
                        ' Sek.; Wiederholung ' .
                        ($attempt + 1) .
                        '/' .
                        (self::THUMBNAIL_ATTEMPTS - 1) .
                        ".\n";
                }
                $this->waitForRetry($delay);
            }
        }
        return $responses;
    }

    /**
     * Distinguish unavailable previews from failures; only decoded JPEG/PNG data enters the cache.
     */
    private function saveThumbnail(array $response, string $target): ?bool
    {
        if ($response['status'] === 406) {
            return null;
        }
        if ($response['status'] !== 200) {
            return false;
        }
        $partial = $target . '.part';
        if (is_link($target) || is_link($partial)) {
            throw new \RuntimeException('Thumbnail-Speicher darf keine Verknüpfung sein.');
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $dimensions = getimagesizefromstring($response['body']);
            if (
                $dimensions === false ||
                !in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ||
                $dimensions[0] * $dimensions[1] > 60000000
            ) {
                return false;
            }
            $image = imagecreatefromstring($response['body']);
            if ($image === false) {
                return false;
            }
            unset($image);
            $this->checkCancelled();
            if (file_put_contents($partial, $response['body']) !== strlen($response['body'])) {
                return false;
            }
            $this->checkCancelled();
            return rename($partial, $target);
        } catch (\ErrorException) {
            return false;
        } finally {
            restore_error_handler();
            if (is_file($partial)) {
                unlink($partial);
            }
        }
    }

    /**
     * Preserve provider backoff while allowing signals to interrupt the wait.
     */
    private function waitForRetry(int $seconds): void
    {
        $this->checkCancelled();
        if ($seconds > 120) {
            throw new \RuntimeException(
                'OneDrive verlangt eine längere Wartezeit (' . $seconds . ' Sek.). Später fortsetzen.'
            );
        }
        $deadline = hrtime(true) + max(1, $seconds) * 1e9;
        do {
            usleep((int) min(200000, max(0, ($deadline - hrtime(true)) / 1000)));
            $this->checkCancelled();
        } while (hrtime(true) < $deadline);
    }

    /**
     * Unwind transfers and locks instead of waiting for the whole CLI step.
     */
    private function checkCancelled(): void
    {
        if ($this->cancelled) {
            throw new JobInterrupted('Lauf abgebrochen.');
        }
    }

    /**
     * Reject non-HTTPS endpoints and embedded credentials for every transport.
     */
    private function validateUrl(string $url): void
    {
        if (
            parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            parse_url($url, PHP_URL_USER) !== null ||
            parse_url($url, PHP_URL_PASS) !== null
        ) {
            throw new \RuntimeException('Ungültige OneDrive-Adresse.');
        }
    }

    /**
     * Stream original bytes without creating a second disk cache or forwarding Graph credentials.
     */
    public function stream(string $drive, string $item, string $name, bool $download): void
    {
        $metadata = $this->get('/drives/' . rawurlencode($drive) . '/items/' . rawurlencode($item));
        header('Cache-Control: ' . ($download ? 'no-store' : 'private, no-cache'));
        header('Vary: Cookie');
        $etag = '"' . hash('sha256', $drive . ':' . $item . ':' . ($metadata['eTag'] ?? '')) . '"';
        header('ETag: ' . $etag);
        $conditions = array_map(
            static fn(string $value): string => preg_replace('/^W\//', '', trim($value)),
            explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '')
        );
        if (!$download && (in_array($etag, $conditions, true) || in_array('*', $conditions, true))) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . ($metadata['file']['mimeType'] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        if ($download) {
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        }
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (isset($_SERVER['HTTP_IF_RANGE']) && $_SERVER['HTTP_IF_RANGE'] !== $etag) {
            $range = '';
        }
        if ($range !== '' && !preg_match('/^bytes=(?:[0-9]+-[0-9]*|-[0-9]+)$/D', $range)) {
            http_response_code(416);
            header('Content-Range: bytes */' . $metadata['size']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            header('Content-Length: ' . $metadata['size']);
            return;
        }
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        try {
            $response = $this->request(
                'GET',
                $metadata['@microsoft.graph.downloadUrl'] ?? '',
                headers: $range === '' ? [] : ['Range: ' . $range],
                target: 'php://output'
            );
            if (!in_array($response['status'], [200, 206, 416], true) && !headers_sent()) {
                http_response_code(502);
            }
        } catch (\RuntimeException) {
            if (!headers_sent()) {
                http_response_code(502);
            }
            error_log('OneDrive-Originalübertragung unterbrochen.');
        }
    }

    /**
     * Centralize bounded HTTPS transfers; redirects never inherit authorization headers.
     */
    protected function request(
        string $method,
        string $url,
        array $headers = [],
        array|string $form = [],
        ?string $target = null,
        int $maximumBytes = PHP_INT_MAX
    ): array {
        $this->checkCancelled();
        $this->validateUrl($url);
        $handle = $this->handle ??= curl_init();
        curl_reset($handle);
        $status = 0;
        $responseHeaders = [];
        $received = 0;
        $body = '';
        $stream = $target !== null ? fopen($target, 'wb') : null;
        if ($target !== null && $stream === false) {
            throw new \RuntimeException('Downloadspeicher nicht verfügbar.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => is_string($form) ? [...$headers, 'Content-Type: application/json'] : $headers,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => fn(): int => (int) $this->cancelled,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => $target === null ? 90 : 600,
                CURLOPT_PROTOCOLS_STR => 'https',
                CURLOPT_REDIR_PROTOCOLS_STR => 'https',
                CURLOPT_FOLLOWLOCATION => $target !== null,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (
                    &$status,
                    &$responseHeaders,
                    $target
                ): int {
                    if (preg_match('~^HTTP/\S+ (\d+)~', $line, $match)) {
                        $status = (int) $match[1];
                        $responseHeaders = [];
                    }
                    if (str_contains($line, ':')) {
                        [$key, $value] = explode(':', trim($line), 2);
                        $responseHeaders[strtolower($key)] = trim($value);
                    }
                    if (trim($line) === '' && $target === 'php://output' && in_array($status, [200, 206, 416], true)) {
                        http_response_code($status);
                        if ($status === 416) {
                            header('Content-Length: 0');
                        }
                        foreach (
                            $status === 416 ? ['content-range'] : ['content-length', 'content-range', 'accept-ranges']
                            as $key
                        ) {
                            if (isset($responseHeaders[$key])) {
                                header($key . ': ' . $responseHeaders[$key]);
                            }
                        }
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
                    &$body,
                    &$received,
                    &$status,
                    $maximumBytes,
                    $stream,
                    $target
                ): int {
                    if ($target !== null && !in_array($status, [200, 206], true)) {
                        return strlen($chunk);
                    }
                    $received += strlen($chunk);
                    if (
                        $received > ($target === null ? 16777216 : $maximumBytes) ||
                        ($target === 'php://output' && connection_aborted())
                    ) {
                        return 0;
                    }
                    if ($stream !== null) {
                        return fwrite($stream, $chunk);
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }
            ]);
            if ($form !== []) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($form) ? $form : http_build_query($form));
            }
            if (curl_exec($handle) === false) {
                $this->checkCancelled();
                throw new \RuntimeException('OneDrive-Übertragung unterbrochen.');
            }
            $this->checkCancelled();
            return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
