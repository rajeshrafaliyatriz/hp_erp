<?php

namespace App\Domain\Campaigns;

use App\Domain\Crm\Leads\LeadLinks;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What a campaign is linked to, and whether a caller may link it (slice 9). Read only, and every lookup carries the organisation, so another organisation's
 * campaign or product can never be linked, counted or named. Where a table or column is not on this host yet (the code reaches a server before its migrations
 * do), the answer is "not linked" or "not available", never an error.
 *
 *   product      a Product Master row (a product or a sub-product) the organisation may use: its own or a shared one. The same rule a lead's product follows
 *                (LeadLinks::productVisible), so a campaign and a lead can never disagree about what is visible.
 *   campaign     a CRM campaign of the organisation that is not in the Recycle Bin. A campaign in the Recycle Bin answers 404 on its own routes, so it is not
 *                a thing to link to either.
 */
final class CampaignLinks
{
    /**
     * The optional link a request names under `$key` (snake case) or its camel-case spelling (the CRM controllers speak camel case, the GTM ones snake case).
     * Snake case wins if both are sent. An empty value (null or '') is a present request to have NO link; anything that is not a whole number above 0 is malformed.
     *
     * @param  array<string, mixed>  $input
     * @return array{present: bool, id: ?int, malformed: bool}
     */
    public static function read(array $input, string $key): array
    {
        $camel = Str::camel($key);
        $name = array_key_exists($key, $input) ? $key : (array_key_exists($camel, $input) ? $camel : null);
        if ($name === null) {
            return ['present' => false, 'id' => null, 'malformed' => false];
        }

        $value = $input[$name];
        if ($value === null || $value === '') {
            return ['present' => true, 'id' => null, 'malformed' => false];
        }
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return ['present' => true, 'id' => (int) $value, 'malformed' => false];
        }

