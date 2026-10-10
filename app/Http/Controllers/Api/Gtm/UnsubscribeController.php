<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\GtmOutreachMessage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One-click unsubscribe from a GTM email. PUBLIC (a recipient is not a G2G user) and protected
 * by a signed URL, so only a link we issued for that contact works. Idempotent. It records the
 * opt-out on the contact and cancels everything not yet sent to them; nothing is deleted.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, int $contact): Response
    {
        $c = GtmContact::find($contact);
        if (! $c) {
            return $this->page('This link is no longer valid.', 404);
        }

        if ($c->status !== 'unsubscribed') {
            $c->forceFill(['status' => 'unsubscribed'])->save();
            GtmOutreachMessage::where('sub_institute_id', $c->sub_institute_id)->where('contact_id', $c->id)
                ->whereIn('status', ['draft', 'pending_approval', 'approved', 'rejected', 'failed'])
                ->update(['status' => 'cancelled', 'decision_note' => 'Contact unsubscribed', 'updated_at' => now()]);
            GtmActivity::create([
                'sub_institute_id' => $c->sub_institute_id, 'account_id' => $c->account_id, 'contact_id' => $c->id, 'type' => 'system',
                'subject' => 'Unsubscribed from outreach (email link)', 'occurred_at' => now(),
            ]);
            GtmAudit::record('gtm.contact.unsubscribed', (int) $c->sub_institute_id, 'gtm_contacts', $c->id, null, ['via' => 'email_link']);
        }

        return $this->page('You have been unsubscribed. You will not receive further emails from us.');
    }

    private function page(string $text, int $status = 200): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem"><p>'.e($text).'</p></body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
