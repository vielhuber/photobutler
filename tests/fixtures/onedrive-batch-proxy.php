<?php
declare(strict_types=1);

require __DIR__ . '/FixtureHttpsServer.php';

$directory = $argv[1];
$mode = json_decode(file_get_contents($directory . '/configuration'), true, flags: JSON_THROW_ON_ERROR)['mode'];
$server = new FixtureHttpsServer($directory, true, 'graph.microsoft.com', ['DNS:graph.microsoft.com', 'IP:127.0.0.1']);
$active = 0;
$maximum = 0;
$attempts = [];
$server->run(static function (array $request) use ($directory, $mode, &$active, &$maximum, &$attempts): ?array {
    if ($request['url'] === '/v1.0/$batch') {
        $batch = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($directory . '/batches', json_encode($batch) . "\n", FILE_APPEND);
        if ($mode === 'graph-stall') {
            file_put_contents($directory . '/ready', '');
            return null;
        }
        $responses = [];
        foreach ($batch['requests'] as $entry) {
            [$id, $kind] = explode('-', $entry['id']);
            $responses[] = [
                'id' => $entry['id'],
                'status' => $mode === 'fallback' && $entry['id'] === '3-thumbnail' ? 406 : 200,
                'body' =>
                    $kind === 'metadata'
                        ? ['size' => 3000000000, 'cTag' => 'v1']
                        : [
                            'value' =>
                                $mode === 'fallback' && $id === '2'
                                    ? []
                                    : [['large' => ['url' => 'https://127.0.0.1/preview/' . $id]]]
                        ]
            ];
        }
        return [
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['responses' => array_reverse($responses)], JSON_UNESCAPED_SLASHES)
        ];
    }
    $id = (int) basename($request['url']);
    $entry = ['id' => $id];
    if (isset($request['headers']['authorization'])) {
        $entry['authorization'] = $request['headers']['authorization'];
    }
    $entry['port'] = $request['port'];
    $entry['time'] = (int) round(microtime(true) * 1000);
    file_put_contents($directory . '/requests', json_encode($entry) . "\n", FILE_APPEND);
    $attempts[$id] = ($attempts[$id] ?? 0) + 1;
    $active++;
    $maximum = max($maximum, $active);
    file_put_contents($directory . '/maximum', (string) $maximum);
    $sent = static function () use (&$active): void {
        $active--;
    };
    $preview = file_get_contents($directory . '/preview');
    if ($mode === 'download-stall' && $id > 4) {
        file_put_contents($directory . '/ready', '');
        return [
            'headers' => ['Content-Type: image/jpeg'],
            'body' => substr($preview, 0, 50),
            'length' => strlen($preview),
            'stall' => true
        ];
    }
    if ($mode === 'backoff') {
        file_put_contents($directory . '/ready', '');
        return ['status' => 429, 'headers' => ['Retry-After: 30'], 'sent' => $sent];
    }
    if ($id === 2 && (($mode === 'retry-long' && $attempts[$id] <= 4) || $mode === 'retry-exhausted')) {
        return ['status' => $mode === 'retry-long' ? 503 : 504, 'headers' => ['Retry-After: 1'], 'sent' => $sent];
    }
    if ($mode === 'retry' && $id === 2 && $attempts[$id] === 1) {
        return ['status' => 429, 'headers' => ['Retry-After: 1'], 'sent' => $sent];
    }
    if ($mode === 'fallback' && $id === 4) {
        return ['status' => 406, 'sent' => $sent];
    }
    if ($mode === 'invalid' && $id === 2) {
        return ['body' => 'not an image', 'sent' => $sent];
    }
    if ($mode === 'oversized' && $id === 2) {
        return ['body' => str_repeat("\0", 16777217), 'sent' => $sent];
    }
    return ['body' => $preview, 'delay' => 0.1, 'sent' => $sent];
});
