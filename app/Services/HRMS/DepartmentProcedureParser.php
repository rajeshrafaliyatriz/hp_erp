<?php

namespace App\Services\HRMS;

use Illuminate\Support\Facades\DB;

/**
 * A department's written SOP procedure, read into structure - the K12-parity
 * sibling of App\Services\Platform\ProcedureParser, kept as its own class
 * rather than an edit to that one: Platform Services' module-wide Add
 * Process screen keeps using the simpler parser untouched, so this class
 * carries all the new behaviour with zero risk of regressing that screen.
 *
 * Same discipline as the parser it's modeled on: deterministic, no AI in
 * this class at all (App\Services\HRMS\DepartmentProcedureAiNormalizer is a
 * SEPARATE, optional, explicitly-invoked fallback - see that class's
 * docblock for why the two are kept apart), and a line this cannot read is
 * REPORTED in `issues`, never guessed at.
 *
 * ── WHAT IT READS, BEYOND THE SIMPLE PARSER ─────────────────────────────────
 *
 *   Preconditions: / Inputs: / Outputs: / Hands over to:   one item per line
 *   (or comma-separated inline) until the next heading or step line. A
 *   precondition may end with a parenthetical rule citation, e.g.
 *   "the receipt book series is configured (BR-02)".
 *
 *   A step may still be the simple form ("1. Do the thing (Role) [approval]"),
 *   or the richer 5-column form:
 *
 *     1. Fees officer | Enters the amount | Validates against the balance | BR-03: is the amount within the outstanding balance? | Amount accepted, or refused
 *
 *   (Actor | User action | System action | Decision/validation | Result -
 *   any cell may be "-" or blank to mean "none"). `BR-xx` anywhere in a
 *   step's text cites a department rule; an unresolved code is reported in
 *   `issues` exactly like an unrecognised workflow key always has been.
 *
 *   An actor of literally "AI" marks the step as unattended; "X + AI" marks
 *   it as a person-reviews-automation step. Both feed deriveTasks()'s four
 *   task categories - see that method's docblock for the exact rule.
 */
class DepartmentProcedureParser
{
    /** Longer than this and it is a document, not a procedure - same ceiling as the simpler parser. */
    private const MAX_STEPS = 40;

    private const LIST_HEADINGS = [
        'preconditions' => ['preconditions', 'precondition'],
        'inputs'        => ['inputs', 'input'],
        'outputs'       => ['outputs', 'output'],
        'handoffs'      => ['hands over to', 'handover', 'handovers', 'hand off to'],
    ];

    /**
     * @return array{
     *   name: string, objective: ?string, trigger: ?string, completion: ?string,
     *   preconditions: array<int,array{text:string,business_rules:string[]}>,
     *   inputs: string[], outputs: string[], handoffs: string[],
     *   steps: array<int,array<string,mixed>>,
     *   business_rules: array<string,array<string,mixed>>,
     *   tasks: array<int,array<string,mixed>>,
     *   issues: string[]
     * }
     */
    public function parse(
        string $text,
        int $departmentId,
        string $departmentName,
        ?string $categoryLabel,
        string $fallbackName = 'Untitled process',
    ): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $objective = null;
        $trigger = null;
        $completion = null;
        /** @var array<string, array<int,string>> */
        $lists = ['preconditions' => [], 'inputs' => [], 'outputs' => [], 'handoffs' => []];
        $activeList = null;
        $steps = [];
        $issues = [];
        $order = 1;
        $citedCodes = [];

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            if ($this->heading($line, ['objective', 'purpose'], $value)) {
                $objective = $value;
                $activeList = null;
                continue;
            }
            if ($this->heading($line, ['trigger', 'when'], $value)) {
                $trigger = $value;
                $activeList = null;
                continue;
            }
            if ($this->heading($line, ['completion', 'done when', 'complete when'], $value)) {
                $completion = $value;
                $activeList = null;
                continue;
            }

            $matchedListKey = null;
            foreach (self::LIST_HEADINGS as $key => $keywords) {
                if ($this->heading($line, $keywords, $value)) {
                    $matchedListKey = $key;
                    if ($value !== '') {
                        foreach (explode(',', $value) as $item) {
                            $item = trim($item);
                            if ($item !== '') {
                                $lists[$key][] = $item;
                            }
                        }
                    }
                    break;
                }
            }
            if ($matchedListKey !== null) {
                $activeList = $matchedListKey;
                continue;
            }

