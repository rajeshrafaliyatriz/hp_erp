<?php

namespace App\Services\Organization;

use App\Http\Controllers\Api\signup_api\SchoolSetupController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * BRINGS AN ORGANISATION INTO EXISTENCE — all of it, or none of it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Creating an organisation was two anonymous POSTs that the caller had to
 * sequence itself, with a database lookup in between:
 *
 *   POST /api/school-setup   tenant + client + 3 profiles + rights.
 *                            Returns the school_setup row and NOT the profile
 *                            ids, so the caller cannot proceed without querying.
 *   POST /api/user-signup    the admin account, given a user_profile_id the
 *                            caller had to find on its own.
 *
 * Neither was transactional, and nothing correlated them. If the second call
 * failed you were left with an organisation nobody could log into, and no
 * cleanup path. That is not hypothetical: dev holds 16 `tblclient` rows against
 * 15 `school_setup` rows, and the tenant ids jump 1000000 → 1000010 → 1000011 →
 * 1000018 → 1000019. Those gaps are ids burned by runs that died partway.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FOUR GAPS IT CLOSES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   1. ONE TRANSACTION. A failure anywhere leaves no organisation at all, which
 *      is a state somebody can retry. Half an organisation is not.
 *   2. ALL NINE ROLES, not three. Signup created Admin/Employee/HR and left the
 *      other six to a seeder that has never run on live, so `profile:` guards,
 *      the leave matrix and the navigation rules were all written against roles
 *      that did not exist for 10 of 12 organisations.
 *   3. AN `org_details` ROW. Nothing has ever created one - 8 of 12 live
 *      tenants have no organisation profile, and both the setup checklist and
 *      the first-run guidance therefore fail their first step by construction.
 *   4. THE ADMIN ACCOUNT, with the profile id it belongs to, in the same
 *      transaction rather than a second call.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IT DELIBERATELY DOES NOT DO
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * No departments, no job roles, no competencies, no courses. An earlier version
 * of signup copied a department catalogue and produced ~98 departments per
 * tenant with 49 duplicate-name groups; that copy was removed and is not coming
 * back. A new organisation gets a working front door and authors its own
 * content - which is exactly what the setup checklist then measures.
 */
class TenantProvisioner
{
    public function __construct(private StandardRoles $roles)
    {
    }

