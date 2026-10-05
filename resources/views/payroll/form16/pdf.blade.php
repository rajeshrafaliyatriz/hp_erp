<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
    .header { text-align: center; margin-bottom: 14px; }
    .header h1 { font-size: 16px; margin: 0 0 2px; }
    .header .addr { font-size: 10px; color: #555; }
    .title { text-align: center; font-size: 13px; font-weight: bold; margin: 10px 0; text-transform: uppercase; }
    .meta-table, .amount-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .meta-table td { padding: 3px 4px; vertical-align: top; }
    .meta-table td.label { color: #555; width: 140px; }
    .amount-table th, .amount-table td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
    .amount-table th { background: #f2f2f2; }
    .amount-table td.amount, .amount-table th.amount { text-align: right; }
    .section-title { font-weight: bold; font-size: 12px; margin: 14px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
    .total-row td { font-weight: bold; border-top: 2px solid #999; }
    .disclaimer { margin-top: 18px; font-size: 9px; color: #777; border-top: 1px solid #eee; padding-top: 8px; }
</style>
</head>
<body>
    <div class="header">
        <h1>{{ $school->SchoolName ?? 'Organisation' }}</h1>
        <div class="addr">{{ $school->ReceiptAddress ?? '' }}</div>
    </div>

    <div class="title">Form 16 &mdash; Salary &amp; Tax Summary for FY {{ $fromDate }} to {{ $toDate }}</div>

    <table class="meta-table">
        <tr>
            <td class="label">Employee Name</td>
            <td>{{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</td>
            <td class="label">Employee No.</td>
            <td>{{ $employee->employee_no ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Department</td>
            <td>{{ $departmentName ?? '-' }}</td>
            <td class="label">PAN</td>
            <td>{{ $employee->pan_no ?: 'Not on file' }}</td>
        </tr>
        <tr>
            <td class="label">Date of Joining</td>
            <td>{{ $employee->joined_date ?? '-' }}</td>
            <td class="label">Financial Year</td>
            <td>{{ $fromDate }} to {{ $toDate }}</td>
        </tr>
    </table>

    <div class="section-title">Earnings</div>
    <table class="amount-table">
        <tr><th>Head</th><th class="amount">Amount (&#8377;)</th></tr>
        @foreach ($earnings as $row)
        <tr><td>{{ $row['label'] }}</td><td class="amount">{{ number_format($row['amount'], 2) }}</td></tr>
        @endforeach
        <tr class="total-row"><td>Gross Earnings</td><td class="amount">{{ number_format($grossEarnings, 2) }}</td></tr>
    </table>

    <div class="section-title">Deductions</div>
    <table class="amount-table">
        <tr><th>Head</th><th class="amount">Amount (&#8377;)</th></tr>
        @foreach ($deductions as $row)
        <tr><td>{{ $row['label'] }}</td><td class="amount">{{ number_format($row['amount'], 2) }}</td></tr>
        @endforeach
        <tr class="total-row"><td>Total Deductions</td><td class="amount">{{ number_format($totalDeductions, 2) }}</td></tr>
    </table>

    <table class="amount-table">
        <tr class="total-row"><td>Net Pay for the Year</td><td class="amount">{{ number_format($grossEarnings - $totalDeductions, 2) }}</td></tr>
    </table>

    <div class="disclaimer">
        This is a system-generated summary of salary and deduction figures for the period shown, computed from
        this organisation's payroll records. It is <strong>not a statutory Form 16</strong> under the Income Tax
        Act &mdash; that certificate is issued by the deductor against TDS actually filed and deposited with the
        government, which this document does not represent. Please consult your organisation's accountant for a
        statutory certificate.
    </div>
</body>
</html>
