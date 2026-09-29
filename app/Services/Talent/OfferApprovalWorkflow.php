<?php

namespace App\Services\Talent;

use App\Services\Platform\ApprovalEngine;

/**
 * `talent.recruitment.offer` enforcement — the internal sign-off BEFORE an
 * offer is sent to a candidate, distinct from the candidate's own
 * accept/decline (`TalentOfferController::accept()`/`reject()`), which this
 * does not touch at all.
 *
 * See `ApprovalEngine`'s own class note for what it does and does not do.
 * `TalentOfferController::store()` had no internal sign-off of any kind
 * before this — it created and emailed an offer in the same action — so a
 * chain here is this point's first real gate, not a narrowing of one.
 */
class OfferApprovalWorkflow
{
    private const FLOW_KEY = 'talent.recruitment.offer';
    private const SUBJECT_TYPE = 'talent_offer';

    public function __construct(
        private readonly ApprovalEngine $engine = new ApprovalEngine(),
    ) {
    }

    /** @return array<int, string> */
    public function openFor(int $offerId, int $tenantId, array $context = []): array
    {
        return $this->engine->openFor($tenantId, self::FLOW_KEY, self::SUBJECT_TYPE, $offerId, $context);
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(int $offerId): array
    {
        return $this->engine->stepsFor(self::SUBJECT_TYPE, $offerId);
    }

    public function currentStep(int $offerId): ?array
    {
        return $this->engine->currentStep(self::SUBJECT_TYPE, $offerId);
    }

    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        return $this->engine->roleMayDecide($step, $roleKey, $userId);
    }

    /** @return array<string, mixed> */
    public function recordDecision(
        int $offerId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        return $this->engine->recordDecision(self::SUBJECT_TYPE, $offerId, $step, $decision, $context, $comment);
    }

    public function closeOpenSteps(int $offerId): int
    {
        return $this->engine->closeOpenSteps(self::SUBJECT_TYPE, $offerId);
    }

    /**
     * `salary` when the field parses as a clean number — it is stored as a
     * free-text varchar(100), not guaranteed numeric (currency symbols,
     * ranges, "negotiable" are all real values recruiters type here), so this
     * fails closed to `[]` rather than guessing at a value that was never
     * meant to be parsed.
     *
     * @return array<string, float>
     */
    public function conditionContextFor(?string $salary): array
    {
        if ($salary === null) {
            return [];
        }

        $numeric = preg_replace('/[^\d.]/', '', $salary);

        return ($numeric !== '' && is_numeric($numeric)) ? ['salary' => (float) $numeric] : [];
    }
}
