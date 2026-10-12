<?php

namespace App\Domain\Gtm\Outreach;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * The suppression list: addresses and whole domains that must NEVER be emailed, whatever a contact record
 * says. An unsubscribe, a hard bounce and a spam complaint each add an entry (reason recorded), so
 * re-importing or re-creating the same person cannot quietly re-enable them. Entries added for those
 * real-world reasons cannot be removed through the API; only `manual` entries can.
 */
final class Suppressions
{
    public const REASONS = ['unsubscribed', 'hard_bounce', 'soft_bounce_limit', 'complaint', 'manual'];

    public static function normalize(string $kind, string $value): string
    {
        $v = mb_strtolower(trim($value));

        return $kind === 'domain' ? ltrim($v, '@') : $v;
    }

    /** @return array{id: int, kind: string, value: string, reason: string}|null the entry that blocks this address, if any */
    public function blocking(int $tenant, ?string $email): ?array
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        $row = DB::table('gtm_suppressions')->where('sub_institute_id', $tenant)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('kind', 'email')->where('value', $email))->orWhere(fn ($x) => $x->where('kind', 'domain')->where('value', $domain)))
            ->first(['id', 'kind', 'value', 'reason']);

        return $row ? ['id' => (int) $row->id, 'kind' => $row->kind, 'value' => $row->value, 'reason' => $row->reason] : null;
    }

    /** @param list<string> $emails @return list<string> the lower-cased subset that is suppressed (by address or domain) */
    public function among(int $tenant, array $emails): array
    {
        $rows = DB::table('gtm_suppressions')->where('sub_institute_id', $tenant)->get(['kind', 'value']);
        $addr = $rows->where('kind', 'email')->pluck('value')->flip();
        $dom = $rows->where('kind', 'domain')->pluck('value')->flip();

        return array_values(array_filter(array_map('mb_strtolower', $emails), fn ($e) => isset($addr[$e]) || (str_contains($e, '@') && isset($dom[substr($e, strrpos($e, '@') + 1)]))));
    }

    /** Add an entry. Returns false when it already existed (and leaves the original reason in place). */
    public function add(int $tenant, string $kind, string $value, string $reason, ?string $note = null, ?int $actorId = null): bool
    {
        $value = self::normalize($kind, $value);
        $exists = DB::table('gtm_suppressions')->where('sub_institute_id', $tenant)->where('kind', $kind)->where('value', $value)->exists();
        if ($exists) {
            return false;
        }
        $id = DB::table('gtm_suppressions')->insertGetId(['sub_institute_id' => $tenant, 'kind' => $kind, 'value' => $value, 'reason' => $reason, 'note' => $note ? mb_substr($note, 0, 255) : null, 'created_by' => $actorId, 'created_at' => now()]);
        GtmAudit::record('gtm.suppression.added', $tenant, 'gtm_suppressions', $id, $actorId, ['kind' => $kind, 'value' => $value, 'reason' => $reason]);

        return true;
    }
}
