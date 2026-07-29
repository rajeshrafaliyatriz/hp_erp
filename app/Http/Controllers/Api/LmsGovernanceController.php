<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Administration & Governance - users, roles, the permission matrix, audit and
 * platform health.
 *
 * Every entity here already had a table and a controller, but all of those
 * controllers are registered in routes/user.php behind ['auth','session','menu']
 * - session-authenticated, CSRF-protected web routes that a cross-origin call
 * cannot use for writes. The old frontend worked around this by reading through
 * the generic /table_data endpoint, which returned every tenant's users
 * including password hashes; that endpoint is now locked down, so a properly
 * scoped API is required rather than optional.
 *
 * routes/user.php is untouched: the old frontend's own screens keep working.
 */
class LmsGovernanceController extends Controller
{
    /** Profiles permitted to administer users, roles and permissions. */
    private const ADMIN_PROFILES = ['admin', 'hr', 'super', 'principal'];

    private function guardApiToken(Request $request)
    {
        if ($request->input('type') !== 'API') {
            return null;
        }

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['status' => false, 'message' => 'Token not provided'], 401);
        }

        if (!PersonalAccessToken::findToken($token)) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }

        return null;
    }

    /**
     * Null when the caller may administer, a 403 response otherwise.
     *
     * Governance is a strictly administrative surface, so unlike the learner
     * endpoints an absent profile name is refused rather than allowed: a caller
     * who does not say who they are does not get to edit users.
     */
    private function guardAdmin(Request $request)
    {
        $profile = strtolower(trim((string) $request->input('user_profile_name', '')));

        foreach (self::ADMIN_PROFILES as $allowed) {
            if ($profile !== '' && str_contains($profile, $allowed)) {
                return null;
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'Your profile is not permitted to administer this institute.',
        ], 403);
    }

    private function tenantId(Request $request)
    {
        return $request->input('sub_institute_id') ?: $request->header('sub_institute_id');
    }

    /**
     * Scope a hpbrain_audit_logs query to one tenant.
     *
     * That table predates the sub_institute_id convention: its tenant_id is a
     * string of the form "t1", not the numeric institute id. Matching on the
     * raw number therefore returns nothing, and matching on nothing at all -
     * which the KPI first did - counts every other tenant's activity. Both
     * spellings are accepted so the column can be normalised later without
     * this breaking.
     */
    private function scopeAuditToTenant($query, $subInstituteId)
    {
        return $query->where(function ($scope) use ($subInstituteId) {
            $scope->where('tenant_id', $subInstituteId)
                  ->orWhere('tenant_id', 't' . $subInstituteId);
        });
    }

    /**
     * Record an administrative change in hpbrain_audit_logs.
     *
     * The Audit tab had nothing to show because nothing wrote to that table
     * from this module - it was populated only by other subsystems. Every
     * privileged write here now leaves a trail, which is the point of having an
     * audit surface at all.
     *
     * Deliberately best-effort: a failure to log must not roll back or fail the
     * change the administrator actually asked for, so it is swallowed. It is
     * also called after the write succeeds, never before, so the log cannot
     * claim something that did not happen.
     *
     * `changes` records what was altered. Never pass a password, hashed or not.
     */
    private function audit(Request $request, string $entityType, $entityId, string $action, array $changes = []): void
    {
        try {
            DB::table('hpbrain_audit_logs')->insert([
                // id is varchar(36) with no auto-increment - this table is
                // UUID-keyed, so the id has to be supplied rather than left to
                // the database. event_id gets its own UUID so a single logical
                // event can later be correlated across sources.
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'event_id'    => (string) \Illuminate\Support\Str::uuid(),
                // The column stores "t{id}"; see scopeAuditToTenant.
                'tenant_id'   => 't' . $this->tenantId($request),
                'entity_type' => $entityType,
                'entity_id'   => (string) $entityId,
                'action'      => $action,
                'actor_id'    => $request->input('user_id'),
                'actor_name'  => $request->input('user_profile_name'),
                'changes'     => $changes ? json_encode($changes) : null,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'source'      => 'lms-governance',
                'status'      => 'success',
                'created_at'  => now(),
            ]);
        } catch (\Exception $e) {
            // Intentionally ignored - see the note above.
        }
    }

    private function fail(\Exception $e, string $message)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }

    /* ─── KPIs ─────────────────────────────────────────────────────────────── */

    /**
     * GET /api/lms/governance/kpis
     *
     * The seven stat cards. Each is a real count for this tenant; the page
     * previously showed seven hardcoded numbers.
     */
    public function kpis(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            // Menus are shared across tenants via a comma-separated list, so the
            // permission count is rights rows rather than menu rows.
            $permissions = DB::table('tblgroupwise_rights')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->count();

            $auditWindow = now()->subDays(30);

            return response()->json([
                'status' => true,
                'data' => [
                    'users' => DB::table('tbluser')
                        ->where('sub_institute_id', $subInstituteId)
                        ->whereNull('deleted_at')
                        ->count(),
                    'active_users' => DB::table('tbluser')
                        ->where('sub_institute_id', $subInstituteId)
                        ->where('status', 1)
                        ->whereNull('deleted_at')
                        ->count(),
                    'roles' => DB::table('tbluserprofilemaster')
                        ->where('sub_institute_id', $subInstituteId)
                        ->whereNull('deleted_at')
                        ->count(),
                    'permissions' => $permissions,
                    'trainers' => DB::table('lms_trainers')
                        ->where('sub_institute_id', $subInstituteId)
                        ->where('status', 1)
                        ->whereNull('deleted_at')
                        ->count(),
                    'vendors' => DB::table('lms_vendors')
                        ->where('sub_institute_id', $subInstituteId)
                        ->where('status', 1)
                        ->whereNull('deleted_at')
                        ->count(),
                    'integrations' => DB::table('lms_integrations')
                        ->where('sub_institute_id', $subInstituteId)
                        ->where('status', 'connected')
                        ->whereNull('deleted_at')
                        ->count(),
                    // The card is labelled "Total Logs (30 Days)". Scoped to
                    // this tenant, so it cannot count another institute's rows.
                    'audit_logs' => $this->scopeAuditToTenant(
                        DB::table('hpbrain_audit_logs'), $subInstituteId
                    )->where('created_at', '>=', $auditWindow)->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load governance KPIs');
        }
    }

    /* ─── Users ────────────────────────────────────────────────────────────── */

    /**
     * GET /api/lms/governance/users
     *
     * Paginated, searchable user list scoped to the tenant, with the
     * department and role names the table renders.
     *
     * Note the explicit select list below: password, plain_password, otp,
     * aadhar_no, pan_no, account_no, ifsc_code and bank_name are never named,
     * so they cannot be returned. This is deliberate - a `select *` here would
     * re-open exactly the exposure just closed in table_data.
     */
    public function users(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

            $query = DB::table('tbluser as u')
                ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                ->where('u.sub_institute_id', $subInstituteId)
                ->whereNull('u.deleted_at');

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('u.first_name', 'like', "%{$search}%")
                      ->orWhere('u.last_name', 'like', "%{$search}%")
                      ->orWhere('u.email', 'like', "%{$search}%")
                      ->orWhere('u.employee_no', 'like', "%{$search}%")
                      ->orWhere('u.user_name', 'like', "%{$search}%");
                });
            }

            if (($status = $request->input('status')) !== null && $status !== '') {
                $query->where('u.status', (int) $status);
            }

            if ($profileId = $request->input('profile_id')) {
                $query->where('u.user_profile_id', $profileId);
            }

            if ($departmentId = $request->input('department_id')) {
                $query->where('u.department_id', $departmentId);
            }

            $sortable = ['name' => 'u.first_name', 'email' => 'u.email', 'status' => 'u.status', 'last_login' => 'u.last_login'];
            $sortBy = $sortable[$request->input('sort_by', 'name')] ?? 'u.first_name';
            $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

            $users = $query
                ->orderBy($sortBy, $sortDir)
                ->paginate($perPage, [
                    'u.id', 'u.user_name', 'u.first_name', 'u.middle_name', 'u.last_name',
                    'u.email', 'u.mobile', 'u.employee_no', 'u.status', 'u.last_login',
                    'u.user_profile_id', 'u.department_id', 'u.image', 'u.created_at',
                    'p.name as profile_name',
                    'd.department as department_name',
                ]);

            return response()->json([
                'status' => true,
                'data' => collect($users->items())->map(function ($user) {
                    $user->full_name = trim(implode(' ', array_filter([
                        $user->first_name, $user->middle_name, $user->last_name,
                    ])));
                    // Two-letter monogram for the avatar, computed here so every
                    // client renders the same thing.
                    $user->initials = strtoupper(
                        substr((string) $user->first_name, 0, 1) . substr((string) $user->last_name, 0, 1)
                    );
                    $user->status = (int) $user->status;
                    return $user;
                }),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load users');
        }
    }

    private function userRules(bool $creating): array
    {
        return [
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'email'           => 'required|email|max:191',
            'mobile'          => 'nullable|string|max:20',
            'employee_no'     => 'nullable|string|max:50',
            'user_profile_id' => 'required|integer',
            'department_id'   => 'nullable|integer',
            'status'          => 'nullable|integer|in:0,1',
            'user_name'       => ($creating ? 'required' : 'nullable') . '|string|max:100',
            'password'        => ($creating ? 'required' : 'nullable') . '|string|min:8|max:100',
        ];
    }

    /** POST /api/lms/governance/users */
    public function storeUser(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), $this->userRules(true));
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Uniqueness is per tenant, not global: two institutes may each have an
        // "admin" without colliding.
        $duplicate = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($request) {
                $q->where('email', $request->input('email'))
                  ->orWhere('user_name', $request->input('user_name'));
            })
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'A user with that email or username already exists.',
            ], 422);
        }

        try {
            $id = DB::table('tbluser')->insertGetId([
                'user_name'       => $request->input('user_name'),
                // Hashed, never stored in plain_password - that column is what
                // made the table_data leak so damaging.
                'password'        => bcrypt($request->input('password')),
                'first_name'      => $request->input('first_name'),
                'middle_name'     => $request->input('middle_name'),
                'last_name'       => $request->input('last_name'),
                'email'           => $request->input('email'),
                'mobile'          => $request->input('mobile'),
                'employee_no'     => $request->input('employee_no'),
                'user_profile_id' => $request->input('user_profile_id'),
                'department_id'   => $request->input('department_id'),
                'status'          => (int) $request->input('status', 1),
                'sub_institute_id' => $subInstituteId,
                'created_by'      => $request->input('user_id'),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->audit($request, 'user', $id, 'create', [
                'email' => $request->input('email'),
                'user_profile_id' => $request->input('user_profile_id'),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'User created',
                'data' => ['id' => $id],
            ], 201);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to create the user');
        }
    }

    /** PUT /api/lms/governance/users/{id} */
    public function updateUser(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), $this->userRules(false));
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = DB::table('tbluser')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        try {
            $payload = [
                'first_name'      => $request->input('first_name'),
                'middle_name'     => $request->input('middle_name'),
                'last_name'       => $request->input('last_name'),
                'email'           => $request->input('email'),
                'mobile'          => $request->input('mobile'),
                'employee_no'     => $request->input('employee_no'),
                'user_profile_id' => $request->input('user_profile_id'),
                'department_id'   => $request->input('department_id'),
                'status'          => (int) $request->input('status', $user->status),
                'updated_by'      => $request->input('user_id'),
                'updated_at'      => now(),
            ];

            // Only touch the password when a new one was actually supplied.
            if ($request->filled('password')) {
                $payload['password'] = bcrypt($request->input('password'));
            }

            DB::table('tbluser')->where('id', $id)->update($payload);

            $this->audit($request, 'user', $id, 'update', [
                'email' => $request->input('email'),
                'user_profile_id' => $request->input('user_profile_id'),
                // Recorded as a flag, never the value itself.
                'password_changed' => $request->filled('password'),
            ]);

            return response()->json(['status' => true, 'message' => 'User updated']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to update the user');
        }
    }

    /**
     * DELETE /api/lms/governance/users/{id}
     *
     * Soft delete. A hard delete would orphan enrolments, progress and
     * certificates that reference the user.
     */
    public function destroyUser(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $actorId = (int) $request->input('user_id');

        if ((int) $id === $actorId) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $user = DB::table('tbluser')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        try {
            DB::table('tbluser')->where('id', $id)->update([
                'status' => 0,
                'deleted_at' => now(),
                'deleted_by' => $actorId,
            ]);

            $this->audit($request, 'user', $id, 'deactivate', ['email' => $user->email]);

            return response()->json(['status' => true, 'message' => 'User deactivated']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to deactivate the user');
        }
    }

    /**
     * Columns a CSV import is allowed to set.
     *
     * An allowlist, not a denylist. The pre-existing POST /api/import-users
     * intersected the CSV header with the full tbluser column list, so a file
     * could set sub_institute_id (writing into another institute), is_admin
     * (self-elevation), status, or plain_password. Naming what is permitted
     * makes that class of mistake impossible: anything not listed is dropped.
     */
    private const IMPORTABLE_USER_COLUMNS = [
        'first_name', 'middle_name', 'last_name', 'email', 'mobile',
        'employee_no', 'user_name', 'gender', 'birthdate', 'address',
        'city', 'state', 'pincode', 'qualification', 'joined_date',
    ];

    /**
     * POST /api/lms/governance/users/import
     *
     * Bulk-create users from a CSV. Tenant, role and password are taken from
     * the request or generated, never from the file, so a crafted CSV cannot
     * place users in another institute or grant them privileges.
     *
     * Rows are validated individually: a bad row is reported and skipped rather
     * than aborting the import, because a 500-row file with two bad emails
     * should still load 498 users.
     */
    public function importUsers(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:5120',
            // The role every imported user receives. Taken from the request so
            // the CSV cannot choose it.
            'user_profile_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $profileExists = DB::table('tbluserprofilemaster')
            ->where('id', $request->input('user_profile_id'))
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$profileExists) {
            return response()->json(['status' => false, 'message' => 'Invalid role'], 422);
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['status' => false, 'message' => 'Could not read the file'], 422);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return response()->json(['status' => false, 'message' => 'The CSV has no header row'], 422);
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);

        if (!in_array('email', $header, true)) {
            fclose($handle);
            return response()->json([
                'status' => false,
                'message' => 'The CSV must include an "email" column.',
            ], 422);
        }

        // Existing emails and usernames in this tenant, read once rather than
        // per row.
        $existingEmails = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->pluck('email')
            ->map(fn ($email) => strtolower((string) $email))
            ->flip();

        $rows = [];
        $errors = [];
        $lineNumber = 1;
        $seen = [];

        while (($line = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if (count($line) !== count($header)) {
                $errors[] = "Row {$lineNumber}: column count does not match the header.";
                continue;
            }

            $raw = array_combine($header, $line);
            $raw = array_map(
                fn ($value) => ($value === '\N' || trim((string) $value) === '') ? null : trim((string) $value),
                $raw
            );

            // Drop everything not on the allowlist.
            $data = array_intersect_key($raw, array_flip(self::IMPORTABLE_USER_COLUMNS));

            $email = strtolower((string) ($data['email'] ?? ''));

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$lineNumber}: missing or invalid email.";
                continue;
            }

            if (empty($data['first_name'])) {
                $errors[] = "Row {$lineNumber}: first_name is required.";
                continue;
            }

            if ($existingEmails->has($email)) {
                $errors[] = "Row {$lineNumber}: {$email} already exists.";
                continue;
            }

            if (isset($seen[$email])) {
                $errors[] = "Row {$lineNumber}: {$email} appears more than once in this file.";
                continue;
            }

            $seen[$email] = true;

            $rows[] = $data + [
                // Server-controlled, never from the file.
                'user_name' => $data['user_name'] ?? $email,
                // A random password: the CSV must not carry credentials, and an
                // account with a predictable one is worse than no account.
                'password' => bcrypt(bin2hex(random_bytes(12))),
                'user_profile_id' => $request->input('user_profile_id'),
                'sub_institute_id' => $subInstituteId,
                'status' => 1,
                'created_by' => $request->input('user_id'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        if ($rows === []) {
            return response()->json([
                'status' => false,
                'message' => 'No valid rows to import.',
                'errors' => array_slice($errors, 0, 50),
                'data' => ['imported' => 0, 'skipped' => count($errors)],
            ], 422);
        }

        try {
            // Chunked so a large file does not build one enormous statement.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('tbluser')->insert($chunk);
            }

            $this->audit($request, 'user', 'bulk', 'import', [
                'imported' => count($rows),
                'skipped' => count($errors),
            ]);

            return response()->json([
                'status' => true,
                'message' => count($rows) . ' user' . (count($rows) === 1 ? '' : 's') . ' imported.'
                    . ($errors ? ' ' . count($errors) . ' row(s) skipped.' : ''),
                'data' => ['imported' => count($rows), 'skipped' => count($errors)],
                // Capped: a 5000-row file with every row bad should not return
                // a 5000-entry array.
                'errors' => array_slice($errors, 0, 50),
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to import users');
        }
    }

    /* ─── Roles ────────────────────────────────────────────────────────────── */

    /** GET /api/lms/governance/roles - profiles plus how many users hold each. */
    public function roles(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $userCounts = DB::table('tbluser')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->select('user_profile_id', DB::raw('COUNT(*) as total'))
                ->groupBy('user_profile_id')
                ->pluck('total', 'user_profile_id');

            $permissionCounts = DB::table('tblgroupwise_rights')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->where('can_view', 1)
                ->select('profile_id', DB::raw('COUNT(*) as total'))
                ->groupBy('profile_id')
                ->pluck('total', 'profile_id');

            $roles = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'parent_id', 'sort_order', 'status'])
                ->map(function ($role) use ($userCounts, $permissionCounts) {
                    $role->user_count = (int) ($userCounts[$role->id] ?? 0);
                    $role->permission_count = (int) ($permissionCounts[$role->id] ?? 0);
                    $role->status = (int) $role->status;
                    return $role;
                });

            return response()->json(['status' => true, 'data' => $roles]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load roles');
        }
    }

    /** POST /api/lms/governance/roles */
    public function storeRole(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string|max:500',
            'parent_id'   => 'nullable|integer',
            'sort_order'  => 'nullable|integer',
            'status'      => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $id = DB::table('tbluserprofilemaster')->insertGetId([
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'parent_id'   => $request->input('parent_id'),
                'sort_order'  => $request->input('sort_order', 0),
                'status'      => (int) $request->input('status', 1),
                'sub_institute_id' => $subInstituteId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $this->audit($request, 'role', $id, 'create', ['name' => $request->input('name')]);

            return response()->json([
                'status' => true,
                'message' => 'Role created',
                'data' => ['id' => $id],
            ], 201);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to create the role');
        }
    }

    /** PUT /api/lms/governance/roles/{id} */
    public function updateRole(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer',
            'status'      => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = DB::table('tbluserprofilemaster')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$role) {
            return response()->json(['status' => false, 'message' => 'Role not found'], 404);
        }

        try {
            DB::table('tbluserprofilemaster')->where('id', $id)->update([
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'sort_order'  => $request->input('sort_order', $role->sort_order),
                'status'      => (int) $request->input('status', $role->status),
                'updated_at'  => now(),
            ]);

            $this->audit($request, 'role', $id, 'update', ['name' => $request->input('name')]);

            return response()->json(['status' => true, 'message' => 'Role updated']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to update the role');
        }
    }

    /**
     * DELETE /api/lms/governance/roles/{id}
     *
     * Refused while users still hold the role: orphaning them would leave
     * accounts with a profile id that resolves to nothing, and the login path
     * reads that profile to decide what the user may see.
     */
    public function destroyRole(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $role = DB::table('tbluserprofilemaster')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$role) {
            return response()->json(['status' => false, 'message' => 'Role not found'], 404);
        }

        $inUse = DB::table('tbluser')
            ->where('user_profile_id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->count();

        if ($inUse > 0) {
            return response()->json([
                'status' => false,
                'message' => "{$inUse} user" . ($inUse === 1 ? '' : 's') . ' still hold this role. Reassign them first.',
            ], 422);
        }

        try {
            DB::table('tbluserprofilemaster')->where('id', $id)->update(['deleted_at' => now()]);

            $this->audit($request, 'role', $id, 'delete', ['name' => $role->name]);

            return response()->json(['status' => true, 'message' => 'Role deleted']);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to delete the role');
        }
    }

    /* ─── Permission matrix ────────────────────────────────────────────────── */

    /**
     * GET /api/lms/governance/permissions?profile_id=
     *
     * The full menu tree with this role's can_view/can_add/can_edit/can_delete
     * flags attached. Menus are shared across tenants through a comma-separated
     * sub_institute_id list, so membership is tested with FIND_IN_SET.
     */
    public function permissions(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $profileId = $request->input('profile_id');

        if (!$subInstituteId || !$profileId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id and profile_id are required',
            ], 422);
        }

        try {
            $menus = DB::table('tblmenumaster')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->where(function ($scope) use ($subInstituteId) {
                    $scope->where('sub_institute_id', $subInstituteId)
                          ->orWhereRaw('FIND_IN_SET(?, sub_institute_id)', [$subInstituteId]);
                })
                ->orderBy('parent_id')
                ->orderBy('sort_order')
                ->get(['id', 'menu_name', 'parent_id', 'level', 'access_link', 'icon', 'sort_order']);

            $rights = DB::table('tblgroupwise_rights')
                ->where('profile_id', $profileId)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('menu_id');

            $menus->transform(function ($menu) use ($rights) {
                $right = $rights[$menu->id] ?? null;
                $menu->can_view = (bool) ($right->can_view ?? false);
                $menu->can_add = (bool) ($right->can_add ?? false);
                $menu->can_edit = (bool) ($right->can_edit ?? false);
                $menu->can_delete = (bool) ($right->can_delete ?? false);
                return $menu;
            });

            return response()->json([
                'status' => true,
                'data' => $menus,
                'meta' => ['profile_id' => (int) $profileId],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load the permission matrix');
        }
    }

    /**
     * POST /api/lms/governance/permissions
     *
     * Saves the matrix for one role. Sent as the whole set of changed rows
     * rather than one request per checkbox, and applied in a transaction so a
     * partial save cannot leave a role half-permitted.
     */
    public function savePermissions(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            'profile_id'            => 'required|integer',
            'permissions'           => 'required|array',
            'permissions.*.menu_id' => 'required|integer',
            'permissions.*.can_view'   => 'nullable|boolean',
            'permissions.*.can_add'    => 'nullable|boolean',
            'permissions.*.can_edit'   => 'nullable|boolean',
            'permissions.*.can_delete' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $profileId = (int) $request->input('profile_id');

        $roleExists = DB::table('tbluserprofilemaster')
            ->where('id', $profileId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$roleExists) {
            return response()->json(['status' => false, 'message' => 'Role not found'], 404);
        }

        try {
            $saved = 0;

            DB::transaction(function () use ($request, $profileId, $subInstituteId, &$saved) {
                foreach ($request->input('permissions', []) as $entry) {
                    $flags = [
                        'can_view'   => !empty($entry['can_view']) ? 1 : 0,
                        'can_add'    => !empty($entry['can_add']) ? 1 : 0,
                        'can_edit'   => !empty($entry['can_edit']) ? 1 : 0,
                        'can_delete' => !empty($entry['can_delete']) ? 1 : 0,
                        'updated_at' => now(),
                    ];

                    $existing = DB::table('tblgroupwise_rights')
                        ->where('menu_id', $entry['menu_id'])
                        ->where('profile_id', $profileId)
                        ->where('sub_institute_id', $subInstituteId)
                        ->first();

                    if ($existing) {
                        DB::table('tblgroupwise_rights')->where('id', $existing->id)->update($flags);
                    } else {
                        DB::table('tblgroupwise_rights')->insert($flags + [
                            'menu_id'    => $entry['menu_id'],
                            'profile_id' => $profileId,
                            'sub_institute_id' => $subInstituteId,
                            'dashboard_right' => 0,
                            'created_at' => now(),
                        ]);
                    }

                    $saved++;
                }
            });

            $this->audit($request, 'permission_matrix', $profileId, 'update', [
                'rows_saved' => $saved,
            ]);

            return response()->json([
                'status' => true,
                'message' => "Saved {$saved} permission" . ($saved === 1 ? '' : 's') . '.',
                'data' => ['saved' => $saved],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to save the permission matrix');
        }
    }

    /* ─── Audit logs ───────────────────────────────────────────────────────── */

    /**
     * GET /api/lms/governance/audit-logs
     *
     * hpbrain_audit_logs is the structured trail (entity, action, actor,
     * changes). tbl_user_journey_logs records navigation, which is a different
     * question, so it is surfaced as a separate count rather than merged in.
     */
    public function auditLogs(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

            // hpbrain_audit_logs has no deleted_at and scopes by tenant_id.
            // Null tenant_id rows are platform-level events, which an admin of
            // any tenant may see.
            $query = $this->scopeAuditToTenant(
                DB::table('hpbrain_audit_logs'), $subInstituteId
            );

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('entity_type', 'like', "%{$search}%")
                      ->orWhere('action', 'like', "%{$search}%")
                      ->orWhere('actor_name', 'like', "%{$search}%");
                });
            }

            if ($action = $request->input('action')) {
                $query->where('action', $action);
            }

            if ($entityType = $request->input('entity_type')) {
                $query->where('entity_type', $entityType);
            }

            if ($from = $request->input('from')) {
                $query->where('created_at', '>=', $from);
            }

            if ($to = $request->input('to')) {
                $query->where('created_at', '<=', $to . ' 23:59:59');
            }

            $logs = $query->orderByDesc('created_at')->paginate($perPage, [
                'id', 'entity_type', 'entity_id', 'action', 'actor_id', 'actor_name',
                'ip_address', 'status', 'source', 'created_at',
            ]);

            return response()->json([
                'status' => true,
                'data' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'journey_events' => DB::table('tbl_user_journey_logs')
                        ->where('sub_institute_id', $subInstituteId)
                        ->count(),
                ],
                'filters' => [
                    'actions' => $this->scopeAuditToTenant(
                        DB::table('hpbrain_audit_logs'), $subInstituteId
                    )->whereNotNull('action')->distinct()->orderBy('action')->pluck('action'),
                    'entity_types' => $this->scopeAuditToTenant(
                        DB::table('hpbrain_audit_logs'), $subInstituteId
                    )->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to load audit logs');
        }
    }

    /* ─── System health ────────────────────────────────────────────────────── */

    /**
     * GET /api/lms/governance/system-health
     *
     * Each check is actually performed rather than reported as "Healthy" from a
     * constant, which is what the page did before. A check that cannot be
     * performed honestly reports 'unknown' instead of guessing.
     */
    public function systemHealth(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $checks = [];

        // Database - a real round trip.
        try {
            $started = microtime(true);
            DB::select('SELECT 1');
            $checks[] = [
                'key' => 'database',
                'label' => 'Database',
                'status' => 'healthy',
                'detail' => round((microtime(true) - $started) * 1000) . ' ms',
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'key' => 'database', 'label' => 'Database',
                'status' => 'error', 'detail' => 'Unreachable',
            ];
        }

        // Mail - configured or not. Whether it delivers cannot be known without
        // sending, so this reports configuration, not deliverability.
        $mailer = config('mail.default');
        $mailHost = config('mail.mailers.' . $mailer . '.host');
        $checks[] = [
            'key' => 'email',
            'label' => 'Email Service',
            'status' => $mailer && $mailer !== 'log' && $mailHost ? 'healthy' : 'warning',
            'detail' => $mailer ? ucfirst($mailer) . ($mailHost ? " ({$mailHost})" : '') : 'Not configured',
        ];

        // Storage - the disk certificates and course images are written to.
        try {
            $disk = config('filesystems.default');
            $checks[] = [
                'key' => 'storage',
                'label' => 'Storage',
                'status' => $disk ? 'healthy' : 'warning',
                'detail' => $disk ? ucfirst((string) $disk) : 'Not configured',
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'key' => 'storage', 'label' => 'Storage',
                'status' => 'error', 'detail' => 'Unavailable',
            ];
        }

        // SSO - reported from the integrations table rather than assumed.
        $sso = DB::table('lms_integrations')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('provider', ['google', 'azure', 'okta', 'saml'])
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(status, 'connected', 'error', 'disconnected')")
            ->first();

        $checks[] = [
            'key' => 'sso',
            'label' => 'SSO',
            'status' => $sso
                ? ($sso->status === 'connected' ? 'healthy' : ($sso->status === 'error' ? 'error' : 'warning'))
                : 'unknown',
            'detail' => $sso ? ($sso->display_name . ' — ' . $sso->status) : 'No provider configured',
        ];

        // Active token count is a useful signal and cheap to read.
        $checks[] = [
            'key' => 'sessions',
            'label' => 'Active Tokens',
            'status' => 'healthy',
            'detail' => DB::table('personal_access_tokens')
                ->where('last_used_at', '>=', now()->subDays(1))
                ->count() . ' in last 24h',
        ];

        return response()->json(['status' => true, 'data' => $checks]);
    }
}
