<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real menu row for Document Library — the Platform Services registry
 * entry added alongside it (`packages/platform-services-core/src/registry.ts`,
 * g2gv0) needs a `tblmenumaster_g2g` row to grant rights against, same as
 * Event Bus and Audit did before this (see
 * `2026_09_30_110000_create_event_bus_and_audit_menu_rights.php`, whose
 * exact shape this copies).
 *
 * SAME POSITION LMS K-12 USES. LMS K-12 lists "Document" in its own Platform
 * Services menu (`app/components/Header.tsx`'s `platformServicesItems`,
 * routing to `/documents_new`, its unified IDMS document browser) - this
 * puts G2G's own Document Library (document_library + the five federated
 * indexers; see the Document Library feature's own migrations) in the
 * equivalent slot, so "Platform Services" names the same thing on both
 * products rather than two menus that happen to rhyme (the registry
 * package's own stated goal).
 *
 * Seeded administrator-only, like Event Bus and unlike a module-inherited
 * grant - this is a brand new row, so there is no existing broader grant to
 * accidentally widen (the trap `2026_09_30_120000_revoke_broad_platform_services_module_grants.php`
 * had to walk back for the six module rows).
 *
 * `status = 0` - hidden from the sidebar, exactly like every other Platform
 * Services row. The avatar menu (driven by the registry + this right) is the
 * real navigation path, not this table's own sidebar rendering.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_05_150000_create_document_library_menu_right.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_05_150000_create_document_library_menu_right.php
 */
return new class extends Migration
{
    private const PARENT_ID = 1; // Organisational Management — see docblock above.
    private const ACCESS_LINK = '/documents';
    private const LABEL = 'Document Library';
    private const ROLES = ['administrator'];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = $this->createMenuRow();

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g') && $this->tableExists('tbluserprofilemaster');

        if ($menuId === null || ! $hasRights) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']);

        foreach ($profiles as $profile) {
            $resolved = RoleKey::fromProfile($profile);

            if (! in_array($resolved, self::ROLES, true)) {
                continue;
            }

            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $profile->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $profile->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $profile->id,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'dashboard_right' => 0,
                'is_mobile' => 0,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $id = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($id === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $id)->delete();
    }

    private function createMenuRow(): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => self::LABEL,
            'parent_id' => self::PARENT_ID,
            'level' => 2,
            'page_type' => null,
            'access_link' => self::ACCESS_LINK,
            'icon' => '',
            'status' => 0,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
