<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a renewal chain for the same learner and course.
 *
 * lms_certificates carried a unique index on (user_id, course_id) to make
 * issuing idempotent. Re-issuing needs a second row for exactly that pair — the
 * superseded original plus its replacement — so the index made renewal
 * impossible (1062 Duplicate entry).
 *
 * Idempotency has not been lost: issueCertificate already looks for an existing
 * certificate and returns it rather than inserting, so the index was belt and
 * braces over a check the application performs first. certificate_number and
 * verification_code both remain unique, which is what actually matters for
 * identifying a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropUnique('lms_certificates_user_course_unique');
            // Still indexed for lookup speed, just no longer unique.
            $table->index(['user_id', 'course_id'], 'lms_certificates_user_course_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropIndex('lms_certificates_user_course_idx');
            $table->unique(['user_id', 'course_id'], 'lms_certificates_user_course_unique');
        });
    }
};
