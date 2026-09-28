<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class FaceAnalyzer
{
    /**
     * Keep the CPU runtime and thumbnail inputs on private storage.
     */
    public function __construct(private readonly string $dataPath) {}

    /**
     * Analyze only an existing static JPEG or PNG thumbnail, never render or open an original.
     */
    public function analyze(string $source): \stdClass
    {
        if (
            dirname($source) !== $this->dataPath . '/thumbnails' ||
            strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'jpg' ||
            !is_file($source) ||
            is_link($source)
        ) {
            throw new \RuntimeException('Statisches Thumbnail für Gesichtsanalyse erforderlich.');
        }
        if (!is_executable($this->dataPath . '/face-runtime/bin/python')) {
            throw new \RuntimeException('Lokale Gesichtsanalyse nicht installiert.');
        }
        $process = null;
        try {
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
        }
    }
}
