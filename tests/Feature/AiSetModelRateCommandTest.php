<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S2 add-on "AI prices in rupees": `ai:set-model-rate`, the developer company's way to price the platform's models
 * (Docs/agentic-crm/slices/S02-pricing-contract.md). The catalogue table comes from the real migrations on the in-memory sqlite database; the
 * command is run through Artisan. Every case that must NOT write is checked by comparing the whole table before and after.
 */
class AiSetModelRateCommandTest extends TestCase
{
    private const MODELS = '2026_12_08_100000_add_currency_to_ai_models';

    private const SECRET = 'sk-NOT-FOR-PRINTING-0123456789abcdef';

    private int $platform;

    private int $org6;

    private int $org7;

    private int $filled;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests write to ai_models: never let them near anything but the in-memory database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['2026_09_19_120000_create_ai_intelligence_tables', self::MODELS] as $m) {
            $this->migration($m)->up();
        }
        DB::table('ai_models')->delete();   // the table migration seeds the configured models

        $this->platform = $this->model();
        $this->org6 = $this->model(['sub_institute_id' => 6]);
        $this->org7 = $this->model(['sub_institute_id' => 7]);
        $this->filled = $this->model(['model_id' => 'filled-model', 'input_cost_per_1k' => 0.5, 'output_cost_per_1k' => 1.0, 'currency' => 'INR']);
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function model(array $o = []): int
    {
        return (int) DB::table('ai_models')->insertGetId($o + [
            'provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'DeepSeek Flash', 'sub_institute_id' => null, 'currency' => 'INR',
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
    }

    /** @return list<array<string, mixed>> the whole table, for "nothing changed" */
    private function table(): array
    {
        return DB::table('ai_models')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function row(int $id): object
    {
        return DB::table('ai_models')->where('id', $id)->first();
    }

    /** @return array{0:int, 1:string} exit code and everything the command printed */
    private function run_(array $args): array
    {
        $code = Artisan::call('ai:set-model-rate', $args);

        return [$code, Artisan::output()];
    }

    private function args(array $o = []): array
    {
        return $o + ['--provider' => 'deepseek', '--model' => 'deepseek-flash', '--input' => '28.80', '--output' => '115.20'];
    }

    // ── dry run and apply ────────────────────────────────────────────────────

    public function test_a_dry_run_writes_nothing_and_shows_before_and_after(): void
    {
        $before = $this->table();

        [$code, $out] = $this->run_($this->args());

        $this->assertSame(0, $code);
        $this->assertSame($before, $this->table(), 'not one cell of the table changed');
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('Before: input -, output - per 1,000 tokens, INR', $out, 'an empty price reads as a dash, never as 0');
        $this->assertStringContainsString('After:  input 0.0288, output 0.1152 per 1,000 tokens, INR', $out);
        $this->assertStringContainsString('nothing was written', $out);
        $this->assertStringContainsString('platform', $out);
    }

    public function test_apply_writes_the_platform_row_and_prints_what_is_stored(): void
    {
        $others = [$this->row($this->org6), $this->row($this->org7), $this->row($this->filled)];

        [$code, $out] = $this->run_($this->args(['--apply' => true]));

        $this->assertSame(0, $code);
        $row = $this->row($this->platform);
        $this->assertEqualsWithDelta(0.0288, (float) $row->input_cost_per_1k, 1e-9);
        $this->assertEqualsWithDelta(0.1152, (float) $row->output_cost_per_1k, 1e-9);
        $this->assertSame('INR', $row->currency);
        $this->assertStringContainsString('APPLY', $out);
        $this->assertStringContainsString('Stored: input 0.0288, output 0.1152 per 1,000 tokens, INR', $out);
        $this->assertEquals($others, [$this->row($this->org6), $this->row($this->org7), $this->row($this->filled)], 'no other row was touched');
    }

    public function test_running_it_again_changes_nothing_not_even_the_updated_stamp(): void
    {
        $this->run_($this->args(['--apply' => true]));
        $after = $this->table();

        [$code, $out] = $this->run_($this->args(['--apply' => true]));

        $this->assertSame(0, $code);
        $this->assertSame($after, $this->table());
        $this->assertStringContainsString('already stored', $out);
    }

    public function test_prices_per_million_are_stored_per_thousand(): void
    {
        $this->run_($this->args(['--input' => '28.8', '--output' => '115.2', '--apply' => true]));
        $row = $this->row($this->platform);
        $this->assertEqualsWithDelta(0.0288, (float) $row->input_cost_per_1k, 1e-12, '28.8 per million is 0.0288 per thousand');
        $this->assertEqualsWithDelta(0.1152, (float) $row->output_cost_per_1k, 1e-12);

        $this->run_($this->args(['--input' => '0.30', '--output' => '1.20', '--currency' => 'USD', '--overwrite' => true, '--apply' => true]));
        $row = $this->row($this->platform);
        $this->assertEqualsWithDelta(0.0003, (float) $row->input_cost_per_1k, 1e-12);
        $this->assertEqualsWithDelta(0.0012, (float) $row->output_cost_per_1k, 1e-12);
        $this->assertSame('USD', $row->currency);

        $this->run_($this->args(['--input' => '0.0288', '--output' => '0.1152', '--per' => '1000', '--currency' => 'INR', '--overwrite' => true, '--apply' => true]));
        $row = $this->row($this->platform);
        $this->assertEqualsWithDelta(0.0288, (float) $row->input_cost_per_1k, 1e-12, '--per=1000 is already per thousand');
        $this->assertEqualsWithDelta(0.1152, (float) $row->output_cost_per_1k, 1e-12);
        $this->assertSame('INR', $row->currency);
    }

    public function test_a_free_model_can_be_priced_at_zero(): void
    {
        [$code] = $this->run_($this->args(['--input' => '0', '--output' => '0', '--apply' => true]));

        $this->assertSame(0, $code);
        $row = $this->row($this->platform);
        $this->assertNotNull($row->input_cost_per_1k);
        $this->assertEquals(0, $row->input_cost_per_1k);
    }

    // ── it never creates a row ───────────────────────────────────────────────

    public function test_a_model_that_is_not_in_the_catalogue_is_a_clear_error_and_no_row_is_created(): void
    {
        $before = $this->table();

        [$code, $out] = $this->run_($this->args(['--model' => 'no-such-model', '--apply' => true]));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no deepseek / no-such-model in the platform catalogue', $out);
        $this->assertStringContainsString('Model Management', $out, 'it says what to do instead');

        [$code, $out] = $this->run_($this->args(['--provider' => 'nobody', '--apply' => true]));
        $this->assertSame(1, $code);

        [$code, $out] = $this->run_($this->args(['--tenant' => '99', '--apply' => true]));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('organisation 99', $out);

        $this->assertSame($before, $this->table(), 'no row created, none changed');
    }

    public function test_two_rows_for_the_same_model_in_one_scope_is_an_error_not_a_guess(): void
    {
        $this->model();   // a second platform deepseek-flash
        $before = $this->table();

        [$code, $out] = $this->run_($this->args(['--apply' => true]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('more than once', $out);
        $this->assertSame($before, $this->table());
    }

    // ── it does not replace a price without --overwrite ──────────────────────

    public function test_a_price_that_is_already_filled_is_not_replaced_without_overwrite(): void
    {
        $before = $this->table();

        [$code, $out] = $this->run_($this->args(['--model' => 'filled-model', '--apply' => true]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('already hold a price', $out);
        $this->assertStringContainsString('--overwrite', $out);
        $this->assertSame($before, $this->table());

        [$code] = $this->run_($this->args(['--model' => 'filled-model']));
        $this->assertSame(1, $code, 'a dry run predicts the refusal too');
    }

    public function test_overwrite_replaces_a_filled_price(): void
    {
        [$code] = $this->run_($this->args(['--model' => 'filled-model', '--overwrite' => true, '--apply' => true]));

        $this->assertSame(0, $code);
        $row = $this->row($this->filled);
        $this->assertEqualsWithDelta(0.0288, (float) $row->input_cost_per_1k, 1e-9);
        $this->assertEqualsWithDelta(0.1152, (float) $row->output_cost_per_1k, 1e-9);
    }

    public function test_changing_only_the_currency_of_a_filled_price_also_needs_overwrite(): void
    {
        $before = $this->table();

        [$code] = $this->run_($this->args(['--model' => 'filled-model', '--input' => '500', '--output' => '1000', '--currency' => 'USD', '--apply' => true]));

        $this->assertSame(1, $code, 'the same numbers in another currency are a different price');
        $this->assertSame($before, $this->table());
    }

    public function test_an_empty_half_can_be_filled_without_overwrite_and_the_filled_half_is_kept_when_equal(): void
    {
        DB::table('ai_models')->where('id', $this->platform)->update(['input_cost_per_1k' => 0.0288]);

        [$code] = $this->run_($this->args(['--apply' => true]));

        $this->assertSame(0, $code);
        $this->assertEqualsWithDelta(0.1152, (float) $this->row($this->platform)->output_cost_per_1k, 1e-9);
    }

    // ── --tenant ─────────────────────────────────────────────────────────────

    public function test_tenant_sets_that_organisations_row_and_never_the_platform_row(): void
    {
        $platform = $this->row($this->platform);
        $other = $this->row($this->org7);

        [$code, $out] = $this->run_($this->args(['--tenant' => '6', '--apply' => true]));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('organisation 6', $out);
        $row = $this->row($this->org6);
        $this->assertEqualsWithDelta(0.0288, (float) $row->input_cost_per_1k, 1e-9);
        $this->assertSame('INR', $row->currency);
        $this->assertEquals($platform, $this->row($this->platform), 'the shared row is untouched');
        $this->assertEquals($other, $this->row($this->org7), 'and so is every other organisation');
    }

    public function test_a_tenant_that_has_no_row_of_its_own_does_not_fall_back_to_the_platform_row(): void
    {
        DB::table('ai_models')->where('id', $this->org6)->delete();
        $before = $this->table();

        [$code] = $this->run_($this->args(['--tenant' => '6', '--overwrite' => true, '--apply' => true]));

        $this->assertSame(1, $code);
        $this->assertSame($before, $this->table());
    }

    // ── bad requests change nothing ──────────────────────────────────────────

    public function test_unusable_options_are_refused_with_exit_code_2_and_nothing_is_written(): void
    {
        $before = $this->table();

        foreach ([
            ['--tenant' => 'abc'], ['--tenant' => '0'], ['--tenant' => '-3'], ['--tenant' => '6x'],   // an unreadable tenant must not become "the platform"
            ['--currency' => 'EUR'], ['--currency' => 'RS'],
            ['--per' => '500'], ['--per' => 'abc'],
            ['--input' => '-1'], ['--output' => 'abc'], ['--input' => 'INF'], ['--input' => '1e999'], ['--input' => ''],
            ['--input' => '0.0000001'],      // rounds to zero per thousand: would be recorded as free
            ['--input' => '99999999999'],    // does not fit the column
            ['--provider' => ''], ['--model' => ''],
            ['--database' => 'no_such_connection'],
        ] as $bad) {
            [$code, $out] = $this->run_($this->args($bad + ['--apply' => true]));
            $this->assertSame(2, $code, json_encode($bad) . ': ' . $out);
        }

        [$code] = $this->run_(['--provider' => 'deepseek', '--model' => 'deepseek-flash', '--apply' => true]);
        $this->assertSame(2, $code, 'prices are required');

        $this->assertSame($before, $this->table());
    }

    public function test_a_lower_case_currency_is_accepted(): void
    {
        [$code] = $this->run_($this->args(['--currency' => 'usd', '--apply' => true]));

        $this->assertSame(0, $code);
        $this->assertSame('USD', $this->row($this->platform)->currency);
    }

    public function test_without_the_currency_column_the_command_refuses_rather_than_record_rupees_as_dollars(): void
    {
        $this->migration(self::MODELS)->down();
        $before = $this->table();

        [$code, $out] = $this->run_($this->args(['--apply' => true]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2026_12_08_100000_add_currency_to_ai_models', $out);
        $this->assertSame($before, $this->table());
    }

    // ── connection and secrets ───────────────────────────────────────────────

    public function test_a_named_connection_is_used_and_the_default_is_restored(): void
    {
        $default = DB::getDefaultConnection();

        [$code, $out] = $this->run_($this->args(['--database' => 'sqlite', '--apply' => true]));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('on connection sqlite', $out);
        $this->assertSame($default, DB::getDefaultConnection());
    }

    public function test_nothing_secret_is_ever_printed(): void
    {
        DB::table('ai_api_keys')->insert(['api_type' => 'deepseek', 'api_key' => self::SECRET, 'status' => 1, 'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        $outputs = [];
        foreach ([$this->args(['--apply' => true]), $this->args(['--model' => 'nope']), $this->args(['--currency' => 'XXX']), $this->args(['--database' => 'no_such_connection'])] as $args) {
            $outputs[] = $this->run_($args)[1];
        }

        $all = implode("\n", $outputs);
        $this->assertStringNotContainsString(self::SECRET, $all);
        $this->assertStringNotContainsString(substr(self::SECRET, 0, 12), $all);
        $this->assertStringNotContainsString((string) config('database.connections.sqlite.database'), $all, 'no connection detail beyond its name');
        $this->assertSame([self::SECRET], DB::table('ai_api_keys')->pluck('api_key')->all(), 'the credential table was not touched');
    }
}
