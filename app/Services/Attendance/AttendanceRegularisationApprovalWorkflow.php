<?php

namespace App\Services\Attendance;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `hrms.attendance.regularisation` enforcement — the first of six points wired
 * onto the generic `ApprovalEngine`. See `ApprovalEngine`'s own class note for
 * what it does and does not do; this class only supplies what is genuinely
 * domain-specific: this point's flow/subject identity, and its own
 * `conditionContextFor()`.
 *
 * ── NO SETTINGS-PATH FALLBACK ─────────────────────────────────────────────
 *
 * Unlike leave, attendance regularisation has no legacy tenant-wide
 * configuration screen to fall back to — `AttendanceRegularisationApiController`'s
 * existing single-decision flow (`ResolvesLeaveAuthority`'s `approve_leave`
 * permission + scope) IS the fallback: when no active platform chain is
 * configured, `openFor()` opens nothing and the controller's pre-existing
 * behaviour is exactly what happens, unchanged.
 */
class AttendanceRegularisationApprovalWorkflow
{
    private const FLOW_KEY = 'hrms.attendance.regularisation';
    private const SUBJECT_TYPE = 'attendance_regularisation';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /** @return array<int, string> the flat role list, empty when nothing is enforced */
    public function openFor(int $regularisationId, int $tenantId): array
    {
        return $this->engine->openFor(
            $tenantId,
            self::FLOW_KEY,
            self::SUBJECT_TYPE,
            $regularisationId,
            $this->conditionContextFor($regularisationId, $tenantId)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $regularisationId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $regularisationId);
    }

    public function currentStep(int $regularisationId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $regularisationId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $regularisationId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $regularisationId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $regularisationId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $regularisationId);
    }

    /**
     * The real facts this request offers `WorkflowConditionEvaluator` — just
     * `department_id`, the same organisational fact `LeaveApprovalWorkflow`
     * falls back to when nothing richer exists. Fails closed to `[]` when the
     * row cannot be found, matching leave's own `conditionContextFor()`.
     *
     * @return array<string, float>
     */
    private function conditionContextFor(int $regularisationId, int $tenantId): array
    {
        $row = DB::table('hrms_attendance_regularisations')
            ->where('id', $regularisationId)
            ->where('sub_institute_id', $tenantId)
            ->first(['user_id']);

        if ($row === null || $row->user_id === null) {
            return [];
        }

        $departmentId = DB::table('tbluser')->where('id', $row->user_id)->value('department_id');

        return ($departmentId !== null && is_numeric($departmentId))
            ? ['department_id' => (float) $departmentId]
            : [];
    }
}
