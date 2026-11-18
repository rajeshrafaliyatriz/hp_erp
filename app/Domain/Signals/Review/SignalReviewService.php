<?php

namespace App\Domain\Signals\Review;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every decision on a signal goes through here: the Signal Review screen (strict), the original screen's buttons (lenient) and "promote to CRM".
 *
 * One decision is ONE transaction that writes the history row (g2g_signal_reviews, append-only: a later decision never edits an earlier row), the
 * signal's own latest-decision columns (review_status, reviewed_by, reviewed_at, and review_notes only when a person gave a note) and the event
 * (SignalAudit). If any of the three cannot be written, none is. The signal row is locked for the length of the transaction, so two people
 * deciding at once are decided in turn and the second sees the first.
 *
 * Strict (decide): reason_code is required to dismiss and must be on the list; "other" needs a note; a decision that changes nothing is refused
 * (409 unchanged); `expected_status` that is no longer true is refused (409 conflict).
 * Lenient (decideLegacy): the original screen sends no reason, so a bare dismissal is stored with the configured fallback reason and note, and a
 * decision that changes nothing is simply not recorded (the old screen never errors on a double click).
 *
 * The code can reach a server before the history table does (migrations are run by hand, host by host). Until then the original screen's buttons
 * work exactly as they always did (only the signal's own columns change), promoting records no history, the history is empty, and the strict
 * decision is refused with 503 rather than accepted and forgotten.
 */
final class SignalReviewService
{
    public const VIA_SIGNAL_REVIEW = 'signal_review';

    public const VIA_LEGACY_SCREEN = 'legacy_screen';

    public const VIA_PROMOTE = 'promote';

    /** @var array<string, string> the decisions a person can make => the review_status each one stores */
    public const STATUS_OF = ['reviewed' => 'Reviewed', 'dismissed' => 'Dismissed', 'follow_up' => 'Follow-up', 'reopened' => 'New'];

    /** What the screens call each stored status. */
    private const STATUS_LABEL = ['New' => 'New', 'Reviewed' => 'Reviewed', 'Follow-up' => 'Contacted', 'Dismissed' => 'Dismissed'];

    /**
     * The Signal Review screen: validate strictly, then record the decision.
     *
     * @param  array<string, mixed>  $input  decision, reason_code, note, expected_status
     * @return array<string, mixed> the history entry just written
     *
     * @throws SignalReviewRefused
     */
    public function decide(int $tenantId, int $opportunityId, ?int $userId, array $input): array
    {
        if (! $this->hasHistory()) {
            throw SignalReviewRefused::unavailable();
        }

        return $this->apply($tenantId, $opportunityId, $userId, $input, self::VIA_SIGNAL_REVIEW, false);
    }

    /**
     * The original screen's buttons. `reason_code` and `notes` are optional there but, when sent, are held to the same rules.
     *
     * @return array<string, mixed>|null the history entry just written, or null when the decision would change nothing and nothing was written
     *
     * @throws SignalReviewRefused
     */
    public function decideLegacy(int $tenantId, int $opportunityId, ?int $userId, string $decision, mixed $reasonCode, mixed $notes): ?array
    {
        $input = ['decision' => $decision, 'reason_code' => $reasonCode, 'note' => $notes];
        if (! $this->hasHistory()) {
            $this->applyWithoutHistory($tenantId, $opportunityId, $userId, $input);

            return null;
        }

        try {
            return $this->apply($tenantId, $opportunityId, $userId, $input, self::VIA_LEGACY_SCREEN, true);
        } catch (SignalReviewRefused $e) {
            if ($e->reason === SignalReviewRefused::UNCHANGED) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A lead was just made from this signal: keep that in its history. Call it inside the transaction that makes the lead, so both stand or neither does.
     * The signal's own status is not a decision here, so it stays as it was.
     *
     * @return array<string, mixed>|null the history entry just written, or null when this server has no history table yet
     *
     * @throws SignalReviewRefused
     */
    public function recordPromotion(int $tenantId, int $opportunityId, ?int $userId, int $leadId): ?array
    {
        if (! $this->hasHistory()) {
            return null;
        }

        return DB::transaction(function () use ($tenantId, $opportunityId, $userId, $leadId) {
            $status = $this->statusOf($this->lockedSignal($tenantId, $opportunityId));
            $reviewId = $this->insertHistory($tenantId, $opportunityId, 'promoted', null, "Lead #{$leadId} created", $status, $status, self::VIA_PROMOTE, $userId, now());
            $this->audit($tenantId, $opportunityId, $userId, $reviewId, 'promoted', null, $status, $status);

            return $this->entry($tenantId, $reviewId);
        });
    }

    /**
     * Every decision made on a signal, newest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SignalReviewRefused when the signal is not this organisation's
     */
    public function history(int $tenantId, int $opportunityId): array
    {
        $this->requireSignal($tenantId, $opportunityId);
        if (! $this->hasHistory()) {
            return [];
        }

        $rows = DB::table('g2g_signal_reviews')->where('sub_institute_id', $tenantId)->where('opportunity_id', $opportunityId)
            ->orderByDesc('decided_at')->orderByDesc('id')->get();
        $names = $this->names($tenantId, $rows->pluck('decided_by')->all());

        return $rows->map(fn ($row) => $this->present($row, $names))->values()->all();
    }

    /** @return array<string, mixed>|null the newest history entry, or null when the signal has none */
    public function latest(int $tenantId, int $opportunityId): ?array
    {
        $row = DB::table('g2g_signal_reviews')->where('sub_institute_id', $tenantId)->where('opportunity_id', $opportunityId)
            ->orderByDesc('decided_at')->orderByDesc('id')->first();

        return $row === null ? null : $this->present($row, $this->names($tenantId, [$row->decided_by]));
    }

    /** @return array<string, mixed> */
    private function apply(int $tenantId, int $opportunityId, ?int $userId, array $input, string $via, bool $lenient): array
    {
        $this->requireSignal($tenantId, $opportunityId);
        $in = $this->validated($input, $lenient);

        return DB::transaction(function () use ($tenantId, $opportunityId, $userId, $in, $via) {
            $signal = $this->lockedSignal($tenantId, $opportunityId);
            $from = $this->statusOf($signal);
            $to = self::STATUS_OF[$in['decision']];

            if ($in['expected'] !== null && $in['expected'] !== $from) {
                throw SignalReviewRefused::conflict(
                    'Someone has decided on this signal since you opened it: it is now ' . self::STATUS_LABEL[$from] . '.',
                    $this->latest($tenantId, $opportunityId)
                );
            }
            // Nothing changes when the status stays and there is no note to record; reopening a signal nobody has decided on is never a change.
            if ($from === $to && ($in['decision'] === 'reopened' || $in['note'] === null)) {
                throw SignalReviewRefused::unchanged(
                    $in['decision'] === 'reopened'
                        ? 'This signal has not been decided on, so there is nothing to reopen.'
                        : 'This signal is already ' . self::STATUS_LABEL[$from] . '. Add a note to record something new.',
                    $this->latest($tenantId, $opportunityId)
                );
            }

            $now = now();
            $reviewId = $this->insertHistory($tenantId, $opportunityId, $in['decision'], $in['reason'], $in['note'] ?? $in['fallbackNote'], $from, $to, $via, $userId, $now);

            $signal->forceFill($this->latestColumns($to, $userId, $now, $in['note']))->save();

            $this->audit($tenantId, $opportunityId, $userId, $reviewId, $in['decision'], $in['reason'], $from, $to);

            return $this->entry($tenantId, $reviewId);
        });
    }

    /**
     * The original screen's buttons before the history table exists: validate the same way, then change only the signal's own columns, as they
     * always did (and do nothing when the status stays and there is no note).
     *
     * @param  array<string, mixed>  $input
     */
    private function applyWithoutHistory(int $tenantId, int $opportunityId, ?int $userId, array $input): void
    {
        $this->requireSignal($tenantId, $opportunityId);
        $in = $this->validated($input, true);

        DB::transaction(function () use ($tenantId, $opportunityId, $userId, $in) {
            $signal = $this->lockedSignal($tenantId, $opportunityId);
            $to = self::STATUS_OF[$in['decision']];
            if ($this->statusOf($signal) !== $to || $in['note'] !== null) {
                $signal->forceFill($this->latestColumns($to, $userId, now(), $in['note']))->save();
            }
        });
    }

    /**
     * The signal keeps showing the latest decision, so the original screens need no change. review_notes is a person's text: only a note a person
     * gave replaces it, never the fallback note a bare dismissal on the original screen is given in the history.
     *
     * @return array<string, mixed>
     */
    private function latestColumns(string $status, ?int $userId, \DateTimeInterface $at, ?string $note): array
    {
        $columns = ['review_status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => $at];
        if ($note !== null) {
            $columns['review_notes'] = $note;
        }

        return $columns;
    }

    private function hasHistory(): bool
    {
        return Schema::hasTable('g2g_signal_reviews');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{decision: string, reason: ?string, note: ?string, fallbackNote: ?string, expected: ?string}
     *
     * @throws SignalReviewRefused
     */
    private function validated(array $input, bool $lenient): array
    {
        $errors = [];
        $noteField = $lenient ? 'notes' : 'note';   // errors are keyed by the field the caller actually sent

        $decision = $input['decision'] ?? null;
        if (! is_string($decision) || ! isset(self::STATUS_OF[$decision])) {
            $errors['decision'][] = 'Choose a decision: ' . implode(', ', array_keys(self::STATUS_OF)) . '.';
        }

        $note = $input['note'] ?? null;
        if ($note !== null && ! is_string($note)) {
            $errors[$noteField][] = 'The note must be text.';
            $note = null;
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);
        $max = (int) config('signals.review.note_max', 1000);
        if ($note !== null && mb_strlen($note) > $max) {
            $errors[$noteField][] = "The note can be at most {$max} characters.";
        }

        // A reason belongs to a dismissal only; sent with any other decision it is ignored, not stored.
        $reason = null;
        $fallbackNote = null;
        if ($decision === 'dismissed') {
            $sent = $input['reason_code'] ?? null;
            if ($sent !== null && ! is_string($sent)) {
                $errors['reason_code'][] = 'Choose a reason from the list.';
            } elseif ($sent === null || trim($sent) === '') {
                if ($lenient) {
                    $reason = (string) config('signals.review.legacy_dismiss_reason');
                    $fallbackNote = (string) config('signals.review.legacy_note');
                } else {
                    $errors['reason_code'][] = 'Choose why this signal is being dismissed.';
                }
            } elseif (! array_key_exists(trim($sent), (array) config('signals.review.reasons', []))) {
                $errors['reason_code'][] = 'That reason is not on the list.';
            } else {
                $reason = trim($sent);
                if ($reason === 'other' && $note === null) {
                    $errors[$noteField][] = 'Add a note: it is required when the reason is Other.';
                }
            }
        }

        $expected = $input['expected_status'] ?? null;
        if ($expected !== null && ! is_string($expected)) {
            $errors['expected_status'][] = 'The expected status must be text.';
            $expected = null;
        }

        if ($errors !== []) {
            throw SignalReviewRefused::invalid($errors);
        }

        return ['decision' => $decision, 'reason' => $reason, 'note' => $note, 'fallbackNote' => $fallbackNote, 'expected' => $expected === '' ? null : $expected];
    }

    private function requireSignal(int $tenantId, int $opportunityId): void
    {
        if (! CompanyOpportunity::where('sub_institute_id', $tenantId)->whereKey($opportunityId)->exists()) {
            throw SignalReviewRefused::notFound();
        }
    }

    private function lockedSignal(int $tenantId, int $opportunityId): CompanyOpportunity
    {
        return CompanyOpportunity::where('sub_institute_id', $tenantId)->whereKey($opportunityId)->lockForUpdate()->first()
            ?? throw SignalReviewRefused::notFound();
    }

    private function statusOf(CompanyOpportunity $signal): string
    {
        return (string) ($signal->review_status ?: 'New');
    }

    private function insertHistory(int $tenantId, int $opportunityId, string $decision, ?string $reason, ?string $note, string $from, string $to, string $via, ?int $userId, \DateTimeInterface $at): int
    {
        $stamp = $at->format('Y-m-d H:i:s');

        return (int) DB::table('g2g_signal_reviews')->insertGetId([
            'sub_institute_id' => $tenantId, 'opportunity_id' => $opportunityId, 'decision' => $decision, 'reason_code' => $reason, 'note' => $note,
            'from_status' => $from, 'to_status' => $to, 'via' => $via, 'decided_by' => $userId, 'decided_at' => $stamp, 'created_at' => $stamp,
        ]);
    }

    private function audit(int $tenantId, int $opportunityId, ?int $userId, int $reviewId, string $decision, ?string $reason, string $from, string $to): void
    {
        SignalAudit::record(SignalAudit::TYPES[$decision], $tenantId, $opportunityId, $userId, [
            'opportunity_id' => $opportunityId, 'review_id' => $reviewId, 'decision' => $decision,
            'reason_code' => $reason, 'from_status' => $from, 'to_status' => $to,
        ], $reviewId);
    }

    /** @return array<string, mixed> */
    private function entry(int $tenantId, int $reviewId): array
    {
        $row = DB::table('g2g_signal_reviews')->where('sub_institute_id', $tenantId)->where('id', $reviewId)->first();

        return $this->present($row, $this->names($tenantId, [$row->decided_by]));
    }

    /**
     * Who decided, by name. Looked up in the organisation's own users only, so an id that belongs to another organisation (or to nobody) gives no
     * name. A user who has since been switched off is still named: history says who did it, whatever they are now.
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, string>
     */
    private function names(int $tenantId, array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($userIds, fn ($id) => $id !== null))));
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $tenantId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $user) {
            $name = trim($user->first_name . ' ' . $user->last_name);
            if ($name !== '') {
                $names[(int) $user->id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function present(object $row, array $names): array
    {
        $by = $row->decided_by === null ? null : (int) $row->decided_by;

        return [
            'id' => (int) $row->id,
            'decision' => $row->decision,
            'reason_code' => $row->reason_code,
            // The code is kept even when the list no longer has it; only the label goes.
            'reason_label' => $row->reason_code === null ? null : ((array) config('signals.review.reasons', []))[$row->reason_code] ?? null,
            'note' => $row->note,
            'from_status' => $row->from_status,
            'to_status' => $row->to_status,
            'via' => $row->via,
            'decided_by' => $by,
            'decided_by_name' => $by === null ? null : ($names[$by] ?? null),
            'decided_at' => Carbon::parse($row->decided_at)->toIso8601String(),
        ];
    }
}
