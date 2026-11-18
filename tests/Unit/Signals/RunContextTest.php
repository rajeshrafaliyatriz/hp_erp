<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Run\RunContext;
use PHPUnit\Framework\TestCase;

/** The process-wide "which research run is this" marker (StructuredAi reads it; the researcher sets and clears it). */
class RunContextTest extends TestCase
{
    protected function tearDown(): void
    {
        RunContext::clear();
        parent::tearDown();
    }

    public function test_no_run_is_active_until_one_is_set(): void
    {
        $this->assertNull(RunContext::current());
    }

    public function test_set_makes_the_run_and_its_organisation_current_and_clear_removes_both(): void
    {
        RunContext::set(41, 7);
        $this->assertSame(['run_id' => 41, 'tenant_id' => 7], RunContext::current());

        RunContext::clear();
        $this->assertNull(RunContext::current());
    }

    public function test_a_later_set_replaces_the_earlier_run_completely(): void
    {
        RunContext::set(41, 7);
        RunContext::set(42, 8);

        $this->assertSame(['run_id' => 42, 'tenant_id' => 8], RunContext::current(), 'nothing of the first run (neither its id nor its organisation) is left');
    }

    public function test_the_related_type_is_the_documented_literal(): void
    {
        // ai_usage_events rows already written, and every report built on them, key on this exact string.
        $this->assertSame('g2g_research_run', RunContext::RELATED_TYPE);
    }
}
