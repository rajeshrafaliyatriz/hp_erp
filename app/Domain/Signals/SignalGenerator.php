<?php

namespace App\Domain\Signals;

use App\Domain\AI\Support\AiCompletion;
use App\Domain\AI\Support\AiModelClient;

/**
 * Turns collected aggregates into validated signals.
 *
 * GROUNDING IS ENFORCED, NOT REQUESTED. The prompt asks the model to cite evidence,
 * but a prompt is a wish. `validate()` checks every cited metric against the data
 * that was actually sent: a signal survives only if at least one of its evidence
 * items names a real metric for a real department with the exact value supplied.
 * An invented statistic therefore cannot reach the database.
 */
class SignalGenerator
{
    public function __construct(private readonly AiModelClient $client)
    {
    }

    /**
     * @param  array<string, mixed>  $data     Output of SignalDataCollector::collect().
     * @param  array<int, array<string, string>>  $research  Optional external sources.
     * @return array{signals: array<int, array<string, mixed>>, rejected: int, completion: AiCompletion}
     */
    public function generate(int $tenantId, array $data, array $research = []): array
    {
        $completion = $this->callWithRetry($tenantId, $data, $research);

        [$signals, $rejected] = $this->validate($this->decode($completion->text), $data, $research);

        return ['signals' => $signals, 'rejected' => $rejected, 'completion' => $completion];
    }

