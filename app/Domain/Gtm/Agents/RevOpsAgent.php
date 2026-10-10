<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmDeal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline hygiene. WHICH deals are at risk is decided by DealHealth rules and is returned as
 * measured data; the model only recommends what to do about the ones flagged. No forecast: that
 * needs probabilities an organisation chooses, and none are recorded.
 */
final class RevOpsAgent extends GtmAgent
{
    private const MAX = 25;

    public static function slug(): string { return 'gtm-revops'; }
    public static function name(): string { return 'RevOps Agent'; }
    public static function description(): string { return 'Finds stalled deals and missing next steps across your open pipeline using fixed rules, then recommends re-engage, re-qualify or close-out for each. No forecasting.'; }
    public static function reads(): array { return ['gtm_deals', 'gtm_activities', 'gtm_contacts']; }
    public static function inputs(): array { return []; }

    public static function unavailable(): ?string
    {
        return Schema::hasTable('gtm_deals') ? null : 'Needs the deals tables. Run the GTM deals migration.';
    }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $open = GtmDeal::where('sub_institute_id', $tenant)->whereIn('stage', GtmDeal::OPEN_STAGES)->get();
        if ($open->isEmpty()) {
            throw new NoDataException('There are no open deals to review.');
        }
        $assessed = app(DealHealth::class)->assess($tenant, $open);
        $flagged = $open->filter(fn ($d) => $assessed[$d->id]['flags'] !== [])
            ->sortByDesc(fn ($d) => count($assessed[$d->id]['flags']))->take(self::MAX)->values();
        if ($flagged->isEmpty()) {
            throw new NoDataException("None of the {$open->count()} open deals has a health flag, so there is nothing to review.");
        }
        $accounts = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereIn('id', $flagged->pluck('account_id'))->pluck('name', 'id');

        $rows = $flagged->map(fn ($d) => [
            'deal_id' => (int) $d->id, 'name' => $d->name, 'account' => $accounts[$d->account_id] ?? null, 'stage' => $d->stage,
            'amount' => $d->amount, 'currency' => $d->currency, 'expected_close_date' => $d->expected_close_date?->toDateString(),
            'next_step' => $d->next_step, 'next_step_due' => $d->next_step_due?->toDateString(),
            'days_since_activity' => $assessed[$d->id]['days_since_activity'], 'days_in_stage' => $assessed[$d->id]['days_in_stage'],
            'flags' => array_map(fn ($f) => ['flag' => $f, 'meaning' => DealHealth::FLAGS[$f]], $assessed[$d->id]['flags']),
        ])->all();

        $valid = array_column($rows, 'deal_id');
        $run = $this->ask($tenant, $userId, 'revops-stalled-deal-review', 'pipeline_review', 'tenant', $tenant, ['flagged_deals' => $rows], [], 'Review of '.count($rows).' flagged deal(s)', 3000);

        $deals = array_values(array_filter((array) ($run['data']['deals'] ?? []), fn ($d) => is_array($d) && in_array((int) ($d['deal_id'] ?? 0), $valid, true)));
        $dropped = count((array) ($run['data']['deals'] ?? [])) - count($deals);
        $run['data']['deals'] = $deals;
        $run['data']['measured'] = ['open_deals' => $open->count(), 'flagged_deals' => $flagged->count(), 'flagged' => $rows];
        $this->ai->amend($tenant, $run['analysis_id'], $run['data']);

        return ['result' => $run['data'], 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? 'Deals the model referenced that were not in the flagged list were removed.' : null]];
    }
}
