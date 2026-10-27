<?php

namespace Tests\Unit;

use App\Domain\Signals\Products\ResearchContextReport as R;
use PHPUnit\Framework\TestCase;

/** What a run for one product is built from, as a report. Pure: no database. */
class ResearchContextReportTest extends TestCase
{
    private function product(array $offer = []): array
    {
        return ['key' => 'g2g', 'label' => 'G2G', 'custom' => false, 'icp' => ['source' => 'database', 'profile_id' => 7, 'name' => 'G2G ICP'],
            'offers' => [$offer + ['what_it_does' => 'HR intelligence', 'modules_components' => 'Competency, LMS', 'needs_solved' => ['Slow hiring'],
                'primary_segments' => ['PSU', 'My own segment'], 'trigger_signals' => ['Funding and procurement', 'My own event'], 'proof_points' => 'Used by a state department']]];
    }

    private function icp(array $over = []): array
    {
        return $over + ['industry_label' => 'public-sector bodies', 'buyer_types' => ['psu'], 'regions' => ['Gujarat'], 'parts' => ['core' => 'x'],
            'triggers' => ['t' => ['source' => 'tender']], 'queries' => [['id' => 'q']], 'must_have' => [], 'disqualifiers' => [['label' => 'x', 'phrases' => ['y']]], 'guidance' => 'g'];
    }

    public function test_a_complete_product_and_icp_reports_every_field_as_saved_with_its_purpose(): void
    {
        $r = R::build($this->product(), $this->icp(), 3);
        $this->assertSame([], $r['warnings']);
        foreach ($r['product_fields'] as $f) {
            $this->assertTrue($f['saved'], $f['field']);
            $this->assertNotSame('', $f['used_for']);
        }
        $this->assertSame(3, $r['icp']['version']);
        $this->assertTrue($r['icp']['saved']);
        $this->assertSame(['Gujarat'], $r['icp']['regions']);
        $this->assertSame('g2g', $r['product']['key']);
    }

    public function test_missing_fields_are_reported_as_missing_and_never_filled_in(): void
    {
        $r = R::build(['key' => 'g2g', 'label' => 'G2G', 'offers' => [['what_it_does' => null, 'modules_components' => null]], 'icp' => null], $this->icp(['regions' => [], 'queries' => []]));
        $byField = array_column($r['product_fields'], 'saved', 'field');
        $this->assertFalse($byField['description']);
        $this->assertFalse($byField['features']);
        $icp = array_column($r['icp_fields'], 'saved', 'field');
        $this->assertFalse($icp['regions']);
        $this->assertFalse($icp['queries']);
        $text = implode(' ', $r['warnings']);
        $this->assertStringContainsString('search queries', $text);
        $this->assertStringContainsString('geography', $text);
        $this->assertStringContainsString('no description or features', $text);
    }

    public function test_no_active_icp_blocks_and_never_borrows_another_products(): void
    {
        $r = R::build($this->product(), null);
        $this->assertNull($r['icp']);
        $this->assertStringContainsString('Nothing from another product is used instead', implode(' ', $r['warnings']));
    }

    public function test_changing_a_saved_value_changes_the_report(): void
    {
        $a = R::build($this->product(), $this->icp());
        $b = R::build($this->product(), $this->icp(['regions' => ['Maharashtra']]));
        $this->assertNotSame($a['icp']['regions'], $b['icp']['regions']);
        $none = R::build($this->product(['what_it_does' => '']), $this->icp());
        $this->assertNotSame($a['product_fields'], $none['product_fields']);
    }

    public function test_a_product_with_no_offers_says_so(): void
    {
        $r = R::build(['key' => 'x', 'label' => 'X', 'offers' => [], 'icp' => null], $this->icp());
        $this->assertSame(0, $r['offer_count']);
        $this->assertStringContainsString('No product details are saved', implode(' ', $r['warnings']));
    }
}
