<?php

/*
|--------------------------------------------------------------------------
| Document Library
|--------------------------------------------------------------------------
|
| The AI provider, model and key are NOT here: classification calls
| AiModelClient under the `ai_module` key below, so it resolves through the
| same AI Providers configuration as every other AI feature in this app
| (see config/ai.php). This file only holds what an operator may want to
| change without touching code - caps, allowed types, the queue name.
*/

return [
    'disk' => env('DOCUMENTS_DISK', 'digitalocean'),

    // Where new objects go. Distinct from every folder staff_document's three
    // writers ever used (public/hp_staff_document/, public/staff_document/,
    // public/offerLetter/) - one convention, one place to look.
    'folder' => env('DOCUMENTS_FOLDER', 'private/document_library/'),

    'max_upload_kb' => (int) env('DOCUMENTS_MAX_UPLOAD_KB', 51200), // 50 MB

    'allowed_extensions' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png,webp',

    // Known document_type values. An open string column, not a locked lookup
    // table (student_document_type has no seeder anywhere in this codebase
    // and its live contents are unknowable from source control - see the
    // migration's docblock) - so this list is what the upload form offers,
    // not what the database enforces. A tenant-specific addition does not
    // need a migration.
    'types' => [
        'personnel' => [
            'resume' => 'Resume',
            'payslip' => 'Payslip',
            'offer_letter' => 'Offer Letter',
            'form16' => 'Form 16',
            'salary_certificate' => 'Salary Certificate',
            'certificate' => 'Certificate',
            'id_proof' => 'Identity Document',
            'other' => 'Other',
        ],
        'organization' => [
            'contract' => 'Contract',
            'agreement' => 'Agreement',
            'policy' => 'Policy',
            'circular' => 'Circular',
            'notice' => 'Notice',
            'minutes' => 'Minutes of Meeting',
            'proposal' => 'Proposal',
            'report' => 'Report',
            'invoice' => 'Invoice',
            'purchase_order' => 'Purchase Order',
            'sop' => 'Standard Operating Procedure',
            // Written only by federated indexers (see source_systems below),
            // never offered on the upload form - a person cannot "upload" an
            // onboarding checklist item or an LMS certificate here, those are
            // indexed copies of a row that lives in its own feature.
            'onboarding_document' => 'Onboarding Document',
            'competency_certificate' => 'Certification Evidence',
            'task_document' => 'Task Document',
            'offboarding_document' => 'Offboarding Document',
            'lms_certificate' => 'Training Certificate',
            'other' => 'Other',
        ],
    ],

    'visibility' => ['private', 'department', 'organization'],

    'processing' => [
        'queue' => env('DOCUMENTS_QUEUE', 'documents'),
        'ai_module' => env('DOCUMENTS_AI_MODULE', 'document_classification'),
        'max_output_tokens' => (int) env('DOCUMENTS_AI_MAX_OUTPUT_TOKENS', 2048),
        'temperature' => (float) env('DOCUMENTS_AI_TEMPERATURE', 0.1),
        // Below this, a classification is kept as a suggestion only - the
        // rule-based fallback's fields win for anything AI could not say
        // confidently.
        'confidence_threshold' => (float) env('DOCUMENTS_AI_CONFIDENCE_THRESHOLD', 0.65),
        // Characters of extracted text sent to the classifier. Bounds prompt
        // size/cost; long documents are classified from an excerpt.
        'excerpt_chars' => (int) env('DOCUMENTS_AI_EXCERPT_CHARS', 6000),
    ],

    // Source systems a federated indexer may write under source_system. Kept
    // here so a new indexer registers itself in one place.
    'source_systems' => [
        'onboarding', 'competency', 'task_management', 'offboarding', 'lms_certificate',
    ],
];
