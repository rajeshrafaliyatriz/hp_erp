<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TaskManagement\WorkspaceController;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Task Dashboard's one-click filters, sort, and the numbers over them (24 Nov 2026).
 *
 * The chips above the list (Mine, Overdue, Due today, Due this week, Unassigned) and the cards over them are each COUNTED in summary() from the same definition
 * quickFilter() filters by, so a number is exactly what clicking it lists. These tests pin that: for every chip, the count in the summary equals the rows its filter returns.
 * Analytics reads by_status and by_priority from the same summary, across the whole scope rather than the 25 rows on a page.
 *
 * The controller's helpers are private and the full list query reads tables this test has no use for, so they are called through reflection.
 */
class TaskDashboardQuickFiltersTest extends TestCase
{
    private const ME = 7;

    private WorkspaceController $controller;

    private array $context = ['user_id' => self::ME, 'sub_institute_id' => 1, 'syear' => '2026', 'department_id' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');

        foreach (['task', 'tbluser', 'hrms_departments', 'task_deadline_extensions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('task', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('task_title')->default('t');
            $t->date('task_date')->nullable();
            $t->string('status')->nullable();
            $t->string('task_type')->nullable();
            $t->string('approve_status')->nullable();
            $t->unsignedBigInteger('task_allocated')->nullable();
            $t->unsignedBigInteger('task_allocated_to')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->default(1);
            $t->string('SYEAR')->default('2026');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('tbluser', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('department_id')->nullable();
        });
        Schema::create('hrms_departments', function (Blueprint $t) {
            $t->id();
            $t->string('department');
        });
        Schema::create('task_deadline_extensions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('requested_by')->default(0);
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->string('status', 20)->default('pending');
        });

        // id => [assignee, due, status, priority, approve_status, updated_at]
        $rows = [
            1 => [self::ME, '2026-11-20', 'PENDING', 'Medium', null, '2026-11-01'],       // overdue
            2 => [self::ME, '2026-11-24', 'IN-PROGRESS', 'High', null, '2026-11-02'],     // due today
            3 => [9, '2026-11-27', 'PENDING', 'High', null, '2026-11-03'],                // due this week
            4 => [0, '2026-12-30', 'PENDING', 'Low', null, '2026-11-04'],                 // unassigned, later
            5 => [9, '2026-11-22', 'ON HOLD', 'Medium', null, '2026-11-05'],              // blocked, and overdue
            6 => [self::ME, '2026-11-23', 'COMPLETED', 'Medium', null, '2026-11-10'],     // finished, waiting for approval, this month
            7 => [9, '2026-10-01', 'COMPLETED', 'Medium', 'approved', '2026-10-01'],      // finished long ago and approved
            8 => [self::ME, null, 'IN PROGRESS', 'Critical', 'rejected', '2026-11-06'], // the spelling real rows have        // no due date; its last completion was sent back
        ];
        foreach ($rows as $id => [$assignee, $due, $status, $priority, $approve, $updated]) {
            DB::table('task')->insert([
                'id' => $id, 'task_allocated_to' => $assignee, 'task_date' => $due, 'status' => $status, 'task_type' => $priority,
                'approve_status' => $approve, 'updated_at' => $updated . ' 09:00:00', 'created_at' => '2026-10-0' . $id . ' 09:00:00',
            ]);
        }
        DB::table('task')->insert(['id' => 9, 'task_allocated_to' => self::ME, 'task_date' => '2026-11-20', 'status' => 'PENDING', 'task_type' => 'High', 'deleted_at' => '2026-11-21 09:00:00']);

        $this->controller = (new \ReflectionClass(WorkspaceController::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['task', 'tbluser', 'hrms_departments', 'task_deadline_extensions'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function invoke(string $method, ...$arguments)
    {
        $reflection = new \ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->controller, ...$arguments);
    }

    /** @return int[] the ids a quick filter lists, ids ascending */
    private function ids(string $quick): array
    {
        [$sql, $bindings] = $this->invoke('quickFilter', $quick, $this->context);

        return DB::table('task as t')->whereNull('t.deleted_at')->whereRaw($sql, $bindings)->orderBy('t.id')->pluck('t.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return int[] ids in the order a sort puts them */
    private function sorted(string $sort): array
    {
        $query = DB::table('task as t')->whereNull('t.deleted_at');
        $this->invoke('applySort', $query, $sort);

        return $query->pluck('t.id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_each_chip_lists_what_its_label_says(): void
    {
        $this->assertSame([1, 2, 6, 8], $this->ids('mine'));
        $this->assertSame([1, 5], $this->ids('overdue'), 'past its date and not finished: the finished one (6) is not overdue');
        $this->assertSame([2], $this->ids('due_today'));
        $this->assertSame([2, 3], $this->ids('due_week'), 'today up to and including six days on');
        $this->assertSame([4], $this->ids('unassigned'));
        $this->assertSame([5], $this->ids('blocked'));
        $this->assertSame([6], $this->ids('needs_review'), 'finished and not approved; 7 was approved');
        $this->assertSame([1, 2, 3, 4, 5, 8], $this->ids('active'));
        $this->assertSame([1, 5], $this->ids('attention'), 'on hold, or overdue');
        $this->assertSame([6], $this->ids('completed_month'));
        $this->assertSame([7], $this->ids('approved_review'));
        $this->assertSame([8], $this->ids('sent_back'));
    }

    public function test_the_summary_counts_are_exactly_what_each_chip_lists(): void
    {
        $summary = $this->invoke('summary', $this->context, 'all');

        foreach (['mine', 'overdue', 'due_today', 'due_week', 'unassigned', 'approved_review', 'sent_back'] as $quick) {
            $this->assertSame(count($this->ids($quick)), $summary['quick'][$quick], "chip {$quick}");
        }
        $this->assertSame(count($this->ids('active')), $summary['active']);
        $this->assertSame(count($this->ids('needs_review')), $summary['pending_review']);
        $this->assertSame(count($this->ids('attention')), $summary['blocked_overdue']);
        $this->assertSame(count($this->ids('completed_month')), $summary['completed_this_month']);
    }

    public function test_analytics_numbers_cover_the_whole_scope_and_skip_deleted_tasks(): void
    {
        $summary = $this->invoke('summary', $this->context, 'all');

        $this->assertSame(['PENDING' => 3, 'IN-PROGRESS' => 2, 'ON HOLD' => 1, 'COMPLETED' => 2], $summary['by_status'], "'IN PROGRESS' (task 8) counts as in-progress");
        $this->assertSame(8, array_sum($summary['by_status']), 'every live task falls in exactly one status');
        $this->assertSame(
            ['Medium' => 4, 'High' => 1 + 1, 'Low' => 1, 'Critical' => 1],
            collect($summary['by_priority'])->pluck('count', 'priority')->all(),
            'the deleted High task (9) is not counted'
        );
        $this->assertSame(0, $summary['extension_pending']);

        DB::table('task_deadline_extensions')->insert(['sub_institute_id' => 1, 'task_id' => 1, 'status' => 'pending']);
        $this->assertSame(0, $this->invoke('summary', $this->context, 'all')['extension_pending'], 'a request on a task the viewer does not own is not their badge');

        DB::table('task')->where('id', 2)->update(['task_allocated' => self::ME]);
        DB::table('task_deadline_extensions')->insert(['sub_institute_id' => 1, 'task_id' => 2, 'status' => 'pending']);
        DB::table('task_deadline_extensions')->insert(['sub_institute_id' => 1, 'task_id' => 2, 'status' => 'approved']);
        $this->assertSame(1, $this->invoke('summary', $this->context, 'all')['extension_pending'], 'a pending request on their own task, and only the pending one');
    }

    public function test_sorting(): void
    {
        $this->assertSame([4, 3, 2, 6, 5, 1, 7, 8], $this->sorted('due_desc'), 'the default: latest due first, undated last');
        $this->assertSame([7, 1, 5, 6, 2, 3, 4, 8], $this->sorted('due_asc'), 'soonest first, undated last');
        $this->assertSame([8, 2, 3, 7, 1, 5, 6, 4], $this->sorted('priority'), 'critical, high, medium, low; ties by due date');
        $this->assertSame([6, 8, 5, 4, 3, 2, 1, 7], $this->sorted('updated'));
        $this->assertSame([8, 7, 6, 5, 4, 3, 2, 1], $this->sorted('created'));
    }
}
