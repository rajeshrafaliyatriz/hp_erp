<?php

namespace App\Domain\AI\Lifecycle;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the twelve stages in order and returns the trace.
 *
 * Rules, all enforced here rather than trusted to each stage:
 *  - the order is fixed and every stage appears in the trace exactly once;
 *  - a `Blocked` result (policy refusal) halts the turn - later stages are `not_reached`;
 *  - any other result, including a failed reasoning stage, lets the rest run, so the evidence
 *    already gathered is still reported when the model could not answer;
 *  - an unexpected exception inside a stage becomes a `failed` stage, never a lost turn.
 */
final class LifecyclePipeline
{
    /** @var array<int, class-string<LifecycleStage>> */
    private const STAGES = [
        Stages\ConversationStage::class,
        Stages\GenerativeAiStage::class,
        Stages\AgentStage::class,
        Stages\PlanningStage::class,
        Stages\McpToolSelectionStage::class,
        Stages\LaravelMcpStage::class,
        Stages\RealDataStage::class,
        Stages\EvidenceStage::class,
        Stages\ReasoningStage::class,
        Stages\RecommendationStage::class,
        Stages\HumanApprovalStage::class,
        Stages\ActionStage::class,
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array<int, array{key:string, label:string, status:string, summary:string, duration_ms:int, detail:array<string,mixed>}>
     */
    public function run(LifecycleContext $context): array
    {
        $trace = [];

        foreach (self::STAGES as $class) {
            /** @var LifecycleStage $stage */
            $stage = $this->container->make($class);
            $key = $stage->key();

            if ($context->halted) {
                $trace[] = $this->row($key, StageResult::skipped('Not reached.'), 0, StageStatus::NotReached);

                continue;
            }

            $started = hrtime(true);

            try {
                $result = $stage->run($context);
            } catch (Throwable $exception) {
                report($exception);
                $result = StageResult::failed('This stage failed unexpectedly.', ['error' => $exception->getMessage()]);
            }

            if ($result->status === StageStatus::Blocked) {
                $context->halted = true;
            }

            $trace[] = $this->row($key, $result, (int) ((hrtime(true) - $started) / 1_000_000));
        }

        return $trace;
    }

    /** @return array<string, mixed> */
    private function row(StageKey $key, StageResult $result, int $ms, ?StageStatus $status = null): array
    {
        return [
            'key' => $key->value,
            'label' => $key->label(),
            'status' => ($status ?? $result->status)->value,
            'summary' => $result->summary,
            'duration_ms' => $ms,
            'detail' => $result->detail,
        ];
    }
}
