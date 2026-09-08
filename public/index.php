<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    new \vielhuber\photobutler\PhotoButler(dirname(__DIR__))->run();
} catch (\RuntimeException | \JsonException $exception) {
    http_response_code(503);
    echo 'Photobutler ist nicht verfügbar. Bitte Konfiguration und Schreibrechte prüfen.';
}
