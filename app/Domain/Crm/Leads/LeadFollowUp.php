<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\Workflows\WorkflowCatalog;
use App\Domain\Gtm\Workflows\WorkflowContext;
use App\Services\Crm\CrmEmailQueue;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The lead follow-up cadence: send an email, WAIT, and either stop (a reason) or send the next one.
 *
 * WAITING costs nothing. A lead in a cadence is one row in crm_lead_followups with a `next_due_at`; no agent or process runs
 * while it waits. The scheduler's tick (GtmScheduler::tick -> processDue) looks only at rows whose time has come.
 *
 * STOP CONDITIONS are re-checked against the live lead immediately before EVERY send, never trusted from when the cadence
 * started: the lead was converted / removed / marked Lost or Junk, the lead (or the organisation, via the mailer) opted out of
 * email, a person is already working it (status Contacted, Pre Qualified, Qualified), it has no valid email address, or the
 * customer replied (stopFor(), called by the reply handler). Once stopped a cadence never restarts.
 *
 * NEVER A DUPLICATE. UNIQUE(lead_id) means one cadence per lead; `locked_until` is an atomic claim, so overlapping ticks cannot
 * both send an attempt; an attempt is only prepared when the previous email is not still waiting to be sent.
 *
 * SENDING stays behind the existing gates. Every email is created through CrmEmailQueue (a Queued crm_emails row) and delivered
 * only by CrmOutreachMailer, which refuses unless CRM_EMAIL_SENDING_ENABLED and every other gate hold. When sending is off the
 * cadence PAUSES with that reason (and tells the owner) rather than pretending an email went out or piling up drafts.
 */
final class LeadFollowUp
{
    /** the lead is finished with: no automatic email, ever */
    public const CLOSED = ['lost lead', 'junk lead'];

    /** a person is already working it: automation steps back */
    public const ENGAGED = ['contacted', 'pre qualified', 'qualified'];

    public const WAITING_STATUS = 'Attempted to Contact';

    /** refusals that mean "not now" - the same Queued email is retried once the cause is fixed */
    private const RETRYABLE = ['sending_disabled', 'mail_disabled', 'unsubscribe_unreachable', 'mailer_not_delivering', 'daily_cap'];

    /**
     * Why this lead may not be emailed automatically, or null when it may.
     */
    public static function ineligible(?object $lead): ?string
    {
        if (! $lead || $lead->deleted_at !== null) {
            return 'lead_removed';
        }
        if ($lead->converted) {
            return 'converted';
        }
        if ($lead->email_opt_out) {
            return 'opted_out';
        }
        $status = strtolower(trim((string) $lead->lead_status));
        if (in_array($status, self::CLOSED, true)) {
            return 'lead_closed';
        }
        if (in_array($status, self::ENGAGED, true)) {
            return 'lead_engaged';
        }
        if (! filter_var(trim((string) $lead->email), FILTER_VALIDATE_EMAIL)) {
            return 'no_email';
        }

        return null;
    }

