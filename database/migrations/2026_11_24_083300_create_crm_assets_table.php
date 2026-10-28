<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets - legacy CRM's `vtiger_assets` (a serialized unit of a Product,
 * tracked for ownership/inventory purposes - confirmed NO depreciation or
 * warranty concept exists anywhere in the legacy module). Confirmed
 * fields: asset_no, asset_name(mandatory), product(mandatory FK),
 * account(FK, the owner), serialnumber, tagnumber, datesold,
 * dateinservice, assetstatus(In Service/Out-of-service), shippingmethod,
 * shippingtrackingnumber, invoiceid.
 *
 * Named `crm_assets` (not bare `assets`) deliberately: `talent_onboarding_
 * assets` already exists in this app for a completely different domain
 * (employee IT-equipment issuance register) - same collision-avoidance
 * reasoning already applied to `crm_vendors` beside `lms_vendors`. The
 * frontend type will be `CrmAsset`, never bare `Asset`.
 *
 * `product_id` is nullable at the DB layer (not NOT NULL) even though
 * conceptually required - enforced only via request validation, matching
 * every other "required" FK in this CRM subsystem, and necessary because
 * NOT NULL is incompatible with `nullOnDelete()`'s SET NULL action (a
 * permanent Product delete would otherwise crash this table's own FK
 * constraint instead of cleanly nulling it out).
 *
 * `invoice_id` is a real, useful new cross-link into the already-shipped
 * order-to-cash work (crm_invoices) - legacy's own `invoiceid` pointed at
 * vtiger's Invoice module the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_assets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('asset_no', 100)->unique();
            $table->string('asset_name', 191);
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            $table->string('serial_number', 100)->nullable()->index();
            $table->string('tag_number', 100)->nullable()->index();
            $table->date('date_sold')->nullable();
            $table->date('date_in_service')->nullable();
            $table->string('status', 30)->default('In Service');

            $table->string('shipping_method', 100)->nullable();
            $table->string('shipping_tracking_number', 100)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();

            $table->text('description')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_assets', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('crm_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_assets');
    }
};
