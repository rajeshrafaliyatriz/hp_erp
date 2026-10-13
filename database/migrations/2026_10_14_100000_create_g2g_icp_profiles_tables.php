<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ICP profiles as DATA, per organisation.
 *
 *   g2g_icp_profiles          one row per ICP an organisation defines (several allowed: one per product)
 *   g2g_icp_profile_versions  every saved version of a profile's configuration, so an edit can be reviewed or undone
 *   g2g_target_accounts.icp_profile_id, g2g_research_runs.icp_profile_id   which ICP an account / a run belongs to
 *
 * ADDITIVE ONLY and idempotent. Nothing existing is changed or dropped. SHOW COLUMNS is used on MySQL/MariaDB
 * because Schema::hasColumn() selects information_schema.generation_expression, which MariaDB 10.1 (live) lacks.
 */
return new class extends Migration
{
    private function hasColumn(string $table, string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
        }

        return Schema::hasColumn($table, $column);
    }

    public function up(): void
    {
        if (! Schema::hasTable('g2g_icp_profiles')) {
            Schema::create('g2g_icp_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('key', 60);                              // slug, unique per organisation (also stored on accounts as `pack`)
                $t->string('name', 120);
                $t->string('product_line', 60)->nullable();
                $t->string('status', 12)->default('draft');         // draft | active | archived
                $t->boolean('is_default')->default(false);          // the one a manual run uses when none is chosen
                $t->unsignedInteger('version')->default(1);
                $t->longText('config');                             // JSON, validated by IcpProfileValidator
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->unique(['sub_institute_id', 'key'], 'g2g_icp_profiles_tenant_key');
                $t->index(['sub_institute_id', 'status'], 'g2g_icp_profiles_tenant_status');
            });
        }

        if (! Schema::hasTable('g2g_icp_profile_versions')) {
            Schema::create('g2g_icp_profile_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('icp_profile_id');
                $t->unsignedInteger('version');
                $t->longText('config');
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamps();

                $t->index(['icp_profile_id', 'version'], 'g2g_icp_versions_profile');
            });
        }

        foreach (['g2g_target_accounts' => 'g2g_target_accounts_icp', 'g2g_research_runs' => 'g2g_research_runs_icp'] as $table => $index) {
            if (Schema::hasTable($table) && ! $this->hasColumn($table, 'icp_profile_id')) {
                Schema::table($table, function (Blueprint $t) use ($index) {
                    $t->unsignedBigInteger('icp_profile_id')->nullable();
                    $t->index('icp_profile_id', $index);
                });
            }
        }
    }

    /** Dropping would erase every organisation's ICPs and their history; leave them. */
    public function down(): void
    {
    }
};
