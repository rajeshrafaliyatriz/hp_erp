<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Meta "Facebook Login for Business" apps sign people in with a saved Configuration (an id), not a permission list. An administrator enters that
 * Configuration ID on the connector screen, next to the App ID and App Secret, so nothing has to be added to the server environment file.
 *
 * ADDITIVE ONLY and idempotent: one nullable column. The id is not a secret (it appears in the login link), so it is stored as plain text.
 * SHOW COLUMNS is used on MySQL/MariaDB for the same reason the other connector migrations avoid information_schema.
 */
return new class extends Migration
{
    private function hasColumn(): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW COLUMNS FROM g2g_connector_provider_settings LIKE 'login_config_id'"));
        }

        return Schema::hasColumn('g2g_connector_provider_settings', 'login_config_id');
    }

    public function up(): void
    {
        if (! Schema::hasTable('g2g_connector_provider_settings') || $this->hasColumn()) {
            return;
        }
        Schema::table('g2g_connector_provider_settings', function (Blueprint $table) {
            $table->string('login_config_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('g2g_connector_provider_settings') && $this->hasColumn()) {
            Schema::table('g2g_connector_provider_settings', function (Blueprint $table) {
                $table->dropColumn('login_config_id');
            });
        }
    }
};
