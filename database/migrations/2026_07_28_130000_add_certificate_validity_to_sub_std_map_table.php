<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a course's certificate stays valid.
 *
 * lms_certificates has expires_at and the API derives active / expiring /
 * expired from it, but nothing in the schema said how long a certificate should
 * last, so every one was issued as never-expiring.
 *
 * There was no existing column to derive this from: s_users_skills
 * .certification_qualifications holds external credential *names*
 * ("CA, ICAI Certifications, CISA"), not durations. Validity belongs to the
 * course, so it lives here and is set on the course form.
 *
 * Null keeps the current behaviour - the certificate never expires - so the
 * 96 existing courses are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_std_map', function (Blueprint $table) {
            $table->unsignedSmallInteger('certificate_validity_months')
                  ->nullable()
                  ->after('content_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sub_std_map', function (Blueprint $table) {
            $table->dropColumn('certificate_validity_months');
        });
    }
};
