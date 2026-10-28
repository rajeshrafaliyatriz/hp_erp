<?php

namespace App\Domain\Gtm\Reports;

use App\Domain\Gtm\Customers\CustomerService;
use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\Forecast\ForecastService;
use App\Domain\Gtm\GtmDeal;
use App\Domain\Gtm\Marketing\CampaignMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GTM reports: tenant-scoped views over the real records, each with a fixed set of columns and filters.
 *
 * Every report is read from the same tables the screens use - nothing is stored or estimated for a report - and the
 * tenant is always supplied by the caller from the authenticated token. A report that holds personal contact data
 * (`pii`) masks e-mail addresses and phone numbers unless the caller is an administrator; `admin_only` reports (the
 * audit trail) are not shown to anyone else at all. The cap on rows keeps one export from becoming a bulk copy of the
 * database; hitting it is reported, never silent.
 *
 * This is deliberately not a second reporting framework: ChatReportService/ReportBuilder render templates for AI modules
 * (rows keyed to ai_modules data sources), and GTM is intentionally not an AI module. These are plain query reports
 * with CSV/JSON output.
 */
final class ReportService
{
    public const MAX_ROWS = 50000;

    /** @var array<string, array{title: string, description: string, pii: bool, admin_only: bool, filters: array<string, string>, columns: array<string, array{label: string, type: string}>}> */
    private const DEFS = [
        'accounts' => ['title' => 'Accounts', 'description' => 'Every target account with its stage, ICP fit and activity.', 'pii' => false, 'admin_only' => false,
            'filters' => ['stage' => 'enum', 'source' => 'enum', 'owner_user_id' => 'int', 'min_icp' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Account', 'text'], 'stage' => ['Stage', 'text'], 'industry' => ['Industry', 'text'], 'location' => ['Location', 'text'], 'source' => ['Source', 'text'], 'owner' => ['Owner', 'text'],
                'icp_fit_score' => ['ICP fit (AI estimate)', 'number'], 'contacts' => ['Contacts', 'number'], 'signals' => ['Research signals', 'number'], 'last_activity_at' => ['Last activity', 'date'], 'created_at' => ['Created', 'date']]],
        'contacts' => ['title' => 'Contacts', 'description' => 'People at your accounts. E-mail and phone are masked unless you are an administrator.', 'pii' => true, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'role' => 'enum', 'account_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'full_name' => ['Name', 'text'], 'title' => ['Title', 'text'], 'email' => ['Email', 'text'], 'phone' => ['Phone', 'text'], 'role_in_deal' => ['Buying role', 'text'], 'status' => ['Status', 'text'],
                'account' => ['Account', 'text'], 'source' => ['Source', 'text'], 'created_at' => ['Created', 'date']]],
        'prospecting_icp' => ['title' => 'Prospecting and ICP fit', 'description' => 'Scored accounts with the reasoning behind each AI-estimated fit score.', 'pii' => false, 'admin_only' => false,
            'filters' => ['min_icp' => 'int', 'max_icp' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Account', 'text'], 'stage' => ['Stage', 'text'], 'icp_fit_score' => ['ICP fit (AI estimate)', 'number'], 'confidence' => ['Confidence', 'text'], 'summary' => ['Summary', 'text'],
                'missing' => ['Missing facts', 'text'], 'model' => ['Model', 'text'], 'scored_at' => ['Scored', 'date']]],
        'signals' => ['title' => 'Research signals', 'description' => 'Buying signals found by company research, each with its source.', 'pii' => false, 'admin_only' => false,
            'filters' => ['priority' => 'enum', 'review_status' => 'enum', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'company' => ['Company', 'text'], 'title' => ['Signal', 'text'], 'signal_kind' => ['Kind', 'text'], 'priority' => ['Priority', 'text'], 'review_status' => ['Review', 'text'], 'confidence' => ['Confidence', 'text'],
                'event_date' => ['Event date', 'date'], 'source_urls' => ['Sources', 'text'], 'account' => ['GTM account', 'text'], 'first_discovered_at' => ['Discovered', 'date']]],
        'pipeline' => ['title' => 'Deal pipeline', 'description' => 'Deals with their stage, value, next step and rule-based health flags.', 'pii' => false, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'stage' => 'enum', 'owner_user_id' => 'int', 'currency' => 'currency', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Deal', 'text'], 'account' => ['Account', 'text'], 'stage' => ['Stage', 'text'], 'amount' => ['Amount', 'money'], 'currency' => ['Currency', 'text'], 'expected_close_date' => ['Expected close', 'date'],
                'owner' => ['Owner', 'text'], 'next_step' => ['Next step', 'text'], 'days_in_stage' => ['Days in stage', 'number'], 'health_flags' => ['Health flags (rules)', 'text']]],
        'forecast' => ['title' => 'Forecast (calculated)', 'description' => 'Open pipeline by expected close month and currency, with weighted values only where probabilities are configured. A calculation, not a result.', 'pii' => false, 'admin_only' => false,
            'filters' => ['owner_user_id' => 'int', 'department_id' => 'int', 'stage' => 'enum', 'currency' => 'currency', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['period' => ['Close month', 'text'], 'currency' => ['Currency', 'text'], 'deal_count' => ['Deals', 'number'], 'pipeline' => ['Pipeline', 'money'], 'best_case' => ['Best case', 'money'], 'commit' => ['Commit', 'money'], 'weighted' => ['Weighted (needs probabilities)', 'money']]],
        'closed_deals' => ['title' => 'Closed deals (actual results)', 'description' => 'Deals actually won or lost, by close date. Actual results, never projections.', 'pii' => false, 'admin_only' => false,
            'filters' => ['stage' => 'enum', 'owner_user_id' => 'int', 'currency' => 'currency', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Deal', 'text'], 'account' => ['Account', 'text'], 'stage' => ['Result', 'text'], 'amount' => ['Amount', 'money'], 'currency' => ['Currency', 'text'], 'closed_at' => ['Closed', 'date'], 'close_reason' => ['Reason', 'text'], 'owner' => ['Owner', 'text']]],
        'methodology' => ['title' => 'BANT / MEDDICC scores', 'description' => 'The latest methodology score for each deal (AI estimate, total computed from the playbook weights).', 'pii' => false, 'admin_only' => false,
            'filters' => ['min_total' => 'int', 'max_total' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['deal_id' => ['Deal id', 'number'], 'deal' => ['Deal', 'text'], 'account' => ['Account', 'text'], 'methodology' => ['Methodology', 'text'], 'total' => ['Total', 'number'], 'assessed' => ['Dimensions assessed', 'text'], 'model' => ['Model', 'text'], 'scored_at' => ['Scored', 'date']]],
        'discovery' => ['title' => 'Discovery analyses', 'description' => 'Discovery-call analyses saved against deals: problems found (each quoted from the notes) and what is still unknown.', 'pii' => false, 'admin_only' => false,
            'filters' => ['date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['deal_id' => ['Deal id', 'number'], 'deal' => ['Deal', 'text'], 'account' => ['Account', 'text'], 'problems' => ['Problems found', 'number'], 'gaps' => ['Not yet established', 'text'], 'model' => ['Model', 'text'], 'analysed_at' => ['Analysed', 'date']]],
        'outreach' => ['title' => 'Outreach', 'description' => 'Every outreach message with its approval, sending and outcome. Recipient e-mail is masked unless you are an administrator.', 'pii' => true, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'outcome' => 'enum', 'campaign_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'contact' => ['Contact', 'text'], 'email' => ['Email', 'text'], 'account' => ['Account', 'text'], 'campaign' => ['Campaign', 'text'], 'subject' => ['Subject', 'text'], 'status' => ['Status', 'text'], 'step' => ['Step', 'number'],
                'scheduled_for' => ['Scheduled for', 'date'], 'sent_at' => ['Sent', 'date'], 'outcome' => ['Outcome', 'text'], 'attempts' => ['Attempts', 'number'], 'error' => ['Last error', 'text'], 'created_at' => ['Created', 'date']]],
        'campaigns' => ['title' => 'Campaigns', 'description' => 'Each campaign with measured reach, replies and the deals that followed (a correlation, not proof of cause).', 'pii' => false, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'kind' => 'enum', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Campaign', 'text'], 'kind' => ['Type', 'text'], 'status' => ['Status', 'text'], 'industry_pack' => ['Industry pack', 'text'], 'target_accounts' => ['Target accounts', 'number'], 'contacts_reached' => ['Contacts reached', 'number'],
                'contacts_replied' => ['Contacts replied', 'number'], 'reply_rate_pct' => ['Reply rate %', 'number'], 'deals_created' => ['Deals created after joining', 'number'], 'deals_won' => ['Deals won', 'number'], 'won_value' => ['Won value', 'text'], 'created_at' => ['Created', 'date']]],
        'customer_health' => ['title' => 'Customer health', 'description' => 'Customers with a computed, explainable health score, risks, and what is missing.', 'pii' => false, 'admin_only' => false,
            'filters' => ['band' => 'enum'],
            'columns' => ['account_id' => ['Account id', 'number'], 'name' => ['Customer', 'text'], 'score' => ['Health score', 'number'], 'band' => ['Band', 'text'], 'risks' => ['Risks', 'text'], 'missing' => ['Missing data', 'text'], 'next_renewal' => ['Next renewal', 'date'], 'renewal_status' => ['Renewal status', 'text']]],
        'renewals' => ['title' => 'Renewals', 'description' => 'Recorded renewals with days remaining and status.', 'pii' => false, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'owner_user_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Id', 'number'], 'name' => ['Renewal', 'text'], 'account' => ['Account', 'text'], 'renewal_date' => ['Renewal date', 'date'], 'days_until' => ['Days until (negative = overdue)', 'number'], 'status' => ['Status', 'text'], 'amount' => ['Amount', 'money'], 'currency' => ['Currency', 'text'], 'owner' => ['Owner', 'text'], 'outcome_note' => ['Outcome note', 'text']]],
        'agent_usage' => ['title' => 'AI agent runs and usage', 'description' => 'Every GTM agent run with its status, duration and tokens, from the agent run log.', 'pii' => false, 'admin_only' => false,
            'filters' => ['agent' => 'enum', 'status' => 'enum', 'user_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Run id', 'number'], 'agent' => ['Agent', 'text'], 'status' => ['Status', 'text'], 'run_by' => ['Run by', 'text'], 'duration_ms' => ['Duration (ms)', 'number'], 'tokens_used' => ['Tokens', 'number'], 'error' => ['Error', 'text'], 'started_at' => ['Started', 'date']]],
        'workflows' => ['title' => 'Workflow runs', 'description' => 'Every workflow run with its outcome, attempts and who approved it.', 'pii' => false, 'admin_only' => false,
            'filters' => ['status' => 'enum', 'workflow_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Run id', 'number'], 'workflow' => ['Workflow', 'text'], 'event_type' => ['Trigger event', 'text'], 'entity' => ['Record', 'text'], 'status' => ['Status', 'text'], 'attempts' => ['Attempts', 'number'], 'decided_by' => ['Decided by', 'text'], 'error' => ['Error', 'text'], 'created_at' => ['Started', 'date'], 'finished_at' => ['Finished', 'date']]],
        'audit' => ['title' => 'Audit trail', 'description' => 'Every recorded GTM event with who did it. Administrators only.', 'pii' => false, 'admin_only' => true,
            'filters' => ['type' => 'text', 'actor_id' => 'int', 'date_from' => 'date', 'date_to' => 'date'],
            'columns' => ['id' => ['Event id', 'number'], 'occurred_at' => ['When', 'date'], 'type' => ['Event', 'text'], 'entity' => ['Record', 'text'], 'actor' => ['By', 'text'], 'source' => ['Source', 'text'], 'detail' => ['Detail', 'text']]],
    ];

    /** Allowed values for enum filters, per filter name (a filter only means one thing across reports). */
    private const ENUMS = [
        'stage' => ['target', 'engaged', 'opportunity', 'customer', 'churned', 'disqualified', 'discovery', 'qualification', 'proposal', 'negotiation', 'won', 'lost'],
        'source' => ['manual', 'research', 'import'], 'role' => ['champion', 'economic_buyer', 'decision_maker', 'influencer', 'user', 'blocker'],
        'status' => ['active', 'bounced', 'unsubscribed', 'do_not_contact', 'open', 'closed', 'draft', 'pending_approval', 'approved', 'rejected', 'sending', 'sent', 'failed', 'cancelled', 'paused', 'completed', 'archived',
            'upcoming', 'in_progress', 'renewed', 'churned', 'success', 'error', 'running', 'queued', 'retry_wait', 'succeeded', 'skipped'],
        'priority' => ['High', 'Medium', 'Low'], 'review_status' => ['New', 'Reviewed', 'Dismissed', 'Actioned'], 'outcome' => ['replied', 'bounced', 'complained'], 'kind' => ['abm', 'outbound', 'nurture', 'event'],
        'band' => ['healthy', 'watch', 'at_risk', 'unknown'], 'agent' => ['gtm-prospecting', 'gtm-outreach', 'gtm-deal-coach', 'gtm-revops', 'gtm-customer-success'],
    ];

    public function __construct(private readonly ForecastService $forecast, private readonly CustomerService $customers, private readonly CampaignMetrics $metrics)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function catalogue(bool $isAdmin): array
    {
        $out = [];
        foreach (self::DEFS as $key => $d) {
            if ($d['admin_only'] && ! $isAdmin) {
                continue;
            }
            $out[$key] = ['key' => $key, 'title' => $d['title'], 'description' => $d['description'], 'pii' => $d['pii'], 'admin_only' => $d['admin_only'], 'filters' => $d['filters'],
                'enums' => array_intersect_key(self::ENUMS, array_filter($d['filters'], fn ($t) => $t === 'enum')),
                'columns' => array_map(fn ($c) => ['label' => $c[0], 'type' => $c[1]], $d['columns'])];
        }

        return $out;
    }

    public function exists(string $key): bool
    {
        return isset(self::DEFS[$key]);
    }

    public function definition(string $key): array
    {
        return self::DEFS[$key];
    }

    /**
     * Validate the filters a report accepts. Returns [clean filters, errors]; an unknown filter is an error, not ignored.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function filters(string $key, array $input): array
    {
        $allowed = self::DEFS[$key]['filters'];
        $clean = [];
        $errors = [];
        foreach ($input as $name => $value) {
            if (in_array($name, ['page', 'per_page', 'format', 'token'], true) || $value === null || $value === '') {
                continue;
            }
            if (! isset($allowed[$name])) {
                $errors[] = "'{$name}' is not a filter of this report.";

                continue;
            }
            $v = (string) $value;
            switch ($allowed[$name]) {
                case 'date':
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                        $errors[] = "'{$name}' must be a date (YYYY-MM-DD).";
                    } else {
                        $clean[$name] = $v;
                    }
                    break;
                case 'int':
                    ctype_digit($v) ? $clean[$name] = (int) $v : $errors[] = "'{$name}' must be a whole number.";
                    break;
                case 'currency':
                    preg_match('/^[A-Z]{3}$/', $v) ? $clean[$name] = $v : $errors[] = "'{$name}' must be a 3-letter currency code in capitals.";
                    break;
                case 'enum':
                    in_array($v, self::ENUMS[$name] ?? [], true) ? $clean[$name] = $v : $errors[] = "'{$name}' must be one of: ".implode(', ', self::ENUMS[$name] ?? []).'.';
                    break;
                default:
                    mb_strlen($v) <= 60 && preg_match('/^[a-z0-9_.\-%]+$/i', $v) ? $clean[$name] = $v : $errors[] = "'{$name}' is not valid.";
            }
        }
        if (isset($clean['date_from'], $clean['date_to']) && $clean['date_to'] < $clean['date_from']) {
            $errors[] = 'date_to cannot be before date_from.';
        }

        return [$clean, $errors];
    }

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>, truncated: bool}
     */
    public function run(string $key, int $tenant, array $f, bool $isAdmin): array
    {
        $rows = match ($key) {
            'accounts' => $this->accounts($tenant, $f),
            'contacts' => $this->contacts($tenant, $f),
            'prospecting_icp' => $this->icp($tenant, $f),
            'signals' => $this->signals($tenant, $f),
            'pipeline' => $this->pipeline($tenant, $f),
            'forecast' => $this->forecastRows($tenant, $f),
            'closed_deals' => $this->closed($tenant, $f),
            'methodology' => $this->analyses($tenant, $f, 'methodology_score'),
            'discovery' => $this->analyses($tenant, $f, 'discovery_analysis'),
            'outreach' => $this->outreach($tenant, $f),
            'campaigns' => $this->campaignRows($tenant, $f),
            'customer_health' => $this->health($tenant, $f),
            'renewals' => $this->renewals($tenant, $f),
            'agent_usage' => $this->agentUsage($tenant, $f),
            'workflows' => $this->workflowRuns($tenant, $f),
            'audit' => $this->audit($tenant, $f),
        };
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        if (self::DEFS[$key]['pii'] && ! $isAdmin) {
            $rows = array_map(fn ($r) => $this->mask($r), $rows);
        }

        return ['rows' => $rows, 'summary' => $this->summary($key, $rows, $tenant, $f), 'truncated' => $truncated];
    }

    /** CSV cell text that a spreadsheet will not execute: a leading = + - @ tab or CR is neutralised (numbers are left alone). */
    public static function safeCell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // the first NON-WHITESPACE character decides: "\n=1" is as dangerous as "=1"
        return preg_match('/^\s*[=+\-@]/u', $s) && ! is_numeric(trim($s)) ? "'".$s : $s;
    }

    // ---------------------------------------------------------------- reports

    private function accounts(int $t, array $f): array
    {
        $q = DB::table('gtm_accounts as a')->where('a.sub_institute_id', $t)->whereNull('a.deleted_at')
            ->selectRaw("a.id, a.name, a.stage, a.industry, a.location, a.source, a.owner_user_id, a.icp_fit_score, a.created_at,
                (SELECT COUNT(*) FROM gtm_contacts c WHERE c.account_id = a.id AND c.sub_institute_id = ? AND c.deleted_at IS NULL) contacts,
                (SELECT COUNT(*) FROM g2g_company_opportunities o WHERE o.company_id = a.company_id AND o.sub_institute_id = ? AND o.review_status != 'Dismissed') signals,
                (SELECT MAX(x.occurred_at) FROM gtm_activities x WHERE x.account_id = a.id AND x.sub_institute_id = ? AND x.type != 'system') last_activity_at", [$t, $t, $t]);
        $this->eq($q, $f, ['stage' => 'a.stage', 'source' => 'a.source', 'owner_user_id' => 'a.owner_user_id']);
        isset($f['min_icp']) && $q->where('a.icp_fit_score', '>=', $f['min_icp']);
        $this->dates($q, $f, 'a.created_at');
        $users = $this->users($t);

        return $q->orderByDesc('a.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'stage' => $r->stage, 'industry' => $r->industry, 'location' => $r->location, 'source' => $r->source,
            'owner' => $users[$r->owner_user_id] ?? null, 'icp_fit_score' => $r->icp_fit_score === null ? null : (int) $r->icp_fit_score, 'contacts' => (int) $r->contacts, 'signals' => (int) $r->signals,
            'last_activity_at' => $r->last_activity_at, 'created_at' => $r->created_at])->all();
    }

    private function contacts(int $t, array $f): array
    {
        $q = DB::table('gtm_contacts as c')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'c.account_id')->where('a.sub_institute_id', '=', $t))->where('c.sub_institute_id', $t)->whereNull('c.deleted_at')
            ->select('c.id', 'c.full_name', 'c.title', 'c.email', 'c.phone', 'c.role_in_deal', 'c.status', 'c.source', 'c.created_at', 'a.name as account');
        $this->eq($q, $f, ['status' => 'c.status', 'role' => 'c.role_in_deal', 'account_id' => 'c.account_id']);
        $this->dates($q, $f, 'c.created_at');

        return $q->orderByDesc('c.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => (array) $r)->all();
    }

    private function icp(int $t, array $f): array
    {
        $q = DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')->whereNotNull('icp_scored_at');
        isset($f['min_icp']) && $q->where('icp_fit_score', '>=', $f['min_icp']);
        isset($f['max_icp']) && $q->where('icp_fit_score', '<=', $f['max_icp']);
        $this->dates($q, $f, 'icp_scored_at');

        return $q->orderByRaw('icp_fit_score IS NULL')->orderByDesc('icp_fit_score')->limit(self::MAX_ROWS + 1)->get(['id', 'name', 'stage', 'icp_fit_score', 'icp_fit_basis', 'icp_scored_at'])->map(function ($r) {
            $b = json_decode((string) $r->icp_fit_basis, true) ?: [];

            return ['id' => $r->id, 'name' => $r->name, 'stage' => $r->stage, 'icp_fit_score' => $r->icp_fit_score === null ? null : (int) $r->icp_fit_score, 'confidence' => $b['confidence'] ?? null, 'summary' => $b['summary'] ?? null,
                'missing' => implode('; ', (array) ($b['missing'] ?? [])), 'model' => $b['model'] ?? null, 'scored_at' => $r->icp_scored_at];
        })->all();
    }

    private function signals(int $t, array $f): array
    {
        $q = DB::table('g2g_company_opportunities as o')->leftJoin('g2g_companies as c', 'c.id', '=', 'o.company_id')->where('o.sub_institute_id', $t)
            ->leftJoin('gtm_accounts as a', fn ($j) => $j->on('a.company_id', '=', 'o.company_id')->where('a.sub_institute_id', '=', $t)->whereNull('a.deleted_at'))
            ->select('o.id', 'c.name as company', 'o.title', 'o.signal_kind', 'o.priority', 'o.review_status', 'o.confidence', 'o.event_date', 'o.sources', 'a.name as account', 'o.first_discovered_at');
        $this->eq($q, $f, ['priority' => 'o.priority', 'review_status' => 'o.review_status']);
        $this->dates($q, $f, 'o.first_discovered_at');

        return $q->orderByDesc('o.id')->limit(self::MAX_ROWS + 1)->get()->map(function ($r) {
            $urls = array_column(json_decode((string) $r->sources, true) ?: [], 'url');

            return ['id' => $r->id, 'company' => $r->company, 'title' => $r->title, 'signal_kind' => $r->signal_kind, 'priority' => $r->priority, 'review_status' => $r->review_status, 'confidence' => $r->confidence,
                'event_date' => $r->event_date, 'source_urls' => implode(' | ', $urls), 'account' => $r->account, 'first_discovered_at' => $r->first_discovered_at];
        })->all();
    }

    private function pipeline(int $t, array $f): array
    {
        $q = GtmDeal::query()->where('gtm_deals.sub_institute_id', $t)->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_deals.account_id')->where('a.sub_institute_id', '=', $t))->select('gtm_deals.*', 'a.name as account_name');
        $status = $f['status'] ?? 'open';
        $status === 'closed' ? $q->whereIn('gtm_deals.stage', ['won', 'lost']) : ($status === 'open' ? $q->whereIn('gtm_deals.stage', GtmDeal::OPEN_STAGES) : null);
        $this->eq($q, $f, ['stage' => 'gtm_deals.stage', 'owner_user_id' => 'gtm_deals.owner_user_id', 'currency' => 'gtm_deals.currency']);
        $this->dates($q, $f, 'gtm_deals.expected_close_date');
        $deals = $q->orderBy('gtm_deals.id')->limit(self::MAX_ROWS + 1)->get();
        $health = app(DealHealth::class)->assess($t, $deals);
        $users = $this->users($t);

        return $deals->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'account' => $d->account_name, 'stage' => $d->stage, 'amount' => $d->amount === null ? null : (float) $d->amount, 'currency' => $d->currency,
            'expected_close_date' => $d->expected_close_date?->toDateString(), 'owner' => $users[$d->owner_user_id] ?? null, 'next_step' => $d->next_step, 'days_in_stage' => $health[$d->id]['days_in_stage'] ?? null,
            'health_flags' => implode('; ', array_map(fn ($x) => DealHealth::FLAGS[$x] ?? $x, $health[$d->id]['flags'] ?? []))])->all();
    }

    private function forecastRows(int $t, array $f): array
    {
        $r = $this->forecast->build($t, array_intersect_key($f, array_flip(['owner_user_id', 'department_id', 'stage', 'currency'])) + ['from' => $f['date_from'] ?? null, 'to' => $f['date_to'] ?? null]);

        return array_map(fn ($p) => ['period' => $p['period'], 'currency' => $p['currency'], 'deal_count' => $p['deal_count'], 'pipeline' => $p['pipeline'], 'best_case' => $p['best_case'], 'commit' => $p['commit'], 'weighted' => $p['weighted']], $r['forecast']['periods']);
    }

    private function closed(int $t, array $f): array
    {
        $q = GtmDeal::query()->where('gtm_deals.sub_institute_id', $t)->whereIn('gtm_deals.stage', ['won', 'lost'])->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_deals.account_id')->where('a.sub_institute_id', '=', $t))->select('gtm_deals.*', 'a.name as account_name');
        $this->eq($q, $f, ['stage' => 'gtm_deals.stage', 'owner_user_id' => 'gtm_deals.owner_user_id', 'currency' => 'gtm_deals.currency']);
        $this->dates($q, $f, 'gtm_deals.closed_at');
        $users = $this->users($t);

        return $q->orderByDesc('gtm_deals.closed_at')->limit(self::MAX_ROWS + 1)->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'account' => $d->account_name, 'stage' => $d->stage, 'amount' => $d->amount === null ? null : (float) $d->amount,
            'currency' => $d->currency, 'closed_at' => $d->closed_at?->toDateTimeString(), 'close_reason' => $d->close_reason, 'owner' => $users[$d->owner_user_id] ?? null])->all();
    }

    /** latest analysis of one kind per deal */
    private function analyses(int $t, array $f, string $kind): array
    {
        $latest = DB::table('gtm_analyses')->where('sub_institute_id', $t)->where('kind', $kind)->where('subject_type', 'deal')->where('status', 'success')->selectRaw('MAX(id) id')->groupBy('subject_id');
        $q = DB::table('gtm_analyses as an')->joinSub($latest, 'l', 'l.id', '=', 'an.id')->join('gtm_deals as d', fn ($j) => $j->on('d.id', '=', 'an.subject_id')->where('d.sub_institute_id', '=', $t))
            ->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'd.account_id')->where('a.sub_institute_id', '=', $t))->select('an.result', 'an.model', 'an.created_at', 'd.id as deal_id', 'd.name as deal', 'a.name as account');
        $this->dates($q, $f, 'an.created_at');
        $rows = [];
        foreach ($q->orderByDesc('an.id')->limit(self::MAX_ROWS + 1)->get() as $r) {
            $res = json_decode((string) $r->result, true) ?: [];
            if ($kind === 'methodology_score') {
                $total = $res['total'] ?? null;
                if ((isset($f['min_total']) && ($total === null || $total < $f['min_total'])) || (isset($f['max_total']) && ($total === null || $total > $f['max_total']))) {
                    continue;
                }
                $rows[] = ['deal_id' => $r->deal_id, 'deal' => $r->deal, 'account' => $r->account, 'methodology' => $res['methodology']['title'] ?? null, 'total' => $total, 'assessed' => isset($res['coverage']) ? "{$res['coverage']['scored']} of {$res['coverage']['of']}" : null, 'model' => $r->model, 'scored_at' => $r->created_at];
            } else {
                $rows[] = ['deal_id' => $r->deal_id, 'deal' => $r->deal, 'account' => $r->account, 'problems' => count((array) ($res['problems'] ?? [])), 'gaps' => implode('; ', (array) ($res['gaps'] ?? [])), 'model' => $r->model, 'analysed_at' => $r->created_at];
            }
        }

        return $rows;
    }

    private function outreach(int $t, array $f): array
    {
        $q = DB::table('gtm_outreach_messages as m')->join('gtm_contacts as c', fn ($j) => $j->on('c.id', '=', 'm.contact_id')->where('c.sub_institute_id', '=', $t))->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'm.account_id')->where('a.sub_institute_id', '=', $t))
            ->leftJoin('gtm_campaigns as cp', fn ($j) => $j->on('cp.id', '=', 'm.campaign_id')->where('cp.sub_institute_id', '=', $t))->where('m.sub_institute_id', $t)
            ->select('m.id', 'c.full_name as contact', 'c.email', 'a.name as account', 'cp.name as campaign', 'm.subject', 'm.status', 'm.step', 'm.scheduled_for', 'm.sent_at', 'm.outcome', 'm.attempts', 'm.error', 'm.created_at');
        $this->eq($q, $f, ['status' => 'm.status', 'outcome' => 'm.outcome', 'campaign_id' => 'm.campaign_id']);
        $this->dates($q, $f, 'm.created_at');

        return $q->orderByDesc('m.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => (array) $r)->all();
    }

    private function campaignRows(int $t, array $f): array
    {
        $q = DB::table('gtm_campaigns')->where('sub_institute_id', $t)->whereNull('deleted_at');
        $this->eq($q, $f, ['status' => 'status', 'kind' => 'kind']);
        $this->dates($q, $f, 'created_at');

        return $q->orderByDesc('id')->limit(500)->get()->map(function ($c) use ($t) {
            $m = $this->metrics->for($t, (int) $c->id);

            return ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'status' => $c->status, 'industry_pack' => $c->industry_pack_slug, 'target_accounts' => $m['targets']['accounts'], 'contacts_reached' => $m['outreach']['contacts_reached'],
                'contacts_replied' => $m['engagement']['contacts_replied'], 'reply_rate_pct' => $m['engagement']['reply_rate_pct'], 'deals_created' => $m['conversion']['deals_created'], 'deals_won' => $m['conversion']['deals_won'],
                'won_value' => implode('; ', array_map(fn ($cur, $v) => "{$cur} {$v}", array_keys($m['conversion']['won_value_by_currency']), $m['conversion']['won_value_by_currency'])), 'created_at' => $c->created_at];
        })->all();
    }

    private function health(int $t, array $f): array
    {
        $rows = [];
        foreach ($this->customers->customers($t) as $c) {
            if (isset($f['band']) && $c['health']['band'] !== $f['band']) {
                continue;
            }
            $rows[] = ['account_id' => $c['account_id'], 'name' => $c['name'], 'score' => $c['health']['score'], 'band' => $c['health']['band'], 'risks' => implode('; ', $c['health']['risks']), 'missing' => implode('; ', $c['health']['missing']),
                'next_renewal' => $c['renewal']['date'] ?? null, 'renewal_status' => $c['renewal']['status'] ?? null];
        }

        return $rows;
    }

    private function renewals(int $t, array $f): array
    {
        $q = DB::table('gtm_renewals as r')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'r.account_id')->where('a.sub_institute_id', '=', $t))->where('r.sub_institute_id', $t)->whereNull('r.deleted_at')
            ->select('r.id', 'r.name', 'a.name as account', 'r.renewal_date', 'r.status', 'r.amount', 'r.currency', 'r.owner_user_id', 'r.outcome_note');
        $this->eq($q, $f, ['status' => 'r.status', 'owner_user_id' => 'r.owner_user_id']);
        $this->dates($q, $f, 'r.renewal_date');
        $users = $this->users($t);
        $today = now()->startOfDay();

        return $q->orderBy('r.renewal_date')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'account' => $r->account, 'renewal_date' => $r->renewal_date, 'days_until' => (int) $today->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false),
            'status' => $r->status, 'amount' => $r->amount === null ? null : (float) $r->amount, 'currency' => $r->currency, 'owner' => $users[$r->owner_user_id] ?? null, 'outcome_note' => $r->outcome_note])->all();
    }

    private function agentUsage(int $t, array $f): array
    {
        $q = DB::table('agentic_agent_runs as r')->join('agentic_agents as a', 'a.id', '=', 'r.agent_id')->where('r.sub_institute_id', $t)->whereNull('r.deleted_at')->where('a.sub_module', 'gtm')
            ->select('r.id', 'a.slug as agent', 'r.status', 'r.created_by', 'r.duration_ms', 'r.tokens_used', 'r.error_message', 'r.started_at');
        $this->eq($q, $f, ['agent' => 'a.slug', 'status' => 'r.status', 'user_id' => 'r.created_by']);
        $this->dates($q, $f, 'r.started_at');
        $users = $this->users($t);

        return $q->orderByDesc('r.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => ['id' => $r->id, 'agent' => $r->agent, 'status' => $r->status, 'run_by' => $users[$r->created_by] ?? null, 'duration_ms' => $r->duration_ms, 'tokens_used' => $r->tokens_used, 'error' => $r->error_message, 'started_at' => $r->started_at])->all();
    }

    private function workflowRuns(int $t, array $f): array
    {
        $q = DB::table('gtm_workflow_runs as r')->join('gtm_workflows as w', fn ($j) => $j->on('w.id', '=', 'r.workflow_id')->where('w.sub_institute_id', '=', $t))->where('r.sub_institute_id', $t)
            ->select('r.id', 'w.name as workflow', 'r.event_type', 'r.entity_type', 'r.entity_id', 'r.status', 'r.attempts', 'r.decided_by', 'r.error', 'r.created_at', 'r.finished_at');
        $this->eq($q, $f, ['status' => 'r.status', 'workflow_id' => 'r.workflow_id']);
        $this->dates($q, $f, 'r.created_at');
        $users = $this->users($t);

        return $q->orderByDesc('r.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => ['id' => $r->id, 'workflow' => $r->workflow, 'event_type' => $r->event_type, 'entity' => $r->entity_type ? "{$r->entity_type} #{$r->entity_id}" : null, 'status' => $r->status,
            'attempts' => $r->attempts, 'decided_by' => $users[$r->decided_by] ?? null, 'error' => $r->error, 'created_at' => $r->created_at, 'finished_at' => $r->finished_at])->all();
    }

    private function audit(int $t, array $f): array
    {
        $q = DB::table('g2g_event')->where('sub_institute_id', $t)->where('type', 'like', 'gtm.%');
        if (isset($f['type'])) {
            $q->where('type', 'like', str_replace(['%', '_'], ['\\%', '\\_'], rtrim($f['type'], '%')).'%');
        }
        isset($f['actor_id']) && $q->where('actor_id', $f['actor_id']);
        $this->dates($q, $f, 'occurred_at');
        $users = $this->users($t);

        return $q->orderByDesc('id')->limit(self::MAX_ROWS + 1)->get(['id', 'occurred_at', 'type', 'entity_type', 'entity_id', 'actor_id', 'metadata', 'payload'])->map(function ($e) use ($users) {
            $meta = json_decode((string) $e->metadata, true) ?: [];

            return ['id' => $e->id, 'occurred_at' => $e->occurred_at, 'type' => $e->type, 'entity' => $e->entity_type ? "{$e->entity_type} #{$e->entity_id}" : null,
                'actor' => $e->actor_id ? ($users[$e->actor_id] ?? "user {$e->actor_id}") : 'system', 'source' => $meta['source'] ?? ($e->actor_id ? 'user' : 'system'), 'detail' => mb_substr((string) $e->payload, 0, 300)];
        })->all();
    }

    // ---------------------------------------------------------------- helpers

    /** @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $q @param array<string, string> $map filter => column */
    private function eq($q, array $f, array $map): void
    {
        foreach ($map as $filter => $column) {
            if (isset($f[$filter])) {
                $q->where($column, $f[$filter]);
            }
        }
    }

    private function dates($q, array $f, string $column): void
    {
        isset($f['date_from']) && $q->where($column, '>=', $f['date_from'].' 00:00:00');
        isset($f['date_to']) && $q->where($column, '<=', $f['date_to'].' 23:59:59');
    }

    /** @return array<int, string> */
    private function users(int $t): array
    {
        return DB::table('tbluser')->where('sub_institute_id', $t)->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name.' '.$u->last_name)])->all();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mask(array $row): array
    {
        if (! empty($row['email']) && str_contains((string) $row['email'], '@')) {
            [$local, $domain] = explode('@', (string) $row['email'], 2);
            $row['email'] = mb_substr($local, 0, 1).'***@'.$domain;
        }
        if (! empty($row['phone'])) {
            $row['phone'] = str_repeat('*', max(0, mb_strlen((string) $row['phone']) - 3)).mb_substr((string) $row['phone'], -3);
        }

        return $row;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function summary(string $key, array $rows, int $t, array $f): array
    {
        $s = ['rows' => count($rows)];
        $sumBy = function (string $amount = 'amount', string $cur = 'currency') use ($rows) {
            $by = [];
            foreach ($rows as $r) {
                if (($r[$amount] ?? null) !== null && ! empty($r[$cur])) {
                    $by[$r[$cur]] = ($by[$r[$cur]] ?? 0) + (int) round(((float) $r[$amount]) * 100);
                }
            }

            return array_map(fn ($c) => $c / 100, $by);
        };
        match ($key) {
            'pipeline', 'closed_deals', 'renewals' => $s['value_by_currency'] = $sumBy(),
            'forecast' => $s['basis'] = 'calculated from open deals and configured probabilities; not a prediction of results',
            'agent_usage' => $s += ['total_tokens' => array_sum(array_map(fn ($r) => (int) ($r['tokens_used'] ?? 0), $rows)), 'failed' => count(array_filter($rows, fn ($r) => $r['status'] !== 'success'))],
            default => null,
        };

        return $s;
    }
}
