<?php

/*
 * GTM tunables. Every value is an organisation-neutral default for a RULE (when is a deal
 * "quiet"?), never a business figure. Override per deployment in .env.
 */
return [
    'deals' => [
        // A deal with no logged activity for this many days is flagged as quiet.
        'quiet_after_days' => (int) env('GTM_DEAL_QUIET_DAYS', 14),
        // An open deal that has sat in one stage this long is flagged as stuck.
        'stuck_in_stage_days' => (int) env('GTM_DEAL_STUCK_DAYS', 30),
    ],

    'outreach' => [
        // Master switch for GTM outreach sending. OFF unless explicitly enabled, and it sits
        // IN ADDITION to App\Support\MailGate (the platform-wide outbound-mail gate), never instead.
        'sending_enabled' => filter_var(env('GTM_OUTREACH_SENDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Emails one organisation may send per day through GTM. A ceiling, not a target.
        'daily_cap' => (int) env('GTM_OUTREACH_DAILY_CAP', 50),
        // When true the person who approved a message must differ from the person who wrote it.
        'require_second_approver' => filter_var(env('GTM_OUTREACH_REQUIRE_SECOND_APPROVER', false), FILTER_VALIDATE_BOOLEAN),
        // Every email carries a signed one-click unsubscribe link. A link to localhost cannot work
        // for a real recipient, so sending is refused while APP_URL is local unless this is set
        // (for sending to yourself while testing).
        // Scheduled sending: the scheduler may send APPROVED messages whose send time has come. OFF unless set, and
        // it needs sending_enabled as well - two switches, both explicit.
        'scheduler_enabled' => filter_var(env('GTM_OUTREACH_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'batch_size' => (int) env('GTM_OUTREACH_BATCH_SIZE', 25),
        // A message stuck in `sending` this long is marked failed (never resent automatically).
        'stale_sending_minutes' => (int) env('GTM_OUTREACH_STALE_MINUTES', 15),
        'allow_local_unsubscribe' => filter_var(env('GTM_OUTREACH_ALLOW_LOCAL_UNSUBSCRIBE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'forecast' => [
        // Closed deals needed before a historical win rate is offered as a SUGGESTION for a stage.
        'min_history_deals' => (int) env('GTM_FORECAST_MIN_HISTORY', 20),
    ],

    'health' => [
        // Customer health bands: a score at/above healthy_at is healthy, at/above watch_at is watch, below is at_risk.
        'healthy_at' => (int) env('GTM_HEALTH_HEALTHY_AT', 70),
        'watch_at' => (int) env('GTM_HEALTH_WATCH_AT', 40),
        // No activity for this many days is listed as a risk.
        'quiet_after_days' => (int) env('GTM_HEALTH_QUIET_DAYS', 60),
        // A renewal this many days away (or overdue) that is not yet being worked raises an alert.
        'renewal_alert_days' => (int) env('GTM_RENEWAL_ALERT_DAYS', 60),
    ],

    'inbound' => [
        // Shared secret for the reply/bounce webhook (HMAC-SHA256). Unset = the endpoint refuses everything.
        'secret' => env('GTM_INBOUND_WEBHOOK_SECRET', ''),
    ],

    'workflows' => [
        // When true, the administrator who created a workflow cannot approve its runs; a different one must.
        'require_second_approver' => filter_var(env('GTM_WORKFLOW_REQUIRE_SECOND_APPROVER', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'import' => [
        'max_rows' => (int) env('GTM_IMPORT_MAX_ROWS', 2000),
        'max_bytes' => (int) env('GTM_IMPORT_MAX_BYTES', 1500000),
    ],
];
