<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /** @var (callable(string): void)|null Replaces the whole start (guard included). Tests only: a test must never spawn a process. */
    private static $starter = null;

    /** @var (callable(string $command, bool $windows): void)|null Replaces ONLY the process spawn, so the guard, the logging and the failure handling run for real. Tests only. */
    private static $runner = null;

    /** Seconds during which a second request for the same queue does not spawn another worker (a double click, a burst of requests). */
    public const GUARD_SECONDS = 5;

    /** @param (callable(string): void)|null $starter pass null to restore the real behaviour */
    public static function fake(?callable $starter): void
    {
        self::$starter = $starter;
    }

    /** @param (callable(string, bool): void)|null $runner pass null to restore the real behaviour */
    public static function fakeRunner(?callable $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * Ask for a short-lived worker. Best effort, and it says so:
     *  - true  = a start was attempted now, or one was requested within the last GUARD_SECONDS (that worker is, or is about to be, draining
     *            the queue; no second process is spawned, so repeated clicks cannot pile up workers);
     *  - false = the host does not allow starting a process, or the attempt threw. The reason is logged as `signals.queue_worker_launch`.
     *
     * `true` does NOT mean the worker is running: the command is detached (it returns at once, with the output discarded on Linux), so
     * whether the worker booted cannot be seen from here. A sync that nobody picks up is closed by DataConnectorSyncReaper with a message
     * naming the worker command. A supervised standing worker is still required; this never replaces it.
     */
    public static function start(string $queue): bool
    {
        if (self::$starter !== null) {
            (self::$starter)($queue);

            return true;
        }

        try {
            if (! Cache::add('signals:worker-launch:' . $queue, 1, self::GUARD_SECONDS)) {
                Log::info('signals.queue_worker_launch', ['queue' => $queue, 'outcome' => 'skipped_recent']);

                return true;
            }
        } catch (\Throwable) {
            // a cache that cannot hold the guard must not stop the launch; the worker stops by itself when the queue is empty
        }

        try {
            $windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $command = self::command($queue);
            if (self::$runner !== null) {
                (self::$runner)($command, $windows);
            } elseif (! self::canSpawn()) {
                Log::warning('signals.queue_worker_launch', ['queue' => $queue, 'outcome' => 'not_allowed', 'reason' => 'exec/popen is disabled on this host']);

                return false;
            } elseif ($windows) {
                pclose(popen($command, 'r'));
            } else {
                exec($command);
            }
            Log::info('signals.queue_worker_launch', ['queue' => $queue, 'outcome' => 'requested', 'os' => PHP_OS_FAMILY]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('signals.queue_worker_launch', ['queue' => $queue, 'outcome' => 'failed', 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
    }

    private static function canSpawn(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $needed = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? ['popen', 'pclose'] : ['exec'];
        foreach ($needed as $function) {
            if (! function_exists($function) || in_array($function, $disabled, true)) {
                return false;
            }
        }

        return true;
    }
}
