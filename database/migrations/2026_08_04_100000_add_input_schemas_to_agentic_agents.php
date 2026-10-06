<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every agent a typed input contract.
 *
 * Before this, running an agent meant typing free text into one box, because
 * the run payload had exactly one field. The agents in the catalogue do not
 * work that way: the Excel agent needs a spreadsheet and a connected Google
 * Sheet, the SEO agent needs a URL and an analysis mode, the marketing agent
 * needs a business type, an audience and a goal.
 *
 * Two schemas, because they answer different questions and have different
 * lifetimes:
 *   input_schema  - asked every run   ("what should I work on?")
 *   config_schema - asked once, saved ("which account do I work against?")
 *
 * Config values live in their own per-tenant table rather than on the agent,
 * so a shared platform agent can hold each organisation's own credentials
 * without being cloned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agentic_agents', function (Blueprint $table) {
            // Field definitions rendered as the launch form. Array of
            // {name,label,type,required,...} - see AgenticCatalogueSeeder.
            $table->json('input_schema')->nullable()->after('outputs');

            // One-time setup fields (credentials, sheet ids, API keys).
            $table->json('config_schema')->nullable()->after('input_schema');

            // Escape hatch: names a bespoke launch screen for the few agents a
            // generic form cannot express (file upload + header validation).
            // Null means "render the generic form from input_schema".
            $table->string('launch_component', 60)->nullable()->after('config_schema');
        });

        Schema::create('agentic_agent_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('agent_id');

            // Non-secret answers, readable back into the form.
            $table->json('values')->nullable();

            // Secret answers, encrypted at rest and never returned by the API.
            // Kept apart from `values` so "return the config" can never leak
            // one by accident - the read path simply does not touch this.
            $table->text('secrets')->nullable();

            $table->unsignedBigInteger('configured_by')->nullable();
            $table->timestamps();

            // One config row per tenant per agent.
            $table->unique(['sub_institute_id', 'agent_id'], 'agentic_agent_configs_tenant_agent_unique');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_agent_configs');

        Schema::table('agentic_agents', function (Blueprint $table) {
            $table->dropColumn(['input_schema', 'config_schema', 'launch_component']);
        });
    }
};
