<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives Department Management the columns its UI has been faking.
 *
 * The screen rendered four columns that no query could ever populate:
 *
 *   Code      - a hardcoded Record<string,string> lookup in the React bundle,
 *               with an initials fallback. Three separate copies of that map
 *               existed. No `code` column has ever existed in the database.
 *   Head      - mapDepartments() set `hod: null` unconditionally.
 *   Employees - mapDepartments() set `employees: 0` unconditionally.
 *   Status    - the API pre-filtered `status = 1`, so every row was "Active".
 *
 * Head and Employees are derivable (head_user_id already exists; headcount is a
 * count over tbluser.department_id) so they need no schema. Code and the
 * description paragraph are not derivable - they are real, editable fields, so
 * they are added here. sort_order backs the Move up / Move down controls, which
 * had no ordering column to move anything within.
 *
 * RUN THIS ON BOTH DATABASES:
 *
 *     php artisan migrate --path=database/migrations/2026_08_21_090000_add_department_management_fields.php
 *     php artisan migrate --database=live --path=database/migrations/2026_08_21_090000_add_department_management_fields.php
 *
 * --path is not optional for live. Live is ~180 migrations behind dev, so a
 * bare `migrate --database=live` would run every unrelated pending migration in
 * one batch.
 *
 * Every step is guarded and re-runnable: the two databases had already drifted
 * (head_user_id carries an index on dev and none on live), so this cannot
 * assume a shared starting point.
 */
