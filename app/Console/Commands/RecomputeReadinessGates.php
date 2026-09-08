<?php

namespace App\Console\Commands;

use App\Services\Readiness\ReadinessGateRecomputer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RECOMPUTE EVERY TENANT'S READINESS GATES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS COMMAND EXISTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ReadinessGateRecomputer has existed and worked the whole time. NOTHING EVER
 * CALLED IT. Every gate on every tenant still held a value computed on
 * 2026-08-11, so a screen that consulted a gate reported a week-old number as
 * though it were current.
 *
 * That is how "My Capability" came to say "coverage 0%" for a tenant whose real
 * coverage was 78.9%. The screen was honest, the endpoint was correct, the data
 * was there - and the measurement between them was stale.
 *
 *     A STORED MEASUREMENT WITH NOTHING TO REFRESH IT DOES NOT DECAY VISIBLY.
 *     IT KEEPS ANSWERING, CONFIDENTLY, WITH THE PAST.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY IT RUNS FOR EVERY TENANT, EVERY DAY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Gates carry `sustained_periods` - a value must hold across N consecutive
 * computations before the gate opens. That counter only advances when something
 * computes. WITHOUT A SCHEDULE, HYSTERESIS NEVER ADVANCES AT ALL: a tenant could
 * sit at 90% coverage forever and the gate would never open, because "three
 * consecutive passes" needs three runs to exist.
 *
 * So the schedule is not housekeeping. It is what makes the sustained-period
 * design work at all.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * "EVERY TENANT" NOW MEANS EVERY TENANT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The heading above was written before the code matched it. The tenant list came
 * from `tenant_readiness_gate` - the table this command WRITES - so the only
 * organisations it skipped were the ones that had never been measured, and they
 * stayed skipped forever. See the comment in handle() and
 * ReadinessGateRecomputer::tenantsToRecompute().
 */
class RecomputeReadinessGates extends Command
{
    protected $signature = 'readiness:recompute
                            {--tenant= : Recompute one tenant only}
                            {--quiet-summary : Print only the totals}';

    protected $description = 'Recompute readiness gates for every tenant (or one), advancing sustained-period counters';

    public function handle(ReadinessGateRecomputer $recomputer): int
    {
        $only = $this->option('tenant');

        /*
         * ── THE TENANT LIST IS NOT tenant_readiness_gate ────────────────────
         *
         * It used to be. This command read the table it was about to write, so
         * A TENANT WITH NO GATES COULD NEVER GET ANY - the nightly job skipped
         * precisely the organisations nobody had ever measured. On live that was
         * tenants 13 and 14, the only two ever created through the product's own
         * signup; on dev it was five, including one with 967 employees.
         *
         * The rule now lives in the recomputer beside the measurements, so this
         * command and recomputeAll() cannot drift onto different tenant lists
         * again - which they had.
         */
        $tenants = $only !== null
            ? [(int) $only]
            : $recomputer->tenantsToRecompute();

        if (!$tenants) {
            $this->warn('No tenants found. Nothing to recompute.');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        $changed = [];
        $firstRun = [];

        foreach ($tenants as $tenant) {
            // Read the state BEFORE, so a change can be reported rather than
            // inferred. A recompute that alters nothing is the normal case and
            // should be visibly uneventful.
            $before = DB::table('tenant_readiness_gate')
                ->where('sub_institute_id', $tenant)
                ->pluck('state', 'gate_key')->all();

            try {
                $recomputer->recompute((int) $tenant);
                $ok++;
            } catch (\Throwable $e) {
                // ONE TENANT'S FAILURE MUST NOT STOP THE REST. A shared job that
                // aborts on the first error leaves every later tenant stale
                // without saying so.
                $failed++;
                $this->error(sprintf('  tenant %d: %s', $tenant, $e->getMessage()));
                continue;
            }

            $after = DB::table('tenant_readiness_gate')
                ->where('sub_institute_id', $tenant)
                ->pluck('state', 'gate_key')->all();

            // A tenant measured for the first time is reported SEPARATELY from a
            // tenant whose gates moved. Folding the two together would list five
            // "new" transitions per first-time tenant among the real changes and
            // bury the one line that matters on the night this ships.
            if (!$before) {
                $firstRun[] = sprintf('tenant %d · first computation · %d gate(s)', $tenant, count($after));
                continue;
            }

            foreach ($after as $gate => $state) {
                if (($before[$gate] ?? null) !== $state) {
                    $changed[] = sprintf('tenant %d · %s · %s -> %s', $tenant, $gate, $before[$gate] ?? 'new', $state);
                }
            }
        }

        if (!$this->option('quiet-summary')) {
            foreach ($firstRun as $line) {
                $this->info('  ' . $line);
            }

            foreach ($changed as $line) {
                $this->info('  ' . $line);
            }

            if ($firstRun) {
                $this->line('  ' . ReadinessGateRecomputer::FIRST_RUN_NOTE);
            }
        }

        $this->info(sprintf(
            'readiness:recompute - %d tenant(s) ok, %d failed, %d first computation(s), %d gate state change(s)',
            $ok, $failed, count($firstRun), count($changed)
        ));

        // A failure in any tenant is a non-zero exit, so a scheduler or CI notices.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
