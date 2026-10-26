<?php

namespace App\Console\Commands;

use App\Domain\Signals\Run\VerifyEnvironmentGuard;
use Illuminate\Console\Command;

/**
 * Fail-closed check that this process is configured for the verification database and nothing else. It reads configuration only and opens NO
 * connection: it exists so a verification run can be refused before anything touches a database.
 *
 *   php artisan signals:verify-preflight          prints PASS or the reasons it failed; exit code 0 only on PASS
 *   php artisan signals:research --verify ...     runs the same check as its first statement
 */
class SignalsVerifyPreflight extends Command
{
    protected $signature = 'signals:verify-preflight';
    protected $description = 'Check (without connecting) that APP_ENV=verify and the database is exactly 127.0.0.1 / g2g_signals_verify.';

    /** Shared with `signals:research --verify`. Returns null when the environment passes, otherwise the reason. */
    public static function check(): ?string
    {
        try {
            VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective(
                (string) config('app.env'),
                (string) config('database.default'),
                (array) config('database.connections', []),
                $_ENV + $_SERVER,
                [
                    'frameworkEnv' => app()->environment(),
                    'cacheStore' => config('cache.default'), 'cacheConnection' => config('cache.stores.database.connection'),
                    'queueDefault' => config('queue.default'), 'queueConnection' => config('queue.connections.database.connection'),
                    'sessionDriver' => config('session.driver'), 'sessionConnection' => config('session.connection'),
                ],
            ));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function handle(): int
    {
        $problem = self::check();
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }
        $this->info('PASS: APP_ENV=verify, database 127.0.0.1 / g2g_signals_verify, no overrides. No connection was opened by this check.');

        return self::SUCCESS;
    }
}
