<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reusable task templates: a saved set of task fields the create form can
 * pre-fill from. The payload is stored as submitted - the create screen owns
 * which fields it uses.
 */
class TaskTemplateController extends Controller
{
    use ResolvesTaskContext;

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('task_management_templates as tpl')
            ->leftJoin('tbluser as creator', 'creator.id', '=', 'tpl.created_by')
            ->where('tpl.sub_institute_id', $context['sub_institute_id'])
            ->orderBy('tpl.name')
            ->selectRaw("tpl.id, tpl.name, tpl.payload, tpl.created_at,
                TRIM(CONCAT_WS(' ', creator.first_name, creator.middle_name, creator.last_name)) as created_by_name")
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'payload' => json_decode((string) $row->payload, true) ?: [],
                'created_by' => $row->created_by_name ?: null,
                'created_at' => $row->created_at,
            ]);

        return $this->ok('Templates retrieved successfully.', ['templates' => $rows->all()]);
    }

    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'payload' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $id = DB::table('task_management_templates')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'name' => $request->input('name'),
            'payload' => json_encode($request->input('payload')),
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok('Template saved.', ['id' => (string) $id], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $deleted = DB::table('task_management_templates')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->delete();

        return $deleted
            ? $this->ok('Template deleted.')
            : $this->fail('Template not found.', 404);
    }
}
