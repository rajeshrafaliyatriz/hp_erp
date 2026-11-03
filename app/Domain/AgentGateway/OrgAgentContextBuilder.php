<?php

namespace App\Domain\AgentGateway;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgAccountPresenter;
use App\Domain\OrgAccounts\OrgResolver;
use App\Domain\OrgAccounts\OrgSources;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assembles what the Organisation agent may know about one organisation (slice 7, thin): a bounded, tenant-scoped, READ-ONLY picture built from the
 * Organisation 360 (OrgAccountPresenter, which is already tenant-scoped and capped) plus the few things the 360 does not carry as rows with ids
 * (the signals' quote and source address, the notes and the open tasks). Nothing here writes.
 *
 * ONE ORGANISATION, ONE TENANT. Every query carries `sub_institute_id`, including the ones that follow a pointer from the identity into a source
 * table, so a pointer that was ever set into another organisation's row shows nothing of it.
 *
 * BOUNDED. A row cap per section and a character budget for the whole context (config agent_actions.max_context_chars); a fact that does not fit is
 * dropped from the END of the priority order, and a dropped fact is also not a ref the model may cite, because the model was not shown it.
 *
 * HOSTS DIFFER. The live database lacks tables and columns the dev one has: every optional table and column is asked about first (Schema::hasTable,
 * ColumnExists::all) and left out when missing. Only plain query-builder SQL is used, so it runs on sqlite (the tests), MariaDB 10.11 and 10.1.
 */
final class OrgAgentContextBuilder
{
    private const SIGNALS = 8;

    private const DEALS = 5;

    private const NOTES = 5;

    private const TASKS = 5;

    private const SUGGESTIONS = 5;

    /** Cuts a text to a length at a character boundary, on one line: a fact is one JSON line and its text never needs a line break. */
    private static function clip(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @param \Closure(int, OrgAccount): array<string, mixed>|null $threeSixty a seam for the tests: what to use instead of the real Organisation 360 */
    public function __construct(private readonly ?\Closure $threeSixty = null)
    {
    }

    public function build(int $tenantId, OrgAccount $account): AgentContext
    {
        $tenantId = (int) $tenantId;
        if ((int) $account->sub_institute_id !== $tenantId) {
            // Defence in depth: the caller found the identity by tenant already. An identity of another organisation is never described.
            throw new \InvalidArgumentException('The organisation does not belong to this tenant.');
        }

        // The Organisation 360 is another track's class and its answer may grow or change: every read of it below is tolerant of a missing key (data_get), so
        // a renamed field leaves a fact thinner instead of failing the run. AgentContextTest pins the keys this builder relies on.
        $three = $this->threeSixty !== null ? ($this->threeSixty)($tenantId, $account) : (new OrgAccountPresenter($tenantId))->threeSixty($account);
        $crmId = $account->crm_organization_id !== null && data_get($three, 'crm.id') !== null ? (int) data_get($three, 'crm.id') : null;
        $facts = [];

        // 1. The organisation itself.
        $sources = [];
        foreach (['crm' => 'CRM organisation', 'gtm' => 'GTM account', 'company' => 'Signals company'] as $key => $label) {
            if (data_get($three, "identity.links.{$key}") !== null) {
                $sources[] = ['source' => $label, 'link_basis' => data_get($three, "identity.links.{$key}.basis")];
            }
        }
        $facts[] = $this->fact('org_account', $account->id, (string) $account->name, "/module/crm/organisation-360/{$account->id}", [
            'name' => (string) $account->name, 'domain' => $account->domain, 'lifecycle' => $account->lifecycle, 'owner' => data_get($three, 'identity.owner_name'),
            'sources' => $sources, 'contacts_count' => (int) data_get($three, 'totals.contacts', 0),
            'last_activity_at' => data_get($three, 'activity.0.at'),
            'crm_organisation_record' => $crmId !== null ? 'exists: notes and tasks can be added' : 'none: notes and tasks cannot be added to this organisation',
            'signals_company' => data_get($three, 'company') ? ['name' => data_get($three, 'company.name'), 'industry' => data_get($three, 'company.industry'), 'location' => data_get($three, 'company.location')] : null,
        ]);

        if (data_get($three, 'crm') !== null) {
            $c = (array) $three['crm'];
            $facts[] = $this->fact('crm_organization', data_get($c, 'id'), (string) data_get($c, 'name'), '/module/crm/marketing/organizations/' . data_get($c, 'id'), [
                'industry' => data_get($c, 'industry'), 'city' => data_get($c, 'billing_city'), 'state' => data_get($c, 'billing_state'), 'website' => data_get($c, 'website'),
                'account_owner' => data_get($c, 'assigned_to_name'),
            ]);
        }
        if (data_get($three, 'gtm') !== null) {
            $g = (array) $three['gtm'];
            $facts[] = $this->fact('gtm_account', data_get($g, 'id'), (string) data_get($g, 'name'), null, [
                'stage' => data_get($g, 'stage'), 'icp_fit_score' => data_get($g, 'icp_fit_score'), 'account_owner' => data_get($g, 'owner_name'),
            ]);
        }

        // 2. Signals, with the evidence a person would look at first.
        $signals = $this->signals($tenantId, $account->g2g_company_id !== null ? (int) $account->g2g_company_id : null);
        foreach ($signals as $s) {
            $facts[] = $s;
        }

        // 3. Open deals.
        $deals = 0;
        foreach ((array) data_get($three, 'deals', []) as $d) {
            if ($deals >= self::DEALS) {
                break;
            }
            if (preg_match('/closed|won|lost/i', (string) data_get($d, 'stage', ''))) {
                continue;
            }
            $deals++;
            $crmDeal = data_get($d, 'source') === 'crm';
            $facts[] = $this->fact($crmDeal ? 'crm_opportunity' : 'gtm_deal', data_get($d, 'id'), (string) data_get($d, 'name'), $crmDeal ? '/module/crm/sales/opportunities/' . data_get($d, 'id') : '/gtm/deals/' . data_get($d, 'id'), [
                'stage' => data_get($d, 'stage'), 'amount' => data_get($d, 'amount'), 'currency' => data_get($d, 'currency'), 'expected_close' => data_get($d, 'closing_date'),
            ]);
        }

        // 4. Notes and open tasks on the CRM organisation.
        $notes = $crmId !== null ? $this->notes($tenantId, $crmId) : [];
        $tasks = $crmId !== null ? $this->tasks($tenantId, $crmId) : [];
        array_push($facts, ...$notes, ...$tasks);

        // 5. Possible duplicates a person has not decided yet that involve this organisation.
        $suggestions = $this->suggestions($tenantId, $account);
        foreach ($suggestions as $s) {
            $other = $s['left_is_mine'] ? $s['right'] : $s['left'];
            $facts[] = $this->fact('link_suggestion', $s['key'], "Possible link: {$other['name']}", "/module/crm/organisation-360/{$account->id}", [
                'other_record' => ['type' => $other['type'], 'name' => $other['name']], 'basis' => $s['basis'], 'score' => $s['score'],
            ]);
        }

        [$kept, $dropped] = $this->fit($facts);
        $keptKeys = array_flip(array_map(fn (array $f) => AgentContext::key($f['ref']['type'], $f['ref']['id']), $kept));

        return new AgentContext(
            tenantId: $tenantId,
            orgAccountId: (int) $account->id,
            orgName: (string) $account->name,
            crmOrganizationId: $crmId,
            today: now()->toDateString(),
            facts: $kept,
            linkSuggestions: array_values(array_map(
                fn (array $s) => ['key' => $s['key'], 'left' => $s['left'], 'right' => $s['right'], 'basis' => $s['basis'], 'score' => $s['score']],
                array_filter($suggestions, fn (array $s) => isset($keptKeys[AgentContext::key('link_suggestion', $s['key'])]))
            )),
            counts: ['facts' => count($kept), 'dropped_for_size' => $dropped, 'signals' => count($signals), 'deals' => $deals, 'notes' => count($notes), 'tasks' => count($tasks), 'suggestions' => count($suggestions)],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ref: array{type: string, id: string, label: string, url: ?string}, data: array<string, mixed>}
     */
    private function fact(string $type, string|int $id, string $label, ?string $url, array $data): array
    {
        return ['ref' => ['type' => $type, 'id' => (string) $id, 'label' => (string) self::clip($label, 120), 'url' => $url], 'data' => array_filter($data, fn ($v) => $v !== null && $v !== [])];
    }

    /**
     * Keeps facts, in order, while the rendered context stays inside the budget. The first fact (the organisation) always stays.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function fit(array $facts): array
    {
        $budget = (int) config('agent_actions.max_context_chars', 14000);
        $used = 0;
        $kept = [];
        $context = new AgentContext(0, 0, '', null, '', [], [], []);
        foreach ($facts as $i => $fact) {
            $length = mb_strlen($context->line($fact)) + 1;
            if ($i > 0 && $used + $length > $budget) {
                continue;   // a smaller later fact may still fit
            }
            $used += $length;
            $kept[] = $fact;
        }

        return [$kept, count($facts) - count($kept)];
    }

    /** @return list<array<string, mixed>> */
    private function signals(int $tenantId, ?int $companyId): array
    {
        if ($companyId === null || ! Schema::hasTable('g2g_company_opportunities')) {
            return [];
        }
        $table = 'g2g_company_opportunities';
        $have = array_map('strtolower', ColumnExists::all($table));
        $wanted = array_values(array_filter(
            ['id', 'title', 'review_status', 'event_date', 'source_published_at', 'source_url', 'source_title', 'observed_event', 'evidence_note', 'sources', 'business_fit', 'first_discovered_at'],
            fn (string $c) => in_array($c, $have, true)
        ));
        $q = DB::table($table)->where('sub_institute_id', $tenantId)->where('company_id', $companyId);
        if (in_array('is_sample', $have, true)) {
            $q->where('is_sample', 0);
        }
        $order = in_array('first_discovered_at', $have, true) ? 'first_discovered_at' : 'id';
        $facts = [];
        foreach ($q->orderByDesc($order)->orderByDesc('id')->limit(self::SIGNALS)->get($wanted) as $row) {
            $quote = null;
            $sources = json_decode((string) ($row->sources ?? ''), true);
            if (is_array($sources) && isset($sources[0]) && is_array($sources[0])) {
                $quote = self::clip($sources[0]['excerpt'] ?? null, 400);
            }
            $title = (string) $row->title;
            $facts[] = $this->fact('signal', $row->id, $title, '/module/crm/signal-review?status=all&q=' . rawurlencode(mb_substr($title, 0, 80)), [
                'title' => self::clip($title, 200),
                'review_status' => ($row->review_status ?? '') !== '' ? $row->review_status : 'New',
                'event_date' => $row->event_date ?? null,
                'published_on' => $row->source_published_at ?? null,
                'what_was_observed' => self::clip($row->observed_event ?? null, 300),
                'verbatim_quote' => $quote,
                'source_url' => self::clip($row->source_url ?? null, 300),
                'source_title' => self::clip($row->source_title ?? null, 150),
                'evidence_note' => self::clip($row->evidence_note ?? null, 200),
                'business_fit' => $row->business_fit ?? null,
            ]);
        }

        return $facts;
    }

    /** @return list<array<string, mixed>> */
    private function notes(int $tenantId, int $crmId): array
    {
        if (! Schema::hasTable('crm_organization_comments')) {
            return [];
        }
        $have = array_map('strtolower', ColumnExists::all('crm_organization_comments'));
        $facts = [];
        foreach (DB::table('crm_organization_comments')->where('sub_institute_id', $tenantId)->where('organization_id', $crmId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::NOTES)->get() as $row) {
            $facts[] = $this->fact('note', $row->id, (string) self::clip($row->comment, 60), "/module/crm/marketing/organizations/{$crmId}", [
                'text' => self::clip($row->comment, 400), 'written_at' => $row->created_at,
                'written_by' => in_array('created_via', $have, true) && ($row->created_via ?? null) === 'agent' ? 'the agent' : 'a person',
            ]);
        }

        return $facts;
    }

    /** @return list<array<string, mixed>> */
    private function tasks(int $tenantId, int $crmId): array
    {
        if (! Schema::hasTable('task_management_calendar_events')) {
            return [];
        }
        $facts = [];
        foreach (DB::table('task_management_calendar_events')->where('sub_institute_id', $tenantId)->where('linked_type', 'ORGANIZATION')->where('linked_id', $crmId)
            ->where('status', 'Planned')->whereNull('deleted_at')->orderBy('start_at')->orderBy('id')->limit(self::TASKS)->get(['id', 'title', 'start_at']) as $row) {
            $facts[] = $this->fact('task', $row->id, (string) $row->title, "/module/crm/marketing/organizations/{$crmId}", ['title' => self::clip($row->title, 150), 'due' => $row->start_at, 'status' => 'open']);
        }

        return $facts;
    }

    /** @return list<array{key: string, left: array{type: string, id: int, name: string}, right: array{type: string, id: int, name: string}, basis: string, score: float, left_is_mine: bool}> */
    private function suggestions(int $tenantId, OrgAccount $account): array
    {
        $mine = [];
        foreach (OrgAccount::POINTERS as $type => $column) {
            if ($account->{$column} !== null) {
                $mine[OrgSources::key($type, (int) $account->{$column})] = true;
            }
        }
        if ($mine === []) {
            return [];
        }

        $out = [];
        foreach (app(OrgResolver::class)->suggestions($tenantId, PHP_INT_MAX) as $s) {
            $leftMine = isset($mine[OrgSources::key($s['left']['type'], (int) $s['left']['id'])]);
            $rightMine = isset($mine[OrgSources::key($s['right']['type'], (int) $s['right']['id'])]);
            // Only a pair that is about THIS organisation, that a person could confirm, and that does not put it in two places at once.
            if ((! $leftMine && ! $rightMine) || ($leftMine && $rightMine) || ($s['confirmable'] ?? true) === false || ($s['place_conflict'] ?? false) === true) {
                continue;
            }
            $out[] = [
                'key' => (string) $s['key'],
                'left' => ['type' => (string) $s['left']['type'], 'id' => (int) $s['left']['id'], 'name' => (string) $s['left']['name']],
                'right' => ['type' => (string) $s['right']['type'], 'id' => (int) $s['right']['id'], 'name' => (string) $s['right']['name']],
                'basis' => (string) $s['basis'], 'score' => (float) $s['score'], 'left_is_mine' => $leftMine,
            ];
            if (count($out) >= self::SUGGESTIONS) {
                break;
            }
        }

        return $out;
    }
}
