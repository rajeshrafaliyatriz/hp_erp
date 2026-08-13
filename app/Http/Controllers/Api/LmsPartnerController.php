<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Trainers, vendors and integrations for Administration & Governance.
 *
 * All three are new entities: nothing in the schema represented them before.
 * Sessions carried trainer_name/trainer_email as free text on
 * lms_virtual_classroom, there was no vendor concept at all, and although
 * routes/api.php references NangoController for Google OAuth, that class does
 * not exist and no table recorded which integrations were connected.
 *
 * The three tables were created for this page; see the migrations for the
 * column rationale. Notably lms_integrations stores no secrets - access tokens
 * stay with the provider, because a governance UI reading them would put
 * credentials back on the wire.
 */
class LmsPartnerController extends Controller
{
    use ResolvesLmsIdentity;

    private const ADMIN_PROFILES = ['admin', 'hr', 'super', 'principal'];

    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    /** Administrative surface: an unstated profile is refused, not allowed. */
    private function guardAdmin(Request $request)
    {
        // The profile now comes from the caller's tbluser row, not from
        // a `user_profile_name` they supplied themselves.
        return $this->guardLmsProfile($request, self::ADMIN_PROFILES, 'Your profile is not permitted to manage partners.');
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    private function invalid($validator)
    {
        return response()->json([
            'status' => false,
            'message' => $validator->messages()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }

    private function fail(\Exception $e, string $message)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }

    /**
     * Fetch a tenant-scoped row, or null.
     *
     * Every write goes through this so one institute can never reach another's
     * record by guessing an id.
     */
    private function findScoped(string $table, $id, $subInstituteId)
    {
        return DB::table($table)
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();
    }

    /* ─── Trainers ─────────────────────────────────────────────────────────── */

    private function trainerRules(): array
    {
        return [
            'name'           => 'required|string|max:191',
            'email'          => 'nullable|email|max:191',
            'phone'          => 'nullable|string|max:50',
            'trainer_type'   => 'nullable|string|in:internal,external',
            'vendor_id'      => 'nullable|integer',
            'user_id_link'   => 'nullable|integer',
            'specialisation' => 'nullable|string|max:191',
            'bio'            => 'nullable|string|max:2000',
            'qualifications' => 'nullable|string|max:1000',
            'hourly_rate'    => 'nullable|numeric|min:0',
            'currency'       => 'nullable|string|max:10',
            'status'         => 'nullable|boolean',
        ];
    }

    /**
     * Columns written on create and update.
     *
     * `user_id_link` rather than `user_id` in the request because every request
     * already carries `user_id` for the acting user - reusing that key would
     * silently link every trainer to whoever created them.
     */
    private function trainerPayload(Request $request): array
    {
        return [
            'name'           => $request->input('name'),
            'email'          => $request->input('email'),
            'phone'          => $request->input('phone'),
            'trainer_type'   => $request->input('trainer_type', 'internal'),
            'vendor_id'      => $request->input('vendor_id') ?: null,
            'user_id'        => $request->input('user_id_link') ?: null,
            'specialisation' => $request->input('specialisation'),
            'bio'            => $request->input('bio'),
            'qualifications' => $request->input('qualifications'),
            'hourly_rate'    => $request->input('hourly_rate'),
            'currency'       => $request->input('currency'),
            'status'         => $request->boolean('status', true) ? 1 : 0,
        ];
    }

    /** GET /api/lms/governance/trainers */
    public function trainers(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $query = DB::table('lms_trainers as t')
                ->leftJoin('lms_vendors as v', 'v.id', '=', 't.vendor_id')
                ->where('t.sub_institute_id', $subInstituteId)
                ->whereNull('t.deleted_at');

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('t.name', 'like', "%{$search}%")
                      ->orWhere('t.email', 'like', "%{$search}%")
                      ->orWhere('t.specialisation', 'like', "%{$search}%");
                });
            }

            if (($status = $request->input('status')) !== null && $status !== '') {
                $query->where('t.status', (int) $status);
            }

            if ($type = $request->input('trainer_type')) {
                $query->where('t.trainer_type', $type);
            }

