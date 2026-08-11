<?php

namespace App\Services\Readiness;

use Illuminate\Support\Facades\DB;

/**
 * X-07b — computes the five readiness gates per tenant and writes their state.
 * (`05-data-flow-contracts.md` §4 and §4.1)
 *
 * WHY THIS EXISTS BEFORE ANY REACTOR. G-EVT-03: a consumer can be the right KIND
 * of thing and still not do the work. `FeatureGateApplier` was declared in the
 * catalogue for months and never written. A gate that DECLARES a state nobody
 * computed would be that finding a fourth time, in the item governing several
 * others - so the computation and its write come first, and the reactor follows.
 *
 * ── FIVE GATES, NOT SIX ─────────────────────────────────────────────────────
 *
 * `task_competency_link` is DROPPED and gets no row. Its map keys into
 * `s_jobrole_task`, a GLOBAL seed library with no tenant column, so it would
 * report THE SAME NUMBER FOR EVERY TENANT. A gauge that reads identically for
 * everyone cannot distinguish a ready tenant from an empty one, which is the only
 * thing a gate exists to do.
 *
 * DROPPING THE GATE DROPPED NO FEATURE. The instance link (`task.skill_id`,
 * 1,514 of 2,271) is live and is golden thread 2's real signal; the catalogue
 * link is an AUTHORING item filled by a customer through X-08's importer.
 *
 * ── ASYMMETRIC SWITCHING (§4.1) ─────────────────────────────────────────────
 *
 * TURNING A GATE ON MAY BE AUTOMATIC. TURNING ONE OFF IS NEVER AUTOMATIC.
 *
 *   blocked ──(passes enable, sustained N times)──► ready
 *   ready   ──(falls below disable)──► at_risk   FEATURE STAYS ON, clock starts
 *   at_risk ──(recovers)──► ready                no acknowledgement needed
 *   at_risk ──(warning elapsed AND acknowledged)──► blocked
 *
 * The failure this prevents is concrete: a bulk import briefly drops
 * `reporting_coverage`, manager approvals disable mid-cycle, and nobody knows
 * why approvals stopped working. **THIS CLASS NEVER RETURNS `blocked` FROM
 * `at_risk` ON ITS OWN.** The acknowledgement is a human act and lives in X-07c.
 */
class ReadinessGateRecomputer
{
    /**
     * `unit` distinguishes the two kinds of gauge. `jobrole_definition` is a
     * COUNT: §4's 70%/55% were written for a population that does not exist -
     * there is no "expected number of job roles" for an organisation - and
     * inventing one would be this product deciding a customer's standard for
     * them. Those thresholds are SUPERSEDED, not silently replaced.
     */
    public const GATES = [
        'reporting_coverage'  => ['enable' => 80, 'disable' => 65, 'unit' => 'percent',
            'remedy' => 'Import your reporting line - :num of :den employees have a manager'],
        'task_hygiene'        => ['enable' => 60, 'disable' => 45, 'unit' => 'percent',
            'remedy' => 'Close or re-date stale tasks - :num of :den are not overdue'],
        'capability_coverage' => ['enable' => 50, 'disable' => 35, 'unit' => 'percent',
            'remedy' => 'Measure capability - :num of :den employees have a measurement'],
        'jobrole_definition'  => ['enable' => 10, 'disable' => 5,  'unit' => 'count',
            'remedy' => 'Import the job-role library - :num roles defined'],
        'course_mapping'      => ['enable' => 50, 'disable' => 35, 'unit' => 'percent',
            'remedy' => 'Map courses to job roles - :num of :den courses mapped'],
    ];

    /** @return array{0:?float,1:int,2:int} value, numerator, denominator */
    private function measure(string $gate, int $tenant): array
    {
        $employees = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();

        switch ($gate) {
            case 'reporting_coverage':
                $n = DB::table('tbluser')->where('sub_institute_id', $tenant)
                    ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '>', 0)->count();
                return [$this->pct($n, $employees), $n, $employees];

            case 'task_hygiene':
                // MEASURED, NOT ASSUMED: `task` has no due_date or end_date
                // column. task_date is the only one that can carry a deadline,
                // and this definition reproduces the phase's known figure
                // exactly - 2,088 of 2,271 overdue platform-wide = 91.9%.
                $d = DB::table('task')->where('sub_institute_id', $tenant)->count();
                $overdue = DB::table('task')->where('sub_institute_id', $tenant)
                    ->whereNotNull('task_date')->where('task_date', '<', now()->toDateString())
                    ->whereNotIn(DB::raw('UPPER(TRIM(status))'), ['COMPLETED', 'COMPLETE', 'DONE', 'CLOSED'])
                    ->count();
                return [$this->pct($d - $overdue, $d), $d - $overdue, $d];

            case 'capability_coverage':
                $n = DB::table('competency_kasba_rating')->where('sub_institute_id', $tenant)
                    ->distinct()->count('user_id');
                return [$this->pct($n, $employees), $n, $employees];

            case 'jobrole_definition':
                // A COUNT. The value IS the numerator; there is no denominator
                // and the row records that with unit='count'.
                $n = DB::table('s_user_jobrole')->where('sub_institute_id', $tenant)->count();
                return [(float) $n, $n, 0];

            case 'course_mapping':
                $d = DB::table('sub_std_map')->where('sub_institute_id', $tenant)->count();
                $n = DB::table('sub_std_map')->where('sub_institute_id', $tenant)
                    ->whereNotNull('jobrole')->where('jobrole', '!=', '')->count();
                return [$this->pct($n, $d), $n, $d];
        }

