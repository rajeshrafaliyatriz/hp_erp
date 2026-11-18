<?php

namespace App\Domain\Facts;

use App\Domain\Gtm\Outreach\Suppressions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WRITES consent so that the platform's four places agree (slice 4, defect D7). Before this, an unsubscribe through the CRM email link flipped one flag on one
 * record, and the outreach engine (which keeps its own suppression list and its own contact status) never heard of it, and the other way round: a person who
 * unsubscribed through one system stayed emailable through the other. The unsubscribe, complaint and bounce handlers now call this after their own write, so
 * what ConsentGate reads is what was recorded, whichever door the person used.
 *
 * ONE ADDRESS, EVERYWHERE IN THE ORGANISATION. An address that unsubscribes or complains is flagged on every contact, lead and organisation of that
 * organisation that carries it, gets an `opted_out` email preference row on each, is put on the suppression list, and marks the outreach contacts that carry it.
 * An address that bounces for good is SUPPRESSED the same way (the record's `suppressed_at`, `suppressed_reason`, `consent_status = suppressed`, the suppression
 * list, the outreach contact's `bounced`) but its preference row is left alone: a bounce is a dead address, not a withdrawn consent.
 *
 * IT ONLY EVER ESCALATES. Nothing here clears an opt-out, turns a suppression into a consent, or overwrites a stronger state with a weaker one; a person
 * re-subscribing is a deliberate act through the screens, not a side effect of a handler. Every step is idempotent, so a retry, or the reconcile command run
 * twice, changes nothing the second time. A column or table this host does not have is skipped (the live database is behind the development one).
 *
 * The handlers wrap the call so that a failure here can never undo or hide the unsubscribe itself: ConsentGate reads every source, so a missed write-through
 * makes the systems disagree on paper only, and `consent:reconcile` repairs it.
 */
final class ConsentRecorder
{
    /** Reasons that withdraw consent: the person said no (the outreach list's own words). */
    public const OPT_OUT_REASONS = ['unsubscribed', 'complaint', 'manual'];

    /** Reasons that make an address undeliverable: suppressed, but not a withdrawn consent. */
    public const BOUNCE_REASONS = ['hard_bounce', 'soft_bounce_limit'];

    /** @var array<string, string> */
    private const TABLES = ['contact' => 'crm_contacts', 'organization' => 'crm_organizations', 'lead' => 'crm_leads'];

    /**
     * Records one record's opt-out or suppression and carries it to every record, list and outreach contact that shares its address.
     *
     * @return array{records: int, preferences: int, suppression: bool, outreach_contacts: int}
     */
    public function optOut(int $tenantId, string $entityType, int $entityId, string $reason, string $source, ?int $actorId = null, ?string $note = null): array
    {
        $row = FactEntities::isType($entityType) ? DB::table(self::TABLES[$entityType])->where('id', $entityId)->where('sub_institute_id', $tenantId)->first() : null;

        return $this->optOutAddress($tenantId, (string) ($row->email ?? ''), $reason, $source, $actorId, $note, $row === null ? null : [$entityType, $entityId]);
    }

    /**
     * @param  array{0: string, 1: int}|null  $also  a record to flag even when its address differs or is missing
     * @return array{records: int, preferences: int, suppression: bool, outreach_contacts: int}
     */
    public function optOutAddress(int $tenantId, string $email, string $reason, string $source, ?int $actorId = null, ?string $note = null, ?array $also = null): array
    {
        $email = strtolower(trim($email));
        $bounce = in_array($reason, self::BOUNCE_REASONS, true);
        if (! $bounce && ! in_array($reason, self::OPT_OUT_REASONS, true)) {
            throw new \InvalidArgumentException("Unknown consent reason '{$reason}'.");
        }

        $result = ['records' => 0, 'preferences' => 0, 'suppression' => false, 'outreach_contacts' => 0];
        foreach ($this->recordsFor($tenantId, $email, $also) as [$type, $id]) {
            $result['records'] += $this->flagRecord($tenantId, $type, $id, $bounce, $reason) ? 1 : 0;
            if (! $bounce) {
                $result['preferences'] += $this->markPreference($tenantId, $type, $id, 'email', 'opted_out', $source, $note, $actorId) ? 1 : 0;
            }
        }
        if ($email !== '' && str_contains($email, '@') && Schema::hasTable('gtm_suppressions')) {
            $result['suppression'] = app(Suppressions::class)->add($tenantId, 'email', $email, $reason, $note, $actorId);
        }
        if ($email !== '' && Schema::hasTable('gtm_contacts')) {
            $result['outreach_contacts'] = DB::table('gtm_contacts')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])
                ->whereNotIn('status', ['unsubscribed', 'bounced', 'do_not_contact'])
                ->update(['status' => $bounce ? 'bounced' : 'unsubscribed', 'updated_at' => now()]);
        }

        return $result;
    }

    // ---- for the handlers: the same calls, which can never throw ----

    /** The unsubscribe link of a CRM email flipped `email_opt_out` on this row of this table: carry it to everything that shares the address. */
    public function afterUnsubscribeLink(string $table, int $id): void
    {
        $type = array_search($table, self::TABLES, true);
        if (! is_string($type)) {
            return;
        }
        try {
            $tenantId = (int) DB::table($table)->where('id', $id)->value('sub_institute_id');
            if ($tenantId > 0) {
                $this->optOut($tenantId, $type, $id, 'unsubscribed', 'unsubscribe_link', null, 'Unsubscribed through the link in an email');
            }
        } catch (\Throwable $e) {
            Log::warning('Consent write-through failed after an unsubscribe link', ['table' => $table, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }

    public function optOutSafely(int $tenantId, string $entityType, int $entityId, string $reason, string $source, ?int $actorId = null, ?string $note = null): void
    {
        try {
            $this->optOut($tenantId, $entityType, $entityId, $reason, $source, $actorId, $note);
        } catch (\Throwable $e) {
            Log::warning('Consent write-through failed', ['type' => $entityType, 'id' => $entityId, 'reason' => $reason, 'error' => $e->getMessage()]);
        }
    }

    public function optOutAddressSafely(int $tenantId, string $email, string $reason, string $source, ?int $actorId = null, ?string $note = null): void
    {
        try {
            $this->optOutAddress($tenantId, $email, $reason, $source, $actorId, $note);
        } catch (\Throwable $e) {
            Log::warning('Consent write-through failed', ['reason' => $reason, 'error' => $e->getMessage()]);
        }
    }

    public function carryOverSafely(int $tenantId, string $fromType, int $fromId, string $toType, int $toId): void
    {
        try {
            $this->carryOver($tenantId, $fromType, $fromId, $toType, $toId);
        } catch (\Throwable $e) {
            Log::warning('Consent carry-over failed on conversion', ['from' => "{$fromType}:{$fromId}", 'to' => "{$toType}:{$toId}", 'error' => $e->getMessage()]);
        }
    }

    /**
     * Copies the RESTRICTIVE consent state of one record to another when a lead becomes a contact: an opt-out, a suppression and an opted-out preference
     * are carried over (never an opt-in over an existing choice), an opt-in is carried only where the other record has recorded nothing. Idempotent.
     * A merge passes $copyMissingPreferences = false: the organisation merge already MOVES the removed record's preference rows, so copying them first would
     * leave the moved row a duplicate that is dropped; a merge only needs the opt-out of a conflicting channel to win, which this still does.
     */
    public function carryOver(int $tenantId, string $fromType, int $fromId, string $toType, int $toId, bool $copyMissingPreferences = true): int
    {
        if (! FactEntities::isType($fromType) || ! FactEntities::isType($toType)) {
            return 0;
        }
        $from = DB::table(self::TABLES[$fromType])->where('id', $fromId)->where('sub_institute_id', $tenantId)->first();
        $to = DB::table(self::TABLES[$toType])->where('id', $toId)->where('sub_institute_id', $tenantId)->first();
        if ($from === null || $to === null) {
            return 0;
        }
        $changes = 0;
        $table = self::TABLES[$toType];
        $update = [];

        if ((int) ($from->email_opt_out ?? 0) === 1 && (int) ($to->email_opt_out ?? 0) !== 1 && Schema::hasColumn($table, 'email_opt_out')) {
            $update['email_opt_out'] = 1;
        }
        if (Schema::hasColumn($table, 'consent_status')) {
            $fromStatus = strtolower(trim((string) ($from->consent_status ?? '')));
            $toStatus = strtolower(trim((string) ($to->consent_status ?? '')));
            $stop = ['opted_out', 'suppressed'];
            if (in_array($fromStatus, $stop, true) && ! in_array($toStatus, $stop, true)) {
                $update['consent_status'] = $fromStatus;
            } elseif ($fromStatus === 'opted_in' && ($toStatus === '' || $toStatus === 'unknown')) {
                $update['consent_status'] = 'opted_in';
            }
        }
        foreach (['suppressed_at', 'suppressed_reason'] as $column) {
            if (($from->{$column} ?? null) !== null && ($to->{$column} ?? null) === null && Schema::hasColumn($table, $column)) {
                $update[$column] = $from->{$column};
            }
        }
        if ($update !== []) {
            DB::table($table)->where('id', $toId)->where('sub_institute_id', $tenantId)->update($update + (Schema::hasColumn($table, 'updated_at') ? ['updated_at' => now()] : []));
            $changes++;
        }

        if (Schema::hasTable('crm_consent_preferences')) {
            foreach (DB::table('crm_consent_preferences')->where('entity_type', $fromType)->where('entity_id', $fromId)->get() as $pref) {
                $have = DB::table('crm_consent_preferences')->where('entity_type', $toType)->where('entity_id', $toId)->where('channel', $pref->channel)->first();
                if ($have === null) {
                    if (! $copyMissingPreferences) {
                        continue;
                    }
                    DB::table('crm_consent_preferences')->insert(['entity_type' => $toType, 'entity_id' => $toId, 'channel' => $pref->channel, 'status' => $pref->status, 'captured_at' => $pref->captured_at,
                        'source' => $pref->source, 'notes' => $pref->notes, 'sub_institute_id' => $tenantId, 'created_by' => $pref->created_by, 'created_at' => now(), 'updated_at' => now()]);
                    $changes++;
                } elseif ($pref->status === 'opted_out' && $have->status !== 'opted_out') {
                    DB::table('crm_consent_preferences')->where('id', $have->id)->update(['status' => 'opted_out', 'source' => $pref->source, 'updated_at' => now()]);
                    $changes++;
                }
            }
        }

        return $changes;
    }

    /**
     * Every CRM record of the organisation that carries the address (primary or second), plus the one named.
     *
     * @param  array{0: string, 1: int}|null  $also
     * @return list<array{0: string, 1: int}>
     */
    private function recordsFor(int $tenantId, string $email, ?array $also): array
    {
        $found = [];
        if ($email !== '') {
            foreach (self::TABLES as $type => $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $query = DB::table($table)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')
                    ->where(fn ($w) => $w->whereRaw('LOWER(email) = ?', [$email])->when(Schema::hasColumn($table, 'secondary_email'), fn ($x) => $x->orWhereRaw('LOWER(secondary_email) = ?', [$email])));
                foreach ($query->pluck('id') as $id) {
                    $found["{$type}:{$id}"] = [$type, (int) $id];
                }
            }
        }
        if ($also !== null && FactEntities::isType($also[0])) {
            $found["{$also[0]}:{$also[1]}"] = [$also[0], (int) $also[1]];
        }

        return array_values($found);
    }

    /** Escalates the record's own flags. @return bool whether anything changed */
    private function flagRecord(int $tenantId, string $type, int $id, bool $bounce, string $reason): bool
    {
        $table = self::TABLES[$type];
        $row = DB::table($table)->where('id', $id)->where('sub_institute_id', $tenantId)->first();
        if ($row === null) {
            return false;
        }
        $update = [];
        $status = strtolower(trim((string) ($row->consent_status ?? '')));

        if ($bounce) {
            if (Schema::hasColumn($table, 'suppressed_at') && ($row->suppressed_at ?? null) === null) {
                $update['suppressed_at'] = now();
                if (Schema::hasColumn($table, 'suppressed_reason')) {
                    $update['suppressed_reason'] = $reason;
                }
            }
            if (Schema::hasColumn($table, 'consent_status') && ! in_array($status, ['opted_out', 'suppressed'], true)) {
                $update['consent_status'] = 'suppressed';
            }
        } else {
            if (Schema::hasColumn($table, 'email_opt_out') && (int) ($row->email_opt_out ?? 0) !== 1) {
                $update['email_opt_out'] = 1;
            }
            if (Schema::hasColumn($table, 'consent_status') && $status !== 'opted_out') {
                $update['consent_status'] = 'opted_out';
            }
        }
        if ($update === []) {
            return false;
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $update['updated_at'] = now();
        }

        return DB::table($table)->where('id', $id)->where('sub_institute_id', $tenantId)->update($update) > 0;
    }

    /** One preference row per record and channel; an opt-out is written over anything, an opt-in is never written here. @return bool whether anything changed */
    private function markPreference(int $tenantId, string $type, int $id, string $channel, string $status, string $source, ?string $note, ?int $actorId): bool
    {
        if (! Schema::hasTable('crm_consent_preferences')) {
            return false;
        }
        $have = DB::table('crm_consent_preferences')->where('entity_type', $type)->where('entity_id', $id)->where('channel', $channel)->first();
        if ($have === null) {
            DB::table('crm_consent_preferences')->insert(['entity_type' => $type, 'entity_id' => $id, 'channel' => $channel, 'status' => $status, 'captured_at' => now(), 'source' => mb_substr($source, 0, 191),
                'notes' => $note, 'sub_institute_id' => $tenantId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);

            return true;
        }
        if ($have->status === $status) {
            return false;
        }

        return DB::table('crm_consent_preferences')->where('id', $have->id)->update(['status' => $status, 'captured_at' => now(), 'source' => mb_substr($source, 0, 191), 'updated_at' => now()]) > 0;
    }
}
