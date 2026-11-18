<?php

namespace App\Domain\Ask;

use App\Support\RoleKey;

/**
 * The rights of the Ask chat (slice 8). Two layers, both real:
 *
 *   1. ASKING at all: the route needs VIEW on any one of the rows in ASK_RIGHT: the row the old CRM chat (`POST /api/crm/ai-chat`) is gated by, or the row of a
 *      kind of record Ask can read (see the constant for why the old row alone is not enough). It only opens the door.
 *   2. READING a kind of record: every tool re-checks the right of the screen that record lives on, per question, for the caller's profile. A person who
 *      may ask but cannot open Leads gets "not permitted" for leads in the answer, whatever the model asks for.
 *
 * The links are exactly the ones the record routes are gated by (routes/api.php): leads `menuright:201` (that row's access_link), contacts and organisations
 * accept the Marketing OR the Support row, opportunities and products one row each. Signals mirror ModuleGate::signals('/signals', 'view', 'read'): the
 * administrator, HR and manager roles while signals.permissions.use_menu_rights is off, the menu right on /signals once it is on.
 */
final class AskRights
{
    /**
     * The door: VIEW on the row the old CRM chat (`POST /api/crm/ai-chat`) is gated by (Campaigns) OR on the row of any kind of record Ask can read, in the
     * `platformright:` argument's own syntax (`|` = any one of them). Why not the Campaigns row alone: it is a hidden menu row (status 0) that tenant 6 on dev holds
     * no rights row for at all (verified, `_evidence/s8/task5-ask-door-rights-on-dev-read-only-2026-11-04.txt`), so the old chat answers 403 to every person of the
     * pilot organisation, the Admin included, and Ask gated the same way would be dead there too. A person who can open at least one kind of record may ask; what
     * a question may READ is checked per kind inside, so the door adds no reading, only a question that costs one model call. One constant: narrow or widen it here.
     */
    public const ASK_RIGHT = '/module/crm/marketing/campaigns|/module/crm/marketing/leads|/module/crm/marketing/contacts|/module/crm/support/contacts|/module/crm/marketing/organizations|/module/crm/support/organizations|/module/crm/sales/opportunities|/module/crm/sales/products';

    /** @var array<string, list<string>> record type => the menu rows whose VIEW opens that kind of record */
    public const RECORD_LINKS = [
        'lead' => ['/module/crm/marketing/leads'],
        'contact' => ['/module/crm/marketing/contacts', '/module/crm/support/contacts'],
        'organization' => ['/module/crm/marketing/organizations', '/module/crm/support/organizations'],
        'opportunity' => ['/module/crm/sales/opportunities'],
        'product' => ['/module/crm/sales/products'],
    ];

    /** The roles `ModuleGate::signals(..., 'read')` lets in while the Signals menu rights are not switched on (the route vocabulary of RoleKey::ALIASES). */
    public const SIGNALS_READ_PROFILES = ['admin', 'hr', 'manager'];

    /** The record kinds the person can name, in the words of a sentence. */
    private const PLURAL = [
        'lead' => 'leads', 'contact' => 'contacts', 'organization' => 'organisations', 'opportunity' => 'opportunities', 'signal' => 'signals', 'product' => 'products',
    ];

    public static function plural(string $type): string
    {
        return self::PLURAL[$type] ?? $type . 's';
    }

    /** May this person open records of this kind ('lead', 'contact', 'organization', 'opportunity', 'product')? An unknown kind is never allowed. */
    public static function canRead(AskCaller $caller, string $type): bool
    {
        if ($type === 'signal') {
            return self::canReadSignals($caller);
        }
        $links = self::RECORD_LINKS[$type] ?? null;

        return $links !== null && $caller->can($links, 'view');
    }

    public static function canReadSignals(AskCaller $caller): bool
    {
        if (! config('signals.permissions.use_menu_rights')) {
            return RoleKey::satisfies(RoleKey::forProfileId($caller->profileId), self::SIGNALS_READ_PROFILES);
        }

        return $caller->can(['/signals'], 'view');
    }

    public static function canAsk(AskCaller $caller): bool
    {
        return $caller->can(explode('|', self::ASK_RIGHT), 'view');
    }
}
