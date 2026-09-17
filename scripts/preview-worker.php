<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use vielhuber\photobutler\PhotoButler;
use vielhuber\photobutler\PhotoRenderer;

[$script, $root, $registry, $socket] = $argv;
$server = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $server = stream_socket_server('unix://' . $socket);
    if ($server === false) {
        throw new RuntimeException('Vorschau-Worker nicht verfügbar.');
    }
    chmod($socket, 0600);
    $renderer = new PhotoRenderer();
    $library = null;
    $configuration = null;
    while (is_dir($root . '/.data')) {
        $read = [$server];
        $write = $except = null;
        if (stream_select($read, $write, $except, 5) !== 1) {
            break;
        }
        $connection = stream_socket_accept($server, 0);
        if ($connection === false) {
            continue;
        }
        try {
            stream_set_timeout($connection, 1);
            $request = fgets($connection, 32);
            if ($request === false || !ctype_digit(trim($request))) {
                continue;
            }
            clearstatcache();
            $currentConfiguration = file_get_contents($root . '/.data/.env');
            if ($library === null || $configuration !== $currentConfiguration) {
                $library = new PhotoButler($root, photoRenderer: $renderer);
                $configuration = $currentConfiguration;
                gc_collect_cycles();
            }
            $id = (int) trim($request);
            $thumbnail = $library->imagePath($id, animated: true);
            fwrite($connection, ($thumbnail !== null ? '1' : '0') . "\n");
        } catch (RuntimeException | ErrorException | JsonException) {
            $library = null;
            error_log('Vorschau-Worker konnte Anfrage nicht abschließen.');
        } finally {
            fclose($connection);
        }
    }
} catch (RuntimeException | ErrorException) {
    error_log('Vorschau-Worker wurde beendet.');
} finally {
    unset($library, $renderer);
    if (is_resource($server)) {
        fclose($server);
    }
    if (file_exists($socket)) {
        unlink($socket);
    }
    if (is_file($registry) && file_get_contents($registry) === $socket) {
        unlink($registry);
    }
    if (is_dir(dirname($socket))) {
        rmdir(dirname($socket));
    }
}
