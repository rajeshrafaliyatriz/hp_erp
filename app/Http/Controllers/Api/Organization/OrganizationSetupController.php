<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Organization\StandardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How far this organisation has got with setting itself up.
 *
 * ── WHY THIS IS DERIVED AND NOT STORED ──────────────────────────────────────
 *
 * "Have you set up departments?" is answerable by counting departments. Storing
 * a separate answer would create a second version of the truth that can
 * disagree with the first, and the disagreement would surface as a tick against
 * a step nobody completed - or worse, a tenant told it is finished when it is
 * not.
 *
 * The product already had one of those: portal-review-page writes
 * `localStorage.setItem('gtg-portal-live', 'true')` - per browser, per device,
 * invisible to the server and to every colleague. And the setup wizard's own
 * progress lived in `globalThis.gtgOnboardingStore`, a Map in the Next.js
 * process, keyed by user rather than tenant and emptied on every redeploy.
 *
 * So nothing here is persisted. Every number below is counted at read time from
 * the tables the rest of the product uses, which means this screen cannot claim
 * anything the product would contradict, and an organisation that did its setup
 * through the ordinary screens - never opening this one - still shows as done.
 *
 * ── IT ALSO WORKS FOR TENANTS THAT ARE NOT NEW ──────────────────────────────
 *
 * The same reasoning makes it useful to the twelve organisations already on
 * live, none of which ever saw a setup flow. It reports what is missing rather
 * than what a wizard remembers being clicked.
 */
class OrganizationSetupController extends Controller
{
    use ResolvesApiIdentity;

    /*
     * The nine roles moved to App\Services\Organization\StandardRoles, because
     * TenantProvisioner needs the identical list when it creates a brand-new
     * organisation. Read StandardRoles::ROLES for what they are and why.
     */

    /** GET /api/organization/setup-status */
    public function status(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = (int) $identity['sub_institute_id'];

        $steps = [
            $this->profileStep($tenant),
            $this->rolesStep($tenant),
            $this->modulesStep($tenant),
            $this->departmentsStep($tenant),
            $this->peopleStep($tenant),
            $this->capabilityStep($tenant),
        ];

        $done = collect($steps)->where('done', true)->count();

        return response()->json([
            'status' => true,
            'data' => [
                'steps' => $steps,
                'done' => $done,
                'total' => count($steps),
                // Deliberately NOT a stored "setup complete" flag. See the class
                // docblock - a flag is a claim, this is a measurement.
                'complete' => $done === count($steps),
            ],
        ]);
    }

    /**
     * POST /api/organization/setup/roles
     *
     * Create the standard roles this organisation is missing, and stamp
     * `role_key` on the three it already has.
     *
     * This is the one step that can be completed in place rather than by
     * visiting another screen, because the nine roles are the PLATFORM's
     * vocabulary, not the customer's authored content. Creating them is not
     * guessing on a customer's behalf - it is making the role model the
     * permission system already assumes actually exist. No user is assigned to
     * a new role and no rights are granted; both are decisions for a person.
     */
    public function createRoles(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = (int) $identity['sub_institute_id'];

        /*
         * The rule itself lives in StandardRoles, because a NEW organisation
         * needs exactly the same nine roles created inside the transaction that
         * brings it into existence. Two copies of "what the nine roles are" is
         * how a tenant created today ends up with a different role model from
         * one repaired tomorrow.
         */
        ['created' => $created, 'stamped' => $stamped] = DB::transaction(
            fn () => app(StandardRoles::class)->ensureFor($tenant)
        );

        return response()->json([
            'status' => true,
            'message' => $created === 0 && $stamped === 0
                ? 'All nine standard roles already exist.'
                : sprintf(
                    '%d role(s) created%s. Nobody has been assigned to them and no permissions were granted.',
                    $created,
                    $stamped > 0 ? sprintf(', %d existing role(s) labelled', $stamped) : ''
                ),
            'data' => ['created' => $created, 'stamped' => $stamped],
        ]);
    }

    /* ─── the steps ─────────────────────────────────────────────────────── */

    /**
     * The organisation's own identity.
     *
     * Signup records a name, an email and a phone number, so a row existing is
     * not the same as the profile being filled in. These four are the fields a
     * person has to enter deliberately, and the ones the rest of the product
     * quotes back on documents.
     */
    private function profileStep(int $tenant): array
    {
        $row = DB::table('org_details')->where('sub_institute_id', $tenant)
            ->first(['legal_name', 'cin', 'industry', 'registered_address']);

        $required = ['legal_name' => 'legal name', 'cin' => 'registration number',
                     'industry' => 'industry', 'registered_address' => 'registered address'];

        $missing = [];

        foreach ($required as $column => $label) {
            if (!$row || trim((string) $row->$column) === '') {
                $missing[] = $label;
            }
        }

        return [
            'key' => 'profile',
            'label' => 'Organisation profile',
            'done' => $missing === [],
            'detail' => $missing === []
                ? 'Name, registration number, industry and address are recorded.'
                : 'Still missing: ' . implode(', ', $missing) . '.',
            'action' => 'Complete the profile',
            'link' => '/module/organizational-management/organization-setup/organization-profile',
        ];
    }

