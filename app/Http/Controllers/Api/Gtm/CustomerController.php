<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\Customers\CustomerService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer accounts with computed, explainable health; the alerts that need attention; one customer's detail. */
class CustomerController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request, CustomerService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $rows = $svc->customers($t);
        $bands = collect($rows)->countBy(fn ($r) => $r['health']['band']);

        return response()->json(['status' => 1, 'data' => [
            'items' => $rows,
            'summary' => ['customers' => count($rows), 'healthy' => (int) ($bands['healthy'] ?? 0), 'watch' => (int) ($bands['watch'] ?? 0), 'at_risk' => (int) ($bands['at_risk'] ?? 0), 'unknown' => (int) ($bands['unknown'] ?? 0)],
            'method' => 'Health is computed from logged activity, replies, contacts and recorded renewals. Components with no data are left out and listed as missing, never scored as zero.',
            'weights' => \App\Domain\Gtm\Customers\CustomerHealth::WEIGHTS,
        ]]);
    }

    public function alerts(Request $request, CustomerService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => $svc->alerts($identity['sub_institute_id'])]);
    }

    public function show(Request $request, int $accountId, CustomerService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $one = $svc->one($t, $accountId);
        if (! $one) {
            return response()->json(['status' => 0, 'message' => 'Customer not found'], 404);
        }
        $account = DB::table('gtm_accounts')->where('sub_institute_id', $t)->find($accountId, ['id', 'company_id', 'name']);
        $signals = $account && $account->company_id ? DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)->where('company_id', $account->company_id)->where('review_status', '!=', 'Dismissed')
            ->orderByRaw("FIELD(priority,'High','Medium','Low')")->orderByDesc('first_discovered_at')->limit(10)->get(['id', 'title', 'signal_kind', 'priority', 'sources', 'event_date'])
            ->map(function ($s) { $s->sources = json_decode($s->sources ?? '[]', true) ?: []; return $s; })->all() : [];
        $review = DB::table('gtm_analyses')->where('sub_institute_id', $t)->where('kind', 'customer_review')->where('status', 'success')->orderByDesc('id')->first(['id', 'result', 'provider', 'model', 'created_at']);
        if ($review) {
            $review->result = json_decode($review->result ?? 'null', true);
        }

        return response()->json(['status' => 1, 'data' => $one + [
            'renewals' => DB::table('gtm_renewals')->where('sub_institute_id', $t)->where('account_id', $accountId)->whereNull('deleted_at')->orderByDesc('renewal_date')->get(),
            'expansion_signals' => $signals,
            'activities' => DB::table('gtm_activities')->where('sub_institute_id', $t)->where('account_id', $accountId)->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get(['id', 'type', 'direction', 'subject', 'occurred_at']),
            'last_review' => $review,
        ]]);
    }
}
