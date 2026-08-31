<?php

namespace App\Console\Commands;

use App\Services\Events\EventRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emit `certification.expiring`, and keep `status` honest.
 *
 * ── THE GAP THIS FILLS ──────────────────────────────────────────────────────
 *
 * The renewal chain was built from the middle outwards and never got its head.
 * `RemediationRecommender` handles `certification.expiring`; `NotificationDispatcher`
 * lists it in NOTIFIES; `05-data-flow-contracts.md:243` specifies a T-30/T-7
 * sweep. NOTHING EMITTED IT. An exhaustive grep of `->record(` across app/ found
 * eight call sites and not one certification-expiry among them.
 *
 * The single `certification.expiring` row in each store was a hand-copied test
 * event — same id and millisecond on both databases, naming a certification valid
 * until 2027. So the chain has never carried a real signal end to end.
 *
 * ── WHY `<= window` AND NOT `== window` ─────────────────────────────────────
 *
 * The obvious sweep asks "is this exactly 30 days out today?" and misses the
 * window permanently the first day the scheduler does not run — a deploy, an
 * outage, a clock skew. Asking `<= window` instead means a missed day is caught
 * up on the next run, and the idempotency key is what keeps it to one emission
 * rather than one per day.
 *
 * ── WHY ONLY THE TIGHTEST WINDOW ────────────────────────────────────────────
 *
 * A certificate five days from lapsing satisfies both the 30-day and the 7-day
 * test. Emitting both would announce, today, a warning that was due three weeks
 * ago — backdated history in an append-only store. Only the tightest unmet window
 * is emitted, so the event says where the certificate actually is now. As it
 * crosses each further threshold, the next run emits the next one.
 *
 * ── WHAT IS DELIBERATELY NOT EMITTED ────────────────────────────────────────
 *
 *   already lapsed (62 rows on each database) .. expired is not expiring. There is
 *                     no `certification.expired` in the catalogue and inventing one
 *                     to fit a backlog would be a product decision made sideways.
 *                     Their `status` is corrected below; telling those holders is a
 *                     separate call for a person to make.
 *   revoked ......... withdrawn, not lapsing.
 *   no user_id ...... nobody to tell.
 *   soft-deleted .... not a certification any more.
 *
 * ── THE STATUS COLUMN WAS ALSO WRONG, AND NOTHING OWNED IT ──────────────────
 *
 * Measured identically on both databases: 62 rows sit past their `expiry_date`
 * while only 22 carry `status='expired'`. No code recomputed it — the column was
 * written once at creation and left. Since this command is already the thing that
 * walks every certification against today's date, it owns the correction.
 */
class ScanCertificationExpiry extends Command
{
    protected $signature = 'certifications:scan-expiry
                            {--windows=30,7 : Comma-separated day thresholds, used when a requirement names none}
                            {--limit=1000 : Maximum certifications examined per run}
                            {--database= : Connection to sweep; omit for the default}
                            {--dry-run : Report what would be emitted and changed, write nothing}';

    protected $description = 'Emit certification.expiring for certifications crossing a renewal window, and correct stale expiry status.';

