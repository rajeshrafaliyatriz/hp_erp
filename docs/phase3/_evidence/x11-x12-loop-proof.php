<?php
/**
 * X-12 + X-11 PROOF — THE LOOP CLOSES.
 *
 *   a gap appears -> people are told -> a course is assigned -> a certificate
 *   proves it -> and the person is told about THAT.
 *
 * Everything this script creates, it deletes. The database is shared and remote.
 * The seeded bridge rows are especially important to remove: leaving one behind
 * would make the coverage figure wrong for whoever measures next.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Events\CertificateIssuer;
use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use App\Services\Events\LearningAssigner;
use App\Services\Events\NotificationDispatcher;
use App\Services\Events\ReplayMode;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0; $skip = 0;

/**
 * R23, THE HARD WAY, AGAIN.
 *
 * The first version of this harness did `$ok ? 'PASS' : 'FAIL'`. Checks that
 * wanted to opt out returned the STRING 'SKIPPED' - which is truthy - so
 * "plan path WORKS the moment the bridge is populated" printed **PASS** beside
 * the detail "no plan+course pair in this tenant". A check that never ran was
 * counted as evidence that it succeeded.
 *
 * Same defect class as X-06's both-branches detail string, one item later: THE
 * VERDICT AND THE DETAIL DISAGREED AND THE VERDICT WON. Three states now, and
 * SKIPPED is counted separately so it can never inflate a pass count.
 */
