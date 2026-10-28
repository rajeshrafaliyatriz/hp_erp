<?php

namespace App\Domain\Portfolio;

class TaxonomyService
{
    public static function getTaxonomy(): array
    {
        return config('g2g_taxonomy', []);
    }

    public static function getNeedLabel(string $code): string
    {
        return config("g2g_taxonomy.needs.{$code}", $code);
    }

    public static function getSegmentLabel(string $code): string
    {
        return config("g2g_taxonomy.segments.{$code}", $code);
    }

    /** A signal family's label with what it covers, or the older signal code's label, or the text itself (a person's own entry). */
    public static function getSignalLabel(string $code): string
    {
        $family = config("g2g_taxonomy.signal_families.{$code}");
        if (is_array($family)) {
            return $family['label'] . ': ' . $family['examples'];
        }

        return config("g2g_taxonomy.signals.{$code}", $code);
    }

    /**
     * The signal family a signal's trigger type belongs to, when it clearly does. Types that could belong to several (hiring, programme, regulation,
     * partnership, pain_point, other) have no family rather than a guessed one.
     */
    public const TRIGGER_FAMILY = [
        'expansion' => 'expansion', 'acquisition' => 'expansion', 'leadership' => 'leadership',
        'tender' => 'funding_procurement', 'rfp' => 'funding_procurement', 'eoi' => 'funding_procurement', 'rfq' => 'funding_procurement', 'funding' => 'funding_procurement',
        'vendor_search' => 'direct_intent',
    ];

    public static function familyForTrigger(?string $type): ?string
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            return null;
        }

        return isset(self::signalFamilies()[$type]) ? $type : (self::TRIGGER_FAMILY[$type] ?? null);
    }

    /** @return array<string, array{label: string, examples: string, relevance: string}> */
    public static function signalFamilies(): array
    {
        return (array) config('g2g_taxonomy.signal_families', []);
    }

    public static function isPartnerSellable(string $readinessStatus): bool
    {
        $allowed = config('g2g_taxonomy.partner_sellable_readiness', [
            'Live – with client',
            'Proven on real client data (pilot)',
            'Built – no production client',
        ]);

        return in_array($readinessStatus, $allowed, true);
    }

    public static function validateNeedCodes(array $codes): array
    {
        $valid = array_keys(config('g2g_taxonomy.needs', []));
        return array_values(array_intersect($codes, $valid));
    }

    public static function validateSegmentCodes(array $codes): array
    {
        $valid = array_keys(config('g2g_taxonomy.segments', []));
        return array_values(array_intersect($codes, $valid));
    }

    public static function validateSignalCodes(array $codes): array
    {
        $valid = array_merge(array_keys(config('g2g_taxonomy.signal_families', [])), array_keys(config('g2g_taxonomy.signals', [])));
        return array_values(array_intersect($codes, $valid));
    }
}

