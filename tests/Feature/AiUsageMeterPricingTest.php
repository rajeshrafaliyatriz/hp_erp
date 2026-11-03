<?php

namespace Tests\Feature;

use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use App\Domain\AI\Support\AiUsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2 add-on "AI prices in rupees": what AiUsageMeter writes for a call (Docs/agentic-crm/slices/S02-pricing-contract.md). The tables come from the
 * real migrations on the in-memory sqlite database; no provider is called. The meter sits on every AI call in the platform, so as much is asserted
 * about what it must NOT do (throw, lose the row, read rupees as dollars, answer 0 for an unknown price) as about the price it writes.
 */
class AiUsageMeterPricingTest extends TestCase
{
    private const MODELS = '2026_12_08_100000_add_currency_to_ai_models';

    private const EVENTS = '2026_12_08_100100_add_cost_columns_to_ai_usage_events';

    /** The columns of an insert on a host that has not run the pricing migrations: what this class has always written. */
    private const TODAYS_INSERT = [
        'ai_module', 'provider', 'model', 'source', 'input_tokens', 'output_tokens', 'latency_ms', 'estimated_cost_usd', 'outcome', 'finish_reason',
        'error', 'related_type', 'related_id', 'user_id', 'sub_institute_id', 'created_at', 'updated_at',
    ];

