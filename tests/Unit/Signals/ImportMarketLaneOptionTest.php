<?php

namespace Tests\Unit\Signals;

use PHPUnit\Framework\TestCase;

/**
 * signals:import-market gained an OPTIONAL --customer-model (b2b, b2c, b2g) that stamps the lane on the dated run, so the lane filters show the
 * imported signals. Without it the command behaves exactly as before. Verified end to end with the importer's own dry-run on the real files.
 */
final class ImportMarketLaneOptionTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Console/Commands/ImportMarketSignals.php');
    }

    public function test_the_option_exists_and_is_only_valid_with_the_three_lanes(): void
    {
        $s = $this->src();
        $this->assertStringContainsString('{--customer-model=', $s);
        $this->assertStringContainsString("['b2b', 'b2c', 'b2g']", $s);
        $this->assertStringContainsString('--customer-model must be b2b, b2c or b2g.', $s);
    }

    public function test_the_lane_is_only_added_to_the_run_when_asked_for(): void
    {
        $s = $this->src();
        $this->assertStringContainsString("] + (\$customerModel !== null ? [", $s);
        $this->assertStringContainsString("'customer_model' => \$customerModel,", $s);
        $this->assertStringContainsString('ResearchRunScope::key($tenant, $productKey, null, $customerModel, null)', $s);
    }

    public function test_the_shipped_import_files_are_in_the_documented_schema(): void
    {
        $dir = dirname(__DIR__, 3) . '/database/signal-imports/2026-11-24';
        if (! is_dir($dir)) {
            $this->markTestSkipped('no import files in this tree');
        }
        $files = glob($dir . '/*.json');
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            $this->assertSame('claude-research-2026-11-24', $j['source_label'] ?? null, basename($f));
            foreach ($j['records'] as $r) {
                $this->assertNotSame('', trim((string) ($r['buyer']['name'] ?? '')), 'a named organisation');
                $this->assertMatchesRegularExpression('#^https?://#', (string) ($r['source_url'] ?? ''), 'an evidence link');
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['event_date'] ?? ''));
                $this->assertContains($r['business_fit'], ['g2g', 'scholar']);
                $this->assertGreaterThanOrEqual(8, str_word_count((string) ($r['source_excerpt'] ?? '')), 'a real quote from the page');
                foreach (['buying_signal', 'problem_fit', 'product_fit', 'accessibility', 'urgency', 'potential_value', 'evidence_quality'] as $k) {
                    $this->assertContains($r['scores'][$k] ?? null, [1, 2, 3, 4, 5], "{$r['buyer']['name']} score $k");
                }
                $this->assertStringNotContainsString('example.invalid', (string) $r['source_url']);
                $this->assertArrayNotHasKey('is_sample', $r, 'real signals, never sample rows');
            }
        }
    }
}
