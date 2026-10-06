<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Organization\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Creating an organisation — the internal operator's surface.
 *
 * ── WHO REACHES THIS ────────────────────────────────────────────────────────
 *
 * `platform.owner`, which is membership in `platform_owners` and deliberately
 * not any tenant role: an administrator of tenant 6 has no more business
 * creating tenant 16 than an employee does. See RequirePlatformOwner.
 *
 * ── WHY THE VALIDATION IS LONGER THAN THE CONTROLLER ────────────────────────
 *
 * Because the constraints are real and the old endpoint enforced none of them:
 *
 *   - `tbluser.email` is UNIQUE ACROSS EVERY TENANT, not per organisation. Two
 *     companies cannot share an admin address, and finding that out as a 500 on
 *     submit is the worst possible way to learn it.
 *   - `school_setup` has no unique index on anything, so two organisations could
 *     be created with the identical name and nobody would ever be able to tell
 *     them apart in a dropdown.
 *   - The short code was derived from initials with no collision check at all -
 *     "Jainam Solutions" and "Jio Systems" both produce JS.
 *
 * Every one of those is a message a person can act on, so every one is a rule
 * here rather than a database error later.
 */
class PlatformOrganizationController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * GET /api/platform/me
     *
     * "May I create organisations?" - answered for ANY authenticated caller,
     * because the navigation has to ask before it can decide whether to show
     * the entry, and an ordinary user asking is not an error.
     *
     * This is the one endpoint in the platform group that is NOT behind
     * `platform.owner`. It has to be: that guard answers 404, which a menu
     * cannot tell apart from a network failure. So the guard protects the
     * ACTIONS and this reports the FACT.
     *
     * It is not a security boundary and does not pretend to be - hiding a menu
     * item is a courtesy. Every endpoint that does anything is still gated
     * server-side, so a caller who lies to their own browser gains nothing.
     */
    public function me(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'is_platform_owner' => \App\Http\Middleware\RequirePlatformOwner::isOwner($identity['user_id']),
            ],
        ]);
    }

    /** GET /api/platform/organizations */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        /*
         * The list exists so the operator can see what they have already created
         * and not create it twice. It is a roster, not a management console -
         * there is no edit and no delete here, because `school_setup.id` carries
         * 96 inbound foreign keys and deleting an organisation is not an
         * operation this product supports.
         */
        $organizations = DB::table('school_setup as s')
            ->leftJoin('org_details as o', 'o.sub_institute_id', '=', 's.id')
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.id')
            ->get(['s.id', 's.SchoolName', 's.ShortCode', 's.Email', 's.created_at', 's.expire_date', 'o.industry'])
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'name' => $row->SchoolName,
                    'short_code' => $row->ShortCode,
                    'email' => $row->Email,
                    'industry' => $row->industry,
                    'created_at' => $row->created_at,
                    'expires_on' => $row->expire_date,
                    // Counted, so the roster says whether an organisation ever
                    // got off the ground rather than merely that it exists.
                    'people' => DB::table('tbluser')->where('sub_institute_id', $row->id)->count(),
                    'departments' => DB::table('hrms_departments')
                        ->where('sub_institute_id', $row->id)->whereNull('deleted_at')->count(),
                ];
            });

        return response()->json([
            'status' => true,
            'data' => ['organizations' => $organizations],
        ]);
    }

    /** POST /api/platform/organizations */
    public function store(Request $request, TenantProvisioner $provisioner)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:191',
                // Not a database constraint - school_setup has no unique index -
                // so it is enforced here, where it can be a sentence.
                Rule::unique('school_setup', 'SchoolName')->whereNull('deleted_at'),
            ],
            'short_code' => ['nullable', 'string', 'max:50', Rule::unique('school_setup', 'ShortCode')],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'industry' => ['nullable', 'string', 'max:191'],
            'syear' => ['nullable', 'string', 'max:50'],

            'admin_first_name' => ['required', 'string', 'max:191'],
            'admin_last_name' => ['nullable', 'string', 'max:191'],
            // The unique rule carries the real constraint: tbluser.email is
            // unique across the whole table, every tenant included.
            'admin_email' => ['required', 'email', 'max:191', Rule::unique('tbluser', 'email')],
            'admin_mobile' => ['nullable', 'string', 'max:20'],
            'admin_password' => ['required', 'string', 'min:8', 'max:191'],
        ], [
            'name.unique' => 'An organisation with this name already exists.',
            'admin_email.unique' => 'This email address already belongs to somebody in another organisation. Every account on the platform needs its own address.',
            'short_code.unique' => 'That short code is already taken.',
        ]);

        // `nullable` keeps absent keys out of the validated array entirely, so
        // this reads through ?? rather than assuming the key is there.
        $data['short_code'] = trim((string) ($data['short_code'] ?? '')) !== ''
            ? $data['short_code']
            : $provisioner->deriveShortCode($data['name']);

        $result = $provisioner->provision($data, $identity['user_id']);

        return response()->json([
            'status' => true,
            'message' => sprintf(
                '%s is ready. %d role(s) created and %d permission(s) granted, and %s can sign in now.',
                $data['name'],
                $result['roles']['created'],
                $result['rights'],
                $data['admin_email']
            ),
            'data' => [
                'tenant_id' => $result['tenant_id'],
                'name' => $data['name'],
                'short_code' => $data['short_code'],
                'admin_user_id' => $result['admin_user_id'],
                'admin_email' => $data['admin_email'],
                // Returned so the caller never has to query for them, which is
                // exactly what the old two-call flow forced on it.
                'profiles' => $result['profiles'],
                'rights_granted' => $result['rights'],
            ],
        ], 201);
    }
}
