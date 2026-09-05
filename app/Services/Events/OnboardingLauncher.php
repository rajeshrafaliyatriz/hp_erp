<?php

namespace App\Services\Events;

use App\Services\Talent\OnboardingJourneyFactory;
use Illuminate\Support\Facades\DB;

/**
 * Starts onboarding when somebody is hired. The consumer `employee.hired` never had.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────
 *
 * `EmployeeFactory` emits `employee.hired` on offer acceptance, and NOTHING read
 * it. EventCatalogue filed the event under NOT_SHIPPED with the verdict
 * DEFERRED and the reason, in writing:
 *
 *     "Its only declared consumer, OnboardingLauncher, was never written."
 *
 * So the recruitment-to-onboarding chain was severed at one joint. The hire was
 * recorded correctly - tbluser row, application moved to Hired, event written -
 * and then the journey had to be started by a human walking to another screen
 * and re-selecting the same offer from a dropdown. On the candidate
 * self-service path there is no operator on a screen at all, so nothing started
 * until somebody noticed.
 *
 * ── WHY IT REUSES THE FACTORY ───────────────────────────────────────────────
 *
 * The journey logic is OnboardingJourneyFactory, shared with the controller,
 * rather than copied here. A second implementation of "what a new journey looks
 * like" is exactly the drift that produced four disagreeing writers of employee
 * rows before EmployeeFactory existed.
 *
 * ── DELIVERY IS NOT AUTOMATIC ───────────────────────────────────────────────
 *
 * EventRecorder only INSERTs into g2g_event; it does not fan out. Delivery is
 * the scheduled `events:react` command, whose reactor list is the hardcoded
 * const ReactEvents::REACTORS. This class is inert until it appears in that
 * array - which is the change that ships alongside it.
 */
class OnboardingLauncher
{
    public const CONSUMER = 'onboarding_launcher';

    public const HANDLES = [
        'employee.hired',
    ];

    public function __construct(private OnboardingJourneyFactory $journeys)
    {
    }

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        /*
         * FIRST LINE, matching every other reactor. A journey is something a
         * person sees and works through; a replay must not create a second one
         * for every hire in history.
         */
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (!$this->handles((string) $event->type)) {
            return;
        }

        $done = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($done) {
            return;
        }

        $tenant = (int) $event->sub_institute_id;
        $payload = $this->payload($event);
        $offerId = isset($payload['offer_id']) ? (int) $payload['offer_id'] : 0;

        /*
         * NO OFFER MEANS NO JOURNEY, AND THAT IS A RESULT, NOT A FAILURE.
         *
         * `employee.hired` is also emitted for employees created directly in the
         * HR directory, where there is no offer, no candidate record and nothing
         * to onboard from. Recorded as `skipped` with the reason so that "this
         * hire had no offer" and "the launcher is broken" never look the same in
         * the ledger.
         */
        if ($offerId <= 0) {
            $this->ledger($event, 'skipped', 'no offer_id on the hire (direct entry, not an offer acceptance)');

            return;
        }

        $offer = DB::table('talent_offers as o')
            ->leftJoin('talent_job_applications as a', function ($join) use ($tenant) {
                $join->on('a.id', '=', 'o.application_id')->where('a.sub_institute_id', '=', $tenant);
            })
            ->where('o.id', $offerId)
            ->where('o.sub_institute_id', $tenant)
            ->first([
                'o.id', 'o.application_id', 'o.position', 'o.start_date', 'o.reportmanager',
                'a.first_name', 'a.last_name', 'a.email', 'a.mobile', 'a.current_location',
            ]);

        if (!$offer) {
            $this->ledger($event, 'skipped', 'offer ' . $offerId . ' not found in this tenant');

            return;
        }

        // The department is on the hire, not the offer - EmployeeFactory resolves
        // it while creating the employee, so the event carries the resolved value.
        $offer->department_id = $payload['department_id'] ?? null;

        try {
            /*
             * The actor is NULL, meaning SYSTEM. That is a real value, not an
             * unknown one: nobody pressed a button, the hire did this. Attributing
             * it to whoever happened to accept the offer would put a person's name
             * on an action they did not take.
             */
            $result = $this->journeys->fromOffer($offer, $tenant, null);
        } catch (\Throwable $e) {
            $this->ledger($event, 'failed', mb_substr($e->getMessage(), 0, 500));

            return;
        }

        $this->ledger(
            $event,
            'done',
            $result['created']
                ? null
                : 'journey already existed (' . ($result['reason'] ?? 'unknown') . ')'
        );
    }

    /** @return array<string, mixed> */
    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }

        $decoded = json_decode((string) ($event->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
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
