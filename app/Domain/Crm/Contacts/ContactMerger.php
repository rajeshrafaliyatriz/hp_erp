<?php

namespace App\Domain\Crm\Contacts;

use App\Support\ColumnExists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every reference to a removed CRM contact onto the one that survives a merge (S18). It walks ContactReferences, the single reviewed list of every table and column that stores a
 * contact id, so nothing is repointed by memory. It is the contact twin of App\Domain\OrgAccounts\OrganisationMerger and obeys the same rules:
 *
 * It runs inside the caller's transaction (HasCrmMerge::mergeRows opens it) and every UPDATE is scoped to the caller's tenant: a table with sub_institute_id is only touched where that
 * equals the tenant, and crm_campaign_targets and the two junctions, which have none, are scoped through their parent row. Tables or columns missing on this host (the live MariaDB 10.1
 * lacks some) are skipped, not an error.
 *
 * A row that would collide with a unique key once it points at the survivor (the survivor is already in that campaign, already on that opportunity, already linked to that e-mail, ...) is
 * deleted instead of moved, and counted. An edge that linked the two merged contacts is deleted too: moved, it would relate the survivor to itself. Calling it again for the same pair
 * changes nothing and reports zeros.
 *
 * Facts and consent are NOT done here: the contact merge calls FactRepoint and ConsentRecorder first (they move a fact with its evidence and let a dismissal outrank a waiting row), and the
 * registry then finds nothing left to move for those two tables.
 */
final class ContactMerger
{
    /** @var array<string, bool> table => exists on this host; per call, because the schema can differ between calls (and between tests). */
    private array $present = [];

    /** @var array<string, list<string>> table => its columns (lower case), read once per table per call */
    private array $columns = [];

    /** @var array<string, int> */
    private array $moved = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /** @var array<string, int> */
    private array $cleared = [];

    private function __construct(private readonly int $tenantId, private readonly int $fromId, private readonly int $toId) {}

    /**
     * @return array{moved: array<string, int>, dropped: array<string, int>, cleared: array<string, int>} counts keyed "table.column" of the reference that was changed: rows pointed at the
     *                                                                                                       survivor, duplicate rows deleted, and pointers set to NULL (the survivor's own reports-to link)
     */
    public static function repoint(int $tenantId, int $fromId, int $toId): array
    {
        if ($fromId === $toId) {
            return ['moved' => [], 'dropped' => [], 'cleared' => []];
        }

        return (new self($tenantId, $fromId, $toId))->run();
    }

    /** @return array{moved: array<string, int>, dropped: array<string, int>, cleared: array<string, int>} */
    private function run(): array
    {
        foreach (ContactReferences::direct() as $reference) {
            $this->direct($reference);
        }
        foreach (ContactReferences::junctions() as $reference) {
            $this->junction($reference);
        }
        foreach (ContactReferences::polymorphic() as $reference) {
            $this->polymorphic($reference);
        }

        return ['moved' => $this->moved, 'dropped' => $this->dropped, 'cleared' => $this->cleared];
    }

    /** @param array{table: string, column: string, scope: string, self_reference?: bool} $reference */
    private function direct(array $reference): void
    {
        ['table' => $table, 'column' => $column, 'scope' => $scope] = $reference;
        if (! $this->has($table, [$column, $scope])) {
            return;
        }

        if ($reference['self_reference'] ?? false) {
            // The survivor may itself have reported to the removed contact: clear that first, or the move below would make it report to itself.
            $this->count($this->cleared, "{$table}.{$column}", $this->tenant($table, $scope)->where('id', $this->toId)->where($column, $this->fromId)->update([$column => null]));
        }

        $this->count($this->moved, "{$table}.{$column}", $this->tenant($table, $scope)->where($column, $this->fromId)->update([$column => $this->toId]));
    }

    /** @param array{table: string, column: string, parent: string, scope: array{via: string, parent: string, parent_key: string, parent_tenant: string}} $reference */
    private function junction(array $reference): void
    {
        ['table' => $table, 'column' => $column, 'parent' => $parent, 'scope' => $scope] = $reference;
        if (! $this->has($table, ['id', $column, $parent]) || ! $this->has($scope['parent'], [$scope['parent_key'], $scope['parent_tenant']])) {
            return;
        }
        $key = "{$table}.{$column}";

        // The survivor is already on these parents (opportunities, vendors): the removed contact's row for the same parent would break the unique key (parent, contact).
        $held = $this->tenantFor($reference)->where($column, $this->toId)->pluck($parent)->map(fn ($p) => (int) $p)->all();
        $drop = [];
        foreach ($this->tenantFor($reference)->where($column, $this->fromId)->orderBy('id')->get(['id', $parent]) as $row) {
            if (in_array((int) $row->{$parent}, $held, true)) {
                $drop[] = $row->id;
            }
            $held[] = (int) $row->{$parent};
        }
        $this->count($this->dropped, $key, $this->deleteIds($table, $drop));

        $this->count($this->moved, $key, $this->tenantFor($reference)->where($column, $this->fromId)->update([$column => $this->toId]));
    }

