<?php

namespace App\Domain\Gtm\Monitoring;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\Gtm\GtmAiRunner;
use App\Domain\Gtm\GtmState;
use App\Domain\Gtm\OutreachMailer;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational health of the GTM module for ONE organisation, from the same records the module itself writes.
 *
 * Every check answers three things: is it working (`status`: ok | warn | fail | unknown), what is the evidence (`summary`
 * and `facts`), and - when it is not ok - what to do about it (`fix`). A check never reports ok by default: with no data
 * it says `unknown`. NO secret is ever returned: API keys, tokens, passwords and webhook secrets are reduced to
 * "configured: yes/no"; mail and AI hosts/models are shown because they are not secret.
 */
final class MonitoringService
{
    /** every table the module needs */
    public const TABLES = ['gtm_accounts', 'gtm_contacts', 'gtm_activities', 'gtm_playbooks', 'gtm_playbook_versions', 'gtm_analyses', 'gtm_deals', 'gtm_deal_stage_history', 'gtm_outreach_messages', 'gtm_outreach_attempts',
        'gtm_suppressions', 'gtm_inbound_events', 'gtm_system_state', 'gtm_stage_settings', 'gtm_forecast_snapshots', 'gtm_segments', 'gtm_campaigns', 'gtm_campaign_members', 'gtm_renewals', 'gtm_health_snapshots',
        'gtm_workflows', 'gtm_workflow_runs', 'gtm_workflow_run_steps'];

    public function __construct(private readonly OutreachMailer $mailer, private readonly GtmAiRunner $ai)
    {
    }

    /** @return array<string, mixed> */
    public function report(int $tenant, bool $isAdmin, bool $probeAi = false, bool $probeSmtp = false): array
    {
        $checks = [];
        foreach ([
            fn () => $this->schema(),
            fn () => $this->aiProvider($tenant, $probeAi),
            fn () => $this->webSearch(),
            fn () => $this->agents($tenant),
            fn () => $this->mail($tenant, $probeSmtp),
            fn () => $this->delivery($tenant),
            fn () => $this->inbound($tenant),
            fn () => $this->scheduler(),
            fn () => $this->eventDelivery($tenant),
            fn () => $this->workflows($tenant),
            fn () => $this->queue(),
        ] as $make) {
            try {
                $c = $make();
            } catch (\Throwable $e) {
                // monitoring must never fall over because one probe did
                $c = ['key' => 'internal', 'label' => 'A check could not run', 'status' => 'unknown', 'summary' => 'This check failed to run: '.mb_substr($e->getMessage(), 0, 160), 'facts' => [], 'fix' => 'See the application log for details.'];
            }
            $checks[] = $c;
        }
        $rank = ['ok' => 0, 'unknown' => 1, 'warn' => 2, 'fail' => 3];
        $worst = collect($checks)->sortByDesc(fn ($c) => $rank[$c['status']])->first()['status'];

        return [
            'generated_at' => now()->toDateTimeString(), 'overall' => $worst, 'checks' => $checks,
            'counts' => collect($checks)->countBy('status')->all(),
            'recent_failures' => $this->recentFailures($tenant),
            'recent_events' => $isAdmin ? $this->recentEvents($tenant) : null,
            'dependencies' => $this->dependencies($tenant),
        ];
    }

