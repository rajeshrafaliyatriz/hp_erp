<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpProfileVersion;
use Illuminate\Support\Facades\DB;

/**
 * Reads and saves a product's OWN ICP in g2g_icp_profiles. The product is the address: a profile is never reached by id through another
 * product, the product line is stamped by the server (never taken from the request), and at most one ICP is active per product line.
 * Saving only writes the ICP (and a version snapshot). It starts no research and calls no search or AI provider.
 */
final class ProductIcpService
{
    /** The settings the editor manages. Any other setting of a stored ICP (scoring, tiers, ...) is kept exactly as stored. */
    public const MANAGED = [
        'label', 'pitch', 'industry_label', 'guidance', 'buyer_types', 'regions', 'priority_cities', 'competitors', 'vendor_phrases',
        'parts', 'triggers', 'queries', 'disqualifiers', 'must_have', 'per_run', 'account_query', 'account_days', 'account_recheck_days',
    ];

    public function __construct(private readonly IcpProfileValidator $validator = new IcpProfileValidator())
    {
    }

    /** @return array<string, mixed> everything the editor needs for one product */
    public function editorState(int $tenantId, string $productKey): array
    {
        $def = $this->definition($productKey);
        $catalog = new ProductCatalog();
        $profile = $this->target($tenantId, $def);
        $template = $def['icp_template'] ? config("signals_icp.{$def['icp_template']}") : null;

        $config = $profile ? (array) $profile->config : (is_array($template) ? $template : []);

        return [
            'product' => $catalog->describe($tenantId, $productKey),
            'profile' => $profile ? [
                'id' => (int) $profile->id, 'key' => $profile->key, 'name' => $profile->name, 'status' => $profile->status,
                'version' => (int) $profile->version, 'updated_at' => $profile->updated_at?->toIso8601String(),
            ] : null,
            'config_source' => $profile ? 'saved' : (is_array($template) ? 'template' : 'empty'),
            'config' => $this->managedOnly($config),
            'problems' => $profile ? $this->validator->validate((array) $profile->config) : [],
            'profiles' => $this->profiles($tenantId, $def),
            'versions' => $profile ? IcpProfileVersion::where('icp_profile_id', $profile->id)->orderByDesc('version')->limit(10)
                ->get(['version', 'changed_by', 'note', 'created_at'])
                ->map(fn ($v) => ['version' => $v->version, 'changed_by' => $v->changed_by, 'note' => $v->note, 'at' => $v->created_at?->toIso8601String()])->all() : [],
            'options' => [
                'sources' => IcpProfileValidator::SOURCES, 'topics' => ['news', 'general'], 'operators' => IcpProfileValidator::OPS,
                'max_searches_per_run' => (int) config('signals.opportunities.max_queries', 6),
            ],
        ];
    }

    /**
     * What saving this input would produce, and what is wrong with it. Writes nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, problems: list<string>, missing: list<string>}
     */
    public function check(int $tenantId, string $productKey, array $input): array
    {
        $def = $this->definition($productKey);
        $config = $this->merged($def, $this->target($tenantId, $def), $input);
        $problems = $this->validator->validate($config);

        return ['config' => $config, 'problems' => $problems, 'missing' => (new ProductCatalog())->missingFor($tenantId, $productKey, $config, $problems)];
    }

    /**
     * Save a product's ICP: edits its active ICP (a new version, the old one kept), else its latest draft, else creates one.
     *
     * @param  array<string, mixed>  $input
     * @return array{profile: IcpProfile, created: bool}
     *
     * @throws ProductIcpInvalid when the input is invalid, or cannot be activated
     */
    public function save(int $tenantId, ?int $userId, string $productKey, array $input, ?string $name, ?string $note, bool $activate): array
    {
        $def = $this->definition($productKey);
        $checked = $this->check($tenantId, $productKey, $input);
        if ($checked['problems'] !== []) {
            throw new ProductIcpInvalid($checked['problems']);
        }
        if ($activate && $checked['missing'] !== []) {
            throw new ProductIcpInvalid($checked['missing'], 'It cannot be activated yet');
        }
        $config = $checked['config'];

        return DB::transaction(function () use ($tenantId, $userId, $def, $config, $name, $note, $activate) {
            $profile = $this->target($tenantId, $def);
            $created = $profile === null;
            if ($created) {
                $profile = IcpProfile::create([
                    'sub_institute_id' => $tenantId, 'key' => $this->newKey($tenantId, $def), 'name' => $name ?: (string) $config['label'],
                    'product_line' => $def['icp_product_line'], 'status' => 'draft', 'is_default' => false, 'version' => 1,
                    'config' => $config, 'created_by' => $userId, 'updated_by' => $userId,
                ]);
                $this->snapshot($profile, $userId, $note ?: 'created');
            } else {
                $changed = $config != (array) $profile->config;
                if ($changed) {
                    $profile->version = (int) $profile->version + 1;
                    $profile->config = $config;
                }
                if ($name !== null && $name !== '') {
                    $profile->name = $name;
                }
                $profile->product_line = $def['icp_product_line'];
                $profile->updated_by = $userId;
                $profile->save();
                if ($changed) {
                    $this->snapshot($profile, $userId, $note ?: 'edited');
                }
            }
            if ($activate) {
                $this->activateExclusive($profile);
            }

            return ['profile' => $profile->refresh(), 'created' => $created];
        });
    }

