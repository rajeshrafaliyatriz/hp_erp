<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TaskManagement\LegacyTaskController;
use App\Http\Controllers\Api\TaskManagement\MyTasksController;
use App\Http\Requests\TaskManagement\StoreTaskRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * My Tasks > Today / Today Due / Upcoming (24 Nov 2026).
 *
 * A task occupies [start, end]: planned_start_date falling back to the due date, and the due date falling back to the start. "Today" used to be `task_date = today`
 * only, so a task started today and due next week stayed out of it for the whole week.
 *
 *   today      running now - started on or before today, not past its due date, not finished
 *   due_today  the due date is today (what "Today" used to show), finished or not
 *   upcoming   not started yet
 *
 * The filter is exercised through MyTasksController::applyGroup directly. The HTTP list also selects CONCAT_WS, which sqlite does not have, and the grouping is the thing
 * that changed. The two date-order rules (create and update) are checked here too, because "start may not follow due" is half of making Start date mandatory.
 */
class MyTasksTodayGroupsTest extends TestCase
{
    private const ME = 10;

    private const OTHER = 11;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');

        Schema::dropIfExists('task');
        Schema::create('task', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('task_title')->default('t');
            $t->date('task_date')->nullable();
            $t->date('planned_start_date')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('task_allocated_to')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->default(1);
            $t->string('syear')->default('2026');
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        $rows = [
            // id => [start, due, status, owner]
            1 => ['2026-11-23', '2026-11-29', 'PENDING', self::ME],      // running, due later this week
            2 => ['2026-11-20', '2026-11-24', 'IN-PROGRESS', self::ME],  // running, due today
            3 => ['2026-11-24', '2026-11-24', 'COMPLETED', self::ME],    // finished, due today
            4 => ['2026-11-25', '2026-11-30', 'PENDING', self::ME],      // starts tomorrow
            5 => [null, '2026-11-27', 'PENDING', self::ME],              // no start, due in 3 days
            6 => [null, '2026-11-24', 'PENDING', self::ME],              // no start, due today
            7 => ['2026-11-23', '2026-11-29', 'PENDING', self::OTHER],   // someone else's
            8 => ['2026-11-10', '2026-11-20', 'PENDING', self::ME],      // overdue
            9 => ['2026-11-24', '2026-11-26', null, self::ME],           // NULL status counts as PENDING
            10 => ['2026-11-22', '2026-11-28', 'COMPLETED', self::ME],   // finished mid-span
            11 => ['2026-11-24', null, 'PENDING', self::ME],             // start only, no due date
        ];
        foreach ($rows as $id => [$start, $due, $status, $owner]) {
            DB::table('task')->insert([
                'id' => $id, 'planned_start_date' => $start, 'task_date' => $due,
                'status' => $status, 'task_allocated_to' => $owner,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('task');
        parent::tearDown();
    }

    /** @return int[] */
    private function idsIn(string $group): array
    {
        $controller = (new \ReflectionClass(MyTasksController::class))->newInstanceWithoutConstructor();
        $apply = new \ReflectionMethod($controller, 'applyGroup');
        $apply->setAccessible(true);

        $query = DB::table('task as t');
        $apply->invoke($controller, $query, $group, ['user_id' => self::ME, 'sub_institute_id' => 1, 'syear' => '2026']);

        return $query->pluck('t.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function test_today_lists_what_is_running_not_only_what_is_due(): void
    {
        // 1 running, 2 running and due today, 6 no start and due today, 9 NULL status, 11 start only.
        // Not 3 or 10 (finished), 4 (not started), 5 (due later, no start), 8 (overdue), 7 (not mine).
        $this->assertSame([1, 2, 6, 9, 11], $this->idsIn('today'));
    }

    public function test_today_due_is_what_today_used_to_show(): void
    {
        $this->assertSame([2, 3, 6], $this->idsIn('due_today'));
    }

    public function test_upcoming_is_only_what_has_not_started(): void
    {
        // 4 starts tomorrow; 5 has no start and is due after today. 1 is running, so it is no longer here.
        $this->assertSame([4, 5], $this->idsIn('upcoming'));
    }

    public function test_a_running_task_is_in_exactly_one_of_today_and_upcoming(): void
    {
        $today = $this->idsIn('today');
        $upcoming = $this->idsIn('upcoming');

        $this->assertSame([], array_values(array_intersect($today, $upcoming)));
        $this->assertContains(1, $today);
        $this->assertNotContains(1, $upcoming);
    }

    public function test_all_is_not_narrowed_by_date(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9, 10, 11], $this->idsIn('all'));
    }

    public function test_create_refuses_a_start_after_the_repeat_until_date(): void
    {
        $base = ['task_title' => 'x', 'sub_institute_id' => 1, 'syear' => '2026', 'repeat_until' => '2026-11-30'];

        $this->assertTrue($this->createPasses($base + ['planned_start_date' => '2026-11-24']));
        $this->assertTrue($this->createPasses($base + ['planned_start_date' => '2026-11-30']), 'a one-day task starts and ends on the same date');
        $this->assertFalse($this->createPasses($base + ['planned_start_date' => '2026-12-01']));
    }

    public function test_create_does_not_demand_a_start_from_older_callers_or_fail_without_an_end(): void
    {
        $base = ['task_title' => 'x', 'sub_institute_id' => 1, 'syear' => '2026'];

        $this->assertTrue($this->createPasses($base + ['repeat_until' => '2026-11-30']), 'the old Blade form sends no start');
        $this->assertTrue($this->createPasses($base + ['planned_start_date' => '2026-11-24']), 'before_or_equal against an absent field would fail');
    }

    public function test_update_refuses_a_due_date_before_the_start_but_allows_callers_that_send_no_start(): void
    {
        $base = ['title' => 'x', 'assignee_id' => 5];

        $this->assertTrue($this->updatePasses($base + ['planned_start_date' => '2026-11-24', 'due_date' => '2026-11-24']));
        $this->assertFalse($this->updatePasses($base + ['planned_start_date' => '2026-11-24', 'due_date' => '2026-11-23']));
        $this->assertTrue($this->updatePasses($base + ['due_date' => '2026-11-23']), 'a caller that sends no start is not broken by the new rule');
    }

    private function createPasses(array $data): bool
    {
        $request = StoreTaskRequest::create('/task', 'POST', $data);

        return ! Validator::make($data, $request->rules())->fails();
    }

    private function updatePasses(array $data): bool
    {
        // rules() reads the current request to decide whether a start was sent.
        request()->replace($data);

        $controller = (new \ReflectionClass(LegacyTaskController::class))->newInstanceWithoutConstructor();
        $rules = new \ReflectionMethod($controller, 'rules');
        $rules->setAccessible(true);

        return ! Validator::make($data, $rules->invoke($controller))->fails();
    }
}
