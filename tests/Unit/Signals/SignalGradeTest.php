<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\GenericName;
use App\Domain\Signals\Market\SignalGrade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Signal grade (ready / verify / lead / reject) and generic-name detection. No database, no network. */
class SignalGradeTest extends TestCase
{
    private function base(array $over = []): array
    {
        return array_merge([
            'organisation' => 'Acme Infosystems Pvt Ltd', 'kind' => 'enterprise', 'source_url' => 'https://acme.example/news',
            'source_host_policy' => 'web_page', 'evidence_level' => 'E1', 'quote_verified' => true, 'event_date' => '2026-10-15',
            'source_published_at' => '2026-10-16', 'deadline' => null, 'today' => '2026-11-21', 'recency_days' => 180, 'trigger_matched' => true,
            'region_status' => 'inside', 'disqualifier_hit' => false, 'is_opinion_or_research' => false, 'independent_domains' => 1,
        ], $over);
    }

    public function test_ready(): void
    {
        $g = SignalGrade::grade($this->base());
        $this->assertSame('ready', $g['grade']);
        $this->assertSame([], $g['blockers']);
        $this->assertSame([], $g['missing']);
    }

    public function test_verify_when_evidence_is_e0(): void
    {
        $g = SignalGrade::grade($this->base(['evidence_level' => 'E0']));
        $this->assertSame('verify', $g['grade']);
        $this->assertNotEmpty($g['missing']);
    }

    public function test_verify_when_quote_not_on_page(): void
    {
        $g = SignalGrade::grade($this->base(['quote_verified' => false]));
        $this->assertSame('verify', $g['grade']);
        $this->assertStringContainsString('quote', implode(' ', $g['missing']));
    }

    public function test_lead_when_only_lead_hint_platform_evidence(): void
    {
        $g = SignalGrade::grade($this->base(['source_host_policy' => 'lead_hint_only', 'evidence_level' => 'E0', 'quote_verified' => false]));
        $this->assertSame('lead', $g['grade']);
    }

    #[DataProvider('leadCases')]
    public function test_lead_cases(array $over): void
    {
        $this->assertSame('lead', SignalGrade::grade($this->base($over))['grade']);
    }

    public static function leadCases(): array
    {
        return [
            'region unknown' => [['region_status' => 'unknown']],
            'region unconfirmed' => [['region_status' => 'unconfirmed']],
            'undated' => [['event_date' => null, 'source_published_at' => null]],
            'stale' => [['event_date' => '2025-01-01', 'source_published_at' => null]],
            'trigger not matched' => [['trigger_matched' => false]],
        ];
    }

    #[DataProvider('rejectCases')]
    public function test_reject_blockers(array $over, string $needle): void
    {
        $g = SignalGrade::grade($this->base($over));
        $this->assertSame('reject', $g['grade']);
        $this->assertStringContainsString($needle, implode(' ', $g['blockers']));
    }

    public static function rejectCases(): array
    {
        return [
            'no organisation' => [['organisation' => null], 'named organisation'],
            'blank organisation' => [['organisation' => '  '], 'named organisation'],
            'category name' => [['organisation' => 'Indian ICT system integrators'], 'category'],
            'region outside' => [['region_status' => 'outside'], 'outside'],
            'expired deadline' => [['deadline' => '2026-11-01', 'kind' => 'government_body'], 'deadline'],
            'disqualifier' => [['disqualifier_hit' => true], 'disqualifier'],
            'opinion' => [['is_opinion_or_research' => true], 'opinion'],
        ];
    }

    public function test_open_deadline_is_not_a_blocker_and_deadline_today_is_open(): void
    {
        $this->assertSame('ready', SignalGrade::grade($this->base(['deadline' => '2026-11-21']))['grade']);
        $this->assertSame('ready', SignalGrade::grade($this->base(['deadline' => '2026-12-30']))['grade']);
    }

    public function test_social_only_evidence_never_ready(): void
    {
        $g = SignalGrade::grade($this->base(['source_host_policy' => 'lead_hint_only', 'evidence_level' => 'E1', 'quote_verified' => true]));
        $this->assertNotSame('ready', $g['grade']);
    }

    public function test_blocker_beats_good_evidence(): void
    {
        $g = SignalGrade::grade($this->base(['region_status' => 'outside', 'evidence_level' => 'E3']));
        $this->assertSame('reject', $g['grade']);
    }

    public function test_deterministic_and_reasons_non_empty(): void
    {
        $cases = [$this->base(), $this->base(['evidence_level' => 'E0']), $this->base(['region_status' => 'unknown']), $this->base(['organisation' => 'schools'])];
        foreach ($cases as $c) {
            $a = SignalGrade::grade($c);
            $this->assertSame($a, SignalGrade::grade($c));
            $this->assertNotEmpty($a['reasons']);
            $this->assertContains($a['grade'], ['ready', 'verify', 'lead', 'reject']);
        }
    }

    public function test_does_not_read_claim_fields(): void
    {
        $a = SignalGrade::grade($this->base());
        $b = SignalGrade::grade($this->base(['claim_level' => 'confirmed', 'qualification' => 'x', 'confidence' => 0.1]));
        $this->assertSame($a, $b);
    }

    #[DataProvider('genericNames')]
    public function test_generic_name_detection(string $name, bool $generic): void
    {
        $this->assertSame($generic, GenericName::isGeneric($name), $name);
    }

    public static function genericNames(): array
    {
        return [
            ['Indian ICT system integrators', true],
            ['system integrators', true],
            ['schools', true],
            ['IT companies', true],
            ['Government', true],
            ['government', true],
            ['', true],
            ['Government of India', false],
            ['Ministry of Education', false],
            ['Department of School Education, Gujarat', false],
            ['Infosys', false],
            ['TCS', false],
            ['Acme Infosystems Pvt Ltd', false],
            ['Delhi Public School', false],
        ];
    }
}
