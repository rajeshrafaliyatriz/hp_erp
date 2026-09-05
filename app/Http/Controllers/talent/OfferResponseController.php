<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Services\Talent\OfferAcceptanceService;
use App\Services\Talent\OfferLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A candidate answering their own offer. Public, unauthenticated, one offer wide.
 *
 * ── HOW IDENTITY WORKS HERE ─────────────────────────────────────────────────
 *
 * There is no token in the Sanctum sense and no session. The candidate's only
 * identifier is the link they were sent, and that link IS the authorisation: it
 * is a 64-character CSPRNG string whose sha256 is the unique key of exactly one
 * acceptance row. Possession of it proves nothing about who they are, only that
 * they received the message we sent to the address on the application - which is
 * the same assurance a password-reset link gives.
 *
 * That is why the surface is deliberately one offer wide. The token opens a
 * single row; it cannot be used to list anything, to reach another offer, or to
 * read the candidate's own application. The response carries only what the
 * candidate was already told in their offer letter.
 *
 * The tenant comes from the acceptance row, never from the request.
 *
 * Rate limited on both verbs - see the route declarations.
 */
class OfferResponseController extends Controller
{
    public function __construct(
        private OfferLinkService $links,
        private OfferAcceptanceService $acceptance,
    ) {
    }

    /** GET /api/offer-response/{token} — what am I being offered? */
    public function show(Request $request, string $token)
    {
        $resolved = $this->links->resolve($token);

        if (!$resolved['row']) {
            return $this->gone($resolved['reason']);
        }

        $row = $resolved['row'];

        $offer = DB::table('talent_offers as o')
            ->leftJoin('talent_job_postings as p', function ($join) use ($row) {
                $join->on('p.id', '=', 'o.job_id')->where('p.sub_institute_id', '=', $row->sub_institute_id);
            })
            ->leftJoin('institute_detail as d', 'd.sub_institute_id', '=', 'o.sub_institute_id')
            ->where('o.id', $row->offer_id)
            ->where('o.sub_institute_id', $row->sub_institute_id)
            ->first([
                'o.id', 'o.position', 'o.salary', 'o.start_date', 'o.status',
                'p.location', 'p.employment_type',
                'd.organization_name',
            ]);

        if (!$offer) {
            return $this->gone('not_found');
        }

        $application = DB::table('talent_job_applications')
            ->where('id', $row->application_id)
            ->where('sub_institute_id', $row->sub_institute_id)
            ->first(['first_name']);

        return response()->json([
            'status' => 1,
            'data' => [
                'organisation'   => $offer->organization_name,
                'candidate_name' => $application->first_name ?? null,
                'position'       => $offer->position,
                'salary'         => $offer->salary,
                'start_date'     => $offer->start_date,
                'location'       => $offer->location,
                'employment_type' => $offer->employment_type,
                'expires_at'     => $row->token_expires_at,
                'already_decided' => $row->decision !== 'pending' ? $row->decision : null,
            ],
        ], 200);
    }

    /** POST /api/offer-response/{token} — accept or decline. */
    public function respond(Request $request, string $token)
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:accepted,declined',
            'note'     => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Please choose whether you are accepting or declining.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $resolved = $this->links->resolve($token);

        if (!$resolved['row']) {
            return $this->gone($resolved['reason']);
        }

        $row = $resolved['row'];

        $offer = DB::table('talent_offers')
            ->where('id', $row->offer_id)
            ->where('sub_institute_id', $row->sub_institute_id)
            ->first();

        if (!$offer) {
            return $this->gone('not_found');
        }

        $decision = $request->input('decision');
        $syear = (string) ($row->syear ?: date('Y'));

        // actorId is null: nobody inside the organisation did this. The audit trail
        // records 'candidate' as the channel rather than attributing the act to a
        // member of staff who was not involved.
        $result = $decision === 'accepted'
            ? $this->acceptance->accept($offer, (int) $row->sub_institute_id, $syear, null, 'candidate', $request->input('note'))
            : $this->acceptance->decline($offer, (int) $row->sub_institute_id, $syear, null, 'candidate', $request->input('note'));

        if (!$result['ok']) {
            return response()->json(['status' => 0, 'message' => $result['message']], $result['status']);
        }

        // Burn the token only once the answer is safely recorded. A failure above
        // leaves the link usable, which is the right way round: a candidate who
        // hits an error must be able to try again.
        $this->links->markUsed((int) $row->id);

        return response()->json([
            'status'  => 1,
            'message' => $decision === 'accepted'
                ? 'Thank you. Your acceptance has been recorded and the team has been notified.'
                : $result['message'],
            'data'    => ['decision' => $decision],
        ], 200);
    }

    /**
     * Every failure looks the same from outside.
     *
     * Unknown, expired and already-used all return 410 so the response cannot be
     * used to probe which tokens exist. The human-readable reason is for the page
     * to show a person, not for a machine to branch on.
     */
    private function gone(?string $reason)
    {
        $message = match ($reason) {
            'expired' => 'This link has expired. Please contact the hiring team for a new one.',
            'used'    => 'This link has already been used. If you need to change your answer, contact the hiring team.',
            default   => 'This link is not valid. Please check you copied the whole address, or contact the hiring team.',
        };

        return response()->json(['status' => 0, 'message' => $message, 'reason' => $reason], 410);
    }
}
