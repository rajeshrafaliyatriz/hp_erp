<?php

namespace App\Services\Platform;

/**
 * A written procedure, read into structure.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DETERMINISTIC, AND NO AI ANYWHERE IN IT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * LMS K-12's equivalent parses first and falls back to a model for prose it
 * cannot read, then re-validates the model's proposal through the same schema.
 * That is a sound design and this deliberately does less: it reads the shapes
 * people actually write and REPORTS what it could not read, rather than asking a
 * model to fill the gap.
 *
 * The reason is what the output is used for. These steps become approval points
 * and the tasks become real work assigned to real people. A parser that returns
 * nothing for an unreadable line leaves somebody to write that line properly; a
 * model that invents a plausible step produces a task that looks reviewed and is
 * not, and the person it lands on has no way to tell the difference.
 *
 * `issues` is therefore part of the contract, not an error channel. A procedure
 * that parses cleanly has none; one that does not names the lines it skipped so
 * they can be fixed at the source.
 *
 * ── WHAT IT READS ───────────────────────────────────────────────────────────
 *
 *   Objective:  / Purpose:              one line, the point of the procedure
 *   Trigger:  / When:                   what starts it
 *   Completion: / Done when:            how you know it finished
 *   1. Something          (Role)        a numbered step, optionally with an actor
 *   - Something           (Role)        a bulleted step
 *   [approval] in a step                marks it as needing a sign-off
 *
 * Anything else is kept as `issues` rather than guessed at.
 */
class ProcedureParser
{
    /** Longer than this and it is a document, not a procedure. */
    private const MAX_STEPS = 40;

    /**
     * @return array{
     *   name: string, objective: ?string, trigger: ?string, completion: ?string,
     *   steps: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>,
     *   issues: array<int, string>
     * }
     */
    public function parse(string $text, string $fallbackName = 'Untitled procedure'): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $objective = null;
        $trigger = null;
        $completion = null;
        $steps = [];
        $issues = [];
        $order = 1;

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            if ($this->heading($line, ['objective', 'purpose'], $value)) {
                $objective = $value;
                continue;
            }

            if ($this->heading($line, ['trigger', 'when'], $value)) {
                $trigger = $value;
                continue;
            }

            if ($this->heading($line, ['completion', 'done when', 'complete when'], $value)) {
                $completion = $value;
                continue;
            }

            $step = $this->step($line);

            if ($step !== null) {
                if (count($steps) >= self::MAX_STEPS) {
                    $issues[] = 'More than ' . self::MAX_STEPS . ' steps — the rest were not read.';
                    break;
                }

                $steps[] = $step + ['order' => $order++];
                continue;
            }

            /*
             * An unread line is REPORTED, not dropped and not guessed at.
             *
             * Truncated because a procedure pasted with a wall of prose would
             * otherwise return the whole wall back as issues, which helps nobody.
             */
            $issues[] = 'Not read as a step: "' . mb_substr($line, 0, 80) . '"';
        }

        if ($steps === []) {
            $issues[] = 'No steps were found. Number them "1." or start them with "-".';
        }

        return [
            'name' => $objective !== null ? mb_substr($objective, 0, 120) : $fallbackName,
            'objective' => $objective,
            'trigger' => $trigger,
            'completion' => $completion,
            'steps' => $steps,
            'tasks' => $this->deriveTasks($steps),
            'issues' => $issues,
        ];
    }

    /**
     * The tasks a procedure implies.
     *
     * ── NOT ONE PER STEP ────────────────────────────────────────────────────
     *
     * A step that says "the system sends a confirmation" is not somebody's task,
     * and raising it as one puts a row in a person's queue that they can neither
     * do nor meaningfully close. So a task is derived only from a step that names
     * an ACTOR — somebody has to do it — and an approval step becomes a task for
     * its approver rather than for whoever performs the work.
     *
     * `ref` is stable across re-parses of the same procedure, which is what lets a
     * second publish recognise what it has already raised.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function deriveTasks(array $steps): array
    {
        $tasks = [];

        foreach ($steps as $step) {
            if (empty($step['actor'])) {
                continue;
            }

            $tasks[] = [
                'ref' => 'step-' . $step['order'],
                'title' => mb_substr($step['text'], 0, 200),
                'actor' => $step['actor'],
                'is_approval' => (bool) $step['is_approval'],
                // A suggestion the screen can change before publishing. Approvals
                // are usually quicker than the work they gate.
                'due_in_days' => $step['is_approval'] ? 2 : 7,
            ];
        }

        return $tasks;
    }

    /**
     * `Objective: ...` and its synonyms.
     */
    private function heading(string $line, array $keywords, ?string &$value): bool
    {
        foreach ($keywords as $keyword) {
            $pattern = '/^' . preg_quote($keyword, '/') . '\s*[:\-]\s*(.+)$/i';

            if (preg_match($pattern, $line, $matches) === 1) {
                $value = trim($matches[1]);

                return $value !== '';
            }
        }

        return false;
    }

    /**
     * A numbered or bulleted step, with its actor and whether it is an approval.
     *
     * @return array<string, mixed>|null
     */
    private function step(string $line): ?array
    {
        if (preg_match('/^(?:\d+[.)]|[-*•])\s*(.+)$/u', $line, $matches) !== 1) {
            return null;
        }

        $text = trim($matches[1]);

        if ($text === '') {
            return null;
        }

        // A trailing "(Role)" names who does it. Taken from the END so a step that
        // mentions a role mid-sentence is not mistaken for one that assigns it.
        $actor = null;

        if (preg_match('/^(.*?)\s*\(([^()]{1,60})\)\s*$/u', $text, $actorMatch) === 1) {
            $text = trim($actorMatch[1]);
            $actor = trim($actorMatch[2]);
        }

        // `[approval]` anywhere in the step marks it as a sign-off point.
        $isApproval = preg_match('/\[\s*approval\s*\]/i', $text) === 1;
        $text = trim(preg_replace('/\[\s*approval\s*\]/i', '', $text) ?? $text);

        return [
            'text' => $text,
            'actor' => $actor,
            'is_approval' => $isApproval,
        ];
    }
}
