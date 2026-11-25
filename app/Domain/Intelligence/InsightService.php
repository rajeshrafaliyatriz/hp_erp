<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the detectors a caller is entitled to and shapes the answer. It owns no rule and reads no table itself: it is the place where the order of operations
 * that keeps Intelligence safe is fixed.
 *
 *   1. AUTHORISE FIRST. `authorizes()` is asked of every detector before anything is read; a detector the caller is not authorised for is NEVER run. It is
 *      reported as a bare `access_restricted` entry (id, domain, title, fixed reason: no counts, no coverage, no insights), built without reading any data.
 *      A detector whose `authorizes()` throws is treated the same way (fail closed). A `detector=` filter naming a detector the caller may not use is
 *      indistinguishable from an unknown id.
 *   2. ISOLATE FAILURES. A detector that throws becomes a generic `error` result. The log line carries the detector's class and the exception's class only: never
 *      the message (which can carry SQL, table names or values) and never a trace. The response carries a fixed sentence.
 *   3. SUPPRESS SMALL GROUPS before anything is counted for the caller, and report only HOW MANY were withheld (never what they were).
 *   4. FILTER, SORT, PAGINATE. Unknown filter values are ignored, never an error and never a way to learn what exists.
 *
 * It does not persist, cache, dispatch or call a model.
 */
final class InsightService
{
    public const MAX_PER_PAGE = 50;

    public const SUPPRESSION_NOTE = 'Some results are withheld to protect small groups.';

    public const GENERIC_COVERAGE_REASON = 'Not enough people to show without identifying individuals.';

    public const THRESHOLDS_NOTE = 'Every threshold behind these insights is provisional and has not been calibrated against real data.';

    /** @var list<InsightDetector> */
    private array $detectors;

    /** @param list<InsightDetector> $detectors */
    public function __construct(array $detectors, private readonly SmallGroupGuard $guard, private readonly ?DetectionClock $clock = null)
    {
        $this->detectors = array_values(array_filter($detectors, static fn ($d) => $d instanceof InsightDetector));
    }

    /**
     * @param  array<string, mixed>  $filters  domain, severity (minimum), category, surface, detector
     * @return array{results: list<DetectorResult>, insights: list<Insight>, suppressed: int, meta: array<string, mixed>}
     */
    public function run(IntelligenceCaller $caller, array $filters = []): array
    {
        $clock = $this->clock ?? DetectionClock::now();
        $asOf = $clock->asOf();

        // 1. Authorise first. Nothing is read for a detector that does not authorise this caller.
        $authorised = [];
        $restricted = [];
        foreach ($this->detectors as $detector) {
            try {
                if ($detector->authorizes($caller)) {
                    $authorised[$detector->id()] = $detector;

                    continue;
                }
            } catch (Throwable $e) {
                $this->logFailure($detector, $e, 'authorizes');
            }
            $restricted[$detector->id()] = $detector;
        }

        $filter = $this->normalizeFilters($filters, array_keys($authorised));

        $results = [];
        $suppressed = 0;
        $ran = 0;
        foreach ($this->detectors as $detector) {
            $id = $detector->id();
            if ($filter['domain'] !== null && $detector->domain() !== $filter['domain']) {
                continue;
            }
            if ($filter['detector'] !== null && $id !== $filter['detector']) {
                continue;
            }
            if (isset($restricted[$id])) {
                // Identity only: the detector is not run and nothing is read for it.
                $results[] = DetectorResult::accessRestricted($detector, $asOf);

                continue;
            }
            if (! isset($authorised[$id]) || $authorised[$id] !== $detector) {
                continue;   // a duplicate id: only the last registration for an id runs
            }
            $ran++;

            // 2. A failing detector never takes the others down and never leaks why.
            try {
                $result = $detector->detect($caller, $clock);
            } catch (Throwable $e) {
                $this->logFailure($detector, $e, 'detect');
                $results[] = DetectorResult::failed($detector, $asOf);

                continue;
            }

            // 3. Small-group suppression, then count only how many were withheld.
            $kept = [];
            foreach ($result->insights as $insight) {
                if ($this->guard->suppresses($caller, $insight->population)) {
                    $suppressed++;

                    continue;
                }
                $kept[] = $insight;
            }
            $results[] = count($kept) === count($result->insights) ? $result : $result->withInsights($kept);
        }

        // 4. Filter, de-duplicate, sort.
        $insights = [];
        $seen = [];
        foreach ($results as $result) {
            foreach ($result->insights as $insight) {
                if ($filter['severity'] !== null && Taxonomy::severityRank($insight->severity) < Taxonomy::severityRank($filter['severity'])) {
                    continue;
                }
                if ($filter['category'] !== null && $insight->category !== $filter['category']) {
                    continue;
                }
                if ($filter['surface'] !== null && ! in_array($filter['surface'], $insight->surfaces, true)) {
                    continue;
                }
                if (isset($seen[$insight->key])) {
                    continue;
                }
                $seen[$insight->key] = true;
                $insights[] = $insight;
            }
        }
        usort($insights, static function (Insight $a, Insight $b): int {
            return [Taxonomy::severityRank($b->severity), $b->asOf, $a->key] <=> [Taxonomy::severityRank($a->severity), $a->asOf, $b->key];
        });

        $subject = $this->guard->appliesTo($caller);

        $states = array_fill_keys(Taxonomy::STATES, 0);
        foreach ($results as $result) {
            $states[$result->state]++;
        }

        return [
            'results' => $results,
            'insights' => $insights,
            'suppressed' => $suppressed,
            'meta' => [
                'generated_at' => $asOf,
                'scope' => $caller->scope(),
                'provisional' => true,
                'thresholds_note' => self::THRESHOLDS_NOTE,
                // A caller under the small-group rule must not learn THAT something was withheld nor how many: "1 withheld" in a team of 3 is itself disclosure.
                'suppressed_count' => $subject ? null : $suppressed,
                'suppression_note' => $subject && $suppressed > 0 ? self::SUPPRESSION_NOTE : null,
                'detectors_run' => $ran,   // detectors actually run; access_restricted entries were not run (see meta.states)
                'states' => $states,
                'filters' => array_filter($filter, static fn ($v) => $v !== null),
            ],
        ];
    }

