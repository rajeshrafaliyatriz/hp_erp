<?php

namespace App\Console\Commands;

use App\Domain\Crm\Sales\GtmResync;
use App\Domain\Crm\Sales\GtmResyncBlocked;
use App\Domain\Crm\Sales\GtmSyncGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the CRM opportunity <-> GTM deal pairs that a failed sync left out of step (defect D17, slice 11). A dry run unless --apply is given; repeating it
 * changes nothing. See GtmResync for exactly what is repaired and what is left alone.
 *
 *   php artisan opportunities:resync-gtm --all                                  (dry run, every organisation that has opportunities)
 *   php artisan opportunities:resync-gtm --tenant=6                             (dry run for one organisation)
 *   php artisan opportunities:resync-gtm --tenant=6 --apply --summary=docs/agentic-crm/_evidence/s11/resync-dev-apply.txt
 *   php artisan opportunities:resync-gtm --tenant=6 --include-deals --apply     (also make the opportunity for a GTM deal that has none)
 */
class OpportunitiesResyncGtmCommand extends Command
{
    /** @var list<string> */
    private array $summaryLines = [];

    protected $signature = 'opportunities:resync-gtm
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has opportunities (instead of --tenant)}
        {--database= : Connection name; the default connection when omitted. The live connection needs the owner\'s go}
        {--include-deals : Also make the opportunity for a GTM deal that has none (the reverse direction)}
        {--apply : Run the repairs (default: show what would be repaired)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Re-run the CRM opportunity / GTM deal sync for the pairs a failed sync left out of step. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $resync = new GtmResync;

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
            $tenants = $resync->tenants();
        } elseif ($tenant !== null && $tenant !== '' && ctype_digit((string) $tenant) && (int) $tenant > 0) {
            $tenants = [(int) $tenant];
        } else {
            $this->error('Give --tenant=<organisation id> (a whole number above 0) or --all.');

            return self::INVALID;
        }

        $this->say(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection() . ', organisations: ' . ($tenants === [] ? '(none)' : implode(', ', $tenants)));
        $total = ['to_repair' => 0, 'repaired' => 0, 'failed' => 0];

        foreach ($tenants as $id) {
            try {
                $r = $resync->run($id, $apply, (bool) $this->option('include-deals'));
            } catch (GtmResyncBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$id}");
            $this->count('opportunities', $r['opportunities']);
            $this->count('  in step with their GTM deal (left alone)', $r['in_step']);
            $this->count($apply ? '  with no GTM deal (deal made)' : '  with no GTM deal (deal would be made)', $r['unlinked']);
            $this->count($apply ? '  whose deal was stale (deal updated)' : '  whose deal is stale (deal would be updated)', $r['stale']);
            $this->count('  whose deal is NEWER than the opportunity (left alone: undoing that edit is a person\'s call)', $r['deal_newer']);
            $this->count('  whose linked deal was removed (left alone)', $r['deal_removed']);
            if ($this->option('include-deals')) {
                $this->count($apply ? 'GTM deals with no opportunity (opportunity made)' : 'GTM deals with no opportunity (opportunity would be made)', $r['deals_without_opportunity']);
            }
            if ($apply) {
                $this->count('repairs done', $r['repaired'] + $r['deals_repaired']);
                $this->count('repairs that failed (logged; the next run tries them again)', $r['failed'] + $r['deals_failed']);
            }
            $this->count('sync failures logged today on this server (resets with the cache)', GtmSyncGuard::failuresToday($id));
            $total['to_repair'] += $r['unlinked'] + $r['stale'] + $r['deals_without_opportunity'];
            $total['repaired'] += $r['repaired'] + $r['deals_repaired'];
            $total['failed'] += $r['failed'] + $r['deals_failed'];
        }

        $this->say();
        $this->say($apply
            ? sprintf('Total: %d repair(s) done, %d failed. Running it again repairs nothing that was repaired.', $total['repaired'], $total['failed'])
            : sprintf('Total: %d repair(s) would be done. Dry run: nothing was written. Run again with --apply to repair.', $total['to_repair']));

        return $this->finish($apply && $total['failed'] > 0 ? self::FAILURE : self::SUCCESS);
    }

    /** One aligned "label  number" line. */
    private function count(string $label, int $n): void
    {
        $this->say(sprintf('  %-92s %d', $label, $n));
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
