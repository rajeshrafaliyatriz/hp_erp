<?php

namespace App\Domain\Signals\Run;

/**
 * The daily-yield report: for each day and product, did the system reach the target of unique, new, qualified signals, and if not, why.
 * Pure: it only arranges rows it is given (read-only selects done by `signals:yield-report`), counts what the rows say, and never estimates,
 * extrapolates or fills a missing figure. A run without stage statistics contributes its run row and nothing else (its detail is "unrecorded").
 *
 * Definitions (so two people read the same number the same way):
 *  - run            one row of g2g_research_runs, by `report_date` (the application's day) and `product_key`.
 *  - completed      status success; partial = finished with limits; failed and skipped never count as completed.
 *  - qualified      what the run reports as qualified (new + updated + unchanged-existing): re-observations are INCLUDED here on purpose, to show
 *                   how much of a run's output was already known.
 *  - unique new     signals first stored on that day for that product (`first_discovered_at`), counted from the signal rows, once each. Only this
 *                   number is compared with the target. Informational or rejected records are never in it.
 */
final class YieldReport
{
    public const DEFAULT_TARGET = 10;

    /**
     * @param  list<array<string, mixed>>  $runs  id, product_key, report_date (YYYY-MM-DD), status, stage_stats (array|json|null)
     * @param  list<array<string, mixed>>  $signals  id, product_key, first_day (YYYY-MM-DD), claim_level, evidence_class (nullable), source_host (nullable)
     * @return array{target: int, days: list<array<string, mixed>>, notes: list<string>}
     */
    public static function build(array $runs, array $signals, int $target = self::DEFAULT_TARGET): array
    {
        $cells = [];
        $cell = function (string $day, string $product) use (&$cells) {
            $k = $day . '|' . $product;

            return $cells[$k] ??= [
                'date' => $day, 'product' => $product,
                'runs' => ['total' => 0, 'success' => 0, 'partial' => 0, 'failed' => 0, 'skipped' => 0, 'other' => 0, 'without_stage_stats' => 0],
                'qualified_reported' => 0, 'reobserved' => 0, 'reported_new' => 0, 'unique_new' => 0, 'rejected' => [], 'rejection_detail' => [],
                'completion' => [], 'ai_failed' => 0, 'queries_failed' => 0, 'pages_failed' => 0, 'robots_blocked' => 0,
                'evidence_mix' => [], 'claim_mix' => [],
            ];
        };

        foreach ($runs as $r) {
            $c = &$cells[self::key($cell, (string) $r['report_date'], (string) ($r['product_key'] ?? 'unassigned'))];
            $c['runs']['total']++;
            $status = (string) $r['status'];
            $c['runs'][in_array($status, ['success', 'partial', 'failed', 'skipped'], true) ? $status : 'other']++;
            $stats = is_string($r['stage_stats'] ?? null) ? json_decode($r['stage_stats'], true) : ($r['stage_stats'] ?? null);
            if (! is_array($stats)) {
                $c['runs']['without_stage_stats']++;
                unset($c);

                continue;
            }
            $o = (array) ($stats['outcome'] ?? []);
            $c['reported_new'] += (int) ($o['new'] ?? 0);
            $c['qualified_reported'] += (int) ($o['qualified_total'] ?? 0);
            $c['reobserved'] += (int) ($o['updated'] ?? 0) + (int) ($o['unchanged_existing'] ?? 0);
            foreach ((array) ($stats['zero_reasons'] ?? []) as $z) {
                $c['rejected'][(string) ($z['code'] ?? $z['label'] ?? 'unknown')] = ($c['rejected'][(string) ($z['code'] ?? $z['label'] ?? 'unknown')] ?? 0) + (int) ($z['count'] ?? 0);
            }
            foreach ((array) ($stats['rejection_detail'] ?? []) as $code => $details) {
                foreach ((array) $details as $detail => $n) {
                    $c['rejection_detail']["{$code}:{$detail}"] = ($c['rejection_detail']["{$code}:{$detail}"] ?? 0) + (int) $n;
                }
            }
            $comp = (string) ($stats['completion'] ?? 'unrecorded');
            $c['completion'][$comp] = ($c['completion'][$comp] ?? 0) + 1;
            $c['ai_failed'] += (int) ($stats['ai']['main_failed'] ?? 0) + (int) ($stats['ai']['followup_failed'] ?? 0);
            $c['queries_failed'] += (int) ($stats['queries']['failed'] ?? 0);
            $c['pages_failed'] += (int) array_sum((array) ($stats['pages']['failed_by_category'] ?? []));
            $c['robots_blocked'] += (int) ($stats['pages']['robots_disallowed'] ?? 0);
            unset($c);
        }

        foreach ($signals as $s) {
            $c = &$cells[self::key($cell, (string) $s['first_day'], (string) ($s['product_key'] ?? 'unassigned'))];
            $c['unique_new']++;
            $e = (string) ($s['evidence_class'] ?? 'unrecorded');
            $c['evidence_mix'][$e] = ($c['evidence_mix'][$e] ?? 0) + 1;
            $cl = (string) ($s['claim_level'] ?? 'unrecorded');
            $c['claim_mix'][$cl] = ($c['claim_mix'][$cl] ?? 0) + 1;
            unset($c);
        }

        ksort($cells);
        $days = [];
        foreach ($cells as $c) {
            arsort($c['rejected']);
            arsort($c['rejection_detail']);
            $c['target_met'] = $c['unique_new'] >= $target;
            $c['shortfall'] = $c['target_met'] ? null : self::shortfall($c, $target);
            $days[] = $c;
        }

        return ['target' => $target, 'days' => $days, 'notes' => [
            'unique_new counts signals first stored that day, once each; re-observations and informational records are not counted toward the target.',
            'A figure that no run recorded is shown as unrecorded or absent, never as zero or an estimate.',
        ]];
    }

    /** @param callable(string, string): array<string, mixed> $cell */
    private static function key(callable $cell, string $day, string $product): string
    {
        $cell($day, $product);

        return $day . '|' . $product;
    }

    /** The biggest recorded reason a day fell short, in plain words. @param array<string, mixed> $c */
    private static function shortfall(array $c, int $target): string
    {
        $runs = $c['runs'];
        if ($runs['total'] === 0) {
            return 'No research ran that day.';
        }
        if ($runs['success'] + $runs['partial'] === 0) {
            return "No run completed ({$runs['failed']} failed, {$runs['skipped']} skipped).";
        }
        if (($c['completion']['incomplete'] ?? 0) > 0) {
            return ($c['completion']['incomplete']) . ' run(s) did not finish (AI failure, a time or page limit, or failed searches): absence of signals proves nothing for those.';
        }
        if ($c['reobserved'] > 0 && $c['reobserved'] >= $c['reported_new']) {
            return 'Most of what the runs found was already known (' . $c['reobserved'] . ' re-observed).';
        }
        if ($c['rejection_detail'] !== []) {
            return 'Candidates were rejected: mostly ' . array_key_first($c['rejection_detail']) . '.';
        }
        if ($c['rejected'] !== []) {
            return 'Candidates did not qualify: mostly ' . array_key_first($c['rejected']) . '.';
        }

        return "Fewer than {$target} unique new qualified signals were found, and no run recorded a specific reason.";
    }
}
