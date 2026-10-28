<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Products\ProductIcpService;
use App\Domain\Signals\Products\ResearchContextReport;
use Tests\TestCase;

/**
 * The ICP's customer type (B2B, B2C or both): validated, kept by the editor, said in the research prompt and the pre-run notice.
 * Qualification is untouched. No network, no database.
 */
class CustomerModelTest extends TestCase
{
    private function cfg(array $extra = []): array
    {
        return $extra + config('signals_icp.scholar_k12');
    }

    public function test_the_validator_accepts_b2b_b2c_and_both_and_refuses_anything_else(): void
    {
        $v = new IcpProfileValidator();
        foreach (['b2b', 'b2c', 'both'] as $m) {
            $this->assertSame([], array_filter($v->validate($this->cfg(['customer_model' => $m])), fn ($e) => str_starts_with($e, 'customer_model')), $m);
        }
        $this->assertSame([], array_filter($v->validate($this->cfg()), fn ($e) => str_starts_with($e, 'customer_model')), 'unset stays valid: existing ICPs are unchanged');
        $this->assertNotEmpty(array_filter($v->validate($this->cfg(['customer_model' => 'consumer'])), fn ($e) => str_starts_with($e, 'customer_model')));
    }

    public function test_the_editor_manages_the_setting(): void
    {
        $this->assertContains('customer_model', ProductIcpService::MANAGED);
    }

    public function test_the_prompt_says_organisations_only_for_b2b_and_both_and_nothing_when_unset(): void
    {
        $b2b = (new IcpPack('scholar_k12', $this->cfg(['customer_model' => 'b2b'])))->promptBlock();
        $both = (new IcpPack('scholar_k12', $this->cfg(['customer_model' => 'both'])))->promptBlock();
        $unset = (new IcpPack('scholar_k12', $this->cfg()))->promptBlock();
        $this->assertStringContainsString('B2B: return only organisations', $b2b);
        $this->assertStringContainsString('Signals about individual consumers are not wanted', $both);
        $this->assertStringNotContainsString('CUSTOMER TYPE', $unset);
    }

    public function test_b2c_makes_the_product_not_ready_with_the_reason_and_both_adds_a_notice(): void
    {
        $src = file_get_contents(base_path('app/Domain/Signals/Products/ProductCatalog.php'));
        $this->assertStringContainsString("customer_model'] ?? null) === 'b2c'", $src);
        $this->assertStringContainsString('B2C is not supported yet', $src);

        $product = ['key' => 'scholarclone', 'label' => 'ScholarClone', 'offers' => [], 'icp' => ['source' => 'template', 'name' => 'x', 'profile_id' => null]];
        $w = ResearchContextReport::build($product, ['industry_label' => 'schools', 'customer_model' => 'both'])['warnings'];
        $this->assertNotEmpty(array_filter($w, fn ($t) => str_contains($t, 'individual consumers are not generated')));
        $w = ResearchContextReport::build($product, ['industry_label' => 'schools', 'customer_model' => 'b2b'])['warnings'];
        $this->assertEmpty(array_filter($w, fn ($t) => str_contains($t, 'individual consumers')));
    }

    public function test_the_ideal_customer_text_may_be_long_but_not_empty(): void
    {
        $v = new IcpProfileValidator();
        $long = str_repeat('Schools and trusts that run several campuses. ', 40);   // ~1,800 characters
        $this->assertSame([], array_filter($v->validate($this->cfg(['industry_label' => $long])), fn ($e) => str_starts_with($e, 'industry_label')));
        $this->assertNotEmpty(array_filter($v->validate($this->cfg(['industry_label' => ''])), fn ($e) => str_starts_with($e, 'industry_label')));
    }

    public function test_customer_types_take_plain_words_and_the_regions_message_says_what_to_write(): void
    {
        $v = new IcpProfileValidator();
        $mine = fn (array $extra) => array_values(array_filter($v->validate($this->cfg($extra)), fn ($e) => str_starts_with($e, 'buyer_types') || str_starts_with($e, 'regions')));

        $this->assertSame([], $mine(['buyer_types' => ["Private K\u{2013}12 schools", 'Private school groups and education trusts'], 'regions' => ['Gujarat', 'Maharashtra']]));
        $this->assertSame([], $mine(['buyer_types' => ['school', 'school_group']]), 'older slug values stay valid');
        $e = $mine(['regions' => [str_repeat('Gujarat and Maharashtra initially; ', 4)]]);
        $this->assertCount(1, $e);
        $this->assertStringContainsString('one place per line', $e[0]);
        $this->assertNotEmpty($mine(['buyer_types' => [str_repeat('x', 81)]]), 'still bounded');
    }

    public function test_roomier_limits_for_guidance_parts_and_customer_types(): void
    {
        $v = new IcpProfileValidator();
        $mine = fn (array $extra) => array_values(array_filter($v->validate($this->cfg($extra)), fn ($e) => preg_match('/^(guidance|parts|buyer_types)/', $e) === 1));
        $this->assertSame([], $mine(['guidance' => str_repeat('a', 1500), 'parts' => ['p' => str_repeat('b', 500)], 'buyer_types' => array_map(fn ($i) => "type {$i}", range(1, 20))]));
        $this->assertNotEmpty($mine(['guidance' => str_repeat('a', 1501)]));
        $this->assertNotEmpty($mine(['parts' => ['p' => str_repeat('b', 501)]]));
        $this->assertNotEmpty($mine(['buyer_types' => array_map(fn ($i) => "type {$i}", range(1, 21))]));
    }

    public function test_a_short_product_key_finds_the_server_key(): void
    {
        $src = file_get_contents(base_path('app/Domain/Signals/Products/ProductCatalog.php'));
        $this->assertStringContainsString('normaliseExact(', $src);
        $this->assertNull(\App\Domain\Signals\Products\ProductCatalog::normalise('p3'), 'no such product here: still unknown, not guessed');
        $this->assertSame('scholarclone', \App\Domain\Signals\Products\ProductCatalog::normalise('ScholarClone'));
    }
}
