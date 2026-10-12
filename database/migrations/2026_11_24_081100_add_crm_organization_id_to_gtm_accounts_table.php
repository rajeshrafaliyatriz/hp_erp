<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one deliberate GTM <-> CRM connection point: when a GTM deal is won,
 * the resulting account can be created/linked to a real crm_organizations
 * row. Nullable and additive - GTM's own tables and workflow are otherwise
 * completely untouched, per the locked-in decision to keep GTM Deals and
 * CRM Opportunities fully separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gtm_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_organization_id')->nullable()->index()->after('company_id');
        });

        Schema::table('gtm_accounts', function (Blueprint $table) {
            $table->foreign('crm_organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gtm_accounts', function (Blueprint $table) {
            $table->dropForeign(['crm_organization_id']);
            $table->dropColumn('crm_organization_id');
        });
    }
};
