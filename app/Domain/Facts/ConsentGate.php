<?php

namespace App\Domain\Facts;

use App\Domain\Gtm\OutreachMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE QUESTION every sender must ask before it writes to somebody (slice 4, defect D7): "may we contact this record on this channel?" Read only. Nothing
 * here sends, and sending stays off everywhere until slice 10; the gate exists so that the day a sender is switched on, it has ONE answer to ask instead of
 * four lists that disagree.
 *
 * It reads FOUR sources, because the platform keeps consent in four places that nothing connected until now:
 *   1. the record's own flags: `email_opt_out`, `consent_status` (opted_out | suppressed) and `suppressed_at` on the contact, organisation or lead;
 *   2. `crm_consent_preferences`: one row per record and channel (opted_in | opted_out | unknown), with where the consent came from;
 *   3. `gtm_suppressions`: the outreach engine's list of addresses and whole domains that must never be emailed (unsubscribed, bounced, complained);
 *   4. `gtm_contacts.status`: an outreach contact with the same address that is bounced, unsubscribed or do_not_contact.
 *
 * THE RULES (decision D9 of the slice file):
 *   - EMAIL is allowed unless one of the four sources says no (the behaviour of today, so nothing that works stops working). Any single source that says no wins.
 *   - SMS and WHATSAPP are NOT allowed until an `opted_in` preference is recorded for that channel: they are opt-in channels. An opt-out beats an opt-in.
 *   - A record's hard-stop flags (consent_status opted_out or suppressed, suppressed_at) block EVERY channel.
 *   - A record with no address (email) or no number (sms, whatsapp) is not allowed: there is nobody to send to.
 *   - FAIL CLOSED: a record that does not exist, is removed, has no organisation, or belongs to another organisation than the caller names answers "not allowed".
 *   - A source this host does not have (a missing table or column) says nothing; the others still speak.
 *
 * The record's organisation is read from the record itself, so a sender need not (and cannot wrongly) say which organisation's lists to consult.
 */
