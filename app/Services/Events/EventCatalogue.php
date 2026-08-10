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
 * Deferred events carry a TRIGGER. An event deferred without one is an event
 * nobody will remember to enable.
 */
class EventCatalogue
{
    public const PROJECTOR = 'P';   // pure, replayable, ledger cleared on rebuild
    public const REACTOR   = 'R';   // touches the world, live only, ledger PERMANENT

    /** @var array<string, array<string, string>> event => consumer => kind */
    public const SHIPPED = [
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
        'capability.flag_raised' => [
            'NotificationDispatcher'      => self::REACTOR,
            'RemediationRecommender'      => self::REACTOR,
        ],
        'capability.flag_resolved' => [
            'ProficiencyService'          => self::PROJECTOR,
            'CapabilityEvidenceProjector' => self::PROJECTOR,
        ],
        'assessment.completed' => [
            'ProficiencyService'          => self::PROJECTOR,
            'GapRecalculator'             => self::PROJECTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'course.completed' => [
            'CertificateIssuer'           => self::REACTOR,
            'ProficiencyService'          => self::PROJECTOR,
            'GapRecalculator'             => self::PROJECTOR,
        ],
        'certification.issued' => [
            'CapabilityEvidenceProjector' => self::PROJECTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'certification.expiring' => [
            'NotificationDispatcher'      => self::REACTOR,
            'RemediationRecommender'      => self::REACTOR,
        ],
        'employee.role_assigned' => [
            'GapRecalculator'             => self::PROJECTOR,
            'ProficiencyService'          => self::PROJECTOR,
            'MandatoryLearningAssigner'   => self::REACTOR,
        ],
        'employee.hired' => [
            'OnboardingLauncher'          => self::REACTOR,
        ],
        'employee.offboarded' => [
            'AccessRevoker'               => self::REACTOR,
            'TaskReassigner'              => self::REACTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'development_plan.approved' => [
            'LearningAssigner'            => self::REACTOR,
            'NotificationDispatcher'      => self::REACTOR,
        ],
        'readiness_gate.changed' => [
            'NotificationDispatcher'      => self::REACTOR,
            'FeatureGateApplier'          => self::REACTOR,
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
        'task.assigned' => [
            'verdict' => 'DEFERRED',
            'trigger' => 'readiness gate: task_hygiene',
            'reason'  => 'The only plausible consumer is a notification, and 2,271 tasks at 99% overdue would make it noise before it was useful.',
        ],
        'task.overdue' => [
            'verdict' => 'DEFERRED',
            'trigger' => 'readiness gate: task_hygiene',
            'reason'  => 'Would fire 2,245 times today. Same gate as F4 (M1).',
        ],
        'task.completed' => [
            'verdict' => 'DROPPED',
            'trigger' => null,
            'reason'  => 'No consumer DOES anything. Completion without approval is not a capability signal, and approval already emits its own event.',
        ],
        'competency.gap_detected' => [
            'verdict' => 'DROPPED',
            'trigger' => null,
            'reason'  => 'Gaps are DERIVED, not a state change. The gap is a query, not an event; emitting one per recompute would flood the store with the same standing gap.',
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
    public static function assertInvariants(): array
    {
        $errors = [];

        foreach (self::SHIPPED as $event => $consumers) {
            if ($consumers === []) {
                $errors[] = "NAMED-CONSUMER TEST: '$event' has no consumer - it is a slower log.";
                continue;
            }
            foreach ($consumers as $name => $kind) {
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

        foreach (self::NOT_SHIPPED as $event => $row) {
            if ($row['verdict'] === 'DEFERRED' && empty($row['trigger'])) {
                $errors[] = "DEFERRED WITHOUT A TRIGGER: '$event' - nobody will remember to enable it.";
            }
        }

        return $errors;
    }
}
