<?php

namespace App\Support;

use Illuminate\Database\Connection;
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
    public static function has(string $table, string $column, string|Connection|null $connection = null): bool
    {
        $conn = $connection instanceof Connection ? $connection : DB::connection($connection);
        $driver = $conn->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $connName = $connection instanceof Connection ? $connection->getName() : $connection;
            return Schema::connection($connName)->hasColumn($table, $column);
        }

        $pdo = $conn->getPdo();
        $rows = $conn->select(self::showColumnsLikeSql($table, $column, fn (string $v): string => (string) $pdo->quote($v)));

        // LIKE treats "_" and "%" as wildcards, so keep only the exact column name.
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row->Field ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The statement has() sends on MySQL / MariaDB. The column name is a quoted literal, never a "?" placeholder: the live MariaDB 10.1
     * rejects `SHOW COLUMNS ... LIKE ?` with SQLSTATE 42000 / error 1064 (measured on both hosts on 2026-11-02; 10.11 accepts it). The
     * quoting is passed in so the shape of the statement can be tested without a MySQL connection.
     *
     * @param  callable(string): string  $quote
     */
    public static function showColumnsLikeSql(string $table, string $column, callable $quote): string
    {
        return 'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $quote($column);
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
