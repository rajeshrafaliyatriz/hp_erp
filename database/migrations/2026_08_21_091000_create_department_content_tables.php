<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backing storage for the SOPs, Policies and Rules tabs.
 *
 * All three tabs shipped as pure fiction: sops-tab.tsx, policies-tab.tsx and
 * rules-tab.tsx each seeded a useState() from a MOCK_* array literal, showed
 * the same four or five records for every department in every tenant, and threw
 * away every create/edit/delete the moment the tab was switched. There was no
 * table behind any of them in either database - not empty tables, none at all.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_08_21_091000_create_department_content_tables.php
 *     php artisan migrate --database=live --path=database/migrations/2026_08_21_091000_create_department_content_tables.php
 *
 * Shape notes, consistent across all three tables:
 *
 * - `department_id` carries an index but NO foreign key. That follows the
 *   convention the competency and performance modules already documented in
 *   their own migrations: hrms_departments is soft-deleted and its rows are
 *   merged and retired over time, and a hard FK turns each of those routine
 *   operations into a failed write.
 *
 * - `sub_institute_id` is stored even though it is reachable through
 *   department_id. It is the tenant key every query filters on, and keeping it
 *   local means a query cannot leak across tenants by forgetting a join. The
 *   composite index below is built for exactly that filter.
 *
 * - status is a string enum rather than the int-0/1 hrms_departments uses.
 *   These records have a genuine third state: a draft SOP is not an inactive
 *   one. The UI already offered Draft; the department table never supported it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('department_sops')) {
            Schema::create('department_sops', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('title', 191);
                $table->string('code', 50)->nullable();
                $table->text('description')->nullable();
                $table->string('category', 100)->nullable();
                $table->string('version', 20)->nullable()->default('1.0');
                $table->enum('status', ['Active', 'Draft', 'Archived'])->default('Draft');

                $table->date('effective_date')->nullable();
                $table->date('review_date')->nullable();

                // Set together or all null. The upload is optional: an SOP may
                // be described inline before its document exists.
                $table->string('file_path', 255)->nullable();
                $table->string('file_name', 191)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('file_mime', 100)->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(
                    ['sub_institute_id', 'department_id', 'deleted_at'],
                    'department_sops_tenant_dept_index'
                );
            });
        }

        if (!$this->tableExists('department_policies')) {
            Schema::create('department_policies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('title', 191);
                $table->string('code', 50)->nullable();
                $table->text('description')->nullable();
                $table->string('category', 100)->nullable();
                $table->string('version', 20)->nullable()->default('1.0');
                $table->enum('status', ['Active', 'Draft', 'Archived'])->default('Draft');

                $table->date('effective_date')->nullable();
                $table->date('review_date')->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(
                    ['sub_institute_id', 'department_id', 'deleted_at'],
                    'department_policies_tenant_dept_index'
                );
            });
        }

        if (!$this->tableExists('department_rules')) {
            Schema::create('department_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();

                $table->string('title', 191);
                $table->string('code', 50)->nullable();
                $table->text('description')->nullable();

                // Free text, not an enum. The frontend's RULE_CATEGORIES list
                // was a hardcoded array of five; tenants will want their own,
                // and an enum would need a migration to add one.
                $table->string('category', 100)->nullable()->index();

                // The rule itself - threshold, window, condition. Held as text
                // so a rule can be a sentence now and structured JSON later
                // without a column change.
                $table->text('rule_definition')->nullable();

                $table->enum('status', ['Active', 'Draft', 'Archived'])->default('Draft');
                $table->date('effective_date')->nullable();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(
                    ['sub_institute_id', 'department_id', 'deleted_at'],
                    'department_rules_tenant_dept_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_rules');
        Schema::dropIfExists('department_policies');
        Schema::dropIfExists('department_sops');
    }

    /**
     * Not Schema::hasTable().
     *
     * Live runs MariaDB 10.1, and Laravel 11's schema introspection selects
     * `generation_expression` from information_schema.columns - a column that
     * version does not have, so the call fails before this migration's own
     * logic runs. Dev is on 10.11 and answers it fine, which is why the
     * difference only shows up against live. This asks for the one fact needed,
     * using columns present in every MySQL and MariaDB release.
     */
    private function tableExists(string $table): bool
    {
        $found = DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
              LIMIT 1',
            [$table]
        );

        return $found !== [];
    }
};
