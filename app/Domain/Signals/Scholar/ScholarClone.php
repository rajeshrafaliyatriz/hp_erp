<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Opportunities\ProductProfile;

/**
 * ScholarClone is the ONLY product the Signals engine looks for buyers of.
 *
 * Who buys it, what the parts are, which events show a school needs it, and where to look for those events all live in
 * config/signals_icp.php under `scholar_k12`. This class only hands that definition to the engine, so there is no
 * product profile to fill in, no second ICP to choose and nothing to configure per organisation.
 */
final class ScholarClone
{
    public const KEY = 'scholar_k12';

    public static function pack(): IcpPack
    {
        return IcpPack::fromTemplate(self::KEY) ?? throw new \LogicException('config/signals_icp.php has no scholar_k12 definition.');
    }

    /**
     * The product, as the research code expects to read it. Never saved: it is a fixed fact, not a setting.
     */
    public static function profile(int $tenantId): ProductProfile
    {
        $c = self::pack()->config();

        return new ProductProfile([
            'sub_institute_id' => $tenantId,
            'product_name' => 'ScholarClone',
            'description' => (string) ($c['pitch'] ?? 'School ERP, learning platform, teacher development and career guidance for K-12 schools.'),
            'problems_solved' => implode('; ', array_map(fn ($t) => (string) ($t['pain'] ?? ''), (array) ($c['triggers'] ?? []))),
            'features' => implode('; ', array_values((array) ($c['parts'] ?? []))),
            'keywords' => ['school ERP', 'school management system', 'learning management system', 'teacher training', 'career guidance'],
            'target_industries' => ['K-12 education'],
            'target_company_types' => ['private school', 'school group', 'school trust'],
            'target_markets' => array_values((array) ($c['regions'] ?? [])),
            'excluded' => ['coaching', 'tuition', 'college', 'university', 'play school', 'pre-primary'],
            'competitors' => (array) ($c['competitors'] ?? []),
            'recency_days' => (int) config('signals.opportunities.default_recency_days', 30),
        ]);
    }

    /**
     * The parts of ScholarClone a signal can be built on, keyed by part. This is the whole "catalogue": one product.
     *
     * @return array<string, array{offer_id: string, name: string, parent_product: string, what_it_does: string}>
     */
    public static function parts(): array
    {
        $out = [];
        foreach ((array) (self::pack()->config()['parts'] ?? []) as $key => $what) {
            $out[(string) $key] = [
                'offer_id' => (string) $key, 'name' => ucwords(str_replace('_', ' ', (string) $key)),
                'parent_product' => 'Scholar K-12', 'what_it_does' => (string) $what,
            ];
        }

        return $out;
    }
}
