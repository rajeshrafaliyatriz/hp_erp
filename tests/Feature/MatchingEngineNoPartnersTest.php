<?php

namespace Tests\Feature;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\ProductOffer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Partner Network is gone (26 Nov 2026; Docs/agentic-crm/briefs/MARKET-SIGNALS-PARTNER-REMOVAL.md): the matching engine matches a signal to product offers and nothing else.
 *
 *   - the result carries no `partner_evaluations` and no partner is read, even when the registry table still exists with an Active partner in it, and even when it is gone (the
 *     table is dropped by 2026_12_28_110000 after the backend is deployed; the engine must run on both sides of that drop)
 *   - the offer evaluation is exactly what it was: the same score, the same reasons, the same keys apart from the removed one
 *   - an offer that is partner_sellable (a product readiness flag, kept) is matched like any other offer
 */
class MatchingEngineNoPartnersTest extends TestCase
{
    private const T = 1;

    /** The keys of one offer match: everything it had before, minus `partner_evaluations`. */
    private const MATCH_KEYS = [
        'offer', 'match_score', 'matched_needs', 'matched_need_codes', 'matched_segments', 'profile_match', 'readiness_status', 'readiness_confirmed',
        'is_deliverable', 'match_reasons', 'satisfied_criteria', 'missing_criteria',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // The product tables of the history migration, which still creates g2g_partners: the registry table is there, with an Active partner, for the tests that need it.
        (require base_path('database/migrations/2026_09_30_220000_create_g2g_portfolio_and_partners_tables.php'))->up();
        DB::table('g2g_partners')->insert([
            'sub_institute_id' => self::T, 'partner_id' => 'PT-1', 'partner_name' => 'Acme Integrators', 'partner_type' => 'Regional SI', 'partner_status' => 'Active',
            'offers_authorized' => json_encode(['OFF-SELL']), 'capacity_available' => 3, 'deal_registration_agreed' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function offer(string $id, string $readiness): ProductOffer
    {
        return ProductOffer::create([
            'sub_institute_id' => self::T, 'offer_id' => $id, 'name' => 'Fee Collection Suite ' . $id, 'parent_product' => 'Scholar K-12', 'readiness_status' => $readiness,
            'needs_solved' => ['fee collection'], 'primary_segments' => [], 'trigger_signals' => ['expansion'],
        ]);
    }

    private function signal(): object
    {
        return (object) [
            'title' => 'Podar group opens a new campus',
            'observed_event' => 'The group announced an expansion with a new campus in Vadodara and will need fee collection from day one.',
            'why_indicates_need' => 'A new campus runs admissions and fee collection from the first week.',
            'business_fit' => null,
        ];
    }

    public function test_the_result_has_no_partner_evaluations_even_for_a_partner_sellable_offer_with_an_active_partner_in_the_registry(): void
    {
        $sellable = $this->offer('OFF-SELL', 'Live – with client');
        $this->assertTrue($sellable->partner_sellable, 'the readiness flag is calculated on save and is kept');

        $result = (new MatchingEngine())->matchForSignal($this->signal(), 'opportunity', self::T);

        $this->assertSame(['evidence', 'offer_matches'], array_keys($result));
        $this->assertCount(1, $result['offer_matches']);
        $match = $result['offer_matches'][0];
        $this->assertSame(self::MATCH_KEYS, array_keys($match));
        $this->assertArrayNotHasKey('partner_evaluations', $match);
        $this->assertStringNotContainsString('partner_evaluations', json_encode($result));
    }

    public function test_the_score_the_reasons_and_the_evidence_are_what_they_were(): void
    {
        $this->offer('OFF-SELL', 'Live – with client');
        $this->offer('OFF-PLAIN', 'In development');

        $result = (new MatchingEngine())->matchForSignal($this->signal(), 'opportunity', self::T);

        $this->assertSame([], $result['evidence']['identified_needs'], 'the generic keyword list has none of these words');
        $this->assertSame([], $result['evidence']['need_codes']);
        $this->assertNull($result['evidence']['buyer_segment']);
        $this->assertSame(['expansion'], $result['evidence']['trigger_signals']);

        $byOffer = collect($result['offer_matches'])->keyBy(fn ($m) => $m['offer']->offer_id);
        foreach (['OFF-SELL', 'OFF-PLAIN'] as $id) {
            $m = $byOffer[$id];
            $this->assertSame(70, $m['match_score'], "{$id}: the score");
            $this->assertSame(['fee collection'], $m['matched_needs']);
            $this->assertSame([
                'Signal text indicates need for: fee collection',
                'Signal mentions trigger event: expansion',
                'The event matches what this product responds to: Expansion.',
            ], array_values($m['match_reasons']), "{$id}: the reasons");
            $this->assertSame(array_values($m['matched_needs']), $m['satisfied_criteria']);
            $this->assertSame([], $m['missing_criteria']);
            $this->assertSame(self::MATCH_KEYS, array_keys($m), "{$id}: the same keys, apart from the removed one");
        }
        $this->assertTrue($byOffer['OFF-SELL']['offer']->partner_sellable);
        $this->assertFalse($byOffer['OFF-PLAIN']['offer']->partner_sellable);
    }

    public function test_the_engine_still_runs_when_the_registry_table_has_been_dropped(): void
    {
        $this->offer('OFF-SELL', 'Live – with client');
        $with = (new MatchingEngine())->matchForSignal($this->signal(), 'opportunity', self::T);

        Schema::drop('g2g_partners');
        $without = (new MatchingEngine())->matchForSignal($this->signal(), 'opportunity', self::T);

        $this->assertFalse(Schema::hasTable('g2g_partners'));
        $this->assertCount(1, $without['offer_matches']);
        $strip = fn (array $r) => json_decode(json_encode($r), true);
        $this->assertSame($strip($with), $strip($without), 'with the table or without it, the same answer');
    }

    public function test_the_engine_does_not_query_the_registry(): void
    {
        $this->offer('OFF-SELL', 'Live – with client');
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        (new MatchingEngine())->matchForSignal($this->signal(), 'opportunity', self::T);

        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'g2g_partners'))));
        $this->assertNotSame([], array_filter($queries, fn ($sql) => str_contains($sql, 'g2g_product_offers')), 'the offers are still read');
    }
}
