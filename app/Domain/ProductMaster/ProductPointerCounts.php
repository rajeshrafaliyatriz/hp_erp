<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * How many rows point at each of SEVERAL records at once (S15 follow-up, 24 Nov 2026; found on the dev database, where one query takes 150 to 250 ms and the preview of a Product with
 * Sub-products answered 500 after 74 seconds).
 *
 * ProductUseCount answers "what points at THIS record" and asks every pointer table of the registry once per record: about 40 queries a record, so a Product with N Sub-products cost
 * N times that. This class asks the same question of the same registry (ProductPointers, the same labels, the same numbers) for a whole LIST of ids: ONE grouped query per pointer
 * column over all the ids (`... WHERE column IN (...) GROUP BY column`), split in PHP, and one read of the column list of each table per instance (not a `hasTable` and a
 * `SHOW COLUMNS` per record per table). The number of queries depends on the number of pointer tables and not on the number of records.
 *
 * Three families, as the registry has them: at the catalogue id (toCrmProduct), at the master id (toMaster, without `crm_products.product_master_id`, which is the Product line of a
 * Sell row and is dealt with as part of the Sub-products; counted by label, as ProductRemoval always did), and at the About's row number (toOffer).
 *
 * Like ProductUseCount it counts the ROWS THAT POINT, whatever organisation or state they are in: a delete would reach them all. A table or a column the host does not have yet is
 * skipped. MariaDB 10.1 safe: `IN` and `GROUP BY` only.
 */
final class ProductPointerCounts
{
    /** How many ids one statement carries (a Product has a handful of Sub-products; the cap only keeps a pathological list inside the packet size). */
    private const CHUNK = 500;

    /** What a person reads for each pointer at the master id (ProductPointers::toMaster()). */
    public const MASTER_LABELS = [
        'crm_leads' => 'leads', 'crm_opportunities' => 'opportunities', 'crm_campaigns' => 'campaigns', 'g2g_company_opportunities' => 'signals', 'g2g_product_claims' => 'approved claims',
        'g2g_research_runs' => 'research runs', 'gtm_campaigns' => 'GTM campaigns', 'gtm_outreach_messages' => 'outreach messages', 'gtm_playbooks' => 'playbooks',
    ];

    /** @var array<string, list<string>> table => its columns, read once per instance (an unknown table is an empty list) */
    private array $columns = [];

    private bool $primed = false;

    /**
     * What points at each catalogue row (crm_products.id), registry order, only the pointers that hold it.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{table: string, column: string, label: string, count: int}>> id => the pointers that hold it
     */
    public function atCatalogueItems(array $ids): array
    {
        return $this->count(ProductPointers::toCrmProductInUse(), $ids, fn (string $table, string $column): string => ProductUseCount::LABELS["{$table}.{$column}"] ?? "{$table}.{$column}");
    }

    /**
     * What points at each About row (g2g_product_offers.id) by its number: the registry's toOffer() family.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{table: string, column: string, label: string, count: int}>>
     */
    public function atOffers(array $ids): array
    {
        return $this->count(ProductPointers::toOffer(), $ids, fn (string $table, string $column): string => ProductUseCount::LABELS["{$table}.{$column}"] ?? "{$table}.{$column}");
    }

    /**
     * What points at each master row, except `crm_products.product_master_id`; one entry per LABEL (the sum of the pointers that share it), in the order they first appear.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{table: string, column: string, label: string, count: int}>>
     */
    public function atMasters(array $ids): array
    {
        $entries = array_values(array_filter(ProductPointers::toMaster(), fn (array $e): bool => $e['table'] !== ProductPointers::CRM_PRODUCTS));
        $perPointer = $this->count($entries, $ids, fn (string $table, string $column): string => self::MASTER_LABELS[$table] ?? "{$table}.{$column}");
        $out = [];
        foreach ($perPointer as $id => $used) {
            $byLabel = [];
            foreach ($used as $u) {
                $byLabel[$u['label']] = ['table' => $u['table'], 'column' => $u['column'], 'label' => $u['label'], 'count' => ($byLabel[$u['label']]['count'] ?? 0) + $u['count']];
            }
            $out[$id] = array_values($byLabel);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $entries  registry entries
     * @param  list<int>  $ids
     * @param  callable(string, string): string  $label
     * @return array<int, list<array{table: string, column: string, label: string, count: int}>>
     */
    private function count(array $entries, array $ids, callable $label): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        $found = array_fill_keys($ids, []);
        if ($ids === []) {
            return $found;
        }
        $this->prime();
        foreach ($entries as $entry) {
            $table = (string) $entry['table'];
            $column = (string) $entry['column'];
            if (! in_array($column, $this->columnsOf($table), true)) {
                continue;
            }
            $counts = [];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (DB::table($table)->select($column)->selectRaw('COUNT(*) as pointer_rows')->whereIn($column, $chunk)->groupBy($column)->get() as $row) {
                    $counts[(int) $row->{$column}] = (int) $row->pointer_rows;
                }
            }
            foreach ($ids as $id) {
                if (($counts[$id] ?? 0) > 0) {
                    $found[$id][] = ['table' => $table, 'column' => $column, 'label' => $label($table, $column), 'count' => $counts[$id]];
                }
            }
        }

        return $found;
    }

    /**
     * The columns of EVERY pointer table in ONE statement, so that finding out which tables and columns the host has costs one query and not one per table. On MySQL and MariaDB it is
     * the bound `information_schema.COLUMNS` form that DB-RULES verified on both hosts; on sqlite (the tests) the table-valued `pragma_table_info`. A table the answer does not name is
     * an empty list and is skipped. Where the statement fails, or on another driver, the per-table read in columnsOf() does the job.
     */
    private function prime(): void
    {
        if ($this->primed) {
            return;
        }
        $this->primed = true;
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        $tables = [];
        foreach ([...ProductPointers::toCrmProduct(), ...ProductPointers::toMaster(), ...ProductPointers::toOffer()] as $entry) {
            $tables[(string) $entry['table']] = true;
        }
        $names = array_keys($tables);
        $marks = implode(', ', array_fill(0, count($names), '?'));
        try {
            $rows = $driver === 'sqlite'
                ? DB::select("SELECT m.name AS t, p.name AS c FROM sqlite_master m, pragma_table_info(m.name) p WHERE m.type = 'table' AND m.name IN ({$marks})", $names)
                : DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$marks})", $names);
        } catch (QueryException) {
            return;
        }
        $byTable = array_fill_keys($names, []);
        foreach ($rows as $row) {
            $byTable[(string) $row->t][] = (string) $row->c;
        }
        foreach ($byTable as $table => $columns) {
            $this->columns[$table] = $columns;
        }
    }

    /** The columns of a table, read ONCE per instance: one statement tells a missing table (an error on MariaDB, an empty list elsewhere) from a missing column. @return list<string> */
    private function columnsOf(string $table): array
    {
        if (! isset($this->columns[$table])) {
            try {
                $this->columns[$table] = ColumnExists::all($table);
            } catch (QueryException) {
                $this->columns[$table] = [];
            }
        }

        return $this->columns[$table];
    }
}
