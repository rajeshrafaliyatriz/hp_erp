<?php

namespace App\Services\Readiness;

use Illuminate\Support\Facades\DB;

/**
 * X-07c — the ONE transition `ReadinessGateRecomputer` deliberately cannot make:
 * `at_risk` -> `blocked`. (`05-data-flow-contracts.md` §4.1)
 *
 * ── THE ACKNOWLEDGEMENT RECORD IS THE ONLY THING THAT DISABLES A GATE ───────
 *
 * Not the elapsed clock. Not a low measurement. Not this class deciding the
 * warning has gone on long enough. **A GATE DISABLED WITH NO ACKNOWLEDGEMENT ROW
 * WOULD BE THE SYSTEM TAKING A DECISION A HUMAN SHOULD TAKE** - the eighth
 * instance of the principle that runs through this phase.
 *
 * So `acknowledged_by` and `acknowledged_at` are written in the SAME statement
 * that sets `blocked`. There is no path here that writes one without the other,
 * and `assertDisableIsAttributable()` exists to prove that from the data rather
 * than from reading this sentence.
 *
 * ── THE WARNING PERIOD IS A COLUMN, NOT A CONSTANT ─────────────────────────
 *
 * `warning_days` lives on the row, per gate and per tenant. A hospital group and
 * a twelve-person company do not get the same grace period on the same gauge, and
 * a default written into this file would quietly override a value an admin set.
 * **THIS CLASS NEVER SUPPLIES ITS OWN NUMBER.** The schema default (14) is
 * visible in the table and alterable per row; it is not a constant in this file
 * that would silently override a value an admin set.
 */
class ReadinessGateAcknowledger
{
    public const OK                = 'acknowledged';
    public const NOT_AT_RISK       = 'gate is not at_risk - only an at_risk gate can be disabled';
    public const WARNING_RUNNING   = 'warning period has not elapsed';
    public const NO_WARNING_PERIOD = 'the warning clock never started - refusing to disable';
    public const NO_SUCH_GATE      = 'no such gate for this tenant';

    /**
     * @return array{status:string, blocked:bool, days_remaining:?int}
     */
    public function acknowledge(int $tenant, string $gateKey, int $userId): array
    {
        $row = DB::table('tenant_readiness_gate')
            ->where('sub_institute_id', $tenant)->where('gate_key', $gateKey)->first();

        if (!$row) {
            return ['status' => self::NO_SUCH_GATE, 'blocked' => false, 'days_remaining' => null];
        }

        // ONLY an at_risk gate can be disabled. A ready gate is working; a
        // blocked one is already off. Acknowledging either would be a no-op
        // dressed as a decision.
        if ($row->state !== 'at_risk') {
            return ['status' => self::NOT_AT_RISK, 'blocked' => false, 'days_remaining' => null];
        }

        // The clock is per row. No fallback in this file: see the class comment.
        //
        // `warning_days` is NOT NULL with a schema default of 14, so a null check
        // here would guard a state the data cannot reach. THE DEFAULT LIVES IN
        // THE SCHEMA ON PURPOSE - visible in the table, alterable per row by an
        // admin, and not a number hidden in this class that would silently
        // override one they set.
        $days = (int) $row->warning_days;

        // at_risk_since CAN be null: a row hand-edited to at_risk without the
        // clock ever starting. Refuse - a warning period that never began is not
        // a warning period that elapsed.
        if ($row->at_risk_since === null) {
            return ['status' => self::NO_WARNING_PERIOD, 'blocked' => false, 'days_remaining' => null];
        }

        $elapsedAt = \Carbon\Carbon::parse($row->at_risk_since)->addDays($days);
        if (now()->lt($elapsedAt)) {
            return [
                'status'         => self::WARNING_RUNNING,
                'blocked'        => false,
                'days_remaining' => (int) ceil(now()->diffInHours($elapsedAt) / 24),
            ];
        }

        // BOTH conditions met. The state and the attribution are written
        // together - there is no ordering in which one lands without the other.
        DB::table('tenant_readiness_gate')
            ->where('id', $row->id)
            ->update([
                'state'           => 'blocked',
                'acknowledged_by' => $userId,
                'acknowledged_at' => now(),
                'updated_at'      => now(),
            ]);

        return ['status' => self::OK, 'blocked' => true, 'days_remaining' => 0];
    }

    /**
     * INVARIANT, checkable from the data: no gate is `blocked` after having been
     * `at_risk` without an acknowledgement naming who and when.
     *
     * A gate that was never ready is legitimately `blocked` with no
     * acknowledgement - it was never on, so nobody turned it off. The offence is
     * a gate that HAS an `at_risk_since` and is now blocked with no attribution.
     *
     * @return array<int,string> offending rows, empty when the invariant holds
     */
    public function assertDisableIsAttributable(): array
    {
        return DB::table('tenant_readiness_gate')
            ->where('state', 'blocked')
            ->whereNotNull('at_risk_since')
            ->where(function ($q) {
                $q->whereNull('acknowledged_by')->orWhereNull('acknowledged_at');
            })
            ->get()
            ->map(fn ($r) => "tenant {$r->sub_institute_id} / {$r->gate_key}: blocked after at_risk with no acknowledgement")
            ->all();
    }
}
