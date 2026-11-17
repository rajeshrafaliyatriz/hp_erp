<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Provider application credentials that an administrator enters in the app, per organisation.
 *
 *   g2g_connector_provider_settings   one row per organisation and provider (youtube | linkedin | meta). client_id and client_secret hold
 *                                     Laravel-encrypted values (the model's `encrypted` cast, keyed by APP_KEY): the columns are TEXT because
 *                                     the ciphertext is longer than the value. Nothing here is ever returned by an API.
 *   g2g_connector_setting_audits      who changed which provider's settings and when, and WHICH fields. Never a value.
 *
 * ADDITIVE ONLY and idempotent: nothing existing is changed or dropped. Server environment credentials keep working; a row here
 * overrides them for its organisation (see ConnectorCredentialResolver). SHOW TABLES is used on MySQL/MariaDB because the live
 * MariaDB 10.1 cannot answer Schema::hasTable()'s information_schema query reliably, as in the other connector migrations.
 */
return new class extends Migration
{
    private function hasTable(string $table): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW TABLES LIKE '{$table}'"));
        }

        return Schema::hasTable($table);
    }

    public function up(): void
    {
        if (! $this->hasTable('g2g_connector_provider_settings')) {
            Schema::create('g2g_connector_provider_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('provider', 20);                 // youtube | linkedin | meta
                $table->text('client_id');                      // encrypted
                $table->text('client_secret');                  // encrypted
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['sub_institute_id', 'provider'], 'g2g_connector_settings_tenant_provider');
            });
        }

        if (! $this->hasTable('g2g_connector_setting_audits')) {
            Schema::create('g2g_connector_setting_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('provider', 20);
                $table->string('action', 20);                   // created | updated | removed
                $table->string('fields', 120)->nullable();      // names only, e.g. "client_id,client_secret"
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['sub_institute_id', 'provider'], 'g2g_connector_audits_tenant_provider');
            });
        }
    }

    /** Dropping would delete every organisation's saved credentials; leave them. */
    public function down(): void
    {
    }
};
