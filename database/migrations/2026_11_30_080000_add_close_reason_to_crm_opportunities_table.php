<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 1: CRM Opportunities had no way to
 * record WHY a deal was lost - only the stage itself (`sales_stage =
 * "Closed Lost"`). GTM's own deal pipeline already requires this
 * (`gtm_deals.close_reason`, enforced in `DealController::stage()`); this
 * brings CRM Opportunities to the same standard, same column name, for a
 * unified "Sales & Marketing" reporting story. See
 * Docs/sales-marketing-merge/00-PROGRESS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            $table->text('close_reason')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            $table->dropColumn('close_reason');
        });
    }
};
