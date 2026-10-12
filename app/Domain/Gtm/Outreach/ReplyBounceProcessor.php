<?php

namespace App\Domain\Gtm\Outreach;

use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns one inbound notification (a reply, a bounce or a spam complaint) into the right changes.
 *
 * TRUST. The caller has already authenticated the sender (signed webhook) or is an administrator recording
 * it by hand. Even so, the TENANT is never taken from the payload: it is derived from the matched message or
 * contact, so a forged or mistaken payload cannot touch another organisation's records.
 *
 * MATCHING. First by the Message-ID we put on the outgoing email (In-Reply-To / References for a reply, the
 * original id for a bounce). Failing that, by the contact's email address - but only when exactly ONE contact
 * across all organisations has it; an ambiguous address is recorded as unmatched and changes nothing.
 *
 * IDEMPOTENT. UNIQUE (provider, provider_event_id): a redelivered event is reported as a duplicate and does nothing.
 * Every event, matched or not, leaves a gtm_inbound_events row with its outcome.
 *
 * EFFECTS
 *  reply            inbound email activity; message marked replied; the rest of its sequence cancelled; any other
 *                   scheduled send to that contact paused (a person should answer a person)
 *  bounce (hard)    contact -> bounced; address suppressed; every unsent message to them cancelled
 *  bounce (soft)    recorded, scheduled sends held for a day; the third soft bounce in 30 days is treated as hard
 *  complaint        contact -> unsubscribed; address suppressed; every unsent message cancelled
 */
final class ReplyBounceProcessor
{
    private const UNSENT = ['draft', 'pending_approval', 'approved', 'rejected'];
    public const SOFT_BOUNCE_LIMIT = 3;

    /**
     * @param  array{provider: string, event_id: string, type: string, bounce_type?: ?string, in_reply_to?: ?string, references?: list<string>, original_message_id?: ?string, from_email?: ?string, recipient?: ?string, subject?: ?string, text?: ?string, reason?: ?string, occurred_at?: ?string}  $n
     * @return array{status: string, inbound_id: int, detail: ?string, tenant?: ?int, contact_id?: ?int, message_id?: ?int}
     */
    public function process(array $n, ?int $actorId = null): array
    {
        return $this->run($n, $actorId, null);
    }

    /**
     * Record an event for a contact the caller has ALREADY resolved within its own tenant (an administrator recording
     * a reply by hand). No address matching: the contact is the contact.
     *
     * @param  array<string, mixed>  $n
     * @return array{status: string, inbound_id: int, detail: ?string, tenant?: ?int, contact_id?: ?int, message_id?: ?int}
     */
    public function processForContact(object $contact, array $n, ?int $actorId = null): array
    {
        return $this->run($n, $actorId, $contact);
    }

