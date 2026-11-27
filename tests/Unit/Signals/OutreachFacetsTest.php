<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Outreach\OutreachFacets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The four filters of the Outreach tab as one definition (OutreachFacets): the sets a company, a person and a message have for Product, Sub-product, ICP and Region, how a filter passes a
 * row (ANDed, `none` = Not recorded, an unknown value matches nothing) and the options with counts (each facet counted with the other three applied and not itself).
 *
 * Plain in-memory sqlite tables with the real column names; every row below is one line of the contract's table.
 */
class OutreachFacetsTest extends TestCase
{
    private const T = 1;

    private const OTHER = 2;

    private int $scholar;

    private int $eb;

    private int $admissions;

    private int $ebSearch;

    private int $item;

    // ── the world ────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables();
        // Products: Scholar K-12 (the organisation's own), Enterprise Brain (shared, tenant 0); a sub-product under each (their offers SCH-01 and EB-01 point at them); G2G-02 is an
        // offer with NO master; an "item" is a catalogue-only master that is neither.
        $this->scholar = $this->master(self::T, 'product', 'Scholar K-12', ['product_key' => 'scholar-k12', 'signal_key' => 'scholar', 'portfolio_name' => 'Scholar K-12']);
        $this->eb = $this->master(0, 'product', 'Enterprise Brain', ['product_key' => 'enterprise-brain', 'signal_key' => 'eb', 'portfolio_name' => 'EB']);
        $o1 = $this->offer(self::T, 'SCH-01', 'Scholar admissions offer', 'Scholar K-12');
        $o2 = $this->offer(null, 'EB-01', 'EB search offer', 'EB');
        $this->offer(null, 'G2G-02', 'G2G procurement', 'G2G');
        $this->admissions = $this->master(self::T, 'sub_product', 'Scholar Admissions', ['product_key' => 'scholar-adm', 'parent_id' => $this->scholar, 'offer_row_id' => $o1]);
        $this->ebSearch = $this->master(0, 'sub_product', 'EB Search', ['product_key' => 'eb-search', 'parent_id' => $this->eb, 'offer_row_id' => $o2]);
        $this->item = $this->master(self::T, 'item', 'Onboarding service', ['product_key' => 'onboarding']);
    }

    private function tables(): void
    {
        Schema::create('g2g_product_master', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_key', 50);
            $t->string('kind', 12);
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name', 191);
            $t->string('status', 12)->default('active');
            $t->string('signal_key', 20)->nullable();
            $t->string('portfolio_name', 50)->nullable();
            $t->unsignedBigInteger('offer_row_id')->nullable();
        });
        Schema::create('g2g_product_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 50);
            $t->string('name', 191);
            $t->string('parent_product', 50);
        });
        Schema::create('g2g_company_opportunities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_master_id')->nullable();
            $t->string('product_key', 40)->nullable();
            $t->string('offer_id', 80)->nullable();
            $t->string('relevant_offer_id', 50)->nullable();
            $t->string('customer_model', 8)->nullable();
            $t->text('region_scope')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        Schema::create('g2g_companies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('name', 191)->default('Co');
            $t->string('state', 100)->nullable();
            $t->string('location', 191)->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        Schema::create('g2g_signal_contacts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('opportunity_id')->nullable();
            $t->string('location', 191)->nullable();
            $t->unsignedBigInteger('gtm_contact_id')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        Schema::create('gtm_outreach_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('product_master_id')->nullable();
            $t->string('status', 20)->default('draft');
        });
    }

    /** @param array<string, mixed> $over */
    private function master(int $tenant, string $kind, string $name, array $over = []): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId($over + ['sub_institute_id' => $tenant, 'kind' => $kind, 'name' => $name, 'product_key' => 'k' . uniqid()]);
    }

    private function offer(?int $tenant, string $code, string $name, string $parent): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => $tenant, 'offer_id' => $code, 'name' => $name, 'parent_product' => $parent]);
    }

    /** @param array<string, mixed> $over */
    private function company(array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_companies')->insertGetId($over + ['sub_institute_id' => $tenant]);
    }

    /** @param array<string, mixed> $over */
    private function signal(int $companyId, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_company_opportunities')->insertGetId($over + ['sub_institute_id' => $tenant, 'company_id' => $companyId]);
    }

    /** @param array<string, mixed> $over */
    private function person(int $companyId, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_signal_contacts')->insertGetId($over + ['sub_institute_id' => $tenant, 'company_id' => $companyId]);
    }

    /** @param array<string, mixed> $over */
    private function message(int $contactId, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('gtm_outreach_messages')->insertGetId($over + ['sub_institute_id' => $tenant, 'contact_id' => $contactId]);
    }

    private function facets(int $tenant = self::T): OutreachFacets
    {
        return new OutreachFacets($tenant);
    }

    /** The sets of one company, read the way a list reads them. @return array<string, array<int|string, mixed>> */
    private function ofCompany(int $companyId, int $tenant = self::T): array
    {
        $row = DB::table('g2g_companies')->where('id', $companyId)->first(['id', 'state', 'location']);

        return $this->facets($tenant)->companySets([$row])[$companyId];
    }

    /** @param array<string, array<int|string, mixed>> $sets @return list<string> the values of a facet, sorted */
    private function values(array $sets, string $facet): array
    {
        $keys = array_map('strval', array_keys($sets[$facet]));
        sort($keys);

        return $keys;
    }

    /** @param array<string, array<int|string, mixed>> $sets */
    private function label(array $sets, string $facet, int|string $value): ?string
    {
        return $sets[$facet][$value]['label'] ?? null;
    }

    // ── a company: Product ───────────────────────────────────────────────────────

    public function test_a_signals_product_master_id_is_its_product(): void
    {
        $c = $this->company();
        $this->signal($c, ['product_master_id' => $this->scholar]);

        $sets = $this->ofCompany($c);

        $this->assertSame([(string) $this->scholar], $this->values($sets, 'product'));
        $this->assertSame('Scholar K-12', $this->label($sets, 'product', $this->scholar));
        $this->assertSame([], $sets['subProduct']);
        $this->assertSame([], $sets['icp']);
        $this->assertSame([], $sets['region']);
    }

    public function test_a_sub_product_master_id_on_a_signal_gives_its_parent_product_and_counts_as_the_sub_product(): void
    {
        $c = $this->company();
        $this->signal($c, ['product_master_id' => $this->admissions]);

        $sets = $this->ofCompany($c);

        $this->assertSame([(string) $this->scholar], $this->values($sets, 'product'), 'a sub-product resolves to its parent product');
        $this->assertSame([(string) $this->admissions], $this->values($sets, 'subProduct'));
        $this->assertSame('Scholar Admissions', $this->label($sets, 'subProduct', $this->admissions));
        $this->assertSame($this->scholar, $sets['subProduct'][$this->admissions]['productId']);
    }

    public function test_a_product_key_is_matched_to_a_master_by_signal_key_or_product_key_the_organisations_or_shared(): void
    {
        $bySignalKey = $this->company();
        $this->signal($bySignalKey, ['product_key' => 'scholar']);
        $byProductKey = $this->company();
        $this->signal($byProductKey, ['product_key' => 'Scholar-K12']);
        $shared = $this->company();
        $this->signal($shared, ['product_key' => 'eb']);
        $unknown = $this->company();
        $this->signal($unknown, ['product_key' => 'something-else']);
        $blank = $this->company();
        $this->signal($blank, ['product_key' => '']);
        $item = $this->company();
        $this->signal($item, ['product_key' => 'onboarding']);
        $viaSub = $this->company();
        $this->signal($viaSub, ['product_key' => 'eb-search']);

        $this->assertSame([(string) $this->scholar], $this->values($this->ofCompany($bySignalKey), 'product'));
        $this->assertSame([(string) $this->scholar], $this->values($this->ofCompany($byProductKey), 'product'));
        $this->assertSame([(string) $this->eb], $this->values($this->ofCompany($shared), 'product'), 'a shared (tenant 0) master counts');
        $this->assertSame([], $this->values($this->ofCompany($unknown), 'product'));
        $this->assertSame([], $this->values($this->ofCompany($blank), 'product'));
        $this->assertSame([], $this->values($this->ofCompany($item), 'product'), 'a catalogue item is not a product');
        $this->assertSame([(string) $this->eb], $this->values($this->ofCompany($viaSub), 'product'), 'a key that names a sub-product gives its product');
    }

    public function test_the_master_id_wins_over_the_key_and_a_master_id_that_leads_nowhere_falls_back_to_the_key(): void
    {
        $both = $this->company();
        $this->signal($both, ['product_master_id' => $this->scholar, 'product_key' => 'eb']);
        $dangling = $this->company();
        $this->signal($dangling, ['product_master_id' => 999999, 'product_key' => 'eb']);
        $itemId = $this->company();
        $this->signal($itemId, ['product_master_id' => $this->item, 'product_key' => 'scholar']);

        $this->assertSame([(string) $this->scholar], $this->values($this->ofCompany($both), 'product'));
        $this->assertSame([(string) $this->eb], $this->values($this->ofCompany($dangling), 'product'));
        $this->assertSame([(string) $this->scholar], $this->values($this->ofCompany($itemId), 'product'), 'an item has no product: the key is used');
    }

    public function test_another_organisations_own_master_is_never_used(): void
    {
        $theirs = $this->master(self::OTHER, 'product', 'Their product', ['signal_key' => 'theirs']);
        $c = $this->company();
        $this->signal($c, ['product_master_id' => $theirs, 'product_key' => 'theirs']);

        $this->assertSame([], $this->values($this->ofCompany($c), 'product'));
    }

    // ── a company: Sub-product ───────────────────────────────────────────────────

    public function test_an_offer_code_resolves_to_the_sub_product_master_through_the_offer(): void
    {
        $mine = $this->company();
        $this->signal($mine, ['offer_id' => 'SCH-01']);
        $lower = $this->company();
        $this->signal($lower, ['offer_id' => ' sch-01 ']);
        $shared = $this->company();
        $this->signal($shared, ['relevant_offer_id' => 'EB-01']);

        $a = $this->ofCompany($mine);
        $this->assertSame([(string) $this->admissions], $this->values($a, 'subProduct'));
        $this->assertSame($this->scholar, $a['subProduct'][$this->admissions]['productId']);
        $this->assertSame([(string) $this->admissions], $this->values($this->ofCompany($lower), 'subProduct'), 'a code matches whatever its case');
        $this->assertSame([(string) $this->ebSearch], $this->values($this->ofCompany($shared), 'subProduct'), 'a shared offer and a shared master');
        $this->assertSame([], $a['product'], 'a code alone does not make a product');
    }

    public function test_the_offer_id_is_used_first_and_the_relevant_offer_only_when_it_is_empty(): void
    {
        $both = $this->company();
        $this->signal($both, ['offer_id' => 'SCH-01', 'relevant_offer_id' => 'EB-01']);
        $blank = $this->company();
        $this->signal($blank, ['offer_id' => '  ', 'relevant_offer_id' => 'EB-01']);

        $this->assertSame([(string) $this->admissions], $this->values($this->ofCompany($both), 'subProduct'));
        $this->assertSame([(string) $this->ebSearch], $this->values($this->ofCompany($blank), 'subProduct'));
    }

    public function test_a_code_with_no_master_is_offer_colon_code_labelled_with_the_offers_name_or_the_code(): void
    {
        $named = $this->company();
        $this->signal($named, ['offer_id' => 'G2G-02', 'product_master_id' => $this->scholar]);
        $bare = $this->company();
        $this->signal($bare, ['offer_id' => 'ZZZ-9']);

        $a = $this->ofCompany($named);
        $this->assertSame(['offer:G2G-02'], $this->values($a, 'subProduct'));
        $this->assertSame('G2G procurement', $this->label($a, 'subProduct', 'offer:G2G-02'));
        $this->assertSame($this->scholar, $a['subProduct']['offer:G2G-02']['productId'], 'the signal\'s own product is where it sits when the offer names none');
        $b = $this->ofCompany($bare);
        $this->assertSame(['offer:ZZZ-9'], $this->values($b, 'subProduct'));
        $this->assertSame('ZZZ-9', $this->label($b, 'subProduct', 'offer:ZZZ-9'));
        $this->assertNull($b['subProduct']['offer:ZZZ-9']['productId']);
    }

    public function test_an_offers_parent_product_places_a_code_with_no_master_under_the_matching_product(): void
    {
        $this->offer(self::T, 'EB-77', 'EB reports', 'EB');
        $c = $this->company();
        $this->signal($c, ['offer_id' => 'EB-77']);

        $sets = $this->ofCompany($c);

        $this->assertSame($this->eb, $sets['subProduct']['offer:EB-77']['productId'], 'the portfolio name EB is the shared Enterprise Brain');
    }

    // ── a company: ICP ───────────────────────────────────────────────────────────

    public function test_the_icp_is_the_customer_model_when_it_is_one_of_the_three(): void
    {
        $cases = ['b2b' => 'b2b', ' B2C ' => 'b2c', 'B2G' => 'b2g', 'b2x' => null, '' => null, 'consumer' => null];
        foreach ($cases as $model => $expected) {
            $c = $this->company();
            $this->signal($c, ['customer_model' => $model]);
            $sets = $this->ofCompany($c);
            $this->assertSame($expected === null ? [] : [$expected], $this->values($sets, 'icp'), "customer_model '{$model}'");
        }
        $c = $this->company();
        $this->signal($c, ['customer_model' => 'b2g']);
        $this->assertSame('B2G: Government and tenders', $this->label($this->ofCompany($c), 'icp', 'b2g'));
        $this->assertSame(['b2b' => 'B2B: Private organisations', 'b2c' => 'B2C: Consumers', 'b2g' => 'B2G: Government and tenders'], OutreachFacets::ICP_LABELS);
    }

    // ── a company: Region ────────────────────────────────────────────────────────

    public function test_a_region_scope_gives_the_country_state_and_city_of_every_entry(): void
    {
        $c = $this->company();
        $this->signal($c, ['region_scope' => '[{"country":"India","state":"Maharashtra","city":"Nagpur"},{"country":"India","state":null,"city":null}]']);

        $sets = $this->ofCompany($c);

        $this->assertSame(['india', 'maharashtra', 'nagpur'], $this->values($sets, 'region'));
        $this->assertSame('Nagpur', $this->label($sets, 'region', 'nagpur'));
    }

    public function test_the_company_state_and_the_parts_of_its_location_are_places_too_and_the_first_spelling_seen_is_the_label(): void
    {
        $c = $this->company(['state' => 'Gujarat', 'location' => 'Surat, INDIA, 395007, none']);
        $this->signal($c, ['region_scope' => '[{"country":"India","state":null,"city":null}]']);

        $sets = $this->ofCompany($c);

        $this->assertSame(['gujarat', 'india', 'surat'], $this->values($sets, 'region'), 'a postcode and the word none are not places');
        $this->assertSame('India', $this->label($sets, 'region', 'india'), 'the signal came first, so its spelling is the label');
        $this->assertSame('Surat', $this->label($sets, 'region', 'surat'));
    }

    public function test_junk_tokens_are_skipped_and_spaces_collapse(): void
    {
        $long = str_repeat('x', 61);
        $edge = str_repeat('y', 60);
        $c = $this->company(['location' => "  New   Delhi , 110001, 2024 , - , {$long}, {$edge}, , 12/34"]);

        $sets = $this->ofCompany($c);

        $this->assertSame(['new delhi', $edge], $this->values($sets, 'region'));
        $this->assertSame('New Delhi', $this->label($sets, 'region', 'new delhi'));
    }

    public function test_every_shape_of_region_scope_is_read_and_a_broken_one_is_nothing(): void
    {
        $this->assertSame(['India', 'Goa'], OutreachFacets::scopeTokens('[{"country":"India","state":"Goa"}]'));
        $this->assertSame(['India'], OutreachFacets::scopeTokens('{"country":"India"}'), 'one entry on its own');
        $this->assertSame(['India', 'Kerala'], OutreachFacets::scopeTokens(['India', 'Kerala']), 'a list of plain places');
        $this->assertSame(['Gujarat'], OutreachFacets::scopeTokens('Gujarat'), 'text that is not JSON is a plain place');
        $this->assertSame(['India'], OutreachFacets::scopeTokens('"India"'));
        $this->assertSame([], OutreachFacets::scopeTokens('[{"country":'), 'broken JSON');
        $this->assertSame([], OutreachFacets::scopeTokens(''));
        $this->assertSame([], OutreachFacets::scopeTokens(null));
        $this->assertSame([], OutreachFacets::scopeTokens('[]'));
        $this->assertSame([], OutreachFacets::scopeTokens('42'));
        $this->assertSame(['India'], OutreachFacets::scopeTokens([['country' => 'India', 'state' => ['not', 'text'], 'city' => 5]]));
        $this->assertSame('new delhi', OutreachFacets::regionKey("  New \t Delhi  "));
        $this->assertSame(['nagpur', 'maharashtra'], array_keys(OutreachFacets::regionSet(OutreachFacets::textTokens('Nagpur, Maharashtra'))));
    }

    public function test_a_placeholder_is_no_region_and_ampersand_and_postcode_do_not_make_a_second_region(): void
    {
        // Seen on db3: location text a research tool wrote when it found no place, an "&" spelling, and a postcode after the place.
        $set = OutreachFacets::regionSet(['Identified from Source', 'Unknown', 'N/A', 'Jammu & Kashmir', 'Jammu and Kashmir', "Gujarat \u{2013} 380060", 'Gujarat', 'Nagpur']);

        $this->assertSame(['jammu and kashmir', 'gujarat', 'nagpur'], array_keys($set));
        $this->assertSame('Jammu & Kashmir', $set['jammu and kashmir']['label'], 'the first spelling seen is the label');
        $this->assertSame('Gujarat', $set['gujarat']['label'], 'the label has no postcode');
        $this->assertSame('jammu and kashmir', OutreachFacets::regionKey('Jammu & Kashmir'), 'a chosen region is keyed the same way as the sets');
    }

    // ── a company: the union, and who it never reads ─────────────────────────────

    public function test_a_company_has_the_union_of_its_signals_and_never_a_deleted_or_foreign_or_other_companys_one(): void
    {
        $c = $this->company();
        $this->signal($c, ['product_master_id' => $this->scholar, 'customer_model' => 'b2b', 'region_scope' => '[{"country":"India"}]']);
        $this->signal($c, ['product_master_id' => $this->eb, 'offer_id' => 'EB-01', 'customer_model' => 'b2g', 'region_scope' => '[{"country":"Nepal"}]']);
        $this->signal($c, ['product_master_id' => $this->scholar, 'customer_model' => 'b2c', 'region_scope' => '[{"country":"Bhutan"}]', 'deleted_at' => '2026-11-01 10:00:00']);
        $this->signal($c, ['customer_model' => 'b2c', 'region_scope' => '[{"country":"Sri Lanka"}]'], self::OTHER);
        $other = $this->company();
        $this->signal($other, ['customer_model' => 'b2c', 'region_scope' => '[{"country":"Japan"}]']);

        $sets = $this->ofCompany($c);

        $this->assertSame([(string) $this->scholar, (string) $this->eb], array_map('strval', array_keys($sets['product'])));
        $this->assertSame([(string) $this->ebSearch], $this->values($sets, 'subProduct'));
        $this->assertSame(['b2b', 'b2g'], $this->values($sets, 'icp'), 'the deleted, the foreign and the other company\'s signals say nothing');
        $this->assertSame(['india', 'nepal'], $this->values($sets, 'region'));
    }

    public function test_a_company_without_signals_has_empty_sets_except_its_own_place(): void
    {
        $bare = $this->company();
        $placed = $this->company(['state' => 'Kerala']);

        $this->assertSame(OutreachFacets::emptySets(), $this->ofCompany($bare));
        $this->assertSame(['kerala'], $this->values($this->ofCompany($placed), 'region'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_companies(): void
    {
        $read = function (int $n): int {
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $c = $this->company(['location' => "Town {$i}"]);
                $this->signal($c, ['product_master_id' => $this->scholar, 'offer_id' => 'SCH-01', 'customer_model' => 'b2b']);
                $rows[] = ['id' => $c, 'state' => null, 'location' => "Town {$i}"];
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $sets = $this->facets()->companySets($rows);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount($n, $sets);

            return $queries;
        };

        $few = $read(3);
        $many = $read(60);

        $this->assertSame($few, $many, 'one set-based read per table, whatever the number of companies');
        $this->assertLessThanOrEqual(16, $many, "{$many} queries");
    }

    // ── a host that lacks tables or columns ──────────────────────────────────────

    public function test_a_host_without_the_product_tables_still_answers_with_what_it_has(): void
    {
        Schema::drop('g2g_product_master');
        Schema::drop('g2g_product_offers');
        $c = $this->company();
        $this->signal($c, ['product_master_id' => $this->scholar, 'product_key' => 'scholar', 'offer_id' => 'SCH-01', 'customer_model' => 'b2b']);

        $sets = $this->ofCompany($c);

        $this->assertSame([], $sets['product']);
        $this->assertSame(['offer:SCH-01'], $this->values($sets, 'subProduct'), 'the code is all that is known');
        $this->assertSame(['b2b'], $this->values($sets, 'icp'));
    }

    public function test_a_host_without_the_signal_columns_or_the_signal_table_answers_with_empty_sets(): void
    {
        Schema::drop('g2g_company_opportunities');
        Schema::create('g2g_company_opportunities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('company_id');
            $t->string('customer_model', 8)->nullable();
        });
        $c = $this->company(['state' => 'Goa']);
        $this->signal($c, ['customer_model' => 'b2c']);

        $sets = $this->ofCompany($c);

        $this->assertSame(['b2c'], $this->values($sets, 'icp'));
        $this->assertSame([], $sets['product']);
        $this->assertSame([], $sets['subProduct']);
        $this->assertSame(['goa'], $this->values($sets, 'region'), 'the company\'s own place is still read');

        Schema::drop('g2g_company_opportunities');
        $this->assertSame(['goa'], $this->values($this->ofCompany($c), 'region'), 'no signal table at all');
    }

    public function test_a_host_without_any_of_the_tables_has_nothing_and_does_not_fail(): void
    {
        foreach (['g2g_product_master', 'g2g_product_offers', 'g2g_company_opportunities', 'g2g_signal_contacts', 'gtm_outreach_messages', 'g2g_companies'] as $table) {
            Schema::drop($table);
        }
        $f = $this->facets();

        $this->assertSame([5 => OutreachFacets::emptySets()], $f->companySets([['id' => 5, 'state' => null, 'location' => null]]));
        $this->assertSame([7 => OutreachFacets::emptySets()], $f->personSets([['id' => 7, 'company_id' => 5, 'opportunity_id' => 9, 'location' => null]]));
        $this->assertSame([], $f->tenantMessageSets());
        $answer = $f->answer([5 => OutreachFacets::emptySets()], OutreachFacets::selection([]));
        $this->assertSame(['products' => 1, 'subProducts' => 1, 'icps' => 1, 'regions' => 1], $answer['notRecorded']);
    }

    // ── a person ─────────────────────────────────────────────────────────────────

    public function test_a_person_has_their_companys_sets_plus_their_own_signal_and_their_own_location(): void
    {
        $c = $this->company(['state' => 'Maharashtra']);
        $s1 = $this->signal($c, ['product_master_id' => $this->scholar, 'customer_model' => 'b2b']);
        $this->signal($c, ['product_master_id' => $this->eb, 'customer_model' => 'b2g', 'region_scope' => '[{"city":"Nagpur"}]']);
        $p = $this->person($c, ['opportunity_id' => $s1, 'location' => 'Pune, Maharashtra']);

        $sets = $this->facets()->personSets([DB::table('g2g_signal_contacts')->find($p)])[$p];

        $this->assertSame([(string) $this->scholar, (string) $this->eb], array_map('strval', array_keys($sets['product'])));
        $this->assertSame(['b2b', 'b2g'], $this->values($sets, 'icp'));
        $this->assertSame(['maharashtra', 'nagpur', 'pune'], $this->values($sets, 'region'));
    }

    public function test_a_persons_own_signal_counts_even_when_it_is_the_only_one_and_a_deleted_signal_does_not(): void
    {
        $c = $this->company();
        $elsewhere = $this->company();
        $own = $this->signal($elsewhere, ['product_master_id' => $this->eb, 'customer_model' => 'b2c']);
        $gone = $this->signal($elsewhere, ['product_master_id' => $this->scholar, 'deleted_at' => '2026-11-01 10:00:00']);
        $withOwn = $this->person($c, ['opportunity_id' => $own]);
        $withGone = $this->person($c, ['opportunity_id' => $gone]);

        $sets = $this->facets()->personSets(DB::table('g2g_signal_contacts')->get());

        $this->assertSame([(string) $this->eb], $this->values($sets[$withOwn], 'product'));
        $this->assertSame(['b2c'], $this->values($sets[$withOwn], 'icp'));
        $this->assertSame([], $sets[$withGone]['product'], 'a deleted signal says nothing');
    }

    public function test_a_person_added_by_hand_to_a_company_with_no_signal_has_only_the_places_they_and_the_company_state(): void
    {
        $c = $this->company(['location' => 'Surat']);
        $p = $this->person($c, ['location' => 'Ahmedabad']);
        $none = $this->person(0);

        $sets = $this->facets()->personSets(DB::table('g2g_signal_contacts')->get());

        $this->assertSame(['ahmedabad', 'surat'], $this->values($sets[$p], 'region'));
        $this->assertSame([], $sets[$p]['product']);
        $this->assertSame(OutreachFacets::emptySets(), $sets[$none], 'no company: empty sets');
    }

    public function test_a_persons_company_that_is_deleted_gives_no_place_but_the_signals_are_still_read(): void
    {
        $c = $this->company(['state' => 'Goa', 'deleted_at' => '2026-11-01 10:00:00']);
        $this->signal($c, ['customer_model' => 'b2b']);
        $p = $this->person($c);

        $sets = $this->facets()->personSets(DB::table('g2g_signal_contacts')->get())[$p];

        $this->assertSame([], $sets['region']);
        $this->assertSame(['b2b'], $this->values($sets, 'icp'));
    }

    // ── a message ────────────────────────────────────────────────────────────────

    /** A person at a company whose signal is Enterprise Brain, b2g, in Nepal, linked to GTM contact 500. @return array{company: int, person: int} */
    private function signalContact(int $gtmContact = 500): array
    {
        $c = $this->company();
        $s = $this->signal($c, ['product_master_id' => $this->eb, 'offer_id' => 'EB-01', 'customer_model' => 'b2g', 'region_scope' => '[{"country":"Nepal"}]']);
        $p = $this->person($c, ['opportunity_id' => $s, 'gtm_contact_id' => $gtmContact]);

        return ['company' => $c, 'person' => $p];
    }

    public function test_a_message_with_no_product_of_its_own_has_everything_from_the_person_behind_its_contact(): void
    {
        $this->signalContact(500);
        $m = $this->message(500);

        $sets = $this->facets()->messageSets([DB::table('gtm_outreach_messages')->find($m)])[$m];

        $this->assertSame([(string) $this->eb], $this->values($sets, 'product'));
        $this->assertSame([(string) $this->ebSearch], $this->values($sets, 'subProduct'));
        $this->assertSame(['b2g'], $this->values($sets, 'icp'));
        $this->assertSame(['nepal'], $this->values($sets, 'region'));
    }

    public function test_a_messages_own_product_is_authoritative_for_product_and_the_rest_still_comes_from_the_person(): void
    {
        $this->signalContact(500);
        $m = $this->message(500, ['product_master_id' => $this->scholar]);

        $sets = $this->facets()->messageSets([DB::table('gtm_outreach_messages')->find($m)])[$m];

        $this->assertSame([(string) $this->scholar], $this->values($sets, 'product'), 'its own product replaces the person\'s');
        $this->assertSame([(string) $this->ebSearch], $this->values($sets, 'subProduct'), 'a product has no sub-product of its own: the person\'s stays');
        $this->assertSame(['b2g'], $this->values($sets, 'icp'));
        $this->assertSame(['nepal'], $this->values($sets, 'region'));
    }

    public function test_a_messages_sub_product_master_id_gives_that_sub_product_and_its_parent_product(): void
    {
        $this->signalContact(500);
        $m = $this->message(500, ['product_master_id' => $this->admissions]);

        $sets = $this->facets()->messageSets([DB::table('gtm_outreach_messages')->find($m)])[$m];

        $this->assertSame([(string) $this->scholar], $this->values($sets, 'product'));
        $this->assertSame([(string) $this->admissions], $this->values($sets, 'subProduct'), 'its own sub-product replaces the person\'s');
        $this->assertSame($this->scholar, $sets['subProduct'][$this->admissions]['productId']);
        $this->assertSame(['b2g'], $this->values($sets, 'icp'));
    }

    public function test_a_message_whose_contact_is_not_a_signal_contact_has_not_recorded_everywhere_but_its_own_product(): void
    {
        $plain = $this->message(777);
        $own = $this->message(777, ['product_master_id' => $this->eb]);
        $dangling = $this->message(777, ['product_master_id' => 999999]);

        $sets = $this->facets()->messageSets(DB::table('gtm_outreach_messages')->get());

        $this->assertSame(OutreachFacets::emptySets(), $sets[$plain]);
        $this->assertSame([(string) $this->eb], $this->values($sets[$own], 'product'));
        $this->assertSame([], $sets[$own]['icp']);
        $this->assertSame([], $sets[$own]['region']);
        $this->assertSame(OutreachFacets::emptySets(), $sets[$dangling], 'a product id that leads nowhere is no product');
    }

    public function test_a_deleted_person_and_another_organisations_person_are_never_behind_a_contact(): void
    {
        $c = $this->company();
        $this->signal($c, ['customer_model' => 'b2b']);
        $this->person($c, ['gtm_contact_id' => 600, 'location' => 'Lima', 'deleted_at' => '2026-11-01 10:00:00']);
        $theirCompany = $this->company([], self::OTHER);
        $this->signal($theirCompany, ['customer_model' => 'b2c'], self::OTHER);
        $this->person($theirCompany, ['gtm_contact_id' => 601, 'location' => 'Tokyo'], self::OTHER);
        $a = $this->message(600);
        $b = $this->message(601);

        $sets = $this->facets()->messageSets(DB::table('gtm_outreach_messages')->get());

        $this->assertSame(OutreachFacets::emptySets(), $sets[$a]);
        $this->assertSame(OutreachFacets::emptySets(), $sets[$b]);
    }

    public function test_every_message_of_the_organisation_and_only_those_is_read_for_the_queue(): void
    {
        $this->signalContact(500);
        $mine = $this->message(500);
        $this->message(500, [], self::OTHER);

        $sets = $this->facets()->tenantMessageSets();

        $this->assertSame([$mine], array_keys($sets));
    }

    public function test_a_host_whose_messages_have_no_product_pointer_still_reads_them(): void
    {
        $this->signalContact(500);
        $m = $this->message(500);
        Schema::table('gtm_outreach_messages', fn (Blueprint $t) => $t->dropColumn('product_master_id'));

        $sets = $this->facets()->tenantMessageSets();

        $this->assertSame([(string) $this->eb], $this->values($sets[$m], 'product'), 'from the person');
    }

    // ── what was asked for ───────────────────────────────────────────────────────

    public function test_the_four_chosen_values_are_cleaned(): void
    {
        $this->assertSame(['product' => null, 'subProduct' => null, 'icp' => null, 'region' => null], OutreachFacets::selection([]));
        $this->assertSame(['product' => null, 'subProduct' => null, 'icp' => null, 'region' => null], OutreachFacets::selection(['product' => '', 'sub_product' => '  ', 'icp' => null, 'region' => ['x']]));
        $this->assertSame(['product' => '12', 'subProduct' => 'offer:G2G-02', 'icp' => 'b2g', 'region' => 'new delhi'],
            OutreachFacets::selection(['product' => ' 12 ', 'sub_product' => 'offer:G2G-02', 'icp' => 'B2G', 'region' => "  New   Delhi "]));
        $this->assertSame(['product' => 'none', 'subProduct' => 'none', 'icp' => 'none', 'region' => 'none'],
            OutreachFacets::selection(['product' => 'None', 'sub_product' => 'NONE', 'icp' => ' none ', 'region' => 'none']));
        $this->assertSame('7', OutreachFacets::selection(['product' => '007'])['product']);
        $this->assertSame('5', OutreachFacets::selection(['sub_product' => 5])['subProduct']);
        $this->assertFalse(OutreachFacets::isActive(OutreachFacets::selection([])));
        $this->assertTrue(OutreachFacets::isActive(OutreachFacets::selection(['icp' => 'b2b'])));
    }

    // ── passing ──────────────────────────────────────────────────────────────────

    /** @return array<string, array<int|string, mixed>> */
    private function sets(array $product = [], array $sub = [], array $icp = [], array $region = []): array
    {
        $labelled = fn (array $values) => array_map(fn ($label) => ['label' => $label], $values);

        return ['product' => $labelled($product), 'subProduct' => $labelled($sub), 'icp' => $labelled($icp), 'region' => $labelled($region)];
    }

    public function test_a_filter_passes_a_row_whose_set_has_the_value_and_filters_are_anded(): void
    {
        $row = $this->sets([1 => 'A', 2 => 'B'], [10 => 'A1'], ['b2b' => 'B2B'], ['nagpur' => 'Nagpur']);
        $pick = fn (array $in) => OutreachFacets::passes($row, OutreachFacets::selection($in));

        $this->assertTrue($pick([]));
        $this->assertTrue($pick(['product' => '2']));
        $this->assertFalse($pick(['product' => '3']), 'a value nobody has matches nothing');
        $this->assertTrue($pick(['product' => '2', 'sub_product' => '10', 'icp' => 'b2b', 'region' => 'Nagpur']));
        $this->assertFalse($pick(['product' => '2', 'sub_product' => '10', 'icp' => 'b2c']), 'one failing filter fails the row (AND, not OR)');
        $this->assertFalse($pick(['product' => '9', 'icp' => 'b2b']), 'the first failing, the second passing: still out');
        $this->assertFalse($pick(['product' => '1', 'icp' => 'b2g']));
    }

    public function test_none_passes_only_a_row_whose_set_is_empty(): void
    {
        $full = $this->sets([1 => 'A'], [], ['b2b' => 'B2B']);
        $pick = fn (array $in) => OutreachFacets::passes($full, OutreachFacets::selection($in));

        $this->assertFalse($pick(['product' => 'none']), 'a row with a product is not "Not recorded"');
        $this->assertTrue($pick(['sub_product' => 'none']));
        $this->assertFalse($pick(['icp' => 'none']));
        $this->assertTrue($pick(['region' => 'none']));
        $this->assertTrue($pick(['sub_product' => 'none', 'region' => 'none']));
        $this->assertFalse($pick(['sub_product' => 'none', 'icp' => 'none']));
        $this->assertTrue(OutreachFacets::passes(OutreachFacets::emptySets(), OutreachFacets::selection(['product' => 'none', 'icp' => 'none'])));
    }

    public function test_a_facet_can_be_left_out_of_the_test(): void
    {
        $row = $this->sets([1 => 'A'], [], ['b2b' => 'B2B']);
        $selection = OutreachFacets::selection(['product' => '9', 'icp' => 'b2b']);

        $this->assertFalse(OutreachFacets::passes($row, $selection));
        $this->assertTrue(OutreachFacets::passes($row, $selection, 'product'));
        $this->assertFalse(OutreachFacets::passes($row, $selection, 'icp'));
    }

    // ── options with counts ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function answerFor(array $rows, array $input = []): array
    {
        return $this->facets()->answer($rows, OutreachFacets::selection($input));
    }

    /** @param list<array<string, mixed>> $options @return array<string, int> */
    private function counts(array $options): array
    {
        return array_column($options, 'count', 'value');
    }

    public function test_every_facet_is_counted_with_the_other_three_applied_and_never_with_itself(): void
    {
        $rows = [
            1 => $this->sets([1 => 'Alpha'], [], ['b2b' => 'B2B']),
            2 => $this->sets([1 => 'Alpha'], [], ['b2c' => 'B2C']),
            3 => $this->sets([2 => 'Beta'], [], ['b2b' => 'B2B']),
            4 => $this->sets([], [], ['b2b' => 'B2B']),
        ];

        $none = $this->answerFor($rows);
        $this->assertSame(['1' => 2, '2' => 1], $this->counts($none['products']));
        $this->assertSame(['b2b' => 3, 'b2c' => 1], $this->counts($none['icps']));
        $this->assertSame(['products' => 1, 'subProducts' => 4, 'icps' => 0, 'regions' => 4], $none['notRecorded']);

        // ICP b2b chosen: products are counted over the b2b rows (1, 3, 4), so Alpha is 1, not 2; the ICP list itself ignores the ICP choice (b2c is still offered: 1).
        $b2b = $this->answerFor($rows, ['icp' => 'b2b']);
        $this->assertSame(['1' => 1, '2' => 1], $this->counts($b2b['products']), 'the naive count over all rows would say Alpha 2');
        $this->assertSame(['b2b' => 3, 'b2c' => 1], $this->counts($b2b['icps']), 'a facet is never counted with itself applied');
        $this->assertSame(1, $b2b['notRecorded']['products'], 'row 4 has no product');

        // Product Alpha chosen: ICPs are counted over rows 1 and 2.
        $alpha = $this->answerFor($rows, ['product' => '1']);
        $this->assertSame(['b2b' => 1, 'b2c' => 1], $this->counts($alpha['icps']));
        $this->assertSame(['1' => 2, '2' => 1], $this->counts($alpha['products']), 'the product list is not narrowed by the product choice');
        $this->assertSame(0, $alpha['notRecorded']['icps']);
    }

    public function test_not_recorded_counts_follow_the_other_filters_too(): void
    {
        $rows = [
            1 => $this->sets([1 => 'Alpha'], [], ['b2b' => 'B2B']),
            2 => $this->sets([], [], ['b2b' => 'B2B']),
            3 => $this->sets([], [], []),
        ];

        $a = $this->answerFor($rows, ['icp' => 'b2b']);
        $this->assertSame(1, $a['notRecorded']['products'], 'row 3 has no ICP, so it is out of the product count');
        $this->assertSame(1, $a['notRecorded']['icps'], 'the ICP "Not recorded" ignores the ICP choice: row 3');
        $b = $this->answerFor($rows, ['icp' => 'none']);
        $this->assertSame(1, $b['notRecorded']['products']);
        $this->assertSame(['b2b' => 2], $this->counts($b['icps']));
    }

    public function test_an_option_nobody_has_is_left_out_except_the_chosen_one_which_stays_at_zero(): void
    {
        $rows = [1 => $this->sets([1 => 'Alpha'], [], ['b2b' => 'B2B'])];

        $icp = $this->answerFor($rows, ['icp' => 'b2g']);
        $this->assertSame(['b2b' => 1, 'b2g' => 0], $this->counts($icp['icps']), 'b2g is chosen but no row has it: it stays at zero beside b2b');
        $this->assertSame(['b2b', 'b2g'], array_column($icp['icps'], 'value'));

        $a = $this->answerFor($rows, ['product' => '99', 'region' => 'goa']);
        $this->assertSame(['99' => 0], $this->counts($a['products']), 'an unknown product stays listed at zero, labelled with its value');
        $this->assertSame('99', $a['products'][0]['label']);
        $this->assertSame(['goa' => 0], $this->counts($a['regions']));
        $this->assertSame([], $a['subProducts'], 'nothing chosen, nothing with a value: no option');
        $this->assertSame([], $this->counts($a['icps']), 'the product choice leaves no row, so no ICP has a count');
    }

    public function test_choosing_none_adds_no_option_and_the_active_values_are_echoed(): void
    {
        $rows = [1 => $this->sets([1 => 'Alpha'])];

        $a = $this->answerFor($rows, ['product' => 'none', 'sub_product' => 'offer:X-1', 'icp' => 'B2B', 'region' => ' Pune ']);

        $this->assertSame(['product' => 'none', 'subProduct' => 'offer:X-1', 'icp' => 'b2b', 'region' => 'pune'], $a['active']);
        $this->assertSame([], $a['products'], 'none is the Not recorded choice, not an option');
        $this->assertSame(['offer:X-1'], array_column($a['subProducts'], 'value'));
        $this->assertSame('X-1', $a['subProducts'][0]['label']);
        $this->assertSame(['product' => null, 'subProduct' => null, 'icp' => null, 'region' => null], $this->answerFor([])['active']);
    }

    public function test_a_chosen_option_at_zero_is_labelled_from_the_catalogue(): void
    {
        $a = $this->answerFor([], ['product' => (string) $this->eb, 'sub_product' => (string) $this->admissions, 'icp' => 'b2g']);

        $this->assertSame('Enterprise Brain', $a['products'][0]['label']);
        $this->assertSame('Scholar Admissions', $a['subProducts'][0]['label']);
        $this->assertSame($this->scholar, $a['subProducts'][0]['productId']);
        $this->assertSame('B2G: Government and tenders', $a['icps'][0]['label']);
        $b = $this->answerFor([], ['sub_product' => 'offer:G2G-02']);
        $this->assertSame('G2G procurement', $b['subProducts'][0]['label']);
    }

    public function test_options_are_sorted_products_and_sub_products_by_name_icps_in_order_regions_by_count_then_name(): void
    {
        $rows = [
            1 => $this->sets([5 => 'banana', 6 => 'Apple', 7 => 'cherry'], [30 => 'Zed', 31 => 'Alpha'], ['b2g' => 'G', 'b2c' => 'C', 'b2b' => 'B'], ['x' => 'Xanadu', 'a' => 'Aden', 'm' => 'Mumbai']),
            2 => $this->sets([5 => 'banana'], [31 => 'Alpha'], [], ['m' => 'Mumbai', 'x' => 'Xanadu']),
            3 => $this->sets([], [], [], ['m' => 'Mumbai']),
        ];

        $a = $this->answerFor($rows);

        $this->assertSame(['Apple', 'banana', 'cherry'], array_column($a['products'], 'label'));
        $this->assertSame(['Alpha', 'Zed'], array_column($a['subProducts'], 'label'));
        $this->assertSame(['b2b', 'b2c', 'b2g'], array_column($a['icps'], 'value'));
        $this->assertSame(['m', 'x', 'a'], array_column($a['regions'], 'value'), 'Mumbai 3, Xanadu 2, Aden 1');
        $this->assertSame([3, 2, 1], array_column($a['regions'], 'count'));
        $this->assertSame([null, null], array_column($a['subProducts'], 'productId'), 'a sub-product with no known product says so');
    }

    public function test_the_region_list_is_the_fifty_most_common_plus_the_chosen_one(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            // place 1 is in 60 rows, place 2 in 59, ... place 60 in 1 row.
            $rows[$i] = $this->sets([], [], [], []);
            for ($p = 1; $p <= 61 - $i; $p++) {
                $rows[$i]['region']['town' . str_pad((string) $p, 2, '0', STR_PAD_LEFT)] = ['label' => 'Town' . $p];
            }
        }

        $plain = $this->answerFor($rows);
        $this->assertCount(50, $plain['regions']);
        $this->assertSame('town01', $plain['regions'][0]['value']);
        $this->assertSame('town50', $plain['regions'][49]['value']);

        $chosen = $this->answerFor($rows, ['region' => 'town58']);
        $this->assertCount(51, $chosen['regions']);
        $this->assertSame('town58', $chosen['regions'][50]['value'], 'the chosen region is kept though it is not among the most common');
        $this->assertSame(3, $chosen['regions'][50]['count']);
    }

    public function test_a_sub_products_product_is_the_first_one_known_for_it(): void
    {
        $rows = [
            1 => ['product' => [], 'subProduct' => [30 => ['label' => 'Sub', 'productId' => null]], 'icp' => [], 'region' => []],
            2 => ['product' => [], 'subProduct' => [30 => ['label' => 'Sub', 'productId' => 4]], 'icp' => [], 'region' => []],
        ];

        $a = $this->answerFor($rows);

        $this->assertSame(4, $a['subProducts'][0]['productId']);
        $this->assertSame(2, $a['subProducts'][0]['count']);
    }
}
