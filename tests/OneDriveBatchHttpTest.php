<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use vielhuber\photobutler\PhotoButler;

final class OneDriveBatchHttpTest extends TestCase
{
    private string $root;
    private PhotoButler $library;
    private mixed $proxy = null;
    private mixed $process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/photobutler-batch-http-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.data', 0700, true);
        file_put_contents($this->root . '/.data/.env', "ONEDRIVE_CLIENT_ID=fixture\nONEDRIVE_FOLDER=FOTOS\n");
        file_put_contents(
            $this->root . '/.data/onedrive-source.json',
            json_encode([
                'drive' => 'drive',
                'folder' => 'root',
                'folder_path' => 'FOTOS',
                'client_id' => 'fixture'
            ])
        );
        file_put_contents(
            $this->root . '/.data/onedrive-token.json',
            json_encode([
                'client_id' => 'fixture',
                'tenant' => 'consumers',
                'access_token' => 'fixture-access',
                'refresh_token' => 'fixture-refresh',
                'expires_at' => time() + 3600
            ])
        );
        $this->library = new PhotoButler($this->root);
        foreach (range(1, 23) as $id) {
            $this->library->database
                ->prepare(
                    "INSERT INTO photos(id,root,path,album,name,modified,bytes,width,height,taken,seen)
                VALUES(?, 'onedrive:/drive', ?, 'album', 'photo.jpg', 1, 3000000000, 0, 0, '2026-01-01', 'fixture')"
                )
                ->execute([$id, 'onedrive:/drive/' . $id]);
            $this->library->database
                ->prepare("INSERT INTO onedrive_photos VALUES (?, ?, 'c:v1', ?)")
                ->execute([$id, (string) $id, $id . '.jpg']);
        }
        imagejpeg(imagecreatetruecolor(16, 12), $this->root . '/preview');
        $certificate = proc_open(
            [
                'openssl',
                'req',
                '-x509',
                '-newkey',
                'rsa:2048',
                '-nodes',
                '-keyout',
                $this->root . '/key.pem',
                '-out',
                $this->root . '/certificate.pem',
                '-days',
                '1',
                '-subj',
                '/CN=graph.microsoft.com',
                '-addext',
                'subjectAltName=DNS:graph.microsoft.com,IP:127.0.0.1'
            ],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertSame(0, proc_close($certificate));
    }

