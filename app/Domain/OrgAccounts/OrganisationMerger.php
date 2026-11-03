<?php

namespace App\Domain\OrgAccounts;

use App\Domain\AgentGateway\AgentSubjects;
use App\Support\ColumnExists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every reference to a removed CRM organisation onto the one that survives a merge (slice 3, decision D6). It walks OrganisationReferences, the single
 * reviewed list of every table and column that stores an organisation id, so nothing is repointed by memory.
 *
 * It runs inside the caller's transaction (HasCrmMerge::mergeRows opens it) and every UPDATE is scoped to the caller's tenant: a table with sub_institute_id is
 * only touched where that equals the tenant, and crm_campaign_targets, which has none, is scoped through its campaign. Tables or columns missing on this host (the
 * live MariaDB 10.1 lacks some) are skipped, not an error.
 *
 * A row that would collide with a unique key once it points at the survivor (the survivor is already in that campaign, already linked to that e-mail, ...) is
 * deleted instead of moved, and counted. An edge that linked the two merged organisations is deleted too: moved, it would relate the survivor to itself.
 * Calling it again for the same pair changes nothing and reports zeros.
 */
final class OrganisationMerger
{
    /** @var array<string, bool> "table" or "table.column" => exists on this host; per call, because the schema can differ between calls (and between tests). */
    private array $present = [];

    /** @var array<string, int> */
    private array $moved = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /** @var array<string, int> */
    private array $cleared = [];

    private function __construct(private readonly int $tenantId, private readonly int $fromId, private readonly int $toId) {}

    /**
     * @return array{moved: array<string, int>, dropped: array<string, int>, cleared: array<string, int>} counts keyed "table.column" of the reference that was changed:
     *                                                                                                       rows pointed at the survivor, duplicate rows deleted, and pointers set to NULL (the survivor's own parent link, a superseded identity)
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
        foreach (OrganisationReferences::direct() as $reference) {
            $this->direct($reference);
        }
        foreach (OrganisationReferences::polymorphic() as $reference) {
            $this->polymorphic($reference);
        }
        $this->identity();

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
            // The survivor may itself have been a child of the removed organisation: clear that first, or the move below would parent it to itself.
            $this->count($this->cleared, "{$table}.{$column}", $this->tenant($table, $scope)->where('id', $this->toId)->where($column, $this->fromId)->update([$column => null]));
        }

        $this->count($this->moved, "{$table}.{$column}", $this->tenant($table, $scope)->where($column, $this->fromId)->update([$column => $this->toId]));
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
                // An edge between the removed organisation and the survivor would, once moved, relate the survivor to itself: delete it.
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
     * Deletes the removed organisation's rows that carry the same values in the rest of the unique key as a row the survivor already has (or as an earlier one of
     * its own), so the move that follows cannot hit the key. Compared case-insensitively, as the database collation does.
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

    /**
     * The identity row that points at the removed organisation. Its (tenant, crm_organization_id) is unique, so when the survivor has an identity too the two
     * cannot both end up pointing at it: the live older one is kept and takes over the other's GTM account and Signals company pointers where it has none; the other
     * has its pointers cleared and is soft-deleted once nothing points anywhere from it (if it still carries a pointer the keeper could not take, it stays, unlinked
     * from the CRM organisation, so no link is lost). Soft-deleted identities are read too: the unique key counts them.
     */
    private function identity(): void
    {
        ['table' => $table, 'column' => $column, 'scope' => $scope] = OrganisationReferences::identity();
        if (! $this->has($table, ['id', $column, $scope])) {
            return;
        }
        $key = "{$table}.{$column}";
        $hasDeletedAt = $this->has($table, ['deleted_at']);
        $stamp = $this->has($table, ['updated_at']) ? ['updated_at' => now()] : [];

        $rows = $this->tenant($table, $scope)->whereIn($column, [$this->fromId, $this->toId])->orderBy('id')->get()->all();
        $loser = null;
        $winner = null;
        foreach ($rows as $row) {
            if ((int) $row->{$column} === $this->fromId) {
                $loser = $row;
            } elseif ((int) $row->{$column} === $this->toId) {
                $winner = $row;
            }
        }
        if ($loser === null) {
            return;
        }

        if ($winner === null) {
            $this->count($this->moved, $key, $this->tenant($table, $scope)->where('id', $loser->id)->update([$column => $this->toId] + $stamp));

            return;
        }

        // A live identity beats a soft-deleted one; between equals the older (lower id) wins.
        $isDeleted = fn (object $row): bool => $hasDeletedAt && $row->deleted_at !== null;
        $pair = [$loser, $winner];
        usort($pair, fn (object $a, object $b): int => [$isDeleted($a), (int) $a->id] <=> [$isDeleted($b), (int) $b->id]);
        [$keeper, $other] = $pair;

        // Pointers the other identity has and the keeper lacks move across; they are cleared on the other FIRST because each is unique per tenant.
        $carry = [];
        foreach (['gtm_account_id' => 'gtm_link_basis', 'g2g_company_id' => 'company_link_basis'] as $pointer => $basis) {
            if ($this->has($table, [$pointer]) && $other->{$pointer} !== null && $keeper->{$pointer} === null) {
                $carry[$pointer] = $other->{$pointer};
                if ($this->has($table, [$basis]) && $other->{$basis} !== null) {
                    $carry[$basis] = $other->{$basis};
                }
            }
        }
        $this->tenant($table, $scope)->where('id', $other->id)->update([$column => null] + array_fill_keys(array_keys($carry), null) + $stamp);
        $this->count($this->cleared, $key, 1);
        $this->tenant($table, $scope)->where('id', $keeper->id)->update([$column => $this->toId] + $carry + $stamp);
        $this->count($this->moved, $key, 1);

        $left = $this->tenant($table, $scope)->where('id', $other->id)->first();
        $stillLinked = false;
        foreach (['crm_organization_id', 'gtm_account_id', 'g2g_company_id'] as $pointer) {
            $stillLinked = $stillLinked || ($this->has($table, [$pointer]) && $left->{$pointer} !== null);
        }
        if ($hasDeletedAt && ! $stillLinked && $left->deleted_at === null) {
            $this->tenant($table, $scope)->where('id', $other->id)->update(['deleted_at' => now()]);
            // The agent's runs and proposals about the identity that has just been removed follow it to the keeper (slice 7; never throws, a no-op without the tables).
            AgentSubjects::repoint($this->tenantId, (int) $other->id, (int) $keeper->id);
        }
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

    /** The table exists on this host and has every one of the columns (the live MariaDB 10.1 lacks some tables and columns). @param list<string> $columns */
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
