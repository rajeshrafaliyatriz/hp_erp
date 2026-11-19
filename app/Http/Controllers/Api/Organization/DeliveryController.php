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
use Illuminate\Support\Facades\Schema;

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

        // ── SMS Configuration (LMS K-12 table + Vault sync) ────────────────
        $smsData = null;
        if (Schema::hasTable('sms_api_details')) {
            $sms = DB::table('sms_api_details')
                ->where('sub_institute_id', $tenantId)
                ->first();

            if (!$sms) {
                $sms = DB::table('sms_api_details')
                    ->where('is_active', 1)
                    ->orderByRaw("sub_institute_id IN (1, 6) DESC, id DESC")
                    ->first();
            }

            if ($sms && !empty($sms->url)) {
                $smsData = [
                    'id' => (string) $sms->id,
                    'configured' => true,
                    'url' => $sms->url,
                    'pram' => $sms->pram ?? '',
                    'mobile_var' => $sms->mobile_var ?? '',
                    'text_var' => $sms->text_var ?? '',
                    'last_var' => $sms->last_var ?? '',
                    'is_active' => (bool) ($sms->is_active ?? true),
                    'updated_at' => $sms->updated_at ?? null,
                ];
            }
        }

        $vaultSms = DB::table('g2g_integration_credentials')
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', 'sms')
            ->first();

        if ($vaultSms) {
            $isVaultActive = ($vaultSms->status ?? 'configured') !== 'inactive';
            if ($smsData) {
                $smsData['is_active'] = isset($sms->is_active) ? (bool) $sms->is_active : $isVaultActive;
                if ($vaultSms->config) {
                    try {
                        $dec = json_decode(Crypt::decryptString($vaultSms->config), true);
                        if (!empty($dec['test_mobile_number'])) {
                            $smsData['test_mobile_number'] = $dec['test_mobile_number'];
                        }
                    } catch (\Throwable) {}
                }
            } elseif ($vaultSms->config) {
                try {
                    $dec = json_decode(Crypt::decryptString($vaultSms->config), true);
                    if (!empty($dec['url'])) {
                        $smsData = [
                            'id' => 'vault-sms',
                            'configured' => true,
                            'url' => $dec['url'] ?? '',
                            'pram' => $dec['pram'] ?? '',
                            'mobile_var' => $dec['mobile_var'] ?? '',
                            'text_var' => $dec['text_var'] ?? '',
                            'last_var' => $dec['last_var'] ?? '',
                            'test_mobile_number' => $dec['test_mobile_number'] ?? '',
                            'is_active' => $isVaultActive,
                            'updated_at' => $vaultSms->updated_at ?? null,
                        ];
                    }
                } catch (\Throwable) {}
            }
        }

        // ── WhatsApp Configuration (LMS K-12 table + Vault sync) ───────────
        $waData = null;
        if (Schema::hasTable('whatapp_user_details')) {
            $wa = DB::table('whatapp_user_details')
                ->where('sub_institute_id', $tenantId)
                ->first();

            if (!$wa) {
                $wa = DB::table('whatapp_user_details')
                    ->where('is_active', 1)
                    ->orderByRaw("sub_institute_id IN (1, 6) DESC, id DESC")
                    ->first();
            }

            if ($wa && (!empty($wa->cloud_api_phone_number_id) || !empty($wa->user_whatsapp_no))) {
                $hasActiveCol = Schema::hasColumn('whatapp_user_details', 'is_active');
                $waData = [
                    'id' => (string) $wa->id,
                    'configured' => true,
                    'user_whatsapp_no' => $wa->user_whatsapp_no ?? '',
                    'phone_number_id' => $wa->cloud_api_phone_number_id ?? '',
                    'has_access_token' => !empty($wa->cloud_api_access_token),
                    'api_version' => 'v25.0',
                    'is_active' => $hasActiveCol ? (bool) ($wa->is_active ?? true) : true,
                    'created_by_name' => $wa->created_by_name ?? null,
                    'updated_at' => $wa->updated_at ?? null,
                ];
            }
        }

        $vaultWa = DB::table('g2g_integration_credentials')
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', 'whatsapp')
            ->first();

        if ($vaultWa) {
            $isWaActive = ($vaultWa->status ?? 'configured') !== 'inactive';
            if ($waData) {
                $waData['is_active'] = $isWaActive;
                if ($vaultWa->config) {
                    try {
                        $decWa = json_decode(Crypt::decryptString($vaultWa->config), true);
                        if (!empty($decWa['api_version'])) {
                            $waData['api_version'] = $decWa['api_version'];
                        }
                    } catch (\Throwable) {}
                }
            } elseif ($vaultWa->config) {
                try {
                    $decWa = json_decode(Crypt::decryptString($vaultWa->config), true);
                    if (!empty($decWa['phone_number_id']) || !empty($decWa['user_whatsapp_no'])) {
                        $waData = [
                            'id' => 'vault-wa',
                            'configured' => true,
                            'user_whatsapp_no' => $decWa['user_whatsapp_no'] ?? '',
                            'phone_number_id' => $decWa['phone_number_id'] ?? '',
                            'has_access_token' => !empty($decWa['access_token']),
                            'api_version' => $decWa['api_version'] ?? 'v25.0',
                            'is_active' => $isWaActive,
                            'updated_at' => $vaultWa->updated_at ?? null,
                        ];
                    }
                } catch (\Throwable) {}
            }
        }

        return response()->json([
            'status' => 1,
            'data' => [
                'profiles' => $profiles->map(fn ($row) => $this->resource($row))->all(),
                'sms' => $smsData,
                'whatsapp' => $waData,
            ],
        ]);
    }

    /** POST /api/organization/delivery/sms */
    public function storeSms(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];
        $request->validate([
            'url' => 'required|string|max:2000',
            'pram' => 'required|string|max:500',
            'mobile_var' => 'required|string|max:255',
            'text_var' => 'required|string|max:255',
            'last_var' => 'nullable|string|max:500',
        ]);

        $url = trim($request->input('url'));
        if (!preg_match('#^https?://#i', $url)) {
            return response()->json(['status' => 0, 'message' => 'The URL must start with http:// or https://.'], 422);
        }

        $pram = trim($request->input('pram'));
        $mobileVar = trim($request->input('mobile_var'));
        $textVar = trim($request->input('text_var'));
        $lastVar = trim((string) $request->input('last_var', ''));
        $isActive = (int) $request->input('is_active', 1);

        $now = now();
        if (Schema::hasTable('sms_api_details')) {
            DB::table('sms_api_details')->updateOrInsert(
                ['sub_institute_id' => $tenantId],
                [
                    'url' => $url,
                    'pram' => $pram,
                    'mobile_var' => $mobileVar,
                    'text_var' => $textVar,
                    'last_var' => $lastVar,
                    'is_active' => $isActive,
                    'updated_at' => $now,
                ]
            );
        }

        $vaultConfig = [
            'url' => $url,
            'pram' => $pram,
            'mobile_var' => $mobileVar,
            'text_var' => $textVar,
            'last_var' => $lastVar,
            'test_mobile_number' => trim((string) $request->input('test_mobile_number', '')),
        ];

        DB::table('g2g_integration_credentials')->updateOrInsert(
            ['sub_institute_id' => $tenantId, 'provider_key' => 'sms'],
            [
                'status' => $isActive ? 'configured' : 'inactive',
                'config' => Crypt::encryptString(json_encode($vaultConfig)),
                'updated_at' => $now,
            ]
        );

        return response()->json(['status' => 1, 'message' => 'SMS gateway configuration saved successfully.']);
    }

    /** PUT /api/organization/delivery/sms/status */
    public function toggleSmsStatus(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];
        $isActive = (int) $request->input('is_active', 1);

        if (Schema::hasTable('sms_api_details')) {
            DB::table('sms_api_details')->where('sub_institute_id', $tenantId)->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);
        }

        DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'sms')->update([
            'status' => $isActive ? 'configured' : 'inactive',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => $isActive ? 'SMS gateway activated.' : 'SMS gateway deactivated.',
        ]);
    }

    /** DELETE /api/organization/delivery/sms */
    public function destroySms(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];

        if (Schema::hasTable('sms_api_details')) {
            DB::table('sms_api_details')->where('sub_institute_id', $tenantId)->delete();
        }

        DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'sms')->delete();

        return response()->json(['status' => 1, 'message' => 'SMS gateway configuration removed.']);
    }

    /** POST /api/organization/delivery/sms/test */
    public function testSms(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];
        $testMobile = trim((string) $request->input('test_mobile', $request->input('test_mobile_number', '')));

        $row = Schema::hasTable('sms_api_details')
            ? DB::table('sms_api_details')->where('sub_institute_id', $tenantId)->first()
            : null;

        $url = $row->url ?? null;
        $pram = $row->pram ?? '';
        $mobileVar = $row->mobile_var ?? '';
        $textVar = $row->text_var ?? '';
        $lastVar = $row->last_var ?? '';

        if (!$url) {
            $vault = DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'sms')->first();
            if ($vault && $vault->config) {
                try {
                    $dec = json_decode(Crypt::decryptString($vault->config), true);
                    $url = $dec['url'] ?? null;
                    $pram = $dec['pram'] ?? '';
                    $mobileVar = $dec['mobile_var'] ?? '';
                    $textVar = $dec['text_var'] ?? '';
                    $lastVar = $dec['last_var'] ?? '';
                } catch (\Throwable) {}
            }
        }

        if (!$url) {
            return response()->json(['status' => 0, 'message' => 'No SMS gateway configured.'], 404);
        }

        $tester = new \App\Services\Platform\Integrations\SmsIntegrationTester();
        $result = $tester->test([
            'url' => $url,
            'pram' => $pram,
            'mobile_var' => $mobileVar,
            'text_var' => $textVar,
            'last_var' => $lastVar,
            'test_mobile_number' => $testMobile,
        ]);

        return response()->json([
            'status' => $result['ok'] ? 1 : 0,
            'message' => $result['message'],
        ]);
    }

    /** POST /api/organization/delivery/whatsapp */
    public function storeWhatsapp(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];
        $request->validate([
            'user_whatsapp_no' => 'required|string|max:20',
            'phone_number_id' => 'required|string|max:191',
        ]);

        $userWhatsappNo = trim($request->input('user_whatsapp_no'));
        $phoneId = trim($request->input('phone_number_id'));
        $token = trim((string) ($request->input('access_token') ?: $request->input('cloud_api_access_token', '')));
        $apiVersion = trim((string) $request->input('api_version', 'v25.0')) ?: 'v25.0';
        $isActive = (int) $request->input('is_active', 1);

        $now = now();

        if (Schema::hasTable('whatapp_user_details')) {
            $existingWa = DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->first();
            $waData = [
                'user_whatsapp_no' => $userWhatsappNo,
                'cloud_api_phone_number_id' => $phoneId,
                'api_type' => 'cloud_api',
                'updated_at' => $now,
            ];

            if ($token !== '') {
                $waData['cloud_api_access_token'] = $token;
            } elseif (!$existingWa) {
                return response()->json(['status' => 0, 'message' => 'An access token is required when configuring WhatsApp for the first time.'], 422);
            }

            if (Schema::hasColumn('whatapp_user_details', 'is_active')) {
                $waData['is_active'] = $isActive;
            }

            if ($existingWa) {
                DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->update($waData);
            } else {
                $waData['sub_institute_id'] = $tenantId;
                $waData['user_whatsapp_sid'] = '';
                $waData['user_whatsapp_token'] = '';
                $waData['created_by'] = $identity['user_id'];
                $waData['created_by_name'] = DB::table('tbluser')->where('id', $identity['user_id'])->value('name') ?: '';
                $waData['created_at'] = $now;
                DB::table('whatapp_user_details')->insert($waData);
            }
        }

        $existingVault = DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'whatsapp')->first();
        $vaultConfig = [
            'user_whatsapp_no' => $userWhatsappNo,
            'phone_number_id' => $phoneId,
            'api_version' => $apiVersion,
        ];
        if ($token !== '') {
            $vaultConfig['access_token'] = $token;
        } elseif ($existingVault && $existingVault->config) {
            try {
                $dec = json_decode(Crypt::decryptString($existingVault->config), true);
                if (!empty($dec['access_token'])) {
                    $vaultConfig['access_token'] = $dec['access_token'];
                }
            } catch (\Throwable) {}
        }

        DB::table('g2g_integration_credentials')->updateOrInsert(
            ['sub_institute_id' => $tenantId, 'provider_key' => 'whatsapp'],
            [
                'status' => $isActive ? 'configured' : 'inactive',
                'config' => Crypt::encryptString(json_encode($vaultConfig)),
                'updated_at' => $now,
            ]
        );

        return response()->json(['status' => 1, 'message' => 'WhatsApp configuration saved successfully.']);
    }

    /** PUT /api/organization/delivery/whatsapp/status */
    public function toggleWhatsappStatus(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];
        $isActive = (int) $request->input('is_active', 1);

        if (Schema::hasTable('whatapp_user_details') && Schema::hasColumn('whatapp_user_details', 'is_active')) {
            DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);
        }

        DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'whatsapp')->update([
            'status' => $isActive ? 'configured' : 'inactive',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => $isActive ? 'WhatsApp Business API activated.' : 'WhatsApp Business API deactivated.',
        ]);
    }

    /** DELETE /api/organization/delivery/whatsapp */
    public function destroyWhatsapp(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];

        if (Schema::hasTable('whatapp_user_details')) {
            DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->delete();
        }

        DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'whatsapp')->delete();

        return response()->json(['status' => 1, 'message' => 'WhatsApp configuration removed.']);
    }

    /** POST /api/organization/delivery/whatsapp/test */
    public function testWhatsapp(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) return $identity;

        $tenantId = (int) $identity['sub_institute_id'];

        $row = Schema::hasTable('whatapp_user_details')
            ? DB::table('whatapp_user_details')->where('sub_institute_id', $tenantId)->first()
            : null;

        $phoneId = $row->cloud_api_phone_number_id ?? null;
        $token = $row->cloud_api_access_token ?? null;
        $version = 'v25.0';

        if (!$phoneId || !$token) {
            $vault = DB::table('g2g_integration_credentials')->where('sub_institute_id', $tenantId)->where('provider_key', 'whatsapp')->first();
            if ($vault && $vault->config) {
                try {
                    $dec = json_decode(Crypt::decryptString($vault->config), true);
                    $phoneId = $dec['phone_number_id'] ?? $phoneId;
                    $token = $dec['access_token'] ?? $token;
                    $version = $dec['api_version'] ?? 'v25.0';
                } catch (\Throwable) {}
            }
        }

        if (!$phoneId || !$token) {
            return response()->json(['status' => 0, 'message' => 'No WhatsApp credentials configured.'], 404);
        }

        $tester = new \App\Services\Platform\Integrations\WhatsappIntegrationTester();
        $result = $tester->test([
            'phone_number_id' => $phoneId,
            'access_token' => $token,
            'api_version' => $version,
        ]);

        return response()->json([
            'status' => $result['ok'] ? 1 : 0,
            'message' => $result['message'],
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

        /*
         * WHO THE TEST GOES TO, NOW A CHOICE RATHER THAN ALWAYS "YOURSELF"
         *
         * This used to only ever mail the caller's own address - a deliberate
         * guard against a configured mailbox being used as an on-demand relay
         * to an arbitrary address. The admin asked for the arbitrary address
         * back, so it is validated here rather than trusted blindly:
         * `email` format (not just non-empty), and the test MESSAGE ITSELF is
         * still fixed - nobody can use this box to send a chosen subject or
         * body, only to prove a mailbox works and ask it to prove that at
         * one address instead of another. `throttle:4,1` on the route is the
         * remaining guard against it being used to spam one address.
         */
        $request->validate(['to' => ['nullable', 'email', 'max:191']]);
        $address = trim((string) $request->input('to')) !== ''
            ? trim((string) $request->input('to'))
            : DB::table('tbluser')->where('id', $identity['user_id'])->value('email');

        if (!$address) {
            return response()->json([
                'status' => 0,
                'message' => 'Your account has no email address, so there is nowhere to send a test. Type one in above instead.',
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
