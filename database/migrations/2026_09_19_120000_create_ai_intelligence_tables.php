<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables behind AI & Intelligence.
 *
 * WHAT THIS ADDS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * Seven tables, all new, none of them touching anything that exists. The
 * capabilities that already have G2G data — agents, conversations, knowledge,
 * recommendations, the knowledge graph, evaluation, usage — are read from
 * `agentic_*`, `hpbrain_*` and `ai_daily_used_api` exactly as they stand. Nothing
 * is copied out of them, nothing is renamed, and no existing row is written.
 * `CapabilityController` reads those tables in place, which is the point: a console
 * that duplicated them would start disagreeing with its source on the first write.
 *
 * What is missing in G2G, and therefore created here, is the *configuration* half:
 * where a provider credential lives, which model a module gets, what a prompt says,
 * what the AI is permitted to do, and what it did. Those had nowhere to live —
 * provider keys were env vars shared by every organisation, and prompts were string
 * literals in the services that sent them.
 *
 * Column shapes match LMS K-12's equivalents, so the two products' AI configuration
 * stays directly comparable and a template or a credential means the same thing in
 * both.
 *
 * `ai_modules` IS SEEDED FROM `tblmenumaster_g2g`, NOT HAND-WRITTEN
 *
 * The module list a template is filed under is this product's own module list. It is
 * read from the level-1 rows of the menu catalogue at migration time, so the selector
 * offers exactly the modules this deployment has. A hard-coded list would drift from
 * the navigation within a release, and an administrator could then write templates
 * for a module that does not exist.
 *
 * SAFE TO RE-RUN
 *
 * Every create is guarded on the table's absence and every seed on the row's, so a
 * partial run is resumable and a second run is a no-op.
 *
 * Run it on its own:
 *
 *   php artisan migrate --path=database/migrations/2026_09_19_120000_create_ai_intelligence_tables.php
 */
