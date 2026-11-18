<?php

namespace App\Domain\Facts;

/**
 * Does what a source says about a person fit the contact we hold? (slice 4, the contact facts ledger). PURE text comparison: nothing here reads a page, a
 * profile or a database. A discovery source (slice 5) reads the source, hands over what it read and the claim it is checking, and this class answers.
 *
 * Ported from Comp AI's CRM research agent (MIT licence, copyright 2026 Comp AI: apps/agent/agent/lib/identity.ts `verdictFor` and apps/agent/agent/lib/names.ts).
 * The procedure is built to fail closed (its skill: "writing a stranger's career onto a customer's record is the failure to prevent"):
 *
 *   - the address on the person's OWN profile equals the address we hold: that settles it alone, nothing else observable is stronger;
 *   - otherwise the employer AND the name must both fit; one of the two is a different person who shares something ("medium": a suggestion, never a fact);
 *   - names are compared on letters and digits only (ASCII, like the original), so "Lewis J. Carhart" is "Lewis Carhart" and a near miss ("Lewis Carter") is not;
 *   - two web hosts are the same only when they are the same host: foo-bar.com is not foobar.com, mail.fernhill.com is not fernhill.com.
 *
 * Left out: `searchTerms` (it builds web-search queries for a lookup, which slice 5 owns) and `isDerivedName` (a first name derived from an email address by
 * a mail sync; G2G has none, and a CRM contact's last name is required).
 */
final class IdentityMatcher
{
    /**
     * @param  array{first_name?: ?string, last_name?: ?string, email?: ?string, roles?: list<array{organisation?: array{name?: ?string, domain?: ?string}}>}  $person  what the source says
     * @param  array{email: string, company_name: string, company_domain: string}  $claim  what we hold
     * @return array{email_matches: bool, employer_matches: bool, name_matches: bool, is_same_person: bool, confidence: string}
     */
    public static function verdict(array $person, array $claim): array
    {
        $local = (string) (explode('@', $claim['email'])[0] ?? '');

        $emailMatches = self::sameEmail($person['email'] ?? null, $claim['email']);
        $employerMatches = false;
        foreach ($person['roles'] ?? [] as $role) {
            $organisation = $role['organisation'] ?? [];
            if (self::sameDomain($organisation['domain'] ?? null, $claim['company_domain'])
                || self::looksLikeSameCompany((string) ($organisation['name'] ?? ''), $claim['company_name'], $claim['company_domain'])) {
                $employerMatches = true;
                break;
            }
        }
        $nameMatches = self::nameMatchesLocalPart(['first_name' => $person['first_name'] ?? null, 'last_name' => $person['last_name'] ?? null], $local);
        $isSamePerson = $emailMatches || ($employerMatches && $nameMatches);

        return [
            'email_matches' => $emailMatches,
            'employer_matches' => $employerMatches,
            'name_matches' => $nameMatches,
            'is_same_person' => $isSamePerson,
            'confidence' => $isSamePerson ? 'high' : ($employerMatches || $nameMatches ? 'medium' : 'low'),
        ];
    }

    /**
     * The evidence a verdict stands for, in the original skill's own table (what to report for what was seen): the address match is `profile.email-match`;
     * employer and name together are `linkedin.employer-and-name`; the employer alone is `employer-only`; a name alone is nothing worth keeping.
     *
     * @param  array{email_matches: bool, employer_matches: bool, name_matches: bool}  $verdict
     * @return list<array{kind: string, source_url: ?string, quote: ?string}>
     */
    public static function evidenceFor(array $verdict, ?string $sourceUrl = null, ?string $quote = null): array
    {
        $kind = match (true) {
            $verdict['email_matches'] => 'profile.email-match',
            $verdict['employer_matches'] && $verdict['name_matches'] => 'linkedin.employer-and-name',
            $verdict['employer_matches'] => 'employer-only',
            default => null,
        };

        return $kind === null ? [] : [['kind' => $kind, 'source_url' => $sourceUrl, 'quote' => $quote]];
    }

    /** Letters and digits only, lower case, ASCII. */
    public static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    /** The employer the source names against the company we hold: either contains the other, or the employer contains the domain's name part ("fernhill" of fernhill.com). */
    public static function looksLikeSameCompany(string $employer, string $companyName, string $domain): bool
    {
        $a = self::normalise($employer);
        $b = self::normalise($companyName);
        $c = self::normalise((string) preg_replace('/\.[a-z.]+$/', '', $domain));

        if ($a === '' || ($b === '' && $c === '')) {
            return false;
        }

        return ($b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a))) || ($c !== '' && str_contains($a, $c));
    }

    /** Whether a name could be the one behind an email local part: "pmarchetti" for Paula Marchetti, "janedoe", "nathan"; a stranger or a profile without a name is not. */
    public static function nameMatchesLocalPart(array $person, string $local): bool
    {
        $first = self::normalise((string) ($person['first_name'] ?? ''));
        $last = self::normalise((string) ($person['last_name'] ?? ''));
        $handle = self::normalise($local);

        if ($handle === '' || ($first === '' && $last === '')) {
            return false;
        }

        $forms = array_values(array_filter([
            $first . $last, $last . $first, substr($first, 0, 1) . $last, $last . substr($first, 0, 1), $first . substr($last, 0, 1), $first, $last,
        ], fn ($form) => $form !== ''));

        foreach ($forms as $form) {
            if ($form === $handle || str_starts_with($form, $handle) || str_starts_with($handle, $form)) {
                return true;
            }
        }

        return false;
    }

    /** "Paula Marchetti" => first and last ("Paula", "Marchetti"); one word => first only; nothing => null. */
    public static function splitName(string $fullName): ?array
    {
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $fullName));
        if ($cleaned === '') {
            return null;
        }
        $words = explode(' ', $cleaned);
        $first = array_shift($words);

        return ['first_name' => $first, 'last_name' => $words === [] ? null : implode(' ', $words)];
    }

    public static function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');

        return $at !== false && $at > 0 ? strtolower(substr($email, $at + 1)) : null;
    }

    /** The host of an address or of a bare domain, lower case, without a scheme, credentials, www or a trailing dot. */
    public static function hostOf(string $value): string
    {
        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return '';
        }
        $withoutScheme = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $trimmed);
        $host = explode('@', explode('/', $withoutScheme)[0]);
        $host = (string) end($host);

        return (string) preg_replace(['/^www\./', '/\.$/'], '', $host);
    }

    /** The same host, however it is written; never two hosts that merely look alike. */
    public static function sameDomain(?string $left, string $right): bool
    {
        if ($left === null || $left === '') {
            return false;
        }
        $a = self::hostOf($left);

        return $a !== '' && $a === self::hostOf($right);
    }

    /** The same name written two ways: all the words in order, or the same first and last word; a first name on its own never matches. */
    public static function namesMatch(?string $a, ?string $b): bool
    {
        $left = self::words($a);
        $right = self::words($b);

        if ($left === [] || $right === []) {
            return false;
        }
        if (implode('', $left) === implode('', $right)) {
            return true;
        }
        if (count($left) < 2 || count($right) < 2) {
            return false;
        }

        return $left[0] === $right[0] && end($left) === end($right);
    }

    private static function sameEmail(?string $left, string $right): bool
    {
        return $left !== null && $left !== '' && strtolower(trim($left)) === strtolower(trim($right));
    }

    /** @return list<string> */
    private static function words(?string $value): array
    {
        $words = array_map([self::class, 'normalise'], (array) preg_split('/\s+/', (string) $value));

        return array_values(array_filter($words, fn ($w) => strlen($w) > 1));
    }
}
