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
     * GET /api/my-hr/pay-breakdown
     *
     * Every month this employee has been paid for, with the COMPONENTS behind
     * each figure - Basic, HRA, PF, whatever this organisation has configured -
     * rather than only the gross and net that /my-hr/payslips returns.
     *
     * The same information exists on the HR side as `employee-payroll-history`,
     * and that endpoint is deliberately NOT reused: it calls
     * employeeDetails($sub_institute_id), which returns the organisation's whole
     * roster in the same response. Handing an employee the staff directory in
     * order to show them their own pay is a worse trade than reading two tables
     * here.
     *
     * Nothing is recomputed. `employee_salary_data` is the payslip's own stored
     * breakdown, read back verbatim; the head names and earning/deduction sense
     * come from payroll_types. A second implementation of "what were you paid"
     * is exactly the defect class this module has spent two phases removing.
     */
    public function payBreakdown(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenant = (int) $identity['sub_institute_id'];

        /*
         * Heads are read WITHOUT a status filter on purpose. A deactivated head
         * that was paid last March is still part of last March's pay, and
         * filtering on status=1 would render it as "Head #14" on the employee's
         * own payslip. F-174 is the same mistake one table across.
         */
        $heads = DB::table('payroll_types')
            ->where('sub_institute_id', $tenant)
            ->get(['id', 'payroll_name', 'payroll_type'])
            ->keyBy('id');

        $query = DB::table('employee_monthly_salary_data')
            ->where('employee_id', $userId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at');

        // An optional narrowing, never a widening: there is no employee_id here
        // to pass, so the worst a caller can do is ask for fewer of their own.
        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }

        $rows = $query
            ->orderByDesc('year')
            ->orderByRaw("FIELD(month,'Dec','Nov','Oct','Sep','Aug','Jul','Jun','May','Apr','Mar','Feb','Jan')")
            ->get(['id', 'month', 'year', 'total_payment', 'total_deduction', 'total_day', 'employee_salary_data', 'created_at']);

        $months = $rows->map(function ($row) use ($heads) {
            $stored = json_decode((string) $row->employee_salary_data, true);
            $stored = is_array($stored) ? $stored : [];

            $components = [];
            foreach ($stored as $headId => $amount) {
                $head = $heads->get((int) $headId);

                $components[] = [
                    'head_id' => (int) $headId,
                    // Named where the head still exists; identified where it
                    // does not, so a missing name reads as a missing name.
                    'name'    => $head->payroll_name ?? "Head #{$headId}",
                    'kind'    => ((int) ($head->payroll_type ?? 1)) === 1 ? 'earning' : 'deduction',
                    'amount'  => (float) $amount,
                ];
            }

            $componentSum = array_sum(array_column($components, 'amount'));

            return [
                'id'            => (int) $row->id,
                'month'         => $row->month,
                'year'          => (int) $row->year,
                'days'          => (float) $row->total_day,
                'gross'         => (float) $row->total_payment + (float) $row->total_deduction,
                'deductions'    => (float) $row->total_deduction,
                'net'           => (float) $row->total_payment,
                'components'    => $components,
                /*
                 * Surfaced rather than hidden. The stored components need not
                 * add up to what was filed - eleven adjustments on this
                 * deployment were entered against a month payroll never matched
                 * (F-173) - and an employee comparing their payslip to their
                 * bank statement deserves to see that the two disagree rather
                 * than a total that quietly papers over it.
                 */
                'component_sum' => $componentSum,
                'reconciles'    => abs($componentSum - (float) $row->total_deduction - (float) $row->total_payment) <= 0.5,
                'issued_at'     => $row->created_at,
            ];
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Pay breakdown fetched successfully',
            'data'    => [
                'months' => $months,
                'years'  => $months->pluck('year')->unique()->values(),
            ],
        ]);
    }

    /**
     * GET /api/my-hr/salary-certificate/{year}
     *
     * The employee's own salary certificate, generated and returned as a PDF.
     *
     * hrms_salary_certificate held ZERO rows across the whole platform when
     * this was written - no certificate had ever been produced, by anybody. The
     * reason was F-110: the builder dereferenced a salary structure that did not
     * exist and fatalled, so the screen refused every combination a user could
     * pick. That is fixed; this makes the result reachable by the person who
     * actually needs it, who until now had to ask HR to operate a screen that
     * did not work.
     *
     * THE GENERATOR IS REUSED VERBATIM, exactly as payslipPdf reuses
     * monthlyPayrollPdf. A certificate an employee downloads and one HR
     * downloads must not be two implementations that can disagree about
     * somebody's salary.
     */
    public function salaryCertificate(Request $request, int $year)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenant = (int) $identity['sub_institute_id'];

        /*
         * Say why BEFORE generating. The builder returns null without a salary
         * structure for the year, and "we could not make your certificate" is a
         * worse answer than naming the missing thing and who can add it.
         */
        $hasStructure = DB::table('employee_salary_structures')
            ->where('employee_id', $userId)
            ->where('sub_institute_id', $tenant)
            ->where('year', $year)
            ->exists();

        if (!$hasStructure) {
            return response()->json([
                'status'  => 0,
                'message' => "A salary certificate for {$year} cannot be issued yet - there is no "
                    . 'salary structure on record for you for that year. Ask HR to add one under '
                    . 'Salary Structure.',
            ], 422);
        }

        /*
         * The whole year and every active earning head, because an employee
         * asking for a salary certificate wants the one a bank or a consulate
         * will accept - a complete statement of their pay, not a subset. HR
         * keeps the picker for the cases where a partial certificate is wanted.
         */
        $earningHeads = DB::table('payroll_types')
            ->where('sub_institute_id', $tenant)
            ->where('status', 1)
            ->where('payroll_type', 1)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($earningHeads === []) {
            return response()->json([
                'status'  => 0,
                'message' => 'Your organisation has no active earning pay heads configured, so a '
                    . 'salary certificate would state nothing. Ask HR to set them up under Payroll Type.',
            ], 422);
        }

        $request->merge([
            'type'             => 'API',
            'sub_institute_id' => $tenant,
            'employee_id'      => $userId,
            'department_id'    => (int) (DB::table('tbluser')->where('id', $userId)->value('department_id') ?? 0),
            'year'             => $year,
            'month_id'         => array_map('strval', range(1, 12)),
            'payroll_type_id'  => $earningHeads,
            'reason'           => (string) ($request->input('reason') ?: 'Requested by the employee'),
        ]);

        $payroll = app(\App\Http\Controllers\Payroll\PayrollController::class);

        $generated = $this->decodeControllerJson($payroll->hrmsSalaryCertificateReport($request));

        // The builder's refusal carries its own sentence; pass it through rather
        // than replacing it with a vaguer one.
        if (is_array($generated)
            && (string) ($generated['status_code'] ?? $generated['status'] ?? '1') === '0') {
            return response()->json([
                'status'  => 0,
                'message' => $generated['message'] ?? 'Your salary certificate could not be produced.',
            ], 422);
        }

        return $payroll->SalaryCertificatePdfDownload($request);
    }

    /**
     * GET /api/my-hr/form-16/{year}
     *
     * The employee's own Form 16 figures for a financial year.
     *
     * FORM 16 HAD NEVER BEEN PRODUCED EITHER, and for a blunter reason than the
     * certificate: form16Report queried `fees_map_years`, a table that exists on
     * neither host and that no migration creates, so every call - HR's included -
     * died with "Base table or view not found" (F-210). Fixed at the source, so
     * this endpoint and the HR screen recovered together.
     *
     * What comes back is the same figures HR sees, narrowed to the caller. It is
     * NOT a statutory Form 16: that is issued by the deductor against filed TDS
     * returns, and nothing here files anything. Whether it satisfies a given
     * authority is a question for the organisation's accountant, not for this
     * endpoint.
     */
    public function form16(Request $request, int $year)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenant = (int) $identity['sub_institute_id'];

        $request->merge([
            'type'             => 'API',
            'sub_institute_id' => $tenant,
            'emp_id'           => $userId,
            'department_id'    => (int) (DB::table('tbluser')->where('id', $userId)->value('department_id') ?? 0),
            'year'             => $year,
            'syear'            => $request->input('syear') ?: $year,
        ]);

        $decoded = $this->decodeControllerJson(
            app(\App\Http\Controllers\Payroll\PayrollController::class)->form16Report($request)
        );

        if (!is_array($decoded)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Your Form 16 figures could not be produced.',
            ], 409);
        }

        /*
         * The HR response also carries the organisation's department list and
         * every configured pay head, which the HR picker needs and this caller
         * does not. Only the employee's own document is passed through.
         */
        $keep = [
            'get_employee_salary', 'get_school_detail', 'get_employee_detail',
            'from_date', 'to_date', 'department_name', 'year',
            'allowance', 'deduction',
        ];

        $data = array_intersect_key($decoded, array_flip($keep));
        $data['employee_id'] = $userId;

        return response()->json([
            'status'  => (string) ($decoded['status_code'] ?? 1) === '0' ? 0 : 1,
            'message' => $decoded['message'] ?? 'Form 16 fetched successfully',
            'data'    => $data,
        ]);
    }

    /**
     * The array behind whatever a legacy controller handed back.
     *
     * is_mobile() returns a JsonResponse under type=API and a View otherwise,
     * and these legacy methods are called directly here rather than through the
     * router, so neither shape can be assumed.
     */
    private function decodeControllerJson($response): ?array
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $decoded = $response->getData(true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_array($response)) {
            return $response;
        }

        if ($response instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $response->toArray();
        }

        return null;
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
