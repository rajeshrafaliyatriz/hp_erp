<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\MailGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * HOW THIS ORGANISATION SENDS ANYTHING AT ALL.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE SECTION THAT UNBLOCKS THE OTHERS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `smtp_details` is already per-tenant and holds ONE row across twelve live
 * organisations - and that row belongs to tenant 1. Everything else in this
 * product that sends email either uses it (AJAXController's bulk mailer, which
 * reads the row for the CALLER's tenant and silently sends nothing when there
 * isn't one) or falls back to the global `.env` mailer.
 *
 * That single missing row is why the invite flow hands an administrator a link
 * to pass on by hand: `InviteService` emails when the tenant can send and
 * returns a copyable link when it cannot, and for eleven of twelve tenants it
 * cannot. This screen is how that stops being true - it is the difference
 * between "copy this link and message it to them yourself" and an invite that
 * simply arrives.
 *
 * ── TWO GATES, AND THEY ARE NOT THE SAME QUESTION ───────────────────────────
 *
 *   `MailGate::allowedForTenant()`   MAY this tenant send?  (an operator switch)
 *   an `smtp_details` row            CAN it, and as whom?   (the tenant's own)
 *
 * Both are reported, separately, because "no email is arriving" has two very
 * different causes and an administrator can only fix one of them.
 *
 * ── THE PASSWORD IS NEVER RETURNED ──────────────────────────────────────────
 *
 * `read()` reports whether one is stored, never its value. An administrator who
 * can read the mailbox password can read every password-reset link that mailbox
 * ever receives, which is every account in the organisation. Writing a new one
 * is allowed; reading the old one is not, and leaving the field blank on save
 * keeps what is already there.
 */
class DeliveryController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/organization/delivery */
    public function show(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        $smtp = DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'gmail', 'server_address', 'port', 'password', 'updated_at']);

        $sms = DB::table('sms_api_details')
            ->where('sub_institute_id', $tenantId)
            ->first(['id', 'url', 'is_active', 'updated_at']);

        return response()->json([
            'status' => 1,
            'data' => [
                'email' => [
                    'configured' => $smtp !== null,
                    'from_address' => $smtp->gmail ?? null,
                    'server' => $smtp->server_address ?? null,
                    'port' => $smtp->port ?? null,
                    // Whether, never what.
                    'has_password' => !empty($smtp->password ?? null),
                    'updated_at' => $smtp->updated_at ?? null,
                ],
                /*
                 * The operator-level switch, reported separately from the
                 * tenant's own settings. An administrator who fills this form in
                 * perfectly and still sees nothing arrive needs to know the
                 * reason is above them, not in their typing.
                 */
                'allowed' => MailGate::allowedForTenant($tenantId),
                'blocked_reason' => MailGate::reasonForTenant($tenantId),
                'sms' => [
                    'configured' => $sms !== null,
                    'endpoint' => $sms->url ?? null,
                    'active' => (bool) ($sms->is_active ?? false),
                ],
            ],
        ]);
    }

    /** PUT /api/organization/delivery */
    public function update(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'from_address' => ['required', 'email:filter', 'max:191'],
            'server' => ['required', 'string', 'max:191'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            // Absent means "keep what is stored". Empty string is NOT the same
            // as absent and is rejected, so a cleared field cannot silently
            // blank a working password.
            'password' => ['sometimes', 'string', 'min:1', 'max:191'],
        ]);

        $tenantId = (int) $identity['sub_institute_id'];

        $existing = DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'password']);

        if (!$existing && !$request->filled('password')) {
            return response()->json([
                'status' => 0,
                'message' => 'A password is needed the first time you set this up.',
            ], 422);
        }

        $payload = [
            'gmail' => $data['from_address'],
            'server_address' => $data['server'],
            'port' => (string) $data['port'],
            'sub_institute_id' => $tenantId,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ];

        if ($request->filled('password')) {
            /*
             * STORED AS TYPED, not hashed - PHPMailer has to present it to the
             * mail server. That is a property of SMTP, not a choice, and it is
             * exactly why `show()` never returns it and why this endpoint is
             * behind an administrator guard.
             */
            $payload['password'] = $data['password'];
        }

        if ($existing) {
            DB::table('smtp_details')->where('id', $existing->id)->update($payload);
        } else {
            $payload['created_by'] = $identity['user_id'];
            $payload['created_at'] = now();
            DB::table('smtp_details')->insert($payload);
        }

        return $this->show($request);
    }

    /**
     * POST /api/organization/delivery/test
     *
     * ── A TEST SEND GOES TO THE CALLER, AND NOWHERE ELSE ────────────────────
     *
     * No address is accepted from the request. An endpoint that mails an
     * arbitrary address on demand, from the organisation's own mailbox, is a
     * spam relay with a login screen. The caller's own address is on their
     * `tbluser` row, and it is the only address that proves anything anyway:
     * you are checking that YOU receive it.
     */
    public function test(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        $address = DB::table('tbluser')
            ->where('id', $identity['user_id'])
            ->value('email');

        if (!$address) {
            return response()->json([
                'status' => 0,
                'message' => 'Your account has no email address, so there is nowhere to send a test.',
            ], 422);
        }

        if (!MailGate::allowedForTenant($tenantId)) {
            return response()->json([
                'status' => 0,
                'message' => MailGate::reasonForTenant($tenantId),
            ], 422);
        }

        try {
            Mail::raw(
                "This is a test from your HP ERP settings.\n\n"
                    . "If you are reading it, invitations and notifications can reach your people.",
                fn ($message) => $message->to($address)->subject('Test message from HP ERP')
            );

            return response()->json([
                'status' => 1,
                'message' => 'Sent to ' . $address . '. If it does not arrive within a few minutes, check the spam folder and the settings above.',
            ]);
        } catch (\Throwable $e) {
            /*
             * The REAL reason, not a generic failure. "Could not send" tells an
             * administrator nothing; "535 Authentication failed" tells them the
             * password is wrong, which is the whole point of a test button.
             */
            Log::error('delivery test failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);

            return response()->json([
                'status' => 0,
                'message' => 'The test could not be sent.',
                'data' => ['error' => $e->getMessage()],
            ], 422);
        }
    }
}
