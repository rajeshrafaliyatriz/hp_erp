<?php

namespace App\Domain\AgentGateway;

use App\Domain\AgentGateway\Tools\Text;
use App\Domain\OrgAccounts\OrgAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The gateway between a model's answer and the records (slice 7, thin). The model proposes; this decides. For every proposal it checks, in order:
 *
 *   1. the tool exists            - one of the three in ToolRegistry; any other name (send_email, delete_org, ...) is `unknown_tool`
 *   2. nothing steers the target  - a field that names another organisation, an assignee or a recipient drops the proposal (`out_of_scope_field`); a
 *                                   `target` that is not the subject drops it (`target_outside_subject`). The target is the subject, always.
 *   3. the tool's own rules       - field lengths, a real due date inside the window, a pair that is really one of the suggestions
 *   4. the evidence               - it cites at least one record and EVERY record it cites was in the context the model was sent
 *                                   (`unknown_evidence_ref` otherwise): a proposal resting on something the model made up is thrown away whole
 *   5. the policy                 - the autonomy level of the action for this organisation: `off` refuses it (`policy_off`)
 *   6. never twice                - the same proposal (subject + action + payload hash) is never stored again, whatever became of the first
 *   7. the decision               - level `alone`: carried out now through the same applier a person's Accept uses, marked as the agent's;
 *                                   anything else waits as a pending proposal for a person
 *
 * Every proposal, kept or thrown away, leaves a row in g2g_agent_tool_calls with its verdict. A refusal is not an error: the run goes on.
 */
final class Gateway
{
    /** Fields that would steer a write somewhere else. A proposal that carries one is not trusted at all. */
    private const STEERING_FIELDS = [
        'organization_id', 'organisation_id', 'org_account_id', 'account_id', 'crm_organization_id', 'entity_id', 'entity_type', 'sub_institute_id', 'tenant', 'tenant_id',
        'assignee', 'assigned_to', 'owner', 'owner_id', 'user_id', 'users', 'to', 'cc', 'bcc', 'recipient', 'recipients', 'channel', 'subject_id',
    ];

