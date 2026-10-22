<?php

namespace App\Domain\Signals\Icp;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductQueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Decides what one research run searches for, for one ICP: a rotating slice of its query catalogue plus one search
 * per named account that has not been checked recently, best prospects first. Used by the real run and by the
 * preview endpoint, so what an administrator previews is what will run. Reads the database; calls nothing external.
 */
final class IcpResearchPlanner
{
    /**
     * @param  string|null  $productKey  the product the run is for: when given, a repeat run on the same day moves on to the next slice of the
     *                                    catalogue instead of repeating the same searches (the slice otherwise depends only on the date)
     * @return array{specs: list<array<string, mixed>>, child_searches: list<array<string, string>>, account_ids: list<int>, accounts: list<array<string, mixed>>, skipped_without_website: int}
     */
    public function plan(int $tenantId, IcpPack $pack, ?Carbon $now = null, ?string $productKey = null): array
    {
        $cfg = $pack->config();
        $max = max(1, (int) config('signals.opportunities.max_queries', 6));
        $now ??= now(config('signals.timezone'));
        // `signals:research --batches=N` moves each batch on by a day so it takes the next slice of the query catalogue.
        $shift = (int) config('signals.icp.batch_shift', 0);
        // Pressing Generate again on the same day used to repeat exactly the same searches (same date, same slice) and find the same nothing.
        // Each run already finished today moves the slice on by one, so repeats widen the coverage at no extra search cost.
        if ($productKey !== null && config('signals.icp.rotate_repeat_runs', true)) {
            $shift += \App\Domain\Signals\Opportunities\ResearchRun::where('sub_institute_id', $tenantId)
                ->where(fn ($q) => $productKey === 'scholarclone' ? $q->whereNull('product_key')->orWhere('product_key', $productKey) : $q->where('product_key', $productKey))
                ->whereDate('report_date', $now->toDateString())->whereIn('status', ['success', 'partial'])->count();
        }
        $now = $now->copy()->addDays($shift);

        $accountSlots = (int) ($cfg['per_run']['accounts'] ?? 3);
        $needsWebsite = (bool) config('signals.icp.account_search_needs_website', true) && \App\Support\ColumnExists::has('g2g_target_accounts', 'website');
        $skipped = 0;
        $accounts = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('g2g_target_accounts')) {
            $due = fn () => TargetAccount::where('sub_institute_id', $tenantId)->where('pack', $pack->key)->where('watch_priority', '<', 9)
                ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDays((int) ($cfg['account_recheck_days'] ?? 7))));
            // A school with no verified website can only be found by a name search, which returned social-media noise and, over every check so far,
            // never a signal. Its search slot goes to the catalogue instead; schools WITH a website keep theirs and get their official site read.
            $query = $due();
            if ($needsWebsite) {
                $skipped = (clone $query)->where(fn ($q) => $q->whereNull('website')->orWhere('website', ''))->count();
                $query->whereNotNull('website')->where('website', '!=', '');
            }
            $accounts = $query->orderBy('watch_priority')->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->orderBy('id')->limit($accountSlots)->get();
        }
        // Only slots that a skipped school would have used move to the catalogue, so a run never makes more searches than it used to.
        $freed = $needsWebsite ? min($skipped, max(0, $accountSlots - $accounts->count())) : 0;
        $catalogueSlots = (int) ($cfg['per_run']['catalogue'] ?? 3) + $freed;

        $specs = array_map(fn ($q) => array_filter([
            'q' => $q['q'], 'topic' => $q['topic'], 'days' => $q['days'], 'domains' => $q['domains'] ?? null, 'source' => $q['source'], 'id' => $q['id'],
        ]), $pack->catalogueForRun($now, $catalogueSlots));

        // The product's own child products: searches written from what is stored about each one (see ProductQueryBuilder). They are ADDED to the
        // catalogue's and the accounts' (a child product used to change no search at all), up to `discovery.child_product_queries` (0 = off).
        $childSpecs = [];
        $childSlots = $productKey === null ? 0 : max(0, (int) config('signals.discovery.child_product_queries', 2));
        if ($childSlots > 0) {
            $builder = new ProductQueryBuilder();
            $childSpecs = $builder->window($builder->forOffers((new ProductCatalog())->researchOffers($tenantId, $productKey)->take(12), $pack), $now, $childSlots);
        }
        $specs = array_merge($specs, $childSpecs);

        foreach ($accounts as $a) {
            $specs[] = $pack->accountQuery($a->name, $a->city) + ['source' => 'account', 'id' => 'account-' . $a->id];
        }

        return [
            'specs' => array_slice($specs, 0, $max + count($childSpecs)),
            'child_searches' => array_map(fn ($c) => ['id' => $c['id'], 'product' => $c['child_name'], 'kind' => $c['facet'], 'q' => $c['q']], $childSpecs),
            'account_ids' => $accounts->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'accounts' => $accounts->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'city' => $a->city, 'priority' => (int) $a->watch_priority])->all(),
            'skipped_without_website' => $skipped,
        ];
    }
}
