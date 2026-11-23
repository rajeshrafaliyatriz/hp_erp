<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HRMS\Concerns\ExpenseRoles;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Employee Ledger: /api/ledger.
 *
 * One account per employee. Nothing is stored as a ledger line: every line is
 * worked out, on request, from the records that already hold the truth, so the
 * ledger can never disagree with them.
 *
 * ── THE ACCOUNT ─────────────────────────────────────────────────────────────
 *
 * Credit  (+)  what the company owes the employee
 * Debit   (−)  what has been paid to them, or what they hold
 *
 *   salary            credit  net salary of each processed payroll month
 *   salary_payment    debit   a payment recorded against that month
 *   advance_paid      debit   an advance handed over (they now hold it)
 *   expense_approved  credit  an approved claim, less what an advance covers
 *   advance_settled   credit  the part of a claim met from an advance, when the
 *                             claim is paid - the advance stops being held
 *   expense_paid      debit   the cash actually paid out on a claim
 *
 * Balance = credits − debits. Positive: the company owes the employee. Negative:
 * the employee holds money (an advance not yet claimed) and owes it back. It
 * equals  salary still due + expenses still to pay − advance still unclaimed,
 * which is what the summary shows column by column.
 *
 * ── WHO MAY SEE WHAT ────────────────────────────────────────────────────────
 *
 * HR and administrators see everyone's. Anybody else sees only their OWN row and
 * statement, and is refused anybody else's: this is salary. Manager, executive
 * and auditor are deliberately not given the organisation-wide view.
 * Recording or reversing a salary payment is HR/admin, enforced in routes/api.php
 * and again here, and never on your own salary.
 *
 * Salary comes from `employee_monthly_salary_data` (net = total_payment). If an
 * employee has no processed payroll, they simply have no salary lines.
 */
class EmployeeLedgerController extends Controller
{
    use ResolvesApiIdentity;
    use ExpenseRoles;

    // Order of lines that fall on the same date.
    private const ORDER = [
        'salary' => 1,
        'advance_paid' => 2,
        'expense_approved' => 3,
        'advance_settled' => 4,
        'expense_paid' => 5,
        'salary_payment' => 6,
    ];

