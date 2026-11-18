<?php

namespace App\Domain\OutreachGate;

/**
 * Who a draft would go to, as the gate understands it: the address, and the CRM records whose consent speaks for that person. `mapped` is false when the gate cannot
 * tell who the recipient is (no valid address, a record that does not exist for the organisation, a kind of link it does not know); every recipient check then FAILS
 * with `detail`, because a recipient nobody can identify is a recipient nobody can vouch for.
 */
final class Recipient
{
    /**
     * @param  list<array{0: string, 1: int}>  $entities  [type, id] of the CRM records (contact, organization, lead) consent is read on
     * @param  bool  $consentFromOutreachOnly  true only when config says a GTM contact with no CRM record is decided by its outreach status and the suppression list
     */
    public function __construct(
        public readonly bool $mapped,
        public readonly string $email,
        public readonly array $entities,
        public readonly string $detail,
        public readonly bool $consentFromOutreachOnly = false,
    ) {
    }

    public static function unmapped(string $why, string $email = ''): self
    {
        return new self(false, $email, [], $why);
    }
}
