<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadNumber;
use App\Domain\Numbering\NumberSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The lead number (slice 6, defect D8): the format is what it always was, the counter is seeded from what is already in the table, two organisations
 * share ONE number space (the real table has a unique index on lead_no alone), a number somebody else made is stepped over, and a host that has not
 * run the migration keeps numbering the old way.
 */
class LeadNumberTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
    }

    private function numberOf(int $id): string
    {
        return (string) DB::table('crm_leads')->where('id', $id)->value('lead_no');
    }

    public function test_the_format_is_ld_and_five_digits_and_a_sixth_digit_appears_at_100000(): void
    {
        $this->assertSame('LD-00001', LeadNumber::format(1));
        $this->assertSame('LD-00042', LeadNumber::format(42));
        $this->assertSame('LD-99999', LeadNumber::format(99999));
        $this->assertSame('LD-100000', LeadNumber::format(100000));
    }

    public function test_the_first_number_of_an_empty_table_is_ld_00001_and_they_count_on(): void
    {
        $this->assertSame('LD-00001', LeadNumber::claim());
        $this->assertSame('LD-00002', LeadNumber::claim());
        $this->assertSame('LD-00003', LeadNumber::claim());
        $this->assertSame(4, (int) DB::table('g2g_number_sequences')->where('seq_key', LeadNumber::KEY)->value('next_value'));
    }

    public function test_the_counter_is_seeded_from_the_highest_number_in_the_table_across_organisations_and_soft_deleted_rows(): void
    {
        $this->makeLead(self::T, ['lead_no' => 'LD-00007']);
        $this->makeLead(self::T2, ['lead_no' => 'LD-00031']);
        $gone = $this->makeLead(self::T, ['lead_no' => 'LD-00040']);
        DB::table('crm_leads')->where('id', $gone)->update(['deleted_at' => now()]);

        $this->assertSame('LD-00041', LeadNumber::claim(), 'LD-00040 belongs to a deleted lead and is still taken');
        $this->assertSame('LD-00042', LeadNumber::claim());
    }

    public function test_the_seed_is_numeric_not_alphabetical_and_survives_odd_numbers(): void
    {
        $this->makeLead(self::T, ['lead_no' => 'LD-99999']);
        $this->makeLead(self::T, ['lead_no' => 'LD-100003']);       // a sixth digit sorts BEFORE LD-99999 as text
        $this->makeLead(self::T, ['lead_no' => 'LEGACY-xyz']);       // not a number: counts as 0
        $this->makeLead(self::T, ['lead_no' => null]);
        $this->makeLead(self::T, ['lead_no' => 'NODASH77']);        // no dash: the whole text is read

        $this->assertSame(100003, LeadNumber::highest());
        $this->assertSame('LD-100004', LeadNumber::claim());
    }

    public function test_two_organisations_share_one_number_space_and_never_receive_the_same_number(): void
    {
        $numbers = [];
        foreach ([1, 2, 1, 2, 2, 1, 1, 2] as $tenant) {
            $id = LeadFactory::create(['last_name' => 'Verma', 'assigned_to' => 1], $tenant, 1);
            $numbers[] = $this->numberOf($id);
        }

        $this->assertSame(['LD-00001', 'LD-00002', 'LD-00003', 'LD-00004', 'LD-00005', 'LD-00006', 'LD-00007', 'LD-00008'], $numbers);
        $this->assertSame([1 => 4, 2 => 4], DB::table('crm_leads')->selectRaw('sub_institute_id t, COUNT(*) n')->groupBy('sub_institute_id')->orderBy('t')->pluck('n', 't')->all());
    }

    /**
     * The race itself (defect D8), made deterministic: while the first create is between "I have my number" and "I save", another create runs in full. The
     * moment between is found by listening for the scoring-rules query that sits there. With "highest plus one" both took the same number and the second
     * save failed on the unique index; with the counter they hold two.
     */
    public function test_two_creates_interleaved_between_the_number_and_the_save_never_collide(): void
    {
        $inner = null;
        $fired = false;
        DB::listen(function ($query) use (&$fired, &$inner) {
            if (! $fired && str_contains($query->sql, 'crm_lead_scoring_rules')) {
                $fired = true;
                $inner = LeadFactory::create(['last_name' => 'Inner', 'assigned_to' => 1], self::T2, 2);
            }
        });

        $outer = LeadFactory::create(['last_name' => 'Outer', 'assigned_to' => 1], self::T, 1);

        $this->assertTrue($fired, 'the second create really ran inside the first');
        $this->assertNotSame($this->numberOf($outer), $this->numberOf($inner));
        $this->assertEqualsCanonicalizing(['LD-00001', 'LD-00002'], [$this->numberOf($outer), $this->numberOf($inner)]);
        $this->assertSame(3, (int) DB::table('g2g_number_sequences')->where('seq_key', LeadNumber::KEY)->value('next_value'), 'the factory takes its number from the counter');
    }

    /** Why the counter is ONE counter and not one per organisation: the proof, against the unique index the real table has. */
    public function test_a_counter_per_organisation_would_collide_on_the_unique_index(): void
    {
        $first = LeadNumber::format(NumberSequence::claim('lead:' . self::T, fn () => 1));
        $second = LeadNumber::format(NumberSequence::claim('lead:' . self::T2, fn () => 1));
        $this->assertSame($first, $second, 'two organisations\' first leads would both be LD-00001');

        $this->makeLead(self::T, ['lead_no' => $first]);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeLead(self::T2, ['lead_no' => $second]);
    }

    public function test_a_number_somebody_else_made_without_asking_the_counter_is_stepped_over(): void
    {
        $this->assertSame('LD-00001', LeadNumber::claim());                  // the counter now says 2
        $this->makeLead(self::T, ['lead_no' => 'LD-00002']);                 // an older server, a script or a hand edit took it
        $this->makeLead(self::T, ['lead_no' => 'LD-00003']);
        $this->makeLead(self::T2, ['lead_no' => 'LD-00004']);

        $this->assertSame('LD-00005', LeadNumber::claim(), 'the counter was moved past everything that exists');
        $this->assertSame('LD-00006', LeadNumber::claim());

        $id = LeadFactory::create(['last_name' => 'Verma', 'assigned_to' => 1], self::T, 1);
        $this->assertSame('LD-00007', $this->numberOf($id), 'and the insert never met the unique index');
    }

    public function test_a_host_without_the_sequence_table_numbers_the_old_way(): void
    {
        Schema::drop('g2g_number_sequences');
        $this->makeLead(self::T, ['lead_no' => 'LD-00012']);

        $id = LeadFactory::create(['last_name' => 'Verma', 'assigned_to' => 1], self::T, 1);

        $this->assertSame('LD-00013', $this->numberOf($id));
    }

    /** Only "the counter's table is not there" may fall back to the old way: any other failure (a lock, a deadlock) must not quietly hand out a racy number. */
    public function test_a_database_error_that_is_not_the_missing_counter_table_is_not_swallowed(): void
    {
        DB::beforeExecuting(function (string $query) {
            if (str_contains($query, 'g2g_number_sequences')) {
                throw new \Illuminate\Database\QueryException('sqlite', $query, [], new \Exception('database is locked'));
            }
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('database is locked');

        LeadNumber::claim();
    }

    public function test_every_lead_the_factory_makes_gets_a_number_a_score_and_a_created_event(): void
    {
        DB::table('crm_lead_scoring_rules')->insert(['sub_institute_id' => self::T, 'is_active' => 1, 'sort_order' => 1, 'field_key' => 'lead_source', 'operator' => 'equals', 'value' => 'Web', 'points' => 15]);

        $id = LeadFactory::create(['last_name' => 'Verma', 'lead_source' => 'Web', 'assigned_to' => 1], self::T, 9);

        $row = DB::table('crm_leads')->where('id', $id)->first();
        $this->assertSame('LD-00001', $row->lead_no);
        $this->assertSame(15, (int) $row->lead_score);
        $this->assertSame(self::T, (int) $row->sub_institute_id);
        $event = DB::table('g2g_event')->where('type', 'crm.lead.created')->where('entity_id', $id)->first();
        $this->assertNotNull($event);
        $this->assertSame(self::T, (int) $event->sub_institute_id);
    }
}
