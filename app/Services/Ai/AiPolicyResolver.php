<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI is permitted to do, and who says so.
 *
 * The G2G counterpart of LMS K-12's `App\Services\AI\AiPolicyResolver`: same
 * contract, same three methods the controller reads, same storage shape
 * (`ai_policies` + `ai_policy_rules` + `ai_policy_assignments`). What differs is the
 * vocabulary, and it has to.
 *
 * WHY THE RULES AND SCOPES ARE NOT THE SAME LIST
 *
 * LMS K-12's catalogue is about a student and an assignment — "use AI for completing
 * assignments", scoped to a grade, a class, an academic year. G2G has no students,
 * no classes and no academic year; it has employees, job roles, departments and
 * competency assessments. Copying that list would have produced a policy screen whose
 * every switch named something this product does not have, which is worse than no
 * policy screen: it reads as configured when it governs nothing.
 *
 * So the *shape* is identical and portable, and the *terms* are G2G's own. A policy
 * written here is structurally the same record as one written there, which is what
 * makes a shared policy service possible later; it simply talks about the right
 * things.
 *
 * SCOPES RESOLVE NARROWEST-FIRST
 *
 * A policy assigned to a job role beats one assigned to a department, which beats a
 * global one. That is the only ordering that makes an override mean anything — a
 * broader rule winning would make the narrower assignment decorative.
 */
class AiPolicyResolver
{
    /**
     * The scopes an assignment may name, narrowest last.
     *
     * The order is the precedence, read directly by `assignmentsForScope()`. `module`
     * names an `ai_modules` row, so "this policy governs Competency Management's AI"
     * is expressed with columns `ai_policy_assignments` already has — nothing was
     * added to the schema to store it.
     *
     * @var array<int, array{value:string, label:string}>
     */
    private const SCOPE_TYPES = [
        ['value' => 'global', 'label' => 'Whole organisation'],
        ['value' => 'module', 'label' => 'Module'],
        ['value' => 'department', 'label' => 'Department'],
        ['value' => 'job_role', 'label' => 'Job role'],
        ['value' => 'competency', 'label' => 'Competency'],
        ['value' => 'course', 'label' => 'Course'],
        ['value' => 'employee', 'label' => 'Individual employee'],
    ];

    /** @return array<int, array{value:string, label:string}> */
    public function scopeTypeOptions(): array
    {
        return self::SCOPE_TYPES;
    }

    /**
     * The rule keys a policy editor can toggle, plus the labels the UI reads.
     *
     * Stored in `ai_policy_rules` as rule_key => rule_value. Each one names something
     * a person in this product actually asks an AI to do.
     *
     * @return array<int, array{key:string, label:string, default:bool}>
     */
    public function ruleCatalogue(): array
    {
        return [
            ['key' => 'use_ai_for_brainstorming', 'label' => 'Brainstorming and ideation', 'default' => true],
            ['key' => 'use_ai_for_grammar_spelling', 'label' => 'Grammar and spelling assistance', 'default' => true],
            ['key' => 'use_ai_for_explanations', 'label' => 'Explaining a concept or a record', 'default' => true],
            ['key' => 'use_ai_for_summarization', 'label' => 'Summarising records and reports', 'default' => true],
            ['key' => 'use_ai_for_rewriting', 'label' => 'Rewriting and tone adjustment', 'default' => true],
            ['key' => 'use_ai_for_generating_answers', 'label' => 'Generating answers in an assessment', 'default' => false],
            ['key' => 'use_ai_for_generating_code', 'label' => 'Generating code', 'default' => false],
            ['key' => 'use_ai_for_generating_images', 'label' => 'Generating images', 'default' => false],
            ['key' => 'use_ai_for_competency_rating', 'label' => 'Proposing a competency rating', 'default' => false],
            ['key' => 'use_ai_for_candidate_screening', 'label' => 'Screening or ranking candidates', 'default' => false],
            ['key' => 'use_ai_for_performance_review', 'label' => 'Drafting a performance review', 'default' => false],
            ['key' => 'use_ai_for_autonomous_action', 'label' => 'Acting without a human approving first', 'default' => false],
        ];
    }

    /** @return array<int, array{value:string, label:string}> */
    public function policyTypeOptions(): array
    {
        return [
            ['value' => 'ai_free', 'label' => 'AI-Free'],
            ['value' => 'ai_assisted', 'label' => 'AI-Assisted'],
            ['value' => 'ai_empowered', 'label' => 'AI-Empowered'],
            ['value' => 'custom', 'label' => 'Custom'],
        ];
    }

