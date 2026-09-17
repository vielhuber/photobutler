<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class PhotoRenderer
{
    private mixed $process = null;
    private array $pipes = [];

    /**
     * Reuse one sequential renderer for the lifetime of its PHP owner.
     */
    public function render(string $source, string $target, int $edge, int $quality): void
    {
        if ($this->process === null) {
            $this->process = proc_open(
                ['node', dirname(__DIR__) . '/scripts/render-photo.cjs'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $this->pipes,
                env_vars: array_replace(getenv(), ['UV_THREADPOOL_SIZE' => '1'])
            );
            if ($this->process === false) {
                $this->process = null;
                throw new \RuntimeException('Foto-Renderer nicht verfügbar.');
            }
            stream_set_timeout($this->pipes[1], 45);
        }
        try {
            fwrite(
                $this->pipes[0],
                json_encode(compact('source', 'target', 'edge', 'quality'), JSON_THROW_ON_ERROR) . "\n"
            );
            if (fgets($this->pipes[1]) !== "ok\n") {
                throw new \RuntimeException('Foto konnte nicht gerendert werden.');
            }
            if (!rename($target . '.tmp', $target)) {
                throw new \RuntimeException('Vorschaubild konnte nicht gespeichert werden.');
            }
        } catch (\RuntimeException | \ErrorException $exception) {
            $this->close();
            throw $exception;
        } finally {
            if (is_file($target . '.tmp')) {
                unlink($target . '.tmp');
            }
        }
    }

    /**
     * Release the child when the owner exits.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Discard failed workers before their output can be mistaken for the next image.
     */
    private function close(): void
    {
        if ($this->process === null) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
    }
}
