<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SLICE 1, ITEM 0 — the blocker.
 *
 * D-007's record states "item_label kept + item_id nullable". THE BUILT SCHEMA
 * HAD NEITHER: item_id was NOT NULL and no item_label column existed. Caught by
 * checking the CREATED SCHEMA rather than the migration source.
 *
 * WHY IT BLOCKS EVERYTHING. `skill` plausibly resolves to s_users_skills, but
 * KNOWLEDGE, ABILITY, ATTITUDE and BEHAVIOUR have NO canonical table. With
 * item_id NOT NULL, four of the five KASBA dimensions are UNCOMPOSABLE and a
 * competency could only ever bundle skills - which contradicts Q-A2's "named
 * bundle of KASBA items" outright.
 *
 * THE RULE, so item_label does not become the free-text problem this phase
 * exists to remove:
 *
 *   item_id populated  = THE TARGET STATE. Resolved by key.
 *   item_label alone   = A HOLDING STATE. Visible in the capability-coverage
 *                        metric, and counted as unresolved.
 *
 * Same shape as F-07b's held orphans: honest about what is unresolved rather
 * than guessing an id. A label is not a key and is never treated as one.
 *
 * Safe: the table is empty (0 rows), so relaxing NOT NULL cannot orphan
 * anything, and no backfill is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Relaxing NOT NULL. Doctrine/DBAL is not assumed present, so raw DDL.
        DB::statement('ALTER TABLE `competency_kasba_item` MODIFY `item_id` BIGINT UNSIGNED NULL');

        if (!Schema::hasColumn('competency_kasba_item', 'item_label')) {
            Schema::table('competency_kasba_item', function (Blueprint $table) {
                $table->string('item_label', 191)->nullable()->after('item_id');
                // A row with neither an id nor a label names nothing at all.
                $table->index(['competency_id', 'kasba_type'], 'idx_cki_competency_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('competency_kasba_item', function (Blueprint $table) {
            $table->dropIndex('idx_cki_competency_type');
            $table->dropColumn('item_label');
        });
        DB::statement('ALTER TABLE `competency_kasba_item` MODIFY `item_id` BIGINT UNSIGNED NOT NULL');
    }
};
