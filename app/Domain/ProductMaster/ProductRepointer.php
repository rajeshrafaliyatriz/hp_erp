<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every reference to a catalogue item that a merge removes onto the item that survives it (slice 15, P2-B2, defect D6;
 * Docs/agentic-crm/slices/S15-product-portfolio-merge.md). The merge route used to repoint two of the twelve columns that hold a crm_products id and leave ten of
 * them pointing at an item that was now in the Recycle Bin. This class walks ProductPointers::toCrmProduct(), the single reviewed list of those columns, so a
 * pointer added to the registry is moved without anybody remembering it: the same idea as App\Domain\OrgAccounts\OrganisationMerger over
 * OrganisationReferences. tests/Unit/ProductRepointerScanTest.php fails when a registered pointer has no decision, and when a migration puts a pointer into a
 * unique key that is not declared below.
 *
 * It runs inside the caller's transaction (HasCrmMerge::mergeRows opens it), so a failure on the last table takes back the first. Every UPDATE is scoped to the
 * caller's organisation: a table with its own sub_institute_id is touched only where that equals the tenant, and a line of a document (a quote line, an invoice
 * line, a price-book item, a bundle row) belongs to the organisation of its document, which is looked up, never assumed. A row of another organisation that
 * happens to carry the removed item's id (damage the checker reports as I1T) is left exactly as it is. A table or column the host does not have (the live
 * MariaDB 10.1 lacks some) is skipped, not an error.
 *
 * Only the pointer column changes: no other column, not even updated_at, is written.
 *
 * Unique keys. A plain UPDATE would break on a key, so the rows that would collide are removed first, and the SURVIVOR's row is the one that stays:
 *   - crm_price_book_items (price_book_id, product_id): a price book that already holds the survivor keeps the survivor's row (and its price); the removed item's
 *     row in that book is deleted. A book that holds only the removed item now holds the survivor, at the removed item's price.
 *   - crm_product_bundle_items (parent_product_id, component_product_id): the same rule for a bundle that holds both as components and for a part that both
 *     bundles hold; and a row that would make a bundle contain ITSELF once repointed (the survivor holding the removed item, the removed item holding the
 *     survivor) is deleted.
 *   - crm_opportunity_products has no unique key (its migration and the route that adds a product both allow the same product twice, each row its own quantity
 *     of interest), so its rows are only moved: an opportunity that listed both now lists the survivor twice, and no row is lost.
 * Every other unique key on these tables is on a number of the document (quote_no, so_no, ...), not on a pointer.
 *
 * It does not look at the Product Master: the removed item's master row follows through ProductMasterSync::throughCatalogueItems, called by the controller after
 * the merge, as it always was.
 */
final class ProductRepointer
{
    /**
     * A pointer that sits in a UNIQUE key: "table.column" => the OTHER columns of that key. A row of the removed item that carries the same values in them as a row
     * of the survivor is deleted instead of moved. The migration scan (tests/Unit/ProductRepointerScanTest.php) fails when a migration has a unique key with a
     * registered pointer in it that is not listed here, and when something listed here has no key behind it.
     */
    private const UNIQUE = [
        'crm_price_book_items.product_id' => ['price_book_id'],
        'crm_product_bundle_items.parent_product_id' => ['component_product_id'],
        'crm_product_bundle_items.component_product_id' => ['parent_product_id'],
    ];

    /**
     * A table whose unique key holds TWO pointers at catalogue items: table => those two columns. Once both are repointed a row that named the removed item and the
     * survivor would name the survivor twice, so it is deleted before anything moves.
     */
    private const SELF_PAIRS = [
        'crm_product_bundle_items' => ['parent_product_id', 'component_product_id'],
    ];

    /**
     * A registered pointer the merge leaves alone on purpose: "table.column" => the reason. Empty: all twelve move. An entry here is a decision a reviewer can
     * disagree with, so the scan test wants a sentence of at least 30 characters.
     */
    private const LEFT_ALONE = [];

