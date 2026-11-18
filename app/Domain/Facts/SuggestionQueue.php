<?php

namespace App\Domain\Facts;

use Illuminate\Support\Facades\DB;

/**
 * What a person is shown (slice 4): the tenant's queue of suggestions, and the facts of one record. Read only.
 *
 * Every query carries the tenant. The queue lists only facts whose record still exists for that tenant (a suggestion about a removed record is noise), and
 * only the kinds of record the caller may view (the controller passes them). Newest first.
 */
final class SuggestionQueue
{
    public const STATUSES = ['proposed', 'applied', 'dismissed', 'superseded'];

    /**
     * @param  list<string>  $types  the kinds of record the caller may view
     * @return array{rows: list<object>, total: int}
     */
    public function page(int $tenantId, array $types, string $status, int $page, int $perPage): array
    {
        $types = array_values(array_intersect(FactEntities::TYPES, $types));
        if ($types === []) {
            return ['rows' => [], 'total' => 0];
        }

        $query = DB::table('g2g_entity_facts as f')->where('f.sub_institute_id', $tenantId)->where('f.status', $status)
            ->where(function ($any) use ($types) {
                foreach ($types as $type) {
                    $table = FactEntities::table($type);
                    $any->orWhere(fn ($one) => $one->where('f.entity_type', $type)->whereExists(
                        fn ($exists) => $exists->select(DB::raw('1'))->from("{$table} as e")->whereColumn('e.id', 'f.entity_id')->whereColumn('e.sub_institute_id', 'f.sub_institute_id')->whereNull('e.deleted_at')
                    ));
                }
            });

        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('f.created_at')->orderByDesc('f.id')->forPage(max(1, $page), max(1, $perPage))->get(['f.*'])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The facts of one record, waiting ones first, then accepted, dismissed and replaced, newest first within each.
     *
     * @return list<object>
     */
    public function forEntity(int $tenantId, string $type, int $id): array
    {
        return DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', $type)->where('entity_id', $id)
            ->orderByRaw("CASE status WHEN 'proposed' THEN 0 WHEN 'applied' THEN 1 WHEN 'dismissed' THEN 2 ELSE 3 END")->orderByDesc('created_at')->orderByDesc('id')
            ->limit((int) config('facts.entity_limit', 200))->get()->all();
    }

    /** @return array{proposed: int, applied: int, dismissed: int} */
    public function counts(int $tenantId, string $type, int $id): array
    {
        $by = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', $type)->where('entity_id', $id)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return ['proposed' => (int) ($by['proposed'] ?? 0), 'applied' => (int) ($by['applied'] ?? 0), 'dismissed' => (int) ($by['dismissed'] ?? 0)];
    }
}
