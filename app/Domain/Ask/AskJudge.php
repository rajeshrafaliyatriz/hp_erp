<?php

namespace App\Domain\Ask;

/**
 * Judges what the answerer (model call 2) wrote against what it was actually shown (slice 8, Ask). The model's claim of a source is worth nothing until the
 * server has matched it to a row the lookups really returned, which is the discipline the Organisation agent already has (Gateway::evidence):
 *
 *   - the answer must be a JSON object with a list of statements (anything else is not an answer: null, and the caller treats the call as failed);
 *   - a citation is kept only when its type and id are one of the rows shown; any other is REMOVED (`removedCitations`), and the label and the address of a
 *     kept citation are the row's own, never the model's;
 *   - a statement left with no citation is WITHHELD: a claim that rested only on a record the model never saw is not shown;
 *   - the text is cleaned to plain words with every link removed (AskText::clean), at most `ask.max_statements` statements, each cut to a length;
 *   - an empty list is a valid answer ("nothing fits"), told apart from a list whose statements were all withheld by `returned`.
 *
 * Whether the answer is `answered`, `no_data` or `refused` is decided by the caller from this verdict.
 */
final class AskJudge
{
    /**
     * @param  array<string, AskRow>  $shown  the rows the answerer was shown, by "type:id"
     */
    public function judge(string $text, array $shown): ?AskVerdict
    {
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
        $decoded = json_decode($text, true);
        if (! is_array($decoded) || array_is_list($decoded) || ! isset($decoded['statements']) || ! is_array($decoded['statements']) || ! array_is_list($decoded['statements'])) {
            return null;
        }

        $statements = [];
        $withheld = 0;
        $removed = 0;
        $returned = 0;
        $maxStatements = (int) config('ask.max_statements', 8);
        $maxCites = (int) config('ask.max_citations_per_statement', 5);
        $maxChars = (int) config('ask.max_answer_chars', 1500);
        $length = 0;

        foreach ($decoded['statements'] as $item) {
            $returned++;
            if ($returned > $maxStatements) {
                $withheld++;   // too many: the rest are not shown

                continue;
            }
            $words = AskText::clean(is_array($item) ? ($item['text'] ?? null) : null, (int) config('ask.max_statement_chars', 400));
            $cited = is_array($item) && is_array($item['cite'] ?? null) ? array_values($item['cite']) : [];
            $kept = [];
            foreach ($cited as $n => $cite) {
                $row = $n < $maxCites ? $this->find($cite, $shown) : null;
                if ($row === null) {
                    $removed++;

                    continue;
                }
                $kept[$row->refKey()] = $row;
            }
            if ($words === '' || $kept === [] || $length + mb_strlen($words) + 1 > $maxChars) {
                $withheld++;   // nothing to say, nothing it rests on, or the whole answer would be longer than it may be

                continue;
            }
            $length += mb_strlen($words) + 1;
            $statements[] = ['text' => $words, 'cites' => array_values($kept)];
        }

        return new AskVerdict($statements, $returned, $withheld, $removed);
    }

    /** The row a citation names, or null when it names anything else (an unknown type, a record that was not shown, a shape that is not a reference). @param array<string, AskRow> $shown */
    private function find(mixed $cite, array $shown): ?AskRow
    {
        if (is_string($cite) && str_contains($cite, ':')) {
            [$type, $id] = explode(':', $cite, 2);
            $cite = ['type' => $type, 'id' => $id];
        }
        $type = is_array($cite) ? ($cite['type'] ?? null) : null;
        $id = is_array($cite) ? ($cite['id'] ?? null) : null;
        if (! is_string($type) || ! (is_string($id) || is_int($id))) {
            return null;
        }

        return $shown[AskRow::key(trim($type), trim((string) $id))] ?? null;
    }
}
