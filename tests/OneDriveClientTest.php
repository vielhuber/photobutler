<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\OneDriveClient;

final class FixtureGraphTransport extends OneDriveClient
{
    public array $responses = [];
    public array $calls = [];

    protected function thumbnailTransfers(array $urls): array
    {
        $responses = [];
        foreach ($urls as $id => $url) {
            $response = $this->request('GET', $url, maximumBytes: self::MAX_THUMBNAIL_BYTES);
            $responses[$id] = ['status' => $response['status'], 'body' => $response['content'] ?? ''];
        }
        return $responses;
    }

    protected function request(
        string $method,
        string $url,
        array $headers = [],
        array|string $form = [],
        ?string $target = null,
        int $maximumBytes = PHP_INT_MAX
    ): array {
        $this->calls[] = compact('method', 'url', 'headers', 'form', 'target', 'maximumBytes');
        if ($url === '') {
            throw new RuntimeException('Missing download URL');
        }
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('Unexpected HTTP fixture request');
        }
        if (str_contains($url, '?$select=')) {
            $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            unset($body['@microsoft.graph.downloadUrl']);
            $response['body'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($target !== null && $target !== 'php://output') {
            file_put_contents($target, $response['content'] ?? '');
        }
        return $response;
    }
}

final class OneDriveClientTest extends TestCase
{
    private string $root;
    private FixtureGraphTransport $client;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-graph-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        file_put_contents(
            $this->root . '/onedrive-token.json',
            json_encode([
                'client_id' => 'fixture',
                'tenant' => 'consumers',
                'access_token' => 'fixture-access',
                'refresh_token' => 'fixture-refresh',
                'expires_at' => time() + 3600
            ])
        );
        $this->client = new FixtureGraphTransport($this->root, 'fixture');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => [], 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
    }

