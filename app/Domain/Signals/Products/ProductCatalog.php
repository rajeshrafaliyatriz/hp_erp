<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Opportunities\ProductProfile;
use Illuminate\Support\Facades\Schema;

/**
 * The products Signals can research, and what each one is configured with.
 *
 * A product is defined by records that already exist: its offers in the Product Portfolio (grouped by `parent_product`) and its
 * ICP in g2g_icp_profiles (`product_line`). Nothing is copied from one product to another: a product without its own ICP is
 * reported as not ready, never researched with another product's.
 */
final class ProductCatalog
{
    /** @return array<string, array{label: string, business_fit: string, portfolio_names: list<string>, icp_product_line: string, icp_template: ?string}> */
    public static function definitions(): array
    {
        return (array) config('signals_products', []);
    }

    /** Accepts a product key, its stored `business_fit` value or its label (any case). Null when it is none of them. */
    public static function normalise(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        foreach (self::definitions() as $key => $d) {
            if ($v === $key || $v === strtolower($d['business_fit']) || $v === strtolower($d['label'])) {
                return $key;
            }
        }

        return null;
    }

    /** An ICP row copied from a built-in definition by `signals:icp-seed` and never saved by a person (version 1, no author). */
    public static function isSeedCopy(IcpProfile $row): bool
    {
        return (int) $row->version === 1 && $row->created_by === null && $row->updated_by === null;
    }

    /** True when `$line` is the ICP product line of a configured product (so that ICP is that product's). */
    public static function isProductLine(?string $line): bool
    {
        foreach (self::definitions() as $d) {
            if ($line !== null && $line !== '' && $d['icp_product_line'] === $line) {
                return true;
            }
        }

        return false;
    }

    /** The stored `business_fit` value for a product key. */
    public static function businessFit(string $key): ?string
    {
        return self::definitions()[$key]['business_fit'] ?? null;
    }

    /**
     * Every configured product with its persisted configuration and what is still missing.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $tenantId): array
    {
        return array_values(array_map(fn (string $key) => $this->describe($tenantId, $key), array_keys(self::definitions())));
    }

    /** @return array<string, mixed> */
    public function describe(int $tenantId, string $key): array
    {
        $def = self::definitions()[$key] ?? throw new ProductNotConfigured($key, ['not a known product'], 'unknown_product');
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def);
        $missing = $this->missing($def, $icp, $this->effectiveOffers($tenantId, $icp['config'], $offers));
        $config = $icp['config'];

