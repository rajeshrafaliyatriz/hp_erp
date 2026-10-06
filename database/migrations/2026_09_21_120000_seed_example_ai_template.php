<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One worked example in Template Management.
 *
 * WHY SEED AN EXAMPLE AT ALL
 *
 * Template Management opens empty, and an empty table teaches nothing. The things
 * an author has to get right are not obvious from a blank form: which module a
 * template belongs to, that a published template must carry a grounding variable,
 * what a safety rule is for, and that publishing against a module is what makes that
 * module offer it. One correct template shows all four at once, and can be opened,
 * read, previewed and copied.
 *
 * WHY IT IS PLATFORM-SCOPED (`sub_institute_id = NULL`)
 *
 * Every organisation sees it, and none can damage it. `TemplateCatalog::update()`
 * answers an edit of a platform row by writing the editing organisation its own
 * copy, so "open the example, change it, save" is a safe first exercise that also
 * demonstrates the override behaviour — the example itself is left intact for
 * everyone else. Seeding it per organisation would instead put a row somebody has
 * to maintain into every tenant.
 *
 * WHY THIS MODULE AND THIS SUBJECT
 *
 * `capability_intelligence` is G2G's own module — the `ai_modules` row derived from
 * the `tblmenumaster_g2g` entry, not a name invented here — and competency frameworks
 * are what it holds. The prompt is written against that domain so the example reads
 * as something this product would actually ask for, rather than a lorem-ipsum
 * placeholder an author has to mentally translate.
 *
 * IT IS BOUND, NOT JUST STORED
 *
 * The `ai_suggestions` row below is the half that makes a template reachable rather
 * than merely saved. Seeding the template without it would demonstrate the wrong
 * lesson — that storing a row is enough — which is the single thing about this
 * screen most likely to be misunderstood.
 *
 * SAFE TO RE-RUN. Guarded on the template key, so a second run is a no-op and an
 * organisation that has already customised the example is never overwritten.
 *
 *   php artisan migrate --path=database/migrations/2026_09_21_120000_seed_example_ai_template.php
 */
