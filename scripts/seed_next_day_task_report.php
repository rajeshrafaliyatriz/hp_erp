<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Domain\Reports\SystemReportFolders;
use App\Domain\Reports\SystemReportSeeds;
use App\Domain\Reports\ReportQueryExecutionService;
use App\Domain\Reports\ReportModuleRegistry;

echo "Checking DB connections...\n";

// Determine which connection is db3
$connection = 'db3';
try {
    DB::connection($connection)->getPdo();
    echo "Connected successfully to [db3].\n";
} catch (\Throwable $e) {
    echo "Connection to [db3] failed: " . $e->getMessage() . "\n";
    echo "Falling back to default connection...\n";
    $connection = config('database.default');
}

echo "Using connection: $connection\n";

$subInstituteId = 6;

// Check user for tenant 6
$user = DB::connection($connection)->table('tbluser')
    ->where('sub_institute_id', $subInstituteId)
    ->where(function ($q) {
        $q->where('email', 'scholarclone@gmail.com')
          ->orWhere('user_name', 'like', '%scholarclone%');
    })
    ->first();

if (!$user) {
    echo "User scholarclone@gmail.com not found directly, looking for any admin in tenant 6...\n";
    $user = DB::connection($connection)->table('tbluser')
        ->where('sub_institute_id', $subInstituteId)
        ->orderBy('id')
        ->first();
}

$userId = $user ? $user->id : 1;
echo "Tenant: $subInstituteId, Admin User ID: $userId (" . ($user ? ($user->email ?? $user->user_name ?? 'user') : 'fallback') . ")\n";

// Ensure system folders exist on this connection
$scope = 'task_management';
$folderNames = ['Task Reports', 'Events Report', 'Assigned Tasks Report'];

