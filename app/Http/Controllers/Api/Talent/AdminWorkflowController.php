<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Talent "Administration & Governance → Workflows" screen.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ROUND 5. REPOINTED FROM A DEAD, SEEDED CATALOGUE TO THE REAL ONE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This used to read `talent_workflows`/`talent_workflow_stages`/
 * `talent_workflow_approvers` — a table nothing ever wrote to beyond 8 rows a
 * seeder inserted with backdated timestamps (the project's own prior audit
 * already flagged this, F-64: "eight rows, no writer, and no approval path
 * consults it"). It genuinely queried a real, tenant-scoped table and
 * computed real summary tiles — the DATA behind it was simply disconnected
 * from anything the app actually enforces.
 *
 * The real thing is Platform Services' Workflow console: the 4 Talent-module
 * points declared in `config('platform_services.workflows')`
 * (`talent.recruitment.requisition`, `talent.recruitment.offer`,
 * `talent.offboarding.clearance`, `talent.mobility.transfer` — all enforced
 * as of this round), backed by real chains in `g2g_platform_workflows` and
 * real version history in `g2g_platform_workflow_versions`
 * (`WorkflowController`, same package). This controller now reads THAT.
 *
 * No destructive change: `talent_workflows` and its two sibling tables are
 * left exactly as they are — dead, harmless, and not worth an irreversible
 * schema drop for a screen repoint.
 *
 * `Workflow['status']` only has 'Active'/'Draft'/'Inactive' to work with (the
 * frontend type, unchanged by this round) — a point with no chain configured
 * at all reads 'Draft' rather than a fabricated 'Active', which is the same
 * "an empty/near-empty result is honest, not a defect" standard the rest of
 * this screen (and the summary tiles it sits above) already holds itself to.
 */
class AdminWorkflowController extends Controller
{
    use ResolvesTalentContext;

    /**
     * The 4 Talent-module points this screen shows, and the module label the
     * frontend's own (unchanged) filter dropdown already offers for each —
     * `Recruitment`/`Offboarding`/`Mobility`. No `Onboarding`/`Performance`
     * point exists in the registry yet, so those filter options legitimately
     * return nothing rather than something invented.
     */
    private const TALENT_FLOW_MODULES = [
        'talent.recruitment.requisition' => 'Recruitment',
        'talent.recruitment.offer' => 'Recruitment',
        'talent.offboarding.clearance' => 'Offboarding',
        'talent.mobility.transfer' => 'Mobility',
    ];

    /**
     * GET /api/talent/admin/workflows
     * Returns a paginated list of the real Talent-module Workflow points for
     * the administration center.
     */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $moduleFilter = $this->activeTalentFilter($request->input('module'));
        $search = $this->activeTalentFilter($request->input('search'));
        $paging = $this->talentPaging($request, 10);

        $rows = $this->buildRows($sid, $moduleFilter, $search);

        $total = count($rows);
        $paged = array_slice($rows, ($paging['page'] - 1) * $paging['per_page'], $paging['per_page']);

        $summary = [
            'active_workflows' => count(array_filter($rows, fn ($r) => $r['status'] === 'Active')),
            'templates' => (int) DB::table('talent_offer_templates')
                ->where('sub_institute_id', $sid)
                ->where('status', 1)
                ->count(),
            'user_roles' => (int) DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->count(),
            'audit_events_30d' => (int) DB::table('g2g_event')
                ->where('sub_institute_id', $sid)
                ->where('occurred_at', '>=', now()->subDays(30))
                ->count(),
        ];

        return collect([
            'status' => 1,
            'message' => 'Success',
            'data' => $paged,
            'summary' => $summary,
            'pagination' => [
                'total' => $total,
                'per_page' => $paging['per_page'],
                'current_page' => $paging['page'],
                'last_page' => max(1, (int) ceil($total / $paging['per_page'])),
            ],
        ]);
    }

    /**
     * GET /api/talent/admin/workflows/{id}
     * Returns the full workflow details including its real configured
     * stages and approvers, when a chain has actually been configured.
     */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $flowKey = preg_replace('/^wf-/', '', (string) $id);
        $module = self::TALENT_FLOW_MODULES[$flowKey] ?? null;
        // NOT config('platform_services.workflows.' . $flowKey) — $flowKey
        // itself contains dots ('talent.recruitment.offer'), so dot-notation
        // config() would parse it as nested path segments instead of the
        // literal array key it actually is.
        $entry = config('platform_services.workflows', [])[$flowKey] ?? null;

        if ($module === null || $entry === null) {
            return $this->talentError('Workflow not found', 404);
        }

        $chain = DB::table('g2g_platform_workflows')
            ->where('sub_institute_id', $sid)
            ->where('flow_key', $flowKey)
            ->orderByDesc('updated_at')
            ->first();

        $versionCount = DB::table('g2g_platform_workflow_versions')
            ->where('sub_institute_id', $sid)
            ->where('flow_key', $flowKey)
            ->count();

        $steps = $chain ? (json_decode((string) $chain->steps, true) ?: []) : [];
        $steps = is_array($steps) ? array_values($steps) : [];

        return $this->talentResponse([
            'id' => 'wf-' . $flowKey,
            'name' => $entry['label'] ?? $flowKey,
            'module' => $module,
            'status' => ($chain !== null && $chain->status === 'active') ? 'Active' : 'Draft',
            'version' => $versionCount > 0 ? ('v' . $versionCount) : '—',
            'description' => $entry['description'] ?? '',
            'createdBy' => $chain->created_by ?? 'Not configured',
            'lastUpdated' => $chain ? ($this->talentDateLabel($chain->updated_at) ?? '—') : 'Not configured',
            'updatedBy' => $chain->updated_by ?? 'Not configured',
            'stages' => collect($steps)->values()->map(fn ($s, $i) => [
                'step' => $i + 1,
                'label' => $s['name'] ?? ('Step ' . ($i + 1)),
            ])->all(),
            'approvers' => collect($steps)->values()->map(fn ($s, $i) => [
                'id' => 'a' . ($i + 1),
                'role' => $s['approver'] ?? ($s['approver_type'] ?? 'unspecified'),
                'title' => $s['name'] ?? ('Step ' . ($i + 1)),
                'initials' => $this->talentInitialsOf($s['approver'] ?? ($s['name'] ?? '?')),
                'approvalType' => !empty($s['require_comment']) ? 'Mandatory' : 'Optional',
                'escalation' => !empty($s['sla_hours'])
                    ? ($s['sla_hours'] . 'h (' . ($s['on_breach'] ?? 'none') . ')')
                    : 'No SLA',
            ])->all(),
        ]);
    }

    /**
     * GET /api/talent/admin/audit-logs
     *
     * Unchanged from before this round — still the real, append-only event
     * store, not a second source that could disagree with what happened.
     */
    public function auditLogs(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $type = $this->activeTalentFilter($request->input('type'));
        $entityType = $this->activeTalentFilter($request->input('entity_type'));
        $search = trim((string) $request->input('search', ''));

        $query = DB::table('g2g_event')
            ->where('sub_institute_id', $sid)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($entityType, fn ($q) => $q->where('entity_type', $entityType))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('type', 'like', "%{$search}%")
                      ->orWhere('entity_type', 'like', "%{$search}%");
                });
            });

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get(['id', 'type', 'entity_type', 'entity_id', 'actor_id', 'payload', 'occurred_at']);

        $directory = $this->talentEmployeeDirectory($sid, $rows->pluck('actor_id')->filter()->all());

        return $this->talentResponse(
            $rows->map(fn ($e) => [
                'id' => (int) $e->id,
                'type' => $e->type,
                'entity_type' => $e->entity_type,
                'entity_id' => $e->entity_id ? (int) $e->entity_id : null,
                // A null actor means SYSTEM, which is a real value, not "unknown".
                'actor' => $e->actor_id ? ($directory[$e->actor_id]['name'] ?? 'User ' . $e->actor_id) : 'System',
                'occurred_at' => $e->occurred_at,
                'payload' => $e->payload ? json_decode($e->payload, true) : null,
            ]),
            'Success',
            200,
            [
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                // Offered as filter options so the client never invents a list of
                // event types that this organisation has not actually produced.
                'types' => DB::table('g2g_event')->where('sub_institute_id', $sid)
                    ->distinct()->orderBy('type')->pluck('type'),
                'entity_types' => DB::table('g2g_event')->where('sub_institute_id', $sid)
                    ->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            ]
        );
    }

    /**
     * The real rows behind index() — one per declared Talent-module Workflow
     * point, joined with whatever real chain (if any) this tenant has
     * configured for it. Shared with index() so filtering/search/pagination
     * happen over the same real list `show()` would resolve any one row from.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(int $sid, ?string $moduleFilter, ?string $search): array
    {
        $flowKeys = array_keys(self::TALENT_FLOW_MODULES);

        $chainsByFlowKey = DB::table('g2g_platform_workflows')
            ->where('sub_institute_id', $sid)
            ->whereIn('flow_key', $flowKeys)
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('flow_key');

        $versionCounts = DB::table('g2g_platform_workflow_versions')
            ->where('sub_institute_id', $sid)
            ->whereIn('flow_key', $flowKeys)
            ->selectRaw('flow_key, COUNT(*) as versions')
            ->groupBy('flow_key')
            ->pluck('versions', 'flow_key');

        $registry = config('platform_services.workflows', []);
        $rows = [];

        foreach ($flowKeys as $flowKey) {
            $entry = $registry[$flowKey] ?? null;
            if ($entry === null) {
                continue;
            }

            $module = self::TALENT_FLOW_MODULES[$flowKey];
            if ($moduleFilter !== null && $moduleFilter !== $module) {
                continue;
            }

            $name = $entry['label'] ?? $flowKey;
            $description = $entry['description'] ?? '';
            if ($search !== null
                && stripos($name, $search) === false
                && stripos($description, $search) === false
            ) {
                continue;
            }

            $flowChains = $chainsByFlowKey->get($flowKey, collect());
            $active = $flowChains->firstWhere('status', 'active');
            $latest = $flowChains->first();
            $versionCount = (int) ($versionCounts[$flowKey] ?? 0);

            $rows[] = [
                'id' => 'wf-' . $flowKey,
                'name' => $name,
                'module' => $module,
                'status' => $active !== null ? 'Active' : 'Draft',
                'version' => $versionCount > 0 ? ('v' . $versionCount) : '—',
                'description' => $description,
                'lastUpdated' => $latest ? ($this->talentDateLabel($latest->updated_at) ?? '—') : 'Not configured',
                'updatedBy' => $latest->updated_by ?? 'Not configured',
            ];
        }

        return $rows;
    }
}
