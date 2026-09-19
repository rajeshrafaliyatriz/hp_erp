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

    /**
     * The preferences that belong to a MACHINE rather than to a person.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY THIS IS A SHORT LIST AND NOT EVERYTHING
     * ═══════════════════════════════════════════════════════════════════════
     *
     * These three describe how a particular screen should look: a bright office
     * monitor and a laptop in the evening genuinely want different answers, and
     * a sidebar that suits a 27-inch display does not suit a 13-inch one.
     *
     * Everything else is a property of the PERSON. Somebody's date format,
     * language, time zone and notification choices do not change because they
     * picked up a different laptop - making those per-device would mean setting
     * them again on every machine, which is not a feature.
     *
     * A key not listed here is always stored at `device_id = ''`, whatever
     * device asked to change it.
     */
    public const DEVICE_SCOPED = ['theme', 'sidebar_collapsed', 'density'];

    /** The account-wide scope. Not a magic string - see the migration's note. */
    public const ACCOUNT_SCOPE = '';

    /**
     * Read everything for one person, as seen FROM ONE DEVICE.
     *
     * ── THE RESOLUTION ORDER IS DEVICE, THEN ACCOUNT, THEN DEFAULT ──────────
     *
     * A device row wins where it exists; otherwise the account default applies;
     * otherwise the product's own default. That is what makes clearing site data
     * survivable: the device id is gone, so every device-scoped key falls through
     * to the account row the person already has, rather than to a factory value.
     *
     * Both scopes are read in ONE query. Two queries would be simpler and would
     * also double the cost of the single most-called endpoint in the settings
     * area - `/account/me` runs on every page load.
     */
    public function all(int $userId, string $deviceId = self::ACCOUNT_SCOPE): array
    {
        $rows = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('device_id', array_unique([self::ACCOUNT_SCOPE, $deviceId]))
            ->get(['device_id', 'pref_key', 'pref_value']);

        $account = [];
        $device = [];

        foreach ($rows as $row) {
            if ($row->device_id === self::ACCOUNT_SCOPE) {
                $account[$row->pref_key] = $row->pref_value;
            } else {
                $device[$row->pref_key] = $row->pref_value;
            }
        }

        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $device)) {
                $out[$key] = $this->cast($device[$key], $default);
            } elseif (array_key_exists($key, $account)) {
                $out[$key] = $this->cast($account[$key], $default);
            } else {
                $out[$key] = $default;
            }
        }

        /*
         * Notification choices are never device-scoped - being emailed is about
         * the person, not the laptop - so they are read from the account rows
         * alone. Passing `$device` here would let one browser mute an event
         * everywhere else it is not muted.
         */
        $out['notify_events'] = $this->eventPreferences(collect($account));

        return $out;
    }

    /**
     * Write the keys present in $values. Anything else is ignored.
     *
     * ── WHERE EACH KEY LANDS IS DECIDED HERE, NOT BY THE CALLER ─────────────
     *
     * A device-scoped key goes to `$deviceId`; everything else goes to the
     * account scope regardless of which device asked. That choice being made in
     * one place is what stops a caller - a future screen, a script - from
     * accidentally writing somebody's date format against one laptop and leaving
     * them to wonder why it did not follow them.
     *
     * When `$deviceId` is empty the two scopes coincide, which is exactly right:
     * a caller that does not know its device is setting the account default.
     *
     * @return array the preferences as they now stand, seen from $deviceId
     */
    public function save(int $userId, ?int $tenantId, array $values, string $deviceId = self::ACCOUNT_SCOPE): array
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $scope = in_array($key, self::DEVICE_SCOPED, true) ? $deviceId : self::ACCOUNT_SCOPE;

            $this->put($userId, $tenantId, $key, $this->encode($values[$key], $default), $scope);
        }

        /*
         * Per-event switches arrive as a map, so they are handled apart from
         * the flat defaults - and only keys the dispatcher actually knows are
         * accepted, so a caller cannot fill the table with rows for events that
         * do not exist.
         *
         * Always the ACCOUNT scope. Being emailed about a leave decision is a
         * fact about the person; muting it on a phone must not leave it unmuted
         * on their laptop.
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
                    filter_var($wanted, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                    self::ACCOUNT_SCOPE
                );
            }
        }

        return $this->all($userId, $deviceId);
    }

    /**
     * "Use these on all my devices."
     *
     * ── WHY THIS EXISTS AS AN EXPLICIT ACTION ───────────────────────────────
     *
     * Per-device settings are right until somebody sets up a new machine and has
     * to redo the work. This copies the current device's device-scoped rows up to
     * the account scope, so every OTHER device that has no opinion of its own
     * inherits them - and any device that does have an opinion keeps it, which is
     * the whole point of having device rows.
     *
     * It deliberately does not delete the device rows. Pressing this should not
     * change anything about the machine you pressed it on.
     *
     * @return array the preferences as they now stand
     */
    public function promoteToAccount(int $userId, ?int $tenantId, string $deviceId): array
    {
        if ($deviceId === self::ACCOUNT_SCOPE) {
            // Already the account default; nothing to copy.
            return $this->all($userId, $deviceId);
        }

        $rows = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->whereIn('pref_key', self::DEVICE_SCOPED)
            ->pluck('pref_value', 'pref_key');

        foreach ($rows as $key => $value) {
            $this->put($userId, $tenantId, $key, $value, self::ACCOUNT_SCOPE);
        }

        return $this->all($userId, $deviceId);
    }

    /**
     * Forget what this device has chosen, falling back to the account default.
     *
     * The counterpart to promoting, and the honest answer to "I set this by
     * mistake on a machine I do not own".
     */
    public function forgetDevice(int $userId, string $deviceId): void
    {
        if ($deviceId === self::ACCOUNT_SCOPE) {
            // Refusing rather than wiping: an empty device id here would delete
            // the account defaults, which is the opposite of what is being asked.
            return;
        }

        DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->delete();
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
        /*
         * ACCOUNT SCOPE ONLY, explicitly.
         *
         * Notification preferences are never written per device (see `save()`),
         * but without this filter the query would also pick up device rows if
         * one ever appeared - and a background job deciding whether to email
         * somebody has no device to ask about anyway. Being emailed is a fact
         * about the person.
         */
        $stored = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('device_id', self::ACCOUNT_SCOPE)
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
    private function put(
        int $userId,
        ?int $tenantId,
        string $key,
        ?string $value,
        string $deviceId = self::ACCOUNT_SCOPE
    ): void {
        /*
         * `device_id` IS PART OF THE MATCH, not part of the payload.
         *
         * The unique index is `(user_id, device_id, pref_key)`. Matching on only
         * two of those three would find the account row when writing a device
         * row and overwrite it - so setting a dark theme on one laptop would
         * silently change every other machine that had no opinion of its own,
         * which is the exact behaviour per-device storage exists to prevent.
         */
        DB::table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId, 'device_id' => $deviceId, 'pref_key' => $key],
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