    /** The nine roles the permission model assumes. */
    private function rolesStep(int $tenant): array
    {
        $present = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('role_key')
            ->pluck('role_key')
            ->map(fn ($key) => (string) $key)
            ->unique();

        $missing = collect(array_keys(StandardRoles::ROLES))->diff($present);

        return [
            'key' => 'roles',
            'label' => 'Standard roles',
            'done' => $missing->isEmpty(),
            'detail' => $missing->isEmpty()
                ? 'All nine standard roles exist.'
                : sprintf(
                    '%d of 9 exist. Missing: %s.',
                    $present->intersect(array_keys(StandardRoles::ROLES))->count(),
                    $missing->implode(', ')
                ),
            // The only step completed in place - see createRoles().
            'action' => 'Create the missing roles',
            'link' => null,
            'inline_action' => 'create-roles',
        ];
    }

    /** Which modules this organisation has switched on. */
    private function modulesStep(int $tenant): array
    {
        $profile = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)
            ->where(function ($q) {
                $q->where('role_key', 'administrator')->orWhere('name', 'Admin');
            })
            ->value('id');

        $moduleIds = DB::table('tblmenumaster_g2g')
            ->where('parent_id', 0)->where('status', 1)->pluck('id');

        $enabled = $profile ? DB::table('tblgroupwise_rights_g2g')
            ->where('profile_id', $profile)
            ->where('can_view', 1)
            ->whereIn('menu_id', $moduleIds)
            ->distinct()->count('menu_id') : 0;

        // Main Dashboard and Organizational Management are always on, so having
        // only those two means nothing has actually been chosen yet.
        $chosen = max(0, $enabled - 2);

        return [
            'key' => 'modules',
            'label' => 'Modules',
            'done' => $chosen > 0,
            'detail' => $chosen > 0
                ? sprintf('%d of %d modules switched on.', $enabled, $moduleIds->count())
                : 'Only the two that are always on. Choose the modules this organisation will use.',
            'action' => 'Choose modules',
            'link' => '/settings/module-configuration',
        ];
    }

    /**
     * Departments.
     *
     * A hard dependency, not a nicety: creating an LMS course returns 422
     * "Invalid Department ID" without one, and posting a job or opening a Task
     * project both require a department id.
     */
    private function departmentsStep(int $tenant): array
    {
        $count = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->count();

        return [
            'key' => 'departments',
            'label' => 'Departments',
            'done' => $count > 0,
            'detail' => $count > 0
                ? sprintf('%d department%s.', $count, $count === 1 ? '' : 's')
                : 'None yet. Courses, job postings and projects all need one.',
            'action' => 'Add departments',
            'link' => '/module/organizational-management/organization-setup/department-management',
        ];
    }

    /** People. One is the signup administrator; a second means real use. */
    private function peopleStep(int $tenant): array
    {
        $count = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();
        $withDepartment = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('department_id')->where('department_id', '>', 0)->count();

        return [
            'key' => 'people',
            'label' => 'People',
            'done' => $count > 1,
            'detail' => $count > 1
                ? sprintf('%d people, %d assigned to a department.', $count, $withDepartment)
                : 'Only the administrator account. Add the people who will use this.',
            'action' => 'Add people',
            'link' => '/module/organizational-management/user-management/employee-directory',
        ];
    }

    /**
     * Capability.
     *
     * Reported, never seeded. The project's own deployment note is explicit:
     * "OUR FRAMEWORK IS AUTHORED, NOT INHERITED. A competency framework that
     * arrived by accident is worse than an empty one, because nobody can say
     * which rows were meant." So this counts what the organisation has authored
     * and points at the screen to author more.
     */
    private function capabilityStep(int $tenant): array
    {
        $competencies = DB::table('competency')
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->count();

        $items = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $tenant)->count();

        return [
            'key' => 'capability',
            'label' => 'Capability framework',
            'done' => $competencies > 0 && $items > 0,
            'detail' => $competencies > 0
                ? sprintf('%d competencies, %d capability items.', $competencies, $items)
                : 'None yet. Define the capabilities this organisation measures people against.',
            'action' => 'Open the competency library',
            'link' => '/module/competency-management/competency-library',
        ];
    }
}
