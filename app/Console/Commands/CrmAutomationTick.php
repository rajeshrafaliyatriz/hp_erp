<?php

namespace App\Console\Commands;

use App\Domain\Crm\Leads\LeadFollowUp;
use App\Domain\Crm\Sales\InvoiceSweep;
use App\Domain\Crm\Sales\OpportunitySweep;
use App\Domain\Gtm\Workflows\WorkflowEngine;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ONE TENANT's CRM/GTM automation pass: the tenant-scoped counterpart of `events:react` + `gtm:tick`.
 *
 *   php artisan crm:automation-tick --tenant=6            run one pass for tenant 6
 *   php artisan crm:automation-tick --tenant=6 --dry-run  only report what is waiting; change nothing
 *
 * WHY IT EXISTS: `events:react` and `gtm:tick` are global - on a shared database they deliver every tenant's events and sweep every
 * tenant's records. This command does the same work through the SAME engine, ledger and cadence code, but every query is filtered to the
 * tenant you name, so it is safe to run by hand or on a loop against a shared database. It adds no event loop, no second scheduler and no
 * second engine; it does NOT replace the platform scheduler (routes/console.php) and it does not start one.
 *
 * ONE PASS, IN THIS ORDER:
 *   1. retry this tenant's workflow runs that failed transiently and are due;
 *   2. run this tenant's follow-up cadences that are due (paused ones that can resume too);
 *   3. run the weekly Opportunity and overdue-Invoice checks for this tenant (they record events, idempotently per week);
 *   4. deliver this tenant's undelivered workflow events, repeating (up to --rounds) so an event that one workflow causes - a reply
 *      that was just classified - can reach the next workflow in the same pass.
 *
 * WHAT IT NEVER DOES: enable email sending or send external messages (follow-ups are skipped whenever CRM email sending is ON),
 * activate a workflow, bypass a workflow's activated_at (events recorded before activation are
 * consumed as 'skipped', exactly as `events:react` does), approve a run, send an invitation, or touch another tenant. Notification
 * delivery (NotificationDispatcher) is deliberately NOT run here: it is a different consumer with its own global ledger.
 *
 * SAFE TO REPEAT: delivery is idempotent through the event-delivery ledger and UNIQUE (workflow_id, event_id); cadence attempts are
 * claimed atomically; sweeps use weekly idempotency keys; a per-tenant lock stops two passes running at once.
 */
class CrmAutomationTick extends Command
{
    protected $signature = 'crm:automation-tick
                            {--tenant= : REQUIRED. The organisation (sub_institute_id) to process}
                            {--limit=200 : Maximum events delivered per round}
                            {--rounds=5 : Maximum delivery rounds (events caused by earlier ones need another round)}
                            {--after-event=0 : Ignore events up to and including this event id (stage a roll-out, or test without touching older events)}
                            {--steps=retries,followups,sweeps,events : Which parts to run, comma separated}
                            {--dry-run : Report what is waiting and change nothing}
                            ';

    protected $description = 'Run the CRM/GTM automation (workflow events, follow-ups, opportunity and invoice checks) for ONE tenant only.';

    public function handle(GtmWorkflowRunner $runner, WorkflowEngine $engine): int
    {
        $raw = $this->option('tenant');
        if ($raw === null || $raw === '' || ! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('--tenant=<id> is required (a positive organisation id). Nothing was processed.');

            return self::INVALID;
        }
        $tenant = (int) $raw;
        if (! DB::table('school_setup')->where('id', $tenant)->exists()) {
            $this->error("Tenant {$tenant} does not exist. Nothing was processed.");

            return self::INVALID;
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $after = max(0, (int) $this->option('after-event'));
        $steps = array_filter(array_map('trim', explode(',', (string) $this->option('steps'))));
        $unknown = array_diff($steps, ['retries', 'followups', 'sweeps', 'events']);
        if ($unknown !== [] || $steps === []) {
            $this->error('--steps accepts: retries, followups, sweeps, events. Nothing was processed.');

            return self::INVALID;
        }
        $on = fn (string $s) => in_array($s, $steps, true);
        $rounds = max(1, min(10, (int) $this->option('rounds')));

        if ($this->option('dry-run')) {
            $this->info("DRY RUN for tenant {$tenant} - nothing is changed.");
            $this->line('  workflow events waiting:   '.$runner->pendingCountForTenant($tenant, $after));
            $this->line('  workflow runs due a retry: '.DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('status', 'retry_wait')->where('next_attempt_at', '<=', now())->count());
            $this->line('  follow-ups due now:        '.DB::table('crm_lead_followups')->where('sub_institute_id', $tenant)->where('state', 'active')->whereNotNull('next_due_at')->where('next_due_at', '<=', now())->count());
            $this->line('  follow-ups paused:         '.DB::table('crm_lead_followups')->where('sub_institute_id', $tenant)->where('state', 'paused')->count());
            $this->line('  CRM email sending:         '.(config('crm.email_sending_enabled') ? 'ON' : 'off'));

            return self::SUCCESS;
        }

        $lock = Cache::lock("crm-automation-tick:{$tenant}", 600);
        if (! $lock->get()) {
            $this->warn("Another pass for tenant {$tenant} is already running. Nothing was processed.");

            return self::SUCCESS;
        }
        $failed = 0;
        try {
            $this->info("CRM automation pass for tenant {$tenant}");
            $step = function (string $label, callable $fn) use (&$failed) {
                try {
                    $r = $fn();
                    $this->line(sprintf('  %-34s %s', $label, is_array($r) ? json_encode($r) : $r));
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  %-34s FAILED: %s', $label, mb_substr($e->getMessage(), 0, 200)));
                }
            };

            $on('retries') && $step('workflow retries', fn () => $engine->retryDue($tenant));
            if ($on('followups')) {
                if (config('crm.email_sending_enabled')) {
                    $this->line(sprintf('  %-34s %s', 'follow-ups', 'SKIPPED: CRM email sending is ON; this command never sends external email'));
                } else {
                    $step('follow-ups', fn () => LeadFollowUp::processDue(50, $tenant));
                }
            }
            if ($on('sweeps')) {
                $step('opportunity check', fn () => OpportunitySweep::run(500, null, $tenant));
                $step('invoice check', fn () => InvoiceSweep::run(500, null, $tenant));
            }

            $delivered = 0;
            for ($round = 1; $on('events') && $round <= $rounds; $round++) {
                $n = 0;
                $step("event delivery (round {$round})", function () use ($runner, $tenant, $limit, $after, &$n) {
                    $n = $runner->catchUpForTenant($tenant, $limit, $after);

                    return "{$n} event(s)";
                });
                $delivered += $n;
                if ($n === 0) {
                    break;
                }
            }
            $left = $runner->pendingCountForTenant($tenant, $after);
            $this->line(sprintf('  %-34s %d delivered, %d still waiting', 'summary', $delivered, $left));
        } finally {
            $lock->release();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