    // ---------------------------------------------------------------- checks

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $missing = array_values(array_filter(self::TABLES, fn ($t) => DB::selectOne('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->c == 0));
        $agents = (int) DB::table('agentic_agents')->where('sub_module', 'gtm')->where('status', 'deployed')->count();
        $menus = (int) DB::table('tblmenumaster_g2g')->where('access_link', 'like', '/gtm%')->count();
        $fix = $missing ? 'Run the GTM migrations: php artisan migrate (they are idempotent). Missing: '.implode(', ', $missing).'.' : null;
        $status = $missing ? 'fail' : (($agents < 5 || $menus < 13) ? 'warn' : 'ok');

        return ['key' => 'schema', 'label' => 'Database and registration', 'status' => $status,
            'summary' => $missing ? count($missing).' GTM table(s) missing' : 'All '.count(self::TABLES)." GTM tables exist; {$agents} of 5 agents deployed; {$menus} sidebar pages registered.",
            'facts' => ['tables_expected' => count(self::TABLES), 'tables_missing' => $missing, 'agents_deployed' => $agents, 'menu_pages' => $menus],
            'fix' => $fix ?? ($status === 'warn' ? 'Run the GTM migrations so every agent and sidebar page is registered.' : null)];
    }

    /** @return array<string, mixed> */
    private function aiProvider(int $tenant, bool $probe): array
    {
        $cfg = app(AiConfigurationResolver::class)->resolve(GtmAiRunner::MODULE, $tenant);
        $configured = $cfg->hasKey();
        $events = DB::table('ai_usage_events')->where('ai_module', 'gtm')->where('sub_institute_id', $tenant)->where('created_at', '>=', now()->subDay());
        $by = (clone $events)->selectRaw('outcome, COUNT(*) c, COALESCE(SUM(input_tokens + output_tokens), 0) tok')->groupBy('outcome')->get()->keyBy('outcome');
        $lastFail = (clone $events)->where('outcome', 'failed')->orderByDesc('id')->first(['error', 'created_at']);
        $lastOk = DB::table('ai_usage_events')->where('ai_module', 'gtm')->where('sub_institute_id', $tenant)->where('outcome', 'success')->max('created_at');
        $quota = $lastFail && preg_match('/quota|429|rate limit|exceeded/i', (string) $lastFail->error) && (! $lastOk || $lastFail->created_at > $lastOk);
        $retry = $quota && preg_match('/retry in ([0-9hms]+(?:\.[0-9]+)?s?)/i', (string) $lastFail->error, $m) ? $m[1] : null;

        $facts = ['configured' => $configured, 'provider' => $cfg->provider, 'model' => $cfg->model, 'configured_via' => $cfg->source,
            'last_24h' => ['success' => (int) ($by['success']->c ?? 0), 'failed' => (int) ($by['failed']->c ?? 0), 'refused' => (int) ($by['refused']->c ?? 0), 'tokens' => (int) collect($by)->sum('tok')],
            'last_success_at' => $lastOk, 'last_failure_at' => $lastFail->created_at ?? null, 'quota_exhausted' => $quota, 'quota_retry_hint' => $retry];
        if ($probe) {
            $facts['probe'] = $this->probeAi($tenant);
        }
        $failed = $facts['last_24h']['failed'];
        $status = ! $configured ? 'fail' : ($quota ? 'fail' : (($failed > 0 && $failed >= $facts['last_24h']['success']) ? 'warn' : (($facts['last_24h']['success'] + $failed) === 0 ? 'unknown' : 'ok')));
        if (($facts['probe']['ok'] ?? null) === true) {
            $status = 'ok';
        } elseif (($facts['probe']['ok'] ?? null) === false) {
            $status = 'fail';
        }

        return ['key' => 'ai', 'label' => 'AI provider', 'status' => $status,
            'summary' => ! $configured ? 'No AI provider is configured for GTM.' : ($quota ? "The provider's quota is exhausted".($retry ? " (it says to retry in {$retry})" : '').'.' : "{$cfg->provider} / {$cfg->model}: {$facts['last_24h']['success']} ok, {$failed} failed in 24 h."),
            'facts' => $facts,
            'fix' => ! $configured ? 'Add a provider key under AI & Intelligence → AI Providers (module "GTM & Revenue"), or set one for the whole organisation.' : ($quota ? 'This is the provider\'s limit, not a GTM defect. Wait for the quota to reset, enable billing / use another key, or point the "GTM & Revenue" module at another provider under AI & Intelligence → AI Providers.' : (in_array($status, ['warn', 'fail'], true) ? 'Run the live check; if it fails, read the error and check the key and model under AI & Intelligence → AI Providers.' : null))];
    }

    /** One tiny real call to prove the provider answers right now. @return array<string, mixed> */
    private function probeAi(int $tenant): array
    {
        try {
            $r = app(AiModelClient::class)->complete(GtmAiRunner::MODULE, [['role' => 'user', 'content' => 'Reply with the single word OK.']], ['max_tokens' => 8, 'thinking_budget' => 0, 'related_type' => 'gtm_monitoring_probe'], $tenant);

            return ['ok' => true, 'provider' => $r->provider, 'model' => $r->model, 'latency_ms' => $r->latencyMs, 'at' => now()->toDateTimeString()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 220), 'at' => now()->toDateTimeString()];
        }
    }

    /** @return array<string, mixed> */
    private function webSearch(): array
    {
        $p = SearchProviderFactory::make();
        $ok = $p !== null && $p->isConfigured();

        return ['key' => 'web_search', 'label' => 'Web search (company research)', 'status' => $ok ? 'ok' : 'warn',
            'summary' => $ok ? "Configured ({$p->name()})." : 'Not configured: new sources cannot be fetched; GTM works from research already collected.',
            'facts' => ['configured' => $ok, 'driver' => $p?->name() ?? config('signals.search_driver', env('SIGNALS_SEARCH_DRIVER'))],
            'fix' => $ok ? null : 'Set the search provider\'s API key (for the Tavily driver: TAVILY_API_KEY) in the backend environment. Until then, signal research cannot find new companies.'];
    }

    /** @return array<string, mixed> */
    private function agents(int $tenant): array
    {
        $ids = DB::table('agentic_agents')->where('sub_module', 'gtm')->pluck('slug', 'id');
        $runs = DB::table('agentic_agent_runs')->where('sub_institute_id', $tenant)->whereIn('agent_id', $ids->keys())->where('started_at', '>=', now()->subDays(7))->whereNull('deleted_at');
        $by = (clone $runs)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $total = (int) $by->sum();
        $failed = (int) ($by['error'] ?? 0);
        $last = (clone $runs)->where('status', 'error')->orderByDesc('id')->first(['error_message', 'started_at', 'agent_id']);
        $lastFailed = $last ? mb_substr((string) $last->error_message, 0, 200) : null;
        $quotaOnly = $failed > 0 && (clone $runs)->where('status', 'error')->where('error_message', 'not like', '%quota%')->where('error_message', 'not like', '%overloaded%')->doesntExist();
        $status = $total === 0 ? 'unknown' : ($failed === 0 ? 'ok' : (($failed / $total) > 0.5 ? 'fail' : 'warn'));

        return ['key' => 'agents', 'label' => 'GTM agents', 'status' => $status,
            'summary' => $total === 0 ? 'No agent has run in the last 7 days.' : "{$total} run(s) in 7 days: ".($by['success'] ?? 0)." succeeded, {$failed} failed.".($quotaOnly ? ' Every failure was the AI provider\'s quota or load, not GTM.' : ''),
            'facts' => ['runs_7d' => $total, 'by_status' => $by, 'last_failure' => $lastFailed, 'last_failure_at' => $last->started_at ?? null, 'failures_were_provider_limits' => $quotaOnly, 'agents' => $ids->values()],
            'fix' => $failed === 0 ? null : ($quotaOnly ? 'See the AI provider check: the failures are the provider refusing requests, and clear when its quota resets.' : 'Open Agents → Recent runs and read the failing runs\' messages; a "no data" failure means there is nothing to analyse yet, not a fault.')];
    }

    /** @return array<string, mixed> */
    private function mail(int $tenant, bool $probe): array
    {
        $r = $this->mailer->readiness($tenant, $probe);
        unset($r['smtp']['password']);
        $status = $r['can_send'] ? 'ok' : 'warn'; // sending off is a deliberate state, not a fault
        if ($probe && ($r['smtp']['ok'] ?? true) === false) {
            $status = 'fail';
        }

        return ['key' => 'mail', 'label' => 'Email sending', 'status' => $status,
            'summary' => $r['can_send'] ? "Sending is enabled via {$r['mailer']}" . ($r['host'] ? " ({$r['host']})" : '') . ", {$r['sent_today']} of {$r['daily_cap']} sent today." : 'Sending is not enabled: '.($r['blockers'][0] ?? 'see details').(count($r['blockers']) > 1 ? ' (+'.(count($r['blockers']) - 1).' more)' : ''),
            'facts' => ['mailer' => $r['mailer'], 'host' => $r['host'], 'from' => $r['from'], 'platform_gate_allows' => $r['platform_gate_allows'], 'gtm_sending_enabled' => $r['gtm_sending_enabled'], 'scheduler_enabled' => $r['scheduler_enabled'],
                'unsubscribe_link_ok' => $r['unsubscribe_link_ok'], 'daily_cap' => $r['daily_cap'], 'sent_today' => $r['sent_today'], 'blockers' => $r['blockers'], 'connection_test' => $r['smtp']],
            'fix' => $r['can_send'] ? null : 'Each blocker above names the setting to change (GTM_OUTREACH_SENDING_ENABLED, G2G_NOTIFY_EMAIL_TENANTS, a public APP_URL). Use "Test connection" to prove the mail server accepts the login without sending anything.'];
    }

    /** @return array<string, mixed> */
    private function delivery(int $tenant): array
    {
        $m = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant);
        $week = (clone $m)->where('updated_at', '>=', now()->subDays(7));
        $by = (clone $week)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $outcomes = (clone $week)->whereNotNull('outcome')->selectRaw('outcome, COUNT(*) c')->groupBy('outcome')->pluck('c', 'outcome');
        $lastFail = DB::table('gtm_outreach_attempts')->where('sub_institute_id', $tenant)->whereIn('outcome', ['failed', 'retry'])->orderByDesc('id')->first(['code', 'detail', 'created_at']);
        $due = (clone $m)->where('status', 'approved')->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())->count();
        $failed24 = DB::table('gtm_outreach_attempts')->where('sub_institute_id', $tenant)->whereIn('outcome', ['failed', 'retry'])->where('created_at', '>=', now()->subDay())->count();
        $sent = (int) ($by['sent'] ?? 0);
        $status = $failed24 > 0 ? 'warn' : (($sent + (int) ($by['failed'] ?? 0)) === 0 ? 'unknown' : 'ok');

