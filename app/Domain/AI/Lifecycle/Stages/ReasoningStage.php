<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Conversation\OrganisationContext;
use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Workspace\AiStackTabContext;
use Throwable;

/**
 * Stage 9 - answer from the evidence.
 *
 * The model is handed the module briefing PLUS the evidence gathered for this question and is
 * told to cite it. Actions and reports are not model work: their intent skips this stage and the
 * reply is built from facts (see LifecycleAskService). A failed model call is a failed stage, not
 * a lost turn - the evidence already gathered is still returned.
 */
final class ReasoningStage implements LifecycleStage
{
    private const CAPABILITY = 'conversational_ai';

    private const EVIDENCE_CHARS = 3500;

    public function __construct(
        private readonly AiModelClient $models,
        private readonly OrganisationContext $organisation,
        private readonly ModuleGrounding $grounding,
        private readonly AiStackTabContext $stackTabs,
    ) {
    }

    public function key(): StageKey
    {
        return StageKey::Reasoning;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if (in_array($context->intent, ['action', 'report'], true)) {
            return StageResult::skipped('The reply is built from the ' . $context->intent . ' itself, not written by the model.');
        }

        $institute = $context->scope->selectedInstituteId;

        if ($context->module !== null) {
            $briefing = $this->grounding->briefing(
                $context->scope,
                (string) $context->module->module_key,
                $context->page['module_key'] ?? null
            );
            $system = $this->organisation->moduleSystemPrompt(
                (string) $context->module->label,
                $briefing,
                $context->organisation,
                $context->page,
                $context->screen
            );
        } else {
            $briefing = $this->organisation->briefing($institute);
            $system = $this->organisation->systemPrompt($briefing, $context->organisation);

            // The page the user has open, when it belongs to no module.
            if ($context->page !== null) {
                $system .= "\n\nThe user has the \"{$context->page['title']}\" page open (" . implode(' > ', $context->page['breadcrumb']) . ').'
                    . ($context->screen !== null ? "\n\n" . $context->screen : '');
            }
        }

        // On an AI Stack tab, ground the answer in what that tab shows right now.
        if ($context->module !== null && $context->aiStackTab !== null) {
            $tabFacts = $this->stackTabs->facts($context->scope, (string) $context->module->module_key, (string) $context->module->label, $context->aiStackTab);
            $system .= $tabFacts === null ? '' : "\n\n" . $tabFacts;
        }

        $context->briefing = $briefing;
        $system .= $this->evidenceBlock($context);

        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $context->history,
            [['role' => 'user', 'content' => $context->message]]
        );

        try {
            $completion = $this->models->complete(
                self::CAPABILITY,
                $messages,
                ['temperature' => 0.2],
                $institute,
                $context->moduleKey
            );
        } catch (AiNotConfiguredException $exception) {
            $context->error = $exception->getMessage();
            $context->configured = false;

            return StageResult::failed($exception->getMessage());
        } catch (Throwable $exception) {
            $context->error = $exception->getMessage();

            return StageResult::failed('The model could not answer.', ['error' => $exception->getMessage()]);
        }

        $context->answer = $completion->text !== ''
            ? $completion->text
            : 'The model returned an empty answer. Try rephrasing the question.';
        $context->usage = $completion->toArray();
        $context->truncated = $completion->wasTruncated();

        return StageResult::ran(
            sprintf('Answered with %s%s.', $completion->provider, $completion->model ? '/' . $completion->model : ''),
            ['provider' => $completion->provider, 'model' => $completion->model, 'latency_ms' => $completion->latencyMs]
        );
    }

    /** The evidence, in the prompt, with the citation rule. Empty when there is none. */
    private function evidenceBlock(LifecycleContext $context): string
    {
        if ($context->evidence === []) {
            return '';
        }

        $lines = [];

        foreach ($context->evidence as $item) {
            $head = sprintf('[%s] %s (%s): %d row%s%s.', $item['id'], $item['label'], $item['source'], $item['total'], $item['total'] === 1 ? '' : 's', $item['truncated'] ? '+' : '');

            if ($item['personal']) {
                $lines[] = $head . ' One row per person, so rows are not shared; columns: ' . implode(', ', $item['columns']) . '.';
            } elseif ($item['rows'] !== []) {
                $lines[] = $head . ' First rows: ' . json_encode($item['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } else {
                $lines[] = $head;
            }
        }

        return "\n\nEVIDENCE READ FOR THIS QUESTION (cite it as [E1], [E2] after any fact you take from it; "
            . "if the evidence does not contain the answer, say so):\n"
            . mb_strimwidth(implode("\n", $lines), 0, self::EVIDENCE_CHARS, '...');
    }
}
