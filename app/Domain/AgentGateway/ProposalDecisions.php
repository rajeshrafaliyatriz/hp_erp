<?php

namespace App\Domain\AgentGateway;

use App\Domain\AgentGateway\Tools\Text;
use Illuminate\Support\Facades\DB;

/**
 * What a person does with a pending proposal: accept it or dismiss it (slice 7, thin). Each is IDEMPOTENT and each is final.
 *
 *   accept   carries the action out through ProposalApplier, as the person: the same tool, the same shared write service, the same events as an action
 *            the agent did alone. Accepting twice writes once and answers the same both times. Accepting what was dismissed is refused.
 *   dismiss  stores an optional reason and ends the proposal. The proposal's key stays in the unique index, so the agent can never offer it again.
 *            Dismissing twice is the same as once. Dismissing what was applied is refused.
 *
 * The caller has already checked the person's right to edit Organizations. Both read the proposal by id AND tenant: another organisation's id is
 * ProposalNotFound, the same as one that never existed.
 */
final class ProposalDecisions
{
    public const DISMISSED = 'dismissed';

    public const ALREADY_DISMISSED = 'already_dismissed';

    public function __construct(private readonly ProposalApplier $applier, private readonly AgentEvents $events)
    {
    }

    /**
     * @return array{outcome: string, proposal: object, error: ?string}
     *
     * @throws ProposalNotFound
     * @throws AgentRefused
     */
    public function accept(int $tenantId, int $userId, int $proposalId): array
    {
        return $this->applier->apply($tenantId, $proposalId, $userId, 'user');
    }

    /**
     * @return array{outcome: string, proposal: object}
     *
     * @throws ProposalNotFound
     */
    public function dismiss(int $tenantId, int $userId, int $proposalId, ?string $reason): array
    {
        $note = Text::plain($reason, false);
        $note = $note === '' ? null : mb_substr($note, 0, 500);

        $row = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $proposalId)->first();
        if ($row === null) {
            throw new ProposalNotFound();
        }

        $claimed = DB::transaction(function () use ($tenantId, $userId, $row, $note) {
            $now = now();
            $changed = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $row->id)->where('status', 'pending')->update([
                'status' => 'dismissed', 'decided_by' => $userId, 'decided_at' => $now, 'decision_note' => $note, 'updated_at' => $now,
            ]);
            if ($changed === 1) {
                $this->events->proposalDecided($tenantId, (int) $row->id, $userId, [
                    'decision' => 'dismissed', 'action' => $row->action, 'run_id' => (int) $row->run_id, 'subject_id' => (int) $row->subject_id, 'has_reason' => $note !== null,
                ]);
            }

            return $changed === 1;
        });

        $now = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $proposalId)->first();
        if ($claimed) {
            return ['outcome' => self::DISMISSED, 'proposal' => $now];
        }

        return ['outcome' => $now->status === 'dismissed' ? self::ALREADY_DISMISSED : ProposalApplier::NOT_PENDING, 'proposal' => $now];
    }
}
