<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OneDriveHttpTest extends TestCase
{
    public function testOriginalStreamingRangesConditionalRequestsAndHeadWithoutDiskCache(): void
    {
        $root = sys_get_temp_dir() . '/photobutler-cloud-http-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $upstream = $server = null;
        try {
            file_put_contents(
                $root . '/upstream.php',
                '<?php declare(strict_types=1); require ' .
                    var_export(__DIR__ . '/fixtures/FixtureHttpsServer.php', true) .
                    ';' .
                    <<<'PHP'
                    $directory = $argv[1];
                    $server = new FixtureHttpsServer($directory, false, 'localhost', ['IP:127.0.0.1']);
                    $server->run(static function (array $request) use ($directory, $server): array {
                        $entry = ['url' => $request['url']];
                        foreach (['authorization', 'range'] as $header) {
                            if (isset($request['headers'][$header])) {
                                $entry[$header] = $request['headers'][$header];
                            }
                        }
                        $entry['method'] = $request['method'];
                        $entry['port'] = $request['port'];
                        file_put_contents($directory . '/requests', json_encode($entry) . "\n", FILE_APPEND);
                        if (str_starts_with($request['url'], '/metadata')) {
                            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
                            $metadata = ['id' => 'photo', 'size' => 10, 'eTag' => 'v1', 'file' => ['mimeType' => 'image/jpeg']];
                            if (!isset($query['$select'])) {
                                $metadata['@microsoft.graph.downloadUrl'] = 'https://127.0.0.1:' . $server->port . '/content';
                            }
                            return ['headers' => ['Content-Type: application/json'], 'body' => json_encode($metadata)];
                        }
                        $range = $request['headers']['range'] ?? '';
                        if ($range === 'bytes=20-30') {
                            return ['status' => 416, 'headers' => ['Content-Range: bytes */10'], 'body' => 'upstream error body'];
                        }
                        if ($range !== '') {
                            return ['status' => 206, 'headers' => ['Content-Range: bytes 2-5/10', 'Accept-Ranges: bytes'], 'body' => '2345'];
                        }
                        return ['headers' => ['Accept-Ranges: bytes'], 'body' => '0123456789'];
                    });
                    PHP
            );
            $upstream = proc_open(
                [PHP_BINARY, $root . '/upstream.php', $root],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', $root . '/upstream.log', 'w']
                ],
                $pipes
            );
            for ($attempt = 0; $attempt < 100 && !is_file($root . '/port'); $attempt++) {
                usleep(50000);
                clearstatcache();
            }
            $this->assertFileExists($root . '/port');
            $port = trim(file_get_contents($root . '/port'));
            $script =
                '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; ';
            $script .=
                'class StreamFixtureClient extends \\vielhuber\\photobutler\\OneDriveClient { public function get(string $path): array { $response=$this->request("GET",' .
                var_export('https://127.0.0.1:' . $port . '/metadata', true) .
                '.(str_contains($path,"?") ? substr($path,strpos($path,"?")) : "")); return json_decode($response["body"],true,flags:JSON_THROW_ON_ERROR); } }';
            $script .=
                '(new StreamFixtureClient(__DIR__,"fixture"))->stream("drive","photo","original.jpg",isset($_GET["download"]));';
            file_put_contents($root . '/index.php', $script);
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $server = proc_open(
                [PHP_BINARY, '-d', 'curl.cainfo=' . $root . '/certificate.pem', '-S', $address, '-t', $root],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $root . '/server.log', 'w'],
                    2 => ['file', $root . '/server.log', 'a']
                ],
                $pipes
            );
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $handle = curl_init('http://' . $address . '/');
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
                curl_exec($handle);
                if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200) {
                    break;
                }
                usleep(50000);
            }
            $etag = '"' . hash('sha256', 'drive:photo:v1') . '"';
            foreach (
                [
                    [[], false, false, 200, '0123456789'],
                    [['Range: bytes=2-5'], false, false, 206, '2345'],
                    [['Range: bytes=20-30'], false, false, 416, ''],
                    [['Range: invalid'], false, false, 416, ''],
                    [['If-None-Match: W/' . $etag], false, false, 304, ''],
                    [['If-None-Match: "old", ' . $etag], false, false, 304, ''],
                    [['If-None-Match: *'], false, false, 304, ''],
                    [[], true, false, 200, ''],
                    [['If-None-Match: ' . $etag], false, true, 200, '0123456789']
                ]
                as [$headers, $head, $download, $expectedStatus, $expectedBody]
            ) {
                $received = [];
                $handle = curl_init('http://' . $address . '/' . ($download ? '?download=1' : ''));
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_NOBODY => $head,
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                        $received[] = trim($line);
                        return strlen($line);
                    }
                ]);
                $body = curl_exec($handle);
                $this->assertSame(
                    $expectedStatus,
                    curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    file_get_contents($root . '/server.log')
                );
                $this->assertSame($expectedBody, $body);
                if ($download) {
                    $this->assertContains('Cache-Control: no-store', $received);
                }
            }
            $requests = array_map(
                static fn(string $line): array => json_decode($line, true),
                file($root . '/requests', FILE_IGNORE_NEW_LINES)
            );
            $contents = array_values(
                array_filter($requests, static fn(array $request): bool => $request['url'] === '/content')
            );
            $this->assertCount(5, $contents);
            foreach ($contents as $request) {
                $this->assertArrayNotHasKey('authorization', $request);
            }
            $reuse =
                '<?php declare(strict_types=1); require ' .
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
                '; ';
            $reuse .=
                '$client = new \\vielhuber\\photobutler\\OneDriveClient(__DIR__, "fixture");
                $request = new ReflectionMethod($client, "request");
                $url = ' .
                var_export('https://127.0.0.1:' . $port, true) .
                ';
                $request->invoke($client, "POST", $url . "/metadata", ["Authorization: Bearer fixture"], ["value" => "fixture"]);
                $partial = $request->invoke($client, "GET", $url . "/content", ["Range: bytes=2-5"]);
                $complete = $request->invoke($client, "GET", $url . "/content");
                echo json_encode([$partial["body"], $complete["body"]]);';
            file_put_contents($root . '/reuse.php', $reuse);
            $process = proc_open(
                [PHP_BINARY, '-d', 'curl.cainfo=' . $root . '/certificate.pem', $root . '/reuse.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/reuse.log', 'w']],
                $pipes
            );
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame(['2345', '0123456789'], json_decode($output, true));
            $reused = array_map(
                static fn(string $line): array => json_decode($line, true),
                array_slice(file($root . '/requests', FILE_IGNORE_NEW_LINES), -3)
            );
            $this->assertSame(['POST', 'GET', 'GET'], array_column($reused, 'method'));
            $this->assertArrayNotHasKey('authorization', $reused[1]);
            $this->assertArrayNotHasKey('authorization', $reused[2]);
            $this->assertArrayNotHasKey('range', $reused[2]);
            $this->assertCount(1, array_unique(array_column($reused, 'port')));
            $this->assertSame([], glob($root . '/*.part'));
        } finally {
            foreach ([$server, $upstream] as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach (glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
