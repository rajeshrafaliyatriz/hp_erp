<?php

namespace App\Domain\Facts;

/**
 * What a fact's value IS, as text (slice 4, the contact facts ledger): checked against the kind of field it belongs to, tidied into the form that is
 * stored and shown, and reduced to a CANONICAL form whose hash is the key of "this value exists once for this record and field". That key is what makes
 * "a dismissed value is never offered again" a database rule: the same value written another way (a trailing slash, www, twitter.com for x.com, capitals,
 * a query string) has the same canonical form, so it is the same value.
 *
 * `canonical()` is a port of `canonicalValue`/`sameValue` in Comp AI's CRM research agent (MIT licence, copyright 2026 Comp AI:
 * apps/agent/agent/lib/facts.ts). The checks per field type are G2G's: every value may come from the web, so it is DATA. It is stored as text and nothing
 * here ever interprets it: a web address is limited to http and https, an email address must be one, and free text may not start like a spreadsheet formula
 * (the CRM exports contacts to CSV).
 */
final class FactValue
{
    private const HOST_ALIASES = ['twitter.com' => 'x.com', 'mobile.twitter.com' => 'x.com'];

    /**
     * Checks and tidies a value of a field type.
     *
     * @return array{display: string, canonical: string}  display is what is stored and written to the record; canonical is what two values are compared by
     *
     * @throws InvalidFactValue with a message for a person
     */
    public static function normalise(string $type, string $raw, int $max): array
    {
        $result = match ($type) {
            'email' => self::email($raw),
            'phone' => self::phone($raw),
            'url' => self::url($raw),
            'linkedin', 'x', 'github' => self::social($type, $raw),
            'text' => self::text($raw),
            default => throw new InvalidFactValue("The field type '{$type}' is not known."),
        };
        if (mb_strlen($result['display']) > $max) {
            throw new InvalidFactValue("The value is longer than the {$max} characters this field holds.");
        }

        return $result;
    }

    /** The key of a value for the unique index: sha1 of the canonical form. */
    public static function hash(string $canonical): string
    {
        return sha1($canonical);
    }

    /** Comp AI's `canonicalValue`: trimmed, whitespace collapsed, lower case; a web address is reduced to host (no www, twitter is x) and path (no trailing slash, no query). */
    public static function canonical(string $value): string
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        if (! preg_match('#^https?://#', $text)) {
            return $text;
        }
        $parts = parse_url($text);
        if ($parts === false || ! isset($parts['host'])) {
            return $text;
        }
        $host = (string) preg_replace('/^www\./', '', $parts['host']);
        $default = ($parts['scheme'] ?? '') === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int) $parts['port'] !== $default) {
            $host .= ':' . $parts['port'];
        }
        $path = (string) preg_replace('#/+$#', '', (string) ($parts['path'] ?? ''));

        return (self::HOST_ALIASES[$host] ?? $host) . $path;
    }

    public static function same(string $a, string $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /** The comparison key of an address in a list of evidence: the canonical form, or '' for nothing. */
    public static function sameValueKey(string $value): string
    {
        return self::canonical($value);
    }

    /** `role` for the address of a role (principal@, admissions@), `personal` for any other: slice 5 sends to role mailboxes by default. */
    public static function emailKind(string $email): string
    {
        $local = mb_strtolower((string) strstr($email, '@', true));

        return in_array($local, (array) config('facts.role_mailboxes'), true) ? 'role' : 'personal';
    }

    /** @return array{display: string, canonical: string} */
    private static function email(string $raw): array
    {
        $value = mb_strtolower(trim($raw));
        if (str_starts_with($value, 'mailto:')) {
            $value = (string) preg_replace('/\?.*$/', '', substr($value, 7));
        }
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidFactValue('That is not an email address.');
        }

        return ['display' => $value, 'canonical' => $value];
    }

    /** @return array{display: string, canonical: string} */
    private static function phone(string $raw): array
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $digits = (string) preg_replace('/\D+/', '', $value);
        if (! preg_match('/^\+?[\d\s().\-]+$/', $value) || strlen($digits) < 6 || strlen($digits) > 15) {
            throw new InvalidFactValue('That is not a phone number (6 to 15 digits, with an optional + at the start).');
        }

        return ['display' => $value, 'canonical' => (str_starts_with($value, '+') ? '+' : '') . $digits];
    }

    /** @return array{display: string, canonical: string} */
    private static function url(string $raw): array
    {
        $value = trim($raw);
        if (preg_match('#^([a-z][a-z0-9+.\-]*):(?!\d)#i', $value, $scheme) && ! in_array(strtolower($scheme[1]), ['http', 'https'], true) && ! str_contains($scheme[1], '.')) {
            throw new InvalidFactValue('Only web addresses (http or https) are accepted.');
        }
        $parts = parse_url(preg_match('#^https?://#i', $value) ? $value : 'https://' . $value);
        $host = $parts === false ? '' : strtolower((string) ($parts['host'] ?? ''));
        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass']) || ! str_contains($host, '.') || ! preg_match('/^[a-z0-9.\-]+$/', $host)) {
            throw new InvalidFactValue('That is not a web address.');
        }
        $display = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim((string) ($parts['path'] ?? ''), '/');

        return ['display' => $display, 'canonical' => self::canonical($display)];
    }

    /** @return array{display: string, canonical: string} */
    private static function social(string $type, string $raw): array
    {
        $profile = SocialUrl::parse($raw);
        if ($profile === null || $profile['network'] !== $type) {
            $name = ['linkedin' => 'LinkedIn', 'x' => 'X (Twitter)', 'github' => 'GitHub'][$type];
            throw new InvalidFactValue("That is not a {$name} profile address.");
        }

        return ['display' => $profile['url'], 'canonical' => self::canonical($profile['url'])];
    }

    /** @return array{display: string, canonical: string} */
    private static function text(string $raw): array
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]+/u', ' ', $raw)));
        if ($value === '') {
            throw new InvalidFactValue('The value is empty.');
        }
        // A cell that starts like a formula runs as one when the CRM's CSV export is opened in a spreadsheet.
        if (preg_match('/^[=@]/', $value) || preg_match('/^[+\-][^\p{L}\s]/u', $value)) {
            throw new InvalidFactValue('A value cannot start with =, @, + or - followed by a symbol or a digit.');
        }

        return ['display' => $value, 'canonical' => self::canonical($value)];
    }
}
