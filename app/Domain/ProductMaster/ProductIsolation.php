<?php

namespace App\Domain\ProductMaster;

/**
 * Which organisations the built-in Products belong to (S15 follow-up, 24 Nov 2026, contract Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md D-1, D-2).
 *
 * A Product, a Sub-product, an item, an About and a custom Product belong to ONE organisation and are visible to that organisation only. The four Products that are
 * defined in code (config/signals_products.php: ScholarClone, Enterprise Brain, G2G, Fiber Valley) are the exception that proves the rule: they belong to the
 * organisations named in config('product_isolation.builtin_owner_tenants') (env G2G_BUILTIN_PRODUCTS_TENANTS, a comma list, default 6) and to nobody else. This class is
 * the one place that reads that list, so a change of owner is a change of the environment and never of code.
 *
 * "No organisation" (a tenant of 0 or null: a code path with no organisation, such as a console command that lists every Product definition) is not an owner: it keeps
 * seeing the built-in definitions, because it reads the definitions of the CODE, not the products of an organisation.
 */
final class ProductIsolation
{
    public const CONFIG_KEY = 'product_isolation.builtin_owner_tenants';

    /** What the owner list is when nothing is configured: the organisation of the owner of the product. */
    public const DEFAULT_OWNERS = [6];

    /** @return list<int> the organisations the built-in Products belong to, as whole numbers above 0, each once, in the order they were given */
    public static function builtinOwners(): array
    {
        $configured = config(self::CONFIG_KEY, self::DEFAULT_OWNERS);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        $owners = [];
        foreach ((array) $configured as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)) {
                $id = (int) trim((string) $value);
                if ($id > 0) {
                    $owners[$id] = $id;
                }
            }
        }

        return array_values($owners);
    }

    /** Whether the built-in Products belong to this organisation. A tenant of 0 or null is no organisation and owns nothing. */
    public static function isBuiltinOwner(?int $tenantId): bool
    {
        return $tenantId !== null && $tenantId > 0 && in_array($tenantId, self::builtinOwners(), true);
    }

    /** The organisation `product-master:isolate-tenants` gives the shared rows to when no --owner is named: the first of the list. */
    public static function defaultOwner(): ?int
    {
        return self::builtinOwners()[0] ?? null;
    }
}
