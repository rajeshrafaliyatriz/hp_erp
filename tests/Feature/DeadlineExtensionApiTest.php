<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RecordingEventRecorder;
use Tests\TestCase;

/**
 * Deadline-extension requests: the rules, and the queue the Dashboard's Extensions tab reads (24 Nov 2026).
 *
 *   who asks      only the person the task is assigned to, on an unfinished task, for a date later than the one it has
 *   who sees      an administrator everything; a task's owner the requests on their tasks; anyone else their own
 *   who decides   an administrator or the task's owner - and a decline must say why
 *   the race      two decisions on one request: the second is refused, not applied twice
 *   the numbers   the date the task had when it was asked about is kept, because approving overwrites it
 *
 * The profile ids (9401, 9402) are used by no other test: RoleKey caches a profile's role for the life of the process.
 */
class DeadlineExtensionApiTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private int $admin;

    private int $owner;

    private int $assignee;

    private int $other;

    private int $outsider;

    private RecordingEventRecorder $events;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');

        foreach (['personal_access_tokens', 'tbluserprofilemaster', 'tbluser', 'hrms_departments', 'task', 'task_deadline_extensions', 'task_management_notifications', 'task_management_project_tasks'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name')->nullable();
            $t->string('role_key')->nullable();
        });
        Schema::create('tbluser', function (Blueprint $t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('hrms_departments', function (Blueprint $t) {
            $t->id();
            $t->string('department');
        });
        Schema::create('task', function (Blueprint $t) {
            $t->id();
            $t->string('task_title');
            $t->date('task_date')->nullable();
            $t->string('status')->nullable();
            $t->string('task_type')->nullable();
            $t->unsignedBigInteger('task_allocated')->nullable();
            $t->unsignedBigInteger('task_allocated_to')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->default(1);
            $t->string('syear')->default('2026');
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('task_deadline_extensions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('requested_by');
            $t->date('requested_date');
            $t->date('original_date')->nullable();
            $t->smallInteger('days_requested')->nullable();
            $t->text('reason')->nullable();
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_on')->nullable();
            $t->text('decision_remarks')->nullable();
            $t->timestamps();
        });
        Schema::create('task_management_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('task_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('body')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('task_management_project_tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('project_id');
        });

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 9401, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 9402, 'name' => 'Admin', 'role_key' => 'administrator'],
        ]);
        DB::table('hrms_departments')->insert(['id' => 5, 'department' => 'Finance']);

        $this->admin = $this->makeUser('Ada', 'Admin', 9402);
        $this->owner = $this->makeUser('Omar', 'Owner', 9401);
        $this->assignee = $this->makeUser('Asha', 'Assignee', 9401, 5);
        $this->other = $this->makeUser('Otto', 'Other', 9401);
        $this->outsider = $this->makeUser('Zed', 'Outsider', 9402, null, self::T2);

        $this->events = new RecordingEventRecorder();
        $this->app->instance(EventRecorder::class, $this->events);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['personal_access_tokens', 'tbluserprofilemaster', 'tbluser', 'hrms_departments', 'task', 'task_deadline_extensions', 'task_management_notifications', 'task_management_project_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function makeUser(string $first, string $last, int $profile, ?int $dept = null, int $tenant = self::T): int
    {
        return DB::table('tbluser')->insertGetId([
            'user_name' => strtolower($first), 'first_name' => $first, 'last_name' => $last,
            'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'department_id' => $dept,
        ]);
    }

    /** @return array<string, string> */
    private function as(int $userId): array
    {
        return ['Authorization' => 'Bearer ' . tbluserModel::find($userId)->createToken('t')->plainTextToken];
    }

    private function task(array $over = []): int
    {
        return DB::table('task')->insertGetId($over + [
            'task_title' => 'Vendor audit', 'task_date' => '2026-12-01', 'status' => 'PENDING', 'task_type' => 'High',
            'task_allocated' => $this->owner, 'task_allocated_to' => $this->assignee, 'created_by' => $this->owner,
        ]);
    }

    private function ext(int $taskId, array $over = []): int
    {
        return DB::table('task_deadline_extensions')->insertGetId($over + [
            'sub_institute_id' => self::T, 'task_id' => $taskId, 'requested_by' => $this->assignee,
            'requested_date' => '2026-12-08', 'original_date' => '2026-12-01', 'days_requested' => 7,
            'reason' => 'Supplier is late', 'status' => 'pending', 'created_at' => '2026-11-20 09:00:00', 'updated_at' => '2026-11-20 09:00:00',
        ]);
    }

    private function read(string $uri, int $user)
    {
        return $this->withHeaders($this->as($user))->getJson('/api/task-management/' . $uri . (str_contains($uri, '?') ? '&' : '?') . 'syear=2026');
    }

    private function write(string $method, string $uri, int $user, array $data = [])
    {
        return $this->withHeaders($this->as($user))->json($method, '/api/task-management/' . $uri, $data + ['syear' => '2026']);
    }

    /** @return int[] the request ids the queue returned, in order */
    private function queueIds(string $query, int $user): array
    {
        $response = $this->read('deadline-extensions/queue?' . $query, $user)->assertOk();

        return array_map('intval', array_column($response->json('data.requests'), 'id'));
    }

    // ── asking ───────────────────────────────────────────────────────────

    public function test_the_assignee_can_ask_and_the_old_date_is_kept(): void
    {
        $task = $this->task();

        $this->write('POST', 'deadline-extensions', $this->assignee, ['task_id' => $task, 'requested_date' => '2026-12-08', 'reason' => 'Supplier is late'])
            ->assertCreated();

        $row = DB::table('task_deadline_extensions')->first();
        $this->assertSame('2026-12-01', $row->original_date, 'approving overwrites the due date, so the date it had is kept now');
        $this->assertSame(7, (int) $row->days_requested);
        $this->assertSame($this->owner, (int) DB::table('task_management_notifications')->value('user_id'), 'the owner decides, so the owner is told');
        $this->assertCount(1, $this->events->of('task.deadline_extension_requested'));
    }

    public function test_only_the_assignee_can_ask_and_the_date_must_be_later_than_the_current_one(): void
    {
        $task = $this->task();
        $ask = ['task_id' => $task, 'requested_date' => '2026-12-08', 'reason' => 'x'];

        $this->write('POST', 'deadline-extensions', $this->owner, $ask)->assertForbidden();
        $this->write('POST', 'deadline-extensions', $this->other, $ask)->assertForbidden();
        $this->write('POST', 'deadline-extensions', $this->assignee, ['requested_date' => '2026-12-01'] + $ask)->assertStatus(422);
        $this->write('POST', 'deadline-extensions', $this->assignee, ['requested_date' => '2026-11-30'] + $ask)->assertStatus(422);
        $this->assertSame(0, DB::table('task_deadline_extensions')->count());
    }

    public function test_a_finished_task_or_a_second_open_request_is_refused(): void
    {
        $done = $this->task(['status' => 'COMPLETED']);
        $this->write('POST', 'deadline-extensions', $this->assignee, ['task_id' => $done, 'requested_date' => '2026-12-08', 'reason' => 'x'])->assertStatus(422);

        $open = $this->task();
        $ask = ['task_id' => $open, 'requested_date' => '2026-12-08', 'reason' => 'x'];
        $this->write('POST', 'deadline-extensions', $this->assignee, $ask)->assertCreated();
        $this->write('POST', 'deadline-extensions', $this->assignee, $ask)->assertStatus(409);
    }

    // ── deciding ─────────────────────────────────────────────────────────

    public function test_the_owner_approves_and_the_due_date_moves(): void
    {
        $task = $this->task();
        $id = $this->ext($task);

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->owner, ['decision' => 'approve'])->assertOk();

        $this->assertSame('approved', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));
        $this->assertSame('2026-12-08', substr((string) DB::table('task')->where('id', $task)->value('task_date'), 0, 10));
        $this->assertSame('2026-12-01', DB::table('task_deadline_extensions')->where('id', $id)->value('original_date'), 'the old date survives the approval');
        $this->assertCount(1, $this->events->of('task.deadline_extended'));
    }

    public function test_an_administrator_who_is_not_the_owner_can_decide_but_a_bystander_or_the_assignee_cannot(): void
    {
        $task = $this->task();
        $id = $this->ext($task);

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->assignee, ['decision' => 'approve'])->assertForbidden();
        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->other, ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->admin, ['decision' => 'approve'])->assertOk();
    }

    public function test_a_decline_must_say_why_and_changes_nothing_on_the_task(): void
    {
        $task = $this->task();
        $id = $this->ext($task);

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->owner, ['decision' => 'reject'])->assertStatus(422);
        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->owner, ['decision' => 'reject', 'remarks' => '   '])->assertStatus(422);
        $this->assertSame('pending', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->owner, ['decision' => 'reject', 'remarks' => 'Month-end cannot move'])->assertOk();
        $this->assertSame('rejected', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));
        $this->assertSame('2026-12-01', substr((string) DB::table('task')->where('id', $task)->value('task_date'), 0, 10));
        $this->assertSame('Month-end cannot move', DB::table('task_management_notifications')->where('type', 'deadline_extension_rejected')->value('body'));
    }

    public function test_a_second_decision_on_the_same_request_is_refused(): void
    {
        $task = $this->task();
        $id = $this->ext($task);

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->owner, ['decision' => 'approve'])->assertOk();
        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->admin, ['decision' => 'reject', 'remarks' => 'too late'])->assertStatus(409);

        $this->assertSame('approved', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));
    }

    public function test_another_organisations_request_is_not_found(): void
    {
        $id = $this->ext($this->task());

        $this->write('PATCH', "deadline-extensions/{$id}/decision", $this->outsider, ['decision' => 'approve'])->assertNotFound();
    }

    // ── withdrawing ──────────────────────────────────────────────────────

    public function test_only_the_requester_can_withdraw_and_only_while_pending(): void
    {
        $id = $this->ext($this->task());

        $this->write('PATCH', "deadline-extensions/{$id}/withdraw", $this->owner)->assertForbidden();
        $this->write('PATCH', "deadline-extensions/{$id}/withdraw", $this->assignee, ['remarks' => 'No longer needed'])->assertOk();
        $this->assertSame('withdrawn', DB::table('task_deadline_extensions')->where('id', $id)->value('status'));

        $this->write('PATCH', "deadline-extensions/{$id}/withdraw", $this->assignee)->assertStatus(409);
        $this->assertCount(1, $this->events->of('task.deadline_extension_withdrawn'));
    }

    // ── seeing ───────────────────────────────────────────────────────────

    public function test_the_per_task_list_needs_a_task_and_hides_other_peoples_requests(): void
    {
        $task = $this->task();
        $this->ext($task);

        $this->read('deadline-extensions', $this->admin)->assertStatus(422);
        $this->assertCount(1, $this->read("deadline-extensions?task_id={$task}", $this->assignee)->json('data.extensions'));
        $this->assertCount(1, $this->read("deadline-extensions?task_id={$task}", $this->owner)->json('data.extensions'));
        $this->assertCount(0, $this->read("deadline-extensions?task_id={$task}", $this->other)->json('data.extensions'), 'a bystander no longer reads the reasons');
    }

    public function test_the_queue_is_scoped_by_role_and_organisation(): void
    {
        $mine = $this->ext($this->task());                                                                                  // owner: Omar, assignee: Asha
        $adminOwned = $this->ext($this->task(['task_allocated' => $this->admin, 'task_allocated_to' => $this->other]), ['requested_by' => $this->other]);

        $this->assertEqualsCanonicalizing([$mine, $adminOwned], $this->queueIds('', $this->admin), 'an administrator sees the whole organisation');
        $this->assertSame([$mine], $this->queueIds('', $this->owner), 'an owner sees requests on their tasks');
        $this->assertSame([$mine], $this->queueIds('', $this->assignee), 'the requester sees their own');
        $this->assertSame([$adminOwned], $this->queueIds('', $this->other));
        $this->assertSame([], $this->queueIds('', $this->outsider), 'another organisation sees nothing');
    }

    public function test_the_queue_default_order_is_pending_first_oldest_first(): void
    {
        $task = $this->task();
        $decided = $this->ext($task, ['status' => 'approved', 'decided_by' => $this->owner, 'decided_on' => '2026-11-22 09:00:00']);
        $newer = $this->ext($this->task(), ['created_at' => '2026-11-23 09:00:00']);
        $older = $this->ext($this->task(), ['created_at' => '2026-11-18 09:00:00']);

        $this->assertSame([$older, $newer, $decided], $this->queueIds('', $this->admin));
    }

    public function test_the_queue_filters(): void
    {
        $t1 = $this->task();
        $a = $this->ext($t1, ['days_requested' => 3, 'reason' => 'Waiting on the vendor']);
        $b = $this->ext($this->task(['task_type' => 'Low', 'task_allocated_to' => $this->other]), ['requested_by' => $this->other, 'days_requested' => 20, 'original_date' => '2026-11-10', 'created_at' => '2026-11-21 09:00:00', 'reason' => 'Holiday']);
        $c = $this->ext($t1, ['status' => 'rejected', 'decided_by' => $this->owner, 'decided_on' => '2026-11-22 09:00:00', 'decision_remarks' => 'Month-end freeze']);

        $this->assertEqualsCanonicalizing([$a], $this->queueIds('status=pending&requester_id=' . $this->assignee, $this->admin));
        $this->assertSame([$c], $this->queueIds('status=rejected', $this->admin));
        $this->assertSame([$b], $this->queueIds('min_days=10', $this->admin));
        $this->assertSame([$a, $c], $this->queueIds('max_days=7&sort=oldest', $this->admin));
        $this->assertSame([$b], $this->queueIds('priority=Low', $this->admin));
        $this->assertSame([$b], $this->queueIds('q=holiday', $this->admin));
        $this->assertSame([$c], $this->queueIds('q=freeze', $this->admin), 'the decision remarks are searched too');
        $this->assertSame([$b], $this->queueIds('overdue_at_request=1', $this->admin), 'its due date had passed on the day they asked');
        $this->assertEqualsCanonicalizing([$a, $c], $this->queueIds('department_id=5', $this->admin), 'the assignee\'s department');
        $this->assertSame([$c], $this->queueIds('view=decided', $this->admin));
        $this->assertSame([$c], $this->queueIds('decided_from=2026-11-22&decided_to=2026-11-22', $this->admin));
    }

    public function test_repeat_extenders_are_the_tasks_asked_about_more_than_once(): void
    {
        $twice = $this->task();
        $first = $this->ext($twice, ['status' => 'approved', 'decided_by' => $this->owner, 'decided_on' => '2026-11-10 09:00:00', 'created_at' => '2026-11-09 09:00:00']);
        $second = $this->ext($twice);
        $once = $this->ext($this->task());

        $this->assertSame([$second], $this->queueIds('repeat=1', $this->admin), 'the second ask is the repeat');

        $response = $this->read('deadline-extensions/queue?sort=oldest', $this->admin)->json('data.requests');
        $byId = array_column($response, 'nth', 'id');
        $this->assertSame([1, 2, 1], [$byId[(string) $first], $byId[(string) $second], $byId[(string) $once]]);
        $this->assertSame(1, $this->read('deadline-extensions/queue', $this->admin)->json('data.summary.repeat_extenders'));
    }

    public function test_the_summary_counts_what_the_viewer_may_decide_and_ignores_the_filters(): void
    {
        $this->ext($this->task());
        $this->ext($this->task(['task_allocated' => $this->admin, 'task_allocated_to' => $this->other]), ['requested_by' => $this->other, 'created_at' => '2026-11-14 09:00:00']);
        $this->ext($this->task(), ['status' => 'approved', 'decided_by' => $this->owner, 'created_at' => '2026-11-20 09:00:00', 'decided_on' => '2026-11-20 21:00:00']);
        $this->ext($this->task(), ['status' => 'rejected', 'decided_by' => $this->owner, 'created_at' => '2026-11-20 09:00:00', 'decided_on' => '2026-11-21 09:00:00']);
        $this->ext($this->task(), ['status' => 'withdrawn']);

        $summary = $this->read('deadline-extensions/queue?status=withdrawn', $this->admin)->json('data.summary');

        $this->assertSame(2, $summary['pending']);
        $this->assertSame(10, $summary['oldest_pending_days']);
        $this->assertSame(0.5, $summary['approval_rate'], 'one approved, one rejected; the withdrawn one is neither');
        $this->assertEquals(18.0, $summary['avg_decision_hours'], 'twelve hours and twenty-four, averaged');
        $this->assertSame(2, $summary['counts']['awaiting_me'], 'an administrator can decide every pending one');
        $this->assertSame(5, $summary['counts']['all']);
        $this->assertSame(3, $summary['counts']['decided']);

        $owner = $this->read('deadline-extensions/queue', $this->owner)->json('data.summary');
        $this->assertSame(1, $owner['counts']['awaiting_me'], 'an owner can decide only requests on their own tasks');
        $this->assertSame(4, $owner['counts']['all']);
    }

    // ── the task's own history ───────────────────────────────────────────

    public function test_the_activity_feed_reads_the_event_store_and_merges_comments(): void
    {
        // The feed read a table nothing has written since the event store replaced it, so its History half was always empty.
        // MariaDB's CONCAT_WS, which the real query uses and sqlite does not have.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT_WS', fn (...$parts) => implode((string) array_shift($parts), array_filter($parts, fn ($p) => $p !== null && $p !== '')), -1);
        Schema::dropIfExists('g2g_event');
        Schema::create('g2g_event', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id');
            $t->dateTime('occurred_at');
        });
        Schema::dropIfExists('task_management_comments');
        Schema::create('task_management_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('author_id')->nullable();
            $t->text('content');
            $t->timestamp('created_at')->nullable();
        });

        $task = $this->task();
        DB::table('g2g_event')->insert([
            ['type' => 'task.deadline_extended', 'entity_type' => 'task', 'entity_id' => $task, 'actor_id' => $this->owner, 'sub_institute_id' => self::T, 'occurred_at' => '2026-11-23 10:00:00'],
            ['type' => 'task.status_changed', 'entity_type' => 'task', 'entity_id' => $task + 1, 'actor_id' => $this->owner, 'sub_institute_id' => self::T, 'occurred_at' => '2026-11-23 10:00:00'],
            ['type' => 'task.created', 'entity_type' => 'task', 'entity_id' => $task, 'actor_id' => $this->owner, 'sub_institute_id' => self::T2, 'occurred_at' => '2026-11-23 10:00:00'],
        ]);
        DB::table('task_management_comments')->insert(['task_id' => $task, 'sub_institute_id' => self::T, 'author_id' => $this->assignee, 'content' => 'On it', 'created_at' => '2026-11-24 09:00:00']);

        $feed = $this->read("workspace/{$task}/activity", $this->assignee)->assertOk()->json('data.activity');

        $this->assertSame(['comment', 'deadline_extended'], array_column($feed, 'type'), 'newest first, the task. prefix dropped, another task and another organisation left out');
        $this->assertSame('Omar Owner', $feed[1]['actor']);
        $this->assertSame('On it', $feed[0]['content']);
    }

    public function test_the_filter_options_list_only_what_the_viewer_can_see_and_only_when_asked(): void
    {
        $this->ext($this->task(), ['status' => 'approved', 'decided_by' => $this->owner, 'decided_on' => '2026-11-21 09:00:00']);
        $this->ext($this->task(['task_allocated' => $this->admin, 'task_allocated_to' => $this->other, 'task_type' => 'Low']), ['requested_by' => $this->other]);

        $without = $this->read('deadline-extensions/queue', $this->admin)->assertOk()->json('data');
        $this->assertNull($without['filters']);

        $options = $this->read('deadline-extensions/queue?include_filters=1', $this->admin)->json('data.filters');
        $this->assertEqualsCanonicalizing(['Asha Assignee', 'Otto Other'], array_column($options['requesters'], 'name'));
        $this->assertSame(['Omar Owner'], array_column($options['deciders'], 'name'));
        $this->assertSame(['Finance'], array_column($options['departments'], 'name'), 'only departments with a request: Otto has none');
        $this->assertEqualsCanonicalizing(['High', 'Low'], $options['priorities']);

        $mine = $this->read('deadline-extensions/queue?include_filters=1', $this->other)->json('data.filters');
        $this->assertSame(['Otto Other'], array_column($mine['requesters'], 'name'), 'an employee is offered only what they can see');
    }

    // ── bulk and export ──────────────────────────────────────────────────

    public function test_bulk_decisions_are_reported_row_by_row(): void
    {
        $mine = $this->ext($this->task());
        $notMine = $this->ext($this->task(['task_allocated' => $this->admin, 'task_allocated_to' => $this->other]), ['requested_by' => $this->other]);
        $already = $this->ext($this->task(), ['status' => 'approved', 'decided_by' => $this->owner, 'decided_on' => '2026-11-21 09:00:00']);

        $response = $this->write('POST', 'deadline-extensions/bulk-decision', $this->owner, ['ids' => [$mine, $notMine, $already, 9999], 'decision' => 'approve'])->assertOk();

        $this->assertSame(1, $response->json('data.succeeded'), 'the message and the count are the number that actually changed');
        $this->assertSame(3, $response->json('data.failed'));
        $byId = array_column($response->json('data.results'), 'ok', 'id');
        $this->assertTrue($byId[(string) $mine]);
        $this->assertFalse($byId[(string) $notMine], 'not the owner of that task');
        $this->assertFalse($byId[(string) $already]);
        $this->assertFalse($byId['9999']);
        $this->assertSame('pending', DB::table('task_deadline_extensions')->where('id', $notMine)->value('status'));
    }

    public function test_bulk_decline_needs_a_reason_and_is_capped(): void
    {
        $id = $this->ext($this->task());

        $this->write('POST', 'deadline-extensions/bulk-decision', $this->admin, ['ids' => [$id], 'decision' => 'reject'])->assertStatus(422);
        $this->write('POST', 'deadline-extensions/bulk-decision', $this->admin, ['ids' => range(1, 51), 'decision' => 'approve'])->assertStatus(422);
        $this->write('POST', 'deadline-extensions/bulk-decision', $this->admin, ['ids' => [], 'decision' => 'approve'])->assertStatus(422);
    }

    public function test_the_export_neutralises_spreadsheet_formulas(): void
    {
        $this->ext($this->task(['task_title' => '=HYPERLINK("http://evil","x")']), ['reason' => '+1 later']);

        $csv = $this->read('deadline-extensions/queue/export', $this->admin)->assertOk()->streamedContent();

        $this->assertStringContainsString('Original due', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1 later", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }
}
