<?php

namespace App\Services\Competency;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `competency.assessment.review` enforcement — the registry point's own
 * subject is "Mapping change", which is `s_competency_mapping_reviews`
 * (`MappingReviewController`), NOT the separate `competency`/`framework`
 * queue `Api\Competency\ApprovalController` manages. Confirmed by reading
 * both controllers directly: `ApprovalController`'s own docblock explicitly
 * says role-mapping reviews are "actioned through /competency/mapping-reviews
 * - that flow works and carries mapping-specific context this table has no
 * columns for."
 *
 * See `ApprovalEngine`'s own class note for what it does and does not do.
 * Like `task.execution.approval`, `MappingReviewController::update()` has no
 * role/manager check today — any tenant member may approve or reject any
 * pending review — so a chain here is this point's first real gate, not a
 * narrowing of one.
 */
class MappingReviewApprovalWorkflow
{
    private const FLOW_KEY = 'competency.assessment.review';
    private const SUBJECT_TYPE = 'competency_mapping_review';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /** @return array<int, string> */
    public function openFor(int $reviewId, int $tenantId): array
    {
        return $this->engine->openFor(
            $tenantId,
            self::FLOW_KEY,
            self::SUBJECT_TYPE,
            $reviewId,
            $this->conditionContextFor($reviewId, $tenantId)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $reviewId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $reviewId);
    }

    public function currentStep(int $reviewId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $reviewId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $reviewId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $reviewId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $reviewId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $reviewId);
    }

    /**
     * `department_id`, already a real column on this table — no join needed,
     * unlike the other domains. Fails closed to `[]` when the row is missing.
     *
     * @return array<string, float>
     */
    private function conditionContextFor(int $reviewId, int $tenantId): array
    {
        $row = DB::table('s_competency_mapping_reviews')
            ->where('id', $reviewId)
            ->where('sub_institute_id', $tenantId)
            ->first(['department_id']);

        if ($row === null || $row->department_id === null || ! is_numeric($row->department_id)) {
            return [];
        }

        return ['department_id' => (float) $row->department_id];
    }
}
