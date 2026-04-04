<?php

namespace App\Http\Controllers\Api\Gemini;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SaveJDController extends Controller
{
    private array $columnCache = [];

    public function save(Request $request)
    {
        $payload = $this->normalizePayload($request);

        $validator = Validator::make(
            $payload,
            array_merge(
                [
                    'job_role_name' => 'required|string|max:255',
                    'department' => 'required|string|max:255',
                    'industry' => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'sub_department' => 'nullable|string|max:255',
                    'department_id' => 'nullable|integer',
                    'jobrole_category' => 'nullable|string|max:255',
                    'sub_institute_id' => 'required|integer',
                    'user_id' => 'nullable|integer',
                    'cwf_items' => 'nullable|array',
                    'cwf_items.*.critical_work_function' => 'required|string|max:255',
                    'cwf_items.*.tasks' => 'nullable|array',
                ],
                $this->collectionRules('skills'),
                $this->collectionRules('knowledge'),
                $this->collectionRules('ability'),
                $this->collectionRules('attitude'),
                $this->collectionRules('behavior')
            )
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($payload) {
                $now = now();
                $subInstituteId = (int) $payload['sub_institute_id'];
                $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
                $jobRoleName = trim((string) $payload['job_role_name']);
                $department = trim((string) $payload['department']);
                $subDepartment = $this->cleanString($payload['sub_department'] ?? $department);
                $industry = trim((string) $payload['industry']);
                $departmentId = $payload['department_id'] ?? $this->resolveDepartmentId($subInstituteId, $department, $subDepartment);

                $jobRoleResult = $this->storeRecord(
                    's_user_jobrole',
                    [
                        'sub_institute_id' => $subInstituteId,
                        'jobrole' => $jobRoleName,
                    ],
                    [
                        'industries' => $industry,
                        'department' => $department,
                        'sub_department' => $subDepartment,
                        'department_id' => $departmentId,
                        'jobrole' => $jobRoleName,
                        'description' => $payload['description'] ?? null,
                        'jobrole_category' => $payload['jobrole_category'] ?? 'AI Generated',
                        'status' => $payload['status'] ?? 'Active',
                    ],
                    $userId,
                    $now
                );

                $summary = [
                    'tasks' => $this->newSummary(),
                    'skills' => $this->newSummary(),
                    'knowledge' => $this->newSummary(),
                    'ability' => $this->newSummary(),
                    'attitude' => $this->newSummary(),
                    'behavior' => $this->newSummary(),
                ];

                foreach ($this->normalizeTasks($payload['cwf_items'] ?? [], $industry, $subDepartment) as $task) {
                    $summary['tasks']['total']++;

                    $taskResult = $this->storeRecord(
                        's_user_jobrole_task',
                        [
                            'sub_institute_id' => $subInstituteId,
                            'jobrole' => $jobRoleName,
                            'critical_work_function' => $task['critical_work_function'],
                            'task' => $task['task'],
                        ],
                        [
                            'sector' => $task['sector'],
                            'track' => $task['track'],
                            'jobrole' => $jobRoleName,
                            'critical_work_function' => $task['critical_work_function'],
                            'task' => $task['task'],
                            'task_type' => $task['task_type'],
                            'task_category' => $task['task_category'],
                        ],
                        $userId,
                        $now
                    );

                    $this->bumpSummary($summary['tasks'], $taskResult['action']);
                }

                foreach ($payload['skills'] ?? [] as $skill) {
                    $title = $this->cleanString($skill['title'] ?? null);
                    if ($title === null) {
                        continue;
                    }

                    $summary['skills']['total']++;

                    $skillResult = $this->storeRecord(
                        's_users_skills',
                        [
                            'sub_institute_id' => $subInstituteId,
                            'department' => $department,
                            'sub_department' => $subDepartment,
                            'category' => $this->cleanString($skill['category'] ?? null),
                            'sub_category' => $this->cleanString($skill['sub_category'] ?? null),
                            'title' => $title,
                        ],
                        [
                            'department' => $department,
                            'sub_department' => $subDepartment,
                            'department_id' => $departmentId,
                            'category' => $this->cleanString($skill['category'] ?? null),
                            'sub_category' => $this->cleanString($skill['sub_category'] ?? null),
                            'micro_category' => $this->cleanString($skill['micro_category'] ?? null),
                            'title' => $title,
                            'description' => $skill['description'] ?? null,
                            'proficiency_level' => isset($skill['proficiency_level']) ? (string) $skill['proficiency_level'] : null,
                            'skill_status' => $skill['skill_status'] ?? ($payload['skill_status'] ?? 'Active'),
                            'status' => $skill['status'] ?? 'Active',
                            'approve_status' => $skill['approve_status'] ?? ($payload['approve_status'] ?? 'Approved'),
                        ],
                        $userId,
                        $now
                    );

                    $this->bumpSummary($summary['skills'], $skillResult['action']);
                }

                $this->saveKabaItems('s_user_knowledge', $payload['knowledge'] ?? [], $subInstituteId, $userId, $now, $summary['knowledge']);
                $this->saveKabaItems('s_user_ability', $payload['ability'] ?? [], $subInstituteId, $userId, $now, $summary['ability']);
                $this->saveKabaItems('s_user_attitude', $payload['attitude'] ?? [], $subInstituteId, $userId, $now, $summary['attitude']);
                $this->saveKabaItems('s_user_behaviour', $payload['behavior'] ?? [], $subInstituteId, $userId, $now, $summary['behavior']);

                return [
                    'jobrole' => $jobRoleResult,
                    'summary' => $summary,
                    'department_id' => $departmentId,
                    'sub_department' => $subDepartment,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'JD data saved successfully.',
                'data' => [
                    'jobrole_id' => $result['jobrole']['id'],
                    'jobrole_action' => $result['jobrole']['action'],
                    'department_id' => $result['department_id'],
                    'sub_department' => $result['sub_department'],
                    'summary' => $result['summary'],
                ],
            ], $result['jobrole']['action'] === 'created' ? 201 : 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save JD data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function normalizePayload(Request $request): array
    {
        $payload = $request->all();
        $payload['job_role_name'] = $payload['job_role_name'] ?? $payload['jobrole'] ?? null;
        $payload['industry'] = $payload['industry'] ?? $payload['industries'] ?? null;
        $payload['sub_institute_id'] = $payload['sub_institute_id']
            ?? $request->header('sub_institute_id')
            ?? $request->session()->get('sub_institute_id');
        $payload['user_id'] = $payload['user_id'] ?? auth()->id() ?? $request->session()->get('user_id');
        $payload['sub_department'] = $payload['sub_department'] ?? $payload['department'] ?? null;
        $payload['behavior'] = $payload['behavior'] ?? $payload['behaviour'] ?? [];

        return $payload;
    }

    private function collectionRules(string $key): array
    {
        return [
            $key => 'nullable|array',
            "{$key}.*.title" => 'required|string|max:255',
            "{$key}.*.category" => 'nullable|string|max:255',
            "{$key}.*.sub_category" => 'nullable|string|max:255',
            "{$key}.*.description" => 'nullable|string',
            "{$key}.*.proficiency_level" => 'nullable|numeric',
        ];
    }

    private function normalizeTasks(array $cwfItems, string $industry, string $subDepartment): array
    {
        $tasks = [];

        foreach ($cwfItems as $cwfItem) {
            $criticalWorkFunction = $this->cleanString($cwfItem['critical_work_function'] ?? null);
            if ($criticalWorkFunction === null) {
                continue;
            }

            foreach ($cwfItem['tasks'] ?? [] as $taskItem) {
                $taskName = null;
                $taskType = $cwfItem['task_type'] ?? 'Core';
                $taskCategory = $cwfItem['task_category'] ?? $criticalWorkFunction;
                $sector = $cwfItem['sector'] ?? $industry;
                $track = $cwfItem['track'] ?? $subDepartment;

                if (is_string($taskItem)) {
                    $taskName = $this->cleanString($taskItem);
                } elseif (is_array($taskItem)) {
                    $taskName = $this->cleanString($taskItem['task'] ?? $taskItem['title'] ?? null);
                    $taskType = $taskItem['task_type'] ?? $taskType;
                    $taskCategory = $taskItem['task_category'] ?? $taskCategory;
                    $sector = $taskItem['sector'] ?? $sector;
                    $track = $taskItem['track'] ?? $track;
                }

                if ($taskName === null) {
                    continue;
                }

                $tasks[] = [
                    'sector' => $sector,
                    'track' => $track,
                    'critical_work_function' => $criticalWorkFunction,
                    'task' => $taskName,
                    'task_type' => $taskType,
                    'task_category' => $taskCategory,
                ];
            }
        }

        return $tasks;
    }

    private function saveKabaItems(
        string $table,
        array $items,
        int $subInstituteId,
        ?int $userId,
        $now,
        array &$summary
    ): void {
        foreach ($items as $item) {
            $title = $this->cleanString($item['title'] ?? null);
            if ($title === null) {
                continue;
            }

            $summary['total']++;

            $result = $this->storeRecord(
                $table,
                [
                    'sub_institute_id' => $subInstituteId,
                    'category' => $this->cleanString($item['category'] ?? null),
                    'sub_category' => $this->cleanString($item['sub_category'] ?? null),
                    'title' => $title,
                ],
                [
                    'category' => $this->cleanString($item['category'] ?? null),
                    'sub_category' => $this->cleanString($item['sub_category'] ?? null),
                    'title' => $title,
                    'description' => $item['description'] ?? null,
                    'business_link' => $this->cleanString($item['business_link'] ?? null),
                    'assessment_method' => $this->cleanString($item['assessment_method'] ?? null),
                ],
                $userId,
                $now
            );

            $this->bumpSummary($summary, $result['action']);
        }
    }

    private function storeRecord(string $table, array $lookup, array $values, ?int $userId, $now): array
    {
        $lookup = $this->filterForTable($table, $lookup);
        $values = $this->filterForTable($table, $values);
        $query = DB::table($table);

        if (in_array('deleted_at', $this->getTableColumns($table), true)) {
            $query->whereNull('deleted_at');
        }

        $this->applyLookup($query, $lookup);
        $existing = $query->first();

        if ($existing) {
            $updateData = $this->filterForTable($table, array_merge(
                $values,
                [
                    'updated_by' => $userId,
                    'updated_at' => $now,
                ]
            ));

            if (!empty($updateData)) {
                DB::table($table)->where('id', $existing->id)->update($updateData);
            }

            return [
                'id' => $existing->id,
                'action' => 'updated',
            ];
        }

        $insertData = $this->filterForTable($table, array_merge(
            $lookup,
            $values,
            [
                'created_by' => $userId,
                'created_at' => $now,
            ]
        ));

        $id = DB::table($table)->insertGetId($insertData);

        return [
            'id' => $id,
            'action' => 'created',
        ];
    }

    private function resolveDepartmentId(int $subInstituteId, string $department, ?string $subDepartment): ?int
    {
        $searchTerms = array_values(array_unique(array_filter([
            $subDepartment,
            $department,
        ])));

        if (empty($searchTerms) || !Schema::hasTable('hrms_departments')) {
            return null;
        }

        $departmentRow = DB::table('hrms_departments')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('department', $searchTerms)
            ->orderByRaw(
                'CASE WHEN department = ? THEN 0 WHEN department = ? THEN 1 ELSE 2 END',
                [$subDepartment ?? '', $department]
            )
            ->orderByRaw('CASE WHEN parent_id != 0 THEN 0 ELSE 1 END')
            ->first();

        return $departmentRow->id ?? null;
    }

    private function applyLookup(Builder $query, array $lookup): void
    {
        foreach ($lookup as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
                continue;
            }

            $query->where($column, $value);
        }
    }

    private function filterForTable(string $table, array $data): array
    {
        $allowedColumns = array_flip($this->getTableColumns($table));

        return array_intersect_key($data, $allowedColumns);
    }

    private function getTableColumns(string $table): array
    {
        if (!array_key_exists($table, $this->columnCache)) {
            $this->columnCache[$table] = Schema::hasTable($table)
                ? Schema::getColumnListing($table)
                : [];
        }

        return $this->columnCache[$table];
    }

    private function cleanString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function newSummary(): array
    {
        return [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
        ];
    }

    private function bumpSummary(array &$summary, string $action): void
    {
        if (!isset($summary[$action])) {
            $summary[$action] = 0;
        }

        $summary[$action]++;
    }
}
