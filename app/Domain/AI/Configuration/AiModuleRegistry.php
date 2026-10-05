<?php

namespace App\Domain\AI\Configuration;

/**
 * The AI modules an administrator can point at a provider — G2G's own list.
 *
 * WHY THIS LIST IS WRITTEN DOWN RATHER THAN DISCOVERED
 *
 * "Which parts of this product call a model" is not something the code can be
 * asked. The callers are spread across services, controllers and console commands,
 * and several reach a provider through a path that predates any shared client. A
 * registry is the honest way to name them: one row per module, each saying what it
 * is and whether the centralised configuration actually reaches it yet.
 *
 * `wired` IS THE HONEST HALF
 *
 * A module with `wired: true` resolves its provider, model and key through
 * `AiConfigurationResolver` at runtime — saving a configuration for it changes what
 * the next call does. A module with `wired: false` is a real part of the product
 * that still reaches its provider its own way; a configuration saved against it is
 * stored and shown, but nothing reads it yet. The screen says so rather than
 * implying a binding that does not exist, because a settings screen that silently
 * does nothing is worse than one that admits what it does not control.
 *
 * EVERY ROW BELOW NAMES A REAL G2G CONSUMER
 *
 * These are not LMS K-12's modules renamed. Each `consumer` is a class that exists
 * in this codebase and makes a model call today; nothing is listed speculatively,
 * because a dropdown entry for a feature that does not exist is a promise the
 * product cannot keep.
 *
 * Keys are stable and lowercase snake_case: they are written into
 * `ai_api_keys.ai_module` and must survive a label being reworded.
 */
