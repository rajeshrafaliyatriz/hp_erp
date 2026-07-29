<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoring settings for a course, as captured by the Course Builder wizard.
 *
 * Kept in its own table rather than as columns on sub_std_map because that
 * table is not LMS-only: the competency module, the jobrole library and
 * enrolment all read it. Widening a shared 28-column table by half again for
 * fields only the LMS writes would make every one of those consumers carry the
 * cost. One row per course, joined only when the builder or a course detail
 * view actually needs it.
 *
 * Every column is nullable: a course created before this table existed, or
 * created through the older POST /api/lms/courses without a settings block,
 * simply has no row here and behaves exactly as it did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_course_settings', function (Blueprint $table) {
            $table->id();
            // sub_std_map.id. Unique because a course has exactly one settings row.
            $table->unsignedBigInteger('course_id')->unique();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();

            // Step 1 - basic information
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('language', 50)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('discussion_enabled')->default(false);
            // 'all' or 'restricted'. Distinct from sub_std_map.status, which is
            // whether the course is published at all, not who may see it.
            $table->string('visibility', 20)->default('all');

            // Step 3 - assessment rules that apply across the whole course
            $table->unsignedTinyInteger('passing_score')->nullable();
            $table->unsignedInteger('max_attempts')->nullable();

            // Step 4 - certification
            $table->boolean('issue_certificate')->default(true);
            $table->string('certificate_template', 50)->nullable();
            $table->boolean('recert_alerts')->default(false);

            // Step 5 - publish settings
            $table->string('enrollment_rule', 20)->default('open');
            // JSON arrays of hrms_departments.id and jobrole names. sub_std_map
            // already carries a single standard_id/jobrole; these are the
            // wizard's multi-select restriction lists, which have no home there.
            $table->json('restrict_departments')->nullable();
            $table->json('restrict_roles')->nullable();
            $table->date('available_from')->nullable();
            $table->date('available_until')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_settings');
    }
};
