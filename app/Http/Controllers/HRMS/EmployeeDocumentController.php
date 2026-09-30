<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\SubjectAuthority;

/**
 * PERSONNEL DOCUMENTS — yours, and (for HR) everybody's.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `tbluserController::addUserDocument` was the only way to file a document, and
 * it had three problems beyond the field-name mismatch that lost every file:
 *
 *   NO VALIDATION   no mime allow-list, no size cap, and the extension taken
 *                   from the CLIENT-SUPPLIED filename.
 *   TENANT IDOR     `sub_institute_id` read from the REQUEST, no role gate on
 *                   the route, and an arbitrary `{id}` in the path - so any
 *                   authenticated employee could file a document against any
 *                   user in any organisation.
 *   PUBLIC OBJECTS  written with `public` visibility under `{userId}{YmdHis}.ext`,
 *                   and the browser downloaded them by building the bucket URL
 *                   itself. Guess a key, read somebody's ID proof, no
 *                   authentication at all.
 *
 * ── TWO ROUTES, ONE CONTROLLER, DIFFERENT SUBJECTS ─────────────────────────
 *
 *   /api/account/documents            the subject is the TOKEN'S OWNER. There is
 *                                     no id parameter, so it cannot be an IDOR by
 *                                     construction - the same reason
 *                                     `/account/me` has none.
 *
 *   /api/employees-management/{id}/documents
 *                                     the subject is {id}, gated `profile:admin,hr`
 *                                     AND re-checked here against the caller's own
 *                                     tenant. The route gate says WHO may ask; the
 *                                     tenant check says WHOM they may ask about.
 *                                     Neither is sufficient alone.
 *
 * ── AND DOWNLOADS ARE STREAMED, NOT LINKED ─────────────────────────────────
 *
 * The path comes off the ROW, never the request, and the bytes go through this
 * application so the permission check above actually applies to reading the file
 * and not merely to listing it. Modelled on `DepartmentSopController::download`,
 * which is the one upload feature in this codebase that was written this way.
 */
