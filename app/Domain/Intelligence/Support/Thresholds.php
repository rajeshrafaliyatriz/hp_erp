<?php

namespace App\Domain\Intelligence\Support;

/**
 * EVERY NUMBER HERE IS PROVISIONAL (decision D-12 default). They are proposals, not measured facts: the product's own code defaults where noted, otherwise
 * numbers chosen for the first version and to be calibrated against real data only after an approved, environment-specific data inspection.
 * Nothing here was calibrated against LOCAL or LIVE data (no database was accessed).
 *
 * Detectors receive an instance, never read config() themselves, so tests can pin a value.
 */
final class Thresholds
{
    /** @param array<string, int|float> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @var array<string, int|float> */
    public const DEFAULTS = [
        // Privacy: groups smaller than this are suppressed for callers not entitled to individuals (D-8 default).
        'min_group_size' => 5,

        // HR - attendance (P-1, P-2)
        'attendance_window_days' => 30,
        'attendance_min_employees' => 10,          // P-1
        'attendance_min_days' => 10,               // P-1 (distinct attendance days in the window)
        'attendance_missing_punchout_pct' => 15,   // P-2
        'attendance_duplicate_pct' => 2,           // P-2
        'attendance_participation_pct' => 70,      // P-1: below this share of active people punching, the picture is incomplete

        // HR - leave approvals (P-3, P-4)
        'leave_pending_hours' => 48,               // P-4
        'leave_min_steps' => 5,                    // P-3

        // HR - certifications (P-5)
        'certification_due_days' => 30,
        'certification_soon_days' => 90,
        'certification_min_records' => 10,         // P-5 (org/department scope; self/team scope reports whatever exists)

        // HR - recruitment (P-6)
        'recruitment_min_applications' => 15,      // P-6
        'recruitment_stale_days' => 14,
        'recruitment_window_days' => 365,

        // HR - onboarding / probation (P-6)
        'probation_window_days' => 30,
        'onboarding_min_journeys' => 5,            // P-6
        'onboarding_task_overdue_days' => 0,

        // HR - organisation structure (PROVISIONAL)
        'org_structure_min_people' => 5,           // active people in scope before structure completeness is assessed
        'org_structure_gap_pct' => 20,             // a gap affecting at least this share of active people is 'attention', below it 'info'

        // HR - tasks (PROVISIONAL)
        'tasks_min_assigned' => 5,                 // tasks assigned to people in scope before workflow is assessed (self scope reports whatever exists)
        'tasks_overdue_pct' => 25,                 // share of open tasks that are overdue at which severity rises from 'attention' to 'high'
        'tasks_reopen_window_days' => 30,          // look-back for reopened tasks (task_status_history is not back-filled)

        // HR - learning assignments (PROVISIONAL)
        'learning_min_assignments' => 5,           // approved assignments in scope before learning is assessed (self scope reports whatever exists)
        'learning_overdue_pct' => 25,              // share of not-yet-completed assignments that are overdue at which severity rises to 'high'
        'learning_stalled_days' => 30,             // an in-progress course with no recorded activity for this long is 'quiet'

        // HR - capability development (PROVISIONAL)
        'capability_min_records' => 5,             // open plans/assessments with a due date before they are assessed (self scope reports whatever exists)
        'capability_overdue_pct' => 25,            // share of open records that are overdue at which severity rises to 'high'

        // Commercial - signals (P-7)
        'signals_window_days' => 90,
        'signals_min_runs' => 5,                   // P-7
        'signals_min_signals' => 20,               // P-7
        'signals_snippet_only_pct' => 50,
        'signals_undated_pct' => 40,
        'signals_zero_yield_pct' => 50,

        // Commercial - deals (P-8, P-9: code defaults of GTM_DEAL_QUIET_DAYS / GTM_DEAL_STUCK_DAYS / CRM_OPPORTUNITY_INACTIVE_DAYS)
        'deals_min_open' => 10,                    // P-8
        'deals_quiet_days' => 14,                  // P-9
        'deals_stuck_days' => 30,                  // P-9
        'crm_inactive_days' => 14,                 // P-9

        // Commercial - customers/renewals (P-9, P-11)
        'renewal_alert_days' => 60,                // P-9
        'customers_min_snapshots' => 7,            // P-11

        // Commercial - invoices (P-12)
        'invoices_min' => 10,
        'invoice_overdue_days' => 30,

        // Platform services, AI governance and Agentic AI (PROVISIONAL; nothing calibrated against LOCAL or LIVE data)
        'scheduler_window_days' => 7,                      // Platform - scheduler: look-back for task runs
        'scheduler_min_runs' => 10,                        // runs in the window before task health is assessed
        'scheduler_silent_hours' => 12,                    // no task has started for this long: the scheduler is not running (the busiest task runs every minute)
        'scheduler_skip_streak' => 5,                      // a task skipped this many times in the window and never completed is stuck
        'scheduler_open_run_hours' => 6,                   // a run started but never closed after this long is reported
        'integrations_min' => 1,                           // saved integration rows before integration status is assessed
        'integration_untested_days' => 90,                 // a configured integration not tested for this long is reported
        'events_window_days' => 7,                         // Platform - event bus: look-back for events
        'events_min_deliveries' => 10,                     // deliveries in the window before delivery health is assessed
        'events_failed_pct' => 5,                          // share of deliveries that failed at which severity rises to high
        'events_pending_hours' => 24,                      // a delivery still pending this long after its event was recorded is reported
        'ai_usage_window_days' => 30,                      // Platform - AI usage: look-back for usage events
        'ai_usage_min_events' => 20,                       // AI calls in the window before failure share is assessed
        'ai_usage_failure_pct' => 10,                      // share of AI calls that failed at which an insight is raised
        'ai_usage_min_priced' => 20,                       // priced AI calls in one currency before cost concentration is assessed
        'ai_usage_cost_concentration_pct' => 70,           // share of one currency's cost taken by one AI module at which concentration is reported
        'governance_min_policies' => 1,                    // active organisation AI policies before policy coverage is assessed
        'agentic_window_days' => 30,                       // Agentic AI: look-back for runs and idle agents
        'agentic_min_runs' => 10,                          // finished agent runs in the window before failure share is assessed
        'agentic_failure_pct' => 20,                       // share of finished agent runs that failed at which an insight is raised
        'agentic_stalled_hours' => 24,                     // a run still pending or running after this long is stalled
        'agentic_min_agents' => 3,                         // deployed agents before idle agents are assessed
        'agentic_cost_rise_pct' => 50,                     // rise in average cost per run, second half of the window against the first, at which it is reported

        // Cross-functional (PROVISIONAL; a relationship is reported only above these minimums of verified-linked, eligible records)
        'cross_learning_min_eligible' => 5,        // overdue, competency-tagged learning assignments in scope before they are compared with open capability-gap requests (self scope reports whatever exists)
        'cross_renewal_min_linked' => 5,           // accounts needing renewal attention AND linked to a CRM organisation before they are compared with overdue invoices
        'cross_attention_pct' => 25,               // share of eligible records that are linked at which severity rises from 'info' to 'attention'
    ];

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /** The lowest value the small-group rule may be configured to: below this it would stop protecting anyone, so a lower setting is raised to it. */
    public const MIN_GROUP_SIZE_FLOOR = 3;

