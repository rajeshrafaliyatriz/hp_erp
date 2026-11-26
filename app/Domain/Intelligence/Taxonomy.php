<?php

namespace App\Domain\Intelligence;

/**
 * The closed vocabularies of G2G Intelligence. One place, so the backend, the API contract and the frontend model cannot drift.
 *
 * CONFIDENCE is not a probability. It says WHAT KIND of statement an insight is:
 *   measured    - a count/date read straight from records ("14 certifications lapse in 30 days").
 *   rule_based  - a deterministic rule applied to records ("a deal with no activity for 14 days is flagged quiet").
 *   ai_estimate - a model's opinion. Phase 1 detectors NEVER emit this; it exists so nothing can be mislabelled later.
 * An AI estimate must never be shown as measured: the frontend tones differ and the constructor of Insight refuses unknown values.
 */
final class Taxonomy
{
    public const DOMAIN_HR = 'hr';
    public const DOMAIN_COMMERCIAL = 'commercial';
    /** Platform services, AI governance and the Agentic AI runtime (the operator's view of the estate; organisation data, never people data). */
    public const DOMAIN_PLATFORM = 'platform';
    /** Cross-functional: a POSSIBLE RELATIONSHIP between two existing detectors' sources, joined only on a key verified in the schema. Never a cause. */
    public const DOMAIN_CROSS = 'cross';
    public const DOMAINS = [self::DOMAIN_HR, self::DOMAIN_COMMERCIAL, self::DOMAIN_PLATFORM, self::DOMAIN_CROSS];

    /** Ordered least to most urgent. */
    public const SEVERITIES = ['info', 'attention', 'high', 'critical'];

    public const CONFIDENCE_MEASURED = 'measured';
    public const CONFIDENCE_RULE_BASED = 'rule_based';
    public const CONFIDENCE_AI_ESTIMATE = 'ai_estimate';
    public const CONFIDENCES = [self::CONFIDENCE_MEASURED, self::CONFIDENCE_RULE_BASED, self::CONFIDENCE_AI_ESTIMATE];

    /** Whose data an insight is drawn from; decided by the CALLER's role, never by the request. */
    public const AUDIENCES = ['self', 'team', 'department', 'organization'];

    /** Lifecycle. Phase 1 computes `open` only: persisting dismiss/snooze needs a table, which is deferred (see the deferred-approvals report). */
    public const STATUS_OPEN = 'open';
    public const STATUSES = [self::STATUS_OPEN];

    /**
     * DETECTOR STATE VOCABULARY (API contract; the frontend uses the same strings). Exactly one per detector per request:
     *
     *   ok                - the detector ran and had enough data. `insight_count` is a real number; zero means "checked, nothing needs attention".
     *   insufficient_data - the detector ran but its PROVISIONAL minimum of records was not met. Coverage and a reason are present. Not a verdict.
     *   not_applicable    - nothing relevant to THIS caller (for example an employee with no tasks assigned). The source exists and is readable.
     *   data_unavailable  - the SOURCE is not present or not readable on this installation (a table or column is absent). Nothing was assessed. Distinct from
     *                       `error`: the installation lacks the feature or its data; nothing failed.
     *   access_restricted - the caller is not authorised for this detector. The entry carries the id, domain, title and a fixed reason ONLY: no counts, no
     *                       coverage, and the detector never ran, so no data was read for it.
     *   error             - the detector threw while running. The reason is a fixed sentence (never SQL, table names or a trace). An error is NEVER a count
     *                       of zero: `insight_count` is null.
     *
     * `insight_count` in the API is an integer for ok / insufficient_data / not_applicable and null for data_unavailable / access_restricted / error.
     * `meta.states` counts every state (zero-filled), so the screen can say how many detectors fell into each.
     */
    public const STATE_OK = 'ok';
    public const STATE_INSUFFICIENT = 'insufficient_data';
    public const STATE_NOT_APPLICABLE = 'not_applicable';
    public const STATE_DATA_UNAVAILABLE = 'data_unavailable';
    public const STATE_ACCESS_RESTRICTED = 'access_restricted';
    public const STATE_ERROR = 'error';
    public const STATES = [
        self::STATE_OK, self::STATE_INSUFFICIENT, self::STATE_NOT_APPLICABLE, self::STATE_DATA_UNAVAILABLE, self::STATE_ACCESS_RESTRICTED, self::STATE_ERROR,
    ];

    /**
     * Surfaces: where an insight may be shown in context. A detector lists the surfaces it is relevant to; the API filters on `surface`.
     * The frontend uses the same strings.
     */
    public const SURFACE_HR_DASHBOARD = 'hr_dashboard';
    public const SURFACE_ME_DASHBOARD = 'me_dashboard';
    public const SURFACE_ATTENDANCE = 'attendance';
    public const SURFACE_LEAVE = 'leave';
    public const SURFACE_CERTIFICATIONS = 'certifications';
    public const SURFACE_RECRUITMENT = 'recruitment';
    public const SURFACE_ONBOARDING = 'onboarding';
    public const SURFACE_SIGNALS = 'signals';
    public const SURFACE_SIGNAL_REVIEW = 'signal_review';
    public const SURFACE_GTM_COMMAND_CENTER = 'gtm_command_center';
    public const SURFACE_GTM_DEALS = 'gtm_deals';
    public const SURFACE_GTM_CUSTOMERS = 'gtm_customers';
    public const SURFACE_CRM_OPPORTUNITIES = 'crm_opportunities';
    public const SURFACE_CRM_INVOICES = 'crm_invoices';
    public const SURFACE_ORGANISATION = 'organisation';
    public const SURFACE_TASKS = 'tasks';
    public const SURFACE_LEARNING = 'learning';
    public const SURFACE_CAPABILITY = 'capability';
    public const SURFACE_PLATFORM_SCHEDULER = 'platform_scheduler';
    public const SURFACE_PLATFORM_INTEGRATIONS = 'platform_integrations';
    public const SURFACE_PLATFORM_EVENT_BUS = 'platform_event_bus';
    public const SURFACE_AI_USAGE_COST = 'ai_usage_cost';
    public const SURFACE_AI_POLICIES = 'ai_policies';
    public const SURFACE_AGENTIC_RUN_LOG = 'agentic_run_log';

    public const SURFACES = [
        self::SURFACE_HR_DASHBOARD, self::SURFACE_ME_DASHBOARD, self::SURFACE_ATTENDANCE, self::SURFACE_LEAVE, self::SURFACE_CERTIFICATIONS,
        self::SURFACE_RECRUITMENT, self::SURFACE_ONBOARDING, self::SURFACE_SIGNALS, self::SURFACE_SIGNAL_REVIEW, self::SURFACE_GTM_COMMAND_CENTER,
        self::SURFACE_GTM_DEALS, self::SURFACE_GTM_CUSTOMERS, self::SURFACE_CRM_OPPORTUNITIES, self::SURFACE_CRM_INVOICES,
        self::SURFACE_ORGANISATION, self::SURFACE_TASKS, self::SURFACE_LEARNING, self::SURFACE_CAPABILITY,
        self::SURFACE_PLATFORM_SCHEDULER, self::SURFACE_PLATFORM_INTEGRATIONS, self::SURFACE_PLATFORM_EVENT_BUS, self::SURFACE_AI_USAGE_COST,
        self::SURFACE_AI_POLICIES, self::SURFACE_AGENTIC_RUN_LOG,
    ];

    public static function severityRank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITIES, true);

        return $rank === false ? -1 : (int) $rank;
    }
}