        return ['key' => 'delivery', 'label' => 'Email delivery', 'status' => $status,
            'summary' => $sent + (int) ($by['failed'] ?? 0) === 0 ? 'No emails were sent or failed in the last 7 days.' : "7 days: {$sent} sent, ".($by['failed'] ?? 0).' failed, '.($outcomes['bounced'] ?? 0).' bounced, '.($outcomes['replied'] ?? 0).' replied'.($due ? "; {$due} scheduled message(s) are due now." : '.'),
            'facts' => ['by_status_7d' => $by, 'outcomes_7d' => $outcomes, 'scheduled_due_now' => $due, 'send_failures_24h' => $failed24, 'last_failure' => $lastFail ? ['kind' => $lastFail->code, 'detail' => mb_substr((string) $lastFail->detail, 0, 200), 'at' => $lastFail->created_at] : null],
            'fix' => $status === 'warn' ? 'Open Outreach and the failed message: its attempt history shows the provider\'s reason. A rate-limit or temporary failure retries by itself; a permanent one (such as a mailbox that does not exist) needs the contact fixed.' : null];
    }

    /** @return array<string, mixed> */
    private function inbound(int $tenant): array
    {
        $configured = (string) config('gtm.inbound.secret') !== '';
        $events = DB::table('gtm_inbound_events')->where('sub_institute_id', $tenant)->where('received_at', '>=', now()->subDays(7));
        $by = (clone $events)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $unmatched = DB::table('gtm_inbound_events')->whereNull('sub_institute_id')->where('received_at', '>=', now()->subDays(7))->count();
        $last = DB::table('gtm_inbound_events')->where('sub_institute_id', $tenant)->max('received_at');
        $rejectedHour = (int) Cache::get('gtm:inbound:rejected:'.now()->format('YmdH'), 0);
        $lastRejected = Cache::get('gtm:inbound:last_rejected');
        $errors = (int) ($by['error'] ?? 0);
        $status = ! $configured ? 'warn' : ($errors > 0 || $rejectedHour > 20 ? 'warn' : ($last === null ? 'unknown' : 'ok'));

        return ['key' => 'inbound', 'label' => 'Reply and bounce intake', 'status' => $status,
            'summary' => ! $configured ? 'Not configured: replies and bounces are not received automatically (they can still be recorded by hand).' : ($last === null ? 'Configured, but no event has been received yet.' : "Last event {$last}; 7 days: ".($by['processed'] ?? 0).' processed, '.($by['unmatched'] ?? 0)." unmatched, {$errors} errors."),
            'facts' => ['secret_configured' => $configured, 'endpoint' => '/api/gtm/inbound', 'last_received_at' => $last, 'by_status_7d' => $by, 'unmatched_unattributed_7d' => $unmatched, 'rejected_this_hour' => $rejectedHour, 'last_rejected' => $lastRejected],
            'fix' => ! $configured ? 'Set GTM_INBOUND_WEBHOOK_SECRET and make your inbound-email service POST signed events to /api/gtm/inbound (see the integration notes for the signing format).' : ($status === 'warn' ? 'Rejected requests mean a sender is using the wrong secret or signing format; errors mean an event could not be applied - see Reports → Audit trail.' : null)];
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        $ledger = DB::table('g2g_platform_task_runs')->where('task_key', 'gtm.tick')->orderByDesc('id')->first(['started_at', 'status', 'exit_code', 'duration_ms', 'output_head']);
        $state = GtmState::get('last_tick_at');
        $latest = collect([$ledger?->started_at, $state])->filter()->map(fn ($t) => Carbon::parse($t))->max();
        $age = $latest ? (int) $latest->diffInMinutes(now()) : null;
        $summary = json_decode((string) GtmState::get('last_tick_summary'), true) ?: [];
        $ledgerAny = DB::table('g2g_platform_task_runs')->max('started_at');
        $status = $latest === null ? 'fail' : ($age <= 15 ? (($summary['errors'] ?? []) === [] ? 'ok' : 'warn') : ($age <= 60 ? 'warn' : 'fail'));

        return ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => $status,
            'summary' => $latest === null ? 'The GTM tick has never run: nothing scheduled (sending, retries, snapshots, alerts) happens.' : "The GTM tick last ran {$age} minute(s) ago.".(($summary['errors'] ?? []) ? ' It reported errors.' : ''),
            'facts' => ['last_tick_at' => $latest?->toDateTimeString(), 'age_minutes' => $age, 'last_scheduled_run' => $ledger ? ['at' => $ledger->started_at, 'status' => $ledger->status, 'exit_code' => $ledger->exit_code, 'ms' => $ledger->duration_ms] : null,
                'last_tick_errors' => $summary['errors'] ?? [], 'last_daily_pass' => GtmState::get('last_daily_date'), 'any_scheduled_task_last_ran' => $ledgerAny],
            'fix' => in_array($status, ['warn', 'fail'], true) ? ($ledgerAny === null || Carbon::parse($ledgerAny)->lt(now()->subDay()) ? 'The platform scheduler is not running. Run "php artisan schedule:work" (or a per-minute cron / Windows Task Scheduler entry for "php artisan schedule:run") on the server. You can run "php artisan gtm:tick" by hand to check it works.' : 'The scheduler runs, but the GTM tick is late or reporting errors. Run "php artisan gtm:tick" by hand and read its output.') : null];
    }

    /** @return array<string, mixed> */
    private function eventDelivery(int $tenant): array
    {
        $runner = app(GtmWorkflowRunner::class);
        $pending = $runner->pendingCount();
        $failed = DB::table('g2g_event_delivery')->where('consumer', GtmWorkflowRunner::CONSUMER)->where('status', 'failed')->count();
        $gtmEvents = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', 'like', 'gtm.%')->count();
        $audited = DB::table('g2g_audit_log')->where('sub_institute_id', $tenant)->where('type', 'like', 'gtm.%')->count();
        $lag = max(0, $gtmEvents - $audited);
        $reactRan = DB::table('g2g_platform_task_runs')->where('task_key', 'like', '%react%')->max('started_at');
        $status = $failed > 0 ? 'warn' : ($pending > 200 ? 'warn' : 'ok');

        return ['key' => 'events', 'label' => 'Event delivery and audit trail', 'status' => $status,
            'summary' => "{$gtmEvents} GTM events recorded; {$audited} projected to the audit log".($lag ? " ({$lag} waiting)" : '').". Workflow runner: {$pending} event(s) waiting, {$failed} failed.",
            'facts' => ['gtm_events_recorded' => $gtmEvents, 'audit_log_rows' => $audited, 'audit_projection_waiting' => $lag, 'workflow_runner_pending' => $pending, 'workflow_runner_failed' => $failed, 'event_runner_last_ran' => $reactRan],
            'fix' => $status === 'warn' ? 'Events are delivered by the platform commands events:react (workflow triggers) and events:project (audit log). If they are behind, check the Scheduler check; you can run "php artisan events:project" and "php artisan events:react --consumer=gtm_workflow_runner" by hand.' : null];
    }

    /** @return array<string, mixed> */
    private function workflows(int $tenant): array
    {
        $wf = DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $runs = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('created_at', '>=', now()->subDays(7))->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $stale = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('status', 'pending_approval')->where('created_at', '<', now()->subDay())->count();
        $retry = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('status', 'retry_wait')->count();
        $failed = (int) ($runs['failed'] ?? 0);
        $lastFail = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('status', 'failed')->orderByDesc('id')->first(['id', 'error', 'created_at']);
        $status = ((int) ($wf['active'] ?? 0)) === 0 && $runs->isEmpty() ? 'unknown' : ($failed > 0 || $stale > 0 ? 'warn' : 'ok');

        return ['key' => 'workflows', 'label' => 'Workflows', 'status' => $status,
            'summary' => ((int) $wf->sum()) === 0 ? 'No workflows exist yet.' : (($wf['active'] ?? 0).' active; 7 days: '.($runs['succeeded'] ?? 0)." succeeded, {$failed} failed, ".($runs['pending_approval'] ?? 0)." awaiting approval, {$retry} waiting to retry."),
            'facts' => ['workflows_by_status' => $wf, 'runs_7d' => $runs, 'awaiting_approval_over_a_day' => $stale, 'waiting_to_retry' => $retry, 'last_failure' => $lastFail ? ['run_id' => $lastFail->id, 'error' => mb_substr((string) $lastFail->error, 0, 200), 'at' => $lastFail->created_at] : null],
            'fix' => $status === 'warn' ? ($stale > 0 ? 'Runs have waited over a day for approval: open Workflows → Runs and approve or reject them. ' : '').($failed > 0 ? 'A failed run shows which step failed and why; fix the cause and use Retry (completed steps are not repeated).' : '') : null];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return ['key' => 'queue', 'label' => 'Background jobs (platform queue)', 'status' => $failed > 0 ? 'warn' : 'ok',
            'summary' => "{$pending} job(s) queued; {$failed} failed in the last 24 h. (The queue is shared by every module; GTM itself queues no jobs.)",
            'facts' => ['driver' => config('queue.default'), 'pending' => $pending, 'failed_24h' => $failed],
            'fix' => $failed > 0 ? 'Inspect failed jobs with "php artisan queue:failed"; they are not caused by GTM unless their class names say so.' : null];
    }

    // ---------------------------------------------------------------- lists

    /** @return list<array<string, mixed>> the most recent failures from every part of GTM, newest first */
    private function recentFailures(int $tenant): array
    {
        $out = [];
        foreach (DB::table('agentic_agent_runs as r')->join('agentic_agents as a', 'a.id', '=', 'r.agent_id')->where('a.sub_module', 'gtm')->where('r.sub_institute_id', $tenant)->where('r.status', 'error')->orderByDesc('r.id')->limit(5)->get(['a.slug', 'r.error_message', 'r.started_at']) as $r) {
            $out[] = ['source' => 'agent', 'what' => $r->slug, 'message' => mb_substr((string) $r->error_message, 0, 200), 'at' => (string) $r->started_at];
        }
        foreach (DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('status', 'failed')->orderByDesc('id')->limit(5)->get(['id', 'error', 'created_at']) as $r) {
            $out[] = ['source' => 'workflow', 'what' => "run #{$r->id}", 'message' => mb_substr((string) $r->error, 0, 200), 'at' => (string) $r->created_at];
        }
        foreach (DB::table('gtm_outreach_attempts')->where('sub_institute_id', $tenant)->whereIn('outcome', ['failed', 'retry'])->orderByDesc('id')->limit(5)->get(['message_id', 'code', 'detail', 'created_at']) as $r) {
            $out[] = ['source' => 'email', 'what' => "message #{$r->message_id} ({$r->code})", 'message' => mb_substr((string) $r->detail, 0, 200), 'at' => (string) $r->created_at];
        }
        foreach (DB::table('gtm_inbound_events')->where('sub_institute_id', $tenant)->where('status', 'error')->orderByDesc('id')->limit(5)->get(['id', 'error', 'received_at']) as $r) {
            $out[] = ['source' => 'inbound', 'what' => "event #{$r->id}", 'message' => mb_substr((string) $r->error, 0, 200), 'at' => (string) $r->received_at];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($out, 0, 12);
    }

    /** @return list<array<string, mixed>> */
    private function recentEvents(int $tenant): array
    {
        return DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', 'like', 'gtm.%')->orderByDesc('id')->limit(15)->get(['id', 'type', 'entity_type', 'entity_id', 'actor_id', 'occurred_at'])
            ->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'record' => $e->entity_type ? "{$e->entity_type} #{$e->entity_id}" : null, 'actor_id' => $e->actor_id, 'at' => $e->occurred_at])->all();
    }

    /** What the module needs from outside, and whether it has it - the list a deployment checklist reads. @return list<array<string, mixed>> */
    private function dependencies(int $tenant): array
    {
        $cfg = app(AiConfigurationResolver::class)->resolve(GtmAiRunner::MODULE, $tenant);
        $mail = $this->mailer->readiness($tenant);

        return [
            ['name' => 'AI provider key', 'ready' => $cfg->hasKey(), 'where' => 'AI & Intelligence → AI Providers'],
            ['name' => 'Web search key', 'ready' => (SearchProviderFactory::make()?->isConfigured() ?? false), 'where' => 'backend environment (TAVILY_API_KEY)'],
            ['name' => 'Public API address (for unsubscribe links)', 'ready' => $mail['unsubscribe_link_ok'], 'where' => 'backend environment (APP_URL)'],
            ['name' => 'Mail allowed for this organisation', 'ready' => $mail['platform_gate_allows'], 'where' => 'backend environment (G2G_NOTIFY_EMAIL_TENANTS)'],
            ['name' => 'GTM sending switched on', 'ready' => $mail['gtm_sending_enabled'], 'where' => 'backend environment (GTM_OUTREACH_SENDING_ENABLED)'],
            ['name' => 'Scheduled sending switched on', 'ready' => $mail['scheduler_enabled'], 'where' => 'backend environment (GTM_OUTREACH_SCHEDULER_ENABLED)'],
            ['name' => 'Inbound reply/bounce secret', 'ready' => (string) config('gtm.inbound.secret') !== '', 'where' => 'backend environment (GTM_INBOUND_WEBHOOK_SECRET)'],
            ['name' => 'Scheduler running', 'ready' => GtmState::get('last_tick_at') !== null && Carbon::parse((string) GtmState::get('last_tick_at'))->gt(now()->subMinutes(30)), 'where' => 'server (php artisan schedule:work or a per-minute cron)'],
        ];
    }
}