        return [null, 0, 0];
    }

    private function pct(int $n, int $d): ?float
    {
        // NULL means NOT COMPUTABLE - an empty population, not a failing one.
        // A gate over zero courses is not "blocked"; there is nothing to judge.
        return $d === 0 ? null : round($n * 100 / $d, 2);
    }

    /**
     * Recompute every gate for one tenant. Returns the transitions that actually
     * occurred - the caller emits `readiness_gate.changed` ONLY for these.
     *
     * A nightly "still blocked" event for every gate in every tenant is exactly
     * the noise §2.2 rejects for `competency.gap_detected`.
     */
    public function recompute(int $tenant): array
    {
        $transitions = [];

        foreach (self::GATES as $key => $spec) {
            [$value, $num, $den] = $this->measure($key, $tenant);

            $row = DB::table('tenant_readiness_gate')
                ->where('sub_institute_id', $tenant)->where('gate_key', $key)->first();

            $was = $row->state ?? null;          // NULL = never computed
            $passes = (int) ($row->consecutive_passes ?? 0);

            if ($value === null) {
                // Not computable. The row records the attempt and leaves value
                // NULL - never-computed, which is not the same claim as zero.
                $this->write($tenant, $key, $spec, null, $was ?? 'blocked', 0, $row, null);
                continue;
            }

            $passes = $value >= $spec['enable'] ? $passes + 1 : 0;
            $next = $this->nextState($was, $value, $passes, $spec, $row);

            $remedy = str_replace([':num', ':den'], [$num, $den], $spec['remedy']);
            $this->write($tenant, $key, $spec, $value, $next, $passes, $row, $remedy);

            if ($was !== null && $was !== $next) {
                $transitions[] = ['gate' => $key, 'from' => $was, 'to' => $next, 'value' => $value];
            }
        }

        return $transitions;
    }

    /**
     * THE ASYMMETRY, IN ONE PLACE. Read it as the answer to "can this turn a
     * feature off by itself?" - it cannot.
     */
    private function nextState(?string $was, float $value, int $passes, array $spec, ?object $row): string
    {
        // ON is automatic, but only after sustained_periods consecutive passes,
        // so a mid-import spike does not switch a feature on either.
        $sustained = (int) ($row->sustained_periods ?? 3);

        if ($value >= $spec['enable']) {
            if ($was === 'at_risk') {
                return 'ready';                 // recovery needs no acknowledgement
            }
            return $passes >= $sustained ? 'ready' : ($was ?? 'blocked');
        }

        if ($value < $spec['disable']) {
            // FALLING BELOW DOES NOT DISABLE. A ready gate enters at_risk and the
            // feature STAYS ON until a human acknowledges it in X-07c. Only a
            // gate that was never ready stays blocked.
            return in_array($was, ['ready', 'at_risk'], true) ? 'at_risk' : 'blocked';
        }

        // Between the two thresholds: hysteresis. A gate on the boundary holds
        // whatever it already was rather than flapping.
        return $was ?? 'blocked';
    }

    private function write(int $t, string $key, array $spec, ?float $value, string $state, int $passes, ?object $row, ?string $remedy): void
    {
        $atRisk = $row->at_risk_since ?? null;
        if ($state === 'at_risk' && $atRisk === null) {
            $atRisk = now();                    // the warning clock starts once
        } elseif ($state !== 'at_risk') {
            $atRisk = null;
        }

        DB::table('tenant_readiness_gate')->updateOrInsert(
            ['sub_institute_id' => $t, 'gate_key' => $key],
            [
                'state'              => $state,
                'unit'               => $spec['unit'],
                'value'              => $value,
                'enable_threshold'   => $spec['enable'],
                'disable_threshold'  => $spec['disable'],
                'consecutive_passes' => $passes,
                'at_risk_since'      => $atRisk,
                'remedy'             => $remedy,
                'computed_at'        => now(),
                'updated_at'         => now(),
                'created_at'         => $row->created_at ?? now(),
            ]
        );
    }

    /** Every tenant with employees. Gates are per tenant. */
    public function recomputeAll(): array
    {
        $out = [];
        $tenants = DB::table('tbluser')->where('sub_institute_id', '>', 0)
            ->distinct()->pluck('sub_institute_id');

        foreach ($tenants as $t) {
            $out[(int) $t] = $this->recompute((int) $t);
        }

        return $out;
    }
}
