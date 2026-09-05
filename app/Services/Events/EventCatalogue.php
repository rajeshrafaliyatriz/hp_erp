<?php

namespace App\Services\Events;

/**
 * THE EVENT CATALOGUE — `05-data-flow-contracts.md` §2.1 and §2.2, as code.
 *
 * It is here rather than in a table because these are DESIGN decisions, not
 * tenant data: which events exist, who consumes them, and whether a consumer may
 * run on replay. A tenant cannot make a reactor replayable.
 *
 * THREE INVARIANTS, enforced by assertInvariants() rather than by review:
 *
 *   1. `kind` on EVERY consumer row. P or R, never blank.
 *   2. Every shipped event has at least one consumer that DOES something - the
 *      NAMED-CONSUMER TEST. An event nobody listens to is a slower log.
 *   3. NO REACTOR DOWNSTREAM OF A PROJECTOR. A projector that could invoke a
 *      reactor would make rebuild unsafe through the back door. Where a
 *      projection should cause a reaction, the projector emits a NEW event and
 *      the reactor subscribes to that.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A GUESSED `from` IN AN EVENT STORE IS WORSE THAN NO EVENT, BECAUSE IT LOOKS
 * LIKE HISTORY.
 *
 *   An event is a claim about what happened. `task.status_changed` claims a
 *   transition FROM one state TO another - and only the code holding both sides
 *   can make that claim honestly. A caller that emits it after writing knows the
 *   NEW value and is guessing the old one.
 *
 *   A wrong `from` does not read as an error. It reads as a fact, and it is
 *   replayed as one by every projector downstream. **An absent event leaves a
 *   gap somebody notices; a fabricated one silently corrupts the record.**
 *
 *   This is why `TaskStatusWriter` emits `task.status_changed` itself (T-01)
 *   rather than letting its five callers do it.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Deferred events carry a TRIGGER. An event deferred without one is an event
 * nobody will remember to enable.
 */
class EventCatalogue
{
    public const PROJECTOR = 'P';   // pure, replayable, ledger cleared on rebuild
    public const REACTOR   = 'R';   // touches the world, live only, ledger PERMANENT

