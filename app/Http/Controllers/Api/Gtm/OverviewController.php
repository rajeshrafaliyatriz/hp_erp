<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmDeal;
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
        $deals = $this->dealsBlock($t);

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
            // null = the deals tables are not installed in this environment (not "0 deals").
            'deals' => $deals,
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

    /** Measured deal figures, or null when the deals tables do not exist here yet. @return array<string, mixed>|null */
    private function dealsBlock(int $t): ?array
    {
        $exists = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['gtm_deals'])->c > 0;
        if (! $exists) {
            return null;
        }
        $open = GtmDeal::where('sub_institute_id', $t)->whereIn('stage', GtmDeal::OPEN_STAGES)->get();
        $assessed = app(DealHealth::class)->assess($t, $open);
        $byCurrency = function ($rows) {
            $out = [];
            foreach ($rows as $d) {
                if ($d->amount !== null && $d->currency) {
                    $out[$d->currency] = round(($out[$d->currency] ?? 0) + (float) $d->amount, 2);
                }
            }

            return $out;
        };
        $closed = GtmDeal::where('sub_institute_id', $t)->whereIn('stage', ['won', 'lost'])->where('closed_at', '>=', now()->subDays(30))->get();

        return [
            'open_count' => $open->count(),
            'open_value_by_currency' => $byCurrency($open),
            'open_without_amount' => $open->whereNull('amount')->count(),
            'flagged_count' => $open->filter(fn ($d) => ($assessed[$d->id]['flags'] ?? []) !== [])->count(),
            'no_next_step_count' => $open->filter(fn ($d) => in_array('no_next_step', $assessed[$d->id]['flags'] ?? [], true))->count(),
            'won_30d' => ['count' => $closed->where('stage', 'won')->count(), 'value_by_currency' => $byCurrency($closed->where('stage', 'won'))],
            'lost_30d' => ['count' => $closed->where('stage', 'lost')->count()],
            'customers' => DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')->where('stage', 'customer')->count(),
        ];
    }
}
