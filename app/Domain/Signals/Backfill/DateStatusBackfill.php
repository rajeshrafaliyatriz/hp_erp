<?php

namespace App\Domain\Signals\Backfill;

use App\Domain\Signals\Opportunities\DateStatus;
use App\Support\ColumnExists;
use Illuminate\Database\Connection;

/**
 * Fills `g2g_company_opportunities.date_status` from the signal's own date columns through DateStatus::classify (the one rule the feed uses too).
 *
 *  - Only an EMPTY (NULL) date_status is filled. A value already there is never touched, even when the columns now say something else: that
 *    is counted and listed instead.
 *  - Sample signals (`is_sample`) are left out entirely: their dates are made up.
 *  - The JSON note `assessment.evidence.date_status` exists on only some rows. It is never the source (it is decoded here, in PHP: the live
 *    MariaDB 10.1 has no JSON functions); it is only cross-checked, and every row where it disagrees with the columns is counted and listed.
 *  - `updated_at` is not touched: the reviews backfill uses it as a fallback date.
 */
final class DateStatusBackfill extends TenantBackfill
{
    private const CHUNK = 500;

    protected function process(Connection $db, int $tenant, bool $apply): array
    {
        foreach (['event_date', 'source_published_at'] as $column) {
            if (! ColumnExists::has(self::TABLE, $column)) {
                throw new BackfillBlocked(self::TABLE . ".{$column} does not exist on this connection, so no signal can be classified.");
            }
        }
        $hasStatus = ColumnExists::has(self::TABLE, 'date_status');
        if (! $hasStatus && $apply) {
            throw new BackfillBlocked(self::TABLE . '.date_status does not exist on this connection: run its migration first.');
        }
        $hasSample = ColumnExists::has(self::TABLE, 'is_sample');
        $hasAssessment = ColumnExists::has(self::TABLE, 'assessment');

        $report = [
            'rows' => 0,
            'samples_skipped' => 0,
            'classified' => array_fill_keys(DateStatus::ALL, 0),
            'fill' => array_fill_keys(DateStatus::ALL, 0),
            'written' => 0,
            'already_filled' => 0,
            'filled_differs' => ['count' => 0, 'ids' => []],
            'assessment' => ['checked' => 0, 'unreadable' => 0, 'disagree' => 0, 'examples' => []],
            'issues' => [],
        ];
        if (! $hasStatus) {
            $report['issues'][] = 'date_status does not exist on this connection yet, so every signal is shown as "would fill".';
        }
        if (! $hasAssessment) {
            $report['issues'][] = 'There is no assessment column on this connection, so the cross-check was skipped.';
        }

        $own = fn () => $db->table(self::TABLE)->where('sub_institute_id', $tenant);
        if ($hasSample) {
            $report['samples_skipped'] = $own()->whereNotNull('is_sample')->where('is_sample', '<>', 0)->count();
        }

        $query = $own();
        if ($hasSample) {
            $query->where(fn ($w) => $w->whereNull('is_sample')->orWhere('is_sample', 0));
        }
        $columns = array_merge(['id', 'event_date', 'source_published_at'], $hasStatus ? ['date_status'] : [], $hasAssessment ? ['assessment'] : []);

        $query->select($columns)->chunkById(self::CHUNK, function ($rows) use ($db, $tenant, $apply, $hasStatus, &$report) {
            foreach ($rows as $row) {
                $this->visit($db, $tenant, $apply, $hasStatus, $row, $report);
            }
        });

        return $report;
    }

    private function visit(Connection $db, int $tenant, bool $apply, bool $hasStatus, object $row, array &$report): void
    {
        $status = DateStatus::status($row->event_date, $row->source_published_at);
        $report['rows']++;
        $report['classified'][$status]++;

        $stored = $hasStatus && $row->date_status !== null ? (string) $row->date_status : null;
        if ($stored === null) {
            $report['fill'][$status]++;
            if ($apply) {
                // The extra IS NULL makes "never overwrite" hold even if someone fills the row between the read and this write.
                $report['written'] += $db->table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $tenant)->whereNull('date_status')
                    ->update(['date_status' => $status]);
            }
        } else {
            $report['already_filled']++;
            if ($stored !== $status) {
                $report['filled_differs']['count']++;
                if (count($report['filled_differs']['ids']) < self::IDS_SHOWN) {
                    $report['filled_differs']['ids'][] = (int) $row->id;
                }
            }
        }

        if (property_exists($row, 'assessment')) {
            $this->crossCheck($row, $status, $report);
        }
    }

    /** Compares the status the stored JSON note carries (when it carries one) with the status the columns give. */
    private function crossCheck(object $row, string $status, array &$report): void
    {
        $raw = trim((string) $row->assessment);
        if ($raw === '') {
            return;
        }
        $assessment = json_decode($raw, true);
        if (! is_array($assessment)) {
            $report['assessment']['unreadable']++;

            return;
        }
        $noted = $assessment['evidence']['date_status'] ?? null;
        if (! is_string($noted) || $noted === '') {
            return;
        }

        $report['assessment']['checked']++;
        if ($noted !== $status) {
            $report['assessment']['disagree']++;
            if (count($report['assessment']['examples']) < self::IDS_SHOWN) {
                $report['assessment']['examples'][] = ['id' => (int) $row->id, 'assessment' => $noted, 'columns' => $status];
            }
        }
    }
}
