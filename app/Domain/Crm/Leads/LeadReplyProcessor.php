<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\GtmAudit;
use App\Services\Events\EventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns one inbound notification (a customer's reply, a bounce, a spam complaint) about an email we sent to a CRM LEAD into
 * the right changes. The CRM twin of App\Domain\Gtm\Outreach\ReplyBounceProcessor, with the same guarantees:
 *
 *  TRUST     The caller has already authenticated the sender (signed webhook). Even so the TENANT is never taken from the
 *            payload: it is the tenant of the email/lead that was matched, so a forged payload cannot touch another organisation.
 *  MATCHING  First by the Message-ID CrmOutreachMailer put on the outgoing email (In-Reply-To / References / original id).
 *            Failing that, by the lead's email address - but only when exactly ONE lead across all organisations has it;
 *            an ambiguous address is recorded as unmatched and changes nothing.
 *  IDEMPOTENT UNIQUE (provider, provider_event_id): a redelivered event reports "duplicate" and does nothing.
 *  LEDGER    Every event, matched or not, leaves a crm_inbound_events row with its outcome.
 *
 * EFFECTS
 *  reply          shown on the lead's Emails tab (inbound row) + a note; the follow-up cadence STOPS; crm.lead.replied is recorded
 *                 so workflows can react. Customer words asking for no more contact set the lead's email opt-out right away.
 *  auto-reply     out-of-office / auto-responder: recorded and noted, the cadence is NOT stopped and no workflow is triggered
 *  hard bounce    cadence stopped, task for the owner to fix the address (the third soft bounce in 30 days counts as hard)
 *  soft bounce    recorded only
 *  complaint      lead opted out of email, cadence stopped
 */
final class LeadReplyProcessor
{
    public const SOFT_BOUNCE_LIMIT = 3;

    /** words from the customer that mean "stop contacting me": honoured WITHOUT waiting for any AI */
    private const OPT_OUT = '/\b(unsubscribe|remove me|take me off|stop (e-?mailing|sending|contacting|messaging)|do not (e-?mail|contact|call)|don\'?t (e-?mail|contact|call)|no (further|more) (contact|e-?mails?|messages?)|opt[- ]?out)\b/i';

    private const AUTO_SUBJECT = '/^\s*(automatic reply|auto(matic)?[- ]?reply|autoreply|out of (the )?office|away from (the )?office|undeliverable|delivery (status|failure))/i';

    private ?int $inboundEmailId = null;

    /**
     * @param  array{provider: string, event_id: string, type: string, bounce_type?: ?string, in_reply_to?: ?string, references?: list<string>, original_message_id?: ?string, message_id?: ?string, from_email?: ?string, recipient?: ?string, subject?: ?string, text?: ?string, reason?: ?string, auto_reply?: ?bool, occurred_at?: ?string}  $n
     * @return array{status: string, inbound_id: int, detail: ?string, tenant?: ?int, lead_id?: ?int}
     */
    public function process(array $n): array
    {
        try {
            $id = (int) DB::table('crm_inbound_events')->insertGetId([
                'provider' => $n['provider'], 'provider_event_id' => $n['event_id'], 'type' => $n['type'], 'bounce_type' => $n['bounce_type'] ?? null, 'status' => 'unmatched',
                'payload' => mb_substr(json_encode($this->safePayload($n), JSON_UNESCAPED_UNICODE), 0, 60000), 'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            $existing = DB::table('crm_inbound_events')->where('provider', $n['provider'])->where('provider_event_id', $n['event_id'])->first(['id']);
            if ($existing) {
                return ['status' => 'duplicate', 'inbound_id' => (int) $existing->id, 'detail' => 'This event was already received.'];
            }
            throw $e;
        }

        try {
            [$email, $lead, $why] = $this->match($n);
            if (! $lead) {
                DB::table('crm_inbound_events')->where('id', $id)->update(['status' => 'unmatched', 'detail' => $why, 'processed_at' => now()]);

                return ['status' => 'unmatched', 'inbound_id' => $id, 'detail' => $why];
            }
            $tenant = (int) $lead->sub_institute_id;
            $this->inboundEmailId = null;
            $result = DB::transaction(fn () => match ($n['type']) {
                'reply' => $this->reply($tenant, $lead, $n, $id),
                'bounce' => $this->bounce($tenant, $lead, $n, $id),
                default => $this->complaint($tenant, $lead, $n),
            });
            DB::table('crm_inbound_events')->where('id', $id)->update([
                'sub_institute_id' => $tenant, 'lead_id' => $lead->id, 'email_id' => $email?->id, 'inbound_email_id' => $this->inboundEmailId,
                'status' => $result['status'], 'classification' => $result['classification'] ?? null, 'classified_at' => isset($result['classification']) ? now() : null,
                'detail' => mb_substr($result['detail'], 0, 500), 'processed_at' => now(),
            ]);
            if ($result['trigger']) {
                // Recorded AFTER the change committed, like every other event; the idempotency key means a retry cannot record it twice.
                GtmAudit::record('crm.lead.replied', $tenant, 'crm_leads', (int) $lead->id, null,
                    ['inbound_id' => $id, 'email_id' => $this->inboundEmailId, 'provider' => $n['provider'], 'classification' => $result['classification'] ?? null, 'lead_no' => $lead->lead_no, 'company' => $lead->company], null, "crm.lead.replied:{$id}");
            }

            return ['status' => 'processed', 'inbound_id' => $id, 'detail' => $result['detail'], 'tenant' => $tenant, 'lead_id' => (int) $lead->id];
        } catch (\Throwable $e) {
            DB::table('crm_inbound_events')->where('id', $id)->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500), 'processed_at' => now()]);
            report($e);

            return ['status' => 'error', 'inbound_id' => $id, 'detail' => 'The event was recorded but could not be applied.'];
        }
    }

    /** True when the customer's own words ask us to stop contacting them. */
    public static function asksToStop(string $text): bool
    {
        return (bool) preg_match(self::OPT_OUT, self::stripQuoted($text));
    }

    /** The customer's new words only: quoted history ("> ...", "On <date> ... wrote:") and a trailing signature divider are dropped. */
    public static function stripQuoted(string $text): string
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*>/', $line) || preg_match('/^\s*On .{5,200} wrote:\s*$/i', $line) || preg_match('/^\s*-{2,}\s*(Original Message|Forwarded message)/i', $line) || preg_match('/^\s*From:\s.+/i', $line) && $out !== []) {
                break;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    /** @return array{0: ?object, 1: ?object, 2: ?string} [outbound email, lead, why not matched] */
    private function match(array $n): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v, " <>\t\r\n"), array_merge([$n['in_reply_to'] ?? null, $n['original_message_id'] ?? null], $n['references'] ?? [])))));
        if ($ids !== []) {
            $email = DB::table('crm_emails')->whereIn('message_id', $ids)->where('direction', 'outbound')->orderByDesc('id')->first();
            if ($email) {
                $link = DB::table('crm_email_links')->where('email_id', $email->id)->where('linked_type', 'LEAD')->where('sub_institute_id', $email->sub_institute_id)->first();
                $lead = $link ? DB::table('crm_leads')->where('sub_institute_id', $email->sub_institute_id)->where('id', $link->linked_id)->whereNull('deleted_at')->first() : null;
                if ($lead) {
                    return [$email, $lead, null];
                }

                return [$email, null, 'The email this answers was not sent to a CRM lead (or the lead was removed).'];
            }
        }
        $address = mb_strtolower(trim((string) (($n['type'] === 'reply' ? ($n['from_email'] ?? '') : ($n['recipient'] ?? '')))));
        if ($address !== '') {
            $leads = DB::table('crm_leads')->whereNull('deleted_at')->whereRaw('LOWER(TRIM(email)) = ?', [$address])->limit(2)->get();
            if ($leads->count() === 1) {
                $lead = $leads->first();
                $email = DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $lead->id)
                    ->where('e.direction', 'outbound')->where('e.status', 'Sent')->orderByDesc('e.id')->select('e.*')->first();

                return [$email, $lead, null];
            }

            return [null, null, $leads->count() > 1 ? 'More than one lead has this address, so the event was not applied to any of them.' : 'No sent email or lead matches this event.'];
        }

        return [null, null, 'No sent email or lead matches this event.'];
    }

    /** @return array{status: string, detail: string, trigger: bool, classification?: string} */
    private function reply(int $tenant, object $lead, array $n, int $inboundId): array
    {
        $text = (string) ($n['text'] ?? '');
        $subject = (string) ($n['subject'] ?? 'Reply');
        $auto = ! empty($n['auto_reply']) || (bool) preg_match(self::AUTO_SUBJECT, $subject);
        $this->inboundEmailId = $this->storeInbound($tenant, $lead, $n, $subject, $text);
        $words = trim(preg_replace('/\s+/', ' ', self::stripQuoted($text)) ?? '');
        $snippet = mb_strlen($words) > 240 ? mb_substr($words, 0, 240).'...' : $words;

        if ($auto) {
            LeadActivity::note($tenant, (int) $lead->id, 'An automatic reply (out of office / auto-responder) arrived. It is not treated as the customer answering, so follow-up continues.');

            return ['status' => 'ignored', 'detail' => 'Automatic reply recorded; follow-up not stopped.', 'trigger' => false, 'classification' => 'auto_reply'];
        }

        $stopped = LeadFollowUp::stopFor($tenant, (int) $lead->id, 'replied', 'replied');
        $classification = null;
        if (self::asksToStop($text)) {
            $this->optOutLead($tenant, $lead, 'inbound');
            $classification = 'no_further_contact';
        }
        LeadActivity::note($tenant, (int) $lead->id, 'Customer replied'.($snippet !== '' ? ": \"{$snippet}\"" : '.')
            .($stopped ? ' Automatic follow-up stopped.' : '').($classification ? ' They asked not to be contacted again, so the lead was opted out of email.' : ''));

        return ['status' => 'processed', 'detail' => 'Reply recorded'.($stopped ? ', follow-up stopped' : '').($classification ? ', lead opted out' : '').'.', 'trigger' => true, 'classification' => $classification];
    }

    /** @return array{status: string, detail: string, trigger: bool} */
    private function bounce(int $tenant, object $lead, array $n, int $inboundId): array
    {
        $hard = ($n['bounce_type'] ?? 'hard') === 'hard';
        if (! $hard) {
            $soft = DB::table('crm_inbound_events')->where('lead_id', $lead->id)->where('type', 'bounce')->where('bounce_type', 'soft')->where('received_at', '>=', now()->subDays(30))->where('id', '!=', $inboundId)->count() + 1;
            $hard = $soft >= self::SOFT_BOUNCE_LIMIT;
        }
        if (! $hard) {
            LeadActivity::note($tenant, (int) $lead->id, 'A temporary delivery problem (soft bounce) was reported for the lead\'s email. Nothing was changed.');

            return ['status' => 'processed', 'detail' => 'Soft bounce recorded.', 'trigger' => false];
        }
        $stopped = LeadFollowUp::stopFor($tenant, (int) $lead->id, 'bounced', 'stopped');
        LeadActivity::note($tenant, (int) $lead->id, 'The lead\'s email address bounced ('.mb_substr((string) ($n['reason'] ?? 'delivery failed'), 0, 150).').'.($stopped ? ' Automatic follow-up stopped.' : ''));
        LeadActivity::task($tenant, $lead, "Email bounced: correct the address ({$lead->lead_no})", 'The email address on this lead did not accept mail. Find a verified address; none was guessed.', 0, null);

        return ['status' => 'processed', 'detail' => 'Hard bounce recorded'.($stopped ? ', follow-up stopped' : '').'.', 'trigger' => false];
    }

    /** @return array{status: string, detail: string, trigger: bool} */
    private function complaint(int $tenant, object $lead, array $n): array
    {
        $this->optOutLead($tenant, $lead, 'inbound');
        $stopped = LeadFollowUp::stopFor($tenant, (int) $lead->id, 'opted_out', 'stopped');
        LeadActivity::note($tenant, (int) $lead->id, 'The recipient reported our email as spam. The lead was opted out of email.'.($stopped ? ' Automatic follow-up stopped.' : ''));

        return ['status' => 'processed', 'detail' => 'Complaint recorded, lead opted out.', 'trigger' => false];
    }

    /** Opt a lead out of email (customer asked, or complained). Idempotent. */
    public function optOutLead(int $tenant, object $lead, string $source): void
    {
        if ($lead->email_opt_out) {
            return;
        }
        DB::table('crm_leads')->where('id', $lead->id)->where('sub_institute_id', $tenant)->update(['email_opt_out' => 1, 'updated_at' => now()]);
        app(EventRecorder::class)->record('crm.lead.updated', $tenant, 'crm_leads', (int) $lead->id, null,
            ['changes' => [['field' => 'email_opt_out', 'oldValue' => 0, 'newValue' => 1]]], ['source' => $source]);
    }

    /** The reply as an inbound email on the lead's Emails tab. */
    private function storeInbound(int $tenant, object $lead, array $n, string $subject, string $text): int
    {
        $id = (int) DB::table('crm_emails')->insertGetId([
            'subject' => mb_substr($subject, 0, 255), 'body' => mb_substr($text, 0, 20000), 'to_address' => mb_substr((string) ($n['recipient'] ?? config('mail.from.address') ?: 'inbox'), 0, 191),
            'from_address' => mb_substr((string) ($n['from_email'] ?? $lead->email), 0, 191), 'direction' => 'inbound', 'message_id' => isset($n['message_id']) ? mb_substr(trim((string) $n['message_id'], ' <>'), 0, 191) : null,
            'status' => 'Received', 'assigned_to' => (int) $lead->assigned_to, 'created_by' => null, 'sub_institute_id' => $tenant, 'sent_at' => $n['occurred_at'] ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_email_links')->insert(['email_id' => $id, 'linked_type' => 'LEAD', 'linked_id' => (int) $lead->id, 'sub_institute_id' => $tenant, 'created_by' => null, 'created_at' => now()]);

        return $id;
    }

    /** What is kept in the ledger: never more than the event needs (the body lives on the inbound email row). @return array<string, mixed> */
    private function safePayload(array $n): array
    {
        unset($n['text']);

        return $n;
    }
}
