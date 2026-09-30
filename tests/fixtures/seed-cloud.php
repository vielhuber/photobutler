<?php
declare(strict_types=1);

/**
 * Mirror a local fixture directory as the configured OneDrive folder without network access.
 *
 * Usage: php seed-cloud.php <root> '{"settings":"AUTH_USERNAME=...\n","directory":"/path","thumbnails":true,"job":"scan|previews"}'
 * Optional "unavailable_previews" / "failed_previews" list relative paths whose preview is missing or invalid.
 * Existing .data/.env settings are kept; the fixture OneDrive connection is added once.
 * Prints a JSON map of available relative paths => photo ids.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use vielhuber\photobutler\OneDriveClient;
use vielhuber\photobutler\PhotoButler;

final class SeedOneDriveClient extends OneDriveClient
{
    public array $pages = [];
    public array $thumbnailSources = [];

    public function get(string $path): array
    {
        return array_shift($this->pages) ?? throw new RuntimeException('Unexpected fixture request');
    }

    public function thumbnails(string $drive, array $photos, ?callable $progress = null): array
    {
        $results = [];
        foreach ($photos as $id => $photo) {
            $source = $this->thumbnailSources[$photo['item']] ?? null;
            $results[$id] = $source === false ? false : ($source === null ? null : true);
            if (is_string($source)) {
                file_put_contents($photo['target'], $source);
            }
            if ($progress !== null) {
                $progress($id, $results[$id]);
            }
        }
        return $results;
    }
}

[, $root, $json] = $argv + [null, null, '{}'];
$options = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
foreach (['.data', '.data/thumbnails', 'public'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        mkdir($root . '/' . $directory, 0700, true);
    }
}
$settings = is_file($root . '/.data/.env') ? file_get_contents($root . '/.data/.env') : '';
if (preg_match('/^ONEDRIVE_CLIENT_ID=/m', $settings) !== 1) {
    file_put_contents(
        $root . '/.data/.env',
        "ONEDRIVE_CLIENT_ID=fixture\nONEDRIVE_FOLDER=FOTOS\n" . $settings . ($options['settings'] ?? '')
    );
}
if (!is_file($root . '/.data/onedrive-source.json')) {
    file_put_contents(
        $root . '/.data/onedrive-source.json',
        json_encode(['drive' => 'drive', 'folder' => 'root', 'folder_path' => 'FOTOS', 'client_id' => 'fixture'])
    );
}
// progress lines from the job runner must not corrupt the printed id map
ob_start();
$client = new SeedOneDriveClient($root . '/.data', 'fixture');
$library = new PhotoButler($root, oneDriveClient: $client);
$items = [['id' => 'root', 'name' => 'FOTOS', 'folder' => []]];
$present = [];
$directory = $options['directory'] ?? null;
if ($directory !== null) {
    // sorted like the former local scan, so catalog ids follow path order
    $files = iterator_to_array(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        )
    );
    ksort($files, SORT_STRING);
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($directory) + 1);
        $parent = dirname($relative) === '.' ? 'root' : 'folder:' . dirname($relative);
        if ($file->isDir()) {
            $items[] = [
                'id' => 'folder:' . $relative,
                'name' => $file->getFilename(),
                'folder' => [],
                'parentReference' => ['id' => $parent]
            ];
            continue;
        }
        $content = file_get_contents($file->getPathname());
        $mime = mime_content_type($file->getPathname()) ?: 'application/octet-stream';
        $id = 'item:' . $relative;
        $present[$id] = true;
        $items[] = [
            'id' => $id,
            'name' => $file->getFilename(),
            'parentReference' => ['id' => $parent],
            'file' => ['mimeType' => $mime],
            'size' => strlen($content),
            'lastModifiedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $file->getMTime()),
            'cTag' => hash('sha256', $content)
        ];
        if (in_array($relative, $options['unavailable_previews'] ?? [], true)) {
            continue;
        }
        if (in_array($relative, $options['failed_previews'] ?? [], true)) {
            $client->thumbnailSources[$id] = false;
            continue;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            ob_start();
            imagejpeg(imagecreatetruecolor(16, 12));
            $content = ob_get_clean();
        }
        $client->thumbnailSources[$id] = $content;
    }
}
foreach ($library->database->query('SELECT item FROM onedrive_photos')->fetchAll(PDO::FETCH_COLUMN) as $known) {
    if (!isset($present[$known])) {
        $items[] = ['id' => $known, 'deleted' => []];
    }
}
$client->pages[] = [
    'value' => $items,
    '@odata.deltaLink' => OneDriveClient::GRAPH . '/drives/drive/root/delta?token=fixture'
];
$job = $options['job'] ?? '';
if ($job === 'scan') {
    $run = $library->jobs->start('scan');
    while ($run['status'] === 'running') {
        $run = $library->jobs->step('scan', $run['token']);
    }
}
if ($job !== 'scan') {
    $library->index();
}
if ($options['thumbnails'] ?? false) {
    $ids = $library->database
        ->query('SELECT o.photo_id FROM onedrive_photos o JOIN photos p ON p.id = o.photo_id WHERE p.available = 1')
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_chunk($ids, 100) as $chunk) {
        $library->oneDrive->previews($chunk);
    }
}
if ($job === 'previews') {
    $run = $library->jobs->start('previews');
    while ($run['status'] === 'running') {
        $run = $library->jobs->step('previews', $run['token'], previewLimit: PHP_INT_MAX);
    }
}
ob_end_clean();
$map = [];
foreach (
    $library->database->query(
        'SELECT o.relative_path, o.photo_id FROM onedrive_photos o JOIN photos p ON p.id = o.photo_id WHERE p.available = 1'
    )
    as $row
) {
    $map[$row['relative_path']] = (int) $row['photo_id'];
}
echo json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
