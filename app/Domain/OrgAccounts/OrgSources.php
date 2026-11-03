<?php

namespace App\Domain\OrgAccounts;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the three places an organisation lives (the CRM account, the GTM account, the Signals company) as one shape, plus the identities and the
 * rejected suggestions of one organisation. READ ONLY: nothing here writes to any table.
 *
 * A source row is "live" when it is not soft-deleted (g2g_companies has no deleted_at column; every row of it is live). Every read filters by
 * sub_institute_id, so another organisation's rows are never seen.
 *
 * A row is an array: key ("type:id"), type, id, name, norm (OrgNormalizer::name), tokens, domain (null when absent or a shared host such as a
 * Facebook page), place (tokens of where it is; empty when unknown), links (the keys of rows the system itself already tied it to).
 */
final class OrgSources
{
    /** @var array<string, string> source type => table */
    public const TABLES = [
        OrgAccount::SOURCE_CRM => 'crm_organizations',
        OrgAccount::SOURCE_GTM => 'gtm_accounts',
        OrgAccount::SOURCE_COMPANY => 'g2g_companies',
    ];

    /** Columns read from each table; the ones a host lacks are skipped (dev and live schemas differ). */
    private const COLUMNS = [
        OrgAccount::SOURCE_CRM => ['id', 'name', 'website', 'billing_city', 'billing_state', 'deleted_at'],
        OrgAccount::SOURCE_GTM => ['id', 'name', 'domain', 'website', 'location', 'company_id', 'crm_organization_id', 'deleted_at'],
        OrgAccount::SOURCE_COMPANY => ['id', 'name', 'domain', 'website', 'location', 'state', 'deleted_at'],
    ];

    /**
     * A country or a state says nothing about WHICH place in it: "Surat, India" and "Pune, India" must not look like the same place, and neither must "Surat,
     * Gujarat" and "Anand, Gujarat" (two different schools can share a name inside one state; a false merge costs far more than a missed link). A place that is
     * ONLY a state, or empty, contradicts nothing. Cities that are also union territories (Delhi, Chandigarh, Daman, Diu) stay as place words.
     */
    private const PLACE_NOISE = ['india', 'andhra', 'pradesh', 'arunachal', 'assam', 'bihar', 'chhattisgarh', 'goa', 'gujarat', 'haryana', 'himachal', 'jharkhand', 'karnataka', 'kerala', 'madhya', 'maharashtra', 'manipur', 'meghalaya', 'mizoram', 'nagaland', 'odisha', 'orissa', 'punjab', 'rajasthan', 'sikkim', 'tamil', 'nadu', 'telangana', 'tripura', 'uttar', 'uttarakhand', 'bengal', 'andaman', 'nicobar', 'ladakh', 'jammu', 'kashmir', 'lakshadweep', 'puducherry'];

