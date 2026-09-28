<?php

namespace App\Domain\AI\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Table/column existence, asked once per request.
 *
 * The module-scoped AI Stack readers open almost every section by asking whether the
 * table they want exists, because a deployment can be behind on migrations and a
 * section should say "not on this estate" rather than 500. Asked through
 * information_schema (Schema::hasTable() has thrown on this estate's MariaDB 10.1) and
 * memoised, so those probes cost one query per table, not one per section.
 */
class SchemaCache
{
    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tables)) {
            return $this->tables[$table];
        }

        try {
            $exists = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            )->c > 0;
        } catch (Throwable) {
            $exists = false;
        }

        return $this->tables[$table] = $exists;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (array_key_exists($key, $this->columns)) {
            return $this->columns[$key];
        }

        try {
            $exists = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            )->c > 0;
        } catch (Throwable) {
            $exists = false;
        }

        return $this->columns[$key] = $exists;
    }
}
