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
    | Course types
    |--------------------------------------------------------------------------
    |
    | What KIND of course this is - how it is delivered. Offered in the Course
    | Builder and the catalogue, and stored on sub_std_map.subject_type.
    |
    | There was no list at all: the field was free text, so tenant 6's entire
    | vocabulary became "E-learning" and "Mutual Fund" - and "Mutual Fund" is a
    | SUBJECT, not a delivery type. The two ideas had collapsed into one field,
    | which is why the values could not be used for anything.
    |
    | These are delivery formats, deliberately industry-agnostic: a hospital, a
    | bank and a factory all run self-paced courses, workshops and compliance
    | training. What the course is ABOUT belongs in subject_category, which is
    | where "Mutual Fund" should have been.
    |
    | Config rather than a table, for the same reason as languages: a fixed list
    | an administrator changes at deploy time. Values already in use are merged
    | in by the filters endpoint, so no existing course loses its type.
    */

    'course_types' => [
        'Self-paced course',
        'Instructor-led (classroom)',
        'Instructor-led (virtual)',
        'Blended',
        'Workshop',
        'Certification programme',
        'Compliance training',
        'Induction / onboarding',
        'Microlearning',
        'On-the-job training',
        'Coaching / mentoring',
        'Assessment only',
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