    /** @var array<string, bool> "table" or "table.column" => exists on this host; per call, because the schema can differ between calls (and between tests) */
    private array $present = [];

    /** @var array<string, int> */
    private array $moved = [];

    /** @var array<string, int> */
    private array $dropped = [];

    private function __construct(private readonly int $tenantId, private readonly int $fromId, private readonly int $toId) {}

    /**
     * @return array{moved: array<string, int>, dropped: array<string, int>} counts keyed "table.column": the rows pointed at the survivor, and the rows deleted
     *                                                                       because the survivor already had them (or because they would have contained themselves)
     */
    public static function repoint(int $tenantId, int $fromId, int $toId): array
    {
        if ($fromId === $toId || $fromId <= 0 || $toId <= 0) {
            return ['moved' => [], 'dropped' => []];
        }

        return (new self($tenantId, $fromId, $toId))->run();
    }

    /**
     * What the merge does with every registered pointer at a catalogue item, for the scan test and for a reader.
     *
     * @return array<string, array{moves: bool, unique: list<string>, reason: ?string}>
     */
    public static function handling(): array
    {
        $out = [];
        foreach (ProductPointers::toCrmProduct() as $e) {
            $key = $e['table'] . '.' . $e['column'];
            $reason = self::LEFT_ALONE[$key] ?? null;
            $out[$key] = ['moves' => $reason === null, 'unique' => self::UNIQUE[$key] ?? [], 'reason' => $reason];
        }

        return $out;
    }

    /** @return array<string, list<string>> "table.column" => the other columns of the unique key it sits in */
    public static function uniqueKeys(): array
    {
        return self::UNIQUE;
    }

    /** @return array<string, list<string>> table => the two pointer columns of one unique key */
    public static function selfPairs(): array
    {
        return self::SELF_PAIRS;
    }

    /** @return array<string, string> "table.column" => why the merge leaves it alone */
    public static function leftAlone(): array
    {
        return self::LEFT_ALONE;
    }

    /** @return array{moved: array<string, int>, dropped: array<string, int>} */
    private function run(): array
    {
        // A table whose unique key holds two pointers first loses the rows that would contain themselves once repointed, ONCE and before any column of it moves:
        // a row that names the removed item at both ends would otherwise be moved to (survivor, removed) by the first column and to (survivor, survivor) by the second.
        foreach (self::SELF_PAIRS as $table => $pair) {
            foreach (ProductPointers::toCrmProduct() as $entry) {
                if ($entry['table'] === $table && $entry['column'] === $pair[0] && $this->usable($entry)) {
                    $this->dropSelfContaining($entry, $pair);
                }
            }
        }

        foreach (ProductPointers::toCrmProduct() as $entry) {
            $table = $entry['table'];
            $column = $entry['column'];
            $key = $table . '.' . $column;
            if (isset(self::LEFT_ALONE[$key]) || ! $this->usable($entry)) {
                continue;
            }

            if (isset(self::UNIQUE[$key])) {
                $this->dropCollisions($entry, self::UNIQUE[$key]);
            }

            $this->count($this->moved, $key, $this->rows($entry)->where($column, $this->fromId)->update([$column => $this->toId]));
        }

        return ['moved' => $this->moved, 'dropped' => $this->dropped];
    }

    /**
     * The rows of this pointer's table that belong to the caller's organisation: its own sub_institute_id, or the organisation of the document (or parent
     * product) the row hangs from. This is what keeps another organisation's rows out of every statement below.
     *
     * @param array<string, mixed> $entry
     */
    private function rows(array $entry): Builder
    {
        $scope = $entry['scope'];
        if (is_string($scope)) {
            return DB::table($entry['table'])->where($scope, $this->tenantId);
        }

        return DB::table($entry['table'])->whereIn($scope['via'], fn ($q) => $q->select($scope['parent_key'])->from($scope['parent'])->where($scope['parent_tenant'], $this->tenantId));
    }

