<?php

namespace App\Domain\Signals\Market;

/** Builds up to 3 corroboration searches for a lead that is not ready, and the kind of source each aims for. Pure; no network. */
final class VerificationPlanner
{
    private const MAX = 3;

    /**
     * @param array{organisation?: ?string, trigger?: ?string, kind?: ?string, event?: ?string, region?: ?string} $in
     * @return list<array{query: string, aims_for: string, reason: string}>
     */
    public static function plan(array $in): array
    {
        $org = trim(str_replace('"', '', self::clean((string) ($in['organisation'] ?? ''))));
        if ($org === '') {
            return [];
        }
        $q = '"' . $org . '"';
        $trigger = self::words(self::clean((string) ($in['trigger'] ?? '')), 6);
        $event = self::words(self::clean((string) ($in['event'] ?? '')), 6);
        $region = self::words(self::clean((string) ($in['region'] ?? '')), 3);
        $kind = (string) ($in['kind'] ?? '');
        $topic = $trigger !== '' ? $trigger : $event;

        if ($kind === 'government_body') {
            $out = [
                [$q . ' tender RFP notice ' . $topic, 'tender_document', 'A tender or RFP document is the strongest proof of government buying intent.'],
                [$q . ' official site portal gov.in ' . $region . ' ' . $topic, 'government_portal', 'The body\'s own portal confirms the plan and its dates.'],
                [$q . ' ' . $event . ' ' . $region . ' news', 'reputable_news', 'A news report is an independent second source.'],
            ];
        } else {
            $out = [
                [$q . ' official site ' . $topic . ' ' . $region, 'official_site', 'The organisation\'s own page confirms the trigger first-hand.'],
                [$q . ' ' . ($event !== '' ? $event : $topic) . ' announcement news ' . $region, 'reputable_news', 'Independent news coverage is a second source.'],
                [$q . ' press release ' . $topic, 'official_site', 'A press release or newsroom post is a primary source.'],
            ];
        }

        $seen = [];
        $plan = [];
        foreach ($out as [$query, $aims, $reason]) {
            $query = trim((string) preg_replace('/\s+/u', ' ', $query));
            $key = mb_strtolower($query);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $plan[] = ['query' => $query, 'aims_for' => $aims, 'reason' => $reason];
            if (count($plan) >= self::MAX) {
                break;
            }
        }

        return $plan;
    }

    /** Removes URLs, tracking parameters, hashtags/handles and stray symbols. */
    private static function clean(string $s): string
    {
        $s = (string) preg_replace('~https?://\S+|www\.\S+~iu', ' ', $s);
        $s = (string) preg_replace('/\b(?:utm_\w+|fbclid|gclid|igshid|ref|source)=\S*/iu', ' ', $s);
        $s = (string) preg_replace('/[#@]\w+/u', ' ', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}\s&.,\'-]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function words(string $s, int $n): string
    {
        return implode(' ', array_slice(preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $n));
    }
}
