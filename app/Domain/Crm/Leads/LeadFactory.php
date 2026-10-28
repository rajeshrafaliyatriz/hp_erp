<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing unification, Phase 4: the lead-numbering + insert tail of
 * `CrmLeadController::store()`, extracted so the new Signals -> CRM promotion
 * path (`Signals\OpportunityController::promoteToCrm()`) does not duplicate
 * it. `store()`'s own camelCase-request-to-column `payload()` mapping stays
 * where it is - this only shares the part that mattered: the one place a
 * `lead_no` is minted.
 */
final class LeadFactory
{
    /**
     * @param  array<string, mixed>  $attributes  Already snake_case, CRM lead columns.
     * @return int the new crm_leads.id
     */
    public static function create(array $attributes, int $tenantId, int $createdBy): int
    {
        $attributes['lead_no'] = self::nextLeadNo($tenantId);
        $attributes['sub_institute_id'] = $tenantId;
        $attributes['created_by'] = $createdBy;
        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        $scored = LeadScorer::score($attributes, $tenantId);
        $attributes['lead_score'] = $scored['total'];
        $attributes['lead_score_basis'] = $scored['basis'] === [] ? null : json_encode($scored['basis']);

        $id = (int) DB::table('crm_leads')->insertGetId($attributes);

        // Every creation path (manual, CSV import, web form, Signals promotion) passes through here, so this is the one
        // place the lead-processing workflows are told a lead exists. Recording never fails the create (GtmAudit logs instead).
        GtmAudit::record('crm.lead.created', $tenantId, 'crm_leads', $id, $createdBy, [
            'lead_source' => $attributes['lead_source'] ?? null,
            'from_signal' => ! empty($attributes['source_company_opportunity_id']),
        ], null, "crm.lead.created:{$tenantId}:{$id}");

        return $id;
    }

    private static function nextLeadNo(int $tenantId): string
    {
        // crm_leads.lead_no carries a UNIQUE index across ALL tenants, so the sequence must be global too: numbering per tenant
        // made every second organization's first lead collide with LD-00001 and fail. (Soft-deleted rows are counted on purpose.)
        $max = (int) DB::table('crm_leads')
            ->max(DB::raw("CAST(SUBSTRING_INDEX(lead_no, '-', -1) AS UNSIGNED)"));

        return 'LD-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }
}
