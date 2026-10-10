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
        'allow_local_unsubscribe' => filter_var(env('GTM_OUTREACH_ALLOW_LOCAL_UNSUBSCRIBE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'import' => [
        'max_rows' => (int) env('GTM_IMPORT_MAX_ROWS', 2000),
        'max_bytes' => (int) env('GTM_IMPORT_MAX_BYTES', 1500000),
    ],
];
