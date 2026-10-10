<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared "group rows whose normalized key collides" detector, used once per
 * signal (e.g. Leads call it once for email, once for last_name+company).
 * Not shared with Campaigns - a campaign isn't a "duplicate record" the way
 * a lead/contact/organization is, so it has no duplicates()/merge() pair.
 */
trait HasCrmDuplicateDetection
{
    /**
     * @param array<string> $requiredColumns every column must be non-empty
     *   for a row to be considered - an empty company shouldn't group every
     *   lead with a blank company together as "duplicates".
     * @param (Closure(object): array<string, mixed>) $resource
     * @param (Closure(\Illuminate\Database\Query\Builder): void)|null $extraWhere
     * @return array<int, array{key: string, reason: string, rows: array<int, array<string, mixed>>}>
     */
    protected function duplicateGroups(
        string $table,
        int $tenantId,
        string $sqlExpr,
        array $requiredColumns,
        string $reason,
        Closure $resource,
        ?Closure $extraWhere = null,
    ): array {
        $query = DB::table($table)->where('sub_institute_id', $tenantId)->whereNull('deleted_at');

        foreach ($requiredColumns as $column) {
            $query->whereNotNull($column)->where($column, '!=', '');
        }

        if ($extraWhere) {
            $extraWhere($query);
        }

        $dupKeys = (clone $query)
            ->selectRaw("{$sqlExpr} as key_value")
            ->groupBy(DB::raw($sqlExpr))
            ->havingRaw('COUNT(*) > 1')
            ->pluck('key_value')
            ->map(fn ($value) => (string) $value)
            ->all();

        if ($dupKeys === []) {
            return [];
        }

        $rows = $query->selectRaw("{$table}.*, {$sqlExpr} as dup_key")->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row->dup_key;

            if (! in_array($key, $dupKeys, true)) {
                continue;
            }

            $groups[$key][] = $row;
        }

        $result = [];
        foreach ($groups as $key => $groupRows) {
            $result[] = [
                'key' => $key,
                'reason' => $reason,
                'rows' => array_map($resource, $groupRows),
            ];
        }

        return $result;
    }
}