    /**
     * The per-detector rows of the Evidence & coverage view. For a caller under the small-group rule, coverage numbers are hidden when the group is below the
     * minimum: `observed`, `expected` and `percent` become null (the basis and the `sufficient` flag stay) and the reason becomes a fixed sentence, because the
     * ordinary one ("Only 2 record(s) support this...") states the group size. Callers not under the rule keep the exact numbers.
     *
     * @param  list<DetectorResult>  $results
     * @return list<array<string, mixed>>
     */
    public function detectorSummaries(IntelligenceCaller $caller, array $results): array
    {
        $subject = $this->guard->appliesTo($caller);
        $minimum = $this->guard->minimum();

        return array_map(static function (DetectorResult $result) use ($subject, $minimum): array {
            $summary = $result->summary();
            $masked = $subject && $result->coverage !== null ? self::maskedCoverage($result->coverage, $minimum) : null;
            if ($masked === null) {
                return $summary;
            }
            $summary['coverage'] = $masked;
            if ($summary['reason'] !== null) {
                $summary['reason'] = self::GENERIC_COVERAGE_REASON;
            }

            return $summary;
        }, $results);
    }

    /**
     * Insights as the API sends them. For a caller under the small-group rule, each insight's own coverage block gets the same masking as the detector view: when
     * observed OR expected is below the guard minimum, observed/expected/percent are null (basis and sufficient stay) and the reason is the generic sentence,
     * because the ordinary one ("Only N record(s) support this; at least M are needed") states a count. Done at serialisation time: Insight stays immutable.
     * Callers not under the rule get the exact numbers.
     *
     * @param  list<Insight>  $insights
     * @return list<array<string, mixed>>
     */
    public function serialiseInsights(IntelligenceCaller $caller, array $insights): array
    {
        $subject = $this->guard->appliesTo($caller);
        $minimum = $this->guard->minimum();

        return array_map(static function (Insight $insight) use ($subject, $minimum): array {
            $array = $insight->toArray();
            $masked = $subject ? self::maskedCoverage($insight->coverage, $minimum) : null;
            if ($masked !== null) {
                $array['coverage'] = $masked;
            }

            return $array;
        }, $insights);
    }

    /** The masked coverage block, or null when the numbers are safe to show (both at or above the minimum). */
    private static function maskedCoverage(Coverage $coverage, int $minimum): ?array
    {
        $tooSmall = $coverage->observed < $minimum || ($coverage->expected !== null && $coverage->expected < $minimum);
        if (! $tooSmall) {
            return null;
        }

        return [
            'observed' => null,
            'expected' => null,
            'percent' => null,
            'basis' => $coverage->basis,
            'sufficient' => $coverage->sufficient,
            'reason' => self::GENERIC_COVERAGE_REASON,
        ];
    }

    /**
     * The INTELLIGENCE_ENABLED switch, parsed so that anything unparseable FAILS CLOSED (disabled). Unset means enabled. An empty string is also disabled:
     * "INTELLIGENCE_ENABLED=" is far more likely a mistake than a decision, and a switch that cannot be read must not be assumed to be on.
     */
    public static function parseEnabled(mixed $raw): bool
    {
        if ($raw === null) {
            return true;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (! is_string($raw) && ! is_int($raw)) {
            return false;
        }

        return filter_var(trim((string) $raw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Only a recognised value survives; everything else becomes "no filter". A detector id counts as recognised only if it belongs to a detector the caller is
     * authorised for, so an id that does not exist and one the caller may not use are indistinguishable.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $authorisedIds
     * @return array{domain: ?string, severity: ?string, category: ?string, surface: ?string, detector: ?string}
     */
    private function normalizeFilters(array $filters, array $authorisedIds): array
    {
        $text = static fn ($v): ?string => is_string($v) && trim($v) !== '' && strlen($v) <= 64 ? trim($v) : null;
        $oneOf = static fn ($v, array $allowed): ?string => is_string($v) && in_array($v, $allowed, true) ? $v : null;

        $detector = $text($filters['detector'] ?? null);

        return [
            'domain' => $oneOf($filters['domain'] ?? null, Taxonomy::DOMAINS),
            'severity' => $oneOf($filters['severity'] ?? null, Taxonomy::SEVERITIES),
            'category' => $text($filters['category'] ?? null),
            'surface' => $oneOf($filters['surface'] ?? null, Taxonomy::SURFACES),
            'detector' => $detector !== null && in_array($detector, $authorisedIds, true) ? $detector : null,
        ];
    }

    private function logFailure(InsightDetector $detector, Throwable $e, string $stage): void
    {
        // Class names only: a message can contain SQL, table names or record values.
        Log::warning('intelligence.detector_failed', ['detector' => $detector::class, 'stage' => $stage, 'exception' => $e::class]);
    }

    /**
     * Page a list. `per_page` is clamped to 1..MAX_PER_PAGE and `page` to at least 1, whatever the caller asked for.
     *
     * @template T
     * @param  list<T>  $items
     * @return array{items: list<T>, pagination: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public static function paginate(array $items, int $page, int $perPage): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $total = count($items);

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))],
        ];
    }
}
