<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The temporary demonstration copy of the local 13 and 14 Oct 2026 signals: a dry run writes nothing, import is guarded, each date shows only its own
 * signals through the normal endpoints, other products and organisations see nothing, and rollback removes exactly what import added.
 */
class SignalsDemoFixtureTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;
    private const FILE = 'database/fixtures/demo_signals_2026-10-13_14.json';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });

        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources', '2026_10_13_100000_create_g2g_target_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-16 10:00:00');
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink(storage_path('app/demo-fixture-manifest-' . self::T . '.json'));
        foreach (glob(storage_path('app/demo-fixture-manifest-*.rolled-back-*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path(self::FILE)), true);
    }

    private function enable(): void
    {
        config(['signals.demo_fixture.enabled' => true]);
    }

    private function import(int $tenant = self::T)
    {
        return $this->artisan('signals:demo-fixture', ['action' => 'import', '--tenant' => $tenant, '--confirm' => 'IMPORT-DEMO-FIXTURE']);
    }

    /** @return list<string> the titles the normal screen endpoint returns for a research date */
    private function shown(string $date, string $product = 'scholarclone', int $tenant = self::T): array
    {
        $rows = $this->getJson("/api/signals/opportunities?product={$product}&run_date={$date}&per_page=50&expired=include", $this->h($tenant))->assertOk()->json('data');

        return collect($rows)->pluck('title')->sort()->values()->all();
    }

    public function test_the_fixture_holds_exactly_the_local_counts_and_is_intact(): void
    {
        $f = $this->fixture();

        $this->assertSame(['2026-10-13' => 11, '2026-10-14' => 5], $f['meta']['signals_per_date']);
        $this->assertCount(16, $f['data']['opportunities']);
        $this->assertSame($f['meta']['sha256'], hash('sha256', json_encode($f['data'])), 'the file is exactly what was exported');
        $on = fn (string $d) => collect($f['meta']['date_of_signal'])->filter(fn ($x) => $x === $d)->keys()->all();
        $this->assertSame([], array_intersect($on('2026-10-13'), $on('2026-10-14')), 'no signal belongs to both days');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('signals:demo-fixture', ['action' => 'plan', '--tenant' => self::T])->assertExitCode(0);

        $this->assertSame(0, DB::table('g2g_company_opportunities')->count());
        $this->assertSame(0, DB::table('g2g_research_runs')->count());
        $this->assertSame(0, DB::table('g2g_companies')->count());
    }

    public function test_import_is_refused_while_switched_off_and_without_the_confirmation(): void
    {
        $this->import()->assertExitCode(1);   // switched off (the default)

        $this->enable();
        $this->artisan('signals:demo-fixture', ['action' => 'import', '--tenant' => self::T])->assertExitCode(1);

        $this->assertSame(0, DB::table('g2g_company_opportunities')->count());
    }

    public function test_each_date_shows_exactly_its_own_signals_through_the_normal_screen_endpoints(): void
    {
        $this->enable();
        $this->import()->assertExitCode(0);
        $f = $this->fixture();

        $expected = fn (string $d) => collect($f['data']['opportunities'])->filter(fn ($o) => $f['meta']['date_of_signal'][$o['id']] === $d)->pluck('title')->sort()->values()->all();

        $this->assertSame($expected('2026-10-13'), $this->shown('2026-10-13'));
        $this->assertCount(11, $this->shown('2026-10-13'));
        $this->assertSame($expected('2026-10-14'), $this->shown('2026-10-14'));
        $this->assertCount(5, $this->shown('2026-10-14'));
        $this->assertSame([], array_intersect($this->shown('2026-10-13'), $this->shown('2026-10-14')), 'the two days never share a signal');
        $this->assertSame([], $this->shown('2026-10-12'), 'another date shows nothing');
        $this->assertSame([], $this->shown('2026-10-15'));

        $dates = collect($this->getJson('/api/signals/research/dates?product=scholarclone', $this->h())->assertOk()->json('data.dates'))->pluck('date')->sort()->values()->all();
        $this->assertSame(['2026-10-13', '2026-10-14'], $dates, 'the normal dropdown offers these two days and no invented ones');
    }

    public function test_the_wording_review_state_and_evidence_are_preserved_exactly(): void
    {
        $this->enable();
        $this->import()->assertExitCode(0);

        foreach ($this->fixture()['data']['opportunities'] as $o) {
            $row = DB::table('g2g_company_opportunities')->where('sub_institute_id', self::T)->where('fingerprint', $o['fingerprint'])->first();
            $this->assertNotNull($row, $o['title']);
            foreach (['title', 'observed_event', 'why_indicates_need', 'recommended_action', 'trigger_summary', 'likely_problem', 'what_we_could_sell', 'soft_marketing_angle', 'sources',
                'source_url', 'source_published_at', 'event_date', 'expires_at', 'qualification', 'review_status', 'priority', 'confidence', 'business_fit', 'score_total', 'first_discovered_at'] as $col) {
                if (property_exists($row, $col)) {
                    $this->assertEquals($o[$col], $row->{$col}, "{$col} of {$o['title']}");
                }
            }
        }
    }

    public function test_another_product_or_organisation_sees_none_of_it(): void
    {
        $this->enable();
        $this->import()->assertExitCode(0);

        $this->assertSame([], $this->shown('2026-10-13', 'enterprise_brain'));
        $this->assertSame([], $this->shown('2026-10-14', 'g2g'));
        $this->assertSame([], $this->shown('2026-10-13', 'scholarclone', self::T2));
    }

    public function test_it_cannot_be_imported_twice(): void
    {
        $this->enable();
        $this->import()->assertExitCode(0);
        $before = DB::table('g2g_company_opportunities')->count();

        $this->import()->assertExitCode(1);   // the manifest exists: roll back first

        $this->assertSame($before, DB::table('g2g_company_opportunities')->count());
    }

    public function test_an_existing_live_signal_with_the_same_fingerprint_is_skipped_not_overwritten(): void
    {
        $this->enable();
        $first = $this->fixture()['data']['opportunities'][0];
        DB::table('g2g_company_opportunities')->insert(array_merge($first, ['id' => 500, 'sub_institute_id' => self::T, 'company_id' => 999, 'research_run_id' => null, 'title' => 'Live original']));

        $this->import()->assertExitCode(0);

        $this->assertSame(16, DB::table('g2g_company_opportunities')->count(), '15 new + the 1 that was already there');
        $this->assertSame('Live original', DB::table('g2g_company_opportunities')->where('fingerprint', $first['fingerprint'])->value('title'));
    }

    public function test_rollback_removes_exactly_what_import_added_and_nothing_else(): void
    {
        $this->enable();
        $live = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Live School', 'normalized_name' => 'live school', 'created_at' => now(), 'updated_at' => now()]);
        $this->import()->assertExitCode(0);
        $this->assertSame(16, DB::table('g2g_company_opportunities')->count());

        $this->artisan('signals:demo-fixture', ['action' => 'rollback', '--tenant' => self::T])->assertExitCode(1);   // needs the confirmation
        $this->artisan('signals:demo-fixture', ['action' => 'rollback', '--tenant' => self::T, '--confirm' => 'ROLLBACK-DEMO-FIXTURE'])->assertExitCode(0);

        $this->assertSame(0, DB::table('g2g_company_opportunities')->count());
        $this->assertSame(0, DB::table('g2g_research_runs')->count());
        $this->assertSame(0, DB::table('g2g_research_sources')->count());
        $this->assertSame(0, DB::table('g2g_opportunity_events')->count());
        $this->assertSame([$live], DB::table('g2g_companies')->pluck('id')->all(), 'only the organisations the import created were removed');
        $this->assertSame([], $this->shown('2026-10-13'));
    }
}