    /** @param array{table: string, id: string, type: string, values: list<string>, unique: list<string>, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}, pair?: array{id: string, type: string}} $reference */
    private function polymorphic(array $reference): void
    {
        ['table' => $table, 'id' => $idColumn, 'type' => $typeColumn, 'values' => $values, 'unique' => $unique] = $reference;
        $pair = $reference['pair'] ?? null;

        $needed = array_merge([$idColumn, $typeColumn, 'id'], $unique, $pair ? [$pair['id'], $pair['type']] : []);
        $scope = $reference['scope'];
        if (! $this->has($table, array_merge($needed, is_string($scope) ? [$scope] : [$scope['via']]))
            || (is_array($scope) && ! $this->has($scope['parent'], [$scope['parent_key'], $scope['parent_tenant']]))) {
            return;
        }
        $key = "{$table}.{$idColumn}";

        foreach ($values as $value) {
            if ($pair) {
                // An edge between the removed contact and the survivor would, once moved, relate the survivor to itself: delete it.
                $self = $this->tenantFor($reference)->where($typeColumn, $value)->whereIn($pair['type'], $values)->where(
                    fn ($q) => $q->where(fn ($a) => $a->where($idColumn, $this->fromId)->where($pair['id'], $this->toId))
                        ->orWhere(fn ($b) => $b->where($idColumn, $this->toId)->where($pair['id'], $this->fromId))
                );
                $this->count($this->dropped, $key, $this->deleteIds($table, $self->pluck('id')->all()));
            }

            if ($unique !== []) {
                $this->count($this->dropped, $key, $this->dropCollisions($reference, $value));
            }

            $this->count($this->moved, $key, $this->tenantFor($reference)->where($typeColumn, $value)->where($idColumn, $this->fromId)->update([$idColumn => $this->toId]));
        }
    }

    /**
     * Deletes the removed contact's rows that carry the same values in the rest of the unique key as a row the survivor already has (or as an earlier one of its own), so the move that
     * follows cannot hit the key. Compared case-insensitively, as the database collation does.
     *
     * @param array{table: string, id: string, type: string, unique: list<string>, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}} $reference
     */
    private function dropCollisions(array $reference, string $value): int
    {
        ['table' => $table, 'id' => $idColumn, 'type' => $typeColumn, 'unique' => $unique] = $reference;
        $fingerprint = fn (object $row): string => strtolower(implode("\x1f", array_map(fn ($c) => (string) $row->{$c}, $unique)));

        $seen = [];
        foreach ($this->tenantFor($reference)->where($typeColumn, $value)->where($idColumn, $this->toId)->get($unique) as $row) {
            $seen[$fingerprint($row)] = true;
        }

        $drop = [];
        foreach ($this->tenantFor($reference)->where($typeColumn, $value)->where($idColumn, $this->fromId)->orderBy('id')->get(array_merge(['id'], $unique)) as $row) {
            $print = $fingerprint($row);
            if (isset($seen[$print])) {
                $drop[] = $row->id;
            }
            $seen[$print] = true;
        }

        return $this->deleteIds($table, $drop);
    }

    /** A query on a table that has its own tenant column, limited to the caller's tenant. */
    private function tenant(string $table, string $scope): Builder
    {
        return DB::table($table)->where($scope, $this->tenantId);
    }

    /** @param array{table: string, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}} $reference */
    private function tenantFor(array $reference): Builder
    {
        $scope = $reference['scope'];
        if (is_string($scope)) {
            return $this->tenant($reference['table'], $scope);
        }

        // No tenant column on the child: it belongs to the tenant of its parent row.
        return DB::table($reference['table'])->whereIn($scope['via'], fn ($q) => $q->select($scope['parent_key'])->from($scope['parent'])->where($scope['parent_tenant'], $this->tenantId));
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
     * The table exists on this host and has every one of the columns (the live MariaDB 10.1 lacks some tables and columns). One SHOW COLUMNS per table, not one per column: the dev database
     * is remote (about 150 ms a query) and a merge asks about two dozen tables.
     *
     * @param list<string> $columns
     */
    private function has(string $table, array $columns): bool
    {
        $this->present[$table] ??= Schema::hasTable($table);
        if (! $this->present[$table]) {
            return false;
        }
        $this->columns[$table] ??= array_map('strtolower', ColumnExists::all($table));
        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $this->columns[$table], true)) {
                return false;
            }
        }

        return true;
    }
}
