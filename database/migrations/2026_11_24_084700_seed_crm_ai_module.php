<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers CRM in the existing AI Stack report engine (ai_modules +
 * ModuleDataSourceCatalog) rather than building a second, parallel report
 * system - this engine is already a real, deterministic, non-generative
 * tabular/mail-merge-style report builder (AiReportController/
 * ReportLayoutRenderer/ai_generated_reports), just with zero crm_* entries
 * today. `menu_id` is looked up from the real CRM root row in
 * tblmenumaster_g2g (id confirmed 199 on this connection, but never
 * hardcoded here either, matching every other CRM menu migration's own
 * discipline, since a fresh-insert row's id is not guaranteed to match
 * between the `default` and `live` connections - the CRM root itself is a
 * REACTIVATED pre-existing row though, so this lookup is mostly a safety
 * net, not a strict necessity the way it is for Sales/Support's own fresh
 * parent rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('ai_modules') || ! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', '/module/crm')->value('id');

        if ($menuId === null) {
            return;
        }

        $exists = DB::table('ai_modules')->where('module_key', 'crm')->exists();

        if ($exists) {
            return;
        }

        DB::table('ai_modules')->insert([
            'module_key' => 'crm',
            'label' => 'CRM',
            'domain' => 'g2g',
            'description' => 'Sales/Marketing/Support CRM data - leads, opportunities, quotes, tickets, invoices.',
            'capabilities' => json_encode(['conversational' => false, 'generative' => false, 'agent' => false, 'workflow' => false, 'ontology' => false]),
            'registry_keys' => json_encode([]),
            'menu_id' => $menuId,
            'icon' => 'mdi mdi-account-group-outline',
            'sort_order' => 20,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! $this->tableExists('ai_modules')) {
            return;
        }

        DB::table('ai_modules')->where('module_key', 'crm')->delete();
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
