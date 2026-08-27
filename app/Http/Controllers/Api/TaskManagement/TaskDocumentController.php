<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Reference documents on a task — what an employee needs to actually do it.
 *
 * ── HOW THIS DIFFERS FROM THE TASK ATTACHMENT ───────────────────────────────
 *
 * `task.task_attachment` is THE file for the task, versioned by
 * TaskAttachmentVersionController. These are the materials that go WITH the
 * task: a checklist, a policy extract, a form to fill in. One is the work; the
 * other is what you need to do the work.
 *
 * ── WHO CAN DO WHAT ─────────────────────────────────────────────────────────
 *
 * Reading and downloading follow TASK OWNERSHIP — assignee, assigner, or
 * admin/hr — exactly as TaskInstructionController scopes the procedure. The
 * person doing the work needs the material, and they are not an administrator.
 *
 * Uploading and deleting are admin/hr only. An employee who could attach a
 * document to their own task could put anything in front of whoever reviews it.
 *
 * ── UPLOAD HARDENING, COPIED FROM DepartmentSopController ───────────────────
 *
 * It is the only upload site in this codebase that gets all four right, and
 * each one matters:
 *
 *   extension AND mime validated   the sibling task endpoint validates size
 *                                  only, so it currently accepts an executable
 *   tenant-scoped on disk          the legacy pattern puts every tenant's files
 *                                  in one shared folder
 *   generated storage name         the legacy date('YmdHis') convention makes
 *                                  two uploads in the same second collide
 *   path read from the ROW         a client-supplied path is how a download
 *                                  endpoint becomes a way to read the server
 */
