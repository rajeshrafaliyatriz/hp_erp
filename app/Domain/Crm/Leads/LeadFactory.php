<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use App\Support\ColumnExists;
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
        $attributes = self::withLinks($attributes, $tenantId);
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = LeadDescription::fit($attributes['description']);
        }

        $attributes['lead_no'] = LeadNumber::claim();
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

    /**
     * `product_master_id` and `org_account_id` are stored only when this host has the columns (the code reaches a server before its migration does) and
     * only when they are this organisation's to link: a product of its own or a shared one, an identity of its own. One that is not is dropped, never
     * stored, so a caller's mistake can never link a lead to another organisation's record.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function withLinks(array $attributes, int $tenantId): array
    {
        $present = null;
        foreach (['product_master_id' => [LeadLinks::class, 'productVisible'], 'org_account_id' => [LeadLinks::class, 'orgAccountVisible']] as $column => $visible) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }
            $present ??= ColumnExists::all('crm_leads');
            $value = (int) $attributes[$column];
            if (! in_array($column, $present, true) || $value <= 0 || ! $visible($tenantId, $value)) {
                unset($attributes[$column]);
            }
        }

        return $attributes;
    }
}
