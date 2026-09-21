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
            'description' => 'Competency assessment generation and scoring — the DeepSeek client behind AiAssessmentController.',
            'wired' => true,
            'consumer' => 'App\Services\DeepSeekService',
        ],
        [
            'key' => 'eso_intelligence',
            'label' => 'ESO Intelligence',
            'description' => 'Employee Skill Objective generation and task-execution classification.',
            'wired' => true,
            'consumer' => 'App\Services\Competency\EsoGenerator',
        ],
        [
            'key' => 'recruitment_ai',
            'label' => 'Recruitment AI',
            'description' => 'Job-description analysis, interview question generation and resume screening.',
            'wired' => true,
            'consumer' => 'App\Http\Controllers\Api\Gemini\AnalyzeJDController',
        ],
        [
            'key' => 'lms_content_ai',
            'label' => 'LMS Content AI',
            'description' => 'Course outlines, quizzes and lesson content for the learning module.',
            'wired' => true,
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
            // The assistant panel calls the Next.js route, which holds its own key.
            // Listed because it is a real consumer and its spend is real; flagged
            // unwired because that route does not read this configuration.
            'description' => 'The assistant panel and its chat route. Served from the frontend today, so it does not read this configuration yet.',
            'wired' => false,
            'consumer' => 'app/api/agent/chat',
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
            'description' => 'Analyse-this-screen actions over dashboards, reports and lists.',
            'wired' => false,
            'consumer' => 'App\Http\Controllers\dashboards',
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
