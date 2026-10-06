<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course discussions.
 *
 * The Discussions tab kept messages in React state, so nothing survived a
 * refresh. lmsDoubtController / lmsCommunicationController / lmsDoubtConversation
 * exist in the codebase, but their tables were never created - so there was no
 * usable discussion entity anywhere.
 *
 * Two tables: a thread scoped to a course (optionally to one lesson), and its
 * replies. Kept deliberately simple - no nested threading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_course_discussions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->unsignedBigInteger('content_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();

            $table->string('title', 191)->nullable();
            $table->text('message');
            // Set when an instructor/admin posts, so the UI can badge it.
            $table->boolean('is_instructor')->default(false);
            $table->boolean('is_resolved')->default(false);

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'created_at'], 'lms_discussions_course_created_idx');
        });

        Schema::create('lms_course_discussion_replies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('discussion_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('message');
            $table->boolean('is_instructor')->default(false);

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_discussion_replies');
        Schema::dropIfExists('lms_course_discussions');
    }
};
