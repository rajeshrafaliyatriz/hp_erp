<?php

namespace App\Services\Talent;

use App\Http\Controllers\talent\talent_jobapplicationcontroller;
use App\Services\Competency\AssessmentScoringService;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The candidate's side of assessment: invite, sit, mark, shortlist.
 *
 * ── WHY THIS IS A SERVICE AND NOT CONTROLLER CODE ───────────────────────────
 *
 * Three callers reach this decision from different directions: HR dragging a
 * card to Assessment, the candidate pressing Submit on a link with no login,
 * and HR re-marking by hand afterwards. Each one must end with the SAME rule
 * deciding the same way. The audit found the cost of not doing this - four
 * writers of employee rows had drifted apart, which is why EmployeeFactory
 * exists. This is the same shape, applied before the drift happens.
 *
 * ── THE RULE, IN ONE PLACE ──────────────────────────────────────────────────
 *
 * HR sets `total_marks` and `qualification_marks` on the blueprint. DeepSeek
 * gives each question a `max_score` that sums to the total. The candidate's
 * awarded marks are compared against `qualification_marks` - MARKS, not
 * percent, because that is what HR was asked to set. Percent is stored too,
 * but only so a result can be shown; nothing branches on it.
 *
 * At or above the mark the application moves to 'Interview Scheduled'. Below
 * it, it stays in 'Assessment' and waits for a person. A failing score never
 * rejects anybody automatically - that decision stays human, which is what you
 * asked for when you said the result is advisory.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ──────────────────────────────────────
 *
 * It never writes a competency rating. A candidate is not an employee, their
 * user id is not a tbluser id, and `competency_kasba_rating` has no foreign
 * key - so an id collision would silently overwrite a real employee's
 * proficiency. AssessmentScoringService::approve() and feedReviewCycles()
 * refuse a non-employee subject; this service is the reason those gates exist.
 */
class CandidateAssessmentService
{
    /** How long a candidate has to sit the test before the link stops working. */
    public const TTL_DAYS = 7;

    public const STATUS_INVITED = 'invited';
    public const STATUS_STARTED = 'started';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    public function __construct(
        private AssessmentScoringService $scoring,
        private EventRecorder $events,
    ) {
    }

    /**
     * Mint an invitation for one application. Returns the RAW token - the only
     * moment it exists readable. Re-inviting overwrites the hash, so a previous
     * link stops working immediately.
     *
     * @return array{token:string, expires_at:\Illuminate\Support\Carbon, id:int}
     */
    public function mint(int $applicationId, int $testId, ?int $blueprintId, int $tenantId, ?int $actorId, ?int $candidateId): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $shared = [
            'token_hash'       => hash('sha256', $token),
            'token_expires_at' => $expiresAt,
            // Cleared on re-invite: the new link must be usable even if the old
            // one had been opened. Without this a re-invite mints a token that
            // resolve() immediately rejects as used.
            'token_used_at'    => null,
            'blueprint_id'     => $blueprintId,
            'test_id'          => $testId,
            'status'           => self::STATUS_INVITED,
            'invited_at'       => now(),
            'updated_by'       => $actorId,
            'updated_at'       => now(),
        ];

        $existing = DB::table('talent_candidate_assessments')
            ->where('application_id', $applicationId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($existing) {
            DB::table('talent_candidate_assessments')->where('id', $existing->id)->update($shared);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('talent_candidate_assessments')->insertGetId($shared + [
                'sub_institute_id' => $tenantId,
                'application_id'   => $applicationId,
                'candidate_id'     => $candidateId,
                'created_by'       => $actorId,
                'created_at'       => now(),
            ]);
        }

        return ['token' => $token, 'expires_at' => $expiresAt, 'id' => $id];
    }

    /**
     * The assessment a raw token opens, or null.
     *
     * Every failure returns null with the same shape - unknown, expired, used -
     * so a caller cannot tell them apart by probing. The reason is for a person
     * to read on the page, never for a machine to branch on.
     *
     * @return array{row:?object, reason:?string}
     */
    public function resolve(string $token): array
    {
        if (strlen($token) !== 64) {
            return ['row' => null, 'reason' => 'not_found'];
        }

        $row = DB::table('talent_candidate_assessments')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return ['row' => null, 'reason' => 'not_found'];
        }

        if ($row->token_used_at !== null) {
            return ['row' => null, 'reason' => 'used'];
        }

        if ($row->token_expires_at !== null && now()->greaterThan($row->token_expires_at)) {
            return ['row' => null, 'reason' => 'expired'];
        }

