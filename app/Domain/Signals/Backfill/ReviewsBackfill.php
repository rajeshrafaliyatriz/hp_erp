<?php

namespace App\Domain\Signals\Backfill;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every signal that was already decided before the history table existed one history row (`g2g_signal_reviews`), made from the four
 * review_* columns that until now held the only copy of the decision.
 *
 * What the old columns never held is left honestly empty: `reason_code` and `note` are NULL, `from_status` is New (the only status it can have
 * had before its first decision), and `via` is `backfill`, so the history never pretends a person gave a reason.
 *
 * `decided_at` is `reviewed_at`; when that is empty it falls back to the signal's `updated_at` (counted as `used_updated_at`, because that date
 * is when the signal last changed, not necessarily when it was decided). A signal with neither date is skipped and listed: no date is invented.
 *
 * Repeating it changes nothing. A row counts as already there when the same organisation, signal, decision and decided_at exist, whoever wrote
 * it. A row that fell back to `updated_at` is also already there when a backfill row for that signal and decision exists, because `updated_at`
 * moves every time the signal is touched and would otherwise create a second row on every run.
 */
final class ReviewsBackfill extends TenantBackfill
{
    private const HISTORY = 'g2g_signal_reviews';

    /** Stored review_status => history decision. Anything else that is not New is skipped and listed, never guessed. */
    private const DECISIONS = ['Reviewed' => 'reviewed', 'Dismissed' => 'dismissed', 'Follow-up' => 'follow_up'];

    public const VIA = 'backfill';

    protected function process(Connection $db, int $tenant, bool $apply): array
    {
        $hasHistory = Schema::connection($db->getName())->hasTable(self::HISTORY);
        if (! $hasHistory && $apply) {
            throw new BackfillBlocked(self::HISTORY . ' does not exist on this connection: run its migration first.');
        }

        $report = [
            'decided' => 0,
            'to_create' => 0,
            'already_there' => 0,
            'used_updated_at' => 0,
            'by_decision' => array_fill_keys(array_values(self::DECISIONS), 0),
            'unmapped' => ['count' => 0, 'ids' => []],
            'no_date' => ['count' => 0, 'ids' => []],
            'issues' => [],
        ];
        if (! $hasHistory) {
            $report['issues'][] = self::HISTORY . ' does not exist on this connection yet, so every signal is shown as "would create".';
        }

        $signals = $db->table(self::TABLE)->where('sub_institute_id', $tenant)
            ->whereNotNull('review_status')->where('review_status', '<>', 'New')->where('review_status', '<>', '')
            ->orderBy('id')->get(['id', 'review_status', 'reviewed_by', 'reviewed_at', 'updated_at']);

        foreach ($signals as $signal) {
            $report['decided']++;

            $decision = self::DECISIONS[(string) $signal->review_status] ?? null;
            if ($decision === null) {
                $this->note($report['unmapped'], (int) $signal->id);

                continue;
            }

            $decidedAt = self::stamp($signal->reviewed_at);
            $fallback = $decidedAt === null;
            $decidedAt ??= self::stamp($signal->updated_at);
            if ($decidedAt === null) {
                $this->note($report['no_date'], (int) $signal->id);

                continue;
            }
            if ($fallback) {
                $report['used_updated_at']++;
            }
            $report['by_decision'][$decision]++;

            if ($hasHistory && $this->exists($db, $tenant, (int) $signal->id, $decision, $decidedAt, $fallback)) {
                $report['already_there']++;

                continue;
            }

            $report['to_create']++;
            if ($apply) {
                $db->table(self::HISTORY)->insert([
                    'sub_institute_id' => $tenant,
                    'opportunity_id' => (int) $signal->id,
                    'decision' => $decision,
                    'reason_code' => null,
                    'note' => null,
                    'from_status' => 'New',
                    'to_status' => (string) $signal->review_status,
                    'via' => self::VIA,
                    'decided_by' => (int) $signal->reviewed_by > 0 ? (int) $signal->reviewed_by : null,
                    'decided_at' => $decidedAt,
                    'created_at' => now(),
                ]);
            }
        }

        return $report;
    }

    private function exists(Connection $db, int $tenant, int $signalId, string $decision, string $decidedAt, bool $fellBack): bool
    {
        $same = fn () => $db->table(self::HISTORY)->where('sub_institute_id', $tenant)->where('opportunity_id', $signalId)->where('decision', $decision);

        if ($same()->where('decided_at', $decidedAt)->exists()) {
            return true;
        }

        return $fellBack && $same()->where('via', self::VIA)->exists();
    }

    /** @param array{count:int, ids:list<int>} $bucket */
    private function note(array &$bucket, int $id): void
    {
        $bucket['count']++;
        if (count($bucket['ids']) < self::IDS_SHOWN) {
            $bucket['ids'][] = $id;
        }
    }

    /** A stored datetime as 'Y-m-d H:i:s', or null when it is empty, unreadable or a zero date (live is not strict and holds '0000-00-00 00:00:00'). */
    private static function stamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || str_starts_with($text, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse($text)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
