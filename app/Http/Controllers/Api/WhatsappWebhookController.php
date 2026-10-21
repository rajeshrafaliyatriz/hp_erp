<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Whatsapp\WhatsappWebhook;
use Illuminate\Http\Request;

/**
 * The address Meta calls for the WhatsApp Cloud API: `GET|POST /api/whatsapp/webhook`.
 *
 * Public on purpose (Meta cannot hold a login), so everything it accepts is authenticated inside
 * WhatsappWebhook: a verify token on subscription, an HMAC signature on every report. See that class.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(private readonly WhatsappWebhook $webhook)
    {
    }

    /** Subscription handshake: echo `hub.challenge` only for a verify token an organisation saved. */
    public function verify(Request $request)
    {
        $challenge = $this->webhook->verify(
            $request->query('hub_mode', $request->query('hub.mode')),
            $request->query('hub_verify_token', $request->query('hub.verify_token')),
            $request->query('hub_challenge', $request->query('hub.challenge'))
        );

        return $challenge === null
            ? response('Verification failed.', 403)
            : response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** Status reports. Always 200 once handled (a non-200 makes Meta retry); only authenticated ones change anything. */
    public function receive(Request $request)
    {
        $result = $this->webhook->handle($request->getContent(), $request->header('X-Hub-Signature-256'));

        return response()->json(['received' => true, 'updated' => $result['updated']], $result['accepted'] ? 200 : 403);
    }
}
