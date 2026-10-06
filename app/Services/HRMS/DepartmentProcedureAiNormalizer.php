<?php

namespace App\Services\HRMS;

use App\Exceptions\DeepSeekBudgetException;
use App\Exceptions\DeepSeekTruncatedException;
use App\Services\DeepSeekService;
use Illuminate\Support\Facades\Log;

/**
 * The fallback for a procedure DepartmentProcedureParser could not read.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TRANSCRIBE, DO NOT AUTHOR - AND NEVER TRUSTED DIRECTLY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This class never returns a ProcessSpec. It asks the model to re-express the
 * SAME source text in the parser's own plain-text syntax (numbered steps,
 * "Preconditions:"/"Inputs:" headings, "Actor | User action | System action |
 * Decision | Result" pipes), then hands that text straight back to
 * DepartmentProcedureParser::parse() - the exact same deterministic checks a
 * human's paste goes through. A hallucinated step, an invented BR code, or an
 * actor that doesn't appear in the source fails the same validation either
 * way; nothing the model writes is trusted as structure on its own.
 *
 * Calls App\Services\DeepSeekService directly, same as
 * App\Services\Competency\EsoGenerator - NOT App\Domain\AI\Support\
 * AiModelClient's DeepSeek path. That path is wired correctly (confirmed:
 * reads DEEPSEEK_API_KEY/DEEPSEEK_MODEL, sends response_format: json_object)
 * but carries none of DeepSeekService's measured blank-completion handling -
 * no retry, no perturbed resend, no "drop JSON mode on the last attempt"
 * fallback, and no finish_reason=length check at all. DeepSeek is documented
 * (config/deepseek.php) to return a blank 200 in JSON mode on a real,
 * non-trivial fraction of calls; going through AiModelClient's DeepSeek path
 * would mean this feature inherits that failure mode with no mitigation.
 * DeepSeekService is the version of "call DeepSeek" that has already paid
 * for the fix.
 */
class DepartmentProcedureAiNormalizer
{
    private const FIRST_BUDGET = 2000;

    public function __construct(private readonly DeepSeekService $ai)
    {
    }

    public function isConfigured(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * @return array{ok:true, text:string}|array{ok:false, reason:string, detail:?string}
     */
    public function normalize(string $sourceText): array
    {
        if (!$this->ai->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured', 'detail' => null];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($sourceText)],
        ];

        $attempts = [self::FIRST_BUDGET, self::FIRST_BUDGET * 2];

        foreach ($attempts as $index => $budget) {
            try {
                $result = $this->ai->chatJson($messages, [
                    'json' => true,
                    'temperature' => 0.1,
                    'max_tokens' => $budget,
                ]);

                return ['ok' => true, 'text' => $this->toIntakeText($result)];
            } catch (DeepSeekBudgetException $e) {
                return ['ok' => false, 'reason' => 'insufficient_balance', 'detail' => $e->getMessage()];
            } catch (DeepSeekTruncatedException $e) {
                if ($index === array_key_last($attempts)) {
                    return ['ok' => false, 'reason' => 'truncated', 'detail' => $e->getMessage()];
                }
                continue;
            } catch (\Throwable $e) {
                Log::warning('Department procedure AI normalization failed', [
                    'type' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                return ['ok' => false, 'reason' => 'ai_error', 'detail' => null];
            }
        }

        // Unreachable - the loop either returns inside the try or on the last
        // truncation. Kept so a future edit cannot fall through silently.
        return ['ok' => false, 'reason' => 'ai_error', 'detail' => null];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are transcribing a written procedure into a fixed plain-text format. You
are not authoring one - do not invent steps, actors, rule codes,
preconditions, inputs, outputs or handovers that are not present in the
source text. If the source does not mention something, leave that field out
rather than guessing.

Respond with a single valid JSON object only - no prose, no markdown fences -
shaped exactly as:

{
  "objective": string|null,
  "trigger": string|null,
  "completion": string|null,
  "preconditions": string[],
  "inputs": string[],
  "outputs": string[],
  "handoffs": string[],
  "steps": [
    {
      "actor": string,
      "user_action": string|null,
      "system_action": string,
      "decision": string|null,
      "result": string|null,
      "is_approval": boolean,
      "business_rules": string[]
    }
  ]
}

"actor" is the literal "AI" for a step the system performs unattended, the
literal "<Role> + AI" for a step where a person reviews something the system
drafted, or a plain role/title for a step a person performs alone. A rule
code only belongs in "business_rules" if the source text itself names it
(e.g. "BR-04"); never invent one.
PROMPT;
    }

    private function userPrompt(string $sourceText): string
    {
        return "Transcribe this procedure:\n\n" . $sourceText;
    }

    /**
     * Build the SAME plain-text intake syntax DepartmentProcedureParser reads
     * from a human paste, from the model's structured (but untrusted) answer -
     * allow-listing every field read out of $result, the same discipline
     * EsoGenerator uses before anything from a model reaches a line of text a
     * person will act on.
     *
     * @param array<string, mixed> $result
     */
    private function toIntakeText(array $result): string
    {
        $lines = [];

        foreach (['objective' => 'Objective', 'trigger' => 'Trigger', 'completion' => 'Completion'] as $key => $label) {
            $value = $result[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lines[] = $label . ': ' . trim($value);
            }
        }

        foreach (['preconditions' => 'Preconditions', 'inputs' => 'Inputs', 'outputs' => 'Outputs', 'handoffs' => 'Hands over to'] as $key => $label) {
            $items = $this->stringList($result[$key] ?? null);
            if ($items !== []) {
                $lines[] = $label . ':';
                foreach ($items as $item) {
                    $lines[] = '- ' . $item;
                }
            }
        }

        $order = 1;
        foreach ((array) ($result['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }

            $actor = is_string($step['actor'] ?? null) ? trim($step['actor']) : '';
            $userAction = is_string($step['user_action'] ?? null) ? trim($step['user_action']) : '-';
            $systemAction = is_string($step['system_action'] ?? null) ? trim($step['system_action']) : '';
            $decision = is_string($step['decision'] ?? null) ? trim($step['decision']) : '-';
            $result_ = is_string($step['result'] ?? null) ? trim($step['result']) : '-';

            if ($actor === '' || $systemAction === '') {
                continue;
            }

            $tag = !empty($step['is_approval']) ? ' [approval]' : '';
            $rules = $this->stringList($step['business_rules'] ?? null);
            $ruleTag = $rules !== [] ? ' [' . implode(', ', $rules) . ']' : '';

            $lines[] = sprintf(
                '%d. %s | %s | %s | %s | %s%s%s',
                $order++,
                $actor,
                $userAction ?: '-',
                $systemAction,
                $decision ?: '-',
                $result_ ?: '-',
                $tag,
                $ruleTag,
            );
        }

        return implode("\n", $lines);
    }

    /** @return string[] */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : '',
            $value,
        ), static fn ($item) => $item !== ''));
    }
}