    /**
     * The policy that governs one request, and whether it permits the operation.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolve(int|string|null $subInstituteId, array $context = []): array
    {
        $assignments = $this->assignmentsForScope($subInstituteId, $context);

        $policyId = $assignments === [] ? null : (int) $assignments[0]['policy_id'];
        $policy = $policyId === null ? null : $this->policyRow($policyId);
        $rules = $policyId === null ? [] : $this->rulesForPolicy($policyId);

        $operation = trim((string) ($context['operation'] ?? 'ai_request'));
        $allowed = $this->operationAllowed($operation, $policy, $rules);

        return [
            'allowed' => $allowed,
            'policy' => $policy,
            'rules' => $rules,
            'message' => $allowed
                ? 'The AI request is permitted under the resolved policy.'
                : $this->restrictionMessage($policy, $operation),
            'scope_type' => trim((string) ($context['scope_type'] ?? '')),
            'scope_id' => isset($context['scope_id']) && $context['scope_id'] !== ''
                ? (int) $context['scope_id']
                : null,
            'policy_id' => $policyId,
        ];
    }

    /**
     * Every active policy this organisation resolves, with its assignments.
     *
     * @return array<int, object>
     */
    public function activePoliciesForInstitute(int|string|null $subInstituteId): array
    {
        if (! Schema::hasTable('ai_policies')) {
            return [];
        }

        return DB::table('ai_policies as p')
            ->leftJoin('ai_policy_assignments as a', 'a.policy_id', '=', 'p.id')
            ->where('p.status', 1)
            ->where(function ($query) use ($subInstituteId) {
                $query->where('p.sub_institute_id', $subInstituteId)
                    ->orWhereNull('p.sub_institute_id');
            })
            ->select([
                'p.id', 'p.name', 'p.description', 'p.policy_type', 'p.status',
                'p.require_disclosure', 'p.require_acknowledgement',
                'p.ai_detection_required', 'p.plagiarism_check_required',
                'p.detection_provider', 'p.detection_threshold',
                'a.scope_type', 'a.scope_id',
            ])
            ->get()
            ->all();
    }

    /**
     * The assignments that could govern this request, narrowest first.
     *
     * A candidate is only considered when the context actually names that scope, so a
     * request that says nothing about a job role cannot accidentally pick up a job
     * role's policy.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function assignmentsForScope(int|string|null $subInstituteId, array $context): array
    {
        if (! Schema::hasTable('ai_policy_assignments')) {
            return [];
        }

        // Narrowest first. `global` is last so it is the fallback rather than the
        // answer, which is the whole reason the other six exist.
        $order = array_reverse(array_column(self::SCOPE_TYPES, 'value'));

        $candidates = [];

        foreach ($order as $scopeType) {
            if ($scopeType === 'global') {
                $candidates[] = ['global', null];

                continue;
            }

            // Two spellings, because callers arrive with both: a context that names
            // several scopes at once passes `module_id`, `department_id` and so on,
            // while one asking about a single named scope passes `scope_type` plus
            // `scope_id`.
            //
            // `$context['scope_type']` is coalesced rather than read directly. Most
            // callers supply neither key, and reading a missing one raised an
            // "Undefined array key" warning on every such call — harmless to the
            // result, which is why it survived, and noise in the log of every request
            // that ever resolves a policy.
            $id = $context[$scopeType . '_id']
                ?? ((($context['scope_type'] ?? null) === $scopeType) ? ($context['scope_id'] ?? null) : null);

            if ($id !== null && $id !== '') {
                $candidates[] = [$scopeType, (int) $id];
            }
        }

        foreach ($candidates as [$scopeType, $scopeId]) {
            $query = DB::table('ai_policy_assignments as a')
                ->join('ai_policies as p', 'p.id', '=', 'a.policy_id')
                ->where('a.scope_type', $scopeType)
                ->where('a.status', 1)
                ->where('p.status', 1)
                ->where(function ($inner) use ($subInstituteId) {
                    $inner->where('a.sub_institute_id', $subInstituteId)
                        ->orWhereNull('a.sub_institute_id');
                });

            $scopeId === null
                ? $query->whereNull('a.scope_id')
                : $query->where('a.scope_id', $scopeId);

            $row = $query
                // An organisation's own assignment beats the platform default.
                ->orderByRaw('a.sub_institute_id IS NULL ASC')
                ->orderByDesc('a.id')
                ->select(['a.policy_id', 'a.scope_type', 'a.scope_id'])
                ->first();

            if ($row !== null) {
                return [(array) $row];
            }
        }

        return [];
    }

    private function policyRow(int $policyId): ?object
    {
        return Schema::hasTable('ai_policies')
            ? DB::table('ai_policies')->where('id', $policyId)->first()
            : null;
    }

    /** @return array<string, bool> */
    private function rulesForPolicy(int $policyId): array
    {
        if (! Schema::hasTable('ai_policy_rules')) {
            return [];
        }

        $rules = [];

        foreach (DB::table('ai_policy_rules')->where('policy_id', $policyId)->get() as $rule) {
            $rules[(string) $rule->rule_key] = (bool) $rule->rule_value;
        }

        return $rules;
    }

    /**
     * Whether a named operation is permitted.
     *
     * An AI-Free policy refuses everything; otherwise the operation's own rule decides,
     * and an operation with no rule is permitted — a policy cannot forbid something it
     * has never heard of, and pretending it can would block features silently as they
     * are added.
     *
     * @param  array<string, bool>  $rules
     */
    private function operationAllowed(string $operation, ?object $policy, array $rules): bool
    {
        if ($policy === null) {
            // No policy assigned is not the same as "forbidden". An organisation that
            // has written no policy has not banned anything.
            return true;
        }

        if ((string) $policy->policy_type === 'ai_free') {
            return false;
        }

        $key = str_starts_with($operation, 'use_ai_for_') ? $operation : 'use_ai_for_' . $operation;

        return ! array_key_exists($key, $rules) || $rules[$key];
    }

    private function restrictionMessage(?object $policy, string $operation): string
    {
        if ($policy === null) {
            return 'This AI request is not permitted.';
        }

        return sprintf(
            '"%s" does not permit %s.',
            (string) $policy->name,
            str_replace('_', ' ', preg_replace('/^use_ai_for_/', '', $operation) ?? $operation)
        );
    }
}
