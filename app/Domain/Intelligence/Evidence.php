<?php

namespace App\Domain\Intelligence;

/**
 * One line of evidence behind an insight. Evidence is what lets a person check the claim, so it is always one of three shapes and never free text copied out
 * of a record:
 *
 *   metric  - a number the detector counted (`label`, `value`, optional `unit`, the `source` table in words)
 *   window  - the dates the metric covers (`from`, `to`)
 *   entity  - a REFERENCE to one record: a kind and an id, nothing else (no name, no e-mail, no free text). It exists so the screen can deep-link to the
 *             record the person is already allowed to open. A detector adds entity evidence only when the caller may reference individuals
 *             (IntelligenceCaller::mayReferenceIndividuals()); executives and auditors get aggregates only.
 *
 * Nothing here is ever read from payroll, performance, salary, bank, identity-number or free-text columns.
 */
final class Evidence
{
    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly array $data,
    ) {
    }

    /**
     * Physical table names that detectors may still pass as a `source`. They are translated to words before they leave the server: a response must not
     * disclose the database's table names (the contract says the source is "the source table in words").
     *
     * @var array<string, string>
     */
    private const SOURCE_WORDS = [
        'hrms_attendances' => 'Attendance records',
        'hrms_emp_leaves' => 'Leave requests',
        'hrms_leave_approval_steps' => 'Leave approval steps',
        's_competency_certifications' => 'Certification records',
        'lms_certificates' => 'Learning certificates',
        'talent_job_applications' => 'Job applications',
        'talent_offers' => 'Job offers',
        'talent_offer_acceptances' => 'Offer responses',
        'talent_onboarding_journeys' => 'Onboarding journeys',
        'talent_onboarding_tasks' => 'Onboarding tasks',
        'g2g_company_opportunities' => 'Signals',
        'g2g_research_runs' => 'Research runs',
        'gtm_deals' => 'GTM deals',
        'gtm_activities' => 'GTM activity log',
        'gtm_renewals' => 'Renewals',
        'gtm_health_snapshots' => 'Customer health snapshots',
        'gtm_accounts' => 'GTM accounts',
        'crm_opportunities' => 'CRM opportunities',
        'crm_invoices' => 'Invoices',
        'tbluser' => 'People records',
        'hrms_departments' => 'Departments',
        'task' => 'Tasks',
        'task_status_history' => 'Task status changes',
        'lms_assignments' => 'Learning assignments',
        'lms_course_enroll' => 'Course enrolments',
        'capability_course_requests' => 'Capability-gap requests',
        'capability_course_request_subjects' => 'Capability-gap request subjects',
        'crm_organizations' => 'CRM organisations',
        's_competency_development_plans' => 'Development plans',
        's_competency_assessments' => 'Competency assessments',
        'g2g_platform_task_runs' => 'Scheduled task runs',
        'g2g_platform_scheduled_tasks' => 'Scheduled task settings',
        'g2g_integration_credentials' => 'Integration status',
        'g2g_event' => 'Platform events',
        'g2g_event_delivery' => 'Event deliveries',
        'ai_usage_events' => 'AI usage',
        'ai_usage_quotas' => 'AI usage quotas',
        'ai_policies' => 'AI policies',
        'ai_policy_assignments' => 'AI policy assignments',
        'agentic_agents' => 'Agents',
        'agentic_agent_runs' => 'Agent runs',
    ];

    /** A source in words: a known table name is translated; anything that still looks like a bare identifier (snake_case, no spaces) becomes a generic phrase. */
    public static function sourceWords(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }
        $trimmed = trim($source);
        if (isset(self::SOURCE_WORDS[$trimmed])) {
            return self::SOURCE_WORDS[$trimmed];
        }

        return preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $trimmed) === 1 ? 'Recorded data' : $trimmed;
    }

    /** @param int|float|string|null $value */
    public static function metric(string $label, int|float|string|null $value, ?string $unit = null, ?string $source = null): self
    {
        return new self('metric', $label, array_filter(['value' => $value, 'unit' => $unit, 'source' => self::sourceWords($source)], static fn ($v) => $v !== null));
    }

    public static function window(string $label, string $from, string $to): self
    {
        return new self('window', $label, ['from' => $from, 'to' => $to]);
    }

    /** A pointer to a record: kind + id only. */
    public static function entity(string $label, string $type, int $id): self
    {
        return new self('entity', $label, ['entity_type' => $type, 'entity_id' => $id]);
    }

    /** @return array{kind: string, label: string, data: array} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'label' => $this->label] + $this->data;
    }
}
