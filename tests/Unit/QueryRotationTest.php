<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The catalogue rotation is a pure function of (calendar day, attempt index): it tiles the catalogue with no gaps or repeats. */
class QueryRotationTest extends TestCase
{
    /** @param list<string> $sources source type of each query, in catalogue order (interleaving mixes them) */
    private function pack(int $count, int $sources = 3, array $extra = []): IcpPack
    {
        $queries = [];
        for ($i = 0; $i < $count; $i++) {
            $queries[] = ['id' => 'q' . $i, 'source' => 's' . ($i % $sources), 'topic' => 'general', 'days' => 30, 'q' => 'query ' . $i];
        }

        return new IcpPack('t', ['queries' => $queries, 'per_run' => ['catalogue' => 4]] + $extra);
    }

    private function ids(array $run): array
    {
        return array_column($run, 'id');
    }

    public function test_identical_inputs_give_identical_windows(): void
    {
        $p = $this->pack(23);
        $d = Carbon::create(2026, 10, 27);
        $this->assertSame($this->ids($p->catalogueForRun($d, 5, 2)), $this->ids($p->catalogueForRun($d->copy(), 5, 2)));
        $this->assertSame($this->ids($p->catalogueForRun($d, 5)), $this->ids($p->catalogueForRun($d, 5, 0)), 'attempt defaults to 0');
    }

    public function test_every_query_is_covered_within_ceil_count_over_n_consecutive_attempts_with_no_repeated_window(): void
    {
        foreach ([[23, 5], [24, 4], [10, 3], [7, 7], [31, 9]] as [$count, $n]) {
            $p = $this->pack($count);
            $d = Carbon::create(2026, 10, 27);
            $need = (int) ceil($count / $n);
            foreach ([0, 3] as $offset) {
                $seen = [];
                $prev = null;
                for ($a = $offset; $a < $offset + $need; $a++) {
                    $w = $this->ids($p->catalogueForRun($d, $n, $a));
                    $this->assertCount(min($n, $count), $w);
                    $this->assertCount(count($w), array_unique($w), 'no duplicates inside a window');
                    if ($prev !== null && $count > $n) {
                        $this->assertNotSame($prev, $w, 'consecutive attempts never repeat a window');
                    }
                    $prev = $w;
                    foreach ($w as $id) {
                        $seen[$id] = true;
                    }
                }
                $this->assertCount($count, $seen, "count {$count}, n {$n}: all covered in {$need} attempts");
            }
        }
    }

    public function test_windows_tile_without_gaps_or_overlap(): void
    {
        $p = $this->pack(20);
        $d = Carbon::create(2026, 10, 27);
        // n divides count: 5 consecutive attempts partition the catalogue exactly.
        $all = [];
        for ($a = 0; $a < 5; $a++) {
            $all = array_merge($all, $this->ids($p->catalogueForRun($d, 4, $a)));
        }
        $this->assertCount(20, array_unique($all));
        $this->assertCount(20, $all);
    }

    public function test_consecutive_days_continue_across_the_year_boundary(): void
    {
        $p = $this->pack(23);
        $n = 5;
        $need = (int) ceil(23 / $n);
        $seen = [];
        $d = Carbon::create(2026, 12, 29);
        for ($i = 0; $i < $need; $i++) {
            foreach ($this->ids($p->catalogueForRun($d->copy()->addDays($i), $n)) as $id) {
                $seen[$id] = true;
            }
        }
        $this->assertCount(23, $seen, 'windows straddling 31 Dec -> 1 Jan still cover everything');
        // With one run a day the next day's window starts exactly where this one ended (31 Dec -> 1 Jan included).
        $order = array_column($this->orderedPack(23), 'id');
        $end = Carbon::create(2026, 12, 31);
        $last = $this->ids($p->catalogueForRun($end, $n));
        $next = $this->ids($p->catalogueForRun($end->copy()->addDay(), $n));
        $posLast = array_search($last[$n - 1], $order, true);
        $this->assertSame($order[($posLast + 1) % 23], $next[0]);
    }

    /** The interleaved order, read back from a window as large as the catalogue starting at cursor 0 is not available; derive by walking. */
    private function orderedPack(int $count): array
    {
        $p = $this->pack($count);
        $d = Carbon::create(2026, 10, 27);
        // n = 1 windows on consecutive attempts walk the circle one query at a time.
        $first = $p->catalogueForRun($d, 1, 0)[0];
        $walk = [$first];
        for ($a = 1; $a < $count; $a++) {
            $walk[] = $p->catalogueForRun($d, 1, $a)[0];
        }

        return $walk;
    }

    public function test_window_larger_than_catalogue_returns_each_query_once(): void
    {
        $p = $this->pack(6);
        $w = $this->ids($p->catalogueForRun(Carbon::create(2026, 10, 27), 20, 1));
        $this->assertCount(6, $w);
        $this->assertCount(6, array_unique($w));
    }

    public function test_zero_or_negative_n_and_empty_catalogue_return_nothing(): void
    {
        $this->assertSame([], $this->pack(8)->catalogueForRun(Carbon::create(2026, 10, 27), 0));
        $this->assertSame([], $this->pack(8)->catalogueForRun(Carbon::create(2026, 10, 27), -3, 2));
        $this->assertSame([], $this->pack(0)->catalogueForRun(Carbon::create(2026, 10, 27), 4));
    }

    public function test_single_source_catalogue_rotates_and_covers_everything(): void
    {
        $p = $this->pack(11, 1);
        $seen = [];
        for ($a = 0; $a < (int) ceil(11 / 3); $a++) {
            foreach ($this->ids($p->catalogueForRun(Carbon::create(2026, 1, 1), 3, $a)) as $id) {
                $seen[$id] = true;
            }
        }
        $this->assertCount(11, $seen);
    }

    public function test_slot_count_never_exceeds_the_requested_cap(): void
    {
        $p = $this->pack(23, 3, ['per_run' => ['catalogue' => 4]]);
        for ($a = 0; $a < 30; $a++) {
            $this->assertLessThanOrEqual(4, count($p->catalogueForRun(Carbon::create(2026, 10, 27), null, $a)), 'default n is per_run.catalogue');
            $this->assertCount(7, $p->catalogueForRun(Carbon::create(2026, 10, 27), 7, $a), 'explicit n is honoured exactly');
        }
    }
}
