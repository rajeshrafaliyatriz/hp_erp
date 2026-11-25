<?php

namespace App\Domain\Signals\Opportunities;

/**
 * The contact details shown on a signal.
 *
 * This only ever REPORTS what is in the data: it never guesses, completes or invents an email or a phone number. A detail comes from
 *   - the CRM lead created from the signal (a person typed it: shown as `crm_record`), or
 *   - the signal's own evidence text, where the source itself printed it (shown as `stated_in_source`, with that source's URL).
 * Neither is independently verified by this code, so `verified` is always false and the status says where the value came from.
 *
 * It never changes whether a signal is qualified or shown: it only adds a `contact` block. A signal with no phone or email still
 * carries every other field and a plain statement of what is missing.
 */
final class SignalContact
{
    /** Local parts / domains that are never a person's contact address. */
    private const IGNORED_EMAIL = '/^(no-?reply|donotreply|do-not-reply|mailer-daemon|postmaster)@|@(example|test|invalid|localhost)\b|\.(png|jpe?g|gif|svg|webp|css|js)$/i';

    public static function validEmail(?string $value): bool
    {
        $v = trim((string) $value);

        return $v !== '' && strlen($v) <= 191 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false && ! preg_match(self::IGNORED_EMAIL, $v);
    }

    /** A usable number: India (10-digit mobile starting 6-9, with or without +91 / 0) or an explicit international +number. */
    public static function normalisePhone(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '91')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
        }
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? '+91' . $digits : null;
    }

    public static function validPhone(?string $value): bool
    {
        return self::normalisePhone($value) !== null;
    }

    /** @return array{emails: list<string>, phones: list<string>} exactly what the text contains, validated, in order of appearance. */
    public static function extract(string $text): array
    {
        $emails = [];
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $text, $m)) {
            foreach ($m[0] as $e) {
                $e = rtrim($e, '.');
                if (self::validEmail($e)) {
                    $emails[strtolower($e)] = $e;
                }
            }
        }
        $phones = [];
        if (preg_match_all('/(?<![\d\w])(?:\+?91[\s\-]?|0)?[6-9]\d{4}[\s\-]?\d{5}(?!\d)|(?<![\d\w])\+\d{1,3}[\s\-]?\d{6,12}(?!\d)/', $text, $m)) {
            foreach ($m[0] as $p) {
                if (($n = self::normalisePhone($p)) !== null) {
                    $phones[$n] = $n;
                }
            }
        }

        return ['emails' => array_values($emails), 'phones' => array_values($phones)];
    }

    /**
     * @param  object       $row   the signal row (company_name, company_website, company_location, source_url, evidence text columns)
     * @param  object|null  $lead  the CRM lead promoted from this signal, if any (tenant-scoped by the caller)
     * @param  array|null   $account  the target account (contact_name, city) behind the organisation, if any
     * @param  list<array>  $sources  the signal's source list (each may carry a url/title)
     */
    public static function build(object $row, ?object $lead = null, ?array $account = null, array $sources = []): array
    {
        $sourceUrl = $row->source_url ?? ($sources[0]['url'] ?? null);

        $name = $designation = $email = $phone = $linkedin = null;
        $emailFrom = $phoneFrom = null;

        if ($lead) {
            $leadName = trim(((string) ($lead->first_name ?? '')) . ' ' . ((string) ($lead->last_name ?? '')));
            $name = $leadName !== '' ? $leadName : null;
            $designation = ($lead->designation ?? null) ?: null;
            if (self::validEmail($lead->email ?? null)) {
                $email = trim((string) $lead->email);
                $emailFrom = ['status' => 'crm_record', 'label' => 'CRM lead ' . ($lead->lead_no ?? ''), 'url' => null];
            }
            foreach ([$lead->mobile ?? null, $lead->phone ?? null] as $candidate) {
                if (($n = self::normalisePhone($candidate)) !== null) {
                    $phone = $n;
                    $phoneFrom = ['status' => 'crm_record', 'label' => 'CRM lead ' . ($lead->lead_no ?? ''), 'url' => null];
                    break;
                }
            }
        }
        $name ??= ($account['contact_name'] ?? null) ?: null;

        // What the signal's own evidence printed. Never used to overwrite a value a person entered.
        if ($email === null || $phone === null) {
            $found = self::extract(implode("\n", array_filter([
                $row->observed_event ?? null, $row->why_indicates_need ?? null, $row->evidence_note ?? null,
                $row->trigger_summary ?? null, $row->source_title ?? null,
                is_string($row->confirmed_facts ?? null) ? $row->confirmed_facts : null,
            ])));
            if ($email === null && $found['emails'] !== []) {
                $email = $found['emails'][0];
                $emailFrom = ['status' => 'stated_in_source', 'label' => 'Stated in the signal\'s source', 'url' => $sourceUrl];
            }
            if ($phone === null && $found['phones'] !== []) {
                $phone = $found['phones'][0];
                $phoneFrom = ['status' => 'stated_in_source', 'label' => 'Stated in the signal\'s source', 'url' => $sourceUrl];
            }
        }

        $evidence = implode("\n", array_filter([$row->observed_event ?? null, $row->evidence_note ?? null, $row->source_url ?? null, $row->source_title ?? null]));
        if (preg_match('~https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:in|company|school)/[A-Za-z0-9_%\-]+/?~i', $evidence, $lm)) {
            $linkedin = $lm[0];
        }

        $location = array_filter([
            'city' => ($lead->city ?? null) ?: ($account['city'] ?? null),
            'state' => ($lead->state ?? null) ?: ($row->company_state ?? null),
            'country' => ($lead->country ?? null) ?: null,
        ]);
        $website = ($lead->website ?? null) ?: ($row->company_website ?? null);

        $missing = [];
        $email === null && $missing[] = 'email address';
        $phone === null && $missing[] = 'phone number';
        $status = $missing === [] ? 'complete' : (count($missing) === 2 ? 'not_available' : 'partial');

        return [
            'status' => $status,
            'name' => $name,
            'designation' => $designation,
            'company' => $row->company_name ?? null,
            'email' => $email,
            'phone' => $phone,
            'website' => $website ?: null,
            'linkedin' => $linkedin,
            'location' => $location === [] ? (($row->company_location ?? null) ? ['text' => $row->company_location] : []) : $location,
            'email_source' => $emailFrom,
            'phone_source' => $phoneFrom,
            'verified' => false,
            'message' => $missing === []
                ? 'Phone and email are present. They are not independently verified: check the source before outreach.'
                : 'Not available: ' . implode(' and ', $missing) . '. None was found in the signal\'s source or in a CRM record, and none is guessed. Add the lead to the CRM or open the source to find it.',
        ];
    }
}
