<?php

namespace App\Domain\Signals\Outreach;

use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadLinks;
use App\Domain\Crm\Leads\LeadSignalEvidence;
use App\Domain\Crm\Records\RecordNote;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Review\SignalReviewService;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;

/**
 * "Convert to lead" from the Outreach tab: a company (and the person chosen at it) becomes ONE CRM lead. Unlike the signal card's button, the lead is about a PERSON when one is
 * chosen (their name, title, email and phone), so it can be worked straight away; with nobody chosen it is named after the company, as the signal's own lead is.
 *
 * One lead per person (or per company when no person is chosen), however many times or at once this is pressed: the person's (or company's) row is locked for the length of the check,
 * and a second press finds the lead the first made. If a signal of the company already became a lead, that lead is the answer and no second one is made. The person's opt-outs travel
 * with the lead, so the CRM's own consent checks honour them. No organization, contact or opportunity is created: those come later, when a customer converts.
 */
final class OutreachLeadConverter
{
    public function __construct(private readonly SignalReviewService $reviews)
    {
    }

    /** @return array{ok: true, lead_id: int, created: bool}|array{ok: false, http: int, code: string, message: string} */
    public function convert(int $tenant, int $actor, int $companyId, ?int $personId): array
    {
        $company = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $companyId)->whereNull('deleted_at')->first();
        if (! $company) {
            return ['ok' => false, 'http' => 404, 'code' => 'not_found', 'message' => 'Company not found.'];
        }
        $person = null;
        if ($personId !== null) {
            $person = DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $companyId)->where('id', $personId)->whereNull('deleted_at')->first();
            if (! $person) {
                return ['ok' => false, 'http' => 404, 'code' => 'person_not_found', 'message' => 'That person is not listed for this company.'];
            }
        } else {
            $person = $this->bestPerson($tenant, $companyId);
        }

        return DB::transaction(function () use ($tenant, $actor, $company, $person) {
            // The row the answer is keyed on is locked, so a second press waits and then finds the first one's lead.
            $keyed = $person
                ? DB::table('g2g_signal_contacts')->where('id', $person->id)->lockForUpdate()->first(['id', 'lead_id'])
                : DB::table('g2g_companies')->where('id', $company->id)->lockForUpdate()->first(['id', 'lead_id']);
            if ($keyed && ($existing = $this->aliveLead($tenant, (int) $keyed->lead_id))) {
                return ['ok' => true, 'lead_id' => $existing, 'created' => false];
            }
            $signal = CompanyOpportunity::where('sub_institute_id', $tenant)->where('company_id', $company->id)->orderByDesc('id')->first();
            if ($signal) {
                $already = DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('source_company_opportunity_id', $signal->id)->whereNull('deleted_at')->value('id');
                if ($already) {
                    $this->remember($tenant, $person, $company, (int) $already);

                    return ['ok' => true, 'lead_id' => (int) $already, 'created' => false];
                }
            }
            $leadId = LeadFactory::create($this->attributes($tenant, $actor, $company, $person, $signal), $tenant, $actor);
            $this->remember($tenant, $person, $company, $leadId);
            if ($signal) {
                // Same history line and the same one note a signal's own "Convert to lead" writes, so the signal says where its lead came from.
                $this->reviews->recordPromotion($tenant, (int) $signal->id, $actor, $leadId);
                (new RecordNote)->record($tenant, 'lead', $leadId, $actor, LeadSignalEvidence::noteText(LeadSignalEvidence::of($signal), LeadSignalEvidence::productName(LeadLinks::productForSignal($tenant, $signal))));
            } else {
                (new RecordNote)->record($tenant, 'lead', $leadId, $actor, 'Added to the CRM from Outreach (Market Signals). The company was added by hand.');
            }

            return ['ok' => true, 'lead_id' => $leadId, 'created' => true];
        });
    }

    /** @return array<string, mixed> only columns this host has (the code can reach a server before its migration does) */
    private function attributes(int $tenant, int $actor, object $company, ?object $person, ?CompanyOpportunity $signal): array
    {
        $name = $person ? trim((string) $person->full_name) : '';
        $parts = $name === '' ? [] : (preg_split('/\s+/u', $name) ?: []);
        $summary = $signal ? trim(implode(' ', array_filter([$signal->observed_event, $signal->why_indicates_need, $signal->recommended_action]))) : '';
        $attrs = [
            'first_name' => count($parts) > 1 ? mb_substr(array_shift($parts), 0, 191) : null,
            'last_name' => mb_substr($parts !== [] ? implode(' ', $parts) : (string) $company->name, 0, 191),
            'company' => mb_substr((string) $company->name, 0, 191),
            'designation' => $person && $person->designation ? mb_substr((string) $person->designation, 0, 191) : null,
            'email' => $person ? OutreachReach::emailOf($person) : ($company->email ?: null),
            'phone' => $person ? ($person->phone ?: null) : ($company->phone ?: null),
            'mobile' => $person && $person->mobile ? $person->mobile : null,
            'website' => $company->website ? mb_substr((string) $company->website, 0, 191) : null,
            'industry' => $company->industry ?: null,
            'lead_source' => $signal ? 'Signals' : 'Self Generated',
            'lead_status' => 'New',
            'description' => $summary !== '' ? $summary : ($signal->title ?? ($company->description ?: null)),
            'source_company_opportunity_id' => $signal?->id,
            'assigned_to' => $actor,
            'product_master_id' => $signal ? LeadLinks::productForSignal($tenant, $signal) : null,
            'org_account_id' => LeadLinks::orgAccountForCompany($tenant, (int) $company->id),
        ];
        if ($person) {
            // What the person said about being contacted goes with the lead, so the CRM's consent checks honour it.
            $attrs['email_opt_out'] = (bool) $person->email_opt_out || in_array((string) $person->consent_status, OutreachReach::CONSENT_BLOCKING, true) ? 1 : 0;
            $attrs['consent_status'] = $person->consent_status ?: 'unknown';
            $attrs['suppressed_at'] = $person->suppressed_at;
            $attrs['suppressed_reason'] = $person->suppressed_reason;
        }
        $present = ColumnExists::all('crm_leads');

        return array_filter(array_intersect_key($attrs, array_flip($present)), fn ($v) => $v !== null);
    }

    /** The person to make the lead about when none was chosen: the primary one, else the most complete one who is not ruled out; null when nobody can be named. */
    private function bestPerson(int $tenant, int $companyId): ?object
    {
        $best = null;
        $bestScore = -1;
        foreach (DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('company_id', $companyId)->whereNull('deleted_at')->get() as $p) {
            if (OutreachReach::forPerson($p) === OutreachReach::BLOCKED) {
                continue;
            }
            $score = ((int) $p->is_primary * 100) + ($p->full_name ? 20 : 0) + ($p->email ? 4 : 0) + ($p->phone ? 4 : 0) + ($p->designation ? 1 : 0);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$p, $score];
            }
        }

        return $best;
    }

    private function aliveLead(int $tenant, int $leadId): ?int
    {
        return $leadId > 0 && DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('id', $leadId)->whereNull('deleted_at')->exists() ? $leadId : null;
    }

    /** The loose pointer from the person (or the company) to its lead, which is what makes a second press find the same lead. */
    private function remember(int $tenant, ?object $person, object $company, int $leadId): void
    {
        if ($person) {
            DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $person->id)->update(['lead_id' => $leadId, 'updated_at' => now()]);
        } else {
            DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $company->id)->update(['lead_id' => $leadId, 'updated_at' => now()]);
        }
    }
}
