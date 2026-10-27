<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpScorer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The FINAL ScholarClone K-12 ICP, rule by rule: every must-have, every disqualifier, the 12-month ERP WATCH rule,
 * the board rule, and one school per score band (P1, P2, P3, not pursued). Pure; the ICP is the template in
 * config/signals_icp.php.
 */
class ScholarIcpFinalTest extends TestCase
{
    private function pack(): array
    {
        return config('signals_icp.scholar_k12');
    }

    /** A school that passes every must-have and has every stored fact known. */
    private function school(array $over = [], array $attr = []): array
    {
        return $over + [
            'name' => 'Sunrise Valley School', 'city' => 'Anand', 'state' => 'Gujarat', 'board' => 'CBSE', 'level' => 'Senior Secondary Level',
            'attributes' => $attr + ['students' => 3000, 'campuses' => 2, 'management' => 'private', 'fee_inr' => 60000],
        ];
    }

    private function score(array $account, ?Carbon $now = null): array
    {
        return (new IcpScorer())->score($account, $this->pack(), $now ?? Carbon::create(2026, 10, 20));
    }

    public function test_the_template_is_valid(): void
    {
        // Valid, apart from the 9 catalogue searches the built-in definition runs, which is above the editor's 0 to 6 per kind: a saved ICP may keep it
        // unchanged (ProductIcpService::withoutBuiltInPerRunProblems), and the total ceiling still applies.
        $problems = (new IcpProfileValidator())->validate($this->pack());
        $this->assertSame([], \App\Domain\Signals\Products\ProductIcpService::withoutBuiltInPerRunProblems($this->pack(), $this->pack(), $problems));
    }

    // ── must-haves ───────────────────────────────────────────────────────────

    /** @dataProvider mustHavesThatPass */
    public function test_a_school_that_meets_the_must_haves_is_a_candidate(array $over, array $attr): void
    {
        $this->assertSame('candidate', $this->score($this->school($over, $attr))['status']);
    }

    public static function mustHavesThatPass(): array
    {
        return [
            'private trust' => [[], ['management' => 'trust']],
            'society' => [[], ['management' => 'society']],
            'Section 8 company' => [[], ['management' => 'section 8 company']],
            'exactly 2,000 students' => [[], ['students' => 2000]],
            'one campus with 2,000+ students' => [[], ['students' => 2500, 'campuses' => 1]],
            'fee at the lower limit' => [[], ['fee_inr' => 25000]],
            'fee at the upper limit' => [[], ['fee_inr' => 100000]],
            'Maharashtra' => [['state' => 'Maharashtra'], []],
            'Daman and Diu region' => [['state' => 'Dadra and Nagar Haveli and Daman and Diu'], []],
            'up to Grade 10' => [['level' => 'Secondary Level'], []],
            'not digitised is not a negative' => [[], ['systems' => 'paper and excel']],
        ];
    }

    /** @dataProvider mustHavesThatFail */
    public function test_a_school_that_fails_a_known_must_have_is_not_icp(array $over, array $attr, string $reason): void
    {
        $r = $this->score($this->school($over, $attr));

        $this->assertSame('disqualified', $r['status']);
        $this->assertSame($reason, $r['notes']['disqualified_by']);
        $this->assertSame(9, $r['watch_priority']);
    }

    public static function mustHavesThatFail(): array
    {
        return [
            'government school' => [[], ['management' => 'government'], 'not a private school (trust, society or Section 8 company)'],
            'aided school' => [[], ['management' => 'aided'], 'not a private school (trust, society or Section 8 company)'],
            '1,999 students' => [[], ['students' => 1999], 'under 2,000 students'],
            'only up to Grade 8' => [['level' => 'Upper Primary Level'], [], 'classes only up to Grade 8 (no Grade 10 or 12)'],
            'fee below range' => [[], ['fee_inr' => 24999], 'annual fee outside ₹25,000–₹1,00,000'],
            'fee above range' => [[], ['fee_inr' => 100001], 'annual fee outside ₹25,000–₹1,00,000'],
            'Rajasthan is out' => [['state' => 'Rajasthan'], [], 'outside the target regions'],
            'Delhi is out' => [['state' => 'Delhi'], [], 'outside the target regions'],
        ];
    }

