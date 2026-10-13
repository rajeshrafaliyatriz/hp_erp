<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor <-> Contact, M:M - mirrors legacy's `vtiger_vendorcontactrel` and
 * this app's own `crm_opportunity_contacts` junction exactly (same shape,
 * same no-soft-deletes reasoning: a link row carries nothing worth
 * preserving history for, a remove is a real delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_vendor_contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['vendor_id', 'contact_id']);

            $table->foreign('vendor_id')->references('id')->on('crm_vendors')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_vendor_contacts');
    }
};
