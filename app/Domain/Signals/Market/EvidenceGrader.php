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

    /** Is this URL a primary source for this buyer? */
    public function isPrimary(string $url, array $buyer): bool
    {
        $host = $this->host($url);
        if ($host === '') {
            return false;
        }

        foreach ((array) config('signals.card.evidence.primary_host_suffixes', []) as $suffix) {
            if (str_ends_with($host, strtolower((string) $suffix))) {
                return true;
            }
        }

        $own = $this->host((string) ($buyer['website'] ?? ''));
        if ($own !== '' && ($host === $own || str_ends_with($host, '.' . $own))) {
            return true;
        }

        // First-party by name: careers.acme.com for "Acme Industries". Short tokens are too generic.
        $token = preg_replace('/[^a-z0-9]/', '', strtolower(explode(' ', trim((string) ($buyer['name'] ?? '')))[0]));

        return $token !== null && strlen($token) >= 5 && str_contains($host, $token);
    }

    private function host(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);

        return is_string($host) ? strtolower(preg_replace('/^www\./', '', $host)) : '';
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