class TaskDocumentController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * What a reference document legitimately is. Anything executable is not,
     * and the list is deliberately the same one SOPs use.
     */
    private const ALLOWED_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png';
    private const MAX_KILOBYTES = 20480; // 20 MB

    /** Free text would fragment into 'Policy', 'policy', 'Policies' within a week. */
    public const DOCUMENT_TYPES = [
        'reference' => 'Reference material',
        'checklist' => 'Checklist',
        'policy'    => 'Policy or standard',
        'form'      => 'Form or template',
        'evidence'  => 'Evidence required',
        'other'     => 'Other',
    ];

    /** GET /task-management/tasks/{taskId}/documents */
    public function index(Request $request, int $taskId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $access = $this->access($request, $context, $taskId);
        if (!is_array($access)) {
            return $access;
        }

        $rows = DB::table('task_documents')
            ->where('sub_institute_id', $access['tenant'])
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'title', 'document_type', 'file_name', 'mime_type',
                   'file_size', 'uploaded_by', 'uploaded_by_name', 'created_at']);

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => (array) $r + [
                'document_type_label' => self::DOCUMENT_TYPES[$r->document_type] ?? null,
            ])->values(),
            'types'  => self::DOCUMENT_TYPES,
            // Uploading is a different permission from reading, and the screen
            // needs to know which buttons to render.
            'can_upload' => $access['elevated'],
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'No documents have been attached to this task.',
        ]);
    }

    /** POST /task-management/tasks/{taskId}/documents */
    public function store(Request $request, int $taskId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $access = $this->access($request, $context, $taskId);
        if (!is_array($access)) {
            return $access;
        }

        if (!$access['elevated']) {
            return response()->json([
                'status'  => 0,
                'message' => 'Only an administrator or HR can attach documents to a task.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title'         => 'nullable|string|max:191',
            'document_type' => 'nullable|string|in:' . implode(',', array_keys(self::DOCUMENT_TYPES)),
            'document'      => 'required|file|mimes:' . self::ALLOWED_EXTENSIONS . '|max:' . self::MAX_KILOBYTES,
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('document');
        if (!$file || !$file->isValid()) {
            return response()->json(['status' => 0, 'message' => 'The upload did not arrive intact. Try again.'], 422);
        }

        // Tenant-scoped, and the stored name is generated by Laravel — the
        // original is kept only as a label.
        $path = $file->store('task_documents/' . $access['tenant'], 'public');

        $id = DB::table('task_documents')->insertGetId([
            'sub_institute_id' => $access['tenant'],
            'task_id'          => $taskId,
            // Falls back to the filename so a document is never nameless in a
            // list, which is where an untitled row becomes unusable.
            'title'            => trim((string) $request->input('title')) ?: $file->getClientOriginalName(),
            'document_type'    => $request->input('document_type') ?: 'reference',
            'file_name'        => $file->getClientOriginalName(),
            'file_path'        => $path,
            'mime_type'        => $file->getClientMimeType(),
            'file_size'        => $file->getSize(),
            'uploaded_by'      => $access['user'],
            'uploaded_by_name' => $this->nameOf($access['user']),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json([
            'status'  => 1,
            'data'    => ['id' => $id],
            'message' => 'Document attached. Everyone on this task can now open it.',
        ], 201);
    }

    /** GET /task-management/tasks/{taskId}/documents/{id}/download */
    public function download(Request $request, int $taskId, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $access = $this->access($request, $context, $taskId);
        if (!is_array($access)) {
            return $access;
        }

        $row = DB::table('task_documents')
            ->where('id', $id)
            ->where('task_id', $taskId)
            ->where('sub_institute_id', $access['tenant'])
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That document does not exist on this task.'], 404);
        }

        // The path comes from the ROW. A path taken from the request is how a
        // download endpoint turns into a way to read arbitrary server files.
        if (!$row->file_path || !Storage::disk('public')->exists($row->file_path)) {
            return response()->json([
                'status' => 0,
                'message' => 'The file for this document is missing from storage. It may have been removed on the server.',
            ], 404);
        }

        return Storage::disk('public')->download($row->file_path, $row->file_name ?: basename($row->file_path));
    }

    /** DELETE /task-management/tasks/{taskId}/documents/{id} */
    public function destroy(Request $request, int $taskId, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $access = $this->access($request, $context, $taskId);
        if (!is_array($access)) {
            return $access;
        }

        if (!$access['elevated']) {
            return response()->json([
                'status'  => 0,
                'message' => 'Only an administrator or HR can remove a document from a task.',
            ], 403);
        }

        $row = DB::table('task_documents')
            ->where('id', $id)->where('task_id', $taskId)
            ->where('sub_institute_id', $access['tenant'])->whereNull('deleted_at')->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That document does not exist on this task.'], 404);
        }

        /*
         * Soft delete, and the FILE IS LEFT ON DISK.
         *
         * A removed document can be restored by clearing deleted_at; a deleted
         * file cannot. Storage is cheap and an accidental removal of the one
         * document somebody needed is not.
         */
        DB::table('task_documents')->where('id', $id)->update([
            'deleted_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Document removed from this task.']);
    }

    /* ------------------------------------------------------------------ *
     * Access
     * ------------------------------------------------------------------ */

    /**
     * Ownership, resolved once.
     *
     * Same rule as TaskInstructionController: you reach a task's material if it
     * is assigned to you, you assigned it, or you hold an elevated role.
     * Without this any authenticated employee could walk task ids and read
     * their organisation's documents.
     *
     * @return array{tenant:int, user:int, elevated:bool}|\Illuminate\Http\JsonResponse
     */
    private function access(Request $request, array $context, int $taskId)
    {
        $tenant = (int) $context['sub_institute_id'];
        $user   = $context['user_id'] !== null ? (int) $context['user_id'] : 0;

        $task = DB::table('task')
            ->where('id', $taskId)->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->first(['id', 'task_allocated_to', 'task_allocated']);

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'That task does not exist in your organisation.'], 404);
        }

        $elevated = $this->isElevated($user);
        $isMine   = (int) $task->task_allocated_to === $user || (int) $task->task_allocated === $user;

        if (!$isMine && !$elevated) {
            return response()->json([
                'status'  => 0,
                'message' => 'This task is not assigned to you, so its documents are not yours to read.',
            ], 403);
        }

        return ['tenant' => $tenant, 'user' => $user, 'elevated' => $elevated];
    }

    /** Matched on role_key, the stable machine name — never a display name. */
    private function isElevated(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $userId)->value('p.role_key');

        return in_array((string) $roleKey, ['administrator', 'hr_manager', 'hr_executive'], true);
    }

    /** Denormalised at upload time, because a document outlives an account. */
    private function nameOf(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = DB::table('tbluser')->where('id', $userId)->first(['first_name', 'last_name', 'user_name']);
        if (!$user) {
            return null;
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->user_name ?? null);
    }
}
