<?php

namespace App\Services\Talent;

use App\Services\Platform\ApprovalEngine;
use Illuminate\Support\Facades\DB;

/**
 * `talent.recruitment.requisition` enforcement — the last of the 8 declared
 * Workflow points. `talent_job_postings` had nothing a chain could attach to
 * before this: a flat active/inactive toggle, no requester, no pending
 * state — see the paired migration's docblock for why a new `'Requested'`
 * enum member was needed rather than reusing `'Draft'`.
 *
 * Same shape as every other Round 4 adapter: a thin wrapper naming this
 * domain's `FLOW_KEY`/`SUBJECT_TYPE` over the shared `ApprovalEngine`. See
 * `ApprovalEngine`'s own class note for what it does and does not do.
 *
 * `talent_jobpostingcontroller::store()` had no internal sign-off of any
 * kind before this — it created the posting live in one action — so a chain
 * here is this point's first real gate, not a narrowing of one.
 */
class RequisitionApprovalWorkflow
{
    private const FLOW_KEY = 'talent.recruitment.requisition';
    private const SUBJECT_TYPE = 'talent_job_posting';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /** @return array<int, string> */
    public function openFor(int $postingId, int $tenantId, array $context = []): array
    {
        return $this->engine->openFor($tenantId, self::FLOW_KEY, self::SUBJECT_TYPE, $postingId, $context);
    }

    /**
     * Whether this tenant has an active chain here at all — checked BEFORE
     * the posting row exists, unlike every other adapter's `openFor()` (which
     * needs a real subject id to freeze steps onto). A posting has nothing
     * to gate before it exists, so `store()` must know whether to create it
     * `'Requested'` or `'Active'` up front rather than creating it live and
     * downgrading it a moment later.
     */
    public function wouldApply(int $tenantId, array $context = []): bool
    {
        return $this->engine->chainFor($tenantId, self::FLOW_KEY, $context) !== [];
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $postingId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $postingId);
    }

    public function currentStep(int $postingId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $postingId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $postingId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $postingId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $postingId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $postingId);
    }

    /**
     * `department_id` of the posting being requested. Fails closed to `[]`
     * when the posting cannot be found, matching every other adapter's
     * `conditionContextFor()`.
     *
     * @return array<string, float>
     */
    public function conditionContextFor(int $postingId, int $tenantId): array
    {
        $departmentId = DB::table('talent_job_postings')
            ->where('id', $postingId)
            ->where('sub_institute_id', $tenantId)
            ->value('department_id');

        return ($departmentId !== null && is_numeric($departmentId))
            ? ['department_id' => (float) $departmentId]
            : [];
    }
}
