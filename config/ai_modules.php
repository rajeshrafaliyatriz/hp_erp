<?php

/*
|--------------------------------------------------------------------------
| Module registration and schema discovery for the AI Stack
|--------------------------------------------------------------------------
|
| Only the facts that cannot be read from the database or the menu tree live here.
| A new module needs NO entry in this file to get an AI Stack: it is registered from its
| level-1 row in `tblmenumaster_g2g` and its tables are found by the prefix its own menu
| links imply (`/module/foo-bar/...` -> `foo_bar_`). An entry below is needed only when
| that convention does not hold, and `php artisan ai:sync-modules` says when it does not.
*/

return [

    /*
    | Modules whose data sources are hand-written in ModuleDataSourceCatalog (joins, labels,
    | privacy decisions made by a person). Schema discovery never adds to these: it would
    | silently widen what their assistant reads. Every other module is discovered.
    */
    'curated' => [
        'main_dashboard',
        'organizational_management',
        'capability_intelligence',
        'talent_management',
        'lms',
        'hrit_management',
        'task_management',
        'agentic_ai',
    ],

    /*
    | Table prefixes per module key, ADDED to the ones the menu tree implies. Needed when a
    | module's tables are not named after its menu links - e.g. a module sold as "Sales &
    | Marketing" whose menu links say `/module/crm` and `/gtm` is already covered by the
    | convention; one whose tables are `acme_*` while its links say `/module/projects` is not.
    */
    'table_prefixes' => [
        // 'projects' => ['acme_'],
    ],

    /*
    | Columns never exposed to the assistant, wherever they appear. Matched against the
    | whole column name. A table whose NAME matches `sensitive_tables` is not discovered.
    */
    'sensitive_columns' => '/(^|_)(password|passwd|pwd|secret|token|api_?key|otp|salt|ssn|aadhaa?r|pan_?(no|number)?|card_?(no|number)?|cvv|iban|ifsc|bank|account_?number|credential|private_?key|remember_token|signature)(_|$)/i',
    /* Contact details of people. Withheld from discovered tables: the assistant needs who and what, not how to reach them. */
    'pii_columns' => '/(^|_)(e?mail|phone|mobile|whatsapp|address|dob|birth(date|day)?|zip|postal|pincode|latitude|longitude|lat|lng|ip)(_|$)/i',
    'sensitive_tables' => '/(password|token|secret|credential|api_?keys?|oauth|session|otp|migration)/i',

    /* Column types that are never selected (binary and structured blobs). Long text is cut short. */
    'blocked_column_types' => ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'json', 'jsonb', 'geometry'],

    /* How many characters of a free-text column the assistant may read. */
    'text_chars' => 200,

    /* Columns read per table, and tables discovered per module. */
    'max_columns' => 24,
    'max_tables' => 80,

    /* Label columns tried, in order, to name the record a foreign key points at. */
    'label_columns' => ['name', 'title', 'subject', 'company_name', 'account_name', 'organization_name', 'display_name', 'full_name', 'label', 'code'],

    /* Seconds a table/column listing is trusted before the schema is read again. */
    'schema_ttl' => 60,
];
