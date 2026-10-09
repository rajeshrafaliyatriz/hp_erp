<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations - the legacy CRM's "Accounts" module, relabeled.
 *
 * Internal name stays Organization/crm_organizations everywhere in code -
 * "Organizations" is a presentation-layer label only, mirroring how the
 * legacy app did the same relabel (a translation-string override, never a
 * renamed table/class).
 *
 * `legacy_id` is a hook for a future one-time data-import job (not built in
 * this pass, per the locked-in decision to ship schema+features now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_organizations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('account_no', 100)->unique();
            $table->string('name', 191);
            $table->unsignedBigInteger('parent_id')->nullable()->index();

            $table->string('account_type', 50)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('rating', 50)->nullable();
            $table->string('ownership', 100)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->unsignedInteger('employees')->nullable();
            $table->string('sic_code', 50)->nullable();
            $table->string('ticker_symbol', 30)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('secondary_phone', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('secondary_email', 100)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('fax', 30)->nullable();
            $table->boolean('email_opt_out')->default(false);

            $table->string('billing_street', 250)->nullable();
            $table->string('billing_city', 50)->nullable();
            $table->string('billing_state', 50)->nullable();
            $table->string('billing_code', 30)->nullable();
            $table->string('billing_country', 50)->nullable();
            $table->string('billing_po_box', 30)->nullable();

            $table->string('shipping_street', 250)->nullable();
            $table->string('shipping_city', 50)->nullable();
            $table->string('shipping_state', 50)->nullable();
            $table->string('shipping_code', 30)->nullable();
            $table->string('shipping_country', 50)->nullable();
            $table->string('shipping_po_box', 30)->nullable();

            $table->text('description')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sub_institute_id', 'name'], 'crm_orgs_tenant_name_unique');
        });

        // Self-referencing FK added after create so the table exists first.
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('crm_organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_organizations');
    }
};
