<?php

namespace App\Http\Controllers\AI;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The AI & Intelligence console — one endpoint behind all twelve capabilities.
 *
 * WHY ONE CONTROLLER AND NOT TWELVE
 *
 * Every capability answers the same three questions for the organisation the user is
 * signed in to: is it configured, how much of it is there, and what has it done
 * lately. Twelve controllers would be twelve copies of the same tenant filter, the
 * same missing-table guard and the same envelope — and the first one written
 * differently is the one that leaks another organisation's rows. One controller with
 * a provider per capability keeps that in a single place.
 *
 * WHAT MAKES THIS THE G2G IMPLEMENTATION RATHER THAN A COPY
 *
 * The capability *names* are LMS K-12's, because they are the platform's shared
 * vocabulary and the menu is built from the same registry. The *tables* are entirely
 * G2G's own, and they had to be: LMS reads `ai_agents` and `ai_conversations`, which
 * do not exist here. G2G's equivalents already hold real data —
 *
 *   Agent Management     agentic_agents / agentic_agent_runs      (the Agentic AI module)
 *   Conversational AI    hpbrain_conversation_sessions / _messages
 *   Knowledge & RAG      hpbrain_knowledge_assets / hpbrain_evidence
 *   Recommendations      hpbrain_recommendations
 *   Knowledge Graph      hpbrain_entity_mappings / hpbrain_context_entities
 *   AI Evaluation        hpbrain_ai_evaluations / _hypotheses / _outcomes
 *   Usage & Cost         hpbrain_ai_executions / ai_daily_used_api
 *
 * — so the console reports what this product has actually done, not an empty shell
 * with an LMS shape.
 *
 * TWO TENANT CONVENTIONS, ONE FILTER
 *
 * G2G's own tables scope on `sub_institute_id`; the `hpbrain_*` tables scope on a
 * `tenant_id` string. `scoped()` handles both, and a table with neither is
 * platform-wide by construction and returned unfiltered rather than silently emptied.
 * That is the one place a tenant filter is written, deliberately.
 *
 * TENANT SCOPE IS NEVER A PARAMETER
 *
 * `$this->scope($request)->selectedInstituteId` comes from `AiContextHydrator`, which
 * derives it from the caller's Sanctum token. It is never read from request input, so
 * a caller cannot name another organisation by editing a query string, and nothing
 * here carries a hard-coded institute, user or module id.
 *
 * HONEST ABOUT WHAT IS NOT THERE
 *
 * A capability whose table has not been migrated onto this deployment returns
 * `state: unavailable` and says which table is missing. One whose table exists but
 * holds nothing for this organisation returns `state: empty`. Those are different
 * facts and the console shows them differently — "not installed" and "nothing
 * recorded yet" lead to different next steps, and collapsing them into one blank
 * screen sends people looking for data that was never going to be there.
 */
class CapabilityController extends AiController
{
    /**
     * Capability key => the G2G tables it reads.
     *
     * The keys match the slugs in `packages/ai-intelligence-core` on the frontend, so
     * the menu entry, the console route and this payload all address the same
     * capability. The first table in each list is the one the index counts.
     */
    private const TABLES = [
        'providers' => ['ai_api_keys'],
        // Model resolution comes from the catalogue plus config, and the catalogue is
        // optional, so nothing gates this one.
        'models' => [],
        'prompts' => ['ai_templates'],
        'policies' => ['ai_policies'],
        'agents' => ['agentic_agents', 'agentic_agent_runs'],
        // The assistant's own transcripts now, not `hpbrain_conversation_*`. Those
        // are written by a different application and this one cannot add to them, so
        // reading them meant the console reported somebody else's conversations and
        // none of its own.
        'conversational-ai' => ['ai_conversations', 'ai_conversation_turns'],
        'knowledge-rag' => ['hpbrain_knowledge_assets', 'hpbrain_evidence'],
        'recommendations' => ['hpbrain_recommendations'],
        'knowledge-graph' => ['hpbrain_entity_mappings', 'hpbrain_context_entities'],
        // `ai_evaluations` rather than `hpbrain_ai_evaluations`: that table stores a
        // run's cases and results as two longtext blobs, so nothing about a run can
        // be queried. See the migration.
        'evaluation' => ['ai_evaluations', 'ai_evaluation_cases'],
        // This layer's own meter. It read `hpbrain_ai_executions` before — a table
        // another application writes — so every call made here was invisible to the
        // panel, which reported zero spend while real calls were being billed.
        'usage-cost' => ['ai_usage_events'],
        'audit' => ['ai_audit_logs'],
    ];

