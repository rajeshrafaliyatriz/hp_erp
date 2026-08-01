<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\OnboardingDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The Documents panel on an onboarding journey - offer letter, ID proof,
 * signed agreement and the rest.
 *
 * talent_onboarding_documents models a request -> submit -> verify lifecycle
 * rather than a plain upload, so the timestamps are written on the transitions
 * that earn them: requested_at when HR asks for a document, submitted_at when a
 * file lands, verified_at when someone signs it off.
 */
class OnboardingDocumentController extends Controller
{
    use ResolvesTalentContext;

    /** GET /api/talent/onboarding/documents?journey_id= */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journeyId = $this->activeTalentFilter($request->input('journey_id'));
        $status = $this->activeTalentFilter($request->input('status'));

        $rows = DB::table('talent_onboarding_documents')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->when($journeyId, fn ($q) => $q->where('journey_id', $journeyId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->talentResponse($rows->map(fn ($row) => $this->present($row))->all(), 'Success', 200, [
            'summary' => [
                'pending'   => (clone $rows)->where('status', 'pending')->count(),
                'submitted' => (clone $rows)->where('status', 'submitted')->count(),
                'verified'  => (clone $rows)->where('status', 'verified')->count(),
            ],
        ]);
    }

    /**
     * POST /api/talent/onboarding/documents
     *
     * Accepts either a real upload (`file`) or a pre-uploaded path, matching
     * PerformanceActivityController::storeAttachment so both modules behave the
     * same way for the same request shape.
     */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'journey_id'       => 'required|integer',
            'title'            => 'required|string|max:191',
            'document_type_id' => 'nullable|integer',
            'file'             => 'nullable|file|max:20480',
            'file_name'        => 'nullable|string|max:191',
            'file_path'        => 'nullable|string|max:500',
            'is_mandatory'     => 'nullable|boolean',
            'due_date'         => 'nullable|date',
            'remarks'          => 'nullable|string',
            'sort_order'       => 'nullable|integer|min:0',
            'status'           => 'nullable|in:' . implode(',', OnboardingDocument::STATUSES),
        ]);

        $journeyExists = DB::table('talent_onboarding_journeys')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->where('id', $validated['journey_id'])
            ->exists();

        if (!$journeyExists) {
            return $this->talentError('Onboarding journey not found', 404);
        }

        $fileName = $validated['file_name'] ?? null;
        $filePath = $validated['file_path'] ?? null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            $stored = 'onboarding_documents/' . $tenant . '/' . uniqid('onb_', true) . '.' . $file->getClientOriginalExtension();

            // 'public' is the disk every other upload in this app writes to.
            Storage::disk('public')->putFileAs(dirname($stored), $file, basename($stored));

            $filePath = $stored;
        }

        $document = OnboardingDocument::create([
            'journey_id'       => $validated['journey_id'],
            'sub_institute_id' => $tenant,
            'document_type_id' => $validated['document_type_id'] ?? null,
            'title'            => $validated['title'],
            'file_name'        => $fileName,
            'file_path'        => $filePath,
            // A row with a file attached has been submitted; without one it is
            // still only requested.
            'status'           => $validated['status'] ?? ($filePath ? 'submitted' : 'requested'),
            'is_mandatory'     => $validated['is_mandatory'] ?? true,
            'due_date'         => $validated['due_date'] ?? null,
            'requested_at'     => now(),
            'submitted_at'     => $filePath ? now() : null,
            'remarks'          => $validated['remarks'] ?? null,
            'sort_order'       => $validated['sort_order'] ?? 0,
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]);

        return $this->talentResponse($this->present($document), 'Document added', 201);
    }

    /**
     * PUT /api/talent/onboarding/documents/{id}
     *
     * Also the submit and verify actions: sending status=submitted stamps
     * submitted_at, status=verified stamps verified_at and verified_by.
     */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $document = OnboardingDocument::where('sub_institute_id', $tenant)->find($id);

        if (!$document) {
            return $this->talentError('Document not found', 404);
        }

        $validated = $request->validate([
            'title'        => 'sometimes|required|string|max:191',
            'file'         => 'nullable|file|max:20480',
            'file_name'    => 'nullable|string|max:191',
            'file_path'    => 'nullable|string|max:500',
            'is_mandatory' => 'nullable|boolean',
            'due_date'     => 'nullable|date',
            'remarks'      => 'nullable|string',
            'sort_order'   => 'nullable|integer|min:0',
            'status'       => 'nullable|in:' . implode(',', OnboardingDocument::STATUSES),
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $stored = 'onboarding_documents/' . $tenant . '/' . uniqid('onb_', true) . '.' . $file->getClientOriginalExtension();
            Storage::disk('public')->putFileAs(dirname($stored), $file, basename($stored));

            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_path'] = $stored;
            $validated['status'] = $validated['status'] ?? 'submitted';
        }

        unset($validated['file']);
        $document->fill($validated);

        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'submitted') {
                $document->submitted_at = $document->submitted_at ?: now();
            }

            if ($validated['status'] === 'verified') {
                $document->submitted_at = $document->submitted_at ?: now();
                $document->verified_at = $document->verified_at ?: now();
                $document->verified_by = $document->verified_by ?: $context['user_id'];
            }

            // Reopening clears the verification, otherwise a rejected document
            // would still read as signed off.
            if (in_array($validated['status'], ['pending', 'requested', 'rejected'], true)) {
                $document->verified_at = null;
                $document->verified_by = null;
            }
        }

        $document->updated_by = $context['user_id'];
        $document->save();

        return $this->talentResponse($this->present($document), 'Document updated');
    }

    /** DELETE /api/talent/onboarding/documents/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $document = OnboardingDocument::where('sub_institute_id', $tenant)->find($id);

        if (!$document) {
            return $this->talentError('Document not found', 404);
        }

        $document->deleted_by = $context['user_id'];
        $document->save();
        $document->delete();

        return $this->talentResponse(null, 'Document deleted');
    }

    private function present($row): array
    {
        $path = $row->file_path ?? null;

        return [
            'id'               => (int) $row->id,
            'journey_id'       => (int) $row->journey_id,
            'document_type_id' => $row->document_type_id ? (int) $row->document_type_id : null,
            'title'            => $row->title,
            'file_name'        => $row->file_name,
            'file_path'        => $path,
            'file_url'         => $path ? Storage::disk('public')->url($path) : null,
            'status'           => $row->status,
            'status_label'     => $this->talentLabel($row->status),
            'is_mandatory'     => (bool) $row->is_mandatory,
            'due_date'         => $row->due_date,
            'due_date_label'   => $this->talentDateLabel($row->due_date),
            'requested_at'     => $row->requested_at,
            'submitted_at'     => $row->submitted_at,
            'verified_at'      => $row->verified_at,
            'remarks'          => $row->remarks,
            'sort_order'       => (int) $row->sort_order,
        ];
    }
}
