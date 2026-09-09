<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

/**
 * WHAT ONE ORGANISATION HAS CHOSEN — the counterpart to UserPreferences.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY tenant_setting AND NOT A NEW TABLE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * It already exists, it already has a unique `(sub_institute_id, setting_key)`,
 * and `FlatCapRule` and `ReportingLineValidator` already read it. It holds ZERO
 * rows on both databases, which is not evidence that it is unused - it is
 * evidence that every setting in it has so far been left at its default, which
 * is exactly what a defaults-with-overrides store looks like when nobody has
 * changed anything.
 *
 * ── THE SAME SHAPE AS UserPreferences, DELIBERATELY ─────────────────────────
 *
 * `DEFAULTS` is simultaneously the allow-list, the type map and the fallback. A
 * key absent from it cannot be written and is never returned. Somebody who has
 * learnt to read one of these two classes can read the other, and the pair make
 * the distinction the product needs: per-person choices in `user_preferences`,
 * per-organisation ones here.
 *
 * ── WHERE THE TWO MEET ──────────────────────────────────────────────────────
 *
 * They overlap on formatting, and the person wins. An organisation's date
 * format is what somebody sees BEFORE they express a preference of their own;
 * it is a default for the organisation, not a rule imposed on it. That is why
 * neither this class nor UserPreferences knows about the other - the resolution
 * happens at the point of use, and it is always "the person's, else the
 * organisation's, else the product's".
 */
class TenantSettings
{
    private const TABLE = 'tenant_setting';

    /** VARCHAR + PHP const, never an ENUM - live is MariaDB 10.1. */
    public const DATE_FORMATS = ['dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd'];

    public const NUMBER_FORMATS = ['1,23,456.78', '123,456.78', '123.456,78'];

    public const WEEK_STARTS = ['monday', 'sunday', 'saturday'];

    /**
     * Every organisation-level setting this product knows about.
     *
     * The prefix is the module that owns it, matching what `FlatCapRule` already
     * stores (`payroll.flat_cap_behaviour`), so one tenant's rows stay legible
     * when several modules keep settings side by side.
     */
    public const DEFAULTS = [
        // ── Working week and calendar ───────────────────────────────────────
        'org.week_start' => 'monday',
        // Which days are working days, as a 7-character mask starting Monday.
        // A string rather than seven booleans because it is one decision, and
        // because MariaDB 10.1 has no JSON column to put an array in.
        'org.working_days' => '1111100',
        // The month the financial year starts in. April in India, which is
        // where every live tenant is.
        'org.financial_year_start_month' => '4',

        // ── Presentation defaults ───────────────────────────────────────────
        'org.currency' => 'INR',
        'org.date_format' => 'dd/mm/yyyy',
        'org.number_format' => '1,23,456.78',
        'org.timezone' => 'Asia/Kolkata',

        // ── Security policy ─────────────────────────────────────────────────
        // The MINIMUM is 8 wherever a password is set, enforced by
        // PasswordController::rule(). This raises it; it cannot lower it.
        'security.password_min_length' => '8',
        'security.password_require_symbol' => '0',
        // How long an invite or reset link stays usable.
        'security.invite_hours' => '24',
        // Whether signing in with a one-time code by SMS is allowed at all.
        // The switch that would have made the hardcoded-OTP backdoor moot.
        'security.otp_login_enabled' => '1',
    ];

    /** Read everything for one organisation, defaults filled in. */
    public function all(int $tenantId): array
    {
        $stored = DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->pluck('setting_value', 'setting_key');

        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = $stored->has($key) ? (string) $stored[$key] : $default;
        }

        return $out;
    }

    /** One value, without reading the whole set. */
    public function get(int $tenantId, string $key): ?string
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return null;
        }

        $stored = DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('setting_key', $key)
            ->value('setting_value');

        return $stored !== null ? (string) $stored : self::DEFAULTS[$key];
    }

    /**
     * Write the keys present in $values. Anything else is ignored.
     *
     * @return array the settings as they now stand
     */
    public function save(int $tenantId, array $values): array
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            DB::table(self::TABLE)->updateOrInsert(
                ['sub_institute_id' => $tenantId, 'setting_key' => $key],
                /*
                 * The closure form, so `created_at` is written on the insert and
                 * left alone on the update. Passing it in a flat array resets
                 * the creation timestamp on every save - the exact bug an
                 * adversarial review found in UserPreferences::put().
                 */
                fn (bool $exists) => $exists
                    ? ['setting_value' => (string) $values[$key], 'updated_at' => now()]
                    : ['setting_value' => (string) $values[$key], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->all($tenantId);
    }

    /**
     * PER-ROLE settings, which the flat DEFAULTS map cannot hold.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY THESE NEED THEIR OWN PATH
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `DEFAULTS` is a fixed allow-list, and that is exactly what makes it safe:
     * a key absent from it cannot be written. Role settings cannot live in it,
     * because the key contains a role name and there are nine of those - listing
     * all 9 x N combinations would be a map nobody maintains.
     *
     * So the allow-list moves down a level: the SUFFIX is fixed, and the role is
     * checked against `RoleKey::ALL`. A caller who invents either half writes
     * nothing. The stored key is `role.<role_key>.<suffix>`, which reads the
     * same way as `org.` and `security.` beside it.
     */
    public const ROLE_DEFAULTS = [
        'landing_page' => 'dashboard',
        'notify_email' => '1',
    ];

    /** Everything stored for one role, defaults filled in. */
    public function forRole(int $tenantId, string $roleKey): array
    {
        if (!in_array($roleKey, \App\Support\RoleKey::ALL, true)) {
            return self::ROLE_DEFAULTS;
        }

        $stored = DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('setting_key', 'like', 'role.' . $roleKey . '.%')
            ->pluck('setting_value', 'setting_key');

        $out = [];

        foreach (self::ROLE_DEFAULTS as $suffix => $default) {
            $key = 'role.' . $roleKey . '.' . $suffix;
            $out[$suffix] = $stored->has($key) ? (string) $stored[$key] : $default;
        }

        return $out;
    }

    /** Write the role settings present in $values. Anything else is ignored. */
    public function saveForRole(int $tenantId, string $roleKey, array $values): array
    {
        if (!in_array($roleKey, \App\Support\RoleKey::ALL, true)) {
            return self::ROLE_DEFAULTS;
        }

        foreach (self::ROLE_DEFAULTS as $suffix => $default) {
            if (!array_key_exists($suffix, $values)) {
                continue;
            }

            DB::table(self::TABLE)->updateOrInsert(
                ['sub_institute_id' => $tenantId, 'setting_key' => 'role.' . $roleKey . '.' . $suffix],
                fn (bool $exists) => $exists
                    ? ['setting_value' => (string) $values[$suffix], 'updated_at' => now()]
                    : ['setting_value' => (string) $values[$suffix], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->forRole($tenantId, $roleKey);
    }

    /**
     * The working days, as day names, for a screen that has to render them.
     *
     * Monday-first because the mask is, and because `org.week_start` decides
     * where a CALENDAR begins, not what the mask means. Conflating the two is
     * how a Sunday-start organisation ends up with its working days rotated.
     */
    public static function workingDayNames(string $mask): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $out = [];

        foreach ($days as $index => $name) {
            if (($mask[$index] ?? '0') === '1') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
