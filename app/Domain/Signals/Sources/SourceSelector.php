<?php

namespace App\Domain\Signals\Sources;

use Illuminate\Support\Carbon;

/**
 * Chooses, deterministically, which registry sources a run SEARCHES, and writes the search for each. Pure: no database, no network, no provider.
 *
 * What it does and does not do
 *  - It turns a registry row into a search spec the researcher already understands (`q`, `topic`, `days`, `domains`). The provider, not this class,
 *    does the searching; a page is read only if the provider returns it and the researcher's own fetch rules allow it.
 *  - A source is never fetched, crawled or marked verified here. A `pending_verification` row may be SEARCHED (a provider-side, domain-restricted
 *    query); that says nothing about whether the row is reachable. Its spec carries `registry_status` so the run record can say so.
 *  - Rows with no address (`needs_url`) are never domain-restricted. They can only yield a sector-wide query, and only when category slots are given.
 *  - A selected source is "selected", not "searched": the researcher counts a source as searched only when its spec was actually executed.
 *
 * Rotation: rows are scored (Gujarat first, then India; tenders and expansion before news; product overlap) and split into a priority tier and the
 * rest. Each tier advances through its rows by the day number, so every source is reached in turn, the strongest ones most often.
 */
final class SourceSelector
{
    /** Retrieval methods that are not worth a domain-restricted search: a directory listing or a job-board page is not an opportunity by itself. */
    public const DEFAULT_EXCLUDED_METHODS = ['directory', 'job_search'];

    /** Score at or above which a row is in the priority tier. */
    private const PRIORITY_SCORE = 40;

    public function __construct(private readonly SourceRegistry $registry)
    {
    }

