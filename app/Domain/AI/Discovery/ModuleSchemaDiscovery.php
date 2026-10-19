<?php

namespace App\Domain\AI\Discovery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a module's tables from the database and decides which of them the assistant may read.
 *
 * WHAT IS DERIVED
 *
 * A module's tables are those whose name starts with a prefix its own menu links imply
 * (`/module/foo-bar/...` -> `foo_bar_`), plus any declared in `config/ai_modules.php`. Columns,
 * soft-delete, primary key and foreign keys are read from the schema.
 *
 * WHAT IS NOT ASSUMED
 *
 * Finding a table does not make it safe to read, so each one is admitted only if the assistant
 * can be held to the caller's organisation: it must carry its own `sub_institute_id`. A child
 * table that reaches its organisation only through a parent (line items, status history) is
 * REPORTED as skipped with that reason, never guessed at. Secret-looking tables and columns
 * and binary/structured blobs are dropped. What this cannot know - business rules, derived
 * figures, which joins are meaningful beyond a foreign key - is listed as unsupported rather
 * than approximated.
 *
 * STAYING CURRENT
 *
 * The listing is re-read every `schema_ttl` seconds and the spec is cached under a hash of the
 * columns it was built from, so a schema change yields a new spec within that window and the old
 * one simply stops being asked for. Nothing is persisted.
 */
final class ModuleSchemaDiscovery
{
    private const TENANT = 'sub_institute_id';

    /**
     * @param  array<int, string>  $prefixes
     * @return array{
     *     fingerprint: string,
     *     prefixes: array<int, string>,
     *     tables: array<int, array<string, mixed>>,
     *     skipped: array<int, array{table:string, reason:string}>
     * }
     */
    public function discover(string $moduleKey, array $prefixes): array
    {
        $prefixes = array_values(array_unique(array_filter($prefixes)));

        if ($prefixes === []) {
            return ['fingerprint' => md5('none'), 'prefixes' => [], 'tables' => [], 'skipped' => []];
        }

        $listing = $this->listing($prefixes);
        // The rules are part of the fingerprint: changing what is withheld must rebuild the spec, not just a schema change.
        $fingerprint = md5(json_encode([$moduleKey, $prefixes, $listing, config('ai_modules')]));

        return Cache::remember(
            'ai.discovery.spec.' . $moduleKey . '.' . $fingerprint,
            now()->addDay(),
            fn () => $this->build($moduleKey, $prefixes, $listing, $fingerprint)
        );
    }

    /** Forget every cached listing so the next call reads the schema again. */
    public function refresh(): void
    {
        foreach (Cache::get('ai.discovery.listing.keys', []) as $key) {
            Cache::forget($key);
        }
        Cache::forget('ai.discovery.listing.keys');
    }

    /**
     * Candidate tables under the prefixes with their columns and foreign keys.
     *
     * @param  array<int, string>  $prefixes
     * @return array<string, array{columns: array<int, array{name:string, type:string, type_name:string, auto:bool}>, fks: array<int, array{column:string, table:string}>}>
     */
    private function listing(array $prefixes): array
    {
        $key = 'ai.discovery.listing.' . md5(implode('|', $prefixes));
        $keys = Cache::get('ai.discovery.listing.keys', []);

        if (! in_array($key, $keys, true)) {
            Cache::forever('ai.discovery.listing.keys', array_merge($keys, [$key]));
        }

        return Cache::remember(
            $key,
            max(1, (int) config('ai_modules.schema_ttl', 60)),
            fn () => DB::getDriverName() === 'mysql' ? $this->readMysql($prefixes) : $this->readPortable($prefixes)
        );
    }

