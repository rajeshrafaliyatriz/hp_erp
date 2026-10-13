<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * A few real, ready-to-open starter reports per system folder — so a
 * freshly-registered module isn't just empty folders waiting for someone
 * to build something from scratch (the exact complaint this closes: "Task
 * Reports"/"Events Report"/"Assigned Tasks Report" had folders but nothing
 * in them).
 *
 * Same lazy, per-tenant creation shape as SystemReportFolders::ensure() and
 * for the same reason: a migration backfill only reaches tenants that
 * existed when it ran. Idempotent by (sub_institute_id, module_scope,
 * title) — re-running never duplicates.
 */
final class SystemReportSeeds
{
    public static function ensure(int $subInstituteId, int $userId, string $scope): void
    {
        $seeds = self::seedsFor($scope);

        if ($seeds === []) {
            return;
        }

        $folderIds = DB::table('report_folders')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->where('is_system', true)
            ->pluck('id', 'name');

        $existingTitles = DB::table('reports')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->whereNull('deleted_at')
            ->pluck('title')
            ->all();

        $now = now();
        $rows = [];

        foreach ($seeds as $seed) {
            if (in_array($seed['title'], $existingTitles, true) || !isset($folderIds[$seed['folder']])) {
                continue;
            }

            $rows[] = [
                'sub_institute_id' => $subInstituteId,
                'module_scope' => $scope,
                'folder_id' => $folderIds[$seed['folder']],
                'report_type' => $seed['report_type'],
                'primary_module' => $seed['primary_module'],
                'title' => $seed['title'],
                'description' => $seed['description'] ?? null,
                'owner_id' => $userId,
                'sharingtype' => 'Public',
                'definition' => json_encode($seed['definition']),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('reports')->insert($rows);
        }
    }

    /** @return array<int, array{folder:string,report_type:string,primary_module:string,title:string,description?:string,definition:array}> */
    private static function seedsFor(string $scope): array
    {
        if ($scope !== 'task_management') {
            return [];
        }

        $col = fn (string $field, string $label, ?string $aggregate = null) => [
            'module' => 'task_management.tasks', 'field' => $field, 'label' => $label, 'visible' => true, 'aggregate' => $aggregate,
        ];

        return [
            [
                'folder' => 'Task Reports',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.tasks',
                'title' => 'All Open Tasks',
                'description' => 'Every task not yet completed, newest first.',
                'definition' => [
                    'columns' => [
                        $col('task_title', 'Task'),
                        $col('status', 'Status'),
                        $col('task_type', 'Priority'),
                        $col('task_date', 'Due Date'),
                        $col('task_allocated_to', 'Assignee'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'task_management.tasks', 'field' => 'status', 'operator' => 'not_equals', 'value' => 'COMPLETED'],
                        ],
                    ],
                    'sort_columns' => [['module' => 'task_management.tasks', 'field' => 'task_date', 'direction' => 'asc']],
                ],
            ],
            [
                'folder' => 'Task Reports',
                'report_type' => 'summary',
                'primary_module' => 'task_management.tasks',
                'title' => 'Tasks by Status',
                'description' => 'How many tasks are in each status right now.',
                'definition' => [
                    'group_by' => [['module' => 'task_management.tasks', 'field' => 'status']],
                ],
            ],
            [
                'folder' => 'Events Report',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.events',
                'title' => "Today's Events",
                'description' => "Everything scheduled for today — the same shape as the legacy CRM's own \"Today calendar\" report.",
                'definition' => [
                    'columns' => [
                        ['module' => 'task_management.events', 'field' => 'title', 'label' => 'Event', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'status', 'label' => 'Status', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'owner_id', 'label' => 'Owner', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'start_at', 'label' => 'Start', 'visible' => true],
                    ],
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'today'],
                    'sort_columns' => [['module' => 'task_management.events', 'field' => 'start_at', 'direction' => 'asc']],
                ],
            ],
            [
                'folder' => 'Events Report',
                'report_type' => 'summary',
                'primary_module' => 'task_management.events',
                'title' => "This Month's Events by Status",
                'definition' => [
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'this_month'],
                    'group_by' => [['module' => 'task_management.events', 'field' => 'status']],
                ],
            ],
            [
                'folder' => 'Assigned Tasks Report',
                'report_type' => 'summary',
                'primary_module' => 'task_management.tasks',
                'title' => 'Tasks by Assignee',
                'description' => 'Who has how many tasks, right now.',
                'definition' => [
                    'group_by' => [['module' => 'task_management.tasks', 'field' => 'task_allocated_to']],
                ],
            ],
            [
                'folder' => 'Assigned Tasks Report',
                'report_type' => 'chart',
                'primary_module' => 'task_management.tasks',
                'title' => 'Workload by Assignee',
                'definition' => [
                    'chart' => [
                        'type' => 'HorizontalBar',
                        'group_by' => ['module' => 'task_management.tasks', 'field' => 'task_allocated_to'],
                        'data_fields' => [['module' => 'task_management.tasks', 'field' => 'task_title', 'aggregate' => 'count', 'label' => 'Tasks']],
                    ],
                ],
            ],
        ];
    }
}
