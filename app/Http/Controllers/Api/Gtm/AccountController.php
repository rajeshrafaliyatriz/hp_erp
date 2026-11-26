<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmContact;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GTM accounts, read-only: the list the Deals, Outreach, Campaigns, Customers and Agents screens pick from, and one account with its people, signals and timeline.
 * Nothing in the GTM screens creates, edits or removes an account or a contact: they come from CRM opportunities (Crm\Sales\OpportunityGtmSyncBridge).
 *
 * Tenant identity comes from the token only. Another organisation's account is answered
 * exactly like a missing one (404). An account may point at a g2g_company (research
 * discovery); its signals are read live from g2g_company_opportunities, never copied.
 */
class AccountController extends Controller
{
    use ResolvesApiIdentity;

    public const STAGES = ['target', 'engaged', 'opportunity', 'customer', 'churned', 'disqualified'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $q = trim((string) $request->input('q', ''));
        $stage = (string) $request->input('stage', '');

        $query = GtmAccount::query()->where('gtm_accounts.sub_institute_id', $tenant);
        if ($stage !== '' && in_array($stage, self::STAGES, true)) {
            $query->where('stage', $stage);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('domain', 'like', $like)->orWhere('industry', 'like', $like));
        }

        // Counts are correlated sub-selects on the SAME tenant, so a row can never be
        // counted against an account from another organisation.
        $query->select('gtm_accounts.*')
            ->selectSub(
                DB::table('gtm_contacts')->selectRaw('COUNT(*)')->whereColumn('gtm_contacts.account_id', 'gtm_accounts.id')
                    ->where('gtm_contacts.sub_institute_id', $tenant)->whereNull('gtm_contacts.deleted_at'),
                'contacts_count'
            )
            ->selectSub(
                // Signals are counted for the account's own product and customer type: a G2G partner account does not show ScholarClone signals.
                DB::table('g2g_company_opportunities')->selectRaw('COUNT(*)')->whereColumn('g2g_company_opportunities.company_id', 'gtm_accounts.company_id')
                    ->where('g2g_company_opportunities.sub_institute_id', $tenant)->where('g2g_company_opportunities.review_status', '!=', 'Dismissed')
                    ->where(fn ($s) => $s->whereNull('gtm_accounts.product_key')->orWhereColumn('g2g_company_opportunities.product_key', 'gtm_accounts.product_key'))
                    ->where(fn ($s) => $s->whereNull('gtm_accounts.customer_model')->orWhereColumn('g2g_company_opportunities.customer_model', 'gtm_accounts.customer_model')),
                'signals_count'
            )
            ->selectSub(
                DB::table('gtm_activities')->selectRaw('MAX(occurred_at)')->whereColumn('gtm_activities.account_id', 'gtm_accounts.id')
                    ->where('gtm_activities.sub_institute_id', $tenant),
                'last_activity_at'
            );

        $sort = (string) $request->input('sort', 'recent');
        match ($sort) {
            'fit' => $query->orderByRaw('icp_fit_score IS NULL')->orderByDesc('icp_fit_score'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('gtm_accounts.id'),
        };

        $page = $query->paginate($perPage);

        return response()->json(['status' => 1, 'data' => [
            'items' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $account = GtmAccount::where('sub_institute_id', $tenant)->find($id);
        if (! $account) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        $contacts = GtmContact::where('sub_institute_id', $tenant)->where('account_id', $id)->orderBy('full_name')->get();

        $signals = [];
        if ($account->company_id) {
            $signals = DB::table('g2g_company_opportunities')
                ->where('sub_institute_id', $tenant)->where('company_id', $account->company_id)
                ->where('review_status', '!=', 'Dismissed')
                ->orderByDesc('first_discovered_at')->limit(25)
                ->get(['id', 'title', 'signal_kind', 'observed_event', 'why_indicates_need', 'recommended_action', 'priority', 'confidence', 'review_status', 'sources', 'event_date', 'first_discovered_at'])
                ->map(function ($s) {
                    $s->sources = json_decode($s->sources ?? '[]', true) ?: [];

                    return $s;
                })->all();
        }

        $activities = GtmActivity::where('sub_institute_id', $tenant)->where('account_id', $id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();

        $analyses = DB::table('gtm_analyses')->where('sub_institute_id', $tenant)
            ->where('subject_type', 'account')->where('subject_id', $id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'kind', 'status', 'is_estimate', 'provider', 'model', 'error_code', 'created_at']);

        return response()->json(['status' => 1, 'data' => [
            'account' => $account,
            'contacts' => $contacts,
            'signals' => $signals,
            'activities' => $activities,
            'analyses' => $analyses,
        ]]);
    }
}
