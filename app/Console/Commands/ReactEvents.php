<?php

namespace App\Console\Commands;

use App\Services\Events\CertificateIssuer;
use App\Services\Events\LearningAssigner;
use App\Services\Events\OnboardingLauncher;
use App\Services\Events\OfferLetterFiler;
use App\Services\Events\NotificationDispatcher;
use App\Services\Events\RemediationRecommender;
use App\Services\Events\ReplayMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drive the event store's REACTORS.
 *
 * ── WHY THIS IS A SEPARATE COMMAND FROM events:project ──────────────────────
 *
 * EventCatalogue splits consumers into two kinds and the distinction is load
 * bearing:
 *
 *   PROJECTOR (P)  pure, replayable; its ledger is CLEARED on a rebuild
 *   REACTOR   (R)  touches the world; its ledger is PERMANENT
 *
 * A projector re-derives a table from the store, so running it twice is free. A
 * reactor enrols people on courses, issues certificates and sends mail — running
 * one twice does it twice. `events:project` therefore must never grow a reactor
 * in its list, and this command must never delete a ledger row.
 *
 * The separation is enforced by POLICY, NOT SCHEMA — both kinds share the single
 * `g2g_event_delivery` table, and `ReplayRunner` keeps them apart by only ever
 * deleting rows for the one projector it is rebuilding. The hard guard is
 * `ReplayMode::assertNotReplaying()`, the first line of every reactor's
 * dispatch(), which THROWS rather than no-ops.
 *
 * ── WHAT WAS ACTUALLY BROKEN ────────────────────────────────────────────────
 *
 * Measured on both databases before this was written:
 *
 *   g2g_event_delivery, reactor consumers ....... 0 rows. None. Ever.
 *   g2g_notification ............................ 0 rows, against 14 templates
 *   lms_assignments WHERE origin_event_id IS NOT NULL ... 0
 *   suggested_course written by a reactor ....... 0 (all 12 rows are from a
 *                                                 human in Feb, pre-dating the
 *                                                 event store entirely)
 *
 * Four complete, correct reactor classes had no `catchUp()` and no caller. That
 * is the whole defect. It is why no certification renewal has ever fired, and why
 * a task rejection has never notified anybody — `task.rejected` is in
 * NotificationDispatcher::NOTIFIES and always has been.
 *
 * ── THE BACKLOG QUESTION, CHECKED RATHER THAN ASSUMED ───────────────────────
 *
 * Switching a reactor on points it at every event it has never seen, which on a
 * busy store would mean mailing out weeks of stale notices in one burst. Counted
 * first: the notifiable backlog was ONE event per database, and it was a fake —
 * a hand-copied test row, identical id and timestamp on both sides, naming a
 * certification that expires in 2027. It is deleted by the migration that
 * accompanies this command, so the first real run starts from an honest store.
 */
class ReactEvents extends Command
{
    protected $signature = 'events:react
                            {--consumer= : Run one reactor by its CONSUMER name}
                            {--limit=200 : Maximum events per reactor per run}
                            {--database= : Connection to drain; omit for the default}
                            {--pending : Report the backlog and exit without dispatching}';

    protected $description = 'Deliver recorded events to their reactors (notifications, learning assignment, certificates, remediation).';

    /**
     * Every consumer EventCatalogue marks R.
     *
     * Resolved through the container rather than `new`, because CertificateIssuer
     * takes an EventRecorder and NotificationDispatcher takes a RecipientResolver
     * and a NotificationSender.
     */
    private const REACTORS = [
        NotificationDispatcher::class,
        LearningAssigner::class,
        CertificateIssuer::class,
        RemediationRecommender::class,
        /*
         * The consumer employee.hired never had. Until this line existed the
         * event was written on every hire and read by nobody, so the
         * recruitment-to-onboarding chain stopped at the hire and a human had to
         * restart it by hand on another screen.
         */
        OnboardingLauncher::class,
        /*
         * Files the offer letter into the new employee's own documents, so they
         * can download it from their profile without HR attaching it by hand.
         */
        OfferLetterFiler::class,
    ];

    public function handle(): int
    {
        /*
         * BELT AND BRACES. Every reactor asserts this itself on its first line,
         * so this cannot be the only guard — but failing here names the command
         * rather than surfacing as one reactor's exception mid-loop.
         */
        if (ReplayMode::active()) {
            $this->error('Refusing to run: a replay is in progress. Reactors must never fire during a rebuild.');

            return self::FAILURE;
        }

        // Before any reactor is resolved: they read the ledger and write side
        // effects through DB::table(), so the connection must be settled first.
        $connection = (string) ($this->option('database') ?? '');
        if ($connection !== '') {
            DB::setDefaultConnection($connection);
            $this->line("  using connection: {$connection}");
        }

        $only    = (string) ($this->option('consumer') ?? '');
        $limit   = max(1, (int) $this->option('limit'));
        $dryRun  = (bool) $this->option('pending');
        $total   = 0;
        $failed  = 0;
        $pending = 0;

        foreach (self::REACTORS as $class) {
            $consumer = $class::CONSUMER;

            if ($only !== '' && $only !== $consumer) {
                continue;
            }

            try {
                $reactor = app($class);
                $waiting = (int) $reactor->pendingCount();
                $pending += $waiting;

                if ($dryRun) {
                    $this->line(sprintf('  %-32s %d event(s) waiting', $consumer, $waiting));
                    continue;
                }

                $done = (int) $reactor->catchUp($limit);
                $total += $done;
                $this->line(sprintf('  %-32s %d event(s)', $consumer, $done));
            } catch (\Throwable $e) {
                /*
                 * One failing reactor must not stop the others — they are
                 * independent consumers of one store, and letting an exception in
                 * the notifier abort the loop would silently take renewals with
                 * it. Per-event failures are already caught and ledgered inside
                 * catchUp(); this catches a reactor that cannot start at all.
                 */
                $failed++;
                $this->error(sprintf('  %-32s FAILED: %s', $consumer, $e->getMessage()));
                Log::error('Event reaction failed', [
                    'consumer' => $consumer,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        if ($dryRun) {
            $this->info(sprintf('%d event(s) awaiting a reactor.', $pending));

            return self::SUCCESS;
        }

        $this->info(sprintf('Reacted to %d event(s).', $total));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
