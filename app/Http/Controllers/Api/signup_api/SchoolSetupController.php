<?php

namespace App\Http\Controllers\Api\signup_api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\school_setupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\tbluserprofileMasterModel;
use App\Models\user\tblgroupwise_rightsModel;
use App\Models\user\tblgroupwise_rights_g2gModel;
use App\Models\tblmenumaster_g2gModel;

class SchoolSetupController extends Controller
{
    /**
     * The three profiles every organisation starts with.
     *
     * `role_key` uses RequireProfile::ALIASES' vocabulary - 'administrator',
     * 'hr_manager', 'employee' - because that is what authorization matches on.
     * The display NAME is the tenant's to change; the key is not.
     */
    private const DEFAULT_PROFILES = [
        ['name' => 'Admin',    'role_key' => 'administrator', 'sort_order' => 1],
        ['name' => 'Employee', 'role_key' => 'employee',      'sort_order' => 2],
        ['name' => 'HR',       'role_key' => 'hr_manager',    'sort_order' => 3],
    ];

    /**
     * Display name -> role_key, for profiles that predate role_key.
     *
     * Used ONLY to read the rights template (tenant 3), whose profiles still
     * have NULL role_key on live. Mirrors RequireProfile::LEGACY_NAMES, plus
     * 'employee' - which that map omits, and whose omission is why a new
     * tenant's Employee profile resolved to no role at all.
     */
    private const CANONICAL_PROFILE_NAMES = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
        'employee'                   => 'employee',
    ];

    public function store(Request $request)
    {
        // ✅ Validation
        $validatedData = $request->validate([
            'SchoolName' => 'required|string|max:255',
            'ShortCode' => 'nullable|string|max:50',
            'ContactPerson' => 'nullable|string|max:255',
            'Mobile' => 'nullable|string|max:20',
            'Email' => 'nullable|email|max:255',
            'syear' => 'nullable|string|max:50',
            'institute_type' => 'nullable|string|max:100',
            'sub_institute_id' => 'nullable|integer',
            'industries_type' => 'nullable|string|max:100',
        ]);

        // ✅ Auto ShortCode
        if (empty($validatedData['ShortCode'])) {
            $words = preg_split('/[\s\-_]+/', $validatedData['SchoolName']);
            $shortCode = '';

            foreach ($words as $word) {
                if (strlen($shortCode) >= 5) break;
                $shortCode .= strtoupper(substr($word, 0, 1));
            }

            if (empty($shortCode)) {
                $shortCode = strtoupper(substr($validatedData['SchoolName'], 0, 5));
            }

            $validatedData['ShortCode'] = $shortCode;
        }

        // ✅ Timestamp
        $validatedData['created_at'] = now();

        // ✅ Create Client
        $client = tblclientModel::create([
            'client_name' => $validatedData['SchoolName'],
            'created_at' => now(),
        ]);

        // ✅ Assign client_id
        $validatedData['client_id'] = $client->id;

        // ✅ Create School Setup
        $schoolSetup = school_setupModel::create($validatedData);

        // ✅ Expire Date (+1 Year)
        $schoolSetup->expire_date = now()->addYear();
        $schoolSetup->save();

        // ✅ Create Default Profiles
        //
        // role_key IS SET HERE, and that is the change. Every profile this
        // endpoint has ever created left it NULL, so authorization fell back to
        // RequireProfile::LEGACY_NAMES - an exact match on the DISPLAY NAME,
        // which a tenant can edit. Two consequences, both silent:
        //
        //   - rename "HR" and that profile stops passing `profile:admin,hr`;
        //   - "Employee" is not in LEGACY_NAMES at all, so it resolved to no
        //     role and was refused on every employee-gated write.
        //
        // The keys are RequireProfile::ALIASES' vocabulary, not free text.
        $profileIds = [];

        foreach (self::DEFAULT_PROFILES as $profile) {
            $newProfile = tbluserprofileMasterModel::create([
                'name' => $profile['name'],
                'description' => $profile['name'],
                'role_key' => $profile['role_key'],
                'sort_order' => $profile['sort_order'],
                'status' => 1,
                'sub_institute_id' => $schoolSetup->id,
                'client_id' => $client->id,
            ]);

            // Keyed by role_key, NOT by name: everything downstream that maps a
            // source profile to a new one now matches on the stable identifier.
            $profileIds[$profile['role_key']] = $newProfile->id;
        }

        $sourceRights = tblgroupwise_rightsModel::where('sub_institute_id', 3)->get();

        if ($sourceRights->count() > 0) {

            /*
             * MAP THE TEMPLATE'S PROFILES BY role_key, NOT BY NAME.
             *
             * This block matched `tbluserprofilemaster.name`, which is
             * user-editable. Renaming tenant 3's "HR" profile would have made
             * every future signup silently lose its HR rights - no error, just
             * a smaller set of rows. RequireProfile documents the same class of
             * failure: "authorization must key on a stable identifier, never on
             * wording a tenant can edit." This was the last place in the signup
             * path still doing it.
             *
             * THE NAME FALLBACK IS NOT A COMPROMISE, IT IS THE LIVE STATE.
             * Tenant 3's three profiles have role_key set on dev and NULL on
             * live, so keying on role_key alone would map nothing on the
             * database that matters. Where role_key is absent the canonical
             * name mapping fills in - exactly today's behaviour - and where it
             * is present the name is ignored.
             */
            $oldProfiles = tbluserprofileMasterModel::where('sub_institute_id', 3)
                            ->get(['id', 'name', 'role_key']);

            $sourceKeyById = [];
            foreach ($oldProfiles as $old) {
                $key = trim((string) ($old->role_key ?? ''));

                if ($key === '') {
                    $key = self::CANONICAL_PROFILE_NAMES[mb_strtolower(trim((string) $old->name))] ?? '';
                }

                if ($key !== '') {
                    $sourceKeyById[(int) $old->id] = $key;
                }
            }

            $insertData = [];

            foreach ($sourceRights as $right) {

                $sourceKey = $sourceKeyById[(int) $right->profile_id] ?? null;
                $newProfileId = $sourceKey !== null ? ($profileIds[$sourceKey] ?? null) : null;

                if (!$newProfileId) continue;

                $insertData[] = [
                    'menu_id' => $right->menu_id,
                    'profile_id' => $newProfileId,
                    'can_view' => $right->can_view,
                    'can_add' => $right->can_add,
                    'can_edit' => $right->can_edit,
                    'can_delete' => $right->can_delete,
                    'created_at' => now(),
                    'sub_institute_id' => $schoolSetup->id, // ✅ NEW ID
                ];
            }

            // ✅ Bulk Insert
            tblgroupwise_rightsModel::insert($insertData);
        }

        // ✅ Menu rights for the CURRENT UI. The block above populates the
        // LEGACY rights table only, which no screen in the new product reads.
        $this->seedDefaultMenuRights($schoolSetup->id, $profileIds);

        /*
         * ── THE CATALOGUE COPY WAS REMOVED HERE ────────────────────────────
         *
         * Six passes used to run at this point, cloning the shared catalogue
         * into the brand-new organisation: job roles from `s_jobrole`, skills
         * from `master_skills`, tasks from `s_jobrole_task`, role-skill links
         * from `s_jobrole_skills`, departments derived from those roles, and a
         * department_id backfill over the lot.
         *
         * WHAT THAT ACTUALLY DELIVERED, measured on live for the two real
         * signups this endpoint has served:
         *
         *     tenant 13 (Jainam)   6,398 rows written,  6,291 copied - 98.3%
         *     tenant 14 (Aqua)    11,921 rows written, 11,814 copied - 99.1%
         *
         * Tenant 14 received 276 job roles, 5,876 tasks, 482 skills, 5,082
         * role-skill mappings and 98 departments - for ONE employee. Every one
         * of its 276 role names also existed in another organisation. All 98
         * departments were flat, formed 49 duplicate-name groups WITHIN that
         * single tenant, and not one of them had a person in it. It received no
         * competencies and no frameworks, which is the layer that is actually
         * meant to be the source of truth.
         *
         * The copy was not a head start. It was 11,800 rows of another
         * company's structure that the customer then had to disprove, and it is
         * the origin of the cross-tenant name collisions that make every
         * name-keyed lookup in this codebase ambiguous.
         *
         * ── WHY ALL SIX AND NOT JUST THE FIRST ─────────────────────────────
         *
         * Passes 2-6 all read back `s_user_jobrole` for THIS tenant, so
         * removing pass 1 alone already reduced them to no-ops. Leaving 390
         * lines that provably do nothing is not a smaller change, it is a
         * bigger one to read.
         *
         * ── AN EMPTY TENANT IS A SUPPORTED STATE, NOT AN UNTESTED ONE ──────
         *
         * Verified before removing this: three empty tenants already exist on
         * dev and have for over a week; there is no firstOrFail/findOrFail
         * against any of the five tables anywhere in app/; every dashboard
         * average is guarded `$total > 0 ? … : 0`; and the readiness gate
         * correctly reports `blocked` with the remedy "Import the job-role
         * library" - which is the product telling the truth, not a break.
         *
         * ── HOW A CUSTOMER GETS CONTENT NOW ────────────────────────────────
         *
         * By asking for it. SeedLibraryPreviewController already reports what
         * the catalogue holds against what the tenant holds, and the adopt
         * endpoint copies the chosen rows recording the source id, so adopted
         * content stays distinguishable from authored content. That is the
         * difference between an offer and an assumption.
         *
         * `$createdBy` went with it: the route at routes/api.php:1141 carries
         * no auth middleware, so Auth::check() was always false and every
         * `if ($createdBy !== null)` branch above was unreachable.
         */

        // ✅ Refresh Data
        $schoolSetup->refresh();

        return response()->json([
            'success' => true,
            'message' => 'School setup created successfully',
            'data' => $schoolSetup
        ], 201);
    }

    /**
     * The default sidebar for a brand-new organisation.
     *
     * role_key => [ menu_id => [can_view, can_add, can_edit, can_delete] ]
     *
     * Keyed on role_key rather than display name for the same reason the rights
     * clone is: the name belongs to the tenant and can be edited.
     *
     * THE NAVIGATION CONTAINERS ARE NOT PADDING. displaySidebarMenu skips a
     * child whose ancestor is not granted, AND drops a granted branch whose
     * children are all ungranted - so Employee Directory without User
     * Management above it is simply invisible. Containers get view only; they
     * are not screens anything can be added to.
     *
     * Role & Permissions (23) is granted to Admin ON PURPOSE: without it the
     * new administrator has no in-product way to widen any of this, and every
     * later change needs a developer running a script.
     *
     * HR gets the same working screens without delete and without Role &
     * Permissions. Employee gets the dashboard only - every other screen here
     * is an administrative or authoring surface. Both are deliberately narrow;
     * the admin widens them from screen 23. Guessing generously on a customer's
     * behalf is how the 4,625 inert rows on live happened.
     */
    private const DEFAULT_MENU_RIGHTS = [
        'administrator' => [
            300 => [1, 0, 0, 0],   // Main Dashboard
            1   => [1, 0, 0, 0],   // Organizational Management   (container)
            7   => [1, 0, 0, 0],   //   Organization Setup        (container)
            13  => [1, 1, 1, 1],   //     Department Management
            8   => [1, 0, 0, 0],   //   User Management           (container)
            22  => [1, 1, 1, 1],   //     Employee Directory
            23  => [1, 1, 1, 1],   //     Role & Permissions
            2   => [1, 0, 0, 0],   // Capability Intelligence     (container)
            223 => [1, 1, 1, 1],   //   Capability Library
            34  => [1, 1, 1, 1],   //   Competency Library
            154 => [1, 1, 1, 1],   //   Competency Framework
        ],
        'hr_manager' => [
            300 => [1, 0, 0, 0],
            1   => [1, 0, 0, 0],
            7   => [1, 0, 0, 0],
            13  => [1, 1, 1, 0],
            8   => [1, 0, 0, 0],
            22  => [1, 1, 1, 0],
            2   => [1, 0, 0, 0],
            223 => [1, 1, 1, 0],
            34  => [1, 1, 1, 0],
            154 => [1, 1, 1, 0],
        ],
        'employee' => [
            300 => [1, 0, 0, 0],
        ],
    ];

    /**
     * GIVES A NEW ORGANISATION A SIDEBAR.
     *
     * ── THE BUG THIS CLOSES ─────────────────────────────────────────────────
     *
     * The block above clones `tblgroupwise_rights` - the LEGACY table, which no
     * screen in the current product reads. Nothing wrote
     * `tblgroupwise_rights_g2g`, which is the one the UI actually consults, so
     * every organisation created here arrived with an EMPTY SIDEBAR. Measured
     * on live: tenants 13 and 14 held 2 rights rows each, and even those came
     * from a one-off backfill months after signup, not from this endpoint. A
     * tenant created the day before this method existed got zero.
     *
     * It could not open Department Management or Employee Directory, so it
     * could not create a department or add an employee - the entire first hour
     * of using the product.
     *
     * ── WHY NOT CALL g2g:seed-default-view-rights ──────────────────────────
     *
     * It states the right policy and implements it tenant-blind: it never
     * writes `sub_institute_id` at all, dedupes on (profile_id, menu_id)
     * without a tenant, and reads every active profile across every
     * organisation. That is why 4,625 of the 4,967 rights rows on live - 93% -
     * can never match RequireMenuRight, which looks a row up by menu + profile
     * + TENANT. Rows that look like configuration and grant nothing are worse
     * than no rows at all.
     *
     * This writes `sub_institute_id` on every row, modelled on
     * tblmenumasterG2gController::storeGroupwiseRightsG2g, the one place in the
     * codebase that already gets it right.
     *
     * ── ORDER MATTERS ──────────────────────────────────────────────────────
     *
     * The menu catalogue used to be gated by a hard-coded id list that excluded
     * every tenant from 12 upward (see 2026_08_22_110000). Until that migration
     * ran, granting these rights would have been writing permissions onto menus
     * the sidebar would not return anyway.
     */
    private function seedDefaultMenuRights(int $subInstituteId, array $profileIds): void
    {
        $wanted = [];
        foreach (self::DEFAULT_MENU_RIGHTS as $grants) {
            $wanted = array_merge($wanted, array_keys($grants));
        }

        // Grant only menus that REALLY EXIST and are active. Hard-coded ids are
        // readable but they are a claim about another table; if the catalogue is
        // ever renumbered this drops the stale ones instead of writing rights
        // that point at nothing.
        $known = tblmenumaster_g2gModel::where('status', 1)
            ->whereIn('id', array_values(array_unique($wanted)))
            ->pluck('id')
            ->flip();

        $rows = [];

        foreach (self::DEFAULT_MENU_RIGHTS as $roleKey => $grants) {
            $profileId = $profileIds[$roleKey] ?? null;

            if (!$profileId) {
                continue;
            }

            foreach ($grants as $menuId => $flags) {
                if (!$known->has($menuId)) {
                    continue;
                }

                [$canView, $canAdd, $canEdit, $canDelete] = $flags;

                $rows[] = [
                    'menu_id'          => $menuId,
                    'profile_id'       => $profileId,
                    'sub_institute_id' => $subInstituteId,
                    'can_view'         => $canView,
                    'can_add'          => $canAdd,
                    'can_edit'         => $canEdit,
                    'can_delete'       => $canDelete,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'created_at'       => now(),
                ];
            }
        }

        if ($rows !== []) {
            tblgroupwise_rights_g2gModel::insert($rows);
        }
    }
}