    /** How many entries past the limit are still audited one by one (the rest are only counted). */
    private const AUDITED_OVERFLOW = 20;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AutonomyPolicy $policy,
        private readonly ProposalApplier $applier,
    ) {
    }

    /**
     * @param  array<int|string, mixed>  $rawProposals  what the model returned under "proposals"
     * @return array{applied: int, proposed: int, denied: int}
     */
    public function handle(int $tenantId, int $runId, int $actorId, OrgAccount $subject, AgentContext $context, array $rawProposals, AgentAudit $audit): array
    {
        $result = ['applied' => 0, 'proposed' => 0, 'denied' => 0];
        $limit = (int) config('agent_actions.max_proposals', 5);
        $index = 0;

        foreach ($rawProposals as $raw) {
            $index++;
            if ($index > $limit + self::AUDITED_OVERFLOW) {
                $result['denied']++;

                continue;
            }
            if ($index > $limit) {
                $audit->record('(over_limit)', ['index' => $index], 'denied', 'over_limit');
                $result['denied']++;

                continue;
            }

            $name = is_array($raw) && is_string($raw['action'] ?? null) ? $raw['action'] : '(none)';
            try {
                if (! is_array($raw)) {
                    throw new Rejected('not_an_object', 'A proposal is not a JSON object.');
                }
                $tool = $this->tools->find($name) ?? throw new Rejected('unknown_tool', "'{$name}' is not an action that exists.");
                $action = $this->validate($tool, $raw, $subject, $context);
                $outcome = $this->admit($tenantId, $runId, $actorId, $subject, $tool->name(), $action, $audit, $raw);
            } catch (Rejected $r) {
                $audit->record($name, is_array($raw) ? $this->safeArgs($raw) : ['value' => 'not an object'], 'denied', $r->reason);
                $result['denied']++;

                continue;
            } catch (\Throwable $e) {
                // Something unexpected (a database failure, a bug) while judging or applying ONE proposal. It must not take the brief and the other
                // proposals down with it, and what it was is for the log, never for the screen. Whatever it had written was rolled back with its
                // transaction: a proposal row that had been stored stays pending, so a person can still decide it.
                Log::error('agent.proposal_failed', ['run' => $runId, 'tenant' => $tenantId, 'action' => $name, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
                $audit->record($name, is_array($raw) ? $this->safeArgs($raw) : ['value' => 'not an object'], 'error', 'internal_error');
                $result['denied']++;

                continue;
            }
            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * Checks 2 to 4: a proposal's own fields. Never writes.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws Rejected
     */
    public function validate(Tools\AgentTool $tool, array $raw, OrgAccount $subject, AgentContext $context): ValidatedAction
    {
        foreach (self::STEERING_FIELDS as $field) {
            if (array_key_exists($field, $raw)) {
                throw new Rejected('out_of_scope_field', "A proposal may not carry '{$field}': it acts on this organisation only.");
            }
        }
        if (array_key_exists('target', $raw) && ! $this->targetIsSubject($raw['target'], $subject, $context)) {
            throw new Rejected('target_outside_subject', 'The proposal names a target other than this organisation.');
        }

        $checked = $tool->validate($raw, $context);

        $evidence = $this->evidence($raw['evidence'] ?? null, $context);
        foreach ($checked['extra_evidence'] as $extra) {
            if (! in_array(AgentContext::key($extra['type'], $extra['id']), array_map(fn ($e) => AgentContext::key($e['type'], $e['id']), $evidence), true)) {
                $ref = $context->ref($extra['type'], $extra['id']);
                if ($ref !== null) {
                    $evidence[] = $ref;
                }
            }
        }

        $reason = Text::plain($raw['reason'] ?? null, false);

        return new ValidatedAction($tool->name(), $checked['payload'], $checked['hash'], $checked['entity_type'], $checked['entity_id'], $evidence, mb_substr($reason, 0, (int) config('agent_actions.max_reason_chars', 300)));
    }

    /**
     * Checks 5 to 7: stores the proposal and, when the level is `alone`, carries it out now.
     *
     * @param  array<string, mixed>  $raw
     * @return 'applied'|'proposed'|'denied'
     *
     * @throws Rejected
     */
    private function admit(int $tenantId, int $runId, int $actorId, OrgAccount $subject, string $toolName, ValidatedAction $action, AgentAudit $audit, array $raw): string
    {
        $level = $this->policy->level($tenantId, $toolName);
        if ($level === AutonomyPolicy::OFF) {
            throw new Rejected('policy_off', 'This kind of action is switched off for the organisation.');
        }

        $existing = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('subject_type', 'org_account')->where('subject_id', $subject->id)
            ->where('action', $toolName)->where('payload_hash', $action->hash)->value('status');
        if ($existing !== null) {
            throw new Rejected($existing === 'dismissed' ? 'dismissed_before' : 'duplicate', 'The same proposal was already made.');
        }
        if ($level !== AutonomyPolicy::ALONE) {
            $pending = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('subject_type', 'org_account')->where('subject_id', $subject->id)->where('status', 'pending')->count();
            if ($pending >= (int) config('agent_actions.max_pending_per_subject', 10)) {
                throw new Rejected('too_many_pending', 'Too many proposals are waiting for a person on this organisation.');
            }
        }

        $now = now();
        try {
            $proposalId = (int) DB::table('g2g_agent_proposals')->insertGetId([
                'sub_institute_id' => $tenantId, 'run_id' => $runId, 'subject_type' => 'org_account', 'subject_id' => $subject->id,
                'action' => $toolName, 'entity_type' => $action->entityType, 'entity_id' => $action->entityId,
                'payload' => (string) json_encode($action->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'payload_hash' => $action->hash,
                'evidence' => (string) json_encode($action->evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'reason' => $action->reason !== '' ? $action->reason : null,
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two runs raced to make the same proposal: the unique key let one in.
            throw new Rejected('duplicate', 'The same proposal was already made.');
        }

        $args = $this->safeArgs($raw);
        if ($level !== AutonomyPolicy::ALONE) {
            $audit->record($toolName, $args, 'proposed', null, $proposalId);

            return 'proposed';
        }

        try {
            $applied = $this->applier->apply($tenantId, $proposalId, $actorId, 'policy');
        } catch (AgentRefused $e) {
            // The agent was switched off between the check above and now. The proposal stays as it is, pending, for a person.
            $audit->record($toolName, $args, 'error', $e->reason, $proposalId);

            return 'denied';
        }
        if ($applied['outcome'] === ProposalApplier::APPLIED) {
            $audit->record($toolName, $args, 'applied', null, $proposalId, $applied['proposal']->result_ref);

            return 'applied';
        }
        // The write was refused (a clash, a record that has gone): the proposal is kept as failed, with the reason, and nothing was written.
        $audit->record($toolName, $args, 'error', 'apply_failed', $proposalId);

        return 'denied';
    }

    /** @param mixed $target */
    private function targetIsSubject(mixed $target, OrgAccount $subject, AgentContext $context): bool
    {
        if (! is_array($target)) {
            return false;
        }
        $type = $target['type'] ?? null;
        $id = $target['id'] ?? null;
        if (! is_string($type) && ! is_int($type)) {
            return false;
        }
        if (! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
            return false;
        }
        $id = (int) $id;

        return ($type === 'org_account' && $id === (int) $subject->id)
            || ($type === 'crm_organization' && $context->crmOrganizationId !== null && $id === $context->crmOrganizationId);
    }

    /**
     * The refs a proposal cites, taken from the CONTEXT (so the label and address are ours, not the model's).
     *
     * @return list<array{type: string, id: string, label: string, url: ?string}>
     *
     * @throws Rejected
     */
    public function evidence(mixed $cited, AgentContext $context): array
    {
        if (! is_array($cited) || $cited === []) {
            throw new Rejected('no_evidence', 'A proposal must cite the records it rests on.');
        }
        if (count($cited) > (int) config('agent_actions.max_evidence_refs', 8)) {
            throw new Rejected('too_many_evidence_refs', 'A proposal cites too many records.');
        }
        $out = [];
        foreach ($cited as $item) {
            if (is_string($item) && str_contains($item, ':')) {
                [$type, $id] = explode(':', $item, 2);
                $item = ['type' => $type, 'id' => $id];
            }
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (! is_string($type) || ! (is_string($id) || is_int($id))) {
                throw new Rejected('unknown_evidence_ref', 'A citation is not a record reference.');
            }
            $ref = $context->ref($type, (string) $id);
            if ($ref === null) {
                throw new Rejected('unknown_evidence_ref', "'{$type}:{$id}' was not among the records the agent was shown.");
            }
            $out[$type . ':' . $id] = $ref;
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed> the proposal as it goes into the audit row: a size-bounded copy
     */
    private function safeArgs(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $out[mb_substr((string) $key, 0, 40)] = is_scalar($value) || $value === null ? (is_string($value) ? mb_substr($value, 0, 500) : $value) : mb_substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 500);
        }

        return array_slice($out, 0, 20, true);
    }
}
