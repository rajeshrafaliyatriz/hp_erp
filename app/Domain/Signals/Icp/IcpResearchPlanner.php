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
     * @return array{specs: list<array<string, mixed>>, name_search_account_ids: list<int>, name_search_missing_context: int, child_searches: list<array<string, string>>, account_ids: list<int>, accounts: list<array<string, mixed>>, skipped_without_website: int}
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
        $nameAccounts = collect();
        $nameMissingContext = 0;
        $requirePlace = (bool) config('signals.icp.name_search_require_place', true);
        $nameSlots = $needsWebsite ? max(0, (int) config('signals.icp.name_search_slots', 2)) : 0;
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
            // A website is not a precondition for finding an opportunity: a bounded number of due schools WITHOUT one are searched by name and place
            // (SchoolNameFallback). `due()` already leaves out a school checked within its recheck days, so the same search is not repeated.
            if ($nameSlots > 0) {
                $noSite = fn () => $due()->where(fn ($q) => $q->whereNull('website')->orWhere('website', ''));
                // A name-only query is ambiguous, so (by default) a school with no city and no state is left out of the slots, counted, and never
                // given an invented place. District kept only in attributes is not visible to this filter and counts as missing here.
                $hasPlace = fn ($q) => $q->where(fn ($w) => $w->where(fn ($c) => $c->whereNotNull('city')->where('city', '!=', ''))
                    ->orWhere(fn ($c) => $c->whereNotNull('state')->where('state', '!=', '')));
                if ($requirePlace) {
                    $nameMissingContext = (clone $noSite())->whereNot(fn ($q) => $hasPlace($q))->count();
                }
                $nameAccounts = ($requirePlace ? $noSite()->where(fn ($q) => $hasPlace($q)) : $noSite())
                    ->orderBy('watch_priority')->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->orderBy('id')->limit($nameSlots)->get();
            }
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

        // Name searches ride on top of the catalogue's slots (they have their own bound), tagged with the school they are about.
        $nameSpecs = [];
        $nameVariants = max(1, (int) config('signals.icp.name_search_variants', 1));
        foreach ($nameAccounts as $a) {
            $nameSpecs = array_merge($nameSpecs, \App\Domain\Signals\Scholar\SchoolNameFallback::queries(
                ['id' => (int) $a->id, 'name' => $a->name, 'city' => $a->city, 'state' => $a->state, 'attributes' => $a->attributes],
                (int) ($cfg['account_days'] ?? 30), $nameVariants
            ));
        }

        return [
            'specs' => array_merge(array_slice($specs, 0, $max + count($childSpecs)), $nameSpecs),
            'name_search_account_ids' => $nameAccounts->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'name_search_missing_context' => $nameMissingContext,
            'child_searches' => array_map(fn ($c) => ['id' => $c['id'], 'product' => $c['child_name'], 'kind' => $c['facet'], 'q' => $c['q']], $childSpecs),
            'account_ids' => $accounts->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'accounts' => $accounts->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'city' => $a->city, 'priority' => (int) $a->watch_priority])->all(),
            'skipped_without_website' => $skipped,
        ];
    }
}
