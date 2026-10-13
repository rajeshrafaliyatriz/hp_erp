<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileVersion;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Organisations own their ICPs as data: who may edit them, tenant isolation, validation, versioning, activation,
 * preview, and research that follows an organisation's OWN ICP (a hospital here, not a school). All web and AI
 * traffic is mocked; runs on the in-memory sqlite database.
 */
class IcpProfileApiTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_13_100000_create_g2g_target_accounts_table',
            '2026_10_14_100000_create_g2g_icp_profiles_tables',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 10:00:00');
        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [],
        ]);
        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function hospital(array $over = []): array
    {
        return $over + [
            'label' => 'Staff scheduling for hospitals', 'product_line' => 'rostering', 'industry_label' => 'hospitals',
            'buyer_types' => ['hospital'], 'regions' => ['Gujarat'], 'parts' => ['rostering' => 'staff rostering and attendance'],
            'must_have' => [['attribute' => 'beds', 'op' => '>=', 'value' => 100, 'label' => 'under 100 beds', 'prompt' => 'fewer than 100 beds']],
            'disqualifiers' => [['label' => 'veterinary clinic', 'phrases' => ['veterinary', 'pet hospital']]],
            'criteria' => [['key' => 'beds', 'label' => 'Beds', 'max' => 20, 'rules' => [['range' => [300, 9999], 'points' => 20], ['range' => [100, 299], 'points' => 10]]]],
            'evidence_points' => ['active_triggers' => 20],
            'triggers' => ['new_wing' => ['source' => 'news', 'part' => 'rostering', 'decay' => 90, 'pain' => 'a new wing needs rosters', 'phrases' => ['new wing']]],
            'queries' => [['id' => 'h-job-nurse', 'source' => 'job', 'topic' => 'general', 'days' => 60, 'q' => 'hospital recruitment "staff nurse" Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 1], 'account_query' => '"{name}" {city} (hiring OR "new wing")',
            'account_days' => 30, 'account_recheck_days' => 7,
        ];
    }

    private function profile(string $key = 'hospitals', string $status = 'draft', int $tenant = self::T1, array $config = []): IcpProfile
    {
        $config = $this->hospital($config);

        return IcpProfile::create([
            'sub_institute_id' => $tenant, 'key' => $key, 'name' => ucfirst($key), 'product_line' => $config['product_line'] ?? null,
            'status' => $status, 'is_default' => false, 'version' => 1, 'config' => $config,
        ]);
    }

    private function productProfile(): void
    {
        ProductProfile::create([
            'sub_institute_id' => self::T1, 'product_name' => 'Rostering', 'description' => 'Rosters.', 'problems_solved' => 'x', 'features' => 'y',
            'target_industries' => ['Healthcare'], 'target_markets' => ['Gujarat'], 'keywords' => ['rostering'], 'excluded' => [],
            'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    private function fakeWeb(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => []], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => []])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
        ]);
    }

    /** @return list<string> */
    private function searched(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), 'api.tavily.com'))
            ->map(fn (Request $r) => $r->data()['query'])->values()->all();
    }

    // ── who can use it ───────────────────────────────────────────────────────

    public function test_only_an_administrator_can_use_the_icp_api(): void
    {
        $this->getJson('/api/signals/icp/profiles', $this->h(self::T1, 2))->assertStatus(403);
        $this->postJson('/api/signals/icp/profiles', ['key' => 'x', 'name' => 'x', 'template' => 'scholar_k12'], $this->h(self::T1, 2))->assertStatus(403);
        $this->getJson('/api/signals/icp/profiles')->assertStatus(401);
        $this->getJson('/api/signals/icp/profiles', $this->h())->assertOk();
    }

    public function test_the_templates_an_organisation_can_start_from_are_listed(): void
    {
        $r = $this->getJson('/api/signals/icp/templates', $this->h())->assertOk();

        $this->assertContains('scholar_k12', array_column($r->json('data'), 'key'));
    }

    // ── create, validate, edit ───────────────────────────────────────────────

    public function test_an_icp_is_created_from_a_template_as_a_draft_and_the_template_itself_stays_untouched(): void
    {
        $r = $this->postJson('/api/signals/icp/profiles', ['key' => 'scholar-k12', 'name' => 'Scholar K-12', 'template' => 'scholar_k12'], $this->h())->assertCreated();

        $this->assertSame('draft', $r->json('data.status'));
        $this->assertSame(1, $r->json('data.version'));
        $this->assertSame('private K-12 schools and school groups', $r->json('data.config.industry_label'));
        $this->assertSame(1, IcpProfileVersion::count());

        $this->postJson('/api/signals/icp/profiles', ['key' => 'scholar-k12', 'name' => 'Again', 'template' => 'scholar_k12'], $this->h())->assertStatus(422);
        $this->postJson('/api/signals/icp/profiles', ['key' => 'Bad Key!', 'name' => 'x', 'template' => 'scholar_k12'], $this->h())->assertStatus(422);
    }

    public function test_an_organisation_can_create_a_completely_different_icp_from_its_own_configuration(): void
    {
        $r = $this->postJson('/api/signals/icp/profiles', ['key' => 'hospitals', 'name' => 'Hospitals', 'config' => $this->hospital()], $this->h())->assertCreated();

        $this->assertSame('rostering', $r->json('data.product_line'));
    }

    public function test_an_invalid_icp_is_refused_with_the_problems_listed(): void
    {
        $bad = $this->hospital(['disqualifiers' => [['label' => 'x', 'phrases' => ['(a+)+$']]], 'queries' => [['id' => 'q', 'source' => 'news', 'topic' => 'images', 'days' => 30, 'q' => 'ok query']]]);

        $r = $this->postJson('/api/signals/icp/profiles', ['key' => 'bad', 'name' => 'Bad', 'config' => $bad], $this->h())->assertStatus(422);

        $this->assertStringContainsString('pattern characters', implode(' ', $r->json('problems')));
        $this->assertStringContainsString('news or general', implode(' ', $r->json('problems')));
        $this->assertSame(0, IcpProfile::count());

        $v = $this->postJson('/api/signals/icp/validate', ['config' => $this->hospital()], $this->h())->assertOk();
        $this->assertTrue($v->json('data.valid'));
        $this->assertFalse($this->postJson('/api/signals/icp/validate', ['config' => $bad], $this->h())->json('data.valid'));
    }

    public function test_every_edit_keeps_the_previous_version_and_a_rename_does_not_create_one(): void
    {
        $h = $this->h();
        $id = $this->postJson('/api/signals/icp/profiles', ['key' => 'hospitals', 'name' => 'Hospitals', 'config' => $this->hospital()], $h)->json('data.id');

        $edited = $this->hospital(['guidance' => 'Prefer teaching hospitals.']);
        $this->putJson("/api/signals/icp/profiles/{$id}", ['config' => $edited, 'note' => 'added guidance'], $h)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/signals/icp/profiles/{$id}", ['name' => 'Hospitals (Gujarat)'], $h)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/signals/icp/profiles/{$id}", ['config' => $edited], $h)->assertOk()->assertJsonPath('data.version', 2); // unchanged config: no new version

        $show = $this->getJson("/api/signals/icp/profiles/{$id}", $h)->assertOk();
        $this->assertSame([2, 1], array_column($show->json('data.versions'), 'version'));
        $this->assertSame('added guidance', $show->json('data.versions.0.note'));
        $this->assertSame('Hospitals (Gujarat)', $show->json('data.name'));

        $this->putJson("/api/signals/icp/profiles/{$id}", ['config' => $this->hospital(['per_run' => ['catalogue' => 9, 'accounts' => 9]])], $h)->assertStatus(422);
        $this->assertSame(2, IcpProfile::find($id)->version, 'a refused edit changes nothing');
    }

    // ── isolation ────────────────────────────────────────────────────────────

    public function test_another_organisations_icp_is_answered_exactly_like_a_missing_one(): void
    {
        $theirs = $this->profile('theirs', 'active', self::T1);
        $other = $this->h(self::T2);

        $this->getJson("/api/signals/icp/profiles/{$theirs->id}", $other)->assertStatus(404);
        $this->putJson("/api/signals/icp/profiles/{$theirs->id}", ['name' => 'hijack'], $other)->assertStatus(404);
        $this->patchJson("/api/signals/icp/profiles/{$theirs->id}/status", ['status' => 'archived'], $other)->assertStatus(404);
        $this->getJson("/api/signals/icp/profiles/{$theirs->id}/preview", $other)->assertStatus(404);
        $this->getJson("/api/signals/icp/profiles/{$theirs->id}/accounts", $other)->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/signals/icp/profiles', $other)->json('data'));
        $this->assertSame('Theirs', IcpProfile::find($theirs->id)->name);
        $this->assertSame('active', IcpProfile::find($theirs->id)->status);
    }

    // ── activation ───────────────────────────────────────────────────────────

    public function test_activation_makes_one_default_and_an_archived_icp_is_never_default(): void
    {
        $h = $this->h();
        $a = $this->profile('a');
        $b = $this->profile('b');

        $this->patchJson("/api/signals/icp/profiles/{$a->id}/status", ['status' => 'active', 'is_default' => true], $h)->assertOk()->assertJsonPath('data.is_default', true);
        $this->patchJson("/api/signals/icp/profiles/{$b->id}/status", ['status' => 'active', 'is_default' => true], $h)->assertOk();

        $this->assertFalse((bool) IcpProfile::find($a->id)->is_default, 'only one default per organisation');
        $this->assertTrue((bool) IcpProfile::find($b->id)->is_default);

        $this->patchJson("/api/signals/icp/profiles/{$b->id}/status", ['status' => 'archived'], $h)->assertOk();
        $this->assertFalse((bool) IcpProfile::find($b->id)->is_default);
        $this->patchJson("/api/signals/icp/profiles/{$a->id}/status", ['status' => 'nonsense'], $h)->assertStatus(422);
    }

    public function test_an_invalid_icp_cannot_be_activated(): void
    {
        $p = $this->profile('broken', 'draft', self::T1, ['per_run' => ['catalogue' => 9, 'accounts' => 9]]);

        $this->patchJson("/api/signals/icp/profiles/{$p->id}/status", ['status' => 'active'], $this->h())->assertStatus(422)->assertJsonStructure(['problems']);
        $this->assertSame('draft', IcpProfile::find($p->id)->status);
    }

    // ── preview ──────────────────────────────────────────────────────────────

    public function test_preview_shows_todays_plan_and_the_model_instructions_and_calls_no_provider(): void
    {
        $p = $this->profile('hospitals', 'active');
        TargetAccount::create(['sub_institute_id' => self::T1, 'pack' => 'hospitals', 'account_key' => 'city general|surat', 'name' => 'City General', 'city' => 'Surat', 'watch_priority' => 1, 'icp_status' => 'unscored']);
        Http::fake();

        $r = $this->getJson("/api/signals/icp/profiles/{$p->id}/preview", $this->h())->assertOk();

        Http::assertNothingSent();
        $this->assertSame([], $r->json('data.problems'));
        $queries = array_column($r->json('data.todays_searches'), 'query');   // each search also carries id, source, topic, recency_days, sites
        $this->assertContains('hospital recruitment "staff nurse" Gujarat', $queries);
        $this->assertContains('"City General" Surat (hiring OR "new wing")', $queries);
        $this->assertStringContainsString('We sell Staff scheduling for hospitals to hospitals', $r->json('data.instructions_to_the_model'));
        $this->assertSame(['unscored' => 1], $r->json('data.account_counts'));
    }

    public function test_the_accounts_of_an_icp_are_listed_and_only_that_icps(): void
    {
        $p = $this->profile('hospitals', 'active');
        foreach ([['City General', 'hospitals'], ['Sunrise Clinic', 'hospitals'], ['Podar World School', 'scholar_k12']] as $i => [$name, $pack]) {
            TargetAccount::create(['sub_institute_id' => self::T1, 'pack' => $pack, 'account_key' => "k{$i}", 'name' => $name, 'watch_priority' => 3, 'icp_status' => 'unscored']);
        }

        $r = $this->getJson("/api/signals/icp/profiles/{$p->id}/accounts", $this->h())->assertOk();

        $this->assertEqualsCanonicalizing(['City General', 'Sunrise Clinic'], array_column($r->json('data'), 'name'));
        $this->assertSame(1, count($this->getJson("/api/signals/icp/profiles/{$p->id}/accounts?search=sunrise", $this->h())->json('data')));
    }

    // ── research follows the organisation's own ICP ──────────────────────────

    public function test_research_follows_the_organisations_own_active_icp_and_ignores_a_draft(): void
    {
        $this->productProfile();
        $this->profile('hospitals', 'active');
        $this->fakeWeb();

        $this->artisan('signals:research', ['--tenant' => self::T1, '--trigger' => 'manual'])->assertExitCode(0);

        $this->assertContains('hospital recruitment "staff nurse" Gujarat', $this->searched(), 'a hospital ICP drives a hospital search');
        $this->assertEmpty(array_filter($this->searched(), fn ($q) => str_contains($q, 'school')), 'nothing about schools');

        IcpProfile::query()->update(['status' => 'draft']);
        $this->fakeWeb();
        Cache::flush();
        $this->artisan('signals:research', ['--tenant' => self::T1, '--trigger' => 'manual'])->assertExitCode(0);
        $this->assertEmpty(array_filter($this->searched(), fn ($q) => str_contains($q, 'staff nurse')), 'a draft is never researched');
    }

    public function test_each_active_icp_gets_its_own_run_and_every_run_records_which_icp_it_was(): void
    {
        $this->productProfile();
        $a = $this->profile('hospitals', 'active');
        $b = $this->profile('clinics', 'active', self::T1, ['queries' => [['id' => 'c-job', 'source' => 'job', 'topic' => 'general', 'days' => 60, 'q' => 'clinic hiring "lab technician" Gujarat']]]);
        $this->fakeWeb();

        $this->artisan('signals:research', ['--tenant' => self::T1, '--trigger' => 'manual'])->assertExitCode(0);

        $runs = ResearchRun::orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $runs->pluck('icp_profile_id')->map(fn ($i) => (int) $i)->all());
        $this->assertContains('hospital recruitment "staff nurse" Gujarat', $this->searched());
        $this->assertContains('clinic hiring "lab technician" Gujarat', $this->searched());
    }

    public function test_a_manual_run_can_pick_one_icp_but_only_an_active_one_of_its_own_organisation(): void
    {
        $this->productProfile();
        $draft = $this->profile('draft-one', 'draft');
        $foreign = $this->profile('foreign', 'active', self::T2);
        $this->fakeWeb();
        $h = $this->h();

        $this->postJson('/api/signals/research/run', ['icp_profile_id' => $draft->id], $h)->assertStatus(422)->assertJsonPath('code', 'icp_not_active');
        $this->postJson('/api/signals/research/run', ['icp_profile_id' => $foreign->id], $h)->assertStatus(422)->assertJsonPath('code', 'icp_not_active');
        $this->assertSame(0, ResearchRun::count(), 'a refused request starts nothing');
    }

    // ── accounts for any organisation ────────────────────────────────────────

    public function test_any_csv_can_be_imported_for_any_icp_with_a_column_mapping_and_custom_facts(): void
    {
        $this->profile('hospitals', 'active');
        $path = tempnam(sys_get_temp_dir(), 'h');
        $f = fopen($path, 'w');
        foreach ([
            ['Hospital', 'City', 'State', 'Beds', 'NABH'],
            ['City General Hospital', 'Surat', 'Gujarat', '450', 'yes'],
            ['Little Care Hospital', 'Rajkot', 'Gujarat', '60', ''],
            ['Rover Pet Hospital', 'Surat', 'Gujarat', '300', ''],
        ] as $row) {
            fputcsv($f, $row);
        }
        fclose($f);

        $this->artisan('signals:import-accounts', ['file' => $path, '--tenant' => self::T1, '--profile' => 'hospitals', '--preset' => 'generic',
            '--map' => 'name=Hospital;city=City;state=State;attr.beds=Beds;attr.nabh=NABH'])->assertExitCode(0);

        $by = TargetAccount::all()->keyBy('name');
        $this->assertSame(450, $by['City General Hospital']->attributes['beds']);
        $this->assertSame('candidate', $by['City General Hospital']->icp_status);
        $this->assertSame('disqualified', $by['Little Care Hospital']->icp_status);
        $this->assertSame('veterinary clinic', $by['Rover Pet Hospital']->icp_notes['disqualified_by']);
        $this->assertSame(IcpProfile::first()->id, (int) $by['City General Hospital']->icp_profile_id);

        // facts added later, in any columns the organisation likes
        $facts = tempnam(sys_get_temp_dir(), 'f');
        $f = fopen($facts, 'w');
        fputcsv($f, ['name', 'city', 'beds', 'icu_beds']);
        fputcsv($f, ['Rover Pet Hospital', 'Surat', '120', '10']);
        fclose($f);
        $this->artisan('signals:import-account-facts', ['file' => $facts, '--tenant' => self::T1, '--profile' => 'hospitals'])->assertExitCode(0);
        $this->assertSame(10, TargetAccount::where('name', 'Rover Pet Hospital')->first()->attributes['icu_beds'], 'a column the ICP never named is kept as a custom fact');
    }

    public function test_a_template_can_be_copied_into_an_organisation_from_the_command_line_safely_twice(): void
    {
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12', '--activate' => true, '--default' => true])->assertExitCode(0);
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12'])->assertExitCode(0);

        $this->assertSame(1, IcpProfile::count());
        $p = IcpProfile::first();
        $this->assertSame('active', $p->status);
        $this->assertTrue((bool) $p->is_default);
        $this->assertSame(1, IcpProfileVersion::where('icp_profile_id', $p->id)->count());
    }

    public function test_the_scholar_preview_shows_each_days_searches_with_topic_sites_and_recency_and_covers_the_set_in_four_days(): void
    {
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12', '--activate' => true])->assertExitCode(0);
        $id = IcpProfile::first()->id;
        $h = $this->h();
        Http::fake();

        $seen = [];
        foreach (range(0, 3) as $d) {
            Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00')->addDays($d));
            $searches = $this->getJson("/api/signals/icp/profiles/{$id}/preview", $h)->assertOk()->json('data.todays_searches');

            $this->assertCount(3, $searches, 'three catalogue searches a day (no accounts loaded)');
            foreach ($searches as $s) {
                $this->assertContains($s['topic'], ['news', 'general']);
                $this->assertIsInt($s['recency_days']);
                $this->assertDoesNotMatchRegularExpression('/AI solutions|enterprise AI|artificial intelligence|generative AI|business intelligence/i', $s['query']);
                if ($s['source'] === 'tender') {
                    $this->assertSame('general', $s['topic']);
                    $this->assertSame(90, $s['recency_days']);
                    $this->assertSame(['gem.gov.in', 'eprocure.gov.in', 'nprocure.com', 'mahatenders.gov.in'], $s['sites']);
                }
                if ($s['source'] === 'job') {
                    $this->assertSame(['general', 60], [$s['topic'], $s['recency_days']]);
                    $this->assertArrayNotHasKey('sites', $s);
                }
                if ($s['source'] === 'news') {
                    $this->assertSame(['news', 30], [$s['topic'], $s['recency_days']]);
                }
                $seen[$s['id']] = true;
            }
        }

        Http::assertNothingSent();
        $this->assertCount(12, $seen, 'the whole set in four days');
    }

    public function test_the_table_backup_only_runs_on_mysql_or_mariadb_and_says_so(): void
    {
        $this->artisan('signals:backup-tables')->expectsOutputToContain('MySQL / MariaDB')->assertExitCode(1);
    }

    // ── will research actually run? ──────────────────────────────────────────

    public function test_an_inactive_icp_produces_no_scheduled_run_and_an_active_one_does(): void
    {
        $this->productProfile();
        $icp = $this->profile('hospitals', 'draft');
        $this->fakeWeb();

        // The schedule is due (profile on, 15:30 IST is past 10:00) but the organisation's only ICP is a draft: nothing to run.
        $this->artisan('signals:research')->expectsOutputToContain('skipped (it has ICPs but none is active)')->assertExitCode(0);
        $this->assertSame(0, ResearchRun::count(), 'a draft ICP never starts a scheduled run, and there is no fallback to generic research');

        $icp->update(['status' => 'archived']);
        $this->artisan('signals:research')->assertExitCode(0);
        $this->assertSame(0, ResearchRun::count(), 'archived is inactive too');

        $icp->update(['status' => 'active']);
        $this->artisan('signals:research')->assertExitCode(0);
        $run = ResearchRun::sole();
        $this->assertSame('scheduled', $run->trigger);
        $this->assertSame($icp->id, (int) $run->icp_profile_id);
    }

    public function test_an_organisation_with_no_icp_at_all_still_gets_the_generic_scheduled_run(): void
    {
        $this->productProfile();
        $this->fakeWeb();

        $this->artisan('signals:research')->assertExitCode(0);

        $this->assertSame(1, ResearchRun::count());
        $this->assertNull(ResearchRun::first()->icp_profile_id);
    }

    public function test_seeding_can_switch_the_daily_schedule_on_but_only_for_a_complete_product_profile(): void
    {
        // no product profile: ICP is created and active, schedule stays off with a clear message
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12', '--activate' => true, '--enable-schedule' => true])
            ->expectsOutputToContain('Schedule NOT enabled: this organisation has no product profile')->assertExitCode(0);
        $this->assertSame('active', IcpProfile::first()->status);

        // an incomplete profile: still off, and it says what is missing
        $p = ProductProfile::create(['sub_institute_id' => self::T1, 'product_name' => 'X', 'research_enabled' => false]);
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12', '--enable-schedule' => true])
            ->expectsOutputToContain('Schedule NOT enabled: the product profile is incomplete')->assertExitCode(0);
        $this->assertFalse((bool) $p->refresh()->research_enabled);

        // a complete profile: on (running the seed again is safe)
        $p->forceFill(['description' => 'Rosters.', 'problems_solved' => 'x', 'features' => 'y', 'target_industries' => ['Healthcare'], 'keywords' => ['rostering']])->save();
        $this->artisan('signals:icp-seed', ['--tenant' => self::T1, '--template' => 'scholar_k12', '--enable-schedule' => true])
            ->expectsOutputToContain('Daily schedule: ON')->assertExitCode(0);
        $this->assertTrue((bool) $p->refresh()->research_enabled);
        $this->assertSame(1, IcpProfile::count());
    }

    public function test_the_overview_reports_icps_schedule_next_run_providers_and_last_run_without_any_key(): void
    {
        $this->productProfile();
        $this->profile('hospitals', 'active');
        $this->profile('clinics', 'draft');
        ResearchRun::create(['sub_institute_id' => self::T1, 'report_date' => '2026-10-19', 'trigger' => 'manual', 'status' => 'success', 'started_at' => now()->subDay()]);

        $o = app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->forTenant(self::T1);

        $this->assertSame(['hospitals'], $o['active_icps']);
        $this->assertSame(['draft' => 1, 'archived' => 0], $o['other_icps']);
        $this->assertTrue($o['schedule_on']);
        $this->assertFalse($o['blocked_by_inactive_icp']);
        $this->assertSame('2026-10-21T10:00:00+05:30', $o['next_run_at'], '10:00 tomorrow, in the timezone of the organisation');
        $this->assertTrue($o['search_provider_configured']);
        $this->assertTrue($o['ai_provider_configured']);
        $this->assertSame('success', $o['last_run']['status']);

        $text = implode("
", app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->lines($o));
        $this->assertStringNotContainsString('tvly-test-key', $text);
        $this->assertStringNotContainsString('test-key', $text);
    }

    public function test_the_overview_says_so_when_icps_exist_but_none_is_active_or_the_schedule_is_off(): void
    {
        $this->productProfile();
        $this->profile('hospitals', 'draft');
        $o = app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->forTenant(self::T1);

        $this->assertTrue($o['blocked_by_inactive_icp']);
        $this->assertNull($o['next_run_at']);
        $this->assertStringContainsString('none is active', implode("
", app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->lines($o)));

        ProductProfile::query()->update(['research_enabled' => false]);
        $off = app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->lines(app(\App\Domain\Signals\Icp\SignalsTenantOverview::class)->forTenant(self::T1));
        $this->assertStringContainsString('OFF', implode("
", $off));
    }

    public function test_diagnose_prints_the_whole_overview_for_an_organisation(): void
    {
        $this->productProfile();
        $this->profile('hospitals', 'active');
        $this->fakeWeb();

        \Illuminate\Support\Facades\Artisan::call('signals:diagnose', ['--tenant' => self::T1]);
        $out = \Illuminate\Support\Facades\Artisan::output();

        foreach (['Tenant overview', 'Organisation 1', 'Active ICPs:                hospitals', 'Schedule:                   ON, daily at 10:00', 'Next run:', 'Search provider configured: yes', 'AI provider configured:     yes', 'Last run:                   no run yet'] as $needle) {
            $this->assertStringContainsString($needle, $out, $needle);
        }
        $this->assertStringNotContainsString('tvly-test-key', $out);
        $this->assertStringNotContainsString('test-key', $out);

        \Illuminate\Support\Facades\Artisan::call('signals:diagnose', ['--all' => true]);
        $this->assertStringContainsString('Organisation 1', \Illuminate\Support\Facades\Artisan::output());
    }
}
