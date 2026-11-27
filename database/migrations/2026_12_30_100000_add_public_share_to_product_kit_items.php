<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The public share link of a Sales Kit item (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md, part B): a person who manages the kit makes a link that anybody can open without
 * signing in, to send a customer one deck, brochure or video.
 *
 *   share_token       the secret of the link (48 random letters and digits); NULL = the item is not shared. UNIQUE, and the public routes look it up by equality on this index
 *   share_expires_at  when the link stops working; NULL = it never expires
 *   shared_at         when the link was made or last renewed (a renewed link has a new token, so the old one stops at once)
 *   shared_by         the user who made it
 *
 * ADDITIVE and idempotent: it adds four columns and one index to g2g_product_kit_items and changes nothing existing; each is added only when it is not there already (a literal SHOW COLUMNS /
 * SHOW INDEX, the form that works on both hosts), and it does nothing when the table is missing. No foreign key and no JSON column: the live host is MariaDB 10.1.48. Every timestamp has an
 * explicit NULL default, so MariaDB adds no ON UPDATE clause. The unique index is on a VARCHAR(64) column of utf8mb4, 256 bytes, well inside the 767-byte limit of the Compact row format; a
 * NULL is allowed any number of times, so the many items that are not shared do not collide. down() drops only what up() added.
 *
 *   php artisan migrate --path=database/migrations/2026_12_30_100000_add_public_share_to_product_kit_items.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_product_kit_items';

    private const INDEX = 'g2g_pki_share_token';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $add = [
                'share_token' => '`share_token` VARCHAR(64) NULL',
                'share_expires_at' => '`share_expires_at` TIMESTAMP NULL DEFAULT NULL',
                'shared_at' => '`shared_at` TIMESTAMP NULL DEFAULT NULL',
                'shared_by' => '`shared_by` BIGINT UNSIGNED NULL',
            ];
            foreach ($add as $column => $definition) {
                if (empty(DB::select("SHOW COLUMNS FROM `" . self::TABLE . "` LIKE '{$column}'"))) {
                    DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN ' . $definition);
                }
            }
            if (empty(DB::select('SHOW INDEX FROM `' . self::TABLE . "` WHERE Key_name = '" . self::INDEX . "'"))) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `' . self::INDEX . '` (`share_token`)');
            }

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'share_token')) {
                $table->string('share_token', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'share_expires_at')) {
                $table->timestamp('share_expires_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'shared_at')) {
                $table->timestamp('shared_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'shared_by')) {
                $table->unsignedBigInteger('shared_by')->nullable();
            }
        });
        $indexes = array_column(Schema::getIndexes(self::TABLE), 'name');
        if (! in_array(self::INDEX, $indexes, true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->unique('share_token', self::INDEX));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! empty(DB::select('SHOW INDEX FROM `' . self::TABLE . "` WHERE Key_name = '" . self::INDEX . "'"))) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
            }
            foreach (['share_token', 'share_expires_at', 'shared_at', 'shared_by'] as $column) {
                if (! empty(DB::select("SHOW COLUMNS FROM `" . self::TABLE . "` LIKE '{$column}'"))) {
                    DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . $column . '`');
                }
            }

            return;
        }

        $indexes = array_column(Schema::getIndexes(self::TABLE), 'name');
        if (in_array(self::INDEX, $indexes, true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        }
        $present = array_values(array_filter(['share_token', 'share_expires_at', 'shared_at', 'shared_by'], fn ($c) => Schema::hasColumn(self::TABLE, $c)));
        if ($present !== []) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
