<?php

namespace App\Domain\Signals\Market;

/**
 * The plain-English rules for the FACE of a lead card, enforced in one place (QualificationGate)
 * and reused by the researcher to give the model one fair second attempt.
 *
 * The face is three written fields. They map onto existing columns, so no vocabulary is duplicated:
 *   what_happened         -> observed_event         what happened: one bold sentence, then context
 *   likely_problem        -> likely_problem         what this means for us: why they are a buyer, which product fits
 *   soft_marketing_angle  -> soft_marketing_angle   what to do: the next step and who to approach
 *
 * Rejection codes: jargon_on_card, sentence_too_long, missing_so_what.
 * Everything here is pure; thresholds and the banned-word list come from config('signals.card.plain').
 */
final class PlainLanguageRules
{
    public const FACE_FIELDS = ['what_happened', 'likely_problem', 'soft_marketing_angle'];

    /**
     * @param  array<string, mixed>  $face  keys from FACE_FIELDS (absent / null fields are skipped here)
     * @param  bool  $needsSoWhat  false for context items, which name no buyer to explain
     * @return list<array{code: string, field: string, reason: string}>
     */
    public function violations(array $face, bool $needsSoWhat = true): array
    {
        $cfg = (array) config('signals.card.plain');
        $out = [];

        foreach (self::FACE_FIELDS as $field) {
            $text = $this->clean($face[$field] ?? null);
            if ($text === '') {
                continue;
            }

            foreach ($this->jargon($text, (array) ($cfg['banned_terms'] ?? [])) as $found) {
                $out[] = ['code' => 'jargon_on_card', 'field' => $field, 'reason' => "{$field} uses '{$found}', which is internal vocabulary. Say it the way a colleague would."];
            }

            $max = (int) ($cfg['max_sentence_words'] ?? 20);
            $sentences = $this->sentences($text);
            foreach ($sentences as $s) {
                $n = $this->words($s);
                if ($n > $max) {
                    $out[] = ['code' => 'sentence_too_long', 'field' => $field, 'reason' => "{$field} has a {$n}-word sentence (limit {$max}): \"" . mb_substr($s, 0, 70) . '…"'];
                    break;
                }
            }

            $cap = (int) (($cfg['max_sentences'][$field] ?? 0));
            if ($cap > 0 && count($sentences) > $cap) {
                $out[] = ['code' => 'sentence_too_long', 'field' => $field, 'reason' => "{$field} runs to " . count($sentences) . " sentences (limit {$cap}). Cut it."];
            }
        }

        if ($needsSoWhat) {
            $so = $this->clean($face['likely_problem'] ?? null);
            if ($so === '' || $this->words($so) < (int) ($cfg['so_what_min_words'] ?? 8) || count($this->sentences($so)) < (int) ($cfg['so_what_min_sentences'] ?? 2)) {
                $out[] = ['code' => 'missing_so_what', 'field' => 'likely_problem', 'reason' => 'likely_problem must explain in two short sentences why this makes them a buyer and which of our products fits.'];
            }
        }

        return $out;
    }

    /** Banned terms (whole words), offer ids and raw long numbers found in the text. @return list<string> */
    private function jargon(string $text, array $banned): array
    {
        $found = [];
        foreach ($banned as $term) {
            $term = trim((string) $term);
            if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
                $found[] = $term;
            }
        }
        if (preg_match('/\b[A-Z]{1,4}-\d{2,3}\b/', $text, $m) === 1) {
            $found[] = $m[0] . ' (an offer id: name the product in words)';
        }
        if (preg_match('/(?<![\d,.])\d{8,}(?![\d,.])/', $text, $m) === 1) {
            $found[] = $m[0] . ' (write it as people say it, for example ₹170 crore)';
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    public function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}₹"“(\d])/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    private function words(string $s): int
    {
        return count(array_filter(preg_split('/\s+/u', trim($s)) ?: [], fn ($w) => $w !== ''));
    }

    private function clean(mixed $v): string
    {
        return is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? '') : '';
    }
}
