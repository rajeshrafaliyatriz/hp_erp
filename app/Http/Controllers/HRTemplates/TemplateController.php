<?php

namespace App\Http\Controllers\HRTemplates;

use App\Http\Controllers\Controller;
use App\Models\hr\Template;
use App\Models\hr\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class TemplateController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'nullable|in:draft,published,archived'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $template = Template::create([
            'name' => $request->name,
            'content' => $request->content,
            'status' => $request->status ?? 'draft',
            'version' => 1,
            'sub_institute_id' => $request->sub_institute_id ?? null,
            'created_by' => auth()->id(),
        ]);

        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'version' => 1,
            'sub_institute_id' => $template->sub_institute_id,
            'created_by' => $template->created_by,
        ]);

        return response()->json($template);
    }

    public function index(Request $request)
    {
        $query = Template::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('sub_institute_id')) {
            $query->where('sub_institute_id', $request->sub_institute_id);
        }

        $templates = $query->get();

        return response()->json($templates);
    }

    public function show($id)
    {
        $template = Template::findOrFail($id);

        return response()->json($template);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'nullable|in:draft,published,archived'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $template = Template::findOrFail($id);
        // Save old version
        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'version' => $template->version,
            'sub_institute_id' => $template->sub_institute_id,
            'created_by' => $template->created_by,
        ]);

        // Update
        $template->update([
            'name' => $request->name,
            'content' => $request->content,
            'status' => $request->status ?? $template->status,
            'version' => $template->version + 1,
            'updated_by' => auth()->id(),
        ]);

        return response()->json($template);
    }

    public function destroy($id)
    {
        $template = Template::findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);
    }

    public function versions($id)
    {
        $template = Template::findOrFail($id);
        $versions = $template->versions()->orderBy('version', 'desc')->get();

        return response()->json($versions);
    }

    public function restore(Request $request, $id, $version)
    {
        $template = Template::findOrFail($id);
        $templateVersion = TemplateVersion::where('template_id', $id)
            ->where('version', $version)
            ->firstOrFail();

        // Save current version before restoring
        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'version' => $template->version,
            'sub_institute_id' => $template->sub_institute_id,
            'created_by' => auth()->id(),
        ]);

        // Restore
        $template->update([
            'content' => $templateVersion->content,
            'version' => $template->version + 1,
            'updated_by' => auth()->id(),
        ]);

        return response()->json($template);
    }
}