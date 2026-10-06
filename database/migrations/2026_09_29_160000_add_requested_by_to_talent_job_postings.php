<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `talent.recruitment.requisition` enforcement — the last of the 8 declared
 * Workflow points, and the one prior rounds explicitly left unbuilt because
 * `talent_job_postings` had nothing a chain could attach to: a flat
 * active/inactive toggle, no requester, no pending state.
 *
 * ── `status` IS A REAL MYSQL ENUM — CHECKED, NOT ASSUMED ────────────────────
 *
 * `SHOW COLUMNS` on both the default and `live` connections confirms
 * `status enum('Active','Draft','Closed','Inactive') DEFAULT 'Draft'`, and
 * the default connection runs with STRICT_TRANS_TABLES while `live` does
 * not. Writing an unlisted string ('requested') would hard-error on the app
 * host and silently truncate to '' on live — the exact `reportmanager`/
 * `talent_offers.status` trap this codebase has already been burned by
 * twice. `'Requested'` is added as a genuine new member rather than
 * overloading the existing `'Draft'` member, because "still being typed up"
 * and "submitted, awaiting sign-off" are different states a reader (and an
 * HR user manually editing the Status dropdown) should be able to tell
 * apart — the same reasoning `talent_offers` uses a dedicated 'draft'→'sent'
 * lifecycle instead of reusing an ambiguous existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_job_postings', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->after('created_by');
        });

        DB::statement(
            "ALTER TABLE talent_job_postings MODIFY status "
            . "ENUM('Active','Draft','Closed','Inactive','Requested') DEFAULT 'Draft'"
        );
    }

    public function down(): void
    {
        // No 'Requested' rows can exist yet when this runs forward-then-back
        // in the same deploy, so narrowing the enum back is safe without a
        // data rewrite.
        DB::statement(
            "ALTER TABLE talent_job_postings MODIFY status "
            . "ENUM('Active','Draft','Closed','Inactive') DEFAULT 'Draft'"
        );

        Schema::table('talent_job_postings', function (Blueprint $table) {
            $table->dropColumn('requested_by');
        });
    }
};
