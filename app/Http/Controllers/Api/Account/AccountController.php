<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Controller;
use App\Services\Account\UserPreferences;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * YOUR OWN ACCOUNT — the first place a person can change anything about it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT DID NOT EXIST BEFORE THIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A survey of `routes/api.php` for `profile|me|account|preferences|change-password`
 * turned up ONE self-service write in the entire product:
 * `POST /api/update-fcm-token`. That is a device push token, and `fcm_token` is
 * set on 0 of 2,373 users.
 *
 * `/profile` displays seven tabs and changes nothing. Its own comment says so:
 * *"Self-service editing does not exist yet - there is no endpoint for an
 * employee to change their own record"*, which is why its Change Password and
 * Edit Profile buttons were removed rather than wired.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT A BRANCH IN tbluserController
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * That controller's route chain is `['auth','session','menu']`, and both guards
 * are permissive for a token caller: `authMiddleware` passes on any valid
 * Sanctum token, and `MenuMiddleware` short-circuits entirely on `type=API`. So
 * it has NO ROLE CHECK AT ALL, and its `updateData()` writes any tbluser column
 * not on a deny-list.
 *
 * Hanging self-service off it would mean the endpoint that lets somebody edit
 * THEMSELVES shares a method with the one that edits ANYBODY. The subject here
 * is always the token's owner; there is no id parameter to get wrong.
 *
 * ── ALLOW-LIST, NOT DENY-LIST ───────────────────────────────────────────────
 *
 * `EDITABLE` is what a person may change about themselves. Everything else on
 * `tbluser` - department, job role, profile, employee number, salary, bank
 * details, statutory numbers, joining date - belongs to HR, and is edited
 * through Employee Directory behind `profile:admin,hr`. Somebody moving their
 * own department is not a settings change; it is a promotion.
 */
class AccountController extends Controller
{
    use ResolvesApiIdentity;

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * A PERSON COULD NOT SEE THEIR OWN SECURITY HISTORY
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `g2g_audit_log` has recorded organisation events since it was built, and
     * every screen that reads it is an ADMINISTRATOR's screen. Nothing showed
     * somebody the events about themselves - no "password changed on the 3rd", no
     * "a new device signed in". Those are exactly the events a person is best
     * placed to recognise as wrong, and they were the ones nobody could see.
     *
     * Worse, the account's own actions were not recorded at all: changing a
     * password, replacing a photo and ending a session left no trace anywhere.
     * The recorder existed; this controller simply never used it.
     */
    /*
     * TwoFactor is a CONSTRUCTOR dependency, not a method parameter, and that is
     * a correction rather than a preference.
     *
     * It was a third argument on `me()`, which Laravel resolves fine when the
     * router calls the method - and `update()` calls `$this->me($request,
     * $preferences)` DIRECTLY, with two. PHP raised ArgumentCountError and every
     * profile save returned 500. tsc cannot see it, the linter cannot see it, and
     * the route that is actually exercised by evidence - GET /account/me - worked
     * perfectly, because the router filled the argument in.
     *
     * On the constructor there is no call site left that can get it wrong.
     */
    public function __construct(
        private EventRecorder $events,
        private \App\Services\Account\TwoFactor $twoFactor,
    ) {
    }

    /** Event types this controller records. Named so a reader is not hunting. */
    private const EVENT_PASSWORD_CHANGED = 'account.password_changed';
    private const EVENT_PHOTO_CHANGED = 'account.photo_changed';
    private const EVENT_SESSIONS_ENDED = 'account.sessions_ended';
    private const EVENT_SIGNED_OUT = 'account.signed_out';

    /**
     * What a person may change about themselves.
     *
     * Contact details and how to reach them. Nothing that decides what they can
     * see, what they are paid, or where they sit in the organisation.
     */
    private const EDITABLE = [
        'first_name', 'middle_name', 'last_name', 'name_suffix',
        'mobile', 'gender', 'birthdate',
        'address', 'address_2', 'city', 'state', 'pincode',
    ];

