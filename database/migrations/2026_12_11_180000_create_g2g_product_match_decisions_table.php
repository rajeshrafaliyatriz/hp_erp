<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The pair review's log (slice 15, track P2-D; Docs/agentic-crm/slices/S15-product-portfolio-merge.md sections 3 and 6, DECISIONS.md 2026-11-13: "You confirm
 * matches one by one").
 *
 * Today a CRM catalogue item (a master row of kind `item`) and a Portfolio Sub-product (a master row of kind `sub_product`) can describe the same product and nothing
 * joins them. A person looks at the suggested pairs and says "Same product" (the two records become ONE record: product-master:unify, ProductMerger) or "Different".
 * Every answer is a row here, so a decision is logged, a "Different" pair is never offered again, and a merge can be found and undone.
 *
 *   item_master_id       the catalogue item's master row (g2g_product_master.id, kind `item`). After a "same" decision this row is the HUSK: archived, origin `merged`,
 *                        no pointers, kept for ever. So this table is also the ALIAS of a retired master id: item_master_id -> survivor_master_id.
 *   survivor_master_id   the Sub-product's master row (kind `sub_product`) that now holds the catalogue item.
 *   decision             `same` or `different`.
 *   score, signals       what the suggester saw when the person decided (0 to 100, and a short sentence of reasons, no JSON): evidence for the log, never an input.
 *   name_kept            `portfolio` or `catalogue`: whose name the one record carries after a "same" decision. NULL for "different".
 *   decided_by/at        who decided (NULL when the owner's map file was applied by the command) and when.
 *
 * UNIQUE (sub_institute_id, item_master_id, survivor_master_id): one row per pair, every unique index includes the organisation. INDEX (sub_institute_id, decision).
 * NEW TABLE, ADDITIVE, no foreign keys (loose pointers, like the claims table; the checker lists orphans), tenant column BIGINT UNSIGNED NOT NULL (0 means shared, but
 * a pair is always one organisation's own), no JSON column and no expression default (live is MariaDB 10.1.48, Compact row format, not strict), explicit utf8mb4 in the
 * raw DDL (dev's database default is latin1). Both timestamps are nullable with an explicit NULL default so MariaDB adds no ON UPDATE clause to the first one
 * (DB-RULES.md); decided_at is a plain DATETIME.
 *
 * Guarded: a second run does nothing. down() drops the table up() made (the log goes with it: undo merges with `product-master:unify --rollback` first; the husk rows
 * and the repointed pointers stay either way).
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate, never the live connection):
 *   php artisan migrate --path=database/migrations/2026_12_11_180000_create_g2g_product_match_decisions_table.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_product_match_decisions';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->isMariaDb()) {
            DB::statement('CREATE TABLE `g2g_product_match_decisions` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `item_master_id` BIGINT UNSIGNED NOT NULL,
                `survivor_master_id` BIGINT UNSIGNED NOT NULL,
                `decision` VARCHAR(12) NOT NULL,
                `score` DECIMAL(5,2) NULL,
                `signals` VARCHAR(500) NULL,
                `name_kept` VARCHAR(12) NULL,
                `decided_by` BIGINT UNSIGNED NULL,
                `decided_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `g2g_pmd_tenant_pair` (`sub_institute_id`, `item_master_id`, `survivor_master_id`),
                KEY `g2g_pmd_tenant_decision` (`sub_institute_id`, `decision`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('item_master_id');
            $t->unsignedBigInteger('survivor_master_id');
            $t->string('decision', 12);
            $t->decimal('score', 5, 2)->nullable();
            $t->string('signals', 500)->nullable();
            $t->string('name_kept', 12)->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['sub_institute_id', 'item_master_id', 'survivor_master_id'], 'g2g_pmd_tenant_pair');
            $t->index(['sub_institute_id', 'decision'], 'g2g_pmd_tenant_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
