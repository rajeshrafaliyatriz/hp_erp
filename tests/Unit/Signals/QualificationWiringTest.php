<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketImporter;
use Tests\TestCase;

/** The qualification switch in the importer: off = the old claim_level lookup exactly; on = the grade-based rule. */
final class QualificationWiringTest extends TestCase
{
    private function label(string $claim, string $trigger, ?array $assessment): string
    {
        $i = (new \ReflectionClass(MarketImporter::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($i, 'qualificationFor'))->invoke($i, $claim, $trigger, $assessment);
    }

    private function a(string $grade, string $level, int $fit): array
    {
        return ['signal_grade' => ['grade' => $grade], 'evidence_maturity' => ['level' => $level], 'fit' => ['total' => $fit]];
    }

    public function test_the_shipped_defaults_are_off(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals.php';
        $this->assertFalse($cfg['qualification_rule']['by_grade']);
        $this->assertFalse($cfg['surface_grade']);
        $this->assertSame(45, $cfg['qualification_rule']['min_fit']);
    }

    public function test_off_keeps_the_old_lookup_whatever_the_grade_says(): void
    {
        config(['signals.qualification_rule.by_grade' => false]);
        $this->assertSame('New Opportunity', $this->label('confirmed', 'expansion', $this->a('lead', 'E0', 0)));
        $this->assertSame('Monitoring', $this->label('hypothesis', 'expansion', $this->a('ready', 'E3', 99)));
        $this->assertSame('Needs Verification', $this->label('inference', 'expansion', null));
    }

    public function test_on_uses_the_grade_rule_and_leaves_confidence_alone(): void
    {
        config(['signals.qualification_rule.by_grade' => true, 'signals.qualification_rule.min_fit' => 45]);
        $this->assertSame('Monitoring', $this->label('confirmed', 'expansion', $this->a('lead', 'E3', 90)));
        $this->assertSame('Needs Verification', $this->label('hypothesis', 'expansion', $this->a('verify', 'E0', 90)));
        $this->assertSame('New Opportunity', $this->label('hypothesis', 'expansion', $this->a('ready', 'E2', 60)));
        $this->assertSame('Relevant Requirement Found', $this->label('hypothesis', 'tender', $this->a('ready', 'E3', 60)));
        $this->assertSame('Needs Verification', $this->label('confirmed', 'expansion', $this->a('ready', 'E1', 90)), 'a page read alone never qualifies');
        $i = (new \ReflectionClass(MarketImporter::class))->newInstanceWithoutConstructor();
        $conf = new \ReflectionMethod($i, 'confidenceFor');
        $this->assertSame($conf->invoke($i, 'confirmed'), $conf->invoke($i, 'confirmed'), 'confidence still comes from the claim level only');
    }

    public function test_on_without_a_grade_falls_back_to_the_old_rule(): void
    {
        config(['signals.qualification_rule.by_grade' => true]);
        $this->assertSame('New Opportunity', $this->label('confirmed', 'expansion', []));
        $this->assertSame('Monitoring', $this->label('hypothesis', 'expansion', []));
    }

    public function test_dropping_rejected_candidates_is_off_by_default_and_flag_guarded(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals.php';
        $this->assertFalse($cfg['drop_rejected_grade']);
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("config('signals.drop_rejected_grade', false) && (string) (\$assessment['signal_grade']['grade'] ?? '') === 'reject'", $src);
        $this->assertStringContainsString("return \$this->reject('graded_reject');", $src);
    }

    public function test_a_candidate_described_as_a_competitor_is_a_disqualifier_hit(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("competing|competitor|competitors|competes with|rival", $src);
        $this->assertSame(1, preg_match('/\\b(competing|competitor|competitors|competes with|rival)\\b/i', 'Schoolcanvas runs a partner programme and already sells a competing school ERP'));
        $this->assertSame(0, preg_match('/\\b(competing|competitor|competitors|competes with|rival)\\b/i', 'Keystone is opening a Bengaluru hub and plans to hire talent'));
    }

    public function test_surfacing_the_grade_is_flag_guarded_and_only_adds_to_the_unverified_list(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("config('signals.surface_grade', false) && \$g !== 'ready'", $src);
        $this->assertStringContainsString("\$assessment['unverified'] = array_values(array_unique(array_merge((array) (\$assessment['unverified'] ?? []), \$extra)));", $src);
    }
}
