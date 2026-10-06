<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course completion certificates.
 *
 * There was no certificate entity anywhere - the dashboard's "Certifications"
 * widget was wired to the achievements endpoint (a different concept, with no
 * expiry), and the player's certificate panel was static.
 *
 * A certificate is issued when a learner completes a course. Because the LMS is
 * competency-driven (sub_std_map.subject_id resolves to a skill / task / jobrole
 * via subject_category), skill_id is carried through where the course maps to a
 * skill - that is what lets a certificate feed the competency picture rather
 * than being a dead-end document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_certificates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('enrollment_id')->nullable()->index();
            // s_users_skills.id when the course maps to a skill; null otherwise.
            $table->unsignedBigInteger('skill_id')->nullable()->index();

            $table->string('certificate_number', 100)->unique();
            $table->string('course_title', 191)->nullable();

            $table->timestamp('issued_at')->nullable();
            // Null means the certificate does not expire.
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('status', 20)->default('active')->index();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One active certificate per learner per course.
            $table->unique(['user_id', 'course_id'], 'lms_certificates_user_course_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_certificates');
    }
};
