<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read-only proof that products are still wired together (S15 plan section 2.1, Docs/agentic-crm/slices/S15-product-portfolio-merge.md).
 *
 * A product lives in up to three stores (the master row, the Portfolio offer, the CRM catalogue item) and is pointed at by 21 columns in 19 tables
 * (ProductPointers). This class answers two questions without ever writing:
 *
 *   check()   do the pointers resolve and do the stores agree?   Invariants, an ERROR makes `product-master:check` exit non-zero, a WARNING is only printed:
 *       I1  error    a registered pointer holds a value and the row it points at does not exist (for crm_products.product_master_id: is not a Product)
 *       I1T error    a registered pointer points at a row of another organisation (a shared row, organisation 0, is fine); counted
 *       I2  error    a master row whose crm_product_id, offer_row_id or parent_id does not resolve, or whose parent is not kind `product`
 *       I3  error    two live master rows of one organisation share a crm_product_id or an offer_row_id
 *       I4  error    a live (not in the Recycle Bin) catalogue item, or a Portfolio offer, that no master row points at
 *       I5  error    the stores disagree: names (master, offer, catalogue item), offers.parent_product against the parent's portfolio_name,
 *                    crm_products.product_master_id against the master row's parent_id. A row holding one pointer is checked against what exists.
 *       not_isolated  error  a master row of organisation 0, or a Portfolio About with no organisation (NULL): a product row belongs to ONE organisation (S15 follow-up, 24 Nov 2026,
 *                    D-1). `php artisan product-master:isolate-tenants` gives such rows to their owner; after it the count is 0. Looked at for every organisation, or for
 *                    `--tenant=0` (the leftovers' own scope); a run limited to one organisation does not report them, they are nobody's.
 *       W1  warning  a live master row points at a catalogue item that is in the Recycle Bin
 *       W2  warning  a shared (organisation 0) master row holds a catalogue pointer
 *       W3  warning  schema drift (schemaDrift(), run by checkWithSchema() and so by the command): a column of crm_products, g2g_product_offers, g2g_product_master or
 *                    g2g_signal_products on this host that no migration defines, or one a migration defines that this host lacks (the dev host lacks
 *                    g2g_product_offers.icp_config, for instance). check() itself never runs it: it needs the migration files, and its callers run on minimal tables.
 *   census()  a JSON-able count of the rows behind every pointer table (rows, SUM of the quantity and total columns, rows per product id for the line
 *             tables). Taken before and after a change, differences() lists exactly what moved: a lost or added line, a changed quantity or total, a line
 *             that now points at another product.
 *
 * READ ONLY by construction: only SELECT statements are issued (tests/Feature/ProductIntegrityTest proves it from the query log and from the rows), and the
 * command asks the server to make its session read-only as well. Tables are looked up lazily: a table or column the host does not have yet (the older live
 * schema) is skipped and listed in `skipped`, never an error. Built on an explicit connection and no transaction of its own, so the unify step can run it
 * inside the transaction that is about to commit. MariaDB 10.1 safe: plain joins, no JSON, no window functions, no CTEs, no bound SHOW.
 */
final class ProductIntegrity
{
    public const FORMAT = 'product-master-census/1';

    /** How many findings of one code are kept in the report (the count is always exact). */
    public const SAMPLE = 50;

    /** Ids per IN (...) list, under sqlite's 999-variable limit. */
    private const CHUNK = 400;

    /** @var array<string, array{0: string, 1: string}> code => [severity, what it means] */
    public const CODES = [
        'I1' => ['error', 'pointer at a product whose target row does not exist (or is not a Product where it must be)'],
        'I1T' => ['error', 'pointer that crosses to another organisation'],
        'I2' => ['error', 'master row with a crm_product_id, offer_row_id or parent_id that does not resolve, or a parent that is not a Product'],
        'I3' => ['error', 'two live master rows of one organisation share a crm_product_id or an offer_row_id'],
        'I4' => ['error', 'live catalogue item or Portfolio offer that no master row points at'],
        'I5' => ['error', 'the stores disagree (name, parent product, product line)'],
        'not_isolated' => ['error', 'master row of organisation 0 or Portfolio About with no organisation: a product row belongs to one organisation'],
        'W1' => ['warning', 'live master row points at a catalogue item in the Recycle Bin'],
        'W2' => ['warning', 'shared (organisation 0) master row holds a catalogue pointer'],
        'W3' => ['warning', 'schema drift: a column on this host that no migration defines, or a migration column this host lacks'],
    ];

    /** The product tables W3 compares with their migrations. */
    public const SCHEMA_TABLES = [ProductPointers::CRM_PRODUCTS, ProductPointers::OFFERS, ProductPointers::MASTER, 'g2g_signal_products'];

    /** The statements that make a MySQL / MariaDB session refuse every write (the server then enforces what the code only promises). */
    public const READ_ONLY_SQL = 'SET SESSION TRANSACTION READ ONLY';

    public const READ_ONLY_CHECK_SQL = 'SELECT @@SESSION.tx_read_only AS ro';

    private readonly Connection $db;

    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $columns = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> findings stored per code */
    private array $stored = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var array<string, int> */
    private array $checked = [];

    /**
     * @param ?int $tenant only the rows of this organisation (0 = the shared rows); every organisation when null. Targets are always looked up everywhere.
     */
    public function __construct(?string $connection = null, private readonly ?int $tenant = null)
    {
        $this->db = DB::connection($connection);
    }

    /** @return array{name: string, driver: string, host: ?string, database: ?string} what the command prints about the server it read (never a credential) */
    public function connectionInfo(): array
    {
        $config = $this->db->getConfig();

        return [
            'name' => (string) $this->db->getName(),
            'driver' => (string) $this->db->getDriverName(),
            'host' => isset($config['host']) ? (string) $config['host'] : null,
            'database' => isset($config['database']) ? (string) $config['database'] : null,
        ];
    }

    /**
     * Asks a MySQL / MariaDB server to refuse every write on this session, then reads the setting back. Other drivers (the sqlite test database) are left alone.
     *
     * @return string 'yes', 'not needed on <driver>' or 'no: <why>'
     */
    public function lockSessionReadOnly(): string
    {
        $driver = $this->db->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return "not needed on {$driver}";
        }

        try {
            $this->db->statement(self::READ_ONLY_SQL);
            $row = $this->db->selectOne(self::READ_ONLY_CHECK_SQL);

            return $row !== null && (int) ($row->ro ?? 0) === 1 ? 'yes' : 'no: the server did not confirm it';
        } catch (\Throwable $e) {
            return 'no: ' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------ check

    /**
     * Runs every invariant.
     *
     * @return array{tenant: ?int, errors: int, warnings: int, counts: array<string, int>, findings: list<array<string, mixed>>, skipped: list<string>, checked: array<string, int>}
     */
    public function check(): array
    {
        $this->findings = [];
        $this->stored = [];
        $this->skipped = [];
        $this->counts = array_fill_keys(array_keys(self::CODES), 0);
        $this->checked = ['pointers' => 0, 'masterRows' => 0, 'catalogueItems' => 0, 'offers' => 0];

        foreach (ProductPointers::all() as $entry) {
            $this->checkPointer($entry);
        }
        $this->checkMasterRows();
        $this->checkCoverage();
        $this->checkIsolation();

        $errors = 0;
        $warnings = 0;
        foreach (self::CODES as $code => [$severity]) {
            if ($severity === 'error') {
                $errors += $this->counts[$code];
            } else {
                $warnings += $this->counts[$code];
            }
        }

        return [
            'tenant' => $this->tenant, 'errors' => $errors, 'warnings' => $warnings, 'counts' => $this->counts,
            'findings' => $this->findings, 'skipped' => array_values(array_unique($this->skipped)), 'checked' => $this->checked,
        ];
    }

    /**
     * check() plus W3: what `product-master:check` runs. The W3 findings and count are merged into the report of check(), and a W3 warning counts as a warning
     * (the exit code of the command stays 0 for it, like W1 and W2).
     *
     * @param  ?string  $migrations  the folder of migration files to compare with (the application's own when null)
     * @return array{tenant: ?int, errors: int, warnings: int, counts: array<string, int>, findings: list<array<string, mixed>>, skipped: list<string>, checked: array<string, int>}
     */
    public function checkWithSchema(?string $migrations = null): array
    {
        $report = $this->check();
        $drift = $this->schemaDrift($migrations);

        $report['counts']['W3'] = $drift['count'];
        $report['warnings'] += $drift['count'];
        $report['findings'] = array_merge($report['findings'], $drift['findings']);
        $report['skipped'] = array_values(array_unique(array_merge($report['skipped'], $drift['skipped'])));
        $report['checked']['schemaTables'] = $drift['checked'];

        return $report;
    }

    /**
     * W3: for each of SCHEMA_TABLES, the columns the host really has (SHOW COLUMNS on MySQL / MariaDB, which works on 10.1; Laravel's own listing elsewhere, so
     * sqlite works) against the columns the migrations declare (MigrationColumns, the reader the scan tests use). A column on the host that no migration defines, and
     * a migration column the host lacks, are each one finding that names the table and the column (the second also names the migration file). A table the host does
     * not have, or one no migration of the folder works on, is skipped and listed, never a finding. Read only. Names are compared without regard to case.
     *
     * @param  ?string  $migrations  the folder of migration files to compare with (the application's own when null)
     * @return array{count: int, findings: list<array<string, mixed>>, skipped: list<string>, checked: int}
     */
    public function schemaDrift(?string $migrations = null): array
    {
        $dir = $migrations ?? MigrationColumns::directory();
        $count = 0;
        $findings = [];
        $skipped = [];
        $checked = 0;

        foreach (self::SCHEMA_TABLES as $table) {
            if (! $this->hasTable($table)) {
                $skipped[] = "{$table}: the table is not on this host, so W3 did not compare its columns";

                continue;
            }
            $declared = [];
            foreach (MigrationColumns::columns($dir, $table) as $column => $files) {
                $declared[strtolower($column)] = ['column' => $column, 'file' => $files[0]];
            }
            if ($declared === []) {
                $skipped[] = "{$table}: no migration in {$dir} works on it, so W3 did not compare its columns";

                continue;
            }
            $host = [];
            foreach ($this->hostColumns($table) as $column) {
                $host[strtolower($column)] = $column;
            }
            $checked++;

            $problems = [];
            foreach ($declared as $key => $d) {
                if (! isset($host[$key])) {
                    $problems[] = ['kind' => 'migration_only', 'column' => $d['column'], 'message' => "{$table}.{$d['column']}: a migration ({$d['file']}) defines it but this host lacks it"];
                }
            }
            foreach ($host as $key => $column) {
                if (! isset($declared[$key])) {
                    $problems[] = ['kind' => 'host_only', 'column' => $column, 'message' => "{$table}.{$column}: this host has it but no migration defines it"];
                }
            }
            foreach ($problems as $p) {
                $count++;
                if ($count <= self::SAMPLE) {
                    $findings[] = ['code' => 'W3', 'severity' => self::CODES['W3'][0], 'message' => $p['message'], 'table' => $table, 'column' => $p['column'], 'kind' => $p['kind']];
                }
            }
        }

        return ['count' => $count, 'findings' => $findings, 'skipped' => $skipped, 'checked' => $checked];
    }

    /** @return list<string> the real columns of a table on this host (MariaDB 10.1 safe: Schema::getColumnListing and hasColumn read information_schema.generation_expression, which 10.1 lacks) */
    private function hostColumns(string $table): array
    {
        if (in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return array_values(array_map(fn ($row): string => (string) $row->Field, $this->db->select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')));
        }

        return array_values(array_map('strval', Schema::connection($this->db->getName())->getColumnListing($table)));
    }

    /** I1 and I1T for one registered pointer. @param array<string, mixed> $e */
    private function checkPointer(array $e): void
    {
        $label = $e['table'] . '.' . $e['column'];
        if (! $this->hasTable($e['table'])) {
            $this->skip("{$label}: the table is not on this host");

            return;
        }
        if (! $this->hasColumn($e['table'], $e['column'])) {
            $this->skip("{$label}: the column is not on this host");

            return;
        }
        if (! $this->hasTable($e['target'])) {
            $this->skip("{$label}: its target table {$e['target']} is not on this host");

            return;
        }
        [$base, $tenantExpr] = $this->referrers($e);
        if ($this->tenant !== null && $tenantExpr === null) {
            $this->skip("{$label}: cannot be limited to one organisation on this host");

            return;
        }
        $this->checked['pointers']++;
        $col = 'r.' . $e['column'];

        // A value that points at nothing.
        $orphans = (clone $base)->leftJoin($e['target'] . ' as t', 't.id', '=', $col)->whereNotNull($col)->where($col, '>', 0)->whereNull('t.id');
        $this->flag('I1', $orphans, $e, $tenantExpr, [], fn (object $row): string => "{$label}: row {$row->rid} points at {$e['target']} {$row->pid}, which does not exist");

        // A value that points at a row that is there but is the wrong kind of row (crm_products.product_master_id must be a Product).
        if (isset($e['target_kind']) && $this->hasColumn($e['target'], 'kind')) {
            $wrong = (clone $base)->join($e['target'] . ' as t', 't.id', '=', $col)->where($col, '>', 0)->where('t.kind', '<>', $e['target_kind']);
            $this->flag('I1', $wrong, $e, $tenantExpr, ['t.kind as tk'], fn (object $row): string => "{$label}: row {$row->rid} points at {$e['target']} {$row->pid}, which is a {$row->tk}, not a {$e['target_kind']}");
        }

        // A value that points into another organisation. Rows of a shared (0) target are for everybody.
        if ($tenantExpr !== null && $this->hasColumn($e['target'], 'sub_institute_id')) {
            $cross = (clone $base)->join($e['target'] . ' as t', 't.id', '=', $col)->where($col, '>', 0)
                ->whereRaw("{$tenantExpr} IS NOT NULL")->whereRaw("t.sub_institute_id <> {$tenantExpr}")->where('t.sub_institute_id', '<>', 0);
            $this->flag('I1T', $cross, $e, $tenantExpr, ['t.sub_institute_id as tt'], fn (object $row): string => "{$label}: row {$row->rid} of organisation {$row->rt} points at {$e['target']} {$row->pid} of organisation {$row->tt}");
        }
    }

    /**
     * The master rows, read once and compared in memory (the master table is small, and string comparison must be exact on every engine, which a SQL
     * comparison under a case-insensitive collation is not): I2, I3, I5, W1, W2.
     */
    private function checkMasterRows(): void
    {
        if (! $this->hasTable(ProductPointers::MASTER)) {
            $this->skip('g2g_product_master: the table is not on this host, so I2, I3, I4, I5, W1 and W2 were skipped');

            return;
        }

        $query = $this->db->table(ProductPointers::MASTER)->orderBy('id');
        if ($this->tenant !== null) {
            $query->where('sub_institute_id', $this->tenant);
        }
        $masters = $query->get(['id', 'sub_institute_id', 'kind', 'parent_id', 'name', 'status', 'portfolio_name', 'offer_row_id', 'crm_product_id']);
        $this->checked['masterRows'] = $masters->count();

        $crmColumns = ['id', 'sub_institute_id', 'name', 'deleted_at'];
        $crmHasLine = $this->hasColumn(ProductPointers::CRM_PRODUCTS, 'product_master_id');
        if ($crmHasLine) {
            $crmColumns[] = 'product_master_id';
        }
        $crm = $this->fetchByIds(ProductPointers::CRM_PRODUCTS, $this->idsOf($masters, 'crm_product_id'), $crmColumns);
        $offers = $this->fetchByIds(ProductPointers::OFFERS, $this->idsOf($masters, 'offer_row_id'), ['id', 'sub_institute_id', 'name', 'parent_product']);
        $parents = $this->fetchByIds(ProductPointers::MASTER, $this->idsOf($masters, 'parent_id'), ['id', 'sub_institute_id', 'kind', 'portfolio_name']);
        if ($crm === null) {
            $this->skip('crm_products: the table is not on this host, so the catalogue pointers of the master rows were not followed');
        }
        if ($offers === null) {
            $this->skip('g2g_product_offers: the table is not on this host, so the offer pointers of the master rows were not followed');
        }

        $liveByCrm = [];
        $liveByOffer = [];

        foreach ($masters as $m) {
            $id = (int) $m->id;
            $tenant = (int) $m->sub_institute_id;
            $crmId = $m->crm_product_id !== null ? (int) $m->crm_product_id : 0;
            $offerId = $m->offer_row_id !== null ? (int) $m->offer_row_id : 0;
            $parentId = $m->parent_id !== null ? (int) $m->parent_id : 0;
            $live = $m->status === ProductMaster::STATUS_ACTIVE;

            if ($live && $crmId > 0) {
                $liveByCrm[$tenant . '|' . $crmId][] = $id;
            }
            if ($live && $offerId > 0) {
                $liveByOffer[$tenant . '|' . $offerId][] = $id;
            }
            if ($tenant === ProductMaster::SHARED && $crmId > 0) {
                $this->add('W2', "master row {$id} is shared (organisation 0) but holds crm_product_id {$crmId}", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
            }

            // ---- the catalogue pointer (I2, W1)
            $crmRow = null;
            if ($crmId > 0 && $crm !== null) {
                $crmRow = $crm[$crmId] ?? null;
                if ($crmRow === null) {
                    $this->add('I2', "master row {$id}: crm_product_id {$crmId} does not exist in crm_products", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                } elseif ($tenant !== ProductMaster::SHARED && (int) $crmRow->sub_institute_id !== $tenant) {
                    $this->add('I2', "master row {$id} (organisation {$tenant}): crm_product_id {$crmId} belongs to organisation {$crmRow->sub_institute_id}", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                    $crmRow = null;
                } elseif ($live && $crmRow->deleted_at !== null) {
                    $this->add('W1', "master row {$id} is live but its catalogue item {$crmId} is in the Recycle Bin", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                }
            }

            // ---- the Portfolio offer pointer (I2)
            $offerRow = null;
            if ($offerId > 0 && $offers !== null) {
                $offerRow = $offers[$offerId] ?? null;
                if ($offerRow === null) {
                    $this->add('I2', "master row {$id}: offer_row_id {$offerId} does not exist in g2g_product_offers", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                } else {
                    $offerTenant = $offerRow->sub_institute_id === null ? ProductMaster::SHARED : (int) $offerRow->sub_institute_id;
                    if ($offerTenant !== $tenant) {
                        $this->add('I2', "master row {$id} (organisation {$tenant}): offer_row_id {$offerId} belongs to organisation {$offerTenant}", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                        $offerRow = null;
                    }
                }
            }

            // ---- the parent (I2): 'none' (no parent), 'bad' (already reported) or 'ok'
            $parentRow = null;
            $parentState = 'none';
            if ($parentId > 0) {
                $parentState = 'bad';
                $parentRow = $parents[$parentId] ?? null;
                if ($parentRow === null) {
                    $this->add('I2', "master row {$id}: parent_id {$parentId} does not exist in g2g_product_master", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                } elseif ($parentRow->kind !== ProductMaster::KIND_PRODUCT) {
                    $this->add('I2', "master row {$id}: parent_id {$parentId} is a {$parentRow->kind}, not a product", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                    $parentRow = null;
                } elseif ((int) $parentRow->sub_institute_id !== $tenant && (int) $parentRow->sub_institute_id !== ProductMaster::SHARED) {
                    $this->add('I2', "master row {$id} (organisation {$tenant}): parent_id {$parentId} belongs to organisation {$parentRow->sub_institute_id}", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                    $parentRow = null;
                } else {
                    $parentState = 'ok';
                }
            }

            // ---- I5: the stores agree. Only what resolved is compared; what did not has been reported above.
            $name = trim((string) $m->name);
            $names = ['master' => $name];
            if ($offerRow !== null) {
                $names['Portfolio offer ' . $offerRow->id] = trim((string) $offerRow->name);
            }
            if ($crmRow !== null) {
                $names['catalogue item ' . $crmRow->id] = trim((string) $crmRow->name);
            }
            if (count(array_unique($names)) > 1) {
                $this->add('I5', "master row {$id}: the names disagree: " . $this->namesLine($names), ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
            }
            if ($offerRow !== null) {
                $group = mb_strtolower(trim((string) $offerRow->parent_product));
                if ($parentState === 'ok') {
                    $want = mb_strtolower(trim((string) $parentRow->portfolio_name));
                    if ($group !== $want) {
                        $this->add('I5', "master row {$id}: Portfolio offer {$offerRow->id} is under '" . $this->short((string) $offerRow->parent_product) . "' but its parent product {$parentId} is '" . $this->short((string) $parentRow->portfolio_name) . "'", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                    }
                } elseif ($parentState === 'none' && $group !== '') {
                    $this->add('I5', "master row {$id}: Portfolio offer {$offerRow->id} is under '" . $this->short((string) $offerRow->parent_product) . "' but the master row has no parent product", ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
                }
            }
            if ($crmRow !== null && $crmHasLine && (int) ($crmRow->product_master_id ?? 0) !== $parentId) {
                $this->add('I5', "master row {$id}: parent_id is {$parentId} but catalogue item {$crmRow->id} has product_master_id " . (int) ($crmRow->product_master_id ?? 0), ['table' => ProductPointers::MASTER, 'id' => $id, 'tenant' => $tenant]);
            }
        }

        // ---- I3: the unique keys of the table already forbid it where they exist; this is the proof where they do not.
        foreach (['crm_product_id' => $liveByCrm, 'offer_row_id' => $liveByOffer] as $column => $groups) {
            foreach ($groups as $key => $ids) {
                if (count($ids) > 1) {
                    [$tenant, $value] = array_map('intval', explode('|', (string) $key));
                    $this->add('I3', 'master rows ' . implode(', ', $ids) . " (organisation {$tenant}) are all live and all hold {$column} {$value}", ['table' => ProductPointers::MASTER, 'id' => min($ids), 'tenant' => $tenant]);
                }
            }
        }
    }

    /**
     * not_isolated: a master row of organisation 0 and a Portfolio offer with no organisation (S15 follow-up, D-1). Nobody can read them any more (the scopes return an
     * organisation's own rows only), so they are dead weight and a sign that `product-master:isolate-tenants` has not been run, or could not place them. They are nobody's,
     * so a run limited to one organisation does not look at them; a whole-database run and `--tenant=0` do.
     */
    private function checkIsolation(): void
    {
        if ($this->tenant !== null && $this->tenant !== 0) {
            return;
        }
        if ($this->hasTable(ProductPointers::MASTER)) {
            $rows = $this->db->table(ProductPointers::MASTER)->where('sub_institute_id', ProductMaster::SHARED);
            $n = (clone $rows)->count();
            if ($n > 0) {
                $this->counts['not_isolated'] += $n;
                foreach ((clone $rows)->orderBy('id')->limit(self::SAMPLE)->get(['id', 'kind', 'name']) as $row) {
                    $this->note('not_isolated', "master row {$row->id} ({$row->kind} '" . $this->short((string) $row->name) . "') belongs to organisation 0, so to no organisation", ['table' => ProductPointers::MASTER, 'id' => (int) $row->id, 'tenant' => 0]);
                }
            }
        }
        if ($this->hasTable(ProductPointers::OFFERS)) {
            $rows = $this->db->table(ProductPointers::OFFERS)->whereNull('sub_institute_id');
            $n = (clone $rows)->count();
            if ($n > 0) {
                $this->counts['not_isolated'] += $n;
                foreach ((clone $rows)->orderBy('id')->limit(self::SAMPLE)->get(['id', 'offer_id', 'name']) as $row) {
                    $this->note('not_isolated', "Portfolio offer {$row->id} ({$row->offer_id}, '" . $this->short((string) $row->name) . "') has no organisation", ['table' => ProductPointers::OFFERS, 'id' => (int) $row->id, 'tenant' => 0]);
                }
            }
        }
    }

    /** I4: a live catalogue item and a Portfolio offer each need a master row. */
    private function checkCoverage(): void
    {
        if (! $this->hasTable(ProductPointers::MASTER)) {
            return;
        }

        if ($this->hasTable(ProductPointers::CRM_PRODUCTS) && $this->hasColumn(ProductPointers::CRM_PRODUCTS, 'deleted_at')) {
            $live = $this->db->table(ProductPointers::CRM_PRODUCTS . ' as p')->whereNull('p.deleted_at')
                ->when($this->tenant !== null, fn ($q) => $q->where('p.sub_institute_id', $this->tenant));
            $this->checked['catalogueItems'] = (clone $live)->count();
            $items = (clone $live)->whereNotExists(function ($sub) {
                $sub->from(ProductPointers::MASTER . ' as m')->whereColumn('m.crm_product_id', 'p.id')->whereColumn('m.sub_institute_id', 'p.sub_institute_id');
            });
            $n = (clone $items)->count();
            if ($n > 0) {
                $this->counts['I4'] += $n;
                foreach ((clone $items)->orderBy('p.id')->limit(self::SAMPLE)->get(['p.id', 'p.sub_institute_id', 'p.name']) as $row) {
                    $this->note('I4', "catalogue item {$row->id} (organisation {$row->sub_institute_id}, '" . $this->short((string) $row->name) . "') has no master row", ['table' => ProductPointers::CRM_PRODUCTS, 'id' => (int) $row->id, 'tenant' => (int) $row->sub_institute_id]);
                }
            }
        } else {
            $this->skip('crm_products: the table (or its deleted_at) is not on this host, so I4 for catalogue items was skipped');
        }

        if ($this->hasTable(ProductPointers::OFFERS)) {
            $offers = $this->db->table(ProductPointers::OFFERS . ' as o')
                ->whereNotExists(function ($sub) {
                    $sub->from(ProductPointers::MASTER . ' as m')->whereColumn('m.offer_row_id', 'o.id')->whereRaw('m.sub_institute_id = COALESCE(o.sub_institute_id, 0)');
                });
            if ($this->tenant !== null) {
                $offers->whereRaw('COALESCE(o.sub_institute_id, 0) = ?', [$this->tenant]);
            }
            $this->checked['offers'] = $this->db->table(ProductPointers::OFFERS . ' as o')->when($this->tenant !== null, fn ($q) => $q->whereRaw('COALESCE(o.sub_institute_id, 0) = ?', [$this->tenant]))->count();
            $n = (clone $offers)->count();
            if ($n > 0) {
                $this->counts['I4'] += $n;
                foreach ((clone $offers)->orderBy('o.id')->limit(self::SAMPLE)->get(['o.id', 'o.sub_institute_id', 'o.offer_id', 'o.name']) as $row) {
                    $this->note('I4', "Portfolio offer {$row->id} ({$row->offer_id}, organisation " . ($row->sub_institute_id ?? 0) . ", '" . $this->short((string) $row->name) . "') has no master row", ['table' => ProductPointers::OFFERS, 'id' => (int) $row->id, 'tenant' => (int) ($row->sub_institute_id ?? 0)]);
                }
            }
        } else {
            $this->skip('g2g_product_offers: the table is not on this host, so I4 for Portfolio offers was skipped');
        }
    }

    // ------------------------------------------------------------------ census

    /**
     * The numbers a change must not move, per pointer table:
     *   rows        every row of the table (inside the organisation when one was asked for)
     *   sums        SUM of each measure column the table has (quantity, line_total, list_price), as a decimal string with two places
     *   pointers    per registered pointer column: `set` (rows holding a value) and, for the line tables, `by_product` (rows per product id)
     *
     * @return array{format: string, tenant: ?int, tables: array<string, array<string, mixed>>, skipped: list<string>}
     */
    public function census(): array
    {
        $this->skipped = [];
        $tables = [];
        foreach (ProductPointers::tables() as $table) {
            if (! $this->hasTable($table)) {
                $this->skip("{$table}: the table is not on this host");

                continue;
            }
            $entries = ProductPointers::forTable($table);
            [$base, $tenantExpr] = $this->referrers($entries[0]);
            if ($this->tenant !== null && $tenantExpr === null) {
                $this->skip("{$table}: cannot be limited to one organisation on this host");

                continue;
            }

            $out = ['rows' => (clone $base)->count(), 'sums' => [], 'pointers' => []];
            $measures = [];
            foreach ($entries as $e) {
                foreach ($e['measures'] as $measure) {
                    $measures[$measure] = true;
                }
            }
            foreach (array_keys($measures) as $measure) {
                if ($this->hasColumn($table, $measure)) {
                    $out['sums'][$measure] = number_format((float) (clone $base)->sum('r.' . $measure), 2, '.', '');
                } else {
                    $this->skip("{$table}.{$measure}: the column is not on this host, so its sum was skipped");
                }
            }
            foreach ($entries as $e) {
                if (! $this->hasColumn($table, $e['column'])) {
                    $this->skip("{$table}.{$e['column']}: the column is not on this host");

                    continue;
                }
                $col = 'r.' . $e['column'];
                $pointer = ['set' => (clone $base)->whereNotNull($col)->where($col, '>', 0)->count()];
                if ($e['lines']) {
                    $byProduct = [];
                    foreach ((clone $base)->whereNotNull($col)->where($col, '>', 0)->selectRaw("{$col} as pid, COUNT(*) as n")->groupBy($col)->orderBy($col)->get() as $row) {
                        $byProduct[(int) $row->pid] = (int) $row->n;
                    }
                    $pointer['by_product'] = $byProduct;
                }
                $out['pointers'][$e['column']] = $pointer;
            }
            $tables[$table] = $out;
        }

        return ['format' => self::FORMAT, 'tenant' => $this->tenant, 'tables' => $tables, 'skipped' => array_values(array_unique($this->skipped))];
    }

    /**
     * Every difference between two censuses, one line each, empty when they are the same. `$before` is usually read from a file, so values are compared as
     * numbers where they are numbers.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     * @return list<string>
     */
    public static function differences(array $before, array $now): array
    {
        $out = [];
        if (($before['format'] ?? null) !== self::FORMAT) {
            return ['the file is not a census written by this command (format ' . json_encode($before['format'] ?? null) . ", expected '" . self::FORMAT . "')"];
        }
        if (($before['tenant'] ?? null) !== ($now['tenant'] ?? null)) {
            $out[] = 'the two censuses cover different organisations (' . self::scopeWord($before['tenant'] ?? null) . ' and ' . self::scopeWord($now['tenant'] ?? null) . ')';
        }

        $a = (array) ($before['tables'] ?? []);
        $b = (array) ($now['tables'] ?? []);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $table) {
            if (! isset($b[$table])) {
                $out[] = "{$table}: in the snapshot, not on this host now";

                continue;
            }
            if (! isset($a[$table])) {
                $out[] = "{$table}: not in the snapshot, on this host now";

                continue;
            }
            if ((int) $a[$table]['rows'] !== (int) $b[$table]['rows']) {
                $out[] = "{$table}: rows {$a[$table]['rows']} -> {$b[$table]['rows']}";
            }
            $sumsA = (array) ($a[$table]['sums'] ?? []);
            $sumsB = (array) ($b[$table]['sums'] ?? []);
            foreach (array_unique(array_merge(array_keys($sumsA), array_keys($sumsB))) as $measure) {
                $x = $sumsA[$measure] ?? null;
                $y = $sumsB[$measure] ?? null;
                if ($x === null || $y === null || abs((float) $x - (float) $y) >= 0.005) {
                    $out[] = "{$table}: sum of {$measure} " . ($x ?? 'absent') . ' -> ' . ($y ?? 'absent');
                }
            }
            $pa = (array) ($a[$table]['pointers'] ?? []);
            $pb = (array) ($b[$table]['pointers'] ?? []);
            foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $column) {
                if (! isset($pa[$column]) || ! isset($pb[$column])) {
                    $out[] = "{$table}.{$column}: " . (isset($pa[$column]) ? 'in the snapshot, not on this host now' : 'not in the snapshot, on this host now');

                    continue;
                }
                if ((int) $pa[$column]['set'] !== (int) $pb[$column]['set']) {
                    $out[] = "{$table}.{$column}: rows with a product {$pa[$column]['set']} -> {$pb[$column]['set']}";
                }
                $byA = (array) ($pa[$column]['by_product'] ?? []);
                $byB = (array) ($pb[$column]['by_product'] ?? []);
                $products = array_unique(array_merge(array_keys($byA), array_keys($byB)));
                sort($products);
                foreach ($products as $product) {
                    if ((int) ($byA[$product] ?? 0) !== (int) ($byB[$product] ?? 0)) {
                        $out[] = "{$table}.{$column}: rows for product {$product} " . (int) ($byA[$product] ?? 0) . ' -> ' . (int) ($byB[$product] ?? 0);
                    }
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ plumbing

    private static function scopeWord(mixed $tenant): string
    {
        return $tenant === null ? 'every organisation' : 'organisation ' . (int) $tenant;
    }

    /**
     * The rows that carry a pointer, aliased `r`, limited to the organisation when one was asked for, and the SQL for the organisation each row belongs to
     * (null when this host cannot say: a column or a parent table is missing).
     *
     * @param  array<string, mixed>  $e
     * @return array{0: Builder, 1: ?string}
     */
    private function referrers(array $e): array
    {
        $query = $this->db->table($e['table'] . ' as r');
        $scope = $e['scope'];
        if (is_string($scope)) {
            $expr = $this->hasColumn($e['table'], $scope) ? 'r.' . $scope : null;
        } elseif ($this->hasTable($scope['parent']) && $this->hasColumn($e['table'], $scope['via']) && $this->hasColumn($scope['parent'], $scope['parent_tenant'])) {
            // LEFT join: a line whose document is gone still counts in a whole-database run (its organisation is then unknown).
            $query->leftJoin($scope['parent'] . ' as p', 'p.' . $scope['parent_key'], '=', 'r.' . $scope['via']);
            $expr = 'p.' . $scope['parent_tenant'];
        } else {
            $expr = null;
        }
        if ($this->tenant !== null && $expr !== null) {
            $query->where($expr, $this->tenant);
        }

        return [$query, $expr];
    }

    /**
     * Counts a query exactly, keeps the first SAMPLE rows as findings.
     *
     * @param  array<string, mixed>  $e
     * @param  list<string>  $extra  further select expressions the message needs
     * @param  \Closure(object): string  $message
     */
    private function flag(string $code, Builder $query, array $e, ?string $tenantExpr, array $extra, \Closure $message): void
    {
        $n = (clone $query)->count();
        if ($n === 0) {
            return;
        }
        $this->counts[$code] += $n;
        $select = array_merge(['r.id as rid', 'r.' . $e['column'] . ' as pid'], $tenantExpr !== null ? ["{$tenantExpr} as rt"] : [], $extra);
        foreach ((clone $query)->orderBy('r.id')->limit(self::SAMPLE)->selectRaw(implode(', ', $select))->get() as $row) {
            $this->note($code, $message($row), [
                'table' => $e['table'], 'column' => $e['column'], 'id' => (int) $row->rid, 'value' => (int) $row->pid,
                'tenant' => isset($row->rt) && $row->rt !== null ? (int) $row->rt : null,
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function add(string $code, string $message, array $context): void
    {
        $this->counts[$code]++;
        $this->note($code, $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function note(string $code, string $message, array $context): void
    {
        $this->stored[$code] = ($this->stored[$code] ?? 0) + 1;
        if ($this->stored[$code] > self::SAMPLE) {
            return;
        }
        $this->findings[] = ['code' => $code, 'severity' => self::CODES[$code][0], 'message' => $message] + $context;
    }

    private function skip(string $why): void
    {
        $this->skipped[] = $why;
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::connection($this->db->getName())->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table . '.' . $column] ??= $this->hasTable($table) && ColumnExists::has($table, $column, $this->db);
    }

    /**
     * The rows with these ids, keyed by id, or null when the table is not on this host.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $columns
     * @return ?array<int, object>
     */
    private function fetchByIds(string $table, array $ids, array $columns): ?array
    {
        if (! $this->hasTable($table)) {
            return null;
        }
        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->db->table($table)->whereIn('id', $chunk)->get($columns) as $row) {
                $rows[(int) $row->id] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<int>
     */
    private function idsOf(iterable $rows, string $column): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->{$column} !== null && (int) $row->{$column} > 0) {
                $ids[(int) $row->{$column}] = true;
            }
        }

        return array_keys($ids);
    }

    /** @param array<string, string> $names */
    private function namesLine(array $names): string
    {
        $parts = [];
        foreach ($names as $who => $name) {
            $parts[] = "{$who} '" . $this->short($name) . "'";
        }

        return implode(', ', $parts);
    }

    private function short(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40) . '...' : $text;
    }
}