    /**
     * Activate, deactivate (draft) or archive the product's ICP.
     *
     * @throws ProductIcpInvalid
     */
    public function setStatus(int $tenantId, ?int $userId, string $productKey, string $status): IcpProfile
    {
        $def = $this->definition($productKey);
        $profile = $this->target($tenantId, $def);
        if ($profile === null) {
            throw new ProductIcpInvalid(['No ICP has been saved for ' . $def['label'] . ' yet.'], 'Nothing to change');
        }
        if ($status === 'active') {
            $problems = $this->validator->validate((array) $profile->config);
            $missing = (new ProductCatalog())->missingFor($tenantId, $productKey, (array) $profile->config, $problems);
            if ($missing !== []) {
                throw new ProductIcpInvalid($missing, 'It cannot be activated yet');
            }
        }

        return DB::transaction(function () use ($profile, $status, $userId) {
            $profile->updated_by = $userId;
            $profile->status = $status;
            if ($status === 'active') {
                $this->activateExclusive($profile);
            } else {
                $profile->is_default = false;
                $profile->save();
            }

            return $profile->refresh();
        });
    }

    /** Make this the only active ICP of its product line (the others are archived, with their history). */
    public function activateExclusive(IcpProfile $profile): void
    {
        if ($profile->product_line !== null && $profile->product_line !== '') {
            IcpProfile::where('sub_institute_id', $profile->sub_institute_id)->where('product_line', $profile->product_line)
                ->where('id', '!=', $profile->id)->where('status', 'active')->update(['status' => 'archived', 'is_default' => false]);
        }
        $profile->status = 'active';
        $profile->is_default = true;
        $profile->save();
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array{label: string, business_fit: string, portfolio_names: list<string>, icp_product_line: string, icp_template: ?string} */
    private function definition(string $productKey): array
    {
        $key = ProductCatalog::normalise($productKey);
        if ($key === null) {
            throw new ProductNotConfigured($productKey, ['not a known product'], 'unknown_product');
        }

        return ProductCatalog::definitions()[$key];
    }

    /** The profile this product edits: its active one, else its latest draft. Archived ones are history. */
    private function target(int $tenantId, array $def): ?IcpProfile
    {
        $base = fn () => IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $def['icp_product_line']);

        return $base()->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->first()
            ?? $base()->where('status', 'draft')->orderByDesc('id')->first();
    }

    /** @return list<array<string, mixed>> */
    private function profiles(int $tenantId, array $def): array
    {
        return IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $def['icp_product_line'])->orderBy('id')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'key' => $p->key, 'name' => $p->name, 'status' => $p->status, 'version' => (int) $p->version])->all();
    }

    /**
     * The stored settings with the editor's input laid over the managed ones. Only managed settings are taken from the input; the
     * product line is always the product's own.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function merged(array $def, ?IcpProfile $profile, array $input): array
    {
        $template = $def['icp_template'] ? config("signals_icp.{$def['icp_template']}") : null;
        $base = $profile ? (array) $profile->config : (is_array($template) ? $template : []);
        foreach (self::MANAGED as $k) {
            if (array_key_exists($k, $input)) {
                if ($input[$k] === null || $input[$k] === '' || $input[$k] === []) {
                    unset($base[$k]);
                } else {
                    $base[$k] = $input[$k];
                }
            }
        }
        $base['product_line'] = $def['icp_product_line'];

        return $base;
    }

    /** @return array<string, mixed> */
    private function managedOnly(array $config): array
    {
        return array_intersect_key($config, array_flip(self::MANAGED));
    }

    private function newKey(int $tenantId, array $def): string
    {
        $base = (string) ($def['icp_template'] ?? ($def['icp_product_line'] . '_icp'));
        $key = $base;
        for ($i = 2; IcpProfile::where('sub_institute_id', $tenantId)->where('key', $key)->exists(); $i++) {
            $key = $base . '_' . $i;
        }

        return $key;
    }

    private function snapshot(IcpProfile $p, ?int $userId, string $note): void
    {
        IcpProfileVersion::create([
            'sub_institute_id' => $p->sub_institute_id, 'icp_profile_id' => $p->id, 'version' => $p->version,
            'config' => $p->config, 'changed_by' => $userId, 'note' => mb_substr($note, 0, 255),
        ]);
    }
}
