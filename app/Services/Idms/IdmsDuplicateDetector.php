<?php

namespace App\Services\Idms;

use App\Models\Idms\DocumentMaster;

/**
 * Exact duplicates (same SHA-256) and version candidates (same type, department,
 * year and similar subject). Both lookups go through visibleTo, so a document the
 * uploader cannot see is never named in a warning.
 */
class IdmsDuplicateDetector
{
    public function detect(DocumentMaster $document, $user, int $subInstituteId): array
    {
        $warnings = [];

        $exact = DocumentMaster::query()
            ->visibleTo($user, $subInstituteId)
            ->where('id', '!=', $document->id)
            ->where('checksum_sha256', $document->checksum_sha256)
            ->first(['id', 'title']);

        if ($exact) {
            $warnings[] = [
                'type' => 'duplicate_of',
                'document_id' => $exact->id,
                'title' => $exact->title,
                'message' => 'An identical file is already stored in the system.',
            ];
        }

        if ($document->document_type && $document->department_id) {
            $candidate = DocumentMaster::query()
                ->visibleTo($user, $subInstituteId)
                ->where('id', '!=', $document->id)
                ->where('document_type', $document->document_type)
                ->where('department_id', $document->department_id)
                ->when($document->academic_year, fn ($q) => $q->where('academic_year', $document->academic_year))
                ->when($document->subject, fn ($q) => $q->where('subject', 'LIKE', '%' . addcslashes($document->subject, '\\%_') . '%'))
                ->first(['id', 'title', 'current_version']);

            if ($candidate) {
                $warnings[] = [
                    'type' => 'version_of_candidate',
                    'document_id' => $candidate->id,
                    'title' => $candidate->title,
                    'current_version' => $candidate->current_version,
                    'message' => "This file resembles '{$candidate->title}'. You can save it as version " . ($candidate->current_version + 1) . '.',
                ];
            }
        }

        return $warnings;
    }
}
