<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Convert a lead into an Organization, a Contact and (optionally) an Opportunity.
 *
 * This is the body of CrmLeadController::convert(), moved here UNCHANGED so the controller (a person pressing Convert) and the
 * lead-automation workflow action (after an administrator approves) run the very same code - there is no second conversion
 * logic to drift. The controller keeps its own validation and identity handling and calls this.
 *
 * Existing behaviour kept: an Organization with exactly the lead's company name in the tenant is reused rather than duplicated;
 * a Contact is never fuzzy-matched (a new one is made unless the caller names an existing one); the Opportunity is created with
 * no amount or stage for the owner to set; the lead is marked converted and keeps ids of everything it produced.
 */
final class LeadConverter
{
    /**
     * @return array{organization_id: ?int, contact_id: ?int, opportunity_id: ?int}
     */
    public static function convert(object $lead, int $tenant, int $actorId, int $assignedTo, bool $createOrganization, bool $createContact, ?int $existingContactId, bool $createOpportunity): array
    {
        $organizationId = null;
        $contactId = null;
        $opportunityId = null;

        DB::transaction(function () use ($lead, $tenant, $actorId, $assignedTo, $createOrganization, $createContact, $existingContactId, $createOpportunity, &$organizationId, &$contactId, &$opportunityId) {
            if ($createOrganization && $lead->company) {
                $existingOrg = DB::table('crm_organizations')
                    ->where('sub_institute_id', $tenant)
                    ->where('name', $lead->company)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingOrg) {
                    $organizationId = $existingOrg->id;
                } else {
                    $organizationId = DB::table('crm_organizations')->insertGetId([
                        'account_no' => 'ORG-'.Str::upper(Str::random(8)),
                        'name' => $lead->company,
                        'industry' => $lead->industry,
                        'phone' => $lead->phone,
                        'fax' => $lead->fax,
                        'rating' => $lead->rating,
                        'website' => $lead->website,
                        'billing_city' => $lead->city,
                        'billing_state' => $lead->state,
                        'billing_country' => $lead->country,
                        'billing_code' => $lead->postal_code,
                        'billing_street' => $lead->street,
                        'billing_po_box' => $lead->po_box,
                        'sub_institute_id' => $tenant,
                        'assigned_to' => $assignedTo,
                        'created_by' => $actorId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($createContact) {
                if ($existingContactId !== null) {
                    $contactId = $existingContactId;
                } else {
                    $contactId = DB::table('crm_contacts')->insertGetId([
                        'contact_no' => 'CON-'.Str::upper(Str::random(8)),
                        'organization_id' => $organizationId,
                        'salutation' => $lead->salutation,
                        'first_name' => $lead->first_name,
                        'last_name' => $lead->last_name,
                        'title' => $lead->designation ?? null,
                        'email' => $lead->email,
                        'secondary_email' => $lead->secondary_email,
                        'phone' => $lead->phone,
                        'mobile' => $lead->mobile,
                        'fax' => $lead->fax,
                        'lead_source' => $lead->lead_source,
                        'email_opt_out' => $lead->email_opt_out,
                        'mailing_city' => $lead->city,
                        'mailing_state' => $lead->state,
                        'mailing_country' => $lead->country,
                        'mailing_code' => $lead->postal_code,
                        'mailing_street' => $lead->street,
                        'mailing_po_box' => $lead->po_box,
                        'description' => $lead->description,
                        'sub_institute_id' => $tenant,
                        'assigned_to' => $assignedTo,
                        'created_by' => $actorId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($createOpportunity) {
                $dealName = $lead->company ? "{$lead->company} - Opportunity" : trim("{$lead->first_name} {$lead->last_name}").' - Opportunity';

                $opportunityId = DB::table('crm_opportunities')->insertGetId([
                    'opportunity_no' => 'OPP-'.Str::upper(Str::random(8)),
                    'name' => $dealName,
                    'organization_id' => $organizationId,
                    'lead_source' => $lead->lead_source,
                    'sub_institute_id' => $tenant,
                    'assigned_to' => $assignedTo,
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($contactId) {
                    DB::table('crm_opportunity_contacts')->insert([
                        'opportunity_id' => $opportunityId,
                        'contact_id' => $contactId,
                        'created_at' => now(),
                    ]);
                }
            }

            DB::table('crm_leads')->where('id', $lead->id)->update([
                'converted' => true,
                'converted_organization_id' => $organizationId,
                'converted_contact_id' => $contactId,
                'converted_opportunity_id' => $opportunityId,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
        });

        return ['organization_id' => $organizationId ? (int) $organizationId : null, 'contact_id' => $contactId ? (int) $contactId : null, 'opportunity_id' => $opportunityId ? (int) $opportunityId : null];
    }

    /**
     * Say a lead was converted, so the post-conversion workflows can react - for a person pressing Convert and for the
     * automation alike. Recorded WITHOUT the "caused by a workflow" marker on purpose: it is a different event type from the
     * one that started the conversion, and the follow-on workflow must be allowed to see it. (A workflow must simply not be
     * configured to start from this event AND convert leads.)
     *
     * @param  array{organization_id: ?int, contact_id: ?int, opportunity_id: ?int}  $result
     */
    public static function announce(int $tenant, object $lead, array $result, ?int $actorId): void
    {
        if ($result['opportunity_id']) {
            \App\Domain\Crm\Sales\OpportunityEvents::created($tenant, $result['opportunity_id'], $actorId, $result['organization_id'], true);
        }
        GtmAudit::record('crm.lead.converted', $tenant, 'crm_leads', (int) $lead->id, $actorId, $result + ['from_signal' => $lead->source_company_opportunity_id !== null], null, "crm.lead.converted:{$tenant}:{$lead->id}");
    }
}