foreach ($folderNames as $idx => $folderName) {
    $exists = DB::connection($connection)->table('report_folders')
        ->where('sub_institute_id', $subInstituteId)
        ->where('module_scope', $scope)
        ->where('name', $folderName)
        ->whereNull('deleted_at')
        ->first();

    if (!$exists) {
        DB::connection($connection)->table('report_folders')->insert([
            'sub_institute_id' => $subInstituteId,
            'module_scope' => $scope,
            'name' => $folderName,
            'is_system' => true,
            'owner_id' => null,
            'sort_order' => $idx + 1,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "Created folder: $folderName\n";
    } else {
        echo "Folder exists: $folderName (ID: {$exists->id})\n";
    }
}

$targetFolder = DB::connection($connection)->table('report_folders')
    ->where('sub_institute_id', $subInstituteId)
    ->where('module_scope', $scope)
    ->where('name', 'Assigned Tasks Report')
    ->whereNull('deleted_at')
    ->first();

$folderId = $targetFolder ? $targetFolder->id : null;
echo "Assigned Tasks Report folder ID: $folderId\n";

$reportTitle = 'Next Day Planned Tasks by Employee';
$reportDesc = 'All tasks planned/due for tomorrow across all employees, grouped by assignee.';

$definition = [
    'columns' => [
        ['module' => 'task_management.tasks', 'field' => 'task_allocated_to', 'label' => 'Employee', 'visible' => true],
        ['module' => 'task_management.tasks', 'field' => 'task_title', 'label' => 'Task Title', 'visible' => true],
        ['module' => 'task_management.tasks', 'field' => 'task_description', 'label' => 'Task Description', 'visible' => true],
        ['module' => 'task_management.tasks', 'field' => 'task_date', 'label' => 'Due Date', 'visible' => true],
        ['module' => 'task_management.tasks', 'field' => 'status', 'label' => 'Status', 'visible' => true],
    ],
    'standard_filter' => [
        'module' => 'task_management.tasks',
        'field' => 'task_date',
        'preset' => 'tomorrow',
    ],
    'sort_columns' => [
        ['module' => 'task_management.tasks', 'field' => 'task_allocated_to', 'direction' => 'asc'],
        ['module' => 'task_management.tasks', 'field' => 'task_date', 'direction' => 'asc'],
    ],
];

// Check if report exists
$existingReport = DB::connection($connection)->table('reports')
    ->where('sub_institute_id', $subInstituteId)
    ->where('module_scope', $scope)
    ->where('title', $reportTitle)
    ->whereNull('deleted_at')
    ->first();

if ($existingReport) {
    echo "Report already exists (ID: {$existingReport->id}). Updating definition...\n";
    DB::connection($connection)->table('reports')
        ->where('id', $existingReport->id)
        ->update([
            'folder_id' => $folderId,
            'report_type' => 'tabular',
            'primary_module' => 'task_management.tasks',
            'description' => $reportDesc,
            'sharingtype' => 'Public',
            'definition' => json_encode($definition),
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);
    $reportId = $existingReport->id;
} else {
    echo "Inserting report '$reportTitle'...\n";
    $reportId = DB::connection($connection)->table('reports')->insertGetId([
        'sub_institute_id' => $subInstituteId,
        'module_scope' => $scope,
        'folder_id' => $folderId,
        'report_type' => 'tabular',
        'primary_module' => 'task_management.tasks',
        'title' => $reportTitle,
        'description' => $reportDesc,
        'owner_id' => $userId,
        'sharingtype' => 'Public',
        'definition' => json_encode($definition),
        'created_by' => $userId,
        'updated_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "Inserted report with ID: $reportId\n";
}

// Also check and seed on default connection if different, so both live and dev have it
if ($connection !== config('database.default')) {
    echo "\nAlso verifying default connection...\n";
    $defConn = config('database.default');
    $defTargetFolder = DB::connection($defConn)->table('report_folders')
        ->where('sub_institute_id', $subInstituteId)
        ->where('module_scope', $scope)
        ->where('name', 'Assigned Tasks Report')
        ->whereNull('deleted_at')
        ->first();

    if ($defTargetFolder) {
        $defExisting = DB::connection($defConn)->table('reports')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->where('title', $reportTitle)
            ->whereNull('deleted_at')
            ->first();

        if ($defExisting) {
            DB::connection($defConn)->table('reports')->where('id', $defExisting->id)->update([
                'folder_id' => $defTargetFolder->id,
                'definition' => json_encode($definition),
                'updated_at' => now(),
            ]);
            echo "Updated default connection report (ID: {$defExisting->id})\n";
        } else {
            $newId = DB::connection($defConn)->table('reports')->insertGetId([
                'sub_institute_id' => $subInstituteId,
                'module_scope' => $scope,
                'folder_id' => $defTargetFolder->id,
                'report_type' => 'tabular',
                'primary_module' => 'task_management.tasks',
                'title' => $reportTitle,
                'description' => $reportDesc,
                'owner_id' => $userId,
                'sharingtype' => 'Public',
                'definition' => json_encode($definition),
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "Inserted report on default connection (ID: $newId)\n";
        }
    }
}

// Test executing the query with ReportQueryExecutionService
echo "\nVerifying ReportQueryExecutionService execution for Report ID: $reportId...\n";
$registry = $app->make(ReportModuleRegistry::class);
$executor = new ReportQueryExecutionService($registry);

try {
    $result = $executor->execute(
        reportType: 'tabular',
        primaryModuleKey: 'task_management.tasks',
        definition: $definition,
        subInstituteId: $subInstituteId,
        forceFresh: true
    );

    echo "Query executed successfully!\n";
    echo "Total matching rows for tomorrow: " . ($result['total'] ?? 0) . "\n";
    if (!empty($result['rows'])) {
        echo "Sample row 1: " . json_encode($result['rows'][0]) . "\n";
    } else {
        echo "No tasks currently scheduled for tomorrow in tenant 6, but query executed cleanly without error.\n";
    }
} catch (\Throwable $e) {
    echo "Query execution error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\nDone!\n";
