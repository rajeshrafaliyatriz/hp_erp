<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Certificate expiry warning window
    |--------------------------------------------------------------------------
    |
    | How many days before its expiry date a certificate is reported as
    | "expiring" rather than "active". The Certifications & Records screen reads
    | this back from the API rather than hardcoding a number in its label, so
    | changing it here changes both the badge and the copy.
    |
    | Compliance renewal windows vary by organisation, which is why this is
    | configurable rather than a constant.
    */

    'certificate_warning_days' => (int) env('LMS_CERT_EXPIRY_WARNING_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Course languages
    |--------------------------------------------------------------------------
    |
    | Offered in the Course Builder and stored on lms_course_settings.language.
    |
    | Config rather than a table: this is a fixed list an administrator changes
    | at deploy time, not tenant data anyone edits through the UI, and a table
    | holding five strings would need its own CRUD screen to be maintainable.
    | Served through /api/lms/courses/filters so the frontend never hardcodes
    | it - adding a language here is enough to make it selectable.
    */

    'languages' => [
        'English (US)',
        'English (UK)',
        'Hindi',
        'Gujarati',
        'Marathi',
        'Spanish (ES)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate templates
    |--------------------------------------------------------------------------
    |
    | `view` is the blade downloadCertificate actually renders for that choice.
    | Every entry must name a view that exists - otherwise the builder offers a
    | template that silently falls back to another one, which is a choice the
    | user cannot see the effect of. The controller resolves through this map
    | and falls back to the standard view for an unknown value.
    */

    'certificate_templates' => [
        [
            'value' => 'standard',
            'label' => 'Standard Corporate Template',
            'view'  => 'lms.certificate',
        ],
        [
            'value' => 'compliance',
            'label' => 'Compliance Template',
            'view'  => 'lms.certificate_compliance',
        ],
    ],
];
