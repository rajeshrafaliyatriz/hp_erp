<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\Customers\CustomerHealth;
use App\Domain\Gtm\Customers\CustomerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer Success, in two modes.
 *
 * renewal_risk: the model explains and prioritises what CustomerHealth already MEASURED (activity recency,
 *   replies, contact coverage, recorded renewals). Health and renewal figures come from the rules and are
 *   returned beside the model's text; what is not recorded is listed as missing, never estimated.
 * expansion: only opportunities backed by a real research signal on that customer. Each one must name the
 *   signal it rests on; any whose signal is not one of the customer's own is removed.
 *
 * Advice only: the agent writes nothing.
 */
final class CustomerSuccessAgent extends GtmAgent
{
    public static function slug(): string { return 'gtm-customer-success'; }
    public static function name(): string { return 'Customer Success Agent'; }
    public static function description(): string { return 'Reviews customer accounts: renewal risk from measured health and recorded renewals, or expansion opportunities backed by a real signal. Nothing is estimated where a record is missing.'; }
    public static function reads(): array { return ['gtm_accounts', 'gtm_contacts', 'gtm_activities', 'gtm_renewals', 'g2g_company_opportunities']; }
    public static function inputs(): array
    {
        return ['mode' => ['type' => 'select', 'required' => true, 'label' => 'What to review', 'options' => ['renewal_risk', 'expansion']]];
    }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $accounts = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('stage', 'customer')->orderBy('name')->limit(20)->get(['id', 'name', 'industry', 'company_id']);
        if ($accounts->isEmpty()) {
            throw new NoDataException('No accounts are marked as customers. Close a won deal, or set an account\'s stage to "customer", to include it.');
        }

        return ($input['mode'] ?? 'renewal_risk') === 'expansion'
            ? $this->expansion($tenant, $userId, $accounts)
            : $this->renewalRisk($tenant, $userId, $accounts);
    }

    private function renewalRisk(int $tenant, ?int $userId, $accounts): array
    {
        $facts = app(CustomerService::class)->facts($tenant, $accounts->pluck('id')->all());
        $rows = [];
        $anyAmount = false;
        foreach ($accounts as $a) {
            $f = $facts[$a->id];
            $h = CustomerHealth::assess($f);
            $renewal = $f['renewal'];
            $anyAmount = $anyAmount || ($renewal && $renewal['amount'] !== null);
            $rows[] = [
                'account_id' => (int) $a->id, 'name' => $a->name, 'industry' => $a->industry,
                'measured_health' => ['score' => $h['score'], 'band' => $h['band'], 'components' => array_map(fn ($c) => $c['detail'], $h['components']), 'risks' => $h['risks'], 'missing' => $h['missing']],
                'renewal' => $renewal ? ['name' => $renewal['name'], 'date' => $renewal['date'], 'status' => $renewal['status'], 'amount' => $renewal['amount'], 'currency' => $renewal['currency'], 'days_until' => (int) now()->startOfDay()->diffInDays(Carbon::parse($renewal['date'])->startOfDay(), false)] : null,
                'recent_notes' => DB::table('gtm_activities')->where('sub_institute_id', $tenant)->where('account_id', $a->id)->where('type', '!=', 'system')->orderByDesc('occurred_at')->limit(5)->pluck('subject')->filter()->values()->all(),
                'signals' => $this->signalsFor($tenant, $a->company_id, 3),
            ];
        }
        $notRecorded = array_values(array_filter(['product_usage', $anyAmount ? null : 'contract_values']));

        $valid = array_column($rows, 'account_id');
        $run = $this->ask($tenant, $userId, 'cs-renewal-risk-review', 'customer_review', 'tenant', $tenant,
            ['customer_accounts' => $rows, 'not_recorded' => $notRecorded], [], 'Renewal risk review of '.count($rows).' customer account(s)', 2800,
            'The "measured_health" and "renewal" figures were computed by rules from recorded data; explain and prioritise them, do not recompute or contradict them. If measured_health.missing is not empty, say what is missing instead of judging that part.');

        $out = array_values(array_filter((array) ($run['data']['accounts'] ?? []), fn ($r) => is_array($r) && in_array((int) ($r['account_id'] ?? 0), $valid, true)));
        $dropped = count((array) ($run['data']['accounts'] ?? [])) - count($out);
        $run['data']['accounts'] = $out;
        $run['data']['mode'] = 'renewal_risk';
        $run['data']['facts'] = array_map(fn ($r) => ['account_id' => $r['account_id'], 'name' => $r['name'], 'score' => $r['measured_health']['score'], 'band' => $r['measured_health']['band'], 'risks' => $r['measured_health']['risks'], 'missing' => $r['measured_health']['missing'], 'renewal' => $r['renewal']], $rows);
        $this->ai->amend($tenant, $run['analysis_id'], $run['data']);

        return $this->done($run, $dropped, 'Accounts the model referenced that are not in your customer list were removed.');
    }

    private function expansion(int $tenant, ?int $userId, $accounts): array
    {
        $profile = $this->productProfile($tenant);
        $rows = [];
        $validSignals = [];
        foreach ($accounts as $a) {
            $signals = $this->signalsFor($tenant, $a->company_id, 6);
            if ($signals === []) {
                continue;
            }
            $validSignals[(int) $a->id] = array_column($signals, 'signal_id');
            $rows[] = ['account_id' => (int) $a->id, 'name' => $a->name, 'industry' => $a->industry, 'signals' => $signals];
        }
        if ($rows === []) {
            throw new NoDataException('None of your customers has a research signal, so there is no real basis to suggest an expansion. Run signal research that covers them first.');
        }
        $run = $this->ask($tenant, $userId, 'cs-expansion-opportunities', 'customer_expansion', 'tenant', $tenant, ['customer_accounts' => $rows, 'product_profile' => $profile], [], 'Expansion review of '.count($rows).' customer account(s)', 2800,
            'Every opportunity must include "signal_id", the id of one signal listed for that same account. Do not suggest an expansion that no listed signal supports.');

        $out = array_values(array_filter((array) ($run['data']['opportunities'] ?? []), fn ($o) => is_array($o)
            && in_array((int) ($o['signal_id'] ?? 0), $validSignals[(int) ($o['account_id'] ?? 0)] ?? [], true) && trim((string) ($o['evidence'] ?? '')) !== ''));
        $dropped = count((array) ($run['data']['opportunities'] ?? [])) - count($out);
        $byId = [];
        foreach ($rows as $r) {
            foreach ($r['signals'] as $s) {
                $byId[$r['account_id']][$s['signal_id']] = $s;
            }
        }
        $run['data']['opportunities'] = array_map(fn ($o) => $o + ['signal_title' => $byId[(int) $o['account_id']][(int) $o['signal_id']]['title'] ?? null, 'source_urls' => $byId[(int) $o['account_id']][(int) $o['signal_id']]['source_urls'] ?? []], $out);
        $run['data']['mode'] = 'expansion';
        $this->ai->amend($tenant, $run['analysis_id'], $run['data']);

        return $this->done($run, $dropped, 'Opportunities that did not rest on one of the customer\'s own signals were removed.');
    }

    /** @param array<string, mixed> $run */
    private function done(array $run, int $dropped, string $note): array
    {
        return ['result' => $run['data'], 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? $note : null]];
    }
}
