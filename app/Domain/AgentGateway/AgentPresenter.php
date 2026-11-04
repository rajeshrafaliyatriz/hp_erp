<?php

namespace App\Domain\AgentGateway;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The JSON the agent API answers with (shapes in g2gv0 services/agent/types.ts). Read only; every query carries the tenant. Names of people are looked
 * up in one batch, in the caller's organisation only.
 *
 * `null` always means "not known" or "does not apply", never zero or empty: a run whose cost could not be priced has `cost: null`.
 */
final class AgentPresenter
{
    public function __construct(private readonly int $tenantId)
    {
    }

    /** @return array<string, mixed> */
    public function proposal(object $row, ?array $names = null): array
    {
        $names ??= $this->userNames([$row->decided_by ?? null]);
        $payload = json_decode((string) $row->payload, true);
        $payload = is_array($payload) ? $payload : [];
        $evidence = json_decode((string) ($row->evidence ?? ''), true);

        return [
            'id' => (int) $row->id,
            'run_id' => (int) $row->run_id,
            'action' => (string) $row->action,
            'status' => (string) $row->status,
            'summary' => $this->summary((string) $row->action, $payload),
            'payload' => $payload,
            'reason' => $row->reason,
            'evidence' => is_array($evidence) ? $evidence : [],
            // 'policy' = the autonomy setting let the agent do it alone; 'user' = a person accepted it.
            'applied_via' => $row->applied_via,
            'applied_at' => $this->iso($row->applied_at),
            'decided_by' => $row->decided_by !== null ? (int) $row->decided_by : null,
            'decided_by_name' => $row->decided_by !== null ? ($names[(int) $row->decided_by] ?? null) : null,
            'decided_at' => $this->iso($row->decided_at),
            'decision_note' => $row->decision_note,
            'result_ref' => $row->result_ref,
            'error' => $row->error,
            'created_at' => $this->iso($row->created_at),
        ];
    }

    /**
     * One row of the tenant-wide queue of proposals (S7b): the proposal exactly as the organisation panel shows it, plus what a person needs to decide
     * without opening the organisation: the action's name in words and the organisation it is about.
     *
     * @param  array<int, string>  $names
     * @param  array<int, array<string, mixed>>  $organisations  from organisations()
     * @return array<string, mixed>
     */
    public function queueRow(object $row, array $names, array $organisations, bool $canDecide): array
    {
        $subject = (int) $row->subject_id;

        return $this->proposal($row, $names) + [
            'label' => $this->actionLabel((string) $row->action),
            // A proposal about an organisation that has gone is shown as such, with the name it had: the history stays readable and the person can still dismiss it.
            'organisation' => $organisations[$subject] ?? ['id' => $subject, 'name' => null, 'href_hint' => null, 'removed' => true],
            'can' => ['decide' => $canDecide],
        ];
    }

    /** The action in plain words (config/agent_actions.php `labels`); the key itself when no wording is set. */
    public function actionLabel(string $action): string
    {
        return (string) config("agent_actions.labels.{$action}.label", $action);
    }