            $step = $this->step($line);

            if ($step !== null) {
                $activeList = null;

                if (count($steps) >= self::MAX_STEPS) {
                    $issues[] = 'More than ' . self::MAX_STEPS . ' steps — the rest were not read.';
                    break;
                }

                foreach ($step['business_rules'] as $code) {
                    $citedCodes[$code] = true;
                }

                $steps[] = $step + ['order' => $order++];
                continue;
            }

            if ($activeList !== null) {
                $lists[$activeList][] = $this->stripBulletMarker($line);
                continue;
            }

            $issues[] = 'Not read as a step: "' . mb_substr($line, 0, 80) . '"';
        }

        if ($steps === []) {
            $issues[] = 'No steps were found. Number them "1." or start them with "-".';
        }

        // Preconditions carry an optional "(BR-xx)" citation, extracted the
        // same way a step's actor is - taken from the end so a precondition
        // that merely mentions a code mid-sentence isn't mistaken for a cite.
        $preconditions = array_map(function (string $item) use (&$citedCodes) {
            $codes = [];
            if (preg_match('/^(.*?)\s*\(([^()]{1,60})\)\s*$/u', $item, $m) === 1 && $this->looksLikeRuleCodes($m[2])) {
                $item = trim($m[1]);
                $codes = $this->extractRuleCodes($m[2]);
                foreach ($codes as $code) {
                    $citedCodes[$code] = true;
                }
            }
            return ['text' => $item, 'business_rules' => $codes];
        }, $lists['preconditions']);

        [$businessRules, $unresolved] = $this->resolveRules(array_keys($citedCodes), $departmentId);
        foreach ($unresolved as $code) {
            $issues[] = 'Cites an unknown rule code "' . $code . '" - not found in this department\'s Rules.';
        }

        $tasks = $this->deriveTasks($preconditions, $steps, $lists['handoffs'], $departmentName, $categoryLabel);

        return [
            'name' => $objective !== null ? mb_substr($objective, 0, 120) : $fallbackName,
            'objective' => $objective,
            'trigger' => $trigger,
            'completion' => $completion,
            'preconditions' => $preconditions,
            'inputs' => $lists['inputs'],
            'outputs' => $lists['outputs'],
            'handoffs' => $lists['handoffs'],
            'steps' => $steps,
            'business_rules' => $businessRules,
            'tasks' => $tasks,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * The tasks a procedure implies, sorted into the four buckets a person
     * actually works from:
     *
     *   readiness      one per precondition - master data/config the
     *                  procedure depends on before it can run at all.
     *   human_gate     one per step whose actor is literally "AI" - the
     *                  engine ran it unattended, so a named person confirms
     *                  the output before anything downstream relies on it.
     *   workflow_step  one per step whose actor is a person (alone, or
     *                  "Person + AI" reviewing an automated draft) -
     *                  directly assignable work inside the procedure.
     *   handover       one per "Hands over to" entry - so nothing this
     *                  procedure produces is left unactioned downstream.
     *
     * Priority/due-in-days are fixed per bucket (not computed): a precondition
     * that cites a rule is High/2 days, otherwise Medium/2 days; a human gate
     * is always High/1 day; a workflow step is always Medium/3 days; a
     * handover is always Low/5 days.
     *
     * @param array<int,array{text:string,business_rules:string[]}> $preconditions
     * @param array<int,array<string,mixed>> $steps
     * @param string[] $handoffs
     * @return array<int,array<string,mixed>>
     */
    private function deriveTasks(array $preconditions, array $steps, array $handoffs, string $departmentName, ?string $categoryLabel): array
    {
        $kra = $categoryLabel ? "{$departmentName} - {$categoryLabel}" : $departmentName;
        $tasks = [];

        foreach ($preconditions as $index => $precondition) {
            $ref = 'precondition-' . ($index + 1);
            $tasks[] = [
                'ref' => $ref,
                'category' => 'readiness',
                'title' => 'Confirm ' . lcfirst($precondition['text']),
                'actor' => null,
                'business_rules' => $precondition['business_rules'],
                'priority' => $precondition['business_rules'] !== [] ? 'High' : 'Medium',
                'due_in_days' => 2,
                'kra' => $kra,
                'kpa' => 'Process readiness',
                'observed' => $precondition['text'] . ' - verified before the next attempt window opens.',
            ];
        }

        foreach ($steps as $step) {
            $actorType = $step['actor_type'];
            $order = $step['order'];
            $actionText = $step['user_action'] ?: $step['system_action'];

            if ($actorType === 'ai') {
                $tasks[] = [
                    'ref' => 'gate-' . $order,
                    'category' => 'human_gate',
                    'title' => 'Verify the output of step ' . $order . ': ' . lcfirst((string) $step['system_action']),
                    'actor' => $step['actor'],
                    'business_rules' => $step['business_rules'],
                    'priority' => 'High',
                    'due_in_days' => 1,
                    'kra' => $kra,
                    'kpa' => 'AI output verified and logged',
                    'observed' => $step['decision'] ?: $step['result'] ?: $step['system_action'],
                ];
                continue;
            }

            // 'person' or 'person_ai'
            $tasks[] = [
                'ref' => 'step-' . $order,
                'category' => 'workflow_step',
                'title' => 'Carry out ' . lcfirst((string) $actionText),
                'actor' => $step['actor'],
                'business_rules' => $step['business_rules'],
                'priority' => 'Medium',
                'due_in_days' => 3,
                'kra' => $kra,
                'kpa' => $step['result'] ?: ('Step ' . $order . ' completed'),
                'observed' => $step['decision'] ?: $step['result'] ?: '',
            ];
        }

        foreach ($handoffs as $index => $target) {
            $tasks[] = [
                'ref' => 'handover-' . ($index + 1),
                'category' => 'handover',
                'title' => 'Action the handover to ' . $target,
                'actor' => null,
                'business_rules' => [],
                'priority' => 'Low',
                'due_in_days' => 5,
                'kra' => $kra,
                'kpa' => 'Handover to ' . $target . ' completed',
                'observed' => 'Nothing this procedure produces is left unactioned at the end of the cycle.',
            ];
        }

        return $tasks;
    }

    /**
     * Resolve every cited BR-xx code against this department's own Rules.
     *
     * @param string[] $codes
     * @return array{0: array<string,array<string,mixed>>, 1: string[]} [resolved-by-code, unresolved-codes]
     */
    private function resolveRules(array $codes, int $departmentId): array
    {
        if ($codes === []) {
            return [[], []];
        }

        $rows = DB::table('department_rules')
            ->where('department_id', $departmentId)
            ->whereIn('code', $codes)
            ->whereNull('deleted_at')
            ->get(['id', 'code', 'title', 'rule_definition', 'validation', 'applies_at', 'on_failure']);

        $resolved = [];
        foreach ($rows as $row) {
            $resolved[$row->code] = [
                'code' => $row->code,
                'rule_id' => (int) $row->id,
                'title' => $row->title,
                'rule_definition' => $row->rule_definition,
                'validation' => $row->validation,
                'applies_at' => $row->applies_at,
                'on_failure' => $row->on_failure,
            ];
        }

        $unresolved = array_values(array_diff($codes, array_keys($resolved)));

        return [$resolved, $unresolved];
    }

    private function heading(string $line, array $keywords, ?string &$value): bool
    {
        foreach ($keywords as $keyword) {
            $pattern = '/^' . preg_quote($keyword, '/') . '\s*[:\-]\s*(.*)$/i';

            if (preg_match($pattern, $line, $matches) === 1) {
                $value = trim($matches[1]);

                return true;
            }
        }

        return false;
    }

    private function stripBulletMarker(string $line): string
    {
        return preg_replace('/^[-*•]\s*/u', '', $line) ?? $line;
    }

    private function looksLikeRuleCodes(string $text): bool
    {
        return preg_match('/^BR-\d{1,4}(\s*,\s*BR-\d{1,4})*$/i', trim($text)) === 1;
    }

    /** @return string[] */
    private function extractRuleCodes(string $text): array
    {
        preg_match_all('/BR-\d{1,4}/i', $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /**
     * One step line, simple or rich form.
     *
     * Steps must be NUMBERED ("1." / "2)") - unlike the simpler ProcedureParser,
     * a bare bullet ("-"/"*"/"•") is never a step here, because this parser
     * also reads bulleted Preconditions/Inputs/Outputs/Hands-over-to lists;
     * letting a bullet mean either would make "- Do the thing" ambiguous
     * between a step and a list item depending on what heading came before it.
     *
     * @return array<string, mixed>|null
     */
    private function step(string $line): ?array
    {
        if (preg_match('/^\d+[.)]\s*(.+)$/u', $line, $matches) !== 1) {
            return null;
        }

        $body = trim($matches[1]);

        if ($body === '') {
            return null;
        }

        $isApproval = false;
        $workflowKey = null;

        if (preg_match('/\[\s*approval\s*(?::\s*([a-z0-9_.]{1,100}))?\s*\]/i', $body, $approvalMatch) === 1) {
            $isApproval = true;
            $taggedKey = isset($approvalMatch[1]) && $approvalMatch[1] !== '' ? trim($approvalMatch[1]) : null;
            if ($taggedKey !== null && array_key_exists($taggedKey, config('platform_services.workflows', []))) {
                $workflowKey = $taggedKey;
            }
            $body = trim(preg_replace('/\[\s*approval\s*(?::\s*[a-z0-9_.]{1,100})?\s*\]/i', '', $body) ?? $body);
        }

        // Explicit [BR-xx] tags, independent of the pipe-form's own BR scan below.
        $businessRules = [];
        if (preg_match_all('/\[\s*(BR-\d{1,4}(?:\s*,\s*BR-\d{1,4})*)\s*\]/i', $body, $tagMatches) > 0) {
            foreach ($tagMatches[1] as $group) {
                foreach ($this->extractRuleCodes($group) as $code) {
                    $businessRules[] = $code;
                }
            }
            $body = trim(preg_replace('/\[\s*BR-\d{1,4}(?:\s*,\s*BR-\d{1,4})*\s*\]/i', '', $body) ?? $body);
        }

        $cells = array_map('trim', explode('|', $body));

        if (count($cells) >= 5) {
            [$actorRaw, $userAction, $systemAction, $decision, $result] = array_slice($cells, 0, 5);

            foreach ($this->extractRuleCodes($decision . ' ' . $result) as $code) {
                $businessRules[] = $code;
            }

            return [
                'actor' => $actorRaw !== '' && $actorRaw !== '-' ? $actorRaw : null,
                'actor_type' => $this->actorType($actorRaw),
                'user_action' => $this->normalizeCell($userAction),
                'system_action' => $this->normalizeCell($systemAction) ?? '',
                'decision' => $this->normalizeCell($decision),
                'result' => $this->normalizeCell($result),
                'is_approval' => $isApproval,
                'workflow_key' => $workflowKey,
                'business_rules' => array_values(array_unique($businessRules)),
            ];
        }

        // Simple form: trailing "(Role)" names the actor, same extraction the
        // original ProcedureParser has always used.
        $actor = null;
        $text = $body;
        if (preg_match('/^(.*?)\s*\(([^()]{1,60})\)\s*$/u', $text, $actorMatch) === 1) {
            $text = trim($actorMatch[1]);
            $actor = trim($actorMatch[2]);
        }

        return [
            'actor' => $actor,
            'actor_type' => $this->actorType($actor ?? ''),
            'user_action' => null,
            'system_action' => $text,
            'decision' => null,
            'result' => null,
            'is_approval' => $isApproval,
            'workflow_key' => $workflowKey,
            'business_rules' => array_values(array_unique($businessRules)),
        ];
    }

    private function normalizeCell(string $cell): ?string
    {
        $cell = trim($cell);

        return ($cell === '' || $cell === '-') ? null : $cell;
    }

    private function actorType(string $actor): string
    {
        $normalized = trim($actor);

        if (strcasecmp($normalized, 'ai') === 0) {
            return 'ai';
        }

        if (preg_match('/\+\s*ai\b/i', $normalized) === 1 || preg_match('/\bai\s*\+/i', $normalized) === 1) {
            return 'person_ai';
        }

        return 'person';
    }
}
