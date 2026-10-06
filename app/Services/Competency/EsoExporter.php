<?php

namespace App\Services\Competency;

use App\Http\Controllers\Api\Competency\EsoController;
use Illuminate\Support\Facades\DB;

/**
 * Turning an ESO row into something that can leave the screen.
 *
 * ── TWO AUDIENCES, TWO FORMATS, AND THEY ARE NOT THE SAME DOCUMENT ──────────
 *
 * An ESO is read by two very different readers and a single format serves
 * neither well:
 *
 *   MARKDOWN — for an agent. YAML front matter so the machine-relevant parts
 *   (mode, status, controls, prohibitions) can be parsed without reading prose,
 *   then a readable body. This is the shape an agent runtime loads as its
 *   operating instructions.
 *
 *   PDF — for a person. A printable SOP: the procedure, who does what, what is
 *   forbidden, signed off with a version and a status. This is what goes in a
 *   quality binder or in front of an auditor.
 *
 * ── THE ONE THING BOTH MUST CARRY ───────────────────────────────────────────
 *
 * `status` and `source`. A Draft written by AI that nobody has read must say so
 * on its face, in both formats. A document that leaves the system loses its
 * surrounding UI - the badge, the warning banner, the context - so the warning
 * has to travel INSIDE the file. An exported ai-generated Draft that looks like
 * an approved procedure is the single worst thing this feature could produce.
 */
class EsoExporter
{
    /**
     * The agent format: YAML front matter + Markdown body.
     *
     * Front matter first so a runtime can read the guardrails without parsing
     * prose, and refuse to act on a Draft.
     */
    public function toMarkdown(object $eso, ?object $task = null): string
    {
        $lists = $this->decode($eso);
        $unreviewed = $eso->source === 'ai-generated' && $eso->status === 'Draft';

        $out = [];

        // ── front matter ───────────────────────────────────────────────────
        $out[] = '---';
        $out[] = 'eso_id: ' . (int) $eso->id;
        $out[] = 'title: ' . $this->yaml($eso->title);
        $out[] = 'scope: ' . $eso->scope;
        $out[] = 'version: ' . (int) $eso->version;
        $out[] = 'status: ' . $eso->status;
        $out[] = 'source: ' . $eso->source;
        if ($eso->model) {
            $out[] = 'model: ' . $this->yaml($eso->model);
        }
        $out[] = 'execution_mode: ' . ($eso->execution_mode ?? 'null');
        // The machine-readable gate. A runtime checks this one line.
        $out[] = 'safe_to_execute: ' . ($eso->status === 'Published' ? 'true' : 'false');
        if ($task) {
            $out[] = 'task: ' . $this->yaml($task->task);
            $out[] = 'job_role: ' . $this->yaml($task->jobrole);
        }
        $out[] = 'exported_at: ' . now()->toIso8601String();
        $out[] = '---';
        $out[] = '';

        // ── the warning, if it needs one ───────────────────────────────────
        if ($eso->status !== 'Published') {
            $out[] = '> **DO NOT EXECUTE.** This procedure is `' . $eso->status . '`, not `Published`.'
                . ($unreviewed ? ' It was written by ' . ($eso->model ?? 'AI') . ' and no person has reviewed it.' : '');
            $out[] = '';
        }

        $out[] = '# ' . $eso->title;
        $out[] = '';

        if ($eso->execution_mode) {
            $modes = TaskExecutionClassifier::MODES;
            $out[] = '**Execution mode:** `' . $eso->execution_mode . '` — '
                . ($modes[$eso->execution_mode] ?? '');
            $out[] = '';
        }

        $this->section($out, 'Objective', $eso->objective);
        $this->section($out, 'Expected outcome', $eso->expected_outcome);

        // ── the guardrails come BEFORE the steps ───────────────────────────
        // An agent that reads top to bottom must meet the limits before it
        // meets the instructions.
        if ($lists['prohibited_actions']) {
            $out[] = '## Prohibited actions';
            $out[] = '';
            $out[] = 'These must never happen while performing this task.';
            $out[] = '';
            foreach ($lists['prohibited_actions'] as $item) {
                $out[] = '- ' . $this->flat($item);
            }
            $out[] = '';
        }

        if ($lists['required_controls']) {
            $out[] = '## Required controls';
            $out[] = '';
            foreach ($lists['required_controls'] as $item) {
                $out[] = '- ' . $this->flat($item);
            }
            $out[] = '';
        }

        if ($lists['escalation_triggers']) {
            $out[] = '## Stop and hand over when';
            $out[] = '';
            foreach ($lists['escalation_triggers'] as $item) {
                $out[] = '- ' . $this->flat($item);
            }
            $out[] = '';
        }

        // ── responsibilities ───────────────────────────────────────────────
        $this->section($out, 'Human responsibility', $eso->human_responsibility);
        $this->section($out, 'Agent responsibility',
            $eso->agent_responsibility ?: '_None. This task is performed by a person._');

        if ($lists['human_decision_points']) {
            $out[] = '## A person must decide';
            $out[] = '';
            foreach ($lists['human_decision_points'] as $item) {
                $out[] = '- ' . $this->flat($item);
            }
            $out[] = '';
        }

        // ── the procedure ──────────────────────────────────────────────────
        if ($lists['steps']) {
            $out[] = '## Steps';
            $out[] = '';
            $out[] = '| # | Actor | Step | Tool | Output |';
            $out[] = '|---|---|---|---|---|';
            foreach ($lists['steps'] as $i => $step) {
                $actor = $step['actor'] ?? '';
                $label = ['H' => 'Person', 'A' => 'AI agent', 'S' => 'Software'][$actor] ?? $actor;
                $out[] = sprintf('| %s | %s | %s | %s | %s |',
                    $step['seq'] ?? $i + 1,
                    $label,
                    $this->cell($step['description'] ?? ''),
                    $this->cell($step['tool'] ?? ''),
                    $this->cell($step['output'] ?? ''));
            }
            $out[] = '';
        }

        // ── I/O ────────────────────────────────────────────────────────────
        if ($lists['inputs']) {
            $out[] = '## Inputs';
            $out[] = '';
            foreach ($lists['inputs'] as $x) {
                $out[] = '- **' . ($x['name'] ?? '?') . '**'
                    . ($x['source'] ? ' from ' . $x['source'] : '')
                    . ($x['format'] ? ' (' . $x['format'] . ')' : '')
                    . (($x['required'] ?? false) ? ' — required' : ' — optional');
            }
            $out[] = '';
        }

        if ($lists['outputs']) {
            $out[] = '## Outputs';
            $out[] = '';
            foreach ($lists['outputs'] as $x) {
                $out[] = '- **' . ($x['name'] ?? '?') . '**'
                    . ($x['format'] ? ' (' . $x['format'] . ')' : '')
                    . ($x['destination'] ? ' → ' . $x['destination'] : '');
            }
            $out[] = '';
        }

        if ($lists['evidence_emitted']) {
            $out[] = '## Evidence emitted';
            $out[] = '';
            foreach ($lists['evidence_emitted'] as $x) {
                $out[] = '- ' . ($x['evidence_type'] ?? 'evidence')
                    . ($x['competency_id'] ? ' → competency #' . $x['competency_id'] : '')
                    . ($x['format'] ? ' (' . $x['format'] . ')' : '');
            }
            $out[] = '';
        } else {
            // Said out loud rather than omitted. A silent absence reads as
            // "this produces no evidence", which is a claim nobody made.
            $out[] = '## Evidence emitted';
            $out[] = '';
            $out[] = '_Not yet defined. Performing this task currently proves nothing '
                . 'in the capability record._';
            $out[] = '';
        }

        return implode("\n", $out) . "\n";
    }

