<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\Gtm\OutreachMailer;
use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\RecipientMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fails when an outreach contact (gtm_contacts) of the organisation with this address is bounced, unsubscribed or do_not_contact (OutreachMailer::BLOCKED_CONTACT). It looks
 * the contact up by ADDRESS, not by id, so a CRM email to somebody who opted out through outreach is stopped too, and so is a GTM message whose address was later given to
 * another outreach contact. No address, no answer: it fails. Read only.
 */
final class ContactStatusCheck implements Check
{
    public function __construct(private readonly RecipientMap $map)
    {
    }

    public function key(): string
    {
        return 'contact_status';
    }

    public function label(): string
    {
        return 'Outreach contact is reachable';
    }

    public function run(Draft $draft): CheckResult
    {
        $r = $this->map->for($draft);
        if ($r->email === '') {
            return CheckResult::fail($this->key(), $this->label(), 'The outreach contact status could not be checked because there is no valid address.');
        }
        if (! Schema::hasTable('gtm_contacts')) {
            return CheckResult::pass($this->key(), $this->label(), 'This host has no outreach contacts.');
        }

        $row = DB::table('gtm_contacts')->where('sub_institute_id', $draft->tenant)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$r->email])
            ->whereIn('status', OutreachMailer::BLOCKED_CONTACT)->orderBy('id')->first(['id', 'status']);

        return $row === null
            ? CheckResult::pass($this->key(), $this->label(), 'No outreach contact with this address is bounced, unsubscribed or do-not-contact.')
            : CheckResult::fail($this->key(), $this->label(), "Outreach contact #{$row->id} with this address is marked {$row->status}.");
    }
}
