<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the employee's own capability screen a way in.
 *
 * ── A FINISHED SCREEN MOUNTED NOWHERE ───────────────────────────────────────
 *
 * `CmMyCapabilityScreen` is a complete 299-line route container. It is exported
 * from components/domain/competency/index.ts and appears in NO content map, so
 * nothing could ever render it. Its own docblock records the shape of the
 * problem:
 *
 *   "That missing container is the whole of why Slice 1's deliverable could not
 *    be opened by anyone - the component was correct, the API was correct, and
 *    nothing joined them."
 *
 * The container was then written, and the last join - a menu row - was still
 * missing. This is it.
 *
 * It was reported as one of three orphan screens. It is one: the other two,
 * `CmMyCapability` and `CmSelfRatingPanel`, are its CHILDREN - it renders them
 * at lines 291 and 164. They were never separately reachable and were never
 * meant to be.
 *
 * ── VERIFIED BEFORE WIRING, NOT ASSUMED ─────────────────────────────────────
 *
 * All three endpoints it depends on were called with a plain employee's token
 * on tenant 6 against live data:
 *
 *   GET /competency/gap?user_id=<self>      200 - 3 competencies, coverage
 *   GET /competency/my-capability           200 - 10 items, 10 rated
 *   GET /competency/capability-progress     200 - 4 entries
 *
 * Called bare, /competency/gap returns 422 "user id is required" - but that is
 * not this screen's path: it sends the caller's own id from the Laravel context
 * and competencySubject() permits it because caller === subject. A colleague's
 * id there is refused server-side with a 403.
 *
 * ── WHERE IT GOES ───────────────────────────────────────────────────────────
 *
 * Immediately after My Certifications, so the employee's own screens sit
 * together rather than being scattered through an admin menu. Positions shift
 * for the same reason as 110000: displaySidebarMenu orders by `sort_order`
 * alone with no tie-breaker, so a tie would order arbitrarily.
 *
 * ── KNOWN OVERLAP, REPORTED NOT SILENTLY CHANGED ────────────────────────────
 *
 * This screen also mounts `CmMyAssessment`, and menu 301 "My Assessment" now
 * points at that same component. So the assessment runner becomes reachable two
 * ways. The screen's comment explains it was mounted there BECAUSE no menu row
 * existed at the time - menu 301 was added later, in 2026_08_26_140000.
 * Removing one of the two is a product decision about where employees should
 * look, so it is left alone and written down here instead.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_30_110400_add_my_capability_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110400_add_my_capability_menu.php
 */
return new class extends Migration
{
    /*
     * 404, NOT 402.
     *
     * 402 was the next free id when this was written and was taken minutes
     * later by an unrelated "Event Bus" row on BOTH databases. The insert then
     * failed with a duplicate primary key - which is the good outcome, because
     * the alternative is two environments where 402 means different screens and
     * the content map's submenuId fallback renders the wrong page on one of
     * them. 403 is "Audit", from the same batch; 404 is free on both.
     */
    private const ID    = 404;
    private const LINK  = '/module/talent-management/my-capability';
    /** My Certifications - the sibling this sits beside. */
    private const AFTER = 401;

    /** Everyone, because everyone has their own capability to look at. */
    private const ROLE_KEYS = [
        'employee',
        'administrator',
        'hr_manager',
        'hr_executive',
        'reporting_manager',
        'department_head',
        'executive',
        'auditor',
        'recruiter',
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Idempotent on the LINK, which also protects the shift below from
        // running twice.
        if (DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists()) {
            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('id', self::AFTER)->first();

        $parentId = $sibling->parent_id ?? 3;
        $level    = $sibling->level ?? 2;
        $position = (int) ($sibling->sort_order ?? 8) + 1;

        /*
         * ONE TRANSACTION, AND THAT IS NOT DEFENSIVE PADDING.
         *
         * Making room and inserting are two statements. The first version ran
         * them bare, the insert failed on a duplicate id, and the SHIFT STAYED
         * APPLIED - so Talent's children ran 1..8 and then jumped to 11 on one
         * database and not the other. Nothing looked broken, because a gap
         * sorts the same as no gap; the damage was that the two environments
         * stopped agreeing. 2026_09_30_110350 cleaned that up.
         *
         * Either both happen or neither does.
         */
        DB::transaction(function () use ($parentId, $level, $position) {
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('sort_order', '>=', $position)
                ->increment('sort_order');

            DB::table('tblmenumaster_g2g')->insert([
                'id'               => self::ID,
                'menu_name'        => 'My Capability',
                'parent_id'        => $parentId,
                'level'            => $level,
                'access_link'      => self::LINK,
                'icon'             => 'Target',
                'status'           => 1,
                'sort_order'       => $position,
                'sub_institute_id' => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });

        $this->grantRights();
    }

    public function down(): void
    {
        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::ID)->delete();
        }

        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();
        if (!$row) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->delete();

        // Close the gap, so a down/up cycle does not drift the siblings one
        // place further each time.
        DB::table('tblmenumaster_g2g')
            ->where('parent_id', $row->parent_id)
            ->where('sort_order', '>', $row->sort_order)
            ->decrement('sort_order');
    }

    /**
     * Read-only view rights for every profile.
     *
     * The Talent Management ancestor is already granted by
     * 2026_09_30_110100, which had to add it for My Certifications - so this
     * only needs the leaf. A leaf under an ungranted parent is invisible
     * (F-209), and that is checked there rather than repeated here.
     */
    private function grantRights(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        /*
         * Plain integers only. `tblgroupwise_rights_g2g.sub_institute_id` is
         * `text` and holds a CSV value '1,2,3,4,5,6,7,8,9,10,11' as well as
         * ids; `tbluserprofilemaster.sub_institute_id` is `bigint`, so MySQL
         * coerces that CSV string to 1 and tenant 1's profiles match twice.
         * Measured: that produced 12 junk rows before the filter existed.
         */
        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->distinct()
            ->whereNotNull('sub_institute_id')
            ->pluck('sub_institute_id')
            ->filter(static fn ($t) => ctype_digit(trim((string) $t)))
            ->values();

        foreach ($tenants as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->whereIn('role_key', self::ROLE_KEYS)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($profiles as $profileId) {
                /*
                 * Unscoped on tenant, to match how the right is READ:
                 * displaySidebarMenu queries `where('profile_id', $id)` with no
                 * tenant predicate, so any row naming this profile and menu is
                 * already in force whatever its sub_institute_id says.
                 */
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', self::ID)
                    ->where('profile_id', $profileId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $tenant,
                    'menu_id'          => self::ID,
                    'profile_id'       => $profileId,
                    'can_view'         => 1,
                    // Self-rating is a write, but it is authorised by the
                    // endpoint (my-rating takes no subject), not by a menu flag.
                    'can_add'          => 0,
                    'can_edit'         => 0,
                    'can_delete'       => 0,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'created_at'       => now(),
                ]);
            }
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
