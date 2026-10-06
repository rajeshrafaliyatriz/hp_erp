<?php

namespace App\Domain\AI\Recommendations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and decides on `hpbrain_recommendations` — G2G's own recommendations.
 *
 * WHY IT READS AN EXISTING TABLE RATHER THAN A NEW ONE
 *
 * G2G already holds 37 recommendations with priority, confidence, impact, cost, risk
 * and urgency, each linked to the reasoning step that produced it and, through
 * `hpbrain_recommendation_evidence`, to the evidence rows behind that. That is a
 * complete explanation chain and it is real data. Creating an `ai_recommendations`
 * table beside it and seeding it would have produced a second, emptier answer to the
 * same question — which is precisely the duplication the AI & Intelligence console
 * exists to remove.
 *
 * So the write half is added and the read half is adopted. Nothing is copied out.
 *
 * ── THE EXPLANATION IS THE FEATURE ─────────────────────────────────────────
 *
 * "A recommendation is only trusted if it can be explained, and the explanation is
 * the evidence behind it" is the capability's own stated purpose. `explain()` walks
 * recommendation → reasoning step → evidence and returns the chain, because a
 * recommendation shown without it is an instruction from nowhere, and an
 * administrator asked to approve one has nothing to weigh.
 *
 * ── TENANT SCOPE ON A VARCHAR ──────────────────────────────────────────────
 *
 * These tables scope on `tenant_id`, a varchar that usually holds the
 * `sub_institute_id` as a string and sometimes `*` for platform-wide. Every query
 * here goes through `scoped()`, which handles both. Ids are UUIDs, so ordering is by
 * date — `ORDER BY id` on a uuid is alphabetical, and would present an arbitrary
 * slice as the newest.
 */
final class RecommendationRepository
{
    private const TABLE = 'hpbrain_recommendations';

    /**
     * Statuses this repository will set, and what each means.
     *
     * The table's existing values are 'open', 'pending' and 'accepted'. Those are
     * kept and extended rather than replaced: rewriting 37 live rows into a new
     * vocabulary to make a screen tidier would destroy the only record of what was
     * already decided.
     */
    public const DECISIONS = [
        'approve' => 'accepted',
        'reject' => 'rejected',
        'defer' => 'deferred',
    ];