    public function testGraphTokensNeverFollowPaginationToAnotherHost(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $this->client->get('https://example.com/v1.0/next');
        } finally {
            $this->assertSame([], $this->client->calls);
        }
    }

    public function testUnauthorizedResponseRefreshesTokenAndPersistsRotationPrivately(): void
    {
        $this->client->responses = [
            $this->response(401, []),
            $this->response(200, [
                'access_token' => 'rotated-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in' => 3600
            ]),
            $this->response(200, ['id' => 'drive'])
        ];
        $this->assertSame(['id' => 'drive'], $this->client->get('/me/drive'));
        $this->assertSame('POST', $this->client->calls[1]['method']);
        $this->assertSame(['Authorization: Bearer rotated-access'], $this->client->calls[2]['headers']);
        $token = json_decode(file_get_contents($this->root . '/onedrive-token.json'), true);
        $this->assertSame('rotated-refresh', $token['refresh_token']);
        $this->assertSame(0600, fileperms($this->root . '/onedrive-token.json') & 0777);
    }

    public function testRevokedConsentStopsWithoutExposingProviderBody(): void
    {
        $this->client->responses = [
            $this->response(401, []),
            $this->response(400, ['error' => 'invalid_grant', 'error_description' => 'fixture-private-value'])
        ];
        try {
            $this->client->get('/me/drive');
            $this->fail('Expected revoked consent');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--onedrive-login', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-private-value', $exception->getMessage());
        }
    }

    #[TestWith(['jpeg'])]
    #[TestWith(['png'])]
    public function testProviderThumbnailIsSavedUnchangedWithoutOriginalDownloadOrBearer(string $format): void
    {
        ob_start();
        $image = imagecreatetruecolor(800, 533);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 40, 80, 120, 64));
        ('image' . $format)($image);
        $preview = ob_get_clean();
        $this->client->responses = [
            $this->response(200, ['size' => 3000000000, 'cTag' => 'v1']),
            $this->response(200, ['value' => [['large' => ['url' => 'https://example.com/preview']]]]),
            ['status' => 200, 'headers' => [], 'body' => '', 'content' => $preview]
        ];
        $this->assertTrue($this->client->thumbnail('drive', 'photo', $this->root . '/preview', 3000000000, 'c:v1'));
        $this->assertSame($preview, file_get_contents($this->root . '/preview'));
        $this->assertSame([], $this->client->calls[2]['headers']);
        $this->assertSame(16777216, $this->client->calls[2]['maximumBytes']);
        $this->assertStringEndsWith('/thumbnails', $this->client->calls[1]['url']);
        $this->assertFileDoesNotExist($this->root . '/preview.part');
    }

    public function testMissingFailedAndInvalidThumbnailsNeverBecomeCaches(): void
    {
        foreach ([null, 406, 504, 200] as $status) {
            $this->client->responses = [
                $this->response(200, ['size' => 3, 'cTag' => 'v1']),
                $this->response(200, [
                    'value' => $status === null ? [] : [['large' => ['url' => 'https://example.com/preview']]]
                ])
            ];
            if ($status !== null) {
                $this->client->responses[] = [
                    'status' => $status,
                    'headers' => [],
                    'body' => '',
                    'content' => 'invalid image'
                ];
            }
            $this->assertSame(
                $status === null || $status === 406 ? null : false,
                $this->client->thumbnail('drive', 'photo', $this->root . '/preview', 3, 'c:v1')
            );
            $this->assertFileDoesNotExist($this->root . '/preview');
            $this->assertFileDoesNotExist($this->root . '/preview.part');
        }
    }

    public function testUnavailableListingIsNotATransferFailure(): void
    {
        $this->client->responses = [$this->response(200, ['size' => 3, 'cTag' => 'v1']), $this->response(406, [])];
        $this->assertNull($this->client->thumbnail('drive', 'photo', $this->root . '/preview', 3, 'c:v1'));
        $this->assertCount(2, $this->client->calls);
        $this->assertFileDoesNotExist($this->root . '/preview');
    }

    public function testReplacedOriginalCannotPopulateOldVersionThumbnailCache(): void
    {
        $this->client->responses = [$this->response(200, ['size' => 3, 'cTag' => 'v2'])];
        $this->expectException(RuntimeException::class);
        try {
            $this->client->thumbnail('drive', 'photo', $this->root . '/preview', 3, 'c:v1');
        } finally {
            $this->assertCount(1, $this->client->calls);
            $this->assertFileDoesNotExist($this->root . '/preview');
        }
    }

    public function testLongThrottleDelayStopsRatherThanRetryingEarly(): void
    {
        $this->client->responses = [['status' => 429, 'headers' => ['retry-after' => '180'], 'body' => '{}']];
        $this->expectException(RuntimeException::class);
        try {
            $this->client->get('/me/drive');
        } finally {
            $this->assertCount(1, $this->client->calls);
        }
    }
    public function testBatchesMapUnorderedResponsesAndSplitAtTwentyRequests(): void
    {
        $photos = [];
        $progress = [];
        ob_start();
        imagejpeg(imagecreatetruecolor(16, 12));
        $jpeg = ob_get_clean();
        foreach (range(1, 23) as $id) {
            $photos[$id] = [
                'item' => 'item-' . $id,
                'target' => $this->root . '/preview-' . $id,
                'bytes' => 3000000000,
                'version' => 'c:v1'
            ];
        }
        foreach (array_chunk($photos, 10, true) as $group) {
            $responses = [];
            foreach ($group as $id => $photo) {
                $responses[] = [
                    'id' => $id . '-metadata',
                    'status' => 200,
                    'body' => ['size' => 3000000000, 'cTag' => 'v1']
                ];
                $responses[] = [
                    'id' => $id . '-thumbnail',
                    'status' => 200,
                    'body' => ['value' => [['large' => ['url' => 'https://example.com/preview-' . $id]]]]
                ];
            }
            $this->client->responses[] = $this->response(200, ['responses' => array_reverse($responses)]);
            foreach ($group as $id => $photo) {
                $this->client->responses[] = ['status' => 200, 'content' => $jpeg . (string) $id];
            }
        }
        $this->assertSame(
            array_fill_keys(range(1, 23), true),
            $this->client->thumbnails('drive', $photos, static function (int $id, bool $saved) use (&$progress): void {
                $progress[$id] = $saved;
            })
        );
        $this->assertSame(array_fill_keys(range(1, 23), true), $progress);
        $batches = array_values(
            array_filter($this->client->calls, static fn(array $call): bool => $call['method'] === 'POST')
        );
        $this->assertCount(3, $batches);
        $this->assertSame(
            [20, 20, 6],
            array_map(static fn(array $call): int => count(json_decode($call['form'], true)['requests']), $batches)
        );
        foreach ($batches as $batch) {
            $this->assertSame(OneDriveClient::GRAPH . '/$batch', $batch['url']);
            foreach (json_decode($batch['form'], true)['requests'] as $request) {
                $this->assertSame('GET', $request['method']);
                $this->assertStringStartsWith('/drives/drive/items/item-', $request['url']);
                $this->assertStringNotContainsString('/content', $request['url']);
            }
        }
        foreach ($photos as $id => $photo) {
            $this->assertSame($jpeg . (string) $id, file_get_contents($photo['target']));
        }
    }

    public function testBatchRetriesOnlyThrottledSubrequestAndContinuesMissingPreviews(): void
    {
        $this->client->responses = [
            $this->response(200, [
                'responses' => [
                    ['id' => '1-metadata', 'status' => 200, 'body' => ['size' => 3, 'cTag' => 'v1']],
                    ['id' => '1-thumbnail', 'status' => 429, 'headers' => ['Retry-After' => '1']],
                    ['id' => '2-metadata', 'status' => 404],
                    ['id' => '2-thumbnail', 'status' => 404]
                ]
            ]),
            $this->response(200, ['responses' => [['id' => '1-thumbnail', 'status' => 406]]])
        ];
        $started = hrtime(true);
        $this->assertSame(
            [1 => null, 2 => false],
            $this->client->thumbnails('drive', [
                1 => ['item' => 'a', 'target' => $this->root . '/a', 'bytes' => 3, 'version' => 'c:v1'],
                2 => ['item' => 'b', 'target' => $this->root . '/b', 'bytes' => 3, 'version' => 'c:v1']
            ])
        );
        $this->assertGreaterThanOrEqual(1e9, hrtime(true) - $started);
        $this->assertSame(
            ['1-thumbnail'],
            array_column(json_decode($this->client->calls[1]['form'], true)['requests'], 'id')
        );
        $this->assertCount(2, $this->client->calls);
    }

    public function testInvalidBatchResponsesNeverDownloadAnUnverifiedPreview(): void
    {
        foreach (
            [
                [],
                [['id' => '1-metadata', 'status' => 200], ['id' => '1-metadata', 'status' => 200]],
                [
                    ['id' => '1-metadata', 'status' => 200, 'body' => ['size' => 3, 'cTag' => 'changed']],
                    ['id' => '1-thumbnail', 'status' => 406]
                ],
                [
                    ['id' => '1-metadata', 'status' => 429, 'headers' => ['Retry-After' => '180']],
                    ['id' => '1-thumbnail', 'status' => 406]
                ]
            ]
            as $responses
        ) {
            $this->client->calls = [];
            $this->client->responses = [$this->response(200, ['responses' => $responses])];
            try {
                $this->client->thumbnails('drive', [
                    1 => ['item' => 'a', 'target' => $this->root . '/a', 'bytes' => 3, 'version' => 'c:v1']
                ]);
                $this->fail('Expected rejected batch');
            } catch (RuntimeException) {
                $this->assertCount(1, $this->client->calls);
                $this->assertFileDoesNotExist($this->root . '/a');
            }
        }
    }

    public function testCancellationRetainsSavedPreviewsAndDoesNotStartTheNextWave(): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(16, 12));
        $jpeg = ob_get_clean();
        $photos = $responses = [];
        foreach (range(1, 5) as $id) {
            $photos[$id] = [
                'item' => (string) $id,
                'target' => $this->root . '/preview-' . $id,
                'bytes' => 3,
                'version' => 'c:v1'
            ];
            $responses[] = ['id' => $id . '-metadata', 'status' => 200, 'body' => ['size' => 3, 'cTag' => 'v1']];
            $responses[] = [
                'id' => $id . '-thumbnail',
                'status' => 200,
                'body' => ['value' => [['large' => ['url' => 'https://example.com/preview-' . $id]]]]
            ];
        }
        $this->client->responses = [
            $this->response(200, ['responses' => $responses]),
            ...array_fill(0, 4, ['status' => 200, 'content' => $jpeg])
        ];
        try {
            $this->client->thumbnails('drive', $photos, function (): void {
                $this->client->cancelled = true;
            });
            $this->fail('Expected cancellation');
        } catch (\vielhuber\photobutler\JobInterrupted) {
            $this->assertSame($jpeg, file_get_contents($this->root . '/preview-1'));
            $this->assertCount(5, $this->client->calls);
            $this->assertSame([], glob($this->root . '/*.part'));
            $this->assertFileDoesNotExist($this->root . '/preview-2');
        }
    }
}
