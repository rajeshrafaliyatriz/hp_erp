<?php

namespace App\Domain\Signals\Run;

/**
 * What a research run actually did, stage by stage, from the counters the researcher already keeps. Pure: it reads arrays and returns arrays, so it
 * is the same on every environment and needs no database or provider.
 *
 * Four different things are never merged: results discovered, candidates the model proposed, signals that passed the gate and were stored, and
 * signals that already existed. "qualified" in the run row is new + updated + unchanged (kept for the existing screens); `outcome` here lists each.
 */
final class RunAccounting
{
    /** Reason codes the pipeline tracks, in the words a salesperson reads. Unknown codes are shown as their own code, never guessed. */
    private const LABELS = [
        'no_search_results' => 'The searches returned nothing recent.',
        'results_too_old' => 'Everything found was older than the freshness window.',
        'source_off_topic' => 'Pages were not about an Indian school.',
        'source_prompt_injection' => 'Pages carried instructions aimed at the AI and were dropped.',
        'pages_unreadable' => 'The pages could not be read (blocked, login wall or error).',
        'no_candidate_proposed' => 'The pages were read, but none showed a school with a clear reason to buy.',
        'rejected_by_gate' => 'Candidates failed the evidence checks (date, source, product fit).',
        'duplicate_of_existing' => 'The signals found already existed and were unchanged.',
        'all_searches_failed' => 'Every search failed, so nothing could be assessed.',
    ];

    /**
     * @param  array<string, mixed>  $diag  planned, executed, skipped (id => reason), failed (id => kind), returned, dropped_old, sources_screened_out, pages_fetched, pages_failed, pages_blocked_by_robots, import
     * @param  array<string, int>  $rejections  reason code => count, from before the gate
     * @param  array<string, int>  $totals  sources (kept after screening), candidates (proposed by the model), considered
     * @return array<string, mixed>
     */
    public static function build(array $diag, array $rejections, array $totals): array
    {
        $import = (array) ($diag['import'] ?? []);
        $planned = count((array) ($diag['planned'] ?? []));
        $executed = count((array) ($diag['executed'] ?? []));
        $failed = count((array) ($diag['failed'] ?? []));
        $skipped = (array) ($diag['skipped'] ?? []);
        $new = (int) ($import['accepted'] ?? 0);
        $updated = (int) ($import['updated'] ?? 0);
        $same = (int) ($import['duplicate'] ?? 0);
        $gate = (int) ($import['rejected_by_gate'] ?? 0);
        $candidates = (int) ($totals['candidates'] ?? 0);
        $sources = (int) ($totals['sources'] ?? 0);
        $returned = (int) ($diag['returned'] ?? 0);
        $pagesFailed = (int) ($diag['pages_failed'] ?? 0) + (int) ($diag['pages_blocked_by_robots'] ?? 0);

        $cacheHits = count(array_filter($skipped, fn ($why) => $why === 'searched_within_cache_window'));
        $byReason = [];
        foreach ($skipped as $why) {
            $byReason[(string) $why] = ($byReason[(string) $why] ?? 0) + 1;
        }
        ksort($byReason);
        // With results / empty are known only for runs that recorded them (null = unavailable, never zero).
        $known = array_key_exists('productive', $diag) && array_key_exists('empty', $diag);
        $stats = [
            'queries' => [
                'planned' => $planned, 'attempted' => $executed,
                'succeeded' => max(0, $executed - $failed),   // attempted and not failed = with_results + empty
                'with_results' => $known ? count((array) $diag['productive']) : null,
                'empty' => $known ? count((array) $diag['empty']) : null,
                'failed' => $failed,
                'cache_hits' => $cacheHits,                    // not run now: results were already paid for
                'skipped' => count($skipped) - $cacheHits,     // not run for any other reason; see skipped_by_reason
                'skipped_by_reason' => $byReason,
            ],
            'results' => ['returned' => $returned, 'dropped_too_old' => (int) ($diag['dropped_old'] ?? 0), 'screened_out' => (int) ($diag['sources_screened_out'] ?? 0), 'kept' => $sources],
            'pages' => self::pages($diag, $pagesFailed),
            'candidates' => ['proposed' => $candidates, 'rejected_before_gate' => array_sum($rejections), 'rejected_by_gate' => $gate],
            'outcome' => ['new' => $new, 'updated' => $updated, 'unchanged_existing' => $same, 'qualified_total' => $new + $updated + $same],
            'errors_by_stage' => self::errors($diag),
            'registry' => self::registry($diag),
            'direct' => array_key_exists('direct', $diag) ? [
                'sources_selected' => (int) $diag['direct']['sources_selected'], 'listings_read' => (int) $diag['direct']['listings_read'],
                'items_found' => (int) $diag['direct']['items_found'], 'items_read' => (int) $diag['direct']['items_read'],
                'items_skipped_seen' => (int) $diag['direct']['items_skipped_seen'], 'refused_robots' => (int) $diag['direct']['refused_robots'],
                'failed_by_category' => (array) $diag['direct']['failed'], 'by_source' => (array) ($diag['direct']['by_source'] ?? []),
            ] : null,   // null = this run did not read sources directly (not zero)
            'budget' => isset($diag['budget']) ? (array) $diag['budget'] : null,   // verification ceilings and what was used; null on ordinary runs
            'name_search' => self::nameSearch($diag),
        ];
        $stats['zero_reasons'] = $stats['outcome']['qualified_total'] === 0 ? self::zeroReasons($stats, $rejections) : [];
        $stats['next_action'] = self::nextAction($stats);

        return $stats;
    }