        return [
            'key' => $key,
            'label' => $def['label'],
            'business_fit' => $def['business_fit'],
            'portfolio_name' => $offers->pluck('parent_product')->filter()->unique()->first() ?? ($def['portfolio_names'][0] ?? null),
            'offers' => $offers->map(fn ($o) => [
                'id' => $o->id, 'offer_id' => $o->offer_id, 'name' => $o->name, 'what_it_does' => $o->what_it_does,
                'readiness_status' => $o->readiness_status, 'needs_solved' => $o->needs_solved, 'primary_segments' => $o->primary_segments,
                'trigger_signals' => $o->trigger_signals,
                // Pricing is shown only where a price was actually stored.
                'pricing_model' => $o->pricing_model ?: null, 'typical_deal_band' => $o->typical_deal_band ?: null,
            ])->values()->all(),
            'icp' => $config === null ? null : [
                'profile_id' => $icp['profile_id'], 'source' => $icp['source'], 'name' => $config['label'] ?? null,
                'pitch' => $config['pitch'] ?? null, 'ideal_customer' => $config['industry_label'] ?? null,
                'buyer_types' => $config['buyer_types'] ?? [], 'regions' => $config['regions'] ?? [],
                'triggers' => array_keys((array) ($config['triggers'] ?? [])), 'parts' => $config['parts'] ?? [],
                'searches' => count((array) ($config['queries'] ?? [])),
                // The portfolio products chosen for this ICP, by Offer ID. null = the ICP has no list (an older ICP): it uses the product's offers.
                'offer_ids' => array_key_exists('offer_ids', $config) ? array_values((array) $config['offer_ids']) : null,
            ],
            'draft_icp' => $this->draft($tenantId, $def),
            'target_accounts' => $config === null ? 0 : $this->accountCount($tenantId, $icp['pack_key']),
            'contacts' => $config === null ? 0 : $this->contactCount($tenantId, $icp['pack_key']),
            'ready' => $missing === [],
            // ready | needs_setup (nothing usable saved or required parts missing) | invalid (a saved ICP fails validation)
            'state' => $missing === [] ? 'ready' : ($icp['errors'] !== [] ? 'invalid' : 'needs_setup'),
            'next_step' => $this->nextStep($def, $icp, $missing, $this->draft($tenantId, $def)),
            'missing' => $missing,
        ];
    }

    /**
     * What is missing for this product if its ICP were `$config`. Used to decide whether a candidate ICP may be activated.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors  the validator's problems for it
     * @return list<string>
     */
    public function missingFor(int $tenantId, string $productKey, array $config, array $errors): array
    {
        $def = self::definitions()[$productKey] ?? throw new ProductNotConfigured($productKey, ['not a known product'], 'unknown_product');

        return $errors !== []
            ? array_map(fn ($e) => "The {$def['label']} ICP is invalid ({$e})", array_slice($errors, 0, 3))
            : $this->missing($def, ['config' => $config, 'errors' => []], $this->effectiveOffers($tenantId, $config, $this->offers($tenantId, $def)));
    }

    /** The next thing a person should do to make this product ready (null when it is). @param list<string> $missing */
    private function nextStep(array $def, array $icp, array $missing, ?array $draft = null): ?string
    {
        if ($missing === []) {
            return null;
        }
        if ($icp['config'] === null && $draft !== null) {
            return "A saved {$def['label']} ICP (\"{$draft['name']}\") is not active. Open its Ideal Customer Profile in Product Portfolio and activate it.";
        }
        if ($icp['config'] === null) {
            return "Open Product Portfolio, choose {$def['label']}, and fill in its Ideal Customer Profile, then activate it.";
        }

        return $icp['errors'] !== []
            ? "Open the {$def['label']} Ideal Customer Profile, fix the problems listed, and save."
            : "Open the {$def['label']} Ideal Customer Profile and complete what is listed.";
    }

    /**
     * The context for one product's run. Throws ProductNotConfigured (naming what is missing) instead of falling back to
     * another product's configuration.
     */
    public function resolve(int $tenantId, ?string $productKey): ProductContext
    {
        $key = self::normalise($productKey);
        if ($key === null) {
            throw new ProductNotConfigured((string) $productKey, ['not a known product'], 'unknown_product');
        }
        $def = self::definitions()[$key];
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def);
        $offers = $this->effectiveOffers($tenantId, $icp['config'], $offers);
        $missing = $this->missing($def, $icp, $offers);
        if ($missing !== []) {
            throw new ProductNotConfigured($def['label'], $missing);
        }

        $pack = new IcpPack($icp['pack_key'], $icp['config'], $icp['profile_id'], (string) ($icp['config']['label'] ?? $def['label']));
        $parts = $this->parts($def, $icp['config'], $offers);

        return new ProductContext($key, $def['label'], $def['business_fit'], $pack, $this->profile($tenantId, $def, $pack, $parts), $parts, (string) $icp['source']);
    }

    // ── what the product is made of ──────────────────────────────────────────

    /** A saved ICP that is not active yet (so the screen can say "activate it" instead of "create one"). @return array{id: int, name: string}|null */
    private function draft(int $tenantId, array $def): ?array
    {
        if (! Schema::hasTable('g2g_icp_profiles')) {
            return null;
        }
        $p = IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $def['icp_product_line'])->where('status', 'draft')->orderByDesc('id')->first();

        return $p ? ['id' => (int) $p->id, 'name' => (string) $p->name] : null;
    }

    /**
     * The offers that stand for this product in an ICP. When the ICP lists its products (`offer_ids`, possibly none) exactly those are used and
     * nothing is added for the person; an ICP without the list keeps the earlier behaviour (every offer of the product).
     *
     * @param  array<string, mixed>|null  $config
     */
    private function effectiveOffers(int $tenantId, ?array $config, $productOffers)
    {
        if ($config === null || ! array_key_exists('offer_ids', $config) || ! Schema::hasTable('g2g_product_offers')) {
            return $productOffers;
        }
        $ids = array_values(array_filter((array) $config['offer_ids'], 'is_string'));

        return $ids === [] ? collect() : ProductOffer::query()->forTenant($tenantId)->whereIn('offer_id', $ids)->orderBy('offer_id')->get();
    }

    private function offers(int $tenantId, array $def)
    {
        if (! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }

        return ProductOffer::query()->forTenant($tenantId)->whereIn('parent_product', $def['portfolio_names'])->orderBy('offer_id')->get();
    }

    /**
     * The product's ICP: the organisation's active profile for this product line; for a product with a built-in definition,
     * that definition fills any setting the stored profile does not set.
     *
     * @return array{config: ?array<string, mixed>, source: ?string, profile_id: ?int, pack_key: string, errors: list<string>}
     */
    private function icp(int $tenantId, array $def): array
    {
        $template = $def['icp_template'] ? config("signals_icp.{$def['icp_template']}") : null;
        $template = is_array($template) ? $template : null;
        $row = Schema::hasTable('g2g_icp_profiles')
            ? IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $def['icp_product_line'])->where('status', 'active')
                ->orderByDesc('is_default')->orderBy('id')->first()
            : null;

        if ($row === null) {
            return ['config' => $template, 'source' => $template ? 'template' : null, 'profile_id' => null,
                'pack_key' => (string) ($def['icp_template'] ?? $def['icp_product_line']), 'errors' => []];
        }

        // A saved ICP that was only COPIED from the built-in definition (seeded, version 1, never saved by a person) is a frozen snapshot of an
        // older definition: it must not replace the current one. The built-in definition applies until a person saves their own settings.
        if ($template !== null && self::isSeedCopy($row)) {
            return ['config' => $template, 'source' => 'template', 'profile_id' => (int) $row->id, 'pack_key' => (string) $row->key, 'errors' => []];
        }

        $stored = (array) $row->config;
        $errors = (new IcpProfileValidator())->validate($stored);
        $config = $template ? array_replace($template, $stored) : $stored;

        return ['config' => $config, 'source' => 'database', 'profile_id' => (int) $row->id, 'pack_key' => (string) $row->key, 'errors' => $errors];
    }

    /** @return list<string> */
    private function missing(array $def, array $icp, $offers): array
    {
        $label = $def['label'];
        if ($icp['config'] === null) {
            return ["No active ICP for {$label}: define who buys {$label} (an ICP whose product line is \"{$def['icp_product_line']}\") before discovery can run"];
        }
        $out = [];
        foreach (array_slice($icp['errors'], 0, 3) as $e) {
            $out[] = "The {$label} ICP is invalid ({$e})";
        }
        $c = $icp['config'];
        if (empty($c['triggers'])) {
            $out[] = "The {$label} ICP lists no buying triggers";
        }
        if (empty($c['queries'])) {
            $out[] = "The {$label} ICP has no search queries";
        }
        if (empty($c['industry_label'])) {
            $out[] = "The {$label} ICP does not say who the ideal customer is";
        }
        if (empty($c['parts']) && $offers->isEmpty()) {
            $out[] = "No {$label} offers in the Product Portfolio and no parts in its ICP: nothing a signal can be matched to";
        }

        return $out;
    }

    /** @return array<string, array{offer_id: string, name: string, parent_product: string, what_it_does: string}> */
    private function parts(array $def, array $config, $offers): array
    {
        $out = [];
        foreach ((array) ($config['parts'] ?? []) as $k => $what) {
            $out[(string) $k] = ['offer_id' => (string) $k, 'name' => ucwords(str_replace('_', ' ', (string) $k)),
                'parent_product' => (string) ($def['portfolio_names'][0] ?? $def['label']), 'what_it_does' => (string) $what];
        }
        if ($out === []) {
            foreach ($offers as $o) {
                $id = strtolower((string) $o->offer_id);
                $out[$id] = ['offer_id' => $id, 'name' => (string) $o->name, 'parent_product' => (string) $o->parent_product, 'what_it_does' => (string) ($o->what_it_does ?: $o->name)];
            }
        }

        return $out;
    }

    /** The profile the prompts read, built only from the ICP and the parts. Never saved. */
    private function profile(int $tenantId, array $def, IcpPack $pack, array $parts): ProductProfile
    {
        $c = $pack->config();
        $phrases = [];
        foreach ((array) ($c['disqualifiers'] ?? []) as $d) {
            $phrases = array_merge($phrases, array_map('strval', (array) ($d['phrases'] ?? [])));
        }

        return new ProductProfile([
            'sub_institute_id' => $tenantId,
            'product_name' => $def['label'],
            'description' => (string) ($c['pitch'] ?? implode('; ', array_column($parts, 'what_it_does'))),
            'problems_solved' => implode('; ', array_filter(array_map(fn ($t) => (string) ($t['pain'] ?? ''), (array) ($c['triggers'] ?? [])))),
            'features' => implode('; ', array_column($parts, 'what_it_does')),
            'keywords' => array_values(array_map('strval', (array) ($c['keywords'] ?? []))),
            'target_industries' => array_filter([(string) ($c['industry_label'] ?? '')]),
            'target_company_types' => array_values(array_map('strval', (array) ($c['buyer_types'] ?? []))),
            'target_markets' => array_values(array_map('strval', (array) ($c['regions'] ?? []))),
            'excluded' => array_values(array_unique($phrases)),
            'competitors' => array_values(array_map('strval', (array) ($c['competitors'] ?? []))),
            'recency_days' => (int) config('signals.opportunities.default_recency_days', 30),
        ]);
    }

    private function accountCount(int $tenantId, string $packKey): int
    {
        return Schema::hasTable('g2g_target_accounts') ? TargetAccount::where('sub_institute_id', $tenantId)->where('pack', $packKey)->count() : 0;
    }

    private function contactCount(int $tenantId, string $packKey): int
    {
        return Schema::hasTable('g2g_target_accounts')
            ? TargetAccount::where('sub_institute_id', $tenantId)->where('pack', $packKey)->whereNotNull('contact_name')->where('contact_name', '!=', '')->count()
            : 0;
    }
}
