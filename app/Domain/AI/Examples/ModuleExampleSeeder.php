<?php

namespace App\Domain\AI\Examples;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Starter report layouts and prompts for the AI Stack's Templates and Prompts tabs.
 *
 * WHAT IT WRITES, AND WHAT IT NEVER DOES
 *
 * Only `ai_templates` rows: platform rows (`sub_institute_id` NULL, like the existing
 * worked examples), published, with a `template_key` under `g2g.example.` and a name ending
 * "(starter example)". A report row is bound to a real read-only source from
 * `ModuleDataSourceCatalog` - a source that is not in the catalogue, or does not belong to
 * the module, is skipped, never invented. It adds no policy, no restriction and no
 * credential, and it never edits or deletes an existing row: a key that already exists is
 * left exactly as it is, so running it twice changes nothing the second time.
 *
 * The G2G choices (which module gets which title) are in `G2gModuleExamples`.
 */
final class ModuleExampleSeeder
{
    public function __construct(private readonly ModuleDataSourceCatalog $sources)
    {
    }

    /**
     * What a run would do, one entry per template, without writing anything.
     *
     * @return array<int, array{key:string, module:string, kind:string, name:string, action:string, reason:string|null}>
     */
    public function plan(): array
    {
        $plan = [];

        foreach (G2gModuleExamples::definitions() as $module => $definition) {
            $moduleExists = DB::table('ai_modules')->where('module_key', $module)->whereNull('sub_institute_id')->exists();

            foreach (['report', 'prompt'] as $kind) {
                $spec = $definition[$kind] ?? null;

                if ($spec === null) {
                    continue;
                }

                $key = G2gModuleExamples::KEY_PREFIX . $module . '.' . $kind;
                $entry = ['key' => $key, 'module' => $module, 'kind' => $kind, 'name' => (string) $spec['name'], 'action' => 'create', 'reason' => null];

                if (! $moduleExists) {
                    $entry['action'] = 'skip';
                    $entry['reason'] = 'no platform ai_modules row for ' . $module;
                } elseif ($kind === 'report' && ! $this->sourceBelongs((string) $spec['source'], $module)) {
                    $entry['action'] = 'skip';
                    $entry['reason'] = (string) $spec['source'] . ' is not a data source of ' . $module;
                } elseif (DB::table('ai_templates')->where('template_key', $key)->exists()) {
                    $entry['action'] = 'exists';
                    $entry['reason'] = 'template_key already present; left untouched';
                }

                $plan[] = $entry;
            }
        }

        return $plan;
    }

    /**
     * Create what the plan says to create.
     *
     * @return array<int, array<string, mixed>> The plan, each created entry with its new `id`.
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->plan() as $entry) {
            if ($entry['action'] === 'create') {
                $entry['id'] = $this->insert($entry['module'], $entry['kind'], $entry['key']);
            }

            $results[] = $entry;
        }

        return $results;
    }

    private function sourceBelongs(string $source, string $module): bool
    {
        return in_array($source, array_column($this->sources->forModule($module), 'name'), true);
    }

    private function insert(string $module, string $kind, string $key): int
    {
        $definition = G2gModuleExamples::definitions()[$module];
        $spec = $definition[$kind];
        $isReport = $kind === 'report';
        $prompt = $isReport ? null : G2gModuleExamples::promptText((string) $spec['focus']);

        return (int) DB::table('ai_templates')->insertGetId([
            'template_key' => $key,
            'name' => (string) $spec['name'],
            'description' => (string) $spec['description'] . ' A starter example: open it, then save your own copy to change it.',
            'domain' => 'g2g',
            'module_key' => $module,
            'kind' => $kind,
            'category' => $isReport ? 'report' : 'summary',
            'version' => 1,
            'status' => 'published',
            'system_prompt' => $isReport ? null : $prompt['system'],
            'user_prompt' => $isReport ? '' : $prompt['user'],
            'variables' => $isReport ? null : json_encode([
                ['key' => 'records', 'label' => 'Records', 'required' => true, 'type' => 'text'],
                ['key' => 'metrics', 'label' => 'Figures', 'required' => true, 'type' => 'text'],
            ]),
            'output_schema' => null,
            'output_format' => $isReport ? 'text' : 'markdown',
            'html_layout' => $isReport ? G2gModuleExamples::reportLayout((string) $spec['heading']) : null,
            'data_source' => $isReport ? (string) $spec['source'] : null,
            'data_arguments' => $isReport ? json_encode($spec['arguments'] ?? ['limit' => 200]) : null,
            'provider' => null,
            'model' => null,
            'temperature' => null,
            'max_tokens' => null,
            'safety_rules' => json_encode($isReport
                ? ['Figures come only from the bound data source; nothing in this report is written by a model.']
                : ['Use only the supplied records and figures.', 'Say so when the data is empty or partial.']),
            'allow_as_evidence' => false,
            'requires_review' => false,
            'created_by' => null,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
