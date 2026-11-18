<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePlatformRight;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S2 add-on "AI prices in rupees": the model catalogue API carries a currency beside the two rates
 * (Docs/agentic-crm/slices/S02-pricing-contract.md). The request goes through the real AI route stack (token, rate limit, scope); only the
 * platform-right check is switched off, because it reads menu tables that have nothing to do with what is tested. The rule that platform rows
 * are read-only to an organisation lives in the controller, and is tested here against the unchanged behaviour.
 */
class AiModelCurrencyApiTest extends TestCase
{
    private const T = 6;

    private const T2 = 7;

    private const MODELS = '2026_12_08_100000_add_currency_to_ai_models';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests create and drop tables: never let them near anything but the in-memory database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->withoutMiddleware(RequirePlatformRight::class);
        Cache::flush();

        \Illuminate\Support\Facades\Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('is_admin')->default(1);
            $t->integer('status')->default(1);
        });

        foreach (['2026_09_19_120000_create_ai_intelligence_tables', self::MODELS] as $m) {
            $this->migration($m)->up();
        }
        DB::table('ai_models')->delete();   // the table migration seeds the configured models; the tests make their own rows
        $this->platform = $this->model(['model_id' => 'platform-model', 'input_cost_per_1k' => 0.0288, 'output_cost_per_1k' => 0.1152, 'currency' => 'INR']);
    }

    private int $platform;

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function model(array $o = []): int
    {
        return (int) DB::table('ai_models')->insertGetId($o + [
            'provider' => 'deepseek', 'model_id' => 'm' . uniqid(), 'label' => 'A model', 'sub_institute_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function body(array $o = []): array
    {
        return $o + ['provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'DeepSeek Flash'];
    }

    private function row(int $id): object
    {
        return DB::table('ai_models')->where('id', $id)->first();
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_a_model_added_without_a_currency_is_priced_in_rupees(): void
    {
        $response = $this->postJson('/api/ai/configuration-models', $this->body(['input_cost_per_1k' => 0.0288, 'output_cost_per_1k' => 0.1152]), $this->h())->assertCreated();

        $this->assertSame('INR', $response->json('data.model.currency'));
        $this->assertEqualsWithDelta(0.0288, $response->json('data.model.input_cost_per_1k'), 1e-9);
        $row = $this->row((int) $response->json('data.model.id'));
        $this->assertSame('INR', $row->currency);
        $this->assertSame(self::T, (int) $row->sub_institute_id, 'in the organisation of the token');
    }

    public function test_a_currency_from_the_configured_list_is_stored_and_case_is_forgiven(): void
    {
        $dollars = $this->postJson('/api/ai/configuration-models', $this->body(['currency' => 'usd ', 'input_cost_per_1k' => 0.0003, 'output_cost_per_1k' => 0.0012]), $this->h())->assertCreated();

        $this->assertSame('USD', $dollars->json('data.model.currency'));
        $this->assertSame('USD', $this->row((int) $dollars->json('data.model.id'))->currency);
    }

    public function test_a_currency_that_is_not_configured_is_refused_and_nothing_is_saved(): void
    {
        foreach (['EUR', 'RS', 'rupees', 'INR;DROP', 123] as $bad) {
            $this->postJson('/api/ai/configuration-models', $this->body(['currency' => $bad]), $this->h())
                ->assertStatus(422)->assertJsonPath('errors.currency.0', fn ($m) => is_string($m) && $m !== '');
        }

        $this->assertSame(0, DB::table('ai_models')->where('sub_institute_id', self::T)->count());
    }

    public function test_adding_a_currency_to_the_configuration_makes_it_acceptable(): void
    {
        config(['ai.currencies' => ['INR' => 'Rs', 'USD' => '$', 'EUR' => 'EUR']]);

        $this->postJson('/api/ai/configuration-models', $this->body(['currency' => 'EUR']), $this->h())->assertCreated()->assertJsonPath('data.model.currency', 'EUR');
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_an_edit_that_does_not_mention_the_currency_leaves_it_alone(): void
    {
        $id = $this->model(['sub_institute_id' => self::T, 'currency' => 'USD', 'input_cost_per_1k' => 1.0, 'output_cost_per_1k' => 2.0]);

        $this->putJson("/api/ai/configuration-models/{$id}", $this->body(['model_id' => 'x', 'input_cost_per_1k' => 3.0, 'output_cost_per_1k' => 4.0]), $this->h())
            ->assertOk()->assertJsonPath('data.model.currency', 'USD');

        $row = $this->row($id);
        $this->assertSame('USD', $row->currency, 'a client that does not know about currencies cannot change one by accident');
        $this->assertEquals(3.0, $row->input_cost_per_1k);
    }

    public function test_an_edit_can_change_the_currency(): void
    {
        $id = $this->model(['sub_institute_id' => self::T, 'currency' => 'INR', 'input_cost_per_1k' => 1.0, 'output_cost_per_1k' => 2.0]);

        $this->putJson("/api/ai/configuration-models/{$id}", $this->body(['model_id' => 'x', 'currency' => 'USD']), $this->h())
            ->assertOk()->assertJsonPath('data.model.currency', 'USD');

        $this->assertSame('USD', $this->row($id)->currency);
    }

    public function test_an_edit_with_a_bad_currency_changes_nothing(): void
    {
        $id = $this->model(['sub_institute_id' => self::T, 'currency' => 'INR', 'label' => 'Before', 'input_cost_per_1k' => 1.0, 'output_cost_per_1k' => 2.0]);

        $this->putJson("/api/ai/configuration-models/{$id}", $this->body(['model_id' => 'x', 'label' => 'After', 'currency' => 'EUR', 'input_cost_per_1k' => 9.0]), $this->h())
            ->assertStatus(422);

        $row = $this->row($id);
        $this->assertSame(['Before', 'INR'], [$row->label, $row->currency]);
        $this->assertEquals(1.0, $row->input_cost_per_1k);
    }

    // ── who may edit which row ───────────────────────────────────────────────

    public function test_a_platform_row_stays_read_only_to_an_organisation_whatever_the_currency(): void
    {
        $before = (array) $this->row($this->platform);

        $this->putJson("/api/ai/configuration-models/{$this->platform}", $this->body(['model_id' => 'platform-model', 'currency' => 'USD', 'input_cost_per_1k' => 99, 'output_cost_per_1k' => 99]), $this->h())
            ->assertStatus(403);

        $this->assertEquals($before, (array) $this->row($this->platform), 'nothing about the shared row changed');
    }

    public function test_another_organisations_row_cannot_be_edited_and_is_not_listed(): void
    {
        $theirs = $this->model(['sub_institute_id' => self::T2, 'model_id' => 'theirs', 'currency' => 'INR', 'input_cost_per_1k' => 1.0, 'output_cost_per_1k' => 2.0]);
        $before = (array) $this->row($theirs);

        $this->putJson("/api/ai/configuration-models/{$theirs}", $this->body(['model_id' => 'theirs', 'currency' => 'USD', 'input_cost_per_1k' => 50]), $this->h())->assertStatus(404);
        $this->assertEquals($before, (array) $this->row($theirs));

        $listed = collect($this->getJson('/api/ai/configuration-models', $this->h())->assertOk()->json('data.models.deepseek'))->pluck('model_id')->all();
        $this->assertNotContains('theirs', $listed);
        $this->assertContains('platform-model', $listed);
    }

    // ── what the screens read ────────────────────────────────────────────────

    public function test_the_catalogue_returns_each_rows_currency_and_the_list_of_currencies(): void
    {
        $this->model(['sub_institute_id' => self::T, 'model_id' => 'own', 'currency' => 'USD', 'input_cost_per_1k' => 0.5, 'output_cost_per_1k' => 1.5]);

        foreach (['/api/ai/configuration-models', '/api/ai/configuration/options'] as $url) {
            $data = $this->getJson($url, $this->h())->assertOk()->json('data');

            $rows = collect($data['models']['deepseek'])->keyBy('model_id');
            $this->assertSame('INR', $rows['platform-model']['currency']);
            $this->assertSame('platform', $rows['platform-model']['scope']);
            $this->assertEqualsWithDelta(0.1152, $rows['platform-model']['output_cost_per_1k'], 1e-9, 'the platform rate is shown, read-only');
            $this->assertSame('USD', $rows['own']['currency']);
            $this->assertSame('institute', $rows['own']['scope']);
            $this->assertSame('INR', $data['default_currency']);
            $codes = collect($data['currencies'])->pluck('symbol', 'code')->all();
            $this->assertSame("\u{20B9}", $codes['INR']);
            $this->assertSame('$', $codes['USD']);
        }
    }

    // ── a deployment that has not run the currency migration ─────────────────

    public function test_before_the_currency_migration_rows_read_as_dollars_and_saving_still_works_but_a_rupee_price_is_refused(): void
    {
        $this->migration(self::MODELS)->down();

        $rows = collect($this->getJson('/api/ai/configuration-models', $this->h())->assertOk()->json('data.models.deepseek'))->keyBy('model_id');
        $this->assertSame('USD', $rows['platform-model']['currency'], 'a rate with no currency column is dollars, as the meter reads it');

        $created = $this->postJson('/api/ai/configuration-models', $this->body(['input_cost_per_1k' => 0.0003, 'output_cost_per_1k' => 0.0012]), $this->h())->assertCreated();
        $this->assertSame('USD', $created->json('data.model.currency'));

        $this->postJson('/api/ai/configuration-models', $this->body(['model_id' => 'other', 'currency' => 'USD']), $this->h())->assertCreated();

        $this->postJson('/api/ai/configuration-models', $this->body(['model_id' => 'rupee', 'currency' => 'INR', 'input_cost_per_1k' => 28.8]), $this->h())
            ->assertStatus(422)->assertJsonPath('errors.currency.0', fn ($m) => is_string($m) && $m !== '');
        $this->assertSame(0, DB::table('ai_models')->where('model_id', 'rupee')->count(), 'a rupee price would have been read as dollars');
    }
}
