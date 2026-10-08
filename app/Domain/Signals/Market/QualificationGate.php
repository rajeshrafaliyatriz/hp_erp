<?php

namespace App\Domain\Signals\Market;

use Illuminate\Support\Carbon;

/**
 * The qualification gates for one imported demand-side record. Pure: no database, no clock
 * beyond `now()`, so every rule is unit-testable.
 *
 * `evaluate()` returns either a rejection ("code" + "reason") or a normalised record ready to
 * store, plus any evidence downgrade it applied. A record with no issuing authority, no date or
 * no named trigger is a rumour, and is rejected rather than stored.
 */
final class QualificationGate
{
    public const TRIGGER_TYPES = ['tender', 'rfp', 'eoi', 'rfq', 'programme', 'regulation', 'expansion', 'funding', 'acquisition', 'leadership', 'hiring', 'partnership', 'other'];
    public const BUYER_TYPES = ['government', 'institutional', 'sme', 'school', 'enterprise', 'other'];
    public const CLAIM_LEVELS = ['confirmed' => 3, 'inference' => 2, 'hypothesis' => 1];
    public const FETCH_LEVELS = ['full_document' => 3, 'page_text' => 2, 'search_snippet' => 1, 'blocked' => 0];
    public const BUSINESS_FIT = ['eb', 'scholar', 'g2g', 'multiple'];
    public const ENTRY_POINTS = ['discovery_meeting', 'pilot', 'poc', 'paid_assessment', 'workshop', 'si_partnership', 'rfp_response', 'other'];
    public const SCALES = ['small', 'medium', 'large', 'strategic'];
    public const SCORE_KEYS = ['buying_signal', 'problem_fit', 'product_fit', 'accessibility', 'urgency', 'potential_value', 'evidence_quality'];

    /** Closing-date style tenders: their expiry is the closing date. */
    private const TENDER_TYPES = ['tender', 'rfp', 'eoi', 'rfq'];

    private const GOV_TRACK = '/\b(?i:mission\s+karmayogi|karmayogi|capacity\s+building\s+commission|igot)\b|\b(?:CBP|CBC)\b/u';

