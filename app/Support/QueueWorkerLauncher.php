<?php

namespace App\Support;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The command that starts a short-lived queue worker from a web request (Generate Signals queues a job and then starts a worker that stops when
 * the queue is empty).
 *
 * It must not depend on where the web server happens to be: under PHP-FPM / Apache the working directory is usually `public/` and `php` may not
 * be on the PATH (or is another version), so a bare `php artisan ...` fails silently and the queued run never starts. Everything here is absolute:
 * the project directory, the artisan file and the CLI PHP binary (PHP_BINARY is php-fpm under FPM, so it is not used directly).
 */
final class QueueWorkerLauncher
{
    /** @param string|null $os  override of PHP_OS, for tests */
    public static function command(string $queue, ?string $os = null, ?string $php = null, ?string $base = null): string
    {
        $os ??= PHP_OS;
        $base ??= base_path();
        $php ??= (new PhpExecutableFinder())->find(false) ?: 'php';
        $queues = $queue . ',default';
        // One try (a failed research must never repeat by itself and spend provider quota), the job's own 900 s limit, and the worker ends by itself.
        $limits = '--tries=1 --timeout=900 --max-time=1500';

        if (strtoupper(substr($os, 0, 3)) === 'WIN') {
            return sprintf('cd /d "%s" && start /B "" "%s" artisan queue:work --queue=%s %s --stop-when-empty', $base, $php, $queues, $limits);
        }

        return sprintf('cd %s && %s artisan queue:work --queue=%s %s --stop-when-empty > /dev/null 2>&1 &', escapeshellarg($base), escapeshellarg($php), escapeshellarg($queues), $limits);
    }

    /** Start it. Returns false when the host does not allow starting a process (the queue is then drained by a standing worker). */
    public static function start(string $queue): bool
    {
        try {
            $command = self::command($queue);
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen($command, 'r'));
            } else {
                exec($command);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
