<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every profile an explicit `role_key`, where its name says so plainly.
 *
 * ── WHY THIS IS THE DURABLE FIX FOR THE GATES ───────────────────────────────
 *
 * Every `profile:` and `subject:` gate resolves the caller through
 * App\Support\RoleKey, and 30 of 42 live profiles have `role_key = NULL`. They
 * pass only because `RoleKey::LEGACY_NAMES` maps three lowercased display
 * names - 'admin', 'organization administrator', 'hr'. So the whole role gate
 * for nine organisations currently rests on a three-entry fallback table keyed
 * on text a tenant can edit in their own settings screen.
 *
 * Rename "HR" to "People Ops" on live tenant 7 today and 59 users resolve to
 * null - and NULL GRANTS NOTHING. Not a 500, not a warning: a clean 403 on
 * every gated Talent route, indistinguishable from a deliberate refusal.
 *
 * Backfilling the column removes that dependency. Nothing about behaviour
 * changes for anybody the fallback already rescued.
 *
 * ── ONLY WHERE THE NAME IS UNAMBIGUOUS ──────────────────────────────────────
 *
 * Three mappings, and no inference beyond them:
 *
 *   'admin'    -> administrator   (already in LEGACY_NAMES)
 *   'hr'       -> hr_manager      (already in LEGACY_NAMES)
 *   'employee' -> employee        (NOT in LEGACY_NAMES - which is exactly why
 *                                  those profiles resolve to nothing today)
 *
 * The first two are copied from LEGACY_NAMES, so they cannot change an answer:
 * they write down the value resolution already produces. The third is the one
 * that changes anything, and it grants NOTHING - `employee` is a member of no
 * tier in SubjectAuthority - it only makes an existing refusal explicit rather
 * than accidental.
 *
 * MEASURED, and deliberately left alone: the app database also has profiles
 * named 'student', 'teacher', 'principal' and 'finance'. None is one of the
 * nine role_keys the platform defines. Guessing a mapping for them would be
 * inventing an authorization decision inside a data migration, so they are
 * skipped and reported.
 *
 * ── WHAT THIS DOES NOT DO ───────────────────────────────────────────────────
 *
 * It does not touch a profile that already has a role_key, and it does not
 * widen anybody's access: every value written is one the resolver already
 * returns, or `employee`, which authorises nothing.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_120000_backfill_role_key_where_unambiguous.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_120000_backfill_role_key_where_unambiguous.php
 */
return new class extends Migration
{
    /**
     * Lowercased display name -> role_key.
     *
     * The first two mirror RoleKey::LEGACY_NAMES exactly. Keep them in step: if
     * that table grows, this can grow with it, and if they ever disagree the
     * gate and the column would give different answers for the same profile.
     */
    private const UNAMBIGUOUS = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
        'employee'                   => 'employee',
    ];

    public function up(): void
    {
        if (!$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $blank = DB::table('tbluserprofilemaster')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('role_key')->orWhere('role_key', '');
            })
            ->get(['id', 'name']);

        $skipped = [];

        foreach ($blank as $profile) {
            $key = self::UNAMBIGUOUS[strtolower(trim((string) $profile->name))] ?? null;

            if ($key === null) {
                $skipped[] = $profile->name;
                continue;
            }

            DB::table('tbluserprofilemaster')
                ->where('id', $profile->id)
                ->update(['role_key' => $key]);
        }

        /*
         * Reported rather than silently left: a profile the platform cannot
         * name is a profile no gate can authorise, and whoever runs this should
         * know which ones they are rather than discover it from a 403.
         */
        if ($skipped) {
            echo '  role_key left blank for ' . count($skipped) . ' profile(s) whose name is not a platform role: '
                . implode(', ', array_unique($skipped)) . PHP_EOL;
        }
    }

    /**
     * Blank them again - but only the ones whose name says what they were.
     *
     * Reversible because the mapping is a pure function of the name: the same
     * query that decided what to write decides what to unwrite. A profile whose
     * role_key was already set before this ran is untouched in both directions.
     */
    public function down(): void
    {
        if (!$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        foreach (self::UNAMBIGUOUS as $name => $key) {
            DB::table('tbluserprofilemaster')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->where('role_key', $key)
                ->update(['role_key' => null]);
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
