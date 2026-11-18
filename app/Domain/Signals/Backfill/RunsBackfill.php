<?php

namespace App\Domain\Signals\Backfill;

use Illuminate\Support\Facades\DB;

/**
 * What the three `signals:backfill-*` commands share: the --database / --tenant / --apply / --summary options, which organisations to run
 * for, the printed lines (kept so --summary can save exactly what was shown) and the final exit code. Used by a Command.
 */
trait RunsBackfill
{
    /** @var list<string> */
    private array $summaryLines = [];

    /**
     * Switches to --database when given, and works out which organisations to run for.
     *
     * @return list<int>|null the organisations; null after an error was printed (the command then exits with INVALID). Without --tenant a dry
     *                        run covers every organisation that has signals, and --apply refuses to run at all.
     */
    private function organisations(TenantBackfill $backfill, bool $apply): ?array
    {
        $database = $this->option('database');
        if ($database !== null && $database !== '') {
            if (config('database.connections.' . $database) === null) {
                $this->error("There is no database connection called '{$database}'.");

                return null;
            }
            DB::setDefaultConnection($database);
        }

        $tenant = $this->option('tenant');
        if ($tenant === null || $tenant === '') {
            if ($apply) {
                $this->error('--apply needs --tenant=<organisation id>: one organisation at a time.');

                return null;
            }

            return $backfill->tenants();
        }
        if (! ctype_digit((string) $tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be an organisation id (a whole number above 0).');

            return null;
        }

        return [(int) $tenant];
    }

    private function header(bool $apply, array $tenants): void
    {
        $this->say(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection() . ', organisations: ' . ($tenants === [] ? '(none)' : implode(', ', $tenants)));
    }

    /** One aligned "label  number" line. */
    private function count(string $label, int $n): void
    {
        $this->say(sprintf('  %-52s %d', $label, $n));
    }

    /** Prints a line and keeps it for --summary. $style is a Command output method: line, warn or error. */
    private function say(string $text = '', string $style = 'line'): void
    {
        $this->summaryLines[] = $text;
        $this->{$style}($text);
    }

    /** Saves the printed lines to --summary when given, and returns the exit code (FAILURE when the file could not be written). */
    private function finish(int $code): int
    {
        $path = $this->option('summary');
        if ($path === null || $path === '') {
            return $code;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (@file_put_contents($path, implode(PHP_EOL, $this->summaryLines) . PHP_EOL) === false) {
            $this->error("The summary could not be written to {$path}.");

            return self::FAILURE;
        }
        $this->line("Summary saved to {$path}");

        return $code;
    }
}
