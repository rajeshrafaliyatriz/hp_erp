<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Recommendations\RecommendationRepository;
use App\Domain\AI\Support\AiAuditLogger;
use Illuminate\Http\Request;
use Throwable;

/**
 * Recommendation Engine — the approval gate over G2G's own recommendations.
 *
 * Serves the same paths as LMS K-12's `RecommendationController` (`pending`, `show`,
 * `approve`, `reject`, `defer`) so the two products' clients agree. What is behind
 * them is entirely G2G's: `hpbrain_recommendations` and the reasoning-and-evidence
 * chain it already carries.
 *
 * ── THE DECISION IS THE POINT, AND SO IS THE EXPLANATION ───────────────────
 *
 * A recommendation nobody can act on is a report. These endpoints are what make it a
 * decision: an administrator sees what was recommended, reads the reasoning step that
 * produced it and the evidence behind that, and then approves, rejects or defers —
 * with the outcome written to the row and to the audit trail.
 *
 * `show` returns the whole chain rather than the recommendation alone. That is
 * deliberate and is the capability's own stated purpose: "a recommendation is only
 * trusted if it can be explained, and the explanation is the evidence behind it".
 * Returning the headline and making the screen fetch its justification separately
 * would let a screen render an approve button beside an explanation that never
 * loaded.
 *
 * ── NOTHING IS GENERATED HERE ──────────────────────────────────────────────
 *
 * This controller does not ask a model for a recommendation. The rows it serves were
 * produced by the reasoning pipeline that already runs in this database, and
 * inventing new ones on read would mean an administrator approving something no
 * evidence supports. Drafting is a separate capability and is honestly absent.
 */
class RecommendationController extends AiController
{
    public function __construct(
        private readonly RecommendationRepository $recommendations,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /**
     * Recommendations awaiting a decision.
     *
     * The work queue, ordered by confidence — see the repository for why that beats
     * ordering by date.
     */
    public function pending(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            if (! $this->recommendations->available()) {
                return $this->failure(
                    'The recommendation store is not installed on this deployment.',
                    422
                );
            }

            return $this->success('Pending recommendations resolved.', [
                'sub_institute_id' => $institute,
                'counts' => $this->recommendations->counts($institute),
                'recommendations' => $this->recommendations->pending(
                    $institute,
                    $this->limit($request, 50, 200)
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Every recommendation, optionally narrowed to one status.
     *
     * Separate from `pending` because they answer different questions: that one is
     * "what needs me", this one is "what has this organisation been told", which is
     * the view somebody reviewing past decisions wants.
     */
    public function index(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $validated = $request->validate([
                'status' => 'nullable|string|max:40',
            ]);

            return $this->success('Recommendations resolved.', [
                'sub_institute_id' => $institute,
                'status' => $validated['status'] ?? null,
                'counts' => $this->recommendations->counts($institute),
                'recommendations' => $this->recommendations->all(
                    $institute,
                    $validated['status'] ?? null,
                    $this->limit($request, 100, 200)
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One recommendation with its reasoning step and evidence. */
    public function show(Request $request, string $recommendation)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $chain = $this->recommendations->explain($recommendation, $institute);

            if ($chain['recommendation'] === null) {
                return $this->failure('That recommendation was not found.', 404);
            }

            return $this->success('Recommendation resolved.', $chain + [
                'sub_institute_id' => $institute,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function approve(Request $request, string $recommendation)
    {
        return $this->decide($request, $recommendation, 'approve');
    }

    public function reject(Request $request, string $recommendation)
    {
        return $this->decide($request, $recommendation, 'reject');
    }

    public function defer(Request $request, string $recommendation)
    {
        return $this->decide($request, $recommendation, 'defer');
    }

    /**
     * Record a decision and audit it.
     *
     * The note is optional and is stored only in the audit row. `hpbrain_recommendations`
     * has no column for one, and adding a column to a table another application writes
     * is a change with a blast radius this feature has no business taking on — whereas
     * the audit trail is exactly where "who decided this, and why" belongs.
     */
    private function decide(Request $request, string $recommendation, string $decision)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $validated = $request->validate([
                'note' => 'nullable|string|max:2000',
            ]);

            // Read before the write so the audit row can name what was decided. After
            // the update the title is still there but the status is not, and "approved
            // a recommendation that was open" is the fact worth recording.
            $before = $this->recommendations->find($recommendation, $institute);

            if ($before === null) {
                return $this->failure('That recommendation was not found.', 404);
            }

            $outcome = $this->recommendations->decide($recommendation, $institute, $decision);

            if (! $outcome['ok']) {
                // 409, not 422: the request was well-formed and the caller is not at
                // fault. The recommendation's state changed, most likely because
                // somebody else decided it first.
                return $this->failure($outcome['message'], 409);
            }

            $this->audit->record("ai.recommendation.{$decision}d", $scope, [
                'related_type' => 'hpbrain_recommendations',
                'subject_entity_key' => 'recommendation',
                'message' => sprintf(
                    '%s "%s" (was %s, now %s).',
                    ucfirst($decision) . 'd',
                    $before['title'],
                    $before['status'],
                    $outcome['status']
                ),
                'payload' => [
                    'recommendation_id' => $recommendation,
                    'from' => $before['status'],
                    'to' => $outcome['status'],
                    'confidence' => $before['confidence'],
                    'note' => $validated['note'] ?? null,
                ],
            ]);

            return $this->success($outcome['message'], [
                'recommendation' => $this->recommendations->find($recommendation, $institute),
                'counts' => $this->recommendations->counts($institute),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
