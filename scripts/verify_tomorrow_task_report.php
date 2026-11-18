<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Domain\Reports\ReportQueryExecutionService;
use App\Domain\Reports\ReportModuleRegistry;
use Carbon\Carbon;

$connection = 'db3';
DB::setDefaultConnection($connection);
$subInstituteId = 6;
$now = Carbon::now('Asia/Kolkata');
$tomorrow = $now->copy()->addDay()->toDateString();
$today = $now->toDateString();

echo "Testing in timezone Asia/Kolkata:\n";
echo "Today: $today\n";
echo "Tomorrow: $tomorrow\n";

// Get admin user
$user = DB::connection($connection)->table('tbluser')->where('sub_institute_id', $subInstituteId)->orderBy('id')->first();
$userId = $user->id;

// Insert a test task for tomorrow
$testTomorrowTaskId = DB::connection($connection)->table('task')->insertGetId([
    'sub_institute_id' => $subInstituteId,
    'task_allocated_to' => $userId,
    'task_allocated' => $userId,
    'task_title' => 'Verification Planned Task For Tomorrow',
    'task_description' => 'Detailed description of the planned task for testing next day report.',
    'task_date' => $tomorrow,
    'status' => 'PENDING',
    'created_at' => now(),
    'updated_at' => now(),
]);

// Insert a test task for today (which MUST be excluded)
$testTodayTaskId = DB::connection($connection)->table('task')->insertGetId([
    'sub_institute_id' => $subInstituteId,
    'task_allocated_to' => $userId,
    'task_allocated' => $userId,
    'task_title' => 'Task For Today (Should NOT appear)',
    'task_description' => 'Should be excluded.',
    'task_date' => $today,
    'status' => 'PENDING',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "Created test task for tomorrow (ID: $testTomorrowTaskId) and today (ID: $testTodayTaskId)\n";

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

    echo "\nResults returned:\n";
    echo "Total matching rows: " . ($result['total'] ?? 0) . "\n";
    foreach ($result['rows'] as $i => $row) {
        echo "Row " . ($i + 1) . ":\n";
        foreach ($row as $k => $v) {
            echo "  $k: $v\n";
        }
    }

    $titles = array_column($result['rows'], 'task_management.tasks.task_title');
    $hasTomorrow = in_array('Verification Planned Task For Tomorrow', $titles, true);
    $hasToday = in_array('Task For Today (Should NOT appear)', $titles, true);

    echo "\nAssertion checks:\n";
    echo "Has tomorrow's task: " . ($hasTomorrow ? "PASS" : "FAIL") . "\n";
    echo "Excluded today's task: " . (!$hasToday ? "PASS" : "FAIL") . "\n";

} finally {
    // Clean up
    DB::connection($connection)->table('task')->whereIn('id', [$testTomorrowTaskId, $testTodayTaskId])->delete();
    echo "\nCleaned up test tasks ($testTomorrowTaskId, $testTodayTaskId).\n";
}
