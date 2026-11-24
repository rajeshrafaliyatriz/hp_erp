<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\QualificationRule as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualificationRuleTest extends TestCase
{
    private const TENDERS = ['tender', 'rfp', 'eoi'];

    private function a(string $grade, string $level, int $fit): array
    {
        return ['signal_grade' => ['grade' => $grade], 'evidence_maturity' => ['level' => $level], 'fit' => ['total' => $fit]];
    }

    #[DataProvider('cases')]
    public function test_label(string $claim, string $trigger, ?array $assessment, string $expected): void
    {
        $this->assertSame($expected, Q::label($claim, $trigger, $assessment, self::TENDERS, 45));
    }

    public static function cases(): array
    {
        $a = fn (string $g, string $l, int $f) => ['signal_grade' => ['grade' => $g], 'evidence_maturity' => ['level' => $l], 'fit' => ['total' => $f]];

        return [
            'ready, corroborated, good fit => qualified' => ['hypothesis', 'expansion', $a('ready', 'E2', 60), 'New Opportunity'],
            'ready, primary source, good fit, tender' => ['hypothesis', 'tender', $a('ready', 'E3', 60), 'Relevant Requirement Found'],
            'ready but only a page read (E1) never qualifies' => ['confirmed', 'expansion', $a('ready', 'E1', 90), 'Needs Verification'],
            'ready, E3, fit below the minimum' => ['confirmed', 'expansion', $a('ready', 'E3', 30), 'Needs Verification'],
            'verify grade' => ['confirmed', 'expansion', $a('verify', 'E0', 80), 'Needs Verification'],
            'lead grade, even with a confirmed claim' => ['confirmed', 'expansion', $a('lead', 'E3', 90), 'Monitoring'],
            'reject grade' => ['confirmed', 'expansion', $a('reject', 'E3', 90), 'Monitoring'],
            'no grade: the old claim rule, confirmed' => ['confirmed', 'expansion', null, 'New Opportunity'],
            'no grade: the old claim rule, inference' => ['inference', 'expansion', ['evidence_maturity' => ['level' => 'E2']], 'Needs Verification'],
            'no grade: the old claim rule, hypothesis' => ['hypothesis', 'expansion', [], 'Monitoring'],
        ];
    }

    public function test_evidence_strength_alone_no_longer_decides_the_label(): void
    {
        // The same claim level gives different labels depending on the rest of the picture: qualification is no longer a renamed confidence.
        $this->assertNotSame(
            Q::label('confirmed', 'expansion', $this->a('ready', 'E3', 80), self::TENDERS),
            Q::label('confirmed', 'expansion', $this->a('lead', 'E3', 80), self::TENDERS),
        );
    }

    public function test_the_old_rule_is_available_unchanged(): void
    {
        $this->assertSame('New Opportunity', Q::byClaim('confirmed', 'expansion', self::TENDERS));
        $this->assertSame('Relevant Requirement Found', Q::byClaim('confirmed', 'tender', self::TENDERS));
        $this->assertSame('Needs Verification', Q::byClaim('inference', 'expansion', self::TENDERS));
        $this->assertSame('Monitoring', Q::byClaim('hypothesis', 'expansion', self::TENDERS));
    }

    public function test_only_the_four_known_labels_are_ever_returned(): void
    {
        foreach (['ready', 'verify', 'lead', 'reject', 'unknown'] as $g) {
            foreach (['E0', 'E1', 'E2', 'E3', 'not_assessed'] as $l) {
                $this->assertContains(Q::label('hypothesis', 'tender', $this->a($g, $l, 70), self::TENDERS), ['New Opportunity', 'Relevant Requirement Found', 'Needs Verification', 'Monitoring']);
            }
        }
    }
}
