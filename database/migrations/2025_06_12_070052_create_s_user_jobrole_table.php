<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('s_user_jobrole', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('jobrole', 191)->index()->nullable();
            $table->text('description')->nullable();
            $table->mediumText('responsibilities')->nullable();
            $table->mediumText('required_skill_experience')->nullable();
            $table->mediumText('location')->nullable();
            $table->mediumText('salary_range')->nullable();
            $table->mediumText('company_information')->nullable();
            $table->mediumText('benefits')->nullable();
            $table->mediumText('keyword_tags')->nullable();
            $table->date('job_posting_date')->nullable();
            $table->date('application_deadline')->nullable();
            $table->mediumText('contact_information')->nullable();
            $table->mediumText('internal_tracking')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraints with NO ACTION on delete and update
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_user_jobrole');
    }
};
