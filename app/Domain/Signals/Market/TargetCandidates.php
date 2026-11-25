<?php

namespace App\Domain\Signals\Market;

use App\Domain\Signals\Support\ForeignCountryHint;

/**
 * Proposes organisations worth TRACKING, from signals already stored for ONE product and ONE customer model. Pure: no database, clock,
 * network or provider. It returns a list for a person to review; nothing is saved or loaded anywhere.
 *
 * Name variants merge with SignalDeduplicator::sameCompany ("Cactus" and "Cactus Communications" are one candidate). Names already tracked,
 * data-quality notes (signal_kind public_reporting) and rows with no company name are left out. A website is only ever a value that is
 * present in the input; nothing is guessed.
 */
final class TargetCandidates
{
    public const STATUS = 'proposed_for_review';

    private const LEVEL_RANK = [EvidenceMaturity::E3 => 4, EvidenceMaturity::E2 => 3, EvidenceMaturity::E1 => 2, EvidenceMaturity::E0 => 1, EvidenceMaturity::NOT_ASSESSED => 0];

    private const GOV_NAME = '/\b(ministry|department|authority|commission|board|corporation)\b/i';

    /**
     * @param  list<array<string, mixed>>  $signals  company, website (or domain), source_url, signal_kind, claim_level, fetch_level, created_at
     * @param  list<string>  $existingNames  organisations already tracked
     * @return list<array{name: string, status: string, kind: string, signal_count: int, best_evidence: string, latest_at: ?string, websites: list<string>, source_hosts: list<string>, reason: string}>
     */
    public static function propose(array $signals, array $existingNames, int $min = 1, ?string $region = 'india'): array
    {
        $clusters = [];
        foreach ($signals as $s) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) ($s['company'] ?? '')));
            if ($name === '' || (string) ($s['signal_kind'] ?? '') === 'public_reporting') {
                continue;
            }
            if (self::tracked($name, $existingNames)) {
                continue;
            }
            $placed = false;
            foreach ($clusters as &$c) {
                if (SignalDeduplicator::sameCompany($c['names'][0], $name)) {
                    $c['rows'][] = $s;
                    $c['names'][] = $name;
                    $placed = true;
                    break;
                }
            }
            unset($c);
            if (! $placed) {
                $clusters[] = ['names' => [$name], 'rows' => [$s]];
            }
        }

        $out = [];
        foreach ($clusters as $c) {
            // A merged cluster may now match a tracked name through a longer variant.
            $display = self::displayName($c['names']);
            if (self::tracked($display, $existingNames)) {
                continue;
            }
            $count = count($c['rows']);
            if ($count < max(1, $min)) {
                continue;
            }
            $best = EvidenceMaturity::NOT_ASSESSED;
            $latest = null;
            $hosts = [];
            $sites = [];
            foreach ($c['rows'] as $r) {
                $lvl = EvidenceMaturity::fromStored(self::str($r['claim_level'] ?? null), self::str($r['fetch_level'] ?? null));
                if (self::LEVEL_RANK[$lvl] > self::LEVEL_RANK[$best]) {
                    $best = $lvl;
                }
                $at = self::str($r['created_at'] ?? null);
                if ($at !== null && ($latest === null || strcmp($at, $latest) > 0)) {
                    $latest = $at;
                }
                $h = self::host($r['source_url'] ?? null);
                if ($h !== null) {
                    $hosts[$h] = true;
                }
                $w = self::str($r['website'] ?? ($r['domain'] ?? null));
                if ($w !== null) {
                    $sites[$w] = true;
                }
            }
            $hosts = array_keys($hosts);
            sort($hosts);
            $sites = array_keys($sites);
            sort($sites);

            // INDIA FIRST. Region is judged only from text the stored signals themselves carry (name, title, event, location) and from their hosts:
            // 'outside' = names another country and never India; 'inside' = names India, an Indian state or city, or sits on an Indian domain; else 'unknown'.
            $text = implode(' ', array_merge($c['names'], array_map(fn ($r) => implode(' ', array_filter([self::str($r['title'] ?? null), self::str($r['observed_event'] ?? null), self::str($r['location'] ?? null)])), $c['rows'])));
            $indianHost = count(array_filter(array_merge($hosts, array_map(fn ($s) => (string) parse_url(str_contains($s, '://') ? $s : 'https://' . $s, PHP_URL_HOST), $sites)), fn ($h) => preg_match('/\.in$/', (string) $h) === 1)) > 0;
            $allHostsForeign = $hosts !== [] && count(array_filter($hosts, fn ($h) => ForeignCountryHint::foreignHost($h))) === count($hosts);
            $regionStatus = ($indianHost || ForeignCountryHint::namesIndia($text)) ? 'inside'
                : (ForeignCountryHint::outsideIndia($text) || ($allHostsForeign && $sites === []) ? 'outside' : 'unknown');
            if ($region === 'india' && $regionStatus === 'outside') {
                continue;
            }

            $out[] = [
                'name' => $display,
                'region' => $regionStatus,
                'status' => self::STATUS,
                'kind' => self::kind($display, $sites),
                'signal_count' => $count,
                'best_evidence' => $best,
                'latest_at' => $latest,
                'websites' => $sites,
                'source_hosts' => $hosts,
                'reason' => sprintf('%d stored signal%s name this organisation; best evidence %s; %d source host%s.', $count, $count === 1 ? '' : 's', $best, count($hosts), count($hosts) === 1 ? '' : 's'),
            ];
        }

        // India-confirmed first, then the rest by evidence, signal count and recency.
        $rank = ['inside' => 0, 'unknown' => 1, 'outside' => 2];
        usort($out, fn ($a, $b) => [$rank[$a['region']], self::LEVEL_RANK[$b['best_evidence']], $b['signal_count'], (string) $b['latest_at'], $a['name']]
            <=> [$rank[$b['region']], self::LEVEL_RANK[$a['best_evidence']], $a['signal_count'], (string) $a['latest_at'], $b['name']]);

        return $out;
    }

    private static function tracked(string $name, array $existing): bool
    {
        foreach ($existing as $e) {
            if (is_string($e) && trim($e) !== '' && (mb_strtolower(trim($e)) === mb_strtolower($name) || SignalDeduplicator::sameCompany($e, $name))) {
                return true;
            }
        }

        return false;
    }

    /** The most frequent spelling; ties go to the longer, more complete name. */
    private static function displayName(array $names): string
    {
        $freq = array_count_values($names);
        uksort($freq, fn ($a, $b) => [$freq[$b], mb_strlen($b), $a] <=> [$freq[$a], mb_strlen($a), $b]);

        return (string) array_key_first($freq);
    }

    /**
     * Government when the NAME says so, or the organisation's OWN website is a government domain. A government page that merely reports on a
     * private company (a source host) says nothing about what the company is.
     *
     * @param  list<string>  $ownSites  the organisation's own website / domain values as stored
     */
    private static function kind(string $name, array $ownSites): string
    {
        if (preg_match(self::GOV_NAME, $name) === 1) {
            return 'government';
        }
        foreach ($ownSites as $site) {
            $h = strtolower((string) (parse_url(str_contains($site, '://') ? $site : 'https://' . $site, PHP_URL_HOST) ?: ''));
            if ($h !== '' && preg_match('/(^|\.)(gov\.in|nic\.in|gov)$/', $h) === 1) {
                return 'government';
            }
        }

        return 'company';
    }

    private static function host(mixed $url): ?string
    {
        $u = self::str($url);
        if ($u === null) {
            return null;
        }
        $h = parse_url(str_contains($u, '://') ? $u : 'https://' . $u, PHP_URL_HOST);
        if (! is_string($h) || $h === '') {
            return null;
        }
        $h = mb_strtolower($h);

        return str_starts_with($h, 'www.') ? substr($h, 4) : $h;
    }

    private static function str(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }
}
