<?php

namespace App\Domain\AgentGateway;

use App\Domain\OrgAccounts\OrgAccount;
use Illuminate\Support\Facades\DB;

/**
 * Carries out ONE stored proposal, exactly once (slice 7, thin). The single path both an `alone` action and a person's Accept go through, so the two
 * cannot drift apart: the same tool, the same service, the same events.
 *
 * EXACTLY ONCE. The proposal is CLAIMED with one conditional UPDATE (`status = pending` becomes `applied`) in the same transaction as the write it
 * stands for and its events. Two clicks, or a click and a retry, race on that UPDATE: InnoDB lets one through and the other updates zero rows, so the
 * write happens once. If the write is refused (a conflict, a record that has gone), the transaction rolls back, the claim with it, and the proposal is
 * then marked `failed` with the reason; if anything unexpected is thrown, it rolls back and stays `pending`.
 *
 * The target of the write is re-derived from the subject inside the tool, and the autonomy level is read again: a proposal made yesterday does not
 * outlive a kill switch or a setting turned off today.
 */
final class ProposalApplier
{
    public const APPLIED = 'applied';

    public const ALREADY_APPLIED = 'already_applied';

    public const NOT_PENDING = 'not_pending';

    public const FAILED = 'failed';

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AutonomyPolicy $policy,
        private readonly AgentEvents $events,
    ) {
    }

    /**
     * @param  string  $via  'policy' (the autonomy level let the agent do it) | 'user' (a person accepted it)
     * @return array{outcome: string, proposal: object, error: ?string}
     *
     * @throws ProposalNotFound
     * @throws AgentRefused when the agent, or this action, is switched off
     */
    public function apply(int $tenantId, int $proposalId, int $actorId, string $via): array
    {
        $row = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $proposalId)->first();
        if ($row === null) {
            throw new ProposalNotFound();
        }
        if ($row->status === 'applied') {
            return ['outcome' => self::ALREADY_APPLIED, 'proposal' => $row, 'error' => null];
        }
        if ($row->status !== 'pending') {
            return ['outcome' => self::NOT_PENDING, 'proposal' => $row, 'error' => null];
        }

        if ($this->policy->killed($tenantId)) {
            throw new AgentRefused('agent_off', 'The agent is switched off for your organisation.', 409);
        }
        if ($this->policy->level($tenantId, (string) $row->action) === AutonomyPolicy::OFF) {
            throw new AgentRefused('policy_off', 'This kind of action is switched off for your organisation.', 409);
        }

        $tool = $this->tools->find((string) $row->action);
        $subject = OrgAccount::query()->where('sub_institute_id', $tenantId)->find((int) $row->subject_id);
        if ($tool === null || $subject === null) {
            return $this->fail($tenantId, $row, $actorId, $via, $tool === null ? 'This action is no longer supported.' : 'The organisation was not found. It may have been removed.');
        }

        $payload = json_decode((string) $row->payload, true);
        $payload = is_array($payload) ? $payload : [];

        try {
            DB::transaction(function () use ($tenantId, $row, $actorId, $via, $tool, $subject, $payload) {
                $now = now();
                $claimed = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $row->id)->where('status', 'pending')->update([
                    'status' => 'applied', 'applied_via' => $via, 'applied_at' => $now, 'updated_at' => $now,
                    'decided_by' => $via === 'user' ? $actorId : null, 'decided_at' => $via === 'user' ? $now : null,
                ]);
                if ($claimed !== 1) {
                    throw new LostTheClaim();
                }

                $ref = $tool->apply($tenantId, $actorId, $subject, $payload, (string) ($row->reason ?? ''));
                DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $row->id)->update(['result_ref' => mb_substr($ref, 0, 80)]);

                if ($via === 'user') {
                    $this->events->proposalDecided($tenantId, (int) $row->id, $actorId, ['decision' => 'accepted', 'action' => $row->action, 'run_id' => (int) $row->run_id, 'subject_id' => (int) $row->subject_id]);
                }
                $this->events->actionApplied($tenantId, (int) $row->id, $actorId, ['action' => $row->action, 'run_id' => (int) $row->run_id, 'subject_id' => (int) $row->subject_id, 'via' => $via, 'result_ref' => $ref]);
            });
        } catch (LostTheClaim) {
            // Somebody else got there first. Report what they did.
            $now = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $proposalId)->first();

            return ['outcome' => $now->status === 'applied' ? self::ALREADY_APPLIED : self::NOT_PENDING, 'proposal' => $now, 'error' => null];
        } catch (Rejected $e) {
            return $this->fail($tenantId, $row, $actorId, $via, $e->getMessage());
        }

        return ['outcome' => self::APPLIED, 'proposal' => DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $proposalId)->first(), 'error' => null];
    }

    /** @return array{outcome: string, proposal: object, error: ?string} */
    private function fail(int $tenantId, object $row, int $actorId, string $via, string $message): array
    {
        $now = now();
        DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $row->id)->where('status', 'pending')->update([
            'status' => 'failed', 'error' => mb_substr($message, 0, 500), 'updated_at' => $now,
            'decided_by' => $via === 'user' ? $actorId : null, 'decided_at' => $via === 'user' ? $now : null,
        ]);

        return ['outcome' => self::FAILED, 'proposal' => DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('id', $row->id)->first(), 'error' => $message];
    }
}