class EmployeeDocumentController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * What a personnel document may be.
     *
     * Same list as `tbluserController::DOCUMENT_EXTENSIONS`, and for the same
     * reason: office documents, PDFs, and images because ID proofs are
     * photographed. Nothing executable.
     */
    private const ALLOWED_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png,webp';
    private const MAX_KILOBYTES = 20480; // 20 MB

    /** Where new objects go. Legacy rows may be in `public/staff_document/`. */
    private const FOLDER = 'public/hp_staff_document/';

    /**
     * Every folder this table's files have ever been written to, newest first.
     *
     * Three writers have filed into `staff_document` with three conventions:
     * this controller and the legacy directory upload both use
     * `public/hp_staff_document/`, while PayrollController files payslips into
     * `public/staff_document/` (PayrollController.php:2262) - a different
     * folder, one character apart.
     *
     * `file_path` has only been recorded since 2026-09-24, so for every row
     * older than that the folder has to be found rather than read. Guessing a
     * single folder is what made every payslip in "Your documents" answer
     * "its file is missing" while the file sat in the bucket next door.
     */
    private const LEGACY_FOLDERS = [
        'public/hp_staff_document/',
        'public/staff_document/',
    ];

    /** GET /api/account/documents — my own. */
    public function mine(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->listFor((int) $identity['user_id'], (int) $identity['sub_institute_id']);
    }

    /** GET /api/employees-management/{id}/documents — somebody else's. */
    public function forEmployee(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $id, $tenantId)) {
            return $this->notFound();
        }

        return $this->listFor((int) $id, $tenantId);
    }

    /** POST /api/account/documents — file one of my own. */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->fileDocument(
            $request,
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            (int) $identity['user_id']
        );
    }

    /**
     * POST /api/employees-management/{id}/documents — file one FOR an employee.
     *
     * HR has always been able to upload an employee's document; it went through
     * `tbluserController::addUserDocument`, which wrote the object **public**,
     * recorded no `file_path`, and took `sub_institute_id` from the request body
     * rather than the token. Two writers filing the same folder with opposite
     * visibility is why a download worked or failed depending on which screen
     * had filed it.
     *
     * Same filer as self-service now, so there is one way in. The subject is the
     * employee in the URL; the ACTOR recorded in `created_by` is the HR user, so
     * the record still says who filed it.
     *
     * Route-gated `profile:admin,hr`; the tenant check below is the second lock,
     * because a role alone would let HR in one organisation file against an
     * employee id belonging to another.
     */
    public function storeForEmployee(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $id, $tenantId)) {
            return $this->notFound();
        }

        return $this->fileDocument($request, (int) $id, $tenantId, (int) $identity['user_id']);
    }

    /**
     * DELETE /api/employees-management/{employee}/documents/{document}
     *
     * HR removes an employee's document. `destroy()` above is deliberately
     * owner-only - its comment says removing somebody else's "is a different
     * decision and does not happen here" - so this is that decision, made
     * explicitly rather than by widening the self-service route.
     *
     * The employee id in the URL is not decoration: the document must belong to
     * that employee AND that employee must be in the caller's tenant, so a
     * guessed document id cannot reach across.
     */
    public function destroyForEmployee(Request $request, $employee, $document)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $employee, $tenantId)) {
            return $this->notFound();
        }

        $row = DB::table('staff_document')
            ->where('id', (int) $document)
            ->where('user_id', (int) $employee)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('staff_document')->where('id', $row->id)->update([
            'deleted_by' => (int) $identity['user_id'],
            'deleted_at' => now(),
        ]);

        // The object is left in place for the same reason destroy() leaves it:
        // the row soft-deletes and can be restored, and a restored row pointing
        // at a purged object is worse than an orphaned object.
        return response()->json([
            'status' => 1,
            'message' => 'Document removed.',
        ]);
    }

    /**
     * Write the file and the row. Shared by self-service and the HR route so
     * there is exactly one place that decides visibility, naming and metadata.
     *
     * @param  int  $subjectId  whose document this is
     * @param  int  $tenantId   the tenant it belongs to
     * @param  int  $actorId    who is filing it (may differ from the subject)
     */
    private function fileDocument(Request $request, int $subjectId, int $tenantId, int $actorId)
    {
        $data = $request->validate([
            'document' => 'required|file|mimes:' . self::ALLOWED_EXTENSIONS . '|max:' . self::MAX_KILOBYTES,
            'document_title' => 'required|string|max:191',
            'document_type_id' => 'required|integer',
        ]);

        /*
         * The type must be a real one, and a STAFF one. Without this the column
         * accepts any integer - which is how the eight existing live rows came to
         * point at `document_type_id = 1` in a table that has no such row.
         */
        $typeExists = DB::table('student_document_type')
            ->where('id', (int) $data['document_type_id'])
            ->where('user_type', 'staff')
            ->exists();

        if (!$typeExists) {
            return response()->json([
                'status' => 0,
                'message' => 'That document type does not exist.',
            ], 422);
        }

        $file = $request->file('document');

        /*
         * The extension comes from the BYTES (`extension()` is `guessExtension()`),
         * never from the client's filename, and the basename is random. See the
         * comment block in `AccountController::storeAvatar` for what happens
         * otherwise: a genuine image uploaded as `payload.html` becomes an
         * executing page on the company's own CDN.
         */
        $extension = $file->extension() ?: 'bin';
        $fileName = $subjectId . '_' . Str::random(24) . '.' . $extension;
        $path = self::FOLDER . $fileName;

        Storage::disk('digitalocean')->putFileAs(
            self::FOLDER,
            $file,
            $fileName,
            /*
             * PRIVATE, unlike everything this table held before.
             *
             * Documents are résumés, ID proofs and salary letters. The old path
             * wrote them `public` with a guessable key, so anybody who worked out
             * the pattern could read them without signing in. These are served by
             * `download()` below, behind the same permission check as the list.
             *
             * ContentType is stated explicitly because `putFileAs` hands Flysystem
             * a stream, which otherwise types the object from the key.
             */
            ['visibility' => 'private', 'ContentType' => $file->getMimeType()]
        );

        $documentId = DB::table('staff_document')->insertGetId([
            'user_id' => $subjectId,
            'document_type_id' => (int) $data['document_type_id'],
            'document_title' => $data['document_title'],
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sub_institute_id' => $tenantId,
            // The ACTOR, which is the HR user when they file on somebody's
            // behalf - so the record says who put it there.
            'created_by' => $actorId,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => 'Document uploaded.',
            'data' => ['id' => $documentId],
        ]);
    }

    /**
     * GET /api/account/documents/{id}/download — stream one back.
     *
     * The path is read from the ROW, never from the request, so a caller cannot
     * ask for an arbitrary key. And the row is scoped to the caller before the
     * object is touched at all.
     */
    public function download(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $row = $this->readableDocument((int) $id, $identity);

        if (!$row) {
            return $this->notFound();
        }

        $path = $this->objectPath($row);

        try {
            if ($path === null) {
                return response()->json([
                    'status' => 0,
                    'message' => 'That document is recorded but its file is missing. Ask whoever uploaded it to add it again.',
                ], 404);
            }

            return Storage::disk('digitalocean')->download(
                $path,
                // The title the person gave it, plus the real extension - so a
                // download is not named `43_a8Kd.pdf`.
                $this->downloadName($row)
            );
        } catch (\Throwable $caught) {
            report($caught);

            return response()->json([
                'status' => 0,
                'message' => 'That document could not be fetched right now.',
            ], 502);
        }
    }

    /** DELETE /api/account/documents/{id} — remove one of mine. */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        /*
         * Scoped to the OWNER, not merely to the tenant. This is the self-service
         * route: somebody removing their own document. HR removing an employee's
         * is a different decision and does not happen here.
         */
        $row = DB::table('staff_document')
            ->where('id', (int) $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('staff_document')->where('id', $row->id)->update([
            'deleted_by' => $userId,
            'deleted_at' => now(),
        ]);

        /*
         * The OBJECT IS LEFT IN PLACE, deliberately. `staff_document` soft-deletes,
         * so the row can be restored - and a restored row pointing at an object
         * that was purged is worse than an orphaned object. Cleaning those up is a
         * scheduled job's work, not a request's.
         */

        return response()->json(['status' => 1, 'message' => 'Document removed.']);
    }

    /* ── shared ────────────────────────────────────────────────────────── */

    /**
     * One person's documents, with their type names resolved.
     *
     * `student_document_type` filtered to `user_type = 'staff'` - NOT the newer
     * `document_type` table, which is empty. A LEFT join, not an inner one: the
     * eight existing live rows point at a type id that exists in neither table,
     * and an inner join would make those documents invisible rather than
     * imperfectly labelled.
     */
    private function listFor(int $userId, int $tenantId)
    {
        $rows = DB::table('staff_document as d')
            ->leftJoin('student_document_type as t', 't.id', '=', 'd.document_type_id')
            ->where('d.user_id', $userId)
            // Tenant-scoped as well as user-scoped. `staff_document` is a global
            // table and a user id alone would be enough to read across.
            ->where('d.sub_institute_id', $tenantId)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.created_at')
            ->get([
                'd.id',
                'd.document_title',
                'd.document_type_id',
                't.document_type',
                'd.file_name',
                'd.mime_type',
                'd.file_size',
                'd.created_at',
            ]);

        return response()->json([
            'status' => 1,
            'data' => $rows,
            // The types a new upload may use, so the form does not hardcode them.
            'document_types' => DB::table('student_document_type')
                ->where('user_type', 'staff')
                ->where('status', 1)
                ->orderBy('document_type')
                ->get(['id', 'document_type']),
        ]);
    }

    /**
     * Where this row's file actually is, or null if it is nowhere.
     *
     * `file_path` when we recorded one; otherwise every historic folder is
     * tried in turn. Returning null rather than a guessed path lets the caller
     * say "the file is missing" truthfully, instead of reporting a miss against
     * one folder while the object sits in another.
     */
    private function objectPath($row): ?string
    {
        $disk = Storage::disk('digitalocean');

        if (!empty($row->file_path) && $disk->exists($row->file_path)) {
            return $row->file_path;
        }

        if (empty($row->file_name)) {
            return null;
        }

        foreach (self::LEGACY_FOLDERS as $folder) {
            $candidate = $folder . $row->file_name;
            if ($disk->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The document, if this caller may read it.
     *
     * Two ways in, and they are checked in order of least privilege:
     *   - it is theirs; or
     *   - they are HR or an administrator AND it belongs to their own tenant.
     *
     * The role is resolved by `RoleKey`, the same resolver every other gate uses,
     * rather than by profile name - ten live tenants have a legacy profile called
     * "Admin" that a name comparison would miss.
     */
    private function readableDocument(int $documentId, array $identity)
    {
        $row = DB::table('staff_document')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return null;
        }

        if ((int) $row->user_id === (int) $identity['user_id']) {
            return $row;
        }

        if ((int) $row->sub_institute_id !== (int) $identity['sub_institute_id']) {
            return null;
        }

        $role = RoleKey::forUserId((int) $identity['user_id']);

        return in_array($role, SubjectAuthority::RECORD_OWNERS, true) ? $row : null;
    }

    /** Is this employee one of ours? */
    private function employeeInTenant(int $userId, int $tenantId): bool
    {
        return DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /** The title the person gave it, plus the stored file's real extension. */
    private function downloadName(object $row): string
    {
        $extension = pathinfo((string) $row->file_name, PATHINFO_EXTENSION);
        $title = trim((string) ($row->document_title ?? '')) ?: 'document';

        // Anything that could steer a path or break a header, removed. The name is
        // person-supplied and ends up in Content-Disposition.
        $safe = preg_replace('/[^A-Za-z0-9 _.-]/', '', $title) ?: 'document';

        return $extension ? $safe . '.' . $extension : $safe;
    }

    /**
     * 404 for both "no such document" and "not yours".
     *
     * Deliberately indistinguishable: a 403 would confirm that a given id exists
     * in somebody else's organisation, which is a slow way of enumerating them.
     */
    private function notFound()
    {
        return response()->json([
            'status' => 0,
            'message' => 'That document was not found.',
        ], 404);
    }
}
