<?php

namespace App\Domain\Signals\Products;

/**
 * What a new product's key and name must satisfy. Pure (no database): the caller passes the built-in definitions and the organisation's existing
 * custom products, so a built-in product can never be overridden and one organisation's names never collide with its own.
 *
 * The key doubles as the stored `business_fit` value (a 20-character column) and as the ICP product line, which is why it is at most 20 characters.
 */
final class CustomProductRules
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{2,19}$/';

    /** Words that already mean something in the product filters. */
    public const RESERVED = ['all', 'unassigned', 'none', 'null', 'new', 'undefined'];

    /**
     * @param  array<string, array<string, mixed>>  $builtIn  ProductCatalog::definitions()
     * @param  array<string, array<string, mixed>>  $existing  this organisation's custom products, by key
     * @return list<string> what is wrong (empty when the product can be created)
     */
    public static function problems(string $key, string $label, array $builtIn, array $existing): array
    {
        $out = [];
        $label = trim($label);
        $lower = static fn (string $v): string => mb_strtolower(trim($v));

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $out[] = 'key: use 3 to 20 characters: lower-case letters, digits and underscores, starting with a letter';
        }
        if (in_array($key, self::RESERVED, true)) {
            $out[] = 'key: this word is reserved';
        }
        if (mb_strlen($label) < 2 || mb_strlen($label) > 50) {
            $out[] = 'label: 2 to 50 characters';
        }

        $taken = [];
        foreach ($builtIn as $k => $d) {
            $taken[$lower((string) $k)] = true;
            $taken[$lower((string) ($d['business_fit'] ?? ''))] = true;
            $taken[$lower((string) ($d['icp_product_line'] ?? ''))] = true;
        }
        if ($key !== '' && isset($taken[$lower($key)])) {
            $out[] = 'key: already used by a built-in product';
        }

        $names = [];
        foreach ($builtIn as $d) {
            $names[$lower((string) ($d['label'] ?? ''))] = true;
            foreach ((array) ($d['portfolio_names'] ?? []) as $n) {
                $names[$lower((string) $n)] = true;
            }
        }
        foreach ($existing as $k => $d) {
            if ($key !== '' && $lower((string) $k) === $lower($key)) {
                $out[] = 'key: you already have a product with this key';
            }
            $names[$lower((string) ($d['label'] ?? ''))] = true;
        }
        if ($label !== '' && isset($names[$lower($label)])) {
            $out[] = 'label: another product already has this name';
        }

        return $out;
    }
}
