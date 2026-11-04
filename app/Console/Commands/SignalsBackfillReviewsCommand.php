<?php

namespace App\Console\Commands;

use App\Domain\Signals\Backfill\BackfillBlocked;
use App\Domain\Signals\Backfill\ReviewsBackfill;
use App\Domain\Signals\Backfill\RunsBackfill;
use Illuminate\Console\Command;

/**
 * Gives each signal that was decided before the history table existed one history row, made from its review_* columns. A dry run unless
 * --apply is given. Repeating it changes nothing. See ReviewsBackfill for the rules (and for why the reason and the note stay empty).
 *
 *   php artisan signals:backfill-reviews                           (dry run, every organisation)
 *   php artisan signals:backfill-reviews --tenant=6 --apply --summary=Docs/agentic-crm/_evidence/s2-reviews.txt
 */
class SignalsBackfillReviewsCommand extends Command
{
    use RunsBackfill;

    protected $signature = 'signals:backfill-reviews
        {--tenant= : One organisation (sub_institute_id). Required with --apply; a dry run without it covers every organisation}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the history rows (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Make one decision-history row for each already-decided signal. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new ReviewsBackfill;

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
            $this->count('signals with a decision (status is not New)', $r['decided']);
            foreach ($r['by_decision'] as $decision => $n) {
                $this->count("  {$decision}", $n);
            }
            $this->count($apply ? 'history rows created' : 'history rows to create', $r['to_create']);
            $this->count('history rows already there (left alone)', $r['already_there']);
            $this->count('decided_at taken from the signal\'s updated_at', $r['used_updated_at']);
            if ($r['used_updated_at'] > 0) {
                $this->say('  ! Those signals have no reviewed_at, so the date is when the signal last changed, not necessarily when it was decided.', 'warn');
            }
            if ($r['unmapped']['count'] > 0) {
                $this->say("  ! {$r['unmapped']['count']} signal(s) have a status this command does not know and were skipped: ids " . implode(', ', $r['unmapped']['ids']), 'warn');
            }
            if ($r['no_date']['count'] > 0) {
                $this->say("  ! {$r['no_date']['count']} signal(s) have neither reviewed_at nor updated_at and were skipped: ids " . implode(', ', $r['no_date']['ids']), 'warn');
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
