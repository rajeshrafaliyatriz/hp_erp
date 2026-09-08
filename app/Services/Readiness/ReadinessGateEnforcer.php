<?php

namespace App\Services\Readiness;

use Illuminate\Support\Facades\DB;

/**
 * THE FIRST ENFORCEMENT POINT. One place, so the next gate does not grow its own.
 *
 * A gate has been a gauge nobody read. This is what makes it mean something: a
 * feature asks `allows()` and refuses when the answer is no.
 *
 * ── WHY THIS EXISTS BEFORE FeatureGateApplier ───────────────────────────────
 *
 * Building the applier first would mean building the consumer of an event before
 * anything consumes the applier's own output - which is exactly how
 * FeatureGateApplier became a paper reactor in the first place. A reader has to
 * exist before a reactor has anyone to react for.
 *
 * ── THREE STATES, AND NULL IS NOT ONE OF THEM ───────────────────────────────
 *
 *   ready    allow.
 *   at_risk  ALLOW. The whole point of §4.1's asymmetry: falling below the
 *            threshold starts a warning, it does not switch anything off. Only a
 *            human acknowledgement moves a gate to blocked.
 *   blocked  REFUSE, with the reason and the remedy - UNLESS the measurement
 *            passes its enable threshold, which means the gate is waiting out
 *            its sustained period rather than failing. See check().
 *
 *   NO ROW, or value IS NULL  ->  ALLOW.
 *
 * **A GATE NOBODY HAS COMPUTED HAS MADE NO CLAIM.** Treating never-computed as
 * blocked would let a feature be switched off by the absence of a measurement,
 * which is the unmeasured-as-zero error wearing a different hat. The recomputer
 * has to have actually looked before its silence means anything.
 *
 * ── THE REFUSAL SAYS WHY AND WHAT WOULD FIX IT ──────────────────────────────
 *
 * A feature that silently returns nothing when a gate blocks it is the same error
 * again: the customer cannot tell "no gaps" from "gap reporting is off". The
 * refusal carries the gate's measured value, its threshold, and the per-gate
 * remedy string §4 specifies.
 */
class ReadinessGateEnforcer
{
    /**
     * @return array{allowed:bool, gate:?string, state:?string, value:?float,
     *               threshold:?float, remedy:?string, reason:?string}
     */
    public function check(int $tenant, string $gateKey): array
    {
        $allow = ['allowed' => true, 'gate' => $gateKey, 'state' => null, 'value' => null,
                  'threshold' => null, 'remedy' => null, 'reason' => null];

        $row = DB::table('tenant_readiness_gate')
            ->where('sub_institute_id', $tenant)->where('gate_key', $gateKey)->first();

        // NEVER COMPUTED. No row at all, or a row whose value was never measured.
        // Allow, and say so - a caller that wants to distinguish "gate is fine"
        // from "gate has never run" can read `state`.
        if (!$row || $row->value === null) {
            $allow['state'] = $row->state ?? 'never_computed';
            $allow['reason'] = 'gate has never been computed - it has made no claim';
            return $allow;
        }

        // ready and at_risk both allow. at_risk MUST allow: that is the asymmetry.
        if ($row->state !== 'blocked') {
            $allow['state'] = $row->state;
            $allow['value'] = (float) $row->value;
            $allow['threshold'] = (float) $row->enable_threshold;
            return $allow;
        }

        /*
         * ── BLOCKED BUT PASSING: THE WARM-UP ────────────────────────────────
         *
         * `blocked` carries two different claims and this one is not a failure.
         *
         * A gate needs `sustained_periods` CONSECUTIVE passes before the
         * recomputer will call it ready, and the schedule runs once a day - so a
         * gate whose measurement is ABOVE its enable threshold still reads
         * `blocked` for the first three days. FIRST_RUN_NOTE says exactly that
         * about the display, and nobody followed it here: this method was
         * refusing the feature for those three days.
         *
         * The consequence was invisible only because the nightly job skipped
         * every tenant that had no gates. The moment that was fixed, ANY tenant
         * measured for the first time would have had gap reporting, role
         * assignment and course recommendations SWITCHED OFF FOR THREE DAYS -
         * including one whose data passes every threshold.
         *
         *     TURNING A CAPABILITY OFF IS NEVER AUTOMATIC. That is the design's
         *     own asymmetry, and a warm-up counter is not a customer decision.
         *
         * So the distinction is drawn on the measurement, not the label: a value
         * at or above the enable threshold has met the standard and is allowed
         * while it waits out the sustained period. A value below it is genuinely
         * short and is refused, with the remedy, exactly as before.
         */
        if ((float) $row->value >= (float) $row->enable_threshold) {
            $allow['state'] = $row->state;
            $allow['value'] = (float) $row->value;
            $allow['threshold'] = (float) $row->enable_threshold;
            $allow['reason'] = sprintf(
                'gate passes its threshold and is waiting out %d consecutive computations before it reads ready (%d so far)',
                (int) $row->sustained_periods,
                (int) $row->consecutive_passes
            );
            return $allow;
        }

        return [
            'allowed'   => false,
            'gate'      => $gateKey,
            'state'     => 'blocked',
            'value'     => (float) $row->value,
            'threshold' => (float) $row->enable_threshold,
            'remedy'    => $row->remedy,
            'reason'    => sprintf(
                'This needs %s at %s%s or above. It is currently %s%s.',
                str_replace('_', ' ', $gateKey),
                rtrim(rtrim((string) $row->enable_threshold, '0'), '.'),
                $row->unit === 'percent' ? '%' : '',
                rtrim(rtrim((string) $row->value, '0'), '.'),
                $row->unit === 'percent' ? '%' : ''
            ),
        ];
    }

    /** Convenience for a controller: the 409 body a blocked feature should return. */
    public function refusalPayload(array $check): array
    {
        return [
            'status'    => false,
            'blocked_by_readiness_gate' => $check['gate'],
            'message'   => $check['reason'],
            'remedy'    => $check['remedy'],
            'value'     => $check['value'],
            'threshold' => $check['threshold'],
        ];
    }
}
