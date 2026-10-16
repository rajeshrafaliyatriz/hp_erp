<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Does this table have this column?" that also works on the live MariaDB 10.1.
 *
 * Schema::hasColumn() reads the column list through a query that selects information_schema.generation_expression, a column that only exists
 * from MariaDB 10.2, so on 10.1 it fails with "Unknown column 'generation_expression'". SHOW COLUMNS works on every MySQL / MariaDB version.
 * Other drivers (the sqlite test database) keep using Laravel's own check.
 */
final class ColumnExists
{
    public static function has(string $table, string $column): bool
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return Schema::hasColumn($table, $column);
        }

        return DB::select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?', [$column]) !== [];
    }

    /**
     * Every column of a table, by the same portable route (SHOW COLUMNS on MySQL / MariaDB; Laravel's own listing elsewhere).
     * Schema::getColumnListing() has the same MariaDB 10.1 problem as Schema::hasColumn().
     *
     * @return list<string>
     */
    public static function all(string $table): array
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return Schema::getColumnListing($table);
        }

        return array_map(fn ($c) => (string) $c->Field, DB::select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`'));
    }
}
