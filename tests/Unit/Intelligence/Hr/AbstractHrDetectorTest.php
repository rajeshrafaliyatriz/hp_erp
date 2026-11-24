<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Detectors\Hr\AbstractHrDetector;
use App\Domain\Intelligence\Detectors\Hr\LeaveAttentionDetector;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

/** L5: a failing detector is logged by NAME ONLY and reports a generic failure; nothing about the failure reaches the result. */
class AbstractHrDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private const LEAK = ['SQLSTATE', 'select ', 'tbluser', 'secret_column', 'no such table', 'Stack trace', '.php'];

    private function assertNothingLeaks(string $text): void
    {
        foreach (self::LEAK as $needle) {
            $this->assertStringNotContainsString($needle, $text, "the text must not contain '{$needle}'");
        }
    }

    public function test_a_throwing_detector_returns_a_generic_error_and_logs_only_ids_and_class_names(): void
    {
        Log::spy();
        $detector = new class($this->db(), Thresholds::defaults()) extends AbstractHrDetector {
            public function id(): string
            {
                return 'hr.probe';
            }

            public function title(): string
            {
                return 'Probe';
            }

            public function surfaces(): array
            {
                return [];
            }

            public function authorizes(IntelligenceCaller $caller): bool
            {
                return true;
            }

            protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
            {
                throw new \RuntimeException('SQLSTATE[HY000]: select secret_column from tbluser where id = 5 at /var/www/app/Foo.php:12 Stack trace');
            }
        };

        $r = $detector->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_ERROR, $r->state);
        $this->assertSame('This check could not be completed right now.', $r->reason);
        $this->assertSame([], $r->insights);
        $this->assertNothingLeaks((string) json_encode($r->summary()));

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context = []) {
            $this->assertSame('intelligence.detector_failed', $message);
            $this->assertSame(['detector', 'exception'], array_keys($context), 'only the detector id and the exception class');
            $this->assertSame('hr.probe', $context['detector']);
            $this->assertSame(\RuntimeException::class, $context['exception']);
            $this->assertNothingLeaks((string) json_encode($context));

            return true;
        });
    }

    public function test_a_real_query_failure_is_generic_and_leaks_no_sql_table_or_column_text(): void
    {
        Log::spy();
        $this->people(3);
        Schema::drop('tbluser');                       // the detector's tables exist, so it gets as far as a real query that then fails
        $detector = new LeaveAttentionDetector($this->db(), Thresholds::defaults());

        $r = $detector->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_ERROR, $r->state);
        $this->assertNothingLeaks((string) json_encode($r->summary()));
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context = []) {
            $this->assertSame('hr.leave_attention', $context['detector']);
            $this->assertSame(['detector', 'exception'], array_keys($context));
            $this->assertStringNotContainsString('tbluser', json_encode($context));
            $this->assertStringNotContainsString('no such table', json_encode($context));

            return true;
        });
    }
}
