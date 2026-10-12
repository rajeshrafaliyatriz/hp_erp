<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one deliberate Task-Management-Projects <-> CRM connection point,
 * answering the user's own question of whether a CRM deal and this app's
 * existing Projects feature are related: yes, by linking to it, not by
 * building a second, duplicate CRM-native project concept.
 *
 * Modeled on `2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_
 * table.php` (a real FK + nullOnDelete(), the correct precedent for "a
 * foreign module pointing AT a CRM entity") - NOT on
 * task_management_projects' own internal no-FK convention, which governs
 * pointers *within* that module, a different situation. Both CRM
 * destroy() methods this points at are soft-deletes, never a hard
 * DELETE, so nullOnDelete() is cheap, safe, and mostly dormant protection.
 *
 * `client_name` (a free-text slot already on this table and already
 * rendered in ProjectFacts) is the conceptual precedent this upgrades -
 * a structured Organization link is a strictly better version of a job
 * that slot already does, not a new concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_management_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_organization_id')->nullable()->index()->after('client_name');
            $table->unsignedBigInteger('crm_opportunity_id')->nullable()->index()->after('crm_organization_id');
        });

        Schema::table('task_management_projects', function (Blueprint $table) {
            $table->foreign('crm_organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('crm_opportunity_id')->references('id')->on('crm_opportunities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_management_projects', function (Blueprint $table) {
            $table->dropForeign(['crm_organization_id']);
            $table->dropForeign(['crm_opportunity_id']);
            $table->dropColumn(['crm_organization_id', 'crm_opportunity_id']);
        });
    }
};