return new class extends Migration
{
    /**
     * The codes the React bundle displayed, so the backfill preserves exactly
     * what users already see. Anything not listed falls back to initials -
     * the same fallback the frontend used.
     */
    private const EXPLICIT_CODES = [
        'Executive Office'     => 'EXO',
        'Human Resources'      => 'HR',
        'Talent Acquisition'   => 'HR-TA',
        'Engineering'          => 'ENG',
        'Platform Engineering' => 'ENG-PLT',
        'Quality Assurance'    => 'ENG-QA',
        'Product Management'   => 'PRD',
        'Sales & Marketing'    => 'SM',
        'Customer Success'     => 'CS',
        'Finance & Accounts'   => 'FIN',
        'Legal & Compliance'   => 'LGL',
        'Information Security' => 'SEC',
    ];

    public function up(): void
    {
        if (!$this->tableExists('hrms_departments')) {
            return;
        }

        Schema::table('hrms_departments', function (Blueprint $table) {
            if (!$this->columnExists('hrms_departments', 'code')) {
                // Nullable: a department without a code is valid, and the
                // backfill below is best-effort rather than a constraint.
                $table->string('code', 50)->nullable()->index();
            }

            if (!$this->columnExists('hrms_departments', 'description')) {
                $table->text('description')->nullable();
            }

            if (!$this->columnExists('hrms_departments', 'sort_order')) {
                // Position among siblings. Default 0 so existing rows sort by
                // name until the backfill assigns real positions.
                $table->integer('sort_order')->default(0)->index();
            }
        });

        $this->addIndexIfMissing(
            'hrms_departments_head_user_id_index',
            'CREATE INDEX `hrms_departments_head_user_id_index` ON `hrms_departments` (`head_user_id`)',
            fn () => $this->columnExists('hrms_departments', 'head_user_id')
        );

        // The API's hot query is `WHERE sub_institute_id = ? AND status = ?
        // AND deleted_at IS NULL`. Every existing index is single-column, so
        // that query could only use one of them.
        $this->addIndexIfMissing(
            'hrms_departments_tenant_status_index',
            'CREATE INDEX `hrms_departments_tenant_status_index` ON `hrms_departments` (`sub_institute_id`, `status`)'
        );

        $this->backfillCodes();
        $this->backfillSortOrder();
    }

    public function down(): void
    {
        if (!$this->tableExists('hrms_departments')) {
            return;
        }

        $this->dropIndexIfPresent('hrms_departments_tenant_status_index');

        Schema::table('hrms_departments', function (Blueprint $table) {
            foreach (['code', 'description', 'sort_order'] as $column) {
                if ($this->columnExists('hrms_departments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // head_user_id's index is deliberately left in place. It predates this
        // migration on dev, so dropping it here would take away something this
        // migration did not add.
    }

    /**
     * Fill `code` for every row that has none.
     *
     * Chunked and scoped to `code IS NULL`, so re-running it is a no-op and a
     * code an admin has since edited by hand is never overwritten.
     */
    private function backfillCodes(): void
    {
        DB::table('hrms_departments')
            ->select('id', 'department')
            ->whereNull('code')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $code = $this->deriveCode((string) $row->department);

                    if ($code === '') {
                        continue;
                    }

                    DB::table('hrms_departments')
                        ->where('id', $row->id)
                        ->update(['code' => $code]);
                }
            });
    }

    /**
     * The frontend's departmentCode(), ported verbatim so the backfilled values
     * match what the UI was already displaying.
     */
    private function deriveCode(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        if (isset(self::EXPLICIT_CODES[$name])) {
            return self::EXPLICIT_CODES[$name];
        }

        $parts = preg_split('/[\s&]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = '';
        foreach ($parts as $part) {
            $initials .= mb_substr($part, 0, 1);
        }

        return mb_substr(mb_strtoupper($initials), 0, 6);
    }

    /**
     * Number each department sequentially within its own (tenant, parent)
     * group, ordered by name - which is the order the list was already
     * displayed in, so nothing visibly moves on first load.
     *
     * Only touches rows still at the 0 default, so a hand-ordered tree survives
     * a re-run.
     */
    private function backfillSortOrder(): void
    {
        $groups = DB::table('hrms_departments')
            ->select('sub_institute_id', 'parent_id')
            ->where('sort_order', 0)
            ->groupBy('sub_institute_id', 'parent_id')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('hrms_departments')
                ->select('id')
                ->where('sub_institute_id', $group->sub_institute_id)
                ->where('parent_id', $group->parent_id)
                ->where('sort_order', 0)
                ->orderBy('department')
                ->orderBy('id')
                ->get();

            $position = 1;
            foreach ($rows as $row) {
                DB::table('hrms_departments')
                    ->where('id', $row->id)
                    ->update(['sort_order' => $position++]);
            }
        }
    }

    /**
     * Blueprint has no "add this index only if it is absent" and the two
     * databases disagree about which indexes exist, so this asks the schema
     * directly rather than assuming.
     */
    private function addIndexIfMissing(string $indexName, string $sql, ?callable $precondition = null): void
    {
        if ($precondition !== null && !$precondition()) {
            return;
        }

        if ($this->indexExists($indexName)) {
            return;
        }

        DB::statement($sql);
    }

    private function dropIndexIfPresent(string $indexName): void
    {
        if ($this->indexExists($indexName)) {
            DB::statement("DROP INDEX `{$indexName}` ON `hrms_departments`");
        }
    }

    /**
     * Schema::hasColumn() and Schema::hasTable() cannot be used here.
     *
     * Laravel 11 introspects columns with a query that selects
     * `generation_expression` from information_schema.columns. Live runs
     * MariaDB 10.1, which has no such column - that query dies with
     * "Unknown column 'generation_expression' in 'field list'" before any of
     * this migration's own logic runs. Dev is on 10.11 and answers it fine,
     * which is exactly why the problem only appears against live.
     *
     * These two ask information_schema for the one thing being checked, using
     * columns every MySQL and MariaDB version has had for decades.
     */
    private function columnExists(string $table, string $column): bool
    {
        $found = DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
              LIMIT 1',
            [$table, $column]
        );

        return $found !== [];
    }

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

    private function indexExists(string $indexName): bool
    {
        $found = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
              LIMIT 1',
            ['hrms_departments', $indexName]
        );

        return $found !== [];
    }
};
