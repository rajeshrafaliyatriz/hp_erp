<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMatchSuggester as S;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * The pair review's suggestions (slice 15, track P2-D): which catalogue items and Portfolio Sub-products look like the same product. Built on the SHAPE of the dev
 * data of tenant 6 (tests/Support/BuildsProductUnifyWorld.php: one Product with 7 Sub-products and 9 items, two of them services, two of them editions).
 *
 * What it proves: the suggester writes nothing; every verdict; the four ways a name-only comparison goes wrong (the shared brand word, editions that share a category,
 * acronyms, services against "implementation effort"); usage protects an item but never makes a match; the margin that keeps a close call AMBIGUOUS, on the item's
 * side and on the Sub-product's side; a "Different" answer is never offered again; a pair is only looked for inside one Product and one organisation.
 */
class ProductMatchSuggesterTest extends TestCase
{
    use BuildsProductUnifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
    }

    /** @return array<string, mixed> */
    private function suggestions(int $tenant = self::T): array
    {
        return (new S)->suggest($tenant);
    }

    /** @return array<string, mixed> the suggestion row of one item master row */
    private function row(array $result, int $itemMasterId): array
    {
        foreach ($result['items'] as $r) {
            if ($r['itemMasterId'] === $itemMasterId) {
                return $r;
            }
        }
        $this->fail("item {$itemMasterId} is not in the suggestions");
    }

    // ------------------------------------------------------------------ nothing is written

    public function test_the_suggestions_write_nothing_and_issue_only_select_statements(): void
    {
        $w = $this->scholarWorld();
        $this->pointAtCrm($w['items']['gujarat']['crm']);
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $w['items']['lms']['master'], 'survivor_master_id' => $w['subs']['SCH-03']['master'], 'decision' => 'different', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $before = $this->dumpEverything();
        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        $this->suggestions();
        (new S)->pair(self::T, $w['items']['gujarat']['master'], $w['subs']['SCH-01']['master']);

        $this->assertNotEmpty($statements, 'it did read');
        foreach ($statements as $sql) {
            // SELECT, and the introspection the schema checks use (sqlite answers "does this column exist" with a PRAGMA table_xinfo; MariaDB with SHOW COLUMNS)
            $this->assertMatchesRegularExpression('/^(select|show|pragma\s+(table_xinfo|table_info|index_list|index_info))\b/i', $sql, 'only reads: ' . $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|replace|create|alter|drop|truncate)\b\s/i', preg_replace('/"[^"]*"|\'[^\']*\'/', '', $sql), 'no write verb: ' . $sql);
        }
        $this->assertSame($before, $this->dumpEverything(), 'every row of every table, updated_at included, is as it was');
    }

    // ------------------------------------------------------------------ the verdicts, on the shape of the dev data

    public function test_every_item_of_the_dev_shape_gets_the_verdict_a_person_would_expect(): void
    {
        $w = $this->scholarWorld();
        $r = $this->suggestions();
        $verdict = fn (string $key): string => $this->row($r, $w['items'][$key]['master'])['verdict'];

        $this->assertSame(S::EDITION, $verdict('gujarat'), 'two items of one category claim Scholar School ERP: editions');
        $this->assertSame(S::EDITION, $verdict('core'));
        $this->assertSame(S::AMBIGUOUS, $verdict('fees'), 'the category is in the Sub-product name, but the rest is thin: a person decides');
        $this->assertSame(S::AMBIGUOUS, $verdict('lms'), 'LMS-PAL shares only the word LMS');
        $this->assertSame(S::NO_MATCH, $verdict('attendance'));
        $this->assertSame(S::NO_MATCH, $verdict('exam'));
        $this->assertSame(S::NO_MATCH, $verdict('fleet'));
        $this->assertSame(S::SERVICE, $verdict('implementation'));
        $this->assertSame(S::SERVICE, $verdict('training'));
        $this->assertSame(9, $r['counts']['items']);
        $this->assertSame([S::MATCH_HIGH => 0, S::EDITION => 2, S::AMBIGUOUS => 2, S::SERVICE => 2, S::NO_MATCH => 3, S::STRAY => 0, S::JUNK => 0], $r['counts']['byVerdict']);

        // the editions name the same Sub-product, and each names the other
        $g = $this->row($r, $w['items']['gujarat']['master']);
        $c = $this->row($r, $w['items']['core']['master']);
        $this->assertSame($w['subs']['SCH-01']['master'], $g['candidate']['masterId']);
        $this->assertSame($w['subs']['SCH-01']['master'], $c['candidate']['masterId']);
        $this->assertSame([$c['itemMasterId']], $g['sharedWith']);
        $this->assertSame([$g['itemMasterId']], $c['sharedWith']);
        $this->assertSame(98.0, $g['score']);
        $this->assertSame(60.0, $c['score']);

        // the signals behind a score are listed, each with its own weight
        $this->assertSame(['name' => 0.0, 'category' => 40.0, 'sku' => 30.0, 'words' => 12.0, 'text' => 16.0, 'acronym' => 0.0, 'price' => 0.0], $g['parts']);
        $this->assertStringContainsString("the category 'School ERP' is inside the Sub-product name (+40)", implode(' | ', $g['reasons']));
        // CHANGED ON PURPOSE (slice 15 WPB, item 6): the retired words (offer, item) are gone from what the screen shows; the meaning is the same.
        $this->assertStringContainsString('the SKU SCH-01-GUJ holds the Sub-product code SCH-01 (+30)', implode(' | ', $g['reasons']));

        $fees = $this->row($r, $w['items']['fees']['master']);
        $this->assertSame($w['subs']['SCH-02']['master'], $fees['candidate']['masterId']);
        $this->assertSame(52.0, $fees['score']);
        $this->assertStringContainsString('weak', implode(' | ', $fees['reasons']));

        // services have no candidate at all, products with nothing have none either
        foreach (['implementation', 'training', 'attendance', 'exam', 'fleet'] as $key) {
            $this->assertNull($this->row($r, $w['items'][$key]['master'])['candidate'], $key);
        }
        // every item is listed with its Product, price, type and SKU
        $this->assertSame('ScholarClone', $g['productName']);
        $this->assertSame(120000.0, $g['price']);
        $this->assertSame('SCH-01-GUJ', $g['sku']);
        $this->assertSame('service', $this->row($r, $w['items']['training']['master'])['type']);
    }

    public function test_a_clear_pair_is_match_high_and_the_second_table_lists_the_sub_products_nobody_names(): void
    {
        $w = $this->scholarWorld();
        $career = $this->item('Career Intelligence Suite', ['category' => 'Career Intelligence', 'sku' => 'SCH-04-X', 'description' => 'student career guidance competency']);
        $r = $this->suggestions();

        $row = $this->row($r, $career['master']);
        $this->assertSame(S::MATCH_HIGH, $row['verdict']);
        $this->assertSame($w['subs']['SCH-04']['master'], $row['candidate']['masterId']);
        $this->assertSame('SCH-04', $row['candidate']['offerCode']);
        $this->assertSame(100.0, $row['score'], 'the sum is capped at 100');
        $this->assertGreaterThanOrEqual(S::MARGIN, $row['margin']);

        $unmatched = array_column($r['unmatchedSubProducts'], 'masterId');
        $this->assertNotContains($w['subs']['SCH-04']['master'], $unmatched, 'SCH-04 is named');
        $this->assertNotContains($w['subs']['SCH-01']['master'], $unmatched);
        $this->assertContains($w['subs']['SCH-05']['master'], $unmatched, 'nobody names Prompt-to-Video Lessons');
        $this->assertContains($w['subs']['EB-01']['master'], $unmatched, 'an Enterprise Brain Sub-product has no ScholarClone item to pair with');
    }

    // ------------------------------------------------------------------ name-only matching is unsafe: the four cases

    public function test_the_brand_word_alone_never_makes_a_match_and_the_brand_words_come_from_the_product_row(): void
    {
        $this->scholarWorld();
        // Under ScholarClone the words "scholar" and "scholarclone" are the Product's own: a pair that shares only them scores nothing.
        $sub = $this->subProduct('SCH-90', 'Scholar Parent Portal');
        $item = $this->item('Scholar Billing Desk', ['category' => null, 'sku' => null, 'description' => null]);
        $row = $this->row($this->suggestions(), $item['master']);
        $this->assertSame(0.0, $row['score']);
        $this->assertSame(S::NO_MATCH, $row['verdict']);
        $this->assertSame(0.0, (new S)->pair(self::T, $item['master'], $sub['master'])['score']);

        // Nothing is hard-coded: under Enterprise Brain the same two names DO share "scholar" (it is nobody's brand word there), and "enterprise" and "brain" are removed instead.
        $eb = $this->sharedId('enterprise_brain');
        $ebSub = $this->subProduct('EB-90', 'Scholar Parent Portal', ['parent_product' => 'EB']);
        $ebItem = $this->item('Scholar Billing Desk', ['product_master_id' => $eb, 'category' => null, 'sku' => null, 'description' => null]);
        $pair = (new S)->pair(self::T, $ebItem['master'], $ebSub['master']);
        $this->assertSame(12.0, $pair['score'], 'one shared word, "scholar", counted under Enterprise Brain');
        $brand = S::brandWords((object) ['name' => 'ScholarClone', 'product_key' => 'scholarclone', 'portfolio_name' => 'Scholar K-12', 'signal_key' => 'scholar']);
        foreach (['scholarclone', 'scholar', 'clone', 'k', '12'] as $word) {
            $this->assertArrayHasKey($word, $brand, $word);
        }
        $ebBrand = S::brandWords((object) ['name' => 'Enterprise Brain', 'product_key' => 'enterprise_brain', 'portfolio_name' => 'EB', 'signal_key' => 'eb']);
        $this->assertSame(['enterprise', 'brain', 'eb'], array_keys($ebBrand));
        $this->assertSame([], S::brandWords(null));
    }

    public function test_editions_of_one_sub_product_are_editions_and_none_of_them_is_match_high(): void
    {
        $this->scholarWorld();
        $sub = $this->subProduct('SCH-91', 'Payroll Manager', ['what_it_does' => 'Runs the monthly payroll']);
        $a = $this->item('Payroll Manager - Gujarat Edition', ['category' => 'Payroll', 'sku' => null, 'description' => null]);
        $b = $this->item('Payroll Manager - Maharashtra Edition', ['category' => 'Payroll', 'sku' => null, 'description' => null]);
        $r = $this->suggestions();

        foreach ([$a, $b] as $edition) {
            $row = $this->row($r, $edition['master']);
            $this->assertSame(S::EDITION, $row['verdict']);
            $this->assertSame($sub['master'], $row['candidate']['masterId']);
            $this->assertGreaterThanOrEqual(S::HIGH, $row['score'], 'each is a strong pair on its own, and that is exactly why nobody may be chosen for the person');
        }

        // a person says the Gujarat edition is a different product: the Maharashtra edition is then the only claimant and becomes a clear pair
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $a['master'], 'survivor_master_id' => $sub['master'], 'decision' => 'different', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $after = $this->suggestions();
        $this->assertSame(S::MATCH_HIGH, $this->row($after, $b['master'])['verdict']);
        $this->assertNotSame($sub['master'], $this->row($after, $a['master'])['candidate']['masterId'] ?? 0, 'a pair marked Different is not offered again');
    }

    public function test_a_weak_hint_at_the_same_sub_product_does_not_turn_a_clear_pair_into_an_edition(): void
    {
        $this->scholarWorld();
        $sub = $this->subProduct('PAY-05', 'Payroll Manager', ['what_it_does' => 'Runs the monthly payroll']);
        $strong = $this->item('Payroll Manager Pro', ['category' => 'Payroll', 'sku' => 'PAY-05-X', 'description' => null]);
        $weak = $this->item('Payroll Reports', ['category' => null, 'sku' => null, 'description' => null]);   // one shared word, nothing else
        $r = $this->suggestions();

        $s = $this->row($r, $strong['master']);
        $w = $this->row($r, $weak['master']);
        $this->assertSame($sub['master'], $s['candidate']['masterId']);
        $this->assertSame($sub['master'], $w['candidate']['masterId'], 'both name the same Sub-product');
        $this->assertSame(12.0, $w['score']);
        $this->assertLessThan(S::EDITION_MIN, $w['score']);
        $this->assertSame(S::MATCH_HIGH, $s['verdict'], 'a score of 12 is not an edition of anything');
        $this->assertSame([], $s['sharedWith']);
        $this->assertSame(S::AMBIGUOUS, $w['verdict']);
        $this->assertStringContainsString('another CRM record scores', implode(' | ', $w['reasons']));   // CHANGED ON PURPOSE (WPB, item 6): was 'another item scores'
    }

    public function test_acronyms_are_found_by_their_letters_and_by_their_spelling(): void
    {
        $w = $this->scholarWorld();
        // "LMS-PAL" against "LMS & Digital Classroom": a whole-name comparison sees nothing in common; word by word they share "lms".
        $r = $this->suggestions();
        $lms = $this->row($r, $w['items']['lms']['master']);
        $this->assertSame($w['subs']['SCH-03']['master'], $lms['alternatives'][0]['masterId']);
        $this->assertSame(12.0, $lms['parts']['words']);
        $this->assertStringContainsString('the names share: lms', implode(' | ', $lms['reasons']));
        $this->assertSame(S::AMBIGUOUS, $lms['verdict'], 'one weak shared word is a hint for a person, never a match');

        // "LMS" on one side and "Learning Management System" on the other: only the spelling shows it.
        $item = $this->item('Digital Classroom Plus', ['category' => null, 'sku' => null, 'description' => 'A Learning Management System for live lessons']);
        $pair = (new S)->pair(self::T, $item['master'], $w['subs']['SCH-03']['master']);
        $this->assertSame(S::W_ACRONYM, $pair['parts']['acronym']);
        $this->assertStringContainsString("'LMS' stands for 'learning management system'", implode(' | ', $pair['reasons']));
        // and the other way round: the item says "LMS", the Sub-product spells it out
        $spelled = $this->subProduct('SCH-92', 'Course Hub', ['what_it_does' => 'A Learning Management System for schools']);
        $acro = $this->item('Course Delivery LMS', ['category' => null, 'sku' => null, 'description' => null]);
        $this->assertSame(S::W_ACRONYM, (new S)->pair(self::T, $acro['master'], $spelled['master'])['parts']['acronym']);
        // an acronym that is already a plain shared word is not counted twice
        $plain = (new S)->pair(self::T, $w['items']['lms']['master'], $w['subs']['SCH-03']['master']);
        $this->assertSame(0.0, $plain['parts']['acronym']);
    }

    public function test_a_service_is_compared_only_with_offers_that_say_they_are_a_service_and_a_product_never_with_one(): void
    {
        $this->scholarWorld();
        $this->assertSame(0, $this->suggestions()['counts']['serviceOffers'], 'as on dev: not one of the Sub-product offers says it is a service');

        // The Portfolio offer of a PRODUCT has an "implementation effort" field. A service ("...Implementation") shares that word with every product, and must not match one.
        $product = $this->subProduct('SCH-93', 'Cloud Deployment Console', ['what_it_does' => 'Cloud and on-premise deployment console', 'implementation_effort' => 'Implementation: six months']);
        $service = $this->item('Cloud Implementation', ['item_type' => 'service', 'category' => 'Cloud Deployment', 'sku' => null, 'description' => 'Cloud and on-premise deployment']);
        $r = $this->suggestions();
        $row = $this->row($r, $service['master']);
        $this->assertSame(S::SERVICE, $row['verdict']);
        $this->assertNull($row['candidate'], 'a product offer is not a candidate for a service');
        $this->assertSame([], $row['alternatives']);
        $this->assertSame(0.0, $row['score']);
        $this->assertSame(0, $r['counts']['serviceOffers'], 'the word "implementation" in the implementation-effort field does not make a product offer a service');

        // An offer that says, in its own name, that it is a service is a candidate for a service, and only for one.
        $svc = $this->subProduct('SCH-94', 'Cloud Deployment Services', ['what_it_does' => 'We deploy it for you']);
        $r2 = $this->suggestions();
        $serviceRow = $this->row($r2, $service['master']);
        $this->assertSame($svc['master'], $serviceRow['candidate']['masterId']);
        $this->assertSame(S::MATCH_HIGH, $serviceRow['verdict']);
        $this->assertSame(56.0, $serviceRow['score'], 'category 40 + the shared word "cloud" 12 + one word of the descriptions 4');

        $productItem = $this->item('Cloud Deployment Console', ['category' => 'Cloud Deployment', 'sku' => null, 'description' => 'Cloud and on-premise deployment console']);
        $r3 = $this->suggestions();
        $prodRow = $this->row($r3, $productItem['master']);
        $this->assertSame($product['master'], $prodRow['candidate']['masterId'], 'a product item pairs with the product offer');
        $this->assertNotContains($svc['master'], array_column($prodRow['alternatives'], 'masterId'), 'and never with the offer that says service');
        $this->assertTrue((new S)->pair(self::T, $productItem['master'], $svc['master'])['typeMismatch']);
        $this->assertFalse((new S)->pair(self::T, $service['master'], $svc['master'])['typeMismatch']);

        // what was found is said in words, for the report
        $this->assertSame(1, $r3['counts']['serviceOffers']);
        $this->assertSame(10, $r3['counts']['offers']);
        $this->assertStringStartsWith('1 of 10 Sub-product offer(s) say they are a service', $r3['serviceRule']);
    }

    // ------------------------------------------------------------------ usage protects, it never matches

    public function test_usage_is_reported_per_table_stays_inside_the_organisation_and_never_changes_a_score(): void
    {
        $w = $this->scholarWorld();
        $before = $this->suggestions();
        $scoresBefore = array_column($before['items'], 'score', 'itemMasterId');

        // 500 lines on the item that matches nothing, and one on each table for another item
        $busy = $w['items']['attendance']['crm'];
        for ($i = 0; $i < 25; $i++) {
            $this->pointAtCrm($busy);
        }
        $this->pointAtCrm($w['items']['gujarat']['crm']);
        // a row of ANOTHER organisation that happens to carry the same catalogue id is not counted
        $this->pointAtCrm($w['items']['gujarat']['crm'], self::T2);

        $after = $this->suggestions();
        $this->assertSame($scoresBefore, array_column($after['items'], 'score', 'itemMasterId'), 'no score moved');
        $this->assertSame(S::NO_MATCH, $this->row($after, $w['items']['attendance']['master'])['verdict'], 'the busiest item is still no match');

        $usage = $this->row($after, $w['items']['attendance']['master'])['usage'];
        $this->assertSame(25, $usage['quotes']);
        $this->assertSame(25, $usage['orders']);
        $this->assertSame(25, $usage['invoices']);
        $this->assertSame(25, $usage['purchaseOrders']);
        $this->assertSame(25, $usage['opportunities']);
        $this->assertArrayNotHasKey('priceBooks', $usage, 'S16: a dormant price-book row is no use and no signal');
        $this->assertSame(50, $usage['bundles'], 'both ends of a bundle row count');
        $this->assertSame(25, $usage['campaigns']);
        foreach (['tickets', 'assets', 'faqs'] as $label) {
            $this->assertSame(25, $usage[$label], $label);
        }
        $this->assertSame(array_sum(array_diff_key($usage, ['total' => 1])), $usage['total']);

        $g = $this->row($after, $w['items']['gujarat']['master'])['usage'];
        $this->assertSame(1, $g['quotes'], "the other organisation's line is not counted");
        $this->assertSame(0, $this->row($before, $w['items']['gujarat']['master'])['usage']['total']);
    }

    // ------------------------------------------------------------------ the margin: a close call is a person's call

    public function test_a_lead_of_exactly_the_margin_is_a_clear_match_and_a_smaller_lead_is_not(): void
    {
        $this->scholarWorld();
        // Item: category "Payroll Processing", SKU holding both offer ids. Candidate A names the category whole (40), B names its words out of order (25): 70 against 55.
        $a = $this->subProduct('PAY-01', 'Payroll Processing Core');
        $b = $this->subProduct('PAY-02', 'Processing for Payroll');
        $item = $this->item('Wage Runner', ['category' => 'Payroll Processing', 'sku' => 'PAY-01-PAY-02', 'description' => null]);

        $row = $this->row($this->suggestions(), $item['master']);
        $this->assertSame(70.0, $row['score']);
        $this->assertSame(S::W_CATEGORY_PHRASE - S::W_CATEGORY_WORDS, $row['margin'], 'the lead is exactly the margin');
        $this->assertSame(S::MARGIN, $row['margin']);
        $this->assertSame(S::MATCH_HIGH, $row['verdict'], 'a lead equal to the margin is enough');
        $this->assertSame($a['master'], $row['candidate']['masterId']);

        // B's name now also shares the word "wage" with the item: 55 + 12 = 67 against 70, three points behind, which is less than the margin asks for
        DB::table('g2g_product_offers')->where('id', $b['offer'])->update(['name' => 'Wage for Processing Payroll']);
        DB::table('g2g_product_master')->where('id', $b['master'])->update(['name' => 'Wage for Processing Payroll']);
        $closer = $this->row($this->suggestions(), $item['master']);
        $this->assertSame(S::AMBIGUOUS, $closer['verdict'], 'B now scores 67 against 70: three points behind');
        $this->assertSame(3.0, $closer['margin']);
        $this->assertStringContainsString('close to the next Sub-product', implode(' | ', $closer['reasons']));
        $this->assertCount(2, $closer['alternatives']);
    }

    public function test_an_item_that_scores_high_but_ties_two_sub_products_is_ambiguous_not_match_high(): void
    {
        $this->scholarWorld();
        $this->subProduct('PAY-01', 'Payroll Processing Core');
        $this->subProduct('PAY-02', 'Payroll Processing Plus');
        $item = $this->item('Wage Runner', ['category' => 'Payroll Processing', 'sku' => 'PAY-01-PAY-02', 'description' => null]);

        $row = $this->row($this->suggestions(), $item['master']);
        $this->assertSame(70.0, $row['score']);
        $this->assertGreaterThanOrEqual(S::HIGH, $row['score'], 'far above the bar');
        $this->assertSame(0.0, $row['margin']);
        $this->assertSame(S::AMBIGUOUS, $row['verdict'], 'a tie is never decided by the program');
    }

    public function test_an_item_is_not_match_high_when_another_item_scores_almost_as_well_for_the_same_sub_product(): void
    {
        $this->scholarWorld();
        $c = $this->subProduct('PAY-01', 'Payroll Processing Core');
        $d = $this->subProduct('PAY-02', 'Wage Runner Deluxe');
        // A: best is C (category 40 + SKU 30 + word "wage" none) = 70. B: best is D (its own name, 45 + ...), but it also scores C at 70 - 12: the rival.
        $a = $this->item('Payroll Run', ['category' => 'Payroll Processing', 'sku' => 'PAY-01', 'description' => null]);
        $b = $this->item('Wage Runner Deluxe', ['category' => 'Payroll Processing', 'sku' => 'PAY-01-PAY-02', 'description' => null]);
        $r = $this->suggestions();

        $rowA = $this->row($r, $a['master']);
        $rowB = $this->row($r, $b['master']);
        $this->assertSame($c['master'], $rowA['candidate']['masterId']);
        $this->assertSame($d['master'], $rowB['candidate']['masterId'], 'B prefers the Sub-product that carries its own name');
        $this->assertGreaterThanOrEqual(S::HIGH, $rowA['score']);
        $this->assertSame(S::AMBIGUOUS, $rowA['verdict'], 'B scores 70 for C as well, 12 short of the lead the margin asks: another item competes for it');
        $this->assertStringContainsString('another CRM record scores', implode(' | ', $rowA['reasons']));   // CHANGED ON PURPOSE (WPB, item 6): was 'another item scores'
    }

    // ------------------------------------------------------------------ a pair is only looked for inside one Product and one organisation

    public function test_candidates_are_the_sub_products_of_the_same_product_with_an_offer_and_no_catalogue_pointer_that_are_active(): void
    {
        $w = $this->scholarWorld();
        $item = $this->item('Quarterly Billing Hub', ['category' => 'Quarterly Billing', 'sku' => 'QBH-1', 'description' => null]);
        // The same name under ANOTHER Product, one that already holds a catalogue item, an archived one, and one that is a Product's own row: none is a candidate.
        $other = $this->subProduct('EB-91', 'Quarterly Billing Hub', ['parent_product' => 'EB']);
        $sold = $this->subProduct('SCH-95', 'Quarterly Billing Hub');
        $crm =(int) DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-SOLD', 'item_type' => 'product', 'name' => 'Quarterly Billing Hub', 'is_active' => 1, 'product_master_id' => $w['product'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('g2g_product_master')->where('id', $sold['master'])->update(['crm_product_id' => $crm]);
        $archived = $this->subProduct('SCH-96', 'Quarterly Billing Hub');
        DB::table('g2g_product_master')->where('id', $archived['master'])->update(['status' => 'archived']);

        $row = $this->row($this->suggestions(), $item['master']);
        $this->assertSame(S::NO_MATCH, $row['verdict']);
        $this->assertSame([], $row['alternatives'], 'not the other Product\'s, not the sold one, not the archived one');
        $this->assertNotNull((new S)->pair(self::T, $item['master'], $other['master']), 'a person may still ask about any pair');
    }

    public function test_one_organisations_rows_never_reach_another(): void
    {
        $w = $this->scholarWorld();
        // Organisation 2 has its own item and Sub-product of the same names: they never appear in organisation 1's suggestions, and the reverse.
        $sub2 = $this->subProduct('SCH-01', 'Scholar School ERP', ['what_it_does' => 'Complete school administration ERP'], self::T2);
        $item2 = $this->item('Scholar One ERP - Gujarat Edition', ['category' => 'School ERP', 'sku' => 'SCH-01-GUJ', 'description' => 'School ERP'], self::T2);
        // and a "Different" answer of organisation 2 about its own pair does not touch organisation 1
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T2, 'item_master_id' => $item2['master'], 'survivor_master_id' => $sub2['master'], 'decision' => 'different', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $one = $this->suggestions(self::T);
        $two = $this->suggestions(self::T2);
        $this->assertCount(9, $one['items']);
        $this->assertCount(1, $two['items']);
        $this->assertNotContains($item2['master'], array_column($one['items'], 'itemMasterId'));
        $this->assertNotContains($w['items']['gujarat']['master'], array_column($two['items'], 'itemMasterId'));
        $this->assertSame(S::NO_MATCH, $two['items'][0]['verdict'], 'organisation 2 said Different about its only pair');
        $this->assertSame([], $one['decisions']);
        $this->assertCount(1, $two['decisions']);
        $this->assertSame(S::EDITION, $this->row($one, $w['items']['gujarat']['master'])['verdict'], 'organisation 1 is unchanged');
        // Nothing of the other organisation is even looked at: a row read and then dropped would still leave a note ("not considered", "not this organisation's").
        $this->assertSame([], $one['notes']);
        $this->assertSame([], $two['notes']);
        $this->assertSame(['items' => 9, 'subProducts' => 8], ['items' => $one['counts']['items'], 'subProducts' => $one['counts']['subProducts']]);

        // evidence for a pair of the other organisation is refused, in every direction
        $this->assertNull((new S)->pair(self::T, $w['items']['gujarat']['master'], $sub2['master']));
        $this->assertNull((new S)->pair(self::T2, $item2['master'], $w['subs']['SCH-01']['master']));
        $this->assertNull((new S)->pair(self::T2, $w['items']['gujarat']['master'], $sub2['master']), 'the item belongs to organisation 1');
        $this->assertNotNull((new S)->pair(self::T2, $item2['master'], $sub2['master']), 'a pair of one\'s own is there');
    }

    // ------------------------------------------------------------------ the item itself: stray, junk, binned, archived, unmapped

    public function test_strays_junk_and_items_that_are_not_live_are_told_apart(): void
    {
        $this->scholarWorld();
        $stray = $this->item('Loose Catalogue Item', ['product_master_id' => null, 'category' => 'School ERP', 'sku' => 'SCH-01-X', 'description' => 'School ERP']);
        $junk = $this->item('ZZ UI Widget', ['category' => 'School ERP', 'sku' => 'SCH-01-Z']);
        $binned = $this->item('Old Binned Item', ['category' => 'School ERP']);
        DB::table('crm_products')->where('id', $binned['crm'])->update(['deleted_at' => now()]);
        $archived = $this->item('Archived By A Person', ['category' => 'School ERP']);
        DB::table('g2g_product_master')->where('id', $archived['master'])->update(['status' => 'archived']);
        $unmapped = (int) DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-NOMASTER', 'item_type' => 'product', 'name' => 'No master row', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->pointAtCrm($junk['crm']);

        $r = $this->suggestions();
        $ids = array_column($r['items'], 'itemMasterId');

        $strayRow = $this->row($r, $stray['master']);
        $this->assertSame(S::STRAY, $strayRow['verdict']);
        $this->assertNull($strayRow['candidate'], 'with no Product there is nothing to look in, however well the name fits');
        $this->assertNull($strayRow['productId']);

        $junkRow = $this->row($r, $junk['master']);
        $this->assertSame(S::JUNK, $junkRow['verdict'], 'report only: even a name that fits a Sub-product is not paired');
        $this->assertNull($junkRow['candidate']);
        $this->assertSame(11, $junkRow['usage']['total'], 'but its usage is shown, so nobody deletes what a quote uses (S16: the 12 pointers less the dormant price-book one)');

        $this->assertNotContains($binned['master'], $ids, 'an item in the Recycle Bin is not live');
        $this->assertNotContains($archived['master'], $ids, 'an archived master row is not offered');
        $binnedRows = array_column($r['binnedItems'], null, 'crmId');
        $this->assertArrayHasKey($binned['crm'], $binnedRows);
        $this->assertSame('binned', $binnedRows[$binned['crm']]['state']);
        $this->assertSame('Old Binned Item', $binnedRows[$binned['crm']]['name']);
        $notes = implode(' | ', $r['notes']);
        $this->assertStringContainsString('1 item master row(s) were not considered: archived', $notes);
        $this->assertStringContainsString('1 item master row(s) were not considered: binned', $notes);
        $this->assertStringContainsString('with no master row', $notes);
        $this->assertStringContainsString((string) $unmapped, $notes, 'a live catalogue item that no master row points at is named');
    }

    // ------------------------------------------------------------------ the helpers, pinned

    public function test_the_text_helpers(): void
    {
        $this->assertSame(['fee', 'study', 'class', 'service', 'business', 'status', 'analysis', 'lms', 'gps', 'hrm'], array_map([S::class, 'fold'], ['fees', 'studies', 'classes', 'services', 'business', 'status', 'analysis', 'lms', 'gps', 'hrms']));
        $this->assertSame(['scholar', 'lms', 'pal', 'ai', 'question', 'generation'], S::tokens('Scholar LMS-PAL (AI questions) generation'));
        $this->assertSame(['k', '12', 'school', 'erp'], S::tokens('K-12 School ERP'));
        // content words: no short words, no numbers, no function words, no filler, no brand words
        $this->assertSame(['school', 'erp', 'gujarat'], S::content(S::tokens('The One K-12 School ERP System Gujarat Edition 2026 for AI'), ['k' => true, 'one' => true]));
        $this->assertSame([], S::content(S::tokens('scholar system suite'), ['scholar' => true]));

        $this->assertSame(['min' => 1000000.0, 'max' => 2500000.0], S::parseBand('10-25 L'));
        $this->assertSame(['min' => 50000.0, 'max' => 150000.0], S::parseBand('Rs 50,000 - 1.5 L'));
        $this->assertSame(['min' => 20000000.0, 'max' => 40000000.0], S::parseBand('₹2 - 4 Cr'));
        $this->assertSame(['min' => 5000.0, 'max' => 9000.0], S::parseBand('9k to 5k'));
        $this->assertNull(S::parseBand('To fill'));
        $this->assertNull(S::parseBand('FY26'));
        $this->assertNull(S::parseBand(null));
        $this->assertNull(S::parseBand(''));

        $this->assertTrue(S::offerSaysService('Faculty Training', null, null));
        $this->assertTrue(S::offerSaysService('Setup', 'We provide implementation and onboarding', null));
        $this->assertTrue(S::offerSaysService('Pack', null, 'Managed Services'));
        $this->assertFalse(S::offerSaysService('Fees Intelligence & Decision Support', 'Role-based fee intelligence', 'SaaS (multi-tenant)'), 'Decision Support is not a service');
        $this->assertFalse(S::offerSaysService('Onboard', 'Onboard an organization from Excel', null), 'onboard is not onboarding');
        $this->assertFalse(S::offerSaysService(null, null, null));

        $this->assertTrue(S::looksLikeJunk('ZZ UI Widget'));
        $this->assertTrue(S::looksLikeJunk('Test product 2'));
        $this->assertTrue(S::looksLikeJunk('Dummy'));
        $this->assertFalse(S::looksLikeJunk('Scholar Smart Attendance & Biometric Access'));
        $this->assertFalse(S::looksLikeJunk('Attestation Suite'), 'a word that merely contains "test"');
    }

    public function test_a_price_inside_the_typical_deal_band_is_a_small_hint(): void
    {
        $this->scholarWorld();
        $sub = $this->subProduct('SCH-97', 'Hostel Manager', ['typical_deal_band' => '5-15 L']);
        $inside = $this->item('Hostel Desk', ['category' => null, 'sku' => null, 'description' => null, 'unit_price' => 800000]);
        $outside = $this->item('Hostel Console', ['category' => null, 'sku' => null, 'description' => null, 'unit_price' => 120000]);

        $this->assertSame(S::W_PRICE, (new S)->pair(self::T, $inside['master'], $sub['master'])['parts']['price']);
        $this->assertSame(0.0, (new S)->pair(self::T, $outside['master'], $sub['master'])['parts']['price']);
        // the dev placeholder "To fill" carries no information
        DB::table('g2g_product_offers')->where('id', $sub['offer'])->update(['typical_deal_band' => 'To fill']);
        $this->assertSame(0.0, (new S)->pair(self::T, $inside['master'], $sub['master'])['parts']['price']);
    }

    public function test_the_evidence_for_one_pair_and_its_short_text_for_the_log(): void
    {
        $w = $this->scholarWorld();
        $pair = (new S)->pair(self::T, $w['items']['gujarat']['master'], $w['subs']['SCH-01']['master']);
        $this->assertSame(98.0, $pair['score']);
        $this->assertSame('category +40, sku +30, words +12, text +16', $pair['signals']);
        $this->assertFalse($pair['typeMismatch']);
        $weak = (new S)->pair(self::T, $w['items']['gujarat']['master'], $w['subs']['SCH-05']['master']);
        $this->assertLessThan(S::FLOOR, $weak['score']);

        $this->assertNull((new S)->pair(self::T, 999999, $w['subs']['SCH-01']['master']));
        $this->assertNull((new S)->pair(self::T, $w['items']['gujarat']['master'], 999999));
        $this->assertNull((new S)->pair(self::T, $w['subs']['SCH-01']['master'], $w['items']['gujarat']['master']), 'the arguments are an item and a Sub-product, in that order');
        $this->assertNull((new S)->pair(0, $w['items']['gujarat']['master'], $w['subs']['SCH-01']['master']));
    }

    public function test_decisions_already_taken_are_listed_with_names_and_a_different_pair_is_left_out_of_the_candidates(): void
    {
        $w = $this->scholarWorld();
        DB::table('g2g_product_match_decisions')->insert([
            ['sub_institute_id' => self::T, 'item_master_id' => $w['items']['lms']['master'], 'survivor_master_id' => $w['subs']['SCH-03']['master'], 'decision' => 'different', 'score' => 12.0, 'signals' => 'words +12', 'decided_by' => 7, 'decided_at' => '2026-11-13 10:00:00', 'created_at' => self::STAMP, 'updated_at' => self::STAMP],
        ]);
        $r = $this->suggestions();

        $this->assertCount(1, $r['decisions']);
        $d = $r['decisions'][0];
        $this->assertSame('different', $d['decision']);
        $this->assertSame('Scholar LMS & Digital Classroom', $d['itemName']);
        $this->assertSame('LMS-PAL (AI question generation)', $d['survivorName']);
        $this->assertSame(12.0, $d['score']);
        $this->assertSame(7, $d['decidedBy']);

        $lms = $this->row($r, $w['items']['lms']['master']);
        $this->assertNotContains($w['subs']['SCH-03']['master'], array_column($lms['alternatives'], 'masterId'));
        $this->assertSame([$w['subs']['SCH-03']['master']], $lms['rejected']);
        // the other Sub-product that scored 12 (School FTS / Digital Office) is still there, so the item is not "no match"
        $this->assertSame($w['subs']['SCH-07']['master'], $lms['alternatives'][0]['masterId']);
    }

    public function test_the_thresholds_keep_their_relations(): void
    {
        $support = S::W_WORD_MAX + S::W_TEXT_MAX + S::W_ACRONYM + S::W_PRICE;
        $this->assertLessThan(S::HIGH, $support, 'words, text, acronyms and a price together can never reach HIGH: nothing merges by name alone');
        $this->assertGreaterThan(S::EDITION_MIN, S::HIGH);
        $this->assertGreaterThan(S::FLOOR, S::EDITION_MIN);
        $this->assertSame(S::W_CATEGORY_PHRASE - S::W_CATEGORY_WORDS, S::MARGIN, 'a whole category phrase beats its scrambled words by exactly the margin: the boundary the test above uses');
        $this->assertSame([S::MATCH_HIGH, S::EDITION, S::AMBIGUOUS, S::SERVICE, S::NO_MATCH, S::STRAY, S::JUNK], S::VERDICTS);

        // a pair whose every support signal is at its maximum, and no identity signal, stays below HIGH
        $this->scholarWorld();
        $sub = $this->subProduct('SCH-98', 'Alpha Beta Gamma LMS', ['what_it_does' => 'epsilon zeta theta iota kappa lambda', 'typical_deal_band' => '1-9 L']);
        $item = $this->item('Alpha Beta Gamma Delta Unit', ['category' => null, 'sku' => null, 'description' => 'epsilon zeta theta iota kappa lambda. A Learning Management System', 'unit_price' => 500000]);
        $max = (new S)->pair(self::T, $item['master'], $sub['master']);
        $this->assertSame(S::W_WORD_MAX, $max['parts']['words']);
        $this->assertSame(S::W_TEXT_MAX, $max['parts']['text']);
        $this->assertSame(S::W_ACRONYM, $max['parts']['acronym']);
        $this->assertSame(S::W_PRICE, $max['parts']['price']);
        $this->assertSame(0.0, $max['parts']['category'] + $max['parts']['sku'] + $max['parts']['name']);
        $this->assertSame(53.0, $max['score']);
        $this->assertLessThan(S::HIGH, $max['score']);
    }

    // ------------------------------------------------------------------ the words the screen shows (slice 15, WPB, item 6)

    /** The words the Product Portfolio merge retired from every screen (the front end's own list: lib/portfolio-list/vocabulary.test.ts). */
    private const RETIRED = '/(?<![\w-])(catalogues?|catalogs?|offers?|items?|product[ -]lines?)(?![\w-])/i';

    public function test_every_reason_the_screen_shows_uses_the_new_words_and_the_four_sentences_are_these(): void
    {
        $w = $this->scholarWorld();
        // an edition pair, a stray, and an item that another item competes for: every sentence that used to say "item" or "offer"
        $stray = $this->item('Loose Record', ['product_master_id' => null, 'category' => 'School ERP', 'sku' => 'SCH-01-X', 'description' => 'School ERP']);
        $sub = $this->subProduct('PAY-01', 'Payroll Run');
        $c = $this->item('Payroll Processing Suite', ['category' => 'Payroll Processing', 'sku' => 'PAY-01', 'description' => null]);
        $d = $this->item('Wage Runner Deluxe', ['category' => 'Payroll Processing', 'sku' => 'PAY-01-PAY-02', 'description' => null]);
        $this->subProduct('PAY-02', 'Wage Runner Deluxe');
        $r = $this->suggestions();

        $all = [];
        foreach ($r['items'] as $row) {
            $all = array_merge($all, $row['reasons']);
        }
        $this->assertNotSame([], $all);
        foreach ($all as $reason) {
            $this->assertDoesNotMatchRegularExpression(self::RETIRED, $reason, "a retired word is on the screen: {$reason}");
        }

        // the four sentences, exactly (the front end's KNOWN DEFECT test counts these three words today and can be set to 0)
        $gujarat = $this->row($r, $w['items']['gujarat']['master']);
        $core = $this->row($r, $w['items']['core']['master']);
        $this->assertContains('the SKU SCH-01-GUJ holds the Sub-product code SCH-01 (+30)', $gujarat['reasons']);
        $this->assertContains("CRM record(s) {$core['itemMasterId']} \"Scholar Core School Management\" have the same best Sub-product: they are cuts of one thing, choose which one is the pair", $gujarat['reasons']);
        $this->assertContains('this CRM record has no Product, so there is no set of Sub-products to look in: give it a Product first', $this->row($r, $stray['master'])['reasons']);
        $competed = $this->row($r, $c['master']);
        $this->assertSame(S::AMBIGUOUS, $competed['verdict']);
        $this->assertTrue(count(array_filter($competed['reasons'], fn (string $s) => str_starts_with($s, 'another CRM record scores '))) === 1);
    }
}
