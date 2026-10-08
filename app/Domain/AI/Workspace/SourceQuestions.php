<?php

namespace App\Domain\AI\Workspace;

/**
 * Starter questions each data source can honestly answer.
 *
 * The chat's grounding (`ModuleGrounding`) gives the model, per data source, a row count and -
 * unless the rows are about individual people - the first rows. A question is only worth
 * suggesting if that is enough to answer it, so a source that is one row per person
 * (enrolments, applications, leave, attendance, tasks, employees) carries only count
 * questions: suggesting "who has the most overdue tasks?" would invite an answer the model
 * has no data for.
 *
 * Keyed by the source name rather than attached to its definition, so the catalogue stays a
 * description of data and this stays a description of what to ask about it. A source with no
 * entry still gets one question, built from its label (see `forSource`).
 */
final class SourceQuestions
{
    /** @var array<string, array<int, string>> */
    private const QUESTIONS = [
        // LMS
        'lms.course_builder' => [
            'How many courses have been built, and how many modules and lessons do they have?',
            'Which courses have no modules or lessons yet?',
        ],
        'lms.assessment_cycles' => [
            'Which assessment cycles are active right now?',
            'What are the completion and overdue counts for each assessment cycle?',
        ],
        'lms.my_enrolments' => [
            'How many course enrolments are recorded?',
        ],
        'lms.catalog' => [
            'What courses are in the learning catalogue?',
            'Which course categories does the catalogue cover?',
        ],
        // Capability
        'capability.jobroles' => [
            'How many job roles are defined, and which departments do they belong to?',
            'Which job roles have the most mapped competencies?',
        ],
        'capability.competencies' => [
            'How many competencies are active and published?',
            'Which competencies are marked most critical?',
        ],
        'capability.entity_mappings' => [
            'How do our records map onto the knowledge graph?',
        ],
        // Talent
        'talent.pipeline' => [
            'How many applications are in the candidate pipeline?',
        ],
        'talent.job_postings' => [
            'Which job postings are open?',
            'How many people applied to each job posting?',
        ],
        'talent.workflows' => [
            'Which hiring workflows are configured, and how many stages does each have?',
        ],
        // Task
        'tasks.my_tasks' => [
            'How many tasks are recorded?',
        ],
        // Organization
        'organization.employees' => [
            'How many employees are recorded?',
        ],
        'organization.departments' => [
            'Which departments exist, and how many employees sit in each?',
            'Which departments have no head assigned?',
        ],
        // HRMS
        'hrms.leave_requests' => [
            'How many leave requests are recorded?',
        ],
        'hrms.attendance' => [
            'How many attendance records are recorded?',
        ],
    ];

    /**
     * @param  array{name:string, label:string}  $source
     * @return array<int, string>
     */
    public function forSource(array $source): array
    {
        return self::QUESTIONS[$source['name']]
            ?? ['What does the ' . mb_strtolower((string) $source['label']) . ' data show?'];
    }
}