final class ConsentGate
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    private const HARD_STOP = ['opted_out', 'suppressed'];

    private const OPT_OUT = ['opted_out'];

    /**
     * @return array{allowed: bool, reason: ?string, code: ?string}
     */
    public function allows(string $entityType, int $entityId, string $channel, ?int $tenantId = null): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return ['allowed' => false, 'reason' => "'{$channel}' is not a channel this gate knows.", 'code' => 'unknown_channel'];
        }

        return $this->report($entityType, $entityId, $tenantId)['channels'][$channel];
    }

    /**
     * Every channel's answer and every consent signal that was found, for the record's consent line.
     *
     * @return array{channels: array<string, array{allowed: bool, reason: ?string, code: ?string}>, sources: list<array{source: string, value: string, at: ?string}>}
     */
    public function report(string $entityType, int $entityId, ?int $tenantId = null): array
    {
        $row = FactEntities::isType($entityType) && $entityId > 0
            ? DB::table(FactEntities::table($entityType))->where('id', $entityId)->whereNull('deleted_at')->first()
            : null;
        $tenant = $row === null ? 0 : (int) ($row->sub_institute_id ?? 0);
        if ($row === null || $tenant <= 0 || ($tenantId !== null && $tenantId !== $tenant)) {
            $none = ['allowed' => false, 'reason' => 'This record does not exist for this organisation.', 'code' => 'unknown_recipient'];

            return ['channels' => array_fill_keys(self::CHANNELS, $none), 'sources' => []];
        }

        $sources = [];
        $stop = $this->recordFlags($row, $sources);
        $preferences = $this->preferences($entityType, $entityId, $tenant, $sources);
        $email = strtolower(trim((string) ($row->email ?? '')));
        $suppression = $email === '' ? null : $this->suppression($tenant, $email, $sources);
        $outreach = $email === '' ? null : $this->outreachContact($tenant, $email, $sources);
        $number = trim((string) ($row->mobile ?? '')) !== '' ? trim((string) $row->mobile) : trim((string) ($row->phone ?? ''));

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = $this->decide($channel, $row, $stop, $preferences[$channel] ?? null, $suppression, $outreach, $email, $number);
        }

        return ['channels' => $channels, 'sources' => $sources];
    }

    /**
     * @param  array{0: string, 1: string}|null  $stop  [code, reason] of the record's hard stop
     * @param  array{status: string, source: ?string}|null  $preference
     * @param  array{reason: string, kind: string}|null  $suppression
     * @param  array{status: string}|null  $outreach
     * @return array{allowed: bool, reason: ?string, code: ?string}
     */
    private function decide(string $channel, object $row, ?array $stop, ?array $preference, ?array $suppression, ?array $outreach, string $email, string $number): array
    {
        $no = fn (string $code, string $reason) => ['allowed' => false, 'reason' => $reason, 'code' => $code];
        $label = ['email' => 'email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'][$channel];

        if ($stop !== null) {
            return $no($stop[0], $stop[1]);
        }
        if ($channel === 'email') {
            if ((int) ($row->email_opt_out ?? 0) === 1) {
                return $no('opted_out', 'Opted out of email (recorded on the record).');
            }
            if ($email === '') {
                return $no('no_address', 'No email address on record.');
            }
        } elseif ($number === '') {
            return $no('no_address', 'No phone number on record.');
        }
        if ($preference !== null && in_array($preference['status'], self::OPT_OUT, true)) {
            return $no('preference_opted_out', "The {$label} consent preference says opted out" . ($preference['source'] ? " (source: {$preference['source']})." : '.'));
        }
        if ($channel === 'email') {
            if ($suppression !== null) {
                return $no('suppression_list', $suppression['kind'] === 'domain'
                    ? "The domain of this address is on the suppression list ({$suppression['reason']})."
                    : "This address is on the suppression list ({$suppression['reason']}).");
            }
            if ($outreach !== null) {
                return $no('outreach_contact_blocked', "A matching outreach contact is marked {$outreach['status']}.");
            }

            return ['allowed' => true, 'reason' => null, 'code' => null];
        }

        return $preference !== null && $preference['status'] === 'opted_in'
            ? ['allowed' => true, 'reason' => null, 'code' => null]
            : $no('no_consent_recorded', "No consent is recorded for {$label}: it needs an opt-in first.");
    }

    /**
     * Source 1. Returns [code, reason] when the record is hard-stopped on every channel; adds what it found to $sources.
     *
     * @param  list<array{source: string, value: string, at: ?string}>  $sources
     * @return array{0: string, 1: string}|null
     */
    private function recordFlags(object $row, array &$sources): ?array
    {
        $stop = null;
        if ((int) ($row->email_opt_out ?? 0) === 1) {
            $sources[] = ['source' => 'email_opt_out', 'value' => '1', 'at' => null];
        }
        $status = strtolower(trim((string) ($row->consent_status ?? '')));
        if ($status !== '' && $status !== 'unknown') {
            $sources[] = ['source' => 'consent_status', 'value' => $status, 'at' => null];
        }
        if (in_array($status, self::HARD_STOP, true)) {
            $stop = [$status === 'opted_out' ? 'opted_out' : 'suppressed', $status === 'opted_out' ? 'Opted out (the record says so).' : 'Suppressed' . $this->because($row) . '.'];
        }
        if (($row->suppressed_at ?? null) !== null) {
            $sources[] = ['source' => 'suppressed_at', 'value' => (string) ($row->suppressed_reason ?? ''), 'at' => $this->date($row->suppressed_at)];
            $stop ??= ['suppressed', 'Suppressed' . $this->because($row) . '.'];
        }

        return $stop;
    }

    private function because(object $row): string
    {
        $why = trim((string) ($row->suppressed_reason ?? ''));

        return $why === '' ? '' : " ({$why})";
    }

    /**
     * Source 2: one row per channel.
     *
     * @param  list<array{source: string, value: string, at: ?string}>  $sources
     * @return array<string, array{status: string, source: ?string}>
     */
    private function preferences(string $type, int $id, int $tenant, array &$sources): array
    {
        if (! Schema::hasTable('crm_consent_preferences')) {
            return [];
        }
        $rows = DB::table('crm_consent_preferences')->where('entity_type', $type)->where('entity_id', $id)
            ->where(fn ($t) => $t->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->orderBy('id')->get();
        $found = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) $row->status));
            $found[strtolower((string) $row->channel)] = ['status' => $status, 'source' => $row->source !== null ? (string) $row->source : null];
            if ($status !== '' && $status !== 'unknown') {
                $sources[] = ['source' => 'crm_consent_preferences', 'value' => "{$row->channel}: {$status}" . ($row->source ? " ({$row->source})" : ''), 'at' => $this->date($row->captured_at ?? $row->updated_at)];
            }
        }

        return $found;
    }

    /**
     * Source 3: the address, or its whole domain, on the suppression list.
     *
     * @param  list<array{source: string, value: string, at: ?string}>  $sources
     * @return array{reason: string, kind: string}|null
     */
    private function suppression(int $tenant, string $email, array &$sources): ?array
    {
        if (! Schema::hasTable('gtm_suppressions') || ! str_contains($email, '@')) {
            return null;
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        $row = DB::table('gtm_suppressions')->where('sub_institute_id', $tenant)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('kind', 'email')->where('value', $email))->orWhere(fn ($x) => $x->where('kind', 'domain')->where('value', $domain)))
            ->orderBy('id')->first();
        if ($row === null) {
            return null;
        }
        $sources[] = ['source' => 'gtm_suppressions', 'value' => "{$row->reason} ({$row->kind})", 'at' => $this->date($row->created_at)];

        return ['reason' => (string) $row->reason, 'kind' => (string) $row->kind];
    }

    /**
     * Source 4: an outreach contact with this address whose status stops mail.
     *
     * @param  list<array{source: string, value: string, at: ?string}>  $sources
     * @return array{status: string}|null
     */
    private function outreachContact(int $tenant, string $email, array &$sources): ?array
    {
        if (! Schema::hasTable('gtm_contacts')) {
            return null;
        }
        $row = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('status', OutreachMailer::BLOCKED_CONTACT)->orderBy('id')->first();
        if ($row === null) {
            return null;
        }
        $sources[] = ['source' => 'gtm_contacts', 'value' => (string) $row->status, 'at' => $this->date($row->updated_at)];

        return ['status' => (string) $row->status];
    }

    private function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : \Carbon\Carbon::parse((string) $value)->toIso8601String();
    }
}
