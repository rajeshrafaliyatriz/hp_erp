<?php

namespace App\Services\Events;

use App\Services\Events\Concerns\DrivesFromEventStore;
use App\Services\Notifications\NotificationSender;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REACTOR — the first one, and as of X-06 the first one that actually SENDS.
 * (`05-data-flow-contracts.md` §2.0, kind = R)
 *
 * IMPURE: it sends. Every dispatch is externally visible and cannot be undone by
 * truncating a table, which is the whole reason reactors are a separate category.
 *
 * TWO PROPERTIES THAT DEFINE A REACTOR, both enforced here rather than documented:
 *
 *   1. IT NEVER RUNS ON REPLAY. dispatch() calls ReplayMode::assertNotReplaying()
 *      before anything else, and that THROWS. A rebuild that reaches this line
 *      has a bug worse than the one being fixed.
 *
 *   2. ITS DISPATCH LEDGER IS PERMANENT. Rows in g2g_event_delivery for a reactor
 *      are NEVER cleared by a rebuild - they are the record that a real message
 *      was really sent. Clearing them would make the system willing to send it
 *      again.
 *
 * BOTH ARE NOW LOAD-BEARING RATHER THAN THEORETICAL. Until X-06 this class wrote
 * a log line, so a replay leak would have produced a duplicate log entry. It now
 * writes a person's inbox and, when the email channel is enabled, leaves the
 * building. The guard and the ledger are the only things standing between a
 * rebuild and 386 people being told the same thing twice.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SEVEN EVENT TYPES. It was six at X-06 and `certification.issued` came back when
 * X-11 shipped - the deferral had a TRIGGER, and the trigger fired. That is the
 * whole point of writing triggers down.
 *
 * The two still out are in EventCatalogue::NOT_NOTIFIED: one has no recipient that
 * exists in the data, one has no human who does anything.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class NotificationDispatcher
{
    use DrivesFromEventStore;

    public const CONSUMER = 'notification_dispatcher';

    /**
     * Events with a NAMED RECIPIENT and a NAMED ACTION. Kept in one place and
     * cross-checked against RecipientResolver by the smoke suite, so an event
     * cannot be listed here without someone to send it to.
     */
    public const NOTIFIES = [
        'task.rejected',
        'assessment.completed',
        'certification.expiring',
        'development_plan.approved',
        'employee.offboarded',
        'rights.changed',
        // X-11 un-defers this: CertificateIssuer now emits it.
        'certification.issued',
    ];

    public function __construct(
        private RecipientResolver $recipients,
        private NotificationSender $sender,
    ) {
    }

    public function handles(string $type): bool
    {
        return in_array($type, self::NOTIFIES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // FIRST LINE. Before any work, before any ledger write.
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (!$this->handles((string) $event->type)) {
            return;
        }

        // Idempotent per (event, consumer): a retry after a partial failure must
        // not send twice. The unique key on g2g_notification is what ultimately
        // enforces it per recipient; this is the cheap early exit.
        $already = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($already) {
            return;
        }

        $tenant = (int) $event->sub_institute_id;
        $people = $this->recipients->forEvent($event);

        // NO RECIPIENT IS A RESULT, NOT AN ERROR. A task rejected on a row whose
        // assignee was never set has nobody to tell. Recording it as `skipped`
        // rather than `done` keeps the two cases distinguishable in the ledger -
        // "we told nobody because there was nobody" is a different fact from "we
        // told everybody", and only one of them is worth investigating later.
        if ($people === []) {
            $this->ledger($event, 'skipped', 'no recipient resolved');
            Log::channel('single')->info('notification.no_recipient', [
                'event_id' => $event->id,
                'type'     => $event->type,
                'tenant'   => $tenant,
            ]);
            return;
        }

        $sent = 0;
        $failed = [];

        foreach ($people as $person) {
            try {
                $channels = $this->sender->send($event, $person, $tenant);
                if ($channels !== []) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $failed[] = $person['user_id'] . ': ' . $e->getMessage();
            }
        }

        // PARTIAL FAILURE IS RECORDED AS FAILURE. Marking a dispatch `done` when
        // one of three recipients threw would retire the event with a person
        // unnotified and no way to notice.
        $this->ledger(
            $event,
            $failed === [] ? 'done' : 'failed',
            $failed === [] ? null : implode(' | ', array_slice($failed, 0, 3))
        );

        Log::channel('single')->info('notification.dispatch', [
            'event_id'   => $event->id,
            'type'       => $event->type,
            'tenant'     => $tenant,
            'recipients' => count($people),
            'sent'       => $sent,
            'email_on'   => $this->sender->emailEnabled(),
        ]);
    }

    private function ledger(object $event, string $status, ?string $error): void
    {
        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            [
                'status'       => $status,
                'attempts'     => DB::raw('attempts + 1'),
                'last_error'   => $error,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
