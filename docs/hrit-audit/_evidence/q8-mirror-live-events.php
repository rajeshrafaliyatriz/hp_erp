<?php
/**
 * Q8 step C. Give the `live` host its own copy of the supersession events.
 *
 *   php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/q8-mirror-live-events.php';"
 *
 * EventRecorder writes with a bare `DB::table('g2g_event')` - the DEFAULT
 * connection - and takes no connection argument. So when q8-recompute-apply.php
 * changed a payslip on the `live` host, the event describing that change was
 * written to mysql. The trail existed and was correctly labelled (the host is
 * in both the payload and the idempotency key), but anyone auditing `live` on
 * its own would have found a payslip that changed for no recorded reason.
 *
 * This copies the two `q8.recompute:live:*` events across verbatim, keeping
 * event_uuid, payload, and occurred_at. Only `id` differs, because each store
 * assigns its own.
 *
 * Idempotent: an event already present on live by idempotency_key is skipped.
 *
 * NOTE for whoever writes the next cross-host maintenance script: record the
 * event on the SAME connection as the write. EventRecorder cannot do that
 * today, so either extend it or insert the row directly, as this does.
 */

$source = \Illuminate\Support\Facades\DB::connection('mysql');
$target = \Illuminate\Support\Facades\DB::connection('live');

$events = $source->table('g2g_event')
    ->where('idempotency_key', 'like', 'q8.recompute:live:%')
    ->orderBy('id')
    ->get();

echo "found " . $events->count() . " event(s) on mysql describing live's changes" . PHP_EOL;

foreach ($events as $event) {
    $exists = $target->table('g2g_event')
        ->where('idempotency_key', $event->idempotency_key)
        ->exists();

    if ($exists) {
        echo "  {$event->idempotency_key}: already on live, left alone" . PHP_EOL;
        continue;
    }

    $row = (array) $event;
    unset($row['id']);          // live assigns its own

    $target->table('g2g_event')->insert($row);
    echo "  {$event->idempotency_key}: copied to live" . PHP_EOL;
}

echo PHP_EOL;
foreach (['mysql', 'live'] as $connection) {
    $n = \Illuminate\Support\Facades\DB::connection($connection)
        ->table('g2g_event')
        ->where('idempotency_key', 'like', 'q8.recompute:%')
        ->count();
    echo "  {$connection}: {$n} q8.recompute event(s)" . PHP_EOL;
}
