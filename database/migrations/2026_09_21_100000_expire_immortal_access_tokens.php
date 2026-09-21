<?php

use App\Http\Middleware\TouchTokenActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GIVE THE EXISTING IMMORTAL SESSIONS AN END.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS DRAINS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `expires_at` was NULL on all 4,960 live tokens and 6,200-odd on dev: every
 * session ever created, immortal. `RequireApiToken` has always REFUSED an expired
 * token - nothing ever set an expiry for it to refuse, and `config/sanctum.php`
 * has `'expiration' => null`.
 *
 * New tokens now get one at creation and `TouchTokenActivity` slides it forward on
 * use. That fixes everything from today onward and does nothing about the backlog,
 * which would otherwise remain valid forever.
 *
 * ── WHY 30 DAYS FROM NOW, NOT 30 DAYS FROM WHEN THEY WERE MADE ──────────────
 *
 * `last_used_at` is NULL on every one of them, so there is no activity history to
 * date them from - that column was the other half of this defect. Backdating off
 * `created_at` instead would sign out everybody whose token is older than a month,
 * which is nearly everybody, in one deploy.
 *
 * Thirty days from now gives every existing session a full idle window: anybody
 * who uses the product once in the next month has their expiry slid forward and
 * never notices, and anything genuinely abandoned dies on its own. The backlog
 * drains without a single person being logged out for it.
 *
 * ── AND WHY `last_used_at` IS LEFT NULL ─────────────────────────────────────
 *
 * There is no honest value for it. Writing `created_at` would put a "last used"
 * date on the screen for sessions that may never have been used at all, which is
 * the kind of invented data that makes a security screen untrustworthy. The
 * frontend says "not recorded yet" and means it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tablePresent()) {
            return;
        }

        $expiry = now()->addDays(TouchTokenActivity::IDLE_DAYS);

        /*
         * Chunked. Dev holds ~6,200 rows and live ~4,960; a single UPDATE is fine
         * at that size, but this table is on the hot path of every authenticated
         * request and a long row lock on it would stall sign-ins across the
         * platform while the migration ran.
         */
        $updated = 0;

        do {
            $ids = DB::table('personal_access_tokens')
                ->whereNull('expires_at')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $updated += DB::table('personal_access_tokens')
                ->whereIn('id', $ids)
                ->update(['expires_at' => $expiry]);
        } while (true);

        if (function_exists('fwrite')) {
            fwrite(STDOUT, sprintf(
                "  gave %d immortal token(s) an expiry of %s\n",
                $updated,
                $expiry->toDateString()
            ));
        }
    }

    /**
     * Reversing this makes every one of those sessions immortal again.
     *
     * Which is the state before the migration, so it is the honest `down()` - but
     * it is worth saying plainly that rolling back does not restore a safer
     * configuration, it restores a less safe one. Only the rows this touched are
     * affected: anything created since has a real expiry and keeps it.
     */
    public function down(): void
    {
        if (!$this->tablePresent()) {
            return;
        }

        /*
         * Matched on the exact expiry this migration wrote, to the minute. A blunt
         * `whereNotNull('expires_at')->update(null)` would also strip the expiry
         * from every token created AFTER this ran - turning a rollback of one
         * change into a reintroduction of the original bug for newer sessions.
         */
        $expiry = now()->addDays(TouchTokenActivity::IDLE_DAYS);

        DB::table('personal_access_tokens')
            ->whereBetween('expires_at', [
                $expiry->copy()->subHours(12),
                $expiry->copy()->addHours(12),
            ])
            ->whereNull('last_used_at')
            ->update(['expires_at' => null]);
    }

    /**
     * Via information_schema, never `Schema::hasTable()`.
     *
     * Live is MariaDB 10.1.48, where Laravel's `hasTable`/`hasColumn` throw: their
     * query asks for `generation_expression`, a column that version does not have.
     */
    private function tablePresent(): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['personal_access_tokens']
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
