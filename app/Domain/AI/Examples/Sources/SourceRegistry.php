<?php

namespace App\Domain\AI\Examples\Sources;

/**
 * Every module's source group, in one place.
 *
 * One group per top-level AI module (`ai_modules`), so adding a module's examples touches its own file
 * and this list. Nothing here names a record or a number.
 */
final class SourceRegistry
{
    /** @var array<int, class-string<SourceGroup>> */
    public const GROUPS = [
        DashboardSources::class,
        OrganisationSources::class,
        CapabilitySources::class,
        TalentSources::class,
        LmsSources::class,
        HritSources::class,
        TaskSources::class,
        AgenticSources::class,
    ];

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $all = [];

        foreach (self::GROUPS as $group) {
            array_push($all, ...(new $group())->definitions());
        }

        return $all;
    }

    /**
     * Each module's LANDING page (its level-1 menu row), mapped to the module's headline sources.
     *
     * A landing page is the module's front door rather than one of its screens, so it is declared here once
     * instead of in each group. The sources are the ones the module's own pages lead with; the group still owns
     * every other page.
     *
     * @var array<string, array{sources: array<int, string>, purpose: string}>
     */
    private const LANDING = [
        '/module/organizational-management' => [
            'sources' => ['organization.employees', 'organization.departments', 'organization.roles'],
            'purpose' => 'The front door of Organizational Management: setup of the organisation, its people and its compliance.',
        ],
        '/module/capability-intelligence' => [
            'sources' => ['capability.competencies', 'capability.jobroles', 'capability.frameworks'],
            'purpose' => 'The front door of Capability Intelligence: the competency library, frameworks and how roles map to them.',
        ],
        '/module/talent-management' => [
            'sources' => ['talent.hiring_funnel', 'talent.job_postings', 'talent.onboarding_journeys'],
            'purpose' => 'The front door of Talent Management: hiring, onboarding, mobility, development and offboarding.',
        ],
        '/module/lms' => [
            'sources' => ['lms.catalog', 'lms.my_enrolments', 'lms.assignments'],
            'purpose' => 'The front door of the LMS: the course catalogue, learning in progress and assignments.',
        ],
        '/module/hrit-solutions' => [
            'sources' => ['hrms.leave_requests', 'hrms.attendance_monthly', 'hrms.payroll_monthly'],
            'purpose' => 'The front door of HRIT: attendance, leave and payroll for the organisation.',
        ],
        '/module/task-management' => [
            'sources' => ['tasks.status_summary', 'tasks.workload', 'tasks.my_tasks'],
            'purpose' => 'The front door of Task Management: where work stands, who carries it and what is mine.',
        ],
        '/module/agentic-ai' => [
            'sources' => ['agentic.agents', 'agentic.runs'],
            'purpose' => 'The front door of Agentic AI: the agents the organisation runs and how their runs ended.',
        ],
    ];

    /**
     * Every page mapping, keyed by `access_link`, each tagged with the group that declared it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $all = [];

        foreach (self::LANDING as $route => $page) {
            $all[$route] = $page + ['group' => self::class];
        }

        foreach (self::GROUPS as $group) {
            foreach ((new $group())->pages() as $route => $page) {
                $all[$route] = $page + ['group' => $group];
            }
        }

        return $all;
    }
}
