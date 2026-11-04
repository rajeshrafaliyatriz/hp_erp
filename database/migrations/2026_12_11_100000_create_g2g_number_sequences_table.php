<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A counter that hands out numbers without ever handing the same one twice (slice 6 of the agentic CRM, Docs/agentic-crm/slices/S06-leads.md, defect D8).
 *
 * The lead number used to be "the highest number in crm_leads plus one", read and then written with nothing between the two: two leads made at the same
 * moment both read 41 and both tried to save LD-00042, and the second failed on the unique index. One row per key here, raised in ONE statement, is what
 * makes that impossible (App\Domain\Numbering\NumberSequence).
 *
 *   seq_key      what is being counted, `<name>:<scope>` (the lead counter is `lead:all`: crm_leads.lead_no is unique across every organisation, so the
 *                counter has to be too)
 *   next_value   the number the NEXT claim receives
 *   updated_at   when it was last raised
 *
 * ADDITIVE: one new table, nothing existing is touched. No foreign keys (repo convention, MariaDB 10.1). Raw DDL states utf8mb4 (dev's database default is
 * latin1) and the key is 60 characters, 240 bytes, well inside the 767-byte limit of the Compact row format on live. `updated_at` has an explicit NULL
 * default so MariaDB adds no ON UPDATE clause to it. Guarded: a second run does nothing. down() drops only what up() made.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_100000_create_g2g_number_sequences_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_number_sequences')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("CREATE TABLE `g2g_number_sequences` (
                `seq_key` VARCHAR(60) NOT NULL,
                `next_value` BIGINT UNSIGNED NOT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`seq_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            return;
        }

        Schema::create('g2g_number_sequences', function (Blueprint $table) {
            $table->string('seq_key', 60)->primary();
            $table->unsignedBigInteger('next_value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_number_sequences');
    }
};
