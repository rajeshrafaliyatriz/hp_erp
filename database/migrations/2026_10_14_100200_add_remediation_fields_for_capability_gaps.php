<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three nullable columns the capability-gap -> auto-assignment chain needs,
 * each on a table that already owns the kind of fact it is:
 *
 *   lms_assignments.due_date_basis         WHICH of the three due-date tiers
 *                                          (course SLA, requirement urgency,
 *                                          tenant default) produced this row's
 *                                          due_date - see RemediationDueDate.
 *                                          Same transparency principle as
 *                                          g2g_notification.recipient_reason:
 *                                          "why is this due Friday" should be
 *                                          answerable from the data.
 *
 *   lms_course_settings.completion_days    Per-course SLA for a gap-driven
 *                                          assignment of THIS course. Highest
 *                                          priority tier - the course's own
 *                                          authoring settings already live
 *                                          here (duration_minutes, is_mandatory),
 *                                          so this is one more of those, not a
 *                                          new table.
 *
 *   jobrole_competency_map.remediation_days  Per-requirement urgency: a
 *                                          mandatory competency a role requires
 *                                          at level 5 is more urgent than an
 *                                          optional one at level 2, and HR
 *                                          already edits this map on the Role
 *                                          Requirements screen.
 *
 * Portable column check: Schema::hasColumn() queries information_schema for
 * `generation_expression`, which does not exist before MariaDB 10.2 and
 * throws on live (10.1.48). SHOW COLUMNS via information_schema.columns works
 * on every version.
 */
return new class extends Migration
{
    private function hasColumnPortable(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function up(): void
    {
        if (Schema::hasTable('lms_assignments') && !$this->hasColumnPortable('lms_assignments', 'due_date_basis')) {
            Schema::table('lms_assignments', function (Blueprint $table) {
                $table->string('due_date_basis', 30)->nullable()->after('due_date');
            });
        }

        if (Schema::hasTable('lms_course_settings') && !$this->hasColumnPortable('lms_course_settings', 'completion_days')) {
            Schema::table('lms_course_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('completion_days')->nullable();
            });
        }

        if (Schema::hasTable('jobrole_competency_map') && !$this->hasColumnPortable('jobrole_competency_map', 'remediation_days')) {
            Schema::table('jobrole_competency_map', function (Blueprint $table) {
                $table->unsignedSmallInteger('remediation_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobrole_competency_map') && $this->hasColumnPortable('jobrole_competency_map', 'remediation_days')) {
            Schema::table('jobrole_competency_map', function (Blueprint $table) {
                $table->dropColumn('remediation_days');
            });
        }

        if (Schema::hasTable('lms_course_settings') && $this->hasColumnPortable('lms_course_settings', 'completion_days')) {
            Schema::table('lms_course_settings', function (Blueprint $table) {
                $table->dropColumn('completion_days');
            });
        }

        if (Schema::hasTable('lms_assignments') && $this->hasColumnPortable('lms_assignments', 'due_date_basis')) {
            Schema::table('lms_assignments', function (Blueprint $table) {
                $table->dropColumn('due_date_basis');
            });
        }
    }
};
