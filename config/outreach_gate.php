<?php

/*
 * The outreach gate (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md).
 *
 * ONE deterministic gate that both mailers (App\Domain\Gtm\OutreachMailer and App\Services\Crm\CrmOutreachMailer) ask as one more refusal step, and that a screen can ask
 * on its own to preview a draft. It only ever adds a REASON NOT TO SEND. It does not send, schedule or switch anything on, and nothing in this file is a sending flag.
 *
 * Everything a rule needs is here, so a rule can change without a code change. Patterns are regular expressions WITHOUT delimiters; they are applied
 * case-insensitively and as unicode (`~...~iu`), so they must not contain a tilde.
 */
return [
    // Phrases that make a cold email look like spam or a scam. Matched as whole phrases, ignoring case and spacing.
    'banned_phrases' => [
        'act now', 'limited time offer', 'click here', 'buy now', 'order now', 'risk-free', 'risk free', '100% free', 'no obligation',
        'you have been selected', 'you are a winner', 'congratulations you', 'make money fast', 'guaranteed income', 'no credit check',
        'once in a lifetime', 'urgent response required', 'this is not spam', 'dear friend', 'double your',
    ],

    // A cold email is short. Over these lengths (in characters) the gate refuses. The mailer's own unsubscribe footer is added AFTER the gate and is not counted.
    'max_subject_chars' => 150,
    'max_body_chars' => 1500,

    // At most this many DISTINCT links in the body. The signed unsubscribe link the mailer adds is never counted; neither is any link whose address contains one of
    // the markers below (a template that already carries its own unsubscribe link).
    'max_links' => 1,
    'unsubscribe_markers' => ['unsubscribe'],
    // A bare domain such as school.example.com/page counts as a link when it ends in one of these (an e-mail address never does: it is excluded by its @).
    'link_tlds' => ['com', 'in', 'org', 'net', 'io', 'co', 'edu', 'ai', 'app', 'dev', 'me', 'ly', 'info', 'biz', 'tech', 'school', 'ac'],

    // A merge field that was never filled in must never reach a person: {{first_name}}, {first_name}, [First Name], [COMPANY], <first_name>, %FIRSTNAME%, ${name}.
    'placeholder_patterns' => [
        '\{\{[^}]*\}\}',
        '\{%[^}]*%\}',
        '\$\{[^}]*\}',
        '\{\s*[a-z_][\w .-]{0,40}\}',
        '\[\s*[^\]\n]{0,25}\b(?:name|first|last|company|organi[sz]ation|school|institute|college|university|contact|recipient|customer|client|sender|title|designation|city|date|product|email)\b[^\]\n]{0,25}\]',
        '(?-i:\[[A-Z][A-Z0-9 _-]{2,}\])',
        '<\s*[a-z]+(?:[_ -][a-z]+)+\s*>',
        '(?-i:%[A-Z][A-Z0-9_]{2,}%)',
    ],

    // A sentence that makes a NUMERIC, PERCENTAGE, GUARANTEE or SUPERLATIVE claim. Such a sentence may be sent only if it appears, normalised, in an APPROVED claim of the
    // product the draft is about (table g2g_product_claims). No approved claim means no such sentence passes. The bare word "only" is NOT a marker (it would stop "we only
    // need 15 minutes"); "the only", "only one" and "one and only" are.
    'claim_markers' => [
        '\d+(?:[.,]\d+)?\s*%',
        '\bpercent\b',
        '\b\d+(?:[.,]\d+)?\s*x\b',
        '\bguarantee[sd]?\b',
        '\bguaranteed\b',
        '#\s*1\b',
        '\bnumber\s+(?:one|1)\b',
        '\b(?:the\s+)?(?:best|fastest|cheapest|lowest|biggest|largest|leading|top[- ]rated|most\s+(?:trusted|popular|advanced|affordable|reliable))\b',
        '\bthe\s+only\b',
        '\bonly\s+one\b',
        '\bone\s+and\s+only\b',
        '\bno\.\s*1\b',
        '\b(?:zero|no)\s+(?:risk|downtime|errors?|mistakes?)\b',
        '\b100\s*%',
    ],

    // What the gate does when a GTM outreach contact has NO record in the CRM (no contact, lead or organisation with the same address): consent lives on CRM records, so
    // without one it cannot be read.
    //   'fail'      (default) the message is refused, with a plain reason ("add the person to the CRM first"). Fail closed, as the slice asks.
    //   'gtm_only'  consent is then decided by the outreach contact's own status and the suppression list alone (both are checked in every case).
    'unmapped_gtm_recipient' => env('OUTREACH_GATE_UNMAPPED_GTM_RECIPIENT', 'fail'),
];
