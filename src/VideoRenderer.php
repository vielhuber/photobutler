<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class VideoRenderer
{
    /**
     * Extract one bounded poster locally without transcoding or changing the original.
     */
    public function render(string $source, string $target): \stdClass
    {
        $probe = json_decode(
            $this->run([
                'ffprobe',
                '-v',
                'error',
                '-protocol_whitelist',
                'file,pipe',
                '-select_streams',
                'v:0',
                '-show_entries',
                'stream=width,height:stream_side_data=rotation',
                '-of',
                'json',
                $source
            ]),
            flags: JSON_THROW_ON_ERROR
        );
        $stream = $probe->streams[0] ?? null;
        $width = (int) ($stream->width ?? 0);
        $height = (int) ($stream->height ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > 60000000) {
            throw new \RuntimeException('Video nicht lesbar oder Pixelgrenze überschritten.');
        }
        foreach ($stream->side_data_list ?? [] as $sideData) {
            if (abs((int) ($sideData->rotation ?? 0)) % 180 === 90) {
                [$width, $height] = [$height, $width];
                break;
            }
        }
        $frame = $this->run([
            'ffmpeg',
            '-v',
            'error',
            '-nostdin',
            '-threads',
            '1',
            '-filter_threads',
            '1',
            '-protocol_whitelist',
            'file,pipe',
            '-i',
            $source,
            '-map',
            '0:v:0',
            '-frames:v',
            '1',
            '-vf',
            "scale='min(640,iw)':'min(640,ih)':force_original_aspect_ratio=decrease",
            '-an',
            '-map_metadata',
            '-1',
            '-threads',
            '1',
            '-f',
            'image2pipe',
            '-vcodec',
            'mjpeg',
            'pipe:1'
        ]);
        $image = imagecreatefromstring($frame);
        if ($image === false) {
            throw new \RuntimeException('Video-Vorschaubild nicht lesbar.');
        }
        try {
            if (!imagejpeg($image, $target . '.tmp', 65) || !rename($target . '.tmp', $target)) {
                throw new \RuntimeException('Video-Vorschaubild konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_file($target . '.tmp')) {
                unlink($target . '.tmp');
            }
        }
        $metadata = new \stdClass();
        $metadata->width = $width;
        $metadata->height = $height;
        $metadata->taken = date('Y-m-d H:i:s', filemtime($source));
        return $metadata;
    }

    /**
     * Bound local probing and decoding by runtime and output size.
     */
    private function run(array $command): string
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if ($process === false) {
            throw new \RuntimeException('Video-Verarbeitung nicht verfügbar.');
        }
        try {
            stream_set_blocking($pipes[1], false);
            $output = '';
            $deadline = microtime(true) + 45;
            do {
                $output .= stream_get_contents($pipes[1]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && strlen($output) < 4194304);
            if ($status['running'] || $status['exitcode'] !== 0 || strlen($output) >= 4194304) {
                throw new \RuntimeException('Video-Verarbeitung fehlgeschlagen.');
            }
            return $output;
        } finally {
            fclose($pipes[1]);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
