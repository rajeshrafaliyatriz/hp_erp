<?php
/**
 * EVIDENCE — an uploaded document actually exists afterwards.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE BUG THIS WAS WRITTEN FOR LOOKED EXACTLY LIKE SUCCESS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The upload form sent the file as `file`. The server asked for `document`. The
 * names did not match, so `hasFile()` was always false, nothing was ever written
 * to storage, and `$file_name` was never assigned - the guard above it was spelled
 * `$filename`, without the underscore, so it did not cover the variable used four
 * lines later.
 *
 * The row inserted with `file_name = NULL` and the screen said "Document Added
 * successfully". Eight rows on live are in that state, including somebody's CV.
 *
 * ── SO THE ASSERTION IS "THE FILE EXISTS", NOT "THE REQUEST SUCCEEDED" ─────
 *
 * A 200 and a database row were exactly what the broken version produced. This
 * checks the only thing that actually matters: that a document handed to the
 * product can be got back out of it. Section 1 would have FAILED before this fix
 * and that is the point of it.
 *
 * Runs on DEV inside a transaction that is always rolled back. The object store is
 * faked, so nothing is uploaded anywhere.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-employee-documents.php';"
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

/*
 * The disk is faked rather than written to. `putFileAs` on the real Space would
 * leave objects behind that the transaction rollback cannot remove - a database
 * rollback says nothing about an object store.
 */
Storage::fake('digitalocean');

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $userId = $db->table('tbluser')->insertGetId([
        'user_profile_id' => $profileId,
        'sub_institute_id' => $tenant,
        'first_name' => 'Document',
        'last_name' => 'Owner',
        'email' => 'document.owner@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('DocCheck123'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $token = \App\Models\auth\tbluserModel::find($userId)->createToken('Doc laptop')->plainTextToken;

    $upload = function (string $field, UploadedFile $file) use ($kernel, $token, $userId, $tenant) {
        $request = \Illuminate\Http\Request::create(
            '/user/user_document/' . $userId,
            'POST',
            [
                'type' => 'API',
                'token' => $token,
                'sub_institute_id' => $tenant,
                'document_title' => 'Test document',
                'document_type_id' => 1,
            ],
            [],
            [$field => $file]
        );
        $request->headers->set('Accept', 'application/json');

        return $kernel->handle($request);
    };

    // ── 1. THE FILE SURVIVES THE UPLOAD ─────────────────────────────────────
    //
    // The assertion the old code failed. It returned 200 and inserted a row while
    // storing nothing, so neither of those is evidence on its own.
    echo "══ 1. a document handed to the product can be got back out ══\n";

    $response = $upload('file', UploadedFile::fake()->create('payslip.pdf', 40, 'application/pdf'));

    $response->getStatusCode() === 200
        ? $ok('the upload returns 200')
        : $bad('the upload returned HTTP ' . $response->getStatusCode());

    $row = $db->table('staff_document')->where('user_id', $userId)->orderByDesc('id')->first();

    $row
        ? $ok('and a row was written')
        : $bad('no staff_document row was created');

    /*
     * THE ONE THAT MATTERS. NULL here is precisely the live bug: a row that claims
     * a document exists, naming no file.
     */
    !empty($row->file_name)
        ? $ok('the row names a file rather than holding NULL')
        : $bad('file_name is NULL - the document was accepted and thrown away');

    if (!empty($row->file_name)) {
        Storage::disk('digitalocean')->exists('public/hp_staff_document/' . $row->file_name)
            ? $ok('and that file is actually in storage')
            : $bad('the row names ' . $row->file_name . ' and no such object exists');
    }

    // ── 2. THE OTHER FIELD NAME WORKS TOO ───────────────────────────────────
    //
    // The Blade screen posts `document`; the React tab posts `file`. Renaming one
    // side alone fixes one caller and breaks the other, so both are accepted - and
    // both are asserted, because "it works for the caller I tested" is how the
    // original mismatch survived.
    echo "\n══ 2. both callers' field names are honoured ══\n";

    $before = $db->table('staff_document')->where('user_id', $userId)->count();
    $upload('document', UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'));
    $second = $db->table('staff_document')->where('user_id', $userId)->orderByDesc('id')->first();

    $db->table('staff_document')->where('user_id', $userId)->count() === $before + 1
        ? $ok("a file sent as 'document' is accepted as well as one sent as 'file'")
        : $bad("the 'document' field name was ignored");

    !empty($second->file_name ?? null)
        ? $ok('and it too names a real file')
        : $bad('the second upload stored NULL');

    // ── 3. THE CLIENT DOES NOT NAME THE OBJECT ──────────────────────────────
    //
    // The extension used to come from the client-supplied filename, which is what
    // AccountController::storeAvatar was hardened against: a genuine image uploaded
    // as `payload.html` becomes an executing page on the company's own CDN.
    echo "\n══ 3. the stored name is derived, not accepted ══\n";

    $stored = (string) ($row->file_name ?? '');

    !str_contains($stored, 'payslip')
        ? $ok('the original filename is not reused as the object key')
        : $bad("the client's filename became the key: $stored");

    str_starts_with($stored, $userId . '_')
        ? $ok('the key is prefixed with the owner id')
        : $bad("the key does not identify its owner: $stored");

    strlen($stored) > 20
        ? $ok('and carries a random component, so one upload cannot guess another')
        : $bad("the key is guessable: $stored");

    // ── 4. WHAT MAY BE UPLOADED IS AN ALLOW-LIST ────────────────────────────
    //
    // There was NO validation here at all. Correcting the field name without this
    // would have turned a broken feature into a working arbitrary file upload,
    // which is strictly worse than the bug.
    echo "\n══ 4. an executable is refused ══\n";

    $refused = null;

    try {
        $refused = $upload('file', UploadedFile::fake()->create('shell.php', 5, 'application/x-php'));
    } catch (\Illuminate\Validation\ValidationException $e) {
        $refused = null; // The validator threw, which is a refusal.
    }

    $phpRows = $db->table('staff_document')
        ->where('user_id', $userId)
        ->where('file_name', 'like', '%.php')
        ->count();

    $phpRows === 0
        ? $ok('a .php upload is refused and stores nothing')
        : $bad('an executable was accepted and stored');

    $oversize = null;

    try {
        // 30 MB against a 20 MB cap.
        $oversize = $upload('file', UploadedFile::fake()->create('huge.pdf', 30720, 'application/pdf'));
    } catch (\Illuminate\Validation\ValidationException $e) {
        $oversize = null;
    }

    $db->table('staff_document')
        ->where('user_id', $userId)
        ->where('document_title', 'Test document')
        ->count() === 2
        ? $ok('and a file over the 20 MB cap is refused too')
        : $bad('an oversized file was stored');

    // ── 5. A ROW IS NEVER CREATED WITHOUT A FILE ────────────────────────────
    //
    // The old behaviour, made impossible: a record claiming a document exists,
    // naming nothing, is worse than an error because the person believes it filed.
    echo "\n══ 5. no file means no row ══\n";

    $countBefore = $db->table('staff_document')->where('user_id', $userId)->count();

    $request = \Illuminate\Http\Request::create('/user/user_document/' . $userId, 'POST', [
        'type' => 'API', 'token' => $token, 'sub_institute_id' => $tenant,
        'document_title' => 'No file at all', 'document_type_id' => 1,
    ]);
    $request->headers->set('Accept', 'application/json');
    $kernel->handle($request);

    $db->table('staff_document')->where('user_id', $userId)->count() === $countBefore
        ? $ok('a request with no file creates no row')
        : $bad('a row was created for an upload that carried no document');
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, row or object kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