final class AiModuleRegistry
{
    /**
     * @var array<int, array{key:string, label:string, description:string, wired:bool, consumer:string}>
     */
    private const MODULES = [
        [
            'key' => 'assessment_ai',
            'label' => 'Assessment AI',
            // Verified 2026-09-29: AiAssessmentController::generate() calls
            // DeepSeekService::chatJson() directly. It never passes through
            // AiConfigurationResolver, so a module binding saved for this capability
            // is stored and shown but does not change what this call sends. See the
            // note on eso_intelligence below for why that is not simply an oversight.
            'description' => 'Competency assessment generation and scoring — the DeepSeek client behind AiAssessmentController. A saved module binding is not read yet.',
            'wired' => false,
            'consumer' => 'App\Services\DeepSeekService',
        ],
        [
            'key' => 'eso_intelligence',
            'label' => 'ESO Intelligence',
            // Verified 2026-09-29: EsoGenerator::generateForTask() and
            // TaskExecutionClassifier both call DeepSeekService::chatJson() directly,
            // bypassing AiConfigurationResolver/ModuleModelBindings entirely.
            //
            // This is deliberate, not an oversight left half-finished: config/deepseek.php
            // and EsoGenerator::EXPECTED_MODEL both document a measured, named failure —
            // 'deepseek-v4-flash'/'deepseek-v4-pro' consume their entire output budget and
            // return nothing parseable, at 8-24x the cost of 'deepseek-chat', which is the
            // only model these generators are tuned for. `ai_models` is an admin-editable
            // catalogue (ModelCatalog), so wiring this capability today would let an admin
            // pick an untested model from this module's own Models tab and silently break
            // ESO generation and task classification for their whole tenant. Wiring this
            // safely needs a per-provider allowed-model check BEFORE a binding is trusted,
            // not just a resolver call — see Docs/cross-repo-audit for the full trace.
            'description' => 'Employee Skill Objective generation and task-execution classification. A saved module binding is not read yet — wiring it without a model allowlist would let an admin pick a model already measured to fail for this generator.',
            'wired' => false,
            'consumer' => 'App\Services\Competency\EsoGenerator',
        ],
        [
            'key' => 'recruitment_ai',
            'label' => 'Recruitment AI',
            // Verified 2026-09-29: AnalyzeJDController reads its Gemini key from a
            // separate legacy `gemini_api` table (keyed only by sub_institute_id) with
            // a hardcoded model in the request URL — it does not use ai_api_keys,
            // ai_module_model_bindings, or AiConfigurationResolver at all. This is a
            // different credential system, not just an unwired resolver call.
            'description' => 'Job-description analysis, interview question generation and resume screening. Reads a separate legacy credentials table, not this configuration — a bigger migration than a resolver call.',
            'wired' => false,
            'consumer' => 'App\Http\Controllers\Api\Gemini\AnalyzeJDController',
        ],
        [
            'key' => 'lms_content_ai',
            'label' => 'LMS Content AI',
            // Verified 2026-09-29: AiCourseController::generateOutline()/generatePresentation()
            // and CourseQuizGenerator both call DeepSeekService directly. generateOutline()
            // already accepts an optional `model` request field, but nothing in g2gv0 ever
            // sends it, and it is never sourced from this module's saved binding — the same
            // deepseek-chat-only constraint noted on eso_intelligence applies here too.
            'description' => 'Course outlines, quizzes and lesson content for the learning module. A saved module binding is not read yet — see eso_intelligence for why that needs a model allowlist, not just a resolver call.',
            'wired' => false,
            'consumer' => 'App\Services\Lms\CourseQuizGenerator',
        ],
        [
            'key' => 'agent_reasoning',
            'label' => 'Agent Reasoning',
            // Agentic AI agents carry their own `model` column and, for HTTP agents,
            // their own endpoint. Marked unwired because saving a configuration here
            // does not yet override an agent's own row — see RunController.
            'description' => 'Planning and tool selection for Agentic AI runs. Agents currently carry their own model and endpoint.',
            'wired' => false,
            'consumer' => 'App\Http\Controllers\Api\Agentic\RunController',
        ],
        [
            'key' => 'conversational_ai',
            'label' => 'Conversational AI',
            // Verified 2026-10-05: AskPipeline::MODULE and AiGenerationController both call
            // AiModelClient::complete('conversational_ai', ...), so a saved configuration is
            // read by the AI Stack assistant and by template generation. The separate Next.js
            // chat route still holds its own key and does not read it.
            'description' => 'The AI Stack assistant and template generation. The separate frontend chat route still uses its own key.',
            'wired' => true,
            'consumer' => 'App\Domain\AI\Conversation\AskPipeline',
        ],
        [
            'key' => 'recommendation_ai',
            'label' => 'Recommendation AI',
            'description' => 'Course and development-path recommendations over the competency taxonomy.',
            'wired' => false,
            'consumer' => 'App\Http\Controllers\courseRecommandation',
        ],
        [
            'key' => 'skill_intelligence',
            'label' => 'Skill Intelligence',
            'description' => 'Skill matching, heat-mapping and gap analysis across the organisation.',
            'wired' => false,
            'consumer' => 'App\Services\SkillHeatmapService',
        ],
        [
            'key' => 'presentation_ai',
            'label' => 'Presentation Generation',
            'description' => 'Deck and document generation through the Gamma integration.',
            'wired' => false,
            'consumer' => 'App\Services\GammaService',
        ],
        [
            'key' => 'analytics_ai',
            'label' => 'Report & Analytics AI',
            // Verified 2026-10-05: EvaluationRunner::MODULE is 'analytics_ai' and calls
            // AiModelClient::complete(), which resolves through AiConfigurationResolver, so a
            // configuration saved here is read by the next evaluation run.
            'description' => 'AI evaluation runs over the assistant\'s answers and templates. Resolved through this configuration; screen-analysis actions elsewhere are not routed here yet.',
            'wired' => true,
            'consumer' => 'App\Domain\AI\Evaluation\EvaluationRunner',
        ],
        [
            'key' => 'signals',
            'label' => 'Signals',
            // Verified 2026-10-05: StructuredAi (company-opportunity research and the ingestion
            // analyser), SignalGenerator and ProviderStatus all call AiModelClient::complete()
            // with the module chosen by SignalsAiModule — `signals`, or the legacy
            // `analytics_ai` for an organisation that configured that before this module existed.
            'description' => 'Department Signals — company opportunities, the ingestion engine and signal generation. Resolved through this configuration; with none saved for Signals, a configuration saved for Report & Analytics AI is still used.',
            'wired' => true,
            'consumer' => 'App\Domain\Signals\Support\StructuredAi',
        ],
    ];

    /** @return array<int, array{key:string, label:string, description:string, wired:bool, consumer:string}> */
    public function all(): array
    {
        return self::MODULES;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_column(self::MODULES, 'key');
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /** @return array{key:string, label:string, description:string, wired:bool, consumer:string}|null */
    public function find(string $key): ?array
    {
        foreach (self::MODULES as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    public function label(string $key): string
    {
        return $this->find($key)['label'] ?? $key;
    }

    public function isWired(string $key): bool
    {
        return (bool) ($this->find($key)['wired'] ?? false);
    }
}
