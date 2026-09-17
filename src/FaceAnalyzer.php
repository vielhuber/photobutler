<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class FaceAnalyzer
{
    /**
     * Keep the CPU runtime and temporary working images on private storage.
     */
    public function __construct(private readonly string $dataPath) {}

    /**
     * Analyze an oriented original or a locally rendered static sticker frame.
     */
    public function analyze(string $source): \stdClass
    {
        if (in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), PhotoButler::VIDEO_EXTENSIONS, true)) {
            $result = new \stdClass();
            $result->status = 'unsupported';
            $result->faces = [];
            return $result;
        }
        if (!is_executable($this->dataPath . '/face-runtime/bin/python')) {
            throw new \RuntimeException('Lokale Gesichtsanalyse nicht installiert.');
        }
        $temporary = tempnam($this->dataPath, 'face-');
        $process = null;
        try {
            $header = file_get_contents($source, false, null, 0, 21);
            if (
                str_starts_with($header, "PK\x03\x04") ||
                (strlen($header) === 21 &&
                    substr($header, 0, 4) === 'RIFF' &&
                    substr($header, 8, 8) === 'WEBPVP8X' &&
                    (ord($header[20]) & 2) !== 0)
            ) {
                try {
                    new StickerRenderer()->render($source, $temporary);
                } catch (\UnexpectedValueException | \JsonException) {
                    $result = new \stdClass();
                    $result->status = 'unsupported';
                    $result->faces = [];
                    return $result;
                }
                $source = $temporary;
            }
            $process = proc_open(
                [
                    $this->dataPath . '/face-runtime/bin/python',
                    dirname(__DIR__) . '/scripts/analyze-faces.py',
                    $source,
                    $this->dataPath . '/face-runtime/models'
                ],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
            if ($process === false) {
                throw new \RuntimeException('Lokale Gesichtsanalyse nicht verfügbar.');
            }
            stream_set_blocking($pipes[1], false);
            $output = '';
            $deadline = microtime(true) + 55;
            do {
                $output .= stream_get_contents($pipes[1]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline && strlen($output) < 8388608);
            if ($status['running'] || $status['exitcode'] !== 0 || strlen($output) >= 8388608) {
                throw new \RuntimeException('Gesichtsanalyse fehlgeschlagen. Installation und Modelldateien prüfen.');
            }
            return json_decode($output, flags: JSON_THROW_ON_ERROR);
        } finally {
            if (is_resource($process)) {
                fclose($pipes[1]);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            foreach ([$temporary, $temporary . '.webp', $temporary . '.tmp', $temporary . '.webp.tmp'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
