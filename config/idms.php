<?php

/*
|--------------------------------------------------------------------------
| IDMS — Intelligent Document Management System
|--------------------------------------------------------------------------
| Separate from config/documents.php (the HR Document Library). The two
| modules share no tables, classes or routes.
*/
return [
    'disk' => env('IDMS_DISK', 'digitalocean'),

    // Days a deleted document stays restorable before it is purged for good.
    'trash_retention_days' => (int) env('IDMS_TRASH_RETENTION_DAYS', 30),

    // AI classification. The document text is untrusted input and is only ever
    // used to fill metadata, never to decide who may see a document.
    'ai_enabled' => filter_var(env('IDMS_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'confidence_threshold' => (float) env('IDMS_CONFIDENCE_THRESHOLD', 0.65),

    'max_upload_size_kb' => (int) env('IDMS_MAX_FILE_SIZE_KB', 51200), // 50 MB

    // Upload types are validated by sniffed content (the `mimes` rule), see IdmsDocumentController::EXTENSIONS.

    'ocr_languages' => ['eng', 'guj', 'hin'],

    'allowed_document_types' => [
        'Contract',
        'Agreement',
        'Policy',
        'Circular',
        'Notice',
        'Minutes of Meeting',
        'Proposal',
        'Report',
        'Invoice',
        'Purchase Order',
        'Academic Syllabus',
        'Question Paper',
        'Certificate',
        'Identity Document',
        'General Correspondence',
    ],
];
