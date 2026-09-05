<?php

namespace App\Services\Talent;

use App\Http\Controllers\Api\Onboarding\Concerns\ResolvesOnboardingContext;
use App\Models\Onboarding\OnboardingJourney;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The ONE way an onboarding journey is created.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Journey creation lived as a private method on OnboardingJourneyController, so
 * the only way to start a journey was for a human to open a dialog. Accepting an
 * offer emits `employee.hired`, and that event had NO CONSUMER at all - the
 * catalogue says so in writing (EventCatalogue: verdict DEFERRED, "Its only
 * declared consumer, OnboardingLauncher, was never written").
 *
 * So the chain Recruitment -> Onboarding was severed at exactly one joint: the
 * hire happened, the event was recorded, and nobody read it. A recruiter
 * accepted an offer, then walked to another screen and re-selected the same
 * offer from a dropdown to start onboarding. On the candidate self-service path
 * - where they accept their own offer through a magic link - there is no
 * operator on a screen at all, so nothing started until somebody noticed.
 *
 * Two callers now share this: the controller (a person choosing an offer) and
 * OnboardingLauncher (the reactor, automatically on hire). Extracted rather than
 * duplicated for the reason EmployeeFactory exists - the audit found four
 * writers of employee rows that had drifted apart, and this is the same shape.
 */
class OnboardingJourneyFactory
{
    // nextJourneyCode(), logOnboardingActivity() and DEFAULT_STAGES already live
    // in this trait, so nothing had to move to make them reachable here.
    use ResolvesOnboardingContext;

    public function __construct(private EventRecorder $events)
    {
    }

    /**
     * Create a journey and seed its stages. Returns the journey.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(int $tenant, ?int $actorId, array $attributes): OnboardingJourney
    {
        $seedStages = $attributes['seed_stages'] ?? true;
        unset($attributes['seed_stages']);

        return DB::transaction(function () use ($tenant, $actorId, $attributes, $seedStages) {
            $journey = OnboardingJourney::create(array_merge($attributes, [
                'sub_institute_id'    => $tenant,
                'journey_code'        => $this->nextJourneyCode($tenant),
                'stage'               => $attributes['stage'] ?? 'preboarding',
                'status'              => $attributes['status'] ?? 'not-started',
                'confirmation_status' => 'pending',
                'created_by'          => $actorId,
                'updated_by'          => $actorId,
            ]));

            if ($seedStages) {
                $this->seedStages($journey, $actorId);
                $this->seedTasks($journey, $actorId);
            }

            $this->logOnboardingActivity(
                $tenant,
                $actorId,
                'created_journey',
                'started onboarding journey ' . $journey->journey_code,
                'journey',
                $journey->id,
                $journey->candidate_name,
                null,
                $journey->id
            );

            /*
             * STARTING A JOURNEY NOW TELLS THE REST OF THE SYSTEM.
             *
             * It emitted nothing at all, so onboarding was the end of the chain:
             * whatever should follow a person's induction - a first-week task
             * list, a manager notification, a buddy assignment - had no way to
             * know it had begun. The activity log above is a human-readable
             * trail on ONE screen; it is not an event and nothing consumes it.
             *
             * `employee_id` is included and may be null. A journey started from
             * an accepted offer is born attached to the person; one typed in
             * ahead of acceptance is not, and a consumer needs to be able to
             * tell those apart rather than guess.
             *
             * No consumer exists yet - deliberately. The event is recorded so
             * the fact is on the record from today; a reactor added later can
             * replay it. Writing the consumer first is how `employee.hired`
             * ended up emitted-and-ignored for months (F-77).
             */
            $this->events->record(
                'onboarding.journey_started',
                $tenant,
                'onboarding_journey',
                (int) $journey->id,
                $actorId,
                [
                    'journey_code'  => $journey->journey_code,
                    'employee_id'   => $journey->employee_id ? (int) $journey->employee_id : null,
                    'offer_id'      => $journey->offer_id ? (int) $journey->offer_id : null,
                    'department_id' => $journey->department_id ? (int) $journey->department_id : null,
                    'position'      => $journey->position,
                    'joining_date'  => optional($journey->joining_date)->toDateString(),
                ]
            );

            return $journey;
        });
    }

    /**
     * Start a journey from an accepted offer, or return the one that exists.
     *
     * ── IDEMPOTENT ON PURPOSE ───────────────────────────────────────────────
     *
     * Two callers can reach this for the same offer: the reactor when
     * `employee.hired` is delivered, and a recruiter who did not know that had
     * already happened. Returning the existing journey rather than refusing (or
     * creating a second) is what lets both paths coexist without either needing
     * to know about the other.
     *
     * @return array{journey: ?OnboardingJourney, created: bool, reason: ?string}
     */
    public function fromOffer(object $offer, int $tenant, ?int $actorId): array
    {
        $existing = DB::table('talent_onboarding_journeys')
            ->where('sub_institute_id', $tenant)
            ->where('offer_id', $offer->id)
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($existing) {
            return [
                'journey' => OnboardingJourney::find($existing->id),
                'created' => false,
                'reason'  => 'already_exists',
            ];
        }

        /*
         * If accepting the offer already created the employee, link them now.
         *
         * It matters beyond tidiness: probation confirmation mirrors onto
         * tbluser and a termination opens an exit case, and BOTH are skipped
         * when employee_id is null. A journey with no employee is a journey
         * whose outcome goes nowhere.
         */
        $acceptedEmployeeId = DB::table('talent_offer_acceptances')
            ->where('offer_id', $offer->id)
            ->where('sub_institute_id', $tenant)
            ->where('decision', 'accepted')
            ->whereNull('deleted_at')
            ->value('accepted_employee_id');

        $name = trim(($offer->first_name ?? '') . ' ' . ($offer->last_name ?? ''));

        $journey = $this->create($tenant, $actorId, [
            'employee_id'     => $acceptedEmployeeId ? (int) $acceptedEmployeeId : null,
            'offer_id'        => (int) $offer->id,
            'application_id'  => $offer->application_id ? (int) $offer->application_id : null,
            'candidate_name'  => $name !== '' ? $name : 'Candidate #' . ($offer->application_id ?? $offer->id),
            'candidate_email' => $offer->email ?? null,
            'candidate_phone' => $offer->mobile ?? null,
            'position'        => $offer->position ?? null,
            'location'        => $offer->current_location ?? null,
            'department_id'   => !empty($offer->department_id) ? (int) $offer->department_id : null,
            'joining_date'    => $offer->start_date ?? null,
            'manager_id'      => !empty($offer->reportmanager) ? (int) $offer->reportmanager : null,
        ]);

        return ['journey' => $journey, 'created' => true, 'reason' => null];
    }

    /**
     * The onboarding checklist, grouped by workstream.
     *
     * ── THE ONE MISSING PIECE ───────────────────────────────────────────────
     *
     * Everything downstream of this was already built: `category` on the task
     * doubles as the workstream key, `GET /api/onboarding/workstreams` rolls it
     * up with a derived status, and the Preboarding tab renders all five cards
     * and uses them as filters. Nothing ever wrote a task, so every card read
     * "No tasks yet / Not Started" on every journey in every organisation - a
     * feature that looked present and did nothing.
     *
     * This is deliberately the same shape OFFBOARDING already uses:
     * OffboardingCaseFactory::DEFAULT_CLEARANCE_TASKS is applied to every new
     * exit case. The exit door had a default IT/Finance/HR checklist and the
     * entry door had none.
     *
     * ── DUE DATES ARE OFFSETS, AND MAY BE NULL ─────────────────────────────
     *
     * Anchored to `joining_date`, negative before / positive after, which is the
     * model SuccessFactors uses. A journey with NO joining date gets NULL due
     * dates rather than offsets from today: an invented deadline shows red on a
     * dashboard for a reason nobody can trace, and "no date yet" is the truth.
     */
    private function seedTasks(OnboardingJourney $journey, ?int $actorId): void
    {
        $joining = $journey->joining_date;
        $now = now();
        $rows = [];

        foreach (self::ONBOARDING_TASK_TEMPLATE as $index => $task) {
            $rows[] = [
                'journey_id'       => $journey->id,
                'sub_institute_id' => $journey->sub_institute_id,
                'title'            => $task['title'],
                'category'         => $task['category'],
                /*
                 * owner_label only. `owner_id` stays NULL because HR completes
                 * every task on this module - there is no IT or Finance login
                 * gate here, and assigning to a role nobody holds would strand
                 * the task. The label tells HR who to chase.
                 */
                'owner_id'         => null,
                'owner_label'      => $task['owner_label'],
                'due_date'         => $joining
                    ? $joining->copy()->addDays($task['day_offset'])->toDateString()
                    : null,
                'status'           => 'pending',
                'sort_order'       => $index,
                'created_by'       => $actorId,
                'updated_by'       => $actorId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('talent_onboarding_tasks')->insert($rows);
    }

    /**
     * The seven default steps, anchored to whatever dates the journey knows.
     *
     * Only stages the journey actually has a date for get one - the rest are left
     * blank for HR to fill in, rather than being back-filled with invented offsets.
     */
    private function seedStages(OnboardingJourney $journey, ?int $actorId): void
    {
        $joining = $journey->joining_date;
        $now = now();
        $rows = [];

        foreach (self::DEFAULT_STAGES as $index => $stage) {
            [$start, $end] = match ($stage['stage_key']) {
                'offer_accepted'   => [optional($journey->created_at)->toDateString() ?? $now->toDateString(), null],
                'preboarding'      => [optional($journey->created_at)->toDateString() ?? $now->toDateString(), optional($joining)->toDateString()],
                'first_day'        => [optional($joining)->toDateString(), optional($joining)->toDateString()],
                'probation'        => [optional($journey->probation_start)->toDateString(), optional($journey->probation_end)->toDateString()],
                'confirmation'     => [optional($journey->probation_end)->toDateString(), null],
                default            => [null, null],
            };

            $rows[] = [
                'journey_id'       => $journey->id,
                'sub_institute_id' => $journey->sub_institute_id,
                'stage_key'        => $stage['stage_key'],
                'title'            => $stage['title'],
                'start_date'       => $start,
                'end_date'         => $end,
                'status'           => $stage['stage_key'] === 'offer_accepted' ? 'completed' : 'pending',
                'completed_at'     => $stage['stage_key'] === 'offer_accepted' ? $now : null,
                'sort_order'       => $index,
                'created_by'       => $actorId,
                'updated_by'       => $actorId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('talent_onboarding_journey_stages')->insert($rows);
    }
}
