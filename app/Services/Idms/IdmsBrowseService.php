<?php

namespace App\Services\Idms;

use App\Models\HrmsDepartment;
use App\Models\Idms\DocumentMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Virtual folder tree (Department > Type > Year) and tag cloud, always under visibleTo. Cached briefly per user. */
class IdmsBrowseService
{
    public function getTree($user, int $subInstituteId): array
    {
        return Cache::remember("idms_tree_{$subInstituteId}_{$user->id}", 60, function () use ($user, $subInstituteId) {
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
        return Cache::remember("idms_tags_{$subInstituteId}_{$user->id}", 60, function () use ($user, $subInstituteId) {
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
