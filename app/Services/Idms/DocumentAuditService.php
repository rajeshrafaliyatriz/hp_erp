<?php

namespace App\Services\Idms;

use App\Models\Idms\DocumentHistory;
use App\Models\Idms\DocumentMaster;
use Illuminate\Support\Facades\Request;

/** Writes document_history rows. A new version is ONE row that is both version record and audit entry. */
class DocumentAuditService
{
    public static function log(DocumentMaster $document, string $action, ?int $userId = null, ?array $details = null): DocumentHistory
    {
        return DocumentHistory::create([
            'document_id' => $document->id,
            'entry_type' => 'audit',
            'action' => $action,
            'user_id' => $userId ?: $document->owner_id,
            'ip_address' => Request::ip(),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public static function logVersion(
        DocumentMaster $document,
        int $versionNumber,
        string $storagePath,
        string $checksum,
        int $size,
        ?string $changeNote = null,
        ?int $userId = null,
        ?array $details = null
    ): DocumentHistory {
        return DocumentHistory::create([
            'document_id' => $document->id,
            'entry_type' => 'version',
            'action' => 'version_added',
            'user_id' => $userId ?: $document->owner_id,
            'ip_address' => Request::ip(),
            'version_number' => $versionNumber,
            'storage_path' => $storagePath,
            'checksum_sha256' => $checksum,
            'size' => $size,
            'change_note' => $changeNote,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