    public static function key(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /** @return array{0: string, 1: int} the type and id a key holds */
    public static function split(string $key): array
    {
        [$type, $id] = explode(':', $key, 2);

        return [$type, (int) $id];
    }

    /** The order a pair is stored and keyed in: by source type, then id. Returns a negative, zero or positive number like strcmp(). */
    public static function compare(string $a, string $b): int
    {
        [$ta, $ia] = self::split($a);
        [$tb, $ib] = self::split($b);

        return $ta === $tb ? $ia <=> $ib : strcmp($ta, $tb);
    }

    /** One string for an unordered pair of source keys, the same whichever is given first. */
    public static function pairKey(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? $a . '|' . $b : $b . '|' . $a;
    }

    /** @return list<string> the source tables this connection lacks */
    public static function missingTables(): array
    {
        return array_values(array_filter(self::TABLES, fn (string $table) => ! Schema::hasTable($table)));
    }

    /** @return bool whether the identity and rejection tables (created by the schema migration) exist here */
    public static function hasIdentityTables(): bool
    {
        return Schema::hasTable('g2g_org_accounts') && Schema::hasTable('g2g_org_link_rejections');
    }

    /**
     * Every organisation that has at least one live source row (the shared tenant 0 is never an organisation).
     *
     * @return list<int>
     */
    public static function tenants(): array
    {
        $ids = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $have = array_flip(ColumnExists::all($table));
            $query = DB::table($table)->where('sub_institute_id', '>', 0);
            if (isset($have['deleted_at'])) {
                $query->whereNull('deleted_at');
            }
            foreach ($query->distinct()->pluck('sub_institute_id') as $id) {
                $ids[(int) $id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * The live source rows of one organisation, CRM first, then GTM, then companies, each by id.
     *
     * @return array<string, array<string, mixed>> key => row
     */
    public static function rows(int $tenantId): array
    {
        $rows = [];
        foreach (self::TABLES as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (self::query($type, $tenantId)->orderBy('id')->get() as $raw) {
                $row = self::shape($type, $raw);
                $rows[$row['key']] = $row;
            }
        }

        return $rows;
    }

    /** One live source row of this organisation, or null (missing, deleted, or another organisation's). */
    public static function row(int $tenantId, string $type, int $id): ?array
    {
        if (! isset(self::TABLES[$type]) || ! Schema::hasTable(self::TABLES[$type])) {
            return null;
        }
        $raw = self::query($type, $tenantId)->where('id', $id)->first();

        return $raw === null ? null : self::shape($type, $raw);
    }

    /**
     * The organisation's live identities.
     *
     * @return list<array<string, mixed>>
     */
    public static function identities(int $tenantId): array
    {
        if (! Schema::hasTable('g2g_org_accounts')) {
            return [];
        }

        return OrgAccount::query()->where('sub_institute_id', $tenantId)->orderBy('id')->get()->map(fn (OrgAccount $a) => $a->getAttributes())->all();
    }

    /**
     * The pairs a person turned down, as pairKey() strings. Rows are read in either order.
     *
     * @return array<string, true>
     */
    public static function rejections(int $tenantId): array
    {
        if (! Schema::hasTable('g2g_org_link_rejections')) {
            return [];
        }
        $set = [];
        foreach (DB::table('g2g_org_link_rejections')->where('sub_institute_id', $tenantId)->get() as $r) {
            $set[self::pairKey(self::key($r->left_type, (int) $r->left_id), self::key($r->right_type, (int) $r->right_id))] = true;
        }

        return $set;
    }

    private static function query(string $type, int $tenantId): \Illuminate\Database\Query\Builder
    {
        $table = self::TABLES[$type];
        $have = array_flip(ColumnExists::all($table));
        $query = DB::table($table)->where('sub_institute_id', $tenantId)
            ->select(array_values(array_filter(self::COLUMNS[$type], fn (string $c) => isset($have[$c]))));
        if (isset($have['deleted_at'])) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private static function shape(string $type, object $raw): array
    {
        $name = trim((string) ($raw->name ?? ''));
        $links = [];
        $place = match ($type) {
            OrgAccount::SOURCE_CRM => [$raw->billing_city ?? null, $raw->billing_state ?? null],
            OrgAccount::SOURCE_GTM => [$raw->location ?? null],
            default => [$raw->location ?? null, $raw->state ?? null],
        };
        if ($type === OrgAccount::SOURCE_GTM) {
            if ((int) ($raw->company_id ?? 0) > 0) {
                $links[] = self::key(OrgAccount::SOURCE_COMPANY, (int) $raw->company_id);
            }
            if ((int) ($raw->crm_organization_id ?? 0) > 0) {
                $links[] = self::key(OrgAccount::SOURCE_CRM, (int) $raw->crm_organization_id);
            }
        }

        $domain = null;
        foreach ([$raw->domain ?? null, $raw->website ?? null] as $candidate) {
            $d = OrgNormalizer::domain($candidate === null ? null : (string) $candidate);
            if ($d !== null && ! OrgNormalizer::isSharedHost($d)) {
                $domain = $d;
                break;
            }
        }

        return [
            'key' => self::key($type, (int) $raw->id),
            'type' => $type,
            'id' => (int) $raw->id,
            'name' => $name,
            'norm' => OrgNormalizer::name($name),
            'tokens' => OrgNormalizer::tokens($name),
            'domain' => $domain,
            'place' => array_values(array_diff(OrgNormalizer::tokens(implode(' ', array_filter(array_map(fn ($p) => $p === null ? '' : trim((string) $p), $place)))), self::PLACE_NOISE)),
            'links' => $links,
        ];
    }
}
