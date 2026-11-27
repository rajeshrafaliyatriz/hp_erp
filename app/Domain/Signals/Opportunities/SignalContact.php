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
        $prefixed = false;   // the number itself carried +91 / 91 or a leading 0, so it was written as a telephone number, not found as a bare run of digits
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
            $prefixed = true;
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
            $prefixed = true;
        }
        // An Indian landline (STD code + number, 10 digits starting 1-5) is a real business line, but only when it was written WITH its +91 or leading 0:
        // a bare ten-digit run of numbers is still not accepted, because that is also what dates, ids and reference numbers look like.
        if ($prefixed && preg_match('/^[1-5]\d{9}$/', $digits)) {
            return '+91' . $digits;
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

    /** Columns written by our own AI/templates about the signal. They can quote our own product or contacts, so they are never evidence of the prospect's contact. */
    private const NOT_EVIDENCE = ['recommended_action', 'soft_marketing_angle', 'what_we_could_sell', 'product_fit', 'assessment', 'business_fit', 'entry_point',
        'partner_route_note', 'score_reasoning', 'review_notes', 'relevant_offer_name', 'product_name', 'likely_problem', 'likely_stakeholder'];

    /** Every piece of text stored with the signal: all its own text columns and its sources, however deeply nested, plus any extra text passed in. */
    public static function evidenceText(object $row, array $extra = []): string
    {
        $parts = [];
        $walk = function ($value, int $depth) use (&$walk, &$parts): void {
            if ($depth > 6 || $value === null) {
                return;
            }
            if (is_string($value)) {
                $t = trim($value);
                if ($t !== '' && ($t[0] === '[' || $t[0] === '{') && is_array($j = json_decode($t, true))) {
                    $walk($j, $depth + 1);

                    return;
                }
                $parts[] = $value;

                return;
            }
            if (is_array($value) || is_object($value)) {
                foreach ((array) $value as $v) {
                    $walk($v, $depth + 1);
                }
            }
        };
        foreach (get_object_vars($row) as $k => $v) {
            if (! in_array($k, self::NOT_EVIDENCE, true)) {
                $walk($v, 0);
            }
        }
        $walk($extra, 0);

        return mb_substr(implode("\n", $parts), 0, 200000);
    }

    /** Own-organisation domains whose addresses are never the prospect's (comma separated in SIGNALS_OWN_CONTACT_DOMAINS). */
    private static function ownDomains(): array
    {
        try {
            $raw = (string) config('signals_connectors.own_contact_domains', '');
        } catch (\Throwable) {
            $raw = '';   // no application container (a plain unit test): nothing configured
        }

        return array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $raw))));
    }

    private static function domainOf(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST) ?: (string) $url;
        $host = strtolower(preg_replace('/^www\./i', '', trim($host)));

        return $host !== '' && str_contains($host, '.') ? $host : null;
    }

    /** Emails from the evidence: our own domains dropped, the company's own domain first. */
    private static function rankEmails(array $emails, ?string $companySite): array
    {
        $own = self::ownDomains();
        $emails = array_values(array_filter($emails, function ($e) use ($own) {
            $d = strtolower(substr(strrchr($e, '@'), 1));
            foreach ($own as $o) {
                if ($d === $o || str_ends_with($d, '.' . $o)) {
                    return false;
                }
            }

            return true;
        }));
        $site = self::domainOf($companySite);
        if ($site !== null) {
            usort($emails, fn ($a, $b) => (int) (substr(strrchr($b, '@'), 1) === $site || str_ends_with(substr(strrchr($b, '@'), 1), '.' . $site))
                <=> (int) (substr(strrchr($a, '@'), 1) === $site || str_ends_with(substr(strrchr($a, '@'), 1), '.' . $site)));
        }

        return $emails;
    }

    /**
     * @param  object       $row
   the signal row (company_name, company_website, company_location, source_url, evidence text columns)
     * @param  object|null  $lead  the CRM lead promoted from this signal, if any (tenant-scoped by the caller)
     * @param  array|null   $account  the target account (contact_name, city) behind the organisation, if any
     * @param  list<array>  $sources  the signal's source list (each may carry a url/title)
     */
    public static function build(object $row, ?object $lead = null, ?array $account = null, array $sources = [], array $extraText = [], array $stored = [], ?array $research = null): array
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

        // Contacts found on the public web for this organisation (a CRM lead typed by a person still wins). Each value keeps the page that printed it.
        foreach ($stored as $s) {
            $src = fn (?string $own) => ['status' => 'found_public', 'label' => 'Found on the public web', 'url' => $own ?: ($s['source_url'] ?? null)];
            if ($email === null && self::validEmail($s['email'] ?? null)) {
                $email = $s['email'];
                $emailFrom = $src($s['email_source_url'] ?? null);
            }
            if ($phone === null && self::normalisePhone($s['phone'] ?? null) !== null) {
                $phone = self::normalisePhone($s['phone']);
                $phoneFrom = $src($s['phone_source_url'] ?? null);
            }
            if ($name === null && ! empty($s['full_name'])) {
                $name = $s['full_name'];
                $designation ??= ($s['designation'] ?? null) ?: null;
            }
            if ($linkedin === null && ! empty($s['linkedin_url'])) {
                $linkedin = $s['linkedin_url'];
            }
        }

        // What the signal's own evidence printed. Never used to overwrite a value a person entered.
        $evidenceText = self::evidenceText($row, array_merge($sources, $extraText));
        if ($email === null || $phone === null) {
            $found = self::extract($evidenceText);
            $found['emails'] = self::rankEmails($found['emails'], $row->company_website ?? null);
            if ($email === null && $found['emails'] !== []) {
                $email = $found['emails'][0];
                $emailFrom = ['status' => 'stated_in_source', 'label' => 'Stated in the signal\'s source', 'url' => $sourceUrl];
            }
            if ($phone === null && $found['phones'] !== []) {
                $phone = $found['phones'][0];
                $phoneFrom = ['status' => 'stated_in_source', 'label' => 'Stated in the signal\'s source', 'url' => $sourceUrl];
            }
        }

        if (preg_match('~https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:in|company|school)/[A-Za-z0-9_%\-]+/?~i', $evidenceText, $lm)) {
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
            'contacts' => array_map(fn ($s) => [
                'name' => $s['full_name'] ?? null, 'designation' => $s['designation'] ?? null, 'email' => self::validEmail($s['email'] ?? null) ? $s['email'] : null,
                'phone' => self::normalisePhone($s['phone'] ?? null), 'linkedin' => $s['linkedin_url'] ?? null, 'source_url' => $s['source_url'] ?? null,
                'email_source_url' => $s['email_source_url'] ?? null, 'phone_source_url' => $s['phone_source_url'] ?? null, 'verification' => 'unverified',
                // Every detail that was saved is sent, so none is hidden: the address and the website or profile page of this contact too.
                'website' => ($s['website'] ?? null) ?: null, 'location' => ($s['location'] ?? null) ?: null,
            ], $stored),
            'research' => $research,
            'verified' => false,
            'message' => $missing === []
                ? 'Phone and email are present. They are not independently verified: check the source before outreach.'
                : 'Not available: ' . implode(' and ', $missing) . '. None was found in the signal\'s source or in a CRM record, and none is guessed. Add the lead to the CRM or open the source to find it.',
        ];
    }
}
