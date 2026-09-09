<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Organization\TenantSettings;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ROLES & ACCESS — what each role starts on, and what it is allowed to see.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS NOT THE PERMISSIONS MATRIX, AND IT SAYS SO
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * WHICH SCREENS a role can reach is `tblmenumaster_g2g` rights, edited in Role &
 * Permissions, and that screen already works. Duplicating it here would give the
 * product two places to change the same thing and no rule about which wins.
 *
 * What has never had a home is everything ELSE about a role: where it lands
 * after signing in, whether it is emailed by default, and what its `data_scope`
 * is. This is that home, and it links to the matrix rather than reproducing it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * data_scope IS SHOWN, SETTABLE, AND NOT ENFORCED — DELIBERATELY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The column exists on `tbluserprofilemaster` as
 * `enum('self','team','department','organization')`, `StandardRoles` writes it
 * for every provisioned role, and A REPO-WIDE SEARCH FINDS NO READER. Two
 * controllers carry comments saying as much:
 * *"a column nobody reads is not a property"*.
 *
 * The decision taken was to expose it as configuration ONLY, and the API is what
 * makes that honest: `data_scope_enforced` is returned as a hard `false` beside
 * the value itself, so the screen states it in plain words rather than letting
 * an administrator believe they have restricted anybody. Hiding the column would
 * be the other reasonable choice; showing it while implying it does something
 * would not be.
 *
 * ── WHY THE VALUE IS CHECKED AGAINST A PHP LIST AND NOT THE COLUMN ──────────
 *
 * `data_scope` is an ENUM, and live is MariaDB 10.1. An out-of-range value is
 * silently coerced to '' under a non-strict SQL mode rather than rejected, so
 * the guard has to be in front of the write, not behind it.
 */
class RoleSettingsController extends Controller
{
    use ResolvesApiIdentity;

    public const DATA_SCOPES = ['self', 'team', 'department', 'organization'];

    /** Plain-English, because "organization scope" is not a sentence. */
    private const SCOPE_MEANING = [
        'self' => 'Their own records only',
        'team' => 'Their own, and the people who report to them',
        'department' => 'Everybody in their department',
        'organization' => 'Everybody in the organisation',
    ];

    /** GET /api/organization/roles */
    public function index(Request $request, TenantSettings $settings)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        $profiles = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'description', 'role_key', 'data_scope', 'is_system']);

        // One grouped count rather than a query per role.
        $counts = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->selectRaw('user_profile_id, COUNT(*) as n')
            ->groupBy('user_profile_id')
            ->pluck('n', 'user_profile_id');

        /*
         * How many menus each role can actually reach.
         *
         * This is the number that says whether a role is usable at all, and it
         * comes from `tblgroupwise_rights_g2g` - the same rows
         * `ModuleEnablementController` reads and the sidebar is built from - so
         * it cannot disagree with what somebody in that role sees.
         *
         * NOT scoped by tenant here, and that is correct rather than an
         * oversight: rights are keyed on `profile_id`, and the profiles were
         * already filtered to this organisation above. The table's own
         * `sub_institute_id` is inconsistently populated on older rows, so
         * filtering on it would silently under-count long-standing roles.
         */
        $rights = DB::table('tblgroupwise_rights_g2g')
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->where('can_view', 1)
            ->selectRaw('profile_id, COUNT(DISTINCT menu_id) as n')
            ->groupBy('profile_id')
            ->pluck('n', 'profile_id');

        $roles = $profiles
            // Privilege order, so the list reads from least to most access
            // rather than by whatever id the rows happened to get.
            ->sortBy(fn ($p) => array_search($p->role_key, RoleKey::ALL, true) === false
                ? 99
                : array_search($p->role_key, RoleKey::ALL, true))
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'role_key' => $p->role_key,
                'is_system' => (bool) $p->is_system,
                'user_count' => (int) ($counts[$p->id] ?? 0),
                'menu_count' => (int) ($rights[$p->id] ?? 0),
                'data_scope' => $p->data_scope,
                'data_scope_meaning' => self::SCOPE_MEANING[$p->data_scope] ?? null,
                'settings' => $p->role_key ? $settings->forRole($tenantId, $p->role_key) : null,
            ])
            ->values();

        return response()->json([
            'status' => 1,
            'data' => [
                'roles' => $roles,
                'choices' => [
                    'data_scope' => collect(self::DATA_SCOPES)
                        ->map(fn ($s) => ['value' => $s, 'label' => self::SCOPE_MEANING[$s]])
                        ->all(),
                    'landing_page' => ['dashboard', 'last-visited'],
                ],
                /*
                 * A HARD false. Not a config lookup, not a feature flag - a
                 * stated fact about this product, returned so the screen can say
                 * it out loud instead of implying otherwise by offering the
                 * control at all.
                 */
                'data_scope_enforced' => false,
                'landing_page_enforced' => false,
            ],
        ]);
    }

    /** PUT /api/organization/roles/{id} */
    public function update(Request $request, TenantSettings $settings, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        // Tenant-scoped BEFORE anything is read from the request, so an id from
        // another organisation is a 404 rather than a 403 - the same rule every
        // other tenant-scoped endpoint here follows.
        $profile = DB::table('tbluserprofilemaster')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'role_key', 'data_scope']);

        if (!$profile) {
            return response()->json(['status' => 0, 'message' => 'Role not found.'], 404);
        }

        $data = $request->validate([
            'data_scope' => ['sometimes', Rule::in(self::DATA_SCOPES)],
            'landing_page' => ['sometimes', Rule::in(['dashboard', 'last-visited'])],
            'notify_email' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('data_scope', $data)) {
            DB::table('tbluserprofilemaster')
                ->where('id', $profile->id)
                ->update(['data_scope' => $data['data_scope'], 'updated_at' => now()]);
        }

        /*
         * The role-scoped settings need a `role_key`, and thirteen legacy
         * profiles predate that column. Theirs are skipped rather than stored
         * under a null key, which would collide across every unmapped profile in
         * the organisation.
         */
        if ($profile->role_key) {
            $roleValues = [];

            if (array_key_exists('landing_page', $data)) {
                $roleValues['landing_page'] = $data['landing_page'];
            }

            if (array_key_exists('notify_email', $data)) {
                $roleValues['notify_email'] = filter_var($data['notify_email'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            if ($roleValues !== []) {
                $settings->saveForRole($tenantId, $profile->role_key, $roleValues);
            }
        }

        return $this->index($request, $settings);
    }
}
