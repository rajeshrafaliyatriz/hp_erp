<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Crm\Contacts\ContactRoles;
use App\Domain\Crm\Sales\OpportunityRules;
use App\Domain\Money\Currencies;
use Illuminate\Support\Facades\DB;

/**
 * The optional choices of the one Convert Lead pop-up (S19, 25 Nov 2026), read from the request and checked against the data, so CrmLeadController::convert stays a thin door and
 * LeadConverter receives clean values. Every field is optional: a request that carries none of them converts exactly as it always did.
 *
 *   organizationName / industry   the name and industry of the NEW organization (default: the lead's company and industry)
 *   existingOrganizationId        attach to a live company of the caller's organisation instead of making one (createOrganization must then be false)
 *   makePrimary / contactRole     the star and the role of the person at that company (S18: ContactPrimary, ContactRoles); ignored when there is no company
 *   opportunity                   name, amount, currency, closeDate, stage of the NEW opportunity; honoured (and checked) only when an opportunity is made
 *   transferRelatedTo             organization | contact | opportunity: where the lead's comments, e-mails, documents and events move to (LeadRelatedTransfer); null moves nothing
 */
final class LeadConversionOptions
{
    public const TARGETS = ['organization', 'contact', 'opportunity'];

    /**
     * @param  array<string, mixed>  $input  the request's input, already shape-checked by the controller's validator
     * @return array{organization_name: ?string, industry: ?string, existing_organization_id: ?int, make_primary: bool, contact_role: ?string, opportunity: ?array<string, mixed>, transfer_to: ?string}
     */
    public static function fromInput(array $input): array
    {
        $text = function (string $key) use ($input): ?string {
            $v = $input[$key] ?? null;

            return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        };

        $opportunity = null;
        if (isset($input['opportunity']) && is_array($input['opportunity'])) {
            $o = $input['opportunity'];
            $pick = function (string $key) use ($o): mixed {
                $v = $o[$key] ?? null;

                return is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
            };
            $opportunity = [
                'name' => $pick('name'), 'amount' => $pick('amount'), 'currency' => $pick('currency'),
                'close_date' => $pick('closeDate'), 'stage' => $pick('stage'),
            ];
        }

        $existing = $input['existingOrganizationId'] ?? null;

        return [
            'organization_name' => $text('organizationName'),
            'industry' => $text('industry'),
            'existing_organization_id' => $existing !== null && $existing !== '' ? (int) $existing : null,
            'make_primary' => filter_var($input['makePrimary'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'contact_role' => $text('contactRole'),
            'opportunity' => $opportunity,
            'transfer_to' => ($t = $text('transferRelatedTo')) !== null ? strtolower($t) : null,
        ];
    }

    /**
     * The first thing wrong with the choices, as the body of a 422 answer; null when there is nothing wrong.
     *
     * @param  array<string, mixed>  $options  from fromInput()
     * @return ?array<string, mixed>
     */
    public static function problem(array $options, object $lead, int $tenant, bool $createOrganization, bool $createContact, ?int $existingContactId, bool $createOpportunity): ?array
    {
        $existingOrg = $options['existing_organization_id'];
        if ($existingOrg !== null) {
            if ($createOrganization) {
                return self::body('Choose a new organization or an existing one, not both.');
            }
            $live = DB::table('crm_organizations')->where('id', $existingOrg)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists();
            if (! $live) {
                return self::body('Choose a company from the list.');
            }
        }

        if ($options['contact_role'] !== null && ! in_array($options['contact_role'], ContactRoles::allowed(), true)) {
            return self::body('Choose a role from the list.');
        }

        if ($createOpportunity && $options['opportunity'] !== null) {
            if (($problem = self::opportunityProblem($options['opportunity'], $lead)) !== null) {
                return $problem;
            }
        }

        if ($options['transfer_to'] !== null) {
            $target = $options['transfer_to'];
            $organizationName = $options['organization_name'] ?? (trim((string) ($lead->company ?? '')) !== '' ? (string) $lead->company : null);
            $exists = match ($target) {
                'organization' => $existingOrg !== null || ($createOrganization && $organizationName !== null),
                'contact' => $createContact && ($existingContactId !== null || ! LeadConverter::hasNoPerson($lead)),
                'opportunity' => $createOpportunity,
                default => null,
            };
            if ($exists === null) {
                return self::body('Choose where to move the related records: the organization, the person or the opportunity.');
            }
            if ($exists === false) {
                return self::body('The related records cannot move to a record that this conversion does not create or attach to.');
            }
        }

        return null;
    }

    /**
     * The checks the opportunity form always had (CrmOpportunityController): a currency of the catalogue, no opportunity born Closed Lost (it would need a reason), and the fields a stage
     * asks for before it can be entered (config/crm_sales.php, empty by default).
     *
     * @param  array<string, mixed>  $opportunity
     * @return ?array<string, mixed>
     */
    private static function opportunityProblem(array $opportunity, object $lead): ?array
    {
        if ($opportunity['currency'] !== null && ! Currencies::isCode($opportunity['currency'])) {
            return self::body('Choose a currency from the list.');
        }

        $stage = $opportunity['stage'];
        if ($stage === null) {
            return null;
        }
        if (OpportunityRules::isClosedLost($stage)) {
            return ['status' => 0, 'code' => 'reason_required', 'message' => 'Say why this opportunity was lost - it is the only way the pipeline learns from it. A new opportunity cannot start as Closed Lost.'];
        }
        $missing = OpportunityRules::missing($stage, [
            'amount' => $opportunity['amount'], 'closing_date' => $opportunity['close_date'], 'probability' => null,
            'product_master_id' => $lead->product_master_id ?? null,
        ]);

        return $missing === [] ? null : OpportunityRules::missingBody($stage, $missing);
    }

    /** @return array{status: int, message: string} */
    private static function body(string $message): array
    {
        return ['status' => 0, 'message' => $message];
    }
}
