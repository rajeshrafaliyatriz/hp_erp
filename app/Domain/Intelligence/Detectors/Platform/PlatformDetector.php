<?php

namespace App\Domain\Intelligence\Detectors\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing of the platform / AI-governance / agentic detectors.
 *
 * This data is ORGANISATION data about the estate's plumbing (tasks, integrations, events, AI usage, policies, agents), not people data: `population` is always
 * null, no person is named or counted, and what limits who sees it is the menu right on the screen the insight links to (PlatformAccess). Executives and
 * auditors receive no record references (IntelligenceCaller::mayReferenceIndividuals()), like the commercial detectors.
 *
 * Failure rule (shared with the HR detectors): a source that is absent on this installation answers `data_unavailable`; a query that throws becomes a generic
 * `error` result: never an exception out of detect(), never the text of the failure, and never zero.
 */
abstract class PlatformDetector implements InsightDetector
{
    /** At most this many record references per insight. */
    protected const MAX_ENTITY_EVIDENCE = 10;

    public function __construct(
        protected readonly ConnectionInterface $db,
        protected readonly Thresholds $thresholds,
    ) {
    }

    public function domain(): string
    {
        return Taxonomy::DOMAIN_PLATFORM;
    }

    final public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        try {
            return $this->assess($caller, $clock);
        } catch (\Throwable $e) {
            try {
                // The detector id and the exception CLASS only: never the message (SQL, table names, values) and never a trace.
                Log::warning('intelligence.detector_failed', ['detector' => $this->id(), 'exception' => $e::class]);
            } catch (\Throwable) {
                // a logging problem must not turn a contained failure into an uncontained one
            }

            return DetectorResult::failed($this, $clock->asOf());
        }
    }

    abstract protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult;

    protected function audience(): string
    {
        return 'organization';
    }

    /**
     * Whether `$table` exists with every one of `$columns` on this connection (same probe as the HR detectors: the schema builder on SQLite, a LIMIT 1 select
     * everywhere else, because the schema builder's hasColumn() is not usable on the live MariaDB 10.1). Only names the detector itself passes are probed.
     *
     * @param list<string> $columns
     */
    protected function canRead(string $table, array $columns): bool
    {
        try {
            if ($this->db->getDriverName() === 'sqlite') {
                $schema = $this->db->getSchemaBuilder();
                if (! $schema->hasTable($table)) {
                    return false;
                }
                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        return false;
                    }
                }

                return true;
            }
            $this->db->table($table)->select($columns)->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Entity references (kind + id) for the screen to deep-link to; none for a caller who may not reference individuals (executive, auditor).
     *
     * @param  list<int|string>  $ids
     * @return list<Evidence>
     */
    protected function entities(IntelligenceCaller $caller, string $label, string $type, array $ids): array
    {
        if (! $caller->mayReferenceIndividuals()) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_ENTITY_EVIDENCE) as $id) {
            if ($id > 0) {
                $out[] = Evidence::entity($label, $type, $id);
            }
        }

        return $out;
    }

    /**
     * A machine identifier (task key, consumer name, provider key, module key) made safe to show as a label: only letters, digits and `: . _ -`, at most 60
     * characters. Anything else is shown as a generic word, never echoed: a stored value is data, not text to trust.
     */
    protected static function safeKey(mixed $value, string $fallback = 'other'): string
    {
        $text = trim((string) $value);

        return $text !== '' && strlen($text) <= 60 && preg_match('/^[a-zA-Z0-9:._\-]+$/', $text) === 1 ? $text : $fallback;
    }

    protected static function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }

    /** Stable insight key: detector + subject + tenant + the day, so one open insight per (detector, subject, day). */
    protected function key(IntelligenceCaller $caller, string $subject, string $today): string
    {
        return sprintf('%s:%s:t%d:%s', $this->id(), $subject, $caller->tenantId, $today);
    }
}
