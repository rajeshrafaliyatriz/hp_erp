<?php

namespace App\Services\Talent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The link a candidate uses to follow their own application.
 *
 * Same shape as OfferLinkService and CandidateAssessmentService - Str::random(64),
 * only the sha256 stored, expiry checked, uniform failure - with one deliberate
 * difference: THIS LINK IS NOT CONSUMED ON USE.
 *
 * The other two open a decision taken once, so burning the token is correct.
 * This one opens a STATUS, which the candidate has every reason to re-read next
 * week. Marking it used would turn "check my application" into a one-shot and
 * push them back to phoning the recruiter, which is the thing it exists to stop.
 */
class ApplicationTrackingService
{
    /** Long enough to outlive a hiring process, short enough not to be forever. */
    public const TTL_DAYS = 90;

    /**
     * The stages a candidate is shown, in order.
     *
     * Deliberately COARSER than the internal pipeline. A candidate does not need
     * to know they are in "Pending Review" rather than "Screening", and telling
     * them invites questions the recruiter has to field. Anything unrecognised
     * maps to the first stage rather than vanishing from the timeline.
     */
    public const STAGES = ['Applied', 'Screening', 'Assessment', 'Interview', 'Offer'];

    /**
     * @var array<string, string> internal status -> candidate-facing stage
     *
     * Covers all nine of talent_jobapplicationcontroller::STATUSES exactly, so a
     * real status can never fall through to the default and show a candidate
     * "Applied" while a recruiter is looking at "Offered".
     */
    public const STAGE_MAP = [
        'Pending Review'      => 'Screening',
        'Under Review'        => 'Screening',
        'Shortlisted'         => 'Screening',
        'Assessment'          => 'Assessment',
        'Interview Scheduled' => 'Interview',
        'Offered'             => 'Offer',
        'Hired'               => 'Offer',
        'Completed'           => 'Offer',
        // 'Rejected' is deliberately absent: it is not a stage, it is an end.
        // timeline() handles it as closed rather than placing it on the track.
    ];

    /**
     * Mint (or re-issue) the link for one application.
     *
     * Re-issuing overwrites the stored hash, so a link forwarded to somebody
     * else stops working the moment a new one is sent.
     *
     * @return array{token:string, expires_at:\Illuminate\Support\Carbon}
     */
    public function mint(int $applicationId, int $tenantId, ?int $candidateId, ?int $actorId = null): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        DB::table('talent_application_tracking')->updateOrInsert(
            ['application_id' => $applicationId],
            [
                'sub_institute_id' => $tenantId,
                'candidate_id'     => $candidateId,
                'token_hash'       => hash('sha256', $token),
                'expires_at'       => $expiresAt,
                // A re-issued link starts a fresh history; the old counts belong
                // to a token that no longer opens anything.
                'last_viewed_at'   => null,
                'view_count'       => 0,
                'created_by'       => $actorId,
                'updated_at'       => now(),
                'created_at'       => now(),
            ]
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * The application a raw token opens, or null.
     *
     * Unknown, expired and deleted all return null with the same shape, so a
     * caller cannot tell them apart by probing. The reason is for a person to
     * read on the page, never for a machine to branch on.
     *
     * @return array{row:?object, reason:?string}
     */
    public function resolve(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return ['row' => null, 'reason' => 'invalid'];
        }

        $row = DB::table('talent_application_tracking')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return ['row' => null, 'reason' => 'unknown'];
        }

        if ($row->expires_at && now()->greaterThan($row->expires_at)) {
            return ['row' => null, 'reason' => 'expired'];
        }

        return ['row' => $row, 'reason' => null];
    }

    /** Reading is not a decision, so it counts rather than consumes. */
    public function noteView(int $id): void
    {
        DB::table('talent_application_tracking')->where('id', $id)->update([
            'last_viewed_at' => now(),
            'view_count'     => DB::raw('view_count + 1'),
            'updated_at'     => now(),
        ]);
    }

    /**
     * The candidate-facing stage for an internal status, and the timeline.
     *
     * @return array{current:string, index:int, stages:array<int, array{name:string, state:string}>, closed:bool}
     */
    public function timeline(?string $status): array
    {
        $closed = (string) $status === 'Rejected';
        $current = self::STAGE_MAP[(string) $status] ?? self::STAGES[0];
        $index = array_search($current, self::STAGES, true);
        $index = $index === false ? 0 : $index;

        $stages = [];
        foreach (self::STAGES as $i => $name) {
            $stages[] = [
                'name'  => $name,
                // 'done' is behind them, 'current' is where they are, 'upcoming'
                // has not happened. A closed application has no current stage.
                'state' => $closed
                    ? ($i <= $index ? 'done' : 'upcoming')
                    : ($i < $index ? 'done' : ($i === $index ? 'current' : 'upcoming')),
            ];
        }

        return ['current' => $current, 'index' => $index, 'stages' => $stages, 'closed' => $closed];
    }
}
