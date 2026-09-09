<?php

namespace App\Services\Account;

use App\Services\Events\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * WHAT ONE PERSON HAS CHOSEN — the product's first per-user store.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEFAULTS ARE DECLARED, NOT IMPLIED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `DEFAULTS` below is simultaneously the allow-list, the type map and the
 * fallback. A key absent from it cannot be written and is never returned, so a
 * request body carrying an unexpected field creates no stray row - and a
 * preference nobody has ever set reads as its default rather than null, so no
 * caller has to guard for a missing value.
 *
 * The pattern is lifted from `ManagesCompetencySettings`, which is the cleanest
 * settings store already here. What is new is the SCOPE: this one is keyed by
 * user, and nothing in this product has been before.
 *
 * ── WHY THE THEME DEFAULT IS 'system' AND NOT 'light' ───────────────────────
 *
 * Because the honest default for "which theme?" is "I have not been asked".
 * `system` follows the operating system, which is what somebody who has never
 * opened these settings almost certainly wants, and it means the first render
 * is not a guess that has to be corrected.
 *
 * ── NOTIFICATION KEYS ARE DERIVED FROM THE DISPATCHER ───────────────────────
 *
 * Not typed out. `NotificationDispatcher::NOTIFIES` is the list of events that
 * can reach somebody; a hand-written copy here would drift the first time an
 * eleventh event is added, and the drift would be silent - a new event that
 * nobody can opt out of.
 */
class UserPreferences
{
    private const TABLE = 'user_preferences';

    /** Theme choices. VARCHAR + const, never an ENUM - live is MariaDB 10.1. */
    public const THEMES = ['system', 'light', 'dark'];

    /** Where somebody lands after signing in. */
    public const LANDING = ['dashboard', 'last-visited'];

    public const DATE_FORMATS = ['dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd'];

    /**
     * Every preference this product knows about, with its default.
     *
     * The TYPE of each default drives the cast on read: a bool default means
     * the stored '1'/'0' comes back as a real boolean, an array default means
     * the text is JSON.
     */
    public const DEFAULTS = [
        // Appearance
        'theme' => 'system',
        'sidebar_collapsed' => true,
        'density' => 'comfortable',

        // Locale and formatting. Today the frontend hardcodes THREE different
        // locales across different files - en-GB, en-US and en-IN - so the same
        // person sees a date formatted three ways in one session.
        'locale' => 'en-GB',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'dd/mm/yyyy',

        // Where to land
        'landing_page' => 'dashboard',

        // Notifications. In-app is not opt-out: it is the product telling you
        // something happened in your own workspace, and a silent inbox is how
        // an approval sits unseen for a week.
        'notify_email' => true,
    ];

    /** Read everything for one person, defaults filled in. */
    public function all(int $userId): array
    {
        $stored = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->pluck('pref_value', 'pref_key');

        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = $stored->has($key)
                ? $this->cast($stored[$key], $default)
                : $default;
        }

        // Per-event opt-outs, one boolean each, defaulting to on.
        $out['notify_events'] = $this->eventPreferences($stored);

