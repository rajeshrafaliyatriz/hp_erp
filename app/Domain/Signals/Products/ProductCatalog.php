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

    public static function isBuiltIn(string $key): bool
    {
        return array_key_exists($key, self::definitions());
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
                foreach (CustomProduct::where('sub_institute_id', $tenantId)->orderBy('id')->get() as $r) {
                    $portfolioData = (array) $r->portfolio_names;
                    $meta = is_array($portfolioData['__meta__'] ?? null) ? $portfolioData['__meta__'] : [];
                    $names = array_values(array_filter($portfolioData, 'is_string'));
                    if (empty($names)) {
                        $names = [(string) $r->label];
                    }
                    $out[(string) $r->key] = [
                        'label' => (string) $r->label,
                        'business_fit' => (string) $r->business_fit,
                        'portfolio_names' => $names,
                        'icp_product_line' => (string) $r->icp_product_line,
                        'icp_template' => null,
                        'custom' => true,
                        'status' => (string) ($r->status ?: 'active'),
                        'description' => $meta['description'] ?? null,
                        'category' => $meta['category'] ?? null,
                        'logo' => $meta['logo'] ?? null,
                    ];
                }
            }
        } catch (\Throwable) {
            $out = [];
        }

        return self::$custom[$tenantId] = $out;
    }

    /**
     * The products one organisation can use: the built-in ones, then its own. A tenant can customize or delete
     * any product (including built-ins). Deleted products are excluded; overrides take precedence.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitionsFor(?int $tenantId): array
    {
        $builtIn = self::definitions();
        if ($tenantId === null || $tenantId <= 0) {
            return $builtIn;
        }

        $custom = self::customFor($tenantId);
        $merged = [];

        foreach ($builtIn as $key => $def) {
            if (isset($custom[$key])) {
                if (($custom[$key]['status'] ?? 'active') === 'deleted') {
                    continue;
                }
                $merged[$key] = array_merge($def, $custom[$key]);
            } else {
                $merged[$key] = $def;
            }
        }

        foreach ($custom as $key => $def) {
            if (! isset($builtIn[$key])) {
                if (($def['status'] ?? 'active') === 'deleted') {
                    continue;
                }
                $merged[$key] = $def;
            }
        }

        return $merged;
    }

    /**
     * Creates one top-level product for the organisation. It gets its own ICP product line, so its ICP is saved and read separately from every
     * other product. Throws CustomProductInvalid (naming every problem) when the key or name cannot be used.
     *
     * @return array<string, mixed> the product as described
     */
    public function createCustom(int $tenantId, ?int $userId, string $key, string $label, array $meta = []): array
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
        $status = in_array($meta['status'] ?? 'active', ['active', 'inactive'], true) ? $meta['status'] : 'active';
        $portfolioData = [
            $label,
            '__meta__' => [
                'description' => isset($meta['description']) ? trim((string) $meta['description']) : null,
                'category' => isset($meta['category']) ? trim((string) $meta['category']) : null,
                'logo' => isset($meta['logo']) ? trim((string) $meta['logo']) : null,
            ],
        ];
        try {
            CustomProduct::create([
                'sub_institute_id' => $tenantId, 'key' => $key, 'label' => $label, 'business_fit' => $key, 'icp_product_line' => $key,
                'portfolio_names' => $portfolioData, 'status' => $status, 'created_by' => $userId,
            ]);
        } catch (\Illuminate\Database\QueryException) {
            self::forgetCustom();
            throw new CustomProductInvalid(['key: this product already exists']);
        }
        self::forgetCustom();

        return $this->describe($tenantId, $key);
    }

    /**
     * Updates an existing product (custom or built-in override).
     */
    public function updateProduct(int $tenantId, ?int $userId, string $key, array $data): array
    {
        $canonicalKey = self::normalise($key, $tenantId);
        if ($canonicalKey === null) {
            throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');
        }
        $allDefs = self::definitionsFor($tenantId);
        $def = $allDefs[$canonicalKey] ?? throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');

        $label = isset($data['label']) ? trim((string) preg_replace('/\s+/u', ' ', $data['label'])) : $def['label'];
        if ($label === '') {
            throw new CustomProductInvalid(['label: product name cannot be empty']);
        }

        foreach ($allDefs as $k => $d) {
            if ($k !== $canonicalKey && strtolower($d['label']) === strtolower($label)) {
                throw new CustomProductInvalid(['label: another product already uses this name']);
            }
        }

        $existing = CustomProduct::where('sub_institute_id', $tenantId)->where('key', $canonicalKey)->first();
        $status = in_array($data['status'] ?? ($existing?->status ?? $def['status'] ?? 'active'), ['active', 'inactive'], true)
            ? ($data['status'] ?? ($existing?->status ?? $def['status'] ?? 'active'))
            : 'active';

        $portfolioData = $existing ? (array) $existing->portfolio_names : [];
        $existingMeta = is_array($portfolioData['__meta__'] ?? null) ? $portfolioData['__meta__'] : [];

        $newMeta = [
            'description' => array_key_exists('description', $data) ? ($data['description'] !== null ? trim((string) $data['description']) : null) : ($existingMeta['description'] ?? ($def['description'] ?? null)),
            'category' => array_key_exists('category', $data) ? ($data['category'] !== null ? trim((string) $data['category']) : null) : ($existingMeta['category'] ?? ($def['category'] ?? null)),
            'logo' => array_key_exists('logo', $data) ? ($data['logo'] !== null ? trim((string) $data['logo']) : null) : ($existingMeta['logo'] ?? ($def['logo'] ?? null)),
        ];

        $names = array_values(array_unique(array_filter(array_merge(
            (array) ($def['portfolio_names'] ?? []),
            [$label]
        ), 'is_string')));

        $updatedPortfolio = array_merge($names, ['__meta__' => $newMeta]);

        if ($existing) {
            $existing->update([
                'label' => $label,
                'status' => $status,
                'portfolio_names' => $updatedPortfolio,
            ]);
        } else {
            CustomProduct::create([
                'sub_institute_id' => $tenantId,
                'key' => $canonicalKey,
                'label' => $label,
                'business_fit' => $def['business_fit'],
                'icp_product_line' => $def['icp_product_line'],
                'portfolio_names' => $updatedPortfolio,
                'status' => $status,
                'created_by' => $userId,
            ]);
        }

        self::forgetCustom();
        if (class_exists('App\Domain\Signals\Products\ProductMasterSync')) {
            \App\Domain\Signals\Products\ProductMasterSync::throughCustomProduct($tenantId, $canonicalKey, 'saved', $userId);
        }

        return $this->describe($tenantId, $canonicalKey);
    }

    /**
     * Inspects product dependencies for safe deletion checks.
     */
    public function productDependencies(int $tenantId, string $key): array
    {
        $canonicalKey = self::normalise($key, $tenantId);
        if ($canonicalKey === null) {
            throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');
        }
        $def = self::definitionsFor($tenantId)[$canonicalKey] ?? throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');

        $offersCount = 0;
        if (Schema::hasTable('g2g_product_offers')) {
            $names = (array) ($def['portfolio_names'] ?? []);
            $offersCount = ProductOffer::query()->forTenant($tenantId)->whereIn('parent_product', $names)->count();
        }

        $icpCount = 0;
        if (Schema::hasTable('g2g_icp_profiles')) {
            $icpCount = IcpProfile::where('sub_institute_id', $tenantId)
                ->where(function ($q) use ($def, $canonicalKey) {
                    $q->where('product_line', $def['icp_product_line'])
                      ->orWhere('product_line', $canonicalKey);
                })->count();
        }

        $oppsCount = 0;
        if (Schema::hasTable('g2g_company_opportunities')) {
            $oppsCount = \Illuminate\Support\Facades\DB::table('g2g_company_opportunities')
                ->where('sub_institute_id', $tenantId)
                ->where('business_fit', $def['business_fit'])
                ->count();
        }

        $runsCount = 0;
        if (Schema::hasTable('g2g_research_runs') && \App\Support\ColumnExists::has('g2g_research_runs', 'product_key')) {
            $runsCount = \Illuminate\Support\Facades\DB::table('g2g_research_runs')
                ->where('sub_institute_id', $tenantId)
                ->where('product_key', $canonicalKey)
                ->count();
        }

        $accountsCount = 0;
        if (Schema::hasTable('g2g_target_accounts')) {
            $accountsCount = \Illuminate\Support\Facades\DB::table('g2g_target_accounts')
                ->where('sub_institute_id', $tenantId)
                ->where(function ($q) use ($def, $canonicalKey) {
                    $q->where('icp_product_line', $def['icp_product_line'])
                      ->orWhere('icp_product_line', $canonicalKey);
                })->count();
        }

        return [
            'key' => $canonicalKey,
            'label' => $def['label'],
            'sub_products_count' => $offersCount,
            'icp_profiles_count' => $icpCount,
            'opportunities_count' => $oppsCount,
            'research_runs_count' => $runsCount,
            'target_accounts_count' => $accountsCount,
            'can_delete' => true,
        ];
    }

    /**
     * Safely deletes or archives a product, preserving historical signals and opportunities.
     */
    public function deleteProduct(int $tenantId, ?int $userId, string $key): array
    {
        $canonicalKey = self::normalise($key, $tenantId);
        if ($canonicalKey === null) {
            throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');
        }
        $def = self::definitionsFor($tenantId)[$canonicalKey] ?? throw new ProductNotConfigured($key, ['unknown product'], 'unknown_product');

        $icpDeleted = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $canonicalKey, $def, $userId, &$icpDeleted) {
            $ids = [];
            if (Schema::hasTable('g2g_icp_profiles')) {
                $ids = IcpProfile::where('sub_institute_id', $tenantId)
                    ->where(function ($q) use ($def, $canonicalKey) {
                        $q->where('product_line', $def['icp_product_line'])
                          ->orWhere('product_line', $canonicalKey);
                    })->pluck('id')->all();
                $icpDeleted = count($ids);
            }

            if ($ids !== []) {
                foreach (['g2g_target_accounts', 'g2g_research_runs'] as $table) {
                    if (Schema::hasTable($table) && \App\Support\ColumnExists::has($table, 'icp_profile_id')) {
                        \Illuminate\Support\Facades\DB::table($table)
                            ->where('sub_institute_id', $tenantId)
                            ->whereIn('icp_profile_id', $ids)
                            ->update(['icp_profile_id' => null]);
                    }
                }
                IcpProfile::whereIn('id', $ids)->delete();
                if (Schema::hasTable('g2g_icp_profile_versions')) {
                    \Illuminate\Support\Facades\DB::table('g2g_icp_profile_versions')->whereIn('icp_profile_id', $ids)->delete();
                }
            }

            if (Schema::hasTable('g2g_product_offers')) {
                $names = (array) ($def['portfolio_names'] ?? []);
                ProductOffer::query()->forTenant($tenantId)
                    ->whereIn('parent_product', $names)
                    ->update(['readiness_status' => 'Archived']);
            }

            $existing = CustomProduct::where('sub_institute_id', $tenantId)->where('key', $canonicalKey)->first();
            if ($existing) {
                $existing->delete();
            } else {
                CustomProduct::create([
                    'sub_institute_id' => $tenantId,
                    'key' => $canonicalKey,
                    'label' => $def['label'],
                    'business_fit' => $def['business_fit'],
                    'icp_product_line' => $def['icp_product_line'],
                    'portfolio_names' => (array) ($def['portfolio_names'] ?? [$def['label']]),
                    'status' => 'deleted',
                    'created_by' => $userId,
                ]);
            }
        });

        self::forgetCustom();
        if (class_exists('App\Domain\Signals\Products\ProductMasterSync')) {
            \App\Domain\Signals\Products\ProductMasterSync::throughCustomProduct($tenantId, $canonicalKey, 'saved', $userId);
        }

        return ['removed' => $canonicalKey, 'icp_deleted' => $icpDeleted];
    }

    /**
     * The portfolio groups (the `parent_product` an offer is saved under) of the products one organisation can use, as group => product label.
     * This is what the Add a Sub-Product "Category" list offers: exactly the catalog's products, so a new product appears there with no code change.
     *
     * @return array<string, string>
     */
    public static function portfolioGroups(?int $tenantId): array
    {
        $out = [];
        foreach (self::definitionsFor($tenantId) as $d) {
            foreach ((array) ($d['portfolio_names'] ?? []) as $name) {
                if (is_string($name) && $name !== '' && ! isset($out[$name])) {
                    $out[$name] = (string) $d['label'];
                }
            }
        }

        return $out;
    }

    /**
     * The portfolio group a value stands for: the group itself, or any spelling of a product (its key, label or business fit) as that product's
     * group. Null when it is neither, so a made-up category is never saved.
     */
    public static function portfolioGroupFor(?int $tenantId, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (array_key_exists($value, self::portfolioGroups($tenantId))) {
            return $value;
        }
        $key = self::normalise($value, $tenantId);

        return $key === null ? null : (self::definitionsFor($tenantId)[$key]['portfolio_names'][0] ?? null);
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
        if ($line === null || $line === '') {
            return false;
        }
        foreach (self::definitionsFor($tenantId) as $key => $d) {
            if ($d['icp_product_line'] === $line || $key === $line || self::normaliseExact($line, $tenantId) !== null) {
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
    public function icpFor(int $tenantId, string $key, string $customerModel = 'b2b'): ?array
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;

        return $def === null ? null : ['def' => $def, 'icp' => $this->icp($tenantId, $def, $customerModel)];
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
    public function researchContext(int $tenantId, string $key, ?\App\Domain\Signals\Support\RegionScope $scope = null, ?string $offerId = null, string $customerModel = 'b2b'): array
    {
        $described = $this->describe($tenantId, $key);
        $found = $this->icpFor($tenantId, $key, $customerModel);
        $sub = $offerId !== null ? $this->subProduct($tenantId, $key, $offerId, $customerModel) : null;
        $subOffer = $sub['offer'] ?? null;
        if ($subOffer !== null && $sub['config'] !== null && $found['icp']['config'] !== null) {
            // The notice describes what a run for THIS sub-product uses: its own ICP over the product's rules, and only its own offer.
            $found['icp']['config'] = (new IcpPack($key, (array) $found['icp']['config']))->withSubProduct($sub['config'], (string) $subOffer->offer_id, (string) $subOffer->name)->config();
            $described['label'] = $described['label'] . ' â†’ ' . $subOffer->name;
        }
        $offers = ($subOffer !== null ? collect([$subOffer]) : $this->childOffers($tenantId, $key, $customerModel))->map(fn ($o) => [
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
        // What each sub-product's own ICP still needs for a run about it alone.
        $subProblems = array_map(fn ($c) => SubProductIcp::problems($c), SubProductIcp::configsFor($tenantId, $offers));
        $config = $icp['config'];

        $productIcpModels = [];
        foreach (['b2b', 'b2c', 'b2g'] as $m) {
            $mIcp = $this->icp($tenantId, $def, $m);
            $productIcpModels[$m] = [
                'ready' => true,
                'missing' => [],
                'configured' => $mIcp['config'] !== null,
                'source' => $mIcp['config'] !== null ? $mIcp['source'] : 'universal',
                'without_icp' => [],
            ];
        }

        return [
            'key' => $key,
            'label' => $def['label'],
            'status' => $def['status'] ?? 'active',
            'description' => $def['description'] ?? null,
            'category' => $def['category'] ?? null,
            'logo' => $def['logo'] ?? null,
            'business_fit' => $def['business_fit'],
            'custom' => ! empty($def['custom']),
            'generation_note' => self::generationBlock($def),
            'portfolio_name' => $offers->pluck('parent_product')->filter()->unique()->first() ?? ($def['portfolio_names'][0] ?? null),
            'icp_models' => $productIcpModels,
            'sub_products_without_icp' => $this->withoutIcpList($icp),
            'offers' => $offers->map(function ($o) use ($tenantId, $subProblems) {
                $allSubConfigs = SubProductIcp::allConfigsFor($tenantId, $o);
                $offerIcpModels = [];
                foreach (['b2b', 'b2c', 'b2g'] as $m) {
                    $offerIcpModels[$m] = [
                        'ready' => true,
                        'missing' => [],
                        'configured' => $allSubConfigs[$m] !== null && $allSubConfigs[$m] !== [],
                    ];
                }
                return [
                    'icp_ready' => true,
                    'icp_missing' => [],
                    'icp_models' => $offerIcpModels,
                    'id' => $o->id, 'offer_id' => $o->offer_id, 'name' => $o->name, 'what_it_does' => $o->what_it_does,
                    'readiness_status' => $o->readiness_status, 'needs_solved' => $o->needs_solved, 'primary_segments' => $o->primary_segments,
                    'trigger_signals' => $o->trigger_signals,
                    'pricing_model' => $o->pricing_model ?: null, 'typical_deal_band' => $o->typical_deal_band ?: null,
                ];
            })->values()->all(),
            'icp' => $config === null ? null : [
                'profile_id' => $icp['profile_id'], 'source' => $icp['source'], 'name' => $config['label'] ?? null,
                'customer_model' => $config['customer_model'] ?? null,
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
            'ready' => true,
            'state' => 'ready',
            'next_step' => null,
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
        if (array_key_exists('sub_missing', $icp)) {
            return "Open Product Portfolio, open {$def['label']}, and give each sub-product listed an Individual ICP (who buys it, its buying triggers and its searches).";
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
    /**
     * Synthesizes an effective, valid universal research context and ICP pack when
     * an individual ICP is not configured for the product, sub-product, or customer model.
     *
     * @return array<string, mixed>
     */
    public function synthesizeConfig(array $def, $offers, string $customerModel = 'b2b', ?ProductOffer $subOffer = null): array
    {
        $label = $subOffer ? $subOffer->name : $def['label'];

        $industryLabel = match ($customerModel) {
            'b2c' => "partners, channel partners, distributors, systems integrators, resellers, agencies, and franchisees for {$label}",
            'b2g' => "government departments, municipal corporations, public authorities, state education boards, and ministries seeking {$label}",
            default => "enterprises, institutions, universities, colleges, schools, and commercial organizations seeking {$label}",
        };

        $buyerTypes = match ($customerModel) {
            'b2c' => ['Partner', 'Reseller', 'Distributor', 'Systems Integrator', 'Channel Partner', 'Agency', 'Franchise Partner', 'Delivery Partner'],
            'b2g' => ['Government Body', 'Public Authority', 'State Department', 'Municipal Corporation', 'Education Board', 'Public Directorate', 'Tender Committee'],
            default => ['Enterprise', 'Institution', 'School', 'College', 'University', 'Corporate', 'Business', 'Organization'],
        };

        $parts = [];
        $triggers = [];
        $queries = [];
        $sourceOffers = $subOffer ? collect([$subOffer]) : $offers;

        $i = 1;
        foreach ($sourceOffers as $o) {
            $partKey = strtolower((string) ($o->offer_id ?: 'part_' . $i));
            $partDesc = (string) ($o->what_it_does ?: $o->name);
            $parts[$partKey] = $partDesc;

            $trigList = is_array($o->trigger_signals) ? $o->trigger_signals : (is_string($o->trigger_signals) ? explode(',', $o->trigger_signals) : []);
            if ($trigList === []) {
                $trigList = ['expansion', 'procurement', 'digital_transformation', 'partnership'];
            }
            foreach ($trigList as $t) {
                $tClean = trim((string) $t);
                if ($tClean !== '') {
                    $tKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $tClean));
                    $triggers[$tKey] = [
                        'source' => $customerModel === 'b2g' ? 'tender' : ($customerModel === 'b2c' ? 'news' : 'job'),
                        'pain' => "Seeking {$o->name} solutions or capabilities to solve " . (is_array($o->needs_solved) ? implode(', ', $o->needs_solved) : ($o->needs_solved ?: 'operational bottlenecks')),
                        'part' => $partKey,
                    ];
                }
            }

            $nameStr = (string) $o->name;
            if ($customerModel === 'b2c') {
                $queries[] = ['id' => "q_{$partKey}_partner", 'source' => 'news', 'topic' => 'Partnership', 'days' => 30, 'q' => '"' . $nameStr . '" partner OR distributor OR reseller OR "channel partner"'];
                $queries[] = ['id' => "q_{$partKey}_integrator", 'source' => 'news', 'topic' => 'System Integrator', 'days' => 30, 'q' => '"' . $label . '" "systems integrator" OR franchise OR agency partnership'];
            } elseif ($customerModel === 'b2g') {
                $queries[] = ['id' => "q_{$partKey}_tender", 'source' => 'tender', 'topic' => 'Tender RFP', 'days' => 45, 'q' => 'tender RFP procurement "' . $nameStr . '" OR "' . $label . '"'];
                $queries[] = ['id' => "q_{$partKey}_gov", 'source' => 'news', 'topic' => 'Government Project', 'days' => 30, 'q' => '"government" "education" OR "department" "' . $nameStr . '" project'];
            } else {
                $queries[] = ['id' => "q_{$partKey}_exp", 'source' => 'news', 'topic' => 'Expansion', 'days' => 30, 'q' => '"' . $nameStr . '" expansion OR adoption OR implementation'];
                $queries[] = ['id' => "q_{$partKey}_hire", 'source' => 'job', 'topic' => 'Hiring', 'days' => 30, 'q' => 'hiring "' . $nameStr . '" OR "' . $label . '" director coordinator manager'];
            }
            $i++;
        }

        if ($triggers === []) {
            $triggers['general_need'] = [
                'source' => $customerModel === 'b2g' ? 'tender' : 'news',
                'pain' => "Explicit requirement, tender, RFP, expansion or technology upgrade initiative for {$label}",
            ];
        }

        if ($queries === []) {
            $queries[] = [
                'id' => 'q_universal_1',
                'source' => $customerModel === 'b2g' ? 'tender' : 'news',
                'topic' => 'Initiative',
                'days' => 30,
                'q' => $customerModel === 'b2g' ? 'tender RFP "' . $label . '" procurement' : ($customerModel === 'b2c' ? '"' . $label . '" partner distributor reseller' : '"' . $label . '" expansion implementation'),
            ];
        }

        if ($parts === []) {
            $pKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $label));
            $parts[$pKey] = "Comprehensive {$label} platform, solutions and services";
        }

        $config = [
            'label' => $label,
            'customer_model' => $customerModel,
            'industry_label' => $industryLabel,
            'pitch' => $subOffer ? (string) ($subOffer->what_it_does ?: "Specialized {$label} solutions.") : "Comprehensive {$label} platform and solutions.",
            'buyer_types' => $buyerTypes,
            'regions' => ['India', 'Global'],
            'buying_window_months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
            'per_run' => ['catalogue' => 5],
            'rotation_runs_per_day' => 1,
            'parts' => $parts,
            'triggers' => $triggers,
            'queries' => $queries,
            'buying_centre' => [
                'Decision Maker' => 'Lead with strategic alignment, ROI, compliance, and institutional speed.',
                'Procurement / Partner Head' => 'Lead with partner margins, SLA, accreditation, and seamless delivery.',
                'Technical Director' => 'Lead with robust architecture, data security, and seamless integration.',
            ],
            'disqualifiers' => [
                ['label' => 'Individual retail consumer with no business or organizational entity'],
                ['label' => 'Personal student seeking personal tutoring or individual study'],
            ],
            'vendor_phrases' => [],
            'vendor_news_phrases' => [],
            'competitors' => [],
        ];

        if ($subOffer !== null) {
            $config['sub_product'] = [
                'offer_id' => (string) $subOffer->offer_id,
                'name' => (string) $subOffer->name,
            ];
        }

        return $config;
    }

    public function resolve(int $tenantId, ?string $productKey, string $customerModel = 'b2b'): ProductContext
    {
        $key = self::normalise($productKey, $tenantId);
        if ($key === null) {
            throw new ProductNotConfigured((string) $productKey, ['not a known product'], 'unknown_product');
        }
        $def = self::definitionsFor($tenantId)[$key];
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def, $customerModel);
        $effectiveOffers = $this->effectiveOffers($tenantId, $icp['config'], $offers);
        if ($effectiveOffers->isEmpty()) {
            $effectiveOffers = $offers;
        }
        $missing = $this->missing($def, $icp, $effectiveOffers);

        // Refused here, before any search, AI call or file is stored, so a switched-off product never spends provider quota.
        if (($note = self::generationBlock($def)) !== null) {
            throw new ProductNotConfigured($def['label'], [$note]);
        }

        if ($missing !== [] || $icp['config'] === null) {
            $config = $this->synthesizeConfig($def, $effectiveOffers, $customerModel);
            $pack = new IcpPack((string) ($def['icp_product_line'] ?? $key), $config, null, (string) ($config['label'] ?? $def['label']));
            $parts = $this->parts($def, $config, $effectiveOffers, $effectiveOffers);
            $source = 'universal';
        } else {
            $pack = new IcpPack($icp['pack_key'], $icp['config'], $icp['profile_id'], (string) ($icp['config']['label'] ?? $def['label']));
            $parts = $this->parts($def, $icp['config'], $effectiveOffers, $effectiveOffers);
            $source = (string) $icp['source'];
        }

        return new ProductContext(
            $key, $def['label'], $def['business_fit'], $pack, $this->profile($tenantId, $def, $pack, $parts), $parts, $source,
            $this->childProfiles($this->childOffers($tenantId, $key, $customerModel)), null, [],
        );
    }

    /**
     * The sub-products of a product that have no usable ICP for one customer model, each with what it lacks. Empty for a product whose ICP is the
     * legacy stored one.
     *
     * @param  array<string, mixed>  $icp  the result of icp()
     * @return list<array{offer_id: string, name: string, problems: list<string>}>
     */
    private function withoutIcpList(array $icp): array
    {
        return array_values(array_map(
            fn ($id, $m) => ['offer_id' => (string) $id, 'name' => (string) $m['name'], 'problems' => array_values($m['problems'])],
            array_keys((array) ($icp['sub_missing'] ?? [])),
            array_values((array) ($icp['sub_missing'] ?? []))
        ));
    }

    /**
     * A sub-product of this product (one of its offers) and what a run for it still needs. The offer must be one of THIS product's offers: a
     * sub-product of another product is never accepted here.
     *
     * @return array{offer: ProductOffer|null, config: array<string, mixed>|null, problems: list<string>}
     */
    public function subProduct(int $tenantId, string $productKey, string $offerId, string $customerModel = 'b2b'): array
    {
        $def = self::definitionsFor($tenantId)[$productKey] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return ['offer' => null, 'config' => null, 'problems' => ['unknown product'], 'customer_model' => $customerModel];
        }
        // A sub-product is identified by the product portfolio itself. Parent ICP offer_ids
        // describe the parent run and must not hide a real child from this selector/run.
        $offers = $this->offers($tenantId, $def);
        $offer = $offers->first(fn ($o) => strcasecmp((string) $o->offer_id, $offerId) === 0);
        if ($offer === null) {
            return ['offer' => null, 'config' => null, 'problems' => ["'{$offerId}' is not one of {$def['label']}'s products"], 'customer_model' => $customerModel];
        }
        $config = SubProductIcp::configFor($tenantId, $offer, $customerModel);

        return ['offer' => $offer, 'config' => $config, 'problems' => [], 'customer_model' => $customerModel];
    }

    /**
     * The context for a run about ONE sub-product: the product's own qualification rules and accounts, with the sub-product's own ICP (who buys it,
     * its triggers and searches) and only that offer as what a signal can be about. Synthesizes a universal ICP if not configured.
     */
    public function resolveSub(int $tenantId, ?string $productKey, string $offerId, string $customerModel = 'b2b'): ProductContext
    {
        $key = self::normalise($productKey, $tenantId);
        if ($key === null) {
            throw new ProductNotConfigured((string) $productKey, ['not a known product'], 'unknown_product');
        }
        $def = self::definitionsFor($tenantId)[$key];
        if (($note = self::generationBlock($def)) !== null) {
            throw new ProductNotConfigured($def['label'], [$note]);
        }
        $found = $this->subProduct($tenantId, $key, $offerId, $customerModel);
        if ($found['offer'] === null) {
            $name = $offerId;
            throw new ProductNotConfigured($name, ["'{$offerId}' is not one of {$def['label']}'s products"]);
        }
        $offer = $found['offer'];

        $subConfig = $found['config'];
        if ($subConfig === null || SubProductIcp::problems($subConfig, $customerModel) !== []) {
            $subConfig = $this->synthesizeConfig($def, collect([$offer]), $customerModel, $offer);
        }
        $basePack = new IcpPack($def['icp_product_line'], $subConfig, null, (string) ($subConfig['label'] ?? $offer->name));
        $pack = $basePack->withSubProduct($subConfig, (string) $offer->offer_id, (string) $offer->name);
        $parts = $this->parts($def, $pack->config(), collect([$offer]), collect([$offer]));

        return new ProductContext(
            $key, $def['label'], $def['business_fit'], $pack, $this->profile($tenantId, $def, $pack, $parts), $parts, 'sub_product',
            $this->childProfiles(collect([$offer])), ['offer_id' => (string) $offer->offer_id, 'name' => (string) $offer->name],
        );
    }

    // â”€â”€ what the product is made of â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /** A saved ICP that is not active yet (so the screen can say "activate it" instead of "create one"). @return array{id: int, name: string}|null */
    private function draft(int $tenantId, array $def): ?array
    {
        if (! (bool) config('signals.legacy_product_icp', false) || ! Schema::hasTable('g2g_icp_profiles')) {
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
    public function childOffers(int $tenantId, string $key, string $customerModel = 'b2b')
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }
        $offers = $this->offers($tenantId, $def);
        $icp = $this->icp($tenantId, $def, $customerModel);   // the offers the SELECTED customer model's ICP uses, never the B2B one's
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
    public function researchOffers(int $tenantId, string $key, string $customerModel = 'b2b')
    {
        $def = self::definitionsFor($tenantId)[$key] ?? null;
        if ($def === null || ! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }

        return $this->effectiveOffers($tenantId, $this->icp($tenantId, $def, $customerModel)['config'], $this->offers($tenantId, $def))->values();
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
     * The ICP a whole-product run works from. A product has no ICP of its own: it is the combination of the ICPs of its sub-products (what "all
     * sub-products" means), so it is derived here from them. The ICP stored against the product itself (an older, now unused configuration) is read
     * only while `signals.legacy_product_icp` is switched on.
     *
     * @return array{config: ?array<string, mixed>, source: ?string, profile_id: ?int, pack_key: string, errors: list<string>, sub_missing?: array<string, array{name: string, problems: list<string>}>}
     */
    private function icp(int $tenantId, array $def, string $customerModel = 'b2b'): array
    {
        return (bool) config('signals.legacy_product_icp', false) ? $this->storedIcp($tenantId, $def, $customerModel) : $this->derivedIcp($tenantId, $def, $customerModel);
    }

    /**
     * The product's ICP as the combination of its sub-products' ICPs for one customer model. Sub-products without a usable ICP are listed in
     * `sub_missing` with what each lacks (the callers report them); the others are combined. No ICP is copied from one sub-product to another.
     *
     * @return array{config: ?array<string, mixed>, source: ?string, profile_id: ?int, pack_key: string, errors: list<string>, sub_missing: array<string, array{name: string, problems: list<string>}>}
     */
    private function derivedIcp(int $tenantId, array $def, string $customerModel = 'b2b'): array
    {
        $offers = $this->offers($tenantId, $def);
        $configs = SubProductIcp::configsFor($tenantId, $offers, $customerModel);
        $ready = [];
        $missing = [];
        foreach ($offers as $o) {
            $id = (string) $o->offer_id;
            $problems = SubProductIcp::problems($configs[$id] ?? null, $customerModel);
            if ($problems === []) {
                $ready[] = ['offer_id' => $id, 'name' => (string) $o->name, 'config' => (array) $configs[$id]];
            } else {
                $missing[$id] = ['name' => (string) $o->name, 'problems' => $problems];
            }
        }

        return [
            'config' => $ready === [] ? null : SubProductIcp::combine($def, $customerModel, $ready),
            'source' => $ready === [] ? null : 'sub_products',
            'profile_id' => null,
            'pack_key' => (string) ($def['icp_template'] ?? $def['icp_product_line']),
            'errors' => [],
            'sub_missing' => $missing,
        ];
    }

    /**
     * The ICP stored against the product line itself: the organisation's active profile for it; for a product with a built-in definition,
     * that definition fills any setting the stored profile does not set. Legacy: read only while `signals.legacy_product_icp` is on.
     *
     * @return array{config: ?array<string, mixed>, source: ?string, profile_id: ?int, pack_key: string, errors: list<string>}
     */
    private function storedIcp(int $tenantId, array $def, string $customerModel = 'b2b'): array
    {
        $template = $def['icp_template'] ? config("signals_icp.{$def['icp_template']}") : null;
        $template = is_array($template) ? $template : null;
        $templateMatches = $template !== null && ($customerModel === 'b2b' || ($template['customer_model'] ?? 'b2b') === $customerModel);
        $activeTemplate = $templateMatches ? $template : null;

        $row = null;
        if (Schema::hasTable('g2g_icp_profiles')) {
            $base = IcpProfile::where('sub_institute_id', $tenantId)
                ->where(function ($q) use ($def, $customerModel) {
                    $q->where('product_line', $def['icp_product_line'])
                      ->orWhere('product_line', $def['icp_product_line'] . '_' . $customerModel);
                });

            $profiles = (clone $base)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get();
            foreach ($profiles as $p) {
                $cfg = (array) $p->config;
                $m = $cfg['customer_model'] ?? 'b2b';
                if ($m === $customerModel || str_ends_with((string) $p->key, '_' . $customerModel)) {
                    $row = $p;
                    break;
                }
            }
            if ($row === null && $customerModel === 'b2b') {
                foreach ($profiles as $p) {
                    $cfg = (array) $p->config;
                    $m = $cfg['customer_model'] ?? 'b2b';
                    if (! in_array($m, ['b2c', 'b2g'], true)) {
                        $row = $p;
                        break;
                    }
                }
            }
        }

        if ($row === null) {
            return ['config' => $activeTemplate, 'source' => $activeTemplate ? 'template' : null, 'profile_id' => null,
                'pack_key' => (string) ($def['icp_template'] ?? $def['icp_product_line']), 'errors' => []];
        }

        if ($activeTemplate !== null && self::isSeedCopy($row)) {
            return ['config' => $activeTemplate, 'source' => 'template', 'profile_id' => (int) $row->id, 'pack_key' => (string) $row->key, 'errors' => []];
        }

        $stored = (array) $row->config;
        // The same tolerance the editor applies when it saves: a per-run search count the built-in definition itself uses is not a problem.
        $errors = ProductIcpService::withoutBuiltInPerRunProblems($stored, $template, (new IcpProfileValidator())->validate($stored));
        $config = $activeTemplate ? array_replace($activeTemplate, $stored) : $stored;

        return ['config' => $config, 'source' => 'database', 'profile_id' => (int) $row->id, 'pack_key' => (string) $row->key, 'errors' => $errors];
    }

    /** @return list<string> */
    private function missing(array $def, array $icp, $offers): array
    {
        $label = $def['label'];
        if ($icp['config'] === null && array_key_exists('sub_missing', $icp)) {
            // Derived from the sub-products: say which has no usable ICP, and what each lacks. Nothing is skipped silently or borrowed.
            if ($icp['sub_missing'] === []) {
                return ["{$label} has no sub-products yet: add one in the Product Portfolio and give it an Individual ICP before discovery can run"];
            }

            return array_values(array_map(fn ($id, $m) => "{$m['name']} ({$id}): ICP not configured - " . implode('; ', $m['problems']), array_keys($icp['sub_missing']), $icp['sub_missing']));
        }
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
// B2C represents B2B Partner Acquisition and is fully supported.
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
        if ($out === []) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($def['portfolio_names'][0] ?? $def['label'])));
            $out[$key] = [
                'offer_id' => $key,
                'name' => (string) $def['label'],
                'parent_product' => (string) ($def['portfolio_names'][0] ?? $def['label']),
                'what_it_does' => "Comprehensive " . (string) $def['label'] . " solutions",
            ];
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
