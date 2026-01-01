<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert existing string interviewer_id to JSON array
        $records = DB::table('talent_interview_schedules')->whereNotNull('interviewer_id')->where('interviewer_id', '!=', '')->get();
        foreach ($records as $record) {
            $ids = explode(',', $record->interviewer_id);
            $json = json_encode(array_map('intval', array_map('trim', $ids)));
            DB::table('talent_interview_schedules')->where('id', $record->id)->update(['interviewer_id' => $json]);
        }

        Schema::table('talent_interview_schedules', function (Blueprint $table) {
            $table->json('interviewer_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert JSON array back to comma-separated string
        $records = DB::table('talent_interview_schedules')->whereNotNull('interviewer_id')->get();
        foreach ($records as $record) {
            $ids = json_decode($record->interviewer_id, true);
            $string = implode(',', $ids);
            DB::table('talent_interview_schedules')->where('id', $record->id)->update(['interviewer_id' => $string]);
        }

        Schema::table('talent_interview_schedules', function (Blueprint $table) {
            $table->string('interviewer_id')->change();
        });
    }
};
