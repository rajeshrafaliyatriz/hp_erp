<?php

namespace App\Domain\Signals\Products;

/**
 * What a run for one product will be built from, as a short, honest report: which saved product details and which ICP fields exist, what each
 * is used for, and what is missing. Pure (no database): it reads a described product (ProductCatalog::describe) and the ICP configuration that
 * discovery will use, and it never fills a gap with another product's data.
 *
 * The report is for people (and for a pre-run notice). It does not change how a run is planned or qualified.
 */
final class ResearchContextReport
{
    /** field => [where it comes from, what the research uses it for] */
    public const PRODUCT_FIELDS = [
        'description' => 'the product description: read by the ICP comparison, the fit check and the research prompt',
        'features' => 'features and capabilities: the capability a signal must map to',
        'problems' => 'customer problems solved: the needs a source is looked for',
        'target_customers' => 'target customers (including your own entries): who the search is aimed at',
        'buying_events' => 'buying events (including your own entries): the kinds of event that count as a trigger',
        'proof' => 'benefits and proof: the outreach angle',
    ];

    public const ICP_FIELDS = [
        'ideal_customer' => 'the ideal customer: the search and the screen for who is a buyer',
        'buyer_types' => 'customer types: the kind of organisation a signal must be about',
        'regions' => 'geography: where the search looks',
        'parts' => 'product parts: what a signal must be tied to',
        'triggers' => 'buying triggers: the events that count, and how long they stay fresh',
        'queries' => 'search queries: what a run looks for',
        'must_have' => 'must-have criteria: facts a customer must meet (an unknown fact is never a failure)',
        'disqualifiers' => 'exclusions: organisations that are never returned',
        'guidance' => 'qualification guidance: extra instructions to the research',
    ];

    /**
     * Which stage of a run reads each field. A field that no stage reads says so (it is kept for people, never sent to a provider or an AI call).
     * Stages: planning (the searches), prompt (what the research model is told), fit (matching a signal to the product), qualification (the gate
     * and the ICP's rules), scoring, outreach (the next-action wording on a card), none.
     */
    public const STAGES = [
        'description' => ['prompt', 'fit'], 'features' => ['planning', 'prompt', 'fit'], 'problems' => ['planning', 'prompt', 'fit'],
        'target_customers' => ['planning', 'prompt'], 'buying_events' => ['planning', 'prompt'], 'proof' => ['outreach'],
        'pricing_model' => ['none'], 'typical_deal_band' => ['none'], 'deployment_model' => ['none'], 'delivery_owner' => ['none'], 'notes' => ['none'],
        'company_profile' => ['none'],
        'ideal_customer' => ['planning', 'prompt', 'qualification'], 'buyer_types' => ['planning', 'prompt', 'qualification'],
        'regions' => ['planning', 'prompt', 'qualification'], 'parts' => ['prompt', 'fit'], 'triggers' => ['planning', 'prompt', 'qualification'],
        'queries' => ['planning'], 'must_have' => ['qualification', 'scoring'], 'disqualifiers' => ['qualification'], 'guidance' => ['prompt'],
    ];

    /** Saved on the product but not read by research: shown so nobody wonders, and never invented when blank. */
    public const KEPT_FOR_PEOPLE = [
        'pricing_model' => 'pricing model: kept for the sales team; never sent to a search or AI call, never invented',
        'typical_deal_band' => 'typical deal size: kept for the sales team; never used to qualify a signal',
        'deployment_model' => 'deployment: kept for the sales team and the ICP comparison',
        'delivery_owner' => 'delivery owner: kept for the sales team',
        'notes' => 'notes: kept for the team; not sent to a provider',
        'company_profile' => 'company profile: describes the selling company, not a product, so it is not used to research a product',
    ];

