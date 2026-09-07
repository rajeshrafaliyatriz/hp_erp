<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What an employee can see about themselves. F-130.
 *
 * THE AUDIT'S PART D GAP, and the plainest one in the module: an employee
 * cannot see their own payslip. Not "it is hard to find" - there is no route
 * that serves it. `monthlyPayrollPdf` sits inside routes/hrms.php's
 * `hrit.role:admin,hr` group, so the only way to a payslip is through the HR
 * console, and an employee asking for last month's pay has to ask a person.
 *
 * THE SUBJECT IS ALWAYS THE CALLER. Every query here is scoped to
 * $identity['user_id'], resolved from the TOKEN. There is no `employee_id`
 * parameter on any of these endpoints, and that is deliberate rather than an
 * omission: the moment one exists, "my payslip" becomes "anyone's payslip"
 * unless a check is remembered on every branch. G-SEC-12's identity-vs-subject
 * rule, applied by removing the choice.
 *
 * That is also why this is a separate controller rather than a `?mine=1` flag
 * on the HR endpoints. A flag is something a caller can leave off.
 */
class MyHrController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * GET /api/my-hr/payslips
     *
     * Every month this employee has a payslip for, newest first.
     */
    public function payslips(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('employee_monthly_salary_data')
            ->where('employee_id', $identity['user_id'])
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->orderByDesc('year')
            ->orderByRaw("FIELD(month,'Dec','Nov','Oct','Sep','Aug','Jul','Jun','May','Apr','Mar','Feb','Jan')")
            ->get(['id', 'month', 'year', 'total_payment', 'total_deduction', 'total_day', 'created_at']);

        return response()->json([
            'status'  => 1,
            'message' => 'Payslips fetched successfully',
            'data'    => $rows->map(fn ($row) => [
                'id'         => (int) $row->id,
                'month'      => $row->month,
                'year'       => (int) $row->year,
                'gross'      => (float) $row->total_payment + (float) $row->total_deduction,
                'deductions' => (float) $row->total_deduction,
                'net'        => (float) $row->total_payment,
                'days'       => (float) $row->total_day,
                'issued_at'  => $row->created_at,
                // The URL is built here rather than in the browser, so a client
                // cannot construct one for a month - or a person - it was not
                // given.
                'pdf_url'    => url("/api/my-hr/payslips/{$row->month}/{$row->year}/pdf"),
            ]),
        ]);
    }

    /**
     * GET /api/my-hr/payslips/{month}/{year}/pdf
     *
     * The employee's own payslip. The existing generator is reused verbatim -
     * this is the same PDF HR downloads, not a second implementation that could
     * disagree with it about somebody's pay.
     */
    public function payslipPdf(Request $request, string $month, int $year)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $exists = DB::table('employee_monthly_salary_data')
            ->where('employee_id', $identity['user_id'])
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('month', $month)
            ->where('year', $year)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            return response()->json([
                'status'  => 0,
                'message' => "You have no payslip for {$month} {$year}.",
            ], 404);
        }

        /*
         * The generator reads sub_institute_id and the employee id from the
         * request, so it is handed OUR resolved identity rather than whatever
         * the caller sent. The route has no employee_id to begin with; this is
         * belt and braces on the one call that could reintroduce it.
         */
        $request->merge([
            'type'             => 'API',
            'sub_institute_id' => $identity['sub_institute_id'],
            'month'            => $month,
            'year'             => $year,
        ]);

        $pdf = app(\App\Http\Controllers\Payroll\PayrollController::class)
            ->monthlyPayrollPdf($request, (int) $identity['user_id'], $month, $year, 'download');

        /*
         * F-125's guard returns null when the employee has no salary structure -
         * the payslip row exists but the breakdown it would print does not. For
         * the HR screen that meant "skip this one and carry on"; here it is the
         * whole response, and returning null would send a 200 with an empty body
         * that a browser renders as a blank tab.
         *
         * The generator's other failure branch is `redirect()->back()`, which is
         * equally wrong for an API caller. Both become a plain answer.
         */
        if ($pdf === null || $pdf instanceof \Illuminate\Http\RedirectResponse) {
            return response()->json([
                'status'  => 0,
                'message' => "Your {$month} {$year} payslip cannot be produced yet - there is no "
                    . 'salary structure on record for you. Ask HR to add one.',
            ], 409);
        }

        return $pdf;
    }

    /**
     * GET /api/my-hr/summary
     *
     * The one screen an employee opens to answer "where do I stand?" - leave
     * balance, this month's attendance, and whether a payslip exists yet.
     *
     * Composed from services that already exist. Nothing here recomputes a
     * balance or a day count: two implementations of "how much leave do I have
     * left" is exactly the defect F-95 was.
     */
    public function summary(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenant = (int) $identity['sub_institute_id'];
        $year   = $this->leaveYear($request);

        $balances = app(\App\Services\Leave\LeaveAnalyticsService::class)
            ->balancesForEmployee($tenant, $year, $userId);

        $pending = DB::table('hrms_emp_leaves')
            ->where('user_id', $userId)
            ->where('sub_institute_id', $tenant)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        // Where each pending request has got to, from the chain rather than from
        // its status - "pending" is one word for "nobody has looked at it" and
        // "your manager approved it, the department head has not".
        $awaiting = DB::table('hrms_leave_approval_steps as s')
            ->join('hrms_emp_leaves as l', 'l.id', '=', 's.leave_id')
            ->where('l.user_id', $userId)
            ->where('l.sub_institute_id', $tenant)
            ->where('l.status', 'pending')
            ->whereNull('l.deleted_at')
            ->where('s.status', 'pending')
            ->get(['l.id as leave_id', 'l.from_date', 'l.to_date', 's.approver_role', 's.step_order', 's.escalated_at'])
            ->map(fn ($row) => [
                'leave_id'   => (int) $row->leave_id,
                'from_date'  => $row->from_date,
                'to_date'    => $row->to_date,
                'step'       => (int) $row->step_order,
                'waiting_on' => \App\Services\Leave\LeaveApprovalWorkflow::label($row->approver_role),
                'overdue'    => $row->escalated_at !== null,
            ]);

        $latestPayslip = DB::table('employee_monthly_salary_data')
            ->where('employee_id', $userId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['month', 'year', 'total_payment']);

        $unread = DB::table('g2g_notification')
            ->where('user_id', $userId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'status'  => 1,
            'message' => 'Summary fetched successfully',
            'data'    => [
                'year'            => $year,
                'leave_balances'  => $balances['leave_types'],
                'pending_leave'   => $pending,
                'awaiting'        => $awaiting,
                'latest_payslip'  => $latestPayslip ? [
                    'month' => $latestPayslip->month,
                    'year'  => (int) $latestPayslip->year,
                    'net'   => (float) $latestPayslip->total_payment,
                ] : null,
                'payslip_count'   => DB::table('employee_monthly_salary_data')
                    ->where('employee_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->whereNull('deleted_at')
                    ->count(),
                'unread_notifications' => $unread,
                // Whether a salary certificate is even possible for this person,
                // so the screen can say WHY rather than offering a button that
                // will refuse. F-110's root cause, surfaced instead of hidden.
                'salary_structure_years' => DB::table('employee_salary_structures')
                    ->where('employee_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->orderByDesc('year')
                    ->pluck('year')
                    ->map(fn ($y) => (int) $y),
            ],
        ]);
    }

    /**
     * The leave year, April to March, normalised the same way ResolvesLeaveContext
     * does it. Duplicated deliberately rather than pulling in the leave trait -
     * that trait also resolves a leave SUBJECT, which is precisely the concept
     * this controller exists to not have.
     */
    private function leaveYear(Request $request): int
    {
        $raw = (string) ($request->input('syear') ?? $request->input('year') ?? '');

        if (preg_match('/^(\d{4})/', $raw, $m)) {
            return (int) $m[1];
        }

        $now = now();

        return $now->month >= 4 ? $now->year : $now->year - 1;
    }
}
