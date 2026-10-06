<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PER-DEVICE PREFERENCES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A DEVICE COLUMN AND NOT JUST localStorage
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * "Theme should be remembered for THIS laptop" and "it should not reset when I
 * clear my cookies" are both wanted, and localStorage alone cannot do the
 * second: a browser whose data has been cleared is, to us, a new browser.
 *
 * So the rows live here, keyed by a device id the browser keeps. Clearing site
 * data loses the id, not the preferences - the person falls back to their
 * ACCOUNT DEFAULT rather than to a factory default, and the rows for their other
 * devices are untouched.
 *
 * `device_id = ''` IS the account default. That is deliberate rather than a
 * nullable column: '' participates in a unique index where NULL would not, so
 * one person cannot end up with two competing account-level rows for the same
 * key. It also means the single row already stored on live keeps working with no
 * backfill - it becomes that person's account default, which is exactly what it
 * was.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TWO THINGS THIS MIGRATION HAS TO GET RIGHT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. THE NEW INDEX IS ADDED BEFORE THE OLD ONE IS DROPPED.
 *
 *    `user_preferences_user_id_foreign` is a real foreign key on `user_id`, and
 *    a foreign key requires an index whose leftmost column is the child column.
 *    Today the unique `(user_id, pref_key)` provides that. Dropping it first
 *    fails with errno 1553 - "needed in a foreign key constraint". The new
 *    unique `(user_id, device_id, pref_key)` also leads with `user_id`, so it
 *    can take over the job, but only once it exists.
 *
 *    This is the same trap `2026_09_08_110000_unique_org_details_per_tenant`
 *    hit. `down()` mirrors the order for the same reason.
 *
 * 2. THE INDEX MUST FIT IN 767 BYTES.
 *
 *    Live is MariaDB 10.1.48 with ROW_FORMAT=Compact, so the index prefix limit
 *    is 767 bytes - not the 3072 of a modern Dynamic table. utf8mb4 costs 4
 *    bytes a character:
 *
 *        user_id     bigint unsigned      8
 *        device_id   varchar(40)   ×4 =  160
 *        pref_key    varchar(96)   ×4 =  384
 *                                     -----
 *                                       552   ✓
 *
 *    VARCHAR(40) is sized for a ULID (26 characters) with room to spare, and
 *    deliberately not larger: at VARCHAR(191) this index would be 1,148 bytes
 *    and would fail on live while passing on dev.
 *
 * ── EXISTENCE IS CHECKED WITH RAW information_schema ────────────────────────
 *
 * Never `Schema::hasColumn()` or `Schema::hasTable()`. Both read
 * `generation_expression`, which does not exist on MariaDB 10.1, so they THROW
 * there rather than answering. Every guarded migration in this project queries
 * information_schema directly for that reason.
 */
return new class extends Migration
{
    private const TABLE = 'user_preferences';

    private const OLD_INDEX = 'user_preferences_user_key_unique';

    private const NEW_INDEX = 'user_preferences_user_device_key_unique';

    public function up(): void
    {
        $connection = DB::connection();

        if (!$this->hasColumn($connection, 'device_id')) {
            /*
             * NOT NULL DEFAULT '' rather than nullable, so every existing row
             * becomes an account-level default with no UPDATE pass, and so the
             * column can sit in a unique index that actually constrains.
             */
            $connection->statement(
                'ALTER TABLE ' . self::TABLE . " ADD COLUMN device_id VARCHAR(40) NOT NULL DEFAULT '' AFTER user_id"
            );
        }

        // FIRST the new index, so the foreign key never loses its cover.
        if (!$this->hasIndex($connection, self::NEW_INDEX)) {
            $connection->statement(
                'ALTER TABLE ' . self::TABLE . ' ADD UNIQUE ' . self::NEW_INDEX . ' (user_id, device_id, pref_key)'
            );
        }

        // THEN the old one.
        if ($this->hasIndex($connection, self::OLD_INDEX)) {
            $connection->statement('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::OLD_INDEX);
        }
    }

    public function down(): void
    {
        $connection = DB::connection();

        /*
         * Reversed, and in the mirror order.
         *
         * Restoring `(user_id, pref_key)` can only succeed once no two rows
         * share that pair - which is exactly what per-device rows create. Any
         * device-scoped row is dropped first, keeping the account defaults,
         * because a rollback that throws halfway leaves the table without a
         * usable unique key at all.
         */
        $connection->table(self::TABLE)->where('device_id', '!=', '')->delete();

        if (!$this->hasIndex($connection, self::OLD_INDEX)) {
            $connection->statement(
                'ALTER TABLE ' . self::TABLE . ' ADD UNIQUE ' . self::OLD_INDEX . ' (user_id, pref_key)'
            );
        }

        if ($this->hasIndex($connection, self::NEW_INDEX)) {
            $connection->statement('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::NEW_INDEX);
        }

        if ($this->hasColumn($connection, 'device_id')) {
            $connection->statement('ALTER TABLE ' . self::TABLE . ' DROP COLUMN device_id');
        }
    }

    private function hasColumn($connection, string $column): bool
    {
        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, $column]
        );
    }

    private function hasIndex($connection, string $index): bool
    {
        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [self::TABLE, $index]
        );
    }
};
