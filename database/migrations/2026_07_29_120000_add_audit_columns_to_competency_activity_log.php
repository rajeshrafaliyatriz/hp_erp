<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns on s_competency_activity_log for the Audit & Activity Center.
 *
 * The feed table was built to drive the Command Center's "Recent Activity"
 * strip, where a single free-text `description` line is enough. The Audit &
 * Activity Center needs two things that line cannot give it:
 *
 *  - subject_name -> the table's own "Record Name" column and the detail
 *                    panel's "Record Name" field. Today the record's name is
 *                    only embedded inside the description string
 *                    (e.g. 'Added certification "PMP Certification"'), so it
 *                    cannot be selected, sorted or searched on its own.
 *  - changes      -> the detail panel's "Change Summary" card and the
 *                    "Version History" tab, both of which are field-level
 *                    before/after tables. Nothing in this schema records what
 *                    a value used to be. Stored as a JSON array of
 *                    {field, label, old, new} objects, the same shape the
 *                    (unrelated, different-product) hpbrain_audit_logs.changes
 *                    column uses.
 *
 * A table audit of all 248 tables found no reusable audit store:
 * hpbrain_audit_logs is another product (UUID tenant_id/actor_id, no
 * sub_institute_id, no PHP consumer here), tbl_user_journey_logs is
 * page-visit clickstream, and template_versions / hpbrain_capability_versions
 * are document-template and capability versioning. So the module's own feed
 * table is extended rather than a new one created.
 *
 * Both columns are nullable with no default, so the four existing readers -
 * CertificationController::history, DevelopmentPlanController::history,
 * skillLibraryController::competencyLibraryDetail and
 * CommandCenterService::recentActivity - are unaffected: they all select
 * explicit columns and none of them writes to this table directly (every write
 * goes through ResolvesCompetencyContext::logCompetencyActivity, whose two new
 * parameters are optional and default to null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s_competency_activity_log', function (Blueprint $table) {
            if (!Schema::hasColumn('s_competency_activity_log', 'subject_name')) {
                $table->string('subject_name', 191)->nullable()->after('subject_id');
            }
            if (!Schema::hasColumn('s_competency_activity_log', 'changes')) {
                // [{field, label, old, new}, ...] - MariaDB aliases json to
                // longtext, which is fine; the model casts it back to an array.
                $table->json('changes')->nullable()->after('subject_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_activity_log', function (Blueprint $table) {
            foreach (['subject_name', 'changes'] as $column) {
                if (Schema::hasColumn('s_competency_activity_log', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