    /**
     * Registry sources SELECTED for this run versus searches that really RAN against them (and failed). A selected source whose search was skipped
     * (time budget, cache window) is not counted as searched. `null` = this run kept no registry record (a run made before it existed or a named
     * scan): that is "unavailable", never zero.
     *
     * @param  array<string, mixed>  $diag
     * @return array<string, mixed>|null
     */
    private static function registry(array $diag): ?array
    {
        if (! array_key_exists('registry', $diag)) {
            return null;
        }
        $isReg = fn ($id) => str_starts_with((string) $id, 'reg-') || str_starts_with((string) $id, 'regcat-');
        $attempted = array_filter((array) ($diag['executed'] ?? []), $isReg);
        $failed = array_filter(array_keys((array) ($diag['failed'] ?? [])), $isReg);
        $known = array_key_exists('productive', $diag) && array_key_exists('empty', $diag);
        $withResults = array_filter((array) ($diag['productive'] ?? []), $isReg);
        $empty = array_filter((array) ($diag['empty'] ?? []), $isReg);
        $skipped = array_filter(array_keys((array) ($diag['skipped'] ?? [])), $isReg);
        $cached = array_filter($skipped, fn ($id) => ($diag['skipped'][$id] ?? null) === 'searched_within_cache_window');

        return [
            'selected' => count((array) ($diag['registry']['selected'] ?? [])),
            'eligible' => (int) ($diag['registry']['eligible'] ?? 0),
            'attempted' => count($attempted),
            'searched' => max(0, count($attempted) - count($failed)),   // searches that ran and did not fail
            'succeeded_with_results' => $known ? count($withResults) : null,
            'succeeded_empty' => $known ? count($empty) : null,
            'failed' => count($failed),
            'served_from_cache' => count($cached),      // not run now: never counted as attempted or searched
            'skipped_other' => count($skipped) - count($cached),
            'not_selected_reason' => $diag['registry']['skipped_reason'] ?? null,
            'verified' => false,   // selecting or searching a source never verifies it
        ];
    }

    /** Schools searched by name (no verified website). `null` when this run kept no name-search record. @param array<string, mixed> $diag @return array<string, mixed>|null */
    private static function nameSearch(array $diag): ?array
    {
        if (! array_key_exists('name_search', $diag)) {
            return null;
        }
        $accounts = (array) ($diag['name_search']['accounts'] ?? []);
        $results = (array) ($diag['name_search']['results'] ?? []);
        $ran = array_filter((array) ($diag['executed'] ?? []), fn ($id) => str_starts_with((string) $id, 'account-name-'));
        $candidateHosts = 0;
        foreach ($results as $r) {
            $candidateHosts += count((array) ($r['candidate_official_hosts'] ?? []));
        }

        return ['skipped_missing_context' => (int) ($diag['name_search']['missing_context'] ?? 0), 'schools_selected' => count($accounts), 'searches_run' => count($ran), 'schools_with_candidate_site' => count(array_filter($results, fn ($r) => ! empty($r['candidate_official_hosts']))),
            'candidate_sites' => $candidateHosts, 'candidate_sites_verified' => 0];
    }

