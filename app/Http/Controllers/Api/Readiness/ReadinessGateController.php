<?php

namespace App\Http\Controllers\Api\Readiness;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Services\Readiness\ReadinessGateAcknowledger;
use App\Services\Readiness\ReadinessGateRecomputer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-07d — the admin surface for readiness gates.
 *
 * ── THE SCREEN MUST SHOW WHAT IS LOST ───────────────────────────────────────
 *
 * `index()` returns, for every at_risk gate: why it is at risk, the measured
 * value against its threshold, the days remaining, and **WHAT THE CUSTOMER LOSES
 * BY ACKNOWLEDGING**. That last field is not decoration.
 *
 * An acknowledgement is the one action that turns a working capability off. **AN
 * ACKNOWLEDGEMENT MADE WITHOUT SEEING THE CONSEQUENCE IS A DECISION TAKEN
 * BLIND** - the same principle as everywhere else in this phase, at the point
 * where a human actually acts rather than where the system does. A confirm
 * dialog that says "are you sure?" and not "this switches off manager approvals
 * for 264 employees" is asking for consent to something unstated.
 *
 * ── AUTHORISATION ───────────────────────────────────────────────────────────
 *
 * Admin and HR only - enforced by the EXISTING `profile:admin,hr` middleware,
 * which already matches exactly on `role_key` and carries the alias map for the
 * 13 legacy profiles that predate it (D-010).
 *
 * THIS CONTROLLER DOES NOT RE-IMPLEMENT THAT CHECK. A second role test living
 * here would be a fifth identity resolver by another name - the same drift that
 * produced four of them, and the one this phase has been consolidating. The
 * route declares the guard; the controller trusts it and resolves only the
 * tenant and actor from identity.
 */
class ReadinessGateController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * WHAT EACH GATE GUARDS, in the customer's terms. Shown before the decision,
     * not after it. Kept beside the endpoint that shows it so the two cannot
     * drift apart silently.
     */
    private const LOSES = [
        'reporting_coverage'  => 'All manager-dependent flows stop: approvals, reporting-line views, and anything that needs to know who someone reports to.',
        'task_hygiene'        => 'Overdue weighting in reporting stops, and overdue task signals are suppressed.',
        'capability_coverage' => 'Gap reporting stops. Competency gaps will not be shown or recalculated.',
        'jobrole_definition'  => 'Gap analysis and automatic role assignment stop.',
        'course_mapping'      => 'The recommendation engine stops suggesting courses.',
    ];

    public function index(Request $request)
    {
        [$tenant, $userId] = [$this->apiTenantId($request), $this->apiUserId($request)];
        if (!$tenant || !$userId) {
            return response()->json(['status' => false, 'message' => 'Unable to resolve identity.'], 401);
        }

        $gates = DB::table('tenant_readiness_gate')
            ->where('sub_institute_id', $tenant)->orderBy('gate_key')->get()
            ->map(function ($g) {
                $daysLeft = null;
                if ($g->state === 'at_risk' && $g->at_risk_since !== null) {
                    $ends = \Carbon\Carbon::parse($g->at_risk_since)->addDays((int) $g->warning_days);
                    $daysLeft = max(0, (int) ceil(now()->diffInHours($ends, false) / 24));
                }

                return [
                    'gate_key'          => $g->gate_key,
                    'state'             => $g->state,
                    'unit'              => $g->unit,
                    // NULL is NEVER COMPUTED, not zero. The screen must render
                    // these differently or it asserts a measurement nobody took.
                    'value'             => $g->value === null ? null : (float) $g->value,
                    'enable_threshold'  => (float) $g->enable_threshold,
                    'disable_threshold' => (float) $g->disable_threshold,
                    'why'               => $g->remedy,
                    'days_remaining'    => $daysLeft,
                    'warning_days'      => (int) $g->warning_days,
                    'acknowledged_by'   => $g->acknowledged_by,
                    'acknowledged_at'   => $g->acknowledged_at,
                    'computed_at'       => $g->computed_at,
                    // THE CONSEQUENCE, sent with the gate rather than looked up
                    // separately, so a screen cannot render the button without it.
                    'losing'            => self::LOSES[$g->gate_key] ?? null,
                    'can_acknowledge'   => $g->state === 'at_risk' && $daysLeft === 0,
                ];
            });

        return response()->json([
            'status' => true,
            'gates'  => $gates,
            'note'   => ReadinessGateRecomputer::FIRST_RUN_NOTE,
        ]);
    }

    public function acknowledge(Request $request, ReadinessGateAcknowledger $ack)
    {
        [$tenant, $userId] = [$this->apiTenantId($request), $this->apiUserId($request)];
        if (!$tenant || !$userId) {
            return response()->json(['status' => false, 'message' => 'Unable to resolve identity.'], 401);
        }

        $request->validate(['gate_key' => 'required|string|max:48']);

        // The acknowledger decides. This method does not re-implement any part of
        // the rule - it supplies the tenant and the ACTOR, and reports the answer.
        // acknowledged_by is $userId from IDENTITY, never from the request.
        $result = $ack->acknowledge($tenant, $request->input('gate_key'), $userId);

        return response()->json([
            'status'  => $result['status'] === ReadinessGateAcknowledger::OK,
            'message' => $result['status'],
            'blocked' => $result['blocked'],
            'days_remaining' => $result['days_remaining'],
        ], $result['status'] === ReadinessGateAcknowledger::OK ? 200 : 409);
    }
}
