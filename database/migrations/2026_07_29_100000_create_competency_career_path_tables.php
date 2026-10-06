<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named career paths for the Development & Career Path Workspace.
 *
 * The existing `career_journey` table (read by CareerJourneyController, used by
 * the old frontend's Succession screen) stores the role-progression GRAPH as
 * edges: jobrole_id -> to_jobrole_id + vertical_lateral_movement. That is the
 * right source for "which role can follow which", and this migration does not
 * touch or duplicate it.
 *
 * What it cannot express is a NAMED, ordered path ("Engineering Leadership
 * Path") that a development plan can be linked to and that HR authors and
 * publishes - there is no name, no ownership, no status and no explicit step
 * order on an edge table. A sweep of the 221-table schema (%path%, %succession%,
 * %progression%, %plan%) found no other candidate. Hence the two tables below.
 *
 * Conventions mirror 2026_07_28_100100_create_competency_module_tables:
 * bigIncrements PK, indexed sub_institute_id, nullable audit columns,
 * timestamps + softDeletes, string status enums, loose joins (jobrole string
 * alongside the id) with no FK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The path itself --------------------------------------------------
        Schema::create('s_competency_career_paths', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('department', 191)->nullable();
            // s_user_jobrole.jobrole_category - "Engineering", "Finance", ...
            $table->string('job_family', 191)->nullable();
            // draft | active | archived
            $table->string('status', 30)->default('active')->index();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Its ordered role nodes -------------------------------------------
        Schema::create('s_competency_career_path_steps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('career_path_id')->index();
            // Both kept: jobrole_id joins s_user_jobrole, jobrole survives a
            // role rename/delete the same way s_user_skill_jobrole does.
            $table->unsignedBigInteger('jobrole_id')->nullable()->index();
            $table->string('jobrole', 191);
            $table->string('job_level', 191)->nullable();
            $table->unsignedInteger('step_order')->default(0);
            // current | next | future - drives the Career Path Explorer node styling
            $table->string('step_type', 30)->default('future');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_career_path_steps');
        Schema::dropIfExists('s_competency_career_paths');
    }
};
