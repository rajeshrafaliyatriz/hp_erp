<?php

namespace App\Domain\OutreachGate;

use App\Domain\Gtm\GtmOutreachMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a message into the Draft the gate looks at. Read only. `find()` is the lookup for the preview endpoint: it carries the organisation, so another organisation's
 * message is simply not found.
 */
final class DraftFactory
{
    public function gtm(GtmOutreachMessage $m): Draft
    {
        $product = (int) $m->getAttribute('product_master_id');

        return new Draft(Draft::GTM, (int) $m->id, (int) $m->sub_institute_id, (string) $m->subject, (string) $m->body, '', (int) $m->contact_id, null, null, $product > 0 ? [$product] : []);
    }

    public function crm(object $email, string $linkedType, int $linkedId): Draft
    {
        return new Draft(Draft::CRM, (int) $email->id, (int) $email->sub_institute_id, (string) $email->subject, (string) $email->body, (string) $email->to_address, null, strtoupper($linkedType), $linkedId, []);
    }

    /** The draft of one message of this organisation, or null when there is no such message (or it is not an outgoing draft). */
    public function find(int $tenant, string $table, int $id): ?Draft
    {
        if ($tenant <= 0 || $id <= 0) {
            return null;
        }

        if ($table === Draft::GTM) {
            $m = GtmOutreachMessage::query()->where('sub_institute_id', $tenant)->whereKey($id)->first();

            return $m === null ? null : $this->gtm($m);
        }

        if ($table === Draft::CRM) {
            $email = DB::table('crm_emails')->where('id', $id)->where('sub_institute_id', $tenant)->where('direction', 'outbound')->first();
            if ($email === null) {
                return null;
            }
            // The link the mailer would be handed: the first one written (compose and the campaign send write the primary link first).
            $link = Schema::hasTable('crm_email_links')
                ? DB::table('crm_email_links')->where('email_id', $id)->where('sub_institute_id', $tenant)->orderBy('id')->first(['linked_type', 'linked_id'])
                : null;

            return $this->crm($email, (string) ($link->linked_type ?? ''), (int) ($link->linked_id ?? 0));
        }

        return null;
    }
}