    /** Statuses that still need somebody to decide. */
    public const PENDING_STATUSES = ['open', 'pending'];

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Recommendations awaiting a decision, most confident first.
     *
     * Ordered by confidence rather than by date on purpose: this is a work queue, and
     * the question it answers is "what should I look at next", not "what arrived
     * last". A low-confidence recommendation at the top of a queue trains people to
     * ignore the queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int|string $institute, int $limit = 50): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->scoped($institute)
            ->whereIn(DB::raw('LOWER(TRIM(status))'), self::PENDING_STATUSES)
            ->orderByDesc('confidence')
            ->orderByDesc('created_date')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->present($row))->all();
    }

    /**
     * Every recommendation this organisation has, whatever its status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int|string $institute, ?string $status = null, int $limit = 100): array
    {
        if (! $this->available()) {
            return [];
        }

        $query = $this->scoped($institute);

        if ($status !== null && $status !== '') {
            $query->whereRaw('LOWER(TRIM(status)) = ?', [strtolower(trim($status))]);
        }

        $rows = $query->orderByDesc('created_date')->limit($limit)->get();

        return $rows->map(fn ($row) => $this->present($row))->all();
    }

    /** One recommendation, or null when it is not this organisation's. */
    public function find(string $id, int|string $institute): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->scoped($institute)->where('id', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * The chain behind one recommendation: the reasoning step, then its evidence.
     *
     * Both halves are optional in the data and the return says which are present.
     * A recommendation with no reasoning step is not an error — some were written
     * directly — but an administrator should be able to see that it has none rather
     * than be shown an empty panel and left to guess whether the lookup failed.
     *
     * @return array<string, mixed>
     */
    public function explain(string $id, int|string $institute): array
    {
        $recommendation = $this->find($id, $institute);

        if ($recommendation === null) {
            return ['recommendation' => null, 'reasoning' => null, 'evidence' => []];
        }

        return [
            'recommendation' => $recommendation,
            'reasoning' => $this->reasoningStep($recommendation['reasoning_step_id'], $institute),
            'evidence' => $this->evidence($id, $institute),
        ];
    }

    /**
     * Record a decision.
     *
     * Only a pending recommendation can be decided. Re-deciding one that is already
     * accepted would overwrite a decision somebody made with no record that the first
     * one existed — `hpbrain_recommendations` has no decision history of its own, so
     * the guard is the only thing protecting it.
     *
     * @return array{ok: bool, message: string, status?: string}
     */
    public function decide(string $id, int|string $institute, string $decision): array
    {
        if (! array_key_exists($decision, self::DECISIONS)) {
            return ['ok' => false, 'message' => "\"{$decision}\" is not a decision."];
        }

        $row = $this->scoped($institute)->where('id', $id)->first();

        if ($row === null) {
            return ['ok' => false, 'message' => 'That recommendation was not found.'];
        }

        $current = strtolower(trim((string) $row->status));

        if (! in_array($current, self::PENDING_STATUSES, true)) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'This recommendation is already %s. Only an open recommendation can be decided.',
                    $current
                ),
            ];
        }

        $next = self::DECISIONS[$decision];

        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => $next,
            'updated_date' => now(),
        ]);

        return ['ok' => true, 'message' => 'Decision recorded.', 'status' => $next];
    }

    /** Counts per status, for the screen's summary line. */
    public function counts(int|string $institute): array
    {
        if (! $this->available()) {
            return ['total' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0, 'deferred' => 0];
        }

        $rows = $this->scoped($institute)
            ->selectRaw('LOWER(TRIM(status)) s, count(*) c')
            ->groupBy('s')
            ->pluck('c', 's');

        return [
            'total' => (int) $rows->sum(),
            'pending' => (int) $rows->get('open', 0) + (int) $rows->get('pending', 0),
            'accepted' => (int) $rows->get('accepted', 0),
            'rejected' => (int) $rows->get('rejected', 0),
            'deferred' => (int) $rows->get('deferred', 0),
        ];
    }

    /**
     * The reasoning step that produced a recommendation.
     *
     * @return array<string, mixed>|null
     */
    private function reasoningStep(?string $stepId, int|string $institute): ?array
    {
        if ($stepId === null || $stepId === '' || ! Schema::hasTable('hpbrain_reasoning_steps')) {
            return null;
        }

        $row = DB::table('hpbrain_reasoning_steps')
            ->where('id', $stepId)
            ->where(fn ($q) => $q->where('tenant_id', (string) $institute)->orWhere('tenant_id', '*'))
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'description' => (string) ($row->description ?? ''),
            'step_order' => $row->step_order === null ? null : (int) $row->step_order,
            'confidence' => $row->confidence_score === null ? null : (float) $row->confidence_score,
            'case_id' => $row->case_id === null ? null : (string) $row->case_id,
            'signal_id' => $row->signal_id === null ? null : (string) $row->signal_id,
            'created_date' => $row->created_date === null ? null : (string) $row->created_date,
        ];
    }

    /**
     * The evidence rows linked to a recommendation.
     *
     * `content` is truncated. An evidence row's content is a payload of arbitrary
     * size and this is a list view; sending the whole of 25,943 rows' worth of
     * content because one of them might be interesting is not a trade worth making.
     *
     * @return array<int, array<string, mixed>>
     */
    private function evidence(string $recommendationId, int|string $institute): array
    {
        if (! Schema::hasTable('hpbrain_recommendation_evidence') || ! Schema::hasTable('hpbrain_evidence')) {
            return [];
        }

        return DB::table('hpbrain_recommendation_evidence as link')
            ->join('hpbrain_evidence as e', 'e.id', '=', 'link.evidence_id')
            ->where('link.recommendation_id', $recommendationId)
            ->where(fn ($q) => $q->where('link.tenant_id', (string) $institute)->orWhere('link.tenant_id', '*'))
            ->orderByDesc('e.observed_date')
            ->limit(25)
            ->get([
                'e.id', 'e.evidence_type', 'e.source', 'e.content',
                'e.confidence', 'e.status', 'e.observed_date',
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'evidence_type' => (string) ($row->evidence_type ?? ''),
                'source' => (string) ($row->source ?? ''),
                'content' => mb_substr((string) ($row->content ?? ''), 0, 600),
                'confidence' => $row->confidence === null ? null : (float) $row->confidence,
                'status' => (string) ($row->status ?? ''),
                'observed_date' => $row->observed_date === null ? null : (string) $row->observed_date,
            ])
            ->all();
    }

    /**
     * One row as the API returns it.
     *
     * `impact`, `cost` and `risk` are `text` in this table and hold sentences rather
     * than the short enums the names suggest, so they are passed through as prose.
     * Coercing them into labels would truncate the only explanation some rows carry.
     *
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $status = strtolower(trim((string) ($row->status ?? '')));

        return [
            'id' => (string) $row->id,
            'title' => (string) ($row->title ?? ''),
            'description' => (string) ($row->description ?? ''),
            'category' => (string) ($row->category ?? ''),
            'priority' => (string) ($row->priority ?? ''),
            'urgency' => (string) ($row->urgency ?? ''),
            'confidence' => $row->confidence === null ? null : (float) $row->confidence,
            'expected_roi' => $row->expected_roi === null ? null : (float) $row->expected_roi,
            'impact' => (string) ($row->impact ?? ''),
            'cost' => (string) ($row->cost ?? ''),
            'risk' => (string) ($row->risk ?? ''),
            'status' => $status,
            // Stated rather than inferred by the screen, so the button logic and the
            // API's own guard cannot disagree about what is decidable.
            'is_pending' => in_array($status, self::PENDING_STATUSES, true),
            'reasoning_step_id' => $row->reasoning_step_id === null ? null : (string) $row->reasoning_step_id,
            'created_date' => $row->created_date === null ? null : (string) $row->created_date,
            'updated_date' => $row->updated_date === null ? null : (string) $row->updated_date,
        ];
    }

    /**
     * The one place the tenant filter is written.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function scoped(int|string $institute)
    {
        return DB::table(self::TABLE)->where(function ($inner) use ($institute) {
            $inner->where('tenant_id', (string) $institute)->orWhere('tenant_id', '*');
        });
    }
}
