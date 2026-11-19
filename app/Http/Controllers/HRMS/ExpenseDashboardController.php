<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HRMS\Concerns\ExpenseRoles;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expense Management's picture and reports: /api/expenses/dashboard and
 * /api/expenses/reports/*.
 *
 * The dashboard is for everybody and is SCOPED to the caller:
 *   HR / admin / executive / auditor   the whole organisation
 *   anyone else                        claims they made, plus those routed to them
 * so a manager's dashboard is their team's and an employee's is their own.
 *
 * The two reports show other people's money, so they are for the same
 * organisation-wide roles only - anyone else gets a 403, whatever the menu shows.
 *
 * Money in the chart means claims actually in play: submitted, approved or paid.
 * Drafts, rejections and cancellations never counted as anyone's spend.
 */
class ExpenseDashboardController extends Controller
{
    use ResolvesApiIdentity;
    use ExpenseRoles;

    private const COUNTED = ['submitted', 'approved', 'paid'];

    /** GET /api/expenses/dashboard */
    public function dashboard(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $all = $this->canReadAll(RoleKey::forUserId($userId));

        $claims = fn () => DB::table('hrms_expense_claims as c')
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->when(!$all, fn ($q) => $q->where(fn ($w) => $w->where('c.employee_id', $userId)->orWhere('c.approver_id', $userId)));

        $advances = fn () => DB::table('hrms_expense_advances as a')
            ->where('a.sub_institute_id', $tenantId)
            ->whereNull('a.deleted_at')
            ->when(!$all, fn ($q) => $q->where(fn ($w) => $w->where('a.employee_id', $userId)->orWhere('a.approver_id', $userId)));

        // Twelve months, oldest first, padded so the chart has no gaps.
        $start = Carbon::now()->startOfMonth()->subMonths(11);
        $byMonth = $claims()
            ->whereIn('c.status', self::COUNTED)
            ->where(DB::raw('COALESCE(c.submitted_at, c.created_at)'), '>=', $start)
            ->groupBy(DB::raw("DATE_FORMAT(COALESCE(c.submitted_at, c.created_at), '%Y-%m')"))
            ->select(
                DB::raw("DATE_FORMAT(COALESCE(c.submitted_at, c.created_at), '%Y-%m') as ym"),
                DB::raw('SUM(c.total_amount) as claimed'),
                DB::raw("SUM(CASE WHEN c.status = 'paid' THEN c.total_amount ELSE 0 END) as paid"),
            )
            ->get()
            ->keyBy('ym');

        $monthly = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $row = $byMonth->get($month->format('Y-m'));
            $monthly[] = [
                'month' => $month->format('M Y'),
                'claimed' => $row ? round((float) $row->claimed, 2) : 0.0,
                'paid' => $row ? round((float) $row->paid, 2) : 0.0,
            ];
        }

        $byStatus = $claims()
            ->where('c.status', '!=', 'draft')
            ->groupBy('c.status')
            ->select('c.status', DB::raw('COUNT(*) as claims'), DB::raw('SUM(c.total_amount) as amount'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['claims' => (int) $r->claims, 'amount' => round((float) $r->amount, 2)]]);

        $topCategories = $claims()
            ->join('hrms_expense_items as i', 'i.claim_id', '=', 'c.id')
            ->leftJoin('hrms_expense_categories as k', 'k.id', '=', 'i.category_id')
            ->whereIn('c.status', self::COUNTED)
            ->groupBy('i.category_id', 'k.name')
            ->orderByDesc(DB::raw('SUM(i.amount)'))
            ->limit(6)
            ->get(['k.name as category', DB::raw('SUM(i.amount) as amount')])
            ->map(fn ($r) => ['category' => $r->category ?: 'Other', 'amount' => round((float) $r->amount, 2)])
            ->values();

        $pending = (int) $claims()->where('c.status', 'submitted')->count();
        $unclaimed = $advances()->where('a.status', 'paid')->whereRaw('a.advance_amount > a.claimed_amount');
        $unclaimedCount = (int) (clone $unclaimed)->count();
        $unclaimedAmount = (float) (clone $unclaimed)->sum(DB::raw('a.advance_amount - a.claimed_amount'));