function check(string $name, callable $fn): void
{
    try {
        [$ok, $detail] = $fn();

        if ($ok === 'SKIPPED') {
            printf("  %-6s %-54s %s\n", 'SKIP', $name, $detail);
            $GLOBALS['skip']++;
            return;
        }

        if (!is_bool($ok)) {
            printf("  %-6s %-54s %s\n", 'FAIL', $name,
                'check returned a non-boolean verdict (' . var_export($ok, true) . ') - fix the check');
            $GLOBALS['fail']++;
            return;
        }

        printf("  %-6s %-54s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
        $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
    } catch (Throwable $e) {
        printf("  %-6s %-54s %s\n", 'FAIL', $name, get_class($e) . ': ' . $e->getMessage());
        $GLOBALS['fail']++;
    }
}

echo "\n========================================================================\n";
echo "X-12 + X-11 — THE LOOP\n";
echo "========================================================================\n\n";

$made = ['event' => [], 'assignment' => [], 'certificate' => [], 'map' => [], 'enrol' => []];

// ── COVERAGE, FIRST AND HONESTLY ────────────────────────────────────────────
echo "COVERAGE (measured before anything is seeded)\n";
$cov = LearningAssigner::coverage();
foreach ($cov as $k => $v) {
    printf("  %-32s %d\n", $k, $v);
}
echo "\n";

// A tenant that actually has a completed enrolment - chosen from data.
$enrol = DB::table('lms_course_enroll')->where('status', 'completed')->whereNull('deleted_at')->first();
$tenant = (int) ($enrol->sub_institute_id ?? 0);
printf("tenant=%d  completed enrolment=%d  user=%d  course=%d\n\n",
    $tenant, $enrol->id ?? 0, $enrol->user_id ?? 0, $enrol->course_id ?? 0);

// ── CATALOGUE ───────────────────────────────────────────────────────────────
echo "CATALOGUE\n";

check('invariants hold after absorbing MandatoryLearningAssigner', function () {
    $e = EventCatalogue::assertInvariants();
    return [$e === [], $e === [] ? 'no violations' : implode(' | ', $e)];
});

check('MandatoryLearningAssigner is gone, LearningAssigner consumes both', function () {
    $names = EventCatalogue::reactors();
    $both = isset(EventCatalogue::SHIPPED['employee.role_assigned']['LearningAssigner'])
        && isset(EventCatalogue::SHIPPED['development_plan.approved']['LearningAssigner']);
    return [!in_array('MandatoryLearningAssigner', $names, true) && $both,
        'reactors: ' . implode(', ', array_slice($names, 0, 4)) . '…'];
});

check('certification.issued moved OUT of NOT_NOTIFIED', function () {
    $deferred = array_key_exists('certification.issued', EventCatalogue::NOT_NOTIFIED);
    $notified = in_array('certification.issued', NotificationDispatcher::NOTIFIES, true);
    return [!$deferred && $notified, $notified ? 'its trigger (X-11) fired and it is wired' : 'still deferred'];
});

// ── X-12 ────────────────────────────────────────────────────────────────────
echo "\nX-12 — LEARNING ASSIGNER\n";

$roleEventId = null;

check('role path assigns from the TEXT link (the only populated one)', function () use ($tenant, &$made, &$roleEventId) {
    // A user who HAS a job role, in a tenant whose courses carry role names.
    $row = DB::table('tbluser as u')
        ->join('s_user_jobrole as j', function ($q) {
            $q->on('j.id', '=', 'u.allocated_standards')->on('j.sub_institute_id', '=', 'u.sub_institute_id');
        })
        ->join('sub_std_map as c', function ($q) {
            $q->on('c.jobrole', '=', 'j.jobrole')->on('c.sub_institute_id', '=', 'j.sub_institute_id');
        })
        ->whereNull('c.deleted_at')
        ->first(['u.id as user_id', 'u.sub_institute_id as tenant', 'j.id as jobrole_id']);

    if (!$row) {
        return [false, 'no user whose job role has a course by name - the fixture itself is the finding'];
    }

    $id = app(EventRecorder::class)->record(
        'employee.role_assigned', (int) $row->tenant, 'user', (int) $row->user_id, (int) $row->user_id,
        ['user_id' => (int) $row->user_id, 'jobrole_id' => (int) $row->jobrole_id]
    );
    $made['event'][] = $id;
    $roleEventId = $id;

    app(LearningAssigner::class)->dispatch(DB::table('g2g_event')->find($id));

    $rows = DB::table('lms_assignments')->where('origin_event_id', $id)->get();
    foreach ($rows as $r) $made['assignment'][] = $r->id;

    $d = DB::table('g2g_event_delivery')->where('event_id', $id)
        ->where('consumer', LearningAssigner::CONSUMER)->first();

    return [$rows->count() > 0 && $d && $d->status === 'done',
        $rows->count() . ' assignment(s), source=' . ($rows[0]->source ?? '-') . ', ledger=' . ($d->status ?? '-')];
});

check('a SECOND dispatch assigns nothing more', function () use (&$roleEventId) {
    $before = DB::table('lms_assignments')->where('origin_event_id', $roleEventId)->count();
    app(LearningAssigner::class)->dispatch(DB::table('g2g_event')->find($roleEventId));
    $after = DB::table('lms_assignments')->where('origin_event_id', $roleEventId)->count();
    return [$before === $after, "before=$before after=$after"];
});

check('plan path is SKIPPED with its reason, not silently done', function () use ($tenant, &$made) {
    $plan = DB::table('s_competency_development_plans')->where('sub_institute_id', $tenant)->first(['id', 'user_id']);
    if (!$plan) return ['SKIPPED', 'no development plan in this tenant'];

    $id = app(EventRecorder::class)->record(
        'development_plan.approved', $tenant, 'development_plan', (int) $plan->id, (int) $plan->user_id,
        ['plan_id' => (int) $plan->id, 'user_id' => (int) $plan->user_id]
    );
    $made['event'][] = $id;

    app(LearningAssigner::class)->dispatch(DB::table('g2g_event')->find($id));

    $d = DB::table('g2g_event_delivery')->where('event_id', $id)
        ->where('consumer', LearningAssigner::CONSUMER)->first();
    $n = DB::table('lms_assignments')->where('origin_event_id', $id)->count();

    // course_competency_map is EMPTY, so `skipped` with a reason is the CORRECT
    // outcome. `done` with 0 assignments would hide an empty bridge table.
    return [$d && $d->status === 'skipped' && $n === 0,
        $d ? "status={$d->status}, reason=\"{$d->last_error}\", assignments=$n" : 'no ledger row'];
});

check('plan path WORKS the moment the bridge is populated', function () use (&$made) {
    // ACROSS TENANTS, not inside the one the certificate fixture happened to
    // pick. Scoping this to tenant 3 made the most important check in the file
    // skip - the mechanism is what is being tested, and any tenant holding both
    // a plan and a course can test it.
    $plan = DB::table('s_competency_development_plans as p')
        ->join('sub_std_map as c', function ($q) {
            $q->on('c.sub_institute_id', '=', 'p.sub_institute_id');
        })
        ->whereNotNull('p.competency_id')
        ->whereNull('c.deleted_at')
        ->whereNull('p.deleted_at')
        ->first(['p.id', 'p.user_id', 'p.competency_id', 'p.sub_institute_id as tenant', 'c.id as course_id']);

    if (!$plan) return ['SKIPPED', 'no tenant holds both a plan with a competency and a course'];

    $tenant = (int) $plan->tenant;
    $course = (object) ['id' => $plan->course_id];

    // ONE seeded bridge row, removed at the end. This proves the mechanism is
    // sound and that the ONLY thing missing is data.
    $mapId = DB::table('course_competency_map')->insertGetId([
        'sub_institute_id' => $tenant, 'course_id' => (int) $course->id,
        'competency_id' => (int) $plan->competency_id, 'is_primary' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $made['map'][] = $mapId;

    $id = app(EventRecorder::class)->record(
        'development_plan.approved', $tenant, 'development_plan', (int) $plan->id, (int) $plan->user_id,
        ['plan_id' => (int) $plan->id, 'user_id' => (int) $plan->user_id]
    );
    $made['event'][] = $id;

    app(LearningAssigner::class)->dispatch(DB::table('g2g_event')->find($id));

    $rows = DB::table('lms_assignments')->where('origin_event_id', $id)->get();
    foreach ($rows as $r) $made['assignment'][] = $r->id;

    return [$rows->count() > 0,
        $rows->count() . ' assignment(s) from ONE seeded bridge row - the mechanism is not the gap, the data is'];
});

check('assigner THROWS in replay', function () use (&$roleEventId) {
    ReplayMode::enable();
    try {
        app(LearningAssigner::class)->dispatch(DB::table('g2g_event')->find($roleEventId));
        ReplayMode::disable();
        return [false, 'IT DID NOT THROW - a rebuild would re-assign courses'];
    } catch (RuntimeException $e) {
        ReplayMode::disable();
        return [true, 'threw: ' . mb_substr($e->getMessage(), 0, 46)];
    }
});

// ── X-11 ────────────────────────────────────────────────────────────────────
echo "\nX-11 — CERTIFICATE ISSUER\n";

$certEventId = null;

check('a completed course issues a certificate', function () use ($enrol, $tenant, &$made, &$certEventId) {
    $id = app(EventRecorder::class)->record(
        'course.completed', $tenant, 'enrolment', (int) $enrol->id, (int) $enrol->user_id,
        ['enrollment_id' => (int) $enrol->id, 'user_id' => (int) $enrol->user_id, 'course_id' => (int) $enrol->course_id]
    );
    $made['event'][] = $id;
    $certEventId = $id;

    app(CertificateIssuer::class)->dispatch(DB::table('g2g_event')->find($id));

    $c = DB::table('lms_certificates')->where('enrollment_id', (int) $enrol->id)->first();
    if ($c) $made['certificate'][] = $c->id;

    return [$c !== null && $c->status === 'issued',
        $c ? "number={$c->certificate_number}, title=\"" . mb_substr((string) $c->course_title, 0, 30) . "\"" : 'none issued'];
});

check('the number and code are DERIVED, so a retry collides', function () use ($enrol, &$certEventId) {
    $before = DB::table('lms_certificates')->where('enrollment_id', (int) $enrol->id)->count();
    // Clear the ledger so the retry reaches the insert rather than exiting early -
    // this tests the IDENTIFIER's idempotency, not the ledger's.
    DB::table('g2g_event_delivery')->where('event_id', $certEventId)
        ->where('consumer', CertificateIssuer::CONSUMER)->delete();
    app(CertificateIssuer::class)->dispatch(DB::table('g2g_event')->find($certEventId));
    $after = DB::table('lms_certificates')->where('enrollment_id', (int) $enrol->id)->count();
    return [$before === $after && $after === 1, "before=$before after=$after (must stay 1)"];
});

check('an enrolment that is NOT complete gets nothing', function () use ($tenant, &$made) {
    $open = DB::table('lms_course_enroll')->where('sub_institute_id', $tenant)
        ->where('status', '!=', 'completed')->whereNull('deleted_at')->first();
    if (!$open) return ['SKIPPED', 'no incomplete enrolment in this tenant'];

    $id = app(EventRecorder::class)->record(
        'course.completed', $tenant, 'enrolment', (int) $open->id, (int) $open->user_id,
        ['enrollment_id' => (int) $open->id]
    );
    $made['event'][] = $id;
    app(CertificateIssuer::class)->dispatch(DB::table('g2g_event')->find($id));

    $n = DB::table('lms_certificates')->where('enrollment_id', (int) $open->id)->count();
    // THE EVENT SAID "completed". THE ENROLMENT DID NOT. The enrolment wins.
    return [$n === 0, "certificates minted from a lying event: $n (must be 0)"];
});

check('issuing EMITS certification.issued', function () use (&$made, &$certEventId) {
    $ev = DB::table('g2g_event')->where('type', 'certification.issued')
        ->where('causation_id', DB::table('g2g_event')->where('id', $certEventId)->value('event_uuid'))
        ->first();
    if ($ev) $made['event'][] = $ev->id;
    return [$ev !== null, $ev ? "event {$ev->id}, entity=certificate {$ev->entity_id}" : 'no event emitted'];
});

check('THE LOOP CLOSES — the holder is notified about it', function () use (&$made) {
    $ev = DB::table('g2g_event')->where('type', 'certification.issued')->orderByDesc('id')->first();
    if (!$ev) return [false, 'no certification.issued to dispatch'];

    app(NotificationDispatcher::class)->dispatch($ev);

    $n = DB::table('g2g_notification')->where('event_id', $ev->id)->first();
    if ($n) $made['event'][] = $ev->id;

    return [$n !== null, $n ? "\"{$n->subject}\" -> user {$n->user_id} ({$n->recipient_reason})" : 'no notification'];
});

check('issuer THROWS in replay', function () use (&$certEventId) {
    ReplayMode::enable();
    try {
        app(CertificateIssuer::class)->dispatch(DB::table('g2g_event')->find($certEventId));
        ReplayMode::disable();
        return [false, 'IT DID NOT THROW - a rebuild would mint certificates'];
    } catch (RuntimeException $e) {
        ReplayMode::disable();
        return [true, 'threw: ' . mb_substr($e->getMessage(), 0, 46)];
    }
});

// ── ACTION LINKS ────────────────────────────────────────────────────────────
echo "\nACTION LINKS (G-NOTIF-02)\n";

check('no template points at a route that does not exist', function () {
    $verified = (require __DIR__ . '/../../../database/migrations/2026_08_11_000300_correct_notification_action_paths.php')::VERIFIED_ROUTES;
    $bad = DB::table('g2g_notification_template')->whereNotNull('action_path')->get(['event_type', 'action_path'])
        ->filter(fn ($r) => !in_array($r->action_path, $verified, true))
        ->map(fn ($r) => $r->event_type . ' -> ' . $r->action_path)
        ->all();
    $linked = DB::table('g2g_notification_template')->whereNotNull('action_path')->count();
    return [$bad === [], $bad === [] ? "$linked linked template(s), all to verified routes" : implode(' | ', $bad)];
});

// ── CLEANUP ─────────────────────────────────────────────────────────────────
echo "\nCLEANUP (shared remote database)\n";

$delNotif = DB::table('g2g_notification')->whereIn('event_id', $made['event'])->delete();
$delCert  = DB::table('lms_certificates')->whereIn('id', $made['certificate'])->delete();
$delAsg   = DB::table('lms_assignments')->whereIn('id', $made['assignment'])->delete();
$delMap   = DB::table('course_competency_map')->whereIn('id', $made['map'])->delete();
$delDel   = DB::table('g2g_event_delivery')->whereIn('event_id', $made['event'])->delete();
$delEv    = DB::table('g2g_event')->whereIn('id', $made['event'])->delete();

printf("  removed: %d notifications, %d certificates, %d assignments, %d bridge rows, %d deliveries, %d events\n",
    $delNotif, $delCert, $delAsg, $delMap, $delDel, $delEv);

$after = LearningAssigner::coverage();
printf("  coverage unchanged: competency_map %d->%d, jobrole_map %d->%d\n",
    $cov['competency_map'], $after['competency_map'], $cov['jobrole_map'], $after['jobrole_map']);
printf("  residue: g2g_event=%d  lms_certificates=%d  lms_assignments=%d (was 49)\n",
    DB::table('g2g_event')->count(), DB::table('lms_certificates')->count(), DB::table('lms_assignments')->count());

echo "\n========================================================================\n";
printf("PASS %d   FAIL %d   SKIPPED %d\n", $pass, $fail, $skip);
echo "VERDICT: " . ($fail === 0 ? "GREEN" : "RED") . "\n";
echo "========================================================================\n";
exit($fail === 0 ? 0 : 1);
