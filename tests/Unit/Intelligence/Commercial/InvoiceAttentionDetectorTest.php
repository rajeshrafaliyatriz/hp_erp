<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\InvoiceAttentionDetector;
use App\Domain\Intelligence\Taxonomy;

class InvoiceAttentionDetectorTest extends CommercialTestCase
{
    private const LINKS = ['/module/crm/sales/invoices'];

    private function detector(): InvoiceAttentionDetector
    {
        return new InvoiceAttentionDetector($this->db(), $this->thresholds());
    }

    /** Ten invoices with a due date in the future (so the minimum is met and none is overdue). */
    private function filler(int $tenant, int $n = 10): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addInvoice($tenant);
        }
    }

    public function test_overdue_rule_mirrors_the_sweep_and_balances_are_per_currency(): void
    {
        $this->filler(1, 3);
        $this->addInvoice(1, ['due_date' => '2026-11-10', 'total' => 100, 'amount_paid' => 40, 'currency' => 'USD']);        // overdue, 60 outstanding, < 30 days
        $this->addInvoice(1, ['due_date' => '2026-09-01', 'total' => 200, 'amount_paid' => 0, 'currency' => 'USD']);         // overdue > 30 days
        $this->addInvoice(1, ['due_date' => '2026-10-01', 'total' => 5000, 'amount_paid' => 0, 'currency' => 'INR']);       // another currency
        $this->addInvoice(1, ['due_date' => '2026-10-01', 'total' => 70, 'amount_paid' => 0, 'currency' => null]);           // no currency
        $this->addInvoice(1, ['due_date' => '2026-10-01', 'total' => 100, 'amount_paid' => 100]);                            // fully paid: excluded
        $this->addInvoice(1, ['due_date' => '2026-10-01', 'total' => 0]);                                                    // zero total: excluded
        $this->addInvoice(1, ['due_date' => '2026-10-01', 'total' => 100, 'amount_paid' => 99.999]);                         // inside the 0.004 tolerance: excluded
        $this->addInvoice(1, ['due_date' => null, 'total' => 100]);                                                          // no due date: excluded

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('high', $insight->severity);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(4, $ev['Overdue and not fully paid']['value']);
        $this->assertSame(3, $ev['Overdue by more than 30 days']['value']);
        $this->assertSame(260.0, (float) $ev['Overdue balance in USD']['value']);
        $this->assertSame(2, $ev['Overdue invoices in USD']['value']);
        $this->assertSame(5000.0, (float) $ev['Overdue balance in INR']['value']);
        $this->assertSame(70.0, (float) $ev['Overdue balance in (no currency)']['value']);
        $this->assertFalse(isset($ev['Overdue balance']), 'there is no combined total across currencies');
        $this->assertSame('/module/crm/sales/invoices', $insight->deepLink);
        $this->assertTrue($this->hasEntityEvidence($insight));
    }

    public function test_attention_when_nothing_is_older_than_the_ageing_threshold(): void
    {
        $this->filler(1);
        $this->addInvoice(1, ['due_date' => '2026-11-15']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame('attention', $result->insights[0]->severity);
    }

    public function test_ok_but_empty_when_nothing_is_overdue(): void
    {
        $this->filler(1);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_insufficient_below_the_invoice_minimum(): void
    {
        $this->filler(1, 4);
        $this->addInvoice(1, ['due_date' => '2026-10-01']);

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $this->detector()->detect($this->commercialCaller('administrator', self::LINKS), $this->clock())->state);
    }

    public function test_other_tenants_and_soft_deleted_invoices_are_never_counted(): void
    {
        $this->filler(1);
        for ($i = 0; $i < 20; $i++) {
            $this->addInvoice(2, ['due_date' => '2026-01-01']);
        }
        $this->addInvoice(1, ['due_date' => '2026-01-01', 'deleted_at' => '2026-11-01 00:00:00']);

        $result = $this->detector()->detect($this->commercialCaller('administrator', self::LINKS, 1), $this->clock());

        $this->assertSame([], $result->insights);
        $this->assertSame(10, $result->coverage->observed);
    }

    public function test_executive_gets_no_entity_references(): void
    {
        $this->filler(1);
        $this->addInvoice(1, ['due_date' => '2026-10-01']);

        $result = $this->detector()->detect($this->commercialCaller('executive', self::LINKS), $this->clock());

        $this->assertCount(1, $result->insights);
        $this->assertFalse($this->hasEntityEvidence($result->insights[0]));
    }

    public function test_authorization_needs_the_invoices_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('administrator', ['/module/crm/sales/opportunities'])));
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('employee', self::LINKS)));
    }
}