    protected function tearDown(): void
    {
        foreach ([$this->process, $this->proxy] as $process) {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
        unset($this->library);
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            )
            as $file
        ) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function start(string $mode): void
    {
        if (!is_resource($this->proxy)) {
            file_put_contents($this->root . '/configuration', json_encode(['mode' => $mode]));
            $this->proxy = proc_open(
                ['node', __DIR__ . '/fixtures/onedrive-batch-proxy.cjs', $this->root],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', $this->root . '/proxy.log', 'w']
                ],
                $pipes
            );
            for ($attempt = 0; $attempt < 100 && !is_file($this->root . '/port'); $attempt++) {
                usleep(50000);
                clearstatcache();
            }
            $this->assertFileExists($this->root . '/port');
        }
        $environment = array_merge(getenv(), [
            'HTTPS_PROXY' => 'http://127.0.0.1:' . trim(file_get_contents($this->root . '/port')),
            'https_proxy' => 'http://127.0.0.1:' . trim(file_get_contents($this->root . '/port')),
            'NO_PROXY' => '',
            'no_proxy' => ''
        ]);
        $this->process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'curl.cainfo=' . $this->root . '/certificate.pem',
                dirname(__DIR__) . '/bin/photobutler-index',
                '--root=' . $this->root,
                '--previews-only'
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->root . '/cli.log', 'w'],
                2 => ['file', $this->root . '/cli.log', 'a']
            ],
            $pipes,
            $this->root,
            $environment
        );
    }

    private function finish(float $timeout = 10): int
    {
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                proc_close($this->process);
                $this->process = null;
                return $status['exitcode'];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('CLI did not exit: ' . file_get_contents($this->root . '/cli.log'));
    }

    #[TestWith(['jpeg'])]
    #[TestWith(['png'])]
    public function testRealCliUsesBatchesAndExactlyFourParallelCredentialFreeDownloads(string $format): void
    {
        ('image' . $format)(imagecreatetruecolor(16, 12), $this->root . '/preview');
        $this->start('normal');
        $this->assertSame(0, $this->finish(), file_get_contents($this->root . '/cli.log'));
        $this->assertSame('4', file_get_contents($this->root . '/maximum'));
        $batches = array_map(
            static fn(string $line): array => json_decode($line, true),
            file($this->root . '/batches')
        );
        $this->assertSame([20, 20, 6], array_map(static fn(array $batch): int => count($batch['requests']), $batches));
        $requests = array_map(
            static fn(string $line): array => json_decode($line, true),
            file($this->root . '/requests')
        );
        $this->assertCount(23, $requests);
        $this->assertCount(4, array_unique(array_column($requests, 'port')));
        foreach ($requests as $request) {
            $this->assertArrayNotHasKey('authorization', $request);
        }
        foreach (range(1, 23) as $id) {
            $this->assertSame(
                file_get_contents($this->root . '/preview'),
                file_get_contents($this->library->imagePath($id, cachedOnly: true))
            );
        }
        $state = $this->library->jobs->all()['previews'];
        $this->assertSame('done', $state['status']);
        $this->assertSame(23, $state['completed']);
        $this->start('normal');
        $this->assertSame(0, $this->finish());
        $this->assertCount(23, file($this->root . '/requests'));
    }

    public static function interruptions(): array
    {
        return [['download-stall', SIGINT], ['download-stall', SIGTERM], ['graph-stall', SIGINT], ['backoff', SIGINT]];
    }

    #[DataProvider('interruptions')]
    public function testRealCliSignalsCancelTransfersOrBackoffAndReleaseLocks(string $mode, int $signal): void
    {
        $this->start($mode);
        for ($attempt = 0; $attempt < 200 && !is_file($this->root . '/ready'); $attempt++) {
            usleep(20000);
            clearstatcache();
        }
        $this->assertFileExists($this->root . '/ready', file_get_contents($this->root . '/cli.log'));
        usleep(200000);
        $started = microtime(true);
        $this->assertTrue(proc_terminate($this->process, $signal));
        $this->assertSame(128 + $signal, $this->finish(4));
        $this->assertLessThan(4, microtime(true) - $started);
        $this->assertSame('paused', $this->library->jobs->all()['previews']['status']);
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.part'));
        $this->assertCount($mode === 'download-stall' ? 4 : 0, glob($this->root . '/.data/thumbnails/*.jpg'));
        foreach (['cli-previews', 'job-previews', 'index', 'thumbnail-0', 'thumbnail-1', 'onedrive-token'] as $name) {
            $lock = fopen($this->root . '/.data/' . $name . '.lock', 'c');
            try {
                $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            } finally {
                fclose($lock);
            }
        }
        $this->assertStringContainsString('Pausiert.', file_get_contents($this->root . '/cli.log'));
    }

    public static function temporaryFailures(): array
    {
        return [['retry', 1], ['retry-long', 4]];
    }

    #[DataProvider('temporaryFailures')]
    public function testOnlyFailedBinaryDownloadIsRetriedAfterProviderDelay(string $mode, int $failures): void
    {
        $this->start($mode);
        $this->assertSame(0, $this->finish(15));
        $requests = array_map(
            static fn(string $line): array => json_decode($line, true),
            file($this->root . '/requests')
        );
        $this->assertCount(23 + $failures, $requests);
        $retries = array_values(array_filter($requests, static fn(array $request): bool => $request['id'] === 2));
        $this->assertCount($failures + 1, $retries);
        for ($attempt = 1; $attempt < count($retries); $attempt++) {
            $this->assertGreaterThanOrEqual(1000, $retries[$attempt]['time'] - $retries[$attempt - 1]['time']);
        }
        $this->assertSame('4', file_get_contents($this->root . '/maximum'));
    }

    public function testExhaustedRetriesReportActualHttpStatusWithoutClaimingThrottling(): void
    {
        $this->start('retry-exhausted');
        $this->assertSame(1, $this->finish(15));
        $this->assertSame('paused', $this->library->jobs->all()['previews']['status']);
        $requests = array_map(
            static fn(string $line): array => json_decode($line, true),
            file($this->root . '/requests')
        );
        $this->assertCount(8, array_filter($requests, static fn(array $request): bool => $request['id'] === 2));
        $log = file_get_contents($this->root . '/cli.log');
        $this->assertStringContainsString('HTTP 504', $log);
        $this->assertStringContainsString('Warte 1 Sek.', $log);
        $this->assertStringNotContainsString('gedrosselt', $log);
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.part'));
    }

    public static function invalidPreviews(): array
    {
        return [['invalid'], ['oversized']];
    }

    #[DataProvider('invalidPreviews')]
    public function testInvalidOrOversizedBodyIsSkippedWithoutCorruptingOtherCaches(string $mode): void
    {
        $this->start($mode);
        $this->assertSame(1, $this->finish());
        $state = $this->library->jobs->all()['previews'];
        $this->assertSame(1, $state['errors']);
        $this->assertSame(22, $state['completed']);
        $this->assertNull($this->library->imagePath(2, cachedOnly: true));
        $this->assertSame([], glob($this->root . '/.data/thumbnails/*.part'));
    }
}
