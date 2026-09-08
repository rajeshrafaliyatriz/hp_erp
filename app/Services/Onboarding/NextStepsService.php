<?php

namespace App\Services\Onboarding;

use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THIS PERSON SHOULD DO FIRST.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE 537 AUTHORED TOUR STEPS ARE NOT USED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `Onboarding_tour_details` holds 537 rows of genuinely written guidance, and
 * the obvious thing to do was to play them back. They were measured first:
 *
 *     35 distinct access_links in the tour table
 *      0 of them match ANY access_link in tblmenumaster_g2g
 *
 * They address the Blade application - `content/organization-dashboard`,
 * `content/HRMS/Payroll/Salary-Structure` - and the product is now Next.js under
 * `/module/...`. Their `on_click` values are Shepherd.js anchors from that UI
 * (`edit-org-btn`, `apply-leave-submit`); NONE of those strings appear anywhere
 * in the frontend, and no tour library is installed.
 *
 *     A TOUR DRIVEN OFF THAT TABLE WOULD HIGHLIGHT NOTHING, ON PAGES THAT NO
 *     LONGER EXIST. It would be the fixture problem again, wearing the costume
 *     of real data because the rows are real.
 *
 * So the rows are left untouched - they are somebody's work and deleting a
 * customer's content is not this service's call - and recorded as a finding
 * instead. `user_onboarding_status`, the other unused table, IS used: it is
 * correctly shaped for exactly what is needed, per user and per tenant.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * EVERY STEP IS MEASURED, ROUTED AND PERMITTED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Three rules, and each one closes a way this feature could lie:
 *
 *   MEASURED   A step appears only while its `outstanding` query says the work
 *              is genuinely undone, counted from the tables the rest of the
 *              product writes. Nothing is remembered, so nothing can claim a
 *              task is pending after somebody did it in another screen - the
 *              same reasoning as OrganizationSetupController.
 *
 *   ROUTED     The destination is a menu ID, and the link is read from
 *              tblmenumaster_g2g at request time. A URL cannot be typed into
 *              this file and rot; if the menu is gone, the step is gone.
 *
 *   PERMITTED  A step is dropped unless the caller's profile has can_view on
 *              that menu. GUIDANCE THAT SENDS SOMEBODY TO A SCREEN THEY CANNOT
 *              OPEN IS WORSE THAN NO GUIDANCE - it reads as the product being
 *              broken. This is also what makes it work on a tenant that has
 *              only some modules switched on.
 *
 * The list is therefore empty for a fully set-up organisation, and empty for an
 * auditor, who configures nothing. An empty list is an answer.
 */
class NextStepsService
{
    /**
     * Menu IDs the steps point at. Named so a reader can see the destination
     * without looking it up, and so a typo is a constant reference rather than
     * a silently wrong number.
     */
    private const MENU_ORG_PROFILE      = 12;
    private const MENU_DEPARTMENTS      = 13;
    private const MENU_EMPLOYEES        = 22;
    private const MENU_ROLES            = 23;
    private const MENU_COMPETENCY_LIB   = 34;
    private const MENU_RECRUITMENT      = 47;
    private const MENU_MY_LEARNING      = 209;
    private const MENU_CAPABILITY_PROG  = 303;
    private const MENU_READINESS        = 304;

    /**
     * The steps, in the order a person would do them.
     *
     * `roles` is matched against role_key exactly - never a display name, never
     * a substring. See App\Support\RoleKey for why.
     */
    /**
     * The one destination that is NOT a menu.
     *
     * `/settings/module-configuration` is an application route rather than a
     * catalogue entry, and that is precisely why the `enable_modules` step can
     * use it: THAT STEP EXISTS BECAUSE THE CALLER'S MENU RIGHTS ARE EMPTY. A
     * destination read from the menu system would be unreachable for the only
     * person who ever sees it.
     */
    private const MODULE_CONFIG_LINK = '/settings/module-configuration';

