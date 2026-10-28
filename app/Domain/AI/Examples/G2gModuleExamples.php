<?php

namespace App\Domain\AI\Examples;

/**
 * G2G's own choices for the AI Stack "Example" panels: which real write action a module demonstrates.
 *
 * THE ONE PLACE G2G-SPECIFIC WORDING LIVES
 *
 * Everything else under `App\Domain\AI\Examples` is generic: it reads whatever
 * `ModuleDataSourceCatalog`, `ai_templates`, `AiPolicyResolver`, the route table and the
 * audit ledger say about a module. What the generic code cannot know is which of a module's
 * real write actions is the one worth demonstrating. That fact is here, keyed by real
 * `ai_modules` keys. Nothing is seeded: no template, policy or agent is ever inserted from this file.
 *
 * Nothing here is a number, a record or a user: a figure on screen is always computed from
 * the database at request time.
 *
 * `action` is the module's chat write action. `method`/`uri` are looked up in the live
 * route table, and the guardrail check evaluates whichever gates that route really has.
 * `self_service` is only the explanation shown when the route has no gate of its own
 * (the controller pins the request to the caller instead).
 */
final class G2gModuleExamples
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            // The Main Dashboard and Agentic AI have no chat write action, so they declare nothing: their Example panels
            // are built entirely from their own records.
            'organizational_management' => [
                'action' => [
                    'key' => 'create_department',
                    'label' => 'Create a department',
                    'method' => 'POST',
                    'uri' => 'api/departments-management',
                ],
            ],
            'hrit_management' => [
                'action' => [
                    'key' => 'apply_leave',
                    'label' => 'Apply for leave',
                    'method' => 'POST',
                    'uri' => 'api/leave/requests',
                    'self_service' => 'The leave endpoint declares no route-level right: the controller files the request for the token owner, so any signed-in employee can apply for their own leave and nobody can apply on behalf of another.',
                ],
            ],
            'talent_management' => [
                'action' => [
                    'key' => 'create_job_posting',
                    'label' => 'Create a job posting',
                    'method' => 'POST',
                    'uri' => 'api/job-postings',
                ],
            ],
            'lms' => [
                'action' => [
                    'key' => 'request_enrollment',
                    'label' => 'Request course enrolment',
                    'method' => 'POST',
                    'uri' => 'api/lmsAssignment/request',
                    'self_service' => 'The enrolment-request endpoint declares no route-level right: the controller records the request against the token owner, so a learner can ask for a course for themselves only, and an administrator decides it.',
                ],
            ],
            'capability_intelligence' => [
                'action' => [
                    'key' => 'create_competency',
                    'label' => 'Create a competency',
                    'method' => 'POST',
                    'uri' => 'api/competency-library/competency',
                ],
            ],
            'task_management' => [
                'action' => [
                    'key' => 'add_backlog_item',
                    'label' => 'Add a backlog item',
                    'method' => 'POST',
                    'uri' => 'api/task-management/backlog',
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function for(string $moduleKey): ?array
    {
        return self::definitions()[$moduleKey] ?? null;
    }

    /** The prompt body shared by every starter prompt: only `focus` differs. */
    public static function promptText(string $focus): array
    {
        return [
            'system' => "You are the {{module}} assistant. Use only the records and figures you are given. "
                . 'If they are empty, say so plainly instead of guessing. Never invent a name, a number or a date.',
            'user' => "Using only the live data below, describe {$focus}. Give five short bullet points and then name one thing worth checking.\n\n"
                . "Figures:\n{{metrics}}\n\nRecords:\n{{records}}\n\n"
                . 'Total records: {{record_count}} (rows shown: {{rows_shown}}; partial view: {{is_partial}}).',
        ];
    }

    /** The layout every starter report uses: letterhead, heading, provenance line and the rows. */
    public static function reportLayout(string $heading): string
    {
        return '<div style="font-family:Inter,Segoe UI,sans-serif;">'
            . '<p style="margin:0;font-size:12px;color:#64748b;"><<institute_name>></p>'
            . '<h2 style="margin:4px 0 2px;">' . e($heading) . '</h2>'
            . '<p style="margin:0 0 12px;font-size:12px;color:#64748b;"><<row_count>> record(s) &middot; generated <<generated_at>></p>'
            . '<<rows_table>>'
            . '</div>';
    }
}
