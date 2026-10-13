<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\Outreach\InboundSignature;
use App\Domain\Gtm\Outreach\ReplyBounceProcessor;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Reply / bounce intake.
 *
 *  webhook()  PUBLIC, authenticated only by the HMAC signature (InboundSignature). With no secret configured it
 *             refuses everything. It accepts ONE normalised JSON shape (below); whatever forwards mail events
 *             (an inbound-email service, a mailbox forwarder) must send that shape, signed.
 *  record()   an Administrator recording a reply or bounce by hand, through the SAME processor.
 *
 * Normalised payload:
 *   { "provider": "mailgun", "event_id": "unique-per-event", "type": "reply|bounce|complaint",
 *     "bounce_type": "hard|soft", "in_reply_to": "<id>", "references": ["<id>"], "original_message_id": "<id>",
 *     "from_email": "...", "recipient": "...", "subject": "...", "text": "...", "reason": "...", "occurred_at": "..." }
 */
class InboundController extends Controller
{
    use ResolvesApiIdentity;

    public function webhook(Request $request, ReplyBounceProcessor $processor): JsonResponse
    {
        $raw = (string) $request->getContent();
        $check = InboundSignature::verify($raw, $request->header('X-GTM-Signature'), (string) config('gtm.inbound.secret'));
        if (! $check['ok']) {
            Cache::increment('gtm:inbound:rejected:'.now()->format('YmdH'));
            Cache::put('gtm:inbound:last_rejected', ['at' => now()->toDateTimeString(), 'reason' => $check['reason']], 86400);
            Log::warning('GTM inbound webhook rejected', ['reason' => $check['reason'], 'ip' => $request->ip()]);

            // 503 when we cannot authenticate anyone at all (an operator problem); 401 for a bad request.
            return response()->json(['status' => 0, 'message' => $check['reason'] === 'not_configured' ? 'Inbound processing is not configured.' : 'Invalid signature.'], $check['reason'] === 'not_configured' ? 503 : 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['status' => 0, 'message' => 'The body is not valid JSON.'], 400);
        }
        $n = $this->normalise($payload);
        if (is_string($n)) {
            Cache::put('gtm:inbound:last_malformed', ['at' => now()->toDateTimeString(), 'reason' => $n], 86400);

            return response()->json(['status' => 0, 'message' => $n], 422);
        }
        $r = $processor->process($n);

        // 200 for everything we understood (even duplicates/unmatched) so the sender does not retry forever.
        return response()->json(['status' => 1, 'data' => ['result' => $r['status'], 'inbound_id' => $r['inbound_id'], 'detail' => $r['detail']]], $r['status'] === 'processed' ? 200 : 202);
    }

    /** An administrator records a reply or a bounce. The tenant is the caller's own, enforced by the contact lookup. */
    public function record(Request $request, int $contactId, ReplyBounceProcessor $processor): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $contact = DB::table('gtm_contacts')->where('sub_institute_id', $t)->whereNull('deleted_at')->find($contactId);
        if (! $contact) {
            return response()->json(['status' => 0, 'message' => 'Contact not found'], 404);
        }
        $v = Validator::make($request->all(), ['type' => 'required|in:reply,bounce,complaint', 'bounce_type' => 'required_if:type,bounce|in:hard,soft', 'subject' => 'nullable|string|max:255', 'text' => 'nullable|string|max:20000', 'reason' => 'nullable|string|max:255']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        // Matched by the contact's own address, scoped to this tenant by construction: pass the id through the address match is not
        // enough (an address can exist in several tenants), so the manual path attaches the contact directly.
        $r = $processor->processForContact($contact, [
            'provider' => 'manual', 'event_id' => 'manual-'.bin2hex(random_bytes(8)), 'type' => (string) $request->input('type'), 'bounce_type' => $request->input('bounce_type'),
            'subject' => $request->input('subject'), 'text' => $request->input('text'), 'reason' => $request->input('reason'), 'occurred_at' => now()->toDateTimeString(),
        ], $identity['user_id']);

        return response()->json(['status' => 1, 'data' => $r]);
    }

    /** @param array<string, mixed> $p @return array<string, mixed>|string the normalised event, or the reason it is unusable */
    private function normalise(array $p): array|string
    {
        $v = Validator::make($p, [
            'provider' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'], 'event_id' => 'required|string|max:191', 'type' => 'required|in:reply,bounce,complaint',
            'bounce_type' => 'nullable|in:hard,soft', 'in_reply_to' => 'nullable|string|max:300', 'references' => 'nullable|array|max:50', 'references.*' => 'string|max:300',
            'original_message_id' => 'nullable|string|max:300', 'from_email' => 'nullable|email|max:191', 'recipient' => 'nullable|email|max:191', 'subject' => 'nullable|string|max:500',
            'text' => 'nullable|string|max:200000', 'reason' => 'nullable|string|max:500', 'occurred_at' => 'nullable|date',
        ]);
        if ($v->fails()) {
            return 'Malformed event: '.$v->errors()->first();
        }
        $d = $v->validated();
        if (($d['type'] ?? '') === 'bounce') {
            $d['bounce_type'] ??= 'hard';
        }
        if (empty($d['in_reply_to']) && empty($d['original_message_id']) && empty($d['references']) && empty($d['from_email']) && empty($d['recipient'])) {
            return 'Malformed event: nothing identifies which message or contact this is about.';
        }

        return $d;
    }
}
