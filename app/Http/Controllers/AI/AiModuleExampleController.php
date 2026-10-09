<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Examples\GuardrailCheck;
use App\Domain\AI\Examples\ModuleExampleBuilder;
use App\Domain\AI\Examples\PageExampleBuilder;
use App\Domain\AI\Support\AiAuditLogger;
use App\Services\Ai\AiPolicyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The worked "Example" panel at the top of each tab of a module's AI Stack.
 *
 * `GET  /modules/{module}/examples`                  one live example per tab
 * `GET  /modules/{module}/page-examples`            one live example per PAGE of the module
 * `POST /modules/{module}/examples/guardrail-check`  run the module's example write action
 *                                                    through the rights its route really enforces,
 *                                                    for the caller, and record the result
 *
 * The tenant is the caller's token's; the module key is the only URL input and cannot widen
 * access. Everything returned is computed at request time (see `ModuleExampleBuilder`).
 */
class AiModuleExampleController extends AiController
{
    public function __construct(
        private readonly ModuleExampleBuilder $builder,
        private readonly GuardrailCheck $guardrails,
        private readonly ModuleGrounding $grounding,
        private readonly AiAuditLogger $audit,
        private readonly AiPolicyResolver $policies,
        private readonly PageExampleBuilder $pageExamples,
    ) {
    }

    public function index(Request $request, string $module)
    {
        try {
            $scope = $this->scope($request);
            $examples = $this->builder->build($request, $scope, $module, $request->boolean('rollup'));

            if ($examples === null) {
                return $this->failure("{$module} is not a registered AI module.", 404);
            }

            return $this->success('Module examples resolved.', $examples);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One example per page of the module, from each page's own data, for the caller's organisation. */
    public function pages(Request $request, string $module)
    {
        try {
            $examples = $this->pageExamples->forModule($this->scope($request), $module);

            if ($examples === null) {
                return $this->failure("{$module} is not a registered AI module.", 404);
            }

            return $this->success('Page examples resolved.', $examples);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function guardrailCheck(Request $request, string $module)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;
            $row = $this->grounding->module($module, $institute);

            if ($row === null) {
                return $this->failure("{$module} is not a registered AI module, so nothing was checked or recorded.", 422);
            }

            $action = $this->builder->definitionFor($module)['action'] ?? null;

            if ($action === null) {
                return $this->failure("{$row->label} has no example write action to check.", 422);
            }

            $chain = $this->guardrails->evaluate($request, $scope, $action);

            $ownId = DB::table('ai_modules')->where('module_key', $module)
                ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
                ->orderByRaw('sub_institute_id IS NULL ASC')->value('id');
            $policy = $this->policies->resolve($institute, ['module_id' => $ownId, 'operation' => 'ai_request']);

            $allowed = $chain['allowed'] && $policy['allowed'];
            $blockedBy = [];

            foreach ($chain['gates'] as $gate) {
                if (($gate['passed'] ?? false) !== true) {
                    $blockedBy[] = $gate['gate'] . ' - ' . $gate['message'];
                }
            }
            if (! $chain['found']) {
                $blockedBy[] = 'route ' . $chain['route'] . ' is not registered';
            }
            if (! $policy['allowed']) {
                $blockedBy[] = 'AI policy - ' . $policy['message'];
            }

            $summary = $allowed
                ? sprintf('Allowed: you may %s in %s.', strtolower((string) $action['label']), $row->label)
                : sprintf('Blocked: you may not %s in %s (%s).', strtolower((string) $action['label']), $row->label, implode('; ', $blockedBy));

            $result = [
                'allowed' => $allowed,
                'route' => $chain['route'],
                'gates' => array_map(fn (array $g) => [
                    'gate' => $g['gate'],
                    'passed' => $g['passed'],
                    'status' => $g['status'],
                    'message' => $g['message'],
                    'meaning' => $g['meaning'],
                ], $chain['gates']),
                'gate_free' => $chain['found'] && $chain['gates'] === [],
                'gate_free_note' => $action['self_service'] ?? null,
                'policy' => [
                    'allowed' => $policy['allowed'],
                    'policy' => $policy['policy'] === null ? null : (string) $policy['policy']->name,
                    'message' => $policy['message'],
                ],
            ];

            $auditId = $this->audit->record("module.{$module}.guardrail_check", $scope, [
                'actor_label' => $this->actorLabel($scope->userId),
                'subject_entity_key' => 'guardrail_check',
                'outcome' => $allowed ? 'success' : 'rejected',
                'message' => $summary,
                'payload' => [
                    'module' => $module,
                    'operation' => 'guardrail_check',
                    'operation_label' => 'Guardrail check: ' . $action['label'],
                    'capability' => 'conversational',
                    'status' => $allowed ? 'completed' : 'denied',
                    'reference' => $chain['route'],
                    'result' => $result,
                ],
            ]);

            return $this->success($summary, [
                'allowed' => $allowed,
                'summary' => $summary,
                'action' => ['key' => $action['key'], 'label' => $action['label']],
                'result' => $result,
                'audit_id' => $auditId,
                'recorded' => $auditId !== null,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function actorLabel(int|string|null $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $row = DB::table('tbluser')->where('id', $userId)->first(['first_name', 'last_name']);

        if ($row === null) {
            return null;
        }

        $name = trim(((string) ($row->first_name ?? '')) . ' ' . ((string) ($row->last_name ?? '')));

        return $name === '' ? null : $name;
    }
}
