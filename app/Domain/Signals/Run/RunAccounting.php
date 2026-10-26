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

        $stats = [
            'queries' => ['planned' => $planned, 'attempted' => $executed, 'succeeded' => max(0, $executed - $failed), 'failed' => $failed, 'skipped' => count($skipped)],
            'results' => ['returned' => $returned, 'dropped_too_old' => (int) ($diag['dropped_old'] ?? 0), 'screened_out' => (int) ($diag['sources_screened_out'] ?? 0), 'kept' => $sources],
            'pages' => ['fetched' => (int) ($diag['pages_fetched'] ?? 0), 'failed' => $pagesFailed],
            'candidates' => ['proposed' => $candidates, 'rejected_before_gate' => array_sum($rejections), 'rejected_by_gate' => $gate],
            'outcome' => ['new' => $new, 'updated' => $updated, 'unchanged_existing' => $same, 'qualified_total' => $new + $updated + $same],
            'errors_by_stage' => self::errors($diag),
        ];
        $stats['zero_reasons'] = $stats['outcome']['qualified_total'] === 0 ? self::zeroReasons($stats, $rejections) : [];
        $stats['next_action'] = self::nextAction($stats);

        return $stats;
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