    /**
     * Put a lead into a cadence and run its first attempt when business hours allow.
     *
     * @param  array{subject: string, body: string, intervals: string, max_attempts: int, window_start: string, window_end: string, timezone: string, weekdays_only: bool}  $cfg
     * @return array{started: bool, reason?: string, followup_id?: int, outcome?: string}
     */
    public static function start(int $tenant, object $lead, array $cfg, ?int $actorId, ?int $workflowId, ?int $runId): array
    {
        if ($why = self::ineligible($lead)) {
            return ['started' => false, 'reason' => $why];
        }
        $intervals = FollowUpSchedule::parseIntervals($cfg['intervals']);
        $due = FollowUpSchedule::nextSlot(now(), 0, $cfg['timezone'], $cfg['window_start'], $cfg['window_end'], $cfg['weekdays_only'])->setTimezone(config('app.timezone'));
        $created = DB::table('crm_lead_followups')->insertOrIgnore([
            'sub_institute_id' => $tenant, 'lead_id' => (int) $lead->id, 'workflow_id' => $workflowId, 'workflow_run_id' => $runId, 'state' => 'active',
            'attempts' => 0, 'max_attempts' => $cfg['max_attempts'], 'intervals' => implode(',', $intervals), 'window_start' => $cfg['window_start'], 'window_end' => $cfg['window_end'],
            'timezone' => $cfg['timezone'], 'weekdays_only' => $cfg['weekdays_only'] ? 1 : 0, 'subject_template' => $cfg['subject'], 'body_template' => $cfg['body'],
            'next_due_at' => $due->format('Y-m-d H:i:s'), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('crm_lead_followups')->where('lead_id', $lead->id)->first();
        if ($created === 0) {
            return ['started' => false, 'reason' => 'already_in_cadence', 'followup_id' => (int) $row->id];
        }
        LeadActivity::note($tenant, (int) $lead->id, sprintf('Follow-up started: up to %d email(s), waits of %s day(s), sent %s-%s %s%s. First email due %s.',
            $cfg['max_attempts'], implode('/', $intervals), $cfg['window_start'], $cfg['window_end'], $cfg['timezone'], $cfg['weekdays_only'] ? ', weekdays only' : '', $due->format('Y-m-d H:i')));
        $outcome = $due->lessThanOrEqualTo(now()) ? self::attemptById((int) $row->id) : 'waiting_for_business_hours';

        return ['started' => true, 'followup_id' => (int) $row->id, 'outcome' => $outcome];
    }

    /**
     * One scheduler pass: every cadence whose next attempt is due, plus paused ones that can resume.
     *
     * @return array<string, int> outcome => count
     */
    public static function processDue(int $limit = 50, ?int $tenant = null): array
    {
        $out = [];
        $ids = DB::table('crm_lead_followups')->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))->where('state', 'active')->whereNotNull('next_due_at')->where('next_due_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))->orderBy('next_due_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $r = self::attemptById((int) $id);
            $out[$r] = ($out[$r] ?? 0) + 1;
        }
        foreach (self::resumePaused($limit, null, $tenant) as $k => $n) {
            $out[$k] = ($out[$k] ?? 0) + $n;
        }

        return $out;
    }

    /**
     * One pass over ONE lead's cadence only (for hand testing and support): resume it if it is paused and can move, or run its next attempt if it is due.
     * Never touches any other lead.
     *
     * @return array<string, int>
     */
    public static function processLead(int $leadId): array
    {
        $out = self::resumePaused(1, $leadId);
        $f = DB::table('crm_lead_followups')->where('lead_id', $leadId)->where('state', 'active')->whereNotNull('next_due_at')->where('next_due_at', '<=', now())->first();
        if ($f) {
            $r = self::attemptById((int) $f->id);
            $out[$r] = ($out[$r] ?? 0) + 1;
        }

        return $out;
    }

    /** Stop a lead's cadence from outside (the customer replied, a person took over). True when a running cadence was stopped. */
    public static function stopFor(int $tenant, int $leadId, string $reason, string $state = 'stopped', ?string $note = null): bool
    {
        $n = DB::table('crm_lead_followups')->where('sub_institute_id', $tenant)->where('lead_id', $leadId)->whereIn('state', ['active', 'paused'])
            ->update(['state' => $state, 'stop_reason' => $reason, 'next_due_at' => null, 'locked_until' => null, 'updated_at' => now()]);
        if ($n === 1 && $note !== null) {
            LeadActivity::note($tenant, $leadId, $note);
        }

        return $n === 1;
    }

    /** Claim a due row atomically, run one attempt, release. @return string outcome */
    public static function attemptById(int $id): string
    {
        $claimed = DB::table('crm_lead_followups')->where('id', $id)->where('state', 'active')->where('next_due_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['locked_until' => now()->addMinutes(5), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 'not_claimed';
        }
        try {
            return self::attempt(DB::table('crm_lead_followups')->find($id));
        } catch (\Throwable $e) {
            report($e);
            self::pause(DB::table('crm_lead_followups')->find($id), 'error', 'The follow-up hit an unexpected error and was paused: '.mb_substr($e->getMessage(), 0, 200));

            return 'error';
        } finally {
            DB::table('crm_lead_followups')->where('id', $id)->update(['locked_until' => null]);
        }
    }