return new class extends Migration
{
    private const MODULE_KEY = 'capability_intelligence';

    private const TEMPLATE_KEY = 'g2g.capability_intelligence.competency_framework_summary';

    /**
     * The standing instruction: who the model is, and what it must not do.
     *
     * The "work only from the data below" sentence is doing real work. Without it a
     * model asked to summarise competencies will happily supply the competencies a
     * generic organisation might have, which reads as plausible and is fiction.
     */
    private const SYSTEM_PROMPT = <<<'TXT'
You are a capability analyst writing for an HR administrator.

Work only from the data supplied below. If the data does not answer part of the
question, say so plainly rather than filling the gap from general knowledge. Never
invent a competency name, code, proficiency level or count.

Write in short paragraphs. Lead with what matters most to a decision.
TXT;

    /**
     * The per-request ask.
     *
     * Every `{{placeholder}}` here is a key from `TemplateVariableCatalog`, which is
     * the list the runtime actually fills. `{{records}}` and `{{metrics}}` are the two
     * marked `grounding: true` — a published template must use at least one, and this
     * one uses both, which is why it can be seeded as `published` rather than `draft`.
     *
     * `{{is_partial}}` and `{{record_count}}` are here for a reason worth copying: a
     * summary of 20 visible rows out of 199 must not be written as though it described
     * all 199, and the model cannot know the difference unless it is told.
     */
    private const USER_PROMPT = <<<'TXT'
Summarise the competency data on this screen for an HR administrator.

Screen: {{page_title}}
Filters in force: {{filters}}
Showing {{rows_shown}} of {{record_count}} records. Partial view: {{is_partial}}

Figures:
{{metrics}}

Records:
{{records}}

Cover, in this order:
1. What this set of competencies is about, in one or two sentences.
2. Any concentration worth noting - a type, a criticality band or a framework that
   dominates the set.
3. The two or three gaps or risks an administrator should look at next, each with
   the records that led you to say it.

If the view is partial, say so before drawing any conclusion about the whole set.
TXT;

    /**
     * Things the model must never do, checked against the output.
     *
     * Deliberately specific. "Be accurate" is not a safety rule; "do not invent a
     * competency code" is one, because it names an output a reader can check.
     */
    private const SAFETY_RULES = [
        'Do not invent a competency name, code, proficiency level or framework name.',
        'Do not state a count or percentage that is not present in the figures or derivable from the records shown.',
        'Do not describe a partial view as though it were the whole set.',
        'Do not name or infer anything about an individual employee.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_templates') || ! Schema::hasTable('ai_modules')) {
            return;
        }

        // Idempotent on the key, not on a row id: an id differs between deployments,
        // and re-running this must never write a second copy of the example.
        if (DB::table('ai_templates')->where('template_key', self::TEMPLATE_KEY)->exists()) {
            return;
        }

        // The module must genuinely exist here. Seeding a template against a module
        // key this deployment does not have would put a row in the table that the
        // selector cannot show and the binding cannot reach - an example that
        // demonstrates a broken state.
        $module = DB::table('ai_modules')
            ->where('module_key', self::MODULE_KEY)
            ->whereNull('sub_institute_id')
            ->first();

        if ($module === null) {
            return;
        }

        $templateId = DB::table('ai_templates')->insertGetId([
            'template_key' => self::TEMPLATE_KEY,
            'name' => 'Competency framework summary (example)',
            'description' => 'A worked example. Summarises the competencies on screen, '
                . 'grounded in the records the page supplies. Open it to see how a template '
                . 'is written, then use Customise to make your own copy.',
            'domain' => 'g2g',
            'module_key' => self::MODULE_KEY,
            'kind' => 'prompt',
            'category' => 'summary',
            'version' => 1,
            'status' => 'published',
            'system_prompt' => self::SYSTEM_PROMPT,
            'user_prompt' => self::USER_PROMPT,
            // The variables this template declares beyond the standard catalogue.
            // Empty here on purpose: everything it uses is already supplied by the
            // runtime, which is the simplest case and the right one to show first.
            'variables' => null,
            'output_schema' => null,
            'output_format' => 'markdown',
            'html_layout' => null,
            'data_source' => null,
            'data_arguments' => null,
            // Left unset so the template runs on whatever the module's configured
            // provider is. Pinning a provider in an example would teach the opposite
            // of what AI Providers exists for.
            'provider' => null,
            'model' => null,
            'temperature' => null,
            'max_tokens' => null,
            'safety_rules' => json_encode(self::SAFETY_RULES, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'allow_as_evidence' => false,
            // An example should not be quietly trusted. A summary a person has not
            // read is not evidence, and leaving this off is the conservative default
            // an author copying this template should inherit.
            'requires_review' => true,
            'created_by' => null,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindToModule();

        // Recorded like any other template creation, so the audit screen has
        // something real in it on day one and the trail does not begin with a gap.
        if (Schema::hasTable('ai_audit_logs')) {
            DB::table('ai_audit_logs')->insert([
                'event_type' => 'ai.template.created',
                'actor_type' => 'system',
                'actor_label' => 'migration',
                'related_type' => 'ai_templates',
                'related_id' => $templateId,
                'outcome' => 'success',
                'message' => 'Example template seeded for ' . $module->label . '.',
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_suggestions')) {
            DB::table('ai_suggestions')->where('action_ref', self::TEMPLATE_KEY)->delete();
        }

        if (Schema::hasTable('ai_templates')) {
            // Only the platform row. An organisation that customised the example owns
            // its copy, and rolling this migration back must not take their work with
            // it.
            DB::table('ai_templates')
                ->where('template_key', self::TEMPLATE_KEY)
                ->whereNull('sub_institute_id')
                ->delete();
        }
    }

    /**
     * The `ai_suggestions` row that makes the module offer it.
     *
     * Written here rather than left to `TemplateCatalog::syncBinding()` because a
     * migration does not go through the controller. The columns match what that
     * method writes, so a later edit through the screen updates this row rather than
     * appending a second one.
     */
    private function bindToModule(): void
    {
        if (! Schema::hasTable('ai_suggestions')) {
            return;
        }

        $exists = DB::table('ai_suggestions')
            ->where('action_ref', self::TEMPLATE_KEY)
            ->whereNull('sub_institute_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('ai_suggestions')->insert([
            'module_key' => self::MODULE_KEY,
            'capability' => 'generative',
            'label' => 'Summarise these competencies',
            'description' => 'Example template. Summarises the competencies currently on screen.',
            'icon' => null,
            'action_type' => 'generate',
            'action_ref' => self::TEMPLATE_KEY,
            'prompt' => null,
            'payload' => null,
            // False: it summarises the list, not one record. A template about a single
            // competency would set this true, and the difference is worth seeing.
            'requires_entity' => false,
            'allowed_roles' => null,
            'required_permissions' => null,
            'sort_order' => 10,
            'status' => 1,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