    /**
     * Deletes the removed item's rows that carry the same values in the rest of a unique key as a row the survivor already has, so the move that follows cannot
     * hit the key. The survivor's rows are looked up in the whole table: a unique key counts everybody's rows, not only this organisation's.
     *
     * @param array<string, mixed> $entry
     * @param list<string> $others the other columns of the key
     */
    private function dropCollisions(array $entry, array $others): void
    {
        $table = $entry['table'];
        $column = $entry['column'];

        $taken = [];
        foreach (DB::table($table)->where($column, $this->toId)->get($others) as $row) {
            $print = $this->print($row, $others);
            if ($print !== null) {
                $taken[$print] = true;
            }
        }

        $drop = [];
        foreach ($this->rows($entry)->where($column, $this->fromId)->orderBy('id')->get(array_merge(['id'], $others)) as $row) {
            $print = $this->print($row, $others);
            if ($print !== null && isset($taken[$print])) {
                $drop[] = $row->id;
            }
        }

        $this->count($this->dropped, $table . '.' . $column, $this->deleteIds($table, $drop));
    }

    /**
     * Deletes the rows that would contain themselves once repointed: one end names the removed item and the other the survivor (either way round), or both name
     * the removed item. After the move each of them would name the survivor twice.
     *
     * @param array<string, mixed> $entry
     * @param list<string> $pair the two pointer columns
     */
    private function dropSelfContaining(array $entry, array $pair): void
    {
        [$a, $b] = $pair;
        $ids = $this->rows($entry)
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where($a, $this->fromId)->where($b, $this->toId))
                ->orWhere(fn ($x) => $x->where($a, $this->toId)->where($b, $this->fromId))
                ->orWhere(fn ($x) => $x->where($a, $this->fromId)->where($b, $this->fromId)))
            ->pluck('id')->all();

        $this->count($this->dropped, "{$entry['table']}.{$a}+{$b} (it would contain itself)", $this->deleteIds($entry['table'], $ids));
    }

    /**
     * A row's values in the other columns of a key, as one string; null when one of them is NULL (a NULL never collides in a unique key).
     *
     * @param list<string> $columns
     */
    private function print(object $row, array $columns): ?string
    {
        $parts = [];
        foreach ($columns as $c) {
            if ($row->{$c} === null) {
                return null;
            }
            $parts[] = (string) $row->{$c};
        }

        return implode("\x1f", $parts);
    }

    /** @param list<int|string> $ids */
    private function deleteIds(string $table, array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }

    /** @param array<string, int> $into */
    private function count(array &$into, string $key, int $n): void
    {
        if ($n > 0) {
            $into[$key] = ($into[$key] ?? 0) + $n;
        }
    }

    /**
     * This host has what the statements need: the table, the pointer column, the id, the organisation column (or the document table with its key and organisation
     * column), and the other columns of the unique key. The live MariaDB 10.1 lacks some tables and columns.
     *
     * @param array<string, mixed> $entry
     */
    private function usable(array $entry): bool
    {
        $scope = $entry['scope'];
        $columns = [$entry['column'], 'id', ...(self::UNIQUE[$entry['table'] . '.' . $entry['column']] ?? [])];
        if (is_string($scope)) {
            $columns[] = $scope;
        } else {
            $columns[] = $scope['via'];
            if (! $this->has($scope['parent'], [$scope['parent_key'], $scope['parent_tenant']])) {
                return false;
            }
        }

        return $this->has($entry['table'], $columns);
    }

    /** The table exists on this host and has every one of the columns. @param list<string> $columns */
    private function has(string $table, array $columns): bool
    {
        $this->present[$table] ??= Schema::hasTable($table);
        if (! $this->present[$table]) {
            return false;
        }
        foreach ($columns as $column) {
            $this->present["{$table}.{$column}"] ??= ColumnExists::has($table, $column);
            if (! $this->present["{$table}.{$column}"]) {
                return false;
            }
        }

        return true;
    }
}
