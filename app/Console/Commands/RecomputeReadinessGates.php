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

        $tenants = $only !== null
            ? [(int) $only]
            : DB::table('tenant_readiness_gate')->distinct()->pluck('sub_institute_id')->all();

        if (!$tenants) {
            $this->warn('No tenants have readiness gates. Nothing to recompute.');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        $changed = [];

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

            foreach ($after as $gate => $state) {
                if (($before[$gate] ?? null) !== $state) {
                    $changed[] = sprintf('tenant %d · %s · %s -> %s', $tenant, $gate, $before[$gate] ?? 'new', $state);
                }
            }
        }

        if (!$this->option('quiet-summary')) {
            foreach ($changed as $line) {
                $this->info('  ' . $line);
            }
        }

        $this->info(sprintf(
            'readiness:recompute - %d tenant(s) ok, %d failed, %d gate state change(s)',
            $ok, $failed, count($changed)
        ));

        // A failure in any tenant is a non-zero exit, so a scheduler or CI notices.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
