<?php

use App\Services\Ai\AiPolicyResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One worked example in AI Policies.
 *
 * WHY THIS IS SEEDED MORE CAREFULLY THAN THE TEMPLATE EXAMPLE
 *
 * A template that nobody uses does nothing. A policy is different: it is a claim
 * about what people are permitted to do, and `AiPolicyResolver` is built to enforce
 * it. Seeding one carelessly would mean the platform starts refusing operations that
 * no administrator ever agreed to refuse — and because nothing calls the resolver
 * yet, that would not surface until enforcement was wired, long after anyone
 * remembered this migration ran.
 *
 * Three decisions keep that from happening, and each is the reason this file is not
 * simply a copy of the template seeder:
 *
 *   1. SCOPED TO A MODULE, NOT GLOBAL. A `global` assignment at platform scope
 *      matches every organisation and becomes their fallback policy. This one is
 *      assigned to the `capability_intelligence` module, so it resolves only for
 *      callers acting in that module. It is also the more useful example: module
 *      scoping is the case an administrator will actually want to copy, and a global
 *      policy would teach the blunter instrument.
 *
 *   2. ITS RULES ARE THE CATALOGUE DEFAULTS. Every switch below is set to the
 *      `default` that `AiPolicyResolver::ruleCatalogue()` already declares. So the
 *      example tightens nothing beyond what an administrator opening a blank form
 *      would see pre-ticked. It demonstrates the shape of a policy without quietly
 *      imposing one.
 *
 *   3. PLATFORM-SCOPED, THEREFORE READ-ONLY. `sub_institute_id` is NULL, so every
 *      organisation can read it and none can edit it — `AiPolicyController::update()`
 *      refuses a platform row with a 403 that says to create their own instead. An
 *      organisation's own policy takes precedence over this one, which is how the
 *      example is meant to be superseded.
 *
 * SAFE TO RE-RUN. Guarded on the policy name at platform scope, so a second run is a
 * no-op and nothing an organisation has written is touched.
 *
 *   php artisan migrate --path=database/migrations/2026_09_21_130000_seed_example_ai_policy.php
 */
return new class extends Migration
{
    private const MODULE_KEY = 'capability_intelligence';

    private const POLICY_NAME = 'Competency assessment integrity (example)';

    private const DESCRIPTION = <<<'TXT'
A worked example. AI may help a person think, explain and write — brainstorming an
approach, clarifying what a competency means, tidying their wording. It may not
produce the judgement itself: not the answers in an assessment, not a proposed
proficiency rating, and nothing that acts without a person approving it first.

Anyone who used AI on work covered by this policy must say so.

Read it to see how the pieces fit together, then create your own policy to replace
it — an organisation's own policy takes precedence over this one.
TXT;

    public function up(): void
    {
        if (! Schema::hasTable('ai_policies') || ! Schema::hasTable('ai_modules')) {
            return;
        }

        // Idempotent on the name at platform scope. An organisation that wrote its
        // own policy with the same name is a different row and is left alone.
        $exists = DB::table('ai_policies')
            ->where('name', self::POLICY_NAME)
            ->whereNull('sub_institute_id')
            ->exists();

        if ($exists) {
            return;
        }

        // The module must genuinely exist on this deployment. Assigning a policy to a
        // module key that is not here would store a scope that resolves to nothing —
        // an example demonstrating a broken state.
        $module = DB::table('ai_modules')
            ->where('module_key', self::MODULE_KEY)
            ->whereNull('sub_institute_id')
            ->first();

        if ($module === null) {
            return;
        }

        $policyId = DB::table('ai_policies')->insertGetId([
            'sub_institute_id' => null,
            'name' => self::POLICY_NAME,
            'description' => trim(self::DESCRIPTION),
            // The catalogue's own vocabulary: AI helps, the person decides.
            'policy_type' => 'ai_assisted',
            'status' => 1,
            // The one obligation this example does impose, and the least intrusive
            // one available: say that you used it.
            'require_disclosure' => 1,
            'require_acknowledgement' => 0,
            // Both left off. Neither has an implementation behind it in G2G, and a
            // switch that reads "required" while nothing checks it is worse than one
            // that is off — it reads as a control that exists.
            'ai_detection_required' => 0,
            'plagiarism_check_required' => 0,
            'detection_provider' => null,
            'detection_threshold' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedRules($policyId);
        $this->assignToModule($policyId, (int) $module->id);

        if (Schema::hasTable('ai_audit_logs')) {
            DB::table('ai_audit_logs')->insert([
                'event_type' => 'ai.policy.created',
                'actor_type' => 'system',
                'actor_label' => 'migration',
                'related_type' => 'ai_policies',
                'related_id' => $policyId,
                'outcome' => 'success',
                'message' => 'Example policy seeded for ' . $module->label . '.',
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_policies')) {
            return;
        }

        $ids = DB::table('ai_policies')
            ->where('name', self::POLICY_NAME)
            ->whereNull('sub_institute_id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach (['ai_policy_rules', 'ai_policy_assignments'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('policy_id', $ids)->delete();
            }
        }

        // Only the platform row — an organisation's own policies are never touched by
        // rolling this back.
        DB::table('ai_policies')->whereIn('id', $ids)->delete();
    }

    /**
     * The switches, taken from the live catalogue rather than written out here.
     *
     * Reading `ruleCatalogue()` is what guarantees the example stays consistent with
     * the form: a rule added to the catalogue later appears on the edit screen, and
     * a hard-coded list here would leave the example missing it and silently
     * disagreeing with every policy written afterwards.
     */
    private function seedRules(int $policyId): void
    {
        if (! Schema::hasTable('ai_policy_rules')) {
            return;
        }

        $rows = [];

        foreach ((new AiPolicyResolver())->ruleCatalogue() as $rule) {
            $rows[] = [
                'policy_id' => $policyId,
                'rule_key' => $rule['key'],
                // The catalogue's own default. See decision 2 in the class note: the
                // example must not tighten anything beyond a blank form's defaults.
                'rule_value' => $rule['default'] ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('ai_policy_rules')->insert($rows);
        }
    }

    /**
     * Assign it to the module, at platform scope.
     *
     * `scope_id` is the `ai_modules` row id, looked up rather than hardcoded — ids
     * differ between deployments, and a literal here would assign the policy to
     * whichever module happened to hold that number elsewhere.
     *
     * `sub_institute_id` is NULL so the assignment travels with the platform policy
     * it belongs to. Stamping an organisation here would make the example apply to
     * one tenant and be invisible to the rest.
     */
    private function assignToModule(int $policyId, int $moduleId): void
    {
        if (! Schema::hasTable('ai_policy_assignments')) {
            return;
        }

        DB::table('ai_policy_assignments')->insert([
            'policy_id' => $policyId,
            'scope_type' => 'module',
            'scope_id' => $moduleId,
            'sub_institute_id' => null,
            'status' => 1,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