    /**
     * @param array<string, mixed> $overrides Unknown keys, non-numeric values (including an empty string from an unset environment variable) and negative
     *                                        numbers are ignored. Two safeguards cannot be configured away:
     *                                          - `min_group_size` is never below MIN_GROUP_SIZE_FLOOR;
     *                                          - every "minimum records" gate (any key containing `_min_` / ending `_min`) is never below 1, so a typo cannot
     *                                            disable an insufficient-data gate and turn "not enough data" into a verdict.
     */
    public static function with(array $overrides): self
    {
        $values = self::DEFAULTS;
        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $values) && is_numeric($value) && $value + 0 >= 0) {
                $values[$key] = $value + 0;
            }
        }

        $values['min_group_size'] = max(self::MIN_GROUP_SIZE_FLOOR, (int) $values['min_group_size']);
        foreach ($values as $key => $value) {
            if ($key !== 'min_group_size' && (str_contains($key, '_min_') || str_ends_with($key, '_min'))) {
                $values[$key] = max(1, $value);
            }
        }

        return new self($values);
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? self::DEFAULTS[$key] ?? 0);
    }

    public function float(string $key): float
    {
        return (float) ($this->values[$key] ?? self::DEFAULTS[$key] ?? 0);
    }

    /** @return array<string, int|float> */
    public function all(): array
    {
        return $this->values;
    }
}
