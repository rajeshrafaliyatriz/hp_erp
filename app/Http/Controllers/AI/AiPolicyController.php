<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Support\ActiveRowFilter;
use App\Domain\AI\Support\AiAuditLogger;
use App\Services\Ai\AiPolicyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AI Policies — what the AI is permitted to do, and where.
 *
 * A policy is three records: the policy itself (`ai_policies`), the switches that
 * make it mean something (`ai_policy_rules`), and the places it applies
 * (`ai_policy_assignments`). They are written and read together here, because a
 * policy with no rules governs nothing and a policy with no assignment governs
 * nowhere — letting either be saved separately would let a screen show a policy that
 * does not actually apply.
 *
 * SCOPE OPTIONS COME FROM G2G's OWN TABLES
 *
 * `options()` returns the modules a policy can be scoped to straight from
 * `ai_modules`, and the departments and job roles from `hrms_departments` and
 * `s_jobrole`. Nothing is hard-coded: a scope dropdown listing ids that do not exist
 * in this deployment is worse than no dropdown, because the assignment it produces
 * silently matches nothing.
 *
 * THE EXAMPLE POLICY IS SEEDED BY A MIGRATION, NOT WRITTEN ON READ
 *
 * LMS K-12's copy writes its demonstration policy from inside `index()`, on first
 * read. That is the part not copied: a read that writes is a surprise, it fires for
 * whichever administrator happens to open the screen first and attributes the row to
 * them, and it cannot be rolled back.
 *
 * `2026_09_21_130000_seed_example_ai_policy` does it instead — visible in version
 * control, idempotent, reversible, and attributed to the migration rather than to a
 * person. The concern that motivated leaving it out originally still stands and is
 * answered there rather than dismissed: a row nobody wrote that says "Active" is a
 * governance claim nobody made, so the example is scoped to one module rather than
 * globally and carries only the catalogue's own default switches.
 */
class AiPolicyController extends AiController
{
    public function __construct(
        private readonly AiPolicyResolver $resolver,
        private readonly AiAuditLogger $audit,
        private readonly \App\Domain\AI\Support\SchemaCache $schema,
    ) {
    }

