<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\Agents\GtmAgentService;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Marketing\CampaignMembers;
use App\Domain\Gtm\Outreach\OutreachDrafts;
use Illuminate\Support\Facades\DB;

/**
 * Executes ONE action of a workflow run. Every action:
 *  - reads and writes only within the run's tenant;
 *  - marks anything it audits with metadata {source: workflow} (so the engine ignores the resulting event and a workflow
 *    can never trigger itself) and attributes it to the SYSTEM, not to a person;
 *  - never sends email. The only way a workflow reaches outreach is by saving DRAFTS, which still need a person to
 *    submit and approve them.
 */
final class WorkflowActions
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array{values: array<string, mixed>, ids: array<string, int>}  $ctx
     * @return array<string, mixed> what happened, stored on the step
     */
    public function run(string $type, array $params, array $ctx, object $run, object $workflow): array
    {
        $tenant = (int) $run->sub_institute_id;
        $meta = ['source' => 'workflow', 'workflow_run_id' => (int) $run->id, 'workflow_id' => (int) $workflow->id];

        return match ($type) {
            'log_activity' => $this->logActivity($tenant, $params, $ctx, $meta),
            'set_next_step' => $this->setNextStep($tenant, $params, $ctx, $meta),
            'run_agent' => $this->runAgent($tenant, $params, $ctx, $workflow),
            'add_to_campaign' => $this->addToCampaign($tenant, $params, $ctx, $meta),
            'change_account_stage' => $this->changeStage($tenant, $params, $ctx, $meta),
            default => throw new \RuntimeException("Unknown action '{$type}'."),
        };
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function logActivity(int $tenant, array $p, array $ctx, array $meta): array
    {
        $a = GtmActivity::create([
            'sub_institute_id' => $tenant, 'account_id' => $ctx['ids']['account'], 'deal_id' => $ctx['ids']['deal'] ?? null, 'contact_id' => $ctx['ids']['contact'] ?? null,
            'type' => in_array($p['type'] ?? 'note', ['note', 'task'], true) ? ($p['type'] ?? 'note') : 'note',
            'subject' => mb_substr(WorkflowCatalog::render((string) $p['subject'], $ctx['values']), 0, 255),
            'body' => isset($p['body']) ? mb_substr(WorkflowCatalog::render((string) $p['body'], $ctx['values']), 0, 2000) : null,
            'occurred_at' => now(), 'user_id' => null, 'metadata' => ['workflow_run_id' => $meta['workflow_run_id']],
        ]);
        GtmAudit::record('gtm.activity.logged', $tenant, 'gtm_activities', $a->id, null, ['via' => 'workflow'], $meta);

        return ['activity_id' => (int) $a->id];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function setNextStep(int $tenant, array $p, array $ctx, array $meta): array
    {
        $deal = DB::table('gtm_deals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['deal'] ?? 0);
        if (! $deal || ! in_array($deal->stage, ['discovery', 'qualification', 'proposal', 'negotiation'], true)) {
            throw new StepSkipped('The deal is not open, so its next step was left alone.');
        }
        if (($p['only_if_empty'] ?? true) && trim((string) $deal->next_step) !== '') {
            throw new StepSkipped('The deal already has a next step, which was left alone.');
        }
        $text = mb_substr(WorkflowCatalog::render((string) $p['text'], $ctx['values']), 0, 1000);
        DB::table('gtm_deals')->where('id', $deal->id)->where('sub_institute_id', $tenant)->update([
            'next_step' => $text, 'next_step_due' => isset($p['due_in_days']) ? now()->addDays((int) $p['due_in_days'])->toDateString() : $deal->next_step_due, 'updated_at' => now(),
        ]);
        GtmAudit::record('gtm.deal.updated', $tenant, 'gtm_deals', (int) $deal->id, null, ['changed' => ['next_step', 'next_step_due'], 'via' => 'workflow'], $meta);

        return ['deal_id' => (int) $deal->id, 'next_step' => $text];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function runAgent(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $input = [];
        foreach ((array) ($p['input'] ?? []) as $k => $v) {
            $input[$k] = is_string($v) && str_starts_with($v, '@') ? ($ctx['values'][substr($v, 1)] ?? null) : $v;
        }
        $r = app(GtmAgentService::class)->run((string) $p['agent'], $tenant, $workflow->created_by ? (int) $workflow->created_by : null, $input);
        if (! $r['ok']) {
            match ($r['code'] ?? '') {
                'no_data' => throw new StepSkipped($r['message'] ?? 'There was nothing to analyse.'),
                'ai_rate_limited', 'ai_provider_busy', 'ai_unavailable' => throw new StepTransient($r['message'] ?? 'The AI provider is unavailable.'),
                default => throw new \RuntimeException($r['message'] ?? 'The agent could not run.'),
            };
        }
        $out = ['agent_run_id' => (int) $r['run_id'], 'analysis_id' => (int) $r['data']['analysis_id']];
        if (! empty($p['save_drafts'])) {
            $d = app(OutreachDrafts::class)->fromAnalysis($tenant, $out['analysis_id'], $workflow->created_by ? (int) $workflow->created_by : null, 'workflow');
            if (! $d['ok']) {
                throw new \RuntimeException("The agent ran, but its draft was not saved: {$d['message']}");
            }
            $out['drafts_saved'] = count($d['messages']);
        }

        return $out;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function addToCampaign(int $tenant, array $p, array $ctx, array $meta): array
    {
        $c = DB::table('gtm_campaigns')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find((int) $p['campaign_id']);
        if (! $c || in_array($c->status, ['completed', 'archived'], true)) {
            throw new \RuntimeException('The campaign no longer exists or is closed.');
        }
        $r = app(CampaignMembers::class)->add($tenant, (int) $c->id, [$ctx['ids']['account']], isset($ctx['ids']['contact']) ? [$ctx['ids']['contact']] : [], null, $meta);
        if ($r['added'] === 0) {
            throw new StepSkipped('It is already a target of that campaign.');
        }

        return ['campaign_id' => (int) $c->id, 'added' => $r['added']];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function changeStage(int $tenant, array $p, array $ctx, array $meta): array
    {
        $a = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['account']);
        if (! $a) {
            throw new \RuntimeException('The account no longer exists.');
        }
        if ($a->stage === $p['stage']) {
            throw new StepSkipped("The account is already {$p['stage']}.");
        }
        DB::transaction(function () use ($a, $p, $tenant) {
            DB::table('gtm_accounts')->where('id', $a->id)->where('sub_institute_id', $tenant)->update(['stage' => $p['stage'], 'updated_at' => now()]);
            GtmActivity::create(['sub_institute_id' => $tenant, 'account_id' => $a->id, 'type' => 'system', 'subject' => "Stage changed: {$a->stage} → {$p['stage']} (workflow)", 'occurred_at' => now()]);
        });
        GtmAudit::record('gtm.account.updated', $tenant, 'gtm_accounts', (int) $a->id, null, ['changed' => ['stage'], 'from' => $a->stage, 'to' => $p['stage'], 'via' => 'workflow'], $meta);

        return ['account_id' => (int) $a->id, 'from' => $a->stage, 'to' => $p['stage']];
    }
}
