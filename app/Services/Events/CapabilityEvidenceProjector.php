<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;

/**
 * PROJECTOR — competency_evidence. (kind = P)
 *
 * Golden thread 2's evidence record. PURE: writes only its own projection,
 * touches nothing outside the database, never invokes a reactor. That is what
 * makes it replayable and what lets its delivery ledger be cleared on rebuild.
 *
 * ── THE EVIDENCE HALF ONLY. THIS IS THE POINT, NOT A LIMITATION. ────────────
 *
 * Q-B3: evidence written immediately on every failure; the manager flagged at a
 * threshold; PROFICIENCY CHANGED ONLY ON EXPLICIT CONFIRMATION. This class does
 * the first of those three and stops.
 *
 * It does not touch proficiency. It does not decide that four rejections mean
 * someone cannot do the job. It records that four rejections happened and leaves
 * the conclusion to a human, because a system that promotes observations into
 * judgements is manufacturing a claim nobody made. The confirmation half is its
 * own item with a UI surface (see the register); inventing a manager-confirmation
 * path nobody specified is precisely what Q-B3 exists to prevent.
 *
 * THE SCHEMA WAS ALREADY WAITING FOR THIS. `direction` is a NOT NULL enum of
 * positive/negative/neutral and `dismissed_reason`/`dismissed_by`/`dismissed_at`
 * are the confirmation path. The table was designed for Q-B3 and left empty
 * because nobody wrote the writer. Nothing here needed designing.
 *
 * ── TWO TABLES, ONE PREFIX APART, HOLDING DIFFERENT CONCEPTS ────────────────
 *
 * `competency_evidence`   THIS ONE. System-OBSERVED evidence, projected from the
 *                         event stream. kasba_type, item_id, source_type,
 *                         source_id, outcome, direction, dismissed_*.
 * `s_competency_evidence` NOT THIS ONE. User-UPLOADED artefacts: title, link,
 *                         file_path, evidence_type. 13 real uploads in it.
 *
 * They are not a duplicate pair and neither is a migration of the other. The
 * names differ by a prefix that elsewhere in this schema means "tenant-owned
 * canonical" (Q-C1), which it does NOT mean here. This is the G-DATA-11 shape
 * before it happens: a join between them would succeed and mean nothing.
 * DO NOT MERGE THEM, and do not write here expecting uploads.
 *
 * ── IDEMPOTENCY ─────────────────────────────────────────────────────────────
 *
 * `competency_evidence` has NO event_id column and no unique key, so re-projection
 * is keyed on the designed lookup path, `idx_ce_source` = (source_type, source_id),
 * with source_type = THE EVENT TYPE and source_id = THE EVENT ID. That is not a
 * workaround: the source of a projected row genuinely is the event that produced
 * it, and it makes the row traceable back to the stream by the index that already
 * exists. No schema change, and replay writes the same row rather than a second
 * one.
 */
class CapabilityEvidenceProjector
{
    public const CONSUMER = 'capability_evidence_projector';

    /**
     * What each event is evidence OF. `direction` is NOT NULL in the schema, so
     * every handled type must state one - there is no "unspecified" to fall back
     * on, and a default would be inventing a reading of the event.
     */
    private const MEANING = [
        'task.rejected'            => ['outcome' => 'task_rejected',     'direction' => 'negative'],
        'task.reopened'            => ['outcome' => 'task_reopened',     'direction' => 'negative'],
        /*
         * APPROVAL IS EVIDENCE TOO, AND ITS ABSENCE WAS THE ASYMMETRY.
         *
         * Every task outcome this class understood was a failure, so a capability
         * record built from it could only ever accumulate bad news — an employee
         * who completed a hundred tasks correctly and had one sent back would show
         * exactly one piece of evidence, and it would be against them.
         *
         * That is not what evidence is for. Doing a task to an approver's
         * satisfaction demonstrates the capability the task exercises, which is
         * the whole claim the ESO's Evidence section makes. It is recorded on
         * APPROVAL rather than on self-reported completion, so nobody credits
         * themselves by ticking their own box.
         */
        'task.approved'            => ['outcome' => 'task_approved',     'direction' => 'positive'],
        'capability.flag_resolved' => ['outcome' => 'flag_resolved',     'direction' => 'positive'],
        'certification.issued'     => ['outcome' => 'certificate_issued', 'direction' => 'positive'],
    ];

    public function handles(string $type): bool
    {
        return array_key_exists($type, self::MEANING);
    }

    /**
     * $target lets a dry run write into a shadow table, same contract as
     * TaskStatusProjector: threading the table through rather than writing live
     * and moving the row, which would touch the live table transiently.
     */
    public function project(object $event, ?string $target = null): void
    {
        $type = (string) $event->type;
        if (!$this->handles($type)) {
            return;
        }

        $target ??= 'competency_evidence';
        $payload = json_decode((string) $event->payload, true) ?: [];
        $after   = $payload['after'] ?? $payload;
        $meaning = self::MEANING[$type];

        // HELD, NOT GUESSED. kasba_type, item_id and competency_id are nullable
        // on purpose. When the event does not carry them the row still records
        // that the thing happened, to the right person, in the right tenant -
        // with the dimension left NULL rather than filled with a plausible value.
        // A null here means "the event did not say", which is a fact. A guess
        // would be a claim the event never made.
        $kasba = $after['kasba_type'] ?? $payload['kasba_type'] ?? null;
        $kasba = in_array($kasba, ['skill', 'knowledge', 'ability', 'attitude', 'behaviour'], true)
            ? $kasba
            : null;

        $userId = $after['user_id'] ?? $payload['user_id'] ?? $event->actor_id;

        DB::table($target)->updateOrInsert(
            // (source_type, source_id) = idx_ce_source. Replay overwrites this row
            // instead of appending a duplicate.
            ['source_type' => $type, 'source_id' => (int) $event->id],
            [
                'sub_institute_id' => (int) $event->sub_institute_id,
                'user_id'          => $userId !== null ? (int) $userId : null,
                'kasba_type'       => $kasba,
                'item_id'          => isset($after['item_id']) ? (int) $after['item_id'] : null,
                'competency_id'    => isset($after['competency_id']) ? (int) $after['competency_id'] : null,
                'outcome'          => $meaning['outcome'],
                'direction'        => $meaning['direction'],
                // recorded_by is the ACTOR ON THE EVENT, never the projector and
                // never a request value. If the event does not name an actor this
                // stays NULL - a projection cannot know who did something the
                // stream did not record.
                'recorded_by'      => $event->actor_id !== null ? (int) $event->actor_id : null,
                'note'             => null,
                'created_at'       => $event->occurred_at,
                'updated_at'       => now(),
            ]
        );

        // A shadow run leaves no trace in the delivery ledger either - otherwise
        // the dry run marks events delivered that the live projection never saw.
        if ($target === 'competency_evidence') {
            DB::table('g2g_event_delivery')->updateOrInsert(
                ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
                ['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'completed_at' => now()]
            );
        }
    }

    public function catchUp(int $limit = 500): int
    {
        $events = DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')->where('d.consumer', '=', self::CONSUMER);
            })
            ->whereNull('d.id')
            ->whereIn('e.type', array_keys(self::MEANING))
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.*']);

        foreach ($events as $event) {
            $this->project($event);
        }

        return $events->count();
    }
}
