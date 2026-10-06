<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Turns an event into the words a person reads.
 *
 * TWO SUBSTITUTION PASSES, IN THIS ORDER AND NOT THE OTHER:
 *   1. {term:key}    - the tenant's word for a thing
 *   2. {payload.key} - facts from the event
 *
 * TERMINOLOGY FIRST, PAYLOAD SECOND. DATA GOES IN LAST AND NOTHING READS IT.
 *
 * The first version of this class had it the other way round, and the X-06 proof
 * caught it: a task titled literally "{term:competency}" would have had its title
 * EXPANDED by the terminology pass, because payload values were already sitting in
 * the string when that pass ran. Harmless in that example and not harmless in
 * general - it is tenant data reaching a template engine, which is the shape every
 * template injection has.
 *
 * Running terminology against the TEMPLATE ONLY means that by the time any payload
 * value exists in the string, every pass that could interpret it has finished.
 *
 * There is a real cost, accepted knowingly: a payload value cannot contain a term
 * placeholder. Nothing needs that, and a template that did would be asking a
 * tenant's data to decide its own wording.
 *
 * A MISSING PAYLOAD KEY RENDERS AS AN EM DASH, NOT AS THE PLACEHOLDER.
 * "Reason given: {payload.approve_remarks}" reaching a human is worse than
 * "Reason given: —", and the notification still says the useful part.
 */
class NotificationComposer
{
    public function __construct(private TerminologyService $terminology)
    {
    }

    /**
     * @return array{subject:string, body:string, action_url:?string}|null
     *         null when no active template exists - which is how an event with no
     *         template stays silent instead of sending an empty message.
     */
    public function compose(object $event, string $channel, int $tenant, string $locale = 'en'): ?array
    {
        $tpl = DB::table('g2g_notification_template')
            ->where('event_type', $event->type)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (!$tpl) {
            return null;
        }

        $payload = $this->payloadFor($event);
        $terms   = $this->terminology->map($tenant, $locale);

        // PASS 1 — terminology, against the template and nothing else.
        $subject = $this->terminology->apply($tpl->subject, $terms);
        $body    = $this->terminology->apply($tpl->body, $terms);

        // PASS 2 — payload. Data lands after every pass that could read it.
        $subject = $this->substitutePayload($subject, $payload);
        $body    = $this->substitutePayload($body, $payload);
        // An action path never carries terminology - it is a URL, not prose.
        $url     = $tpl->action_path ? $this->substitutePayload($tpl->action_path, $payload) : null;

        return [
            'subject'    => $this->clip($subject, 255),
            'body'       => $body,
            // An action path still holding an unresolved placeholder points nowhere;
            // send the notification without a link rather than with a broken one.
            'action_url' => ($url !== null && !str_contains($url, '{')) ? $this->clip($url, 255) : null,
        ];
    }

    private function payloadFor(object $event): array
    {
        $decoded = is_array($event->payload)
            ? $event->payload
            : json_decode((string) ($event->payload ?? ''), true);

        $payload = is_array($decoded) ? $decoded : [];

        // Event columns are addressable as payload keys so a template can say
        // {payload.entity_id} without every emitter having to duplicate it.
        $payload += [
            'entity_id'   => $event->entity_id,
            'entity_type' => $event->entity_type,
            'actor_id'    => $event->actor_id,
        ];

        return $payload;
    }

    private function substitutePayload(string $text, array $payload): string
    {
        return preg_replace_callback(
            '/\{payload\.([a-z0-9_]+)\}/i',
            function ($m) use ($payload) {
                $v = $payload[$m[1]] ?? null;
                if ($v === null || $v === '' || is_array($v)) {
                    return '—';
                }
                return (string) $v;
            },
            $text
        );
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
