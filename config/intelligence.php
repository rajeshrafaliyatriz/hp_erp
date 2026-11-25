<?php

use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\Thresholds;

/*
|--------------------------------------------------------------------------
| G2G Intelligence
|--------------------------------------------------------------------------
|
| Role-aware, tenant-safe, evidence-backed decision support. Detection is deterministic rules over records the caller may already see; nothing here calls a
| model, writes a record, or schedules anything.
|
| EVERY NUMBER BELOW IS PROVISIONAL (decision D-12 default). They are proposals, not measured facts, and none was calibrated against LOCAL or LIVE data (no
| database was accessed while writing them). The defaults live in App\Domain\Intelligence\Support\Thresholds::DEFAULTS; this file only lets an environment
| override one with INTELLIGENCE_<KEY> (e.g. INTELLIGENCE_MIN_GROUP_SIZE).
|
| The RAW env value is passed to Thresholds::with(), with NO cast: a cast would turn an empty or misspelt value into 0 and quietly weaken a gate. Thresholds::with()
| ignores a value that is not numeric (so "" or "abc" leave the default in place) and applies its own hard floors. Never cast here.
*/

return [
    /** Master switch. Unset = on. false makes every Intelligence endpoint answer 503 and read nothing. An UNPARSEABLE value ("maybe", empty) fails CLOSED: disabled. */
    'enabled' => InsightService::parseEnabled(env('INTELLIGENCE_ENABLED')),

    /** PROVISIONAL thresholds, one entry per Thresholds::DEFAULTS key (explicit so a missing default is a test failure, not a silent zero). */
    'thresholds' => [
        'min_group_size' => env('INTELLIGENCE_MIN_GROUP_SIZE', Thresholds::DEFAULTS['min_group_size']),                 // D-8, PROVISIONAL
        'attendance_window_days' => env('INTELLIGENCE_ATTENDANCE_WINDOW_DAYS', Thresholds::DEFAULTS['attendance_window_days']),               // PROVISIONAL
        'attendance_min_employees' => env('INTELLIGENCE_ATTENDANCE_MIN_EMPLOYEES', Thresholds::DEFAULTS['attendance_min_employees']),         // P-1 PROVISIONAL
        'attendance_min_days' => env('INTELLIGENCE_ATTENDANCE_MIN_DAYS', Thresholds::DEFAULTS['attendance_min_days']),                        // P-1 PROVISIONAL
        'attendance_missing_punchout_pct' => env('INTELLIGENCE_ATTENDANCE_MISSING_PUNCHOUT_PCT', Thresholds::DEFAULTS['attendance_missing_punchout_pct']), // P-2 PROVISIONAL
        'attendance_duplicate_pct' => env('INTELLIGENCE_ATTENDANCE_DUPLICATE_PCT', Thresholds::DEFAULTS['attendance_duplicate_pct']),          // P-2 PROVISIONAL
        'attendance_participation_pct' => env('INTELLIGENCE_ATTENDANCE_PARTICIPATION_PCT', Thresholds::DEFAULTS['attendance_participation_pct']), // P-1 PROVISIONAL
        'leave_pending_hours' => env('INTELLIGENCE_LEAVE_PENDING_HOURS', Thresholds::DEFAULTS['leave_pending_hours']),                        // P-4 PROVISIONAL
        'leave_min_steps' => env('INTELLIGENCE_LEAVE_MIN_STEPS', Thresholds::DEFAULTS['leave_min_steps']),                                    // P-3 PROVISIONAL
        'certification_due_days' => env('INTELLIGENCE_CERTIFICATION_DUE_DAYS', Thresholds::DEFAULTS['certification_due_days']),               // PROVISIONAL
        'certification_soon_days' => env('INTELLIGENCE_CERTIFICATION_SOON_DAYS', Thresholds::DEFAULTS['certification_soon_days']),            // PROVISIONAL
        'certification_min_records' => env('INTELLIGENCE_CERTIFICATION_MIN_RECORDS', Thresholds::DEFAULTS['certification_min_records']),      // P-5 PROVISIONAL
        'recruitment_min_applications' => env('INTELLIGENCE_RECRUITMENT_MIN_APPLICATIONS', Thresholds::DEFAULTS['recruitment_min_applications']), // P-6 PROVISIONAL
        'recruitment_stale_days' => env('INTELLIGENCE_RECRUITMENT_STALE_DAYS', Thresholds::DEFAULTS['recruitment_stale_days']),               // PROVISIONAL
        'recruitment_window_days' => env('INTELLIGENCE_RECRUITMENT_WINDOW_DAYS', Thresholds::DEFAULTS['recruitment_window_days']),            // PROVISIONAL
        'probation_window_days' => env('INTELLIGENCE_PROBATION_WINDOW_DAYS', Thresholds::DEFAULTS['probation_window_days']),                  // PROVISIONAL
        'onboarding_min_journeys' => env('INTELLIGENCE_ONBOARDING_MIN_JOURNEYS', Thresholds::DEFAULTS['onboarding_min_journeys']),            // P-6 PROVISIONAL
        'onboarding_task_overdue_days' => env('INTELLIGENCE_ONBOARDING_TASK_OVERDUE_DAYS', Thresholds::DEFAULTS['onboarding_task_overdue_days']), // PROVISIONAL
        'signals_window_days' => env('INTELLIGENCE_SIGNALS_WINDOW_DAYS', Thresholds::DEFAULTS['signals_window_days']),                        // PROVISIONAL
        'signals_min_runs' => env('INTELLIGENCE_SIGNALS_MIN_RUNS', Thresholds::DEFAULTS['signals_min_runs']),                                 // P-7 PROVISIONAL
        'signals_min_signals' => env('INTELLIGENCE_SIGNALS_MIN_SIGNALS', Thresholds::DEFAULTS['signals_min_signals']),                        // P-7 PROVISIONAL
        'signals_snippet_only_pct' => env('INTELLIGENCE_SIGNALS_SNIPPET_ONLY_PCT', Thresholds::DEFAULTS['signals_snippet_only_pct']),         // PROVISIONAL
        'signals_undated_pct' => env('INTELLIGENCE_SIGNALS_UNDATED_PCT', Thresholds::DEFAULTS['signals_undated_pct']),                        // PROVISIONAL
        'signals_zero_yield_pct' => env('INTELLIGENCE_SIGNALS_ZERO_YIELD_PCT', Thresholds::DEFAULTS['signals_zero_yield_pct']),               // PROVISIONAL
        'deals_min_open' => env('INTELLIGENCE_DEALS_MIN_OPEN', Thresholds::DEFAULTS['deals_min_open']),                                       // P-8 PROVISIONAL
        'deals_quiet_days' => env('INTELLIGENCE_DEALS_QUIET_DAYS', Thresholds::DEFAULTS['deals_quiet_days']),                                 // P-9: code default of GTM_DEAL_QUIET_DAYS, PROVISIONAL
        'deals_stuck_days' => env('INTELLIGENCE_DEALS_STUCK_DAYS', Thresholds::DEFAULTS['deals_stuck_days']),                                 // P-9: code default of GTM_DEAL_STUCK_DAYS, PROVISIONAL
        'crm_inactive_days' => env('INTELLIGENCE_CRM_INACTIVE_DAYS', Thresholds::DEFAULTS['crm_inactive_days']),                              // P-9: code default of CRM_OPPORTUNITY_INACTIVE_DAYS, PROVISIONAL
        'renewal_alert_days' => env('INTELLIGENCE_RENEWAL_ALERT_DAYS', Thresholds::DEFAULTS['renewal_alert_days']),                           // P-9: code default of GTM_RENEWAL_ALERT_DAYS, PROVISIONAL
        'customers_min_snapshots' => env('INTELLIGENCE_CUSTOMERS_MIN_SNAPSHOTS', Thresholds::DEFAULTS['customers_min_snapshots']),            // P-11 PROVISIONAL
        'invoices_min' => env('INTELLIGENCE_INVOICES_MIN', Thresholds::DEFAULTS['invoices_min']),                                             // P-12 PROVISIONAL
        'org_structure_min_people' => env('INTELLIGENCE_ORG_STRUCTURE_MIN_PEOPLE', Thresholds::DEFAULTS['org_structure_min_people']),       // PROVISIONAL
        'org_structure_gap_pct' => env('INTELLIGENCE_ORG_STRUCTURE_GAP_PCT', Thresholds::DEFAULTS['org_structure_gap_pct']),                // PROVISIONAL
        'tasks_min_assigned' => env('INTELLIGENCE_TASKS_MIN_ASSIGNED', Thresholds::DEFAULTS['tasks_min_assigned']),                        // PROVISIONAL
        'tasks_overdue_pct' => env('INTELLIGENCE_TASKS_OVERDUE_PCT', Thresholds::DEFAULTS['tasks_overdue_pct']),                           // PROVISIONAL
        'tasks_reopen_window_days' => env('INTELLIGENCE_TASKS_REOPEN_WINDOW_DAYS', Thresholds::DEFAULTS['tasks_reopen_window_days']),      // PROVISIONAL
        'learning_min_assignments' => env('INTELLIGENCE_LEARNING_MIN_ASSIGNMENTS', Thresholds::DEFAULTS['learning_min_assignments']),      // PROVISIONAL
        'learning_overdue_pct' => env('INTELLIGENCE_LEARNING_OVERDUE_PCT', Thresholds::DEFAULTS['learning_overdue_pct']),                  // PROVISIONAL
        'learning_stalled_days' => env('INTELLIGENCE_LEARNING_STALLED_DAYS', Thresholds::DEFAULTS['learning_stalled_days']),               // PROVISIONAL
        'capability_min_records' => env('INTELLIGENCE_CAPABILITY_MIN_RECORDS', Thresholds::DEFAULTS['capability_min_records']),            // PROVISIONAL
        'capability_overdue_pct' => env('INTELLIGENCE_CAPABILITY_OVERDUE_PCT', Thresholds::DEFAULTS['capability_overdue_pct']),            // PROVISIONAL
        'invoice_overdue_days' => env('INTELLIGENCE_INVOICE_OVERDUE_DAYS', Thresholds::DEFAULTS['invoice_overdue_days']),                     // PROVISIONAL
        'scheduler_window_days' => env('INTELLIGENCE_SCHEDULER_WINDOW_DAYS', Thresholds::DEFAULTS['scheduler_window_days']),   // PROVISIONAL
        'scheduler_min_runs' => env('INTELLIGENCE_SCHEDULER_MIN_RUNS', Thresholds::DEFAULTS['scheduler_min_runs']),   // PROVISIONAL
        'scheduler_silent_hours' => env('INTELLIGENCE_SCHEDULER_SILENT_HOURS', Thresholds::DEFAULTS['scheduler_silent_hours']),   // PROVISIONAL
        'scheduler_skip_streak' => env('INTELLIGENCE_SCHEDULER_SKIP_STREAK', Thresholds::DEFAULTS['scheduler_skip_streak']),   // PROVISIONAL
        'scheduler_open_run_hours' => env('INTELLIGENCE_SCHEDULER_OPEN_RUN_HOURS', Thresholds::DEFAULTS['scheduler_open_run_hours']),   // PROVISIONAL
        'integrations_min' => env('INTELLIGENCE_INTEGRATIONS_MIN', Thresholds::DEFAULTS['integrations_min']),   // PROVISIONAL
        'integration_untested_days' => env('INTELLIGENCE_INTEGRATION_UNTESTED_DAYS', Thresholds::DEFAULTS['integration_untested_days']),   // PROVISIONAL
        'events_window_days' => env('INTELLIGENCE_EVENTS_WINDOW_DAYS', Thresholds::DEFAULTS['events_window_days']),   // PROVISIONAL
        'events_min_deliveries' => env('INTELLIGENCE_EVENTS_MIN_DELIVERIES', Thresholds::DEFAULTS['events_min_deliveries']),   // PROVISIONAL
        'events_failed_pct' => env('INTELLIGENCE_EVENTS_FAILED_PCT', Thresholds::DEFAULTS['events_failed_pct']),   // PROVISIONAL
        'events_pending_hours' => env('INTELLIGENCE_EVENTS_PENDING_HOURS', Thresholds::DEFAULTS['events_pending_hours']),   // PROVISIONAL
        'ai_usage_window_days' => env('INTELLIGENCE_AI_USAGE_WINDOW_DAYS', Thresholds::DEFAULTS['ai_usage_window_days']),   // PROVISIONAL
        'ai_usage_min_events' => env('INTELLIGENCE_AI_USAGE_MIN_EVENTS', Thresholds::DEFAULTS['ai_usage_min_events']),   // PROVISIONAL
        'ai_usage_failure_pct' => env('INTELLIGENCE_AI_USAGE_FAILURE_PCT', Thresholds::DEFAULTS['ai_usage_failure_pct']),   // PROVISIONAL
        'ai_usage_min_priced' => env('INTELLIGENCE_AI_USAGE_MIN_PRICED', Thresholds::DEFAULTS['ai_usage_min_priced']),   // PROVISIONAL
        'ai_usage_cost_concentration_pct' => env('INTELLIGENCE_AI_USAGE_COST_CONCENTRATION_PCT', Thresholds::DEFAULTS['ai_usage_cost_concentration_pct']),   // PROVISIONAL
        'governance_min_policies' => env('INTELLIGENCE_GOVERNANCE_MIN_POLICIES', Thresholds::DEFAULTS['governance_min_policies']),   // PROVISIONAL
        'agentic_window_days' => env('INTELLIGENCE_AGENTIC_WINDOW_DAYS', Thresholds::DEFAULTS['agentic_window_days']),   // PROVISIONAL
        'agentic_min_runs' => env('INTELLIGENCE_AGENTIC_MIN_RUNS', Thresholds::DEFAULTS['agentic_min_runs']),   // PROVISIONAL
        'agentic_failure_pct' => env('INTELLIGENCE_AGENTIC_FAILURE_PCT', Thresholds::DEFAULTS['agentic_failure_pct']),   // PROVISIONAL
        'agentic_stalled_hours' => env('INTELLIGENCE_AGENTIC_STALLED_HOURS', Thresholds::DEFAULTS['agentic_stalled_hours']),   // PROVISIONAL
        'agentic_min_agents' => env('INTELLIGENCE_AGENTIC_MIN_AGENTS', Thresholds::DEFAULTS['agentic_min_agents']),   // PROVISIONAL
        'agentic_cost_rise_pct' => env('INTELLIGENCE_AGENTIC_COST_RISE_PCT', Thresholds::DEFAULTS['agentic_cost_rise_pct']),   // PROVISIONAL
        'cross_learning_min_eligible' => env('INTELLIGENCE_CROSS_LEARNING_MIN_ELIGIBLE', Thresholds::DEFAULTS['cross_learning_min_eligible']),   // PROVISIONAL
        'cross_renewal_min_linked' => env('INTELLIGENCE_CROSS_RENEWAL_MIN_LINKED', Thresholds::DEFAULTS['cross_renewal_min_linked']),   // PROVISIONAL
        'cross_attention_pct' => env('INTELLIGENCE_CROSS_ATTENTION_PCT', Thresholds::DEFAULTS['cross_attention_pct']),   // PROVISIONAL
    ],
];
