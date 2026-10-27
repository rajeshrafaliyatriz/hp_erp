<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\Agents\GtmAgentService;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Marketing\CampaignMembers;
use App\Domain\Crm\Leads\LeadActivity;
use App\Domain\Crm\Leads\LeadAssigner;
use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Leads\LeadFollowUp;
use App\Domain\Crm\Leads\LeadReplyAi;
use App\Domain\Gtm\Outreach\OutreachDrafts;
use App\Services\Events\EventRecorder;
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
            'add_lead_note' => $this->addLeadNote($tenant, $params, $ctx),
            'create_lead_task' => $this->createLeadTask($tenant, $params, $ctx, $workflow),
            'set_lead_status' => $this->setLeadStatus($tenant, $params, $ctx, $meta),
            'assign_lead_round_robin' => $this->assignLead($tenant, $ctx, $meta),
            'start_lead_followup' => $this->startLeadFollowup($tenant, $params, $ctx, $run, $workflow),
            'convert_lead' => $this->convertLead($tenant, $params, $ctx, $workflow),
            'create_support_onboarding' => $this->createSupportOnboarding($tenant, $params, $ctx, $workflow),
            'prepare_quote' => $this->prepareQuote($tenant, $ctx, $workflow),
            'email_quote' => $this->emailQuote($tenant, $params, $ctx, $meta, $workflow),
            'create_sales_order' => $this->createSalesOrder($tenant, $params, $ctx, $workflow),
            'create_invoice' => $this->createInvoice($tenant, $params, $ctx, $workflow),
            'create_invoice_task' => $this->createInvoiceTask($tenant, $params, $ctx, $workflow),
            'add_opportunity_note' => $this->addOpportunityNote($tenant, $params, $ctx),
            'create_opportunity_task' => $this->createOpportunityTask($tenant, $params, $ctx, $workflow),
            'set_opportunity_stage' => $this->setOpportunityStage($tenant, $params, $ctx, $meta, $workflow),
            'classify_lead_reply' => $this->classifyReply($tenant, $ctx),
            'draft_lead_reply' => $this->draftReply($tenant, $ctx),
            'propose_lead_meeting' => $this->proposeMeeting($tenant, $params, $ctx),
            'book_lead_meeting' => $this->bookMeeting($tenant, $ctx, $workflow),
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

    /** The lead this run is about, still in its own tenant, still open. A converted or removed lead is left alone. */
    private function openLead(int $tenant, array $ctx): object
    {
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['lead'] ?? 0);
        if (! $lead) {
            throw new \RuntimeException('The lead no longer exists.');
        }
        if ($lead->converted) {
            throw new StepSkipped('The lead has already been converted, so it was left alone.');
        }

        return $lead;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function addLeadNote(int $tenant, array $p, array $ctx): array
    {
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['lead'] ?? 0);
        if (! $lead) {
            throw new \RuntimeException('The lead no longer exists.');
        }
        $id = LeadActivity::note($tenant, (int) $lead->id, WorkflowCatalog::render((string) $p['text'], $ctx['values']));

        return ['comment_id' => (int) $id, 'lead_id' => (int) $lead->id];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createLeadTask(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $lead = ($p['link_to'] ?? 'lead') === 'lead' ? $this->openLead($tenant, $ctx) : $this->anyLead($tenant, $ctx);
        $title = WorkflowCatalog::render((string) $p['title'], $ctx['values']);
        $desc = isset($p['description']) ? WorkflowCatalog::render((string) $p['description'], $ctx['values']) : null;
        $actor = $workflow->created_by ? (int) $workflow->created_by : null;
        $linkTo = (string) ($p['link_to'] ?? 'lead');
        $target = match ($linkTo) { 'organization' => ['ORGANIZATION', $lead->converted_organization_id], 'opportunity' => ['OPPORTUNITY', $lead->converted_opportunity_id], default => ['LEAD', $lead->id] };
        if ($target[1] === null) {
            throw new StepSkipped("The lead has no converted {$linkTo}, so the task was not created.");
        }
        $id = LeadActivity::taskFor($tenant, $target[0], (int) $target[1], (int) $lead->assigned_to, $title, $desc, (int) ($p['due_in_days'] ?? 1), $actor);
        if ($id === null) {
            throw new StepSkipped('An open task with this title already exists on the lead.');
        }

        return ['event_id' => $id, 'lead_id' => (int) $lead->id, 'owner_id' => (int) $lead->assigned_to];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function setLeadStatus(int $tenant, array $p, array $ctx, array $meta): array
    {
        $lead = $this->openLead($tenant, $ctx);
        $status = (string) $p['status'];
        if (! WorkflowCatalog::leadStatuses()->contains($status)) {
            throw new \RuntimeException("'{$status}' is no longer a Lead Status picklist value.");
        }
        if (trim((string) ($p['only_from'] ?? '')) !== '') {
            $allowed = array_map(fn ($v) => strtolower(trim($v)), explode(',', (string) $p['only_from']));
            $current = strtolower(trim((string) $lead->lead_status));
            if (! in_array($current === '' ? '(blank)' : $current, $allowed, true)) {
                throw new StepSkipped("The lead's status is '{$lead->lead_status}', which is not one this step changes.");
            }
        }
        if ((string) $lead->lead_status === $status) {
            throw new StepSkipped("The lead is already '{$status}'.");
        }
        DB::table('crm_leads')->where('id', $lead->id)->where('sub_institute_id', $tenant)->update(['lead_status' => $status, 'updated_at' => now()]);
        app(EventRecorder::class)->record('crm.lead.updated', $tenant, 'crm_leads', (int) $lead->id, null,
            ['changes' => [['field' => 'lead_status', 'oldValue' => $lead->lead_status, 'newValue' => $status]]], $meta);

        LeadActivity::announceQualified($tenant, $lead, $status, null);

        return ['lead_id' => (int) $lead->id, 'from' => $lead->lead_status, 'to' => $status];
    }

    /** @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function assignLead(int $tenant, array $ctx, array $meta): array
    {
        $lead = $this->openLead($tenant, $ctx);
        if (! in_array(strtolower(trim((string) $lead->lead_status)), ['', 'new', 'not contacted'], true)) {
            throw new StepSkipped('The lead has already been worked, so its owner was left alone.');
        }
        $next = LeadAssigner::next($tenant);
        if ($next === null) {
            throw new StepSkipped('No round-robin rotation is enabled (Master Fields > Lead Scoring & Assignment).');
        }
        if ($next === (int) $lead->assigned_to) {
            throw new StepSkipped('The next person in the rotation already owns the lead.');
        }
        DB::table('crm_leads')->where('id', $lead->id)->where('sub_institute_id', $tenant)->update(['assigned_to' => $next, 'updated_at' => now()]);
        app(EventRecorder::class)->record('crm.lead.updated', $tenant, 'crm_leads', (int) $lead->id, null,
            ['changes' => [['field' => 'assigned_to', 'oldValue' => (int) $lead->assigned_to, 'newValue' => $next]]], $meta);

        return ['lead_id' => (int) $lead->id, 'from' => (int) $lead->assigned_to, 'to' => $next];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function startLeadFollowup(int $tenant, array $p, array $ctx, object $run, object $workflow): array
    {
        $lead = $this->openLead($tenant, $ctx);
        $r = LeadFollowUp::start($tenant, $lead, [
            'subject' => (string) $p['subject'], 'body' => (string) $p['body'], 'intervals' => (string) $p['intervals'], 'max_attempts' => (int) $p['max_attempts'],
            'window_start' => (string) ($p['window_start'] ?? '09:00'), 'window_end' => (string) ($p['window_end'] ?? '17:00'),
            'timezone' => (string) ($p['timezone'] ?? config('app.timezone')), 'weekdays_only' => (bool) ($p['weekdays_only'] ?? true),
        ], $workflow->created_by ? (int) $workflow->created_by : null, (int) $workflow->id, (int) $run->id);
        if (! $r['started']) {
            throw new StepSkipped('The follow-up was not started: '.str_replace('_', ' ', $r['reason']).'.');
        }

        return ['followup_id' => $r['followup_id'], 'lead_id' => (int) $lead->id, 'first_attempt' => $r['outcome']];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function classifyReply(int $tenant, array $ctx): array
    {
        $inbound = (int) ($ctx['values']['event.inbound_id'] ?? 0);
        $ai = app(LeadReplyAi::class);
        try {
            $r = $ai->classify($tenant, $inbound);
        } catch (\App\Domain\AI\Support\AiNotConfiguredException|\App\Domain\AI\Support\AiCredentialsExhaustedException $e) {
            // Nothing will fix this by waiting: say so on the lead so a person reads the reply.
            $r = $ai->unclear($tenant, $inbound, 'No AI model is available to read it ('.mb_substr($e->getMessage(), 0, 100).').');
        } catch (\App\Domain\AI\Support\AiQuotaExceededException $e) {
            throw new StepTransient($e->getMessage());
        } catch (\DomainException $e) {
            throw new StepSkipped($e->getMessage());
        } catch (\Throwable $e) {
            throw new StepTransient('The reply could not be classified: '.mb_substr($e->getMessage(), 0, 200));
        }
        if ($r['already']) {
            throw new StepSkipped("The reply was already classified as {$r['classification']}.");
        }

        return ['inbound_id' => $inbound, 'classification' => $r['classification'], 'confidence' => $r['confidence']];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function proposeMeeting(int $tenant, array $p, array $ctx): array
    {
        if (($ctx['values']['event.classification'] ?? '') !== 'meeting_requested') {
            throw new StepSkipped('The reply is not a meeting request, so no time was proposed.');
        }
        $lead = $this->openLead($tenant, $ctx);
        try {
            $r = \App\Domain\Crm\Leads\LeadMeeting::propose($tenant, (int) $lead->id, (int) ($ctx['values']['event.inbound_id'] ?? 0), array_intersect_key($p, array_flip(['duration_minutes', 'days_ahead', 'slots', 'window_start', 'window_end', 'timezone', 'weekdays_only'])));
        } catch (\DomainException $e) {
            throw new StepSkipped($e->getMessage());
        }
        if ($r['already']) {
            throw new StepSkipped('Times were already proposed for this reply.');
        }

        return ['lead_id' => (int) $lead->id, 'slots' => $r['slots'], 'task_id' => $r['task_id'], 'draft_email_id' => $r['draft_email_id'], 'event_id' => $r['event_id']];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function bookMeeting(int $tenant, array $ctx, object $workflow): array
    {
        $lead = $this->openLead($tenant, $ctx);
        $tz = (string) ($ctx['values']['event.timezone'] ?? (config('crm.meeting.timezone') ?: config('app.timezone')));
        try {
            return \App\Domain\Crm\Leads\LeadMeeting::book($tenant, (int) $lead->id, (string) ($ctx['values']['event.slot_start'] ?? ''), (string) ($ctx['values']['event.slot_end'] ?? ''), $tz, $workflow->created_by ? (int) $workflow->created_by : null);
        } catch (\DomainException $e) {
            LeadActivity::note($tenant, (int) $lead->id, 'The meeting was not booked: '.$e->getMessage());
            throw new StepSkipped($e->getMessage());
        }
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function draftReply(int $tenant, array $ctx): array
    {
        $class = (string) ($ctx['values']['event.classification'] ?? '');
        if (! in_array($class, ['interested', 'needs_information', 'meeting_requested'], true)) {
            throw new StepSkipped("No suggested reply is prepared for a '{$class}' reply.");
        }
        try {
            $id = app(LeadReplyAi::class)->draft($tenant, (int) ($ctx['values']['event.inbound_id'] ?? 0), $class);
        } catch (\App\Domain\AI\Support\AiNotConfiguredException|\App\Domain\AI\Support\AiCredentialsExhaustedException $e) {
            throw new StepSkipped('No AI model is available to prepare a suggested reply.');
        } catch (\App\Domain\AI\Support\AiQuotaExceededException $e) {
            throw new StepTransient($e->getMessage());
        } catch (\DomainException $e) {
            throw new StepSkipped($e->getMessage());
        } catch (\Throwable $e) {
            throw new StepTransient('The suggested reply could not be prepared: '.mb_substr($e->getMessage(), 0, 200));
        }

        return ['draft_email_id' => $id];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function convertLead(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $lead = $this->openLead($tenant, $ctx);
        $required = (string) ($p['required_status'] ?? LeadActivity::QUALIFIED);
        // Approval can arrive days after the trigger: judge the lead as it is NOW, not as it was.
        if (strcasecmp(trim((string) $lead->lead_status), $required) !== 0) {
            throw new StepSkipped("The lead is now '{$lead->lead_status}', not '{$required}', so it was not converted.");
        }
        $gaps = array_keys(array_filter([
            'a company' => trim((string) $lead->company) === '', 'a contact last name' => trim((string) $lead->last_name) === '',
            'an email address or phone number' => trim((string) $lead->email) === '' && trim((string) $lead->phone) === '' && trim((string) $lead->mobile) === '',
        ]));
        if ($gaps !== []) {
            LeadActivity::note($tenant, (int) $lead->id, 'Conversion was held back: the lead still needs '.implode(', ', $gaps).'. Nothing was filled in automatically.');
            LeadActivity::task($tenant, $lead, "Complete details before conversion ({$lead->lead_no})", 'Needs '.implode(', ', $gaps).'. Add them from a verified source, then convert the lead.', 0, null);
            throw new StepSkipped('The lead is missing '.implode(', ', $gaps).', so it was not converted.');
        }
        $actor = $workflow->created_by ? (int) $workflow->created_by : (int) $lead->assigned_to;
        $r = LeadConverter::convert($lead, $tenant, $actor, (int) $lead->assigned_to, true, true, null, (bool) ($p['create_opportunity'] ?? true));
        LeadActivity::note($tenant, (int) $lead->id, sprintf('Converted by automation: Organization #%s, Contact #%s%s. Owner: user #%d.', $r['organization_id'], $r['contact_id'],
            $r['opportunity_id'] ? ", Opportunity #{$r['opportunity_id']}" : ' (no Opportunity requested)', (int) $lead->assigned_to).($lead->source_company_opportunity_id ? " Originating Signal: #{$lead->source_company_opportunity_id}." : ''));
        LeadConverter::announce($tenant, $lead, $r, $actor);

        return $r + ['lead_id' => (int) $lead->id];
    }

    /** The lead whether or not it has been converted (work on its converted records is exactly what happens AFTER conversion). */
    private function anyLead(int $tenant, array $ctx): object
    {
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['lead'] ?? 0);
        if (! $lead) {
            throw new \RuntimeException('The lead no longer exists.');
        }

        return $lead;
    }

    /** The Opportunity this run is about, still in its own tenant. */
    private function openOpportunity(int $tenant, array $ctx): object
    {
        $o = DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['opportunity'] ?? 0);
        if (! $o) {
            throw new \RuntimeException('The Opportunity no longer exists.');
        }

        return $o;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function addOpportunityNote(int $tenant, array $p, array $ctx): array
    {
        $o = $this->openOpportunity($tenant, $ctx);
        $id = (int) DB::table('crm_opportunity_comments')->insertGetId([
            'opportunity_id' => $o->id, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            'comment' => '[Automation] '.mb_substr(WorkflowCatalog::render((string) $p['text'], $ctx['values']), 0, 1980),
        ]);

        return ['comment_id' => $id, 'opportunity_id' => (int) $o->id];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createOpportunityTask(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $o = $this->openOpportunity($tenant, $ctx);
        if ($o->assigned_to === null) {
            throw new StepSkipped('The Opportunity has no owner, so no task was created.');
        }
        $id = LeadActivity::taskFor($tenant, 'OPPORTUNITY', (int) $o->id, (int) $o->assigned_to, WorkflowCatalog::render((string) $p['title'], $ctx['values']),
            isset($p['description']) ? WorkflowCatalog::render((string) $p['description'], $ctx['values']) : null, (int) ($p['due_in_days'] ?? 1), $workflow->created_by ? (int) $workflow->created_by : null);
        if ($id === null) {
            throw new StepSkipped('An open task with this title already exists on the Opportunity.');
        }

        return ['event_id' => $id, 'opportunity_id' => (int) $o->id, 'owner_id' => (int) $o->assigned_to];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function setOpportunityStage(int $tenant, array $p, array $ctx, array $meta, object $workflow): array
    {
        $o = $this->openOpportunity($tenant, $ctx);
        $stage = (string) $p['stage'];
        if (! WorkflowCatalog::opportunityStages()->contains($stage)) {
            throw new \RuntimeException("'{$stage}' is no longer a Sales Stage picklist value.");
        }
        if (! \App\Domain\Crm\Sales\OpportunityEvents::isOpen($o->sales_stage)) {
            throw new StepSkipped("The Opportunity is already '{$o->sales_stage}', so its stage was left alone.");
        }
        if (trim((string) ($p['only_from'] ?? '')) !== '') {
            $allowed = array_map(fn ($v) => strtolower(trim($v)), explode(',', (string) $p['only_from']));
            $current = strtolower(trim((string) $o->sales_stage));
            if (! in_array($current === '' ? '(blank)' : $current, $allowed, true)) {
                throw new StepSkipped("The Opportunity's stage is '{$o->sales_stage}', which is not one this step changes.");
            }
        }
        if ((string) $o->sales_stage === $stage) {
            throw new StepSkipped("The Opportunity is already '{$stage}'.");
        }
        $update = ['sales_stage' => $stage, 'updated_at' => now()];
        if ($stage === 'Closed Lost') {
            $reason = trim(WorkflowCatalog::render((string) ($p['reason'] ?? ''), $ctx['values']));
            if ($reason === '') {
                throw new \RuntimeException('Closed Lost needs a reason and none was given.');
            }
            $update['close_reason'] = mb_substr($reason, 0, 1000);
        }
        $actor = $workflow->created_by ? (int) $workflow->created_by : 0;
        DB::transaction(function () use ($o, $update, $stage, $tenant, $actor) {
            DB::table('crm_opportunity_stage_history')->insert([
                'opportunity_id' => $o->id, 'from_stage' => $o->sales_stage, 'to_stage' => $stage, 'amount' => $o->amount, 'probability' => $o->probability,
                'closing_date' => $o->closing_date, 'changed_by' => $actor ?: null, 'created_at' => now(),
            ]);
            DB::table('crm_opportunities')->where('id', $o->id)->where('sub_institute_id', $tenant)->update($update);
        });
        \App\Domain\Crm\Sales\OpportunityEvents::stageChanged($tenant, (int) $o->id, $o->sales_stage, $stage, null, $meta);

        return ['opportunity_id' => (int) $o->id, 'from' => $o->sales_stage, 'to' => $stage];
    }

    private function actor(object $workflow, ?int $fallback): int
    {
        return $workflow->created_by ? (int) $workflow->created_by : (int) $fallback;
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function prepareQuote(int $tenant, array $ctx, object $workflow): array
    {
        $o = $this->openOpportunity($tenant, $ctx);
        $r = \App\Domain\Crm\Sales\QuoteBuilder::fromOpportunity($o, $tenant, $this->actor($workflow, $o->assigned_to), $o->assigned_to !== null ? (int) $o->assigned_to : null);
        if (! $r['ok']) {
            DB::table('crm_opportunity_comments')->insert(['opportunity_id' => $o->id, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
                'comment' => '[Automation] A draft Quote was not prepared: '.$r['reason'].'. Nothing was guessed - fix it and prepare the Quote by hand.']);
            throw new StepSkipped('No Quote was prepared: '.$r['reason'].'.');
        }
        DB::table('crm_opportunity_comments')->insert(['opportunity_id' => $o->id, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            'comment' => "[Automation] A draft Quote (#{$r['quote_id']}) was prepared from this Opportunity's products at their list prices. Review it before sending."]);

        return ['quote_id' => $r['quote_id'], 'opportunity_id' => (int) $o->id];
    }

    private function openQuote(int $tenant, array $ctx): object
    {
        $q = DB::table('crm_quotes')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['quote'] ?? 0);
        if (! $q) {
            throw new \RuntimeException('The Quote no longer exists.');
        }

        return $q;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @param array<string, mixed> $meta @return array<string, mixed> */
    private function emailQuote(int $tenant, array $p, array $ctx, array $meta, object $workflow): array
    {
        $q = $this->openQuote($tenant, $ctx);
        if (! in_array(strtolower(trim((string) $q->quote_stage)), ['draft', 'revised', ''], true)) {
            throw new StepSkipped("The Quote is now '{$q->quote_stage}', so it was not emailed.");
        }
        $contact = $q->contact_id ? DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($q->contact_id) : null;
        if (! $contact || ! filter_var(trim((string) $contact->email), FILTER_VALIDATE_EMAIL)) {
            throw new StepSkipped('The Quote has no contact with a valid email address, so it was not emailed.');
        }
        if ($contact->email_opt_out) {
            throw new StepSkipped('The contact has opted out of email, so the Quote was not emailed.');
        }
        $lines = DB::table('crm_quote_line_items')->where('quote_id', $q->id)->orderBy('sequence_no')->get();
        if ($lines->isEmpty() || (float) $q->total <= 0) {
            throw new StepSkipped('The Quote has no line items or total, so it was not emailed.');
        }
        $values = ($ctx['values'] ?? []);
        $table = $lines->map(fn ($l) => sprintf('- %s x %s @ %s = %s', rtrim(rtrim(number_format((float) $l->quantity, 2, '.', ''), '0'), '.'), $l->description ?: 'item', number_format((float) $l->unit_price, 2), number_format((float) $l->line_total, 2)))->implode("\n");
        $body = trim(WorkflowCatalog::render((string) $p['body'], $values))."\n\nQuote {$q->quote_no}".($q->currency ? " ({$q->currency})" : '')."\n{$table}\nTotal: ".number_format((float) $q->total, 2);
        $emailId = \App\Services\Crm\CrmEmailQueue::create($tenant, 'CONTACT', (int) $contact->id, trim((string) $contact->email), WorkflowCatalog::render((string) $p['subject'], $values), $body,
            $q->assigned_to !== null ? (int) $q->assigned_to : null, $this->actor($workflow, $q->assigned_to), $q->opportunity_id ? [['OPPORTUNITY', (int) $q->opportunity_id]] : []);
        $r = \App\Services\Crm\CrmEmailQueue::deliver($emailId, 'CONTACT', (int) $contact->id, $this->actor($workflow, $q->assigned_to));
        if ($r['ok']) {
            DB::table('crm_quotes')->where('id', $q->id)->where('sub_institute_id', $tenant)->update(['quote_stage' => 'Sent', 'updated_at' => now()]);
            DB::table('crm_quote_status_history')->insert(['quote_id' => $q->id, 'from_stage' => $q->quote_stage, 'to_stage' => 'Sent', 'changed_by' => $workflow->created_by ?: null, 'created_at' => now()]);
            \App\Domain\Crm\Sales\SalesEvents::stageChanged('quote', $tenant, (int) $q->id, $q->quote_stage, 'Sent', null, $meta);
        }

        return ['email_id' => $emailId, 'sent' => $r['ok'], 'not_sent_because' => $r['ok'] ? null : $r['message'], 'quote_stage' => $r['ok'] ? 'Sent' : $q->quote_stage];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createSalesOrder(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $q = $this->openQuote($tenant, $ctx);
        $need = (string) ($p['required_stage'] ?? 'Accepted');
        if (strcasecmp(trim((string) $q->quote_stage), $need) !== 0) {
            throw new StepSkipped("The Quote is now '{$q->quote_stage}', not '{$need}', so no Sales Order was created.");
        }
        if (! DB::table('crm_quote_line_items')->where('quote_id', $q->id)->exists() || (float) $q->total <= 0) {
            throw new StepSkipped('The Quote has no line items or total, so no Sales Order was created.');
        }
        if (DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->where('quote_id', $q->id)->whereNull('deleted_at')->exists()) {
            throw new StepSkipped('A Sales Order already exists for this Quote.');
        }
        $id = \App\Domain\Crm\Sales\SalesDocuments::salesOrderFromQuote($q, $tenant, $this->actor($workflow, $q->assigned_to), $q->assigned_to !== null ? (int) $q->assigned_to : null);

        return ['sales_order_id' => $id, 'quote_id' => (int) $q->id];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createInvoice(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $so = DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['sales_order'] ?? 0);
        if (! $so) {
            throw new \RuntimeException('The Sales Order no longer exists.');
        }
        if (isset($p['required_stage']) && strcasecmp(trim((string) $so->so_stage), (string) $p['required_stage']) !== 0) {
            throw new StepSkipped("The Sales Order is now '{$so->so_stage}', not '{$p['required_stage']}', so no Invoice was created.");
        }
        if (! DB::table('crm_sales_order_line_items')->where('sales_order_id', $so->id)->exists() || (float) $so->total <= 0) {
            throw new StepSkipped('The Sales Order has no line items or total, so no Invoice was created.');
        }
        if (DB::table('crm_invoices')->where('sub_institute_id', $tenant)->where('sales_order_id', $so->id)->whereNull('deleted_at')->exists()) {
            throw new StepSkipped('An Invoice already exists for this Sales Order.');
        }
        $id = \App\Domain\Crm\Sales\SalesDocuments::invoiceFromSalesOrder($so, $tenant, $this->actor($workflow, $so->assigned_to), $so->assigned_to !== null ? (int) $so->assigned_to : null);
        if (isset($p['due_in_days'])) {
            DB::table('crm_invoices')->where('id', $id)->update(['due_date' => now()->addDays((int) $p['due_in_days'])->toDateString()]);
        }

        return ['invoice_id' => $id, 'sales_order_id' => (int) $so->id, 'due_date' => isset($p['due_in_days']) ? now()->addDays((int) $p['due_in_days'])->toDateString() : null];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createInvoiceTask(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $i = DB::table('crm_invoices')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($ctx['ids']['invoice'] ?? 0);
        if (! $i) {
            throw new \RuntimeException('The Invoice no longer exists.');
        }
        if (! $i->organization_id || $i->assigned_to === null) {
            throw new StepSkipped('The Invoice has no Organization or owner to attach a task to.');
        }
        $id = LeadActivity::taskFor($tenant, 'ORGANIZATION', (int) $i->organization_id, (int) $i->assigned_to, WorkflowCatalog::render((string) $p['title'], $ctx['values']),
            isset($p['description']) ? WorkflowCatalog::render((string) $p['description'], $ctx['values']) : null, (int) ($p['due_in_days'] ?? 0), $workflow->created_by ? (int) $workflow->created_by : null);
        if ($id === null) {
            throw new StepSkipped('An open task with this title already exists on the Organization.');
        }

        return ['event_id' => $id, 'organization_id' => (int) $i->organization_id, 'owner_id' => (int) $i->assigned_to];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $ctx @return array<string, mixed> */
    private function createSupportOnboarding(int $tenant, array $p, array $ctx, object $workflow): array
    {
        $src = \App\Domain\Crm\Support\OnboardingTicket::sourcesFor($tenant, array_intersect_key($ctx['ids'], array_flip(['opportunity', 'quote', 'sales_order', 'invoice'])));
        if ($src['organization_id'] === null) {
            throw new StepSkipped('The sale has no Organization, so no onboarding ticket was opened.');
        }
        $r = \App\Domain\Crm\Support\OnboardingTicket::create($tenant, $src['organization_id'], $src['contact_id'], $src['sources'], [
            'title' => WorkflowCatalog::render((string) $p['title'], $ctx['values']), 'assigned_to' => (int) $p['assign_to_user_id'],
            'priority' => $p['priority'] ?? null, 'category' => $p['category'] ?? null, 'faq_category' => $p['faq_category'] ?? null,
        ], $workflow->created_by ? (int) $workflow->created_by : (int) $p['assign_to_user_id']);
        if (! $r['ok']) {
            throw new StepSkipped('No onboarding ticket was opened: '.$r['reason'].'.');
        }

        return ['ticket_id' => $r['ticket_id'], 'organization_id' => $src['organization_id'], 'sources' => $src['sources']];
    }
}
