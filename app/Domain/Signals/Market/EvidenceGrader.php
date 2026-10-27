<?php

namespace App\Domain\Signals\Market;

/**
 * Derives the evidence level of a signal. It is never asserted by the writer.
 *
 *   confirmed  = a PRIMARY source (the issuer's own site, or an official government / academic
 *                domain) states it directly, the page text was actually read, and the supporting
 *                quote was verified verbatim against that text.
 *   inference  = reasoned from a verified fact, and the reasoning is recorded; the fact must be
 *                backed by a primary source or by independent domains reporting it.
 *   hypothesis = anything else: one unverified or secondary source, a snippet only, speculation.
 *
 * A headline is not a confirmed fact: a news article is a secondary source however well it quotes.
 *
 * Pure (no database, no clock). Called only by QualificationGate, so the rule exists in one place
 * and the web-research and market-import paths cannot drift apart.
 */
final class EvidenceGrader
{
    /**
     * @param  array{quote_verified?: mixed, corroborating_domains?: mixed, inference_reasoning?: mixed}  $evidence
     * @param  array{name?: ?string, website?: ?string}  $buyer
     * @return array{claim_level: string, note: string, primary: bool}
     */
    public function grade(array $evidence, string $fetchLevel, string $sourceUrl, array $buyer): array
    {
        $verified = filter_var($evidence['quote_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $reasoning = $this->clean($evidence['inference_reasoning'] ?? null);
        $domains = max(1, (int) ($evidence['corroborating_domains'] ?? 1));
        $needDomains = (int) config('signals.card.evidence.corroboration_domains', 2);
        $primary = $this->isPrimary($sourceUrl, $buyer);
        $read = (QualificationGate::FETCH_LEVELS[$fetchLevel] ?? 0) >= QualificationGate::FETCH_LEVELS['page_text'];
        $host = $this->host($sourceUrl) ?: 'the source';

        if (! $verified) {
            return $this->result('hypothesis', "No verbatim supporting quote was verified in {$host}.", $primary);
        }
        if ($primary && $read) {
            return $this->result('confirmed', "Stated directly by a primary source ({$host}); page text read and quote verified.", true);
        }
        if ($reasoning !== null && ($primary || $domains >= $needDomains)) {
            $basis = $primary ? "primary source {$host} (seen only as {$fetchLevel})" : "{$domains} independent domains";

            return $this->result('inference', mb_substr("Inference from {$basis}: {$reasoning}", 0, 500), $primary);
        }

        $why = $primary
            ? "Primary source {$host} was seen only as {$fetchLevel} and no reasoning was recorded."
            : "Single secondary source ({$host})" . ($reasoning === null ? ' and no reasoning was recorded.' : ' with no corroborating domain.');

        return $this->result('hypothesis', mb_substr($why, 0, 500), $primary);
    }

    /** Words that identify a kind of organisation, not a particular one. Never enough to corroborate identity. */
    private const GENERIC_TOKENS = [
        'school', 'schools', 'public', 'international', 'academy', 'education', 'educational', 'institute',
        'college', 'university', 'trust', 'society', 'group', 'vidyalaya', 'vidya', 'mandir', 'board',
        'department', 'government', 'govt', 'national', 'state', 'city', 'the', 'of', 'and',
    ];

    /** Second-level labels that, under a 2-letter country TLD, belong to the public suffix (co.in, ac.uk ...). */
    private const SECOND_LEVEL = ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'nic', 'or', 'ne', 'ltd', 'plc', 'sch', 'res'];

    /** Is this URL a primary source for this buyer? */
    public function isPrimary(string $url, array $buyer): bool
    {
        return $this->primaryReason($url, $buyer) !== null;
    }

    /**
     * Why is this URL a primary source for this buyer? Null when it is not (or when we cannot tell).
     *
     *   buyer_website                 host is the buyer's own website host or a subdomain of it
     *   buyer_name_on_official_domain government/education suffix AND a distinctive buyer-name token is a whole
     *                                 label (or label segment) of the registrable part
     *   buyer_name_on_own_domain      same name rule on the registrable domain label of a non-official host
     *
     * @param  array{name?: ?string, website?: ?string}  $buyer
     */
    public function primaryReason(string $url, array $buyer): ?string
    {
        $host = $this->host($url);
        if ($host === '') {
            return null;
        }

        $own = $this->host((string) ($buyer['website'] ?? ''));
        if ($own !== '' && str_contains($own, '.') && ($host === $own || str_ends_with($host, '.' . $own))) {
            return 'buyer_website';
        }

        $nameTokens = $this->nameTokens((string) ($buyer['name'] ?? ''));

        foreach ((array) config('signals.card.evidence.primary_host_suffixes', []) as $suffix) {
            $suffix = '.' . ltrim(strtolower(trim((string) $suffix)), '.');
            if ($suffix === '.' || ! str_ends_with($host, $suffix)) {
                continue;
            }
            $rest = substr($host, 0, -strlen($suffix));
            if ($rest === '') {
                continue;
            }
            $labels = explode('.', $rest);

            // Official suffix alone proves nothing about WHICH body this is: the name must appear in the domain.
            return $this->nameMatchesLabel(end($labels), $nameTokens) ? 'buyer_name_on_official_domain' : null;
        }

        $label = $this->registrableLabel($host);

        return $label !== null && $this->nameMatchesLabel($label, $nameTokens) ? 'buyer_name_on_own_domain' : null;
    }

    /** @return list<string> lowercase ascii-alnum words of the name, in order */
    private function nameTokens(string $name): array
    {
        $name = mb_strtolower(trim($name));
        $tokens = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : array_values($tokens);
    }

    /**
     * True when the DNS label (or a '-'/digit-split segment of it) equals a distinctive name token, or equals
     * a run of consecutive name words that contains one ("podarworld" for "Podar World School").
     *
     * @param  list<string>  $tokens
     */
    private function nameMatchesLabel(string $label, array $tokens): bool
    {
        $distinctive = static fn (string $t): bool => preg_match('/^[a-z]{4,}$/', $t) === 1
            && ! in_array($t, self::GENERIC_TOKENS, true);
        if ($label === '' || ! array_filter($tokens, $distinctive)) {
            return false;
        }

        $candidates = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $run = '';
            $has = false;
            for ($j = $i; $j < $n; $j++) {
                $run .= $tokens[$j];
                $has = $has || $distinctive($tokens[$j]);
                if ($has) {
                    $candidates[$run] = true;
                }
            }
        }

        $segments = preg_split('/[-0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments[] = $label;
        $segments[] = str_replace('-', '', $label);

        foreach ($segments as $seg) {
            if (isset($candidates[$seg])) {
                return true;
            }
        }

        return false;
    }

    /** The label directly left of the public suffix (approximate: 2-letter ccTLD with a known second level). */
    private function registrableLabel(string $host): ?string
    {
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n < 2) {
            return null;
        }
        if ($n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SECOND_LEVEL, true)) {
            return $labels[$n - 3];
        }

        return $labels[$n - 2];
    }

    /** Normalised hostname (lowercase, no www, no port/userinfo/trailing dot) or '' when missing or malformed. */
    private function host(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/\s/', $url)) {
            return '';
        }
        $host = @parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }
        $host = mb_strtolower(rtrim($host, '.'));
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (! is_string($ascii) || $ascii === '') {
                return '';
            }
            $host = $ascii;
        }
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host === '' || strlen($host) > 253) {
            return '';
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $label) !== 1) {
                return '';
            }
        }
        // Bare IPs / numeric TLDs are not names.
        if (preg_match('/^\d+$/', (string) substr(strrchr('.' . $host, '.'), 1))) {
            return '';
        }

        return $host;
    }

    private function clean(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');

        return mb_strlen($v) >= 15 ? mb_substr($v, 0, 350) : null;
    }

    /** @return array{claim_level: string, note: string, primary: bool} */
    private function result(string $claim, string $note, bool $primary): array
    {
        return ['claim_level' => $claim, 'note' => $note, 'primary' => $primary];
    }
}