    /** GET /api/ledger - one row per employee (only your own unless you are HR/admin). */
    public function summary(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $hr = $this->isHr(RoleKey::forUserId($userId));

        $employees = DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', function ($join) {
                $join->on('d.id', '=', 'u.department_id')->whereNull('d.deleted_at');
            })
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.deleted_at')
            ->when(!$hr, fn ($q) => $q->where('u.id', $userId))
            ->orderBy('u.first_name')
            ->get([
                'u.id', 'u.status', 'u.employee_no',
                DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as name"),
                DB::raw('d.department as department'),
            ]);

        $byEmployee = $this->entries($tenantId, $hr ? null : [$userId])->groupBy('employee_id');

        $rows = [];
        foreach ($employees as $e) {
            $lines = $byEmployee->get($e->id, collect());

            // Everyone active is listed even at zero; a leaver only if they still have a balance history.
            if ((int) $e->status !== 1 && $lines->isEmpty()) {
                continue;
            }

            $rows[] = array_merge([
                'employee_id' => (int) $e->id,
                'employee_name' => $e->name ?: '—',
                'employee_no' => $e->employee_no,
                'department' => $e->department,
                'active' => (int) $e->status === 1,
            ], $this->totalsFor($lines));
        }

        $sum = fn (string $key) => round(array_sum(array_column($rows, $key)), 2);

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'scope' => $hr ? 'all' : 'own',
            'data' => $rows,
            'totals' => [
                'employees' => count($rows),
                'salary_due' => $sum('salary_due'),
                'advance_unclaimed' => $sum('advance_unclaimed'),
                'expense_due' => $sum('expense_due'),
                'net_balance' => $sum('net_balance'),
            ],
        ]);
    }

    /** GET /api/ledger/{employeeId}  (?from=&to=) - the statement, with a running balance. */
    public function statement(Request $request, $employeeId)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $hr = $this->isHr(RoleKey::forUserId($userId));
        $employeeId = (int) $employeeId;

        $employee = $this->employee($employeeId, $tenantId);
        if (!$employee) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }
        if (!$hr && $employeeId !== $userId) {
            return response()->json(['status' => 0, 'message' => 'You can only see your own ledger.'], 403);
        }

        $validator = Validator::make($request->all(), ['from' => 'nullable|date', 'to' => 'nullable|date']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $all = $this->entries($tenantId, [$employeeId])->sortBy([['date', 'asc'], ['order', 'asc'], ['seq', 'asc']])->values();

        $from = $request->filled('from') ? date('Y-m-d', strtotime((string) $request->input('from'))) : null;
        $to = $request->filled('to') ? date('Y-m-d', strtotime((string) $request->input('to'))) : null;

        $opening = $from
            ? round($all->filter(fn ($l) => $l['date'] < $from)->sum(fn ($l) => $l['credit'] - $l['debit']), 2)
            : 0.0;

        $balance = $opening;
        $lines = [];
        foreach ($all as $l) {
            if (($from && $l['date'] < $from) || ($to && $l['date'] > $to)) {
                continue;
            }
            $balance = round($balance + $l['credit'] - $l['debit'], 2);
            $lines[] = [
                'date' => $l['date'],
                'type' => $l['type'],
                'particulars' => $l['particulars'],
                'reference' => $l['reference'],
                'debit' => $l['debit'],
                'credit' => $l['credit'],
                'balance' => $balance,
                'payment_id' => $l['payment_id'],
            ];
        }

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => [
                'employee' => [
                    'id' => (int) $employee->id,
                    'name' => $employee->name ?: '—',
                    'employee_no' => $employee->employee_no,
                    'department' => $employee->department,
                    'active' => (int) $employee->status === 1,
                ],
                'summary' => $this->totalsFor($all),
                'opening_balance' => $opening,
                'closing_balance' => $balance,
                'lines' => $lines,
                'salary_months' => $this->salaryMonths($all),
                'can' => [
                    // Never on your own salary.
                    'record_payment' => $hr && $employeeId !== $userId,
                    'reverse_payment' => $hr && $employeeId !== $userId,
                ],
            ],
        ]);
    }

    /** POST /api/ledger/salary-payments   { employee_id, month, year, amount, paid_on, payment_reference?, note? }   HR/admin */
    public function recordSalaryPayment(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        if (!$this->isHr(RoleKey::forUserId($userId))) {
            return response()->json(['status' => 0, 'message' => 'Only HR can record a salary payment.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|min:1',
            'month' => 'required|string|max:20',
            'year' => 'required|integer|min:2000|max:2100',
            'amount' => 'required|numeric|gt:0|max:9999999999.99',
            'paid_on' => 'required|date|before_or_equal:today',
            'payment_reference' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:255',
        ], [
            'paid_on.before_or_equal' => 'A payment cannot be dated in the future.',
            'amount.gt' => 'The amount must be more than zero.',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $employeeId = (int) $request->input('employee_id');

        if ($employeeId === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot record a payment on your own salary. Ask another HR user.'], 403);
        }
        if (!$this->employee($employeeId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Employee not found'], 404);
        }

        $month = $this->salaryRow($tenantId, $employeeId, (string) $request->input('month'), (int) $request->input('year'));
        if (!$month) {
            return response()->json(['status' => 0, 'message' => 'There is no processed salary for that month, so there is nothing to pay against.'], 422);
        }

        $net = round((float) $month->total_payment, 2);
        $paid = round((float) DB::table('hrms_salary_payments')
            ->where('sub_institute_id', $tenantId)->where('employee_id', $employeeId)
            ->where('month', $month->month)->where('year', (int) $month->year)
            ->whereNull('deleted_at')->sum('amount'), 2);
        $due = round($net - $paid, 2);
        $amount = round((float) $request->input('amount'), 2);

        if ($amount > $due + 0.001) {
            return response()->json([
                'status' => 0,
                'message' => 'Only ' . number_format(max($due, 0), 2) . ' is still due for ' . $month->month . ' ' . $month->year . '.',
            ], 422);
        }

        $id = DB::table('hrms_salary_payments')->insertGetId([
            'sub_institute_id' => $tenantId,
            'employee_id' => $employeeId,
            'month' => $month->month,
            'year' => (int) $month->year,
            'amount' => $amount,
            'paid_on' => date('Y-m-d', strtotime((string) $request->input('paid_on'))),
            'payment_reference' => $request->filled('payment_reference') ? trim($request->input('payment_reference')) : null,
            'note' => $request->filled('note') ? trim($request->input('note')) : null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => 'Salary payment recorded.',
            'data' => ['id' => $id, 'month_due' => round($due - $amount, 2)],
        ], 201);
    }

    /** DELETE /api/ledger/salary-payments/{id}   HR/admin - reverses a mistaken entry. */
    public function reverseSalaryPayment(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        if (!$this->isHr(RoleKey::forUserId($userId))) {
            return response()->json(['status' => 0, 'message' => 'Only HR can reverse a salary payment.'], 403);
        }

        $payment = DB::table('hrms_salary_payments')
            ->where('id', (int) $id)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first();

        if (!$payment) {
            return response()->json(['status' => 0, 'message' => 'Payment not found'], 404);
        }
        if ((int) $payment->employee_id === $userId) {
            return response()->json(['status' => 0, 'message' => 'You cannot reverse a payment on your own salary. Ask another HR user.'], 403);
        }

        DB::table('hrms_salary_payments')->where('id', $payment->id)->update([
            'deleted_at' => now(),
            'deleted_by' => $userId,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Payment reversed.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every ledger line for these employees (or, with null, the whole organisation).
     *
     * @param int[]|null $ids
     * @return \Illuminate\Support\Collection<int,array>
     */
    private function entries(int $tenantId, ?array $ids)
    {
        $lines = collect();
        $seq = 0;
        $push = function (int $employeeId, string $date, string $type, string $particulars, ?string $reference, float $debit, float $credit, ?int $paymentId = null, ?string $monthKey = null) use (&$lines, &$seq) {
            $lines->push([
                'employee_id' => $employeeId,
                'date' => $date,
                'order' => self::ORDER[$type],
                'seq' => $seq++,
                'type' => $type,
                'particulars' => $particulars,
                'reference' => $reference,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'payment_id' => $paymentId,
                'month_key' => $monthKey,
            ]);
        };

        // Salary: the latest processed row per employee and month (a month can be re-run).
        $salary = DB::table('employee_monthly_salary_data')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('total_payment')
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->orderBy('id')
            ->get(['employee_id', 'month', 'year', 'salary_amount', 'total_deduction', 'total_payment'])
            ->keyBy(fn ($r) => $r->employee_id . '|' . strtolower($r->month) . '|' . $r->year);

        foreach ($salary as $r) {
            $net = (float) $r->total_payment;
            $push(
                (int) $r->employee_id,
                $this->monthEnd((string) $r->month, (int) $r->year),
                'salary',
                'Salary for ' . $r->month . ' ' . $r->year
                    . ' (gross ' . number_format((float) $r->salary_amount, 2) . ', deductions ' . number_format((float) $r->total_deduction, 2) . ')',
                null,
                0.0,
                $net,
                null,
                strtolower($r->month) . '|' . $r->year,
            );
        }

        DB::table('hrms_salary_payments')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->get(['id', 'employee_id', 'month', 'year', 'amount', 'paid_on', 'payment_reference', 'note'])
            ->each(function ($p) use ($push) {
                $push(
                    (int) $p->employee_id,
                    (string) $p->paid_on,
                    'salary_payment',
                    'Salary paid for ' . $p->month . ' ' . $p->year . ($p->note ? ' – ' . $p->note : ''),
                    $p->payment_reference,
                    (float) $p->amount,
                    0.0,
                    (int) $p->id,
                    strtolower($p->month) . '|' . $p->year,
                );
            });

        DB::table('hrms_expense_advances')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['paid', 'claimed'])
            ->whereNotNull('paid_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->get(['employee_id', 'advance_no', 'purpose', 'advance_amount', 'paid_at', 'payment_reference'])
            ->each(function ($a) use ($push) {
                $push(
                    (int) $a->employee_id,
                    substr((string) $a->paid_at, 0, 10),
                    'advance_paid',
                    'Advance paid – ' . $a->purpose,
                    $a->advance_no . ($a->payment_reference ? ' · ' . $a->payment_reference : ''),
                    (float) $a->advance_amount,
                    0.0,
                );
            });

        $claims = DB::table('hrms_expense_claims as c')
            ->leftJoin('hrms_expense_advances as a', 'a.id', '=', 'c.advance_id')
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', ['approved', 'paid'])
            ->when($ids !== null, fn ($q) => $q->whereIn('c.employee_id', $ids))
            ->get([
                'c.employee_id', 'c.claim_no', 'c.title', 'c.status', 'c.total_amount', 'c.advance_adjusted',
                'c.decided_at', 'c.paid_at', 'c.payment_reference', 'a.advance_no',
            ]);

        foreach ($claims as $c) {
            $total = (float) $c->total_amount;
            $adjusted = (float) $c->advance_adjusted;
            $net = round($total - $adjusted, 2);
            $employeeId = (int) $c->employee_id;
            $approvedOn = substr((string) ($c->decided_at ?: $c->paid_at), 0, 10);

            if ($net > 0) {
                $push($employeeId, $approvedOn, 'expense_approved', 'Expense approved – ' . $c->title, $c->claim_no, 0.0, $net);
            }

            if ($c->status === 'paid' && $c->paid_at) {
                $paidOn = substr((string) $c->paid_at, 0, 10);

                if ($adjusted > 0) {
                    $push($employeeId, $paidOn, 'advance_settled', 'Advance ' . ($c->advance_no ?? '') . ' used against ' . $c->claim_no . ' – ' . $c->title, $c->claim_no, 0.0, $adjusted);
                }
                if ($net > 0) {
                    $push($employeeId, $paidOn, 'expense_paid', 'Expense paid – ' . $c->title, $c->claim_no . ($c->payment_reference ? ' · ' . $c->payment_reference : ''), $net, 0.0);
                }
            }
        }

        return $lines;
    }

    /** The column-by-column figures for one employee's lines. */
    private function totalsFor($lines): array
    {
        $credit = fn (string $type) => round($lines->where('type', $type)->sum('credit'), 2);
        $debit = fn (string $type) => round($lines->where('type', $type)->sum('debit'), 2);

        $salaryEarned = $credit('salary');
        $salaryPaid = $debit('salary_payment');
        $advancePaid = $debit('advance_paid');
        $advanceSettled = $credit('advance_settled');
        $expenseApproved = $credit('expense_approved');
        $expensePaid = $debit('expense_paid');

        $latest = $lines->where('type', 'salary')->sortBy('date')->last();

        return [
            'latest_salary' => $latest ? $latest['credit'] : 0.0,
            'salary_earned' => $salaryEarned,
            'salary_paid' => $salaryPaid,
            'salary_due' => round($salaryEarned - $salaryPaid, 2),
            'advance_paid' => $advancePaid,
            'advance_settled' => $advanceSettled,
            'advance_unclaimed' => round($advancePaid - $advanceSettled, 2),
            'expense_approved' => $expenseApproved,
            'expense_paid' => $expensePaid,
            'expense_due' => round($expenseApproved - $expensePaid, 2),
            // Positive: the company owes the employee. Negative: they hold an advance.
            'net_balance' => round($lines->sum('credit') - $lines->sum('debit'), 2),
        ];
    }

    /** One entry per processed month, with what has been paid against it - for the "record payment" form. */
    private function salaryMonths($lines): array
    {
        $out = [];

        foreach ($lines->where('type', 'salary')->sortBy('date')->values() as $l) {
            [$month, $year] = explode('|', (string) $l['month_key']);
            $paid = round($lines->where('type', 'salary_payment')->where('month_key', $l['month_key'])->sum('debit'), 2);

            // The stored month name keeps its own case, which the label wants.
            preg_match('/^Salary for (\S+) /', $l['particulars'], $m);

            $out[] = [
                'month' => $m[1] ?? $month,
                'year' => (int) $year,
                'label' => ($m[1] ?? $month) . ' ' . $year,
                'net' => $l['credit'],
                'paid' => $paid,
                'due' => round($l['credit'] - $paid, 2),
            ];
        }

        return $out;
    }

    private function employee(int $id, int $tenantId)
    {
        return DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', function ($join) {
                $join->on('d.id', '=', 'u.department_id')->whereNull('d.deleted_at');
            })
            ->where('u.id', $id)
            ->where('u.sub_institute_id', $tenantId)
            ->whereNull('u.deleted_at')
            ->first([
                'u.id', 'u.status', 'u.employee_no',
                DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as name"),
                DB::raw('d.department as department'),
            ]);
    }

    /** The processed row for an employee's month: the latest one, matched on the month name without regard to case. */
    private function salaryRow(int $tenantId, int $employeeId, string $month, int $year)
    {
        return DB::table('employee_monthly_salary_data')
            ->where('sub_institute_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereRaw('LOWER(month) = ?', [strtolower(trim($month))])
            ->where('year', $year)
            ->whereNull('deleted_at')
            ->whereNotNull('total_payment')
            ->orderByDesc('id')
            ->first(['month', 'year', 'total_payment']);
    }

    /** "Aug", 2026 -> 2026-08-31. Payroll names months by their three letters. */
    private function monthEnd(string $month, int $year): string
    {
        $ts = strtotime('1 ' . $month . ' ' . $year);

        return $ts ? date('Y-m-t', $ts) : $year . '-12-31';
    }
}
