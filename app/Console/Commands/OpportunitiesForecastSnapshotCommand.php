<?php

namespace App\Console\Commands;

use App\Domain\Crm\Sales\ForecastSnapshots;
use App\Domain\Crm\Sales\ForecastSnapshotsBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Takes today's snapshot of the CRM pipeline, per owner (slice 11, task 6). A dry run unless --apply is given; running it twice on the same day leaves the same
 * rows (the day's rows are replaced, never added to). It always records "now" under today's date: there is no way to write a past day, because a past day's
 * pipeline cannot be known from today's rows. See ForecastSnapshots for what a row means.
 *
 *   php artisan opportunities:forecast-snapshot --all                      (dry run: what would be recorded)
 *   php artisan opportunities:forecast-snapshot --tenant=6 --apply         (record today's rows for one organisation)
 *   php artisan opportunities:forecast-snapshot --all --apply              (what the daily schedule runs; each organisation in its own transaction)
 */
class OpportunitiesForecastSnapshotCommand extends Command
{
    /** @var list<string> */
    private array $summaryLines = [];

    protected $signature = 'opportunities:forecast-snapshot
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has opportunities (instead of --tenant)}
        {--database= : Connection name; the default connection when omitted. The live connection needs the owner\'s go}
        {--apply : Record the rows (default: show what would be recorded)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Record today\'s CRM pipeline per owner (open, best case, commit, weighted). Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $snapshots = new ForecastSnapshots;

        $database = $this->option('database');
        if ($database !== null && $database !== '') {
            if (config('database.connections.' . $database) === null) {
                $this->error("There is no database connection called '{$database}'.");

                return self::INVALID;
            }
            DB::setDefaultConnection($database);
        }

        $tenant = $this->option('tenant');
        if ($this->option('all') && ($tenant !== null && $tenant !== '')) {
            $this->error('Give --tenant or --all, not both.');

            return self::INVALID;
        }
        if ($this->option('all')) {
            $tenants = $snapshots->tenants();
        } elseif ($tenant !== null && $tenant !== '' && ctype_digit((string) $tenant) && (int) $tenant > 0) {
            $tenants = [(int) $tenant];
        } else {
            $this->error('Give --tenant=<organisation id> (a whole number above 0) or --all.');

            return self::INVALID;
        }

        $this->say(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection() . ', date ' . now()->toDateString() . ', organisations: ' . ($tenants === [] ? '(none)' : implode(', ', $tenants)));
        $total = ['rows' => 0, 'written' => 0];

        foreach ($tenants as $id) {
            try {
                $r = $snapshots->run($id, $apply);
            } catch (ForecastSnapshotsBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$id}");
            $this->count('open opportunities (not omitted)', $r['open']);
            $this->count($apply ? 'rows recorded' : 'rows to record (every owner and currency)', $apply ? $r['written'] : $r['rows']);
            foreach ($r['totals'] as $t) {
                $this->say(sprintf('  all owners, %s: %d open, %d priced, pipeline %s, best case %s, commit %s, weighted %s', $t['currency'], $t['open_count'], $t['priced_count'],
                    number_format($t['open_value'], 2), number_format($t['best_case_value'], 2), number_format($t['commit_value'], 2),
                    $t['weighted_value'] === null ? 'none (no probability set)' : number_format($t['weighted_value'], 2) . " ({$t['weighted_count']} deals)"));
            }
            $total['rows'] += $r['rows'];
            $total['written'] += $r['written'];
        }

        $this->say();
        $this->say($apply ? sprintf('Total: %d row(s) recorded for today. Running it again today leaves the same rows.', $total['written'])
            : sprintf('Total: %d row(s) would be recorded. Dry run: nothing was written. Run again with --apply to record.', $total['rows']));

        return $this->finish(self::SUCCESS);
    }

    private function count(string $label, int $n): void
    {
        $this->say(sprintf('  %-60s %d', $label, $n));
    }

    /** Prints a line and keeps it for --summary. $style is a Command output method: line, warn or error. */
    private function say(string $text = '', string $style = 'line'): void
    {
        $this->summaryLines[] = $text;
        $this->{$style}($text);
    }

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
