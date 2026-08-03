<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link sessions to trainer records properly.
 *
 * lms_virtual_classroom stores the trainer as free-text trainer_name and
 * trainer_email, so counting a trainer's sessions meant string-matching on
 * email and falling back to name. That is fragile: a typo, a changed surname or
 * a personal address instead of a work one silently splits one person into two,
 * and the trainer list under-reports.
 *
 * Additive and nullable. trainer_name / trainer_email are deliberately kept:
 * they are what every existing row and the session form already write, and
 * removing them would break the sessions module. trainer_id is the precise link
 * when it is set, with the string match remaining as the fallback for rows that
 * predate a matching trainer record.
 *
 * The backfill below only claims rows it can match unambiguously by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_virtual_classroom', function (Blueprint $table) {
            $table->unsignedBigInteger('trainer_id')->nullable()->after('trainer_email')->index();
        });

        // Match on email only, and only where exactly one trainer holds that
        // address - a name match would be guessing.
        if (Schema::hasTable('lms_trainers')) {
            $trainers = DB::table('lms_trainers')
                ->whereNull('deleted_at')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->select('id', 'email', 'sub_institute_id')
                ->get()
                ->groupBy(fn ($trainer) => strtolower($trainer->sub_institute_id . '|' . $trainer->email));

            foreach ($trainers as $group) {
                if ($group->count() !== 1) {
                    continue;
                }

                $trainer = $group->first();

                DB::table('lms_virtual_classroom')
                    ->where('sub_institute_id', $trainer->sub_institute_id)
                    ->whereRaw('LOWER(TRIM(trainer_email)) = ?', [strtolower(trim($trainer->email))])
                    ->whereNull('trainer_id')
                    ->update(['trainer_id' => $trainer->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('lms_virtual_classroom', function (Blueprint $table) {
            $table->dropIndex(['trainer_id']);
            $table->dropColumn('trainer_id');
        });
    }
};
