<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors - legacy CRM's `vtiger_vendor` (confirmed: vendor_no, vendorname,
 * phone, email, website, glacct, category, one flat address block,
 * description - that is genuinely the entire table, no status/workflow
 * field at all, and no separate billing/shipping split the order documents
 * get).
 *
 * Named `crm_vendors` (not bare `vendors`) and the frontend type will be
 * `CrmVendor` (not bare `Vendor`) deliberately: `lms_vendors` already exists
 * in this app for a different domain (LMS training-content suppliers), and
 * `services/lms/governance.ts` already exports a bare `Vendor` type for it -
 * same collision-avoidance reasoning `types/crm.ts` already documents for
 * `CrmOpportunity`/`CrmQuote`/`CrmProduct`.
 *
 * `is_active` is not a legacy field - it's this app's own established
 * convention for a record you don't want to delete but don't want offered
 * going forward (crm_products and crm_price_books both already carry it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_vendors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('vendor_no', 100)->unique();
            $table->string('name', 191);
            $table->string('phone', 50)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('gl_account', 100)->nullable();
            $table->string('category', 50)->nullable();

            $table->string('street', 250)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('country', 50)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('po_box', 30)->nullable();

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_vendors');
    }
};