    /**
     * @param  array{products?: list<string>, excluded_methods?: list<string>, days?: int, shift?: int, can_restrict_domains?: bool, category_slots?: int}  $options
     *         products: the capabilities the ICP cares about (core|learn|ccb|insight|compass); empty = all
     *         can_restrict_domains: whether the search provider honours `include_domains`. When false, no domain-restricted spec is produced.
     * @return array{specs: list<array<string, mixed>>, selected: list<string>, skipped: array<string, string>, eligible: int}
     */
    public function select(Carbon $day, int $slots, array $options = []): array
    {
        $skipped = [];
        $days = max(1, (int) ($options['days'] ?? 45));
        $products = array_values(array_filter((array) ($options['products'] ?? [])));
        $excluded = (array) ($options['excluded_methods'] ?? self::DEFAULT_EXCLUDED_METHODS);
        $shift = (int) ($options['shift'] ?? 0);
        $canRestrict = (bool) ($options['can_restrict_domains'] ?? true);
        $categorySlots = max(0, (int) ($options['category_slots'] ?? 0));

        $eligible = [];
        $category = [];
        foreach ($this->registry->all() as $row) {
            if ($row['status'] === SourceRegistry::NEEDS_URL) {
                $skipped[$row['id']] = 'needs_url';
                $category[] = $row;

                continue;
            }
            if (in_array($row['retrieval_method'], $excluded, true)) {
                $skipped[$row['id']] = 'method_not_searchable:' . $row['retrieval_method'];

                continue;
            }
            if (self::host((string) $row['url']) === '') {
                $skipped[$row['id']] = 'no_usable_host';

                continue;
            }
            $eligible[] = $row + ['score' => self::score($row, $products)];
        }

        $specs = [];
        $selected = [];
        if ($slots > 0 && ! $canRestrict) {
            // Honest limit: a provider that cannot restrict to a domain would return the open web for this query, which is not a search of this source.
            foreach ($eligible as $row) {
                $skipped[$row['id']] = 'provider_cannot_restrict_domain';
            }
            $eligible = [];
        }

        usort($eligible, fn ($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $priority = array_values(array_filter($eligible, fn ($r) => $r['score'] >= self::PRIORITY_SCORE));
        $rest = array_values(array_filter($eligible, fn ($r) => $r['score'] < self::PRIORITY_SCORE));
        $restSlots = $slots > 1 && $rest !== [] ? intdiv($slots, 2) : 0;
        $priorityRows = self::window($priority, $slots - $restSlots, $day, $shift);
        $restRows = self::window($rest, $restSlots, $day, $shift);
        // A tier too small to fill its slots hands them to the other tier.
        $short = $slots - count($priorityRows) - count($restRows);
        if ($short > 0) {
            $more = array_values(array_filter($eligible, fn ($r) => ! in_array($r['id'], array_column($priorityRows, 'id'), true) && ! in_array($r['id'], array_column($restRows, 'id'), true)));
            $restRows = array_merge($restRows, self::window($more, $short, $day, $shift));
        }

        foreach (array_merge($priorityRows, $restRows) as $row) {
            $specs[] = $this->spec($row, $days);
            $selected[] = $row['id'];
        }

        // Sector-wide queries for the rows that have no address yet: one per distinct query text, never domain-restricted.
        if ($categorySlots > 0) {
            $seen = array_column($specs, 'q');
            $category = array_values(array_filter($category, fn ($r) => ! in_array($r['retrieval_method'], $excluded, true)));
            usort($category, fn ($a, $b) => $a['id'] <=> $b['id']);
            foreach (self::window($category, count($category), $day, $shift) as $row) {
                if (count($specs) >= $slots + $categorySlots) {
                    break;
                }
                $q = self::query($row);
                if (in_array($q, $seen, true)) {
                    continue;
                }
                $seen[] = $q;
                $specs[] = ['q' => $q, 'topic' => 'general', 'days' => $days, 'source' => 'registry_category', 'id' => 'regcat-' . $row['id'],
                    'registry_id' => $row['id'], 'registry_status' => $row['status']];
                $selected[] = $row['id'];
            }
        }

        return ['specs' => $specs, 'selected' => $selected, 'skipped' => $skipped, 'eligible' => count($eligible)];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function spec(array $row, int $days): array
    {
        $isNews = in_array($row['category'], ['news'], true);

        return [
            'q' => self::query($row), 'topic' => $isNews ? 'news' : 'general', 'days' => $days,
            'domains' => [self::host((string) $row['url'])], 'source' => 'registry', 'id' => 'reg-' . $row['id'],
            'registry_id' => $row['id'], 'registry_status' => $row['status'],
        ];
    }

    /**
     * The search for one row: the source's own name, the words of its configured path, what this kind of source can show a school needing, and
     * where. Plain words, deterministic, built only from the row (nothing is assumed about the page behind it). Never contains the row's address:
     * the domain restriction (`include_domains`, which narrows a search to that site and excludes nothing) carries that.
     *
     * @param  array<string, mixed>  $row
     */
    public static function query(array $row): string
    {
        $types = (array) $row['signal_types'];
        $what = match (true) {
            in_array('tender', $types, true) || in_array('procurement', $types, true) => 'school management software OR ERP OR LMS OR "smart class" tender OR RFP OR EOI',
            in_array('hiring', $types, true) => 'school principal OR "ERP" OR "academic coordinator" OR "teacher trainer" vacancy',
            in_array('affiliation', $types, true) || in_array('expansion', $types, true) => 'new school OR "new campus" OR "new branch" OR affiliation school group',
            in_array('circular', $types, true) || in_array('policy', $types, true) => 'school circular digital OR ERP OR "teacher training" OR "learning management"',
            default => 'school group expansion OR "new campus" OR "school ERP" OR "school management system"',
        };
        $where = match ($row['region']) {
            // The Gujarat press also names the places; a municipal or government source already carries its own place in its name.
            'gujarat' => ($row['category'] ?? '') === 'news' ? 'Gujarat Ahmedabad OR Surat OR Vadodara OR Rajkot' : 'Gujarat',
            'international' => 'international school group',
            default => 'India',
        };

        return trim(implode(' ', array_filter([self::nameTerm((string) ($row['name'] ?? '')), self::pathTerms((string) ($row['url'] ?? '')), $what, $where])));
    }

    /** The source's name as a quoted phrase, so results are about that body; empty when it is missing or too long to help. */
    private static function nameTerm(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', str_replace('"', '', preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name)) ?? $name);

        return ($name !== '' && mb_strlen($name) <= 60) ? '"' . $name . '"' : '';
    }

    /**
     * The words of the path configured for the source (`/city/ahmedabad` gives `city ahmedabad`; a file name loses its extension), at most three, so
     * a source that is one section of a larger site is searched for that section. Only what is written in the configured address; nothing is guessed.
     */
    private static function pathTerms(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $words = [];
        foreach (preg_split('#[/_\-.]+#', mb_strtolower($path)) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, ['html', 'htm', 'php', 'aspx', 'jsp', 'index', 'home', 'common', 'www', 'new', 'content'], true) && ! in_array($w, $words, true)) {
                $words[] = $w;
            }
        }

        return implode(' ', array_slice($words, 0, 3));
    }

    /** Higher = searched sooner and more often. Visible, additive, and based only on the row itself. */
    public static function score(array $row, array $products = []): int
    {
        $score = match ($row['region']) {
            'gujarat' => 30, 'india' => 20, 'state' => 15, default => 5,
        };
        $score += $row['classification'] === 'official' ? 5 : 0;
        $types = (array) $row['signal_types'];
        $score += (in_array('tender', $types, true) || in_array('procurement', $types, true)) ? 12 : 0;
        $score += (array_intersect(['hiring', 'expansion', 'affiliation'], $types) !== []) ? 6 : 0;
        $score += (array_intersect(['circular', 'policy'], $types) !== []) ? 4 : 0;
        $score += $products === [] ? 0 : 2 * count(array_intersect($products, (array) $row['products']));

        return $score;
    }

    /**
     * `$n` consecutive rows from an already ordered list, starting where the day number says, wrapping round. The same day, shift and list always give
     * the same rows; the next day gives the next rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function window(array $rows, int $n, Carbon $day, int $shift): array
    {
        $count = count($rows);
        if ($n <= 0 || $count === 0) {
            return [];
        }
        $n = min($n, $count);
        $start = ((intdiv($day->copy()->startOfDay()->getTimestamp(), 86400) + $shift) * $n) % $count;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $rows[($start + $i) % $count];
        }

        return $out;
    }

    public static function host(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', mb_strtolower($host)) : '';
    }
}
