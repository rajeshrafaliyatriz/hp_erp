<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learner notes taken while working through a course.
 *
 * The Notes tab kept everything in React state, so a note vanished on refresh.
 * There was no notes table anywhere in the schema.
 *
 * Notes are private to their author. content_id and timestamp_seconds are
 * nullable so a note can be attached to the course generally, to one lesson, or
 * to a specific moment in a video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_content_notes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->unsignedBigInteger('content_id')->nullable()->index();

            $table->text('note');
            // Position in the lesson media this note refers to, in seconds.
            $table->unsignedInteger('timestamp_seconds')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'course_id'], 'lms_content_notes_user_course_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_content_notes');
    }
};
