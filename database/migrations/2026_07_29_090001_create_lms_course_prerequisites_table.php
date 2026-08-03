<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Learner must finish course X before starting course Y."
 *
 * A join table rather than a column on lms_course_settings because a course can
 * have several prerequisites, and each one needs to be queried from both ends:
 * "what blocks this course" when rendering the builder, and "what does
 * completing this course unlock" when evaluating enrolment eligibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_course_prerequisites', function (Blueprint $table) {
            $table->id();
            // Both reference sub_std_map.id.
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('prerequisite_course_id');
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The same prerequisite must not be listed twice for one course.
            $table->unique(['course_id', 'prerequisite_course_id'], 'lms_course_prereq_unique');
            $table->index('prerequisite_course_id', 'lms_course_prereq_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_prerequisites');
    }
};