    /**
     * The calling browser's device id, or the account scope.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * VALIDATED HERE, ONCE, BECAUSE IT REACHES A UNIQUE INDEX
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The browser mints a ULID and keeps it in localStorage. It is not a secret
     * and not an identity - it says nothing about WHO is calling, only WHICH of
     * their machines, and the user is always resolved from the token. Somebody
     * who sends a made-up device id scopes their own preferences to a device
     * they invented, which harms nobody.
     *
     * What it must not be is malformed. It lands in a `VARCHAR(40)` that is part
     * of `(user_id, device_id, pref_key)`, so anything longer is a truncation
     * away from colliding with a different row. Anything not matching is treated
     * as ABSENT rather than rejected: an old client that has never heard of
     * device ids must keep working, and it does - it writes the account default.
     */
    private function deviceId(Request $request): string
    {
        $raw = trim((string) $request->input('device_id', ''));

        if ($raw === '' || strlen($raw) > 40 || !preg_match('/^[A-Za-z0-9_-]+$/', $raw)) {
            return UserPreferences::ACCOUNT_SCOPE;
        }

        return $raw;
    }

    /**
     * Who this person is AT WORK: job title, department, manager, employee number.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THE JOB TITLE DOES NOT LIVE WHERE IT LOOKS LIKE IT LIVES
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `tbluser.jobtitle_id` resolves against `s_user_jobrole`, NOT `s_jobrole`.
     * Both tables exist, both have an `id` and a `jobrole` column, and the ids
     * overlap - so joining the wrong one does not error, it returns a DIFFERENT
     * PERSON'S JOB TITLE. Measured on live: 293 of 293 ids resolve against
     * `s_user_jobrole`; only 101 resolve against `s_jobrole`, and the rest of
     * those are coincidental collisions.
     *
     * ── IT MUST NOT BE ABLE TO BREAK /account/me ────────────────────────────
     *
     * Every screen in the product depends on this endpoint - the theme, the
     * sidebar, the header avatar, the whole settings rail. A join against a
     * table that is missing on some deployment would turn one cosmetic field
     * into a total outage, so the lookup is wrapped and degrades to nulls. A
     * profile missing its job title is a small problem; a product that will not
     * load is not.
     *
     * ── THE JOINING DATE IS REAL BUT SPARSE ─────────────────────────────────
     *
     * `tbluser.joined_date` exists and is populated on 14 of 299 live people. It
     * is returned as-is, null where it is unset, and the screen omits an empty
     * one rather than printing a dash - a field that says nothing is worse than
     * a field that is not there.
     *
     * What is NOT used for this is `created_at`: that is when the ROW was made,
     * which for anybody migrated in is not when they joined, and presenting it
     * as a joining date would be a confident lie.
     *
     * `reporting_manager_id` IS returned, and is currently null for all 299 live
     * users because nothing populates it yet. It is included so the field starts
     * working the day HR fills it in, and the screen omits it while it is empty.
     */
    private function workIdentity(object $user, int $tenantId): array
    {
        $work = [
            'job_title' => null,
            'department' => null,
            'reporting_manager' => null,
            'employee_no' => $user->employee_no ?? null,
            // Sparse - 14 of 299 live people have one. Null where unset; the
            // screen omits it rather than printing an empty row.
            'joined_date' => ($user->joined_date ?? null) ?: null,
        ];

        try {
            if (!empty($user->jobtitle_id)) {
                $work['job_title'] = DB::table('s_user_jobrole')
                    ->where('id', $user->jobtitle_id)
                    ->value('jobrole');
            }

            if (!empty($user->department_id)) {
                $work['department'] = DB::table('hrms_departments')
                    ->where('id', $user->department_id)
                    ->value('department');
            }

            if (!empty($user->reporting_manager_id)) {
                /*
                 * Tenant-scoped, even though the id comes from this person's own
                 * row. An id that points across tenants is a data fault, and the
                 * answer to a data fault is to show nothing rather than to name
                 * somebody from another organisation.
                 */
                $manager = DB::table('tbluser')
                    ->where('id', $user->reporting_manager_id)
                    ->where('sub_institute_id', $tenantId)
                    ->first(['first_name', 'last_name']);

                if ($manager) {
                    $work['reporting_manager'] = trim(
                        ($manager->first_name ?? '') . ' ' . ($manager->last_name ?? '')
                    ) ?: null;
                }
            }
        } catch (\Throwable $caught) {
            report($caught);
        }

        return $work;
    }

