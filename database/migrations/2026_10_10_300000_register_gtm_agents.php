<?php

use App\Domain\Gtm\Agents\GtmAgentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Registers the five GTM agents in the existing Agentic AI store (agentic_agents) as platform
 * agents (sub_institute_id NULL, origin 'platform'), the same shape as the other platform agents.
 *
 *   php artisan migrate --path=database/migrations/2026_10_10_300000_register_gtm_agents.php
 *
 * Deal Coach and RevOps are registered as 'draft' because they judge deal records that G2G does
 * not hold yet; a later migration activates them when the deals table exists. sub_module is
 * 'gtm', which is deliberately NOT an ai_modules key, so these never appear in a module AI
 * Stack's Automations tab. Idempotent: an existing row is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (GtmAgentService::AGENTS as $class) {
            if (DB::table('agentic_agents')->whereNull('sub_institute_id')->where('slug', $class::slug())->exists()) {
                continue;
            }
            DB::table('agentic_agents')->insert([
                'sub_institute_id' => null, 'origin' => 'platform', 'slug' => $class::slug(), 'name' => $class::name(),
                'description' => $class::description(), 'module' => 'GTM & Revenue', 'sub_module' => 'gtm', 'role' => 'analyst',
                'model' => 'central-ai-config', 'system_prompt' => null, 'execution_mode' => 'none', 'tools' => json_encode($class::reads()),
                'status' => in_array($class::slug(), ['gtm-deal-coach', 'gtm-revops'], true) ? 'draft' : 'deployed',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $slugs = array_map(fn ($c) => $c::slug(), GtmAgentService::AGENTS);
        $ids = DB::table('agentic_agents')->whereNull('sub_institute_id')->whereIn('slug', $slugs)->pluck('id');
        DB::table('agentic_agent_runs')->whereIn('agent_id', $ids)->delete();
        DB::table('agentic_agents')->whereIn('id', $ids)->delete();
    }
};
