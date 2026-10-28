<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a gap in 2026_11_29_080200_add_signals_menu_branch.php's grant
 * matrix, found while mapping Phase 2's platformright actions onto it:
 * today's route `DELETE /signals/ingestion/sources/{id}` sits inside the
 * `profile:admin,hr` group (routes/api.php ~3531-3551), so hr_manager and
 * hr_executive CAN delete an ingestion source today. The Signals menu
 * migration gave every role the same can_delete=0 except administrator,
 * which would silently narrow hr_manager/hr_executive's access the moment
 * Phase 2 gates that DELETE route behind `platformright:/ingestion,delete`.
 *
 * Scoped to the Ingestion Engine row ONLY (access_link=/ingestion) - it is
 * the only Signals screen with a delete route under the admin,hr tier.
 * Idempotent: only raises can_delete from 0, never touches an existing 1.
 */
return new class extends Migration
{
    private const INGESTION_LINK = '/ingestion';
    private const ROLE_KEYS = ['hr_manager', 'hr_executive'];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g') || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::INGESTION_LINK)->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->whereIn('profile_id', function ($q) {
                $q->select('id')->from('tbluserprofilemaster')->whereIn('role_key', self::ROLE_KEYS);
            })
            ->where('can_delete', 0)
            ->update(['can_delete' => 1]);
    }

    public function down(): void
    {
        // No-op, same precedent as the other grant-only migrations in this
        // batch: a permission this migration's up() corrected should not be
        // silently stripped by a rollback.
    }

    /** MariaDB 10.1 (live) cannot run Schema::hasTable(); sqlite (PHPUnit) has no information_schema. */
    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