    /**
     * Record something that happened to this account.
     *
     * ── IT MUST NEVER FAIL THE ACTION IT DESCRIBES ──────────────────────────
     *
     * Every caller records AFTER the change is committed. A password is already
     * written by the time this runs, so an exception here would report a failure
     * for something that succeeded - and would do it for the most alarming action
     * on the screen. Swallowed and reported, like the other bookkeeping in this
     * codebase.
     *
     * `entity_type` is `tbluser` and `entity_id` the person themselves: these are
     * events ABOUT an account, so the account is the subject, and that is what
     * lets `activity()` find them with one indexed lookup.
     */
    private function recordSecurityEvent(string $type, array $identity, array $payload = []): void
    {
        try {
            $this->events->record(
                $type,
                (int) $identity['sub_institute_id'],
                'tbluser',
                (int) $identity['user_id'],
                (int) $identity['user_id'],
                $payload
            );
        } catch (\Throwable $caught) {
            report($caught);
        }
    }

    /** GET /api/account/me */
    public function me(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $user = DB::table('tbluser')
            ->where('id', $identity['user_id'])
            ->first(array_merge(self::EDITABLE, [
                'id', 'email', 'image', 'employee_no', 'last_login',
                // Read-only, for `workIdentity()`. Selected here rather than in a
                // second query because this row is already being fetched, and
                // these three ids are what the work identity is resolved FROM.
                'jobtitle_id', 'department_id', 'reporting_manager_id', 'joined_date',
            ]));

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Account not found.'], 404);
        }

        /*
         * `tbluser.image` holds a BARE FILENAME, and the browsable URL is built
         * from the disk. Resolving it here rather than in React is deliberate:
         * five controllers already build this path, two of them by hardcoding
         * `https://s3-triz.fra1.cdn.digitaloceanspaces.com` into a SQL string.
         * A sixth copy in the frontend would be the first one that cannot be
         * changed by moving the bucket.
         */
        $user->image_url = $user->image ? $this->avatarUrl($user->image) : null;

        /*
         * ═══════════════════════════════════════════════════════════════════
         * WHAT A PERSON MAY SEE IS NOT WHAT THEY MAY CHANGE
         * ═══════════════════════════════════════════════════════════════════
         *
         * `EDITABLE` was doing two jobs: the write allow-list AND the read
         * payload. Keeping job title and department out of what somebody may
         * CHANGE is right - moving your own department is a promotion, not a
         * settings change. Keeping them out of what somebody may SEE was an
         * accident of using one list for both, and it is why this product ended
         * up with TWO profile screens: `/profile` had to fetch the HRMS
         * endpoints separately to show a job title, while `/settings?s=profile`
         * was the only place anything could be edited. Two screens, two data
         * sources, and no reason for either to agree with the other.
         *
         * This is the read side. Nothing here is writable: `updateProfile()`
         * intersects the request with `EDITABLE`, so a PUT naming `jobtitle_id`
         * is discarded exactly as an invented field would be.
         */
        $user->work = $this->workIdentity($user, (int) $identity['sub_institute_id']);