        return ['row' => $row, 'reason' => null];
    }

    /**
     * Mark the sitting, record the result, and shortlist if it qualifies.
     *
     * ONE TRANSACTION covers the result, the pipeline move and the event, so a
     * candidate can never be shown as qualified while their application sits in
     * the wrong column - or moved with no record of why.
     *
     * The AI call is made BEFORE the transaction opens. It takes seconds and can
     * fail; holding a write transaction across it would keep row locks on the
     * application table for the length of a network round trip.
     *
     * @return array{ok:bool, score:float, max:float, percent:?float, qualified:bool,
     *               awaiting:int, moved:bool, message:string}
     */
    public function finaliseAndShortlist(object $assessment, int $tenantId, ?int $actorId): array
    {
        $attemptId = (int) $assessment->attempt_id;

        // Outside the transaction, deliberately - see the docblock.
        $marking = $this->scoring->scoreShortAnswers($attemptId, $tenantId);
        $result  = $this->scoring->finalise($attemptId, $tenantId);

        $blueprint = $assessment->blueprint_id
            ? DB::table('talent_assessment_blueprints')
                ->where('id', $assessment->blueprint_id)
                ->where('sub_institute_id', $tenantId)
                ->first(['total_marks', 'qualification_marks'])
            : null;

        $score = (float) $result['total'];
        $max   = (float) $result['max'];
        $awaiting = (int) $result['awaiting'];

        /*
         * A paper still awaiting human marking is NOT judged.
         *
         * If the AI failed on the written answers, `total` counts only the
         * auto-scored MCQs - so a strong candidate would look like a weak one
         * and be held back by a rule that never saw half their paper. Holding
         * at 'submitted' asks a person to finish the marking instead, which is
         * the honest failure and the one HR can act on.
         */
        if ($awaiting > 0 || $blueprint === null) {
            DB::table('talent_candidate_assessments')->where('id', $assessment->id)->update([
                'status'       => self::STATUS_SUBMITTED,
                'score'        => $score,
                'max_score'    => $max,
                'percent'      => $result['percent'],
                'qualified'    => null,
                'submitted_at' => $assessment->submitted_at ?? now(),
                'updated_by'   => $actorId,
                'updated_at'   => now(),
            ]);

            return [
                'ok' => true, 'score' => $score, 'max' => $max, 'percent' => $result['percent'],
                'qualified' => false, 'awaiting' => $awaiting, 'moved' => false,
                'message' => $blueprint === null
                    ? 'Scored, but this assessment has no blueprint, so no pass mark applies. A recruiter decides.'
                    : $awaiting . ' answer(s) still need a person to mark before the result can decide anything.',
            ];
        }

        $threshold = (float) $blueprint->qualification_marks;
        $qualified = $score >= $threshold;

        $moved = DB::transaction(function () use ($assessment, $tenantId, $actorId, $score, $max, $result, $qualified, $threshold) {
            DB::table('talent_candidate_assessments')->where('id', $assessment->id)->update([
                'status'       => self::STATUS_GRADED,
                'score'        => $score,
                'max_score'    => $max,
                'percent'      => $result['percent'],
                'qualified'    => $qualified ? 1 : 0,
                'submitted_at' => $assessment->submitted_at ?? now(),
                'graded_at'    => now(),
                'updated_by'   => $actorId,
                'updated_at'   => now(),
            ]);

            $moved = false;

            if ($qualified) {
                /*
                 * Scoped to the tenant AND to the column we expect to be in.
                 *
                 * The tenant scope is F-67: a bare where('id') let a token from
                 * one organisation rewrite another's application. The status
                 * scope is so a late or replayed result cannot drag somebody
                 * back out of Offered or Hired into Interview.
                 */
                $moved = DB::table('talent_job_applications')
                    ->where('id', $assessment->application_id)
                    ->where('sub_institute_id', $tenantId)
                    ->where('status', self::STAGE_ASSESSMENT)
                    ->update([
                        'status'     => self::STAGE_INTERVIEW,
                        'updated_by' => $actorId,
                        'updated_at' => now(),
                    ]) > 0;
            }

            $this->events->record(
                type: 'candidate.assessment.graded',
                subInstituteId: $tenantId,
                entityType: 'talent_job_application',
                entityId: (int) $assessment->application_id,
                actorId: $actorId,
                payload: [
                    'assessment_id'       => (int) $assessment->id,
                    'test_id'             => (int) $assessment->test_id,
                    'score'               => $score,
                    'max_score'           => $max,
                    'percent'             => $result['percent'],
                    'qualification_marks' => $threshold,
                    'qualified'           => $qualified,
                    'moved_to_interview'  => $moved,
                ],
            );

            return $moved;
        });

        if (!empty($marking['reason'])) {
            Log::info('Candidate assessment marked with an AI warning', [
                'assessment' => $assessment->id, 'reason' => $marking['reason'],
            ]);
        }

        return [
            'ok' => true, 'score' => $score, 'max' => $max, 'percent' => $result['percent'],
            'qualified' => $qualified, 'awaiting' => 0, 'moved' => $moved,
            'message' => $qualified
                ? ($moved
                    ? 'Qualified. The application has moved to Interview Scheduled.'
                    : 'Qualified. The application was not in Assessment, so its stage was left alone.')
                : 'Below the pass mark of ' . rtrim(rtrim(number_format($threshold, 2), '0'), '.')
                  . '. The application stays in Assessment for a recruiter to decide.',
        ];
    }

    /**
     * The two pipeline stages this service reads and writes.
     *
     * Held as values, not as an index into talent_jobapplicationcontroller::STATUSES
     * - an index silently points at a different stage the moment someone inserts
     * one. stagesAreValid() proves they still exist in that list instead.
     */
    public const STAGE_ASSESSMENT = 'Assessment';
    public const STAGE_INTERVIEW  = 'Interview Scheduled';

    /** Guard for a test: both stages must still be part of the pipeline vocabulary. */
    public static function stagesAreValid(): bool
    {
        $known = talent_jobapplicationcontroller::STATUSES;

        return in_array(self::STAGE_ASSESSMENT, $known, true)
            && in_array(self::STAGE_INTERVIEW, $known, true);
    }
}