    /**
     * G-EVT-03: `ProficiencyService` REMOVED from capability.flag_resolved,
     * assessment.completed, course.completed and employee.role_assigned.
     *
     * IT RESOLVES AND IT IS NOT A CONSUMER. One public method, `rollUp()`, a
     * query. No handles(), no project(), no CONSUMER, no catchUp, no delivery
     * ledger entry, and no mention of any event type. Its only callers are
     * CompetencyGapController and NineBoxController - both READ paths.
     *
     * G-EVT-01's check asks whether a name RESOLVES. This one resolved. THAT IS
     * NOT THE SAME AS DOING THE WORK, and the difference is now its own
     * invariant below.
     *
     * NO EVENT DIED: each of the four keeps a real consumer
     * (CapabilityEvidenceProjector, NotificationDispatcher, CertificateIssuer,
     * LearningAssigner respectively), so the named-consumer test still passes.
     * PROFICIENCY IS NOT PROJECTED AT ALL - rollUp() derives it ON READ from
     * competency_kasba_rating. That is a design, not an omission, and it is why
     * GapRecalculator is DROPPED rather than deferred: nothing is stored, so
     * nothing needs recalculating.
     */
    /** @var array<string, array<string, string>> event => consumer => kind */
    public const SHIPPED = [
        // Consumed by OnboardingLauncher, which creates the onboarding journey
        // from the hire's offer_id. Was in NOT_SHIPPED until that reactor existed.
        'employee.hired' => [
            'OnboardingLauncher' => self::REACTOR,
        ],
        'task.rejected' => [
            'CapabilityEvidenceProjector' => self::PROJECTOR,
            'TaskStatusProjector'         => self::PROJECTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'task.status_changed' => [
            'TaskStatusProjector'         => self::PROJECTOR,
        ],
        'task.reopened' => [
            'CapabilityEvidenceProjector' => self::PROJECTOR,
        ],
        // X-06: NotificationDispatcher REMOVED from this event. RemediationRecommender
        // still consumes it, so the event survives the named-consumer test.
        'capability.flag_raised' => [
            'RemediationRecommender'      => self::REACTOR,
        ],
        'capability.flag_resolved' => [
            'CapabilityEvidenceProjector' => self::PROJECTOR,
        ],
        'assessment.completed' => [
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'course.completed' => [
            'CertificateIssuer'           => self::REACTOR,
        ],
        // X-06 deferred this; X-11 UN-DEFERS it. CertificateIssuer now emits it,
        // and the certificate row it announces exists before the emit happens.
        'certification.issued' => [
            'CapabilityEvidenceProjector' => self::PROJECTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'certification.expiring' => [
            'NotificationDispatcher'      => self::REACTOR,
            'RemediationRecommender'      => self::REACTOR,
        ],
        'employee.role_assigned' => [
            // X-12: MandatoryLearningAssigner ABSORBED into LearningAssigner.
            // They differed only in where the course list came from, and two
            // classes meant two places to get idempotency wrong.
            'LearningAssigner'            => self::REACTOR,
        ],
        'employee.offboarded' => [
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'development_plan.approved' => [
            'LearningAssigner'            => self::REACTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'rights.changed' => [
            'AuditLogProjector'           => self::PROJECTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
    ];

    /**
     * Events that FAILED the named-consumer test, with the reason - kept visible
     * so nobody re-proposes them as an oversight.
     *
     * `trigger` is what makes a deferred event enable-able. `null` means dropped,
     * not deferred: there is nothing to wait for.
     */
    public const NOT_SHIPPED = [
        // RECLASSIFIED 2026-08-11: DEFERRED -> DEFERRED_INDEFINITELY.
        //
        // `task_hygiene` was written as a condition that would plausibly be met.
        // Re-measured: 2,088 of 2,271 tasks overdue - 91.9%. IT IS NOT DRIFTING
        // TOWARD FIRING. A gate at 91.9% is not "not yet"; it is "probably never"
        // unless the way this product is used changes.
        //
        // The distinction matters because a trigger that plausibly fires belongs
        // in a schedule somebody re-reads, and one that does not belongs in a
        // record of decisions. Mixing them makes the schedule untrustworthy.
        'task.assigned' => [
            'verdict' => 'DEFERRED_INDEFINITELY',
            'trigger' => 'readiness gate: task_hygiene - measured 91.9% overdue, NOT drifting toward firing',
            'reason'  => 'The only plausible consumer is a notification, and 2,271 tasks at 99% overdue would make it noise before it was useful.',
        ],
        'task.overdue' => [
            'verdict' => 'DEFERRED_INDEFINITELY',
            'trigger' => 'readiness gate: task_hygiene - measured 91.9% overdue, NOT drifting toward firing',
            'reason'  => 'Would fire 2,245 times today. Same gate as F4 (M1). G-TASK-03 measured the same condition from the STATE side: 4 in-progress and 1 on-hold across 2,271 tasks. Two independent measurements, one conclusion - the workflow is not being used.',
        ],
        'task.completed' => [
            'verdict' => 'DROPPED',
            'trigger' => null,
            'reason'  => 'No consumer DOES anything. Completion without approval is not a capability signal, and approval already emits its own event.',
        ],
        // ─── MOVED HERE BY G-EVT-01, NOT DROPPED ON MERIT ───────────────────
        // Each had exactly one consumer and that consumer did not exist.
        // Removing the paper reactor left the event with nobody, which is the
        // named-consumer test. The trigger says what brings each back.
        //
        // FILED WRONG ONCE: these first went into NOT_NOTIFIED, which records
        // dropped NOTIFICATIONS. An event with no consumer is not a notification
        // decision, and putting it there would have said something untrue about
        // a decision nobody took. NOT_SHIPPED is the list for events.
        //
        // 'task.reopened' WAS HERE and is now back in SHIPPED: its trigger said
        // "CapabilityEvidenceProjector is built", and it now is. Left as a
        // comment rather than deleted so the deferral and its resolution stay
        // visible together - same treatment as certification.issued.
        //
        // 'employee.hired' WAS HERE and is now SHIPPED. Its trigger said
        // "OnboardingLauncher is built (X-14)", and it now is:
        // App\Services\Events\OnboardingLauncher creates the onboarding journey
        // from the hire's offer_id and is registered in ReactEvents::REACTORS.
        // Left as a comment rather than deleted so the deferral and its
        // resolution stay visible together - the same treatment task.reopened
        // and certification.issued already have.
        'readiness_gate.changed' => [
            'verdict' => 'DEFERRED',
            'trigger' => 'CORRECTED 2026-08-11. X-07 is DONE - the gates compute, the acknowledgement path works, and CompetencyGapController now ENFORCES capability_coverage. The remaining condition is not the state, it is the READER: a reactor needs someone to react FOR. Trigger is now "more features enforce gates, then X-15" - building the applier before that is building the consumer of an event before anything consumes the applier own output, which is how FeatureGateApplier became a paper reactor to begin with.',
            'reason'  => 'Its only declared consumer, FeatureGateApplier, was never written. G-EVT-01. Its NOTIFICATION is separately DROPPED - see NOT_NOTIFIED, re-taken on the surviving clause.',
        ],
        'competency.gap_detected' => [
            'verdict' => 'DROPPED',
            'trigger' => null,
            'reason'  => 'Gaps are DERIVED, not a state change. The gap is a query, not an event; emitting one per recompute would flood the store with the same standing gap.',
        ],
    ];

    /**
     * X-06 — THE NAMED-CONSUMER TEST APPLIED TO NOTIFICATIONS SPECIFICALLY.
     *
     * The test the events themselves passed was "does any consumer DO something".
     * A notification has a stricter one, because its consumer is a PERSON:
     *
     *     WHO receives it, HOW do we find them, and WHAT do they do about it?
     *
     * Three of the nine events NotificationDispatcher used to claim cannot answer
     * all three. They are recorded here rather than quietly dropped, with the
     * trigger that would let them back in.
     */
    public const NOT_NOTIFIED = [
        'capability.flag_raised' => [
            'verdict'   => 'DEFERRED',
            'recipient' => "the employee's manager",
            'trigger'   => 'an employee->manager edge that resolves',
            'reason'    => 'MEASURED: tbluser.reporting_manager_id is populated on 0 of 387 rows, and supervisor_opt is a flag (4 Supervisor / 57 Subordinate) with no edge between the two. Every other manager column in the schema belongs to a CASE, not to a person. A flag is an ESCALATION; redirecting it to the employee would change what it means, and that is a product decision, not a build one.',
        ],
        // 'certification.issued' WAS HERE. X-11 shipped, so its trigger fired and
        // it moved back into NOTIFIES. Left as a comment rather than deleted so
        // the deferral and its resolution stay visible together.
        // THE EVENT ITSELF is also unshipped now: FeatureGateApplier was its only
        // consumer. X-07 must build the readiness_gate STATE before a reactor has
        // anything to gate; X-15 follows X-07, not the reverse. The plan had that
        // ordering backwards.
        'readiness_gate.changed' => [
            'verdict'   => 'DROPPED',
            'recipient' => null,
            'trigger'   => null,
            'reason'    => 'RE-TAKEN 2026-08-11 (G-EVT-01). The original drop rested on TWO clauses and the first was false: "FeatureGateApplier already applies the change" - there is no such class, so nothing applied anything. Decided again on the SURVIVING clause alone: no human does anything on being told a gate moved, which makes the notification an announcement rather than a message. THE VERDICT IS UNCHANGED AND THE REASON IS NOT. It now rests on one clause that is true instead of two, one of which was invented.',
            'verdict_history' => 'DROPPED (X-06, on a false premise) -> DROPPED (G-EVT-01, on the surviving clause)',
        ],
    ];

    /**
     * Consumers whose dispatch ledger is PERMANENT and survives every rebuild.
     */
    public static function reactors(): array
    {
        $out = [];
        foreach (self::SHIPPED as $consumers) {
            foreach ($consumers as $name => $kind) {
                if ($kind === self::REACTOR) $out[$name] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    }

    public static function projectors(): array
    {
        $out = [];
        foreach (self::SHIPPED as $consumers) {
            foreach ($consumers as $name => $kind) {
                if ($kind === self::PROJECTOR) $out[$name] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    }

    /**
     * @return array<int,string> violations; empty means the catalogue is coherent
     */
    /**
     * Resolve a declared consumer name to its fully-qualified class, or null.
     *
     * NOT a bare class_exists() on this namespace. The first version of this
     * check hardcoded App\Services\Events\ and reported four false absences -
     * ProficiencyService is real and lives in App\Services\Competency. A consumer
     * is not obliged to live beside the catalogue that names it (R26: the first
     * red was partly the check).
     */
    public static function resolveConsumer(string $name): ?string
    {
        foreach (['App\\Services\\Events\\', 'App\\Services\\Competency\\', 'App\\Services\\'] as $ns) {
            if (class_exists($ns . $name)) return $ns . $name;
        }
        return null;
    }

    public static function assertInvariants(): array
    {
        $errors = [];

        foreach (self::SHIPPED as $event => $consumers) {
            if ($consumers === []) {
                $errors[] = "NAMED-CONSUMER TEST: '$event' has no consumer - it is a slower log.";
                continue;
            }
            foreach ($consumers as $name => $kind) {
                // G-EVT-01. A DECLARED CONSUMER MUST RESOLVE TO A CLASS.
                //
                // This is the gap that let ELEVEN declarations through, naming six
                // classes that were never written. Every other invariant here
                // checks the SHAPE of a declaration - kinds, notification rules -
                // and none of them ever asked whether the name refers to anything.
                // A PAPER REACTOR PASSED EVERY CHECK IN THIS METHOD.
                //
                // It is not cosmetic: X-06 removed a notification because
                // "FeatureGateApplier already does it", and that class did not
                // exist. This list is the authority on what shipped, and the rest
                // of the phase quotes it.
                //
                // Consumers are NOT required to live in this namespace -
                // ProficiencyService is in App\Services\Competency - so resolution
                // tries the siblings first and then the known homes. A name that
                // resolves nowhere is the error.
                $fqcn = self::resolveConsumer($name);
                if (!$fqcn) {
                    $errors[] = "ABSENT CONSUMER: '$event' -> '$name' resolves to no class. "
                        . "Declare it only when it exists; until then it belongs in NOT_SHIPPED.";
                } elseif (!method_exists($fqcn, 'handles')
                          || !method_exists($fqcn, $kind === self::PROJECTOR ? 'project' : 'dispatch')) {
                    // G-EVT-03. RESOLVING IS NOT THE SAME AS BEING A CONSUMER.
                    //
                    // ProficiencyService was declared PROJECTOR on four events and
                    // is a query service with one method, rollUp(). The class
                    // existed, so the resolution check passed, and nothing in the
                    // event path had ever called it.
                    //
                    // A consumer must expose project() (projector) or handle()
                    // (reactor). This is a SHAPE test, not a behaviour test - it
                    // cannot tell whether the method does the right thing, only
                    // that the class is the kind of thing that could.
                    $errors[] = "NOT A CONSUMER: '$event' -> '$name' resolves to $fqcn, which has "
                        . "neither project() nor handle(). A class that resolves is not thereby a consumer.";
                }
                if (!in_array($kind, [self::PROJECTOR, self::REACTOR], true)) {
                    $errors[] = "KIND: '$event' -> '$name' has kind '" . var_export($kind, true) . "'; must be P or R.";
                }
            }
        }

        // A name must not be a projector in one place and a reactor in another:
        // replay safety is a property of the CONSUMER, not of the pairing.
        $seen = [];
        foreach (self::SHIPPED as $event => $consumers) {
            foreach ($consumers as $name => $kind) {
                if (isset($seen[$name]) && $seen[$name] !== $kind) {
                    $errors[] = "SPLIT KIND: '$name' is {$seen[$name]} elsewhere and $kind on '$event'. A consumer is replayable or it is not.";
                }
                $seen[$name] = $kind;
            }
        }

        // ── LIST MEMBERSHIP. G-EVT-01's SECOND LESSON. ──────────────────────
        //
        // The resolution check above asks whether a declared consumer RESOLVES.
        // It does not ask whether an entry is in the RIGHT LIST, and those are
        // different questions: `employee.hired` was filed in NOT_NOTIFIED, which
        // records dropped NOTIFICATIONS, when what had happened was that the
        // EVENT lost its only consumer. Every invariant here passed, because the
        // entry was well-formed - it was just filed against a decision nobody
        // took.
        //
        // SO A GREEN assertInvariants() DOES NOT MEAN "THE CATALOGUE IS
        // CORRECTLY FILED". It means every declaration is well-formed and every
        // name resolves. The two cheap membership rules are enforced below; the
        // judgement of whether a NOT_NOTIFIED entry describes a notification
        // decision or an event decision is NOT mechanised and cannot be read off
        // a green run.
        foreach (array_keys(self::NOT_SHIPPED) as $event) {
            if (isset(self::SHIPPED[$event])) {
                $errors[] = "DOUBLE-FILED: '$event' is in BOTH SHIPPED and NOT_SHIPPED. "
                    . "An event ships or it does not.";
            }
        }
        foreach (array_keys(self::NOT_NOTIFIED) as $event) {
            if (isset(self::NOT_SHIPPED[$event]) && !isset(self::SHIPPED[$event])) {
                // Legal but worth stating: readiness_gate.changed is deferred as
                // an EVENT and separately dropped as a NOTIFICATION, and its
                // entries cross-reference each other. Silence here would let an
                // accidental version of the same pair look identical.
                if (!str_contains((string) (self::NOT_SHIPPED[$event]['reason'] ?? ''), 'NOT_NOTIFIED')) {
                    $errors[] = "UNLINKED PAIR: '$event' is deferred as an event AND recorded as a "
                        . "dropped notification, but its NOT_SHIPPED reason does not reference the other. "
                        . "Two verdicts on one event must each say the other exists.";
                }
            }
        }

        foreach (self::NOT_SHIPPED as $event => $row) {
            if (in_array($row['verdict'], ['DEFERRED', 'DEFERRED_INDEFINITELY'], true) && empty($row['trigger'])) {
                $errors[] = "DEFERRED WITHOUT A TRIGGER: '$event' - nobody will remember to enable it.";
            }
        }

        foreach (self::NOT_NOTIFIED as $event => $row) {
            if (in_array($row['verdict'], ['DEFERRED', 'DEFERRED_INDEFINITELY'], true) && empty($row['trigger'])) {
                $errors[] = "NOT_NOTIFIED WITHOUT A TRIGGER: '$event' - nobody will remember to enable it.";
            }
            // A deferred notification must not still be wired up. This is the
            // check that stops the register and the code drifting apart.
            if (isset(self::SHIPPED[$event]['NotificationDispatcher'])) {
                $errors[] = "CONTRADICTION: '$event' is in NOT_NOTIFIED but NotificationDispatcher still consumes it.";
            }
        }

        // AND THE OTHER DIRECTION. An event the dispatcher notifies must be in the
        // catalogue as one of its events - otherwise the catalogue is describing a
        // system that no longer exists.
        foreach (NotificationDispatcher::NOTIFIES as $event) {
            if (!isset(self::SHIPPED[$event])) {
                $errors[] = "UNKNOWN EVENT NOTIFIED: NotificationDispatcher sends on '$event', which is not a shipped event.";
            } elseif (!isset(self::SHIPPED[$event]['NotificationDispatcher'])) {
                $errors[] = "UNDECLARED CONSUMER: NotificationDispatcher sends on '$event' but is not listed as its consumer.";
            }
        }

        return $errors;
    }
}
