<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Gtm\OutreachMailer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Which leads may we email?" for a whole list at once (slice 6: the Leads screen's consent filter).
 *
 * THE ANSWER IS THE CONSENT GATE'S (App\Domain\Facts\ConsentGate, slice 4: the one question every sender asks). The gate answers for one record and costs
 * four queries; a list of a thousand leads cannot afford four thousand, so this reads the same four sources for ALL the candidate leads at once, in a handful
 * of queries, and applies the gate's own rules for the email channel:
 *   - the record's flags: consent_status opted_out or suppressed, suppressed_at, email_opt_out = 1;
 *   - no email address;
 *   - the newest consent preference row for the channel "email" says opted_out;
 *   - the organisation's suppression list holds the address or its domain;
 *   - an outreach contact with the same address is bounced, unsubscribed or do-not-contact.
 * Anything else is allowed (email is allowed unless a source says no). LeadConsentFilterTest compares every case with the gate itself, so a change to the gate
 * that this class does not follow fails there first.
 *
 * Only the organisation's own rows are read.
 */
final class LeadConsentFilter
{
    private const HARD_STOP = ['opted_out', 'suppressed'];

    private const CHUNK = 500;

    /**
     * @param  Builder  $candidates  the leads of the organisation the list would show (every other filter already applied)
     * @return list<int> the ids among them whose email the gate would refuse
     */
    public static function blockedIds(Builder $candidates, int $tenantId): array
    {
        $rows = (clone $candidates)->get(['id', 'email', 'email_opt_out', 'consent_status', 'suppressed_at']);

        $blocked = [];
        $emailOf = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $status = strtolower(trim((string) ($row->consent_status ?? '')));
            $email = strtolower(trim((string) ($row->email ?? '')));
            if (in_array($status, self::HARD_STOP, true) || ($row->suppressed_at ?? null) !== null || (int) ($row->email_opt_out ?? 0) === 1 || $email === '') {
                $blocked[$id] = true;

                continue;
            }
            $emailOf[$id] = $email;
        }

        foreach (self::byPreference($tenantId, array_keys($emailOf)) as $id) {
            $blocked[$id] = true;
        }
        $suppressed = self::suppressions($tenantId);
        $outreach = self::blockedOutreachAddresses($tenantId);
        foreach ($emailOf as $id => $email) {
            $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : null;
            if ($domain !== null && (isset($suppressed['email'][$email]) || isset($suppressed['domain'][$domain]))) {
                $blocked[$id] = true;
            }
            if (isset($outreach[$email])) {
                $blocked[$id] = true;
            }
        }

        return array_map('intval', array_keys($blocked));
    }

    /**
     * Source 2: the NEWEST preference row of the channel decides (the gate reads them oldest to newest and the last one wins).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function byPreference(int $tenantId, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('crm_consent_preferences')) {
            return [];
        }

        $latest = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('crm_consent_preferences')->where('entity_type', 'lead')->whereIn('entity_id', $chunk)
                ->where(fn ($t) => $t->where('sub_institute_id', $tenantId)->orWhereNull('sub_institute_id'))->orderBy('id')->get(['entity_id', 'channel', 'status']);
            foreach ($rows as $row) {
                if (strtolower((string) $row->channel) === 'email') {
                    $latest[(int) $row->entity_id] = strtolower(trim((string) $row->status));
                }
            }
        }

        return array_keys(array_filter($latest, fn (string $status) => $status === 'opted_out'));
    }

    /** @return array{email: array<string, true>, domain: array<string, true>} the organisation's suppression list */
    private static function suppressions(int $tenantId): array
    {
        $out = ['email' => [], 'domain' => []];
        if (! Schema::hasTable('gtm_suppressions')) {
            return $out;
        }
        foreach (DB::table('gtm_suppressions')->where('sub_institute_id', $tenantId)->whereIn('kind', ['email', 'domain'])->get(['kind', 'value']) as $row) {
            $out[(string) $row->kind][strtolower(trim((string) $row->value))] = true;
        }

        return $out;
    }

    /** @return array<string, true> the addresses of outreach contacts that are bounced, unsubscribed or do-not-contact */
    private static function blockedOutreachAddresses(int $tenantId): array
    {
        if (! Schema::hasTable('gtm_contacts')) {
            return [];
        }
        $out = [];
        foreach (DB::table('gtm_contacts')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->whereIn('status', OutreachMailer::BLOCKED_CONTACT)->pluck('email') as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '') {
                $out[$email] = true;
            }
        }

        return $out;
    }
}
