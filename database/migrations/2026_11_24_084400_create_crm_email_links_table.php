<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic pivot for "this email is visible from this record's
 * timeline" - same shape and reasoning as document_library_links, applied
 * to crm_emails instead. See that migration's own docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('email_id')->index();
            $table->string('linked_type', 40);
            $table->unsignedBigInteger('linked_id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['email_id', 'linked_type', 'linked_id']);
            $table->index(['linked_type', 'linked_id']);

            $table->foreign('email_id')->references('id')->on('crm_emails')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_links');
    }
};
