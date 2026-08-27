<?php

namespace Tests\Unit;

use App\Services\Competency\TaskExecutionClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The risk ceiling and the executability score.
 *
 * ── WHY THESE TWO AND NOT SOMETHING BIGGER ──────────────────────────────────
 *
 * This is the first behavioural test in the repository, so it had to be one
 * that runs. The configured harness is in-memory sqlite (`phpunit.xml`), and
 * the ESO endpoints cannot run there: `roles()` uses MySQL boolean aggregates
 * (`SUM(e.id IS NOT NULL)`) and the migration is raw `CREATE TABLE … ENGINE=InnoDB`
 * with an `information_schema` probe. Building that fixture would be a project
 * of its own.
 *
 * `clamp()` and `score()` need none of it. They are pure, public, take plain
 * arguments — and they are where the safety story actually lives. Every other
 * guarantee in this feature is downstream of them: if `clamp()` stops capping,
 * a Regulated task can be proposed for unattended AI execution and the only
 * thing standing in the way is a sentence in a prompt.
 *
 *   vendor/bin/phpunit tests/Unit/TaskExecutionClassifierTest.php
 */
class TaskExecutionClassifierTest extends TestCase
{
    private TaskExecutionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        // No container, no database, no HTTP. The constructor takes a
        // DeepSeekService it never touches on these paths.
        $this->classifier = new TaskExecutionClassifier(
            new \App\Services\DeepSeekService()
        );
    }

    /**
     * THE ONE THAT MATTERS. A Regulated task must never reach unattended
     * execution, whatever it scored and whatever the model proposed.
     */
    public function test_a_regulated_task_can_never_be_autonomous(): void
    {
        $this->assertSame('ai_human_review', $this->classifier->clamp('ai_autonomous', 'Regulated'));
        $this->assertSame('ai_human_review', $this->classifier->clamp('ai_supervised', 'Regulated'));
    }

    public function test_high_risk_stops_short_of_autonomous(): void
    {
        $this->assertSame('ai_supervised', $this->classifier->clamp('ai_autonomous', 'High'));
        // But it may stay where it already is.
        $this->assertSame('ai_supervised', $this->classifier->clamp('ai_supervised', 'High'));
    }

    public function test_low_and_medium_risk_allow_autonomy(): void
    {
        $this->assertSame('ai_autonomous', $this->classifier->clamp('ai_autonomous', 'Low'));
        $this->assertSame('ai_autonomous', $this->classifier->clamp('ai_autonomous', 'Medium'));
    }

    public function test_the_ceiling_never_promotes(): void
    {
        // A cap is an upper bound. A conservative answer must survive it.
        foreach (TaskExecutionClassifier::RISK_CLASSES as $risk) {
            $this->assertSame('human_only', $this->classifier->clamp('human_only', $risk),
                "human_only was promoted under $risk risk");
        }
    }

    /**
     * Deterministic software is not on the AI ladder, so the AI ceiling does
     * not apply to it. A scheduled backup job is not more dangerous because the
     * data it touches is regulated — the control is elsewhere.
     */
    public function test_system_automated_is_outside_the_ai_ladder(): void
    {
        foreach (TaskExecutionClassifier::RISK_CLASSES as $risk) {
            $this->assertSame('system_automated', $this->classifier->clamp('system_automated', $risk));
        }
    }

    /** Judgement and consequence must REDUCE the score, not raise it. */
    public function test_judgment_and_consequence_are_inverse(): void
    {
        $base = ['digital_input' => 100, 'rule_clarity' => 100,
                 'judgment_required' => 0, 'error_consequence' => 0];

        $this->assertSame(100, $this->classifier->score($base));

        $needsJudgment = $this->classifier->score(['judgment_required' => 100] + $base);
        $costlyIfWrong = $this->classifier->score(['error_consequence' => 100] + $base);

        $this->assertLessThan(100, $needsJudgment, 'high judgement did not reduce the score');
        $this->assertLessThan(100, $costlyIfWrong, 'high consequence did not reduce the score');
    }

    public function test_score_spans_the_full_range(): void
    {
        $this->assertSame(0, $this->classifier->score([
            'digital_input' => 0, 'rule_clarity' => 0,
            'judgment_required' => 100, 'error_consequence' => 100,
        ]));

        $this->assertSame(50, $this->classifier->score([
            'digital_input' => 50, 'rule_clarity' => 50,
            'judgment_required' => 50, 'error_consequence' => 50,
        ]));
    }

    /** A missing dimension must not be read as a perfect one. */
    public function test_missing_dimensions_do_not_inflate_the_score(): void
    {
        // Absent digital_input and rule_clarity count as 0; the two inverse
        // dimensions are absent too, so they count as 0 and invert to 100.
        $partial = $this->classifier->score(['judgment_required' => 0, 'error_consequence' => 0]);
        $full    = $this->classifier->score([
            'digital_input' => 100, 'rule_clarity' => 100,
            'judgment_required' => 0, 'error_consequence' => 0,
        ]);

        $this->assertLessThan($full, $partial,
            'a record with missing dimensions scored as highly as a complete one');
    }

    /** The policy table and the mode vocabulary must not drift apart. */
    public function test_every_risk_class_has_a_ceiling_and_every_ceiling_is_a_real_mode(): void
    {
        foreach (TaskExecutionClassifier::RISK_CLASSES as $risk) {
            $this->assertArrayHasKey($risk, TaskExecutionClassifier::RISK_CEILING,
                "risk class $risk has no declared ceiling");
        }

        foreach (TaskExecutionClassifier::RISK_CEILING as $risk => $ceiling) {
            $this->assertArrayHasKey($ceiling, TaskExecutionClassifier::MODES,
                "the ceiling for $risk is not a real execution mode");
        }
    }

    /** The four weights are the whole score, so they have to sum to 1. */
    public function test_the_dimension_weights_sum_to_one(): void
    {
        $this->assertEqualsWithDelta(
            1.0, array_sum(TaskExecutionClassifier::WEIGHTS), 0.0001,
            'the weights no longer sum to 1, so the score is not a percentage'
        );
    }
}