    /**
     * Pages: read, failed (kept as before: failures plus robots refusals), SKIPPED (no request made, e.g. an unreadable social host: neither read
     * nor failed), and why. The by-category maps are null for a run that did not record them (unavailable, never zero). When recorded, the
     * categories add up to `failed` (a robots refusal is the category `robots_disallowed`).
     *
     * @param  array<string, mixed>  $diag
     * @return array<string, mixed>
     */
    private static function pages(array $diag, int $failed): array
    {
        $skipped = (array) ($diag['pages_skipped'] ?? []);
        $known = array_key_exists('page_failures', $diag);
        $byCategory = null;
        if ($known) {
            $byCategory = array_map('intval', (array) $diag['page_failures']);
            if ((int) ($diag['pages_blocked_by_robots'] ?? 0) > 0) {
                $byCategory['robots_disallowed'] = (int) $diag['pages_blocked_by_robots'];
            }
            ksort($byCategory);
        }
        $byHost = $known ? array_map('intval', (array) ($diag['page_failures_by_host_class'] ?? [])) : null;
        if ($byHost !== null) {
            ksort($byHost);
        }

        return [
            'fetched' => (int) ($diag['pages_fetched'] ?? 0), 'failed' => $failed,
            'blocked_by_robots' => (int) ($diag['pages_blocked_by_robots'] ?? 0),
            'skipped' => array_key_exists('pages_skipped', $diag) ? array_sum($skipped) : null,
            'skipped_by_reason' => array_key_exists('pages_skipped', $diag) ? $skipped : null,
            'failed_by_category' => $byCategory,
            'failed_by_host_class' => $byHost,
        ];
    }

    /** @param array<string, mixed> $diag @return array<string, array<string, int>> */
    private static function errors(array $diag): array
    {
        $out = [];
        foreach ((array) ($diag['failed'] ?? []) as $kind) {
            $out['search'][(string) $kind] = ($out['search'][(string) $kind] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Only reasons the run really tracked, each with its count; the biggest first. Nothing is estimated.
     *
     * @param  array<string, mixed>  $s
     * @param  array<string, int>  $rejections
     * @return list<array{code: string, label: string, count: int}>
     */
    public static function zeroReasons(array $s, array $rejections): array
    {
        $rows = [];
        $add = function (string $code, int $count) use (&$rows) {
            if ($count > 0) {
                $rows[] = ['code' => $code, 'label' => self::LABELS[$code] ?? $code, 'count' => $count];
            }
        };

        if ($s['queries']['attempted'] > 0 && $s['queries']['failed'] === $s['queries']['attempted']) {
            $add('all_searches_failed', $s['queries']['failed']);
        } elseif ($s['results']['returned'] === 0) {
            $add('no_search_results', max(1, $s['queries']['succeeded']));
        }
        $add('results_too_old', $s['results']['dropped_too_old']);
        foreach ($rejections as $code => $n) {
            $add((string) $code, (int) $n);
        }
        $add('pages_unreadable', $s['pages']['failed']);
        $add('rejected_by_gate', $s['candidates']['rejected_by_gate']);
        if ($s['results']['kept'] > 0 && $s['candidates']['proposed'] === 0) {
            $add('no_candidate_proposed', $s['results']['kept']);
        }
        $add('duplicate_of_existing', $s['outcome']['unchanged_existing']);

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    /** @param array<string, mixed> $s */
    private static function nextAction(array $s): string
    {
        if ($s['outcome']['qualified_total'] > 0) {
            return 'Review the new signals and approach the ones marked "Approach now".';
        }
        if ($s['queries']['attempted'] > 0 && $s['queries']['failed'] === $s['queries']['attempted']) {
            return 'Check the search provider key, quota and network; nothing was assessed.';
        }
        if ($s['results']['returned'] === 0) {
            return 'Widen the freshness window or add sources; the searches returned nothing.';
        }
        if ($s['results']['kept'] === 0) {
            return 'The results were off-topic or too old; tighten the searches or add direct sources.';
        }

        return 'The pages were read but none showed a clear reason to buy; add direct sources (tenders, new affiliations) or school websites.';
    }

    /**
     * The run's status from what really happened. Zero qualified is still "success" (the run did its job); a failed source makes it "partial";
     * every search failing with no direct source to fall back on makes it "failed".
     */
    public static function status(int $queriesRun, int $queriesFailed, bool $officialFailed, bool $timeLimited, bool $hadDirectSources): string
    {
        if ($queriesRun > 0 && $queriesFailed === $queriesRun && ! $hadDirectSources) {
            return 'failed';
        }

        return ($queriesFailed > 0 || $officialFailed || $timeLimited) ? 'partial' : 'success';
    }
}
