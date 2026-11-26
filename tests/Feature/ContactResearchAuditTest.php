<?php

namespace Tests\Feature;

use App\Console\Commands\SignalsEnrichContacts;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\SignalContactStore;
use App\Http\Controllers\Api\Signals\OpportunityController;
use App\Jobs\EnrichSignalContactsJob;
use App\Models\auth\tbluserModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pre-staging audit of contact enrichment, through the real HTTP layer and the real queue job. In-memory sqlite. Every external request is blocked
 * (Http::preventStrayRequests, and each test fakes Tavily itself): no real provider can be reached from this file.
 */
class ContactResearchAuditTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Cache::flush();
        config(['signals.legacy_product_icp' => true, 'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.tavily_keys' => '', 'signals_connectors.contact_research.allow_in_tests' => true, 'signals_connectors.contact_research.enabled' => true]);

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_12_07_090000_add_scope_to_g2g_company_opportunities', '2026_12_28_100000_create_g2g_signal_contacts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'], ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee']]);
        foreach ([self::T1, self::T2] as $tenant) {
            ProductProfile::create([
                'sub_institute_id' => $tenant, 'product_name' => 'ScholarClone', 'description' => 'School ERP system', 'problems_solved' => 'Manual school operations',
                'features' => 'Fee collection', 'target_industries' => ['Education'], 'target_markets' => ['India'], 'keywords' => ['school erp'],
                'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** Signals are created with the lookup OFF (no provider is touched while creating them). */
    private function makeSignal(int $tenant, string $name, string $url, string $eventDate = '2026-11-10'): CompanyOpportunity
    {
        config(['signals_connectors.contact_research.allow_in_tests' => false]);
        app(MarketImporter::class)->import($tenant, [[
            'buyer' => ['name' => $name, 'type' => 'school', 'state' => 'Gujarat'], 'trigger_type' => 'expansion', 'trigger_summary' => "{$name} is opening a new campus.",
            'what_happened' => "{$name} is opening a new campus in Vadodara.", 'event_date' => $eventDate, 'source_url' => $url, 'source_title' => "{$name} campus",
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet',
        ]], ['source_label' => 'audit']);
        config(['signals_connectors.contact_research.allow_in_tests' => true]);

        return CompanyOpportunity::where('sub_institute_id', $tenant)->where('source_url', $url)->firstOrFail();
    }

    /** Tavily answers from a map {needle in the query => results}; counts every request. */
    private function fakeTavily(array $answers = []): void
    {
        Http::fake(function ($request) use ($answers) {
            $q = (string) ($request->data()['query'] ?? '');
            foreach ($answers as $needle => $results) {
                if (str_contains($q, (string) $needle)) {
                    return Http::response(['results' => array_map(fn ($r) => ['url' => $r[0], 'title' => $r[1], 'content' => $r[2] ?? ''], $results)]);
                }
            }

            return Http::response(['results' => []]);
        });
    }

    private function searches(): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), 'tavily'))->count();
    }

    // ── 1. visibility ────────────────────────────────────────────────────────

    public function test_every_signal_is_listed_with_a_contact_block_whatever_the_enrichment_state(): void
    {
        $a = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $b = $this->makeSignal(self::T1, 'Beta Public School', 'https://news.test/beta');
        $c = $this->makeSignal(self::T1, 'Gamma Public School', 'https://news.test/gamma');
        SignalContactStore::save(self::T1, (int) $a->company_id, $a->id, [['full_name' => 'Asha Rao', 'designation' => 'Principal', 'email' => 'asha@alpha.example', 'email_source_url' => 'https://alpha.example/c']]);
        SignalContactStore::markSearched(self::T1, (int) $b->company_id, 'none');   // searched, nothing found; $c never searched

        $rows = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data'));
        $this->assertCount(3, $rows);
        $byId = $rows->keyBy('id');
        $this->assertSame('found', $byId[$a->id]['contact']['research']['state']);
        $this->assertSame('none_found', $byId[$b->id]['contact']['research']['state']);
        $this->assertSame('not_searched', $byId[$c->id]['contact']['research']['state']);
        $this->assertSame('asha@alpha.example', $byId[$a->id]['contact']['email']);
        $this->assertSame('not_available', $byId[$b->id]['contact']['status']);
        $this->assertNull($byId[$b->id]['contact']['email']);
    }

    public function test_a_broken_contact_store_still_lists_every_signal(): void
    {
        $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $this->makeSignal(self::T1, 'Beta Public School', 'https://news.test/beta');
        Schema::table('g2g_signal_contacts', fn ($t) => $t->renameColumn('sub_institute_id', 'broken'));   // reading contacts now raises an SQL error

        $rows = $this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data');
        $this->assertCount(2, $rows, 'a problem reading contacts must not hide signals');
        $this->assertSame('not_available', $rows[0]['contact']['status']);
    }

    public function test_a_failure_building_one_contact_block_does_not_lose_the_signal(): void
    {
        $m = new ReflectionMethod(OpportunityController::class, 'contactBlock');
        $m->setAccessible(true);
        $block = $m->invoke(app(OpportunityController::class), (object) ['id' => 7, 'company_name' => 'Alpha'], null, null, [], [], [['email' => new \stdClass()]], ['state' => 'found']);   // a malformed stored contact: building the block raises a TypeError
        $this->assertSame('not_available', $block['status']);
        $this->assertSame('Alpha', $block['company']);
        $this->assertStringContainsString('could not be loaded', $block['message']);
    }

    // ── 8. a failed or unavailable search never touches the signal ───────────

    public function test_unavailable_failing_or_empty_search_leaves_every_signal_field_unchanged(): void
    {
        $s = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $fields = ['qualification', 'priority', 'review_status', 'claim_level', 'fetch_level', 'score_total', 'feed_section', 'pipeline_status', 'title'];
        $before = CompanyOpportunity::find($s->id)->only($fields);

        // a) provider unreachable (a connection error), b) HTTP 500, c) no results, d) no provider configured
        Http::fake(fn () => throw new ConnectionException('unreachable'));
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        Http::fake(['*' => Http::response('boom', 500)]);
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->fakeTavily();
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        config(['signals.web_search.driver' => 'none']);
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();

        $this->assertSame($before, CompanyOpportunity::find($s->id)->only($fields));
        $this->assertSame(0, DB::table('g2g_signal_contacts')->count());
        $this->assertCount(1, $this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data'));
    }

    // ── 3. tenants ───────────────────────────────────────────────────────────

    public function test_contacts_never_cross_organisations(): void
    {
        $t1 = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $t2 = $this->makeSignal(self::T2, 'Alpha Public School', 'https://news.test/alpha2');
        SignalContactStore::save(self::T1, (int) $t1->company_id, $t1->id, [['full_name' => 'Asha Rao', 'email' => 'asha@alpha.example']]);
        // even a row filed under organisation 2 but pointing at organisation 1's company id must not show on organisation 1's signal
        DB::table('g2g_signal_contacts')->insert(['sub_institute_id' => self::T2, 'company_id' => $t1->company_id, 'full_name' => 'Intruder', 'email' => 'x@evil.example', 'verification_status' => 'unverified']);

        $one = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h(self::T1))->assertOk()->json('data'))->firstWhere('id', $t1->id);
        $two = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h(self::T2))->assertOk()->json('data'))->firstWhere('id', $t2->id);
        $this->assertSame('asha@alpha.example', $one['contact']['email']);
        $this->assertCount(1, $one['contact']['contacts']);
        $this->assertNull($two['contact']['email'], 'organisation 2 sees nothing of organisation 1');
        $this->assertSame([], $two['contact']['contacts']);
        $this->assertStringNotContainsString('asha@alpha.example', json_encode($two));

        // the lookup refuses a signal that belongs to another organisation
        $this->fakeTavily(['contact us' => [['https://www.alpha.example/c', 'Contact Alpha Public School', 'office@alpha.example']]]);
        (new EnrichSignalContactsJob(self::T2, $t1->id, true))->handle();
        $this->assertSame(0, $this->searches());
    }

    public function test_the_find_contacts_endpoint_is_scoped_and_refuses_when_not_set_up(): void
    {
        $t1 = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        Queue::fake();

        $this->postJson("/api/signals/opportunities/{$t1->id}/contacts/research", [], $this->h(self::T2))->assertNotFound();
        $this->postJson("/api/signals/opportunities/{$t1->id}/contacts/research", [])->assertUnauthorized();
        $this->postJson('/api/signals/opportunities/999999/contacts/research', [], $this->h(self::T1))->assertNotFound();
        Queue::assertNothingPushed();

        $this->postJson("/api/signals/opportunities/{$t1->id}/contacts/research", [], $this->h(self::T1))->assertOk()->assertJson(['status' => 1]);
        Queue::assertPushed(EnrichSignalContactsJob::class, fn ($j) => $j->tenantId === self::T1 && $j->opportunityId === $t1->id && $j->force === true);

        config(['signals.web_search.driver' => 'none']);
        $this->postJson("/api/signals/opportunities/{$t1->id}/contacts/research", [], $this->h(self::T1))->assertStatus(422)->assertJson(['status' => 0]);
    }

    // ── 2. + 4. + 5. storage, sources, no fabrication ────────────────────────

    public function test_running_the_lookup_twice_stores_each_contact_once_with_sources_and_unverified_status(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        Company::whereKey($s->company_id)->update(['website' => 'https://www.sunriseschool.in']);
        $this->fakeTavily([
            'contact us' => [['https://www.sunriseschool.in/contact', 'Contact Sunrise Public School', 'Office: office@sunriseschool.in. Call +91 98220 11223']],
            '"Principal"' => [
                ['https://in.linkedin.com/in/asha-rao', 'Asha Rao - Principal - Sunrise Public School | LinkedIn', 'Sunrise Public School'],
                ['https://www.linkedin.com/in/vikram-shah', 'Vikram Shah - Director - Sunrise Public School | LinkedIn', 'Sunrise Public School'],
            ],
        ]);

        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $first = DB::table('g2g_signal_contacts')->get();
        $this->assertCount(3, $first, 'the organisation line and two decision-makers');
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();   // forced again: nothing may be duplicated
        $this->assertCount(3, DB::table('g2g_signal_contacts')->get());

        $org = $first->firstWhere('designation', 'Organisation contact (published)');
        $this->assertSame('office@sunriseschool.in', $org->email);
        $this->assertSame('+919822011223', $org->phone);
        $this->assertSame('https://www.sunriseschool.in/contact', $org->email_source_url);
        $this->assertSame('https://www.sunriseschool.in/contact', $org->phone_source_url);
        foreach ($first as $row) {
            $this->assertSame('unverified', $row->verification_status);
            $this->assertSame((string) self::T1, (string) $row->sub_institute_id);
            $this->assertSame((string) $s->company_id, (string) $row->company_id);
            $this->assertNotNull($row->source_url);
        }
        $people = $first->whereNotNull('full_name');
        $this->assertCount(2, $people);
        foreach ($people as $p) {
            $this->assertNull($p->email, 'no email was printed for a person, so none is stored (and none is guessed)');
            $this->assertNull($p->phone);
        }
    }

    public function test_nothing_printed_means_nothing_stored_and_no_value_is_ever_invented(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        Company::whereKey($s->company_id)->update(['website' => 'https://www.sunriseschool.in']);
        $this->fakeTavily(['"Principal"' => [['https://www.linkedin.com/in/asha-rao', 'Asha Rao - Principal - Sunrise Public School | LinkedIn', 'Principal']]]);
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();

        foreach (DB::table('g2g_signal_contacts')->get() as $row) {
            $this->assertNull($row->email);
            $this->assertNull($row->phone);
        }
        $this->assertStringNotContainsString('@sunriseschool.in', json_encode(DB::table('g2g_signal_contacts')->get()));
    }

    // ── 6. budget and cooldown ───────────────────────────────────────────────

    public function test_one_lookup_never_makes_more_than_six_searches(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        CompanyOpportunity::whereKey($s->id)->update(['likely_stakeholder' => 'Trustee']);
        // No website on file, so the lookup wants: website (1) + organisation line (1) + three roles (3) + an email for two people (2) = 7 searches.
        Http::fake(function ($request) {
            $q = (string) $request->data()['query'];
            if (str_contains($q, 'official website')) {
                return Http::response(['results' => [['url' => 'https://www.sunriseschool.in/', 'title' => 'Sunrise Public School - Home', 'content' => 'Sunrise Public School']]]);
            }

            $who = str_contains($q, '"Trustee"') ? 'Arun Mehta' : (str_contains($q, '"Principal"') ? 'Asha Rao' : (str_contains($q, '"Director"') ? 'Vikram Shah' : 'Other Person'));

            return Http::response(['results' => [['url' => 'https://www.linkedin.com/in/' . strtolower(str_replace(' ', '-', $who)), 'title' => "{$who} - Director - Sunrise Public School | LinkedIn", 'content' => 'Sunrise Public School']]]);
        });

        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->assertSame(6, $this->searches(), 'it wanted 7 and the cap stopped it at exactly 6');
    }

    public function test_the_cap_follows_the_setting(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        config(['signals_connectors.contact_research.max_searches' => 2]);
        $this->fakeTavily();
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->assertSame(2, $this->searches());
    }

    public function test_switching_contact_research_off_stops_the_lookup_and_the_button(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        config(['signals_connectors.contact_research.enabled' => false]);
        Queue::fake();
        $this->postJson("/api/signals/opportunities/{$s->id}/contacts/research", [], $this->h())->assertStatus(422);
        $this->assertFalse(EnrichSignalContactsJob::dispatchFor(self::T1, $s->id));
        Queue::assertNothingPushed();
        $row = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data'))->firstWhere('id', $s->id);
        $this->assertFalse($row['contact']['research']['can_search']);
        $this->assertSame('not_set_up', $row['contact']['research']['state']);
    }

    public function test_an_organisation_is_not_searched_again_for_seven_days_but_is_after_that(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        Company::whereKey($s->company_id)->update(['website' => 'https://www.sunriseschool.in']);
        $this->fakeTavily(['contact us' => [['https://www.sunriseschool.in/contact', 'Contact Sunrise Public School', 'office@sunriseschool.in']]]);

        (new EnrichSignalContactsJob(self::T1, $s->id))->handle();
        $used = $this->searches();
        $this->assertGreaterThan(0, $used);

        (new EnrichSignalContactsJob(self::T1, $s->id))->handle();                 // day 0: skipped
        Carbon::setTestNow('2026-11-26 10:00:00');
        (new EnrichSignalContactsJob(self::T1, $s->id))->handle();                 // day 6: still skipped
        $this->assertSame($used, $this->searches());

        Carbon::setTestNow('2026-11-28 10:01:00');
        (new EnrichSignalContactsJob(self::T1, $s->id))->handle();                 // day 8: searches again
        $this->assertGreaterThan($used, $this->searches());
    }

    public function test_a_search_that_could_not_run_is_not_remembered_as_done(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        Http::fake(['*' => Http::response('boom', 500)]);
        (new EnrichSignalContactsJob(self::T1, $s->id))->handle();
        $this->assertNull(SignalContactStore::lastSearch(self::T1, (int) $s->company_id), 'a failed attempt must be retried, not locked out for seven days');
    }

    public function test_two_jobs_for_one_organisation_cannot_run_together(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        $this->fakeTavily();
        $lock = Cache::lock('signals:contact-research-lock:' . self::T1 . ':' . $s->company_id, 60);
        $this->assertTrue($lock->get());
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();   // another lookup holds the organisation: this one leaves quietly
        $this->assertSame(0, $this->searches());
        $lock->release();
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->assertGreaterThan(0, $this->searches());
    }

    // ── 7. queue, backfill ───────────────────────────────────────────────────

    public function test_the_job_is_on_the_signals_queue_with_bounded_time_and_never_fails_the_worker(): void
    {
        $j = new EnrichSignalContactsJob(self::T1, 1);
        $this->assertSame(config('signals.queue', 'signals'), $j->queue);
        $this->assertSame(2, $j->tries);
        $this->assertLessThan(900, $j->timeout, 'below the worker timeout and the connector sync job');
        $this->assertGreaterThan(6 * (int) config('signals.web_search.timeout', 20), $j->timeout, 'long enough for six searches');
        Http::fake(fn () => throw new ConnectionException('unreachable'));
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->addToAssertionCount(1);   // reached: handle() swallowed the failure instead of throwing
    }

    public function test_backfill_queues_one_job_per_organisation_skips_searched_ones_and_changes_no_signal(): void
    {
        $a1 = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $a2 = $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha-2', '2026-10-01');   // same organisation as $a1, a different event
        $b = $this->makeSignal(self::T1, 'Beta Public School', 'https://news.test/beta');
        $c = $this->makeSignal(self::T1, 'Gamma Public School', 'https://news.test/gamma');
        $other = $this->makeSignal(self::T2, 'Delta Public School', 'https://news.test/delta');
        SignalContactStore::markSearched(self::T1, (int) $c->company_id, 'none');
        $before = CompanyOpportunity::query()->orderBy('id')->get()->map->only(['id', 'qualification', 'priority', 'review_status', 'score_total'])->all();
        Queue::fake();

        $this->assertSame(0, Artisan::call('signals:enrich-contacts', ['--tenant' => self::T1]));
        Queue::assertPushed(EnrichSignalContactsJob::class, 2);   // Alpha (once) and Beta; Gamma was searched; Delta is another organisation
        Queue::assertPushed(EnrichSignalContactsJob::class, fn ($j) => $j->tenantId === self::T1);
        Queue::assertNotPushed(EnrichSignalContactsJob::class, fn ($j) => $j->tenantId === self::T2);
        $this->assertSame($before, CompanyOpportunity::query()->orderBy('id')->get()->map->only(['id', 'qualification', 'priority', 'review_status', 'score_total'])->all());
    }

    public function test_backfill_needs_a_tenant_respects_the_limit_and_does_nothing_without_a_provider(): void
    {
        $this->makeSignal(self::T1, 'Alpha Public School', 'https://news.test/alpha');
        $this->makeSignal(self::T1, 'Beta Public School', 'https://news.test/beta');
        Queue::fake();

        $this->assertSame(1, Artisan::call('signals:enrich-contacts'));
        Queue::assertNothingPushed();
        Artisan::call('signals:enrich-contacts', ['--tenant' => self::T1, '--limit' => 1]);
        Queue::assertPushed(EnrichSignalContactsJob::class, 1);

        Queue::fake();
        config(['signals.web_search.driver' => 'none']);
        Artisan::call('signals:enrich-contacts', ['--tenant' => self::T1]);
        Queue::assertNothingPushed();
        $this->assertTrue(class_exists(SignalsEnrichContacts::class));
    }

    public function test_the_lookup_is_queued_only_after_the_signal_is_committed_and_never_runs_inside_the_save(): void
    {
        // The importer saves a signal inside a database transaction. With the queue on `sync` a job queued from inside it would run, with web searches,
        // while that transaction is open. It must wait for the commit instead, and must not run at all if the save is rolled back.
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        config(['queue.default' => 'sync']);
        $this->fakeTavily(['contact us' => [['https://www.sunriseschool.in/c', 'Contact Sunrise Public School', 'office@sunriseschool.in']]]);

        DB::beginTransaction();
        EnrichSignalContactsJob::dispatchFor(self::T1, $s->id);
        $this->assertSame(0, $this->searches(), 'nothing is searched while the save is still open');
        DB::rollBack();
        $this->assertSame(0, $this->searches(), 'a rolled-back save never triggers a lookup');

        DB::beginTransaction();
        EnrichSignalContactsJob::dispatchFor(self::T1, $s->id);
        $this->assertSame(0, $this->searches());
        DB::commit();
        $this->assertGreaterThan(0, $this->searches(), 'once the save is committed the lookup runs');
    }

    // ── choosing who to look for, and saying what happened ───────────────────

    public function test_roles_fit_the_kind_of_organisation_and_start_with_the_signals_own_stakeholder(): void
    {
        $this->assertSame(['Principal', 'Director', 'Founder'], \App\Domain\Signals\Opportunities\ContactResearcher::rolesFor('school', 'Sunrise Public School', null));
        $this->assertSame(['Managing Director', 'Chairman', 'General Manager'], \App\Domain\Signals\Opportunities\ContactResearcher::rolesFor(null, 'Intelligent Communication Systems India Limited (ICSIL)', null));
        $this->assertSame(['CEO', 'Managing Director', 'Director'], \App\Domain\Signals\Opportunities\ContactResearcher::rolesFor(null, 'Acme Traders', null));
        $roles = \App\Domain\Signals\Opportunities\ContactResearcher::rolesFor('school', 'Sunrise Public School', 'Establishment head', ['Trustee']);
        $this->assertSame('Establishment head', $roles[0]);
        $this->assertContains('Trustee', $roles);
        $this->assertCount(count(array_unique($roles)), $roles, 'no title twice');
    }

    public function test_a_head_of_an_establishment_is_accepted_but_a_clerk_is_not(): void
    {
        $f = fn (string $t) => \App\Domain\Signals\Opportunities\ContactResearcher::personFromLinkedInTitle($t);
        $this->assertSame('Establishment Head', $f('Rakesh Gupta - Establishment Head - ICSIL | LinkedIn')['designation']);
        $this->assertNotNull($f('Meena Joshi - Joint Secretary - ICSIL | LinkedIn'));
        $this->assertNull($f('Anil Kumar - Software Engineer - ICSIL | LinkedIn'));
        $this->assertNull($f('Sunita Rao - Accounts Clerk - ICSIL | LinkedIn'));
    }

    public function test_the_last_attempt_is_recorded_whether_it_found_nothing_failed_or_found_something(): void
    {
        $s = $this->makeSignal(self::T1, 'Sunrise Public School', 'https://news.test/sunrise');
        $company = (int) $s->company_id;
        $mode = 'fail';
        Http::fake(function () use (&$mode) {
            return match ($mode) {
                'fail' => Http::response('boom', 500),
                'empty' => Http::response(['results' => []]),
                default => Http::response(['results' => [['url' => 'https://www.sunriseschool.in/contact', 'title' => 'Contact Sunrise Public School', 'content' => 'office@sunriseschool.in']]]),
            };
        });

        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $a = SignalContactStore::lastAttempt(self::T1, $company);
        $this->assertSame('failed', $a['outcome']);
        $this->assertGreaterThan(0, $a['failed']);
        $this->assertNull(SignalContactStore::lastSearch(self::T1, $company), 'a failed attempt is not a completed search');

        $mode = 'empty';
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $a = SignalContactStore::lastAttempt(self::T1, $company);
        $this->assertSame('none', $a['outcome']);
        $this->assertSame(0, $a['found']);

        $mode = 'found';
        Company::whereKey($company)->update(['website' => 'https://www.sunriseschool.in']);
        (new EnrichSignalContactsJob(self::T1, $s->id, true))->handle();
        $this->assertSame('found', SignalContactStore::lastAttempt(self::T1, $company)['outcome']);

        $row = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data'))->firstWhere('id', $s->id);
        $this->assertSame('found', $row['contact']['research']['attempt']['outcome']);
        $this->assertSame('office@sunriseschool.in', $row['contact']['email']);
    }
}
