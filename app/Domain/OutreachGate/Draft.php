<?php

namespace App\Domain\OutreachGate;

/**
 * What the gate looks at: one outreach message that has not been sent, in the shape both mailers share. Immutable. Built by DraftFactory from a GTM outreach
 * message or a CRM email row; the checks never see the tables themselves.
 *
 * The text is the DRAFT's own text. The unsubscribe footer a mailer appends is added after the gate runs and is not part of it.
 */
final class Draft
{
    public const GTM = 'gtm_outreach_messages';

    public const CRM = 'crm_emails';

    public const TABLES = [self::GTM, self::CRM];

    /**
     * @param  string  $table  GTM or CRM
     * @param  int  $tenant  the organisation the message belongs to
     * @param  string  $address  the address it would be sent to (may be empty or invalid: the recipient check says so)
     * @param  int|null  $gtmContactId  GTM only: the outreach contact (gtm_contacts.id)
     * @param  string|null  $linkedType  CRM only: LEAD, CONTACT, ORGANIZATION, OPPORTUNITY or TICKET
     * @param  int|null  $linkedId  CRM only: the linked record's id
     * @param  list<int>  $productIds  the products the message is DECLARED to be about (a pointer on the message), before any found by name
     */
    public function __construct(
        public readonly string $table,
        public readonly int $id,
        public readonly int $tenant,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $address = '',
        public readonly ?int $gtmContactId = null,
        public readonly ?string $linkedType = null,
        public readonly ?int $linkedId = null,
        public readonly array $productIds = [],
    ) {
    }

    /** Subject and body as one text, for the checks that read both. */
    public function text(): string
    {
        return $this->subject . "\n" . $this->body;
    }
}