    public function test_an_unknown_fact_never_fails_a_must_have(): void
    {
        $r = $this->score(['name' => 'Sunrise Valley School', 'state' => 'Gujarat']);

        $this->assertSame('unscored', $r['status']);
        $this->assertArrayNotHasKey('disqualified_by', $r['notes']);
    }

    // ── disqualifiers ────────────────────────────────────────────────────────

    /** @dataProvider disqualifiedByName */
    public function test_each_disqualifier_removes_an_organisation(string $name, string $rule): void
    {
        $this->assertSame($rule, $this->score($this->school(['name' => $name]))['notes']['disqualified_by']);
    }

    public static function disqualifiedByName(): array
    {
        return [
            ['Tiny Tots Play School', 'pre-primary or play school only'],
            ['Montessori House', 'pre-primary or play school only'],
            ['Apex Coaching Classes', 'coaching or tuition centre'],
            ['Excel Tuition Centre', 'coaching or tuition centre'],
            ['Gujarat College of Commerce', 'college or university'],
            ['Nirma University', 'college or university'],
            ['LearnWell EdTech Pvt', 'edtech, ERP or LMS vendor'],
            ['Schoolwise ERP Company', 'edtech, ERP or LMS vendor'],
        ];
    }

    /** @dataProvider manualFlags */
    public function test_each_flag_a_person_sets_disqualifies(string $flag, string $reason): void
    {
        $r = $this->score($this->school([], ['flags' => [$flag]]));

        $this->assertSame('disqualified', $r['status']);
        $this->assertSame($reason, $r['notes']['disqualified_by']);
    }

    public static function manualFlags(): array
    {
        return [
            ['wants_cheapest_erp_only', 'wants only the cheapest ERP'],
            ['no_sponsor', 'no one at the top willing to sponsor the change'],
            ['heavy_custom_development', 'requires heavy custom development'],
            ['parent_platform_mandated', 'parent organisation mandates its own global platform'],
        ];
    }

    // ── WATCH, not reject ────────────────────────────────────────────────────

    public function test_a_school_that_signed_an_erp_in_the_last_twelve_months_is_watched_not_rejected(): void
    {
        $r = $this->score($this->school([], ['erp_signed_on' => '2026-03-01']), Carbon::create(2026, 10, 20));

        $this->assertSame('watch', $r['status']);
        $this->assertSame(3, $r['watch_priority'], 'watched schools are checked rarely, never first');
        $this->assertSame('2027-03-01', $r['notes']['watch']['recheck_after'], 'look again at renewal');
        $this->assertStringContainsString('watch until renewal', $r['notes']['watch']['reason']);
        $this->assertArrayNotHasKey('disqualified_by', $r['notes']);
    }

    public function test_a_contract_older_than_twelve_months_or_unknown_is_not_a_watch(): void
    {
        $now = Carbon::create(2026, 10, 20);
        $this->assertSame('candidate', $this->score($this->school([], ['erp_signed_on' => '2025-09-01']), $now)['status']);
        $this->assertSame('candidate', $this->score($this->school(), $now)['status']);
        $this->assertSame('candidate', $this->score($this->school([], ['erp_signed_on' => 'not a date']), $now)['status']);
    }

    public function test_a_hard_disqualifier_beats_a_watch(): void
    {
        $r = $this->score($this->school([], ['erp_signed_on' => '2026-06-01', 'students' => 900]));

        $this->assertSame('disqualified', $r['status']);
    }

    // ── board ────────────────────────────────────────────────────────────────

    public function test_gseb_gujarati_medium_counts_only_when_established_and_running_both_mediums(): void
    {
        $pts = fn (array $attr) => $this->score($this->school(['board' => 'GSEB'], $attr))['notes']['known']['board']['points'] ?? null;

        $this->assertSame(5, $pts(['medium' => 'english']));
        $this->assertSame(5, $pts(['medium' => 'both', 'established' => 'yes']));
        $this->assertSame(0, $pts(['medium' => 'gujarati']), 'known Gujarati-only earns nothing');
        $this->assertSame(0, $pts(['medium' => 'both', 'established' => 'no']) ?? 0);
        $this->assertNull($pts([]), 'medium unknown: not scored either way');
    }

    // ── cities ───────────────────────────────────────────────────────────────

