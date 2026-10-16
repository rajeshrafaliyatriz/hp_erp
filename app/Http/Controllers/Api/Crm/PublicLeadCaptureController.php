<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Leads\LeadAssigner;
use App\Domain\Crm\Leads\LeadFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sales & Marketing gap-closure, Wave 3: the PUBLIC, anonymous side of
 * web-to-lead capture - genuinely unauthenticated, no `resolveApiIdentity()`
 * anywhere in this class, deliberately kept separate from
 * CrmLeadCaptureController (the authenticated admin side) so the two trust
 * boundaries can never be mixed up by a shared base class or a copy-pasted
 * middleware list.
 *
 * The tenant is resolved ONLY from `publicKey` (an unguessable, random
 * per-tenant token - never a numeric id, see the settings migration's own
 * docblock) - nothing in the request body is ever trusted for that, the same
 * rule ResolvesApiIdentity enforces for authenticated routes, applied here
 * by construction instead.
 *
 * Defenses, deliberately proportionate to a free-text public form rather
 * than a login endpoint: route-level rate limiting (`throttle:10,1` on
 * submit), a honeypot field (`website` - a name real bots fill on reflex,
 * real visitors never see it), and a same-tenant/same-email 24h de-dupe so a
 * double-click or a confused retry can't create two leads. No CAPTCHA
 * integration - that needs a vendor key this tenant hasn't chosen yet; see
 * the gap-closure scope tiering in DECISIONS.md for why that is a separate,
 * later decision rather than something guessed at here.
 */
class PublicLeadCaptureController extends Controller
{
    /** GET - lets the public page confirm the form is live before rendering it, with nothing more than a boolean leaked. */
    public function form(string $publicKey): JsonResponse
    {
        $row = $this->activeSettingsFor($publicKey);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'This form is not available.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Form available.', 'data' => ['available' => true]]);
    }

    public function submit(Request $request, string $publicKey): JsonResponse
    {
        $settings = $this->activeSettingsFor($publicKey);

        if (! $settings) {
            return response()->json(['status' => 0, 'message' => 'This form is not available.'], 404);
        }

        // Honeypot: a real visitor never sees or fills this field (hidden off-screen on the
        // public page). A bot that blindly fills every input in the DOM does. Pretend success
        // so the bot has no signal to adapt to - never created, never logged as an error.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['status' => 1, 'message' => 'Thanks! We will be in touch soon.']);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'nullable|string|max:191',
            'lastName' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:191',
            'message' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = (int) $settings->sub_institute_id;
        $email = $request->filled('email') ? trim((string) $request->input('email')) : null;

        // A double-click or a confused retry within the same day lands on the lead already
        // created, not a second one - checked by email only (the one field likely to repeat
        // verbatim across both a genuine and an accidental duplicate submission).
        if ($email !== null) {
            $recent = DB::table('crm_leads')
                ->where('sub_institute_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($recent) {
                return response()->json(['status' => 1, 'message' => 'Thanks! We will be in touch soon.']);
            }
        }

        $assignedTo = LeadAssigner::next($tenantId) ?? ($settings->default_assigned_to ? (int) $settings->default_assigned_to : null);

        if ($assignedTo === null) {
            // Should not be reachable - CrmLeadCaptureController::updateSettings() refuses to
            // enable the form without a default assignee - but a public endpoint never assumes
            // its own invariants held; fail closed rather than violate crm_leads.assigned_to's
            // NOT NULL constraint.
            report(new \RuntimeException("Public lead capture for tenant {$tenantId} has no assignee available (rotation empty and no default set) despite being enabled."));

            return response()->json(['status' => 0, 'message' => 'This form is temporarily unavailable. Please try again later.'], 503);
        }

        LeadFactory::create([
            'first_name' => $request->filled('firstName') ? mb_substr(trim((string) $request->input('firstName')), 0, 191) : null,
            'last_name' => mb_substr(trim((string) $request->input('lastName')), 0, 191),
            'email' => $email,
            'phone' => $request->filled('phone') ? mb_substr(trim((string) $request->input('phone')), 0, 50) : null,
            'company' => $request->filled('company') ? mb_substr(trim((string) $request->input('company')), 0, 191) : null,
            'lead_source' => $settings->default_lead_source,
            'lead_status' => 'New',
            'description' => $request->filled('message') ? mb_strcut(trim((string) $request->input('message')), 0, 255, 'UTF-8') : null,
            'assigned_to' => $assignedTo,
        ], $tenantId, $assignedTo);

        DB::table('crm_lead_capture_settings')->where('id', $settings->id)->update([
            'submissions_count' => DB::raw('submissions_count + 1'),
            'last_submission_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Thanks! We will be in touch soon.'], 201);
    }

    private function activeSettingsFor(string $publicKey): ?object
    {
        return DB::table('crm_lead_capture_settings')
            ->where('public_key', $publicKey)
            ->where('is_enabled', true)
            ->first();
    }
}
