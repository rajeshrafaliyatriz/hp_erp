<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third-party integrations connected to the LMS.
 *
 * New model: routes/api.php references NangoController for Google OAuth, but
 * that class does not exist and nothing persists connection state, so there was
 * no way to answer "what is connected". This table is that record.
 *
 * IMPORTANT - no secrets here. `config` holds non-sensitive settings only
 * (endpoints, scopes, sync options). Access tokens and API keys stay with the
 * OAuth provider or in server-side config; storing them in a table the
 * governance UI reads would put credentials back on the wire, which is exactly
 * the class of problem just fixed in table_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            /** Machine name: 'google', 'zoom', 'teams', 'scorm_cloud'. */
            $table->string('provider', 100);
            $table->string('display_name', 191);
            $table->string('category', 50)->nullable();
            $table->text('description')->nullable();

            /** 'connected', 'disconnected', 'error'. */
            $table->string('status', 20)->default('disconnected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();

            /** Non-sensitive settings only. See the note above. */
            $table->json('config')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One row per provider per tenant.
            $table->unique(['sub_institute_id', 'provider'], 'lms_integrations_tenant_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_integrations');
    }
};
