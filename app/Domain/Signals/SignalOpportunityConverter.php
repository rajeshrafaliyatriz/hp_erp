<?php

namespace App\Domain\Signals;

use App\Domain\Crm\Leads\ConversionRecords;
use App\Domain\Crm\Leads\LeadConversionOptions;
use App\Domain\Crm\Leads\LeadLinks;
use App\Domain\Crm\Sales\OpportunityEvents;
use App\Domain\Gtm\ProspectAccounts;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Review\SignalReviewService;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;

/**
 * Convert a SIGNAL straight into an Opportunity (S20), with the same choices as the Convert Lead pop-up: an Organization (made, named and in an industry the signal's
 * company prefills, or an existing one), an OPTIONAL Person (an existing one, or a new one) with the star and the role, and the Opportunity (name, amount, currency,
 * close date, stage) for an owner.
 *
 * It writes the records through Crm\Leads\ConversionRecords, the class Convert Lead (LeadConverter) writes them through, so the two never drift. The opportunity is
 * linked back to the signal (`crm_opportunities.source_company_opportunity_id`), which is what makes it idempotent (ONE opportunity per signal, under the signal's row lock)
 * and what lets the signal say "Opportunity: <name>". In the same transaction the signal's history says "Converted to opportunity", and the company's Prospecting account
 * (made if it is missing) moves to the opportunity stage (never down) and is linked to the organization (`gtm_accounts.crm_organization_id`, when empty).
 */
final class SignalOpportunityConverter
{
    /** Does this host have the link column yet? (The code reaches a server before its migration does.) */
    public static function ready(): bool
    {
        return in_array('source_company_opportunity_id', ColumnExists::all('crm_opportunities'), true);
    }

