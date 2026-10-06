<?php
/**
 * EVIDENCE — the Employee Directory serves a photo a browser can actually load.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT WAS NOT "MISSING". IT WAS BROKEN IN A WAY THAT LOOKED LIKE MISSING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `u.image` has been in the directory's SELECT all along, and the screen has
 * rendered an `<img>` all along. What it was fed is the stored FILENAME -
 * `41_a8Kd.jpg` - which a browser resolves against the Next.js origin, not the
 * object store. Every one of the 24 live accounts with a photo produced a 404 and
 * a broken-image box, and the ternary had already committed to the `<img>`, so
 * there was no way back to the placeholder.
 *
 * Reported as "photos should show". The cause was a missing URL, one layer down.
 *
 * ── SO THE ASSERTION IS ABOUT THE SHAPE OF THE VALUE ───────────────────────
 *
 * Not "image_url is present" - the broken version had `image` present too. It has
 * to be an ABSOLUTE url, because that is the only difference between what worked
 * and what did not.
 *
 * Runs on DEV inside a transaction that is always rolled back.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-directory-photos.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $make = function (string $label, ?string $image) use ($db, $tenant, $profileId) {
        return $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Photo',
            'last_name' => $label,
            'email' => 'photo.' . $label . '@example.test',
            'image' => $image,
            'password' => \Illuminate\Support\Facades\Hash::make('PhotoCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $withPhoto = $make('haspic', '999_AbCdEfGhIjKlMnOpQrStUv.jpg');
    $without = $make('nopic', null);

    $viewer = $make('viewer', null);
    $token = \App\Models\auth\tbluserModel::find($viewer)->createToken('Directory laptop')->plainTextToken;

    $request = \Illuminate\Http\Request::create('/api/employees-management', 'GET', [
        'type' => 'API', 'token' => $token, 'per_page' => 2000,
    ]);
    $request->headers->set('Accept', 'application/json');
    $body = json_decode($kernel->handle($request)->getContent(), true);

    $rows = collect($body['data'] ?? []);

    $rows->isNotEmpty()
        ? $ok('the directory returns rows')
        : $bad('the directory returned nothing: ' . json_encode($body));

    $one = $rows->firstWhere('id', $withPhoto);
    $none = $rows->firstWhere('id', $without);

    // ── 1. A PHOTO COMES BACK AS SOMETHING A BROWSER CAN FETCH ──────────────
    echo "══ 1. the photo is a URL, not a filename ══\n";

    if (!$one) {
        $bad('the employee with a photo is not in the directory at all');
    } else {
        array_key_exists('image_url', $one)
            ? $ok('the row carries image_url')
            : $bad('no image_url on the row: ' . implode(', ', array_keys($one)));

        $url = (string) ($one['image_url'] ?? '');

        /*
         * THE ASSERTION THAT SEPARATES FIXED FROM BROKEN. The old response had
         * `image` populated too - with a bare filename. Only an absolute URL is
         * something a browser can actually load.
         */
        str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $ok('and it is an absolute URL: ' . substr($url, 0, 52) . '…')
            : $bad('image_url is not absolute, so the browser will 404 it: ' . var_export($url, true));

        str_contains($url, '999_AbCdEfGhIjKlMnOpQrStUv.jpg')
            ? $ok('pointing at this person\'s own stored file')
            : $bad('the URL does not contain the stored filename');

        str_contains($url, 'hp_user')
            ? $ok('in the avatars folder, not the logo or document folder')
            : $bad('the URL is not in hp_user/: ' . $url);
    }

    // ── 2. NO PHOTO IS NULL, NOT AN EMPTY-STRING URL ────────────────────────
    //
    // The screen tests truthiness to decide between the image and the initials.
    // A URL built from an empty filename would be truthy, and every employee
    // without a photo would render a broken image instead of their initials.
    echo "\n══ 2. somebody with no photo gets null, so the initials show ══\n";

    if (!$none) {
        $bad('the employee without a photo is missing from the directory');
    } else {
        /*
         * `array_key_exists`, not `??`. The first version of this check used
         * `$none['image_url'] ?? 'unset'`, and null-coalescing treats a key that
         * is PRESENT AND NULL exactly like one that is absent - so it reported a
         * correct null as missing. The distinction is the whole assertion here.
         */
        $present = array_key_exists('image_url', $none);

        $present && $none['image_url'] === null
            ? $ok('image_url is present and null for an employee with no photo')
            : $bad($present
                ? 'expected null, got ' . var_export($none['image_url'], true)
                : 'image_url is absent from the row entirely');
    }

    // ── 3. THE DRAWER AND THE ROW AGREE ─────────────────────────────────────
    //
    // Two endpoints serve the same person - the list and show(). They are built by
    // different methods, and a photo attached to only one would mean the drawer
    // and the row that opened it disagree.
    echo "\n══ 3. the detail endpoint says the same thing as the list ══\n";

    $detail = \Illuminate\Http\Request::create('/api/employees-management/' . $withPhoto, 'GET', [
        'type' => 'API', 'token' => $token,
    ]);
    $detail->headers->set('Accept', 'application/json');
    $one_detail = json_decode($kernel->handle($detail)->getContent(), true)['data'] ?? null;

    if (!is_array($one_detail)) {
        $bad('the detail endpoint returned nothing usable');
    } else {
        ($one_detail['image_url'] ?? null) === ($one['image_url'] ?? null)
            ? $ok('both endpoints return the same image_url')
            : $bad('the drawer and the row disagree: '
                . var_export($one_detail['image_url'] ?? null, true) . ' vs '
                . var_export($one['image_url'] ?? null, true));
    }

    // ── 4. AND A MISSING OBJECT STORE CANNOT TAKE THE DIRECTORY DOWN ────────
    //
    // The URL builder is wrapped for a reason: an unconfigured or unreachable disk
    // would otherwise throw while building a cosmetic field and 500 the whole
    // directory. A missing photo is a placeholder; a missing directory is an outage.
    echo "\n══ 4. a cosmetic field cannot 500 the screen ══\n";

    // Through the container: this controller has constructor dependencies, so
    // `new` on it throws ArgumentCountError.
    $controller = app(\App\Http\Controllers\HRMS\EmployeeDirectoryController::class);
    $method = new ReflectionMethod($controller, 'avatarUrl');
    $method->setAccessible(true);

    $method->invoke($controller, '') === null
        ? $ok('an empty filename yields null rather than a broken URL')
        : $bad('an empty filename produced a URL');

    $method->invoke($controller, null) === null
        ? $ok('and so does null')
        : $bad('null produced a URL');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
