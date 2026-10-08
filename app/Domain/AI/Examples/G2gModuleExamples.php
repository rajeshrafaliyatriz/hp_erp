<?php

namespace App\Domain\AI\Examples;

/**
 * G2G's own choices for the AI Stack "Example" panels and the starter rows that back them.
 *
 * THE ONE PLACE G2G-SPECIFIC WORDING LIVES
 *
 * Everything else under `App\Domain\AI\Examples` is generic: it reads whatever
 * `ModuleDataSourceCatalog`, `ai_templates`, `AiPolicyResolver`, the route table and the
 * audit ledger say about a module. What the generic code cannot know is which of a module's
 * real write actions is the one worth demonstrating, and what its starter report and
 * prompt should be called. Those few facts are here, keyed by real `ai_modules` keys and
 * naming real data sources (the seeder checks every name against the catalogue and skips
 * what does not exist, rather than inventing a source).
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
    /** Prefix of the template keys the seeder owns; also how its rows are recognised and removed. */
    public const KEY_PREFIX = 'g2g.example.';

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            'organizational_management' => [
                'action' => [
                    'key' => 'create_department',
                    'label' => 'Create a department',
                    'method' => 'POST',
                    'uri' => 'api/departments-management',
                ],
                'report' => [
                    'source' => 'organization.departments',
                    'name' => 'Department structure register (starter example)',
                    'heading' => 'Department structure',
                    'description' => 'Every department with its head, parent and headcount, read live from Department Management.',
                    'arguments' => ['limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Organisation structure summary (starter example)',
                    'description' => 'Summarises how the organisation is structured from its live department records.',
                    'focus' => 'how the organisation is structured: how many departments there are, which are largest and which look empty',
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
                'report' => [
                    'source' => 'hrms.leave_requests',
                    'name' => 'Leave requests register (starter example)',
                    'heading' => 'Leave requests',
                    'description' => 'Every leave request with its type, dates and status, read live from HRIT leave management.',
                    'arguments' => ['limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Leave position summary (starter example)',
                    'description' => 'Summarises where leave requests stand from the live leave records.',
                    'focus' => 'the leave position: how many requests exist, how many are still waiting for a decision and which leave types dominate',
                ],
            ],
            'talent_management' => [
                'action' => [
                    'key' => 'create_job_posting',
                    'label' => 'Create a job posting',
                    'method' => 'POST',
                    'uri' => 'api/job-postings',
                ],
                'report' => [
                    'source' => 'talent.job_postings',
                    'name' => 'Open job postings register (starter example)',
                    'heading' => 'Job postings',
                    'description' => 'Every job posting with its department, status and number of applicants, read live from Recruitment.',
                    'arguments' => ['limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Hiring position summary (starter example)',
                    'description' => 'Summarises the state of recruitment from the live job posting records.',
                    'focus' => 'the hiring position: how many postings exist, which are open and where applications are concentrated',
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
                'report' => [
                    'source' => 'lms.my_enrolments',
                    'name' => 'Learning in progress (starter example)',
                    'heading' => 'Learning in progress',
                    'description' => 'Enrolments that are currently in progress, with the learner, course and dates, read live from the LMS.',
                    'arguments' => ['status' => 'in-progress', 'limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Learning activity summary (starter example)',
                    'description' => 'Summarises course and enrolment activity from the live LMS records.',
                    'focus' => 'learning activity: how many courses and enrolments exist, how enrolments are spread by status and what looks stalled',
                ],
            ],
            'capability_intelligence' => [
                'action' => [
                    'key' => 'create_competency',
                    'label' => 'Create a competency',
                    'method' => 'POST',
                    'uri' => 'api/competency-library/competency',
                ],
                'report' => [
                    'source' => 'capability.competencies',
                    'name' => 'Competency library register (starter example)',
                    'heading' => 'Competency library',
                    'description' => 'Every competency in the library with its category and level, read live from Capability Intelligence.',
                    'arguments' => ['limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Competency coverage summary (starter example)',
                    'description' => 'Summarises the competency library and job role coverage from live records.',
                    'focus' => 'capability coverage: how many competencies and job roles exist, how competencies are spread by category and where coverage looks thin',
                ],
            ],
            'task_management' => [
                'action' => [
                    'key' => 'add_backlog_item',
                    'label' => 'Add a backlog item',
                    'method' => 'POST',
                    'uri' => 'api/task-management/backlog',
                ],
                'report' => [
                    'source' => 'tasks.my_tasks',
                    'name' => 'Task status report (starter example)',
                    'heading' => 'Task status',
                    'description' => 'Every task with its priority, status, assignee and department, read live from Task Management; run it weekly for a status snapshot.',
                    'arguments' => ['limit' => 200],
                ],
                'prompt' => [
                    'name' => 'Task workload summary (starter example)',
                    'description' => 'Summarises task workload and progress from the live task records.',
                    'focus' => 'task workload: how many tasks exist, how they split by status and priority, and what looks overdue or unassigned',
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
