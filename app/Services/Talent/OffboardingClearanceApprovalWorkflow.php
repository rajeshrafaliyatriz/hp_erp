<?php

namespace App\Services\Talent;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `talent.offboarding.clearance` enforcement — "sign-off before an exit case
 * is closed." `OffboardingController::updateStatus()` lets a case move to ANY
 * of its six statuses directly with no sequencing or permission check at all,
 * so this gates specifically the transition INTO 'Closed', the one the
 * registry's own subject ("Exit case") and description name — not every
 * status change, and not the per-task clearance checklist
 * (`updateClearance()`), which the investigation behind this round's plan
 * found is only partly data-driven and is explicitly out of scope here.
 *
 * See `ApprovalEngine`'s own class note for what it does and does not do.
 * No role/manager check existed on this transition before this, so a chain
 * here is this point's first real gate, not a narrowing of one.
 */
class OffboardingClearanceApprovalWorkflow
{
    private const FLOW_KEY = 'talent.offboarding.clearance';
    private const SUBJECT_TYPE = 'offboarding_case';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /**
     * Freeze a chain onto this case's closure. A no-op when steps already
     * exist — a caller retrying the same 'Closed' request must not collide
     * with the unique (subject_type, subject_id, step_order) constraint or
     * silently re-freeze an already-decided chain.
     *
     * @return array<int, string>
     */
    public function openFor(int $caseId, int $tenantId): array
    {
        if ($this->engine->stepsFor(self::SUBJECT_TYPE, $caseId) !== []) {
            return [];
        }

        return $this->engine->openFor(
            $tenantId,
            self::FLOW_KEY,
            self::SUBJECT_TYPE,
            $caseId,
            $this->conditionContextFor($caseId, $tenantId)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $caseId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $caseId);
    }

    public function currentStep(int $caseId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $caseId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $caseId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $caseId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $caseId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $caseId);
    }

    /**
     * `department_id` of the exiting employee. Fails closed to `[]` when the
     * case or its employee cannot be found.
     *
     * @return array<string, float>
     */
    private function conditionContextFor(int $caseId, int $tenantId): array
    {
        $case = DB::table('talent_offboarding_cases')
            ->where('id', $caseId)
            ->where('sub_institute_id', $tenantId)
            ->first(['employee_id']);

        if ($case === null || $case->employee_id === null) {
            return [];
        }

        $departmentId = DB::table('tbluser')->where('id', $case->employee_id)->value('department_id');

        return ($departmentId !== null && is_numeric($departmentId))
            ? ['department_id' => (float) $departmentId]
            : [];
    }
}