    private function catalogue(): array
    {
        return [
            /*
             * FIRST, AND ONLY WHEN NOTHING ELSE CAN BE SHOWN.
             *
             * An administrator with no rights rows at all sees an empty sidebar
             * and - because every other step is dropped for pointing at a screen
             * they cannot open - would otherwise see empty guidance too. That is
             * the worst outcome for the tenant that needs it most: live tenants
             * 13 and 14 and dev's Fiber Valley (967 employees) all have exactly
             * zero rights rows, because they were created before signup granted
             * any.
             *
             * `measure` gets the viewable-menu count so this can fire on the one
             * condition no other step can describe: not "you have not done X"
             * but "you cannot reach anything".
             */
            [
                'key' => 'enable_modules',
                'roles' => ['administrator'],
                'menu' => null,
                'link' => self::MODULE_CONFIG_LINK,
                'title' => 'Switch on the modules your organisation uses',
                'action' => 'Open module configuration',
                'measure' => function (int $tenant, int $userId, int $viewable) {
                    return $viewable === 0
                        ? [true, 'Your account can reach no screens yet, so your sidebar is empty. Start here.']
                        : [false, sprintf('%d screen%s available.', $viewable, $viewable === 1 ? '' : 's')];
                },
            ],
            [
                'key' => 'org_profile',
                'roles' => ['administrator'],
                'menu' => self::MENU_ORG_PROFILE,
                'title' => 'Add your organisation’s details',
                'action' => 'Open organisation profile',
                'measure' => function (int $tenant) {
                    $row = DB::table('org_details')->where('sub_institute_id', $tenant)
                        ->first(['legal_name', 'registered_address', 'industry']);

                    if (!$row) {
                        return [true, 'No organisation profile has been created yet.'];
                    }

                    $blank = collect(['legal_name' => 'legal name', 'registered_address' => 'address', 'industry' => 'industry'])
                        ->filter(fn ($_, $field) => trim((string) ($row->$field ?? '')) === '')
                        ->values();

                    return $blank->isEmpty()
                        ? [false, 'Your organisation profile is filled in.']
                        : [true, 'Still blank: ' . $blank->join(', ') . '.'];
                },
            ],
            [
                'key' => 'standard_roles',
                'roles' => ['administrator'],
                'menu' => self::MENU_ROLES,
                'title' => 'Create the nine standard roles',
                'action' => 'Open roles & permissions',
                'measure' => function (int $tenant) {
                    $have = DB::table('tbluserprofilemaster')
                        ->where('sub_institute_id', $tenant)
                        ->whereNotNull('role_key')->where('role_key', '!=', '')
                        ->distinct()->count('role_key');

                    $need = count(RoleKey::ALL);

                    return $have >= $need
                        ? [false, sprintf('All %d roles exist.', $need)]
                        : [true, sprintf('%d of %d exist. Permissions and approvals key on the missing ones.', $have, $need)];
                },
            ],
            [
                'key' => 'departments',
                'roles' => ['administrator', 'hr_manager', 'hr_executive'],
                'menu' => self::MENU_DEPARTMENTS,
                'title' => 'Set up your departments',
                'action' => 'Open department management',
                'measure' => function (int $tenant) {
                    $n = DB::table('hrms_departments')->where('sub_institute_id', $tenant)
                        ->whereNull('deleted_at')->count();

                    return $n > 0
                        ? [false, sprintf('%d department%s.', $n, $n === 1 ? '' : 's')]
                        : [true, 'Employees, leave and reporting all hang off departments.'];
                },
            ],
            [
                'key' => 'people',
                'roles' => ['administrator', 'hr_manager', 'hr_executive'],
                'menu' => self::MENU_EMPLOYEES,
                'title' => 'Add your people',
                'action' => 'Open employee directory',
                'measure' => function (int $tenant) {
                    $n = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();

                    return $n > 1
                        ? [false, sprintf('%d people.', $n)]
                        : [true, 'Only your own account exists so far.'];
                },
            ],
            [
                'key' => 'reporting_line',
                'roles' => ['administrator', 'hr_manager'],
                'menu' => self::MENU_EMPLOYEES,
                'title' => 'Record who reports to whom',
                'action' => 'Open employee directory',
                'measure' => function (int $tenant) {
                    $total = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();

                    // Nothing to say about a reporting line for one person.
                    if ($total <= 1) {
                        return [false, 'Not applicable yet.'];
                    }

                    $withManager = DB::table('tbluser')->where('sub_institute_id', $tenant)
                        ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '>', 0)->count();

                    return $withManager > 0
                        ? [false, sprintf('%d of %d have a manager.', $withManager, $total)]
                        : [true, sprintf('None of your %d people have a manager, so approvals and team views cannot work.', $total)];
                },
            ],
            [
                'key' => 'capability_framework',
                'roles' => ['administrator', 'hr_manager'],
                'menu' => self::MENU_COMPETENCY_LIB,
                'title' => 'Define what good looks like',
                'action' => 'Open competency library',
                'measure' => function (int $tenant) {
                    $n = DB::table('competency_kasba_item')->where('sub_institute_id', $tenant)->count();

                    return $n > 0
                        ? [false, sprintf('%d capability item%s defined.', $n, $n === 1 ? '' : 's')]
                        : [true, 'Without this there is nothing to measure people against.'];
                },
            ],
            [
                'key' => 'measure_capability',
                'roles' => ['hr_manager', 'hr_executive', 'department_head'],
                'menu' => self::MENU_CAPABILITY_PROG,
                'title' => 'Measure your people',
                'action' => 'Open capability progress',
                'measure' => function (int $tenant) {
                    $employees = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();

                    if ($employees <= 1) {
                        return [false, 'Add people first.'];
                    }

                    $measured = DB::table('competency_kasba_rating')->where('sub_institute_id', $tenant)
                        ->distinct()->count('user_id');

                    return $measured > 0
                        ? [false, sprintf('%d of %d measured.', $measured, $employees)]
                        : [true, sprintf('None of your %d people have a capability measurement yet.', $employees)];
                },
            ],
            [
                'key' => 'readiness',
                'roles' => ['administrator', 'hr_manager'],
                'menu' => self::MENU_READINESS,
                'title' => 'See which capabilities are switched on',
                'action' => 'Open readiness gates',
                'measure' => function (int $tenant) {
                    $blocked = DB::table('tenant_readiness_gate')
                        ->where('sub_institute_id', $tenant)
                        ->where('state', 'blocked')
                        ->whereNotNull('value')
                        ->whereColumn('value', '<', 'enable_threshold')
                        ->count();

                    return $blocked === 0
                        ? [false, 'Nothing is being held back.']
                        : [true, sprintf('%d capabilit%s waiting on your data.', $blocked, $blocked === 1 ? 'y is' : 'ies are')];
                },
            ],
            [
                'key' => 'open_roles',
                'roles' => ['recruiter'],
                'menu' => self::MENU_RECRUITMENT,
                'title' => 'Post your first opening',
                'action' => 'Open recruitment',
                'measure' => function (int $tenant) {
                    $n = DB::table('talent_job_postings')->where('sub_institute_id', $tenant)
                        ->whereNull('deleted_at')->where('status', 'Active')->count();

                    return $n > 0
                        ? [false, sprintf('%d open role%s.', $n, $n === 1 ? '' : 's')]
                        : [true, 'Nothing is advertised on your careers page yet.'];
                },
            ],
            [
                'key' => 'my_capability',
                'roles' => ['employee', 'reporting_manager', 'department_head'],
                'menu' => self::MENU_CAPABILITY_PROG,
                'title' => 'Rate your own capabilities',
                'action' => 'Open capability progress',
                'measure' => function (int $tenant, int $userId) {
                    $mine = DB::table('competency_kasba_rating')
                        ->where('sub_institute_id', $tenant)->where('user_id', $userId)->count();

                    return $mine > 0
                        ? [false, sprintf('%d rating%s recorded.', $mine, $mine === 1 ? '' : 's')]
                        : [true, 'Your gaps and course recommendations start from this.'];
                },
            ],
            [
                'key' => 'my_learning',
                'roles' => ['employee', 'reporting_manager', 'department_head', 'hr_executive'],
                'menu' => self::MENU_MY_LEARNING,
                'title' => 'Finish the course you are enrolled on',
                'action' => 'Open my learning',
                'measure' => function (int $tenant, int $userId) {
                    $open = DB::table('lms_course_enroll')
                        ->where('sub_institute_id', $tenant)->where('user_id', $userId)
                        ->whereNull('deleted_at')
                        ->whereIn('status', ['enrolled', 'in-progress'])
                        ->count();

                    return $open > 0
                        ? [true, sprintf('%d course%s still open.', $open, $open === 1 ? '' : 's')]
                        : [false, 'Nothing outstanding.'];
                },
            ],
        ];
    }

    /**
     * The outstanding steps for one caller.
     *
     * @return array{role:?string, steps:array<int,array>, dismissed:int}
     */
    public function forUser(int $tenant, int $userId): array
    {
        $roleKey = RoleKey::forUserId($userId);
        $profileId = (int) (DB::table('tbluser')->where('id', $userId)->value('user_profile_id') ?? 0);

        // A role nobody can resolve gets no guidance. Guessing would be the
        // substring-matching mistake in a friendlier place.
        if ($roleKey === null) {
            return ['role' => null, 'steps' => [], 'dismissed' => 0];
        }

        $dismissed = DB::table('user_onboarding_status')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('feature')
            ->flip();

        $links = $this->accessLinks();
        $viewable = $this->viewableMenus($profileId, $tenant);

        $steps = [];

        foreach ($this->catalogue() as $step) {
            if (!in_array($roleKey, $step['roles'], true)) {
                continue;
            }

            if ($dismissed->has($step['key'])) {
                continue;
            }

            $menuId = $step['menu'] ?? null;

            if ($menuId !== null) {
                // ROUTED and PERMITTED. A missing menu row or a missing right
                // drops the step entirely rather than producing a link to
                // nowhere. The one step with `menu` => null is exempt by
                // design - see MODULE_CONFIG_LINK.
                if (!isset($links[$menuId]) || !isset($viewable[$menuId])) {
                    continue;
                }

                $link = $links[$menuId];
            } else {
                $link = $step['link'];
            }

            [$outstanding, $detail] = ($step['measure'])($tenant, $userId, count($viewable));

            if (!$outstanding) {
                continue;
            }

            $steps[] = [
                'key' => $step['key'],
                'title' => $step['title'],
                'detail' => $detail,
                'action' => $step['action'],
                'link' => $link,
                'menu_id' => $menuId,
            ];
        }

        return [
            'role' => $roleKey,
            'steps' => $steps,
            'dismissed' => $dismissed->count(),
        ];
    }

    /**
     * Mark a step done-with, for this user only.
     *
     * `completed_at` is set because from the person's side that is what it is:
     * they have dealt with it, whether by doing the work or by deciding it does
     * not apply to them. The measurement still runs - a dismissed step that
     * later becomes outstanding again stays hidden, which is the point of
     * dismissing it.
     */
    public function dismiss(int $tenant, int $userId, string $key): bool
    {
        $known = collect($this->catalogue())->pluck('key')->flip();

        // Only keys this service defines. An arbitrary string would let a caller
        // fill the table with rows nothing reads.
        if (!$known->has($key)) {
            return false;
        }

        $existing = DB::table('user_onboarding_status')
            ->where('user_id', $userId)->where('feature', $key)->first(['id']);

        if ($existing) {
            DB::table('user_onboarding_status')->where('id', $existing->id)->update([
                'completed_at' => now(),
                'deleted_at' => null,
                'deleted_by' => null,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

            return true;
        }

        DB::table('user_onboarding_status')->insert([
            'user_id' => $userId,
            'feature' => $key,
            'completed_at' => now(),
            'sub_institute_id' => $tenant,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** Bring a dismissed step back. */
    public function restore(int $userId, string $key): bool
    {
        return DB::table('user_onboarding_status')
            ->where('user_id', $userId)->where('feature', $key)
            ->update(['deleted_at' => now(), 'deleted_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /** menu_id => access_link, for the menus this service points at. */
    private function accessLinks(): array
    {
        // filter() drops the one step routed outside the menu system.
        $ids = collect($this->catalogue())->pluck('menu')->filter()->unique()->all();

        return DB::table('tblmenumaster_g2g')
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->whereNotNull('access_link')
            ->pluck('access_link', 'id')
            ->all();
    }

    /**
     * The menus this profile may view, keyed by menu id.
     *
     * The tenant-stamped row wins where both exist. 89% of rights rows on live
     * carry a NULL sub_institute_id (F-151), so a profile routinely has two rows
     * for the same menu and picking the first one returned is a coin toss -
     * which is how the sidebar came to show different screens on different
     * requests. Same collapse rule as tblmenumasterG2gController.
     */
    private function viewableMenus(int $profileId, int $tenant): array
    {
        if ($profileId <= 0) {
            return [];
        }

        $collapsed = DB::table('tblgroupwise_rights_g2g')
            ->where('profile_id', $profileId)
            ->get(['menu_id', 'can_view', 'sub_institute_id'])
            ->reduce(function ($carry, $right) use ($tenant) {
                $menuId = (int) $right->menu_id;
                $isExact = (string) $right->sub_institute_id === (string) $tenant;

                if (!array_key_exists($menuId, $carry) || $isExact) {
                    $carry[$menuId] = (int) $right->can_view === 1;
                }

                return $carry;
            }, []);

        // Only the GRANTED menus survive as keys, so the caller can test with
        // isset(). A row that exists with can_view = 0 is a refusal, and keeping
        // it would let a step through to a screen the person cannot open.
        return array_filter($collapsed);
    }
}
