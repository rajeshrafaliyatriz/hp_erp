<?php

namespace App\Domain\Gtm;

use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadLinks;
use App\Domain\Crm\Leads\LeadSignalEvidence;
use App\Domain\Crm\Records\RecordNote;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Review\SignalReviewService;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The step AFTER prospecting (S20): a person at a target account is qualified as a LEAD. The funnel is Signal -> Prospecting (the account and its people)
 * -> Lead (one person) -> Convert Lead (organisation + person + opportunity); a lead is never made straight from a signal any more.
 *
 * ONE lead per person (account, contact), made through LeadFactory like every other lead, so it names the PERSON (first and last name from the contact),
 * carries the contact's email, phone and title, and, when the account's company has a live signal, the strongest one's summary, its Evidence tab
 * (`source_company_opportunity_id`), its one structured note and the product and organisation identity it supports. The lead is linked back to where it
 * came from by `gtm_account_id` and `gtm_contact_id` (two nullable columns on crm_leads; nothing existing can say WHICH person a lead was made for).
 *
 * Everything that must stand together happens in one transaction: the lead, its note, the signal's history line, the account's move from target to
 * engaged and the lines on the account's timeline. Idempotent: a second call for the same person answers the lead already made.
 */
final class ProspectLeads
{
    public const SOURCE = 'Prospecting';

    /** @var array<string, string> the contact statuses that may not become a lead => the sentence the person sees */
    private const REFUSED = [
        'do_not_contact' => 'This person asked not to be contacted, so a lead cannot be made for them.',
        'unsubscribed' => 'This person has unsubscribed, so a lead cannot be made for them.',
        'bounced' => 'Email to this person bounced, so a lead cannot be made for them until the address is fixed.',
    ];

    /** Does this host have the two link columns yet? (The code reaches a server before its migration does.) */
    public static function ready(): bool
    {
        return array_diff(['gtm_account_id', 'gtm_contact_id'], ColumnExists::all('crm_leads')) === [];
    }

    /**
     * @return array{http: int, body: array<string, mixed>}
     */
    public static function qualify(int $tenant, int $userId, int $accountId, int $contactId, ?int $assignedTo): array
    {
        $account = GtmAccount::where('sub_institute_id', $tenant)->find($accountId);
        if (! $account) {
            return self::refuse(404, 'Account not found');
        }
        $contact = GtmContact::where('sub_institute_id', $tenant)->where('account_id', $accountId)->find($contactId);
        if (! $contact) {
            return self::refuse(404, 'Person not found on this account');
        }
        $assignee = $assignedTo ?? $userId;
        if (! DB::table('tbluser')->where('id', $assignee)->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            return self::refuse(422, 'Choose a user of this organisation to assign the lead to.');
        }
        if (! self::ready()) {
            return self::refuse(503, 'Leads from Prospecting need a database update that has not been run on this server yet.', 'not_ready');
        }

        return DB::transaction(function () use ($tenant, $userId, $accountId, $contact, $assignee) {
            // The account's row is locked for the length of check-and-create, so a second press waits, then finds the lead the first one made.
            $account = GtmAccount::where('sub_institute_id', $tenant)->whereKey($accountId)->lockForUpdate()->first();
            if (! $account) {
                return self::refuse(404, 'Account not found');
            }
            $existing = DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('gtm_contact_id', $contact->id)->whereNull('deleted_at')->orderBy('id')->first(['id', 'lead_no']);
            if ($existing) {
                return ['http' => 200, 'body' => ['status' => 1, 'data' => ['lead_id' => (string) $existing->id, 'lead_no' => $existing->lead_no, 'created' => false, 'stage' => $account->stage]]];
            }
            if (($sentence = self::refusalFor($contact)) !== null) {
                return self::refuse(422, $sentence, 'contact_not_contactable');
            }

            $signal = self::strongestSignal($tenant, $account);
            $made = self::create($tenant, $userId, $assignee, $account, $contact, $signal);
            $leadId = $made['id'];
            $leadNo = $made['no'];

            return ['http' => 201, 'body' => ['status' => 1, 'data' => ['lead_id' => (string) $leadId, 'lead_no' => $leadNo, 'created' => true, 'stage' => $account->stage]]];
        });
    }

