<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class PreviewPool
{
    /**
     * Workers are created lazily under the caller's exclusive preview-job lock.
     */
    public function __construct(private readonly string $dataPath) {}

    /**
     * Dispatch at most two image pairs before waiting, retaining each worker across requests.
     *
     * @param list<int> $ids
     * @return array<int, bool>
     */
    public function render(array $ids): array
    {
        if (count($ids) > 2) {
            throw new \InvalidArgumentException('Höchstens zwei Vorschauen gleichzeitig.');
        }
        $connections = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ($ids as $id) {
                $registry = $this->dataPath . '/preview-worker-' . $id % 2 . '.socket';
                $connection = false;
                $socket = null;
                if (is_file($registry)) {
                    try {
                        $socket = file_get_contents($registry);
                        $connection = stream_socket_client('unix://' . $socket, timeout: 1);
                    } catch (\ErrorException) {
                        // A worker may have reached its idle deadline between requests.
                        if (isset($socket)) {
                            $this->removeSocket($socket);
                        }
                    }
                }
                if ($connection === false) {
                    $directory = sys_get_temp_dir() . '/photobutler-preview-' . bin2hex(random_bytes(8));
                    mkdir($directory, 0700);
                    $socket = $directory . '/worker.sock';
                    try {
                        $launcher = proc_open(
                            [
                                'node',
                                '-e',
                                'require("node:child_process").spawn(process.argv[1],process.argv.slice(2),{detached:true,stdio:"ignore"}).unref();',
                                'php',
                                dirname(__DIR__) . '/scripts/preview-worker.php',
                                dirname($this->dataPath),
                                $registry,
                                $socket
                            ],
                            [
                                0 => ['file', '/dev/null', 'r'],
                                1 => ['file', '/dev/null', 'w'],
                                2 => ['file', '/dev/null', 'w']
                            ],
                            $pipes
                        );
                        if ($launcher === false || proc_close($launcher) !== 0) {
                            throw new \RuntimeException('Vorschau-Worker nicht verfügbar.');
                        }
                        $deadline = microtime(true) + 5;
                        do {
                            clearstatcache(true, $socket);
                            if (file_exists($socket)) {
                                $connection = stream_socket_client('unix://' . $socket, timeout: 1);
                                break;
                            }
                            usleep(10000);
                        } while (microtime(true) < $deadline);
                        if ($connection === false) {
                            throw new \RuntimeException('Vorschau-Worker nicht verfügbar.');
                        }
                        file_put_contents($registry, $socket, LOCK_EX);
                    } finally {
                        if ($connection === false) {
                            $this->removeSocket($socket);
                        }
                    }
                }
                $connections[$id] = $connection;
                stream_set_timeout($connection, 100);
                fwrite($connection, $id . "\n");
            }
            $results = [];
            foreach ($connections as $id => $connection) {
                $result = fgets($connection, 16);
                if (!in_array($result, ["0\n", "1\n"], true)) {
                    throw new \RuntimeException('Vorschau-Worker wurde unterbrochen.');
                }
                $results[$id] = $result === "1\n";
            }
            return $results;
        } catch (\ErrorException $exception) {
            throw new \RuntimeException('Vorschau-Worker nicht verfügbar.', previous: $exception);
        } finally {
            foreach ($connections as $connection) {
                fclose($connection);
            }
            restore_error_handler();
        }
    }

    /**
     * Remove only a failed worker's private temporary socket directory.
     */
    private function removeSocket(string $socket): void
    {
        if (
            dirname(dirname($socket)) !== sys_get_temp_dir() ||
            !str_starts_with(basename(dirname($socket)), 'photobutler-preview-') ||
            basename($socket) !== 'worker.sock'
        ) {
            throw new \RuntimeException('Ungültiger Vorschau-Worker.');
        }
        foreach ([$socket, dirname($socket)] as $path) {
            try {
                if (is_dir($path)) {
                    rmdir($path);
                    continue;
                }
                if (file_exists($path)) {
                    unlink($path);
                }
            } catch (\ErrorException $exception) {
                clearstatcache(true, $path);
                if (file_exists($path)) {
                    throw $exception;
                }
            }
        }
    }
}