    private function callWithRetry(int $tenantId, array $data, array $research): AiCompletion
    {
        $attempts = max(1, (int) config('signals.ai_attempts', 2));
        $messages = $this->messages($data, $research);
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return $this->client->complete(
                    (string) config('signals.ai_module'),
                    $messages,
                    [
                        'json' => true,
                        'temperature' => (float) config('signals.temperature'),
                        'max_tokens' => (int) config('signals.max_output_tokens'),
                    ],
                    $tenantId,
                );
            } catch (\App\Domain\AI\Support\AiNotConfiguredException|\App\Domain\AI\Support\AiQuotaExceededException $e) {
                throw $e; // retrying cannot fix configuration or a quota
            } catch (\Throwable $e) {
                $last = $e;
                if ($i < $attempts) {
                    usleep(1_500_000);
                }
            }
        }

        throw $last;
    }

    /** @return array<int, array{role: string, content: string}> */
    private function messages(array $data, array $research): array
    {
        $max = (int) config('signals.max_signals_per_run', 8);
        $types = implode(', ', array_keys((array) config('signals.types')));

        $system = <<<TXT
You are an organisational analyst inside an HR platform. You receive AGGREGATE data about one organisation's departments and must produce a small number of useful, actionable signals for HR and department leaders.

STRICT RULES
- Use ONLY the data in the user message. Never invent statistics, people, events or external facts.
- Every signal MUST cite evidence: items of the form {"department_id": <id or null for organisation-wide>, "metric": "<metric name from the data>", "value": <exact number from the data>}. Values must match the data exactly.
- If the data does not support a useful signal, return {"signals": []}. Fewer, well-grounded signals are better than many weak ones.
- Recommendations are advisory only. Never say an action has been or will be taken automatically.
- Return at most {$max} signals, most important first. Plain, concise English.
- Reply with a single JSON object and nothing else:
{"signals":[{"title":"<max 120 chars>","summary":"<1-2 sentences>","explanation":"<detail>","signal_type":"<one of: {$types}>","why_it_matters":"<text>","recommended_action":"<text>","priority":"High|Medium|Low","department_id":<id or null>,"evidence":[{"department_id":<id or null>,"metric":"<name>","value":<number>}],"source_ids":[<ids from EXTERNAL_SOURCES, only if used>]}]}
TXT;

        $payload = ['organisation' => $data['organisation'], 'departments' => $data['departments']];
        if ($research !== []) {
            $payload['EXTERNAL_SOURCES'] = array_map(
                fn ($s) => ['id' => $s['id'], 'title' => $s['title'], 'snippet' => $s['snippet']],
                $research
            );
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /** @return array<int, mixed> */
    private function decode(string $text): array
    {
        $text = trim($text);
        // Some providers wrap JSON in a markdown fence even when told not to.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('AI response was not valid JSON.');
        }

        $signals = $decoded['signals'] ?? null;
        if (! is_array($signals)) {
            throw new \RuntimeException('AI response did not contain a signals list.');
        }

        return array_values($signals);
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function validate(array $raw, array $data, array $research): array
    {
        $metrics = $this->metricIndex($data);
        $sourcesById = [];
        foreach ($research as $source) {
            $sourcesById[$source['id']] = $source;
        }

        $types = array_keys((array) config('signals.types'));
        $max = (int) config('signals.max_signals_per_run', 8);
        $valid = [];
        $rejected = 0;

        foreach ($raw as $item) {
            if (count($valid) >= $max) {
                $rejected++;

                continue;
            }

            $signal = is_array($item) ? $this->validateOne($item, $metrics, $sourcesById, $types) : null;
            $signal === null ? $rejected++ : $valid[] = $signal;
        }

        return [$valid, $rejected];
    }

    /** @return array<string, array<string, int>> "dept:<id>" | "org" => metric => value */
    private function metricIndex(array $data): array
    {
        $index = ['org' => array_map('intval', $data['organisation'])];
        foreach ($data['departments'] as $row) {
            $id = (int) $row['department_id'];
            $index["dept:{$id}"] = collect($row)->except(['department_id', 'name'])->map(fn ($v) => (int) $v)->all();
        }

        return $index;
    }

    private function validateOne(array $item, array $metrics, array $sourcesById, array $types): ?array
    {
        $text = fn (string $k, int $max) => is_string($item[$k] ?? null) ? mb_substr(trim($item[$k]), 0, $max) : '';

        $title = $text('title', 191);
        $summary = $text('summary', 500);
        $explanation = $text('explanation', 4000);
        $why = $text('why_it_matters', 2000);
        $action = $text('recommended_action', 2000);
        $type = strtolower($text('signal_type', 40));
        $priority = ucfirst(strtolower($text('priority', 10)));

        if ($title === '' || $summary === '' || $explanation === '' || $why === '' || $action === '') {
            return null;
        }
        if (! in_array($type, $types, true) || ! in_array($priority, Signal::PRIORITIES, true)) {
            return null;
        }

        $departmentId = $item['department_id'] ?? null;
        if ($departmentId !== null) {
            if (! is_numeric($departmentId) || ! isset($metrics['dept:' . (int) $departmentId])) {
                return null;
            }
            $departmentId = (int) $departmentId;
        }

        $evidence = [];
        foreach ((array) ($item['evidence'] ?? []) as $e) {
            if (! is_array($e) || ! isset($e['metric']) || ! is_numeric($e['value'] ?? null)) {
                continue;
            }
            $scope = isset($e['department_id']) && $e['department_id'] !== null
                ? 'dept:' . (int) $e['department_id']
                : 'org';
            $known = $metrics[$scope][(string) $e['metric']] ?? null;
            if ($known !== null && $known === (int) $e['value'] && (float) $e['value'] == (int) $e['value']) {
                $evidence[] = [
                    'department_id' => $scope === 'org' ? null : (int) $e['department_id'],
                    'metric' => (string) $e['metric'],
                    'value' => $known,
                ];
            }
        }

        // No verifiable evidence = not grounded = not stored.
        if ($evidence === []) {
            return null;
        }

        $sources = [];
        foreach ((array) ($item['source_ids'] ?? []) as $id) {
            if (is_string($id) && isset($sourcesById[$id])) {
                $s = $sourcesById[$id];
                $sources[] = ['title' => $s['title'], 'url' => $s['url'], 'retrieved_at' => $s['retrieved_at']];
            }
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'explanation' => $explanation,
            'signal_type' => $type,
            'why_it_matters' => $why,
            'recommended_action' => $action,
            'priority' => $priority,
            'department_id' => $departmentId,
            'evidence' => $evidence,
            'sources' => $sources,
            'origin' => $sources === [] ? 'internal' : 'mixed',
        ];
    }
}