    public function __construct(private EventRecorder $recorder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
         * Set BEFORE any query. EventRecorder resolves DB::table() at call time
         * too, so switching the default here carries the emission to the same
         * database as the sweep — a scan of live that appended events to dev
         * would be worse than not running at all.
         */
        $connection = (string) ($this->option('database') ?? '');
        if ($connection !== '') {
            DB::setDefaultConnection($connection);
            $this->line("  using connection: {$connection}");
        }

        $dry     = (bool) $this->option('dry-run');
        $today   = now()->toDateString();
        $limit   = max(1, (int) $this->option('limit'));
        $default = $this->windows((string) $this->option('windows'));

        if ($default === []) {
            $this->error('No valid windows given.');

            return self::FAILURE;
        }

        $stale = $this->correctStatus($today, $dry);
        $this->line(sprintf('  %-28s %d row(s)%s', 'status corrected', $stale, $dry ? ' (dry run)' : ''));

        /*
         * `renewal_reminder_days` on the requirement is the customer's own policy
         * and beats the flag. It is populated on all 15/16 requirement rows — but
         * only 3 certifications carry a `requirement_id`, so the fallback is the
         * live path for essentially everything today. Both are honoured rather
         * than picking one, because the join is expected to improve.
         */
        $rows = DB::table('s_competency_certifications as c')
            ->leftJoin('s_competency_certification_requirements as r', function ($join) {
                $join->on('r.id', '=', 'c.requirement_id')->whereNull('r.deleted_at');
            })
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.expiry_date')
            ->whereNotNull('c.user_id')
            ->where('c.user_id', '>', 0)
            ->whereRaw('LOWER(COALESCE(c.status, \'\')) <> ?', ['revoked'])
            ->whereDate('c.expiry_date', '>=', $today)
            ->orderBy('c.expiry_date')
            ->limit($limit)
            ->get([
                'c.id', 'c.sub_institute_id', 'c.user_id', 'c.competency_id',
                'c.name', 'c.expiry_date', 'r.renewal_reminder_days',
            ]);

        $emitted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $tenant = (int) $row->sub_institute_id;

            if ($tenant <= 0) {
                // EventRecorder refuses a tenantless event, and rightly. Counting
                // it rather than letting the exception end the sweep.
                $skipped++;
                continue;
            }

            $days = (int) floor((strtotime((string) $row->expiry_date) - strtotime($today)) / 86400);

            $windows = $row->renewal_reminder_days !== null && (int) $row->renewal_reminder_days > 0
                ? [(int) $row->renewal_reminder_days]
                : $default;

            // Tightest window this certificate has already crossed.
            $applicable = array_filter($windows, fn ($w) => $days <= $w);

            if ($applicable === []) {
                continue;
            }

            $window = min($applicable);
            $key    = 'certification.expiring:' . $tenant . ':' . (int) $row->id . ':' . $window;

            if ($dry) {
                $this->line(sprintf('    would emit  cert #%-6d T-%-3d (%d day(s) left)  %s',
                    $row->id, $window, $days, $row->name));
                $emitted++;
                continue;
            }

            try {
                $this->recorder->record(
                    'certification.expiring',
                    $tenant,
                    'certification',
                    (int) $row->id,
                    // No actor: the passage of time is not somebody's action. A
                    // scheduler is not a person and recording it as one would put
                    // a fictional name on the audit trail.
                    null,
                    [
                        'certification_id' => (int) $row->id,
                        'user_id'          => (int) $row->user_id,
                        'competency_id'    => $row->competency_id ? (int) $row->competency_id : null,
                        /*
                         * `certification_name`, NOT `certification`. The key is
                         * dictated by the notification templates, which read
                         * `{payload.certification_name}` — both the email and the
                         * in-app row for this event type. The first live run of
                         * this chain proved why that matters: the notification
                         * arrived, correctly addressed, reading
                         *
                         *   "— expires on 2026-09-03."
                         *
                         * with the placeholder resolving to nothing and the em-dash
                         * left stranded. A notification that arrives and says
                         * nothing is worse than one that never arrives, because it
                         * looks like the system is working.
                         */
                        'certification_name' => $row->name,
                        'expiry_date'      => (string) $row->expiry_date,
                        'days_remaining'   => $days,
                        'window_days'      => $window,
                    ],
                    null,
                    $key
                );
                $emitted++;
            } catch (QueryException $e) {
                /*
                 * `uq_event_idem` is unique on both databases, so a re-run of a
                 * window already emitted lands here. That is the mechanism
                 * working, not a failure — the daily sweep is SUPPOSED to
                 * re-examine every certificate and emit nothing new.
                 */
                if ($this->isDuplicateKey($e)) {
                    $skipped++;
                    continue;
                }

                throw $e;
            }
        }

        $this->info(sprintf('%s %d certification(s) examined, %d event(s) emitted, %d already known.',
            $dry ? 'Dry run:' : 'Scanned:', $rows->count(), $emitted, $skipped));

        if ($emitted > 0 && ! $dry) {
            Log::channel('single')->info('certification.expiry_scan', [
                'examined' => $rows->count(),
                'emitted'  => $emitted,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Bring `status` into line with `expiry_date`.
     *
     * Only touches rows whose status is a lifecycle value this owns. `revoked` is
     * a decision somebody made and outranks the calendar — a revoked certificate
     * that later passes its expiry date is still revoked, and overwriting that
     * with `expired` would erase why it stopped counting.
     */
    private function correctStatus(string $today, bool $dry): int
    {
        $lapsed = DB::table('s_competency_certifications')
            ->whereNull('deleted_at')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->whereRaw('LOWER(COALESCE(status, \'\')) NOT IN (?, ?)', ['revoked', 'expired']);

        $count = (clone $lapsed)->count();

        if ($count > 0 && ! $dry) {
            $lapsed->update(['status' => 'expired', 'updated_at' => now()]);
        }

        return $count;
    }

    /** @return int[] */
    private function windows(string $raw): array
    {
        $parsed = array_filter(
            array_map(fn ($p) => (int) trim($p), explode(',', $raw)),
            fn ($n) => $n > 0
        );

        // Descending is irrelevant to correctness — min() picks the tightest —
        // but a sorted list makes the --dry-run output readable.
        rsort($parsed);

        return array_values(array_unique($parsed));
    }

    /** MySQL/MariaDB 1062, surfaced through PDO as SQLSTATE 23000. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
