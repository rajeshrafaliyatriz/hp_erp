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

    /** @var array<int, array<string, array<string, mixed>>> an organisation's custom products, read once per request */
    private static array $custom = [];

    /** Forgets the custom products read so far (after one is created, and between tests). */
    public static function forgetCustom(): void
    {
        self::$custom = [];
    }

    /**
     * One organisation's own products, in the same shape as the built-in definitions plus `custom => true`. Empty when the organisation has none
     * or the table has not been created yet: a built-in product never depends on it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function customFor(int $tenantId): array
    {
        if (isset(self::$custom[$tenantId])) {
            return self::$custom[$tenantId];
        }
        $out = [];
        try {
            if (Schema::hasTable('g2g_signal_products')) {
                foreach (CustomProduct::where('sub_institute_id', $tenantId)->where('status', 'active')->orderBy('id')->get() as $r) {
                    $out[(string) $r->key] = [
                        'label' => (string) $r->label, 'business_fit' => (string) $r->business_fit,
                        'portfolio_names' => array_values(array_filter((array) ($r->portfolio_names ?: [$r->label]), 'is_string')),
                        'icp_product_line' => (string) $r->icp_product_line, 'icp_template' => null, 'custom' => true,
                    ];
                }
            }
        } catch (\Throwable) {
            $out = [];
        }

        return self::$custom[$tenantId] = $out;
    }

    /**
     * The products one organisation can use: the built-in ones, then its own. A custom product can never replace a built-in one (the creation
     * rules refuse a clashing key, and the built-in definition wins here regardless). Without a tenant: the built-in ones only.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitionsFor(?int $tenantId): array
    {
        $builtIn = self::definitions();

        return $tenantId === null || $tenantId <= 0 ? $builtIn : $builtIn + self::customFor($tenantId);
    }

    /**
     * Creates one top-level product for the organisation. It gets its own ICP product line, so its ICP is saved and read separately from every
     * other product. Throws CustomProductInvalid (naming every problem) when the key or name cannot be used.
     *
     * @return array<string, mixed> the product as described
     */
    public function createCustom(int $tenantId, ?int $userId, string $key, string $label): array
    {
        $key = strtolower(trim($key));
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if (! Schema::hasTable('g2g_signal_products')) {
            throw new CustomProductInvalid(['Custom products are not available yet: the g2g_signal_products table has not been created.'], 'Not available');
        }
        self::forgetCustom();
        $problems = CustomProductRules::problems($key, $label, self::definitions(), self::customFor($tenantId));
        if ($problems !== []) {
            throw new CustomProductInvalid($problems);
        }
        try {
            CustomProduct::create([
                'sub_institute_id' => $tenantId, 'key' => $key, 'label' => $label, 'business_fit' => $key, 'icp_product_line' => $key,
                'portfolio_names' => [$label], 'status' => 'active', 'created_by' => $userId,
            ]);
        } catch (\Illuminate\Database\QueryException) {
            // The unique keys (organisation + key / business fit / ICP line) caught a clash the check above could not see (a second request).
            self::forgetCustom();
            throw new CustomProductInvalid(['key: this product already exists']);
        }
        self::forgetCustom();

        return $this->describe($tenantId, $key);
    }

    /** Accepts a product key, its stored `business_fit` value or its label (any case). Null when it is none of them. */
    public static function normalise(?string $value, ?int $tenantId = null): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        $found = self::normaliseExact($v, $tenantId);
        if ($found === null && ($alt = self::keyFrom($v)) !== $v) {
            // A key made from a short Product ID ("p3") is stored as the server's own key ("prod_p3"): either spelling finds it.
            $found = self::normaliseExact($alt, $tenantId);
        }

        return $found;
    }

    private static function normaliseExact(string $v, ?int $tenantId): ?string
    {
        $vClean = str_replace(['-', '_', ' '], '', $v);
        foreach (self::definitionsFor($tenantId) as $key => $d) {
            $keyClean = str_replace(['-', '_', ' '], '', $key);
            $fitClean = str_replace(['-', '_', ' '], '', strtolower($d['business_fit']));
            $labelClean = str_replace(['-', '_', ' '], '', strtolower($d['label']));
            if ($v === $key || $v === strtolower($d['business_fit']) || $v === strtolower($d['label']) ||
                $vClean === $keyClean || $vClean === $fitClean || $vClean === $labelClean) {
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
    public static function isProductLine(?string $line, ?int $tenantId = null): bool
    {
        foreach (self::definitionsFor($tenantId) as $d) {
            if ($line !== null && $line !== '' && $d['icp_product_line'] === $line) {
                return true;
            }
        }

        return false;
    }

    /** The stored `business_fit` value for a product key. */
    public static function businessFit(string $key, ?int $tenantId = null): ?string
    {
        return self::definitionsFor($tenantId)[$key]['business_fit'] ?? null;
    }

    /**
     * Every configured product with its persisted configuration and what is still missing.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $tenantId): array
    {
        return array_values(array_map(fn (string $key) => $this->describe($tenantId, $key), array_keys(self::definitionsFor($tenantId))));
    }

    /**
     * The ICP configuration a product is researched with, exactly as discovery reads it (the active saved ICP laid over the built-in
     * definition where there is one), with where it came from. Read-only.
     *
     * @return array{def: array<string, mixed>, icp: array{config: ?array<string, mixed>, source: ?string, profile_id: ?int, pack_key: string, errors: list<string>}}|null
     */
    public function icpFor(int $tenantId, string $key): ?array
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;

        return $def === null ? null : ['def' => $def, 'icp' => $this->icp($tenantId, $def)];
    }

    /**
     * Why signals cannot be generated for this product yet, or null. An organisation's own product can have an ICP, but the qualification gate
     * (QualificationGate::BUSINESS_FIT) still accepts only the built-in business-fit values, so its candidates would all be refused after the
     * searches had been paid for. Generation stays off until the gate accepts a product's own value and that is verified on an isolated database
     * (then set signals.custom_products.generate to true). Built-in products are never affected.
     *
     * @param  array<string, mixed>  $def
     */
    public static function generationBlock(array $def): ?string
    {
        if (empty($def['custom']) || config('signals.custom_products.generate', false)) {
            return null;
        }

        return 'Signal generation for your own products is not switched on yet: it is verified on an isolated database first (the qualification gate already accepts a product\'s own business fit for its own runs). Its ICP can be configured and saved now.';
    }

    /**
     * What a run for this product would be built from, as a report (see ResearchContextReport): the saved product details (all of the product's
     * own offers, plus those its ICP lists) and the ICP discovery would use. Read-only; nothing is borrowed from another product.
     *
     * @return array<string, mixed>
     */
    public function researchContext(int $tenantId, string $key, ?\App\Domain\Signals\Support\RegionScope $scope = null, ?string $offerId = null): array
    {
        $described = $this->describe($tenantId, $key);
        $found = $this->icpFor($tenantId, $key);
        $sub = $offerId !== null ? $this->subProduct($tenantId, $key, $offerId) : null;
        $subOffer = $sub['offer'] ?? null;
        if ($subOffer !== null && $sub['config'] !== null && $found['icp']['config'] !== null) {
            // The notice describes what a run for THIS sub-product uses: its own ICP over the product's rules, and only its own offer.
            $found['icp']['config'] = (new IcpPack($key, (array) $found['icp']['config']))->withSubProduct($sub['config'], (string) $subOffer->offer_id, (string) $subOffer->name)->config();
            $described['label'] = $described['label'] . ' → ' . $subOffer->name;
        }
        $offers = ($subOffer !== null ? collect([$subOffer]) : $this->childOffers($tenantId, $key))->map(fn ($o) => [
            'what_it_does' => $o->what_it_does, 'modules_components' => $o->modules_components, 'needs_solved' => $o->needs_solved,
            'primary_segments' => $o->primary_segments, 'trigger_signals' => $o->trigger_signals, 'proof_points' => $o->proof_points,
            'pricing_model' => $o->pricing_model, 'typical_deal_band' => $o->typical_deal_band, 'deployment_model' => $o->deployment_model,
            'delivery_owner' => $o->delivery_owner, 'notes' => $o->notes,
        ])->values()->all();
        $companyProfile = false;
        try {
            $companyProfile = Schema::hasTable('g2g_product_profiles') && ProductProfile::where('sub_institute_id', $tenantId)->exists();
        } catch (\Throwable) {
            // unknown is reported as not saved
        }
        $profileId = $found['icp']['profile_id'] ?? null;
        $version = null;
        if ($profileId !== null && Schema::hasTable('g2g_icp_profiles')) {
            $version = IcpProfile::where('sub_institute_id', $tenantId)->where('id', $profileId)->value('version');
        }

        $report = ResearchContextReport::build(['offers' => $offers] + $described, $found['icp']['config'] ?? null, $version === null ? null : (int) $version, $companyProfile, $scope);
        if ($sub !== null && $sub['problems'] !== []) {
            $report['warnings'][] = ($subOffer?->name ?? $offerId) . ' cannot be run on its own yet: ' . implode('; ', $sub['problems']) . '.';
        }

        return $report;
    }

    /** @return array<string, mixed> */
    public function describe(int $tenantId, string $key): array
    {
        $def = self::definitionsFor($tenantId)[$key] ?? throw new ProductNotConfigured($key, ['not a known product'], 'unknown_product');
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def);
        // A custom product's offers are those in its own group plus the ones its ICP lists (it has no built-in group to read them from).
        if (! empty($def['custom']) && $icp['config'] !== null) {
            $chosen = array_values(array_filter((array) ($icp['config']['offer_ids'] ?? []), 'is_string'));
            if ($chosen !== [] && Schema::hasTable('g2g_product_offers')) {
                $offers = $offers->concat(ProductOffer::query()->forTenant($tenantId)->whereIn('offer_id', $chosen)->get())->unique('offer_id')->values();
            }
        }
        $missing = $this->missing($def, $icp, $this->effectiveOffers($tenantId, $icp['config'], $offers));
        // What each sub-product's own ICP still needs for a run about it alone (one read for all of them).
        $subProblems = array_map(fn ($c) => SubProductIcp::problems($c), SubProductIcp::configsFor($tenantId, $offers));
        $config = $icp['config'];

        return [
            'key' => $key,
            'label' => $def['label'],
            'business_fit' => $def['business_fit'],
            'custom' => ! empty($def['custom']),
            'generation_note' => self::generationBlock($def),
            'portfolio_name' => $offers->pluck('parent_product')->filter()->unique()->first() ?? ($def['portfolio_names'][0] ?? null),
            'offers' => $offers->map(fn ($o) => [
                'icp_ready' => ($subProblems[(string) $o->offer_id] ?? ['x']) === [], 'icp_missing' => $subProblems[(string) $o->offer_id] ?? [],
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
    /**
     * A valid custom-product key from whatever a form sends ("P3", "My Product-01"): lower-case letters, digits and underscores, starting with a
     * letter, 3 to 20 characters. Too short a key gets a "prod_" prefix. Pure.
     */
    public static function keyFrom(string $raw): string
    {
        $k = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($raw)), '_');
        $k = preg_match('/^[a-z]/', $k) === 1 ? $k : 'p_' . $k;
        $k = rtrim(substr($k, 0, 20), '_');

        return strlen($k) >= 3 ? $k : substr('prod_' . $k, 0, 20);
    }

    public function missingFor(int $tenantId, string $productKey, array $config, array $errors): array
    {
        $def = self::definitionsFor($tenantId)[$productKey] ?? throw new ProductNotConfigured($productKey, ['not a known product'], 'unknown_product');

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
        $key = self::normalise($productKey, $tenantId);
        if ($key === null) {
            throw new ProductNotConfigured((string) $productKey, ['not a known product'], 'unknown_product');
        }
        $def = self::definitionsFor($tenantId)[$key];
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def);
        $offers = $this->effectiveOffers($tenantId, $icp['config'], $offers);
        $missing = $this->missing($def, $icp, $offers);
        if ($missing !== []) {
            throw new ProductNotConfigured($def['label'], $missing);
        }
        // Refused here, before any search, AI call or file is stored, so a switched-off product never spends provider quota.
        if (($note = self::generationBlock($def)) !== null) {
            throw new ProductNotConfigured($def['label'], [$note]);
        }

        $pack = new IcpPack($icp['pack_key'], $icp['config'], $icp['profile_id'], (string) ($icp['config']['label'] ?? $def['label']));
        // The parts a signal can be built on: the ICP's own, and the offers this ICP uses (all of the product's, or exactly those the ICP lists).
        $parts = $this->parts($def, $icp['config'], $offers, $offers);

        return new ProductContext(
            $key, $def['label'], $def['business_fit'], $pack, $this->profile($tenantId, $def, $pack, $parts), $parts, (string) $icp['source'],
            $this->childProfiles($this->childOffers($tenantId, $key)),
        );
    }

    /**
     * A sub-product of this product (one of its offers) and what a run for it still needs. The offer must be one of THIS product's offers: a
     * sub-product of another product is never accepted here.
     *
     * @return array{offer: ProductOffer|null, config: array<string, mixed>|null, problems: list<string>}
     */
    public function subProduct(int $tenantId, string $productKey, string $offerId): array
    {
        $def = self::definitionsFor($tenantId)[$productKey] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return ['offer' => null, 'config' => null, 'problems' => ['unknown product']];
        }
        $icp = $this->icp($tenantId, $def);
        $offers = $this->effectiveOffers($tenantId, $icp['config'], $this->offers($tenantId, $def));
        $offer = $offers->first(fn ($o) => strcasecmp((string) $o->offer_id, $offerId) === 0);
        if ($offer === null) {
            return ['offer' => null, 'config' => null, 'problems' => ["'{$offerId}' is not one of {$def['label']}'s products"]];
        }
        $config = SubProductIcp::configFor($tenantId, $offer);

        return ['offer' => $offer, 'config' => $config, 'problems' => SubProductIcp::problems($config)];
    }

    /**
     * The context for a run about ONE sub-product: the product's own qualification rules and accounts, with the sub-product's own ICP (who buys it,
     * its triggers and searches) and only that offer as what a signal can be about. Throws ProductNotConfigured (naming what is missing) when the
     * product or the sub-product's ICP is not ready, and never uses another product's.
     */
    public function resolveSub(int $tenantId, ?string $productKey, string $offerId): ProductContext
    {
        $base = $this->resolve($tenantId, $productKey);
        $found = $this->subProduct($tenantId, $base->key, $offerId);
        if ($found['offer'] === null || $found['problems'] !== []) {
            $name = $found['offer']?->name ?? $offerId;
            throw new ProductNotConfigured($name, array_map(fn ($p) => ucfirst($p), $found['problems']));
        }
        $offer = $found['offer'];
        $def = self::definitionsFor($tenantId)[$base->key];
        $pack = $base->pack->withSubProduct((array) $found['config'], (string) $offer->offer_id, (string) $offer->name);
        $parts = $this->parts($def, $pack->config(), collect([$offer]), collect([$offer]));

        return new ProductContext(
            $base->key, $base->label, $base->businessFit, $pack, $this->profile($tenantId, $def, $pack, $parts), $parts, $base->icpSource,
            $this->childProfiles(collect([$offer])), ['offer_id' => (string) $offer->offer_id, 'name' => (string) $offer->name],
        );
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
     * The child products of a product line: every portfolio product in its category (including ones a person added), plus any other product the
     * line's ICP lists. Research and matching read all of them. Nothing here is specific to a kind of product.
     *
     * @return \Illuminate\Support\Collection<int, ProductOffer>
     */
    public function childOffers(int $tenantId, string $key)
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def);
        $chosen = array_values(array_filter((array) (($icp['config'] ?? [])['offer_ids'] ?? []), 'is_string'));
        if ($chosen !== []) {
            $offers = $offers->concat(ProductOffer::query()->forTenant($tenantId)->whereIn('offer_id', $chosen)->get());
        }

        return $offers->unique('offer_id')->values();
    }

    /**
     * The child products research SEARCHES for and a signal can be built on: the offers the product's ICP uses (all of the product's own, or exactly
     * those the ICP lists). The research prompt is still shown every child (childOffers); the searches follow the ICP's own choice.
     *
     * @return \Illuminate\Support\Collection<int, ProductOffer>
     */
    public function researchOffers(int $tenantId, string $key)
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }

        return $this->effectiveOffers($tenantId, $this->icp($tenantId, $def)['config'], $this->offers($tenantId, $def))->values();
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
        if (($c['customer_model'] ?? null) === 'b2c') {
            $out[] = "The {$label} ICP is set to B2C: daily signals find organisations with buying events, so B2C is not supported yet. Choose B2B or Both";
        }
        if (empty($c['parts']) && $offers->isEmpty()) {
            $out[] = "No {$label} offers in the Product Portfolio and no parts in its ICP: nothing a signal can be matched to";
        }

        return $out;
    }

    /** @return array<string, array{offer_id: string, name: string, parent_product: string, what_it_does: string}> */
    private function parts(array $def, array $config, $offers, $children = null): array
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
        // Every child product the research prompt is shown can also be named as the part a signal fits. Without this a signal could only ever be
        // built on the ICP's fixed parts, so a child product added later could never be the product a signal is for. A part of the ICP keeps its key.
        foreach ($children ?? [] as $o) {
            $id = strtolower((string) $o->offer_id);
            if ($id !== '' && ! isset($out[$id])) {
                $out[$id] = ['offer_id' => $id, 'name' => (string) $o->name, 'parent_product' => (string) $o->parent_product, 'what_it_does' => (string) ($o->what_it_does ?: $o->name), 'child_product' => true];
            }
        }

        return $out;
    }

    /**
     * What the research prompt is shown about each child product: everything stored about it, clipped, so the model reads the whole range we sell.
     *
     * @param  \Illuminate\Support\Collection<int, ProductOffer>  $offers
     * @return list<array<string, mixed>>
     */
    private function childProfiles($offers): array
    {
        $clip = fn ($v) => $v === null || $v === '' ? null : mb_substr(trim((string) $v), 0, 400);
        $labels = fn (array $list, callable $label) => array_values(array_map(fn ($x) => mb_substr((string) $label((string) $x), 0, 160), array_slice(array_values(array_filter($list, 'is_string')), 0, 12)));

        return $offers->take(12)->map(fn ($o) => array_filter([
            'id' => $o->offer_id, 'name' => $o->name, 'category' => $o->parent_product,
            'what_it_does' => $clip($o->what_it_does), 'features' => $clip($o->modules_components), 'proof' => $clip($o->proof_points),
            'problems_it_solves' => $labels((array) $o->needs_solved, fn ($c) => \App\Domain\Portfolio\TaxonomyService::getNeedLabel($c)),
            'sold_to' => $labels((array) $o->primary_segments, fn ($c) => \App\Domain\Portfolio\TaxonomyService::getSegmentLabel($c)),
            'responds_to_events' => $labels((array) $o->trigger_signals, fn ($c) => \App\Domain\Portfolio\TaxonomyService::getSignalLabel($c)),
        ], fn ($v) => $v !== null && $v !== []))->values()->all();
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
