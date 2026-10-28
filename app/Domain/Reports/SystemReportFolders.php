<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The system (non-custom) folders each module_scope ships with — "Task
 * Reports", "Events Report", "Assigned Tasks Report" for Task Management;
 * CRM's 12 real folders (Docs/reports-engine/PLAN.md, Phase 3) below.
 *
 * "All Reports" and "Shared With Me" are never rows here — computed views,
 * see ReportFolderController's own docblock. "Triz Reports" is also
 * deliberately absent: confirmed against the real legacy CRM source that it
 * is not a stock vtiger concept at all, just an ordinary custom folder this
 * client happened to create — a user makes one via the existing "New
 * folder" button exactly like any other custom folder, no seeding needed.
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
        'crm' => [
            'Organization and Contact Reports',
            'Lead Reports',
            'Opportunity Reports',
            'Activity Reports',
            'Campaign Reports',
            'Quote Reports',
            'Sales Order Reports',
            'Purchase Order Reports',
            'Invoice Reports',
            'Tickets Reports',
            'Product Reports',
            'Email Reports',
        ],
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
