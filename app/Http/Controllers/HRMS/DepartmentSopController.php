<?php

namespace App\Http\Controllers\HRMS;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Standard Operating Procedures for a department.
 *
 * Replaces sops-tab.tsx's MOCK_SOPS - five hardcoded records shown identically
 * for every department, plus a "Download" button whose handler built a text
 * blob containing the string "This is a mock SOP document." and handed that to
 * the user as their file. View and Download were wired to the same handler, so
 * neither did what its label said.
 *
 * CRUD comes from DepartmentContentController. What is specific to SOPs is the
 * document: upload on create/update, and a download endpoint that streams the
 * stored file.
 */
class DepartmentSopController extends DepartmentContentController
{
    /**
     * Uploads are restricted by extension AND by the browser-reported mime.
     * Office documents and PDFs are what an SOP is; anything executable is not.
     */
    private const ALLOWED_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv';
    private const MAX_KILOBYTES = 20480; // 20 MB

    protected function table(): string
    {
        return 'department_sops';
    }

    protected function label(): string
    {
        return 'SOP';
    }

    protected function writableColumns(): array
    {
        return [
            'title',
            'code',
            'description',
            'category',
            'version',
            'status',
            'effective_date',
            'review_date',
        ];
    }

    protected function rules(bool $creating): array
    {
        return [
            'title'          => ($creating ? 'required' : 'sometimes|required') . '|string|max:191',
            'code'           => 'nullable|string|max:50',
            'description'    => 'nullable|string',
            'category'       => 'nullable|string|max:100',
            'version'        => 'nullable|string|max:20',
            'status'         => 'nullable|string|in:Active,Draft,Archived',
            'effective_date' => 'nullable|date',
            'review_date'    => 'nullable|date',
            'document'       => 'nullable|file|mimes:' . self::ALLOWED_EXTENSIONS . '|max:' . self::MAX_KILOBYTES,
        ];
    }

    /**
     * Create, then attach the document if one came with the request.
     *
     * The parent handles validation and the insert; the file is stored
     * afterwards so a rejected row never leaves an orphaned upload on disk.
     */
    public function store(Request $request)
    {
        $response = parent::store($request);

        if ($response->getStatusCode() !== 201 || !$request->hasFile('document')) {
            return $response;
        }

        $payload = $response->getData(true);
        $sopId   = $payload['data']['id'] ?? null;

        if (!$sopId) {
            return $response;
        }

        $identity = $this->resolveApiIdentity($request);
        $tenantId = $identity['sub_institute_id'];

        $this->attachDocument($request, (int) $sopId, $tenantId);

        $payload['data'] = DB::table($this->table())->where('id', $sopId)->first();

        return response()->json($payload, 201);
    }

    public function update(Request $request, $id)
    {
        $response = parent::update($request, $id);

        if ($response->getStatusCode() !== 200 || !$request->hasFile('document')) {
            return $response;
        }

        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $existing = $this->findForTenant($id, $tenantId);
        if (!$existing) {
            return $response;
        }

        $this->attachDocument($request, (int) $existing->id, $tenantId, $existing->file_path);

        return response()->json([
            'status'  => 1,
            'message' => 'SOP updated successfully',
            'data'    => DB::table($this->table())->where('id', $existing->id)->first(),
        ]);
    }

    /**
     * Stream the stored document.
     *
     * The path is read from the row, never from the request - a client-supplied
     * path is how a download endpoint becomes a way to read arbitrary files off
     * the server.
     */
    public function download(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $sop = $this->findForTenant($id, $tenantId);

        if (!$sop) {
            return response()->json(['status' => 0, 'message' => 'SOP not found'], 404);
        }

        if (!$sop->file_path || !Storage::disk('public')->exists($sop->file_path)) {
            return response()->json(['status' => 0, 'message' => 'No document attached to this SOP'], 404);
        }

        return Storage::disk('public')->download(
            $sop->file_path,
            $sop->file_name ?: basename($sop->file_path)
        );
    }

    /**
     * Remove the attached document but keep the SOP record.
     */
    public function removeDocument(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $sop = $this->findForTenant($id, $tenantId);

        if (!$sop) {
            return response()->json(['status' => 0, 'message' => 'SOP not found'], 404);
        }

        $this->deleteStoredFile($sop->file_path);

        DB::table($this->table())
            ->where('id', $sop->id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'file_path'  => null,
                'file_name'  => null,
                'file_size'  => null,
                'file_mime'  => null,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 1, 'message' => 'Document removed']);
    }

    // ---------------------------------------------------------------------

    /**
     * Store the upload under the tenant's own folder and record it on the row.
     *
     * The stored filename is generated, never taken from the upload: the
     * original name is kept only as a display label and as what the browser is
     * told to save the file as.
     */
    private function attachDocument(Request $request, int $sopId, int $tenantId, ?string $replacing = null): void
    {
        $file = $request->file('document');

        if (!$file || !$file->isValid()) {
            return;
        }

        $path = $file->store('department_sops/' . $tenantId, 'public');

        if ($replacing) {
            $this->deleteStoredFile($replacing);
        }

        DB::table($this->table())
            ->where('id', $sopId)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'file_path'  => $path,
                'file_name'  => $file->getClientOriginalName(),
                'file_size'  => $file->getSize(),
                'file_mime'  => $file->getClientMimeType(),
                'updated_at' => now(),
            ]);
    }

    private function deleteStoredFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
