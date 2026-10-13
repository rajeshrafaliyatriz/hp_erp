<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\MailGate;
use App\Support\MailSender;
use App\Support\MailSenderModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HOW THIS ORGANISATION SENDS ANYTHING AT ALL - now one profile per
 * (tenant, module) instead of one row per tenant. An administrator can give
 * Hiring its own mailbox, CRM its own, and leave everything else on one
 * organisation-wide default - see the gap-closure plan's own "Decisions
 * locked in" for why `smtp_details` grew a `module_key` column instead of
 * a new table.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE SCREEN THAT UNBLOCKS THE OTHERS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every real send site in the app (`App\Support\MailSender::mailer()`) now
 * resolves its own module's profile here first, falling back to this
 * tenant's own 'default' profile, falling back to the platform's shared
 * `.env` account only if neither exists. This screen is the only place any
 * of those three states gets created or changed.
 *
 * ── TWO GATES, AND THEY ARE NOT THE SAME QUESTION ───────────────────────────
 *
 *   `MailGate::allowedForTenant()`   MAY this tenant send AT ALL?  (an operator switch)
 *   an `smtp_details` row            CAN it, and AS WHOM?          (the tenant's own choice)
 *
 * Both are reported, separately (`options()`), because "no email is
 * arriving" has two very different causes and an administrator can only fix
 * one of them from this screen.
 *
 * ── THE PASSWORD IS NEVER RETURNED, AND IS STORED ENCRYPTED ────────────────
 *
 * `index()` reports whether a profile has one stored, never its value - same
 * reasoning as before (an administrator who can read the mailbox password
 * can read every password-reset link that mailbox ever receives). It is now
 * also encrypted at rest (`Crypt::encryptString()`/`decryptString()`, since
 * this table is read via the query builder, not an Eloquent model with a
 * cast) rather than the plain text it was stored as previously.
 */
class DeliveryController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/organization/delivery/options - the module picker + the operator-level gate status. */
    public function options(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        return response()->json([
            'status' => 1,
            'data' => [
                'modules' => MailSenderModules::forFrontend(),
                'allowed' => MailGate::allowedForTenant($tenantId),
                'blocked_reason' => MailGate::reasonForTenant($tenantId),
            ],
        ]);
    }

    /** GET /api/organization/delivery/profiles */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        $profiles = DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByRaw("module_key = 'default' DESC")
            ->orderBy('label')
            ->get(['id', 'label', 'module_key', 'gmail', 'from_name', 'server_address', 'port', 'password', 'updated_at']);

        $sms = DB::table('sms_api_details')
            ->where('sub_institute_id', $tenantId)
            ->first(['id', 'url', 'is_active', 'updated_at']);

        return response()->json([
            'status' => 1,
            'data' => [
                'profiles' => $profiles->map(fn ($row) => $this->resource($row))->all(),
                'sms' => [
                    'configured' => $sms !== null,
                    'endpoint' => $sms->url ?? null,
                    'active' => (bool) ($sms->is_active ?? false),
                ],
            ],
        ]);
    }

    /** POST /api/organization/delivery/profiles */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $data = $this->validatePayload($request);
        $tenantId = (int) $identity['sub_institute_id'];

        if (!$request->filled('password')) {
            return response()->json([
                'status' => 0,
                'message' => 'A password is needed the first time you set this up.',
            ], 422);
        }

        if ($this->moduleTaken($tenantId, $data['module_key'])) {
            return response()->json([
                'status' => 0,
                'message' => 'This organisation already has a sender profile for that module. Edit the existing one instead.',
            ], 422);
        }

        try {
            $id = DB::table('smtp_details')->insertGetId([
                'label' => $data['label'],
                'module_key' => $data['module_key'],
                'gmail' => $data['from_address'],
                'from_name' => $data['from_name'] ?? null,
                'server_address' => $data['server'],
                'port' => (string) $data['port'],
                'password' => Crypt::encryptString($data['password']),
                'sub_institute_id' => $tenantId,
                'created_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The check above can still lose a genuine race (two submits at
            // once) - the unique index is the real guarantee, this is just
            // making sure its violation reaches the admin as a sentence
            // rather than as a raw SQL error.
            return response()->json([
                'status' => 0,
                'message' => 'This organisation already has a sender profile for that module. Edit the existing one instead.',
            ], 422);
        }

        $row = DB::table('smtp_details')->where('id', $id)->first(['id', 'label', 'module_key', 'gmail', 'from_name', 'server_address', 'port', 'password', 'updated_at']);

        return response()->json(['status' => 1, 'message' => 'Sender profile created.', 'data' => $this->resource($row)], 201);
    }

    /** PUT /api/organization/delivery/profiles/{id} */
    public function update(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $existing = $this->ownRow($tenantId, $id);

        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Sender profile not found.'], 404);
        }

        $data = $this->validatePayload($request);

        if ($data['module_key'] !== $existing->module_key && $this->moduleTaken($tenantId, $data['module_key'])) {
            return response()->json([
                'status' => 0,
                'message' => 'This organisation already has a sender profile for that module.',
            ], 422);
        }

        $payload = [
            'label' => $data['label'],
            'module_key' => $data['module_key'],
            'gmail' => $data['from_address'],
            'from_name' => $data['from_name'] ?? null,
            'server_address' => $data['server'],
            'port' => (string) $data['port'],
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ];

        if ($request->filled('password')) {
            $payload['password'] = Crypt::encryptString($data['password']);
        }

        try {
            DB::table('smtp_details')->where('id', $id)->update($payload);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json([
                'status' => 0,
                'message' => 'This organisation already has a sender profile for that module. Edit the existing one instead.',
            ], 422);
        }

        $row = DB::table('smtp_details')->where('id', $id)->first(['id', 'label', 'module_key', 'gmail', 'from_name', 'server_address', 'port', 'password', 'updated_at']);

        return response()->json(['status' => 1, 'message' => 'Sender profile updated.', 'data' => $this->resource($row)]);
    }

    /** DELETE /api/organization/delivery/profiles/{id} */
    public function destroy(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->ownRow($tenantId, $id)) {
            return response()->json(['status' => 0, 'message' => 'Sender profile not found.'], 404);
        }

        /*
         * HARD DELETE, NOT SOFT - unlike the rest of this codebase's
         * convention, and deliberately so.
         *
         * `unique(sub_institute_id, module_key)` has no idea what `deleted_at`
         * means - it is a plain column, not a partial/filtered index (MySQL
         * has no such thing). A soft-deleted row still occupies its slot, so
         * deleting a profile and then trying to re-add one for the SAME
         * module fails with a raw "Duplicate entry" the admin never typed -
         * confirmed live: tenant 6 hit exactly this deleting and recreating
         * a 'default' profile. There is no audit/compliance reason to keep a
         * dead row of SMTP credentials around, so removing it for real is
         * both simpler and correct here.
         */
        DB::table('smtp_details')->where('id', $id)->delete();

        return response()->json(['status' => 1, 'message' => 'Sender profile removed. Mail for that module now falls back to the organisation default.']);
    }

    /**
     * POST /api/organization/delivery/profiles/{id}/test
     *
     * ── A TEST SEND GOES TO THE CALLER, AND NOWHERE ELSE ────────────────────
     *
     * No address is accepted from the request - same reasoning as before.
     *
     * ── AND IT GOES THROUGH *THIS* PROFILE'S OWN CREDENTIALS, NOT THE
     *    GLOBAL MAILER ───────────────────────────────────────────────────────
     *
     * The previous version of this endpoint called the plain `Mail::raw()`
     * facade, which always sends through the platform's shared `.env`
     * account - so clicking Test never actually proved the organisation's
     * own stored credentials worked. `MailSender::mailerForRow()` builds a
     * Mailer from this exact row, closing that gap.
     */
    public function test(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $row = $this->ownRow($tenantId, $id);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Sender profile not found.'], 404);
        }

        $address = DB::table('tbluser')->where('id', $identity['user_id'])->value('email');

        if (!$address) {
            return response()->json([
                'status' => 0,
                'message' => 'Your account has no email address, so there is nowhere to send a test.',
            ], 422);
        }

        if (!MailGate::allowedForTenant($tenantId)) {
            return response()->json(['status' => 0, 'message' => MailGate::reasonForTenant($tenantId)], 422);
        }

        try {
            MailSender::mailerForRow($row)->raw(
                "This is a test from your HP ERP settings, sent through the \"{$row->label}\" sender profile.\n\n"
                    . "If you are reading it, that mailbox is set up correctly.",
                fn ($message) => $message->to($address)->subject('Test message from HP ERP')
            );

            return response()->json([
                'status' => 1,
                'message' => 'Sent to ' . $address . ' via ' . $row->gmail . '. If it does not arrive within a few minutes, check the spam folder and the settings above.',
            ]);
        } catch (\Throwable $e) {
            // The REAL reason, not a generic failure - same convention as before.
            Log::error('delivery profile test failed', ['tenant' => $tenantId, 'profile_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'status' => 0,
                'message' => 'The test could not be sent.',
                'data' => ['error' => $e->getMessage()],
            ], 422);
        }
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:191'],
            'module_key' => ['required', 'string', 'max:60', function ($attribute, $value, $fail) {
                if (!MailSenderModules::isValid($value)) {
                    $fail('That is not a recognised module.');
                }
            }],
            'from_address' => ['required', 'email:filter', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:191'],
            'server' => ['required', 'string', 'max:191'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            // Absent means "keep what is stored" on update; store() requires
            // it explicitly since there is nothing to keep yet.
            'password' => ['sometimes', 'string', 'min:1', 'max:191'],
        ]);
    }

    private function moduleTaken(int $tenantId, string $moduleKey): bool
    {
        return DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('smtp_details')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'label', 'module_key', 'gmail', 'from_name', 'server_address', 'port', 'password', 'updated_at']);
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'label' => $row->label,
            'moduleKey' => $row->module_key,
            'moduleLabel' => MailSenderModules::OPTIONS[$row->module_key] ?? $row->module_key,
            'fromAddress' => $row->gmail,
            'fromName' => $row->from_name,
            'server' => $row->server_address,
            'port' => $row->port !== null ? (int) $row->port : null,
            // Whether, never what.
            'hasPassword' => !empty($row->password),
            'updatedAt' => $row->updated_at,
        ];
    }
}
