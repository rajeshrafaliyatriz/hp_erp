<?php

namespace App\Console\Commands;

use App\Domain\Signals\Backfill\BackfillBlocked;
use App\Domain\Signals\Backfill\DateStatusBackfill;
use App\Domain\Signals\Backfill\RunsBackfill;
use App\Domain\Signals\Opportunities\DateStatus;
use Illuminate\Console\Command;

/**
 * Fills the empty `date_status` of each signal from its own date columns (event date, else source date, else undated). A dry run unless
 * --apply is given. Repeating it changes nothing. See DateStatusBackfill for the rules.
 *
 *   php artisan signals:backfill-date-status                          (dry run, every organisation, per-organisation counts)
 *   php artisan signals:backfill-date-status --tenant=6               (dry run for one organisation)
 *   php artisan signals:backfill-date-status --tenant=6 --apply --summary=Docs/agentic-crm/_evidence/s2-date-status.txt
 *   php artisan signals:backfill-date-status --tenant=6 --apply --database=live
 */
class SignalsBackfillDateStatusCommand extends Command
{
    use RunsBackfill;

    protected $signature = 'signals:backfill-date-status
        {--tenant= : One organisation (sub_institute_id). Required with --apply; a dry run without it covers every organisation}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the values (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Fill the empty date_status of Signals from their date columns. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new DateStatusBackfill;

        $tenants = $this->organisations($backfill, $apply);
        if ($tenants === null) {
            return self::INVALID;
        }
        $this->header($apply, $tenants);

        foreach ($tenants as $tenant) {
            try {
                $r = $backfill->run($tenant, $apply);
            } catch (BackfillBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$tenant}");
            $this->count('signals classified (samples left out)', $r['rows']);
            if ($r['samples_skipped'] > 0) {
                $this->count('sample signals skipped', $r['samples_skipped']);
            }
            $this->say('  what their date columns say:');
            foreach (DateStatus::ALL as $status) {
                $this->count("  {$status}", $r['classified'][$status]);
            }
            $this->say('  ' . ($apply ? 'date_status filled:' : 'date_status empty, would fill:'));
            foreach (DateStatus::ALL as $status) {
                $this->count("  {$status}", $r['fill'][$status]);
            }
            $this->count('date_status already filled (left alone)', $r['already_filled']);
            if ($r['filled_differs']['count'] > 0) {
                $this->say("  ! {$r['filled_differs']['count']} already-filled value(s) differ from the date columns and were kept: ids " . implode(', ', $r['filled_differs']['ids']), 'warn');
            }

            $a = $r['assessment'];
            $this->count('stored assessment carries a date status', $a['checked']);
            if ($a['unreadable'] > 0) {
                $this->count('stored assessment that could not be read', $a['unreadable']);
            }
            if ($a['disagree'] > 0) {
                $this->say("  ! the stored assessment disagrees with the date columns on {$a['disagree']} signal(s):", 'warn');
                foreach ($a['examples'] as $e) {
                    $this->say("      id {$e['id']}: assessment says {$e['assessment']}, columns say {$e['columns']}");
                }
                if ($a['disagree'] > count($a['examples'])) {
                    $this->say('      (first ' . count($a['examples']) . ' shown)');
                }
            }
            foreach ($r['issues'] as $issue) {
                $this->say('  ! ' . $issue, 'warn');
            }
        }

        $this->say();
        $this->say($apply ? 'Done. Running it again changes nothing.' : 'Dry run: nothing was written. Run again with --tenant=<id> --apply to write.');

        return $this->finish(self::SUCCESS);
    }
}
