<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Which modules this organisation uses.
 *
 * ── WHAT THIS REPLACES ──────────────────────────────────────────────────────
 *
 * /settings/module-configuration rendered five hardcoded modules with invented
 * "15 screens / 48 features" counts, and "saved" the choice into
 * `globalThis.gtgOnboardingStore` - a Map in the Next.js process, keyed by user
 * rather than tenant, unauthenticated, and lost on every redeploy. Two admins of
 * one organisation therefore disagreed about what was enabled, and nothing they
 * chose ever reached the product.
 *
 * ── THERE IS NO NEW TABLE, AND THAT IS DELIBERATE ───────────────────────────
 *
 * "Module X is on for tenant T" is not a separate fact from "T's administrator
 * can see X's menus" - it IS that fact. A second table would be a claim about
 * enablement that could disagree with the rights rows that actually decide what
 * renders, and the disagreement would stay invisible until somebody noticed a
 * module switched on that nobody could open. So the rights rows are the storage,
 * and this endpoint reads and writes them.
 *
 * ── WHAT ENABLING ACTUALLY DOES, STATED HONESTLY ────────────────────────────
 *
 * It makes the module VISIBLE. It is not an access control. RequireMenuRight -
 * the only reader that filters rights by tenant - is registered in
 * bootstrap/app.php and attached to ZERO routes; every `menuright:` in routes/
 * is inside a comment. So rights drive the sidebar and nothing else, and the
 * endpoints behind a hidden screen stay callable by anyone holding a token. The
 * screen says this rather than implying a guarantee the product does not make.
 *
 * ── WHO GETS THE RIGHTS ─────────────────────────────────────────────────────
 *
 * The administrator, and only the administrator. Not because HR does not need
 * Talent Management, but because guessing which roles need which module is
 * exactly what SchoolSetupController's own docblock warns against: "Guessing
 * generously on a customer's behalf is how the 4,625 inert rows on live
 * happened." Enabling opens the door; the admin decides who else walks through
 * it, from Role & Permissions, and the screen signposts that.
 */
