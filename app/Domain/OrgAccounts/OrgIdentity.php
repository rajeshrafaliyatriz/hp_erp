<?php

namespace App\Domain\OrgAccounts;

/**
 * What an identity row says about the source rows behind it. Used where an identity is created (the backfill and a manual link), so the two agree.
 */
final class OrgIdentity
{
    /** The stages an organisation passes through, lowest first. `archived` is outside the ladder: a person puts it there and nothing raises it. */
    private const LADDER = ['discovered', 'target', 'engaged', 'customer'];

    /** The source whose name is the best one to show, best first: the CRM account is what the team keeps by hand. */
    private const NAME_ORDER = [OrgAccount::SOURCE_CRM, OrgAccount::SOURCE_GTM, OrgAccount::SOURCE_COMPANY];

    /**
     * name, normalized_name, domain and lifecycle for an identity made of these source rows.
     *
     * @param  list<array<string, mixed>>  $rows  rows as OrgSources shapes them
     * @return array{name: string, normalized_name: string, domain: ?string, lifecycle: string}
     */
    public static function fields(array $rows): array
    {
        $ordered = self::byPriority($rows);

        $name = '';
        $domain = null;
        foreach ($ordered as $row) {
            if ($name === '' && $row['name'] !== '') {
                $name = $row['name'];
            }
            if ($domain === null && $row['domain'] !== null) {
                $domain = $row['domain'];
            }
        }
        $name = mb_substr($name === '' ? '(unnamed)' : $name, 0, 191);

        return [
            'name' => $name,
            'normalized_name' => mb_substr(OrgNormalizer::name($name), 0, 191),
            'domain' => $domain === null ? null : mb_substr($domain, 0, 191),
            'lifecycle' => self::lifecycleFor($rows),
        ];
    }

    /** `engaged` when a CRM account exists, else `target` when a GTM account does, else `discovered`. */
    public static function lifecycleFor(array $rows): string
    {
        $types = array_column($rows, 'type');
        if (in_array(OrgAccount::SOURCE_CRM, $types, true)) {
            return 'engaged';
        }

        return in_array(OrgAccount::SOURCE_GTM, $types, true) ? 'target' : 'discovered';
    }

    /** The higher of two lifecycles on the ladder. A value outside the ladder (`archived`) is a person's decision and is kept as it is. */
    public static function raise(string $current, string $derived): string
    {
        $c = array_search($current, self::LADDER, true);
        $d = array_search($derived, self::LADDER, true);

        return ($c === false || $d === false || $d <= $c) ? $current : $derived;
    }

    /**
     * The identity as the callers see it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function present(array $attributes): array
    {
        $out = [];
        foreach (['id', 'name', 'normalized_name', 'domain', 'lifecycle', 'crm_organization_id', 'gtm_account_id', 'g2g_company_id', 'crm_link_basis', 'gtm_link_basis',
            'company_link_basis', 'owner_user_id'] as $column) {
            $out[$column] = $attributes[$column] ?? null;
        }
        foreach (['id', 'crm_organization_id', 'gtm_account_id', 'g2g_company_id', 'owner_user_id'] as $column) {
            $out[$column] = $out[$column] === null ? null : (int) $out[$column];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function byPriority(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => array_search($a['type'], self::NAME_ORDER, true) <=> array_search($b['type'], self::NAME_ORDER, true) ?: $a['id'] <=> $b['id']);

        return $rows;
    }
}
