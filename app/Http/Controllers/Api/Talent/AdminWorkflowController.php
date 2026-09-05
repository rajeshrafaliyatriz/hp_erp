<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminWorkflowController extends Controller
{
    use ResolvesTalentContext;

    /**
     * GET /api/talent/admin/workflows
     * Returns a paginated list of workflows for the administration center.
     */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $module = $this->activeTalentFilter($request->input('module'));
        $search = $this->activeTalentFilter($request->input('search'));
        $paging = $this->talentPaging($request, 10);
        
        $query = DB::table('talent_workflows as w')
            ->where('w.sub_institute_id', $sid)
            ->whereNull('w.deleted_at')
            ->when($module, fn ($q) => $q->where('w.module', $module))
            ->when($search, fn ($q) => $q->where(function($sq) use ($search) {
                $sq->where('w.name', 'like', "%{$search}%")
                   ->orWhere('w.description', 'like', "%{$search}%");
            }));

        $total = $query->count();
        $workflows = $query->orderByDesc('w.updated_at')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get([
                'w.id', 'w.name', 'w.module', 'w.status', 'w.version', 
                'w.description', 'w.updated_at'
            ]);

        // Eager load the creators/updaters using the directory helper
        $userIds = DB::table('talent_workflows')->whereIn('id', $workflows->pluck('id'))->pluck('updated_by')->toArray();
        $directory = $this->talentEmployeeDirectory($sid, $userIds);

        $result = $workflows->map(function ($w) use ($directory) {
            $updatedBy = DB::table('talent_workflows')->where('id', $w->id)->value('updated_by');
            return [
                'id' => 'wf-' . $w->id,
                'name' => $w->name,
                'module' => $w->module,
                'status' => $w->status,
                'version' => $w->version,
                'description' => $w->description,
                'lastUpdated' => $this->talentDateLabel($w->updated_at),
                'updatedBy' => $updatedBy ? ($directory[$updatedBy]['name'] ?? 'System') : 'System'
            ];
        });

        /*
         * Real counts for the headline tiles.
         *
         * The Administration screen rendered five hardcoded numbers - "Active
         * Workflows: 28", "Templates: 56", "Audit Events: 1,248" - directly above a
         * live, paginated table (audit F-28). The table told the truth and the
         * tiles above it did not, which is worse than having no tiles: the real
         * data lent the invented data credibility.
         *
         * Four of the five have a real referent and are computed here, tenant
         * scoped. The fifth, "Integrations", has no table, no route and no
         * concept anywhere in the codebase, so it is not returned and the tile is
         * removed rather than invented.
         */
        $summary = [
            'active_workflows' => (int) DB::table('talent_workflows')
                ->where('sub_institute_id', $sid)
                ->where('status', 'Active')
                ->whereNull('deleted_at')
                ->count(),
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
            'data' => $result,
            'summary' => $summary,
            'pagination' => [
                'total' => $total,
                'per_page' => $paging['per_page'],
                'current_page' => $paging['page'],
                'last_page' => max(1, ceil($total / $paging['per_page']))
            ]
        ]);
    }

    /**
     * GET /api/talent/admin/workflows/{id}
     * Returns the full workflow details including stages and approvers.
     */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $numericId = (int) str_replace('wf-', '', $id);

        $workflow = DB::table('talent_workflows')
            ->where('id', $numericId)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$workflow) {
            return $this->talentError('Workflow not found', 404);
        }

        $stages = DB::table('talent_workflow_stages')
            ->where('workflow_id', $numericId)
            ->orderBy('step')
            ->get(['step', 'label']);

        $approvers = DB::table('talent_workflow_approvers')
            ->where('workflow_id', $numericId)
            ->orderBy('sort_order')
            ->get(['id', 'role', 'title', 'approval_type', 'escalation']);

        $userIds = array_filter([$workflow->created_by, $workflow->updated_by]);
        $directory = $this->talentEmployeeDirectory($sid, $userIds);

        return $this->talentResponse([
            'id' => 'wf-' . $workflow->id,
            'name' => $workflow->name,
            'module' => $workflow->module,
            'status' => $workflow->status,
            'version' => $workflow->version,
            'description' => $workflow->description,
            'createdBy' => $workflow->created_by ? ($directory[$workflow->created_by]['name'] ?? 'System') : 'System',
            'lastUpdated' => $this->talentDateLabel($workflow->updated_at),
            'updatedBy' => $workflow->updated_by ? ($directory[$workflow->updated_by]['name'] ?? 'System') : 'System',
            'stages' => $stages->map(fn($s) => [
                'step' => $s->step,
                'label' => $s->label
            ]),
            'approvers' => $approvers->map(fn($a) => [
                'id' => 'a' . $a->id,
                'role' => $a->role,
                'title' => $a->title,
                'initials' => $this->talentInitialsOf($a->role),
                'approvalType' => $a->approval_type,
                'escalation' => $a->escalation
            ])
        ]);
    }

    /**
     * GET /api/talent/admin/audit-logs
     *
     * The Administration screen's Audit Logs button had no destination. This is
     * it, and it needed no new storage: `g2g_event` is already the append-only
     * record of everything that happens, written in the same transaction as the
     * change it describes.
     *
     * ── WHY THIS READS THE EVENT STORE AND NOT A NEW TABLE ─────────────────
     *
     * An audit log assembled from a second source can disagree with what
     * actually happened. The event store cannot: nothing writes a talent state
     * change without recording one, and it has no UPDATE or DELETE path - a
     * mistake is corrected by a compensating event, so history is never rewritten.
     *
     * Tenant-scoped, newest first, and the payload is returned decoded so the
     * client does not have to know it is stored as a JSON string in a text
     * column (live is MariaDB 10.1 and has no json type).
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
}
