<?php

namespace App\Domain\Gtm;

use App\Domain\Gtm\Customers\CustomerService;
use App\Domain\Gtm\Forecast\ForecastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything GTM does on a clock, in one place, driven by ONE command (`gtm:tick`, every five minutes,
 * registered in routes/console.php inside the platform's TaskRunLedger so its runs are recorded).
 *
 *  1. Recover emails stuck in `sending` (a crash mid-send). They become `failed` and are NEVER resent
 *     automatically: whether the mail was delivered is unknown, so a person checks first.
 *  2. Send what is due: approved messages whose scheduled time has come, through OutreachMailer (every gate,
 *     atomic claim), with bounded retries. Off unless GTM_OUTREACH_SCHEDULER_ENABLED.
 *  3. Retry workflow runs that failed transiently (hook for the workflow engine).
 *  4. Once a day: forecast and customer-health snapshots, renewal/customer alert events, deal-flag events.
 *
 * A pass never throws: each part is wrapped so one failure cannot stop the others, and the result (including
 * any error text) is stored as the heartbeat the monitoring screen reads.
 */
final class GtmScheduler
{
    public function __construct(private readonly OutreachMailer $mailer)
    {
    }

    /** @return array<string, mixed> */
    public function tick(bool $forceDaily = false): array
    {
        $summary = ['at' => now()->toDateTimeString(), 'stale_recovered' => 0, 'send' => null, 'workflows' => null, 'daily' => null, 'errors' => []];
        foreach (['stale_recovered' => fn () => $this->recoverStale(), 'send' => fn () => $this->sendDue(), 'workflows' => fn () => $this->retryWorkflows(), 'daily' => fn () => $this->daily($forceDaily)] as $part => $fn) {
            try {
                $summary[$part] = $fn();
            } catch (\Throwable $e) {
                report($e);
                $summary['errors'][] = "{$part}: ".mb_substr($e->getMessage(), 0, 200);
                Log::error('GTM tick part failed', ['part' => $part, 'error' => $e->getMessage()]);
            }
        }
        GtmState::set('last_tick_at', now()->toDateTimeString());
        GtmState::set('last_tick_summary', json_encode($summary));

        return $summary;
    }

    public function recoverStale(): int
    {
        $minutes = (int) config('gtm.outreach.stale_sending_minutes', 15);
        $stuck = GtmOutreachMessage::where('status', 'sending')->where('updated_at', '<', now()->subMinutes($minutes))->get();
        foreach ($stuck as $m) {
            $error = "Interrupted while sending: it is not known whether this email was delivered. Check the mailbox before sending it again.";
            DB::table('gtm_outreach_messages')->where('id', $m->id)->where('status', 'sending')->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
            DB::table('gtm_outreach_attempts')->insert(['sub_institute_id' => $m->sub_institute_id, 'message_id' => $m->id, 'attempt' => max(1, (int) $m->attempts), 'outcome' => 'failed', 'code' => 'interrupted', 'detail' => $error, 'scheduled' => true, 'created_at' => now()]);
            GtmAudit::record('gtm.outreach.send_failed', (int) $m->sub_institute_id, 'gtm_outreach_messages', $m->id, null, ['error' => $error, 'kind' => 'interrupted', 'will_retry' => false]);
        }

        return $stuck->count();
    }

    /** @return array<string, mixed> */
    public function sendDue(): array
    {
        if (! config('gtm.outreach.scheduler_enabled')) {
            return ['skipped' => 'scheduler disabled (GTM_OUTREACH_SCHEDULER_ENABLED)'];
        }
        if (! config('gtm.outreach.sending_enabled')) {
            return ['skipped' => 'sending disabled (GTM_OUTREACH_SENDING_ENABLED)'];
        }
        $due = GtmOutreachMessage::where('status', 'approved')->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())
            ->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('scheduled_for')->orderBy('id')->limit((int) config('gtm.outreach.batch_size', 25))->get();

        $r = ['due' => $due->count(), 'sent' => 0, 'retry_later' => 0, 'failed' => 0, 'cancelled' => 0, 'deferred' => 0, 'halted' => null];
        $haltedTenants = [];
        foreach ($due as $m) {
            if (isset($haltedTenants[$m->sub_institute_id])) {
                $r['deferred']++;

                continue;
            }
            $res = $this->mailer->send($m, null, true);
            if ($res['ok']) {
                $r['sent']++;

                continue;
            }
            switch ($res['code']) {
                case 'contact_blocked':
                case 'suppressed':
                case 'no_recipient':
                case 'reply_recorded':
                    $this->close($m, 'cancelled', "Not sent: {$res['message']}");
                    $r['cancelled']++;
                    break;
                case 'content_changed':
                    $this->close($m, 'failed', $res['message']);
                    $r['failed']++;
                    break;
                case 'sequence_order':
                    DB::table('gtm_outreach_messages')->where('id', $m->id)->update(['next_attempt_at' => now()->addMinutes(10)]);
                    $r['deferred']++;
                    break;
                case 'daily_cap':
                    $haltedTenants[$m->sub_institute_id] = true;
                    $r['deferred']++;
                    break;
                case 'send_failed':
                    if (($res['kind'] ?? '') === 'auth') {
                        $r['halted'] = 'The mail server refused our credentials; no more sends this pass.';
                        break 2;
                    }
                    ($res['kind'] ?? '') === 'rate_limited' && $haltedTenants[$m->sub_institute_id] = true;
                    ! empty($res['retry_at']) ? $r['retry_later']++ : $r['failed']++;
                    break;
                case 'mail_disabled':
                case 'sending_disabled':
                case 'unsubscribe_unreachable':
                case 'mailer_not_delivering':
                    $r['halted'] = $res['message'];
                    break 2;
                default: // not_approved / already_claimed: another process got there first
                    break;
            }
        }

        return $r;
    }

    /** @return array<string, mixed> */
    public function retryWorkflows(): array
    {
        return class_exists(\App\Domain\Gtm\Workflows\WorkflowEngine::class) ? app(\App\Domain\Gtm\Workflows\WorkflowEngine::class)->retryDue() : ['skipped' => 'workflow engine not installed'];
    }

    /** @return array<string, mixed> */
    public function daily(bool $force = false): array
    {
        $today = now()->toDateString();
        if (! $force && GtmState::get('last_daily_date') === $today) {
            return ['skipped' => 'already ran today'];
        }
        $out = ['tenants' => 0, 'forecast_rows' => 0, 'health_rows' => 0, 'renewal_events' => 0, 'customer_events' => 0, 'deal_flag_events' => 0];
        foreach (DB::table('gtm_accounts')->whereNull('deleted_at')->distinct()->pluck('sub_institute_id') as $tenant) {
            $tenant = (int) $tenant;
            $out['tenants']++;
            $out['forecast_rows'] += app(ForecastService::class)->snapshot($tenant);
            $out['health_rows'] += app(CustomerService::class)->snapshot($tenant);
            $s = app(CustomerService::class)->sweep($tenant);
            $out['renewal_events'] += $s['renewal_events'];
            $out['customer_events'] += $s['customer_events'];
            $out['deal_flag_events'] += app(DealSweep::class)->run($tenant);
        }
        GtmState::set('last_daily_date', $today);

        return $out;
    }

    private function close(GtmOutreachMessage $m, string $status, string $note): void
    {
        DB::table('gtm_outreach_messages')->where('id', $m->id)->update(['status' => $status, 'decision_note' => mb_substr($note, 0, 500), 'error' => $status === 'failed' ? mb_substr($note, 0, 400) : null, 'scheduled_for' => null, 'updated_at' => now()]);
    }
}
