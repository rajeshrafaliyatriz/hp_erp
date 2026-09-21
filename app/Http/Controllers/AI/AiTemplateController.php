<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Templates\TemplateCatalog;
use App\Domain\AI\Templates\TemplateModuleCatalog;
use App\Domain\AI\Templates\TemplatePreviewData;
use App\Domain\AI\Templates\TemplateVariableCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Template Management — one screen for every module's AI templates.
 *
 * WHAT THIS REPLACES
 *
 * G2G's AI prompts live in PHP string literals inside the services that send them —
 * `EsoGenerator`, `RecruitmentAssessmentGenerator`, `CourseQuizGenerator` and the
 * Gemini controllers each carry their own. Improving one is a code change and a
 * deploy, there is no version to roll back to, and no two of them can be compared.
 * This controller is the alternative: a template is a row with a module, a version
 * and a status, authored on a screen.
 *
 * ONE SHAPE FOR EVERY MODULE
 *
 * There is no per-module endpoint and no per-module payload. `index` takes a
 * `module_key` and filters; every other route is module-agnostic and reads the module
 * off the record. The modules come from `ai_modules`, which is seeded from
 * `tblmenumaster_g2g`, so a module this deployment has appears in the selector with
 * no change here and no change in the UI. That a new module must not need a new
 * screen is expressed as an absence of code rather than a promise.
 *
 * TENANT SCOPE COMES FROM THE TOKEN
 *
 * Like `AiConfigurationController`, every read is filtered by
 * `$this->scope($request)->selectedInstituteId` and every write is stamped with it.
 * The organisation is never read from input, so a caller cannot write a template into
 * another organisation by naming one. Platform templates — the shared baseline
 * everyone resolves — are visible to all and editable by none: an edit writes that
 * organisation its own copy instead. See `TemplateCatalog::update()`.
 *
 * PROMPTS ONLY, FOR NOW
 *
 * LMS K-12's copy also authors report layouts bound to its read-only MCP tools. G2G
 * has no such registry, so `kind` accepts `prompt` and nothing else rather than
 * offering a data-source dropdown with nothing in it. See the note on
 * `TemplateCatalog`.
 */
