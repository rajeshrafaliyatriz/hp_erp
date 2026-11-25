<?php

namespace App\Domain\Gtm;

use App\Domain\Signals\Opportunities\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Putting a researched company into Prospecting (a GTM account). ONE place does it, for the Research tab's "Add" (AccountController::fromCompany) and for a
 * signal's "Send to Prospecting" (Signals\OpportunityController::sendToProspecting): the account is filed under a product, a customer type and a place,
 * and promoting the same company again under the same scope answers the account that is already there instead of making another.
 */
final class ProspectAccounts
{
    /**
     * The list a signal belongs to: the product and customer type it was found for, and its place when the run was limited to exactly one place. A signal
     * from an "All regions" run, or from a run over several places, has no single place, so its account is filed without one (the same rule the Research
     * tab's candidates use). A value the catalogue or the location rules no longer accept is not an error here: that part of the scope is left out.
     *
     * @param  object  $signal  a g2g_company_opportunities row or model
     * @return array{product: ?string, model: ?string, region: ?array{country: string, state: ?string, city: ?string, key: string}}
     */
    public static function scopeOfSignal(int $tenant, object $signal): array
    {
        $places = $signal->region_scope ?? null;
        $places = is_string($places) ? json_decode($places, true) : $places;
        $place = is_array($places) && count($places) === 1 && is_array($places[0] ?? null)
            ? ['country' => $places[0]['country'] ?? null, 'state' => $places[0]['state'] ?? null, 'city' => $places[0]['city'] ?? null] : null;

        try {
            return ProspectScope::fromInput($signal->product_key ?? null, $signal->customer_model ?? null, $tenant, $place);
        } catch (\DomainException) {
            try {
                return ProspectScope::fromInput($signal->product_key ?? null, $signal->customer_model ?? null, $tenant, null);
            } catch (\DomainException) {
                return ['product' => null, 'model' => null, 'region' => null];
            }
        }
    }