    /**
     * The ONE place a lead that comes out of Prospecting or out of a signal is built, for qualify() (a person at an account) and for a signal's Convert to lead
     * (Signals\OpportunityController::promoteToCrm, with or without a person). Made through LeadFactory like every other lead.
     *
     *   with a person   first and last name, email, phone and title come from the contact; the company is the account's; the lead is Prospecting-sourced
     *   without one     the lead is named after the company, exactly as a lead from a signal always was (LeadConverter::hasNoPerson copes with it); sourced "Signals"
     *
     * With a signal the lead carries its summary, its Evidence tab (`source_company_opportunity_id`), the product and organisation identity it supports, the signal's
     * history line and ONE structured note. With an account the lead is linked back to it (`gtm_account_id`, `gtm_contact_id`), the account's timeline says so and the account
     * moves from target to engaged (never down, never off a later stage). Call it inside the caller's transaction: everything stands together or not at all.
     *
     * @return array{id: int, no: ?string}
     */
    public static function create(int $tenant, int $userId, int $assignee, ?GtmAccount $account, ?GtmContact $contact, ?CompanyOpportunity $signal): array
    {
        $company = $signal?->company;
        $productId = $signal ? LeadLinks::productForSignal($tenant, $signal) : null;
        $productId ??= LeadLinks::productForKey($tenant, $account?->product_key);
        $companyId = $account?->company_id ?: ($signal?->company_id);
        $orgAccountId = LeadLinks::orgAccountForCompany($tenant, $companyId ? (int) $companyId : null)
            ?? ($account ? LeadLinks::orgAccountForGtmAccount($tenant, (int) $account->id) : null);

        if ($contact !== null && $account !== null) {
            [$first, $last] = self::splitName((string) $contact->full_name);
            $attributes = [
                'first_name' => $first,
                'last_name' => $last,
                'company' => mb_substr((string) $account->name, 0, 191),
                'designation' => self::clip($contact->title, 191),
                'email' => self::clip($contact->email, 191),
                'phone' => self::clip($contact->phone, 50),
                'website' => self::clip($account->website ?: ($company->website ?? null), 191),
                'industry' => self::clip($account->industry ?: ($company->industry ?? null), 150),
                'lead_source' => self::SOURCE,
                'description' => $signal ? LeadSignalEvidence::summary($signal) : 'Qualified in Prospecting from the account ' . $account->name . '.',
            ];
        } else {
            // A lead that names no person: its last name and company are both the company's name (what a lead from a signal has always been).
            $companyName = $company->name ?? $account?->name ?? (string) ($signal?->product_name ?: 'Unnamed company');
            $summary = $signal ? LeadSignalEvidence::summary($signal) : '';
            $attributes = [
                'last_name' => mb_substr($companyName, 0, 191),
                'company' => mb_substr($companyName, 0, 191),
                'designation' => $signal?->likely_stakeholder ? mb_substr($signal->likely_stakeholder, 0, 191) : null,
                'website' => ($company->website ?? null) ? mb_substr($company->website, 0, 191) : null,
                'industry' => $company->industry ?? null,
                'lead_source' => 'Signals',
                'description' => $summary !== '' ? $summary : $signal?->title,
            ];
        }
        $attributes += [
            'lead_status' => 'New',
            'assigned_to' => $assignee,
            'product_master_id' => $productId,
            'org_account_id' => $orgAccountId,
        ];
        if ($account !== null) {
            $attributes['gtm_account_id'] = (int) $account->id;
            $attributes['gtm_contact_id'] = $contact ? (int) $contact->id : null;
        }
        if ($signal) {
            $attributes['source_company_opportunity_id'] = (int) $signal->id;
        }
        $leadId = LeadFactory::create($attributes, $tenant, $userId);
        $leadNo = DB::table('crm_leads')->where('id', $leadId)->value('lead_no');

        if ($signal) {
            // The lead's history line on the signal, and its ONE note carrying what the signal says (its quote, source, date and fit). Written here and nowhere
            // else, and only for a lead made now, so a lead never gets a second note.
            app(SignalReviewService::class)->recordPromotion($tenant, (int) $signal->id, $userId, $leadId);
            (new RecordNote)->record($tenant, 'lead', $leadId, $userId, LeadSignalEvidence::noteText(LeadSignalEvidence::of($signal), LeadSignalEvidence::productName($productId)));
        }

        if ($account !== null) {
            GtmActivity::create([
                'sub_institute_id' => $tenant, 'account_id' => $account->id, 'contact_id' => $contact?->id, 'type' => 'system',
                'subject' => $contact ? "Qualified {$contact->full_name} as a lead" . ($leadNo ? " ({$leadNo})" : '') : 'Lead' . ($leadNo ? " {$leadNo}" : '') . ' created from a signal',
                'occurred_at' => now(), 'user_id' => $userId, 'metadata' => ['lead_id' => $leadId, 'contact_id' => $contact?->id],
            ]);
            ProspectAccounts::advance($account, 'engaged', $userId);
        }

        return ['id' => $leadId, 'no' => $leadNo === null ? null : (string) $leadNo];
    }