    private static function attempt(object $f): string
    {
        $tenant = (int) $f->sub_institute_id;
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->find($f->lead_id);
        if ($why = self::ineligible($lead)) {
            self::stop($f, $why);

            return 'stopped_'.$why;
        }
        if ((int) $f->attempts >= (int) $f->max_attempts) {
            return self::complete($f, $lead);
        }
        if ($f->last_email_id && DB::table('crm_emails')->where('id', $f->last_email_id)->value('status') !== 'Sent') {
            self::pause($f, 'email_not_sent', 'The previous follow-up email has not been sent yet, so the next one was not prepared.');

            return 'paused_email_not_sent';
        }
        $values = WorkflowContext::load($tenant, 'crm.lead.created', 'crm_leads', (int) $lead->id, [])['values'] ?? [];
        $subject = trim(WorkflowCatalog::render((string) $f->subject_template, $values));
        $body = trim(WorkflowCatalog::render((string) $f->body_template, $values));
        if ($subject === '' || $body === '') {
            self::pause($f, 'empty_message', 'The follow-up email would be empty once the lead\'s details are filled in, so nothing was prepared.');

            return 'paused_empty_message';
        }
        $emailId = CrmEmailQueue::create($tenant, 'LEAD', (int) $lead->id, trim((string) $lead->email), $subject, $body, (int) $lead->assigned_to, $f->created_by ? (int) $f->created_by : null);
        DB::table('crm_lead_followups')->where('id', $f->id)->update(['attempts' => DB::raw('attempts + 1'), 'last_email_id' => $emailId, 'updated_at' => now()]);

        return self::finishSend(DB::table('crm_lead_followups')->find($f->id), $lead, $emailId);
    }

    /** Deliver one prepared email and record what really happened. @return string outcome */
    private static function finishSend(object $f, object $lead, int $emailId): string
    {
        $r = CrmEmailQueue::deliver($emailId, 'LEAD', (int) $lead->id, $f->created_by ? (int) $f->created_by : null);
        if ($r['ok']) {
            self::afterSent($f, $lead);

            return 'sent';
        }
        if (in_array($r['code'], self::RETRYABLE, true)) {
            self::pause($f, $r['code'], 'Follow-up email '.$f->attempts.' is prepared but was NOT sent: '.$r['message'].' It stays Queued on the lead\'s Emails tab and is sent automatically once that is resolved.', true);

            return 'paused_'.$r['code'];
        }
        if ($r['code'] === 'recipient_blocked') {
            DB::table('crm_emails')->where('id', $emailId)->update(['status' => 'Failed', 'failure_reason' => 'Recipient has opted out of email.', 'updated_at' => now()]);
            self::stop($f, 'opted_out');

            return 'stopped_opted_out';
        }
        self::pause($f, 'send_failed', 'Follow-up email '.$f->attempts.' could not be sent: '.$r['message'].' It was not retried automatically.', true);

        return 'paused_send_failed';
    }

    /** The email is out: record it, move a fresh lead to Attempted to Contact, and schedule the next attempt (or the final check). */
    private static function afterSent(object $f, object $lead): void
    {
        $tenant = (int) $f->sub_institute_id;
        $wait = FollowUpSchedule::waitAfter(FollowUpSchedule::parseIntervals((string) $f->intervals), (int) $f->attempts);
        $next = FollowUpSchedule::nextSlot(now(), $wait, $f->timezone, $f->window_start, $f->window_end, (bool) $f->weekdays_only)->setTimezone(config('app.timezone'));
        DB::table('crm_lead_followups')->where('id', $f->id)->update(['state' => 'active', 'stop_reason' => null, 'last_sent_at' => now(), 'next_due_at' => $next->format('Y-m-d H:i:s'), 'updated_at' => now()]);
        $last = (int) $f->attempts >= (int) $f->max_attempts;
        LeadActivity::note($tenant, (int) $lead->id, sprintf('Follow-up email %d of %d sent. %s', $f->attempts, $f->max_attempts,
            $last ? 'That was the last one; if there is no reply by '.$next->format('Y-m-d H:i').' the owner is asked to decide.' : 'Next one is due '.$next->format('Y-m-d H:i').' unless the customer replies.'));
        if (in_array(strtolower(trim((string) $lead->lead_status)), ['', 'new', 'not contacted'], true)) {
            LeadActivity::setStatus($tenant, $lead, self::WAITING_STATUS, ['source' => 'workflow', 'followup_id' => (int) $f->id]);
        }
    }

