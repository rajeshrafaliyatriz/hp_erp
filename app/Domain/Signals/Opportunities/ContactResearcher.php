<?php

namespace App\Domain\Signals\Opportunities;

/**
 * Finds publicly listed contacts for the organisation behind a signal, using the web-search provider G2G already has (Tavily).
 *
 * Ported from the free enrichment steps of the Company Sales Intelligence Platform (find a person by role and company, find a published phone,
 * find a published email at the company's domain), with two deliberate differences:
 *   - it NEVER generates "pattern guess" emails (first.last@domain). A value is returned only if a search result printed it, together with that
 *     result's URL;
 *   - a result is used only if it is about THIS organisation (its domain, or its name in the result), and a person only if their title is a
 *     decision-maker role, so unrelated employees of other companies are not returned.
 *
 * Nothing it returns is verified: every contact carries `verification_status = unverified` and the page it came from.
 * It does not read or change a signal, its qualification or its score, and a search failure only means fewer (or no) contacts.
 */
final class ContactResearcher
{
    /** Titles that make someone a person worth contacting about a purchase. */
    private const DECISION_ROLE = '/\b(principal|vice[\s\-]?principal|director|founder|co[\s\-]?founder|owner|ceo|chief|managing director|md|chairman|chairperson|chair|trustee|correspondent|president|vice[\s\-]?president|vp|head|headmaster|headmistress|administrator|secretary|commissioner|registrar|dean|cto|coo|cfo|general manager|gm|manager|officer)\b/i';

    /** Hosts that list many organisations or only carry a profile: never taken as the organisation's own website. */
    private const NOT_A_WEBSITE = '/(^|\.)(linkedin|facebook|instagram|youtube|twitter|x|wikipedia|justdial|indiamart|sulekha|glassdoor|naukri|ambitionbox|zaubacorp|tofler|crunchbase|yellowpages|schoolmykids|eduvidya|shiksha|careers360|collegedunia|google|bing|pinterest|quora|medium)\.[a-z.]+$/i';

    private const COMPANY_NOISE = ['school', 'schools', 'public', 'pvt', 'ltd', 'private', 'limited', 'the', 'of', 'and', 'group', 'international', 'education', 'educational', 'institute', 'institutions', 'academy', 'senior', 'secondary', 'college', 'university', 'trust', 'society'];

    private int $searches = 0;

    /** @var int how many searches failed (provider error), so the caller can tell "found nothing" from "could not search". */
    public int $failures = 0;

    public function __construct(private ?WebSearchProvider $search = null, private int $maxSearches = 6)
    {
    }

