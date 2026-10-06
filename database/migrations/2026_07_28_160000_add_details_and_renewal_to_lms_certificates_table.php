<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificate presentation fields, public verification, and re-issue lineage.
 *
 * The Certifications screen shows a certificate name, a description and tags,
 * none of which existed - only course_title did, so the name and the course
 * were forced to be the same string.
 *
 * verification_code backs a public /verify/{code} page: a certificate is only
 * worth anything if a third party can check it without logging in, and the
 * sequential certificate_number is not safe to expose for that (it is
 * guessable). superseded_by/supersedes record a renewal chain so re-issuing
 * never overwrites the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_certificates', function (Blueprint $table) {
            // Falls back to course_title when not set, so existing rows read the same.
            $table->string('name', 191)->nullable()->after('course_title');
            $table->text('description')->nullable()->after('name');
            // JSON array of free-text tags, e.g. ["Compliance","Mandatory"].
            $table->text('tags')->nullable()->after('description');

            $table->string('verification_code', 64)->nullable()->unique()->after('certificate_number');

            $table->unsignedBigInteger('supersedes')->nullable()->index()->after('status');
            $table->unsignedBigInteger('superseded_by')->nullable()->index()->after('supersedes');
            $table->timestamp('reissued_at')->nullable()->after('superseded_by');
            $table->unsignedBigInteger('reissued_by')->nullable()->after('reissued_at');
        });
    }

    public function down(): void
    {
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropUnique(['verification_code']);
            $table->dropColumn([
                'name',
                'description',
                'tags',
                'verification_code',
                'supersedes',
                'superseded_by',
                'reissued_at',
                'reissued_by',
            ]);
        });
    }
};
