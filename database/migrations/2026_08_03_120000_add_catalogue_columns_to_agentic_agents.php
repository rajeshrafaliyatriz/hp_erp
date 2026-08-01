<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn agentic_agents into the whole catalogue, not just tenant-created agents.
 *
 * The legacy Agent Library rendered thirteen agents hardcoded in the JavaScript
 * bundle. That made them identical for every customer, uneditable without a
 * redeploy, and — most importantly — left nowhere to record the endpoint that
 * actually performs the work. Anything the operator wanted to change (a link, a
 * description, a HuggingFace URL) was a code change.
 *
 * Two things are added here:
 *
 *  1. Catalogue content — the Function / Workflow / Outputs / call-to-action
 *     copy the cards render. `origin = 'platform'` rows have a NULL
 *     sub_institute_id: curated content every tenant sees and nobody edits in
 *     place, the same shape used for s_invisible_library.
 *
 *  2. An execution endpoint — where a run is actually dispatched. `none` keeps
 *     today's behaviour (a run is recorded and reported into); `http` posts to
 *     a HuggingFace Space, an n8n webhook, or anything else that speaks HTTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agentic_agents')) {
            return;
        }

        // Platform rows belong to no tenant, so the column has to allow NULL.
        DB::statement('ALTER TABLE agentic_agents MODIFY sub_institute_id BIGINT UNSIGNED NULL');

        Schema::table('agentic_agents', function (Blueprint $table) {
            // 'platform' = curated catalogue entry, 'tenant' = created here.
            $table->string('origin', 20)->default('tenant')->after('sub_institute_id');
            /** Stable key for seeding and for re-running the seeder idempotently. */
            $table->string('slug', 120)->nullable()->after('origin');
            /** lucide icon name the card renders. */
            $table->string('icon', 60)->nullable()->after('slug');

            // Catalogue copy.
            $table->longText('function_text')->nullable()->after('system_prompt');
            /** Ordered steps, e.g. ["User initiates…","Agent analyses…"]. */
            $table->json('workflow')->nullable()->after('function_text');
            /** What the agent produces, e.g. ["Smart Task Assignments", …]. */
            $table->json('outputs')->nullable()->after('workflow');

            // Where the card's button goes.
            $table->string('cta_label', 120)->nullable()->after('outputs');
            $table->string('cta_link', 500)->nullable()->after('cta_label');
            // 'internal' routes inside this app; 'external' opens a new tab.
            $table->string('cta_target', 20)->default('internal')->after('cta_link');

            // How a run is performed.
            $table->string('execution_mode', 20)->default('none')->after('cta_target');
            $table->string('endpoint_url', 500)->nullable()->after('execution_mode');
            $table->string('endpoint_method', 10)->default('POST')->after('endpoint_url');
            /** Extra request headers, e.g. {"Authorization":"Bearer …"}. */
            $table->json('endpoint_headers')->nullable()->after('endpoint_method');
            /** Seconds to wait before giving up on the endpoint. */
            $table->unsignedSmallInteger('endpoint_timeout')->default(60)->after('endpoint_headers');

            $table->index(['origin', 'slug'], 'agentic_agents_origin_slug_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('agentic_agents')) {
            return;
        }

        Schema::table('agentic_agents', function (Blueprint $table) {
            $table->dropIndex('agentic_agents_origin_slug_index');
            $table->dropColumn([
                'origin', 'slug', 'icon',
                'function_text', 'workflow', 'outputs',
                'cta_label', 'cta_link', 'cta_target',
                'execution_mode', 'endpoint_url', 'endpoint_method', 'endpoint_headers', 'endpoint_timeout',
            ]);
        });

        // Platform rows would violate the NOT NULL, so they go with the columns.
        DB::table('agentic_agents')->whereNull('sub_institute_id')->delete();
        DB::statement('ALTER TABLE agentic_agents MODIFY sub_institute_id BIGINT UNSIGNED NOT NULL');
    }
};
