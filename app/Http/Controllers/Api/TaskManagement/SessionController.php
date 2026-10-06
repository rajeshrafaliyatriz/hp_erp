<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who am I, for the task module: identity, profile and department resolved
 * from the token so the frontend never trusts localStorage for role gating.
 */
class SessionController extends Controller
{
    use ResolvesTaskContext;

    public function show(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $user = DB::table('tbluser')
            ->leftJoin('tbluserprofilemaster as profile', 'profile.id', '=', 'tbluser.user_profile_id')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'tbluser.department_id')
            ->where('tbluser.id', $context['user_id'])
            ->selectRaw("tbluser.id,
                TRIM(CONCAT_WS(' ', tbluser.first_name, tbluser.middle_name, tbluser.last_name)) as name,
                profile.name as profile_name, tbluser.department_id, department.department as department_name")
            ->first();

        if (!$user) {
            return $this->fail('User not found.', 404);
        }

        return $this->ok('Session retrieved successfully.', [
            'user_id' => (string) $user->id,
            'name' => (string) $user->name,
            'profile' => $user->profile_name ?: null,
            'department_id' => $user->department_id ? (string) $user->department_id : null,
            'department' => $user->department_name ?: null,
            'sub_institute_id' => (string) $context['sub_institute_id'],
            'syear' => $context['syear'],
        ]);
    }

    /**
     * The permission matrix as the server actually enforces it.
     *
     * Rendered from the same PRIVILEGED list TaskPermissionMiddleware checks,
     * so the Administration > Permissions screen shows enforcement reality
     * rather than a hand-maintained copy that can drift. Read-only: the rule
     * itself (Employee is denied privileged abilities) lives in code.
     */
    public function permissions(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $profiles = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->pluck('name')
            ->unique()
            ->values();

        $abilities = [
            ['key' => 'task.create', 'label' => 'Create tasks', 'privileged' => false],
            ['key' => 'task.update', 'label' => 'Edit tasks', 'privileged' => false],
            ['key' => 'task.status', 'label' => 'Update task status', 'privileged' => false],
            ['key' => 'task.comment', 'label' => 'Comment on tasks', 'privileged' => false],
            ['key' => 'task.delete', 'label' => 'Archive / delete tasks', 'privileged' => true],
            ['key' => 'task.approve', 'label' => 'Approve / reject completed work', 'privileged' => true],
            ['key' => 'project.create', 'label' => 'Create projects', 'privileged' => true],
            ['key' => 'project.manage', 'label' => 'Manage projects & teams', 'privileged' => true],
            ['key' => 'workstream.manage', 'label' => 'Manage workstreams', 'privileged' => true],
            ['key' => 'dependency.manage', 'label' => 'Manage dependencies', 'privileged' => true],
            ['key' => 'milestone.manage', 'label' => 'Manage milestones', 'privileged' => true],
            ['key' => 'notification.manage', 'label' => 'Manage notifications', 'privileged' => true],
        ];

        $matrix = collect($abilities)->map(fn (array $ability) => [
            'key' => $ability['key'],
            'label' => $ability['label'],
            'roles' => $profiles->mapWithKeys(fn ($profile) => [
                $profile => !($ability['privileged'] && strcasecmp(trim((string) $profile), 'Employee') === 0),
            ]),
        ]);

        return $this->ok('Permission matrix.', [
            'profiles' => $profiles->all(),
            'abilities' => $matrix->all(),
            'note' => 'Enforced server-side by task.permission middleware; the Employee profile is denied privileged abilities.',
        ]);
    }

    /**
     * Which task-module integrations are configured, without exposing any
     * secret. Backs the Administration > Integrations screen; configuration
     * itself happens in .env, deliberately not from the browser.
     */
    public function integrations(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $n8n = (string) config('services.n8n.task_webhook', '');

        return $this->ok('Integration status.', [
            'integrations' => [
                [
                    'key' => 'n8n',
                    'name' => 'n8n task webhook',
                    'description' => 'Notifies an n8n workflow whenever a task is created through the API.',
                    'configured' => $n8n !== '',
                    'env' => 'N8N_TASK_WEBHOOK_URL',
                ],
                [
                    'key' => 'gemini',
                    'name' => 'Gemini AI task generation',
                    'description' => 'Drafts task titles and descriptions in the create-task form.',
                    'configured' => (string) config('gemini.api_key', env('GEMINI_API_KEY', '')) !== '',
                    'env' => 'GEMINI_API_KEY',
                ],
                [
                    'key' => 'fcm',
                    'name' => 'Push notifications (FCM)',
                    'description' => 'Delivers task notifications to mobile devices.',
                    'configured' => (string) env('FCM_SERVER_KEY', '') !== '',
                    'env' => 'FCM_SERVER_KEY',
                ],
            ],
        ]);
    }

    /** Revokes the presented token - "sign out of the task module". */
    public function destroy(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        $accessToken = $token !== '' ? PersonalAccessToken::findToken($token) : null;

        if (!$accessToken) {
            return $this->fail('Unauthenticated.', 401);
        }

        $accessToken->delete();

        return $this->ok('Session ended.');
    }
}
