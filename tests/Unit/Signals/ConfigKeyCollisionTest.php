<?php

namespace Tests\Unit\Signals;

use PHPUnit\Framework\TestCase;

/**
 * The existing QualificationGate reads signals.qualification.min_fit (default 25) and min_fit_for_hot (default 60) from config. A key we add under
 * `qualification` would silently change the gate: this happened once (a min_fit of 45 tightened it) and was caught only by a feature test.
 */
final class ConfigKeyCollisionTest extends TestCase
{
    public function test_the_shipped_signals_config_does_not_define_the_gates_qualification_keys(): void
    {
        $cfg = require dirname(__DIR__, 3) . '/config/signals.php';
        $this->assertArrayNotHasKey('qualification', $cfg, 'signals.qualification.* belongs to the QualificationGate defaults');
        $this->assertArrayHasKey('qualification_rule', $cfg);
    }

    public function test_the_config_file_has_no_duplicate_top_level_keys(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/config/signals.php');
        preg_match_all("/^    '([a-z_]+)' => /m", $src, $m);
        $dups = array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));
        $this->assertSame([], $dups, 'a duplicate key silently overrides the first');
    }

    public function test_every_config_path_the_gate_reads_has_its_default_untouched(): void
    {
        $cfg = require dirname(__DIR__, 3) . '/config/signals.php';
        $this->assertNull($cfg['qualification']['min_fit'] ?? null);
        $this->assertNull($cfg['qualification']['min_fit_for_hot'] ?? null);
    }
}