class AiTemplateController extends AiController
{
    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly TemplateModuleCatalog $modules,
        private readonly TemplateVariableCatalog $variables,
        private readonly TemplatePreviewData $previewData,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /**
     * Everything the screen needs to render before a module is chosen.
     *
     * One call rather than four. The module list, the variable catalogue, the statuses
     * and the category suggestions are all useless individually — the form cannot be
     * drawn until it has all of them — and four round trips is four chances to render
     * a form with an empty dropdown.
     */
    public function options(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            return $this->success('Template options resolved.', [
                'modules' => $this->modules->all($institute),
                'shared_key' => TemplateModuleCatalog::SHARED,
                'variables' => $this->variables->all(),
                'grounding_variables' => $this->variables->groundingKeys(),
                'statuses' => TemplateCatalog::STATUSES,
                'kinds' => TemplateCatalog::KINDS,
                'output_formats' => TemplateCatalog::OUTPUT_FORMATS,
                'categories' => TemplateCatalog::SUGGESTED_CATEGORIES,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The templates for the selected module.
     *
     * `module_key` is optional on purpose: without it the screen lists every template
     * the organisation can see, which is the view an administrator wants when the
     * question is "what exists at all" rather than "what does Competency have".
     */
    public function index(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $validated = $request->validate([
                'module_key' => 'nullable|string|max:60',
            ]);

            $moduleKey = $validated['module_key'] ?? null;

            if ($moduleKey !== null && $moduleKey !== '' && ! $this->modules->exists($moduleKey, $institute)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $templates = $this->templates->forModule($moduleKey, $institute);

            return $this->success('Templates resolved.', [
                'sub_institute_id' => $institute,
                'module_key' => $moduleKey,
                'module_label' => $moduleKey === null ? 'All modules' : $this->modules->label($moduleKey, $institute),
                'templates' => $templates,
                // So the screen can say "3 of 14 are live in this module" without
                // counting client-side and disagreeing with the next page of results.
                'counts' => [
                    'total' => count($templates),
                    'published' => count(array_filter($templates, fn ($row) => $row['status'] === 'published')),
                    'offered' => count(array_filter($templates, fn ($row) => $row['offered_in_module'])),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One template in full, for the view and edit screens. */
    public function show(Request $request, int $id)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;
            $template = $this->templates->find($id, $institute);

            if ($template === null) {
                return $this->failure('That template could not be found.', 404);
            }

            return $this->success('Template resolved.', ['template' => $template]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Create a template and, when it is published against a module, offer it there. */
    public function store(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $data = $this->validated($request, $institute);
            $data['created_by'] = $scope->userId;

            $id = $this->templates->create($data, $institute, $scope->clientId);

            $this->audit->record('ai.template.created', $scope, [
                'related_type' => 'ai_templates',
                'related_id' => $id,
                'message' => sprintf(
                    'Template "%s" created for %s.',
                    $data['name'],
                    $this->modules->label($data['module_key'] ?? null, $institute)
                ),
            ]);

            return $this->success('Template saved.', [
                'template' => $this->templates->find($id, $institute),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Change a template.
     *
     * `new_version` asks for the change to land as a new version with the previous one
     * archived, rather than as an edit in place. Worth offering on a published
     * template already in use: the old text stays recoverable, so a prompt that turns
     * out worse can be rolled back by republishing the version before it.
     */
    public function update(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            if ($this->templates->find($id, $institute) === null) {
                return $this->failure('That template could not be found.', 404);
            }

            $data = $this->validated($request, $institute);
            $data['created_by'] = $scope->userId;

            $result = $this->templates->update(
                $id,
                $data,
                $institute,
                $scope->clientId,
                (bool) $request->boolean('new_version')
            );

            $this->audit->record('ai.template.' . $result['action'], $scope, [
                'related_type' => 'ai_templates',
                'related_id' => $result['id'],
                'message' => sprintf('Template "%s" %s.', $data['name'], $result['action']),
            ]);

            return $this->success(match ($result['action']) {
                'overridden' => 'This organisation now has its own version of the platform template. '
                    . 'The shared one is unchanged for every other organisation.',
                'versioned' => 'A new version was published. The previous one is archived and can be restored.',
                default => 'Template updated.',
            }, [
                'template' => $this->templates->find($result['id'], $institute),
                'action' => $result['action'],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Retire a template and withdraw it from its module's panel. */
    public function destroy(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);

            $this->templates->archive($id, $scope->selectedInstituteId);

            $this->audit->record('ai.template.archived', $scope, [
                'related_type' => 'ai_templates',
                'related_id' => $id,
                'message' => 'Template retired.',
            ]);

            return $this->success('Template retired.', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Render the prompts with sample values, without calling a model.
     *
     * The question an author has at this point is about their own prompt — did the
     * placeholder land where I meant it to, is anything still unresolved — and that is
     * answerable with string substitution. Running a generation to answer it would
     * cost a call, take seconds, and fail entirely where no provider is configured,
     * which is a poor way to check a typo.
     */
    public function preview(Request $request)
    {
        try {
            $validated = $request->validate([
                'system_prompt' => 'nullable|string|max:20000',
                'user_prompt' => 'required|string|max:20000',
                'values' => 'nullable|array',
            ]);

            // The signed-in organisation's own records, not an invented set — see
            // TemplatePreviewData for why, and for the line it draws at taxonomy.
            $values = array_merge(
                $this->previewData->forInstitute($this->scope($request)->selectedInstituteId),
                $validated['values'] ?? []
            );

            $rendered = $this->templates->preview(
                (string) ($validated['system_prompt'] ?? ''),
                (string) $validated['user_prompt'],
                $values
            );

            return $this->success('Prompt rendered.', $rendered + ['values' => $values]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Validation shared by create and update.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, int|string|null $institute): array
    {
        $moduleKeys = array_merge([TemplateModuleCatalog::SHARED], $this->modules->keys($institute));

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'kind' => ['nullable', Rule::in(TemplateCatalog::KINDS)],

            // Write the template for the whole platform rather than only this
            // organisation. Opt-in — see TemplateCatalog::create().
            'shared' => 'nullable|boolean',

            // Optional: generated from the module and name when absent, so an
            // administrator writing their first template never has to invent a dotted
            // key or know the convention behind one.
            'template_key' => 'nullable|string|max:120|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
            'module_key' => ['required', 'string', Rule::in($moduleKeys)],
            'domain' => 'nullable|string|max:40',
            'category' => 'nullable|string|max:60',
            'status' => ['required', Rule::in(TemplateCatalog::STATUSES)],

            'system_prompt' => 'nullable|string|max:20000',
            'user_prompt' => 'required|string|max:20000',

            'variables' => 'nullable|array',
            'variables.*.key' => 'required|string|max:60|regex:/^[a-zA-Z0-9_.]+$/',
            'variables.*.label' => 'nullable|string|max:150',
            'variables.*.required' => 'nullable|boolean',
            'variables.*.type' => 'nullable|string|max:30',

            'output_format' => ['nullable', Rule::in(TemplateCatalog::OUTPUT_FORMATS)],
            'output_schema' => 'nullable|array',

            'provider' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:120',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:1|max:200000',

            'safety_rules' => 'nullable|array',
            'safety_rules.*' => 'string|max:500',

            'allow_as_evidence' => 'nullable|boolean',
            'requires_review' => 'nullable|boolean',

            // The module binding, which is what makes the template reachable from the
            // module's AI panel rather than only stored.
            'offer_in_module' => 'nullable|boolean',
            'suggestion_label' => 'nullable|string|max:150',
            'requires_entity' => 'nullable|boolean',
        ]);

        // A published template whose prompt carries no data variable will be answered
        // from the model's general knowledge, which for a question about this
        // organisation's competencies means a confident, invented number. Refused
        // rather than warned: a warning on a screen is not read by the person who
        // meets the answer.
        if (($validated['status'] ?? 'draft') === 'published') {
            $prompt = ($validated['user_prompt'] ?? '') . ' ' . ($validated['system_prompt'] ?? '');
            $used = $this->variables->used($prompt);
            $declared = array_column($validated['variables'] ?? [], 'key');

            if (array_intersect($this->variables->groundingKeys(), array_merge($used, $declared)) === []) {
                throw ValidationException::withMessages([
                    'user_prompt' => [
                        'A published template must include at least one data variable — '
                        . implode(' or ', array_map(fn ($key) => '{{' . $key . '}}', $this->variables->groundingKeys()))
                        . ' — or the model has nothing to work from and will answer from general knowledge. '
                        . 'Save it as a draft if it is not finished.',
                    ],
                ]);
            }
        }

        $validated['kind'] = 'prompt';
        $validated['template_key'] = $validated['template_key'] ?? null;

        return $validated;
    }
}