    /**
     * The titles to look for, most relevant first: the signal's own "likely stakeholder", then titles that fit the kind of organisation (a school is
     * approached through its principal; a government body or a company through its head and its administration), then the configured extras.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    public static function rolesFor(?string $organisationType, ?string $companyName, ?string $stakeholder, array $extra = []): array
    {
        $hay = strtolower(trim((string) $organisationType . ' ' . (string) $companyName));
        $byType = match (true) {
            preg_match('/school|college|universit|institut|educat|academy|vidyal|shiksha/', $hay) === 1 => ['Principal', 'Director', 'Founder'],
            // A company or a government body is approached through its head AND through whoever runs HR, so the second title searched is always the HR head.
            preg_match('/gov|psu|public sector|municipal|corporation|department|authority|board|ministry|commission|nigam|parishad|limited|\bltd\b/', $hay) === 1 => ['Managing Director', 'HR Head', 'Chairman', 'General Manager'],
            default => ['CEO', 'HR Head', 'Managing Director', 'Director'],
        };
        $stake = $stakeholder !== null ? trim(preg_replace('/\s+/', ' ', $stakeholder)) : '';

        return array_values(array_unique(array_filter(array_merge($stake !== '' && mb_strlen($stake) <= 60 ? [$stake] : [], $byType, $extra))));
    }

    public function searchesUsed(): int
    {
        return $this->searches;
    }

    /**
     * @param  array{company_name: string, website?: ?string, location?: ?string, roles?: list<string>}  $ctx
     * @return list<array<string, mixed>>  contacts, each with only the fields that were actually found
     */
    public function research(array $ctx): array
    {
        $company = trim((string) ($ctx['company_name'] ?? ''));
        if ($company === '' || $this->search === null || ! $this->search->isConfigured()) {
            return [];
        }
        $location = trim((string) ($ctx['location'] ?? ''));
        $domain = self::domainOf($ctx['website'] ?? null);
        $website = $domain !== null ? ($ctx['website'] ?: 'https://' . $domain) : null;

        // 1. The organisation's own website, when the signal did not carry one (source: get_company_website_info).
        if ($domain === null) {
            [$domain, $website] = $this->findWebsite($company, $location);
        }

        $contacts = [];

        // 2. The organisation's published contact line: phone and email printed on its own pages (source: find_phone_free / find_email_free).
        if (($org = $this->organisationContact($company, $domain, $location)) !== null) {
            $contacts[] = $org + ['website' => $website, 'location' => $location ?: null];
        }

        // 2b. The organisation's own public profile pages (LinkedIn company page, Facebook, Instagram): only addresses a search result returned, and only for a result about THIS organisation.
        foreach ($this->socialProfiles($company, $domain, $location) as $profile) {
            $contacts[] = $profile + ['website' => $profile['website'] ?? $website, 'location' => $location ?: null];
        }

        // 3. Decision-makers by role (source: find_contact_person_free), then a published email for each at the company domain.
        $people = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('trim', $ctx['roles'] ?? [])))), 0, 3) as $role) {
            foreach ($this->peopleForRole($company, $domain, $location, $role) as $p) {
                $people[strtolower($p['full_name'])] ??= $p;
            }
            if (count($people) >= 3) {
                break;
            }
        }
        $emailLookups = 0;
        foreach ($people as $p) {
            if ($domain !== null && $emailLookups < 2) {
                $emailLookups++;
                if (($found = $this->emailFor($p['full_name'], $domain)) !== null) {
                    $p['email'] = $found['email'];
                    $p['email_source_url'] = $found['url'];
                }
            }
            $contacts[] = $p + ['website' => $website, 'location' => $location ?: null];
        }

        return array_values(array_filter($contacts, fn ($c) => ! empty($c['email']) || ! empty($c['phone']) || ! empty($c['full_name']) || ! empty($c['linkedin_url'])
            || (! empty($c['source_url']) && ! empty($c['website']) && str_starts_with((string) ($c['designation'] ?? ''), 'Company '))));
    }

    /** @return array{0: ?string, 1: ?string} the domain and website URL of the organisation's own site, only if a result clearly belongs to it */
    private function findWebsite(string $company, string $location): array
    {
        foreach ($this->query(trim("{$company} official website {$location}"), []) as $r) {
            $host = self::hostOf($r['url']);
            if ($host === null || preg_match(self::NOT_A_WEBSITE, $host) === 1) {
                continue;
            }
            if ($this->mentionsCompany($company, $r['title'] . ' ' . $host)) {
                return [self::domainOf($r['url']), (string) preg_replace('~^(https?://[^/]+).*$~i', '$1', $r['url'])];
            }
        }

        return [null, null];
    }

    /** @return ?array<string, mixed> */
    private function organisationContact(string $company, ?string $domain, string $location): ?array
    {
        $results = $this->query(trim("{$company} contact us phone number email {$domain} {$location}"), $domain !== null ? ['include_domains' => [$domain]] : []);
        $emails = $phones = [];
        foreach ($results as $r) {
            $own = $domain !== null && self::hostMatches(self::hostOf($r['url']), $domain);
            $about = $own || $this->mentionsCompany($company, $r['title'] . ' ' . $r['excerpt']);
            if (! $about) {
                continue;
            }
            $found = SignalContact::extract($r['title'] . "\n" . $r['excerpt']);
            foreach ($found['emails'] as $e) {
                $ed = strtolower(substr(strrchr($e, '@'), 1));
                if ($own || ($domain !== null && self::hostMatches($ed, $domain))) {
                    $emails[strtolower($e)] ??= ['email' => $e, 'url' => $r['url'], 'on_domain' => $domain !== null && self::hostMatches($ed, $domain)];
                }
            }
            foreach ($found['phones'] as $p) {
                $phones[$p] ??= ['phone' => $p, 'url' => $r['url']];
            }
        }
        if ($emails === [] && $phones === []) {
            return null;
        }
        usort($emails, fn ($a, $b) => (int) $b['on_domain'] <=> (int) $a['on_domain']);
        $email = $emails[0] ?? null;
        $phone = array_values($phones)[0] ?? null;

        return array_filter([
            'full_name' => null, 'designation' => 'Organisation contact (published)',
            'email' => $email['email'] ?? null, 'email_source_url' => $email['url'] ?? null,
            'phone' => $phone['phone'] ?? null, 'phone_source_url' => $phone['url'] ?? null,
            'source_url' => ($email['url'] ?? null) ?: ($phone['url'] ?? null), 'role_searched' => 'organisation',
        ], fn ($v) => $v !== null);
    }

    /**
     * The organisation's own LinkedIn company page, Facebook page and Instagram account, from one search. A result counts only if it is a PROFILE address
     * (not a post, a share link or a login page) and its title, text or address names this organisation. Nothing is guessed or built from the name.
     *
     * @return list<array<string, mixed>>
     */
    private function socialProfiles(string $company, ?string $domain, string $location): array
    {
        $out = [];
        $seen = [];
        foreach ($this->query(trim("\"{$company}\" official LinkedIn Facebook Instagram page {$location}"), []) as $r) {
            $host = self::hostOf($r['url']);
            if ($host === null || ! $this->mentionsCompany($company, $r['title'] . ' ' . $r['excerpt'] . ' ' . str_replace(['-', '_', '.', '/'], ' ', (string) $r['url']))) {
                continue;
            }
            $clean = (string) preg_replace('~^(https?://[^?#]+?)/?([?#].*)?$~', '$1', $r['url']);
            if (preg_match('/(^|\.)linkedin\.com$/i', $host) && preg_match('~^https?://[^/]+/company/[A-Za-z0-9_%\-\.]+$~i', $clean) && ! isset($seen['linkedin'])) {
                $seen['linkedin'] = true;
                $out[] = ['designation' => 'Company LinkedIn page', 'linkedin_url' => $clean, 'source_url' => $clean];
            } elseif (preg_match('/(^|\.)facebook\.com$/i', $host) && preg_match('~^https?://[^/]+/(?!sharer|share|login|groups|events|watch|public|policies|help|marketplace|hashtag|photo|story|permalink)[A-Za-z0-9.\-_]{3,}$~i', $clean) && ! isset($seen['facebook'])) {
                $seen['facebook'] = true;
                $out[] = ['designation' => 'Company Facebook page', 'website' => $clean, 'source_url' => $clean];
            } elseif (preg_match('/(^|\.)instagram\.com$/i', $host) && preg_match('~^https?://[^/]+/(?!p/|reel|reels|explore|accounts|stories|tv/|about|directory)[A-Za-z0-9._]{3,}$~i', $clean) && ! isset($seen['instagram'])) {
                $seen['instagram'] = true;
                $out[] = ['designation' => 'Company Instagram account', 'website' => $clean, 'source_url' => $clean];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function peopleForRole(string $company, ?string $domain, string $location, string $role): array
    {
        $out = [];
        foreach ($this->query(trim("\"{$role}\" \"{$company}\" linkedin OR profile OR name {$location}"), []) as $r) {
            $text = $r['title'] . "\n" . $r['excerpt'];
            $host = self::hostOf($r['url']);
            if ($host !== null && preg_match('/(^|\.)linkedin\.com$/i', $host) && preg_match('~/in/[A-Za-z0-9_%\-]+~', $r['url'])) {
                if (! $this->mentionsCompany($company, $text) || ($p = self::personFromLinkedInTitle($r['title'])) === null) {
                    continue;
                }
                $out[] = $p + ['linkedin_url' => (string) preg_replace('~^(https?://[^?#]+).*$~', '$1', $r['url']), 'source_url' => $r['url'], 'role_searched' => $role];

                continue;
            }
            // A person named with a title on the organisation's own pages (team / about / leadership).
            if ($domain !== null && self::hostMatches($host, $domain)) {
                foreach (self::peopleFromOwnPage($text) as $p) {
                    $out[] = $p + ['source_url' => $r['url'], 'role_searched' => $role];
                }
            }
        }

        return $out;
    }

    /** "Asha Rao - Principal - Sunrise Public School | LinkedIn" => name and title, only when the title is a decision-maker role. */
    public static function personFromLinkedInTitle(string $title): ?array
    {
        $t = trim(preg_replace('/\s*[|\-–—]\s*LinkedIn\s*$/iu', '', $title));
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s+[-–—|]\s+/u', $t))));
        if (count($parts) < 2) {
            return null;
        }
        $name = preg_replace('/^(dr|mr|mrs|ms|prof|shri|smt)\.?\s+/i', '', $parts[0]);
        if (! preg_match('/^[\p{L}][\p{L}\.\' ]{2,58}$/u', $name) || count(preg_split('/\s+/', trim($name))) < 2 || count(preg_split('/\s+/', trim($name))) > 4) {
            return null;
        }
        $designation = $parts[1];
        if (preg_match(self::DECISION_ROLE, $designation) !== 1) {
            return null;
        }

        return ['full_name' => $name, 'designation' => mb_substr($designation, 0, 190)];
    }

    /** @return list<array{full_name: string, designation: string}> */
    public static function peopleFromOwnPage(string $text): array
    {
        $out = [];
        if (preg_match_all('/(?:(?:Dr|Mr|Mrs|Ms|Prof|Shri|Smt)\.?\s+)?([A-Z][a-z]+(?:\s[A-Z][a-z]+){1,2})\s*[,\-–|(:]\s*((?:Vice[\s\-]?Principal|Principal|Managing Director|Chief Executive Officer|Chief People Officer|Chief Human Resources Officer|CHRO|HR Head|Director|Co-?Founder|Founder|Chairman|Chairperson|CEO|Owner|Trustee|Correspondent|Headmaster|Headmistress|Head of [A-Za-z ]{2,25}))/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[strtolower($x[1])] ??= ['full_name' => trim($x[1]), 'designation' => trim($x[2])];
            }
        }

        return array_values($out);
    }

    /** @return ?array{email: string, url: string} an email printed in a result that also names the person, at the company's own domain. */
    private function emailFor(string $fullName, string $domain): ?array
    {
        $parts = preg_split('/\s+/', strtolower(trim($fullName)));
        $last = end($parts);
        foreach ($this->query("\"{$fullName}\" email {$domain}", []) as $r) {
            $text = $r['title'] . "\n" . $r['excerpt'];
            if ($last === false || ! str_contains(strtolower($text), $last)) {
                continue;
            }
            foreach (SignalContact::extract($text)['emails'] as $e) {
                if (self::hostMatches(strtolower(substr(strrchr($e, '@'), 1)), $domain)) {
                    return ['email' => $e, 'url' => $r['url']];
                }
            }
        }

        return null;
    }

    /** @return list<array{url: string, title: string, excerpt: string}> empty when the budget is used up or the search fails */
    private function query(string $query, array $extra): array
    {
        if ($this->search === null || $this->searches >= $this->maxSearches) {
            return [];
        }
        $this->searches++;
        try {
            return array_map(fn ($r) => ['url' => (string) $r['url'], 'title' => (string) $r['title'], 'excerpt' => (string) ($r['excerpt'] ?? '')],
                $this->search->search($query, 365, 8, ['topic' => 'general'] + $extra));
        } catch (\Throwable) {
            $this->failures++;

            return [];
        }
    }

    /** True when the result text names this organisation: all (up to two) of its distinctive words appear. */
    private function mentionsCompany(string $company, string $text): bool
    {
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($company)), fn ($w) => strlen($w) >= 3 && ! in_array($w, self::COMPANY_NOISE, true)));
        if ($words === []) {
            return false;
        }
        $hay = strtolower($text);
        foreach (array_slice($words, 0, 2) as $w) {
            if (! str_contains($hay, $w)) {
                return false;
            }
        }

        return true;
    }

    public static function hostOf(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return $host ? strtolower((string) preg_replace('/^www\./i', '', $host)) : null;
    }

    public static function domainOf(?string $url): ?string
    {
        $v = trim((string) $url);
        if ($v === '') {
            return null;
        }
        $host = self::hostOf(str_contains($v, '://') ? $v : 'https://' . $v);

        return $host !== null && str_contains($host, '.') ? $host : null;
    }

    /** The host is the domain or a sub-domain of it. */
    public static function hostMatches(?string $host, ?string $domain): bool
    {
        return $host !== null && $domain !== null && ($host === $domain || str_ends_with($host, '.' . $domain));
    }
}