    /**
     * @param  array{name:string, short_code:?string, contact_person:?string,
     *               mobile:?string, email:?string, industry:?string, syear:?string,
     *               admin_first_name:string, admin_last_name:?string,
     *               admin_email:string, admin_password:string,
     *               admin_mobile:?string}  $input
     * @return array{tenant_id:int, client_id:int, profiles:array<string,int>,
     *               admin_user_id:int, rights:int, roles:array{created:int,stamped:int}}
     */
    public function provision(array $input, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($input, $actorId) {
            $now = now();

            // ── 1. The client, then the tenant ──────────────────────────────
            $clientId = (int) DB::table('tblclient')->insertGetId([
                'client_name' => $input['name'],
                'created_at' => $now,
            ]);

            /*
             * school_setup.id IS the sub_institute_id. It is a plain
             * AUTO_INCREMENT and nothing in the application assigns it.
             *
             * NOTE FOR ANYONE READING THIS EXPECTING 1000000+: that range exists
             * only on the dev database, where somebody bumped the counter by
             * hand. Live is at 15. Never validate or display against it.
             */
            $tenantId = (int) DB::table('school_setup')->insertGetId([
                'SchoolName' => $input['name'],
                'ShortCode' => $input['short_code'],
                'ContactPerson' => $input['contact_person'] ?? null,
                'Mobile' => $input['mobile'] ?? null,
                'Email' => $input['email'] ?? null,
                'client_id' => $clientId,
                'syear' => $input['syear'] ?? (string) $now->year,
                'institute_type' => $input['industry'] ?? null,
                'is_lms' => 'N',
                'SortOrder' => 0,
                'expire_date' => $now->copy()->addYear()->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // ── 2. All nine roles, in one place ─────────────────────────────
            //
            // The three signup used to create are among the nine, so there is no
            // separate "defaults then extras" step - ensureFor() creates every
            // role this organisation is missing, which for a new one is all of
            // them.
            $roleResult = $this->roles->ensureFor($tenantId);

            $profiles = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenantId)
                ->whereNotNull('role_key')
                ->pluck('id', 'role_key')
                ->map(fn ($id) => (int) $id)
                ->all();

            // client_id is not set by ensureFor - it is not part of the role
            // model, it is a billing link - so it is stamped here where the
            // client is known.
            DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenantId)
                ->update(['client_id' => $clientId]);

            // ── 3. A sidebar ────────────────────────────────────────────────
            $rights = $this->seedMenuRights($tenantId, $profiles);

            // ── 4. An organisation profile ──────────────────────────────────
            //
            // Seeded, not blank: the name and contact details were just captured
            // on the form and re-typing them would be the product forgetting
            // something it was told 200ms ago. The rest - CIN, GST, PAN,
            // registered address - is genuinely the customer's to enter, and the
            // setup checklist asks for it.
            DB::table('org_details')->insert([
                'legal_name' => $input['name'],
                'industry' => $input['industry'] ?? null,
                'email' => $input['email'] ?? null,
                'mobile_no' => $input['mobile'] ?? null,
                'sub_institute_id' => $tenantId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // ── 5. Somebody who can log in ──────────────────────────────────
            $adminProfileId = $profiles['administrator'] ?? null;

            if (!$adminProfileId) {
                // Cannot happen unless ensureFor changed underneath us, and if it
                // did, an organisation with no administrator is not something to
                // hand back as a success.
                throw new \RuntimeException('The administrator profile was not created.');
            }

            $adminId = (int) DB::table('tbluser')->insertGetId([
                'user_name' => $input['admin_email'],
                'password' => Hash::make($input['admin_password']),
                'first_name' => $input['admin_first_name'],
                'last_name' => $input['admin_last_name'] ?? null,
                'email' => $input['admin_email'],
                'mobile' => $input['admin_mobile'] ?? null,
                'user_profile_id' => $adminProfileId,
                'sub_institute_id' => $tenantId,
                // Derived from the profile, never asserted - the same rule
                // UserSignupController now follows.
                'is_admin' => 1,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The academic year the rest of the product filters on. Signup wrote
            // this from the SECOND call, so an organisation created by the first
            // alone had none.
            DB::table('academic_year')->insert([
                'syear' => $input['syear'] ?? (string) $now->year,
                'sub_institute_id' => $tenantId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'profiles' => $profiles,
                'admin_user_id' => $adminId,
                'rights' => $rights,
                'roles' => $roleResult,
            ];
        });
    }

    /**
     * A short code derived from the name's initials, made unique.
     *
     * The original derivation collided freely and silently - "Jainam Solutions"
     * and "Jio Systems" both produce JS - so the numeric suffix is what makes
     * this a code rather than a coincidence.
     */
    public function deriveShortCode(string $name): string
    {
        $base = '';

        foreach (preg_split('/[\s\-_]+/', trim($name)) as $word) {
            if ($word === '' || strlen($base) >= 5) {
                continue;
            }

            $base .= strtoupper(substr($word, 0, 1));
        }

        if ($base === '') {
            $base = 'ORG';
        }

        if (!$this->shortCodeTaken($base)) {
            return $base;
        }

        for ($n = 2; $n < 100; $n++) {
            $candidate = substr($base, 0, 4) . $n;

            if (!$this->shortCodeTaken($candidate)) {
                return $candidate;
            }
        }

        // 98 organisations sharing initials is not a case worth more code than
        // this; a timestamp suffix is ugly and correct.
        return substr($base, 0, 3) . substr((string) time(), -3);
    }

    private function shortCodeTaken(string $code): bool
    {
        return DB::table('school_setup')->where('ShortCode', $code)->exists();
    }

    /**
     * The default sidebar, reusing SchoolSetupController's policy verbatim.
     *
     * Referenced, not copied. That constant is the reviewed decision about which
     * menus an organisation starts with, and a second copy here would drift the
     * first time somebody edits one of them - which is exactly how menu 304 came
     * to be granted by a migration and not by signup.
     *
     * @param  array<string,int>  $profiles  role_key => profile id
     */
    private function seedMenuRights(int $tenantId, array $profiles): int
    {
        $grantsByRole = SchoolSetupController::DEFAULT_MENU_RIGHTS;

        $wanted = [];

        foreach ($grantsByRole as $grants) {
            $wanted = array_merge($wanted, array_keys($grants));
        }

        // Only menus that really exist and are active. A hard-coded id is a
        // claim about another table.
        $known = DB::table('tblmenumaster_g2g')
            ->where('status', 1)
            ->whereIn('id', array_values(array_unique($wanted)))
            ->pluck('id')
            ->flip();

        $rows = [];

        foreach ($grantsByRole as $roleKey => $grants) {
            $profileId = $profiles[$roleKey] ?? null;

            if (!$profileId) {
                continue;
            }

            foreach ($grants as $menuId => [$canView, $canAdd, $canEdit, $canDelete]) {
                if (!$known->has($menuId)) {
                    continue;
                }

                $rows[] = [
                    'menu_id' => $menuId,
                    'profile_id' => $profileId,
                    // ALWAYS stamped. 88% of live's rights rows carry a NULL
                    // tenant and are inert for RequireMenuRight; not one more.
                    'sub_institute_id' => $tenantId,
                    'can_view' => $canView,
                    'can_add' => $canAdd,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('tblgroupwise_rights_g2g')->insert($rows);
        }

        return count($rows);
    }
}
