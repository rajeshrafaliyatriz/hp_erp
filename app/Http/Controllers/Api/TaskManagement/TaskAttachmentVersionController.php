<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Versioned attachments for a task.
 *
 * Each upload becomes a new numbered version; the latest is mirrored onto the
 * legacy task.task_attachment columns so the old screens keep showing the
 * current file. Restore copies an old version forward as a new one rather than
 * rewriting history.
 */
class TaskAttachmentVersionController extends Controller
{
    use ResolvesTaskContext;

    private const DISK = 'local';
    private const DIR = 'task-attachments';

    /**
     * A task attachment is a document or an image. Anything executable is not.
     *
     * Kept identical to DepartmentSopController and TaskDocumentController so
     * there is one answer in this codebase to "what may be uploaded", plus the
     * two image types a task attachment legitimately is (the assign form's own
     * picker already accepts jpg/png).
     */
    private const ALLOWED_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png';
    private const MAX_KILOBYTES = 20480; // 20 MB

    public function index(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('task_management_attachment_versions as v')
            ->leftJoin('tbluser as u', 'u.id', '=', 'v.uploaded_by')
            ->where('v.task_id', $id)
            ->where('v.sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('v.version')
            ->selectRaw("v.version, v.file_name, v.file_size, v.file_type, v.restored_from, v.created_at,
                TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as uploaded_by_name")
            ->get()
            ->map(fn ($row) => [
                'version' => (int) $row->version,
                'file_name' => (string) $row->file_name,
                'file_size' => $row->file_size !== null ? (int) $row->file_size : null,
                'file_type' => $row->file_type,
                'uploaded_by' => $row->uploaded_by_name ?: null,
                'restored_from' => $row->restored_from !== null ? (int) $row->restored_from : null,
                'created_at' => $row->created_at,
            ]);

        return $this->ok('Attachment versions retrieved successfully.', ['versions' => $rows->all()]);
    }

    public function store(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        /*
         * EXTENSION AND MIME, NOT JUST SIZE.
         *
         * This validated `required|file|max:20480` only — so it accepted a
         * `.php`, a `.exe`, anything, as long as it was under 20 MB. The file
         * lands on a disk this app serves, which makes an unrestricted upload
         * endpoint the shortest route to running someone else's code.
         *
         * The list is the same one DepartmentSopController and
         * TaskDocumentController use; a task attachment is a document or an
         * image, and nothing else.
         */
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:' . self::ALLOWED_EXTENSIONS . '|max:' . self::MAX_KILOBYTES,
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $task = DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        $file = $request->file('file');
        $version = (int) DB::table('task_management_attachment_versions')
            ->where('task_id', $id)
            ->max('version') + 1;

        $path = $file->storeAs(
            self::DIR . "/{$id}",
            "v{$version}_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()),
            self::DISK
        );

        DB::table('task_management_attachment_versions')->insert([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $id,
            'version' => $version,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'file_type' => $file->getClientMimeType(),
            'uploaded_by' => $context['user_id'],
            'created_at' => now(),
        ]);

        $this->mirrorLatest($id, $file->getClientOriginalName(), (string) $file->getSize(), $file->getClientMimeType());

        return $this->ok('Attachment uploaded.', ['version' => $version], 201);
    }

    public function download(Request $request, int $id, int $version)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = $this->findVersion($context, $id, $version);
        if (!$row) {
            return $this->fail('Attachment version not found.', 404);
        }

        if (!Storage::disk(self::DISK)->exists($row->file_path)) {
            return $this->fail('The stored file is missing from disk.', 410);
        }

        return Storage::disk(self::DISK)->download($row->file_path, $row->file_name);
    }

    public function restore(Request $request, int $id, int $version)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $source = $this->findVersion($context, $id, $version);
        if (!$source) {
            return $this->fail('Attachment version not found.', 404);
        }

        $next = (int) DB::table('task_management_attachment_versions')
            ->where('task_id', $id)
            ->max('version') + 1;

        DB::table('task_management_attachment_versions')->insert([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $id,
            'version' => $next,
            'file_name' => $source->file_name,
            'file_path' => $source->file_path,
            'file_size' => $source->file_size,
            'file_type' => $source->file_type,
            'uploaded_by' => $context['user_id'],
            'restored_from' => $version,
            'created_at' => now(),
        ]);

        $this->mirrorLatest($id, $source->file_name, (string) $source->file_size, (string) $source->file_type);

        return $this->ok("Version {$version} restored as version {$next}.", ['version' => $next], 201);
    }

    private function findVersion(array $context, int $taskId, int $version): ?object
    {
        return DB::table('task_management_attachment_versions')
            ->where('task_id', $taskId)
            ->where('version', $version)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();
    }

    /** The legacy columns always describe the newest version. */
    private function mirrorLatest(int $taskId, string $name, ?string $size, ?string $type): void
    {
        DB::table('task')->where('id', $taskId)->update([
            'task_attachment' => $name,
            'file_size' => $size,
            'file_type' => $type,
            'updated_at' => now(),
        ]);
    }
}