            $trainers = $query
                ->orderBy('t.name')
                ->get([
                    't.id', 't.name', 't.email', 't.phone', 't.trainer_type', 't.vendor_id',
                    't.user_id', 't.specialisation', 't.bio', 't.qualifications',
                    't.hourly_rate', 't.currency', 't.status', 't.created_at',
                    'v.name as vendor_name',
                ])
                ->map(function ($trainer) {
                    $trainer->status = (int) $trainer->status;
                    return $trainer;
                });

            /*
             * Session counts, preferring the real link.
             *
             * lms_virtual_classroom.trainer_id is the precise association. Rows
             * created before a trainer record existed still carry only the
             * free-text trainer_email / trainer_name, so those are counted by
             * string match as a fallback - but only for sessions that have no
             * trainer_id, so a session is never counted twice.
             */
            $linkedCounts = DB::table('lms_virtual_classroom')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereNotNull('trainer_id')
                ->select('trainer_id', DB::raw('COUNT(*) as total'))
                ->groupBy('trainer_id')
                ->pluck('total', 'trainer_id');

            $unlinked = DB::table('lms_virtual_classroom')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereNull('trainer_id')
                ->select('trainer_email', 'trainer_name', DB::raw('COUNT(*) as total'))
                ->groupBy('trainer_email', 'trainer_name')
                ->get();

            $trainers->transform(function ($trainer) use ($linkedCounts, $unlinked) {
                $matched = (int) ($linkedCounts[$trainer->id] ?? 0);

                $fuzzy = (int) $unlinked
                    ->filter(function ($row) use ($trainer) {
                        if ($trainer->email && $row->trainer_email) {
                            return strcasecmp(trim($row->trainer_email), trim($trainer->email)) === 0;
                        }
                        return $row->trainer_name
                            && strcasecmp(trim($row->trainer_name), trim($trainer->name)) === 0;
                    })
                    ->sum('total');

                $trainer->session_count = $matched + $fuzzy;
                // Surfaced so the UI can show which counts are exact and which
                // still rest on a name match.
                $trainer->linked_session_count = $matched;
                $trainer->unlinked_session_count = $fuzzy;

                return $trainer;
            });

