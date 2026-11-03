<?php

/*
 * The contact facts ledger (slice 4 of the agentic CRM, Docs/agentic-crm/slices/S04-contact-facts-and-consent.md).
 *
 * The evidence weights, the noisy-OR score, the ceiling and the band floors are PORTED from Comp AI's CRM research agent (MIT licence, copyright 2026
 * Comp AI: apps/agent/agent/lib/evidence.ts). They live here, in code, and not in the database, so a change is reviewed, versioned and covered by the
 * golden tests (tests/Unit/FactsEvidenceScorerTest.php) instead of being an edit nobody can see.
 *
 * HOW A SCORE IS MADE. Each independent piece of evidence has a fixed weight. The score is 1 minus the product of (1 - weight): two moderate sources
 * outweigh one, and no number of weak ones reaches certainty. It is capped at `ceiling` (nothing is ever 100% certain). A `contradiction` (two sources
 * disagree) does not lower the score a little, it HOLDS the fact: the score is capped at `contradicted_cap`, below the "probable" floor. The band follows
 * from the score, and `verified` additionally needs at least one PRIMARY source (one that identifies the person or the organisation itself): however
 * high the weights are ever calibrated, evidence that is only circumstantial is never `verified`.
 */
return [
    /** Nothing is ever certain. */
    'ceiling' => 0.99,

    /** A fact whose sources disagree is held below the "probable" floor. */
    'contradicted_cap' => 0.45,

    /** A score below `possible` is not kept at all. `verified` also needs a primary source. */
    'band_floor' => ['verified' => 0.85, 'probable' => 0.55, 'possible' => 0.30],

    /**
     * kind => weight, whether it is a primary source, and the sentence a person reads ("their email address is on the profile").
     * The first eleven are Comp AI's. The last two (`site.own-page`, `directory.listing`) are G2G additions for the sources the discovery slice (S5) will
     * use for schools: an organisation's own website is the authority on its own contact details, and an official directory or a tender notice is a
     * second, independent voice. Their weights are judgement, not measurement (the calibration against a real answer key is slice 5's job).
     *
     * @var array<string, array{weight: float, primary: bool, label: string}>
     */
    'weights' => [
        'profile.email-match' => ['weight' => 0.95, 'primary' => true, 'label' => 'their email address is on the profile'],
        'linkedin.employer-and-name' => ['weight' => 0.85, 'primary' => true, 'label' => 'LinkedIn: employer and name both match'],
        'crm.thread-reply' => ['weight' => 0.85, 'primary' => true, 'label' => 'they replied on a thread we have'],
        'crm.signature-block' => ['weight' => 0.80, 'primary' => true, 'label' => 'their own email signature says so'],
        'github.account-identity' => ['weight' => 0.80, 'primary' => true, 'label' => 'the GitHub account names them or their employer'],
        'crm.meeting-attendance' => ['weight' => 0.70, 'primary' => true, 'label' => 'they attended a meeting on our calendar'],
        'web.cited-claim' => ['weight' => 0.40, 'primary' => false, 'label' => 'a cited web source states it'],
        'handle.name-form' => ['weight' => 0.35, 'primary' => false, 'label' => 'the handle is a form of their name'],
        'search.cites-profile' => ['weight' => 0.35, 'primary' => false, 'label' => 'a search for them cites this profile'],
        'employer-only' => ['weight' => 0.20, 'primary' => false, 'label' => 'the employer matches, the name does not'],
        'contradiction' => ['weight' => 0.00, 'primary' => false, 'label' => 'another source disagrees'],
        // G2G additions
        'site.own-page' => ['weight' => 0.80, 'primary' => true, 'label' => "the organisation's own website states it"],
        'directory.listing' => ['weight' => 0.55, 'primary' => false, 'label' => 'an official directory or tender notice lists it'],
    ],

    /**
     * The fields a fact can be about.
     *
     *   type      how the value is checked and normalised (App\Domain\Facts\FactValue): email | phone | url | linkedin | x | github | text
     *   max       the longest value stored, the narrowest target column among the record types (the live database is not strict and would cut it off silently)
     *   one       true: one current value (a new accepted fact SUPERSEDES the previous one in the ledger); false: several can be true at once (two email addresses)
     *   targets   record type => the column an accepted fact is written to when that column is BLANK; null = ledger only; a record type that is not listed is
     *             not applicable (an organisation has no job title)
     *
     * @var array<string, array{label: string, type: string, max: int, one: bool, targets: array<string, ?string>}>
     */
    'fields' => [
        'email' => ['label' => 'Email address', 'type' => 'email', 'max' => 191, 'one' => false,
            'targets' => ['contact' => 'email', 'organization' => 'email', 'lead' => 'email']],
        'secondary_email' => ['label' => 'Second email address', 'type' => 'email', 'max' => 191, 'one' => false,
            'targets' => ['contact' => 'secondary_email', 'organization' => 'secondary_email', 'lead' => 'secondary_email']],
        'phone' => ['label' => 'Phone number', 'type' => 'phone', 'max' => 30, 'one' => false,
            'targets' => ['contact' => 'phone', 'organization' => 'phone', 'lead' => 'phone']],
        'mobile' => ['label' => 'Mobile number', 'type' => 'phone', 'max' => 30, 'one' => false,
            'targets' => ['contact' => 'mobile', 'lead' => 'mobile']],
        'website' => ['label' => 'Website', 'type' => 'url', 'max' => 191, 'one' => false,
            'targets' => ['organization' => 'website', 'lead' => 'website']],
        'linkedin_url' => ['label' => 'LinkedIn address', 'type' => 'linkedin', 'max' => 300, 'one' => true,
            'targets' => ['contact' => 'linkedin_url', 'organization' => 'linkedin_url', 'lead' => null]],
        'x_url' => ['label' => 'X (Twitter) address', 'type' => 'x', 'max' => 300, 'one' => true,
            'targets' => ['contact' => null, 'organization' => null, 'lead' => null]],
        'github_url' => ['label' => 'GitHub address', 'type' => 'github', 'max' => 300, 'one' => true,
            'targets' => ['contact' => null, 'lead' => null]],
        'title' => ['label' => 'Job title', 'type' => 'text', 'max' => 100, 'one' => true,
            'targets' => ['contact' => 'title', 'lead' => 'designation']],
        'employer' => ['label' => 'Employer', 'type' => 'text', 'max' => 191, 'one' => true,
            'targets' => ['contact' => null, 'lead' => null]],
        'location' => ['label' => 'Location', 'type' => 'text', 'max' => 191, 'one' => true,
            'targets' => ['contact' => null, 'organization' => null, 'lead' => null]],
    ],

    /**
     * The address of a role, not of a person (principal@, admissions@): `value_kind` is `role` for these local parts and `personal` for any other.
     * Slice 5 sends to role mailboxes by default; a personal address needs a recorded basis first.
     *
     * @var list<string>
     */
    'role_mailboxes' => [
        'info', 'admin', 'administrator', 'office', 'contact', 'contactus', 'enquiry', 'enquiries', 'inquiry', 'inquiries', 'hello', 'hi', 'mail', 'email',
        'support', 'help', 'helpdesk', 'sales', 'marketing', 'accounts', 'accounting', 'billing', 'finance', 'hr', 'careers', 'jobs', 'recruitment',
        'principal', 'headmaster', 'headmistress', 'hm', 'director', 'chairman', 'secretary', 'registrar', 'admission', 'admissions', 'reception', 'frontdesk',
        'webmaster', 'postmaster', 'noreply', 'no-reply', 'donotreply', 'team', 'school', 'college', 'trust',
    ],

    'queue' => ['default_per_page' => 25, 'max_per_page' => 50],

    /** The most facts one record's evidence tab loads. */
    'entity_limit' => 200,
];
