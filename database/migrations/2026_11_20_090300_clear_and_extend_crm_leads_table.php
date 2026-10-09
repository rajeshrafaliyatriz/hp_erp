<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `crm_leads` already exists (created 2026-07-01 by an abandoned, code-less
 * prototype - see this plan's Context section) with 3 fake/test rows. Reused
 * here rather than replaced: the 3 rows are cleared first (not real business
 * data - confirmed with the user), then the table is extended additively to
 * the fuller schema this migration plan needs.
 *
 * Column type changes (`annual_revenue` varchar->decimal, `email_opt_out`
 * varchar->boolean) use raw `MODIFY COLUMN` rather than Blueprint's
 * ->change(), because doctrine/dbal is not installed in this project (and
 * without it Blueprint::change() would error).
 *
 * `number_of_emp` and `postal_code` keep their existing names - reusing what
 * is already there rather than renaming for its own sake. The existing,
 * unused `type` column (junk test dropdown values: school/college/
 * university/corporate/Investors - matches no real vtiger Lead field) is
 * left in place, unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('crm_leads')) {
            return;
        }

        // Clear the 3 fake/test rows before any type-tightening below, so
        // the MODIFY COLUMN statements never have to reconcile real data
        // against a stricter type.
        DB::table('crm_leads')->delete();

        if (! $this->hasColumn('crm_leads', 'legacy_id')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_id')->nullable()->index()->after('id');
                $table->string('salutation', 20)->nullable()->after('lead_no');
                $table->string('fax', 30)->nullable()->after('mobile');
                $table->string('campaign_text', 191)->nullable()->after('type');
                $table->boolean('converted')->default(false)->index()->after('campaign_text');
                $table->unsignedBigInteger('converted_organization_id')->nullable()->after('converted');
                $table->unsignedBigInteger('converted_contact_id')->nullable()->after('converted_organization_id');
            });
        }

        DB::statement('ALTER TABLE crm_leads MODIFY COLUMN annual_revenue DECIMAL(15,2) NULL');
        DB::statement('ALTER TABLE crm_leads MODIFY COLUMN email_opt_out TINYINT(1) NOT NULL DEFAULT 0');

        if (! $this->hasUniqueIndex('crm_leads', 'lead_no')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->unique('lead_no', 'crm_leads_lead_no_unique');
            });
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->foreign('converted_organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('converted_contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->tableExists('crm_leads')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropForeign(['converted_organization_id']);
            $table->dropForeign(['converted_contact_id']);
        });

        if ($this->hasUniqueIndex('crm_leads', 'lead_no')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->dropUnique('crm_leads_lead_no_unique');
            });
        }

        DB::statement('ALTER TABLE crm_leads MODIFY COLUMN annual_revenue VARCHAR(191) NULL');
        DB::statement('ALTER TABLE crm_leads MODIFY COLUMN email_opt_out VARCHAR(191) NULL');

        if ($this->hasColumn('crm_leads', 'legacy_id')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->dropColumn([
                    'legacy_id', 'salutation', 'fax', 'campaign_text',
                    'converted', 'converted_organization_id', 'converted_contact_id',
                ]);
            });
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function hasUniqueIndex(string $table, string $column): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0 LIMIT 1",
            [$table, $column]
        ) !== [];
    }
};
