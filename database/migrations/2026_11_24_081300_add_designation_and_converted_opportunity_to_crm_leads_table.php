<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 audit gap: the legacy CRM's Lead `designation` (job title) field
 * was never ported - CrmLeadController::convert() even referenced
 * `$lead->designation` already, silently resolving to null since the
 * column didn't exist. `converted_opportunity_id` is new: convert() can now
 * optionally create a crm_opportunities row too (Sales module exists now,
 * unlike when Leads was first built - see the stale "out of scope" comment
 * this migration's controller change also corrects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('company');
            $table->unsignedBigInteger('converted_opportunity_id')->nullable()->after('converted_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['designation', 'converted_opportunity_id']);
        });
    }
};
