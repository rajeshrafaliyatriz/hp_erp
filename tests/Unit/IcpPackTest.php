<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpScorer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Scholar K-12 ICP pack: scoring that never confuses "unknown" with "bad", the day's searches,
 * hard disqualifiers, signal decay and the prompt block. Pure; the pack comes from config/signals_icp.php.
 */
class IcpPackTest extends TestCase
{
    private function pack(): array
    {
        return config('signals_icp.scholar_k12');
    }

    public function test_a_school_whose_facts_are_unknown_is_unscored_not_a_bad_fit(): void
    {
        $r = (new IcpScorer())->score(['name' => 'Tapovan U B Sanskar Tirth', 'state' => 'Gujarat', 'board' => 'GSEB'], $this->pack());

        $this->assertSame('unscored', $r['status']);
        $this->assertNull($r['tier']);
        $this->assertContains('campuses', $r['notes']['unknown']);
        $this->assertContains('students', $r['notes']['unknown']);
        $this->assertLessThan(50, $r['known_share']);
    }

    public function test_a_school_that_passes_every_known_must_have_with_enough_facts_is_a_candidate_and_never_gets_a_computed_tier(): void
    {
        $r = (new IcpScorer())->score([
            'name' => 'Sunrise Valley School', 'state' => 'Gujarat', 'board' => 'CBSE', 'level' => 'Senior Secondary Level',
            'attributes' => ['campuses' => 4, 'students' => 3500, 'management' => 'private'],
        ], $this->pack());

        $this->assertSame('candidate', $r['status']);
        $this->assertNull($r['tier'], 'tier A, B or C is how we enter: a person judges it');
        $this->assertSame(25, $r['static_points'], '10 students + 5 grades + 5 campuses + 5 board');
        $this->assertSame(83, $r['fit_pct'], '25 of the 30 points that are known');
        $this->assertSame(['IcpScorer inr' => '1,00,000', '2,50,000' => '2,50,000', '25,000' => '25,000', '999' => '999'], ['IcpScorer inr' => IcpScorer::inr(100000), '2,50,000' => IcpScorer::inr(250000), '25,000' => IcpScorer::inr(25000), '999' => IcpScorer::inr(999)]);
        $this->assertSame(2, $r['watch_priority']);

        $big = (new IcpScorer())->score(['name' => 'Another School', 'state' => 'Maharashtra', 'board' => 'ICSE', 'attributes' => ['students' => 4500, 'campuses' => 1]], $this->pack());
        $this->assertSame('candidate', $big['status']);
        $this->assertSame(1, $big['watch_priority'], '4,000+ students is watched first');
    }

    /** @dataProvider failedMustHaves */
    public function test_a_must_have_fails_only_on_a_known_fact(array $account, string $reason): void
    {
        $r = (new IcpScorer())->score($account + ['name' => 'Some School', 'state' => 'Gujarat'], $this->pack());

        $this->assertSame('disqualified', $r['status']);
        $this->assertSame($reason, $r['notes']['disqualified_by']);
    }

    public static function failedMustHaves(): array
    {
        return [
            'too few students' => [['attributes' => ['students' => 1500]], 'under 2,000 students'],
            'classes stop at Grade 8' => [['level' => 'Upper Primary Level'], 'classes only up to Grade 8 (no Grade 10 or 12)'],
            'fee above the range' => [['attributes' => ['fee_inr' => 150000]], 'annual fee outside ₹25,000–₹1,00,000'],
            'fee below the range' => [['attributes' => ['fee_inr' => 12000]], 'annual fee outside ₹25,000–₹1,00,000'],
            'Rajasthan is out in the revised ICP' => [['state' => 'Rajasthan'], 'outside the target regions'],
            'not private' => [['attributes' => ['management' => 'government']], 'not a private school (trust, society or Section 8 company)'],
            'a flag a person set' => [['attributes' => ['flags' => ['wants_cheapest_erp_only']]], 'wants only the cheapest ERP'],
        ];
    }

    public function test_an_unknown_must_have_never_rejects_a_school(): void
    {
        $r = (new IcpScorer())->score(['name' => 'Some School', 'state' => 'Gujarat'], $this->pack());

        $this->assertSame('unscored', $r['status']);
        $this->assertNull($r['notes']['disqualified_by'] ?? null);
    }

    public function test_plain_gseb_stays_unknown_until_the_medium_is_known(): void
    {
        $s = new IcpScorer();
        $plain = $s->score(['name' => 'Some School', 'state' => 'Gujarat', 'board' => 'GSEB'], $this->pack());
        $this->assertContains('board', $plain['notes']['unknown']);
        $this->assertSame(['board'], $plain['notes']['open_questions']);

        $english = $s->score(['name' => 'Some School', 'state' => 'Gujarat', 'board' => 'GSEB', 'attributes' => ['medium' => 'english']], $this->pack());
        $this->assertSame(5, $english['notes']['known']['board']['points']);
    }

    public function test_the_stored_facts_are_30_points_and_the_evidence_points_make_the_other_70(): void
    {
        $this->assertSame(30, array_sum(array_column($this->pack()['criteria'], 'max')));
        $this->assertSame(70, array_sum($this->pack()['evidence_points']));
        $this->assertSame(['Gujarat', 'Dadra and Nagar Haveli and Daman and Diu', 'Maharashtra'], $this->pack()['regions']);
    }