class ModuleEnablementController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * Modules nobody can switch off.
     *
     * The dashboard is where every role lands, and Organizational Management
     * holds the screens that create departments and people - what every other
     * module depends on. Turning either off would leave an organisation unable
     * to reach the tools it needs to turn them back on.
     */
    private const ALWAYS_ON = [300, 1];

    /** GET /api/organization/modules */
    public function index(Request $request)
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $tenant = (int) $this->apiTenantId($request);
        $profile = $this->adminProfile($tenant);

        if (!$profile) {
            return $this->noAdminProfile();
        }

        $granted = DB::table('tblgroupwise_rights_g2g')
            ->where('profile_id', $profile->id)
            ->where('can_view', 1)
            ->pluck('menu_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $modules = $this->modules()->map(function ($module) use ($granted) {
            $menus = $this->moduleMenus((int) $module->id);

            return [
                'id' => (int) $module->id,
                'name' => $module->menu_name,
                'icon' => $module->icon,
                'access_link' => $module->access_link,
                // Real counts from the real catalogue. The screen showed
                // invented ones - "15 screens", "48 features".
                'screens' => $menus->where('is_leaf', true)->count(),
                'menus' => $menus->count(),
                'enabled' => $granted->has((int) $module->id),
                'always_on' => in_array((int) $module->id, self::ALWAYS_ON, true),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => [
                'modules' => $modules->values(),
                'profile' => ['id' => (int) $profile->id, 'name' => $profile->name],
                // Stated in the payload so the screen cannot quietly imply
                // otherwise. See the class docblock.
                'note' => 'Enabling a module adds it to the administrator navigation. Use Role and Permissions to give other roles access.',
            ],
        ]);
    }

    /**
     * POST /api/organization/modules
     *
     * Body: module_ids[] - the COMPLETE set that should be on. Anything absent
     * is switched off. A complete set rather than a delta, because the screen
     * shows a complete set of switches and a delta would let the two disagree
     * about what "off" meant.
     */
    public function store(Request $request)
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'module_ids' => 'present|array',
            'module_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
            ], 422);
        }

        $tenant = (int) $this->apiTenantId($request);
        $profile = $this->adminProfile($tenant);

        if (!$profile) {
            return $this->noAdminProfile();
        }

        $available = $this->modules()->pluck('id')->map(fn ($id) => (int) $id);

        /*
         * Unknown or disabled ids are dropped rather than refused. The menu
         * catalogue is global and can change under a client that has this
         * screen open; failing the whole save because one id went stale would
         * be worse than applying the rest.
         */
        $wanted = collect($request->input('module_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $available->contains($id))
            ->merge(self::ALWAYS_ON)
            ->unique()
            ->values();

        $enabled = [];
        $disabled = [];

        DB::transaction(function () use ($available, $wanted, $profile, $tenant, &$enabled, &$disabled) {
            foreach ($available as $moduleId) {
                $menus = $this->moduleMenus($moduleId);

                if ($menus->isEmpty()) {
                    continue;
                }

                /*
                 * Every module is cleared before it is re-granted; a disabled
                 * one is simply not re-granted.
                 *
                 * Delete-then-insert rather than an upsert, because
                 * tblgroupwise_rights_g2g has NO unique key - only PRIMARY(id)
                 * and non-unique indexes on menu_id and profile_id - so the
                 * database cannot make an insert idempotent and a second save
                 * would stack duplicates. That is not merely wasteful:
                 * displaySidebarMenu collapses duplicates with keyBy('menu_id')
                 * over an unordered query, so whichever copy MySQL returns last
                 * is the one that wins.
                 *
                 * Scoped to THIS module's menus, so an admin's hand-tuned rights
                 * on other modules survive untouched.
                 */
                DB::table('tblgroupwise_rights_g2g')
                    ->where('profile_id', $profile->id)
                    ->whereIn('menu_id', $menus->pluck('id'))
                    ->delete();

                if (!$wanted->contains($moduleId)) {
                    $disabled[] = $moduleId;
                    continue;
                }

                $now = now();

                $rows = $menus->map(fn ($menu) => [
                    'menu_id' => $menu['id'],
                    'profile_id' => (int) $profile->id,
                    // ALWAYS stamped. 4,238 of live's 4,759 rows carry a NULL
                    // tenant and are inert for RequireMenuRight; this must not
                    // add to them.
                    'sub_institute_id' => $tenant,
                    'can_view' => 1,
                    // A container is a folder, not a screen - nothing can be
                    // added to it. Leaves get full CRUD, matching the pattern
                    // SchoolSetupController::DEFAULT_MENU_RIGHTS establishes.
                    'can_add' => $menu['is_leaf'] ? 1 : 0,
                    'can_edit' => $menu['is_leaf'] ? 1 : 0,
                    'can_delete' => $menu['is_leaf'] ? 1 : 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => $now,
                ])->all();

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('tblgroupwise_rights_g2g')->insert($chunk);
                }

                $enabled[] = $moduleId;
            }
        });

        return response()->json([
            'status' => true,
            'message' => sprintf(
                '%d module(s) on, %d off.',
                count($enabled),
                count($disabled)
            ),
            'data' => ['enabled' => $enabled, 'disabled' => $disabled],
        ]);
    }

    /* ─── helpers ───────────────────────────────────────────────────────── */

    /** Authentication. The role gate is the route's `profile:admin` middleware. */
    private function guard(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        return is_array($identity) ? null : $identity;
    }

    private function noAdminProfile()
    {
        return response()->json([
            'status' => false,
            'message' => 'This organisation has no administrator profile, so modules cannot be configured.',
        ], 422);
    }

    /** The tenant's own administrator profile. */
    private function adminProfile(int $tenant)
    {
        return DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)
            ->where(function ($q) {
                // role_key is the modern discriminator; 10 of 12 live tenants
                // still have it NULL, so the legacy display name is the
                // fallback - the same pair ResolvesLmsIdentity matches on.
                $q->where('role_key', 'administrator')->orWhere('name', 'Admin');
            })
            ->first(['id', 'name']);
    }

    /**
     * The switchable modules: level-1, enabled, in catalogue order.
     *
     * status=0 rows are excluded - CRM (199) and Reports (6) are disabled in the
     * catalogue, and offering a switch for a module the product will not render
     * is how a screen starts lying.
     */
    private function modules()
    {
        return DB::table('tblmenumaster_g2g')
            ->where('parent_id', 0)
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get(['id', 'menu_name', 'icon', 'access_link']);
    }

    /**
     * Every enabled menu under a module, the module row included, each marked
     * leaf or container.
     *
     * The catalogue is three levels deep at most - verified against dev, where
     * no module has a level-4 row - which is also the depth
     * lib/role-permissions.ts can represent. Walked level by level rather than
     * recursively so that assumption stays visible: if a fourth level ever
     * appears it shows up here as menus that are not granted, which is a
     * reportable gap, rather than as a silent recursion over a deeper tree the
     * rights matrix could not then display.
     */
    private function moduleMenus(int $moduleId)
    {
        $exists = DB::table('tblmenumaster_g2g')
            ->where('id', $moduleId)->where('status', 1)
            ->exists();

        if (!$exists) {
            return collect();
        }

        $level2 = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $moduleId)->where('status', 1)->pluck('id');

        $level3 = $level2->isEmpty() ? collect() : DB::table('tblmenumaster_g2g')
            ->whereIn('parent_id', $level2)->where('status', 1)->pluck('id');

        $all = collect([$moduleId])
            ->merge($level2)
            ->merge($level3)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // A menu is a leaf when nothing enabled hangs off it.
        $parents = DB::table('tblmenumaster_g2g')
            ->whereIn('parent_id', $all)->where('status', 1)
            ->pluck('parent_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->flip();

        return $all->map(fn ($id) => [
            'id' => $id,
            'is_leaf' => !$parents->has($id),
        ])->values();
    }
}