    /** Out of attempts and still no reply: ask the owner to decide. Nothing is marked Lost automatically. */
    private static function complete(object $f, object $lead): string
    {
        $tenant = (int) $f->sub_institute_id;
        DB::table('crm_lead_followups')->where('id', $f->id)->update(['state' => 'completed', 'stop_reason' => 'no_reply', 'next_due_at' => null, 'updated_at' => now()]);
        LeadActivity::note($tenant, (int) $lead->id, "No reply after {$f->attempts} follow-up email(s). Automatic follow-up has ended; the lead was not changed.");
        LeadActivity::task($tenant, $lead, "No reply after {$f->attempts} follow-ups: keep nurturing or mark Lost? ({$lead->lead_no})", 'The automatic follow-up cadence has finished without a reply. Decide the next step; the lead was not marked Lost for you.', 0, null);

        return 'completed_no_reply';
    }

    private static function stop(object $f, string $reason): void
    {
        $text = [
            'converted' => 'the lead was converted', 'lead_closed' => 'the lead was marked Lost/Junk', 'lead_engaged' => 'a person is already working the lead (status Contacted / Pre Qualified / Qualified)',
            'opted_out' => 'the lead opted out of email', 'no_email' => 'the lead has no valid email address', 'lead_removed' => 'the lead no longer exists',
        ][$reason] ?? $reason;
        $changed = DB::table('crm_lead_followups')->where('id', $f->id)->whereIn('state', ['active', 'paused'])
            ->update(['state' => 'stopped', 'stop_reason' => $reason, 'next_due_at' => null, 'locked_until' => null, 'updated_at' => now()]);
        if ($changed && $reason !== 'lead_removed') {
            LeadActivity::note((int) $f->sub_institute_id, (int) $f->lead_id, "Automatic follow-up stopped: {$text}.");
        }
    }

    private static function pause(?object $f, string $reason, string $why, bool $withTask = false): void
    {
        if (! $f) {
            return;
        }
        $already = $f->state === 'paused' && $f->stop_reason === $reason;
        DB::table('crm_lead_followups')->where('id', $f->id)->update(['state' => 'paused', 'stop_reason' => $reason, 'locked_until' => null, 'updated_at' => now()]);
        if ($already) {
            return;
        }
        $tenant = (int) $f->sub_institute_id;
        LeadActivity::note($tenant, (int) $f->lead_id, "Automatic follow-up paused ({$reason}). {$why}");
        $lead = DB::table('crm_leads')->where('sub_institute_id', $tenant)->find($f->lead_id);
        if ($withTask && $lead) {
            LeadActivity::task($tenant, $lead, "Follow-up email waiting to be sent ({$lead->lead_no})", $why, 0, null);
        }
    }

    /**
     * Paused cadences that can move again: the owner sent the prepared email by hand (it is now Sent), or the cause of a
     * "not now" refusal is gone and the same Queued email can be handed to the mailer again.
     *
     * @return array<string, int>
     */
    private static function resumePaused(int $limit, ?int $onlyLeadId = null, ?int $tenant = null): array
    {
        $out = [];
        $rows = DB::table('crm_lead_followups')->where('state', 'paused')->whereNotNull('last_email_id')->when($onlyLeadId !== null, fn ($q) => $q->where('lead_id', $onlyLeadId))->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))->orderBy('updated_at')->limit($limit)->get();
        foreach ($rows as $f) {
            $claim = DB::table('crm_lead_followups')->where('id', $f->id)->where('state', 'paused')->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
                ->update(['locked_until' => now()->addMinutes(5)]);
            if ($claim !== 1) {
                continue;
            }
            try {
                $lead = DB::table('crm_leads')->where('sub_institute_id', $f->sub_institute_id)->find($f->lead_id);
                $email = DB::table('crm_emails')->where('id', $f->last_email_id)->first();
                if ($why = self::ineligible($lead)) {
                    self::stop($f, $why);
                    $r = 'stopped_'.$why;
                } elseif ($email && $email->status === 'Sent') {
                    self::afterSent($f, $lead);
                    $r = 'resumed_sent_by_hand';
                } elseif ($email && $email->status === 'Queued' && in_array($f->stop_reason, self::RETRYABLE, true) && config('crm.email_sending_enabled')) {
                    $r = 'retry_'.self::finishSend($f, $lead, (int) $email->id);
                } else {
                    $r = 'still_paused';
                }
                $out[$r] = ($out[$r] ?? 0) + 1;
            } finally {
                DB::table('crm_lead_followups')->where('id', $f->id)->update(['locked_until' => null]);
            }
        }

        return $out;
    }
}
