<?php

use App\Traits\Helpers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One spelling of the month, then one payslip per employee-month. F-137, F-138.
 *
 * `employee_monthly_salary_data.month` is a free-form varchar and live data
 * holds two incompatible formats:
 *
 *     SELECT DISTINCT month  ->  'May'  'Aug'  'july'
 *
 * The payroll screen posts what Helpers::getMonths() returns - 'Jul' - so the
 * seventeen rows stored as 'july' were unreachable by every query that matches
 * on month. That included F-109's duplicate-collapsing upsert, whose entire
 * purpose was those seventeen rows. The Sprint 6 probe printed
 * "employee-months holding more than one payslip: 1" and passed anyway: it
 * observed the surviving cluster and did not assert against it.
 *
 * utf8mb4_unicode_ci ignores case but not length, so no collation makes
 * 'Jul' = 'july'. This has to be fixed in the data.
 *
 * WHAT IS SAFE HERE, and why this is defensible on real payslips: all 17 rows
 * are byte-identical in every figure -
 *
 *     total_payment 35000.00   total_deduction 100.00   total_day 10.00
 *     employee_salary_data {"6":"7000"}      1 distinct value each
 *
 * They differ only in `id` and `created_at`. Collapsing them loses NO financial
 * information whatsoever. The earliest row (id 5) is kept, so the payslip's
 * original created_at survives.
 *
 * ORDER MATTERS. Normalise, then collapse, then index - the unique key cannot be
 * created while seventeen rows share it, and normalising WITHOUT collapsing
 * would be worse than doing nothing: it would make all seventeen match, so the
 * very next save would delete sixteen financial rows in one go.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 1. Every stored month to its canonical spelling.
            foreach (DB::table('employee_monthly_salary_data')->distinct()->pluck('month') as $stored) {
                $canonical = Helpers::canonicalMonth($stored);

                if ($canonical === null) {
                    throw new RuntimeException(
                        "employee_monthly_salary_data holds a month this system cannot name: "
                        . var_export($stored, true) . '. Resolve it by hand before running this - '
                        . 'guessing which month a payslip belongs to is not something a migration '
                        . 'should do.'
                    );
                }

                if ($canonical !== $stored) {
                    DB::table('employee_monthly_salary_data')
                        ->where('month', $stored)
                        ->update(['month' => $canonical]);
                }
            }

            // payroll_month_locks keys on the same string, so a lock taken as
            // 'Jul' was bypassable by posting 'july'. Empty today, which makes
            // this free now and awkward later.
            foreach (DB::table('payroll_month_locks')->distinct()->pluck('month') as $stored) {
                $canonical = Helpers::canonicalMonth($stored);

                if ($canonical !== null && $canonical !== $stored) {
                    DB::table('payroll_month_locks')->where('month', $stored)->update(['month' => $canonical]);
                }
            }

            // 2. Collapse duplicates, keeping the earliest row of each group.
            $groups = DB::table('employee_monthly_salary_data')
                ->select('sub_institute_id', 'employee_id', 'year', 'month')
                ->selectRaw('MIN(id) AS keep_id, COUNT(*) AS n')
                ->groupBy('sub_institute_id', 'employee_id', 'year', 'month')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($groups as $group) {
                $doomed = DB::table('employee_monthly_salary_data')
                    ->where([
                        'sub_institute_id' => $group->sub_institute_id,
                        'employee_id'      => $group->employee_id,
                        'year'             => $group->year,
                        'month'            => $group->month,
                    ])
                    ->where('id', '<>', $group->keep_id)
                    ->get();

                /*
                 * The before-image goes to the event store before the rows go.
                 * g2g_event is append-only and AuditLogProjector consumes every
                 * type, so this is recoverable from the record rather than only
                 * from a backup - and the reversal script carries literal
                 * INSERTs as well, because a migration should not depend on a
                 * projector having run.
                 */
                app(\App\Services\Events\EventRecorder::class)->record(
                    'payroll.payslip.superseded',
                    (int) $group->sub_institute_id,
                    'employee_monthly_salary_data',
                    (int) $group->keep_id,
                    null, // SYSTEM: a migration, not a person.
                    [
                        'employee_id' => (int) $group->employee_id,
                        'month'       => $group->month,
                        'year'        => (int) $group->year,
                        'kept_id'     => (int) $group->keep_id,
                        'reason'      => 'F-137 month canonicalisation; duplicates were financially identical',
                        'superseded'  => $doomed->map(fn ($r) => (array) $r)->all(),
                    ],
                    null,
                    'payroll.month.canonicalised:' . $group->sub_institute_id . ':' . $group->employee_id
                        . ':' . $group->month . ':' . $group->year
                );

                DB::table('employee_monthly_salary_data')
                    ->whereIn('id', $doomed->pluck('id'))
                    ->delete();
            }

            // 3. The filed payslip document names the old spelling. Left alone,
            //    the next save's checkDoc lookup - which keys on file_name -
            //    misses and files a SECOND document row for the same month.
            foreach (DB::table('staff_document')->where('document_type_id', 56)->get() as $doc) {
                $renamed = preg_replace_callback(
                    '/_payslip_([A-Za-z]+)_/',
                    fn ($m) => '_payslip_' . (Helpers::canonicalMonth($m[1]) ?? $m[1]) . '_',
                    (string) $doc->file_name
                );

                $retitled = preg_replace_callback(
                    '/^Payslip ([A-Za-z]+) /',
                    fn ($m) => 'Payslip ' . (Helpers::canonicalMonth($m[1]) ?? $m[1]) . ' ',
                    (string) $doc->document_title
                );

                if ($renamed !== $doc->file_name || $retitled !== $doc->document_title) {
                    DB::table('staff_document')->where('id', $doc->id)->update([
                        'file_name'      => $renamed,
                        'document_title' => $retitled,
                        'updated_at'     => now(),
                    ]);
                }
            }
        });

        /*
         * 4. And now the guarantee. Outside the transaction because DDL commits
         *    implicitly in MariaDB anyway - wrapping it would be a comfortable
         *    lie about atomicity.
         *
         *    This is what makes F-109 a property of the database rather than of
         *    one method remembering to check. Two concurrent saves now produce a
         *    duplicate-key error instead of a second payslip.
         */
        DB::statement(
            'ALTER TABLE employee_monthly_salary_data
               ADD UNIQUE KEY employee_monthly_salary_data_period_unique
               (sub_institute_id, employee_id, year, month)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employee_monthly_salary_data DROP INDEX employee_monthly_salary_data_period_unique');

        /*
         * The month values and the collapsed rows are NOT restored here.
         *
         * Rolling back to 'july' would put the seventeen rows back out of reach
         * of every query that matches on month - the defect, not a prior state
         * worth returning to - and re-inserting them needs their original ids
         * and created_at, which a down() cannot reconstruct.
         *
         * Both are in the reversal script, with the literal INSERTs:
         *   Docs/hrit-audit/_reversals/REVERSAL-2026-09-07-payroll-month-canonical.sql
         */
    }
};
