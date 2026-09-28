<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the shared (LMS_K12-universal) AI Stack needs from `ai_modules`, and the one table
 * its Models tab writes.
 *
 * `capabilities` — the same JSON flag map LMS_K12's `ai_modules` carries
 * (conversational / generative / agent / workflow / ontology). The shared Guardrails and
 * Models screens read it: Models offers a model choice only for a capability the module
 * actually uses.
 *
 * `registry_keys` — G2G-only. The `AiModuleRegistry` keys (`lms_content_ai`,
 * `eso_intelligence`, ...) of the real backend consumers behind this module. G2G meters
 * usage and binds credentials per consumer, not per menu module, so this is the join
 * between the two vocabularies. Each value below names a consumer a code audit found
 * actually running for that screen.
 *
 * `ai_module_model_bindings` — identical in shape to LMS_K12's: product module ×
 * capability → provider/model/credential. `AiConfigurationResolver` consults it ahead of
 * the central configuration only when a caller names the product module, so every
 * existing caller resolves exactly as before.
 *
 * Only the nine AI Stack modules are seeded; the eight top-level rows are untouched.
 */
return new class extends Migration
{
    /** module_key => [capabilities, registry_keys] */
    private const SEED = [
        'lms_course_builder' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => false, 'ontology' => false], ['lms_content_ai', 'presentation_ai']],
        'lms_assessments' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => false, 'ontology' => false], ['lms_content_ai']],
        'lms_my_learning' => [['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => false, 'ontology' => false], ['recommendation_ai']],
        'lms_learning_catalog' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => false, 'ontology' => false], ['lms_content_ai', 'presentation_ai']],
        'capability_library' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => true, 'ontology' => true], ['eso_intelligence']],
        'capability_explorer' => [['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => false, 'ontology' => true], []],
        'talent_recruitment' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => false, 'ontology' => false], ['recruitment_ai', 'assessment_ai']],
        'talent_administration' => [['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => true, 'ontology' => false], ['recruitment_ai']],
        'task_my_tasks' => [['conversational' => true, 'generative' => true, 'agent' => true, 'workflow' => false, 'ontology' => false], ['eso_intelligence']],
    ];

    public function up(): void
    {
        if (Schema::hasTable('ai_modules')) {
            Schema::table('ai_modules', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_modules', 'capabilities')) {
                    $table->text('capabilities')->nullable()->after('description');
                }
                if (! Schema::hasColumn('ai_modules', 'registry_keys')) {
                    $table->text('registry_keys')->nullable()->after('capabilities');
                }
            });

            foreach (self::SEED as $key => [$capabilities, $registryKeys]) {
                DB::table('ai_modules')
                    ->where('module_key', $key)
                    ->whereNull('sub_institute_id')
                    ->update([
                        'capabilities' => json_encode($capabilities),
                        'registry_keys' => json_encode($registryKeys),
                        'updated_at' => now(),
                    ]);
            }
        }

        if (! Schema::hasTable('ai_module_model_bindings')) {
            Schema::create('ai_module_model_bindings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('product_module', 80);
                $table->string('capability', 100);
                $table->string('provider', 60)->nullable();
                $table->string('model', 190)->nullable();
                $table->unsignedBigInteger('api_key_id')->nullable();
                $table->unsignedInteger('max_output_tokens')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->unsignedBigInteger('sub_institute_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['product_module', 'capability'], 'ai_mmb_module_capability');
                $table->index('sub_institute_id', 'ai_mmb_institute');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_module_model_bindings');

        if (Schema::hasTable('ai_modules')) {
            Schema::table('ai_modules', function (Blueprint $table) {
                foreach (['registry_keys', 'capabilities'] as $column) {
                    if (Schema::hasColumn('ai_modules', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
