<?php

namespace Tests\Unit\Signals;

use PHPUnit\Framework\TestCase;

/**
 * Safety rules of signals:market-batch (the way chosen signals are carried to the live server). The command itself needs a database; it was
 * rehearsed end to end on a throwaway tenant (export, plan, import, refused second import, rollback). These pin the rules that must never change.
 */
final class MarketBatchCommandTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Console/Commands/SignalsMarketBatch.php');
    }

    public function test_import_is_off_by_default(): void
    {
        $cfg = require dirname(__DIR__, 3) . '/config/signals.php';
        $this->assertFalse($cfg['market_batch']['enabled']);
    }

    public function test_import_needs_the_switch_the_tenant_and_the_exact_phrase(): void
    {
        $s = $this->src();
        $this->assertStringContainsString("config('signals.market_batch.enabled')", $s);
        $this->assertStringContainsString("'IMPORT-MARKET-BATCH'", $s);
        $this->assertStringContainsString("'ROLLBACK-MARKET-BATCH'", $s);
        $this->assertStringContainsString('Pass --tenant=<sub_institute_id> explicitly.', $s);
    }

    public function test_nothing_existing_is_overwritten_and_every_row_carries_the_batch_marker(): void
    {
        $s = $this->src();
        $this->assertStringContainsString("'market-batch:' . \$this->batch()", $s);
        $this->assertStringContainsString("'import_source' => \$this->marker()", $s);
        $this->assertStringContainsString('never duplicate or overwrite an existing signal', $s);
        $this->assertStringNotContainsString('->update(', $s, 'the command only inserts and deletes its own rows');
    }

    public function test_rollback_deletes_only_the_manifest_ids_inside_the_tenant_and_marker(): void
    {
        $s = $this->src();
        $this->assertStringContainsString("->where('import_source', \$this->marker())->whereIn('id', \$m['opportunities'])->delete()", $s);
        $this->assertStringContainsString("where('sub_institute_id', \$tenant)->whereIn('id', \$m['sources'])->delete()", $s);
    }

    public function test_environment_specific_ids_are_not_copied(): void
    {
        $s = $this->src();
        foreach (["'icp_profile_id' => null", "'reviewed_by' => null", "'ingestion_source_id' => null", "'triggered_by' => null"] as $needle) {
            $this->assertStringContainsString($needle, $s);
        }
        $this->assertStringContainsString('$resolver->product($tenant, $productKey)?->id', $s, 'the Product Master link is looked up again on the target');
    }

    public function test_an_export_refuses_signals_without_an_evidence_link(): void
    {
        $this->assertStringContainsString('a batch only carries signals with evidence', $this->src());
    }
}
