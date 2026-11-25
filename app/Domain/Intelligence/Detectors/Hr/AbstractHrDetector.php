<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing of the HR detectors: the injected connection, the provisional thresholds, a stable insight key, and the one rule every HR detector
 * shares about failure: a query that cannot run (a table or column absent on this installation, a driver error) yields a generic `error` result,
 * never an exception and never the text of the failure.
 *
 * Nothing here queries anything on its own.
 */
abstract class AbstractHrDetector implements InsightDetector
{
    /** The roles that read the whole organisation's people data without an HR role: kept here so the detectors agree. */
    protected const PEOPLE_ROLES = ['employee', 'reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor'];

    public function __construct(
        protected readonly ConnectionInterface $db,
        protected readonly Thresholds $thresholds,
    ) {
    }

    public function domain(): string
    {
        return Taxonomy::DOMAIN_HR;
    }

    final public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        try {
            return $this->assess($caller, $clock);
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return DetectorResult::failed($this, $clock->asOf());
        }
    }

    /**
     * The detector id and the exception CLASS only. Never the message (it can carry SQL, table names or record values), never the trace, never an argument.
     * A logging problem must not turn a contained failure into an uncontained one.
     */
    private function logFailure(\Throwable $e): void
    {
        try {
            Log::warning('intelligence.detector_failed', ['detector' => $this->id(), 'exception' => $e::class]);
        } catch (\Throwable) {
            // nothing more can be done, and nothing must escape
        }
    }

    abstract protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult;

    /** A key that is stable for one detector, metric, caller scope and day. */
    protected function keyFor(string $metric, IntelligenceCaller $caller, DetectionClock $clock, string $bucket = ''): string
    {
        $scopeId = match ($caller->scope()) {
            'self', 'team' => $caller->userId,
            'department' => $caller->departmentId ?? 0,
            default => 0,
        };

        return sprintf('%s:%s:t%d:%s%d:%s:%s', $this->id(), $metric, $caller->tenantId, $caller->scope(), $scopeId, $bucket, $clock->today());
    }

    /**
     * Whether `$table` exists with every one of `$columns` on this connection.
     *
     * SQLite answers through the schema builder (a quoted unknown identifier is read there as a string literal, so a probe query would wrongly succeed);
     * every other driver is asked by selecting the columns with LIMIT 1, because the schema builder's hasColumn() is not usable on the live MariaDB 10.1.
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

    protected function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
