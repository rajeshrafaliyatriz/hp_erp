<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The system (non-custom) folders each module_scope ships with — "Task
 * Reports", "Events Report", "Assigned Tasks Report" for Task Management;
 * CRM's 13 real folders land here in Phase 3 (Docs/reports-engine/PLAN.md).
 *
 * LAZY PER-TENANT CREATION, NOT A MIGRATION BACKFILL
 *
 * `report_folders` is tenant-scoped, so "seed 3 rows" would only reach
 * tenants that already existed when the migration ran — every tenant
 * created afterward would be missing them, with no obvious reason why.
 * `ensure()` is called from ReportFolderController::index() instead: cheap
 * (one SELECT, an INSERT only for whatever is actually missing), and a
 * brand-new tenant gets its system folders the first time it ever asks,
 * automatically, without a second migration needing to remember to backfill.
 */
final class SystemReportFolders
{
    /** @var array<string, string[]> module_scope => ordered system folder names */
    private const FOLDERS = [
        'task_management' => ['Task Reports', 'Events Report', 'Assigned Tasks Report'],
    ];

    public static function namesFor(string $scope): array
    {
        return self::FOLDERS[$scope] ?? [];
    }

    public static function ensure(int $subInstituteId, string $scope): void
    {
        $names = self::namesFor($scope);

        if ($names === []) {
            return;
        }

        $existing = DB::table('report_folders')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->where('is_system', true)
            ->pluck('name')
            ->all();

        $now = now();
        $rows = [];

        foreach ($names as $i => $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $rows[] = [
                'sub_institute_id' => $subInstituteId,
                'module_scope' => $scope,
                'name' => $name,
                'is_system' => true,
                'owner_id' => null,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('report_folders')->insert($rows);
        }
    }
}
