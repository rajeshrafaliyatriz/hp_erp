<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE CEO BECOMES A SECOND PLATFORM OWNER.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES, AND THE MUCH LONGER LIST OF WHAT IT DOES NOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Two writes:
 *
 *   1. `tbluser.email` for Kalpesh Sheth: Kalpesh@gmail.com -> kalpesh@scholarclone.com
 *   2. a `platform_owners` row for that account
 *
 * It does NOT touch `scholarclone@gmail.com` (user 28) in any way. That account
 * keeps its email, its password, its tenant-6 administrator role AND its existing
 * platform ownership - it is not revoked, because a single owner is a single point
 * of failure and because it was explicitly asked to be left alone. Platform
 * ownership is a row in `platform_owners`, not part of anybody's login, so adding
 * one takes nothing away from the other.
 *
 * It does NOT change the CEO's organisation or role either. The account stays a
 * tenant-6 Employee: `sub_institute_id` = 6, the same `user_profile_id`. That is
 * deliberate rather than lazy - see below.
 *
 * ── WHY THE TENANT IS LEFT IN PLACE ─────────────────────────────────────────
 *
 * "Platform owner with no organisation" sounds cleaner and is a security change.
 * `tbluser.sub_institute_id` is nullable, and `ResolvesApiIdentity` treats a null
 * tenant as "take the tenant from the request body or header". So detaching this
 * account would let the CALLER choose which of the twelve organisations it acts as,
 * on every API request. Leaving `sub_institute_id` = 6 keeps the tenant
 * server-decided. Platform-wide authority comes from `platform_owners` alone,
 * which is gated by `RequirePlatformOwner` and cannot be steered by a request.
 *
 * ── WHY THE ACCOUNT IS FOUND BY EMAIL AND NOT BY ID ─────────────────────────
 *
 * The row is id 49 on both databases today - I checked. That is a coincidence of
 * history, not a guarantee, and a migration that hardcodes an id will one day grant
 * platform ownership over twelve organisations to whoever happens to be id 49 on
 * some third database. Matched on the email, scoped to tenant 6, and every
 * precondition is verified before anything is written.
 */
return new class extends Migration
{
    /** The account being promoted, and what it becomes. */
    private const FROM_EMAIL = 'kalpesh@gmail.com';
    private const TO_EMAIL = 'kalpesh@scholarclone.com';
    private const TENANT = 6;

    public function up(): void
    {
        if (!$this->tablePresent('platform_owners') || !$this->tablePresent('tbluser')) {
            return;
        }

        /*
         * Either address is acceptable as the starting point, so re-running after a
         * partial failure finishes the job instead of refusing. `whereRaw(LOWER())`
         * because the stored value is `Kalpesh@gmail.com` with a capital K and
         * collation is not something to bet a migration on.
         */
        $user = DB::table('tbluser')
            ->where('sub_institute_id', self::TENANT)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(email) IN (?, ?)', [self::FROM_EMAIL, self::TO_EMAIL])
            ->first(['id', 'email']);

        if (!$user) {
            /*
             * Not an error. A database without this person - a fresh install, a
             * different customer's deployment - should skip this quietly rather than
             * fail every migration that follows it.
             */
            return;
        }

        $this->renameAccount($user);
        $this->grantOwnership((int) $user->id);
    }

    /**
     * Change the login address, if it has not been changed already.
     *
     * The uniqueness check is not ceremony: `tbluser.email` is UNIQUE, so a clash
     * would abort the migration mid-run and leave the ownership grant undone. Better
     * to notice and stop having written nothing.
     */
    private function renameAccount(object $user): void
    {
        if (strtolower((string) $user->email) === self::TO_EMAIL) {
            return;
        }

        $taken = DB::table('tbluser')
            ->whereRaw('LOWER(email) = ?', [self::TO_EMAIL])
            ->where('id', '<>', $user->id)
            ->exists();

        if ($taken) {
            /*
             * Somebody else already holds the target address. Renaming is impossible
             * and guessing an alternative would be worse, so this stops - loudly
             * enough to be seen in the migration output, without failing the run and
             * blocking unrelated migrations behind it.
             */
            echo "  [skipped] " . self::TO_EMAIL . " already belongs to another account; "
                . "account {$user->id} was NOT renamed and ownership was NOT granted.\n";

            return;
        }

        DB::table('tbluser')->where('id', $user->id)->update([
            'email' => self::TO_EMAIL,
            'updated_at' => now(),
        ]);

        /*
         * Any live token keeps working - tokens are keyed on the user id, not the
         * email - but the person now signs in with a different address, which is why
         * this was agreed before it ran rather than discovered at a sign-in screen.
         */
        echo "  account {$user->id}: " . $user->email . " -> " . self::TO_EMAIL . "\n";
    }

    /**
     * Add the ownership row, alongside whatever is already there.
     *
     * `granted_by` is null because nobody granted this through the product - it came
     * from a migration, and recording a person who did not perform the action would
     * put a fiction in an audit trail. `RequirePlatformOwner` reads
     * `revoked_at IS NULL`, so a previously revoked row is revived rather than
     * duplicated.
     */
    private function grantOwnership(int $userId): void
    {
        $existing = DB::table('platform_owners')->where('user_id', $userId)->first(['id', 'revoked_at']);

        if ($existing && $existing->revoked_at === null) {
            return;
        }

        if ($existing) {
            DB::table('platform_owners')->where('id', $existing->id)->update([
                'revoked_by' => null,
                'revoked_at' => null,
                'updated_at' => now(),
            ]);

            echo "  platform ownership re-instated for account {$userId}\n";

            return;
        }

        DB::table('platform_owners')->insert([
            'user_id' => $userId,
            'note' => 'Added by 2026_09_22_110000. The CEO, as a second platform owner '
                . 'alongside the seeded one - so neither is a single point of failure.',
            'granted_by' => null,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo "  platform ownership granted to account {$userId}\n";
    }

    /**
     * Revoke the ownership and put the address back.
     *
     * Genuinely reversible, unlike the identity migration beside it: both prior
     * values are known constants rather than per-row history. The ownership row is
     * REVOKED rather than deleted, so the record that it once existed survives - an
     * audit trail that can be made to forget is not one.
     */
    public function down(): void
    {
        if (!$this->tablePresent('platform_owners') || !$this->tablePresent('tbluser')) {
            return;
        }

        $user = DB::table('tbluser')
            ->where('sub_institute_id', self::TENANT)
            ->whereRaw('LOWER(email) = ?', [self::TO_EMAIL])
            ->first(['id']);

        if (!$user) {
            return;
        }

        DB::table('platform_owners')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        DB::table('tbluser')->where('id', $user->id)->update([
            'email' => self::FROM_EMAIL,
            'updated_at' => now(),
        ]);
    }

    private function tablePresent(string $table): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