        return ['present' => true, 'id' => null, 'malformed' => true];
    }

    /**
     * The product a request names for a CRM campaign (`product_master_id` / `productMasterId`), checked against THIS organisation. Absent means "leave it as it
     * is"; an empty value means "no product"; a product the organisation may not use (another organisation's, one that does not exist) is "not found", the same
     * answer for both so it says nothing about the other organisation. A host whose campaigns table has no such column cannot store a product and says so
     * instead of answering as if it had; clearing a product there has nothing to clear and is accepted.
     *
     * @param  array<string, mixed>  $input
     * @return array{present: bool, id: ?int, error: ?string, code: ?string}
     */
    public static function checkedProduct(int $tenantId, array $input): array
    {
        return self::checked($tenantId, $input, 'product_master_id', 'crm_campaigns', 'product', 'campaigns', [self::class, 'productVisible']);
    }

    /**
     * The campaign a request names as the one that first touched a NEW lead (`campaign_id` / `campaignId`), checked the same way against THIS organisation: its own
     * live campaign, else "not found" (the same answer for another organisation's campaign and for one that does not exist).
     *
     * @param  array<string, mixed>  $input
     * @return array{present: bool, id: ?int, error: ?string, code: ?string}
     */
    public static function checkedLeadCampaign(int $tenantId, array $input): array
    {
        return self::checked($tenantId, $input, 'campaign_id', 'crm_leads', 'campaign', 'leads', [self::class, 'crmCampaignVisible']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  callable(int, int): bool  $visible
     * @return array{present: bool, id: ?int, error: ?string, code: ?string}
     */
    private static function checked(int $tenantId, array $input, string $key, string $table, string $noun, string $tableLabel, callable $visible): array
    {
        $none = ['present' => false, 'id' => null, 'error' => null, 'code' => null];
        $link = self::read($input, $key);

        if ($link['malformed']) {
            return ['present' => true, 'id' => null, 'error' => "The {$noun} must be a whole number above 0.", 'code' => "{$noun}_invalid"];
        }
        if (! $link['present']) {
            return $none;
        }

        $hasColumn = self::hasColumn($table, $key);

        if ($link['id'] === null) {
            return $hasColumn ? ['present' => true, 'id' => null, 'error' => null, 'code' => null] : $none;
        }
        if (! $hasColumn) {
            return ['present' => true, 'id' => null, 'error' => "Linking a {$noun} is not available yet: the {$tableLabel} table has not been updated on this server.", 'code' => "{$noun}_link_unavailable"];
        }
        if (! $visible($tenantId, $link['id'])) {
            return ['present' => true, 'id' => null, 'error' => ucfirst($noun) . ' not found.', 'code' => "{$noun}_not_found"];
        }

        return ['present' => true, 'id' => $link['id'], 'error' => null, 'code' => null];
    }

    /** Whether this host has the column. ColumnExists::all, not ::has: has() throws on the live MariaDB 10.1 until commit 02d4d896 is deployed (DB-RULES, defect D20). */
    public static function hasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && in_array($column, ColumnExists::all($table), true);
    }

    /** Whether the organisation may link this Product Master row: its own or a shared one. An archived product still counts: a campaign is filed under what it sold. */
    public static function productVisible(int $tenantId, int $productId): bool
    {
        return LeadLinks::productVisible($tenantId, $productId);
    }

    /** Whether this is a CRM campaign of the organisation that is not in the Recycle Bin. */
    public static function crmCampaignVisible(int $tenantId, int $campaignId): bool
    {
        return $tenantId > 0 && $campaignId > 0 && Schema::hasTable('crm_campaigns')
            && DB::table('crm_campaigns')->where('id', $campaignId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists();
    }

    /**
     * The GTM campaign that is the same campaign as this CRM campaign, or null. The organisation's own, not in the Recycle Bin; if data ever holds two links
     * the oldest answers (the write rule below stops a second one from being made).
     *
     * @return array{id: int, name: string, status: string}|null
     */
    public static function gtmCampaignOf(int $tenantId, int $crmCampaignId): ?array
    {
        if ($tenantId <= 0 || $crmCampaignId <= 0 || ! self::hasColumn('gtm_campaigns', 'crm_campaign_id')) {
            return null;
        }

        $row = DB::table('gtm_campaigns')->where('sub_institute_id', $tenantId)->where('crm_campaign_id', $crmCampaignId)->whereNull('deleted_at')
            ->orderBy('id')->first(['id', 'name', 'status']);

        return $row === null ? null : ['id' => (int) $row->id, 'name' => (string) $row->name, 'status' => (string) $row->status];
    }

    /**
     * The CRM campaign a GTM campaign points at, as `{id, name, status}`, or null (no link, another organisation's, in the Recycle Bin, or deleted for good).
     *
     * @return array{id: int, name: string, status: ?string}|null
     */
    public static function crmCampaignSummary(int $tenantId, ?int $crmCampaignId): ?array
    {
        $id = (int) $crmCampaignId;
        if ($tenantId <= 0 || $id <= 0 || ! Schema::hasTable('crm_campaigns')) {
            return null;
        }

        $row = DB::table('crm_campaigns')->where('id', $id)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first(['id', 'name', 'campaign_status']);

        return $row === null ? null : ['id' => (int) $row->id, 'name' => (string) $row->name, 'status' => $row->campaign_status];
    }

    /** Whether a GTM campaign of the organisation, other than `$exceptGtmId`, is already linked to this CRM campaign. */
    public static function crmCampaignTakenByAnotherGtm(int $tenantId, int $crmCampaignId, ?int $exceptGtmId = null): bool
    {
        if (! self::hasColumn('gtm_campaigns', 'crm_campaign_id')) {
            return false;
        }

        $query = DB::table('gtm_campaigns')->where('sub_institute_id', $tenantId)->where('crm_campaign_id', $crmCampaignId)->whereNull('deleted_at');
        if ($exceptGtmId !== null) {
            $query->where('id', '!=', $exceptGtmId);
        }

        return $query->exists();
    }

    /** How many of the organisation's leads (not in the Recycle Bin, converted ones included) first came from this campaign. */
    public static function leadCount(int $tenantId, int $campaignId): int
    {
        if ($tenantId <= 0 || $campaignId <= 0 || ! self::hasColumn('crm_leads', 'campaign_id')) {
            return 0;
        }

        return (int) DB::table('crm_leads')->where('sub_institute_id', $tenantId)->where('campaign_id', $campaignId)->whereNull('deleted_at')->count();
    }
}