        return $out;
    }

    /**
     * Write the keys present in $values. Anything else is ignored.
     *
     * @return array the preferences as they now stand
     */
    public function save(int $userId, ?int $tenantId, array $values): array
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $this->put($userId, $tenantId, $key, $this->encode($values[$key], $default));
        }

        /*
         * Per-event switches arrive as a map, so they are handled apart from
         * the flat defaults - and only keys the dispatcher actually knows are
         * accepted, so a caller cannot fill the table with rows for events that
         * do not exist.
         */
        if (isset($values['notify_events']) && is_array($values['notify_events'])) {
            foreach ($values['notify_events'] as $event => $wanted) {
                if (!in_array($event, self::notifiableEvents(), true)) {
                    continue;
                }

                $this->put(
                    $userId,
                    $tenantId,
                    $this->eventKey($event),
                    filter_var($wanted, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'
                );
            }
        }

        return $this->all($userId);
    }

    /**
     * May this person be emailed about this event?
     *
     * The one question `NotificationSender` needs to ask, and the reason this
     * class exists at all: `send()` has never consulted a preference of any
     * kind, so there has been no way to stop being emailed about anything.
     *
     * Defaults to TRUE. A preference nobody has expressed is not consent to
     * silence - somebody who has never opened these settings should still hear
     * that their leave was approved.
     */
    public function wantsEmail(int $userId, string $event): bool
    {
        $stored = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('pref_key', ['notify_email', $this->eventKey($event)])
            ->pluck('pref_value', 'pref_key');

        // The master switch wins: off means off, whatever the per-event value.
        if ($stored->has('notify_email')
            && !filter_var($stored['notify_email'], FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $key = $this->eventKey($event);

        return $stored->has($key)
            ? filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN)
            : true;
    }

    /**
     * The events somebody can be notified about, from the dispatcher itself.
     *
     * `NOTIFIES` is a plain LIST of event names, not a keyed map - so this is
     * the values, not the keys. Taking the keys returned 0..9, which then
     * became ten preference rows named after array indexes. Caught because the
     * check printed the event names and they were integers.
     */
    public static function notifiableEvents(): array
    {
        return array_values(NotificationDispatcher::NOTIFIES);
    }

    /**
     * Which of those events can actually reach somebody BY EMAIL today.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY THE SCREEN HAS TO KNOW THIS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `NotificationComposer::compose()` returns null when no active template
     * exists for an event on a channel, and `sendEmail()` treats that as "do not
     * send" - which is right, because a template-less send would be an empty
     * message.
     *
     * But there are 10 notifiable events and only SEVEN email templates. The
     * three HRIT leave events - leave.submitted, leave.decided, leave.escalated
     * - have in-app templates and no email ones, so they cannot be emailed at
     * all. An email switch beside them would be a control that does nothing, and
     * the person who turned it off would believe they had stopped an email that
     * was never going to arrive.
     *
     * Found by an evidence check that deliberately tested the OTHER direction:
     * having proved an opted-out event sent no email, it opted back in and
     * asserted an email DID send. It did not. Without that control the first
     * assertion would have passed vacuously.
     *
     * This is a per-tenant question - templates carry a locale and an is_active
     * flag - but the table has no tenant column, so it is the same answer for
     * everybody today.
     */
    public static function emailableEvents(string $locale = 'en'): array
    {
        $withTemplates = DB::table('g2g_notification_template')
            ->where('channel', 'email')
            ->where('locale', $locale)
            ->where('is_active', true)
            ->pluck('event_type')
            ->all();

        return array_values(array_intersect(self::notifiableEvents(), $withTemplates));
    }

    /** `leave.decided` -> `notify_event.leave.decided`, inside VARCHAR(96). */
    private function eventKey(string $event): string
    {
        return substr('notify_event.' . $event, 0, 96);
    }

    private function eventPreferences($stored): array
    {
        $out = [];

        foreach (self::notifiableEvents() as $event) {
            $key = $this->eventKey($event);

            $out[$event] = $stored->has($key)
                ? filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN)
                : true;
        }

        return $out;
    }

    /**
     * Write one preference.
     *
     * ── created_at IS SET ONCE, NOT ON EVERY SAVE ───────────────────────────
     *
     * `updateOrInsert($attributes, $values)` applies `$values` as the UPDATE SET
     * when the row already exists, so passing `created_at` in that array reset
     * the creation timestamp on every single write - the column recorded the
     * last change, exactly like `updated_at` beside it, and the two were always
     * equal.
     *
     * The closure form is given the existence flag, so the insert can carry
     * `created_at` and the update can leave it alone.
     */
    private function put(int $userId, ?int $tenantId, string $key, ?string $value): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId, 'pref_key' => $key],
            fn (bool $exists) => $exists
                ? [
                    'sub_institute_id' => $tenantId,
                    'pref_value' => $value,
                    'updated_at' => now(),
                ]
                : [
                    'sub_institute_id' => $tenantId,
                    'pref_value' => $value,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
        );
    }

    /** Text out of the column, back into the shape its default declares. */
    private function cast(?string $stored, mixed $default): mixed
    {
        if (is_bool($default)) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_array($default)) {
            $decoded = json_decode((string) $stored, true);

            return is_array($decoded) ? $decoded : $default;
        }

        if (is_int($default)) {
            return (int) $stored;
        }

        return (string) $stored;
    }

    private function encode(mixed $value, mixed $default): ?string
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (is_array($default)) {
            return json_encode(array_values((array) $value));
        }

        return $value === null ? null : (string) $value;
    }
}