    /** Every capability, with just enough per row for the console index. */
    public function index(Request $request)
    {
        try {
            $instituteId = $this->scope($request)->selectedInstituteId;

            $capabilities = [];

            foreach (array_keys(self::TABLES) as $key) {
                $capabilities[] = ['key' => $key] + $this->summarise($key, $instituteId);
            }

            return $this->success('AI capabilities resolved.', [
                'sub_institute_id' => $instituteId,
                'capabilities' => $capabilities,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One capability, with its metrics and its most recent rows. */
    public function show(Request $request, string $capability)
    {
        try {
            $capability = strtolower(trim($capability));

            if (! array_key_exists($capability, self::TABLES)) {
                return $this->failure("\"{$capability}\" is not an AI capability.", 404);
            }

            $instituteId = $this->scope($request)->selectedInstituteId;
            $missing = $this->missingTables($capability);

            if ($missing !== []) {
                return $this->success('This capability is not installed on this deployment.', [
                    'key' => $capability,
                    'state' => 'unavailable',
                    'missing_tables' => $missing,
                    'metrics' => [],
                    'table' => null,
                ]);
            }

            $limit = $this->limit($request, 25, 100);

            $detail = match ($capability) {
                'providers' => $this->providers($instituteId, $limit),
                'models' => $this->models($instituteId, $limit),
                'prompts' => $this->prompts($instituteId, $limit),
                'policies' => $this->policies($instituteId, $limit),
                'agents' => $this->agents($instituteId, $limit),
                'conversational-ai' => $this->conversational($instituteId, $limit),
                'knowledge-rag' => $this->knowledge($instituteId, $limit),
                'recommendations' => $this->recommendations($instituteId, $limit),
                'knowledge-graph' => $this->knowledgeGraph($instituteId, $limit),
                'evaluation' => $this->evaluation($instituteId, $limit),
                'usage-cost' => $this->usage($instituteId, $limit),
                'audit' => $this->audit($instituteId, $limit),
            };

            return $this->success('Capability resolved.', [
                'key' => $capability,
                'sub_institute_id' => $instituteId,
                'state' => ($detail['table']['rows'] ?? []) === [] ? 'empty' : 'live',
            ] + $detail);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------
    // Per-capability providers. Each returns metrics + one recent-rows table.
    // ---------------------------------------------------------------------

    private function providers(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('keys', 'Credentials', $this->count('ai_api_keys', $institute)),
                $this->metric('active', 'Active', $this->count('ai_api_keys', $institute, ['status' => 1])),
                $this->metric('modules', 'Modules bound', $this->distinct('ai_api_keys', $institute, 'ai_module')),
            ],
            'table' => $this->rows(
                'ai_api_keys',
                $institute,
                [
                    'ai_module' => 'Module',
                    'api_type' => 'Provider',
                    'model' => 'Model',
                    'account_email' => 'Account',
                    'status' => 'Status',
                    'updated_at' => 'Updated',
                ],
                $limit,
                // `api_key` is never selected. A console that can display a credential
                // is a console that can leak one.
            ),
        ];
    }

    /**
     * Which model each configured provider resolves to.
     *
     * Reads the catalogue where there is one and `config/ai.php` where there is not,
     * because those two together are what actually decides the model — there is no
     * third list, and reporting one would make Model Management look configured when
     * nothing behind it was.
     */
    private function models(int|string $institute, int $limit): array
    {
        $rows = [];
        $active = (string) config('ai.provider.driver');

        if (Schema::hasTable('ai_models')) {
            $catalogue = DB::table('ai_models')
                ->where('status', 1)
                ->where(function ($inner) use ($institute) {
                    $inner->whereNull('sub_institute_id')->orWhere('sub_institute_id', $institute);
                })
                ->orderBy('provider')
                ->orderBy('sort_order')
                ->limit($limit)
                ->get();

            foreach ($catalogue as $row) {
                $rows[] = [
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->model_id,
                    'label' => (string) $row->label,
                    'max_output_tokens' => $row->max_output_tokens === null ? '—' : (string) $row->max_output_tokens,
                    'scope' => ($row->sub_institute_id ?? null) === null ? 'Platform' : 'This organisation',
                    'state' => (string) $row->provider === $active ? 'Active driver' : 'Selectable',
                ];
            }
        }

        return [
            'metrics' => [
                $this->metric('models', 'Models in catalogue', $this->count('ai_models', $institute, ['status' => 1])),
                $this->metric('providers', 'Providers offered', $this->distinct('ai_models', $institute, 'provider')),
                $this->metric('pinned', 'Templates pinning a model', $this->distinct('ai_templates', $institute, 'model')),
            ],
            'table' => [
                'columns' => [
                    ['key' => 'provider', 'label' => 'Provider'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'label', 'label' => 'Name'],
                    ['key' => 'max_output_tokens', 'label' => 'Max output tokens'],
                    ['key' => 'scope', 'label' => 'Scope'],
                    ['key' => 'state', 'label' => 'State'],
                ],
                'rows' => $rows,
            ],
        ];
    }

    private function prompts(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('templates', 'Templates', $this->count('ai_templates', $institute)),
                // `ai_templates.status` is a word, not a flag — counting `status = 1`
                // would report 0 published against every row that exists.
                $this->metric('active', 'Published', $this->count('ai_templates', $institute, ['status' => 'published'])),
                $this->metric('modules', 'Modules covered', $this->distinct('ai_templates', $institute, 'module_key')),
            ],
            'table' => $this->rows(
                'ai_templates',
                $institute,
                [
                    'template_key' => 'Key',
                    'name' => 'Name',
                    'module_key' => 'Module',
                    'version' => 'Version',
                    'provider' => 'Provider',
                    'status' => 'Status',
                ],
                $limit
            ),
        ];
    }

    private function policies(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('policies', 'Policies', $this->count('ai_policies', $institute)),
                $this->metric('active', 'Active', $this->count('ai_policies', $institute, ['status' => 1])),
                $this->metric('assignments', 'Assignments', $this->count('ai_policy_assignments', $institute)),
            ],
            'table' => $this->rows(
                'ai_policies',
                $institute,
                [
                    'name' => 'Policy',
                    'policy_type' => 'Type',
                    'status' => 'Status',
                    'require_disclosure' => 'Disclosure',
                    'updated_at' => 'Updated',
                ],
                $limit
            ),
        ];
    }

