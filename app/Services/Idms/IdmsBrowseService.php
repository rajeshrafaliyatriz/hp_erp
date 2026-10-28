<?php

namespace App\Services\Idms;

use App\Models\HrmsDepartment;
use App\Models\Idms\DocumentMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Virtual folder tree (Department > Type > Year) and tag cloud, always under
 * visibleTo. Cached briefly per user; the cache is dropped for the whole tenant
 * whenever anything that changes what is published is written (see forget()),
 * so a freshly published document is never hidden behind a stale, empty tree.
 */
class IdmsBrowseService
{
    private function key(string $kind, int $subInstituteId, $user): string
    {
        $version = (int) Cache::get("idms_nav_version_{$subInstituteId}", 1);

        return "idms_{$kind}_{$subInstituteId}_v{$version}_{$user->id}";
    }

    /** Invalidate every user's tree and tag cloud for one tenant. */
    public static function forget(int $subInstituteId): void
    {
        $key = "idms_nav_version_{$subInstituteId}";
        Cache::forever($key, (int) Cache::get($key, 1) + 1);
    }

    public function getTree($user, int $subInstituteId): array
    {
        return Cache::remember($this->key('tree', $subInstituteId, $user), 60, function () use ($user, $subInstituteId) {
            $records = DocumentMaster::query()
                ->visibleTo($user, $subInstituteId)
                ->where('processing_status', 'done')
                ->select([
                    'department_id',
                    DB::raw("COALESCE(document_type, 'Unclassified') as doc_type"),
                    DB::raw("COALESCE(academic_year, 'General') as acad_year"),
                    DB::raw('COUNT(*) as total_count'),
                ])
                ->groupBy('department_id', 'doc_type', 'acad_year')
                ->get();

            $departments = HrmsDepartment::where('status', 1)->pluck('department', 'id')->toArray();

            $tree = [];
            foreach ($records as $row) {
                $deptId = (int) ($row->department_id ?: 0);
                $tree[$deptId] ??= [
                    'id' => $deptId,
                    'name' => $departments[$deptId] ?? 'General / Common',
                    'count' => 0,
                    'types' => [],
                ];
                $tree[$deptId]['count'] += (int) $row->total_count;

                $tree[$deptId]['types'][$row->doc_type] ??= ['name' => $row->doc_type, 'count' => 0, 'years' => []];
                $tree[$deptId]['types'][$row->doc_type]['count'] += (int) $row->total_count;
                $tree[$deptId]['types'][$row->doc_type]['years'][] = [
                    'year' => $row->acad_year,
                    'count' => (int) $row->total_count,
                ];
            }

            return array_values(array_map(function ($dept) {
                $dept['types'] = array_values($dept['types']);

                return $dept;
            }, $tree));
        });
    }

    public function getTagCloud($user, int $subInstituteId): array
    {
        return Cache::remember($this->key('tags', $subInstituteId, $user), 60, function () use ($user, $subInstituteId) {
            $counts = [];
            $lists = DocumentMaster::query()
                ->visibleTo($user, $subInstituteId)
                ->where('processing_status', 'done')
                ->whereNotNull('tag_names')
                ->pluck('tag_names');

            foreach ($lists as $names) {
                foreach ((array) $names as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                    }
                }
            }

            arsort($counts);
            $out = [];
            foreach (array_slice($counts, 0, 50, true) as $name => $count) {
                $out[] = ['name' => (string) $name, 'count' => $count];
            }

            return $out;
        });
    }
}