    /**
     * One query for every column and one for every foreign key, whatever the number of tables.
     *
     * Asking per table (Schema::getColumns) costs a round trip each and took over a minute against the
     * shared database; the module's whole schema is a single information_schema read.
     *
     * @param  array<int, string>  $prefixes
     * @return array<string, array<string, mixed>>
     */
    private function readMysql(array $prefixes): array
    {
        $match = [];
        $bindings = [];

        foreach ($prefixes as $prefix) {
            $match[] = 'LEFT(c.table_name, ' . strlen($prefix) . ') = ?';
            $bindings[] = $prefix;
        }

        $rows = DB::select(
            "SELECT c.table_name AS t, c.column_name AS n, c.data_type AS dt, c.column_type AS ct, c.extra AS ex
               FROM information_schema.columns c
               JOIN information_schema.tables x ON x.table_schema = c.table_schema AND x.table_name = c.table_name AND x.table_type = 'BASE TABLE'
              WHERE c.table_schema = DATABASE() AND (" . implode(' OR ', $match) . ')
              ORDER BY c.table_name, c.ordinal_position',
            $bindings
        );

        $out = [];

        foreach ($rows as $r) {
            $out[$r->t]['columns'][] = [
                'name' => (string) $r->n,
                'type' => strtolower((string) $r->ct),
                'type_name' => strtolower((string) $r->dt),
                'auto' => str_contains(strtolower((string) $r->ex), 'auto_increment'),
            ];
            $out[$r->t]['fks'] ??= [];
        }

        ksort($out);
        $out = array_slice($out, 0, (int) config('ai_modules.max_tables', 80), true);

        try {
            $fks = DB::select(
                'SELECT k.table_name AS t, k.column_name AS n, k.referenced_table_name AS rt
                   FROM information_schema.key_column_usage k
                  WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND (' . str_replace('c.table_name', 'k.table_name', implode(' OR ', $match)) . ')',
                $bindings
            );

            foreach ($fks as $fk) {
                if (isset($out[$fk->t])) {
                    $out[$fk->t]['fks'][] = ['column' => (string) $fk->n, 'table' => (string) $fk->rt];
                }
            }
        } catch (Throwable) {
            // Foreign keys are an enhancement; their absence only costs the label join.
        }

        return $out;
    }

    /**
     * The same listing through Laravel's schema builder, for databases other than MySQL/MariaDB.
     *
     * @param  array<int, string>  $prefixes
     * @return array<string, array<string, mixed>>
     */
    private function readPortable(array $prefixes): array
    {
        $names = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];

            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $names[] = $name;
                    break;
                }
            }
        }

        sort($names);
        $out = [];

        foreach (array_slice($names, 0, (int) config('ai_modules.max_tables', 80)) as $name) {
            $columns = array_map(fn (array $c) => [
                'name' => (string) $c['name'],
                'type' => strtolower((string) ($c['type'] ?? '')),
                'type_name' => strtolower((string) ($c['type_name'] ?? '')),
                'auto' => (bool) ($c['auto_increment'] ?? false),
            ], Schema::getColumns($name));

            $fks = [];
            try {
                foreach (Schema::getForeignKeys($name) as $fk) {
                    if (count($fk['columns']) === 1) {
                        $fks[] = ['column' => (string) $fk['columns'][0], 'table' => (string) $fk['foreign_table']];
                    }
                }
            } catch (Throwable) {
                // see readMysql()
            }

            $out[$name] = ['columns' => $columns, 'fks' => $fks];
        }

        return $out;
    }
    /**
     * @param  array<int, string>  $prefixes
     * @param  array<string, array<string, mixed>>  $listing
     * @return array<string, mixed>
     */
    private function build(string $moduleKey, array $prefixes, array $listing, string $fingerprint): array
    {
        $sensitiveColumns = (string) config('ai_modules.sensitive_columns');
        $sensitiveTables = (string) config('ai_modules.sensitive_tables');
        $pii = (string) config('ai_modules.pii_columns');
        $blocked = (array) config('ai_modules.blocked_column_types', []);
        $maxColumns = (int) config('ai_modules.max_columns', 24);

        $tables = [];
        $skipped = [];
        $orphans = [];

        /*
         * Decide what of a table the assistant may read. $via is null for a table that carries its own
         * sub_institute_id, or names the parent through which a table without one is held to the caller's
         * organisation (a quote's line items reach their organisation only through the quote).
         */
        $admit = function (string $name, array $info, ?array $via) use ($sensitiveColumns, $pii, $blocked, $maxColumns, &$skipped): ?array {
            $names = array_column($info['columns'], 'name');
            $visible = [];
            $dropped = [];

            foreach ($info['columns'] as $column) {
                $isBlocked = in_array($column['type_name'], $blocked, true) || in_array(preg_replace('/\(.*/', '', $column['type']), $blocked, true);

                if ($column['name'] === self::TENANT) {
                    continue;
                }

                if ($isBlocked || preg_match($sensitiveColumns, $column['name']) === 1 || preg_match($pii, $column['name']) === 1) {
                    $dropped[] = $column['name'];
                    continue;
                }

                $visible[] = $column;
            }

            $primary = null;
            foreach ($info['columns'] as $column) {
                if ($column['auto']) {
                    $primary = $column['name'];
                    break;
                }
            }
            $primary ??= in_array('id', $names, true) ? 'id' : null;

            if (array_filter($visible, fn ($c) => $c['name'] !== $primary && $c['name'] !== 'deleted_at') === []) {
                $skipped[] = ['table' => $name, 'reason' => 'No readable columns remain after removing identifiers, secrets and binary columns.'];

                return null;
            }

            return [
                'table' => $name,
                'primary' => $primary,
                'soft_deletes' => in_array('deleted_at', $names, true),
                'order_by' => in_array('created_at', $names, true) ? 'created_at' : $primary,
                'columns' => array_slice($visible, 0, $maxColumns),
                'dropped_columns' => $dropped,
                'fks' => $info['fks'],
                'joins' => [],
                'tenant_via' => $via,
            ];
        };

        // First pass: tables that carry their own organisation column.
        foreach ($listing as $name => $info) {
            if (preg_match($sensitiveTables, $name) === 1) {
                $skipped[] = ['table' => $name, 'reason' => 'The table name looks like it holds credentials or sessions, so it is never read.'];
                continue;
            }

            if (! in_array(self::TENANT, array_column($info['columns'], 'name'), true)) {
                $orphans[$name] = $info;
                continue;
            }

            if (($spec = $admit($name, $info, null)) !== null) {
                $tables[$name] = $spec;
            }
        }

        // Second pass: a table with no organisation column is read only through a parent that has one.
        // Unambiguous means exactly one candidate parent; two would make the scope a guess.
        foreach ($orphans as $name => $info) {
            $parents = [];
            $fkByColumn = [];

            foreach ($info['fks'] as $fk) {
                $fkByColumn[$fk['column']] = $fk['table'];
            }

            foreach ($info['columns'] as $column) {
                $col = $column['name'];

                if (! str_ends_with($col, '_id') || strlen($col) <= 3) {
                    continue;
                }

                $parent = $this->parentOf($col, $fkByColumn, $tables, $prefixes);

                if ($parent !== null && $tables[$parent]['primary'] !== null && $tables[$parent]['tenant_via'] === null) {
                    $parents[$col] = $parent;
                }
            }

            // Several lookups can point away from a child table (a line item has a quote, a product, a tax
            // rate). The one that OWNS it is the one the table is named after: quote_line_items belongs to
            // quotes. If that does not single out exactly one, it stays a guess and the table is skipped.
            if (count($parents) > 1) {
                $childShort = $this->short($name, $prefixes);
                $owners = array_filter($parents, fn ($parent) => str_starts_with($childShort . '_', Str::singular($this->short($parent, $prefixes)) . '_'));

                if (count($owners) === 1) {
                    $parents = $owners;
                }
            }

            if (count($parents) !== 1) {
                $skipped[] = ['table' => $name, 'reason' => $parents === []
                    ? 'No sub_institute_id column and no foreign key to a table that has one: its rows cannot be limited to the caller\'s organisation.'
                    : 'No sub_institute_id column and more than one possible parent (' . implode(', ', $parents) . '): which one scopes it would be a guess.'];
                continue;
            }

            $col = array_key_first($parents);
            $spec = $admit($name, $info, ['column' => $col, 'parent' => $parents[$col], 'parent_key' => $tables[$parents[$col]]['primary']]);

            if ($spec !== null) {
                $tables[$name] = $spec;
            }
        }

        // Third pass: relationships, now that the admissible tables are known.
        foreach ($tables as $name => &$table) {
            $table['joins'] = $this->relationships($name, $table, $tables, $prefixes);
        }
        unset($table);

        ksort($tables);

        return [
            'fingerprint' => $fingerprint,
            'prefixes' => $prefixes,
            'tables' => array_values($tables),
            'skipped' => $skipped,
        ];
    }

    /** @param array<int, string> $prefixes */
    private function short(string $table, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return substr($table, strlen($prefix));
            }
        }

        return $table;
    }

    /**
     * The admissible table a `<thing>_id` column points at: a real foreign key if there is one,
     * otherwise `<prefix><thing>(s)` of the same module.
     *
     * @param  array<string, string>  $fkByColumn
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<int, string>  $prefixes
     */
    private function parentOf(string $col, array $fkByColumn, array $tables, array $prefixes): ?string
    {
        $parent = $fkByColumn[$col] ?? null;

        if ($parent === null) {
            $thing = substr($col, 0, -3);

            foreach ($prefixes as $prefix) {
                foreach ([$thing . 's', $thing . 'es', $thing, Str::plural($thing)] as $candidate) {
                    if (isset($tables[$prefix . $candidate])) {
                        return $prefix . $candidate;
                    }
                }
            }

            return null;
        }

        return isset($tables[$parent]) ? $parent : null;
    }
    /**
     * Columns that point at another admissible table of the same module, with a column to name the
     * parent by. A real foreign key wins; otherwise `<thing>_id` is matched to `<prefix><thing>(s)`.
     *
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<int, string>  $prefixes
     * @return array<int, array{column:string, parent:string, label:string, alias:string}>
     */
    private function relationships(string $name, array $table, array $tables, array $prefixes): array
    {
        $existing = array_column($table['columns'], 'name');
        $labels = (array) config('ai_modules.label_columns', []);
        $joins = [];
        $fkByColumn = [];

        foreach ($table['fks'] as $fk) {
            $fkByColumn[$fk['column']] = $fk['table'];
        }

        foreach ($table['columns'] as $column) {
            $col = $column['name'];

            if (! str_ends_with($col, '_id') || $col === $table['primary'] || strlen($col) <= 3) {
                continue;
            }

            $parent = $fkByColumn[$col] ?? null;

            if ($parent === null) {
                $thing = substr($col, 0, -3);
                foreach ($prefixes as $prefix) {
                    foreach ([$thing . 's', $thing . 'es', $thing, Str::plural($thing)] as $candidate) {
                        if (isset($tables[$prefix . $candidate])) {
                            $parent = $prefix . $candidate;
                            break 2;
                        }
                    }
                }
            }

            if ($parent === null || $parent === $name || ! isset($tables[$parent]) || $tables[$parent]['primary'] === null || $tables[$parent]['tenant_via'] !== null) {
                continue;
            }

            $parentCols = array_column($tables[$parent]['columns'], 'name');
            $label = null;
            foreach ($labels as $candidate) {
                if (in_array($candidate, $parentCols, true)) {
                    $label = $candidate;
                    break;
                }
            }

            $alias = substr($col, 0, -3);

            if ($label === null || in_array($alias, $existing, true)) {
                continue;
            }

            $joins[] = ['column' => $col, 'parent' => $parent, 'parent_key' => $tables[$parent]['primary'], 'label' => $label, 'alias' => $alias];
        }

        return $joins;
    }
}
