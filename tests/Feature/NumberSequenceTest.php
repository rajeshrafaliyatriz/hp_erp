<?php

namespace Tests\Feature;

use App\Domain\Numbering\NumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The counter behind the lead number (slice 6, defect D8). What has to be true: a number is never handed out twice, a key never touches another key's
 * row, the first claim of a key is seeded from the caller's own question and only then, and two requests that start the same key at the same moment still
 * get two numbers. The last one is tested twice: deterministically (a seed question that is answered by the OTHER request, in the gap between "no row yet"
 * and "make the row"), and for real, by two operating-system processes on one sqlite database file.
 *
 * The MariaDB form of the claim (LAST_INSERT_ID) cannot run on the in-memory sqlite test database; it was run against the dev database with two real
 * processes (Docs/agentic-crm/_evidence/s6/number-sequence-race-dev.txt).
 */
class NumberSequenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('database/migrations/2026_12_11_100000_create_g2g_number_sequences_table.php'))->up();
    }

    private function row(string $key): ?object
    {
        return DB::table('g2g_number_sequences')->where('seq_key', $key)->first();
    }

    public function test_the_first_claim_is_seeded_from_the_callers_question_and_the_next_ones_count_on(): void
    {
        $asked = 0;
        $seed = function () use (&$asked) {
            $asked++;

            return 42;
        };

        $this->assertSame(42, NumberSequence::claim('thing:1', $seed));
        $this->assertSame(43, NumberSequence::claim('thing:1', $seed));
        $this->assertSame(44, NumberSequence::claim('thing:1', $seed));
        $this->assertSame(1, $asked, 'the question is asked once, when the key has no row, and never again');
        $this->assertSame(45, (int) $this->row('thing:1')->next_value, 'next_value is the number the NEXT claim receives');
        $this->assertNotNull($this->row('thing:1')->updated_at);
    }

    public function test_a_seed_below_one_starts_at_one(): void
    {
        $this->assertSame(1, NumberSequence::claim('a:1', fn () => 0));
        $this->assertSame(1, NumberSequence::claim('b:1', fn () => -7));
    }

    public function test_two_keys_never_touch_each_others_counter(): void
    {
        $this->assertSame(10, NumberSequence::claim('thing:6', fn () => 10));
        $this->assertSame(500, NumberSequence::claim('thing:7', fn () => 500));
        $this->assertSame(11, NumberSequence::claim('thing:6', fn () => 99999));
        $this->assertSame(501, NumberSequence::claim('thing:7', fn () => 99999));
        $this->assertSame(12, NumberSequence::claim('thing:6', fn () => 99999));

        $this->assertSame(13, (int) $this->row('thing:6')->next_value);
        $this->assertSame(502, (int) $this->row('thing:7')->next_value);
        $this->assertSame(2, DB::table('g2g_number_sequences')->count());
    }

    public function test_numbers_claimed_one_after_the_other_never_repeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[] = NumberSequence::claim('many:all', fn () => 1);
        }

        $this->assertSame(range(1, 200), $seen);
    }

    /**
     * Two requests find no row for a key at the same moment. The second request's seed question is answered while the first is between "no row" and "make
     * the row": here the seed closure of the FIRST claim itself claims the same key (that is the other request, finishing first). The first then tries to
     * make the row, changes nothing (INSERT IGNORE), and claims from the row the other one made.
     */
    public function test_two_requests_starting_the_same_key_at_the_same_moment_get_two_numbers(): void
    {
        $other = null;
        $first = NumberSequence::claim('race:all', function () use (&$other) {
            $other = NumberSequence::claim('race:all', fn () => 5);

            return 5;
        });

        $this->assertSame(5, $other);
        $this->assertSame(6, $first);
        $this->assertNotSame($first, $other);
        $this->assertSame(7, (int) $this->row('race:all')->next_value);
        $this->assertSame(1, DB::table('g2g_number_sequences')->where('seq_key', 'race:all')->count());
    }

    public function test_raising_a_counter_never_lowers_it_and_leaves_a_key_without_a_row_alone(): void
    {
        NumberSequence::claim('up:all', fn () => 10);                    // next_value is now 11

        NumberSequence::raiseTo('up:all', 5);
        $this->assertSame(11, (int) $this->row('up:all')->next_value, 'a floor below the counter changes nothing');

        NumberSequence::raiseTo('up:all', 50);
        $this->assertSame(50, (int) $this->row('up:all')->next_value);
        $this->assertSame(50, NumberSequence::claim('up:all', fn () => 1));

        NumberSequence::raiseTo('nothing:all', 50);
        $this->assertNull($this->row('nothing:all'), 'raising does not create a counter: the first claim seeds it');
    }

    public function test_a_claim_inside_a_transaction_that_is_rolled_back_gives_the_number_back(): void
    {
        $this->assertSame(1, NumberSequence::claim('tx:all', fn () => 1));

        DB::beginTransaction();
        $this->assertSame(2, NumberSequence::claim('tx:all', fn () => 1));
        DB::rollBack();

        $this->assertSame(2, NumberSequence::claim('tx:all', fn () => 1), 'the rolled back claim left no gap');
    }

    public function test_available_says_whether_the_table_exists(): void
    {
        $this->assertTrue(NumberSequence::available());
        Schema::drop('g2g_number_sequences');
        $this->assertFalse(NumberSequence::available());
    }

    public function test_the_migration_makes_the_table_once_and_down_removes_only_it(): void
    {
        $migration = require base_path('database/migrations/2026_12_11_100000_create_g2g_number_sequences_table.php');
        $migration->up();                                                  // a second run: setUp made it already
        $this->assertSame(['seq_key', 'next_value', 'updated_at'], Schema::getColumnListing('g2g_number_sequences'));

        Schema::create('unrelated_table', fn ($t) => $t->id());
        $migration->down();

        $this->assertFalse(Schema::hasTable('g2g_number_sequences'));
        $this->assertTrue(Schema::hasTable('unrelated_table'));
        $migration->down();                                                // nothing to drop: no error
    }

    /**
     * Two operating-system processes, two real connections to ONE sqlite file, claiming the same key at the same time. If a number were ever handed out
     * twice, or the key were seeded twice, the union below would be shorter than the sum.
     */
    public function test_two_processes_never_claim_the_same_number(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'numseq_' . uniqid();
        mkdir($dir);
        $file = $dir . DIRECTORY_SEPARATOR . 'race.sqlite';
        $start = $dir . DIRECTORY_SEPARATOR . 'go';
        touch($file);

        config(['database.connections.race' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::connection('race')->statement('CREATE TABLE g2g_number_sequences (seq_key VARCHAR(60) PRIMARY KEY NOT NULL, next_value INTEGER NOT NULL, updated_at DATETIME NULL)');

        $claims = 50;
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file]);
        $worker = base_path('tests/Support/number_sequence_worker.php');
        $processes = [];
        foreach ([1, 2] as $n) {
            $pipes = [];
            $handle = proc_open([PHP_BINARY, $worker, $file, $start, 'race:all', (string) $claims, '1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($handle, 'the worker process started');
            $processes[$n] = [$handle, $pipes];
        }
        touch($start);

        $numbers = [];
        foreach ($processes as $n => [$handle, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($handle), "worker {$n} failed: " . substr($err . $out, 0, 600));
            $got = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
            $this->assertCount($claims, $got, "worker {$n} printed " . count($got) . ' numbers: ' . substr($err . $out, 0, 600));
            $numbers = array_merge($numbers, array_map('intval', $got));
        }

        sort($numbers);
        $this->assertSame(range(1, 2 * $claims), $numbers, 'every number from 1 to 100 was handed out exactly once, whichever process got it');
        $this->assertSame(2 * $claims + 1, (int) DB::connection('race')->table('g2g_number_sequences')->where('seq_key', 'race:all')->value('next_value'));

        DB::purge('race');
        @unlink($file);
        @unlink($start);
        @rmdir($dir);
    }
}
