<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\GtmAccount;
use Illuminate\Support\Facades\DB;

/**
 * Reviews accounts marked as customers. Renewal DATES and contract values are not recorded in
 * G2G yet, so the agent says so in its output instead of estimating them: what it can judge
 * is engagement history (recency and volume of logged activity) and live signals.
 */
final class CustomerSuccessAgent extends GtmAgent
{
    public static function slug(): string { return 'gtm-customer-success'; }
    public static function name(): string { return 'Customer Success Agent'; }
    public static function description(): string { return 'Reviews accounts you marked as customers for engagement-based renewal risk. Renewal dates and contract values are not tracked yet, so it does not estimate them.'; }
    public static function reads(): array { return ['gtm_accounts', 'gtm_contacts', 'gtm_activities', 'g2g_company_opportunities']; }
    public static function inputs(): array { return []; }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $accounts = GtmAccount::where('sub_institute_id', $tenant)->where('stage', 'customer')->orderBy('name')->limit(20)->get();
        if ($accounts->isEmpty()) {
            throw new NoDataException('No accounts are marked as customers. Set an account\'s stage to "customer" to include it.');
        }

        $facts = $accounts->map(function ($a) use ($tenant) {
            $acts = DB::table('gtm_activities')->where('sub_institute_id', $tenant)->where('account_id', $a->id)->where('type', '!=', 'system');
            $last = (clone $acts)->max('occurred_at');

            return [
                'account_id' => (int) $a->id, 'name' => $a->name, 'industry' => $a->industry,
                'contacts' => DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->where('account_id', $a->id)->whereNull('deleted_at')->count(),
                'activities_90d' => (clone $acts)->where('occurred_at', '>=', now()->subDays(90))->count(),
                'activities_total' => (clone $acts)->count(),
                'last_activity_at' => $last, 'days_since_last_activity' => $last ? (int) now()->diffInDays($last) : null,
                'recent_notes' => (clone $acts)->orderByDesc('occurred_at')->limit(5)->pluck('subject')->filter()->values()->all(),
                'signals' => $this->signalsFor($tenant, $a->company_id, 3),
            ];
        })->all();

        $valid = array_column($facts, 'account_id');
        $run = $this->ask($tenant, $userId, 'cs-renewal-risk-review', 'customer_review', 'tenant', $tenant,
            ['customer_accounts' => $facts, 'not_recorded' => ['renewal_dates', 'contract_values', 'product_usage']], [], 'Renewal risk review of '.count($facts).' customer account(s)', 2600);

        $rows = array_values(array_filter((array) ($run['data']['accounts'] ?? []), fn ($r) => is_array($r) && in_array((int) ($r['account_id'] ?? 0), $valid, true)));
        $dropped = count((array) ($run['data']['accounts'] ?? [])) - count($rows);
        $run['data']['accounts'] = $rows;
        $run['data']['facts'] = $facts; // the measured numbers the judgement rests on, shown beside it

        return ['result' => $run['data'], 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? 'Accounts the model referenced that are not in your customer list were removed.' : null]];
    }
}
