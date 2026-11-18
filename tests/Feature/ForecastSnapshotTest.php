<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\ForecastSnapshots;
use App\Domain\Crm\Sales\ForecastSnapshotsBlocked;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * Slice 11, task 6: the daily record of the CRM pipeline per owner (g2g_forecast_snapshots). The arithmetic follows the GTM forecast's rules: whole minor units,
 * one currency at a time, no placeholder values (an opportunity without an amount or currency is counted, not summed; one without a probability is left out of the
 * weighted figure and counted, and with none at all the figure is NULL), cumulative categories. A snapshot is "as of now", stored under today's date, and a second
 * run on the same day replaces that day's rows. Another organisation's rows are never read or written.
 */
class ForecastSnapshotTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    private const OWNER_A = 10;

    private const OWNER_B = 11;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        Carbon::setTestNow('2026-11-04 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $over */
    private function deal(int $tenant, int $owner, ?string $amount, ?string $currency, array $over = []): int
    {
        return $this->makeOpportunity($tenant, $over + ['assigned_to' => $owner ?: null, 'amount' => $amount, 'currency' => $currency, 'sales_stage' => 'Qualification']);
    }

    /** The world whose numbers are worked out by hand in the test below. */
    private function world(): void
    {
        $this->deal(self::T, self::OWNER_A, '1000.00', 'INR', ['probability' => 50]);                                       // pipeline, weighted 500
        $this->deal(self::T, self::OWNER_A, '2000.00', 'INR', ['probability' => 80, 'forecast_category' => 'Commit']);       // commit, weighted 1600
        $this->deal(self::T, self::OWNER_A, '500.00', 'INR', ['forecast_category' => 'Best Case']);                          // best case, no probability
        $this->deal(self::T, self::OWNER_A, null, 'INR');                                                                    // no amount: counted, not summed
        $this->deal(self::T, self::OWNER_A, '300.00', 'USD', ['probability' => 10]);                                         // another currency, its own row
        $this->deal(self::T, self::OWNER_A, '400.00', null);                                                                 // no currency: counted, never summed
        $this->deal(self::T, self::OWNER_A, '77.00', 'RUPEE');                                                               // not a three-letter code: not guessed (not 'RUP')
        $this->deal(self::T, self::OWNER_A, '700.00', 'INR', ['forecast_category' => 'Omitted']);                            // omitted: left out of everything
        $this->deal(self::T, self::OWNER_A, '9999.00', 'INR', ['sales_stage' => 'Closed Won']);                              // closed
        $this->deal(self::T, self::OWNER_A, '8888.00', 'INR', ['sales_stage' => 'Closed Lost']);
        $this->deal(self::T, self::OWNER_A, '5555.00', 'INR', ['deleted_at' => now()]);                                      // deleted
        $this->deal(self::T, self::OWNER_B, '100.00', 'INR', ['probability' => 0]);                                          // a probability of 0 is a probability
        $this->deal(self::T, 0, '50.00', 'INR');                                                                             // nobody owns it
        $this->deal(self::T2, 77, '1000000.00', 'INR', ['probability' => 100, 'forecast_category' => 'Commit']);             // another organisation
    }

    /** @return array<string, array<string, mixed>> the computed rows keyed "scope|owner|currency" */
    private function keyed(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out["{$r['scope']}|{$r['owner_user_id']}|{$r['currency']}"] = $r;
        }

        return $out;
    }

    public function test_the_numbers_are_worked_out_by_hand(): void
    {
        $this->world();

        $rows = $this->keyed((new ForecastSnapshots)->compute(self::T));

        // all owners, INR: A has 1000+2000+500(+ an unpriced one), B 100, nobody 50. Omitted 700, closed and deleted ones are out.
        $total = $rows['total|0|INR'];
        $this->assertSame([6, 5, 1], [$total['open_count'], $total['priced_count'], $total['omitted_count']]);
        $this->assertEquals([3650, 2500, 2000], [$total['open_value'], $total['best_case_value'], $total['commit_value']]);
        $this->assertEquals([2100, 3], [$total['weighted_value'], $total['weighted_count']], '500 + 1600 + 0');
        // one owner
        $a = $rows['owner|10|INR'];
        $this->assertSame([4, 3, 1], [$a['open_count'], $a['priced_count'], $a['omitted_count']]);
        $this->assertEquals([3500, 2500, 2000, 2100, 2], [$a['open_value'], $a['best_case_value'], $a['commit_value'], $a['weighted_value'], $a['weighted_count']]);
        // a probability of 0 gives a weighted value of 0, not NULL
        $b = $rows['owner|11|INR'];
        $this->assertSame(0.0, (float) $b['weighted_value']);
        $this->assertNotNull($b['weighted_value']);
        $this->assertSame(1, $b['weighted_count']);
        // nobody's opportunities are an owner of their own (0)
        $this->assertEquals([1, 50, null], [$rows['owner|0|INR']['open_count'], $rows['owner|0|INR']['open_value'], $rows['owner|0|INR']['weighted_value']], 'no probability: NULL, not 0');
        // another currency is a row of its own, never added to the rupees
        $this->assertEquals([1, 300, 30.0], [$rows['total|0|USD']['open_count'], $rows['total|0|USD']['open_value'], $rows['total|0|USD']['weighted_value']]);
        // no currency: counted, never summed
        $none = $rows['total|0|XXX'];
        $this->assertSame([2, 0], [$none['open_count'], $none['priced_count']], 'one with no currency and one with a word that is not a currency code');
        $this->assertEquals([0, 0, 0], [$none['open_value'], $none['best_case_value'], $none['commit_value']]);
        $this->assertNull($none['weighted_value']);
        // the other organisation's million is nowhere
        $this->assertSame([], array_filter($rows, fn ($r) => $r['open_value'] >= 1000000));
    }

    public function test_money_is_added_in_whole_minor_units(): void
    {
        foreach (['0.29', '0.57', '0.58'] as $amount) {
            $this->deal(self::T, self::OWNER_A, $amount, 'INR', ['probability' => 33]);
        }

        $total = $this->keyed((new ForecastSnapshots)->compute(self::T))['total|0|INR'];

        $this->assertSame(1.44, (float) $total['open_value'], 'rounded to the cent, not cut: 0.29 x 100 is 28.999999999999996');
        $this->assertSame(0.48, (float) $total['weighted_value'], 'each deal weighted at 33 percent and rounded to the cent: 0.10 + 0.19 + 0.19');
    }

    public function test_a_currency_code_is_matched_without_regard_to_case(): void
    {
        $this->deal(self::T, self::OWNER_A, '100.00', 'inr');
        $this->deal(self::T, self::OWNER_A, '200.00', ' INR ');

        $rows = $this->keyed((new ForecastSnapshots)->compute(self::T));

        $this->assertEquals([2, 300], [$rows['total|0|INR']['open_count'], $rows['total|0|INR']['open_value']]);
        $this->assertArrayNotHasKey('total|0|inr', $rows);
    }

    public function test_with_no_probability_anywhere_the_weighted_figure_is_null(): void
    {
        $this->deal(self::T, self::OWNER_A, '1000.00', 'INR');
        $this->deal(self::T, self::OWNER_A, '2000.00', 'INR', ['forecast_category' => 'Commit']);

        $total = $this->keyed((new ForecastSnapshots)->compute(self::T))['total|0|INR'];

        $this->assertNull($total['weighted_value']);
        $this->assertSame(0, $total['weighted_count']);
        $this->assertEquals([3000, 2000, 2000], [$total['open_value'], $total['best_case_value'], $total['commit_value']], 'categories are cumulative: best case includes commit');
    }

    public function test_a_stage_spelled_in_another_case_is_still_closed(): void
    {
        $this->deal(self::T, self::OWNER_A, '1000.00', 'INR', ['sales_stage' => 'closed won']);
        $this->deal(self::T, self::OWNER_A, '10.00', 'INR', ['sales_stage' => null]);   // no stage yet: open

        $total = $this->keyed((new ForecastSnapshots)->compute(self::T))['total|0|INR'];

        $this->assertEquals([1, 10], [$total['open_count'], $total['open_value']]);
    }

    public function test_a_dry_run_records_nothing_and_apply_records_todays_rows(): void
    {
        $this->world();

        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T]);
        $dry = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $dry);
        $this->assertStringContainsString('Dry run: nothing was written', $dry);
        $this->assertSame(0, DB::table('g2g_forecast_snapshots')->count());

        $code = Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code);
        $rows = DB::table('g2g_forecast_snapshots')->get();
        $this->assertSame([self::T], $rows->pluck('sub_institute_id')->unique()->map(fn ($v) => (int) $v)->values()->all(), 'only the organisation asked for');
        $this->assertSame(['2026-11-04'], $rows->pluck('snapshot_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all(), "stored under today's date");
        $this->assertSame(count((new ForecastSnapshots)->compute(self::T)), $rows->count());
    }

    /** @return list<array<string, mixed>> the day's rows without their ids and moments */
    private function stored(int $tenant, string $date): array
    {
        return DB::table('g2g_forecast_snapshots')->where('sub_institute_id', $tenant)->where('snapshot_date', $date)->orderBy('scope', 'desc')->orderBy('owner_user_id')->orderBy('currency')->get()
            ->map(fn ($r) => array_diff_key((array) $r, ['id' => 1, 'created_at' => 1]))->all();
    }

    public function test_a_second_run_on_the_same_day_leaves_the_same_rows(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);
        $first = $this->stored(self::T, '2026-11-04');
        $count = DB::table('g2g_forecast_snapshots')->count();

        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->assertSame($first, $this->stored(self::T, '2026-11-04'));
        $this->assertSame($count, DB::table('g2g_forecast_snapshots')->count(), 'replaced, never added to');
    }

    public function test_a_rerun_the_same_day_follows_the_pipeline_and_drops_an_owner_who_has_nothing_open_any_more(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);
        $this->assertSame(1, DB::table('g2g_forecast_snapshots')->where('scope', 'owner')->where('owner_user_id', self::OWNER_B)->count());

        DB::table('crm_opportunities')->where('assigned_to', self::OWNER_B)->update(['sales_stage' => 'Closed Won']);
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, DB::table('g2g_forecast_snapshots')->where('scope', 'owner')->where('owner_user_id', self::OWNER_B)->count(), 'owner B has nothing open: no stale row');
        $this->assertEquals(3550, DB::table('g2g_forecast_snapshots')->where('scope', 'total')->where('currency', 'INR')->value('open_value'));
    }

    public function test_each_day_adds_its_own_rows_and_keeps_the_earlier_ones(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);
        $dayOne = $this->stored(self::T, '2026-11-04');

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->deal(self::T, self::OWNER_A, '10000.00', 'INR');
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->assertSame($dayOne, $this->stored(self::T, '2026-11-04'), "yesterday's record is not rewritten");
        $this->assertEquals(13650, DB::table('g2g_forecast_snapshots')->where('snapshot_date', '2026-11-05')->where('scope', 'total')->where('currency', 'INR')->value('open_value'));
        $this->assertEquals(3650, DB::table('g2g_forecast_snapshots')->where('snapshot_date', '2026-11-04')->where('scope', 'total')->where('currency', 'INR')->value('open_value'));
    }

    public function test_it_never_reads_or_writes_another_organisations_rows(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T2, '--apply' => true]);
        $theirs = $this->stored(self::T2, '2026-11-04');
        $this->assertNotSame([], $theirs);

        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->assertSame($theirs, $this->stored(self::T2, '2026-11-04'), "organisation 1's run left organisation 2's rows of the same day alone");
        $this->assertEquals(1000000, DB::table('g2g_forecast_snapshots')->where('sub_institute_id', self::T2)->where('scope', 'total')->value('open_value'));
        $this->assertSame(0, DB::table('g2g_forecast_snapshots')->where('sub_institute_id', self::T)->where('open_value', '>=', 1000000)->count());
    }

    public function test_the_unique_key_stops_a_double_row(): void
    {
        $this->world();
        (new ForecastSnapshots)->store(self::T, '2026-11-04', (new ForecastSnapshots)->compute(self::T));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('g2g_forecast_snapshots')->insert(['sub_institute_id' => self::T, 'snapshot_date' => '2026-11-04', 'scope' => 'total', 'owner_user_id' => 0, 'currency' => 'INR']);
    }

    // ---- the read endpoint ----

    public function test_the_endpoint_answers_for_the_callers_organisation_only(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--all' => true, '--apply' => true]);
        $caller = $this->opportunitiesUser(self::T, ['view']);

        $res = $this->getJson('/api/crm/opportunities/forecast-snapshots', $caller['h'])->assertOk();

        $items = $res->json('data.items');
        $this->assertNotEmpty($items);
        $this->assertSame([], array_filter($items, fn ($i) => $i['open_value'] >= 1000000), "organisation 2's million is not in organisation 1's answer");
        $this->assertSame(['2026-11-04', '2026-11-04'], [$res->json('data.first_date'), $res->json('data.last_date')]);
        $this->assertNotNull($res->json('data.basis'));
        $total = collect($items)->firstWhere(fn ($i) => $i['scope'] === 'total' && $i['currency'] === 'INR');
        $this->assertEquals(3650, $total['open_value']);
        $this->assertEquals(2100, $total['weighted_value']);
    }

    public function test_the_endpoint_filters_by_day_scope_owner_and_currency(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);
        Carbon::setTestNow('2026-11-06 10:00:00');
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);
        $h = $this->opportunitiesUser(self::T, ['view'])['h'];

        $this->assertSame(['2026-11-06'], array_values(array_unique(array_column($this->getJson('/api/crm/opportunities/forecast-snapshots?from=2026-11-05', $h)->json('data.items'), 'date'))));
        $this->assertSame(['2026-11-04'], array_values(array_unique(array_column($this->getJson('/api/crm/opportunities/forecast-snapshots?to=2026-11-05', $h)->json('data.items'), 'date'))));
        $this->assertSame(['total'], array_values(array_unique(array_column($this->getJson('/api/crm/opportunities/forecast-snapshots?scope=total', $h)->json('data.items'), 'scope'))));
        $owner = $this->getJson('/api/crm/opportunities/forecast-snapshots?scope=owner&owner_id=11', $h)->json('data.items');
        $this->assertSame([11], array_values(array_unique(array_column($owner, 'owner_user_id'))));
        $unowned = $this->getJson('/api/crm/opportunities/forecast-snapshots?scope=owner&owner_id=0&currency=inr', $h)->json('data.items');
        $this->assertSame([[0, 'INR']], array_values(array_unique(array_map(fn ($i) => [$i['owner_user_id'], $i['currency']], $unowned), SORT_REGULAR)), 'owner 0 is the unowned ones, and the currency is not case sensitive');
        $this->getJson('/api/crm/opportunities/forecast-snapshots?from=yesterday', $h)->assertStatus(422);
        $this->getJson('/api/crm/opportunities/forecast-snapshots?scope=everyone', $h)->assertStatus(422);
    }

    public function test_the_endpoint_needs_the_opportunities_view_right_and_a_token(): void
    {
        $this->world();
        Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--apply' => true]);

        $this->getJson('/api/crm/opportunities/forecast-snapshots')->assertStatus(401);
        $this->getJson('/api/crm/opportunities/forecast-snapshots', $this->user([], self::T)['h'])->assertStatus(403);
        $this->getJson('/api/crm/opportunities/forecast-snapshots', $this->opportunitiesUser(self::T, ['add'])['h'])->assertStatus(403);
        $this->getJson('/api/crm/opportunities/forecast-snapshots', $this->opportunitiesUser(self::T, ['view'])['h'])->assertOk();
    }

    public function test_the_endpoint_is_not_taken_for_an_opportunity_id(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/crm/opportunities/forecast-snapshots', 'GET'));

        $this->assertStringContainsString('CrmForecastSnapshotController', $route->getActionName());
    }

    public function test_a_host_without_the_table_answers_nothing_recorded(): void
    {
        Schema::drop('g2g_forecast_snapshots');

        $res = $this->getJson('/api/crm/opportunities/forecast-snapshots', $this->opportunitiesUser(self::T, ['view'])['h'])->assertOk();

        $this->assertSame([], $res->json('data.items'));
        $this->assertNull($res->json('data.first_date'));
    }

    // ---- the migration ----

    public function test_the_migration_makes_the_table_once_and_down_removes_only_it(): void
    {
        $file = base_path('database/migrations/2026_12_11_130100_create_g2g_forecast_snapshots_table.php');
        Schema::drop('g2g_forecast_snapshots');
        $migration = require $file;

        $migration->up();
        $migration->up();   // a second run does nothing

        $this->assertSame(['id', 'sub_institute_id', 'snapshot_date', 'scope', 'owner_user_id', 'currency', 'open_count', 'priced_count', 'omitted_count', 'open_value',
            'best_case_value', 'commit_value', 'weighted_value', 'weighted_count', 'created_at'], array_map(fn ($c) => $c['name'], Schema::getColumns('g2g_forecast_snapshots')));
        $unique = collect(Schema::getIndexes('g2g_forecast_snapshots'))->firstWhere('name', 'g2g_forecast_snapshots_unique');
        $this->assertTrue($unique['unique']);
        $this->assertSame(['sub_institute_id', 'snapshot_date', 'scope', 'owner_user_id', 'currency'], $unique['columns'], 'every unique index includes the organisation');
        $this->assertTrue(Schema::hasTable('crm_opportunities'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('g2g_forecast_snapshots'));
        $this->assertTrue(Schema::hasTable('crm_opportunities'), 'nothing else was touched');
        $migration->down();   // and a second down() does nothing
        $source = preg_replace('~/\*.*?\*/~s', '', file_get_contents($file));
        $this->assertStringNotContainsString('->foreign(', $source);
        $this->assertStringNotContainsString('->json(', $source);
    }

    // ---- the command and the schedule ----

    public function test_the_command_checks_its_options_and_says_so_without_the_table(): void
    {
        $this->assertSame(2, Artisan::call('opportunities:forecast-snapshot'));
        $this->assertSame(2, Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--all' => true]));
        $this->assertSame(2, Artisan::call('opportunities:forecast-snapshot', ['--tenant' => 'x']));
        $this->assertSame(2, Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T, '--database' => 'no_such_connection']));

        Schema::drop('g2g_forecast_snapshots');
        $this->assertSame(1, Artisan::call('opportunities:forecast-snapshot', ['--tenant' => self::T]));
        $this->assertStringContainsString('g2g_forecast_snapshots is not on this connection', Artisan::output());
        $this->expectException(ForecastSnapshotsBlocked::class);
        (new ForecastSnapshots)->run(self::T, true);
    }

    public function test_it_has_no_way_to_write_a_past_day(): void
    {
        $this->assertStringNotContainsString('{--date', file_get_contents(base_path('app/Console/Commands/OpportunitiesForecastSnapshotCommand.php')));
    }

    public function test_it_is_scheduled_daily_and_only_where_the_table_exists(): void
    {
        Artisan::call('list');   // loads routes/console.php, which registers the schedule
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'opportunities:forecast-snapshot'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('20 0 * * *', $event->expression);
        $this->assertStringContainsString('--all --apply', (string) $event->command);
        $this->assertTrue($event->filtersPass(app()), 'runs here: the table exists');
        Schema::drop('g2g_forecast_snapshots');
        $this->assertFalse($event->filtersPass(app()), 'and is skipped on a host without the table');
    }

    public function test_the_gtm_snapshot_table_and_its_service_are_left_alone(): void
    {
        $this->assertSame(0, count(array_filter(
            ['app/Domain/Gtm/Forecast/ForecastService.php', 'app/Domain/Gtm/Forecast/ForecastMath.php'],
            fn ($f) => str_contains(file_get_contents(base_path($f)), 'g2g_forecast_snapshots'),
        )));
    }
}
