<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\QualificationGate;
use Tests\TestCase;

/** The gate accepts an organisation's own product value only for the run that registered it, and every other gate rule still applies. */
class OwnProductFitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QualificationGate::forgetOwnProductFits();
    }

    protected function tearDown(): void
    {
        QualificationGate::forgetOwnProductFits();
        parent::tearDown();
    }

    private function record(array $over = []): array
    {
        return array_replace_recursive([
            'buyer' => ['name' => 'Example State Department of School Education', 'type' => 'government', 'state' => 'Exampleland'],
            'trigger_type' => 'tender', 'trigger_summary' => 'Tender for a payroll platform',
            'source_url' => 'https://example.invalid/t/1', 'event_date' => '2026-10-01',
            'claim_level' => 'confirmed', 'fetch_level' => 'full_document', 'business_fit' => 'payroll_cloud',
        ], $over);
    }

    public function test_an_own_product_value_is_refused_until_the_running_product_registers_it(): void
    {
        $v = (new QualificationGate())->evaluate($this->record());
        $this->assertFalse($v['ok']);
        $this->assertSame('invalid_business_fit', $v['code']);

        QualificationGate::allowOwnProductFit('payroll_cloud');
        $this->assertTrue((new QualificationGate())->evaluate($this->record())['ok']);
    }

    public function test_only_the_registered_value_is_accepted_and_malformed_ones_cannot_be_registered(): void
    {
        QualificationGate::allowOwnProductFit('payroll_cloud');
        $this->assertSame('invalid_business_fit', (new QualificationGate())->evaluate($this->record(['business_fit' => 'other_product']))['code']);

        foreach (['', 'AB', 'has space', '9abc', "x'; drop", str_repeat('a', 21), null] as $bad) {
            QualificationGate::allowOwnProductFit($bad);
        }
        QualificationGate::forgetOwnProductFits();
        $this->assertFalse((new QualificationGate())->evaluate($this->record(['business_fit' => 'has space']))['ok']);
    }

    public function test_a_record_cannot_register_its_own_allowed_value(): void
    {
        $r = $this->record(['ownProductFits' => ['payroll_cloud'], 'allowed_business_fits' => ['payroll_cloud']]);
        $this->assertFalse((new QualificationGate())->evaluate($r)['ok']);
    }

    public function test_built_in_values_and_every_other_rule_are_unchanged(): void
    {
        foreach (['eb', 'scholar', 'g2g', 'multiple'] as $fit) {
            $this->assertTrue((new QualificationGate())->evaluate($this->record(['business_fit' => $fit]))['ok'], $fit);
        }
        QualificationGate::allowOwnProductFit('payroll_cloud');
        $noSource = $this->record();
        unset($noSource['source_url']);
        $this->assertSame('missing_source_url', (new QualificationGate())->evaluate($noSource)['code'], 'a registered product does not skip the evidence rules');
        $this->assertSame(['eb', 'scholar', 'g2g', 'multiple'], QualificationGate::BUSINESS_FIT);
    }

    public function test_forgetting_clears_it(): void
    {
        QualificationGate::allowOwnProductFit('payroll_cloud');
        QualificationGate::forgetOwnProductFits();
        $this->assertFalse((new QualificationGate())->evaluate($this->record())['ok']);
    }
}
