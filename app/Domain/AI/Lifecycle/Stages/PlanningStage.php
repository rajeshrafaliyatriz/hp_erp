<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Lifecycle\Text;

/**
 * Stage 4 - what is the user asking for?
 *
 * Deterministic on purpose: the intent decides whether a write form opens, so it must not
 * depend on a model's mood. Order matters and is the safety property:
 *
 *   1. An ACTION is proposed only when the sentence contains one of the phrases of an action
 *      the page itself offered (the client sends only what the user's rights-filtered sidebar
 *      resolved). Nothing else can reach an action.
 *   2. A REPORT or TEMPLATE request needs the noun plus a request verb.
 *   3. Everything else is a DATA question.
 */
final class PlanningStage implements LifecycleStage
{
    private const REQUEST_VERBS = ['generate', 'create', 'make', 'prepare', 'build', 'produce', 'run', 'export', 'draft', 'give me', 'i need', 'i want', 'can i get'];

    public function key(): StageKey
    {
        return StageKey::Planning;
    }

    public function run(LifecycleContext $context): StageResult
    {
        // 1. A write the page offers.
        $best = null;

        foreach ($context->availableActions as $action) {
            foreach (($action['phrases'] ?? []) as $phrase) {
                $length = mb_strlen(Text::normalise((string) $phrase));

                if (Text::containsPhrase($context->message, (string) $phrase) && ($best === null || $length > $best['length'])) {
                    $best = ['action' => $action, 'length' => $length];
                }
            }
        }

        if ($best !== null) {
            $context->intent = 'action';
            $context->matchedAction = [
                'key' => (string) $best['action']['key'],
                'label' => (string) ($best['action']['label'] ?? $best['action']['key']),
                'description' => (string) ($best['action']['description'] ?? ''),
            ];

            return StageResult::ran('This asks for the action "' . $context->matchedAction['label'] . '".', ['intent' => 'action']);
        }

        $asks = $this->asksFor($context->message);

        if ($asks && str_contains(Text::normalise($context->message), 'report')) {
            $context->intent = 'report';

            return StageResult::ran('This asks for a report.', ['intent' => 'report']);
        }

        if (str_contains(Text::normalise($context->message), 'template')) {
            $context->intent = 'template';

            return StageResult::ran('This is about templates.', ['intent' => 'template']);
        }

        return StageResult::ran('This is a question about the module\'s data.', ['intent' => 'data']);
    }

    private function asksFor(string $message): bool
    {
        foreach (self::REQUEST_VERBS as $verb) {
            if (Text::containsPhrase($message, $verb)) {
                return true;
            }
        }

        return false;
    }
}
