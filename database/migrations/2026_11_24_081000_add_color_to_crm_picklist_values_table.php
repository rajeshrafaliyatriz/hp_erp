<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Opportunity kanban board wants a color per stage, and nothing in this
 * table carries one today. Nullable and additive - every existing row
 * (Leads/Contacts/Organizations/Campaigns picklists) is unaffected and just
 * falls back to a neutral style wherever a color isn't set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_picklist_values', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('crm_picklist_values', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
