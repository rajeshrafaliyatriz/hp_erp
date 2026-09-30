<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the gap a half-applied migration left in the Talent menu order.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────
 *
 * 2026_09_30_110400 makes room for a new row by incrementing the sort_order of
 * everything at or after the target position, and THEN inserts. Those were two
 * separate statements with no transaction around them, so when the insert
 * failed - id 402 had been taken by an unrelated "Event Bus" row in between -
 * the shift stayed applied and the insert did not happen. Twice, once per
 * attempt.
 *
 * Measured afterwards on the app database: Talent's children ran
 * 1..8, then jumped to 11. Live was untouched, because the migration never got
 * that far there. Nothing was visibly broken - the sidebar sorts by sort_order
 * and a gap sorts identically - but the two databases no longer agreed, and
 * "the two databases no longer agree" is how the next pinned-id migration goes
 * wrong.
 *
 * 110400 is now wrapped in a transaction so the pair cannot half-apply again.
 *
 * ── WHAT THIS DOES ──────────────────────────────────────────────────────────
 *
 * Renumbers Talent Management's children contiguously from 1, preserving their
 * current order exactly (sort_order, then id, which is the order
 * GenerateG2gAccessLinks already uses). That was the invariant before this
 * pass: both databases ran 1..16 with no gaps.
 *
 * Idempotent and order-preserving - on a healthy database it writes the same
 * numbers back, so running it twice changes nothing.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_30_110350_normalise_talent_menu_sort_order.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110350_normalise_talent_menu_sort_order.php
 */
return new class extends Migration
{
    private const PARENT = 3; // Talent Management

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $rows = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $position = 0;

        foreach ($rows as $row) {
            $position++;

            // Only touch rows that are actually in the wrong place.
            if ((int) $row->sort_order === $position) {
                continue;
            }

            DB::table('tblmenumaster_g2g')
                ->where('id', $row->id)
                ->update(['sort_order' => $position, 'updated_at' => now()]);
        }
    }

    /**
     * Nothing to undo.
     *
     * The previous state was a gap left by a failure. Restoring a gap is not a
     * rollback, it is reintroducing the defect.
     */
    public function down(): void
    {
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