    /**
     * Idempotent: the account already filed for this company under this scope is answered as it is.
     *
     * @param  array{product: ?string, model: ?string, region: ?array{country: string, state: ?string, city: ?string, key: string}}  $scope
     * @return array{account: GtmAccount, created: bool}
     */
    public static function fromCompany(int $tenant, Company $company, array $scope, ?int $userId): array
    {
        $existing = ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant)->where('company_id', $company->id), $scope)->first();
        if ($existing) {
            return ['account' => $existing, 'created' => false];
        }

        $domain = self::normaliseDomain($company->domain ?: $company->website);
        if ($domain && self::domainTaken($tenant, $domain, $scope)) {
            $domain = null; // a manual account already owns this domain here; keep both rather than fail the promote
        }

        $account = GtmAccount::create([
            'sub_institute_id' => $tenant,
            'product_key' => $scope['product'],
            'customer_model' => $scope['model'],
            'region_country' => $scope['region']['country'] ?? null,
            'region_state' => $scope['region']['state'] ?? null,
            'region_city' => $scope['region']['city'] ?? null,
            'region_key' => $scope['region']['key'] ?? '',
            'company_id' => $company->id,
            'name' => $company->name,
            'domain' => $domain,
            'website' => $company->website,
            'industry' => $company->industry,
            'location' => $company->location,
            'source' => 'research',
            'created_by' => $userId,
        ]);

        GtmActivity::create([
            'sub_institute_id' => $tenant, 'account_id' => $account->id, 'type' => 'system',
            'subject' => 'Added from research signals', 'occurred_at' => now(), 'user_id' => $userId,
            'metadata' => ['company_id' => $company->id],
        ]);
        GtmAudit::record('gtm.account.created', $tenant, 'gtm_accounts', $account->id, $userId, ['name' => $account->name, 'source' => 'research', 'company_id' => $company->id]);

        return ['account' => $account, 'created' => true];
    }

    /** The stages in the order a company moves through them; a stage that is not here (churned, disqualified) is never moved by a conversion. */
    private const STAGE_RANK = ['target' => 0, 'engaged' => 1, 'opportunity' => 2, 'customer' => 3];

    /**
     * Move an account FORWARD to a stage (a lead made from it: engaged; an opportunity made: opportunity), with the timeline line and the audit line every stage change has.
     * Never downgrades, never moves a churned or disqualified account, never moves one already at or past the stage. The account is saved.
     */
    public static function advance(GtmAccount $account, string $to, ?int $userId): bool
    {
        if ($account->stage === null) {
            $account->refresh();   // a row just made carries its database default (target) only after it is read back
        }
        $from = (string) $account->stage;
        if (! isset(self::STAGE_RANK[$from], self::STAGE_RANK[$to]) || self::STAGE_RANK[$from] >= self::STAGE_RANK[$to]) {
            return false;
        }
        $account->stage = $to;
        $account->save();
        GtmActivity::create([
            'sub_institute_id' => $account->sub_institute_id, 'account_id' => $account->id, 'type' => 'system',
            'subject' => "Stage changed: {$from} → {$to}", 'occurred_at' => now(), 'user_id' => $userId,
        ]);
        GtmAudit::record('gtm.account.updated', (int) $account->sub_institute_id, 'gtm_accounts', (int) $account->id, $userId, ['changed' => ['stage']]);

        return true;
    }

    /**
     * Is this signal one a company should go into Prospecting for? The same eligibility as the Research tab's candidates: a company, not dismissed, not a sample and
     * not expired. (A signal the qualification gate rejected is never saved, so it never gets here.)
     *
     * @param  object  $signal  a g2g_company_opportunities row or model
     */
    public static function eligible(object $signal): bool
    {
        $expires = $signal->expires_at ?? null;
        $expired = $expires !== null && substr((string) ($expires instanceof \DateTimeInterface ? $expires->format('Y-m-d') : $expires), 0, 10) < now()->toDateString();

        return (int) ($signal->company_id ?? 0) > 0 && ($signal->review_status ?? null) !== 'Dismissed' && ! (bool) ($signal->is_sample ?? false) && ! $expired;
    }

    /**
     * A saved qualified signal puts its company into Prospecting AUTOMATICALLY (target stage), by the same fromCompany() the Research tab uses. Idempotent (the account
     * already there is left exactly as it is: it is never edited), never fails the save (a problem is logged and nothing is made), and respects a person's choice:
     * a company whose account for this scope was REMOVED is not brought back. Off with GTM_AUTO_PROSPECTING=false.
     *
     * @param  object  $signal  a CompanyOpportunity just saved
     * @return GtmAccount|null the account that is there now, or null when none was made or found
     */
    public static function autoAdd(int $tenant, object $signal, ?int $userId = null): ?GtmAccount
    {
        if (! config('gtm.auto_prospecting', true) || ! self::eligible($signal) || ! Schema::hasTable('gtm_accounts')) {
            return null;
        }

        try {
            return DB::transaction(function () use ($tenant, $signal, $userId) {
                $company = Company::where('sub_institute_id', $tenant)->find($signal->company_id);
                if (! $company) {
                    return null;
                }
                $scope = self::scopeOfSignal($tenant, $signal);
                if (ProspectScope::apply(GtmAccount::onlyTrashed()->where('sub_institute_id', $tenant)->where('company_id', $company->id), $scope)->exists()) {
                    return null;
                }

                return self::fromCompany($tenant, $company, $scope, $userId)['account'];
            });
        } catch (\Throwable $e) {
            Log::warning('A signal could not be put into Prospecting', ['signal' => $signal->id ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The account this signal's company has in Prospecting for the signal's scope: the one that is there, else (when the signal is eligible and the account was not removed
     * on purpose) one made now by autoAdd(). Best effort and read-mostly: a server whose Prospecting tables are older than this code answers null, never an error.
     *
     * @param  object  $signal  a CompanyOpportunity
     */
    public static function forSignal(int $tenant, object $signal, ?int $userId = null): ?GtmAccount
    {
        if ((int) ($signal->company_id ?? 0) <= 0 || ! Schema::hasTable('gtm_accounts')) {
            return null;
        }
        try {
            $existing = ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant)->where('company_id', $signal->company_id), self::scopeOfSignal($tenant, $signal))->first();
        } catch (\Throwable $e) {
            Log::warning('A signal\'s Prospecting account could not be looked up', ['signal' => $signal->id ?? null, 'error' => $e->getMessage()]);

            return null;
        }

        return $existing ?? self::autoAdd($tenant, $signal, $userId);
    }

    /** Is this website already an account for this product, customer type and region? (Removed accounts count: they still hold the website.) */
    public static function domainTaken(int $tenant, string $domain, array $scope, ?int $exceptId = null): bool
    {
        $q = GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->where('domain', $domain);
        $scope['product'] === null ? $q->whereNull('product_key') : $q->where('product_key', $scope['product']);
        $scope['model'] === null ? $q->whereNull('customer_model') : $q->where('customer_model', $scope['model']);
        $q->where('region_key', $scope['region']['key'] ?? '');
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->exists();
    }

    public static function normaliseDomain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?: $value;

        return preg_replace('/^www\./', '', $host) ?: null;
    }
}