        return response()->json([
            'status' => true,
            'data' => [
                'profile' => $user,
                'preferences' => $preferences->all((int) $identity['user_id'], $this->deviceId($request)),
                /*
                 * Echoed back so the screen can say "this laptop" rather than
                 * guessing, and can offer "use on all my devices" only when
                 * there is a device to promote FROM.
                 */
                'device_scope' => [
                    'device_id' => $this->deviceId($request),
                    'is_device' => $this->deviceId($request) !== UserPreferences::ACCOUNT_SCOPE,
                    'device_scoped_keys' => UserPreferences::DEVICE_SCOPED,
                ],
                /*
                 * The role is reported so the settings screen knows which
                 * sections to show. It is a PRESENTATION hint - every endpoint
                 * behind those sections is guarded independently, so a caller
                 * who lies to their own browser gains nothing.
                 */
                'role' => RoleKey::forUserId((int) $identity['user_id']),
                /*
                 * Whether the second factor is on, and how many recovery codes are
                 * left.
                 *
                 * The COUNT and not the codes: they exist in readable form exactly
                 * once, in the response that issues them. A running total is what
                 * lets the screen warn somebody who is down to their last one -
                 * which is the moment before a lost phone becomes a lost account.
                 */
                'two_factor' => [
                    'enabled' => $this->twoFactor->isEnabled((int) $identity['user_id']),
                    'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft((int) $identity['user_id']),
                    /*
                     * WHETHER THE ORGANISATION OBLIGES THIS PERSON TO HAVE IT ON.
                     *
                     * The reason the screen can explain the 403s rather than leaving
                     * somebody staring at a product that has stopped working. When
                     * this is true and `enabled` is false, every other endpoint is
                     * refused by `RequireTwoFactorEnrolment` - and this payload is on
                     * its allow-list precisely so the explanation is still reachable.
                     *
                     * Through the same reader the refusal uses, so the sentence on the
                     * screen and the decision on the server cannot drift apart.
                     */
                    'required' => \App\Http\Controllers\Api\Account\TwoFactorController::policyRequired(
                        (int) $identity['user_id'],
                        (int) $identity['sub_institute_id'],
                    ),
                ],
                'notifiable_events' => UserPreferences::notifiableEvents(),
                /*
                 * Of those, the ones an email can actually be built for. Three
                 * of the ten have no email template, so a switch beside them
                 * would be a control that changes nothing - the screen marks
                 * them rather than pretending.
                 */
                'emailable_events' => UserPreferences::emailableEvents(),
                'choices' => [
                    'theme' => UserPreferences::THEMES,
                    'landing_page' => UserPreferences::LANDING,
                    'date_format' => UserPreferences::DATE_FORMATS,
                ],
            ],
        ]);
    }

    /** PUT /api/account/profile */
    public function updateProfile(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'name_suffix' => ['nullable', 'string', 'max:20'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', 'in:M,F,O'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:191'],
            'address_2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],

            // Validated HERE, with everything else, not inside the upload.
            // Checking it after the text write meant a rejected file type 422'd
            // AFTER the name had already changed - the caller saw a failure and
            // half of it had happened.
            // `image` checks the BYTES; `mimes` additionally constrains the
            // client's extension. Both, because each catches what the other
            // misses - `image` alone admits a real PNG named `x.html`.
            'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ]);

        /*
         * EMAIL IS NOT HERE, DELIBERATELY.
         *
         * It is the login identifier and the only unique key on `tbluser`, and
         * changing it changes who you are to this system. Letting somebody
         * retype it in a settings form is how an account quietly walks away
         * from the person who is supposed to hold it. HR changes it, from the
         * Employee Directory, where it is an act with a witness.
         */
        $payload = array_intersect_key($data, array_flip(self::EDITABLE));

        if ($payload !== []) {
            $payload['updated_at'] = now();
            DB::table('tbluser')->where('id', $identity['user_id'])->update($payload);
        }

        $imageError = null;

        if ($request->hasFile('image')) {
            try {
                $this->storeAvatar($request, (int) $identity['user_id']);

                // Only on success. Recording a photo change that failed to upload
                // would put an event on the person's history for something that
                // did not happen.
                $this->recordSecurityEvent(self::EVENT_PHOTO_CHANGED, $identity);
            } catch (\Throwable $e) {
                // The object store being unreachable must not lose the details
                // that were typed alongside the picture. Those are already
                // saved; the caller is told which half did not land.
                report($e);
                $imageError = 'Your details were saved, but the photo could not be uploaded. Please try the photo again.';
            }
        }

        $response = $this->me($request, $preferences);

        if ($imageError) {
            $body = $response->getData(true);
            $body['image_error'] = $imageError;

            return response()->json($body);
        }

        return $response;
    }

    /** PUT /api/account/preferences */
    public function updatePreferences(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate([
            'theme' => ['sometimes', Rule::in(UserPreferences::THEMES)],
            'landing_page' => ['sometimes', Rule::in(UserPreferences::LANDING)],
            'date_format' => ['sometimes', Rule::in(UserPreferences::DATE_FORMATS)],
            'locale' => ['sometimes', 'string', 'max:12'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'density' => ['sometimes', 'in:comfortable,compact'],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_events' => ['sometimes', 'array'],
            'notify_events.*' => ['boolean'],

            /*
             * HOW SOMEBODY PRESENTS THEMSELVES, AND WHO SEES WHAT.
             *
             * Length caps rather than a free-for-all: `about` lands in a TEXT
             * column read by the directory, and a control with no limit is a
             * control somebody eventually pastes a novel into. 300 is about four
             * lines on screen, which is what the field is for.
             *
             * `nullable` on all three because clearing one is a legitimate
             * choice - "I no longer want pronouns shown" has to be expressible,
             * and without `nullable` an empty string fails `string`.
             */
            'display_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'pronouns' => ['sometimes', 'nullable', 'string', 'max:30'],
            'about' => ['sometimes', 'nullable', 'string', 'max:300'],

            /*
             * Visibility is an enum and is validated as one. Without this a
             * client could store `visible_mobile = 'yes'`, which the directory
             * would not recognise - and an unrecognised visibility is the one
             * case that must never silently mean "show it".
             */
            'visible_mobile' => ['sometimes', Rule::in(UserPreferences::VISIBILITY)],
            'visible_birthdate' => ['sometimes', Rule::in(UserPreferences::VISIBILITY)],
            'visible_address' => ['sometimes', Rule::in(UserPreferences::VISIBILITY)],
        ]);

        $saved = $preferences->save(
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            $request->all(),
            $this->deviceId($request)
        );

        return response()->json([
            'status' => true,
            'message' => 'Saved.',
            'data' => ['preferences' => $saved],
        ]);
    }

    /**
     * POST /api/account/preferences/promote
     *
     * "Use these on all my devices." Copies this device's appearance choices up
     * to the account default, so a machine that has never been configured
     * inherits them. Devices that HAVE been configured keep what they have -
     * that is the point of per-device storage, and silently overwriting it would
     * make the feature untrustworthy.
     */
    public function promotePreferences(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $deviceId = $this->deviceId($request);

        if ($deviceId === UserPreferences::ACCOUNT_SCOPE) {
            return response()->json([
                'status' => false,
                'message' => 'These are already your defaults on every device.',
            ], 422);
        }

        $saved = $preferences->promoteToAccount(
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            $deviceId
        );

        return response()->json([
            'status' => true,
            'message' => 'Saved. Your other devices will use these unless they have their own.',
            'data' => ['preferences' => $saved],
        ]);
    }

    /**
     * DELETE /api/account/preferences/device
     *
     * Forget what THIS device has chosen and fall back to the account default.
     * The honest answer to "I set this on a machine that is not mine".
     */
    public function forgetDevicePreferences(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $deviceId = $this->deviceId($request);

        if ($deviceId === UserPreferences::ACCOUNT_SCOPE) {
            /*
             * Refused rather than treated as a no-op. An empty device id here
             * would mean "delete my account defaults", which is the opposite of
             * what this endpoint is for, and `forgetDevice()` guards it a second
             * time for the same reason.
             */
            return response()->json([
                'status' => false,
                'message' => 'This browser has no settings of its own to forget.',
            ], 422);
        }

        $preferences->forgetDevice((int) $identity['user_id'], $deviceId);

        return response()->json([
            'status' => true,
            'message' => 'This browser now follows your account settings.',
            'data' => ['preferences' => $preferences->all((int) $identity['user_id'], $deviceId)],
        ]);
    }

    /** POST /api/account/password */
    public function changePassword(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            // The caller's own organisation, so a tenant that has raised its
            // minimum has raised it here too. This is the one password path
            // where the tenant is always known.
            'password' => ['required', 'confirmed', PasswordController::rule((int) $identity['sub_institute_id'])],
        ]);

        $user = DB::table('tbluser')->where('id', $identity['user_id'])->first(['id', 'password']);

        /*
         * THE CURRENT PASSWORD IS REQUIRED even though the caller is already
         * authenticated. A token left behind on a shared machine is exactly the
         * situation this guards: holding a session should not be enough to take
         * the account permanently.
         */
        if (!$user || !Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'That is not your current password.',
            ], 422);
        }

        DB::table('tbluser')->where('id', $user->id)->update([
            'password' => Hash::make($request->input('password')),
            'otp' => null,
            'updated_at' => now(),
        ]);

        // Every OTHER session ends. The one making this request survives, or
        // changing your password would log you out of the screen you did it on.
        $currentTokenId = $this->currentTokenId($request);

        $endedCount = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            ->where('tokenable_id', $user->id)
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        /*
         * RECORDED, because this is the event somebody most needs to see.
         *
         * A password change nobody asked for is the clearest sign an account has
         * been taken, and until now it left no trace: no audit row, no email, no
         * entry on any screen. The row carries how many other sessions ended, so
         * the entry reads as something that happened rather than a bare label.
         */
        $this->recordSecurityEvent(
            self::EVENT_PASSWORD_CHANGED,
            $identity,
            ['other_sessions_ended' => $endedCount]
        );

        return response()->json([
            'status' => true,
            'message' => 'Your password is changed. Any other signed-in devices have been signed out.',
        ]);
    }

    /**
     * GET /api/account/activity — what has happened to THIS account.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ONE PERSON'S HISTORY, AND NEVER ANOTHER'S
     * ═══════════════════════════════════════════════════════════════════════
     *
     * There is no id parameter. The subject is the token's owner, resolved the
     * same way every other method here resolves it, so there is nothing for a
     * caller to tamper with. The tenant is filtered too - belt and braces, since
     * `actor_id` is already unique across the platform, but an audit reader that
     * can be talked across a tenant boundary is the last place to save a clause.
     *
     * ── TWO SOURCES, ONE LIST ───────────────────────────────────────────────
     *
     * `g2g_audit_log` holds what the person DID. `personal_access_tokens` holds
     * when they SIGNED IN and from what - which is nowhere in the audit log,
     * because signing in was never recorded as an event. Merging them is what
     * makes this a security history rather than half of one.
     *
     * Sign-ins are derived from `created_at` on each token: one token per
     * sign-in, named with its `DeviceLabel`. Tokens deleted by "sign out
     * everywhere" take their sign-in row with them, so old sign-ins disappear
     * over time - honest, if imperfect, and the audit row for the sign-out
     * remains to explain the gap.
     */
    public function activity(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        /*
         * Capped. A long-serving account could hold thousands of rows, and a
         * security history is read from the top - nobody scrolls to last year.
         * `limit` is clamped rather than trusted: a caller asking for 100000 is
         * asking the database to build a response nobody will read.
         */
        $limit = min(200, max(10, (int) $request->input('limit', 50)));

        /*
         * `g2g_event`, NOT `g2g_audit_log`, and the difference is visible to the
         * person using this.
         *
         * `g2g_audit_log` is a PROJECTION, built by the scheduled `events:project`
         * command. Reading it would mean somebody changes their password, opens
         * their own security history, and does not see it - because the projector
         * has not run yet. For an administrator querying last quarter that lag is
         * irrelevant; for "did that just happen?" it is the entire question.
         *
         * `g2g_event` is the source `EventRecorder` writes to, so it is immediate
         * and cannot disagree with the projection. It carries everything needed:
         * `type`, `actor_id`, `sub_institute_id`, `payload` and `occurred_at`.
         */
        $audit = DB::table('g2g_event')
            ->where('actor_id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'type', 'entity_type', 'entity_id', 'payload', 'occurred_at']);

        $entries = [];

        foreach ($audit as $row) {
            $entries[] = [
                'kind' => 'event',
                'type' => $row->type,
                'at' => $row->occurred_at,
                'device' => null,
                'detail' => $this->activityDetail($row),
            ];
        }

        $signIns = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            ->where('tokenable_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'created_at']);

        foreach ($signIns as $row) {
            $entries[] = [
                'kind' => 'sign_in',
                'type' => 'account.signed_in',
                'at' => $row->created_at,
                'device' => $row->name,
                'detail' => null,
            ];
        }

        /*
         * Merged in PHP rather than with a UNION.
         *
         * The two tables share no column names, no types and no time precision -
         * `occurred_at` is `datetime(3)`, `created_at` a plain `timestamp`. A
         * UNION would need a cast list per column and would still be sorted by a
         * string. Two capped queries and one sort is clearer and bounded.
         */
        usort($entries, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return response()->json([
            'status' => true,
            'data' => [
                'entries' => array_slice($entries, 0, $limit),
                /*
                 * So the screen can say "this is everything we have" rather than
                 * implying a complete history. The audit log began partway through
                 * this product's life and sign-in rows vanish with their tokens.
                 */
                'since' => $signIns->last()->created_at ?? null,
            ],
        ]);
    }

    /**
     * A human sentence for one audit row, or null.
     *
     * `detail` is a JSON payload written by whatever recorded the event, so it is
     * decoded defensively: a row whose payload is malformed should lose its
     * detail, not break the whole list.
     */
    private function activityDetail(object $row): ?string
    {
        // `payload` on `g2g_event`; the projection renames it `detail`. Reading the
        // event table means reading the event table's column name.
        $payload = json_decode((string) ($row->payload ?? $row->detail ?? ''), true);

        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['other_sessions_ended']) && $payload['other_sessions_ended'] > 0) {
            return $payload['other_sessions_ended'] . ' other device(s) were signed out';
        }

        if (isset($payload['sessions_ended'])) {
            return ($payload['one_device'] ?? false)
                ? 'One device was signed out'
                : $payload['sessions_ended'] . ' device(s) were signed out';
        }

        return null;
    }

    /** GET /api/account/sessions */
    public function sessions(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $currentTokenId = $this->currentTokenId($request);

        $sessions = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            ->where('tokenable_id', $identity['user_id'])
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'last_used_at' => $row->last_used_at,
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                // So the screen can label one "this device" and refuse to end it
                // with the same click that ends the others.
                'current' => (int) $row->id === $currentTokenId,
            ]);

        return response()->json([
            'status' => true,
            'data' => ['sessions' => $sessions],
        ]);
    }

    /** DELETE /api/account/sessions/{id} — or all but this one when id is absent. */
    public function endSessions(Request $request, ?int $id = null)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $currentTokenId = $this->currentTokenId($request);

        $query = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            // SCOPED TO THE CALLER. Without this, an id from the request would
            // end somebody else's session - the tokens table is global.
            ->where('tokenable_id', $identity['user_id']);

        if ($id !== null) {
            if ($id === $currentTokenId) {
                return response()->json([
                    'status' => false,
                    'message' => 'That is this device. Use Sign out instead.',
                ], 422);
            }

            $ended = $query->where('id', $id)->delete();
        } else {
            $ended = $query->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();
        }

        /*
         * Recorded whether one session or twenty ended.
         *
         * "Signed out 14 other devices" is a line somebody scanning their own
         * history needs, and it is also the trace left behind if SOMEBODY ELSE
         * did it - an attacker ending the real owner's sessions to keep them out.
         */
        $this->recordSecurityEvent(self::EVENT_SESSIONS_ENDED, $identity, [
            'sessions_ended' => $ended,
            'one_device' => $id !== null,
        ]);

        return response()->json([
            'status' => true,
            'message' => $ended === 1
                ? 'That device has been signed out.'
                : $ended . ' device(s) have been signed out.',
            'data' => ['ended' => $ended],
        ]);
    }

    /**
     * POST /api/account/logout — end THIS session, on the server.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * SIGNING OUT USED TO BE A CLIENT-SIDE FICTION
     * ═══════════════════════════════════════════════════════════════════════
     *
     * There was no logout endpoint anywhere in this application. A repository-wide
     * search for one found nothing, and `routes/web.php` has never declared
     * `/logout` - while `header.blade.php` links to it and `footer.blade.php`
     * navigates to it, so both 404.
     *
     * So "Sign out" cleared the browser and nothing else. The Sanctum token stayed
     * valid for its full 30-day window, and the Laravel session row was never
     * invalidated. On a shared machine, anybody who recovered that token from
     * storage - or simply pressed Back - was still authenticated. `endSessions`
     * above even tells people "That is this device. Use Sign out instead", which
     * until now pointed at an action that revoked nothing.
     *
     * ── ONLY THIS TOKEN, NOT EVERY TOKEN ────────────────────────────────────
     *
     * Signing out of a laptop must not sign the same person out of their phone.
     * "Sign out everywhere else" is a separate, deliberate action - `endSessions`
     * - and conflating the two would make a routine sign-out destructive.
     *
     * ── IT SUCCEEDS EVEN WHEN IT CANNOT FIND THE TOKEN ──────────────────────
     *
     * An expired or already-deleted token means the session is ALREADY over, which
     * is what the caller asked for. Returning an error there would leave a client
     * unable to complete a sign-out it has every right to complete, and it would
     * teach clients to ignore the response.
     */
    public function logout(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $currentTokenId = $this->currentTokenId($request);

        $revoked = 0;

        if ($currentTokenId) {
            $revoked = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
                // Scoped to the caller as well as to the id. The tokens table is
                // global, and an id alone would be somebody else's session.
                ->where('tokenable_id', $identity['user_id'])
                ->where('id', $currentTokenId)
                ->delete();
        }

        /*
         * The web session too, where there is one.
         *
         * A caller may hold BOTH a token and a Blade session - the same browser can
         * have used both surfaces. Clearing one and leaving the other is how
         * somebody "signs out" and then finds the ERP still open in another tab.
         * `authMiddleware::hasSession()` is satisfied by `user_id` alone, so the
         * session has to be emptied rather than partly rewritten.
         */
        $this->forgetWebSession($request);

        $this->recordSecurityEvent(self::EVENT_SIGNED_OUT, $identity, [
            'token_revoked' => $revoked === 1,
        ]);

        return response()->json([
            'status' => true,
            // The same answer whether a token was found or not: the session is over
            // either way, and a client has nothing different to do.
            'message' => 'You have been signed out.',
        ]);
    }

    /**
     * Invalidate the Laravel session, if this request has one.
     *
     * `invalidate()` flushes the data AND regenerates the id, which is what stops a
     * fixated session id being reused. `regenerateToken()` then reissues the CSRF
     * token, without which the very next form post from that browser would 419.
     *
     * Wrapped, because an API request may legitimately have no session at all, and
     * sign-out must never fail on the half that does not apply to it.
     */
    private function forgetWebSession(Request $request): void
    {
        try {
            if (!$request->hasSession()) {
                return;
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (\Throwable $caught) {
            report($caught);
        }
    }

    /**
     * Which access token is making this request?
     *
     * Sanctum's plain-text token is `<id>|<secret>`, and the id before the pipe
     * is the row. Read this way rather than through `$request->user()` because
     * these routes authenticate through ResolvesApiIdentity, not the Sanctum
     * guard, so there is no authenticated user object to ask.
     */
    private function currentTokenId(Request $request): ?int
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '' || !str_contains($token, '|')) {
            return null;
        }

        $id = (int) strtok($token, '|');

        return $id > 0 ? $id : null;
    }

    /**
     * A browsable link for a stored avatar filename, or null.
     *
     * Wrapped because the disk needs credentials to build a URL, and a tenant
     * whose object store is not configured must still be able to READ their own
     * account. Without the guard, a missing `DO_SPACES_*` turns the one endpoint
     * every settings screen depends on into a 500 over a profile picture.
     */
    private function avatarUrl(string $filename): ?string
    {
        try {
            return Storage::disk('digitalocean')->url('public/hp_user/' . $filename);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Put an avatar on the object store and record its filename. */
    private function storeAvatar(Request $request, int $userId): void
    {
        // Already validated in updateProfile(), with the rest of the body.
        $file = $request->file('image');

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE CLIENT DOES NOT GET TO NAME THE OBJECT
         * ═══════════════════════════════════════════════════════════════════
         *
         * This used to be `$userId . '_' . time() . '_' . $file->getClientOriginalName()`,
         * and that filename is entirely attacker-controlled.
         *
         * The `image` rule does NOT constrain it. Laravel's `validateImage`
         * defers to `validateMimes`, which compares `guessExtension()` - derived
         * from the BYTES via finfo - against jpg/png/gif/bmp/webp. The only
         * filename check anywhere in that path is `shouldBlockPhpUpload`, which
         * blocks php/phtml/phar and nothing else.
         *
         * So a genuine PNG uploaded as `payload.html` passes validation. And
         * because `putFileAs` hands Flysystem a STREAM rather than a string,
         * `FinfoMimeTypeDetector` skips the buffer sniff and types the object
         * FROM THE KEY - text/html. Written with 'public' visibility, that is a
         * permanent, attacker-controlled, executing page on the company's own
         * CDN host, in the bucket holding every tenant's avatars.
         *
         * `$file->extension()` is `guessExtension()`: the content-derived one.
         * A random basename also stops one upload from guessing another's key.
         */
        $extension = $file->extension() ?: 'jpg';
        $name = $userId . '_' . \Illuminate\Support\Str::random(24) . '.' . $extension;

        /*
         * Same disk and folder tbluserController::update() already uses for
         * avatars, so one person's picture is not in two places.
         *
         * `ContentType` is stated EXPLICITLY. Left out, Flysystem types the
         * object from the key's extension, which is exactly the steering this
         * method now refuses to allow - and `getMimeType()` reads the bytes, so
         * the two cannot disagree.
         */
        Storage::disk('digitalocean')->putFileAs('public/hp_user/', $file, $name, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        DB::table('tbluser')->where('id', $userId)->update(['image' => $name, 'updated_at' => now()]);
    }
}
