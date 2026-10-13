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

    public static function getSignalLabel(string $code): string
    {
        return config("g2g_taxonomy.signals.{$code}", $code);
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
        $valid = array_keys(config('g2g_taxonomy.signals', []));
        return array_values(array_intersect($codes, $valid));
    }
}