    /**
     * @param  array<string, mixed>  $product  ProductCatalog::describe() output
     * @param  array<string, mixed>|null  $config  the ICP configuration discovery will use (null = none)
     * @return array<string, mixed>
     */
    public static function build(array $product, ?array $config, ?int $icpVersion = null, bool $companyProfileSaved = false, ?\App\Domain\Signals\Support\RegionScope $scope = null): array
    {
        $offers = (array) ($product['offers'] ?? []);
        $any = fn (string $k) => array_filter(array_map(fn ($o) => $o[$k] ?? null, $offers), fn ($v) => $v !== null && $v !== '' && $v !== []);

        $present = [
            'description' => $any('what_it_does') !== [],
            'features' => $any('modules_components') !== [],
            'problems' => $any('needs_solved') !== [],
            'target_customers' => $any('primary_segments') !== [],
            'buying_events' => $any('trigger_signals') !== [],
            'proof' => $any('proof_points') !== [],
        ];
        $kept = [
            'pricing_model' => $any('pricing_model') !== [], 'typical_deal_band' => $any('typical_deal_band') !== [], 'deployment_model' => $any('deployment_model') !== [],
            'delivery_owner' => $any('delivery_owner') !== [], 'notes' => $any('notes') !== [], 'company_profile' => $companyProfileSaved,
        ];
        $c = $config ?? [];
        $icpPresent = [
            'ideal_customer' => trim((string) ($c['industry_label'] ?? '')) !== '',
            'buyer_types' => ! empty($c['buyer_types']),
            'regions' => ! empty($c['regions']),
            'parts' => ! empty($c['parts']),
            'triggers' => ! empty($c['triggers']),
            'queries' => ! empty($c['queries']),
            'must_have' => ! empty($c['must_have']),
            'disqualifiers' => ! empty($c['disqualifiers']),
            'guidance' => trim((string) ($c['guidance'] ?? '')) !== '',
        ];

        $warnings = [];
        if ($config === null) {
            $warnings[] = 'This product has no active ICP, so research cannot start. Nothing from another product is used instead.';
        } else {
            foreach (['ideal_customer' => 'who the ideal customer is', 'triggers' => 'buying triggers', 'queries' => 'search queries', 'regions' => 'a geography'] as $k => $what) {
                if (! $icpPresent[$k]) {
                    $warnings[] = "The ICP does not say {$what}" . ($k === 'regions' ? ': searches will not be limited to a region.' : '.');
                }
            }
        }
        if (($config['customer_model'] ?? null) === 'both') {
            $warnings[] = 'The ICP is set to B2B and B2C: signals are found for organisations only. Signals about individual consumers are not generated.';
        } elseif (($config['customer_model'] ?? null) === 'b2g') {
            $warnings[] = 'The ICP is set to B2G: searches and signals focus on tenders, procurement, government departments, and educational boards.';
        }
        if ($offers === []) {
            $warnings[] = 'No product details are saved for this product (no offers), so the research has no description, features or problems to match against.';
        } elseif (! $present['description'] && ! $present['features']) {
            $warnings[] = 'The product has no description or features saved: a signal cannot be matched to what it does.';
        }

        $source = $product['icp']['source'] ?? null;

        $saved = array_values(array_map('strval', (array) ($c['regions'] ?? [])));
        // The geography this run will actually use, and where it comes from. `request` replaces the saved regions in the planning copy only;
        // otherwise the saved (or built-in) ICP regions are the default; with neither, the run is not limited to a place.
        $region = $scope !== null
            ? ['source' => 'request', 'label' => $scope->label(), 'places' => count($scope->selections()), 'saved_regions' => $saved,
                'note' => 'Searches and the research prompt use this region. The ICP\'s saved regions are kept for qualifying target accounts and are not changed.']
            : ($saved !== []
                ? ['source' => 'saved ICP', 'label' => implode(', ', $saved), 'places' => count($saved), 'saved_regions' => $saved,
                    'note' => 'No region was chosen for this run, so the ICP\'s saved regions apply as the default.']
                : ['source' => 'All Regions', 'label' => 'All Regions', 'places' => 0, 'saved_regions' => [], 'note' => 'Research is not limited to a place.']);

        return [
            'region' => $region,
            'product' => ['key' => $product['key'] ?? null, 'label' => $product['label'] ?? null, 'custom' => (bool) ($product['custom'] ?? false)],
            'icp' => $config === null ? null : [
                'source' => $source, 'profile_id' => $product['icp']['profile_id'] ?? null, 'name' => $product['icp']['name'] ?? ($c['label'] ?? null), 'version' => $icpVersion,
                'regions' => array_values(array_map('strval', (array) ($c['regions'] ?? []))),
                'saved' => $source === 'database',
            ],
            'product_fields' => array_map(fn ($k) => ['field' => $k, 'saved' => $present[$k], 'used_for' => self::PRODUCT_FIELDS[$k], 'stages' => self::STAGES[$k]], array_keys(self::PRODUCT_FIELDS)),
            'icp_fields' => array_map(fn ($k) => ['field' => $k, 'saved' => $icpPresent[$k], 'used_for' => self::ICP_FIELDS[$k], 'stages' => self::STAGES[$k]], array_keys(self::ICP_FIELDS)),
            'kept_for_people' => array_map(fn ($k) => ['field' => $k, 'saved' => $kept[$k], 'used_for' => self::KEPT_FOR_PEOPLE[$k], 'stages' => self::STAGES[$k]], array_keys(self::KEPT_FOR_PEOPLE)),
            'offer_count' => count($offers),
            'warnings' => $warnings,
        ];
    }
}
