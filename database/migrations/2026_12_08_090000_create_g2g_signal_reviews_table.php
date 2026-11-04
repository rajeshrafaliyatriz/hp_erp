<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The decision history of a signal (slice 2 of the agentic CRM, Docs/agentic-crm/slices/S02-signals-front-door.md).
 *
 * Until now deciding on a signal OVERWROTE the previous decision in g2g_company_opportunities.review_status / reviewed_by / reviewed_at / review_notes,
 * kept no reason and no history. This table is append-only: one row per decision, never edited. The four review_* columns on the signal stay and keep
 * showing the LATEST decision, so the original Signals screen is untouched.
 *
 *   decision      reviewed | dismissed | follow_up | reopened | promoted     (follow_up is what the screens call "Contacted")
 *   reason_code   one of config('signals.review.reasons'); required to dismiss on the new screen, NULL where it was never known (backfilled rows)
 *   from_status / to_status   the signal's review_status before and after (New, Reviewed, Follow-up, Dismissed)
 *   via           signal_review (new screen) | legacy_screen (the original screen's buttons) | backfill (made from the old columns) | promote
 *   decided_by    the user id; NULL when it was never recorded
 *
 * ADDITIVE and idempotent. No foreign keys and no JSON column: the live host is MariaDB 10.1.48, which has no JSON type. The raw DDL states utf8mb4
 * explicitly because the dev database's own default charset is latin1. The first timestamp column has an explicit NULL default so MariaDB adds no
 * ON UPDATE clause to it (DB-RULES.md). There is deliberately NO unique key: two decisions in the same second are legitimate.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_08_090000_create_g2g_signal_reviews_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_090000_create_g2g_signal_reviews_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_signal_reviews'"))) {
                DB::statement("CREATE TABLE `g2g_signal_reviews` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `opportunity_id` BIGINT UNSIGNED NOT NULL,
                    `decision` VARCHAR(12) NOT NULL,
                    `reason_code` VARCHAR(30) NULL,
                    `note` TEXT NULL,
                    `from_status` VARCHAR(12) NOT NULL,
                    `to_status` VARCHAR(12) NOT NULL,
                    `via` VARCHAR(16) NOT NULL DEFAULT 'signal_review',
                    `decided_by` BIGINT UNSIGNED NULL,
                    `decided_at` DATETIME NOT NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_sr_tenant_opp_decided` (`sub_institute_id`, `opportunity_id`, `decided_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_signal_reviews')) {
            Schema::create('g2g_signal_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('opportunity_id');
                $table->string('decision', 12);
                $table->string('reason_code', 30)->nullable();
                $table->text('note')->nullable();
                $table->string('from_status', 12);
                $table->string('to_status', 12);
                $table->string('via', 16)->default('signal_review');
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at');
                $table->timestamp('created_at')->nullable();
                $table->index(['sub_institute_id', 'opportunity_id', 'decided_at'], 'g2g_sr_tenant_opp_decided');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_signal_reviews');
    }
};
