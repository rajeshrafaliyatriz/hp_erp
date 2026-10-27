<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Leads\LeadReplyProcessor;
use App\Domain\Gtm\Outreach\InboundSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * CRM reply / bounce intake: PUBLIC, authenticated only by an HMAC signature (the same InboundSignature the GTM webhook uses,
 * header X-CRM-Signature, secret CRM_INBOUND_WEBHOOK_SECRET). With no secret configured it refuses everything.
 *
 * It accepts ONE normalised JSON shape; whatever forwards mail events (an inbound-email service, a mailbox forwarder) must send
 * that shape, signed:
 *   { "provider": "mailgun", "event_id": "unique-per-event", "type": "reply|bounce|complaint", "bounce_type": "hard|soft",
 *     "in_reply_to": "<id>", "references": ["<id>"], "original_message_id": "<id>", "message_id": "<the reply's own id>",
 *     "from_email": "...", "recipient": "...", "subject": "...", "text": "...", "reason": "...", "auto_reply": false, "occurred_at": "..." }
 * The tenant is never read from the payload.
 */
class InboundController extends Controller
{
    public function webhook(Request $request, LeadReplyProcessor $processor): JsonResponse
    {
        $raw = (string) $request->getContent();
        $check = InboundSignature::verify($raw, $request->header('X-CRM-Signature'), (string) config('crm.inbound.secret'));
        if (! $check['ok']) {
            Cache::increment('crm:inbound:rejected:'.now()->format('YmdH'));
            Cache::put('crm:inbound:last_rejected', ['at' => now()->toDateTimeString(), 'reason' => $check['reason']], 86400);
            Log::warning('CRM inbound webhook rejected', ['reason' => $check['reason'], 'ip' => $request->ip()]);

            return response()->json(['status' => 0, 'message' => $check['reason'] === 'not_configured' ? 'Inbound processing is not configured.' : 'Invalid signature.'], $check['reason'] === 'not_configured' ? 503 : 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['status' => 0, 'message' => 'The body is not valid JSON.'], 400);
        }
        $n = $this->normalise($payload);
        if (is_string($n)) {
            return response()->json(['status' => 0, 'message' => $n], 422);
        }
        $r = $processor->process($n);

        // 200/202 for everything we understood (even duplicates/unmatched) so the sender does not retry forever.
        return response()->json(['status' => 1, 'data' => ['result' => $r['status'], 'inbound_id' => $r['inbound_id'], 'detail' => $r['detail']]], $r['status'] === 'processed' ? 200 : 202);
    }

    /** @param array<string, mixed> $p @return array<string, mixed>|string the normalised event, or the reason it is unusable */
    private function normalise(array $p): array|string
    {
        $v = Validator::make($p, [
            'provider' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'], 'event_id' => 'required|string|max:191', 'type' => 'required|in:reply,bounce,complaint',
            'bounce_type' => 'nullable|in:hard,soft', 'in_reply_to' => 'nullable|string|max:300', 'references' => 'nullable|array|max:50', 'references.*' => 'string|max:300',
            'original_message_id' => 'nullable|string|max:300', 'message_id' => 'nullable|string|max:300', 'from_email' => 'nullable|email|max:191', 'recipient' => 'nullable|email|max:191',
            'subject' => 'nullable|string|max:500', 'text' => 'nullable|string|max:200000', 'reason' => 'nullable|string|max:500', 'auto_reply' => 'nullable|boolean', 'occurred_at' => 'nullable|date',
        ]);
        if ($v->fails()) {
            return 'Malformed event: '.$v->errors()->first();
        }
        $d = $v->validated();
        if (($d['type'] ?? '') === 'bounce') {
            $d['bounce_type'] ??= 'hard';
        }
        if (empty($d['in_reply_to']) && empty($d['original_message_id']) && empty($d['references']) && empty($d['from_email']) && empty($d['recipient'])) {
            return 'Malformed event: nothing identifies which message or lead this is about.';
        }

        return $d;
    }
}
