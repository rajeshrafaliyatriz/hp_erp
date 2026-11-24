<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `task:backfill-extension-dates` (24 Nov 2026): fills original_date and days_requested on requests made before those columns existed.
 *
 * A dry run changes nothing; --apply writes only what is still NULL; a second run changes nothing; an approved request gets its old date from the event store
 * only when an event sits within a day of the decision.
 */
class BackfillExtensionDatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['task', 'task_deadline_extensions', 'g2g_event'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('task', function (Blueprint $t) {
            $t->id();
            $t->date('task_date')->nullable();
        });
        Schema::create('task_deadline_extensions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('task_id');
            $t->date('requested_date');
            $t->date('original_date')->nullable();
            $t->smallInteger('days_requested')->nullable();
            $t->string('status', 20);
            $t->timestamp('decided_on')->nullable();
        });
        Schema::create('g2g_event', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id');
            $t->text('payload');
            $t->dateTime('occurred_at');
        });

        // 1 pending, 2 rejected: the task still has the date it was asked about.
        // 3 approved with an event; 4 approved with no event; 5 approved with an event a week away from the decision.
        foreach ([1 => '2026-12-01', 2 => '2026-12-02', 3 => '2026-12-15', 4 => '2026-12-16', 5 => '2026-12-17'] as $id => $due) {
            DB::table('task')->insert(['id' => $id, 'task_date' => $due]);
        }
        $row = fn (int $task, string $status, ?string $decided = null) => ['sub_institute_id' => 1, 'task_id' => $task, 'requested_date' => '2026-12-20', 'status' => $status, 'decided_on' => $decided];
        DB::table('task_deadline_extensions')->insert([
            $row(1, 'pending'), $row(2, 'rejected', '2026-11-20 10:00:00'),
            $row(3, 'approved', '2026-11-20 10:00:00'), $row(4, 'approved', '2026-11-20 10:00:00'), $row(5, 'approved', '2026-11-20 10:00:00'),
        ]);
        DB::table('g2g_event')->insert([
            ['type' => 'task.deadline_extended', 'entity_type' => 'task', 'entity_id' => 3, 'sub_institute_id' => 1, 'payload' => json_encode(['before' => ['task_date' => '2026-12-10']]), 'occurred_at' => '2026-11-20 10:00:05'],
            ['type' => 'task.deadline_extended', 'entity_type' => 'task', 'entity_id' => 5, 'sub_institute_id' => 1, 'payload' => json_encode(['before' => ['task_date' => '2026-12-11']]), 'occurred_at' => '2026-11-27 10:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['task', 'task_deadline_extensions', 'g2g_event'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function original(int $task): ?string
    {
        return DB::table('task_deadline_extensions')->where('task_id', $task)->value('original_date');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('task:backfill-extension-dates')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, DB::table('task_deadline_extensions')->whereNotNull('original_date')->count());
    }

    public function test_apply_fills_what_it_can_from_the_task_and_the_event_store(): void
    {
        $evidence = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ext-backfill-' . uniqid() . '.json';

        $this->artisan('task:backfill-extension-dates', ['--apply' => true, '--evidence' => $evidence])->assertSuccessful();

        $this->assertSame('2026-12-01', $this->original(1), 'pending: the task still has the date');
        $this->assertSame('2026-12-02', $this->original(2), 'rejected: the task was not changed');
        $this->assertSame('2026-12-10', $this->original(3), 'approved: the old date comes from the event next to the decision');
        $this->assertNull($this->original(4), 'approved with no event: nothing to go on, so nothing invented');
        $this->assertNull($this->original(5), "an event a week from the decision is somebody else's approval");
        $this->assertSame(19, (int) DB::table('task_deadline_extensions')->where('task_id', 1)->value('days_requested'));
        $this->assertSame('apply', json_decode((string) file_get_contents($evidence), true)['mode']);
        @unlink($evidence);
    }

    public function test_a_second_run_changes_nothing_and_never_overwrites_a_filled_value(): void
    {
        DB::table('task_deadline_extensions')->where('task_id', 1)->update(['original_date' => '2026-11-01']);

        $this->artisan('task:backfill-extension-dates', ['--apply' => true])->assertSuccessful();
        $first = DB::table('task_deadline_extensions')->orderBy('id')->get()->toJson();
        $this->artisan('task:backfill-extension-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2026-11-01', $this->original(1), 'a value someone filled is kept');
        $this->assertSame($first, DB::table('task_deadline_extensions')->orderBy('id')->get()->toJson());
    }
}