    public function test_a_school_in_a_named_city_is_watched_before_one_elsewhere(): void
    {
        $bare = fn (string $city) => $this->score(['name' => 'Plain School', 'city' => $city, 'state' => 'Gujarat'])['watch_priority'];

        $this->assertSame(2, $bare('Surat'));
        $this->assertSame(2, $bare('Pune'));
        $this->assertSame(3, $bare('Bhavnagar'), 'other cities in the regions are allowed, at lower priority');
    }

    // ── the 100-point score and its bands ────────────────────────────────────

    public function test_the_stored_facts_are_30_points_and_the_evidence_is_70(): void
    {
        $this->assertSame(30, array_sum(array_column($this->pack()['criteria'], 'max')));
        $this->assertSame(70, array_sum($this->pack()['evidence_points']));
    }

    public function test_counted_evidence_follows_the_icp_steps(): void
    {
        $s = new IcpScorer();
        foreach ([[0, 0], [1, 10], [2, 20], [5, 20]] as [$count, $points]) {
            $this->assertSame($points, $s->evidencePoints('active_triggers', $count, $this->pack()), "{$count} triggers");
        }
        foreach ([[0, 0], [1, 5], [2, 10], [3, 15], [5, 15]] as [$count, $points]) {
            $this->assertSame($points, $s->evidencePoints('need_across_parts', $count, $this->pack()), "{$count} parts");
        }
    }

    /** @dataProvider bands */
    public function test_one_school_per_priority_band(array $attr, array $evidence, string $level, ?int $priority, int $total): void
    {
        $r = (new IcpScorer())->total($this->school(['level' => $level, 'board' => 'CBSE'], $attr), $evidence, $this->pack(), Carbon::create(2026, 10, 20));

        $this->assertSame($total, $r['total']);
        $this->assertSame($priority, $r['priority']);
        $this->assertSame($priority !== null, $r['pursue']);
    }

    public static function bands(): array
    {
        // static = students + grades + campuses + board
        return [
            // 3,500 students 10 + Grade 12 5 + 2 campuses 5 + CBSE 5 = 25; evidence 20+20+15+10 = 65 -> 90
            'P1 (75 or more)' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 20, 'active_triggers' => 20, 'financial_capacity' => 15, 'need_across_parts' => 10], 'Senior Secondary Level', 1, 90],
            // 25 + 10+10+10+5 = 35 -> 60
            'P2 (55 to 74)' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 10, 'active_triggers' => 10, 'financial_capacity' => 10, 'need_across_parts' => 5], 'Senior Secondary Level', 2, 60],
            // 25 + 20 = 45
            'P3 (40 to 54)' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 10, 'financial_capacity' => 10], 'Senior Secondary Level', 3, 45],
            // 2,000 students 10 + Grade 10 3 + 1 campus 2 + CBSE 5 = 20; evidence 10 -> 30
            'not pursued (below 40)' => [['students' => 2000, 'campuses' => 1], ['active_triggers' => 10], 'Secondary Level', null, 30],
            // exactly on the lines
            'exactly 75 is P1' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 20, 'active_triggers' => 20, 'financial_capacity' => 10], 'Senior Secondary Level', 1, 75],
            'exactly 55 is P2' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 20, 'active_triggers' => 10], 'Senior Secondary Level', 2, 55],
            'exactly 40 is P3' => [['students' => 3500, 'campuses' => 2], ['leadership_willingness' => 15], 'Senior Secondary Level', 3, 40],
        ];
    }

    public function test_evidence_above_its_maximum_is_capped_and_only_a_candidate_is_ranked(): void
    {
        $s = new IcpScorer();
        $capped = $s->total($this->school(), ['leadership_willingness' => 999], $this->pack(), Carbon::create(2026, 10, 20));
        $this->assertSame(20, $capped['evidence_points'], 'never trusted above the maximum');

        $watched = $s->total($this->school([], ['erp_signed_on' => '2026-08-01']), ['leadership_willingness' => 20, 'active_triggers' => 20, 'financial_capacity' => 15, 'need_across_parts' => 15], $this->pack(), Carbon::create(2026, 10, 20));
        $this->assertNull($watched['priority'], 'a watched school is not ranked');

        $unscored = $s->total(['name' => 'Unknown School', 'state' => 'Gujarat'], ['leadership_willingness' => 20, 'active_triggers' => 20, 'financial_capacity' => 15, 'need_across_parts' => 15], $this->pack());
        $this->assertNull($unscored['priority'], 'too few facts: not ranked, however strong the evidence');
    }
}