    private const COLUMNS_KEY = 'ai.usage_meter.columns';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests create and drop tables: never let them near anything but the in-memory database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['2026_09_19_120000_create_ai_intelligence_tables', '2026_09_21_150000_create_ai_usage_tables', '2026_10_09_160000_add_product_to_ai_usage_events', self::MODELS, self::EVENTS] as $m) {
            $this->migration($m)->up();
        }
        DB::table('ai_models')->delete();   // the table migration seeds the configured models; these tests set their own rates
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function meter(): AiUsageMeter
    {
        return app(AiUsageMeter::class);
    }

    private function withModel(?string $model): ResolvedAiConfiguration
    {
        return new ResolvedAiConfiguration(provider: 'deepseek', model: $model, apiKey: null, source: 'env');
    }

    /** A rate-card row. Rates are per 1,000 tokens; the platform's row has no organisation. */
    private function rate(?float $input, ?float $output, string $currency = 'INR', ?int $tenant = null, string $model = 'deepseek-flash'): int
    {
        return (int) DB::table('ai_models')->insertGetId([
            'provider' => 'deepseek', 'model_id' => $model, 'label' => $model, 'input_cost_per_1k' => $input, 'output_cost_per_1k' => $output,
            'currency' => $currency, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fire(int $in = 1000, int $out = 500, int|string|null $tenant = 6, array $options = [], ?string $model = 'deepseek-flash'): object
    {
        $this->meter()->record('signals', $this->withModel($model), $tenant, $in, $out, 120, $options);

        return DB::table('ai_usage_events')->orderByDesc('id')->first();
    }

    // ── what is written ──────────────────────────────────────────────────────

    public function test_a_rupee_price_is_written_as_rupees_and_never_as_dollars(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');

        $row = $this->fire(1000, 500);

        $this->assertEqualsWithDelta(0.0864, (float) $row->estimated_cost, 1e-9);
        $this->assertSame('INR', $row->cost_currency);
        $this->assertNull($row->estimated_cost_usd, 'a rupee price must never sit in the dollar column');
        $this->assertSame([1000, 500], [(int) $row->input_tokens, (int) $row->output_tokens]);
    }

    public function test_a_dollar_price_is_written_to_both_columns(): void
    {
        $this->rate(0.0003, 0.0012, 'USD');

        $row = $this->fire(2000, 1000);

        $this->assertEqualsWithDelta(0.0018, (float) $row->estimated_cost, 1e-9);
        $this->assertSame('USD', $row->cost_currency);
        $this->assertEqualsWithDelta(0.0018, (float) $row->estimated_cost_usd, 1e-9);
    }

    public function test_tokens_times_rate_is_rounded_to_six_decimals(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $row = $this->fire(12345, 678);   // 12.345 x 0.0288 + 0.678 x 0.1152 = 0.4336416
        $this->assertEqualsWithDelta(0.433642, (float) $row->estimated_cost, 1e-9);

        DB::table('ai_models')->delete();
        $this->rate(0.123456, 0.5, 'INR');
        $row = $this->fire(777, 3);   // 0.777 x 0.123456 + 0.003 x 0.5 = 0.097425312, not 0.097425312
        $this->assertEqualsWithDelta(0.097425, (float) $row->estimated_cost, 1e-9, 'rounded to six decimals, not stored with the surplus digits');
    }

    // ── which row is the price ───────────────────────────────────────────────

    public function test_the_organisations_complete_row_wins_over_the_platform_row_and_brings_its_own_currency(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->rate(0.001, 0.002, 'USD', 6);

        $row = $this->fire(1000, 1000, 6);

        $this->assertEqualsWithDelta(0.003, (float) $row->estimated_cost, 1e-9);
        $this->assertSame('USD', $row->cost_currency);
        $this->assertEqualsWithDelta(0.003, (float) $row->estimated_cost_usd, 1e-9);
    }

    public function test_an_organisation_row_with_blank_rates_does_not_hide_the_platform_price(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->rate(null, null, 'USD', 6);

        $row = $this->fire(1000, 500, 6);

        $this->assertEqualsWithDelta(0.0864, (float) $row->estimated_cost, 1e-9, 'the platform default prices the call');
        $this->assertSame('INR', $row->cost_currency, 'and in the platform row\'s currency, not the blank row\'s');
        $this->assertNull($row->estimated_cost_usd);
    }

    public function test_an_organisation_row_with_only_one_rate_is_not_a_price_either(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->rate(5.0, null, 'USD', 6);
        $this->assertEqualsWithDelta(0.0864, (float) $this->fire(1000, 500, 6)->estimated_cost, 1e-9, 'a half-filled input rate');

        DB::table('ai_models')->where('sub_institute_id', 6)->update(['input_cost_per_1k' => null, 'output_cost_per_1k' => 5.0]);
        $this->assertEqualsWithDelta(0.0864, (float) $this->fire(1000, 500, 6)->estimated_cost, 1e-9, 'a half-filled output rate');
    }

    public function test_another_organisations_row_never_prices_this_organisations_call(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->rate(9.0, 9.0, 'USD', 7);

        $row = $this->fire(1000, 500, 6);

        $this->assertEqualsWithDelta(0.0864, (float) $row->estimated_cost, 1e-9);
        $this->assertSame('INR', $row->cost_currency);
        $this->assertSame('INR', $this->fire(1000, 500, '6')->cost_currency, 'an organisation id given as text finds the same rows');
        $this->assertSame('INR', $this->fire(1000, 500, null)->cost_currency, 'no organisation at all gets the platform price');
    }

    public function test_a_free_model_priced_at_zero_is_free_not_unknown(): void
    {
        $this->rate(0.0, 0.0, 'INR');

        $row = $this->fire(5000, 5000);

        $this->assertNotNull($row->estimated_cost);
        $this->assertEquals(0, $row->estimated_cost);
        $this->assertSame('INR', $row->cost_currency);
    }

    // ── unknown stays unknown ────────────────────────────────────────────────

    public function test_no_row_or_a_blank_rate_leaves_the_cost_null_never_zero(): void
    {
        $none = $this->fire(1000, 500);
        $this->assertNull($none->estimated_cost, 'no row for the model');
        $this->assertNull($none->cost_currency);
        $this->assertNull($none->estimated_cost_usd);

        $this->rate(0.0288, null, 'INR');
        $half = $this->fire(1000, 500);
        $this->assertNull($half->estimated_cost, 'one blank rate');
        $this->assertNull($half->cost_currency);
        $this->assertNull($half->estimated_cost_usd);

        $this->rate(0.0288, 0.1152, 'INR', null, 'other-model');
        $this->assertNull($this->fire(1000, 500)->estimated_cost, 'a price for a different model is not this model\'s price');

        $unnamed = $this->fire(1000, 500, 6, [], null);
        $this->assertNull($unnamed->estimated_cost, 'a call with no model name');
        $this->assertSame(1000, (int) $unnamed->input_tokens, 'the tokens are recorded regardless');
    }

    public function test_a_rate_in_an_unreadable_currency_is_an_unknown_price_not_a_dollar_one(): void
    {
        $id = $this->rate(0.0288, 0.1152, 'INR');

        foreach (['', 'RS', 'RUPEES'] as $bad) {
            DB::table('ai_models')->where('id', $id)->update(['currency' => $bad]);
            $row = $this->fire();
            $this->assertNull($row->estimated_cost, "currency '{$bad}'");
            $this->assertNull($row->estimated_cost_usd, "currency '{$bad}'");
        }

        DB::table('ai_models')->where('id', $id)->update(['currency' => ' inr ']);
        $this->assertSame('INR', $this->fire()->cost_currency, 'case and padding are forgiven');
    }

    public function test_a_refused_call_costs_nothing_and_that_nothing_is_a_real_zero_where_a_price_exists(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');

        $refused = $this->fire(0, 0, 6, ['outcome' => AiUsageMeter::OUTCOME_REFUSED, 'error' => 'quota spent']);

        $this->assertSame('refused', $refused->outcome);
        $this->assertNotNull($refused->estimated_cost, 'a refusal is a known cost of zero, as before');
        $this->assertEquals(0, $refused->estimated_cost);
        $this->assertSame('INR', $refused->cost_currency);
        $this->assertNull($refused->estimated_cost_usd);

        DB::table('ai_models')->delete();
        $this->rate(0.0003, 0.0012, 'USD');
        $usd = $this->fire(0, 0, 6, ['outcome' => AiUsageMeter::OUTCOME_REFUSED]);
        $this->assertEquals(0, $usd->estimated_cost_usd, 'zero in the dollar column too, as before');

        DB::table('ai_models')->delete();
        $unpriced = $this->fire(0, 0, 6, ['outcome' => AiUsageMeter::OUTCOME_REFUSED]);
        $this->assertNull($unpriced->estimated_cost, 'with no price at all even a refusal stays unpriced, as before');
    }

    // ── a host that has not run the migrations ───────────────────────────────

    /** @return list<string> */
    private function captureInsertColumns(callable $do): array
    {
        $inserts = [];
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into "ai_usage_events"')) {
                preg_match('/\(([^)]*)\)\s*values/i', $query->sql, $m);
                $inserts[] = array_map(fn ($c) => trim($c, ' "'), explode(',', $m[1]));
            }
        });
        $do();

        $this->assertCount(1, $inserts);

        return $inserts[0];
    }

    public function test_with_neither_migration_run_the_insert_is_exactly_todays_and_a_rate_is_read_as_dollars(): void
    {
        $this->migration(self::EVENTS)->down();
        $this->migration(self::MODELS)->down();
        $this->meter()->forgetColumns();
        DB::table('ai_models')->insert([
            'provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'x', 'input_cost_per_1k' => 0.0003, 'output_cost_per_1k' => 0.0012,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $columns = $this->captureInsertColumns(fn () => $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 2000, 1000, 50));

        $this->assertSame(self::TODAYS_INSERT, $columns, 'not one column more than the host has');
        $row = DB::table('ai_usage_events')->first();
        $this->assertEqualsWithDelta(0.0018, (float) $row->estimated_cost_usd, 1e-9, 'with no currency column a rate is dollars, exactly as today');
    }

    public function test_with_neither_migration_run_an_unpriced_call_is_still_recorded_unpriced(): void
    {
        $this->migration(self::EVENTS)->down();
        $this->migration(self::MODELS)->down();
        $this->meter()->forgetColumns();

        $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 10, 5, 50);

        $row = DB::table('ai_usage_events')->first();
        $this->assertNotNull($row, 'the row is written');
        $this->assertNull($row->estimated_cost_usd);
    }

    public function test_rupee_rates_on_a_host_whose_usage_table_has_no_cost_columns_are_recorded_unpriced_not_as_dollars(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->migration(self::EVENTS)->down();
        $this->meter()->forgetColumns();

        $columns = $this->captureInsertColumns(fn () => $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 1000, 500, 50));

        $this->assertSame(self::TODAYS_INSERT, $columns);
        $row = DB::table('ai_usage_events')->first();
        $this->assertNull($row->estimated_cost_usd, 'nowhere to put rupees, and they must not become dollars');
        $this->assertSame(1000, (int) $row->input_tokens);
    }

    public function test_a_usage_table_with_the_cost_columns_but_a_rate_card_without_a_currency_prices_in_dollars(): void
    {
        $this->migration(self::MODELS)->down();
        $this->meter()->forgetColumns();
        DB::table('ai_models')->insert([
            'provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'x', 'input_cost_per_1k' => 0.0003, 'output_cost_per_1k' => 0.0012,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->fire(2000, 1000);

        $this->assertSame('USD', $row->cost_currency);
        $this->assertEqualsWithDelta(0.0018, (float) $row->estimated_cost, 1e-9);
        $this->assertEqualsWithDelta(0.0018, (float) $row->estimated_cost_usd, 1e-9);
    }

    // ── it never costs the caller its answer ─────────────────────────────────

    public function test_a_rate_card_that_cannot_be_read_costs_the_row_its_price_not_its_existence(): void
    {
        Schema::drop('ai_models');
        Schema::create('ai_models', function ($t) {   // a rate card missing the rate columns: the pricing query cannot run
            $t->id();
            $t->string('provider');
            $t->string('model_id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        DB::table('ai_models')->insert(['provider' => 'deepseek', 'model_id' => 'deepseek-flash']);
        $this->meter()->forgetColumns();
        Log::spy();

        $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 100, 20, 50);

        $row = DB::table('ai_usage_events')->first();
        $this->assertNotNull($row, 'the tokens are still recorded');
        $this->assertSame([100, 20], [(int) $row->input_tokens, (int) $row->output_tokens]);
        $this->assertNull($row->estimated_cost);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'could not price'))->once();
    }

    public function test_an_insert_that_fails_is_logged_and_never_thrown(): void
    {
        Schema::drop('ai_usage_events');
        Schema::create('ai_usage_events', function ($t) {   // a usage table the insert cannot satisfy
            $t->id();
            $t->string('required_and_unknown');
        });
        $this->meter()->forgetColumns();
        Log::spy();

        $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 100, 20, 50);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'could not record'))->once();
        $this->assertSame(0, DB::table('ai_usage_events')->count());
    }

    public function test_without_the_usage_table_the_meter_returns_quietly(): void
    {
        Schema::drop('ai_usage_events');

        $this->meter()->record('signals', $this->withModel('deepseek-flash'), 6, 100, 20, 50);

        $this->assertFalse(Schema::hasTable('ai_usage_events'));
    }

    // ── the per-process column cache ─────────────────────────────────────────

    /** The number of queries that read a table's column list (on sqlite: the table_info pragma). */
    private function columnListingQueries(callable $do): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains(strtolower($query->sql), 'table_xinfo') || str_contains(strtolower($query->sql), 'table_info')) {
                $count++;
            }
        });
        $do();

        return $count;
    }

    public function test_the_column_checks_are_made_once_not_once_per_call(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->meter()->forgetColumns();

        $first = $this->columnListingQueries(fn () => $this->fire());
        $this->assertGreaterThan(0, $first, 'the first call does look at the schema (otherwise this test measures nothing)');

        $later = $this->columnListingQueries(function () {
            for ($i = 0; $i < 5; $i++) {
                $this->fire();
            }
        });

        $this->assertSame(0, $later, 'five more calls ask the database nothing about its columns');
        $this->assertSame(6, DB::table('ai_usage_events')->whereNotNull('estimated_cost')->count());
    }

    public function test_a_column_that_was_missing_is_noticed_after_a_minute_but_not_before(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->migration(self::EVENTS)->down();
        $this->meter()->forgetColumns();
        $this->fire();   // remembers that the cost columns are missing

        $this->migration(self::EVENTS)->up();   // a migration run under a live worker
        $soon = $this->fire();
        $this->assertNull($soon->estimated_cost, 'inside the minute the answer is the remembered one (nothing was asked of the database)');

        $cache = $this->app->make(self::COLUMNS_KEY);
        foreach ($cache->getArrayCopy() as $table => $entry) {
            $entry['read_at'] = time() - 120;
            $cache[$table] = $entry;
        }
        $later = $this->fire();

        $this->assertEqualsWithDelta(0.0864, (float) $later->estimated_cost, 1e-9, 'a minute on, the new columns are used');
        $this->assertSame('INR', $later->cost_currency);
    }

    public function test_forgetting_the_columns_makes_the_meter_look_again_at_once(): void
    {
        $this->rate(0.0288, 0.1152, 'INR');
        $this->migration(self::EVENTS)->down();
        $this->meter()->forgetColumns();
        $this->fire();

        $this->migration(self::EVENTS)->up();
        $this->meter()->forgetColumns();

        $this->assertSame('INR', $this->fire()->cost_currency);
    }
}