    /** The sentence a person sees when this contact cannot become a lead, or null when they can. */
    public static function refusalFor(GtmContact $contact): ?string
    {
        return self::REFUSED[(string) $contact->status] ?? null;
    }

    /**
     * The leads qualified from an account, oldest first. Empty where the link columns are not on this host yet.
     *
     * @return list<array{id: string, leadNo: ?string, name: string, status: ?string, contactId: ?int}>
     */
    public static function leadsOf(int $tenant, int $accountId): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('gtm_account_id', $accountId)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'lead_no', 'first_name', 'last_name', 'lead_status', 'gtm_contact_id'])
            ->map(fn ($l) => [
                'id' => (string) $l->id, 'leadNo' => $l->lead_no, 'name' => trim($l->first_name . ' ' . $l->last_name), 'status' => $l->lead_status,
                'contactId' => $l->gtm_contact_id === null ? null : (int) $l->gtm_contact_id,
            ])->values()->all();
    }

    /** "Asha Maria Rao" => ["Asha Maria", "Rao"]; one word is a last name alone. @return array{0: ?string, 1: string} */
    public static function splitName(string $fullName): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $fullName));
        $at = mb_strrpos($name, ' ');
        if ($at === false) {
            return [null, mb_substr($name, 0, 191)];
        }

        return [mb_substr(mb_substr($name, 0, $at), 0, 191), mb_substr(mb_substr($name, $at + 1), 0, 191)];
    }

    /** The account's strongest live signal: its company's, in the account's own product and customer type, not dismissed, not expired, not a sample. */
    private static function strongestSignal(int $tenant, GtmAccount $account): ?CompanyOpportunity
    {
        if (! $account->company_id || ! Schema::hasTable('g2g_company_opportunities')) {
            return null;
        }
        $q = CompanyOpportunity::where('sub_institute_id', $tenant)->where('company_id', $account->company_id)
            ->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', '!=', 'Dismissed'))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->where('is_sample', false);
        ProspectScope::applyToSignals($q, ['product' => $account->product_key, 'model' => $account->customer_model, 'region' => null], 'g2g_company_opportunities');

        return $q->orderByRaw("CASE priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END")->orderByDesc('first_discovered_at')->orderByDesc('id')->first();
    }

    private static function clip(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return array{http: int, body: array<string, mixed>} */
    private static function refuse(int $http, string $message, ?string $code = null): array
    {
        return ['http' => $http, 'body' => array_filter(['status' => 0, 'code' => $code, 'message' => $message], fn ($v) => $v !== null)];
    }
}
