<?php

namespace App\Http\Controllers\Api\Onboarding;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Onboarding\NextStepsService;
use Illuminate\Http\Request;

/**
 * First-run guidance: what THIS person should do next.
 *
 * ── NO ROLE GUARD ON THE ROUTE, DELIBERATELY ────────────────────────────────
 *
 * Every other admin surface in this module carries `profile:admin,hr`. This one
 * must not: an employee's next step is as real as an administrator's, and the
 * whole point is that each of the nine roles gets an answer. The ROLE decides
 * the CONTENT, and it is read from the token's owner - so there is nothing a
 * caller could send to see somebody else's list.
 *
 * The permission check that matters happens per step, in NextStepsService: a
 * step is dropped unless the caller's profile can view the screen it points at.
 * A guard on the route would be coarser than that, not stricter.
 *
 * ── AND NOTHING IS STORED EXCEPT DISMISSAL ──────────────────────────────────
 *
 * The steps themselves are measured at read time. The only thing written is a
 * `user_onboarding_status` row saying this person has dealt with a step, which
 * is the one fact no query could recover - a person deciding a suggestion does
 * not apply to them leaves no other trace.
 */
class NextStepsController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/onboarding/next-steps */
    public function index(Request $request, NextStepsService $steps)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $result = $steps->forUser($identity['sub_institute_id'], $identity['user_id']);

        return response()->json([
            'status' => true,
            'data' => [
                'role' => $result['role'],
                'steps' => $result['steps'],
                'dismissed' => $result['dismissed'],
                // An empty list is an ANSWER, not a failure to produce one, and
                // the screen needs to be able to tell the two apart.
                'complete' => $result['role'] !== null && $result['steps'] === [],
            ],
        ]);
    }

    /** POST /api/onboarding/next-steps/dismiss */
    public function dismiss(Request $request, NextStepsService $steps)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate(['key' => 'required|string|max:191']);

        $ok = $steps->dismiss(
            $identity['sub_institute_id'],
            $identity['user_id'],
            (string) $request->input('key')
        );

        // A key this service does not define is a 404 rather than a silent
        // success - a caller that mistyped a step should hear about it instead
        // of believing something was dismissed.
        if (!$ok) {
            return response()->json(['status' => false, 'message' => 'No such step.'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Step hidden.']);
    }

    /** POST /api/onboarding/next-steps/restore */
    public function restore(Request $request, NextStepsService $steps)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate(['key' => 'required|string|max:191']);

        $steps->restore($identity['user_id'], (string) $request->input('key'));

        return response()->json(['status' => true, 'message' => 'Step restored.']);
    }
}
