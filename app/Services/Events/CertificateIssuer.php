<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * X-11 — REACTOR. Issues the certificate that closes the loop. (kind = R)
 *
 * Closes G-FLOW-05's manual-claim gap: the plan carried certificate upload and
 * resolve, so a certificate existed only if somebody remembered to upload one.
 * Completing a course now produces the proof automatically.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDEMPOTENCY ON AN IRREVERSIBLE ACT — THREE LAYERS, DELIBERATELY OVERLAPPING.
 *
 *   1. `g2g_event_delivery` — this event, this consumer, once.
 *   2. UNIQUE INDEX on `lms_certificates.enrollment_id` — added while the table
 *      was still empty, so it is the strong constraint that X-12 could not have.
 *   3. THE CERTIFICATE NUMBER AND VERIFICATION CODE ARE DERIVED, NOT RANDOM.
 *
 * Layer 3 is the one worth explaining. A random UUID would make every retry
 * produce a NEW number that the unique index cannot recognise as a duplicate, so
 * the guard would depend entirely on layers 1 and 2 being reached. Deriving both
 * values from (tenant, enrolment) means A RETRY COMPUTES THE SAME CERTIFICATE and
 * collides on its own uniqueness - the identifier itself carries the idempotency.
 *
 * The verification code is a keyed hash, so it is reproducible by us and not
 * guessable by someone holding a certificate number.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A REACTOR THAT EMITS AN EVENT. `certification.issued` is recorded here, which
 * is the catalogue's documented pattern for "a reaction should cause more work" -
 * the alternative, calling the next consumer directly, is what makes rebuilds
 * unsafe. On replay this class throws before reaching the emit, so no phantom
 * certification.issued can enter the store during a rebuild.
 */
class CertificateIssuer
{
    public const CONSUMER = 'certificate_issuer';

