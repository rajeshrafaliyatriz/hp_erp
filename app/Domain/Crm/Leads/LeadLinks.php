<?php

namespace App\Domain\Crm\Leads;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead is FOR and WHO it is about, worked out from what is known (slice 6). Read only; every lookup carries the organisation, so another
 * organisation's product or identity can never be linked to a lead. Where the table a lookup needs is not on this host yet (the code reaches a server
 * before its migrations do), the answer is "not known", never an error.
 *
 *   product       the signal's own Product Master row when it has one (slice 2 decided it, sub-product aware), else the row the existing resolver finds from
 *                 the signal's stored business_fit and matched offer. Never guessed from a title or a keyword.
 *   org account   the organisation identity (g2g_org_accounts) that holds the signal's company, or the CRM organisation, as one of its source rows. Never made
 *                 here and never matched on a name: a lead from a free-typed form has no identity until a person links it.
 */
final class LeadLinks
{
    /** The Product Master row (product or sub-product) a signal supports, or null. @param object $signal a g2g_company_opportunities row or model */
    public static function productForSignal(int $tenantId, object $signal): ?int
    {
        if ($tenantId <= 0 || ! Schema::hasTable('g2g_product_master')) {
            return null;
        }

        $own = (int) ($signal->product_master_id ?? 0);
        if ($own > 0 && self::productVisible($tenantId, $own)) {
            return $own;
        }

        $hit = (new ProductResolver)->forSignal($tenantId, $signal->business_fit ?? null, $signal->offer_id ?? null);

        return $hit === null ? null : (int) $hit->id;
    }

    /** The identity that holds this Signals company, or null. */
    public static function orgAccountForCompany(int $tenantId, ?int $companyId): ?int
    {
        return self::identity($tenantId, 'g2g_company_id', $companyId);
    }

    /** The identity that holds this CRM organisation, or null. */
    public static function orgAccountForCrmOrganization(int $tenantId, ?int $crmOrganizationId): ?int
    {
        return self::identity($tenantId, 'crm_organization_id', $crmOrganizationId);
    }

    /**
     * Two organisation identities were merged (slice 3): the leads filed under the one that went away follow it to the one that stayed. Pointers are moved,
     * never nulled (the rule of every merge). Scoped to the organisation; returns how many leads moved. Call it from the identity merge, beside
     * AgentSubjects::repoint (OrgLinker::merge is slice 3's file and is not edited by slice 6: see the hand-over); until then leads:backfill-links re-resolves
     * a lead whose account is gone.
     */
    public static function repointAccount(int $tenantId, int $fromId, int $toId): int
    {
        // ColumnExists::all, not ::has: has() throws on the live MariaDB 10.1 until commit 02d4d896 is deployed (DB-RULES, defect D20).
        if ($tenantId <= 0 || $fromId <= 0 || $toId <= 0 || $fromId === $toId || ! in_array('org_account_id', \App\Support\ColumnExists::all('crm_leads'), true)) {
            return 0;
        }

        return DB::table('crm_leads')->where('sub_institute_id', $tenantId)->where('org_account_id', $fromId)->update(['org_account_id' => $toId]);
    }

    /** Whether the organisation may link this product: its own, or a shared one (tenant 0). An archived product still counts: a lead is filed under what it was about. */
    public static function productVisible(int $tenantId, int $productId): bool
    {
        return $tenantId > 0 && $productId > 0 && Schema::hasTable('g2g_product_master')
            && ProductMaster::query()->visibleTo($tenantId)->whereKey($productId)->exists();
    }

    /** Whether this identity is the organisation's own and not removed. */
    public static function orgAccountVisible(int $tenantId, int $orgAccountId): bool
    {
        return $tenantId > 0 && $orgAccountId > 0 && Schema::hasTable('g2g_org_accounts')
            && DB::table('g2g_org_accounts')->where('id', $orgAccountId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists();
    }

    private static function identity(int $tenantId, string $pointer, ?int $sourceId): ?int
    {
        if ($tenantId <= 0 || ($sourceId ?? 0) <= 0 || ! Schema::hasTable('g2g_org_accounts')) {
            return null;
        }

        $id = DB::table('g2g_org_accounts')->where('sub_institute_id', $tenantId)->where($pointer, $sourceId)->whereNull('deleted_at')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
