<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Products\SchoolTriggers;
use PHPUnit\Framework\TestCase;

/** Pure tests: no database, no provider, no application boot. */
class SchoolTriggersTest extends TestCase
{
    public function test_detects_the_strongest_trigger_first(): void
    {
        $t = SchoolTriggers::detect('Tender for school ERP issued. The school also announced a new campus and a new principal.');

        $this->assertSame('erp_lms_tender', $t[0]['key']);
        $this->assertEqualsCanonicalizing(['erp_lms_tender', 'campus_expansion', 'leadership_change'], array_column($t, 'key'));
    }

    public function test_plain_school_with_no_event_has_no_trigger(): void
    {
        $this->assertSame([], SchoolTriggers::detect('Cricket match results from the district.'));
        $this->assertSame([], SchoolTriggers::detect('A school established in 1985 offering classes 1 to 12.'));
    }

    public function test_same_event_from_different_pages_has_one_fingerprint(): void
    {
        $a = SchoolTriggers::fingerprint('The Delhi Public School, Pune', 'campus_expansion', '2026-11-15');
        $b = SchoolTriggers::fingerprint('Delhi Public  School Pune', 'campus_expansion', '2026-11-02');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, SchoolTriggers::fingerprint('Delhi Public School Pune', 'leadership_change', '2026-11-15'));
        $this->assertNotSame($a, SchoolTriggers::fingerprint('Delhi Public School Pune', 'campus_expansion', '2026-12-01'));
        $this->assertNotSame($a, SchoolTriggers::fingerprint('Delhi Public School Pune', 'campus_expansion', null));
    }

    public function test_fresh_strong_evidenced_signal_scores_high(): void
    {
        $s = SchoolTriggers::score(['fit' => 90, 'trigger_strength' => 85, 'evidence' => 90, 'has_contact' => true, 'event_date' => '2026-11-15', 'today' => '2026-11-21', 'expires_in_days' => 120]);

        $this->assertGreaterThanOrEqual(85, $s['priority']);
        $this->assertSame(90, $s['confidence']);
        $this->assertFalse($s['expired']);
    }

    public function test_undated_signal_is_capped_even_with_everything_else_perfect(): void
    {
        $s = SchoolTriggers::score(['fit' => 100, 'trigger_strength' => 100, 'evidence' => 100, 'has_contact' => true, 'event_date' => null]);

        $this->assertSame(SchoolTriggers::UNDATED_PRIORITY_CAP, $s['priority']);
        $this->assertNotEmpty($s['notes']);
    }

    public function test_expired_event_is_flagged_and_capped(): void
    {
        $s = SchoolTriggers::score(['fit' => 100, 'trigger_strength' => 100, 'evidence' => 100, 'event_date' => '2026-01-01', 'today' => '2026-11-21', 'expires_in_days' => 60]);

        $this->assertTrue($s['expired']);
        $this->assertLessThanOrEqual(30, $s['priority']);
    }

    public function test_priority_does_not_hide_weak_evidence(): void
    {
        $s = SchoolTriggers::score(['fit' => 90, 'trigger_strength' => 95, 'evidence' => 20, 'event_date' => '2026-11-20', 'today' => '2026-11-21', 'expires_in_days' => 45]);

        $this->assertSame(20, $s['confidence']);
        $this->assertGreaterThan($s['confidence'], $s['priority']);
        $this->assertStringContainsString('Weak evidence', implode(' ', $s['notes']));
    }
}
