<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TaskManagement\MyTasksController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The `can` block of GET /task-management/my-tasks/{id} (24 Nov 2026).
 *
 * The task detail panel used to guess what a viewer could do from `owner_id`, or from having been opened from the Dashboard, and guessed wrong both ways: an owner on
 * the Employee profile was shown Approve and Delete buttons that answered 403, an administrator who was not the owner was shown none, and every viewer was offered a
 * status form although only the assignee may change a status. Each flag now mirrors the check the matching endpoint makes, and the panel shows a control exactly when
 * its call can succeed.
 *
 * The profile ids are ones no other test uses: RoleKey caches a profile's role for the life of the process.
 */
class TaskDetailCanFlagsTest extends TestCase
{
    private const ASSIGNEE = 31;

    private const OWNER = 32;

    private const ADMIN = 33;

    private const BYSTANDER = 34;

    private const CREATOR = 35;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['tbluser', 'tbluserprofilemaster', 'task_deadline_extensions'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('tbluserprofilemaster', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name')->nullable();
            $t->string('role_key')->nullable();
        });
        Schema::create('tbluser', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('user_profile_id')->nullable();
        });
        Schema::create('task_deadline_extensions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('task_id');
            $t->string('status', 20)->default('pending');
        });

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 9301, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 9302, 'name' => 'Admin', 'role_key' => 'administrator'],
        ]);
        foreach ([self::ASSIGNEE, self::OWNER, self::BYSTANDER, self::CREATOR] as $id) {
            DB::table('tbluser')->insert(['id' => $id, 'user_profile_id' => 9301]);
        }
        DB::table('tbluser')->insert(['id' => self::ADMIN, 'user_profile_id' => 9302]);
    }

    protected function tearDown(): void
    {
        foreach (['tbluser', 'tbluserprofilemaster', 'task_deadline_extensions'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function can(int $viewer, string $status = 'PENDING', int $taskId = 7): array
    {
        $task = (object) [
            'id' => $taskId,
            'task_allocated_to' => self::ASSIGNEE,
            'task_allocated' => self::OWNER,
            'created_by' => self::CREATOR,
        ];

        $controller = (new \ReflectionClass(MyTasksController::class))->newInstanceWithoutConstructor();
        $permissions = new \ReflectionMethod($controller, 'permissions');
        $permissions->setAccessible(true);

        return $permissions->invoke($controller, $task, ['user_id' => $viewer, 'sub_institute_id' => 1, 'syear' => '2026'], $status);
    }

    public function test_the_assignee_can_move_the_status_and_ask_for_time_but_not_decide_or_delete(): void
    {
        $can = $this->can(self::ASSIGNEE);

        $this->assertTrue($can['update_status']);
        $this->assertTrue($can['manage']);
        $this->assertTrue($can['request_extension']);
        $this->assertFalse($can['decide_extension']);
        $this->assertFalse($can['delete']);
        $this->assertFalse($can['archive']);
        $this->assertFalse($can['approve']);
    }

    public function test_the_owner_can_decide_an_extension_even_on_the_employee_profile_but_not_change_the_status(): void
    {
        $can = $this->can(self::OWNER);

        $this->assertTrue($can['decide_extension'], 'the owner is the one notified, and the one the old buttons were shown to');
        $this->assertTrue($can['manage']);
        $this->assertFalse($can['update_status'], 'only the assignee may change a status');
        $this->assertFalse($can['request_extension']);
        $this->assertFalse($can['delete'], 'delete, archive and approve stay privileged');
        $this->assertFalse($can['approve']);
    }

    public function test_an_administrator_who_is_neither_assignee_nor_owner_can_do_everything_but_change_the_status(): void
    {
        $can = $this->can(self::ADMIN);

        $this->assertTrue($can['manage']);
        $this->assertTrue($can['delete']);
        $this->assertTrue($can['archive']);
        $this->assertTrue($can['approve']);
        $this->assertTrue($can['decide_extension']);
        $this->assertFalse($can['update_status']);
        $this->assertFalse($can['request_extension']);
    }

    public function test_the_creator_can_manage_but_nobody_unrelated_can_do_anything(): void
    {
        $this->assertTrue($this->can(self::CREATOR)['manage']);

        $this->assertSame(
            array_fill_keys(['update_status', 'manage', 'delete', 'archive', 'approve', 'request_extension', 'decide_extension'], false),
            $this->can(self::BYSTANDER)
        );
    }

    public function test_an_open_request_or_a_finished_task_closes_the_request_form(): void
    {
        $this->assertTrue($this->can(self::ASSIGNEE, 'IN-PROGRESS')['request_extension']);
        $this->assertFalse($this->can(self::ASSIGNEE, 'COMPLETED')['request_extension'], 'nothing to extend on finished work');

        DB::table('task_deadline_extensions')->insert(['task_id' => 7, 'status' => 'pending']);
        $this->assertFalse($this->can(self::ASSIGNEE)['request_extension'], 'one open request per task');

        DB::table('task_deadline_extensions')->where('task_id', 7)->update(['status' => 'rejected']);
        $this->assertTrue($this->can(self::ASSIGNEE)['request_extension'], 'a decided request does not block a new one');
    }
}