    /** @param array<string, mixed> $n */
    private function run(array $n, ?int $actorId, ?object $known): array
    {
        try {
            $id = (int) DB::table('gtm_inbound_events')->insertGetId([
                'provider' => $n['provider'], 'provider_event_id' => $n['event_id'], 'type' => $n['type'], 'bounce_type' => $n['bounce_type'] ?? null, 'status' => 'unmatched',
                'payload' => mb_substr(json_encode($this->safePayload($n), JSON_UNESCAPED_UNICODE), 0, 60000), 'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            $existing = DB::table('gtm_inbound_events')->where('provider', $n['provider'])->where('provider_event_id', $n['event_id'])->first(['id']);
            if ($existing) {
                return ['status' => 'duplicate', 'inbound_id' => (int) $existing->id, 'detail' => 'This event was already received.'];
            }
            throw $e;
        }

        try {
            [$message, $contact] = $known ? [DB::table('gtm_outreach_messages')->where('sub_institute_id', $known->sub_institute_id)->where('contact_id', $known->id)->where('status', 'sent')->orderByDesc('sent_at')->first(), $known] : $this->match($n);
            if (! $contact) {
                DB::table('gtm_inbound_events')->where('id', $id)->update(['status' => 'unmatched', 'detail' => 'No sent message or single contact matches this event.', 'processed_at' => now()]);

                return ['status' => 'unmatched', 'inbound_id' => $id, 'detail' => 'No sent message or single contact matches this event.'];
            }
            $tenant = (int) $contact->sub_institute_id;
            $detail = DB::transaction(fn () => match ($n['type']) {
                'reply' => $this->reply($tenant, $contact, $message, $n),
                'bounce' => $this->bounce($tenant, $contact, $message, $n, $id),
                default => $this->complaint($tenant, $contact, $message, $n),
            });
            DB::table('gtm_inbound_events')->where('id', $id)->update(['sub_institute_id' => $tenant, 'message_id' => $message?->id, 'contact_id' => $contact->id, 'status' => 'processed', 'detail' => mb_substr($detail, 0, 500), 'processed_at' => now()]);
            GtmAudit::record('gtm.inbound.received', $tenant, 'gtm_inbound_events', $id, $actorId, ['type' => $n['type'], 'provider' => $n['provider'], 'contact_id' => $contact->id]);

            return ['status' => 'processed', 'inbound_id' => $id, 'detail' => $detail, 'tenant' => $tenant, 'contact_id' => (int) $contact->id, 'message_id' => $message?->id ? (int) $message->id : null];
        } catch (\Throwable $e) {
            DB::table('gtm_inbound_events')->where('id', $id)->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500), 'processed_at' => now()]);
            report($e);

            return ['status' => 'error', 'inbound_id' => $id, 'detail' => 'The event was recorded but could not be applied.'];
        }
    }

    /** @return array{0: ?object, 1: ?object} [message, contact] */
    private function match(array $n): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v, " <>\t\r\n"), array_merge([$n['in_reply_to'] ?? null, $n['original_message_id'] ?? null], $n['references'] ?? [])))));
        if ($ids !== []) {
            $message = DB::table('gtm_outreach_messages')->whereIn('provider_message_id', $ids)->orderByDesc('id')->first();
            if ($message) {
                $contact = DB::table('gtm_contacts')->where('sub_institute_id', $message->sub_institute_id)->where('id', $message->contact_id)->whereNull('deleted_at')->first();
                if ($contact) {
                    return [$message, $contact];
                }
            }
        }
        $email = mb_strtolower(trim((string) ($n['type'] === 'reply' ? ($n['from_email'] ?? '') : ($n['recipient'] ?? ''))));
        if ($email !== '') {
            $contacts = DB::table('gtm_contacts')->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();
            if ($contacts->count() === 1) {
                $c = $contacts->first();
                $message = DB::table('gtm_outreach_messages')->where('sub_institute_id', $c->sub_institute_id)->where('contact_id', $c->id)->where('status', 'sent')->orderByDesc('sent_at')->first();

                return [$message, $c];
            }
        }

        return [null, null];
    }

    private function reply(int $tenant, object $contact, ?object $message, array $n): string
    {
        GtmActivity::create([
            'sub_institute_id' => $tenant, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'deal_id' => $message?->deal_id, 'type' => 'email', 'direction' => 'inbound',
            'subject' => mb_substr((string) ($n['subject'] ?? 'Reply'), 0, 255), 'body' => mb_substr((string) ($n['text'] ?? ''), 0, 20000), 'occurred_at' => $n['occurred_at'] ?? now(),
            'metadata' => ['provider' => $n['provider'], 'event_id' => $n['event_id'], 'outreach_message_id' => $message?->id],
        ]);
        $stopped = 0;
        if ($message) {
            DB::table('gtm_outreach_messages')->where('id', $message->id)->update(['outcome' => 'replied', 'outcome_at' => now(), 'updated_at' => now()]);
            if ($message->sequence_key) {
                $stopped = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('sequence_key', $message->sequence_key)->whereIn('status', self::UNSENT)
                    ->update(['status' => 'cancelled', 'decision_note' => 'Reply received', 'scheduled_for' => null, 'updated_at' => now()]);
            }
        }
        // anything else queued for automatic sending to this person waits for a human
        $paused = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('contact_id', $contact->id)->where('status', 'approved')->whereNotNull('scheduled_for')
            ->update(['scheduled_for' => null, 'updated_at' => now()]);
        GtmAudit::record('gtm.contact.replied', $tenant, 'gtm_contacts', (int) $contact->id, null, ['provider' => $n['provider'], 'sequence_steps_cancelled' => $stopped, 'scheduled_sends_paused' => $paused]);

        return "Reply recorded. {$stopped} later sequence step(s) cancelled, {$paused} other scheduled send(s) paused.";
    }

    private function bounce(int $tenant, object $contact, ?object $message, array $n, int $inboundId): string
    {
        $soft = ($n['bounce_type'] ?? 'hard') === 'soft';
        $reason = mb_substr((string) ($n['reason'] ?? ''), 0, 255);
        if ($message) {
            DB::table('gtm_outreach_messages')->where('id', $message->id)->update(['outcome' => 'bounced', 'outcome_at' => now(), 'outcome_detail' => ($soft ? 'soft: ' : 'hard: ').$reason, 'updated_at' => now()]);
        }
        if ($soft) {
            $recent = DB::table('gtm_inbound_events')->where('contact_id', $contact->id)->where('type', 'bounce')->where('bounce_type', 'soft')->where('status', 'processed')->where('received_at', '>=', now()->subDays(30))->count() + 1;
            if ($recent < self::SOFT_BOUNCE_LIMIT) {
                $held = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('contact_id', $contact->id)->where('status', 'approved')->whereNotNull('scheduled_for')
                    ->update(['next_attempt_at' => now()->addDay(), 'updated_at' => now()]);
                GtmActivity::create(['sub_institute_id' => $tenant, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'type' => 'system', 'subject' => "Soft bounce ({$recent} of ".self::SOFT_BOUNCE_LIMIT." in 30 days)", 'occurred_at' => now()]);

                return "Soft bounce {$recent} of ".self::SOFT_BOUNCE_LIMIT.' in 30 days; '.$held.' scheduled send(s) held for a day.';
            }
        }

        $why = $soft ? 'soft_bounce_limit' : 'hard_bounce';
        $this->suppressAndStop($tenant, $contact, 'bounced', $why, $reason ?: 'Delivery failed', 'Delivery failed');
        GtmAudit::record('gtm.contact.bounced', $tenant, 'gtm_contacts', (int) $contact->id, null, ['provider' => $n['provider'], 'bounce_type' => $soft ? 'soft_limit' : 'hard', 'reason' => $reason]);

        return $soft ? 'Third soft bounce in 30 days: treated as a hard bounce. Contact marked bounced and address suppressed.' : 'Hard bounce. Contact marked bounced, address suppressed, unsent messages cancelled.';
    }

    private function complaint(int $tenant, object $contact, ?object $message, array $n): string
    {
        if ($message) {
            DB::table('gtm_outreach_messages')->where('id', $message->id)->update(['outcome' => 'complained', 'outcome_at' => now(), 'updated_at' => now()]);
        }
        $this->suppressAndStop($tenant, $contact, 'unsubscribed', 'complaint', 'Spam complaint', 'Spam complaint');
        GtmAudit::record('gtm.contact.unsubscribed', $tenant, 'gtm_contacts', (int) $contact->id, null, ['via' => 'complaint', 'provider' => $n['provider']]);

        return 'Spam complaint. Contact marked unsubscribed, address suppressed, unsent messages cancelled.';
    }

    private function suppressAndStop(int $tenant, object $contact, string $status, string $reason, string $note, string $subject): void
    {
        DB::table('gtm_contacts')->where('id', $contact->id)->where('sub_institute_id', $tenant)->update(['status' => $status, 'updated_at' => now()]);
        if (trim((string) $contact->email) !== '') {
            app(Suppressions::class)->add($tenant, 'email', (string) $contact->email, $reason, $note);
        }
        DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('contact_id', $contact->id)->whereIn('status', self::UNSENT)
            ->update(['status' => 'cancelled', 'decision_note' => $subject, 'scheduled_for' => null, 'updated_at' => now()]);
        GtmActivity::create(['sub_institute_id' => $tenant, 'account_id' => $contact->account_id, 'contact_id' => $contact->id, 'type' => 'system', 'subject' => "{$subject}: contact marked {$status}", 'occurred_at' => now()]);
    }

    /** What is stored of the event: no secrets are ever in it, and the free text is capped. @param array<string, mixed> $n @return array<string, mixed> */
    private function safePayload(array $n): array
    {
        $n['text'] = isset($n['text']) ? mb_substr((string) $n['text'], 0, 4000) : null;

        return $n;
    }
}