    /**
     * @param  array<string, mixed>  $r  one raw record, in the documented import schema
     * @return array{ok: false, code: string, reason: string}|array{ok: true, record: array<string, mixed>, notes: list<string>}
     */
    public function evaluate(array $r): array
    {
        $buyer = is_array($r['buyer'] ?? null) ? $r['buyer'] : [];
        $name = $this->str($buyer['name'] ?? null);
        $summary = $this->str($r['trigger_summary'] ?? null);
        $url = $this->str($r['source_url'] ?? null);

        if ($name === null) {
            return $this->reject('missing_buyer', 'No issuing/buyer organisation. An item with no issuing authority is a rumour.');
        }
        if ($summary === null) {
            return $this->reject('missing_trigger', 'No named trigger event (trigger_summary).');
        }

        $eventDate = $this->date($r['event_date'] ?? null);
        $observedAt = $this->date($r['observed_at'] ?? null);
        if ($this->str($r['event_date'] ?? null) !== null && $eventDate === null) {
            return $this->reject('invalid_date', 'event_date is not a valid date.');
        }
        if ($eventDate === null && $observedAt === null) {
            return $this->reject('missing_date', 'No date: provide event_date or observed_at.');
        }

        if ($url === null || ! preg_match('#^https?://\S+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->reject('missing_source_url', 'source_url is required and must be an http(s) URL.');
        }

        $claim = $this->enum($r['claim_level'] ?? null, array_keys(self::CLAIM_LEVELS));
        if ($claim === null) {
            return $this->reject('invalid_claim_level', 'claim_level must be one of: ' . implode(', ', array_keys(self::CLAIM_LEVELS)) . '.');
        }
        $fetch = $this->enum($r['fetch_level'] ?? null, array_keys(self::FETCH_LEVELS));
        if ($fetch === null) {
            return $this->reject('invalid_fetch_level', 'fetch_level must be one of: ' . implode(', ', array_keys(self::FETCH_LEVELS)) . '.');
        }

        $trigger = $this->enum($r['trigger_type'] ?? 'other', self::TRIGGER_TYPES);
        if ($trigger === null) {
            return $this->reject('invalid_trigger_type', 'trigger_type must be one of: ' . implode(', ', self::TRIGGER_TYPES) . '.');
        }

        foreach (['business_fit' => self::BUSINESS_FIT, 'entry_point' => self::ENTRY_POINTS, 'scale' => self::SCALES] as $field => $allowed) {
            if ($this->str($r[$field] ?? null) !== null && $this->enum($r[$field], $allowed) === null) {
                return $this->reject('invalid_' . $field, "{$field} must be one of: " . implode(', ', $allowed) . '.');
            }
        }

        $buyerType = $this->str($buyer['type'] ?? null);
        if ($buyerType !== null && $this->enum($buyerType, self::BUYER_TYPES) === null) {
            return $this->reject('invalid_buyer_type', 'buyer.type must be one of: ' . implode(', ', self::BUYER_TYPES) . '.');
        }
        $buyerType = $buyerType === null ? null : strtolower($buyerType);

        $codes = $this->needCodes($r['candidate_need_codes'] ?? []);
        if ($codes === null) {
            return $this->reject('invalid_need_code', 'candidate_need_codes must be codes from N01 to N20.');
        }

        $scores = $this->scores($r['scores'] ?? null);
        if ($scores === false) {
            return $this->reject('invalid_scores', 'scores must contain all seven keys (' . implode(', ', self::SCORE_KEYS) . '), each an integer from 1 to 5.');
        }

        $notes = [];
        $evidenceNote = null;

        // Evidence grade: a snippet or a blocked page can never support a "confirmed" claim.
        if ($claim === 'confirmed' && self::FETCH_LEVELS[$fetch] < self::FETCH_LEVELS['page_text']) {
            $claim = 'inference';
            $evidenceNote = "Downgraded from confirmed to inference: the source was only seen as '{$fetch}'.";
            $notes[] = $evidenceNote;
        }

        // Expiry: explicit, else a tender's closing date, else none ("no expiry").
        $expires = $this->date($r['expires_at'] ?? null)
            ?? (in_array($trigger, self::TENDER_TYPES, true) ? $this->date($r['closing_date'] ?? ($r['deadline'] ?? null)) : null);

        // Government track: central capacity-building bodies are reached through an SI partner.
        $state = $this->str($buyer['state'] ?? null);
        $haystack = implode(' ', array_filter([$name, $summary, $this->str($r['what_happened'] ?? null), $this->str($r['source_title'] ?? null)]));
        $govTrack = filter_var($r['is_government_track'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($buyerType === 'government' && $state === null && preg_match(self::GOV_TRACK, $haystack) === 1);

        $entry = $this->enum($r['entry_point'] ?? null, self::ENTRY_POINTS) ?? ($govTrack ? 'si_partnership' : null);

        $record = [
            'buyer' => [
                'name' => $name, 'type' => $buyerType, 'state' => $state, 'website' => $this->url($buyer['website'] ?? null),
                'aliases' => $this->aliases($buyer['aliases'] ?? []),
            ],
            'trigger_type' => $trigger,
            'trigger_summary' => $summary,
            'what_happened' => $this->str($r['what_happened'] ?? null),
            'source_url' => $url,
            'source_title' => $this->str($r['source_title'] ?? null),
            'reference_no' => $this->str($r['reference_no'] ?? null),
            'event_date' => $eventDate?->toDateString(),
            'observed_at' => ($observedAt ?? $eventDate)?->toDateTimeString(),
            'expires_at' => $expires?->toDateString(),
            'claim_level' => $claim,
            'fetch_level' => $fetch,
            'evidence_note' => $evidenceNote,
            'business_fit' => $this->enum($r['business_fit'] ?? null, self::BUSINESS_FIT),
            'candidate_need_codes' => $codes,
            'buyer_segment' => $this->str($r['buyer_segment'] ?? null),
            'likely_problem' => $this->str($r['likely_problem'] ?? null),
            'likely_stakeholder' => $this->str($r['likely_stakeholder'] ?? null),
            'what_we_could_sell' => $this->str($r['what_we_could_sell'] ?? null),
            'entry_point' => $entry,
            'scale' => $this->enum($r['scale'] ?? null, self::SCALES),
            'estimated_value' => $this->str($r['estimated_value'] ?? null),
            'is_government_track' => $govTrack,
            'partner_route_note' => $this->str($r['partner_route_note'] ?? null),
            'soft_marketing_angle' => $this->str($r['soft_marketing_angle'] ?? null),
            'scores' => $scores ?: null,
            'score_reasoning' => is_array($r['scores'] ?? null) ? $this->str($r['scores']['reasoning'] ?? null) : null,
            'is_sample' => filter_var($r['is_sample'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        return ['ok' => true, 'record' => $record, 'notes' => $notes];
    }

    /** Does `$new` represent real progress over a stored signal? (a later date, escalation, amendment) */
    public static function hasAdvanced(array $stored, array $new): bool
    {
        if ($new['event_date'] !== null && ($stored['event_date'] ?? null) === null) {
            return true;
        }
        if ($new['event_date'] !== null && $new['event_date'] > $stored['event_date']) {
            return true;
        }
        if ($new['expires_at'] !== null && $new['expires_at'] !== ($stored['expires_at'] ?? null)) {
            return true; // amendment: the deadline moved
        }
        if ((self::CLAIM_LEVELS[$new['claim_level']] ?? 0) > (self::CLAIM_LEVELS[$stored['claim_level'] ?? ''] ?? 0)) {
            return true;
        }
        if ((self::FETCH_LEVELS[$new['fetch_level']] ?? 0) > (self::FETCH_LEVELS[$stored['fetch_level'] ?? ''] ?? -1)) {
            return true;
        }

        return false;
    }

    private function reject(string $code, string $reason): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function url(mixed $v): ?string
    {
        $v = $this->str($v);

        return $v !== null && preg_match('#^https?://\S+$#i', $v) ? $v : null;
    }

    /** @return list<string> */
    private function aliases(mixed $v): array
    {
        if (is_string($v)) {
            $v = explode('|', $v);
        }

        return array_values(array_filter(array_map(fn ($a) => $this->str($a), (array) $v)));
    }

    /** @param list<string> $allowed */
    private function enum(mixed $v, array $allowed): ?string
    {
        $v = $this->str($v);
        $v = $v === null ? null : strtolower($v);

        return $v !== null && in_array($v, $allowed, true) ? $v : null;
    }

    private function date(mixed $v): ?Carbon
    {
        $v = $this->str($v);
        if ($v === null) {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null null when any code is invalid */
    private function needCodes(mixed $v): ?array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,;|]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ((array) $v as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            if (! preg_match('/^N(0[1-9]|1\d|20)$/', $code)) {
                return null;
            }
            $out[$code] = $code;
        }

        return array_values($out);
    }

    /** @return array<string, int>|false|null null = no scores supplied; false = invalid; else the seven scores plus 'total' */
    private function scores(mixed $v): array|false|null
    {
        if ($v === null || $v === []) {
            return null;
        }
        if (! is_array($v)) {
            return false;
        }
        if (array_intersect(self::SCORE_KEYS, array_keys($v)) === []) {
            return null; // only a reasoning note: no scores were supplied
        }

        $out = [];
        foreach (self::SCORE_KEYS as $key) {
            $s = $v[$key] ?? null;
            if (! (is_int($s) || (is_string($s) && ctype_digit($s))) || (int) $s < 1 || (int) $s > 5) {
                return false;
            }
            $out[$key] = (int) $s;
        }
        $out['total'] = array_sum($out);

        return $out;
    }
}
