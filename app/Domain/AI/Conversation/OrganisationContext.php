<?php

namespace App\Domain\AI\Conversation;

use App\Domain\AI\Support\ActiveRowFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The facts about this organisation that get put in front of the model.
 *
 * WHY AN ASSISTANT NEEDS THIS AND WHY IT IS ONLY AGGREGATES
 *
 * A model asked "how many job roles do we have" with no data will answer. It will
 * answer confidently, with a number, and the number will be invented — which is
 * worse than a refusal, because a refusal is obviously a non-answer and a fabricated
 * count looks like a report. Grounding is the fix: give it the real figures and
 * instruct it to use nothing else.
 *
 * What is supplied is deliberately **counts and taxonomy only**. No employee name,
 * no competency rating, no assessment result, no salary. That boundary is not
 * squeamishness, it is what makes this safe to enable at all: everything below is a
 * fact about the organisation's shape, the kind of thing on a dashboard, and none of
 * it is a fact about a person. An assistant that could quote somebody's proficiency
 * rating would need a permissions model per record, and it does not have one.
 *
 * Any caller that later wants per-person answers has to build that model first. The
 * absence of those tables from this file is the enforcement.
 *
 * READ FAILURES ARE SURVIVABLE
 *
 * A missing table or a broken column costs one line of context, not the answer. Each
 * probe is guarded and the whole thing is wrapped, because an assistant that refuses
 * to reply because one count could not be read is less useful than one that replies
 * with the counts it has.
 */
final class OrganisationContext
{
    /**
     * A short factual briefing, or null when nothing could be read.
     *
     * Returns null rather than an empty string so the caller can tell "this
     * organisation has no data" from "I have data and it is blank", and word the
     * system prompt accordingly.
     */
    public function briefing(int|string|null $institute): ?string
    {
        if ($institute === null || trim((string) $institute) === '') {
            return null;
        }

        $lines = [];

        foreach ($this->probes() as $label => $probe) {
            try {
                $value = $probe($institute);
            } catch (Throwable) {
                // One unreadable count is not worth losing the rest of the briefing.
                continue;
            }

            if ($value !== null) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Each fact, as a label and a way to read it.
     *
     * A map rather than a sequence of method calls so adding a fact is one entry and
     * the labels stay next to the queries that produce them — a label that drifts
     * from its query is a briefing that lies.
     *
     * @return array<string, callable(int|string): ?string>
     */
    private function probes(): array
    {
        return [
            'Active employees' => fn ($i) => $this->count('tbluser', $i, fn ($q) => $q->where('status', 1)),
            'Departments' => fn ($i) => $this->count('hrms_departments', $i),
            'Competencies defined' => fn ($i) => $this->count('competency', $i),
            'Competency frameworks' => fn ($i) => $this->count('s_competency_frameworks', $i),
            'Job-role competency requirements mapped' => fn ($i) => $this->count('jobrole_competency_map', $i),
            'Courses' => fn ($i) => $this->count('contents', $i),
            'Open tasks' => fn ($i) => $this->count('task', $i),
            'AI agents configured' => fn ($i) => $this->count('agentic_agents', $i),
            'AI agent runs recorded' => fn ($i) => $this->count('agentic_agent_runs', $i),
            'AI templates published' => fn ($i) => $this->count(
                'ai_templates',
                $i,
                fn ($q) => $q->where('status', 'published'),
                includePlatform: true
            ),
            'AI policies active' => fn ($i) => $this->count(
                'ai_policies',
                $i,
                fn ($q) => $q->where('status', 1),
                includePlatform: true
            ),
        ];
    }

    /**
     * A tenant-scoped count, or null when the table is not on this deployment.
     *
     * Null rather than zero for a missing table. Zero is a claim that the
     * organisation has none of something; a missing table means this deployment
     * cannot say, and telling the model "0" would have it report an absence that was
     * never measured.
     *
     * `$includePlatform` is for the `ai_*` tables, where a NULL `sub_institute_id`
     * means shared-with-everyone rather than belonging to nobody.
     */
    private function count(
        string $table,
        int|string $institute,
        ?callable $filter = null,
        bool $includePlatform = false
    ): ?string {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'sub_institute_id')) {
            $includePlatform
                ? $query->where(fn ($inner) => $inner->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
                : $query->where('sub_institute_id', $institute);
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($filter !== null) {
            $filter($query);
        } else {
            // No explicit filter means "whatever is not retired", and the status
            // conventions across these tables disagree — see ActiveRowFilter.
            ActiveRowFilter::apply($query, $table);
        }

        return number_format((int) $query->count());
    }

    /**
     * The standing instruction the briefing is wrapped in.
     *
     * Kept beside the data it describes. A system prompt that tells the model to use
     * only the supplied figures, held in a different file from the code that supplies
     * them, is a pairing that drifts.
     */
    public function systemPrompt(?string $briefing, string $organisationName = 'this organisation'): string
    {
        $prompt = <<<TXT
You are the assistant inside GapstoGrowth, a capability and competency management
platform. You are speaking to an administrator of {$organisationName}.

Answer questions about capability, competency, learning, talent and the platform
itself. Be brief and concrete. Prefer a short direct answer to a long hedged one.

RULES YOU MUST NOT BREAK

- Use only the figures supplied below. If a question needs a number that is not
  there, say which screen would show it instead of estimating one.
- Never invent a name, a count, a rating or a date.
- You do not have access to any individual person's record. If asked about a named
  employee's rating, assessment or pay, say plainly that you cannot see per-person
  data and point at the module that can.
- If you are unsure, say so. An admitted gap is useful; a confident guess is not.
TXT;

        if ($briefing === null) {
            return $prompt . "\n\nNo figures are available for this organisation, so answer only "
                . 'questions about how the platform works, and say when you would need data you do not have.';
        }

        return $prompt . "\n\nCURRENT FIGURES FOR {$organisationName}:\n{$briefing}";
    }
}
