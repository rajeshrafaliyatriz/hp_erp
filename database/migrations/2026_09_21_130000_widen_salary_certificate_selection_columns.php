<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-212. The salary certificate could not cover a whole year.
 *
 * `hrms_salary_certificate` records which months and which pay heads a
 * certificate states, and it records them as comma-joined id lists:
 *
 *   PayrollController.php  'month' => implode(',', $request->get('month_id'))
 *
 * `month` is varchar(20). Twelve months joined is
 *
 *   1,2,3,4,5,6,7,8,9,10,11,12    -> 26 characters
 *
 * so the insert died with
 *
 *   SQLSTATE[22001]: String data, right truncated: 1406
 *   Data too long for column 'month' at row 1
 *
 * rendered as HTTP 500. THIS WAS NOT ONLY THE NEW EMPLOYEE ENDPOINT. The HR
 * Salary Certificate screen lets a user tick all twelve months, and has always
 * crashed when they did - eight months (1,2,3,4,5,6,7,8 = 15 chars) fits,
 * eleven (1,2,3,4,5,6,7,8,9,10,11 = 23) does not. A certificate covering a full
 * financial year is the ordinary case for a bank or a visa application, so the
 * most useful thing the screen can produce is the thing it refused.
 *
 * `payroll_type_id` has the same shape and the same fault one column across:
 * varchar(50) holds about seventeen two-digit head ids, and an organisation
 * with more earning heads than that would hit it the same way. Widened
 * together rather than waiting for the second bug report.
 *
 * NOT A DATA CHANGE. Both columns are widened, never narrowed, so every
 * existing value stays valid and no row is rewritten. The table held 0 rows
 * platform-wide until very recently (F-110 made the builder fatal on every
 * combination), so there is almost nothing to preserve in any case.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-21-salary-certificate-columns.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Raw DDL rather than the Schema builder. This runs against MariaDB
         * 10.1 on 128.199.17.97, where the builder's column introspection is
         * unreliable (the same reason this audit queries information_schema
         * directly elsewhere), and doctrine/dbal is not installed - without it
         * Schema::table()->change() throws.
         */
        DB::statement('ALTER TABLE hrms_salary_certificate MODIFY month VARCHAR(64) NULL');
        DB::statement('ALTER TABLE hrms_salary_certificate MODIFY payroll_type_id VARCHAR(255) NULL');
    }

    public function down(): void
    {
        /*
         * Narrowing back can only succeed while no stored value is longer than
         * the old limit - which is precisely what this migration exists to
         * allow. So the rollback checks first and refuses rather than letting
         * MySQL truncate somebody's certificate silently.
         */
        $tooLong = DB::table('hrms_salary_certificate')
            ->whereRaw('CHAR_LENGTH(month) > 20 OR CHAR_LENGTH(payroll_type_id) > 50')
            ->count();

        if ($tooLong > 0) {
            throw new \RuntimeException(
                "Refusing to narrow hrms_salary_certificate: {$tooLong} row(s) hold a month or "
                . 'pay-head list that would be truncated. Delete or shorten those certificates first.'
            );
        }

        DB::statement('ALTER TABLE hrms_salary_certificate MODIFY month VARCHAR(20) NULL');
        DB::statement('ALTER TABLE hrms_salary_certificate MODIFY payroll_type_id VARCHAR(50) NULL');
    }
};
