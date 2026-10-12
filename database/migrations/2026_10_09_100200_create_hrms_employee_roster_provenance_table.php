<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an employee's working hours came from, per weekday.
 *
 * ── THE QUESTION THIS ANSWERS ───────────────────────────────────────────────
 *
 * "Apply to department" writes the department's template onto its employees'
 * `tbluser` columns. Once an employee can set their own hours and have them
 * approved, an apply would silently flatten that choice - which is precisely
 * the failure the PREVIOUS version of department shift-setting was deleted from
 * the product for (see routes/hrms.php's removal note): a bulk write wiped a
 * 14:00 Saturday finish for 100 employees with no record and no warning.
 *
 * So the apply has to be able to ask, per employee per weekday: did a person
 * choose this, or did a template put it here? Nothing in the schema could
 * answer that. This table answers it.
 *
 * ── WHY A SIDE TABLE AND NOT A COLUMN ON tbluser ────────────────────────────
 *
 * **Three** code paths write the 21 roster columns:
 *
 *   DepartmentScheduleController::previewOrApply()   the template apply
 *   EmployeeDirectoryController::update()            via scheduleColumns()
 *   tbluserController::update()                      the legacy Blade form
 *
 * and two of them copy EVERY request key into the write, held back only by
 * their own separate blocklists (`EmployeeDirectoryController::NEVER_WRITABLE`,
 * and the user-import controller's). A provenance column on `tbluser` would
 * therefore be settable by any caller of those endpoints unless it were added
 * to each blocklist - three files that must all remember, with the worst
 * possible failure mode: provenance says "the employee chose this" because
 * somebody posted that field.
 *
 * Nothing writes a side table by accident. `tbluser` - which every module reads
 * - is left alone. And one varchar plus one bigint is 10.1-safe with no JSON.
 *
 * ── WHY NOT DERIVE IT FROM THE APPROVED REQUESTS ────────────────────────────
 *
 * Because it is not the same fact. An approved request in March followed by a
 * department apply in April means the roster is now DEPARTMENT-set, while the
 * approved request row still exists. Deriving provenance from the request table
 * would make Apply skip that employee forever, and the opt-in override would
 * become the only way to reach them again.
 *
 * Doing it properly would mean comparing the request's `applied_at` against the
 * last `department.schedule.applied` event - and `g2g_event.payload` is a
 * json_encode'd STRING column on a 10.1 host with no JSON functions, so that
 * comparison happens in PHP over every event ever recorded for the department,
 * inside a preview that already runs across a whole department.
 *
 * The request table stays the corroborating detail the preview can QUOTE
 * ("set in request #412 on 3 Oct"), not the mechanism.
 *
 * ── PER WEEKDAY, NOT PER WEEK ───────────────────────────────────────────────
 *
 * An employee may have asked about Saturday alone - which is what most of them
 * ask about. Week-grain provenance would mark their whole week as
 * employee-chosen and make Apply skip the four days nobody has an opinion
 * about.
 *
 * ── NOT RETROACTIVE, AND THE UI MUST NOT IMPLY OTHERWISE ────────────────────
 *
 * Absence of a row means "we do not know where this came from". That is true of
 * every roster written before this ships, including all 2,008 employees who have
 * no roster at all. The apply must treat a missing row as NOT employee-set, so
 * the skip count starts at zero and fills forward only.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-roster-provenance.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_09_100200_create_hrms_employee_roster_provenance_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_100200_create_hrms_employee_roster_provenance_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_employee_roster_provenance')) {
            return;   // idempotent: this runs on two hosts
        }

        Schema::create('hrms_employee_roster_provenance', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('user_id');

            // 'monday'..'sunday' - the tbluser column prefix, as everywhere else
            // in this feature.
            $table->string('weekday', 9);

            /*
             * employee_request | department_apply | hr_directory | import
             *
             * varchar, not enum: these two hosts' enums diverge from their own
             * migrations, and `strict => false` means a value outside an enum
             * coerces silently instead of erroring. A new source should be a
             * code change, not a schema migration on a table every apply reads.
             */
            $table->string('source', 24);

            // The request id, the department id, whatever the source points at.
            $table->unsignedBigInteger('source_ref_id')->nullable();

            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamp('set_at')->nullable();

            $table->timestamps();

            // One row per employee per weekday - which is what makes the writer
            // an updateOrInsert, the same device hds_tenant_dept_weekday_uniq
            // gives the department template.
            $table->unique(['sub_institute_id', 'user_id', 'weekday'], 'herp_tenant_user_weekday_uniq');
            // The apply reads a whole department at once, by user id.
            $table->index(['sub_institute_id', 'user_id'], 'herp_tenant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_employee_roster_provenance');
    }
};