    /**
     * Agent Management, over the Agentic AI module's own tables.
     *
     * This is the capability the shared registry already records G2G as owning, and
     * these are the rows behind that claim: the agent library the Agentic AI module
     * writes and the runs it records.
     */
    private function agents(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('agents', 'Registered agents', $this->count('agentic_agents', $institute)),
                $this->metric('deployed', 'Deployed', $this->count('agentic_agents', $institute, ['status' => 'deployed'])),
                $this->metric('runs', 'Runs', $this->count('agentic_agent_runs', $institute)),
            ],
            'table' => $this->rows(
                'agentic_agent_runs',
                $institute,
                [
                    'agent_id' => 'Agent',
                    'status' => 'Status',
                    'trigger' => 'Trigger',
                    'tokens_used' => 'Tokens',
                    'duration_ms' => 'Duration (ms)',
                    'started_at' => 'Started',
                ],
                $limit
            ),
        ];
    }

    private function conversational(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('conversations', 'Conversations', $this->count('ai_conversations', $institute)),
                $this->metric('turns', 'Turns', $this->count('ai_conversation_turns', $institute)),
                // Failed turns, named explicitly. A count of conversations that hides
                // how many answers never arrived flatters the capability.
                $this->metric('failed', 'Turns that failed', $this->failedTurns($institute)),
            ],
            'table' => $this->rows(
                'ai_conversations',
                $institute,
                [
                    'title' => 'Conversation',
                    'module_key' => 'Module',
                    'turn_count' => 'Turns',
                    'status' => 'Status',
                    'last_turn_at' => 'Last activity',
                ],
                $limit
            ),
        ];
    }

    /**
     * Turns that recorded an error.
     *
     * Its own method because `count()` compares a column to a value and this asks
     * whether a column is set at all — a distinction worth keeping out of the shared
     * helper rather than adding a mode to it.
     */
    private function failedTurns(int|string $institute): int
    {
        if (! Schema::hasTable('ai_conversation_turns')) {
            return 0;
        }

        return (int) $this->scoped('ai_conversation_turns', $institute)->whereNotNull('error')->count();
    }

    private function knowledge(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('assets', 'Knowledge assets', $this->count('hpbrain_knowledge_assets', $institute)),
                $this->metric('evidence', 'Evidence records', $this->count('hpbrain_evidence', $institute)),
                $this->metric('categories', 'Categories', $this->distinct('hpbrain_knowledge_assets', $institute, 'category')),
            ],
            'table' => $this->rows(
                'hpbrain_knowledge_assets',
                $institute,
                [
                    'title' => 'Document',
                    'category' => 'Category',
                    'confidence' => 'Confidence',
                    'reuse_count' => 'Reused',
                    'status' => 'Status',
                    'updated_date' => 'Updated',
                ],
                $limit
            ),
        ];
    }

    private function recommendations(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('total', 'Recommendations', $this->count('hpbrain_recommendations', $institute)),
                $this->metric('open', 'Open', $this->count('hpbrain_recommendations', $institute, ['status' => 'open'])),
                $this->metric('categories', 'Categories', $this->distinct('hpbrain_recommendations', $institute, 'category')),
            ],
            'table' => $this->rows(
                'hpbrain_recommendations',
                $institute,
                [
                    'title' => 'Recommendation',
                    'category' => 'Category',
                    'priority' => 'Priority',
                    'confidence' => 'Confidence',
                    'impact' => 'Impact',
                    'status' => 'Status',
                ],
                $limit
            ),
        ];
    }

    private function knowledgeGraph(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('mappings', 'Entity mappings', $this->count('hpbrain_entity_mappings', $institute)),
                $this->metric('entities', 'Universal entities', $this->distinct('hpbrain_entity_mappings', $institute, 'universal_entity')),
                $this->metric('terms', 'Context terms', $this->count('hpbrain_context_entities', $institute)),
            ],
            'table' => $this->rows(
                'hpbrain_entity_mappings',
                $institute,
                [
                    'source_system' => 'Source system',
                    'source_entity' => 'Source entity',
                    'universal_entity' => 'Universal entity',
                    'universal_field' => 'Universal field',
                    'mapping_type' => 'Mapping',
                    'is_active' => 'Active',
                ],
                $limit
            ),
        ];
    }

    private function evaluation(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('evaluations', 'Evaluations', $this->count('ai_evaluations', $institute)),
                $this->metric('completed', 'Runs completed', $this->count('ai_evaluations', $institute, ['status' => 'completed'])),
                $this->metric('cases', 'Cases', $this->count('ai_evaluation_cases', $institute)),
            ],
            'table' => $this->rows(
                'ai_evaluations',
                $institute,
                [
                    'name' => 'Evaluation',
                    'template_key' => 'Template',
                    'model' => 'Model',
                    'status' => 'Status',
                    'score' => 'Score',
                    'finished_at' => 'Run',
                ],
                $limit
            ),
        ];
    }

    private function usage(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('calls', 'Model calls metered', $this->count('ai_usage_events', $institute)),
                $this->metric('tokens', 'Tokens consumed', $this->tokenTotal($institute)),
                $this->metric('modules', 'Modules calling', $this->distinct('ai_usage_events', $institute, 'ai_module')),
            ],
            'table' => $this->rows(
                'ai_usage_events',
                $institute,
                [
                    'ai_module' => 'Module',
                    'provider' => 'Provider',
                    'model' => 'Model',
                    'input_tokens' => 'In',
                    'output_tokens' => 'Out',
                    'estimated_cost_usd' => 'Cost (USD)',
                    'outcome' => 'Outcome',
                    'created_at' => 'When',
                ],
                $limit
            ),
        ];
    }

    /**
     * Tokens consumed, in and out together.
     *
     * A sum across two columns, which `count()` and `distinct()` cannot express — and
     * the figure an administrator actually asks for, since a call count says nothing
     * about spend when one call can be a hundred times another.
     */
    private function tokenTotal(int|string $institute): int
    {
        if (! Schema::hasTable('ai_usage_events')) {
            return 0;
        }

        return (int) $this->scoped('ai_usage_events', $institute)
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    private function audit(int|string $institute, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('events', 'AI events recorded', $this->count('ai_audit_logs', $institute)),
                $this->metric('types', 'Event types', $this->distinct('ai_audit_logs', $institute, 'event_type')),
                // The other two ledgers this product keeps, named so an administrator
                // knows where else to look rather than concluding nothing is recorded.
                $this->metric('platform_events', 'Platform audit entries', $this->count('g2g_audit_log', $institute)),
            ],
            'table' => $this->rows(
                'ai_audit_logs',
                $institute,
                [
                    'event_type' => 'Event',
                    'actor_id' => 'Actor',
                    'actor_type' => 'Actor type',
                    'outcome' => 'Outcome',
                    'message' => 'Message',
                    'created_at' => 'When',
                ],
                $limit
            ),
        ];
    }

    // ---------------------------------------------------------------------
    // Shared helpers. Every query below is filtered by the caller's organisation.
    // ---------------------------------------------------------------------

    /** Counts per capability, cheap enough for the index. */
    private function summarise(string $capability, int|string $institute): array
    {
        $missing = $this->missingTables($capability);

        if ($missing !== []) {
            return ['state' => 'unavailable', 'count' => 0, 'missing_tables' => $missing];
        }

        // `models` has no gating table: its count is the catalogue, which is optional.
        $primary = self::TABLES[$capability][0] ?? 'ai_models';
        $count = $this->count($primary, $institute);

        return [
            'state' => $count > 0 ? 'live' : 'empty',
            'count' => $count,
            'primary_table' => $primary,
        ];
    }

    /** @return array<int, string> tables this deployment has not migrated. */
    private function missingTables(string $capability): array
    {
        return array_values(array_filter(
            self::TABLES[$capability],
            fn (string $table) => ! Schema::hasTable($table)
        ));
    }

    /** @param array<string, mixed> $where */
    private function count(string $table, int|string $institute, array $where = []): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = $this->scoped($table, $institute);

        foreach ($where as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $query->where($column, $value);
            }
        }

        return (int) $query->count();
    }

    private function distinct(string $table, int|string $institute, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) $this->scoped($table, $institute)->distinct()->count($column);
    }

    /**
     * A table's most recent rows, restricted to columns that actually exist.
     *
     * Column sets drift between deployments, so selecting only what
     * `Schema::hasColumn` confirms means a missing column costs one blank column
     * rather than a 500.
     *
     * @param  array<string, string>  $columns
     * @return array{columns:array<int, array{key:string,label:string}>, rows:array<int, array<string, string|null>>}
     */
    private function rows(string $table, int|string $institute, array $columns, int $limit): array
    {
        $present = array_filter(
            $columns,
            fn (string $label, string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($present === []) {
            return ['columns' => [], 'rows' => []];
        }

        $query = $this->scoped($table, $institute)->select(array_keys($present));

        $query->orderByDesc($this->orderColumn($table));

        $rows = $query->limit($limit)->get()->map(
            fn ($row) => array_map(
                fn ($value) => $value === null ? null : (string) $value,
                (array) $row
            )
        )->all();

        return [
            'columns' => array_map(
                fn (string $column, string $label) => ['key' => $column, 'label' => $label],
                array_keys($present),
                array_values($present)
            ),
            'rows' => $rows,
        ];
    }

    /**
     * What "most recent" means for a table.
     *
     * The `hpbrain_*` tables have UUID primary keys, so `ORDER BY id DESC` on them is
     * not "newest first" — it is alphabetical, which would show an arbitrary slice and
     * call it recent activity. A timestamp is preferred wherever one exists, and `id`
     * is only the fallback for a table that has none.
     */
    private function orderColumn(string $table): string
    {
        foreach (['created_date', 'created_at', 'started_at', 'run_date', 'date'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }

    /**
     * The one place a tenant filter is written.
     *
     * Three conventions exist across the tables this console reads:
     *
     *   `sub_institute_id`  G2G's own tables. A null row is shared configuration
     *                       seeded for every organisation, so it is included
     *                       alongside the organisation's own.
     *   `tenant_id`         the `hpbrain_*` tables, which hold it as a string. `*`
     *                       is that schema's spelling of "every tenant".
     *   neither             platform-wide by construction, returned unfiltered rather
     *                       than silently emptied.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function scoped(string $table, int|string $institute)
    {
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'sub_institute_id')) {
            $query->where(function ($inner) use ($institute) {
                $inner->where('sub_institute_id', $institute)
                    ->orWhereNull('sub_institute_id');
            });
        } elseif (Schema::hasColumn($table, 'tenant_id')) {
            $query->where(function ($inner) use ($institute) {
                $inner->where('tenant_id', (string) $institute)
                    ->orWhere('tenant_id', '*')
                    ->orWhereNull('tenant_id');
            });
        }

        foreach (['deleted_at', 'deleted_date'] as $softDelete) {
            if (Schema::hasColumn($table, $softDelete)) {
                $query->whereNull($softDelete);
            }
        }

        return $query;
    }

    /** @return array{key:string, label:string, value:int} */
    private function metric(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value];
    }
}
