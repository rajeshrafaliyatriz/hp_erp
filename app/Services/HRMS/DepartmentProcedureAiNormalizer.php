<?php

namespace App\Services\HRMS;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiProviderHttpException;
use App\Domain\AI\Support\AiQuotaExceededException;
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
 * Calls App\Domain\AI\Support\AiModelClient - "the one place this application
 * calls a model" - under the module key 'department_process_normalizer'
 * (registered in AiModuleRegistry), the same client Document Understanding's
 * DocumentClassificationService already calls. With no saved per-module
 * credential, AiConfigurationResolver falls through to the shared
 * GEMINI_API_KEY env credential every unregistered/unconfigured module uses -
 * the same "free Gemini" setup Document Understanding runs on today, not a
 * second one. Called synchronously from a controller, the same way
 * AskController/AskPipeline already call this client inline (Document
 * Understanding's own call is queue-only for unrelated reasons - OCR payload
 * size - not because the client requires a queue).
 */
class DepartmentProcedureAiNormalizer
{
    private const MODULE_KEY = 'department_process_normalizer';
    private const FIRST_BUDGET = 2000;

    public function __construct(private readonly AiModelClient $client)
    {
    }

    /**
     * @return array{ok:true, text:string}|array{ok:false, reason:string, detail:?string}
     */
    public function normalize(string $sourceText, int|string|null $institute): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($sourceText)],
        ];

        $attempts = [self::FIRST_BUDGET, self::FIRST_BUDGET * 2];

        foreach ($attempts as $index => $budget) {
            try {
                $completion = $this->client->complete(
                    self::MODULE_KEY,
                    $messages,
                    ['max_tokens' => $budget, 'temperature' => 0.1, 'json' => true],
                    $institute,
                );
            } catch (AiNotConfiguredException $e) {
                return ['ok' => false, 'reason' => 'not_configured', 'detail' => $e->getMessage()];
            } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
                return ['ok' => false, 'reason' => 'insufficient_balance', 'detail' => $e->getMessage()];
            } catch (AiProviderHttpException $e) {
                return ['ok' => false, 'reason' => 'ai_error', 'detail' => $e->getMessage()];
            } catch (\Throwable $e) {
                Log::warning('Department procedure AI normalization failed', [
                    'type' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                return ['ok' => false, 'reason' => 'ai_error', 'detail' => null];
            }

            if ($completion->wasTruncated()) {
                if ($index === array_key_last($attempts)) {
                    return ['ok' => false, 'reason' => 'truncated', 'detail' => null];
                }
                continue; // retry once with double the room
            }

            $decoded = $this->parseJson($completion->text);
            if ($decoded === null) {
                return ['ok' => false, 'reason' => 'ai_error', 'detail' => 'The model returned something that could not be read as JSON.'];
            }

            return ['ok' => true, 'text' => $this->toIntakeText($decoded)];
        }

        // Unreachable - the loop either returns inside the try or on the last
        // truncation. Kept so a future edit cannot fall through silently.
        return ['ok' => false, 'reason' => 'ai_error', 'detail' => null];
    }

    /**
     * AiModelClient only asks the provider for JSON output mime type - it
     * does not parse/validate the text itself, same as
     * DocumentClassificationService::parseJson() right next to it.
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $clean) ?? $clean;

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
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
