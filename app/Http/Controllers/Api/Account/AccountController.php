<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Controller;
use App\Services\Account\UserPreferences;
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

    /** GET /api/account/me */
    public function me(Request $request, UserPreferences $preferences)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $user = DB::table('tbluser')
            ->where('id', $identity['user_id'])
            ->first(array_merge(self::EDITABLE, ['id', 'email', 'image', 'employee_no', 'last_login']));

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

        return response()->json([
            'status' => true,
            'data' => [
                'profile' => $user,
                'preferences' => $preferences->all((int) $identity['user_id']),
                /*
                 * The role is reported so the settings screen knows which
                 * sections to show. It is a PRESENTATION hint - every endpoint
                 * behind those sections is guarded independently, so a caller
                 * who lies to their own browser gains nothing.
                 */
                'role' => RoleKey::forUserId((int) $identity['user_id']),
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
        ]);

        $saved = $preferences->save(
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            $request->all()
        );

        return response()->json([
            'status' => true,
            'message' => 'Saved.',
            'data' => ['preferences' => $saved],
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

        DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
            ->where('tokenable_id', $user->id)
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Your password is changed. Any other signed-in devices have been signed out.',
        ]);
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

        return response()->json([
            'status' => true,
            'message' => $ended === 1
                ? 'That device has been signed out.'
                : $ended . ' device(s) have been signed out.',
            'data' => ['ended' => $ended],
        ]);
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