    /**
     * The organisations a set of proposals are about, by identity id, this tenant's only. A removed identity (soft-deleted) is still named, as `removed`; one
     * that is not there at all is not in the answer. `href_hint` is the CRM organisation's page, and only when that CRM record is still there.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, href_hint: ?string, removed: bool}>
     */
    public function organisations(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $accounts = DB::table('g2g_org_accounts')->where('sub_institute_id', $this->tenantId)->whereIn('id', $ids)->get(['id', 'name', 'crm_organization_id', 'deleted_at']);
        $crmIds = $accounts->pluck('crm_organization_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $liveCrm = $crmIds === [] ? [] : DB::table('crm_organizations')->where('sub_institute_id', $this->tenantId)->whereIn('id', $crmIds)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();

        $out = [];
        foreach ($accounts as $a) {
            $removed = $a->deleted_at !== null;
            $crm = $a->crm_organization_id !== null ? (int) $a->crm_organization_id : null;
            $out[(int) $a->id] = [
                'id' => (int) $a->id, 'name' => (string) $a->name,
                'href_hint' => ! $removed && $crm !== null && in_array($crm, $liveCrm, true) ? "/module/crm/marketing/organizations/{$crm}" : null,
                'removed' => $removed,
            ];
        }

        return $out;
    }

    /**
     * A run in the list: no brief, no proposals, just what the history table shows.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $orgNames
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public function runSummary(object $run, array $names, array $orgNames, array $counts): array
    {
        return [
            'id' => (int) $run->id,
            'agent_key' => (string) $run->agent_key,
            'trigger' => (string) $run->trigger_source,
            'status' => (string) $run->status,
            // A name is looked up for an organisation subject only. An Ask run's subject is a conversation, whose id is a number from another table: reading
            // it as an organisation's id would hang an unrelated organisation's name on the run.
            'subject' => ['type' => (string) $run->subject_type, 'id' => (int) $run->subject_id, 'name' => $run->subject_type === 'org_account' ? ($orgNames[(int) $run->subject_id] ?? null) : null],
            'user_id' => $run->user_id !== null ? (int) $run->user_id : null,
            'user_name' => $run->user_id !== null ? ($names[(int) $run->user_id] ?? null) : null,
            'started_at' => $this->iso($run->started_at),
            'finished_at' => $this->iso($run->finished_at),
            'provider' => $run->provider,
            'model' => $run->model,
            'input_tokens' => $run->input_tokens !== null ? (int) $run->input_tokens : null,
            'output_tokens' => $run->output_tokens !== null ? (int) $run->output_tokens : null,
            'cost' => $run->cost !== null ? (float) $run->cost : null,
            'cost_currency' => $run->cost_currency,
            'tool_calls' => (int) $run->tool_calls,
            'error_code' => $run->error_code,
            'message' => $run->error_message,
            // Always the four statuses, always in this order, whatever order the database grouped them in: the screen reads these by name and the
            // contract is one shape.
            'proposal_counts' => [
                'pending' => (int) ($counts['pending'] ?? 0), 'applied' => (int) ($counts['applied'] ?? 0),
                'dismissed' => (int) ($counts['dismissed'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
            ],
        ];
    }

    /**
     * One run with its brief, its proposals and its audit trail.
     *
     * @return array<string, mixed>
     */
    public function runDetail(object $run): array
    {
        $proposals = DB::table('g2g_agent_proposals')->where('sub_institute_id', $this->tenantId)->where('run_id', $run->id)->orderBy('id')->get();
        $calls = DB::table('g2g_agent_tool_calls')->where('sub_institute_id', $this->tenantId)->where('run_id', $run->id)->orderBy('seq')
            ->get(['seq', 'tool', 'outcome', 'deny_reason', 'proposal_id', 'result_ref', 'latency_ms']);
        $names = $this->userNames(array_merge([$run->user_id], $proposals->pluck('decided_by')->all()));
        $orgNames = $this->orgNames([(int) $run->subject_id]);
        $counts = $proposals->groupBy('status')->map->count()->all();
        $evidence = json_decode((string) ($run->brief_evidence ?? ''), true);

        return $this->runSummary($run, $names, $orgNames, array_map('intval', $counts)) + [
            'brief' => $run->brief,
            'brief_evidence' => is_array($evidence) ? $evidence : [],
            'proposals' => $proposals->map(fn ($p) => $this->proposal($p, $names))->values()->all(),
            'trace' => $calls->map(fn ($c) => [
                'seq' => (int) $c->seq, 'tool' => (string) $c->tool, 'outcome' => (string) $c->outcome, 'deny_reason' => $c->deny_reason,
                'proposal_id' => $c->proposal_id !== null ? (int) $c->proposal_id : null, 'result_ref' => $c->result_ref, 'latency_ms' => $c->latency_ms !== null ? (int) $c->latency_ms : null,
            ])->values()->all(),
        ];
    }

    /** @return string a short line a person can scan: what the action is */
    public function summary(string $action, array $payload): string
    {
        return match ($action) {
            'add_note' => 'Add a note: ' . mb_substr((string) ($payload['text'] ?? ''), 0, 140),
            'add_task' => 'Add a task: ' . (string) ($payload['title'] ?? '') . (isset($payload['due_date']) ? ' (due ' . $payload['due_date'] . ')' : ''),
            'confirm_link' => 'Confirm they are the same organisation: ' . ($payload['left']['name'] ?? '?') . ' and ' . ($payload['right']['name'] ?? '?'),
            default => $action,
        };
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<int, string> user id => name, for people of this organisation only
     */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($v) => $v !== null)))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $this->tenantId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $u) {
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            $out[(int) $u->id] = $name !== '' ? $name : 'User #' . $u->id;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> identity id => name (a deleted identity keeps its name in the history)
     */
    public function orgNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('g2g_org_accounts')->where('sub_institute_id', $this->tenantId)->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * Per-run proposal counts by status, in one query.
     *
     * @param  Collection<int, object>|list<object>  $runs
     * @return array<int, array<string, int>>
     */
    public function countsFor(Collection|array $runs): array
    {
        $ids = collect($runs)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('g2g_agent_proposals')->where('sub_institute_id', $this->tenantId)->whereIn('run_id', $ids)->selectRaw('run_id, status, COUNT(*) as n')->groupBy('run_id', 'status')->get() as $r) {
            $out[(int) $r->run_id][(string) $r->status] = (int) $r->n;
        }

        return $out;
    }

    public function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
