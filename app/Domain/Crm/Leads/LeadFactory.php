<?php

namespace App\Domain\Crm\Leads;

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

        return DB::table('crm_leads')->insertGetId($attributes);
    }

    private static function nextLeadNo(int $tenantId): string
    {
        $max = (int) DB::table('crm_leads')
            ->where('sub_institute_id', $tenantId)
            ->max(DB::raw("CAST(SUBSTRING_INDEX(lead_no, '-', -1) AS UNSIGNED)"));

        return 'LD-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }
}
