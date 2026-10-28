<?php

namespace App\Domain\AI\Workspace;

/**
 * What the rows of a data source say about themselves, found from the rows alone.
 *
 * A facet is a column that groups the rows into a handful of categories - a status, a
 * department, a category - with how many rows fall in each. Nothing about any particular
 * source is known here: the same code reads employees, courses or job postings, which is
 * what lets a page's questions come from its real data without a line written per page.
 *
 * WHAT IS DELIBERATELY NOT A FACET
 *
 *   - Identifiers, names, e-mails, dates and anything about an individual person - a
 *     facet is an aggregate, and an aggregate keyed by a person is the person.
 *   - Free text and measures. A column of module counts (0, 1, 3, 6) is a number, not a
 *     category; numeric columns qualify only when named like a classification (status,
 *     priority, level...), because a status stored as 1 and 2 is still a status.
 *   - Columns with one value (nothing to split) or more than eight (a list, not a split).
 *
 * Counts cover the rows passed in, which the catalogue caps; callers say so when it did.
 */
final class SourceFacets
{
    private const MAX_VALUES = 8;
    private const MAX_FACETS = 3;
    private const MAX_TEXT = 40;

    /** Columns that name one record or one person, or are plainly not categories. */
    private const EXCLUDED = '/(^|_)(id|ids|user|learner|employee|candidate|person|assessee|name|email|phone|mobile|code|date|time|timestamp|at|deadline|created|updated|reference|reason|note|notes|description|title)(_|$)/i';

    /** Numeric columns count as categories only when named like one. */
    private const CLASSIFICATION = '/(status|priority|level|type|category|criticality|stage|mode|state)/i';

    /** Names that make the better headline split, in order of preference. */
    private const PREFERRED = ['status', 'category', 'department', 'type', 'priority', 'stage', 'level'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{column:string, label:string, values:array<int, array{value:string, count:int}>}>
     */
    public function compute(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $facets = [];

        foreach (array_keys($rows[0]) as $column) {
            $column = (string) $column;

            if (preg_match(self::EXCLUDED, $column) === 1) {
                continue;
            }

            $counts = $this->tally($rows, $column);

            if ($counts === null || count($counts) < 2 || count($counts) > self::MAX_VALUES) {
                continue;
            }

            arsort($counts);

            $facets[] = [
                'column' => $column,
                'label' => $this->humanise($column),
                'values' => array_map(
                    fn ($value, $count) => ['value' => (string) $value, 'count' => (int) $count],
                    array_keys($counts),
                    array_values($counts)
                ),
            ];
        }

        usort($facets, fn ($a, $b) => $this->rank($a) <=> $this->rank($b));

        return array_slice($facets, 0, self::MAX_FACETS);
    }

    /** "Courses - build status" -> "courses": the plural noun a question can be built around. */
    public function noun(string $label): string
    {
        $head = preg_split('/\s+[—–-]\s+/u', $label)[0] ?? $label;
        // A trailing qualifier - "Entity mappings (knowledge graph)" - is not part of the noun.
        $head = preg_replace('/\s*\([^)]*\)\s*$/u', '', $head) ?? $head;

        return mb_strtolower(trim($head));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>|null  Null when the column is not a usable category.
     */
    private function tally(array $rows, string $column): ?array
    {
        $counts = [];
        $numeric = true;
        $seen = 0;

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > self::MAX_TEXT)) {
                return null;
            }

            $numeric = $numeric && is_numeric($value);
            $key = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $seen++;
        }

        // A mostly empty column says little; require most rows to carry a value.
        if ($seen < max(2, (int) ceil(count($rows) * 0.5))) {
            return null;
        }

        // Every row different: a key or a name, not a way of splitting the rows.
        if (count($counts) === $seen) {
            return null;
        }

        if ($numeric && preg_match(self::CLASSIFICATION, $column) !== 1) {
            return null;
        }

        return $counts;
    }

    private function humanise(string $column): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $column)));
    }

    /** @param array{column:string, values:array<int, mixed>} $facet */
    private function rank(array $facet): int
    {
        foreach (self::PREFERRED as $index => $name) {
            if (str_contains(mb_strtolower($facet['column']), $name)) {
                return $index;
            }
        }

        return count(self::PREFERRED) + count($facet['values']);
    }
}