    public const HANDLES = ['course.completed'];

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    public function __construct(private EventRecorder $recorder)
    {
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // FIRST LINE. A certificate is a claim about a person that outlives this
        // system; a rebuild must never mint one.
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

        $tenant  = (int) $event->sub_institute_id;
        $payload = $this->payload($event);

        $enrolment = $this->enrolment($event, $payload, $tenant);

        if (!$enrolment) {
            $this->ledger($event, 'skipped', 'no completed enrolment resolved');
            return;
        }

        $number = $this->certificateNumber($tenant, (int) $enrolment->id);
        $code   = $this->verificationCode($tenant, (int) $enrolment->id);

        [$courseTitle, $validityMonths] = $this->course((int) $enrolment->course_id, $tenant);

        $written = DB::table('lms_certificates')->insertOrIgnore([
            'user_id'            => (int) $enrolment->user_id,
            'course_id'          => (int) $enrolment->course_id,
            'enrollment_id'      => (int) $enrolment->id,
            'certificate_number' => $number,
            'verification_code'  => $code,
            'course_title'       => $courseTitle,
            'name'               => $courseTitle,
            'issued_at'          => now(),
            // EXPIRY COMES FROM THE COURSE, OR NOT AT ALL.
            //
            // `sub_std_map.certificate_validity_months` is the right source and
            // it is populated on 0 of 95 courses today, so in practice every
            // certificate issued now is open-ended. That is the correct outcome:
            // a GUESSED expiry on a real certificate is worse than none, because
            // certification.expiring would later fire at a date nobody chose and
            // tell a real person to renew something that never lapsed.
            'expires_at'         => $validityMonths ? now()->addMonths($validityMonths) : null,
            'status'             => 'issued',
            'sub_institute_id'   => $tenant,
            'created_by'         => (int) ($event->actor_id ?: 0) ?: null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        if ($written === 0) {
            // Already issued - by an earlier delivery of this event, or by a
            // different event for the same enrolment. Either way it is not new,
            // and NO certification.issued is emitted for it.
            $this->ledger($event, 'done', 'certificate already existed');
            return;
        }

        $certificateId = (int) DB::table('lms_certificates')
            ->where('enrollment_id', (int) $enrolment->id)
            ->value('id');

        // EMIT ONLY AFTER THE WRITE SUCCEEDED. Emitting first would announce a
        // certificate that a failed insert never created, and consumers would
        // notify somebody about a document that does not exist.
        $this->recorder->record(
            'certification.issued',
            $tenant,
            'certificate',
            $certificateId,
            (int) ($event->actor_id ?: 0) ?: null,
            [
                'user_id'            => (int) $enrolment->user_id,
                'course_id'          => (int) $enrolment->course_id,
                'certificate_number' => $number,
                'certificate_name'   => $courseTitle,
            ],
            null,
            // Idempotency at the STORE level too: a second attempt cannot append
            // a duplicate certification.issued for the same certificate.
            'certification.issued:' . $tenant . ':' . $certificateId,
            $event->correlation_id ?? null,
            $event->event_uuid ?? null
        );

        $this->ledger($event, 'done', null);

        Log::channel('single')->info('certificate.issued', [
            'event_id'       => $event->id,
            'certificate_id' => $certificateId,
            'user_id'        => $enrolment->user_id,
            'number'         => $number,
        ]);
    }

    /**
     * The enrolment this event is about, and it must actually BE complete.
     *
     * An event saying "completed" is not evidence that the enrolment says so.
     * Trusting the payload would let a mis-emitted event mint a certificate for
     * work nobody finished.
     */
    private function enrolment(object $event, array $payload, int $tenant): ?object
    {
        $q = DB::table('lms_course_enroll')
            ->where('sub_institute_id', $tenant)
            ->where('status', 'completed')
            ->whereNull('deleted_at');

        $enrolmentId = (int) ($payload['enrollment_id'] ?? 0);
        if ($enrolmentId === 0 && $event->entity_type === 'enrolment') {
            $enrolmentId = (int) $event->entity_id;
        }

        if ($enrolmentId > 0) {
            return $q->where('id', $enrolmentId)->first(['id', 'user_id', 'course_id']);
        }

        $userId   = (int) ($payload['user_id'] ?? 0);
        $courseId = (int) ($payload['course_id'] ?? 0);

        if ($userId > 0 && $courseId > 0) {
            return $q->where('user_id', $userId)->where('course_id', $courseId)
                ->orderByDesc('id')->first(['id', 'user_id', 'course_id']);
        }

        return null;
    }

    /**
     * Derived, not random. See the class docblock.
     * Shape: G2G-<tenant>-<enrolment>, which is stable, readable and unique.
     */
    private function certificateNumber(int $tenant, int $enrolmentId): string
    {
        return sprintf('G2G-%d-%d', $tenant, $enrolmentId);
    }

    /**
     * Keyed hash: reproducible by us, not derivable from the certificate number.
     * Falls back to a non-secret derivation only if APP_KEY is absent, and says
     * so in the value rather than silently issuing a guessable code.
     */
    private function verificationCode(int $tenant, int $enrolmentId): string
    {
        $key = (string) config('app.key');
        $material = 'g2g-cert:' . $tenant . ':' . $enrolmentId;

        return $key !== ''
            ? substr(hash_hmac('sha256', $material, $key), 0, 32)
            : 'UNKEYED-' . substr(hash('sha256', $material), 0, 24);
    }

    /**
     * THE COURSE TABLE IS CALLED `sub_std_map`.
     *
     * Not lms_courses, not courses - neither exists. I found it by reading the
     * join in LmsCourseController (`->on('e.course_id', '=', 's.id')`) rather than
     * by guessing a name, after two guesses returned "table missing".
     *
     * @return array{0:?string, 1:?int} title, validity in months
     */
    private function course(int $courseId, int $tenant): array
    {
        $row = DB::table('sub_std_map')
            ->where('id', $courseId)
            ->where('sub_institute_id', $tenant)
            ->first(['display_name', 'certificate_validity_months']);

        return [
            $row->display_name ?? null,
            $row->certificate_validity_months !== null ? (int) $row->certificate_validity_months : null,
        ];
    }

    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }
        $d = json_decode((string) ($event->payload ?? ''), true);

        return is_array($d) ? $d : [];
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
