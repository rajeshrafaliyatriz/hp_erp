<?php

namespace App\Services\Talent;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `talent.mobility.transfer` enforcement — "sign-off before an employee moves
 * team." The real move happens at the transition into 'Completed'
 * (`MobilityTransferController::completeTransferInProfile()` is what actually
 * writes the employee's new department/job role), so that is the one
 * transition this gates — the same "gate the one consequential transition,
 * leave the rest of a free-form status field alone" shape
 * `OffboardingClearanceApprovalWorkflow` already uses.
 *
 * See `ApprovalEngine`'s own class note for what it does and does not do.
 *
 * ── PAIRED WITH A REAL FIX, NOT JUST ENFORCEMENT ─────────────────────────────
 *
 * `store()`'s validator used to accept `status` directly from the request —
 * `'status' => 'required|string|in:Pending,Approved,Completed,Cancelled'` —
 * so a caller could create an ALREADY-'Completed' transfer with zero review,
 * which immediately ran `completeTransferInProfile()` and rewrote the
 * employee's real department/job-role. This round removes that: `store()`
 * always creates 'Pending' server-side now, whether or not a chain is
 * configured. The chain narrows an already-safe default further; it does
 * not repair a hole an unenforced tenant would still have.
 */
class MobilityTransferApprovalWorkflow
{
    private const FLOW_KEY = 'talent.mobility.transfer';
    private const SUBJECT_TYPE = 'mobility_transfer';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /**
     * A no-op when steps already exist — the same guard the other five
     * domains use so a caller re-requesting completion cannot collide with
     * the unique (subject_type, subject_id, step_order) constraint.
     *
     * @return array<int, string>
     */
    public function openFor(int $transferId, int $tenantId): array
    {
        if ($this->engine->stepsFor(self::SUBJECT_TYPE, $transferId) !== []) {
            return [];
        }

        return $this->engine->openFor(
            $tenantId,
            self::FLOW_KEY,
            self::SUBJECT_TYPE,
            $transferId,
            $this->conditionContextFor($transferId, $tenantId)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $transferId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $transferId);
    }

    public function currentStep(int $transferId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $transferId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $transferId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $transferId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $transferId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $transferId);
    }

    /**
     * `department_id` of the destination department — the real fact a
     * "transfer this many levels/departments" condition would name. Fails
     * closed to `[]` when the row cannot be found.
     *
     * @return array<string, float>
     */
    private function conditionContextFor(int $transferId, int $tenantId): array
    {
        $row = DB::table('s_mobility_transfers')
            ->where('id', $transferId)
            ->where('sub_institute_id', $tenantId)
            ->first(['to_department_id']);

        if ($row === null || $row->to_department_id === null || ! is_numeric($row->to_department_id)) {
            return [];
        }

        return ['department_id' => (float) $row->to_department_id];
    }
}
