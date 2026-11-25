<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Crm\Contacts\ContactPrimary;
use App\Domain\Crm\Sales\OpportunityStageHistory;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Money\Currencies;
use App\Support\ColumnExists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convert a lead into an Organization, a Contact and (optionally) an Opportunity.
 *
 * This is the body of CrmLeadController::convert(), moved here UNCHANGED so the controller (a person pressing Convert) and the
 * lead-automation workflow action (after an administrator approves) run the very same code - there is no second conversion
 * logic to drift. The controller keeps its own validation and identity handling and calls this.
 *
 * Slice 6 (the two changes since it was moved here): a lead that names no person (hasNoPerson: its last name is only the company's name, which is what a
 * lead promoted from a Signal starts with) makes NO placeholder contact, and the result says so (contact_skipped); and what the facts ledger knows about
 * the lead follows it to its contact (LeadFacts), beside the consent that already did (slice 4).
 *
 * Existing behaviour kept: an Organization with exactly the lead's company name in the tenant is reused rather than duplicated;
 * a Contact is never fuzzy-matched (a new one is made unless the caller names an existing one); the Opportunity is created with
 * no amount or stage for the owner to set; the lead is marked converted and keeps ids of everything it produced.
 */
final class LeadConverter
{
    /**
     * Whether the lead names no person: its last name is blank, or is only the company's name (what a lead promoted from a Signal starts with) or the
     * Signal Inbox's "Signal Lead", and there is no first name. A first name is a person. A last name that is not the company's is taken as a person too,
     * whether or not there is a first name: the lead form only requires the last name, and a contact made from it is what conversion has always done.
     */
    public static function hasNoPerson(object $lead): bool
    {
        $squash = fn ($v) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
        $last = $squash($lead->last_name ?? '');
        if ($last === '') {
            return true;
        }
        if (trim((string) ($lead->first_name ?? '')) !== '') {
            return false;
        }

        return $last === $squash($lead->company ?? '') || $last === 'signal lead';
    }

