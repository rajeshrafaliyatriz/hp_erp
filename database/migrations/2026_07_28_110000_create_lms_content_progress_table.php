<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-content-item learning progress.
 *
 * Nothing tracked a learner's position inside a course before this: the only
 * signal was lms_course_enroll.status, a single string for the whole course.
 * The previous frontend kept lesson completion in localStorage, so it was lost
 * on any other device and invisible to reporting.
 *
 * One row per (user, content). course_id and chapter_id are denormalised so the
 * course-level percentage can be computed without walking content_master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_content_progress', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->index();
            // sub_std_map.id - the course, matching lms_course_enroll.course_id.
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->unsignedBigInteger('content_id')->index();

            $table->enum('status', ['not-started', 'in-progress', 'completed'])
                  ->default('not-started')
                  ->index();

            // Resume point for video/audio content, in seconds.
            $table->unsignedInteger('last_position_seconds')->nullable();
            // Accumulated time on this item, in seconds.
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A learner has exactly one progress row per content item; the API
            // upserts against this.
            $table->unique(['user_id', 'content_id'], 'lms_content_progress_user_content_unique');
            $table->index(['user_id', 'course_id'], 'lms_content_progress_user_course_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_content_progress');
    }
};
