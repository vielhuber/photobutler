<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class StickerRenderer
{
    private const MAX_JSON_BYTES = 4194304;
    private const RENDER_TIMEOUT_SECONDS = 45;

    /**
     * Render ZIP/Lottie and animated WebP stickers without changing the original.
     */
    public function render(string $source, string $target): \stdClass
    {
        if (file_get_contents($source, false, null, 0, 4) === "PK\x03\x04") {
            $archive = new \ZipArchive();
            if ($archive->open($source, \ZipArchive::RDONLY) !== true) {
                throw new \UnexpectedValueException('Sticker-Archiv nicht lesbar.');
            }
            try {
                $entry = $archive->statName('animation/animation.json');
                if ($entry === false || $entry['size'] > self::MAX_JSON_BYTES) {
                    throw new \UnexpectedValueException('Sticker-Animation fehlt oder ist zu groß.');
                }
                $json = $archive->getFromName('animation/animation.json', self::MAX_JSON_BYTES);
            } finally {
                $archive->close();
            }
            if ($json === false) {
                throw new \UnexpectedValueException('Sticker-Animation nicht lesbar.');
            }
            $animation = json_decode($json, flags: JSON_THROW_ON_ERROR);
            if (
                !($animation instanceof \stdClass) ||
                !is_numeric($animation->w ?? null) ||
                !is_numeric($animation->h ?? null) ||
                $animation->w <= 0 ||
                $animation->h <= 0
            ) {
                throw new \UnexpectedValueException('Sticker-Abmessungen nicht auswertbar.');
            }
            $width = $animation->w;
            $height = $animation->h;
            $input = $json;
        } else {
            $size = getimagesize($source);
            $width = $size[0];
            $height = $size[1];
            $input = file_get_contents($source);
        }
        $process = proc_open(
            ['node', dirname(__DIR__) . '/scripts/render-sticker.cjs', $target],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if ($process === false) {
            throw new \RuntimeException('Sticker-Renderer nicht verfügbar.');
        }
        try {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $deadline = microtime(true) + self::RENDER_TIMEOUT_SECONDS;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            if ($status['running'] || $status['exitcode'] !== 0) {
                throw new \RuntimeException('Sticker konnte nicht gerendert werden.');
            }
            if (!rename($target . '.webp.tmp', $target . '.webp') || !rename($target . '.tmp', $target)) {
                throw new \RuntimeException('Sticker-Vorschau konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            foreach ([$target . '.tmp', $target . '.webp.tmp'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        $metadata = new \stdClass();
        $metadata->width = $width;
        $metadata->height = $height;
        $metadata->taken = date('Y-m-d H:i:s', filemtime($source));
        return $metadata;
    }
}
