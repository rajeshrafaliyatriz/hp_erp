<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Framework & Role Mapping Studio — the two genuinely-new domain tables the
 * studio needs that have no existing equivalent in the 221-table schema.
 *
 * Everything else the studio renders reuses existing tables:
 *   - Frameworks / framework items -> s_competency_frameworks / _framework_items
 *   - Role mapping matrix cells     -> s_user_skill_jobrole (jobrole,skill,proficiency_level)
 *   - Proficiency scale             -> s_proficiency_levels (+ KASA s_proficiency_*)
 *   - Category tree / competencies  -> s_users_skills
 *
 * The two tables below (category weighting + a mapping-change approval queue)
 * were confirmed to have NO backing table or API in either the current backend
 * or the old frontend (both were static mock there). Conventions mirror the
 * 2026 competency migration: bigIncrements PK, indexed sub_institute_id, nullable
 * audit columns, timestamps + softDeletes, string status enums, loose joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Per-category weighting for a framework's competency scoring -------
        Schema::create('s_competency_framework_weights', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            // Null framework_id = the tenant's default weighting profile.
            $table->unsignedBigInteger('framework_id')->nullable()->index();
            $table->string('category', 191);
            $table->decimal('weight', 5, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Role-mapping change approval queue (Workflow & Review tab) --------
        Schema::create('s_competency_mapping_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('jobrole', 191);
            $table->string('department', 191)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('framework_id')->nullable()->index();
            $table->unsignedBigInteger('submitted_by')->nullable();   // tbluser.id
            $table->string('submitted_by_name', 191)->nullable();
            // pending | approved | rejected
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('changes_count')->default(0);
            $table->text('changes')->nullable();                      // human summary / JSON of edits
            $table->text('note')->nullable();                         // reviewer note
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_mapping_reviews');
        Schema::dropIfExists('s_competency_framework_weights');
    }
};
