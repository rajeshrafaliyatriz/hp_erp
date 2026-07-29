<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence Repository for the Employee Competency Profile — per-employee proof
 * items (certificates, projects, documents, endorsements) optionally tied to a
 * competency. No existing table fits: the hpbrain_*_evidence tables belong to a
 * separate product (tenant_id, not sub_institute_id) and staff_document is HR
 * paperwork, not competency evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s_competency_evidence', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('user_id')->index();          // employee (tbluser.id)
            $table->unsignedBigInteger('competency_id')->nullable()->index(); // s_users_skills.id
            $table->string('title', 191);
            // certification | project | document | endorsement | training
            $table->string('evidence_type', 50)->default('document');
            $table->text('description')->nullable();
            $table->string('link', 500)->nullable();
            // verified | pending
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_evidence');
    }
};
