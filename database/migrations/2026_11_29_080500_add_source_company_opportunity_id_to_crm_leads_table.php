<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing unification, Phase 4: traceability for a lead created by
 * `Signals\OpportunityController::promoteToCrm()` from a `g2g_company_opportunities`
 * row - an AI-discovered, unvetted signal landing in the one place CRM already
 * has for "something a human must qualify first" (crm_leads), reusing the
 * existing `CrmLeadController::convert()` transaction unmodified from there on.
 * No FK, matching this table's existing cross-reference columns' style
 * (converted_organization_id, converted_contact_id, converted_opportunity_id -
 * none of those carry one either, since crm_* and g2g_* tables are kept
 * separate by design; see Docs/sales-marketing-merge/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->unsignedBigInteger('source_company_opportunity_id')->nullable()->index()->after('converted_opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('source_company_opportunity_id');
        });
    }
};