return new class extends Migration
{
    /**
     * The starting model catalogue.
     *
     * Costs are per 1,000 tokens in USD and are null wherever the vendor's public
     * pricing is not something a migration should assert. A null cost means usage
     * reporting shows tokens and leaves the money column blank, which is the honest
     * outcome — a guessed rate is worse than no rate on a screen an administrator
     * uses to explain a bill.
     */
    private const CATALOGUE = [
        // provider,      model_id,                      label,                max out
        ['gemini',       'gemini-3.6-flash',            'Gemini 3.6 Flash',    8192],
        ['gemini',       'gemini-3.6-pro',              'Gemini 3.6 Pro',      8192],
        ['deepseek',     'deepseek-chat',               'DeepSeek Chat',       4096],
        ['deepseek',     'deepseek-reasoner',           'DeepSeek Reasoner',   4096],
        ['openrouter',   'deepseek/deepseek-chat',      'DeepSeek Chat',       4096],
        ['openrouter',   'openai/gpt-4o-mini',          'GPT-4o mini',         4096],
        ['openai',       'gpt-4o-mini',                 'GPT-4o mini',         4096],
        ['openai',       'gpt-4o',                      'GPT-4o',              4096],
        ['groq',         'llama-3.3-70b-versatile',     'Llama 3.3 70B',       4096],
        ['mistral',      'mistral-large-latest',        'Mistral Large',       4096],
    ];

    public function up(): void
    {
        $this->createApiKeys();
        $this->createModels();
        $this->createModules();
        $this->createTemplates();
        $this->createSuggestions();
        $this->createPolicies();
        $this->createAuditLogs();

        $this->seedModels();
        $this->seedModulesFromMenu();
    }

    public function down(): void
    {
        // Ordered so a table is dropped before anything that references it by
        // convention. None of these carry foreign keys, but the reading order still
        // matters to whoever runs this in a hurry.
        Schema::dropIfExists('ai_audit_logs');
        Schema::dropIfExists('ai_policy_acknowledgements');
        Schema::dropIfExists('ai_policy_assignments');
        Schema::dropIfExists('ai_policy_rules');
        Schema::dropIfExists('ai_policies');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('ai_templates');
        Schema::dropIfExists('ai_modules');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_api_keys');
    }

    /**
     * Provider credentials, bound to a module and a model.
     *
     * `ai_module` NULL means "serves any module" — the shared pool every caller
     * falls back to. `model` NULL means the provider's configured default. Both
     * nullable so a credential can be saved before anyone decides what it is for.
     */
    private function createApiKeys(): void
    {
        if (Schema::hasTable('ai_api_keys')) {
            return;
        }

        Schema::create('ai_api_keys', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('account_email', 191)->nullable();
            // The provider tag a credential is looked up by. See ProviderCatalog.
            $table->string('api_type', 191)->nullable()->index();
            // Indexed because resolution reads by it on every AI call.
            $table->string('ai_module', 64)->nullable()->index();
            $table->string('model', 120)->nullable();
            // No default: a credential row with an empty key is a row that resolves
            // to nothing and reads as configured.
            $table->mediumText('api_key');
            // Doubles as the per-key output-token ceiling, which is the meaning this
            // column already carries elsewhere in the platform.
            $table->string('api_limit', 191)->nullable();

            $table->integer('status')->default(1);   // 1 = active, 0 = retired

            // NULL = platform credential, shared by every organisation.
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /** The model catalogue every model dropdown reads. */
    private function createModels(): void
    {
        if (Schema::hasTable('ai_models')) {
            return;
        }

        Schema::create('ai_models', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('provider', 40)->index();
            // The id the provider itself answers to, sent verbatim on the wire.
            $table->string('model_id', 120);
            // What an administrator reads in a dropdown.
            $table->string('label', 120);

            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('input_cost_per_1k', 12, 6)->nullable();
            $table->decimal('output_cost_per_1k', 12, 6)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->integer('status')->default(1);   // 1 = selectable, 0 = retired

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamps();

            // One row per model per scope. Stops a double-submit putting the same
            // model in a dropdown twice.
            $table->unique(['provider', 'model_id', 'sub_institute_id'], 'ai_models_scope_unique');
        });
    }

    /** The product modules a template or a policy can be filed under. */
    private function createModules(): void
    {
        if (Schema::hasTable('ai_modules')) {
            return;
        }

        Schema::create('ai_modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_key', 80)->index();
            $table->string('label', 150);
            $table->string('domain', 40)->default('g2g')->index();
            $table->text('description')->nullable();

            // The menu row this module came from, so a re-sync can find it again and
            // so a reader can see that this list is derived rather than invented.
            $table->unsignedBigInteger('menu_id')->nullable()->index();
            $table->string('icon', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true)->index();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['module_key', 'sub_institute_id'], 'ai_modules_key_tenant_unique');
        });
    }

    /** Versioned AI templates, one row per version. */
    private function createTemplates(): void
    {
        if (Schema::hasTable('ai_templates')) {
            return;
        }

        Schema::create('ai_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 120)->index();
            $table->string('name', 200);
            $table->string('domain', 40)->default('g2g')->index();
            $table->string('module_key', 60)->nullable()->index();
            // `prompt` today. The column exists so report layouts can be added
            // without a migration — see TemplateCatalog.
            $table->string('kind', 20)->default('prompt')->index();
            $table->string('category', 60)->nullable()->index();
            $table->text('description')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft')->index();   // draft|published|archived

            $table->longText('system_prompt')->nullable();
            $table->longText('user_prompt');
            $table->json('variables')->nullable();                     // [{key,label,required,type}]
            $table->json('output_schema')->nullable();
            $table->string('output_format', 24)->default('text');      // text|json|markdown

            // Report-layout columns, unused today. Present so the two products'
            // schemas stay comparable and so the feature is a code change rather
            // than a schema change.
            $table->longText('html_layout')->nullable();
            $table->string('data_source', 120)->nullable();
            $table->json('data_arguments')->nullable();

            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();

            $table->json('safety_rules')->nullable();
            $table->boolean('allow_as_evidence')->default(false);
            $table->boolean('requires_review')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['template_key', 'version', 'sub_institute_id'], 'ai_templates_key_ver_tenant_unique');
        });
    }

    /**
     * The binding that puts a published template in its module's AI panel.
     *
     * Separate from `ai_templates` because it answers a different question: that
     * table says what a template is, this says where it is offered. One template can
     * be published and not offered — a draft being reviewed — and the two states have
     * to be distinguishable.
     */
    private function createSuggestions(): void
    {
        if (Schema::hasTable('ai_suggestions')) {
            return;
        }

        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('module_key', 80)->index();
            $table->string('capability', 24)->index();   // generative | conversational | agent
            $table->string('label', 200);
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();

            $table->string('action_type', 40);           // generate | prompt | run_agent
            // The key the action binds to: ai_templates.template_key, or an agent id.
            $table->string('action_ref', 150)->nullable();

            $table->text('prompt')->nullable();
            $table->json('payload')->nullable();

            // Hidden unless the current screen resolved a record, so a record-specific
            // action cannot appear on a list page.
            $table->boolean('requires_entity')->default(false);
            $table->json('allowed_roles')->nullable();
            $table->json('required_permissions')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true)->index();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamps();

            $table->index(['module_key', 'capability', 'sort_order'], 'ai_suggestions_module_cap_idx');
        });
    }

    /** Policies, their switches, where they apply, and who acknowledged them. */
    private function createPolicies(): void
    {
        if (! Schema::hasTable('ai_policies')) {
            Schema::create('ai_policies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
                $table->string('name', 191);
                $table->text('description')->nullable();
                $table->string('policy_type', 80);          // ai_free|ai_assisted|ai_empowered|custom
                $table->tinyInteger('status')->default(1);
                $table->tinyInteger('require_disclosure')->default(0);
                $table->tinyInteger('require_acknowledgement')->default(0);
                $table->tinyInteger('ai_detection_required')->default(0);
                $table->tinyInteger('plagiarism_check_required')->default(0);
                $table->string('detection_provider', 120)->nullable();
                $table->decimal('detection_threshold', 5, 2)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['sub_institute_id', 'status'], 'ai_policies_scope_status_index');
            });
        }

        if (! Schema::hasTable('ai_policy_rules')) {
            Schema::create('ai_policy_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('policy_id')->index();
                $table->string('rule_key', 120);
                $table->longText('rule_value')->nullable();
                $table->timestamps();

                $table->unique(['policy_id', 'rule_key'], 'ai_policy_rules_policy_key_unique');
            });
        }

        if (! Schema::hasTable('ai_policy_assignments')) {
            Schema::create('ai_policy_assignments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('policy_id')->index();
                // global | module | department | job_role | competency | course | employee
                $table->string('scope_type', 40);
                $table->unsignedBigInteger('scope_id')->nullable()->index();
                $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
                $table->tinyInteger('status')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(
                    ['policy_id', 'scope_type', 'scope_id', 'sub_institute_id'],
                    'ai_policy_assignments_unique'
                );
            });
        }

        if (! Schema::hasTable('ai_policy_acknowledgements')) {
            Schema::create('ai_policy_acknowledgements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('policy_id')->index();
                // The employee who acknowledged it. G2G's people are users, not
                // students, so this names `tbluser` rather than a student table.
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('scope_type', 40)->nullable();
                $table->unsignedBigInteger('scope_id')->nullable()->index();
                $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
                $table->tinyInteger('acknowledged')->default(0);
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * The AI audit trail.
     *
     * Deliberately shaped like `g2g_audit_log` and `hpbrain_audit_logs` so the three
     * can be read together. It is a third table rather than a column on either
     * because both of those are written by paths this layer does not control, and an
     * audit trail that shares a table with something that can rewrite it is not one.
     */
    private function createAuditLogs(): void
    {
        if (Schema::hasTable('ai_audit_logs')) {
            return;
        }

        Schema::create('ai_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('actor_type', 24)->default('system');   // user | agent | system
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_label', 150)->nullable();

            $table->string('subject_entity_key', 100)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('related_type', 80)->nullable();
            $table->unsignedBigInteger('related_id')->nullable()->index();

            $table->string('outcome', 24)->nullable()->index();     // success | failure | rejected
            $table->text('message')->nullable();
            $table->longText('payload')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamps();

            $table->index(['event_type', 'created_at'], 'ai_audit_event_time_idx');
        });
    }

    /**
     * Seed the catalogue, including whatever `config/ai.php` currently resolves to.
     *
     * The config models are inserted first and separately from the fixed list above,
     * because a deployment that has pinned its own `GEMINI_MODEL` must find that
     * model in the dropdown — otherwise this migration would quietly narrow the
     * choices to the ones written here.
     */
    private function seedModels(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        $rows = [];
        $order = 0;

        foreach (['gemini', 'deepseek', 'openrouter'] as $driver) {
            $model = trim((string) config("ai.provider.{$driver}.model", ''));

            if ($model === '') {
                continue;
            }

            $rows[$driver . '|' . $model] = [
                'provider' => $driver,
                'model_id' => $model,
                'label' => $model,
                'max_output_tokens' => (int) config("ai.provider.{$driver}.max_output_tokens") ?: null,
                'sort_order' => $order++,
            ];
        }

        foreach (self::CATALOGUE as [$provider, $modelId, $label, $maxOut]) {
            $key = $provider . '|' . $modelId;

            if (isset($rows[$key])) {
                // Config already contributed this one; keep the friendlier label.
                $rows[$key]['label'] = $label;

                continue;
            }

            $rows[$key] = [
                'provider' => $provider,
                'model_id' => $modelId,
                'label' => $label,
                'max_output_tokens' => $maxOut,
                'sort_order' => $order++,
            ];
        }

        foreach ($rows as $row) {
            $exists = DB::table('ai_models')
                ->where('provider', $row['provider'])
                ->where('model_id', $row['model_id'])
                ->whereNull('sub_institute_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('ai_models')->insert($row + [
                'input_cost_per_1k' => null,
                'output_cost_per_1k' => null,
                'status' => 1,
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * One `ai_modules` row per module in this deployment's menu catalogue.
     *
     * Reads `tblmenumaster_g2g` level-1 rows — the modules the sidebar is built from.
     * Seeded at platform scope (`sub_institute_id` NULL) because the menu catalogue
     * itself is global here: every organisation sees the same modules, and an
     * organisation that wants its own label can add a row that shadows this one.
     *
     * Skipped entirely, without failing, when the menu table is absent — a deployment
     * without it simply gets the "Shared — every module" entry, and Template
     * Management still works.
     */
    private function seedModulesFromMenu(): void
    {
        if (! Schema::hasTable('ai_modules') || ! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $modules = DB::table('tblmenumaster_g2g')
            ->where('level', 1)
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get(['id', 'menu_name', 'icon', 'sort_order']);

        foreach ($modules as $module) {
            $key = $this->moduleKey((string) $module->menu_name);

            if ($key === '') {
                continue;
            }

            $exists = DB::table('ai_modules')
                ->where('module_key', $key)
                ->whereNull('sub_institute_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('ai_modules')->insert([
                'module_key' => $key,
                'label' => (string) $module->menu_name,
                'domain' => 'g2g',
                'description' => null,
                'menu_id' => (int) $module->id,
                'icon' => $module->icon === null ? null : mb_substr((string) $module->icon, 0, 60),
                'sort_order' => (int) ($module->sort_order ?? 0),
                'status' => 1,
                'sub_institute_id' => null,
                'client_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A stable key from a menu label.
     *
     * The label is what a person reads and may be reworded; the key is written into
     * `ai_templates.module_key` and must not change when that happens. Derived rather
     * than taken from the menu id because an id differs between deployments, and a
     * template exported from one would then point at the wrong module in another.
     */
    private function moduleKey(string $label): string
    {
        $key = strtolower(trim($label));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return mb_substr(trim($key, '_'), 0, 80);
    }
};
