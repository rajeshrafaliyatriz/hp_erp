<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Market\OpportunityFit;
use App\Domain\Signals\Scholar\SchoolNameFallback;
use Tests\TestCase;

/**
 * Partner acquisition (customer type b2c): firms that sell, deliver or integrate the product are the wanted result, not rivals; they are scored on
 * firm type, place and proof (they have no tender), and a name search never adds "school".
 */
class PartnerModeTest extends TestCase
{
    private function pack(string $model): IcpPack
    {
        $p = IcpPack::fromTemplate('scholar_k12');
        $cfg = $p->config();
        $cfg['customer_model'] = $model;
        $cfg['vendor_phrases'] = ['consulting', 'technologies'];
        $cfg['competitors'] = ['Rival Corp'];

        return new IcpPack($p->key, $cfg, $p->profileId);
    }

    public function test_a_consultancy_is_a_vendor_for_a_customer_run_but_a_partner_for_a_partner_run(): void
    {
        $this->assertTrue($this->pack('b2b')->looksLikeVendor('Naman HR Consulting', 'HR consulting', ''));
        $this->assertFalse($this->pack('b2c')->looksLikeVendor('Naman HR Consulting', 'HR consulting', ''));
    }

    public function test_a_named_competitor_is_still_excluded_in_partner_mode(): void
    {
        $this->assertTrue($this->pack('b2c')->looksLikeVendor('Rival Corp India', null, ''));
    }

    public function test_partner_weights_total_100_and_give_a_firm_with_place_and_proof_a_higher_score(): void
    {
        $this->assertSame(100, array_sum(OpportunityFit::PARTNER_WEIGHTS));
        $this->assertSame(100, array_sum(OpportunityFit::WEIGHTS));

        $in = [
            'part_known' => true, 'child_supported' => null, 'sub_product_run' => false,
            'buyer_type' => 'consultancy', 'icp_buyer_types' => ['consultancy'], 'trigger_type' => null, 'icp_triggers' => [],
            'region_requested' => true, 'region_status' => 'confirmed', 'location_in_icp_regions' => true,
            'page_read' => true, 'quote_verified' => true, 'source_domains' => 2,
            'intent_level' => 'unconfirmed', 'age_days' => null, 'tender_days_left' => null,
        ];
        $customer = OpportunityFit::score($in);
        $partner = OpportunityFit::score($in + ['partner_mode' => true]);

        $this->assertGreaterThan($customer['total'], $partner['total']);
        $this->assertSame('High', OpportunityFit::band($partner['total']));
        $this->assertSame(30, $partner['components']['evidence']['max']);
        $this->assertLessThanOrEqual(100, $partner['total']);
    }

    public function test_a_name_search_adds_school_only_when_asked_to(): void
    {
        $acc = ['id' => 1, 'name' => 'TVRLS', 'city' => 'Ahmedabad'];
        $this->assertSame('TVRLS Ahmedabad school', SchoolNameFallback::queries($acc)[0]['q']);
        $this->assertSame('TVRLS Ahmedabad', SchoolNameFallback::queries($acc, 60, 2, '')[0]['q']);
    }
}
