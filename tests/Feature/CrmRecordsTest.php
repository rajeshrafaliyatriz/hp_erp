<?php

namespace Tests\Feature;

use App\Domain\Crm\Records\RecordNotFound;
use App\Domain\Crm\Records\RecordNote;
use App\Domain\Crm\Records\RecordRefused;
use App\Domain\Crm\Records\RecordTask;
use App\Services\TaskManagement\CalendarEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The two shared write services (slice 7, thin): RecordNote and RecordTask add a note or a task to a CRM record for a caller that is not a request. They do
 * not check the person's rights (their caller has), so what they DO check is tested here on its own: the record must exist, be live and belong to the
 * organisation, whoever calls them, because the agent gateway's own scoping is not the only door these may one day have.
 */
class CrmRecordsTest extends TestCase
{
    use BuildsAgentWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    private function contact(int $tenant, string $last = 'Rao'): int
    {
        return (int) DB::table('crm_contacts')->insertGetId(['contact_no' => 'C-' . uniqid(), 'last_name' => $last, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- RecordNote ----

    public function test_a_note_is_written_for_the_person_it_is_made_for_with_who_wrote_it(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');

        $id = (new RecordNote())->record(self::T, 'organization', $org['crm'], 42, '  A note.  ', 'agent');

        $row = DB::table('crm_organization_comments')->find($id);
        $this->assertSame(['A note.', 42, 'agent', $org['crm'], self::T], [$row->comment, (int) $row->created_by, $row->created_via, (int) $row->organization_id, (int) $row->sub_institute_id]);
    }

    public function test_a_note_a_person_writes_has_no_via_and_a_column_that_is_not_there_yet_is_not_written(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        $note = new RecordNote();

        $this->assertNull(DB::table('crm_organization_comments')->find($note->record(self::T, 'organization', $org['crm'], 42, 'By a person.'))->created_via);

        // A host that has not run the created_via migration yet (the code ships before the column does): the note is written and the via is simply not recorded.
        (require base_path('database/migrations/2026_12_10_090400_add_created_via_to_crm_organization_comments.php'))->down();
        $id = $note->record(self::T, 'organization', $org['crm'], 42, 'By the agent.', 'agent');
        $this->assertSame('By the agent.', DB::table('crm_organization_comments')->find($id)->comment);
        $this->assertFalse(Schema::hasColumn('crm_organization_comments', 'created_via'));
    }

    public function test_it_writes_notes_on_contacts_and_opportunities_too(): void
    {
        $contact = $this->contact(self::T);

        $id = (new RecordNote())->record(self::T, 'contact', $contact, 42, 'On a contact.');

        $this->assertSame([$contact, 'On a contact.'], [(int) DB::table('crm_contact_comments')->find($id)->contact_id, DB::table('crm_contact_comments')->find($id)->comment]);
        $this->assertSame(['lead', 'contact', 'organization', 'opportunity'], RecordNote::entityTypes());
    }

    public function test_a_record_of_another_organisation_or_a_deleted_one_or_one_that_never_existed_is_the_same_not_found(): void
    {
        $mine = $this->makeOrg(self::T, 'Greenfield');
        $theirs = $this->makeOrg(self::T2, 'Their School');
        DB::table('crm_organizations')->where('id', $mine['crm'])->update(['deleted_at' => now()]);
        $live = $this->makeOrg(self::T, 'Live School');
        $note = new RecordNote();
        $messages = [];

        foreach ([[$theirs['crm'], 'another organisation'], [$mine['crm'], 'a deleted record'], [999999, 'one that never existed']] as [$id, $what]) {
            try {
                $note->record(self::T, 'organization', $id, 42, 'Should not be written.');
                $this->fail("a note was written on {$what}");
            } catch (RecordNotFound $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame(0, DB::table('crm_organization_comments')->count());
        $this->assertSame(['The organization was not found.', 'The organization was not found.', 'The organization was not found.'], $messages, 'the same answer for all three: a probe learns nothing');
        $this->assertNotNull($note->record(self::T, 'organization', $live['crm'], 42, 'But a live one of this organisation is fine.'));
    }

    public function test_a_note_must_have_text_and_a_known_kind_of_record_and_is_cut_to_the_column_limit(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        $note = new RecordNote();

        foreach ([['organization', "  \n "], ['sale', 'x']] as [$type, $text]) {
            try {
                $note->record(self::T, $type, $org['crm'], 42, $text);
                $this->fail("accepted {$type} / " . json_encode($text));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $id = $note->record(self::T, 'organization', $org['crm'], 42, str_repeat('x', RecordNote::MAX_LENGTH + 50));
        $this->assertSame(RecordNote::MAX_LENGTH, mb_strlen(DB::table('crm_organization_comments')->find($id)->comment));
    }

    // ---- RecordTask ----

    public function test_a_task_is_a_calendar_event_linked_to_the_record_owned_by_the_person_it_is_for_in_the_current_academic_year(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        Schema::create('academic_year', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('syear');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->softDeletes();
        });
        DB::table('academic_year')->insert(['sub_institute_id' => self::T, 'syear' => '2026', 'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->addMonths(6)->toDateString()]);
        $due = now()->addDays(5)->toDateString();

        $id = (new RecordTask())->record(self::T, 'organization', $org['crm'], 7, 42, '  Call the trustee  ', 'Why: a signal.', $due);

        $row = DB::table('task_management_calendar_events')->find($id);
        $this->assertSame(['Call the trustee', 'Why: a signal.', 7, 42, 'ORGANIZATION', $org['crm'], 'Planned', '2026', self::T],
            [$row->title, $row->description, (int) $row->owner_id, (int) $row->created_by, $row->linked_type, (int) $row->linked_id, $row->status, $row->syear, (int) $row->sub_institute_id]);
        $this->assertSame("{$due} 09:00:00", $row->start_at);
        $this->assertSame("{$due} 09:30:00", $row->end_at);
    }

    public function test_a_record_of_another_organisation_or_a_deleted_one_gets_no_task(): void
    {
        $mine = $this->makeOrg(self::T, 'Greenfield');
        $theirs = $this->makeOrg(self::T2, 'Their School');
        DB::table('crm_organizations')->where('id', $mine['crm'])->update(['deleted_at' => now()]);
        $task = new RecordTask();

        foreach ([$theirs['crm'], $mine['crm'], 999999] as $id) {
            try {
                $task->record(self::T, 'organization', $id, 7, 7, 'Should not exist', null, now()->addDay()->toDateString());
                $this->fail("a task was written on record {$id}");
            } catch (RecordNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('task_management_calendar_events')->count());
    }

    public function test_an_open_task_with_the_same_title_is_not_doubled_but_a_finished_one_does_not_count(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        $task = new RecordTask();
        $due = now()->addDays(3)->toDateString();

        $first = $task->record(self::T, 'organization', $org['crm'], 7, 7, 'Call the trustee', null, $due);
        $again = $task->record(self::T, 'organization', $org['crm'], 7, 7, 'Call the trustee', null, $due);
        DB::table('task_management_calendar_events')->where('id', $first)->update(['status' => 'Completed']);
        $afterIt = $task->record(self::T, 'organization', $org['crm'], 7, 7, 'Call the trustee', null, $due);

        $this->assertNotNull($first);
        $this->assertNull($again, 'a retry never doubles an open task');
        $this->assertNotNull($afterIt, 'once the first is done, the same title is a new task');
        $this->assertSame(2, DB::table('task_management_calendar_events')->count());
    }

    public function test_the_other_organisations_open_task_with_the_same_title_does_not_block_this_one(): void
    {
        $mine = $this->makeOrg(self::T, 'Greenfield');
        $theirs = $this->makeOrg(self::T2, 'Their School');
        $task = new RecordTask();
        $due = now()->addDays(3)->toDateString();
        $task->record(self::T2, 'organization', $theirs['crm'], 7, 7, 'Call the trustee', null, $due);

        $this->assertNotNull($task->record(self::T, 'organization', $mine['crm'], 7, 7, 'Call the trustee', null, $due));
    }

    public function test_a_title_is_cut_a_date_must_be_a_real_day_and_a_task_due_today_starts_at_the_next_whole_hour_when_nine_has_passed(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        $task = new RecordTask();

        foreach (['2026-02-30', 'tomorrow', '03/11/2026', ''] as $bad) {
            try {
                $task->record(self::T, 'organization', $org['crm'], 7, 7, 'A task', null, $bad);
                $this->fail("accepted the date {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $task->record(self::T, 'organization', $org['crm'], 7, 7, '   ', null, now()->addDay()->toDateString());
            $this->fail('accepted an empty title');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->travelTo(now()->setTime(14, 20));
        $id = $task->record(self::T, 'organization', $org['crm'], 7, 7, str_repeat('t', RecordTask::MAX_TITLE + 40), null, now()->toDateString());
        $row = DB::table('task_management_calendar_events')->find($id);
        $this->assertSame(RecordTask::MAX_TITLE, mb_strlen($row->title));
        $this->assertSame(now()->toDateString() . ' 15:00:00', $row->start_at, 'nine has passed today, so the next whole hour');
    }

    public function test_a_task_on_something_that_cannot_have_one_is_refused_and_the_calendar_saying_no_is_a_refusal_not_a_crash(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield');
        $task = new RecordTask();
        try {
            $task->record(self::T, 'invoice', 1, 7, 7, 'A task', null, now()->addDay()->toDateString());
            $this->fail('a task on an invoice');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        app()->instance(CalendarEventWriter::class, new class extends CalendarEventWriter
        {
            public function __construct()
            {
            }

            public function create(array $attributes, int $subInstituteId, string $syear, int $actorId): array
            {
                return ['ok' => false, 'reason' => 'An event cannot end before it starts.'];
            }
        });

        $this->expectException(RecordRefused::class);
        $this->expectExceptionMessage('The task could not be scheduled: An event cannot end before it starts.');
        $task->record(self::T, 'organization', $org['crm'], 7, 7, 'A task', null, now()->addDay()->toDateString());
    }
}