    /** @dataProvider disqualified */
    public function test_hard_rules_remove_an_organisation_whatever_else_is_known(string $name, string $rule): void
    {
        $r = (new IcpScorer())->score(['name' => $name, 'state' => 'Gujarat', 'board' => 'CBSE', 'attributes' => ['campuses' => 3, 'students' => 2000]], $this->pack());

        $this->assertSame('disqualified', $r['status']);
        $this->assertSame($rule, $r['notes']['disqualified_by']);
        $this->assertSame(9, $r['watch_priority']);
    }

    public static function disqualified(): array
    {
        return [
            ['Little Stars Play School', 'pre-primary or play school only'],
            ['Apex IIT-JEE Coaching Classes', 'coaching or tuition centre'],
            ['V. T. College of Commerce', 'college or university'],
            ['Acme EdTech Solutions', 'edtech, ERP or LMS vendor'],
        ];
    }

    public function test_a_private_sounding_name_is_watched_first_among_schools_with_no_facts(): void
    {
        $s = new IcpScorer();
        $this->assertSame(1, $s->score(['name' => 'Podar World School'], $this->pack())['watch_priority']);
        $this->assertSame(3, $s->score(['name' => 'Shri V K Patel High School'], $this->pack())['watch_priority']);
        $this->assertSame(2, $s->score(['name' => 'Shri V K Patel High School', 'level' => 'Secondary Level'], $this->pack())['watch_priority']);
    }

    public function test_the_catalogue_rotates_so_every_query_runs_within_a_few_days_and_a_run_mixes_source_types(): void
    {
        $pack = new IcpPack('scholar_k12', $this->pack());
        $all = collect($this->pack()['queries'])->pluck('id')->all();

        $seen = [];
        $first = null;
        for ($d = 0; $d < 12; $d++) {
            $run = $pack->catalogueForRun(Carbon::create(2026, 10, 1)->addDays($d));
            $this->assertCount(5, $run);
            $first ??= $run;
            foreach ($run as $q) {
                $seen[$q['id']] = true;
            }
        }
        $this->assertEqualsCanonicalizing($all, array_keys($seen), 'every catalogue query runs within 12 days');
        $this->assertGreaterThan(1, count(array_unique(array_column($first, 'source'))), 'one run mixes kinds of evidence');
    }

    public function test_every_catalogue_query_is_in_the_buyers_words_not_ours(): void
    {
        foreach ($this->pack()['queries'] as $q) {
            $this->assertDoesNotMatchRegularExpression('/\b(AI solutions?|artificial intelligence|enterprise AI|generative AI|business intelligence)\b/i', $q['q'], $q['id']);
            $this->assertContains($q['topic'], ['news', 'general']);
            $this->assertGreaterThan(0, $q['days']);
        }
        $tenders = array_filter($this->pack()['queries'], fn ($q) => $q['source'] === 'tender');
        $this->assertNotEmpty($tenders);
        foreach ($tenders as $q) {
            $this->assertSame('general', $q['topic'], 'a tender page is not a news article');
            $this->assertGreaterThanOrEqual(60, $q['days'], 'tenders stay open for weeks');
            $this->assertNotEmpty($q['domains']);
        }
    }

    public function test_a_school_query_names_the_school_and_strips_quotes(): void
    {
        $pack = new IcpPack('scholar_k12', $this->pack());
        $q = $pack->accountQuery('Podar "World" School', 'Vadodara');

        $this->assertSame('Podar World School Vadodara', $q['q'], 'plain name and city: no quotes, no event words');
        $this->assertSame('general', $q['topic']);
    }

    public function test_signals_decay_by_type_and_a_tender_lasts_until_its_closing_date(): void
    {
        $pack = new IcpPack('scholar_k12', $this->pack());
        $this->assertSame(90, $pack->decayDays('expansion'));
        $this->assertSame(60, $pack->decayDays('hiring'));
        $this->assertSame(270, $pack->decayDays('leadership'));
        $this->assertNull($pack->decayDays('tender'));
        $this->assertSame(90, $pack->decayDays('something-new'));
    }

    public function test_every_organisation_gets_the_scholarclone_pack_and_nothing_else(): void
    {
        $this->assertSame('scholar_k12', IcpPack::forTenant(6)?->key);
        $this->assertSame('scholar_k12', IcpPack::forTenant(7)?->key);
        $this->assertSame(['scholar_k12'], array_map(fn ($p) => $p->key, IcpPack::allActive(7)));
    }

    public function test_the_prompt_block_carries_the_icp_the_roles_and_the_never_list(): void
    {
        $pack = new IcpPack('scholar_k12', $this->pack());
        $text = $pack->promptBlock();

        $this->assertStringContainsString('private K-12 schools and school groups', $text);
        $this->assertStringContainsString('school_erp', $text);
        $this->assertStringContainsString('trustee or chairperson', $text);
        $this->assertStringContainsString('coaching or tuition centre', $text);
        $this->assertStringContainsString('needs_statement', $text);
        $this->assertStringContainsString('closing_date', $text);
        $this->assertStringContainsString('never a named person', $text);
    }

    public function test_the_buying_window_is_december_to_march(): void
    {
        $pack = new IcpPack('scholar_k12', $this->pack());
        $this->assertTrue($pack->inBuyingWindow(Carbon::create(2026, 12, 15)));
        $this->assertTrue($pack->inBuyingWindow(Carbon::create(2027, 2, 1)));
        $this->assertFalse($pack->inBuyingWindow(Carbon::create(2026, 7, 1)));
    }
}
