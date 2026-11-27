<?php

namespace Tests\Feature;

use App\Domain\Gtm\GtmOutreachMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The four filters of the Outreach tab (27 Nov 2026): Product, Sub-product, ICP and Region on the Companies list, the All people list and the Messages queue, and the options with live
 * counts each answer carries (`facets`). In-memory sqlite, the real migration files; nothing here can deliver mail.
 *
 * The world: five companies.
 *   A Riverdale School      Scholar K-12 / Scholar Admissions, b2b, India > Maharashtra > Nagpur         people: Meera (ready), Kiran (email only)
 *   B Sunrise Academy       Scholar K-12 (by its key), b2c, India > Gujarat                               nobody found
 *   C Education Dept        Enterprise Brain, b2g, India > Delhi                                         people: Ravi (email only)
 *   D Mystery Co            a signal that records nothing                                                people: Anita (no details)
 *   E Greenfield Traders    added by hand, location "Surat, Gujarat", no signal                          people: Asha (email only, location Pune)
 */
class OutreachFiltersApiTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const OTHER = 2;

    private const COMPANIES = '/api/signals/outreach/companies';

    private const PEOPLE = '/api/signals/outreach/people';

    private const MESSAGES = '/api/gtm/outreach';

    private array $admin;

    private int $scholar;

    private int $eb;

    private int $admissions;

    /** @var array<string, int> company id by letter */
    private array $co = [];

    /** @var array<string, int> person id by first name */
    private array $who = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        foreach (['2026_12_28_100000_create_g2g_signal_contacts_table', '2026_12_29_090000_add_outreach_fields_to_signal_contacts_and_companies', '2026_12_11_150300_add_product_master_id_to_gtm_outreach_messages'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Mail::fake();
        $this->admin = $this->signalsAdmin();
        $this->catalogue();
        $this->world();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function master(int $tenant, string $kind, string $name, array $over = []): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId($over + ['sub_institute_id' => $tenant, 'product_key' => 'k' . uniqid(), 'kind' => $kind, 'name' => $name, 'status' => 'active', 'origin' => 'manual']);
    }

    private function offer(?int $tenant, string $code, string $name, string $parent): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => $tenant, 'offer_id' => $code, 'name' => $name, 'parent_product' => $parent, 'readiness_status' => 'ready']);
    }

    private function catalogue(): void
    {
        $this->scholar = $this->master(self::T, 'product', 'Scholar K-12', ['signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12']);
        $this->eb = $this->master(0, 'product', 'Enterprise Brain', ['signal_key' => 'eb', 'portfolio_name' => 'EB']);
        $offer = $this->offer(self::T, 'SCH-01', 'Scholar admissions offer', 'Scholar K-12');
        $this->admissions = $this->master(self::T, 'sub_product', 'Scholar Admissions', ['parent_id' => $this->scholar, 'offer_row_id' => $offer]);
    }

    /** @param array<string, mixed> $signal @param array<string, mixed> $company */
    private function found(string $name, array $signal, array $company = [], int $tenant = self::T): int
    {
        return (int) $this->makeSignal($tenant, $signal, $company + ['name' => $name, 'normalized_name' => strtolower($name), 'domain' => strtolower(str_replace(' ', '', $name)) . '.org'])->company_id;
    }

    /** @param array<string, mixed> $over */
    private function person(int $companyId, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_signal_contacts')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'company_id' => $companyId, 'full_name' => 'Meera Patel', 'designation' => 'Principal', 'email' => 'meera@riverdale-school.org', 'phone' => '+919876543210',
            'verification_status' => 'unverified', 'source' => 'found', 'consent_status' => 'unknown', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function world(): void
    {
        $this->co['A'] = $this->found('Riverdale School', ['product_master_id' => $this->scholar, 'offer_id' => 'SCH-01', 'customer_model' => 'b2b', 'region_scope' => '[{"country":"India","state":"Maharashtra","city":"Nagpur"}]']);
        $this->co['B'] = $this->found('Sunrise Academy', ['product_key' => 'scholar', 'customer_model' => 'b2c', 'region_scope' => '[{"country":"India","state":"Gujarat","city":null}]']);
        $this->co['C'] = $this->found('Education Dept', ['product_master_id' => $this->eb, 'customer_model' => 'b2g', 'region_scope' => '[{"country":"India","state":"Delhi","city":null}]']);
        $this->co['D'] = $this->found('Mystery Co', []);
        $this->co['E'] = (int) DB::table('g2g_companies')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Greenfield Traders', 'normalized_name' => 'greenfield traders', 'source' => 'manual', 'location' => 'Surat, Gujarat', 'created_at' => now(), 'updated_at' => now()]);

        $signalOf = fn (string $c) => (int) DB::table('g2g_company_opportunities')->where('company_id', $this->co[$c])->value('id');
        $this->who['Meera'] = $this->person($this->co['A'], ['opportunity_id' => $signalOf('A')]);
        $this->who['Kiran'] = $this->person($this->co['A'], ['full_name' => 'Kiran Desai', 'email' => 'kiran@riverdale-school.org', 'phone' => null, 'opportunity_id' => $signalOf('A')]);
        $this->who['Ravi'] = $this->person($this->co['C'], ['full_name' => 'Ravi Shah', 'email' => 'ravi@education.example.in', 'phone' => null, 'opportunity_id' => $signalOf('C')]);
        $this->who['Anita'] = $this->person($this->co['D'], ['full_name' => 'Anita Rao', 'email' => null, 'phone' => null]);
        $this->who['Asha'] = $this->person($this->co['E'], ['full_name' => 'Asha Mehta', 'email' => 'asha@greenfield.example.in', 'phone' => null, 'source' => 'manual', 'location' => 'Pune']);
    }

    private function api(string $url)
    {
        return $this->getJson($url, $this->admin['h']);
    }

    /** @return list<int> */
    private function ids($response, string $key = 'data'): array
    {
        return array_map('intval', array_column($response->json($key), 'id'));
    }

    /** @return list<int> */
    private function companyIds(string $query = ''): array
    {
        return $this->ids($this->api(self::COMPANIES . '?per_page=100' . $query)->assertOk());
    }

    /** @return list<int> */
    private function personIds(string $query = ''): array
    {
        return $this->ids($this->api(self::PEOPLE . '?per_page=100' . $query)->assertOk());
    }

    /** @param list<string> $letters @return list<int> */
    private function of(array $letters): array
    {
        return array_map(fn (string $l) => $this->co[$l], $letters);
    }

    /** @param list<string> $names @return list<int> */
    private function persons(array $names): array
    {
        return array_map(fn (string $n) => $this->who[$n], $names);
    }

    /** @param list<array<string, mixed>> $options @return array<string, int> */
    private function counts(array $options): array
    {
        return array_column($options, 'count', 'value');
    }

    private function sameIds(array $expected, array $actual, string $why = ''): void
    {
        $this->assertEqualsCanonicalizing($expected, $actual, $why);
    }

    // ── companies: each filter alone ─────────────────────────────────────────────

    public function test_each_filter_alone_narrows_the_companies(): void
    {
        $this->sameIds($this->of(['A', 'B', 'C', 'D', 'E']), $this->companyIds());
        $this->sameIds($this->of(['A', 'B']), $this->companyIds("&product={$this->scholar}"), 'a product, also by its key');
        $this->sameIds($this->of(['C']), $this->companyIds("&product={$this->eb}"), 'a shared product');
        $this->sameIds($this->of(['A']), $this->companyIds("&sub_product={$this->admissions}"));
        $this->sameIds($this->of(['B']), $this->companyIds('&icp=b2c'));
        $this->sameIds($this->of(['C']), $this->companyIds('&icp=B2G'), 'an ICP in any case');
        $this->sameIds($this->of(['A']), $this->companyIds('&region=nagpur'));
        $this->sameIds($this->of(['A', 'B', 'C']), $this->companyIds('&region=India'), 'a region in any case');
        $this->sameIds($this->of(['B', 'E']), $this->companyIds('&region=' . urlencode('  gujarat ')), 'a signal\'s state and a company\'s own location');
        $this->sameIds($this->of(['E']), $this->companyIds('&region=surat'), 'a part of a free-text location');
    }

    public function test_two_filters_are_anded_not_ored(): void
    {
        $this->sameIds($this->of(['B']), $this->companyIds("&product={$this->scholar}&icp=b2c"));
        $this->sameIds($this->of(['B']), $this->companyIds("&product={$this->scholar}&region=gujarat"));
        $this->sameIds($this->of(['A']), $this->companyIds("&product={$this->scholar}&sub_product={$this->admissions}&icp=b2b&region=nagpur"));
        $this->sameIds([], $this->companyIds("&product={$this->eb}&icp=b2b"), 'Enterprise Brain and b2b: nobody (an OR would list four)');
        $this->sameIds([], $this->companyIds("&product={$this->scholar}&region=delhi"));
    }

    public function test_not_recorded_is_the_value_none_and_means_an_empty_set(): void
    {
        $this->sameIds($this->of(['D', 'E']), $this->companyIds('&product=none'));
        $this->sameIds($this->of(['B', 'C', 'D', 'E']), $this->companyIds('&sub_product=none'), 'B has a product by its key but no sub-product');
        $this->sameIds($this->of(['D', 'E']), $this->companyIds('&icp=none'));
        $this->sameIds($this->of(['D']), $this->companyIds('&region=none'), 'E has a location, so it has a region');
        $this->sameIds($this->of(['D', 'E']), $this->companyIds('&product=NONE&icp=none'), 'two at once, any case');
        $this->sameIds($this->of(['D']), $this->companyIds('&product=none&icp=none&region=none&sub_product=none'));
        $this->sameIds($this->of(['B']), $this->companyIds('&sub_product=none&icp=b2c'), 'none beside a real value');
    }

    public function test_an_unknown_value_matches_nothing_and_is_not_an_error_and_any_combination_is_accepted(): void
    {
        foreach (['&product=99999', '&product=abc', '&sub_product=offer:NOPE-1', '&icp=b2x', '&region=atlantis', '&product[]=1&region[]=x'] as $query) {
            $r = $this->api(self::COMPANIES . '?per_page=100' . $query)->assertOk();
            if (str_contains($query, '[]')) {
                $this->assertCount(5, $r->json('data'), 'a value that is not text counts as not chosen');

                continue;
            }
            $this->assertSame([], $r->json('data'), $query);
            $this->assertSame(0, $r->json('meta.total'));
            $this->assertSame(['all' => 0, 'reachable' => 0, 'needs_details' => 0, 'blocked' => 0, 'found' => 0, 'manual' => 0, 'mine' => 0], $r->json('summary'));
            $this->assertArrayHasKey('facets', $r->json());
        }
        // The cascade is the screen's job: the API takes a sub-product of another product without complaint.
        $this->sameIds([], $this->companyIds("&product={$this->eb}&sub_product={$this->admissions}"));
        $this->sameIds($this->of(['A']), $this->companyIds("&product={$this->scholar}&sub_product={$this->admissions}"));
    }

    // ── companies: the options with counts ───────────────────────────────────────

    public function test_the_answer_carries_the_options_with_their_counts_and_every_old_field_is_still_there(): void
    {
        $r = $this->api(self::COMPANIES . '?per_page=100')->assertOk();

        $this->assertSame(['status', 'data', 'meta', 'summary', 'facets'], array_keys($r->json()), 'the old keys in their old order, facets after them');
        $this->assertSame(['all' => 5, 'reachable' => 3, 'needs_details' => 2, 'blocked' => 0, 'found' => 4, 'manual' => 1, 'mine' => 0], $r->json('summary'));
        $f = $r->json('facets');
        $this->assertSame(['products', 'subProducts', 'icps', 'regions', 'notRecorded', 'active'], array_keys($f));
        $this->assertSame([['value' => (string) $this->eb, 'label' => 'Enterprise Brain', 'count' => 1], ['value' => (string) $this->scholar, 'label' => 'Scholar K-12', 'count' => 2]], $f['products'], 'by name');
        $this->assertSame([['value' => (string) $this->admissions, 'label' => 'Scholar Admissions', 'count' => 1, 'productId' => $this->scholar]], $f['subProducts']);
        $this->assertSame([['value' => 'b2b', 'label' => 'B2B: Private organisations', 'count' => 1], ['value' => 'b2c', 'label' => 'B2C: Consumers', 'count' => 1], ['value' => 'b2g', 'label' => 'B2G: Government and tenders', 'count' => 1]], $f['icps']);
        $this->assertSame(['india' => 3, 'gujarat' => 2, 'delhi' => 1, 'maharashtra' => 1, 'nagpur' => 1, 'surat' => 1], $this->counts($f['regions']), 'by count, then name');
        $this->assertSame(['India', 'Gujarat', 'Delhi', 'Maharashtra', 'Nagpur', 'Surat'], array_column($f['regions'], 'label'));
        $this->assertSame(['products' => 2, 'subProducts' => 4, 'icps' => 2, 'regions' => 1], $f['notRecorded']);
        $this->assertSame(['product' => null, 'subProduct' => null, 'icp' => null, 'region' => null], $f['active']);
    }

    public function test_each_facet_is_counted_with_the_other_three_applied_and_never_with_itself(): void
    {
        // Choose b2b: only Riverdale is left. Products are counted over THAT (Scholar 1, not 2: Sunrise is b2c), yet Enterprise Brain is not offered (nobody b2b has it).
        $f = $this->api(self::COMPANIES . '?icp=b2b')->json('facets');
        $this->assertSame([(string) $this->scholar => 1], $this->counts($f['products']), 'a naive count over everything would say Scholar 2 and Enterprise Brain 1');
        $this->assertSame(['b2b' => 1, 'b2c' => 1, 'b2g' => 1], $this->counts($f['icps']), 'the ICP list ignores the ICP choice: switching to another ICP is one click away');

        // Choose Scholar: ICPs are counted over Riverdale and Sunrise; the product list ignores the product choice.
        $f = $this->api(self::COMPANIES . "?product={$this->scholar}")->json('facets');
        $this->assertSame(['b2b' => 1, 'b2c' => 1], $this->counts($f['icps']), 'b2g is Enterprise Brain\'s: it has no row here');
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 2], $this->counts($f['products']));
        $this->assertSame(0, $f['notRecorded']['icps']);
        $this->assertSame(['india' => 2, 'gujarat' => 1, 'maharashtra' => 1, 'nagpur' => 1], $this->counts($f['regions']));

        // Two chosen: each list is counted with the OTHER two (and the unchosen) applied.
        $f = $this->api(self::COMPANIES . "?product={$this->scholar}&icp=b2c")->json('facets');
        $this->assertSame(['b2b' => 1, 'b2c' => 1], $this->counts($f['icps']), 'over the Scholar rows, ignoring its own choice');
        $this->assertSame([(string) $this->scholar => 1], $this->counts($f['products']), 'over the b2c rows: only Sunrise, a Scholar company');
        $this->assertSame(['gujarat' => 1, 'india' => 1], $this->counts($f['regions']), 'only Sunrise is Scholar and b2c (equal counts: by name)');
    }

    public function test_the_chosen_option_stays_listed_at_zero_and_not_recorded_counts_follow_the_other_filters(): void
    {
        $f = $this->api(self::COMPANIES . "?product={$this->eb}&icp=b2c")->json('facets');

        $this->assertSame([(string) $this->eb => 0, (string) $this->scholar => 1], $this->counts($f['products']), 'no Enterprise Brain row is b2c: the chosen product stays at zero, beside the product of the b2c company');
        $this->assertSame('Enterprise Brain', collect($f['products'])->firstWhere('value', (string) $this->eb)['label']);
        $this->assertSame(['b2c' => 0, 'b2g' => 1], $this->counts($f['icps']), 'over the Enterprise Brain rows; b2c is chosen, so it keeps its place at zero, in the B2B, B2C, B2G order');
        $this->assertSame(['product' => (string) $this->eb, 'subProduct' => null, 'icp' => 'b2c', 'region' => null], $f['active']);

        $n = $this->api(self::COMPANIES . '?icp=none')->json('facets');
        $this->assertSame(['products' => 2, 'subProducts' => 2, 'icps' => 2, 'regions' => 1], $n['notRecorded'], 'over Mystery Co and Greenfield: neither has a product, a sub-product or an ICP; Mystery has no region');
        $this->assertSame([], $n['products']);
    }

    public function test_the_chips_counts_follow_the_filters_and_the_chip_does_not_move_the_options(): void
    {
        $plain = $this->api(self::COMPANIES . "?product={$this->scholar}")->assertOk();

        $this->assertSame(['all' => 2, 'reachable' => 1, 'needs_details' => 1, 'blocked' => 0, 'found' => 2, 'manual' => 0, 'mine' => 0], $plain->json('summary'));
        $chip = $this->api(self::COMPANIES . "?product={$this->scholar}&reach=reachable")->assertOk();
        $this->assertSame($this->of(['A']), $this->ids($chip), 'a chip count equals what the chip would show');
        $this->assertSame($plain->json('summary'), $chip->json('summary'), 'the chips keep their numbers when one is chosen');
        $this->assertSame($plain->json('facets'), $chip->json('facets'), 'the options are counted before the chip');
        $this->assertSame($this->of(['B']), $this->ids($this->api(self::COMPANIES . "?product={$this->scholar}&reach=needs_details")));
        $this->assertSame($this->ids($this->api(self::COMPANIES . "?product={$this->scholar}")), $this->ids($this->api(self::COMPANIES . "?product={$this->scholar}&reach=all")));
    }

    public function test_the_search_sort_and_paging_work_with_the_filters_and_paging_does_not_change_the_options(): void
    {
        $this->assertSame($this->of(['A']), $this->ids($this->api(self::COMPANIES . "?product={$this->scholar}&search=riverdale")));
        $this->assertSame(1, $this->api(self::COMPANIES . "?product={$this->scholar}&search=riverdale")->json('summary.all'));
        $this->assertSame([], $this->ids($this->api(self::COMPANIES . "?product={$this->eb}&search=riverdale")));
        // The search is part of the base rows (the chips already follow it), so the options follow it too.
        $f = $this->api(self::COMPANIES . '?search=riverdale')->json('facets');
        $this->assertSame([(string) $this->scholar => 1], $this->counts($f['products']));

        $desc = $this->ids($this->api(self::COMPANIES . "?product={$this->scholar}&sort=name&dir=desc"));
        $this->assertSame($this->of(['B', 'A']), $desc);
        $one = $this->api(self::COMPANIES . "?product={$this->scholar}&sort=name&per_page=1&page=1");
        $two = $this->api(self::COMPANIES . "?product={$this->scholar}&sort=name&per_page=1&page=2");
        $this->assertSame(2, $one->json('meta.total'));
        $this->assertSame(2, $one->json('meta.last_page'));
        $this->assertEqualsCanonicalizing($this->of(['A', 'B']), array_merge($this->ids($one), $this->ids($two)));
        $this->assertSame($one->json('facets'), $two->json('facets'));
        $this->assertSame($this->api(self::COMPANIES . "?product={$this->scholar}")->json('facets'), $one->json('facets'));
    }

    public function test_another_organisations_signals_products_and_regions_are_never_counted_or_matched(): void
    {
        $theirs = $this->master(self::OTHER, 'product', 'Their Product', ['signal_key' => 'theirs']);
        $this->found('Their School', ['product_master_id' => $theirs, 'customer_model' => 'b2c', 'region_scope' => '[{"country":"Japan"}]'], [], self::OTHER);
        // Another organisation's signal on MY company (it should never happen, and must not count if it does).
        $this->makeSignal(self::OTHER, ['company_id' => $this->co['D'], 'customer_model' => 'b2g', 'product_master_id' => $this->eb, 'region_scope' => '[{"country":"Peru"}]']);

        $f = $this->api(self::COMPANIES)->json('facets');

        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 2], $this->counts($f['products']));
        $this->assertArrayNotHasKey('japan', $this->counts($f['regions']));
        $this->assertArrayNotHasKey('peru', $this->counts($f['regions']));
        $this->assertSame(['b2b' => 1, 'b2c' => 1, 'b2g' => 1], $this->counts($f['icps']));
        $this->assertSame([], $this->companyIds("&product={$theirs}"), 'their product id matches nothing here');
        $this->sameIds($this->of(['D', 'E']), $this->companyIds('&icp=none'), 'their b2g signal on my company says nothing');
    }

    public function test_a_deleted_signal_company_or_person_is_never_counted(): void
    {
        Schema::table('g2g_company_opportunities', fn (Blueprint $t) => $t->dateTime('deleted_at')->nullable());
        // Sunrise's only signal is deleted: it has nothing recorded now.
        DB::table('g2g_company_opportunities')->where('company_id', $this->co['B'])->update(['deleted_at' => now()]);
        // A deleted company with a rich signal, and a deleted person: neither is listed or counted.
        $ghost = $this->found('Ghost School', ['product_master_id' => $this->eb, 'customer_model' => 'b2g', 'region_scope' => '[{"country":"Chile"}]']);
        DB::table('g2g_companies')->where('id', $ghost)->update(['deleted_at' => now()]);
        $this->person($this->co['C'], ['full_name' => 'Gone Person', 'email' => 'gone@education.example.in', 'location' => 'Lima', 'deleted_at' => now()]);

        $f = $this->api(self::COMPANIES)->json('facets');

        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 1], $this->counts($f['products']), 'Sunrise\'s deleted signal no longer makes it a Scholar company');
        $this->assertSame(['b2b' => 1, 'b2g' => 1], $this->counts($f['icps']));
        $this->assertArrayNotHasKey('chile', $this->counts($f['regions']));
        $this->assertArrayNotHasKey('lima', $this->counts($this->api(self::PEOPLE)->json('facets.regions')));
        $this->sameIds($this->of(['B', 'D', 'E']), $this->companyIds('&product=none'));
        $this->assertNotContains($ghost, $this->companyIds());
    }

    // ── people ───────────────────────────────────────────────────────────────────

    public function test_each_filter_narrows_the_people_by_their_company_their_own_signal_and_their_own_place(): void
    {
        $this->sameIds($this->persons(['Meera', 'Kiran', 'Ravi', 'Anita', 'Asha']), $this->personIds());
        $this->sameIds($this->persons(['Meera', 'Kiran']), $this->personIds("&product={$this->scholar}"));
        $this->sameIds($this->persons(['Ravi']), $this->personIds("&product={$this->eb}"));
        $this->sameIds($this->persons(['Meera', 'Kiran']), $this->personIds("&sub_product={$this->admissions}"));
        $this->sameIds($this->persons(['Ravi']), $this->personIds('&icp=b2g'));
        $this->sameIds($this->persons(['Meera', 'Kiran']), $this->personIds('&region=nagpur'));
        $this->sameIds($this->persons(['Asha']), $this->personIds('&region=pune'), 'a person\'s own location');
        $this->sameIds($this->persons(['Asha']), $this->personIds('&region=gujarat'), 'their company\'s location');
        $this->sameIds($this->persons(['Meera', 'Kiran', 'Ravi']), $this->personIds('&region=india'));
        $this->sameIds($this->persons(['Anita', 'Asha']), $this->personIds('&product=none'));
        $this->sameIds($this->persons(['Anita']), $this->personIds('&region=none'));
        $this->sameIds($this->persons(['Anita', 'Asha']), $this->personIds('&icp=none'));
        $this->sameIds($this->persons(['Meera', 'Kiran']), $this->personIds("&product={$this->scholar}&icp=b2b"));
        $this->sameIds([], $this->personIds("&product={$this->scholar}&icp=b2g"));
        $this->sameIds([], $this->personIds('&region=atlantis'));
    }

    public function test_a_persons_own_signal_counts_even_when_it_is_another_companys(): void
    {
        // Anita (Mystery Co) was found from Sunrise's signal: she is b2c and Scholar through it.
        $signal = (int) DB::table('g2g_company_opportunities')->where('company_id', $this->co['B'])->value('id');
        DB::table('g2g_signal_contacts')->where('id', $this->who['Anita'])->update(['opportunity_id' => $signal]);

        $this->sameIds($this->persons(['Anita']), $this->personIds('&icp=b2c'));
        $this->sameIds($this->persons(['Meera', 'Kiran', 'Anita']), $this->personIds("&product={$this->scholar}"));
        $this->sameIds($this->of(['B']), $this->companyIds('&icp=b2c'), 'the company itself is unchanged');
    }

    public function test_the_peoples_options_summary_and_search_follow_the_filters(): void
    {
        $r = $this->api(self::PEOPLE . '?per_page=100')->assertOk();
        $this->assertSame(['status', 'data', 'meta', 'summary', 'facets'], array_keys($r->json()));
        $f = $r->json('facets');
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 2], $this->counts($f['products']));
        $this->assertSame(['products' => 2, 'subProducts' => 3, 'icps' => 2, 'regions' => 1], $f['notRecorded']);
        $this->assertSame(['india' => 3, 'maharashtra' => 2, 'nagpur' => 2, 'delhi' => 1, 'gujarat' => 1, 'pune' => 1, 'surat' => 1], $this->counts($f['regions']));

        $s = $this->api(self::PEOPLE . "?product={$this->scholar}")->assertOk();
        $this->assertSame(['all' => 2, 'reachable' => 2, 'needs_details' => 0, 'blocked' => 0, 'found' => 2, 'manual' => 0, 'mine' => 0], $s->json('summary'));
        $this->assertSame($this->persons(['Kiran']), $this->ids($this->api(self::PEOPLE . "?product={$this->scholar}&reach=email_only")));
        $this->assertSame($s->json('facets'), $this->api(self::PEOPLE . "?product={$this->scholar}&reach=email_only")->json('facets'), 'the chip does not move the options');
        $this->assertSame([(string) $this->scholar => 1], $this->counts($this->api(self::PEOPLE . '?search=meera')->json('facets.products')), 'the search is part of the base rows');
        $this->assertSame($this->persons(['Meera']), $this->ids($this->api(self::PEOPLE . "?product={$this->scholar}&search=meera")));
        $this->assertSame($this->persons(['Kiran', 'Meera']), $this->ids($this->api(self::PEOPLE . "?product={$this->scholar}&sort=name")), 'Kiran before Meera');
    }

    public function test_the_company_filter_of_the_people_list_and_the_company_page_are_unchanged(): void
    {
        $r = $this->api(self::PEOPLE . '?company_id=' . $this->co['A'])->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame([(string) $this->scholar => 2], $this->counts($r->json('facets.products')));
        $this->assertSame($this->persons(['Meera']), $this->ids($this->api(self::PEOPLE . '?company_id=' . $this->co['A'] . '&icp=b2b&search=meera')));

        $page = $this->api(self::COMPANIES . '/' . $this->co['A'])->assertOk();
        $this->assertArrayNotHasKey('facets', $page->json(), 'the company page has no filters');
        $this->assertArrayNotHasKey('facets', $page->json('data'));
        $this->assertCount(2, $page->json('data.people'));
    }

    // ── a host that lacks columns or tables ──────────────────────────────────────

    public function test_a_host_without_the_product_tables_or_the_scope_columns_answers_with_what_it_has(): void
    {
        Schema::drop('g2g_product_master');
        Schema::drop('g2g_product_offers');
        Schema::table('g2g_company_opportunities', fn (Blueprint $t) => $t->dropColumn(['region_scope', 'relevant_offer_id']));

        $c = $this->api(self::COMPANIES . '?per_page=100')->assertOk();
        $p = $this->api(self::PEOPLE . '?per_page=100')->assertOk();

        $this->assertCount(5, $c->json('data'));
        $this->assertCount(5, $p->json('data'));
        $f = $c->json('facets');
        $this->assertSame([], $f['products']);
        $this->assertSame(['offer:SCH-01' => 1], $this->counts($f['subProducts']), 'the code alone is all that is known');
        $this->assertSame(['b2b' => 1, 'b2c' => 1, 'b2g' => 1], $this->counts($f['icps']));
        $this->assertSame(['gujarat' => 1, 'surat' => 1], $this->counts($f['regions']), 'the company\'s own place is still read');
        $this->assertSame(5, $f['notRecorded']['products']);
        $this->assertSame([], $this->companyIds('&product=1'));
        $this->sameIds($this->of(['A']), $this->companyIds('&sub_product=offer:SCH-01'));
        $this->sameIds($this->of(['A', 'B', 'C', 'D', 'E']), $this->companyIds('&product=none'));
    }

    // ═══ the Messages queue ══════════════════════════════════════════════════════

    /** @var array<string, int> message id by name */
    private array $msg = [];

    /** @return array{account: int, contact: int} a GTM prospect, as writing to someone leaves it */
    private function gtmContact(int $tenant = self::T): array
    {
        $account = (int) DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => 'Acct ' . uniqid(), 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $contact = (int) DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => $tenant, 'account_id' => $account, 'full_name' => 'Prospect ' . uniqid(), 'email' => uniqid() . '@example.test', 'status' => 'active', 'source' => 'research', 'created_at' => now(), 'updated_at' => now()]);

        return ['account' => $account, 'contact' => $contact];
    }

    /** @param array<string, mixed> $over */
    private function message(string $name, array $gtm, array $over = [], int $tenant = self::T): int
    {
        $id = (int) DB::table('gtm_outreach_messages')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'account_id' => $gtm['account'], 'contact_id' => $gtm['contact'], 'subject' => "Subject {$name}", 'body' => 'A body long enough to be written down here.',
            'status' => 'draft', 'step' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->msg[$name] = $id;

        return $id;
    }

    /**
     * m1 draft to Meera (Riverdale: Scholar, Scholar Admissions, b2b, Nagpur), no product of its own
     * m2 approved to Meera, its own product Enterprise Brain
     * m3 sent to Ravi (Education Dept: Enterprise Brain, b2g, Delhi), its own product the sub-product Scholar Admissions
     * m4 draft to a prospect who is no signal contact, no product
     * m5 draft to that prospect, its own product Scholar
     */
    private function messagesWorld(): void
    {
        $meera = $this->gtmContact();
        $ravi = $this->gtmContact();
        $plain = $this->gtmContact();
        DB::table('g2g_signal_contacts')->where('id', $this->who['Meera'])->update(['gtm_contact_id' => $meera['contact']]);
        DB::table('g2g_signal_contacts')->where('id', $this->who['Ravi'])->update(['gtm_contact_id' => $ravi['contact']]);
        $this->message('m1', $meera);
        $this->message('m2', $meera, ['status' => 'approved', 'product_master_id' => $this->eb]);
        $this->message('m3', $ravi, ['status' => 'sent', 'product_master_id' => $this->admissions, 'sent_at' => now()]);
        $this->message('m4', $plain);
        $this->message('m5', $plain, ['product_master_id' => $this->scholar]);
        $this->msg['meera'] = $meera['contact'];
    }

    /** @param list<string> $names @return list<int> */
    private function msgs(array $names): array
    {
        return array_map(fn (string $n) => $this->msg[$n], $names);
    }

    /** @return list<int> */
    private function messageIds(string $query = ''): array
    {
        return $this->ids($this->api(self::MESSAGES . '?x=1' . $query)->assertOk(), 'data.items');
    }

    public function test_with_no_filter_the_messages_answer_is_what_it_always_was_plus_the_options(): void
    {
        $this->messagesWorld();
        $other = $this->gtmContact(self::OTHER);
        $this->message('theirs', $other, [], self::OTHER);

        $r = $this->api(self::MESSAGES)->assertOk();

        $legacyItems = GtmOutreachMessage::where('gtm_outreach_messages.sub_institute_id', self::T)
            ->join('gtm_contacts as c', fn ($j) => $j->on('c.id', '=', 'gtm_outreach_messages.contact_id')->where('c.sub_institute_id', '=', self::T))
            ->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_outreach_messages.account_id')->where('a.sub_institute_id', '=', self::T))
            ->select('gtm_outreach_messages.*', 'c.full_name as contact_name', 'c.email as contact_email', 'c.status as contact_status', 'a.name as account_name')
            ->orderByDesc('gtm_outreach_messages.id')->limit(200)->get();
        $legacyCounts = GtmOutreachMessage::where('sub_institute_id', self::T)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $this->assertSame(json_decode(json_encode(['items' => $legacyItems, 'counts' => $legacyCounts]), true), Arr::except($r->json('data'), ['facets']), 'items and counts: exactly the old answer');
        $this->assertSame(['status', 'data'], array_keys($r->json()));
        $this->assertSame(['items', 'counts', 'facets'], array_keys($r->json('data')));
        $this->assertEquals(['draft' => 3, 'approved' => 1, 'sent' => 1], $r->json('data.counts'));
        $this->assertSame($this->msgs(['m5', 'm4', 'm3', 'm2', 'm1']), $this->ids($r, 'data.items'), 'newest first, never the other organisation\'s');
        $this->assertSame('Subject m3', $r->json('data.items.2.subject'));
        $this->assertArrayHasKey('contact_name', $r->json('data.items.0'));

        // The old status and contact filters answer as before too.
        $this->assertSame($this->msgs(['m5', 'm4', 'm1']), $this->messageIds('&status=draft'));
        $this->assertSame($this->msgs(['m2', 'm1']), $this->messageIds('&contact_id=' . $this->msg['meera']));
        $this->assertSame($this->msgs(['m5', 'm4', 'm3', 'm2', 'm1']), $this->messageIds('&status=unheard-of'));
    }

    public function test_each_filter_narrows_the_messages_and_a_messages_own_product_wins(): void
    {
        $this->messagesWorld();

        $this->sameIds($this->msgs(['m1', 'm3', 'm5']), $this->messageIds("&product={$this->scholar}"), 'm2 is Meera\'s but its own product is Enterprise Brain');
        $this->sameIds($this->msgs(['m2']), $this->messageIds("&product={$this->eb}"), 'm3 goes to an Enterprise Brain contact but is about a Scholar sub-product');
        $this->sameIds($this->msgs(['m1', 'm2', 'm3']), $this->messageIds("&sub_product={$this->admissions}"), 'a sub-product master id is that sub-product; the rest come from the person');
        $this->sameIds($this->msgs(['m1', 'm2']), $this->messageIds('&icp=b2b'), 'inherited from the person');
        $this->sameIds($this->msgs(['m3']), $this->messageIds('&icp=b2g'));
        $this->sameIds($this->msgs(['m1', 'm2']), $this->messageIds('&region=nagpur'));
        $this->sameIds($this->msgs(['m3']), $this->messageIds('&region=delhi'));
        $this->sameIds($this->msgs(['m1']), $this->messageIds("&product={$this->scholar}&icp=b2b"), 'two together');
        $this->sameIds($this->msgs(['m2']), $this->messageIds("&product={$this->eb}&icp=b2b&region=nagpur"));
        $this->sameIds([], $this->messageIds("&product={$this->eb}&icp=b2g"), 'AND, not OR: Enterprise Brain is m2 and b2g is m3');
    }

    public function test_not_recorded_unknown_values_and_any_combination_for_messages(): void
    {
        $this->messagesWorld();

        $this->sameIds($this->msgs(['m4']), $this->messageIds('&product=none'));
        $this->sameIds($this->msgs(['m4', 'm5']), $this->messageIds('&icp=none'), 'a contact that is no signal contact has nothing recorded, though m5 has its own product');
        $this->sameIds($this->msgs(['m4', 'm5']), $this->messageIds('&region=none'));
        $this->sameIds($this->msgs(['m4', 'm5']), $this->messageIds('&sub_product=none'));
        $this->sameIds($this->msgs(['m5']), $this->messageIds('&icp=none&product=' . $this->scholar));
        foreach (['&product=99999', '&icp=b2x', '&region=atlantis', '&sub_product=offer:NOPE', "&product={$this->eb}&sub_product={$this->admissions}&icp=none"] as $query) {
            $r = $this->api(self::MESSAGES . '?x=1' . $query)->assertOk();
            $this->assertSame([], $r->json('data.items'), $query);
            $this->assertSame([], $r->json('data.counts'), $query . ': an empty answer counts nothing');
            $this->assertArrayHasKey('facets', $r->json('data'));
        }
    }

    public function test_the_status_counts_follow_the_filters_and_the_status_tab_does_not_move_them_or_the_options(): void
    {
        $this->messagesWorld();

        $r = $this->api(self::MESSAGES . "?product={$this->scholar}")->assertOk();
        $this->assertEquals(['draft' => 2, 'sent' => 1], $r->json('data.counts'), 'm1 and m5 are drafts, m3 is sent: the tabs show what the tabs would list');
        $this->assertSame(count($r->json('data.items')), array_sum($r->json('data.counts')));

        $tab = $this->api(self::MESSAGES . "?product={$this->scholar}&status=sent")->assertOk();
        $this->assertSame($this->msgs(['m3']), $this->ids($tab, 'data.items'));
        $this->assertSame($r->json('data.counts'), $tab->json('data.counts'), 'a tab does not change the numbers of the tabs');
        $this->assertSame($r->json('data.facets'), $tab->json('data.facets'), 'nor the options');

        $contact = $this->api(self::MESSAGES . "?product={$this->scholar}&contact_id=" . $this->msg['meera'])->assertOk();
        $this->assertSame($this->msgs(['m1']), $this->ids($contact, 'data.items'));
        $this->assertSame($r->json('data.counts'), $contact->json('data.counts'), 'counts and options are the queue\'s, as before: the contact narrows the rows only');
        $this->assertSame($r->json('data.facets'), $contact->json('data.facets'));
    }

    public function test_the_messages_options_are_counted_with_the_other_three_applied_and_never_with_itself(): void
    {
        $this->messagesWorld();

        $plain = $this->api(self::MESSAGES)->json('data.facets');
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 3], $this->counts($plain['products']), 'm1, m3 and m5 are Scholar; m2 is Enterprise Brain');
        $this->assertSame([(string) $this->admissions => 3], $this->counts($plain['subProducts']));
        $this->assertSame(['b2b' => 2, 'b2g' => 1], $this->counts($plain['icps']));
        $this->assertSame(['india' => 3, 'maharashtra' => 2, 'nagpur' => 2, 'delhi' => 1], $this->counts($plain['regions']));
        $this->assertSame(['products' => 1, 'subProducts' => 2, 'icps' => 2, 'regions' => 2], $plain['notRecorded']);

        // Choose b2b: products are counted over m1 and m2 (Scholar 1, not 3), and the ICP list ignores the choice.
        $f = $this->api(self::MESSAGES . '?icp=b2b')->json('data.facets');
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 1], $this->counts($f['products']), 'the naive count would say Scholar 3');
        $this->assertSame(['b2b' => 2, 'b2g' => 1], $this->counts($f['icps']));
        $this->assertSame(0, $f['notRecorded']['regions'], 'm4 and m5 have no region, but they are not b2b');
        // Choose Scholar: ICPs are counted over m1, m3, m5.
        $f = $this->api(self::MESSAGES . "?product={$this->scholar}")->json('data.facets');
        $this->assertSame(['b2b' => 1, 'b2g' => 1], $this->counts($f['icps']));
        $this->assertSame(1, $f['notRecorded']['icps'], 'm5');
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 3], $this->counts($f['products']), 'the product list ignores the product choice');
    }

    public function test_another_organisations_messages_and_people_are_never_behind_a_contact_or_counted(): void
    {
        $this->messagesWorld();
        $theirCompany = $this->found('Their School', ['product_master_id' => $this->eb, 'customer_model' => 'b2c', 'region_scope' => '[{"country":"Japan"}]'], [], self::OTHER);
        $theirGtm = $this->gtmContact(self::OTHER);
        $theirPerson = $this->person($theirCompany, ['gtm_contact_id' => $theirGtm['contact'], 'location' => 'Tokyo'], self::OTHER);
        $this->message('theirs', $theirGtm, [], self::OTHER);
        // My message to a contact id that only another organisation's person is linked to: that person is not behind it.
        $mineToTheirContact = $this->message('stray', ['account' => $this->gtmContact()['account'], 'contact' => $theirGtm['contact']]);

        $f = $this->api(self::MESSAGES)->json('data.facets');

        $this->assertArrayNotHasKey('japan', $this->counts($f['regions']));
        $this->assertArrayNotHasKey('tokyo', $this->counts($f['regions']), 'the place of their person does not leak through a contact id');
        $this->assertArrayNotHasKey('b2c', $this->counts($f['icps']));
        $this->assertNotContains($this->msg['theirs'], $this->messageIds());
        $this->assertNotNull($theirPerson);
        $this->sameIds($this->msgs(['m4']), $this->messageIds('&product=none'), 'the stray message has no contact of mine, so the queue does not list it, as before');
        $this->assertNotContains($mineToTheirContact, $this->messageIds());
    }

    public function test_a_deleted_person_is_not_behind_a_message_and_a_host_without_the_product_pointer_still_answers(): void
    {
        $this->messagesWorld();
        DB::table('g2g_signal_contacts')->where('id', $this->who['Ravi'])->update(['deleted_at' => now()]);

        $this->sameIds($this->msgs(['m3', 'm4', 'm5']), $this->messageIds('&icp=none'), 'm3 lost its person: its own product is all that is left');

        DB::table('g2g_signal_contacts')->where('id', $this->who['Ravi'])->update(['deleted_at' => null]);
        Schema::table('gtm_outreach_messages', function (Blueprint $t) {
            $t->dropIndex('gtm_outreach_tenant_product');
            $t->dropColumn('product_master_id');
        });
        $r = $this->api(self::MESSAGES)->assertOk();
        $this->assertCount(5, $r->json('data.items'));
        $this->sameIds($this->msgs(['m1', 'm2']), $this->messageIds("&product={$this->scholar}&icp=b2b"), 'with no own product, the person decides: both of Meera\'s messages are Scholar');
        $this->sameIds($this->msgs(['m4', 'm5']), $this->messageIds('&product=none'));
    }

    public function test_a_host_without_the_signal_tables_answers_the_queue_with_nothing_recorded(): void
    {
        $this->messagesWorld();
        Schema::drop('g2g_signal_contacts');
        Schema::drop('g2g_company_opportunities');

        $r = $this->api(self::MESSAGES)->assertOk();

        $this->assertCount(5, $r->json('data.items'));
        $this->assertSame([(string) $this->eb => 1, (string) $this->scholar => 2], $this->counts($r->json('data.facets.products')), 'only the messages\' own products are left');
        $this->assertSame(5, $r->json('data.facets.notRecorded.icps'));
    }

    public function test_the_filters_need_the_same_rights_as_before(): void
    {
        $this->messagesWorld();
        $this->getJson(self::MESSAGES . "?product={$this->scholar}")->assertStatus(401);
        $this->getJson(self::COMPANIES . "?product={$this->scholar}")->assertStatus(401);
        $this->getJson(self::PEOPLE . '?icp=b2b')->assertStatus(401);
    }
}