    /**
     * @param  array<string, mixed>  $options  S19, the optional choices of the Convert Lead pop-up, from LeadConversionOptions::fromInput() (organization name and industry, an existing organization, the primary star and role,
     *         the opportunity's amount / currency / close date / stage, and where to move the lead's related records). Empty: the conversion is exactly what it always was.
     * @return array{organization_id: ?int, contact_id: ?int, opportunity_id: ?int, contact_skipped: bool, facts_moved: int, primary_set?: bool, transferred?: array{to: string, id: int, comments: int, emails: int, documents: int, events: int}}
     *         contact_skipped: a contact was asked for but none was made, because the lead names no person (hasNoPerson). A contact named after the
     *         company would be a placeholder that looks like a person; the organization and the opportunity are made, the lead keeps what it knows.
     */
    public static function convert(object $lead, int $tenant, int $actorId, int $assignedTo, bool $createOrganization, bool $createContact, ?int $existingContactId, bool $createOpportunity, array $options = []): array
    {
        $organizationId = null;
        $contactId = null;
        $opportunityId = null;
        $contactSkipped = false;
        $factsMoved = 0;
        // S19: the optional choices of the one Convert Lead pop-up (LeadConversionOptions::fromInput). Empty for every other caller, which then converts exactly as before.
        $opts = $options + [
            'organization_name' => null, 'industry' => null, 'existing_organization_id' => null, 'make_primary' => false, 'contact_role' => null, 'opportunity' => null, 'transfer_to' => null,
        ];
        $organizationFresh = false;
        $contactFresh = false;
        $primarySet = false;
        $transferred = null;

        DB::transaction(function () use ($lead, $tenant, $actorId, $assignedTo, $createOrganization, $createContact, $existingContactId, $createOpportunity, $opts, &$organizationId, &$contactId, &$opportunityId, &$contactSkipped, &$factsMoved, &$organizationFresh, &$contactFresh, &$primarySet, &$transferred) {
            $organizationName = $opts['organization_name'] ?? ($lead->company ?: null);

            if ($opts['existing_organization_id'] !== null) {
                // The caller chose a company of this organisation (LeadConversionOptions::problem checked it): nothing is made and no name is matched.
                $organizationId = (int) $opts['existing_organization_id'];
            } elseif ($createOrganization && $organizationName) {
                $existingOrg = DB::table('crm_organizations')
                    ->where('sub_institute_id', $tenant)
                    ->where('name', $organizationName)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingOrg) {
                    $organizationId = $existingOrg->id;
                } else {
                    $organizationFresh = true;
                    $organizationId = DB::table('crm_organizations')->insertGetId([
                        'account_no' => 'ORG-'.Str::upper(Str::random(8)),
                        'name' => $organizationName,
                        'industry' => $opts['industry'] ?? $lead->industry,
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
                } elseif (self::hasNoPerson($lead)) {
                    $contactSkipped = true;
                } else {
                    $contactFresh = true;
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

            // S19: the person's star and role at the company (S18). Only a person who is in that company: the new one, or a chosen one whose company it is. Ignored when there is no company.
            if ($contactId && $organizationId && ($opts['make_primary'] || $opts['contact_role'] !== null)) {
                $inCompany = $contactFresh || DB::table('crm_contacts')->where('id', $contactId)->where('sub_institute_id', $tenant)->where('organization_id', $organizationId)->whereNull('deleted_at')->exists();
                $columns = $inCompany ? ColumnExists::all('crm_contacts') : [];
                if ($inCompany && $opts['contact_role'] !== null && in_array('contact_role', $columns, true)) {
                    DB::table('crm_contacts')->where('id', $contactId)->where('sub_institute_id', $tenant)->update(['contact_role' => $opts['contact_role'], 'updated_at' => now()]);
                }
                if ($inCompany && $opts['make_primary'] && in_array('is_primary', $columns, true)) {
                    ContactPrimary::make($tenant, (int) $contactId, (int) $organizationId);
                    $primarySet = true;
                }
            }

            // Slice 4 (defect D7): what the lead said about being contacted is not lost on conversion. An opt-out, a suppression and an opted-out preference are
            // carried to the contact, a NEW one or the existing one that was reused; an opt-in only where the contact has recorded nothing (never throws).
            if ($contactId) {
                app(\App\Domain\Facts\ConsentRecorder::class)->carryOverSafely($tenant, 'lead', (int) $lead->id, 'contact', (int) $contactId);
                // Slice 6: what the facts ledger knows about the lead follows it to the contact (never throws).
                $factsMoved = LeadFacts::followToContactSafely($tenant, (int) $lead->id, (int) $contactId);
            }

            if ($createOpportunity) {
                $dealName = $lead->company ? "{$lead->company} - Opportunity" : trim("{$lead->first_name} {$lead->last_name}").' - Opportunity';

                $details = $opts['opportunity'];
                $oppData = [
                    'opportunity_no' => 'OPP-'.Str::upper(Str::random(8)),
                    'name' => $details['name'] ?? $dealName,
                    'organization_id' => $organizationId,
                    'lead_source' => $lead->lead_source,
                    'sub_institute_id' => $tenant,
                    'assigned_to' => $assignedTo,
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (isset($lead->product_master_id) && $lead->product_master_id && ColumnExists::has('crm_opportunities', 'product_master_id')) {
                    $oppData['product_master_id'] = $lead->product_master_id;
                }

                if (isset($lead->campaign_id) && $lead->campaign_id && ColumnExists::has('crm_opportunities', 'campaign_id')) {
                    $oppData['campaign_id'] = $lead->campaign_id;
                }

                // S19: what the pop-up asked for (checked by LeadConversionOptions::problem). The currency is a catalogue code, or the organisation's default; the amount keeps that currency's decimals.
                if ($details !== null) {
                    $currency = Currencies::normalise($details['currency']) ?? Currencies::defaultFor($tenant);
                    $oppData['currency'] = $currency;
                    if ($details['amount'] !== null) {
                        $oppData['amount'] = round((float) $details['amount'], Currencies::decimals($currency));
                    }
                    if ($details['close_date'] !== null) {
                        $oppData['closing_date'] = Carbon::parse($details['close_date'])->toDateString();
                    }
                    if ($details['stage'] !== null) {
                        $oppData['sales_stage'] = $details['stage'];
                    }
                }

                $opportunityId = DB::table('crm_opportunities')->insertGetId($oppData);

                if (isset($oppData['sales_stage'])) {
                    OpportunityStageHistory::record((int) $opportunityId, null, $oppData['sales_stage'], $oppData['amount'] ?? null, null, $oppData['closing_date'] ?? null, $actorId);
                }

                if ($contactId) {
                    DB::table('crm_opportunity_contacts')->insert([
                        'opportunity_id' => $opportunityId,
                        'contact_id' => $contactId,
                        'created_at' => now(),
                    ]);
                }
            }

            // S19: "Transfer related records to": the lead's comments, e-mails, documents and events move to the record that was made or attached to, in this same transaction.
            if ($opts['transfer_to'] !== null) {
                [$targetId, $fresh] = match ($opts['transfer_to']) {
                    'organization' => [$organizationId, $organizationFresh],
                    'contact' => [$contactId, $contactFresh],
                    default => [$opportunityId, true],
                };
                if ($targetId) {
                    $transferred = ['to' => $opts['transfer_to'], 'id' => (int) $targetId] + LeadRelatedTransfer::move($tenant, (int) $lead->id, $opts['transfer_to'], (int) $targetId, $fresh);
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

        $result = [
            'organization_id' => $organizationId ? (int) $organizationId : null, 'contact_id' => $contactId ? (int) $contactId : null, 'opportunity_id' => $opportunityId ? (int) $opportunityId : null,
            'contact_skipped' => $contactSkipped, 'facts_moved' => $factsMoved,
        ];
        // The two keys below exist only when the pop-up asked for the thing, so every other caller (and the audit payload of a plain conversion) is unchanged.
        if ($opts['make_primary']) {
            $result['primary_set'] = $primarySet;
        }
        if ($transferred !== null) {
            $result['transferred'] = $transferred;
        }

        return $result;
    }

    /**
     * Say a lead was converted, so the post-conversion workflows can react - for a person pressing Convert and for the
     * automation alike. Recorded WITHOUT the "caused by a workflow" marker on purpose: it is a different event type from the
     * one that started the conversion, and the follow-on workflow must be allowed to see it. (A workflow must simply not be
     * configured to start from this event AND convert leads.)
     *
     * @param  array{organization_id: ?int, contact_id: ?int, opportunity_id: ?int, contact_skipped?: bool, facts_moved?: int, primary_set?: bool, transferred?: array<string, mixed>}  $result
     */
    public static function announce(int $tenant, object $lead, array $result, ?int $actorId): void
    {
        if ($result['opportunity_id']) {
            \App\Domain\Crm\Sales\OpportunityEvents::created($tenant, $result['opportunity_id'], $actorId, $result['organization_id'], true);
        }
        GtmAudit::record('crm.lead.converted', $tenant, 'crm_leads', (int) $lead->id, $actorId, $result + ['from_signal' => $lead->source_company_opportunity_id !== null], null, "crm.lead.converted:{$tenant}:{$lead->id}");
        // S19: one line for what moved from the lead to the record it was transferred to (the pop-up's "Transfer related records to").
        if (isset($result['transferred'])) {
            GtmAudit::record('crm.lead.related_transferred', $tenant, 'crm_leads', (int) $lead->id, $actorId, $result['transferred'], null, "crm.lead.related_transferred:{$tenant}:{$lead->id}");
        }
    }
}
