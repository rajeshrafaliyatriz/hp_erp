<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Crm\Contacts\ContactPrimary;
use App\Domain\Crm\Sales\OpportunityStageHistory;
use App\Domain\Money\Currencies;
use App\Support\ColumnExists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The three records a conversion makes (S19, shared in S20): an Organization, a Person (crm_contacts) and an Opportunity. ONE place writes them, for Convert Lead
 * (LeadConverter) and for a signal's Convert to opportunity (Signals\SignalOpportunityConverter), so the two can never drift apart: the same numbers, the same
 * exact-name reuse of an organization, the same star and role of the person, the same currency and stage-history rules for the opportunity.
 * Everything runs inside the caller's transaction; nothing here opens one.
 */
final class ConversionRecords
{
    /**
     * An organization with exactly this name in the tenant is reused rather than duplicated; otherwise a new one is made.
     *
     * @param  array<string, mixed>  $attributes  more crm_organizations columns (industry, phone, website, billing_*, ...)
     * @return array{id: int, fresh: bool}
     */
    public static function organization(int $tenant, int $actorId, int $assignedTo, string $name, array $attributes = []): array
    {
        $existing = DB::table('crm_organizations')->where('sub_institute_id', $tenant)->where('name', $name)->whereNull('deleted_at')->first();
        if ($existing) {
            return ['id' => (int) $existing->id, 'fresh' => false];
        }

        $id = DB::table('crm_organizations')->insertGetId($attributes + [
            'account_no' => 'ORG-'.Str::upper(Str::random(8)),
            'name' => $name,
            'sub_institute_id' => $tenant,
            'assigned_to' => $assignedTo,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => (int) $id, 'fresh' => true];
    }

    /**
     * A new person. A person is never fuzzy-matched: the caller names an existing one instead of asking for a new one.
     *
     * @param  array<string, mixed>  $attributes  the crm_contacts columns of the person (first_name, last_name, title, email, phone, ...)
     */
    public static function contact(int $tenant, int $actorId, int $assignedTo, ?int $organizationId, array $attributes): int
    {
        return (int) DB::table('crm_contacts')->insertGetId($attributes + [
            'contact_no' => 'CON-'.Str::upper(Str::random(8)),
            'organization_id' => $organizationId,
            'sub_institute_id' => $tenant,
            'assigned_to' => $assignedTo,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The person's star and role at the company (S18). Only a person who is in that company: a new one, or a chosen one whose company it is. Ignored with no company.
     *
     * @return bool whether the star was set
     */
    public static function personAtCompany(int $tenant, ?int $contactId, ?int $organizationId, bool $contactFresh, bool $makePrimary, ?string $role): bool
    {
        if (! $contactId || ! $organizationId || (! $makePrimary && $role === null)) {
            return false;
        }
        $inCompany = $contactFresh || DB::table('crm_contacts')->where('id', $contactId)->where('sub_institute_id', $tenant)->where('organization_id', $organizationId)->whereNull('deleted_at')->exists();
        $columns = $inCompany ? ColumnExists::all('crm_contacts') : [];
        if ($inCompany && $role !== null && in_array('contact_role', $columns, true)) {
            DB::table('crm_contacts')->where('id', $contactId)->where('sub_institute_id', $tenant)->update(['contact_role' => $role, 'updated_at' => now()]);
        }
        if ($inCompany && $makePrimary && in_array('is_primary', $columns, true)) {
            ContactPrimary::make($tenant, $contactId, $organizationId);

            return true;
        }

        return false;
    }

    /**
     * The opportunity. `$details` is what the pop-up asked for (LeadConversionOptions shape: name, amount, currency, close_date, stage), already checked; null makes the
     * opportunity with no amount or stage for the owner to set. `$links` are the optional pointers (product_master_id, campaign_id, source_company_opportunity_id), each stored only
     * when this host has the column and a value is given.
     *
     * @param  array<string, mixed>|null  $details
     * @param  array<string, int|null>  $links
     */
    public static function opportunity(int $tenant, int $actorId, int $assignedTo, string $defaultName, ?int $organizationId, ?string $leadSource, ?array $details, array $links = [], ?int $contactId = null): int
    {
        $data = [
            'opportunity_no' => 'OPP-'.Str::upper(Str::random(8)),
            'name' => $details['name'] ?? $defaultName,
            'organization_id' => $organizationId,
            'lead_source' => $leadSource,
            'sub_institute_id' => $tenant,
            'assigned_to' => $assignedTo,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ($links as $column => $value) {
            if ($value && ColumnExists::has('crm_opportunities', $column)) {
                $data[$column] = $value;
            }
        }

        // What the pop-up asked for. The currency is a catalogue code, or the organisation's default; the amount keeps that currency's decimals.
        if ($details !== null) {
            $currency = Currencies::normalise($details['currency']) ?? Currencies::defaultFor($tenant);
            $data['currency'] = $currency;
            if ($details['amount'] !== null) {
                $data['amount'] = round((float) $details['amount'], Currencies::decimals($currency));
            }
            if ($details['close_date'] !== null) {
                $data['closing_date'] = Carbon::parse($details['close_date'])->toDateString();
            }
            if ($details['stage'] !== null) {
                $data['sales_stage'] = $details['stage'];
            }
        }

        $id = (int) DB::table('crm_opportunities')->insertGetId($data);

        if (isset($data['sales_stage'])) {
            OpportunityStageHistory::record($id, null, $data['sales_stage'], $data['amount'] ?? null, null, $data['closing_date'] ?? null, $actorId);
        }

        if ($contactId) {
            DB::table('crm_opportunity_contacts')->insert(['opportunity_id' => $id, 'contact_id' => $contactId, 'created_at' => now()]);
        }

        return $id;
    }
}