    /** Everything the PDF view needs, decoded. */
    public function viewData(object $eso, ?object $task = null): array
    {
        return [
            'eso'   => $eso,
            'task'  => $task,
            'lists' => $this->decode($eso),
            'modeLabel' => $eso->execution_mode
                ? (TaskExecutionClassifier::MODES[$eso->execution_mode] ?? '')
                : '',
            'unreviewed' => $eso->source === 'ai-generated' && $eso->status === 'Draft',
            'actorLabel' => ['H' => 'Person', 'A' => 'AI agent', 'S' => 'Software'],
        ];
    }

    /** The row plus its task, or null if it is a Template. */
    public function taskFor(object $eso): ?object
    {
        if (!$eso->user_jobrole_task_id) {
            return null;
        }

        return DB::table('s_user_jobrole_task')
            ->where('id', $eso->user_jobrole_task_id)
            ->first(['task', 'jobrole', 'critical_work_function']);
    }

    /** A safe filename from the title. */
    public function filename(object $eso, string $extension): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower((string) $eso->title));
        $slug = trim((string) $slug, '-') ?: 'eso';

        return sprintf('eso-%d-%s-v%d.%s', $eso->id, mb_substr($slug, 0, 60), (int) $eso->version, $extension);
    }

    /** @return array<string, array> */
    private function decode(object $eso): array
    {
        $out = [];
        foreach (EsoController::LIST_FIELDS as $field) {
            $raw = $eso->$field ?? null;
            $decoded = $raw !== null && $raw !== '' ? json_decode((string) $raw, true) : null;
            $out[$field] = is_array($decoded) ? $decoded : [];
        }
        return $out;
    }

    private function section(array &$out, string $heading, ?string $body): void
    {
        if ($body === null || trim($body) === '') {
            return;
        }
        $out[] = '## ' . $heading;
        $out[] = '';
        $out[] = trim($body);
        $out[] = '';
    }

    /** A list entry may be a string or an object; render either. */
    private function flat($item): string
    {
        if (is_string($item)) {
            return $item;
        }
        if (is_array($item)) {
            return implode(' · ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $item));
        }
        return (string) $item;
    }

    /** Pipes would break a Markdown table row. */
    private function cell($value): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $this->flat($value));
    }

    private function yaml(?string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], (string) $value) . '"';
    }
}
