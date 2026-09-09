<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONE ORGANISATION PROFILE PER ORGANISATION.
 *
 *   php artisan migrate --path=database/migrations/2026_09_08_110000_unique_org_details_per_tenant.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_08_110000_unique_org_details_per_tenant.php
 *
 * ── THE CODE ALREADY ASSUMES THIS; THE SCHEMA DID NOT ENFORCE IT ────────────
 *
 * `organizationDetailsController::store()` writes with
 * `updateOrCreate(['sub_institute_id' => $tenant], [...])`, and
 * `OrganizationSetupController::profileStep()` reads with `->first()`. Both are
 * written as though a tenant has exactly one row - and nothing stopped it having
 * two. Two concurrent saves, or one save racing the new provisioner's insert,
 * and the organisation quietly acquires a second profile; `first()` then returns
 * whichever the optimiser felt like, so the same screen shows different details
 * on different requests and neither is wrong.
 *
 * Measured before writing this, on both databases:
 *
 *     dev   9 rows, 0 tenants with more than one, 0 with a NULL tenant
 *     live  4 rows, 0 tenants with more than one, 0 with a NULL tenant
 *
 * So the constraint is already true of the data and this only makes it stay
 * true. The check is repeated at run time rather than trusted, because the
 * measurement above was taken on one day and this migration runs on another.
 *
 * ── LIVE ────────────────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: the index is on one BIGINT, 8 bytes, nowhere near the
 * 767-byte prefix limit. The name is 34 characters, inside the 64-char cap.
 * information_schema is used for the existence check because Schema::hasTable()
 * throws on 10.1.
 */
return new class extends Migration
{
    private const TABLE = 'org_details';
    private const INDEX = 'org_details_sub_institute_id_unique';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || $this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        /*
         * REFUSE RATHER THAN DESTROY.
         *
         * If a tenant has picked up a second profile row since the measurement
         * above, this migration must not decide which one to delete - that is a
         * customer's registered address, and choosing between two of them is not
         * a schema change. It stops and says what to look at.
         */
        $duplicates = DB::table(self::TABLE)
            ->selectRaw('sub_institute_id, COUNT(*) AS c')
            ->whereNotNull('sub_institute_id')
            ->groupBy('sub_institute_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to add a unique index: these tenants have more than one org_details row - '
                . $duplicates->map(fn ($d) => $d->sub_institute_id . ' (' . $d->c . ')')->implode(', ')
                . '. Decide which row is the real one and remove the others first.'
            );
        }

        /*
         * ORDER MATTERS, and the first attempt got it wrong:
         *
         *   SQLSTATE[HY000] 1553: Cannot drop index
         *   'org_details_sub_institute_id_index': needed in a foreign key
         *   constraint
         *
         * `sub_institute_id` carries an FK to school_setup.id, and InnoDB
         * requires an index on the child column. The plain index was the only
         * one, so dropping it first left the constraint unbacked and MariaDB
         * refused - correctly.
         *
         * Adding the unique index FIRST gives the foreign key an index to lean
         * on, after which the old one is genuinely redundant and drops cleanly.
         */
        DB::statement(
            'ALTER TABLE `org_details`
             ADD UNIQUE KEY `org_details_sub_institute_id_unique` (`sub_institute_id`)'
        );

        // Now redundant: MySQL would pick the unique index for every lookup, so
        // the old one is pure write cost on every insert and update.
        if ($this->indexExists(self::TABLE, 'org_details_sub_institute_id_index')) {
            DB::statement('ALTER TABLE `org_details` DROP INDEX `org_details_sub_institute_id_index`');
        }
    }

    public function down(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        // The plain index goes back FIRST, for the same reason up() adds the
        // unique one first: the foreign key needs an index on this column at
        // every moment, and dropping the only one is refused.
        if (!$this->indexExists(self::TABLE, 'org_details_sub_institute_id_index')) {
            DB::statement(
                'ALTER TABLE `org_details`
                 ADD INDEX `org_details_sub_institute_id_index` (`sub_institute_id`)'
            );
        }

        DB::statement('ALTER TABLE `org_details` DROP INDEX `org_details_sub_institute_id_unique`');
    }

    /** information_schema, not Schema::hasTable() - that throws on live's 10.1. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }

    private function indexExists(string $table, string $index): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ));
    }
};
