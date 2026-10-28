<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a department rule stand in for a K12-style "governing business rule"
 * (BR-xx) on a converted process: `code` and `rule_definition` already exist
 * on `department_rules` (reused as the BR code and the rule text), so this
 * only adds what is missing - what the rule checks, where it applies, and
 * what happens when it fails.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_10_07_110000_add_governance_columns_to_department_rules.php
 *     php artisan migrate --database=live --path=database/migrations/2026_10_07_110000_add_governance_columns_to_department_rules.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('department_rules', 'validation')) {
            Schema::table('department_rules', function (Blueprint $table) {
                $table->text('validation')->nullable()->after('rule_definition');
            });
        }

        if (!Schema::hasColumn('department_rules', 'applies_at')) {
            Schema::table('department_rules', function (Blueprint $table) {
                $table->string('applies_at', 191)->nullable()->after('validation');
            });
        }

        if (!Schema::hasColumn('department_rules', 'on_failure')) {
            Schema::table('department_rules', function (Blueprint $table) {
                $table->text('on_failure')->nullable()->after('applies_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('department_rules', function (Blueprint $table) {
            foreach (['validation', 'applies_at', 'on_failure'] as $column) {
                if (Schema::hasColumn('department_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
