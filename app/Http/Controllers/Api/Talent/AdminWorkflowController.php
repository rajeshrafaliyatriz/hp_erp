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

        return collect([
            'status' => 1,
            'message' => 'Success',
            'data' => $result,
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
}
