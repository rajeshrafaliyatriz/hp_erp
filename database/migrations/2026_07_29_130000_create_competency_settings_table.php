<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings store for the Competency module.
 *
 * Backs two panels that had nowhere to persist to:
 *  - Framework Studio -> Weighting & Configuration -> "Weighting Configuration"
 *    (scoring model, rounding, target threshold, how unmapped competencies
 *    count, which surfaces the weights apply to).
 *  - Assessment & Calibration Workspace -> "View Configuration"
 *    (self-assessment required, calibration mandatory, approval chain,
 *    campaign defaults).
 *
 * A table audit of all 249 tables found no reusable settings store:
 * hpbrain_settings is another product (varchar(36) tenant_id/user_id, no
 * sub_institute_id, one row, no PHP consumer here); hrms_leave_workflow_settings
 * and lms_course_settings are fixed-column tables owned by their own modules.
 *
 * Deliberately key/value rather than one wide column per setting, so the two
 * panels above - and any later competency setting - share ONE table instead of
 * adding a table per panel. `scope` names the panel ('weighting', 'assessment'),
 * `scope_id` optionally narrows it to one record (a framework id for a
 * per-framework weighting profile); NULL scope_id is the tenant default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('s_competency_settings')) {
            return;
        }

        Schema::create('s_competency_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            // Which panel the setting belongs to: weighting | assessment
            $table->string('scope', 50)->index();
            // Optional narrowing (e.g. a framework id). NULL = tenant default.
            $table->unsignedBigInteger('scope_id')->nullable()->index();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One row per setting per scope - the controllers upsert on this.
            $table->unique(['sub_institute_id', 'scope', 'scope_id', 'key'], 's_competency_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_settings');
    }
};