    public function options(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            return $this->success('AI policy options resolved.', [
                'policy_types' => $this->resolver->policyTypeOptions(),
                'rule_catalogue' => $this->resolver->ruleCatalogue(),
                'scope_types' => $this->resolver->scopeTypeOptions(),
                // The real targets an assignment can name, for each scope that has
                // any. A scope with no targets in this deployment simply arrives
                // empty and the screen offers it without a picker.
                'scope_targets' => [
                    'module' => $this->targets('ai_modules', 'label', $institute, 'sub_institute_id'),
                    'department' => $this->targets('hrms_departments', 'department', $institute, 'sub_institute_id'),
                    // `s_jobrole` is a shared taxonomy with no tenant column — it is
                    // the same catalogue of roles for every organisation — so nothing
                    // is passed to scope it by.
                    'job_role' => $this->targets('s_jobrole', 'jobrole', $institute, null),
                ],
                // The same modules again, with their key. The shared (LMS_K12-universal)
                // module AI Stack scopes a new policy to its own module by key, and needs
                // the `ai_modules` id that key resolves to on this estate — ids differ
                // between deployments, so they are looked up, never hardcoded. Additive:
                // the central console reads `scope_targets` and ignores this.
                'modules' => $this->moduleOptions($institute),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Policies this organisation can see.
     *
     * `module_key` narrows the list to the policies that govern one module: those with
     * a `module` assignment naming it. Omitted, the behaviour is every policy, which
     * is what the central console wants.
     */
    public function index(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $moduleKey = trim((string) $request->input('module_key', ''));
            $moduleIds = $moduleKey === '' ? [] : $this->moduleIds($moduleKey, $institute);

            $query = DB::table('ai_policies as p')
                ->where(function ($inner) use ($institute) {
                    $inner->where('p.sub_institute_id', $institute)
                        ->orWhereNull('p.sub_institute_id');
                });

            if ($moduleKey !== '') {
                // No `ai_modules` row for that key means no policy can be scoped to
                // it. An empty list is the honest answer; falling through to every
                // policy would quietly show another module's configuration.
                if ($moduleIds === []) {
                    return $this->success('AI policies resolved.', [
                        'sub_institute_id' => $institute,
                        'module_key' => $moduleKey,
                        'module_ids' => [],
                        'policies' => [],
                    ]);
                }

                $query->whereExists(function ($exists) use ($moduleIds) {
                    $exists->from('ai_policy_assignments as a')
                        ->whereColumn('a.policy_id', 'p.id')
                        ->where('a.scope_type', 'module')
                        ->whereIn('a.scope_id', $moduleIds);
                });
            }

            $rows = $query->orderByDesc('p.updated_at')->orderByDesc('p.id')->get()->all();

            $policies = array_values(array_filter(array_map(
                fn ($row) => $this->policyDetail((int) $row->id, $institute),
                $rows
            )));

            return $this->success('AI policies resolved.', [
                'sub_institute_id' => $institute,
                'module_key' => $moduleKey === '' ? null : $moduleKey,
                'module_ids' => array_values($moduleIds),
                'policies' => $policies,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;
            $data = $this->validatedPolicy($request);

            $id = DB::table('ai_policies')->insertGetId([
                'sub_institute_id' => $institute,
                'name' => trim($data['name']),
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                'policy_type' => $data['policy_type'],
                'status' => $data['status'] ?? 1,
                'require_disclosure' => $data['require_disclosure'] ?? 0,
                'require_acknowledgement' => $data['require_acknowledgement'] ?? 0,
                'ai_detection_required' => $data['ai_detection_required'] ?? 0,
                'plagiarism_check_required' => $data['plagiarism_check_required'] ?? 0,
                'detection_provider' => $data['detection_provider'] ?? null,
                'detection_threshold' => $data['detection_threshold'] ?? null,
                'created_by' => $scope->userId,
                'updated_by' => $scope->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->saveRules($id, $data['rules'] ?? []);
            $this->saveAssignments($id, $data['assignments'] ?? [], $institute, $scope->userId);

            $this->audit->record('ai.policy.created', $scope, [
                'related_type' => 'ai_policies',
                'related_id' => $id,
                'message' => sprintf('AI policy "%s" created.', trim($data['name'])),
            ]);

            return $this->success('AI policy saved.', [
                'policy' => $this->policyDetail($id, $institute),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $row = $this->ownedPolicy($id, $institute);

            if ($row === null) {
                return $this->failure('That AI policy was not found.', 404);
            }

            $data = $this->validatedPolicy($request);

            // A platform policy is shared by every organisation, so an edit never
            // rewrites it: it writes THIS organisation its own copy, which takes
            // precedence over the platform one — the same fork LMS_K12 does, and exactly
            // what the old refusal told the user to do by hand. The platform row is left
            // intact for everyone else.
            if ($row->sub_institute_id === null) {
                $forkId = (int) DB::table('ai_policies')->insertGetId([
                    'sub_institute_id' => $institute,
                    'name' => trim($data['name']),
                    'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                    'policy_type' => $data['policy_type'],
                    'status' => $data['status'] ?? 1,
                    'require_disclosure' => $data['require_disclosure'] ?? 0,
                    'require_acknowledgement' => $data['require_acknowledgement'] ?? 0,
                    'ai_detection_required' => $data['ai_detection_required'] ?? 0,
                    'plagiarism_check_required' => $data['plagiarism_check_required'] ?? 0,
                    'detection_provider' => $data['detection_provider'] ?? null,
                    'detection_threshold' => $data['detection_threshold'] ?? null,
                    'created_by' => $scope->userId,
                    'updated_by' => $scope->userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->saveRules($forkId, $data['rules'] ?? []);
                $this->saveAssignments($forkId, $data['assignments'] ?? [], $institute, $scope->userId);

                $this->audit->record('ai.policy.forked', $scope, [
                    'related_type' => 'ai_policies',
                    'related_id' => $forkId,
                    'message' => sprintf('Platform AI policy #%d copied to this organisation as "%s".', $id, trim($data['name'])),
                ]);

                return $this->success('Saved as this organisation\'s own copy of the shared policy.', [
                    'policy' => $this->policyDetail($forkId, $institute),
                    'action' => 'forked',
                    'forked_from' => $id,
                ]);
            }

            DB::table('ai_policies')->where('id', $id)->update([
                'name' => trim($data['name']),
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                'policy_type' => $data['policy_type'],
                'status' => $data['status'] ?? 1,
                'require_disclosure' => $data['require_disclosure'] ?? 0,
                'require_acknowledgement' => $data['require_acknowledgement'] ?? 0,
                'ai_detection_required' => $data['ai_detection_required'] ?? 0,
                'plagiarism_check_required' => $data['plagiarism_check_required'] ?? 0,
                'detection_provider' => $data['detection_provider'] ?? null,
                'detection_threshold' => $data['detection_threshold'] ?? null,
                'updated_by' => $scope->userId,
                'updated_at' => now(),
            ]);

            $this->saveRules($id, $data['rules'] ?? []);
            $this->saveAssignments($id, $data['assignments'] ?? [], $institute, $scope->userId);

            $this->audit->record('ai.policy.updated', $scope, [
                'related_type' => 'ai_policies',
                'related_id' => $id,
                'message' => sprintf('AI policy "%s" updated.', trim($data['name'])),
            ]);

            return $this->success('AI policy updated.', [
                'policy' => $this->policyDetail($id, $institute),
                'action' => 'updated',
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Retire a policy.
     *
     * Sets `status = 0` rather than deleting. A deleted policy takes with it the only
     * record of what was permitted while it applied, and the audit rows that name it
     * become unreadable. A retired policy stops resolving immediately, which is the
     * part that matters operationally.
     */
    public function destroy(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $row = $this->ownedPolicy($id, $institute);

            if ($row === null) {
                return $this->failure('That AI policy was not found.', 404);
            }

            if ($row->sub_institute_id === null) {
                return $this->failure(
                    'This is a platform policy shared by every organisation and cannot be retired here.',
                    403
                );
            }

            DB::table('ai_policies')->where('id', $id)->update([
                'status' => 0,
                'updated_by' => $scope->userId,
                'updated_at' => now(),
            ]);

            $this->audit->record('ai.policy.retired', $scope, [
                'related_type' => 'ai_policies',
                'related_id' => $id,
                'message' => 'AI policy retired.',
            ]);

            return $this->success('AI policy retired.', [
                'id' => $id,
                'sub_institute_id' => $institute,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------
    // Validation and reads
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validatedPolicy(Request $request): array
    {
        $policyTypes = array_column($this->resolver->policyTypeOptions(), 'value');
        $scopeTypes = array_column($this->resolver->scopeTypeOptions(), 'value');
        $ruleKeys = array_column($this->resolver->ruleCatalogue(), 'key');

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'policy_type' => 'required|string|in:' . implode(',', $policyTypes),
            'status' => 'nullable|integer|in:0,1',
            'require_disclosure' => 'nullable|integer|in:0,1',
            'require_acknowledgement' => 'nullable|integer|in:0,1',
            'ai_detection_required' => 'nullable|integer|in:0,1',
            'plagiarism_check_required' => 'nullable|integer|in:0,1',
            'detection_provider' => 'nullable|string|max:120',
            'detection_threshold' => 'nullable|numeric|min:0|max:100',
            'rules' => 'nullable|array',
            'rules.*' => 'nullable|boolean',
            'assignments' => 'nullable|array',
            'assignments.*.scope_type' => 'required|string|in:' . implode(',', $scopeTypes),
            'assignments.*.scope_id' => 'nullable|integer|min:1',
            'assignments.*.status' => 'nullable|integer|in:0,1',
        ]);

        // Only rules the catalogue knows are stored. A rule key nothing reads is a
        // switch that looks like governance and is not, which is the worst kind of
        // setting to leave in a table.
        $rules = [];

        foreach ($validated['rules'] ?? [] as $key => $value) {
            if (in_array((string) $key, $ruleKeys, true)) {
                $rules[(string) $key] = (bool) $value;
            }
        }

        $validated['rules'] = $rules;

        $validated['assignments'] = array_map(fn (array $assignment): array => [
            'scope_type' => (string) ($assignment['scope_type'] ?? 'global'),
            'scope_id' => isset($assignment['scope_id']) && $assignment['scope_id'] !== ''
                ? (int) $assignment['scope_id']
                : null,
            'status' => isset($assignment['status']) ? (int) $assignment['status'] : 1,
        ], $validated['assignments'] ?? []);

        return $validated;
    }

    /** A policy this organisation may see. Ownership is checked separately. */
    private function ownedPolicy(int $id, int|string|null $institute): ?object
    {
        return DB::table('ai_policies')
            ->where('id', $id)
            ->where(function ($inner) use ($institute) {
                $inner->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id');
            })
            ->first();
    }

    /**
     * The rows a scope can point at, as {id, label} pairs.
     *
     * Guarded on both the table and the label column existing, because these are
     * G2G's own tables and a deployment that has not run every module's migrations
     * should lose one dropdown rather than the whole screen.
     *
     * @return array<int, array{id:int, label:string}>
     */
    private function targets(string $table, string $labelColumn, int|string|null $institute, ?string $tenantColumn): array
    {
        if (! $this->schema->hasTable($table) || ! $this->schema->hasColumn($table, $labelColumn)) {
            return [];
        }

        $query = DB::table($table);

        if ($tenantColumn !== null && $this->schema->hasColumn($table, $tenantColumn)) {
            $query->where(function ($inner) use ($tenantColumn, $institute) {
                $inner->where($tenantColumn, $institute)->orWhereNull($tenantColumn);
            });
        }

        if ($this->schema->hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        // NOT `where('status', 1)`. These are G2G's own tables and they disagree
        // about how to say "active": `hrms_departments` uses the integer 1, while
        // `s_jobrole` is an enum of 'Active' | 'Inactive'. Comparing that enum to 1
        // matched none of its 3,347 rows, so the job-role scope picker rendered
        // empty and a policy could not be scoped to a role at all. See
        // ActiveRowFilter for why the test is inverted.
        ActiveRowFilter::apply($query, $table);

        return $query
            ->orderBy($labelColumn)
            ->limit(500)
            ->get(['id', $labelColumn])
            ->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->{$labelColumn}])
            ->all();
    }

    /**
     * Every `ai_modules` id this organisation resolves for one module key.
     *
     * Plural because a key can exist at both platform and organisation scope, and an
     * assignment may name either. Matching only one would hide policies saved against
     * the other.
     *
     * @return array<int, int>
     */
    /** @return array<int, array{id:int, key:string, label:string}> */
    private function moduleOptions(int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_modules')) {
            return [];
        }

        return DB::table('ai_modules')
            ->where('status', 1)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'module_key', 'label'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'key' => (string) $row->module_key, 'label' => (string) $row->label])
            ->all();
    }

    private function moduleIds(string $moduleKey, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_modules')) {
            return [];
        }

        return DB::table('ai_modules')
            ->where('module_key', $moduleKey)
            ->where(function ($query) use ($institute) {
                $query->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The module keys a policy's `module` assignments point at.
     *
     * Resolved for the reader's benefit: an assignment stores an id, and a screen that
     * had to translate ids itself would need the module table too.
     *
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, string>
     */
    private function assignedModuleKeys(array $assignments): array
    {
        $ids = [];

        foreach ($assignments as $assignment) {
            if (($assignment['scope_type'] ?? '') === 'module' && $assignment['scope_id'] !== null) {
                $ids[] = (int) $assignment['scope_id'];
            }
        }

        if ($ids === [] || ! $this->schema->hasTable('ai_modules')) {
            return [];
        }

        return array_values(array_unique(
            DB::table('ai_modules')
                ->whereIn('id', $ids)
                ->pluck('module_key')
                ->map(fn ($key) => (string) $key)
                ->all()
        ));
    }

    /** @return array<string, mixed>|null */
    private function policyDetail(int $id, int|string|null $institute): ?array
    {
        $row = DB::table('ai_policies')->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $rules = [];

        foreach (DB::table('ai_policy_rules')->where('policy_id', $id)->get() as $rule) {
            $rules[(string) $rule->rule_key] = (bool) $rule->rule_value;
        }

        $assignments = DB::table('ai_policy_assignments')
            ->where('policy_id', $id)
            ->orderBy('id')
            ->get()
            ->map(fn ($assignment) => [
                'id' => (int) $assignment->id,
                'policy_id' => (int) $assignment->policy_id,
                'scope_type' => (string) $assignment->scope_type,
                'scope_id' => $assignment->scope_id !== null ? (int) $assignment->scope_id : null,
                'sub_institute_id' => $assignment->sub_institute_id !== null ? (int) $assignment->sub_institute_id : null,
                'status' => (int) $assignment->status,
            ])
            ->all();

        return [
            'id' => (int) $row->id,
            'sub_institute_id' => $row->sub_institute_id !== null ? (int) $row->sub_institute_id : null,
            'is_platform' => $row->sub_institute_id === null,
            // A seeded worked example: a platform row whose name says so. G2G has no
            // `is_example` column, and every seeded example is named "(example)".
            'is_example' => $row->sub_institute_id === null && str_ends_with((string) $row->name, '(example)') ? 1 : 0,
            // Platform policies are shared: this organisation reads them, and an edit
            // writes it its own copy (see update()) rather than changing the shared row.
            'editable' => $row->sub_institute_id !== null,
            'name' => (string) $row->name,
            'description' => $row->description,
            'policy_type' => (string) $row->policy_type,
            'status' => (int) $row->status,
            'require_disclosure' => (int) $row->require_disclosure,
            'require_acknowledgement' => (int) $row->require_acknowledgement,
            'ai_detection_required' => (int) $row->ai_detection_required,
            'plagiarism_check_required' => (int) $row->plagiarism_check_required,
            'detection_provider' => $row->detection_provider,
            'detection_threshold' => $row->detection_threshold !== null ? (float) $row->detection_threshold : null,
            'created_by' => $row->created_by,
            'updated_by' => $row->updated_by,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'rules' => $rules,
            'assignments' => $assignments,
            // Which modules this policy governs, as keys rather than ids. Empty means
            // it is not scoped to any module — it applies wherever its other
            // assignments say, which for a policy with none at all is everywhere.
            'module_keys' => $this->assignedModuleKeys($assignments),
            'institute_scope' => $institute,
        ];
    }

    /** @param array<string, bool> $rules */
    private function saveRules(int $policyId, array $rules): void
    {
        DB::table('ai_policy_rules')->where('policy_id', $policyId)->delete();

        foreach ($rules as $ruleKey => $enabled) {
            if (! is_string($ruleKey) || $ruleKey === '') {
                continue;
            }

            DB::table('ai_policy_rules')->insert([
                'policy_id' => $policyId,
                'rule_key' => $ruleKey,
                'rule_value' => $enabled ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $assignments */
    private function saveAssignments(
        int $policyId,
        array $assignments,
        int|string|null $institute,
        int|string|null $userId
    ): void {
        DB::table('ai_policy_assignments')->where('policy_id', $policyId)->delete();

        // A policy with no assignment governs nowhere, which is almost never what
        // somebody who just wrote one meant. Defaulting to a whole-organisation
        // assignment makes an unassigned policy do the obvious thing instead of
        // nothing at all — and it is still visible and removable on the screen.
        if ($assignments === []) {
            $assignments = [['scope_type' => 'global', 'scope_id' => null, 'status' => 1]];
        }

        foreach ($assignments as $assignment) {
            DB::table('ai_policy_assignments')->insert([
                'policy_id' => $policyId,
                'scope_type' => trim((string) ($assignment['scope_type'] ?? 'global')),
                'scope_id' => isset($assignment['scope_id']) && $assignment['scope_id'] !== ''
                    ? (int) $assignment['scope_id']
                    : null,
                'sub_institute_id' => $institute,
                'status' => isset($assignment['status']) ? (int) $assignment['status'] : 1,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
