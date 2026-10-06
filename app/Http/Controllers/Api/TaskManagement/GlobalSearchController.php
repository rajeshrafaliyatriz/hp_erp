<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * One search box over the whole task module: tasks, projects and people,
 * grouped so the client can render sectioned results.
 */
class GlobalSearchController extends Controller
{
    use ResolvesTaskContext;


    /**
     * Escape LIKE wildcards in a user-supplied search term.
     *
     * Without this, searching for "%" matches every row (the audit measured
     * 417 of 417) and "_" matches any single character - the term stops being
     * a search and becomes a table dump.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:191',
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $term = '%' . $this->escapeLike(trim((string) $request->input('q'))) . '%';
        $limit = (int) $request->input('limit', 10);
        $sid = $context['sub_institute_id'];

        $tasks = DB::table('task')
            ->where('sub_institute_id', $sid)
            ->where('SYEAR', $context['syear'])
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('task_title', 'like', $term)->orWhere('task_description', 'like', $term))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'task_title', 'status', 'task_date'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->task_title,
                'status' => $row->status,
                'due_date' => $row->task_date,
            ]);

        $projects = DB::table('task_management_projects')
            ->where('sub_institute_id', $sid)
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'status'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'status' => $row->status,
            ]);

        $users = DB::table('tbluser')
            ->where('sub_institute_id', $sid)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereRaw("TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) like ?", [$term])
            ->orderBy('first_name')
            ->limit($limit)
            ->selectRaw("id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name")
            ->get()
            ->map(fn ($row) => ['id' => (string) $row->id, 'name' => (string) $row->name]);

        return $this->ok('Search results.', [
            'tasks' => $tasks->all(),
            'projects' => $projects->all(),
            'users' => $users->all(),
        ]);
    }
}