    /**
     * @param  array<string, mixed>  $input  the request's input, already shape-checked
     * @return array{http: int, body: array<string, mixed>}
     */
    public static function convert(CompanyOpportunity $signal, int $tenant, int $actorId, array $input): array
    {
        if (! self::ready()) {
            return self::refuse(503, 'Converting a signal to an opportunity needs a database update that has not been run on this server yet.', 'not_ready');
        }
        $company = $signal->company;
        $companyName = trim((string) ($company->name ?? ''));
        $options = LeadConversionOptions::fromInput($input);

        $assignedTo = isset($input['assignedTo']) && $input['assignedTo'] !== '' ? (int) $input['assignedTo'] : $actorId;
        if (! DB::table('tbluser')->where('id', $assignedTo)->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            return self::refuse(422, 'Choose a user of this organisation to own the opportunity.');
        }

        $existingContactId = ($input['existingContactId'] ?? null) !== null && $input['existingContactId'] !== '' ? (int) $input['existingContactId'] : null;
        if ($existingContactId !== null && ! DB::table('crm_contacts')->where('id', $existingContactId)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists()) {
            return self::refuse(422, 'That existing Contact was not found.');
        }
        $person = self::personFrom($input);
        if ($existingContactId !== null && $person !== null) {
            return self::refuse(422, 'Choose an existing person or add a new one, not both.');
        }
        if ($person !== null && ($person['last_name'] ?? '') === '') {
            return self::refuse(422, 'A new person needs a last name.');
        }

        $organizationName = $options['organization_name'] ?? ($companyName !== '' ? $companyName : null);
        if ($options['existing_organization_id'] === null && $organizationName === null) {
            return self::refuse(422, 'Name the organization.');
        }

        $productId = LeadLinks::productForSignal($tenant, $signal);
        $lead = (object) ['company' => $organizationName, 'product_master_id' => $productId];
        if (($problem = LeadConversionOptions::problem($options, $lead, $tenant, $options['existing_organization_id'] === null, $existingContactId !== null || $person !== null, $existingContactId, true)) !== null) {
            return ['http' => 422, 'body' => $problem];
        }

        return DB::transaction(function () use ($signal, $tenant, $actorId, $assignedTo, $options, $existingContactId, $person, $organizationName, $companyName, $productId, $company) {
            // One opportunity per signal, however many times (or at once) this is pressed: the signal's row is locked for the check-and-create.
            $locked = \App\Domain\Signals\Opportunities\CompanyOpportunity::where('sub_institute_id', $tenant)->whereKey($signal->id)->lockForUpdate()->first();
            if (! $locked) {
                return self::refuse(404, 'Opportunity not found');
            }
            $existing = DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->where('source_company_opportunity_id', $signal->id)->whereNull('deleted_at')->orderBy('id')->first(['id', 'name', 'organization_id']);
            if ($existing) {
                return ['http' => 200, 'body' => ['status' => 1, 'data' => ['opportunity_id' => (int) $existing->id, 'opportunity_name' => $existing->name, 'organization_id' => $existing->organization_id ? (int) $existing->organization_id : null, 'created' => false]]];
            }

            $organizationFresh = false;
            if ($options['existing_organization_id'] !== null) {
                $organizationId = (int) $options['existing_organization_id'];
            } else {
                $made = ConversionRecords::organization($tenant, $actorId, $assignedTo, (string) $organizationName, [
                    'industry' => $options['industry'] ?? ($company->industry ?? null),
                    'website' => ($company->website ?? null) ? mb_substr((string) $company->website, 0, 191) : null,
                ]);
                $organizationId = $made['id'];
                $organizationFresh = $made['fresh'];
            }

            $contactId = $existingContactId;
            $contactFresh = false;
            if ($contactId === null && $person !== null) {
                $contactFresh = true;
                $contactId = ConversionRecords::contact($tenant, $actorId, $assignedTo, $organizationId, $person + ['lead_source' => 'Signals']);
            }
            $primarySet = ConversionRecords::personAtCompany($tenant, $contactId, $organizationId, $contactFresh, (bool) $options['make_primary'], $options['contact_role']);

            $title = trim((string) $signal->title);
            $defaultName = mb_substr($companyName !== '' ? ($title !== '' ? "{$companyName} - {$title}" : "{$companyName} - Opportunity") : ($title !== '' ? $title : 'Opportunity'), 0, 191);
            $opportunityId = ConversionRecords::opportunity(
                $tenant, $actorId, $assignedTo, $defaultName, $organizationId, 'Signals', $options['opportunity'],
                ['product_master_id' => $productId, 'source_company_opportunity_id' => (int) $signal->id], $contactId,
            );
            $name = (string) DB::table('crm_opportunities')->where('id', $opportunityId)->value('name');

            app(SignalReviewService::class)->recordConvertedToOpportunity($tenant, (int) $signal->id, $actorId, $opportunityId);
            OpportunityEvents::created($tenant, $opportunityId, $actorId, $organizationId, false);

            // The company's Prospecting account (made if it is missing) is an opportunity now, and knows which organization it became.
            $account = ProspectAccounts::forSignal($tenant, $signal, $actorId);
            if ($account !== null) {
                if (empty($account->crm_organization_id ?? null) && in_array('crm_organization_id', ColumnExists::all('gtm_accounts'), true)) {
                    $account->crm_organization_id = $organizationId;
                    $account->save();
                }
                ProspectAccounts::advance($account, 'opportunity', $actorId);
            }

            return ['http' => 201, 'body' => ['status' => 1, 'data' => [
                'opportunity_id' => $opportunityId, 'opportunity_name' => $name, 'organization_id' => $organizationId, 'organization_created' => $organizationFresh,
                'contact_id' => $contactId, 'primary_set' => $primarySet, 'account_id' => $account?->id ? (int) $account->id : null, 'created' => true,
            ]]];
        });
    }

    /**
     * The new person asked for, as crm_contacts columns, or null when none was asked for. A person is "asked for" when any of the five fields is filled in.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, ?string>|null
     */
    private static function personFrom(array $input): ?array
    {
        $p = is_array($input['person'] ?? null) ? $input['person'] : [];
        $clean = fn (string $key): ?string => isset($p[$key]) && is_scalar($p[$key]) && trim((string) $p[$key]) !== '' ? trim((string) $p[$key]) : null;
        $row = ['first_name' => $clean('firstName'), 'last_name' => $clean('lastName'), 'email' => $clean('email'), 'phone' => $clean('phone'), 'title' => $clean('title')];

        return count(array_filter($row, fn ($v) => $v !== null)) === 0 ? null : ['first_name' => $row['first_name'], 'last_name' => $row['last_name'] ?? '', 'email' => $row['email'], 'phone' => $row['phone'], 'title' => $row['title']];
    }

    /** @return array{http: int, body: array<string, mixed>} */
    private static function refuse(int $http, string $message, ?string $code = null): array
    {
        return ['http' => $http, 'body' => array_filter(['status' => 0, 'code' => $code, 'message' => $message], fn ($v) => $v !== null)];
    }
}
