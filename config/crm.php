<?php

/*
 * CRM Emails tunables - the real-external-send safety gate for the CRM
 * "Emails" gap area (compose/send/log against Lead/Contact/Organization/
 * Opportunity/Ticket). Modeled directly on config/gtm.php's own 'outreach'
 * block and App\Domain\Gtm\OutreachMailer, which this feature's own
 * App\Services\Crm\CrmOutreachMailer is itself modeled on - same shape of
 * risk (real external recipients), same shape of gate.
 */
return [
    // Master switch for real CRM email dispatch. OFF unless explicitly enabled,
    // and it sits IN ADDITION to App\Support\MailGate (the platform-wide
    // outbound-mail gate), never instead. While this is false,
    // CrmEmailController::compose() still fully validates the request and
    // writes the crm_emails row as 'Queued' - it simply never hands that row
    // to CrmOutreachMailer::send(). A 'Queued' row with sending disabled is a
    // fully valid, intentional end state, not a bug.
    'email_sending_enabled' => filter_var(env('CRM_EMAIL_SENDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Emails one organisation may send per day through this feature. A
    // ceiling, not a target.
    'email_daily_cap' => (int) env('CRM_EMAIL_DAILY_CAP', 50),

    // Every email carries a signed one-click unsubscribe link (crm.email.unsubscribe).
    // A link built from a local/private APP_URL cannot work for a real recipient,
    // so sending is refused while APP_URL is local unless this is set (for
    // sending to yourself while testing) - same reasoning as
    // GTM_OUTREACH_ALLOW_LOCAL_UNSUBSCRIBE.
    'email_allow_local_unsubscribe' => filter_var(env('CRM_EMAIL_ALLOW_LOCAL_UNSUBSCRIBE', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Sales & Marketing gap-closure, Wave 5: list-based/campaign sending.
     * `email_daily_cap` above is already the real, binding ceiling (every
     * send - one-off or campaign - shares that one per-tenant-per-day
     * count), so this is only a defensive backstop against one oversized
     * synchronous request (a campaign with thousands of targets looping
     * through thousands of DB inserts in one HTTP call), never the number a
     * real send is expected to reach. CrmCampaignController::send() reports
     * plainly when a campaign has more targets than this and needs a second
     * "Send" click to continue - never a silent partial send.
     */
    'campaign_send_batch_limit' => (int) env('CRM_CAMPAIGN_SEND_BATCH_LIMIT', 200),
];
