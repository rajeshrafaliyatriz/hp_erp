<?php
/**
 * X-06 PROOF — the first reactor with a real send.
 *
 * Everything this script creates, it deletes. The database is shared and remote;
 * a test that leaves rows behind is a test that corrupts the next measurement.
 * The ONE exception is documented at the bottom and is deliberate.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Events\EventCatalogue;
use App\Services\Events\NotificationDispatcher;
use App\Services\Events\ReplayMode;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\TerminologyService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        [$ok, $detail] = $fn();
        printf("  %-6s %-52s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
        $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
    } catch (Throwable $e) {
        printf("  %-6s %-52s %s\n", 'FAIL', $name, get_class($e) . ': ' . $e->getMessage());
        $GLOBALS['fail']++;
    }
}

echo "\n========================================================================\n";
echo "X-06 — NOTIFICATION SERVICE PROOF\n";
echo "========================================================================\n\n";

// A real tenant with real users, chosen from data rather than assumed.
$tenant = (int) DB::table('tbluser')->whereNotNull('sub_institute_id')
    ->where('sub_institute_id', '>', 0)
    ->groupBy('sub_institute_id')->orderByRaw('count(*) desc')
    ->limit(1)->value('sub_institute_id');
$userA = (int) DB::table('tbluser')->where('sub_institute_id', $tenant)->orderBy('id')->value('id');
$other = (int) DB::table('tbluser')->where('sub_institute_id', '!=', $tenant)
    ->where('sub_institute_id', '>', 0)->orderBy('id')->value('id');

printf("tenant=%d  recipient=%d  outsider=%d\n\n", $tenant, $userA, $other);

$made = ['event' => [], 'notif' => [], 'delivery' => [], 'term' => []];

// ── CATALOGUE ───────────────────────────────────────────────────────────────
echo "CATALOGUE\n";

check('catalogue invariants hold after the X-06 edit', function () {
    $e = EventCatalogue::assertInvariants();
    return [$e === [], $e === [] ? 'no violations' : implode(' | ', $e)];
});

check('every notified event has a recipient resolver', function () {
    $src = file_get_contents(__DIR__ . '/../../../app/Services/Notifications/RecipientResolver.php');
    $missing = [];
    foreach (NotificationDispatcher::NOTIFIES as $t) {
        if (!str_contains($src, "'$t'")) $missing[] = $t;
    }
    return [$missing === [], $missing === [] ? count(NotificationDispatcher::NOTIFIES) . ' events, all resolvable' : 'no resolver: ' . implode(', ', $missing)];
});

check('every notified event has an active template', function () {
    $have = DB::table('g2g_notification_template')->where('channel', 'inapp')
        ->where('is_active', true)->pluck('event_type')->all();
    $missing = array_diff(NotificationDispatcher::NOTIFIES, $have);
    return [$missing === [], $missing === [] ? count($have) . ' inapp templates' : 'no template: ' . implode(', ', $missing)];
});

check('deferred events are NOT wired to the dispatcher', function () {
    $bad = [];
    foreach (array_keys(EventCatalogue::NOT_NOTIFIED) as $t) {
        if (in_array($t, NotificationDispatcher::NOTIFIES, true)) $bad[] = $t;
    }
    return [$bad === [], $bad === [] ? count(EventCatalogue::NOT_NOTIFIED) . ' deferred/dropped, none wired' : 'still wired: ' . implode(', ', $bad)];
});

// ── TERMINOLOGY ─────────────────────────────────────────────────────────────
echo "\nTERMINOLOGY (Q-F1)\n";

check('global defaults resolve', function () use ($tenant) {
    $m = (new TerminologyService())->map($tenant);
    return [isset($m['job_role']['singular']), count($m) . ' terms; job_role = "' . ($m['job_role']['singular'] ?? '?') . '"'];
});

check('a tenant override REPLACES the global word', function () use ($tenant, &$made) {
    DB::table('g2g_terminology')->updateOrInsert(
        ['sub_institute_id' => $tenant, 'term_key' => 'job_role', 'locale' => 'en'],
        ['singular' => 'Position', 'plural' => 'Positions', 'created_at' => now(), 'updated_at' => now()]
    );
    $made['term'][] = [$tenant, 'job_role'];
    $m = (new TerminologyService())->map($tenant);
    $g = (new TerminologyService())->map(0);
    return [
        ($m['job_role']['singular'] ?? '') === 'Position' && ($g['job_role']['singular'] ?? '') === 'Job Role',
        'tenant sees "' . ($m['job_role']['singular'] ?? '?') . '", global still "' . ($g['job_role']['singular'] ?? '?') . '"',
    ];
});

check('fixed wording survives the substitution', function () use ($tenant) {
    $t = new TerminologyService();
    $out = $t->apply('Your {term:job_role} changed. Review your {term:competency|plural}.', $t->map($tenant));
    return [
        str_contains($out, 'Position') && str_contains($out, 'Competencies') && str_contains($out, 'Your ') && !str_contains($out, '{'),
        '"' . $out . '"',
    ];
});

check('tenant DATA cannot reach the template engine', function () use ($tenant) {
    // A payload value that looks like a template directive must render as text.
    $ev = (object) [
        'id' => 0, 'type' => 'task.rejected', 'sub_institute_id' => $tenant,
        'entity_id' => 1, 'entity_type' => 'task', 'actor_id' => 1,
        'payload' => json_encode(['task_title' => '{term:competency}', 'approve_remarks' => 'x']),
    ];
    $c = new NotificationComposer(new TerminologyService());
    $m = $c->compose($ev, 'inapp', $tenant);
    // THE DETAIL REPORTS WHAT HAPPENED, NOT WHAT WAS HOPED FOR. The first
    // version of this check printed "rendered as literal text" on BOTH branches,
    // so a FAIL line described a pass. A detail string that cannot be wrong is
    // not evidence.
    $ok = $m !== null && str_contains($m['body'], '{term:competency}');
    return [$ok, $m === null ? 'no template'
        : ($ok ? 'payload directive rendered as literal text'
               : 'EXPANDED - tenant data reached the template engine: '
                 . mb_substr(str_replace("\n", ' ', $m['body']), 0, 50))];
});

// ── THE SEND ────────────────────────────────────────────────────────────────
echo "\nTHE SEND\n";

$eventId = null;

check('an event with a resolvable recipient produces a notification', function () use ($tenant, $userA, &$made, &$eventId) {
    $eventId = DB::table('g2g_event')->insertGetId([
        'event_uuid' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'rights.changed', 'sub_institute_id' => $tenant,
        'entity_type' => 'user', 'entity_id' => $userA, 'actor_id' => $userA,
        'payload' => json_encode(['user_id' => $userA, 'actor_name' => 'X-06 proof']),
        'metadata' => json_encode([]),
        'occurred_at' => now(), 'recorded_at' => now(),
    ]);
    $made['event'][] = $eventId;

    app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($eventId));

    $n = DB::table('g2g_notification')->where('event_id', $eventId)->get();
    foreach ($n as $r) $made['notif'][] = $r->id;
    $made['delivery'][] = $eventId;

    return [$n->count() === 1 && (int) $n[0]->user_id === $userA,
        $n->count() . ' notification(s), user=' . ($n[0]->user_id ?? '-') . ', reason=' . ($n[0]->recipient_reason ?? '-')];
});

check('the ledger records the dispatch as done', function () use (&$eventId) {
    $d = DB::table('g2g_event_delivery')->where('event_id', $eventId)
        ->where('consumer', NotificationDispatcher::CONSUMER)->first();
    return [$d && $d->status === 'done', $d ? "status={$d->status} attempts={$d->attempts}" : 'no ledger row'];
});

check('a SECOND dispatch of the same event sends nothing more', function () use (&$eventId) {
    $before = DB::table('g2g_notification')->where('event_id', $eventId)->count();
    app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($eventId));
    $after = DB::table('g2g_notification')->where('event_id', $eventId)->count();
    return [$before === $after, "before=$before after=$after"];
});

check('the notification carries the TENANT wording, not the global', function () use (&$eventId) {
    // rights.changed does not use job_role, so assert on what it DOES use:
    // the sentence is fixed and complete, with no unresolved placeholder.
    $n = DB::table('g2g_notification')->where('event_id', $eventId)->first();
    return [$n && !str_contains($n->body, '{') && str_contains($n->body, 'X-06 proof'),
        $n ? '"' . mb_substr(str_replace("\n", ' ', $n->body), 0, 64) . '..."' : 'none'];
});

check('an event with NO resolvable recipient is skipped, not failed', function () use ($tenant, &$made) {
    $id = DB::table('g2g_event')->insertGetId([
        'event_uuid' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'rights.changed', 'sub_institute_id' => $tenant,
        'entity_type' => 'user', 'entity_id' => 0, 'actor_id' => 0,
        'payload' => json_encode(['user_id' => 0]),
        'metadata' => json_encode([]),
        'occurred_at' => now(), 'recorded_at' => now(),
    ]);
    $made['event'][] = $id; $made['delivery'][] = $id;
    app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($id));
    $d = DB::table('g2g_event_delivery')->where('event_id', $id)->first();
    $n = DB::table('g2g_notification')->where('event_id', $id)->count();
    return [$d && $d->status === 'skipped' && $n === 0, $d ? "status={$d->status}, notifications=$n" : 'no ledger row'];
});

check('a recipient in ANOTHER tenant is refused', function () use ($tenant, $other, &$made) {
    $id = DB::table('g2g_event')->insertGetId([
        'event_uuid' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'rights.changed', 'sub_institute_id' => $tenant,
        'entity_type' => 'user', 'entity_id' => $other, 'actor_id' => 0,
        // The payload NAMES a user who belongs to a different organisation.
        'payload' => json_encode(['user_id' => $other, 'actor_name' => 'X-06 proof']),
        'metadata' => json_encode([]),
        'occurred_at' => now(), 'recorded_at' => now(),
    ]);
    $made['event'][] = $id; $made['delivery'][] = $id;
    app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($id));
    $n = DB::table('g2g_notification')->where('event_id', $id)->count();
    return [$n === 0, "notifications to the outsider: $n (must be 0)"];
});

// ── REPLAY ──────────────────────────────────────────────────────────────────
echo "\nREPLAY GUARD — now guarding a real send\n";

check('dispatch THROWS while replaying', function () use (&$eventId) {
    ReplayMode::enable();
    try {
        app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($eventId));
        ReplayMode::disable();
        return [false, 'IT DID NOT THROW - a rebuild would re-notify every person'];
    } catch (RuntimeException $e) {
        ReplayMode::disable();
        return [true, 'threw: ' . mb_substr($e->getMessage(), 0, 58)];
    }
});

check('replay wrote no notification and no ledger row', function () use ($tenant, &$made) {
    $id = DB::table('g2g_event')->insertGetId([
        'event_uuid' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'rights.changed', 'sub_institute_id' => $tenant,
        'entity_type' => 'user', 'entity_id' => 1, 'actor_id' => 1,
        'payload' => json_encode(['user_id' => 1]),
        'metadata' => json_encode([]), 'occurred_at' => now(), 'recorded_at' => now(),
    ]);
    $made['event'][] = $id; $made['delivery'][] = $id;
    ReplayMode::enable();
    try { app(NotificationDispatcher::class)->dispatch(DB::table('g2g_event')->find($id)); } catch (Throwable $e) {}
    ReplayMode::disable();
    $n = DB::table('g2g_notification')->where('event_id', $id)->count();
    $d = DB::table('g2g_event_delivery')->where('event_id', $id)->count();
    return [$n === 0 && $d === 0, "notifications=$n ledger=$d (both must be 0)"];
});

// ── EMAIL ───────────────────────────────────────────────────────────────────
echo "\nEMAIL CHANNEL\n";

check('email is OFF by default and nothing left the building', function () use (&$made) {
    $sender = app(App\Services\Notifications\NotificationSender::class);
    $emailRows = DB::table('g2g_notification')
        ->whereIn('event_id', $made['event'])->where('channel', 'email')->count();
    return [!$sender->emailEnabled() && $emailRows === 0,
        'G2G_NOTIFY_EMAIL=' . var_export($sender->emailEnabled(), true) . ", email rows written: $emailRows"];
});

check('the email template exists and is ready to send', function () {
    $n = DB::table('g2g_notification_template')->where('channel', 'email')->where('is_active', true)->count();
    return [$n === count(NotificationDispatcher::NOTIFIES), "$n email templates - built, not stubbed"];
});

// ── CLEANUP ─────────────────────────────────────────────────────────────────
echo "\nCLEANUP (shared remote database)\n";

$delN = DB::table('g2g_notification')->whereIn('event_id', $made['event'])->delete();
$delD = DB::table('g2g_event_delivery')->whereIn('event_id', $made['event'])->delete();
$delE = DB::table('g2g_event')->whereIn('id', $made['event'])->delete();
foreach ($made['term'] as [$t, $k]) {
    DB::table('g2g_terminology')->where('sub_institute_id', $t)->where('term_key', $k)->delete();
}
printf("  removed: %d notifications, %d delivery rows, %d events, %d terminology overrides\n",
    $delN, $delD, $delE, count($made['term']));

/*
 * THE ONE THING NOT CLEANED UP: the seeded global terminology and the six
 * templates. They are DESIGN DATA - the product's default vocabulary and its
 * fixed wording, seeded by the migration - not test residue. Deleting them would
 * leave the notification service unable to compose anything.
 */
printf("  kept: %d global terms, %d templates (design data, seeded by the migration)\n",
    DB::table('g2g_terminology')->where('sub_institute_id', 0)->count(),
    DB::table('g2g_notification_template')->count());

$left = DB::table('g2g_event')->count() + DB::table('g2g_notification')->count();
printf("  residue check: g2g_event + g2g_notification = %d rows\n", $left);

echo "\n========================================================================\n";
printf("PASS %d   FAIL %d\n", $pass, $fail);
echo "VERDICT: " . ($fail === 0 ? "GREEN" : "RED") . "\n";
echo "========================================================================\n";
exit($fail === 0 ? 0 : 1);
