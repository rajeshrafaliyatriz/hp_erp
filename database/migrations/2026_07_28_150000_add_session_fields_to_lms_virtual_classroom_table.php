<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training-session fields on lms_virtual_classroom.
 *
 * The table already carries what a scheduled session needs structurally -
 * event_date, from_time, to_time, url, password, status - which is why it is
 * extended rather than duplicated by a second scheduling entity. What it lacks
 * is everything the Sessions page shows around the slot itself: who runs it,
 * where, and how many people fit.
 *
 * All nullable; the table has no rows today, and the Blade screens that write
 * it never send these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_virtual_classroom', function (Blueprint $table) {
            // 'virtual' sessions join through `url`; 'classroom' ones meet at `venue`.
            $table->string('session_type', 20)->nullable()->default('virtual')->after('room_name');
            $table->string('trainer_name', 191)->nullable()->after('description');
            $table->string('trainer_email', 191)->nullable()->after('trainer_name');
            $table->string('venue', 191)->nullable()->after('trainer_email');
            // Null means uncapped - Open/Almost-full/Full is only derived when set.
            $table->unsignedSmallInteger('seats_total')->nullable()->after('venue');
            $table->text('notes')->nullable()->after('seats_total');
        });
    }

    public function down(): void
    {
        Schema::table('lms_virtual_classroom', function (Blueprint $table) {
            $table->dropColumn([
                'session_type',
                'trainer_name',
                'trainer_email',
                'venue',
                'seats_total',
                'notes',
            ]);
        });
    }
};
