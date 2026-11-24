<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\PipelineAttentionDetector;
use App\Domain\Intelligence\Taxonomy;

class PipelineAttentionDetectorTest extends CommercialTestCase
{
    private const LINKS = ['/gtm/forecast'];

    private function detector(): PipelineAttentionDetector
    {
        return new PipelineAttentionDetector($this->db(), $this->thresholds());
    }

    public function test_reports_incomplete_deals_per_currency_without_amounts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->addDeal(1, ['currency' => 'USD']);
            $this->addDeal(1, ['currency' => 'INR']);
        }
        $this->addDeal(1, ['amount' => null]);
        $this->addDeal(1, ['currency' => null]);
        $this->addDeal(1, ['expected_close_date' => null]);
        $this->addDeal(1, ['amount' => null, 'expected_close_date' => null]);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(10, $ev['Open deals']['value']);
        $this->assertSame(3, $ev['Missing a price (amount or currency)']['value']);
        $this->assertSame(2, $ev['Missing an expected close date']['value']);
        $this->assertSame(6, $ev['Priced and dated']['value']);
        $this->assertSame(4, $ev['Priced open deals in USD']['value'], 'USD and INR are separate lines, never combined');
        $this->assertSame(3, $ev['Priced open deals in INR']['value']);
        $this->assertSame('info', $insight->severity, '60% complete is over the provisional 50% gate');
        $this->assertSame('/gtm/forecast', $insight->deepLink);
        foreach ($ev as $line) {
            $this->assertTrue($line['kind'] !== 'metric' || is_int($line['value']), 'counts only: every metric is an integer count');
        }
    }

    public function test_attention_when_fewer_than_half_are_priced_and_dated(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->addDeal(1);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->addDeal(1, ['amount' => null]);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame('attention', $result->insights[0]->severity);
        $this->assertFalse($result->insights[0]->coverage->sufficient);
    }

    public function test_ok_but_empty_when_every_open_deal_is_complete(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addDeal(1);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_insufficient_below_the_open_deal_minimum(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->addDeal(1, ['amount' => null]);
        }

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->state);
    }

    public function test_tenant_isolation_soft_delete_and_closed_deals(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->addDeal(1);
        }
        for ($i = 0; $i < 30; $i++) {
            $this->addDeal(2, ['amount' => null]);
        }
        $this->addDeal(1, ['deleted_at' => '2026-11-01 00:00:00']);
        $this->addDeal(1, ['stage' => 'won']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS, 1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(9, $result->coverage->observed);
    }

    public function test_executive_gets_no_entity_references_and_hr_style_caller_does(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addDeal(1, ['amount' => null]);
        }

        $exec = $this->detector()->detect($this->commercialCaller('executive', self::LINKS), $this->clock());
        $admin = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertFalse($this->hasEntityEvidence($exec->insights[0]));
        $this->assertTrue($this->hasEntityEvidence($admin->insights[0]));
    }

    public function test_authorization_needs_the_forecast_or_command_center_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator', ['/gtm/deals'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', ['/gtm/forecast'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', ['/gtm/command-center'])));
    }
}