            return response()->json(['status' => true, 'data' => $trainers]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load trainers');
        }
    }

    /** POST /api/lms/governance/trainers */
    public function storeTrainer(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->trainerRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);

        try {
            $id = DB::table('lms_trainers')->insertGetId(
                $this->trainerPayload($request) + [
                    'sub_institute_id' => $subInstituteId,
                    'created_by' => $this->contextUserId($request),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Trainer added',
                'data' => DB::table('lms_trainers')->find($id),
            ], 201);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to add the trainer');
        }
    }

    /** PUT /api/lms/governance/trainers/{id} */
    public function updateTrainer(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->trainerRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);

        if (!$this->findScoped('lms_trainers', $id, $subInstituteId)) {
            return response()->json(['status' => false, 'message' => 'Trainer not found'], 404);
        }

        try {
            DB::table('lms_trainers')->where('id', $id)->update(
                $this->trainerPayload($request) + [
                    'updated_by' => $this->contextUserId($request),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Trainer updated',
                'data' => DB::table('lms_trainers')->find($id),
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to update the trainer');
        }
    }

    /** DELETE /api/lms/governance/trainers/{id} - soft delete. */
    public function destroyTrainer(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$this->findScoped('lms_trainers', $id, $subInstituteId)) {
            return response()->json(['status' => false, 'message' => 'Trainer not found'], 404);
        }

        try {
            DB::table('lms_trainers')->where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->contextUserId($request),
            ]);

            return response()->json(['status' => true, 'message' => 'Trainer removed']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to remove the trainer');
        }
    }

    /* ─── Vendors ──────────────────────────────────────────────────────────── */

    private function vendorRules(): array
    {
        return [
            'name'           => 'required|string|max:191',
            'vendor_code'    => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:191',
            'email'          => 'nullable|email|max:191',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|string|max:191',
            'address'        => 'nullable|string|max:1000',
            'service_type'   => 'nullable|string|max:50',
            'contract_start' => 'nullable|date',
            'contract_end'   => 'nullable|date|after_or_equal:contract_start',
            'contract_value' => 'nullable|numeric|min:0',
            'currency'       => 'nullable|string|max:10',
            'status'         => 'nullable|boolean',
            'notes'          => 'nullable|string|max:2000',
        ];
    }

    private function vendorPayload(Request $request): array
    {
        return [
            'name'           => $request->input('name'),
            'vendor_code'    => $request->input('vendor_code'),
            'contact_person' => $request->input('contact_person'),
            'email'          => $request->input('email'),
            'phone'          => $request->input('phone'),
            'website'        => $request->input('website'),
            'address'        => $request->input('address'),
            'service_type'   => $request->input('service_type'),
            'contract_start' => $request->input('contract_start'),
            'contract_end'   => $request->input('contract_end'),
            'contract_value' => $request->input('contract_value'),
            'currency'       => $request->input('currency'),
            'status'         => $request->boolean('status', true) ? 1 : 0,
            'notes'          => $request->input('notes'),
        ];
    }

    /** GET /api/lms/governance/vendors */
    public function vendors(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $query = DB::table('lms_vendors')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at');

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('vendor_code', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%");
                });
            }

            if (($status = $request->input('status')) !== null && $status !== '') {
                $query->where('status', (int) $status);
            }

            $trainerCounts = DB::table('lms_trainers')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereNotNull('vendor_id')
                ->select('vendor_id', DB::raw('COUNT(*) as total'))
                ->groupBy('vendor_id')
                ->pluck('total', 'vendor_id');

            $today = now()->startOfDay();

            $vendors = $query
                ->orderBy('name')
                ->get()
                ->map(function ($vendor) use ($trainerCounts, $today) {
                    $vendor->status = (int) $vendor->status;
                    $vendor->trainer_count = (int) ($trainerCounts[$vendor->id] ?? 0);

                    // Contract state is derived, not stored, so it can never go
                    // stale the way a saved flag would.
                    if (!$vendor->contract_end) {
                        $vendor->contract_state = 'open';
                        $vendor->days_to_expiry = null;
                    } else {
                        $end = \Carbon\Carbon::parse($vendor->contract_end);
                        $vendor->days_to_expiry = (int) $today->diffInDays($end, false);
                        $vendor->contract_state = $vendor->days_to_expiry < 0
                            ? 'expired'
                            : ($vendor->days_to_expiry <= 60 ? 'expiring' : 'active');
                    }

                    return $vendor;
                });

            return response()->json(['status' => true, 'data' => $vendors]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load vendors');
        }
    }

    /** POST /api/lms/governance/vendors */
    public function storeVendor(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->vendorRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);

        try {
            $id = DB::table('lms_vendors')->insertGetId(
                $this->vendorPayload($request) + [
                    'sub_institute_id' => $subInstituteId,
                    'created_by' => $this->contextUserId($request),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Vendor added',
                'data' => DB::table('lms_vendors')->find($id),
            ], 201);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to add the vendor');
        }
    }

    /** PUT /api/lms/governance/vendors/{id} */
    public function updateVendor(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->vendorRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);

        if (!$this->findScoped('lms_vendors', $id, $subInstituteId)) {
            return response()->json(['status' => false, 'message' => 'Vendor not found'], 404);
        }

        try {
            DB::table('lms_vendors')->where('id', $id)->update(
                $this->vendorPayload($request) + [
                    'updated_by' => $this->contextUserId($request),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Vendor updated',
                'data' => DB::table('lms_vendors')->find($id),
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to update the vendor');
        }
    }

    /**
     * DELETE /api/lms/governance/vendors/{id}
     *
     * Refused while trainers still reference the vendor, which would otherwise
     * leave them pointing at a row that no longer resolves.
     */
    public function destroyVendor(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$this->findScoped('lms_vendors', $id, $subInstituteId)) {
            return response()->json(['status' => false, 'message' => 'Vendor not found'], 404);
        }

        $linked = DB::table('lms_trainers')
            ->where('vendor_id', $id)
            ->whereNull('deleted_at')
            ->count();

        if ($linked > 0) {
            return response()->json([
                'status' => false,
                'message' => "{$linked} trainer" . ($linked === 1 ? '' : 's') . ' still linked to this vendor. Reassign them first.',
            ], 422);
        }

        try {
            DB::table('lms_vendors')->where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->contextUserId($request),
            ]);

            return response()->json(['status' => true, 'message' => 'Vendor removed']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to remove the vendor');
        }
    }

    /* ─── Integrations ─────────────────────────────────────────────────────── */

    /** GET /api/lms/governance/integrations */
    public function integrations(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $integrations = DB::table('lms_integrations')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->orderBy('display_name')
                ->get([
                    'id', 'provider', 'display_name', 'category', 'description',
                    'status', 'connected_at', 'last_sync_at', 'last_error',
                    'config', 'created_at',
                ])
                ->map(function ($integration) {
                    $decoded = $integration->config ? json_decode($integration->config, true) : null;
                    $integration->config = is_array($decoded) ? $decoded : null;
                    return $integration;
                });

            return response()->json(['status' => true, 'data' => $integrations]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load integrations');
        }
    }

    private function integrationRules(): array
    {
        return [
            'provider'     => 'required|string|max:100',
            'display_name' => 'required|string|max:191',
            'category'     => 'nullable|string|max:50',
            'description'  => 'nullable|string|max:1000',
            'status'       => 'nullable|string|in:connected,disconnected,error',
            'config'       => 'nullable|array',
        ];
    }

    /**
     * POST /api/lms/governance/integrations
     *
     * Records that a provider is configured. It does not perform an OAuth
     * handshake - that belongs to the provider's own flow - and it deliberately
     * accepts no token or secret field.
     */
    public function storeIntegration(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->integrationRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);
        $provider = $request->input('provider');

        $duplicate = DB::table('lms_integrations')
            ->where('sub_institute_id', $subInstituteId)
            ->where('provider', $provider)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'That provider is already configured for this institute.',
            ], 422);
        }

        try {
            $status = $request->input('status', 'disconnected');

            $id = DB::table('lms_integrations')->insertGetId([
                'sub_institute_id' => $subInstituteId,
                'provider'     => $provider,
                'display_name' => $request->input('display_name'),
                'category'     => $request->input('category'),
                'description'  => $request->input('description'),
                'status'       => $status,
                'connected_at' => $status === 'connected' ? now() : null,
                'config'       => $request->has('config')
                    ? json_encode($request->input('config'))
                    : null,
                'created_by'   => $this->contextUserId($request),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Integration added',
                'data' => DB::table('lms_integrations')->find($id),
            ], 201);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to add the integration');
        }
    }

    /** PUT /api/lms/governance/integrations/{id} */
    public function updateIntegration(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), $this->integrationRules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $subInstituteId = $this->tenantId($request);
        $integration = $this->findScoped('lms_integrations', $id, $subInstituteId);

        if (!$integration) {
            return response()->json(['status' => false, 'message' => 'Integration not found'], 404);
        }

        try {
            $status = $request->input('status', $integration->status);

            DB::table('lms_integrations')->where('id', $id)->update([
                'display_name' => $request->input('display_name'),
                'category'     => $request->input('category'),
                'description'  => $request->input('description'),
                'status'       => $status,
                // Stamp the moment it first became connected; leave it alone
                // on later edits so the original connection date survives.
                'connected_at' => $status === 'connected'
                    ? ($integration->connected_at ?: now())
                    : null,
                'last_error'   => $status === 'error' ? $request->input('last_error') : null,
                'config'       => $request->has('config')
                    ? json_encode($request->input('config'))
                    : $integration->config,
                'updated_by'   => $this->contextUserId($request),
                'updated_at'   => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Integration updated',
                'data' => DB::table('lms_integrations')->find($id),
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to update the integration');
        }
    }

    /** DELETE /api/lms/governance/integrations/{id} */
    public function destroyIntegration(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$this->findScoped('lms_integrations', $id, $subInstituteId)) {
            return response()->json(['status' => false, 'message' => 'Integration not found'], 404);
        }

        try {
            DB::table('lms_integrations')->where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->contextUserId($request),
            ]);

            return response()->json(['status' => true, 'message' => 'Integration removed']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to remove the integration');
        }
    }
}
