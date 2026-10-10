<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\SignalRunner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Command Center numbers.
 *
 * Two blocks, never mixed: `measured` is counted from rows; `estimates` is what a model
 * said. A figure with nothing behind it is null, not zero - "0 meetings" and "meetings are
 * not tracked yet" are different statements and the screen must be able to tell them apart.
 */
class OverviewController extends Controller
{
    use ResolvesApiIdentity;

    public function show(Request $request, SignalRunner $signals): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $since = now()->subDays(30);

        $accountsByStage = DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')
            ->selectRaw('stage, COUNT(*) c')->groupBy('stage')->pluck('c', 'stage');

        $signalsByPriority = DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)
            ->where('review_status', '!=', 'Dismissed')->selectRaw('priority, COUNT(*) c')->groupBy('priority')->pluck('c', 'priority');
        $signalsNew = DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)->where('review_status', 'New')->count();

        $activities = DB::table('gtm_activities')->where('sub_institute_id', $t)->where('occurred_at', '>=', $since)
            ->where('type', '!=', 'system')->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type');

        $companiesWithoutAccount = DB::table('g2g_companies')->where('g2g_companies.sub_institute_id', $t)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gtm_accounts')
                ->whereColumn('gtm_accounts.company_id', 'g2g_companies.id')->where('gtm_accounts.sub_institute_id', $t)->whereNull('gtm_accounts.deleted_at'))
            ->count();

        $scored = DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')->whereNotNull('icp_fit_score');

        $search = SearchProviderFactory::make();

        return response()->json(['status' => 1, 'data' => [
            'measured' => [
                'accounts_total' => (int) $accountsByStage->sum(),
                'accounts_by_stage' => $accountsByStage,
                'contacts_total' => DB::table('gtm_contacts')->where('sub_institute_id', $t)->whereNull('deleted_at')->count(),
                'accounts_without_contacts' => DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gtm_contacts')->whereColumn('gtm_contacts.account_id', 'gtm_accounts.id')->whereNull('gtm_contacts.deleted_at'))->count(),
                'signals_by_priority' => $signalsByPriority,
                'signals_unreviewed' => $signalsNew,
                'companies_not_yet_accounts' => $companiesWithoutAccount,
                'activities_30d_by_type' => $activities,
                'activities_30d_total' => (int) $activities->sum(),
            ],
            'estimates' => [
                // AI-produced, labelled. Null until at least one account has been scored.
                'icp_fit_avg' => $scored->count() > 0 ? round((float) (clone $scored)->avg('icp_fit_score'), 1) : null,
                'icp_fit_scored_accounts' => (clone $scored)->count(),
            ],
            'readiness' => [
                'web_search' => ['configured' => $search !== null && $search->isConfigured(), 'driver' => $search?->name()],
                'ai' => ['configured' => $signals->aiConfigured($t)],
            ],
        ]]);
    }
}
