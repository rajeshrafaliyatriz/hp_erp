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
        Schema::create('talent_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('job_id');
            $table->string('template_id', 50)->nullable();
            $table->string('position', 255);
            $table->string('salary', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('expires_at')->nullable();
            $table->enum('status', ['draft', 'sent', 'rejected', 'expired'])->default('draft');
            $table->string('offer_letter_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by');
            $table->datetime('sent_at')->nullable();
            $table->datetime('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('application_id')->references('id')->on('talent_job_applications');
            $table->foreign('job_id')->references('id')->on('talent_job_postings');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('tbluser');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_offers');
    }
};
