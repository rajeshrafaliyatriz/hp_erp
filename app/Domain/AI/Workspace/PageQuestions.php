<?php

namespace App\Domain\AI\Workspace;

/**
 * Questions worth asking about the page the user is looking at, from what is on it.
 *
 * Built from a cleaned `PageSnapshot` and nothing else - no page is known by name, no
 * question is written for a screen. The same rules produce "Tell me about Devops" on a
 * department list, "Which rows match the filter Status Active?" on a filtered one and "What
 * is the breakdown of the Job Postings by Status?" on another, because each fills a template
 * with the page's own title, its own columns and the values actually in its rows.
 *
 * Every question must be answerable from the snapshot the chat is given, so each is about the
 * rows loaded on screen and says so where the page shows only part of a list.
 *
 * ORDER IS RELEVANCE: what the user has done on the page (selected rows, searched, filtered)
 * comes before what the page merely contains.
 */
final class PageQuestions
{
    private const MAX = 6;

    /** A filter value that means "no filter". */
    private const UNFILTERED = '/^(all|any|none|select|-)\b/i';

    public function __construct(private readonly SourceFacets $facets)
    {
    }

    /**
     * @param  array<string, mixed>  $snapshot  Output of `PageSnapshot::clean()`.
     * @return array<int, string>
     */
    public function derive(array $snapshot): array
    {
        $questions = [];
        $add = function (string $question) use (&$questions): void {
            if (count($questions) < self::MAX && ! in_array($question, $questions, true)) {
                $questions[] = $question;
            }
        };

        $table = $snapshot['tables'][0] ?? null;
        $subject = $table['title'] ?? $snapshot['title'] ?? 'this page';

        // 1. What the user has done on the page.
        $selected = $snapshot['selected'] ?? [];

        if (count($selected) >= 2) {
            $add(sprintf('Compare %s and %s.', $this->primary($selected[0]), $this->primary($selected[1])));
        }

        if (count($selected) >= 1) {
            $add(sprintf('Tell me about %s.', $this->primary($selected[0])));
        }

        $query = $snapshot['search']['query'] ?? '';

        if ($query !== '') {
            $add(sprintf('What matches "%s" on this page?', $query));
        }

        foreach ($snapshot['filters'] ?? [] as $filter) {
            if (preg_match(self::UNFILTERED, $filter['value']) !== 1) {
                // Worded to read correctly for any title, singular or plural.
                $add(sprintf(
                    'With %s set to %s, what is in the %s?',
                    $filter['label'] !== '' ? $filter['label'] : 'the filter',
                    $filter['value'],
                    $subject
                ));
            }
        }

        // 2. What the page contains: a split of the loaded rows by a category, and the biggest group.
        if ($table !== null) {
            $facet = $this->headlineFacet($table);

            if ($facet !== null) {
                $add(sprintf('What is the breakdown of the %s by %s?', $subject, $facet['label']));
                $add(sprintf('Of the %s loaded, how many have %s "%s"?', $subject, $facet['label'], $facet['values'][0]['value']));
            }

            $first = $this->primary((string) (reset($table['rows'][0]) ?: ''));

            if ($first !== '') {
                $add(sprintf('Tell me about %s.', $first));
            }

            $add(sprintf('What stands out in the %s?', $subject));
        }

        if ($questions === [] && ($snapshot['title'] ?? null) !== null) {
            $add(sprintf('Summarise the %s page.', $snapshot['title']));
        }

        return $questions;
    }

    /**
     * The category column that best splits the loaded rows, found by the same rules as a data
     * source's columns - so identifiers, names and dates never become a "category".
     *
     * @param  array{rows:array<int, array<string,string>>}  $table
     * @return array{label:string, values:array<int, array{value:string,count:int}>}|null
     */
    private function headlineFacet(array $table): ?array
    {
        // "Unassigned · No HOD" is one fact shown over two lines; the first part is the value.
        // The first column names the row (the department, the course): it identifies, it does not
        // categorise, so it is left out of the split.
        $identifier = $table['columns'][0] ?? null;
        $rows = array_map(function (array $row) use ($identifier) {
            unset($row[$identifier]);

            return array_map(fn (string $cell) => $this->primary($cell), $row);
        }, $table['rows']);

        foreach ($this->facets->compute($rows) as $facet) {
            if (! is_numeric($facet['values'][0]['value']) && strcasecmp($facet['label'], 'actions') !== 0) {
                return $facet;
            }
        }

        return null;
    }

    /** The first line of a stacked cell: "Development · D" -> "Development". */
    private function primary(string $cell): string
    {
        return trim(explode(' · ', $cell)[0]);
    }
}
