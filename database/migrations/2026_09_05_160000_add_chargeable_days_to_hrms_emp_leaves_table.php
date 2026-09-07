<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Leave\LeaveDayCounter;

/**
 * What a leave request actually costs, stored on the row. F-95.
 *
 * The number was being re-derived in THREE places - countDays() from
 * LeaveAnalyticsService::requestDays() and ::consumedByType(), and a raw SQL
 * copy in LeaveReportApiController::DAYS_EXPR - and all three counted calendar
 * days, so a Saturday-to-Sunday request cost two days in a tenant whose own
 * configuration says one is a half day and the other is not worked at all.
 *
 * Computing it once at write time and storing it fixes the arithmetic and the
 * duplication together: the reports sum a column instead of re-deriving it, so
 * they cannot drift from what the employee was told when they applied.
 *
 * It also freezes the charge. If HR adds a public holiday next year, an already
 * approved request keeps the cost it was approved at - which is what an HR
 * system has to do, and what a live recalculation could not.
 *
 * The backfill uses the same service the application uses, so historical rows
 * get the corrected number rather than the old calendar-day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('hrms_emp_leaves', 'chargeable_days')) {
            Schema::table('hrms_emp_leaves', function (Blueprint $table) {
                // Nullable: a row whose dates cannot be parsed keeps NULL rather
                // than a wrong 0, and the readers fall back for it.
                $table->decimal('chargeable_days', 6, 2)->nullable()->after('to_date');
            });
        }

        $counter = new LeaveDayCounter();

        DB::table('hrms_emp_leaves as hel')
            ->leftJoin('tbluser as u', 'u.id', '=', 'hel.user_id')
            ->whereNull('hel.chargeable_days')
            ->orderBy('hel.id')
            ->select(['hel.id', 'hel.sub_institute_id', 'hel.from_date', 'hel.to_date', 'hel.day_type', 'u.department_id'])
            ->chunk(200, function ($rows) use ($counter) {
                foreach ($rows as $row) {
                    if (!$row->from_date || !$row->sub_institute_id) {
                        continue;
                    }

                    $days = $counter->daysFor(
                        (int) $row->sub_institute_id,
                        $row->department_id ? (int) $row->department_id : null,
                        $row->from_date,
                        $row->to_date,
                        $row->day_type ?: '1'
                    );

                    DB::table('hrms_emp_leaves')->where('id', $row->id)->update(['chargeable_days' => $days]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('hrms_emp_leaves', 'chargeable_days')) {
            Schema::table('hrms_emp_leaves', function (Blueprint $table) {
                $table->dropColumn('chargeable_days');
            });
        }
    }
};
