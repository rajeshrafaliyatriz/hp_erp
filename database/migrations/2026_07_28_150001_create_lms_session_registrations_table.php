<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on a training session.
 *
 * No attendee entity existed anywhere - lms_session_registration,
 * session_registrations and lms_virtual_classroom_student were all absent - so
 * seat counts, the Total Registrations KPI and the Open / Almost-full / Full
 * status had nothing behind them.
 *
 * A learner may self-register for an open session, and an admin may register or
 * remove people directly. Cancelling keeps the row (status = 'cancelled') so
 * the history survives, and only 'registered' rows consume a seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_session_registrations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // lms_virtual_classroom.id
            $table->unsignedBigInteger('session_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            $table->enum('status', ['registered', 'attended', 'cancelled', 'no-show'])
                  ->default('registered')
                  ->index();

            $table->timestamp('registered_at')->nullable();
            // Set when an admin adds someone rather than the learner signing up.
            $table->unsignedBigInteger('registered_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One live registration per learner per session; re-registering
            // after cancelling reuses the row.
            $table->unique(['session_id', 'user_id'], 'lms_session_reg_session_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_session_registrations');
    }
};