        $thisMonth = (float) $claims()
            ->whereIn('c.status', self::COUNTED)
            ->where(DB::raw('COALESCE(c.submitted_at, c.created_at)'), '>=', Carbon::now()->startOfMonth())
            ->sum('c.total_amount');

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => [
                'scope' => $all ? 'organization' : 'mine',
                'monthly' => $monthly,
                'by_status' => $byStatus,
                'top_categories' => $topCategories,
                'shortcuts' => [
                    'pending_claims' => $pending,
                    'unclaimed_advances' => $unclaimedCount,
                ],
                'totals' => [
                    'this_month' => round($thisMonth, 2),
                    'awaiting_payment' => (float) ($byStatus['approved']['amount'] ?? 0),
                    'advances_outstanding' => round($unclaimedAmount, 2),
                ],
            ],
        ]);
    }

    /**
     * GET /api/expenses/reports/unpaid-claims  (?employee_id=)
     *
     * Claims approved and still waiting to be paid, oldest first, with how long
     * each has waited.
     */
    public function unpaidClaims(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        if ($refused = $this->refuseUnlessOrgWide($identity)) {
            return $refused;
        }

        $rows = DB::table('hrms_expense_claims as c')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.employee_id')
            ->leftJoin('hrms_departments as d', function ($join) {
                $join->on('d.id', '=', 'u.department_id')->whereNull('d.deleted_at');
            })
            ->where('c.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('c.deleted_at')
            ->where('c.status', 'approved')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('c.employee_id', (int) $request->input('employee_id')))
            ->orderBy('c.decided_at')
            ->limit(2000)
            ->get([
                'c.id', 'c.claim_no', 'c.employee_id', 'c.title', 'c.total_amount', 'c.advance_adjusted',
                'c.decided_at', 'c.currency',
                DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as employee_name"),
                DB::raw('d.department as department'),
                DB::raw('DATEDIFF(NOW(), c.decided_at) as days_waiting'),
            ]);

        $buckets = ['0-7' => 0.0, '8-30' => 0.0, '31-60' => 0.0, '60+' => 0.0];
        $data = $rows->map(function ($r) use (&$buckets) {
            $days = max((int) $r->days_waiting, 0);
            $net = round((float) $r->total_amount - (float) $r->advance_adjusted, 2);
            $key = $days <= 7 ? '0-7' : ($days <= 30 ? '8-30' : ($days <= 60 ? '31-60' : '60+'));
            $buckets[$key] += $net;

            return [
                'id' => (int) $r->id,
                'claim_no' => $r->claim_no,
                'employee_id' => (int) $r->employee_id,
                'employee_name' => $r->employee_name ?: '—',
                'department' => $r->department,
                'title' => $r->title,
                'approved_at' => $r->decided_at,
                'days_waiting' => $days,
                'total_amount' => (float) $r->total_amount,
                'advance_adjusted' => (float) $r->advance_adjusted,
                'net_payable' => $net,
                'currency' => $r->currency,
            ];
        })->values();

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $data,
            'totals' => [
                'claims' => $data->count(),
                'net_payable' => round($data->sum('net_payable'), 2),
                'aging' => array_map(fn ($v) => round($v, 2), $buckets),
            ],
        ]);
    }

    /**
     * GET /api/expenses/reports/advance-summary
     *
     * One row per employee who has had an advance: what was paid out, how much
     * of it claims have used, what they still hold, and what is still pending.
     */
    public function advanceSummary(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        if ($refused = $this->refuseUnlessOrgWide($identity)) {
            return $refused;
        }

        $rows = DB::table('hrms_expense_advances as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.employee_id')
            ->leftJoin('hrms_departments as d', function ($join) {
                $join->on('d.id', '=', 'u.department_id')->whereNull('d.deleted_at');
            })
            ->where('a.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('a.deleted_at')
            ->whereIn('a.status', ['requested', 'approved', 'paid', 'claimed'])
            ->groupBy('a.employee_id', 'u.first_name', 'u.last_name', 'd.department')
            ->orderBy('u.first_name')
            ->get([
                'a.employee_id',
                DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as employee_name"),
                DB::raw('d.department as department'),
                DB::raw("SUM(CASE WHEN a.status IN ('paid','claimed') THEN 1 ELSE 0 END) as advances"),
                DB::raw("SUM(CASE WHEN a.status IN ('paid','claimed') THEN a.advance_amount ELSE 0 END) as paid"),
                DB::raw("SUM(CASE WHEN a.status IN ('paid','claimed') THEN a.claimed_amount ELSE 0 END) as claimed"),
                DB::raw("SUM(CASE WHEN a.status IN ('requested','approved') THEN a.advance_amount ELSE 0 END) as pending"),
            ]);

        $data = $rows->map(function ($r) {
            $paid = round((float) $r->paid, 2);
            $claimed = round((float) $r->claimed, 2);

            return [
                'employee_id' => (int) $r->employee_id,
                'employee_name' => $r->employee_name ?: '—',
                'department' => $r->department,
                'advances' => (int) $r->advances,
                'paid' => $paid,
                'claimed' => $claimed,
                'unclaimed' => max(round($paid - $claimed, 2), 0),
                'pending' => round((float) $r->pending, 2),
            ];
        })->values();

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $data,
            'totals' => [
                'employees' => $data->count(),
                'paid' => round($data->sum('paid'), 2),
                'claimed' => round($data->sum('claimed'), 2),
                'unclaimed' => round($data->sum('unclaimed'), 2),
                'pending' => round($data->sum('pending'), 2),
            ],
        ]);
    }

    /** These reports list other people's money: organisation-wide roles only. */
    private function refuseUnlessOrgWide(array $identity)
    {
        if (!$this->canReadAll(RoleKey::forUserId((int) $identity['user_id']))) {
            return response()->json(['status' => 0, 'message' => 'This report is for HR and administrators.'], 403);
        }

        return null;
    }
}
