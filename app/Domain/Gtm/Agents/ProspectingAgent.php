<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\GtmAccount;
use Illuminate\Support\Facades\DB;

/** Briefs one account from its research signals, or orders the unreviewed signals across the organisation. */
final class ProspectingAgent extends GtmAgent
{
    public static function slug(): string { return 'gtm-prospecting'; }
    public static function name(): string { return 'Prospecting Agent'; }
    public static function description(): string { return 'Analyses companies and buying signals your research already found: briefs one account, or ranks unreviewed signals. Never searches the web itself.'; }
    public static function reads(): array { return ['gtm_accounts', 'g2g_company_opportunities', 'g2g_product_profiles']; }
    public static function inputs(): array
    {
        return [
            'mode' => ['type' => 'select', 'required' => true, 'label' => 'What to do', 'options' => ['account', 'signals']],
            'account_id' => ['type' => 'account', 'required' => false, 'label' => 'Account (for an account brief)'],
        ];
    }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $profile = $this->productProfile($tenant);

        if (($input['mode'] ?? 'account') === 'signals') {
            $signals = DB::table('g2g_company_opportunities as o')->leftJoin('g2g_companies as c', 'c.id', '=', 'o.company_id')
                ->where('o.sub_institute_id', $tenant)->where('o.review_status', 'New')
                ->orderByRaw("FIELD(o.priority,'High','Medium','Low')")->orderByDesc('o.first_discovered_at')->limit(20)
                ->get(['o.id', 'o.title', 'o.signal_kind', 'o.observed_event', 'o.priority', 'o.event_date', 'o.sources', 'c.name as company']);
            if ($signals->isEmpty()) {
                throw new NoDataException('There are no unreviewed signals to rank. New ones appear after the next signal research run.');
            }
            $records = ['product_profile' => $profile, 'signals' => $signals->map(fn ($s) => [
                'signal_id' => (int) $s->id, 'company' => $s->company, 'title' => $s->title, 'kind' => $s->signal_kind, 'event' => $s->observed_event,
                'priority' => $s->priority, 'event_date' => $s->event_date,
                'source_urls' => array_values(array_filter(array_column(json_decode($s->sources ?? '[]', true) ?: [], 'url'))),
            ])->all()];
            $valid = array_column($records['signals'], 'signal_id');
            $run = $this->ask($tenant, $userId, 'prospecting-signal-prioritisation', 'signal_prioritisation', 'tenant', $tenant, $records, [], 'Rank '.count($valid).' unreviewed signals');

            $ordered = array_values(array_filter((array) ($run['data']['ordered'] ?? []), fn ($o) => is_array($o) && in_array((int) ($o['signal_id'] ?? 0), $valid, true)));
            $dropped = count((array) ($run['data']['ordered'] ?? [])) - count($ordered);
            $byId = array_column($records['signals'], null, 'signal_id');
            $run['data']['ordered'] = array_map(fn ($o) => $o + ['title' => $byId[(int) $o['signal_id']]['title'], 'company' => $byId[(int) $o['signal_id']]['company'], 'source_urls' => $byId[(int) $o['signal_id']]['source_urls']], $ordered);
            $run['data']['weak_evidence'] = array_values(array_intersect($valid, array_map('intval', (array) ($run['data']['weak_evidence'] ?? []))));
        } else {
            $account = GtmAccount::where('sub_institute_id', $tenant)->find((int) ($input['account_id'] ?? 0));
            if (! $account) {
                throw new \DomainException('Choose one of your accounts.');
            }
            $signals = $this->signalsFor($tenant, $account->company_id);
            if ($signals === []) {
                throw new NoDataException("{$account->name} has no research signals to brief from. Add it from a research result, or run signal research for it first.");
            }
            $records = [
                'product_profile' => $profile,
                'account' => array_filter(['name' => $account->name, 'industry' => $account->industry, 'location' => $account->location, 'website' => $account->website ?: $account->domain, 'stage' => $account->stage], fn ($v) => $v !== null && $v !== ''),
                'signals' => $signals,
            ];
            $valid = array_column($signals, 'signal_id');
            $run = $this->ask($tenant, $userId, 'prospecting-account-research', 'account_research', 'account', (int) $account->id, $records, array_merge(...array_column($signals, 'source_urls')), "Account brief for {$account->name}");

            $ranked = array_values(array_filter((array) ($run['data']['ranked_signals'] ?? []), fn ($o) => is_array($o) && in_array((int) ($o['signal_id'] ?? 0), $valid, true)));
            $dropped = count((array) ($run['data']['ranked_signals'] ?? [])) - count($ranked);
            $byId = array_column($signals, null, 'signal_id');
            $run['data']['ranked_signals'] = array_map(fn ($o) => $o + ['title' => $byId[(int) $o['signal_id']]['title'], 'source_urls' => $byId[(int) $o['signal_id']]['source_urls']], $ranked);
        }

        return ['result' => $run['data'], 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? 'Some references the model cited did not exist in your data and were removed.' : null]];
    }
}
