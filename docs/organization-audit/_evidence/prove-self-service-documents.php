<?php
/**
 * EVIDENCE — an employee files their own documents, and nobody else's.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There was no self-service path at all: `AccountController` had zero document
 * references, and the only way to file one was `POST /user/user_document/{id}`
 * on the web stack, which took the target user id from the URL and the TENANT
 * from the request body, with no role gate.
 *
 * So any authenticated employee could file a document against any user in any
 * organisation. Section 3 is that hole, asserted shut.
 *
 * ── AND THE OBJECTS WERE PUBLIC ────────────────────────────────────────────
 *
 * The old path wrote `public` visibility under `{userId}{YmdHis}.ext` and the
 * browser downloaded by building the bucket URL itself - so a guessed key read
 * somebody's ID proof with no authentication. New uploads are private and served
 * through `download()`, behind the same check as the list. Section 4.
 *
 * Runs on DEV inside a transaction that is always rolled back, with the object
 * store faked so nothing is uploaded anywhere.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-self-service-documents.php';"
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();
Storage::fake('digitalocean');

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

DB::beginTransaction();

try {
    $call = function (string $method, string $uri, string $token, array $body = [], array $files = []) use ($kernel) {
        $request = \Illuminate\Http\Request::create(
            $uri, $method, array_merge(['type' => 'API', 'token' => $token], $body), [], $files
        );
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'raw' => $response];
    };

    $make = function (string $roleKey, string $label, int $inTenant) use ($db) {
        $profileId = $db->table('tbluserprofilemaster')
            ->where('sub_institute_id', $inTenant)->where('role_key', $roleKey)->value('id');

        if (!$profileId) {
            return null;
        }

        $id = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $inTenant,
            'first_name' => 'Doc', 'last_name' => $label,
            'email' => 'doc.' . $label . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('DocSelf123'),
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => \App\Models\auth\tbluserModel::find($id)->createToken('Doc laptop')->plainTextToken];
    };

    $me = $make('employee', 'me', $tenant);
    $colleague = $make('employee', 'colleague', $tenant);
    $hr = $make('hr_manager', 'hr', $tenant) ?: $make('administrator', 'hr', $tenant);

    $typeId = (int) $db->table('student_document_type')->where('user_type', 'staff')->value('id');

    // ── 1. I CAN FILE MY OWN ────────────────────────────────────────────────
    echo "══ 1. an employee files a document on their own profile ══\n";

    $upload = $call('POST', '/api/account/documents', $me['token'], [
        'document_title' => 'My resume',
        'document_type_id' => $typeId,
    ], ['document' => UploadedFile::fake()->create('resume.pdf', 30, 'application/pdf')]);

    $upload['status'] === 200
        ? $ok('the upload succeeds with no id parameter anywhere')
        : $bad('upload returned HTTP ' . $upload['status'] . ': ' . json_encode($upload['body']));

    $row = $db->table('staff_document')->where('user_id', $me['id'])->orderByDesc('id')->first();

    $row && !empty($row->file_name)
        ? $ok('and the row names a real file')
        : $bad('no row, or file_name is NULL: ' . json_encode($row));

    if ($row) {
        Storage::disk('digitalocean')->exists($row->file_path ?: ('public/hp_staff_document/' . $row->file_name))
            ? $ok('which is actually in storage')
            : $bad('the object is missing: ' . ($row->file_path ?? $row->file_name));

        !empty($row->mime_type) && (int) $row->file_size > 0
            ? $ok('with its mime type and size recorded')
            : $bad('mime_type/file_size not stored: ' . json_encode([$row->mime_type, $row->file_size]));
    }

    $mine = $call('GET', '/api/account/documents', $me['token']);

    collect($mine['body']['data'] ?? [])->contains('id', $row->id ?? 0)
        ? $ok('and it appears in my own list')
        : $bad('my document is not in my list: ' . json_encode($mine['body']['data'] ?? null));

    is_array($mine['body']['document_types'] ?? null) && count($mine['body']['document_types']) > 0
        ? $ok('served with the document types, so the form does not hardcode them')
        : $bad('no document types returned');

    // ── 2. A COLLEAGUE CANNOT SEE IT; HR CAN ────────────────────────────────
    echo "\n══ 2. colleagues cannot read it, HR can ══\n";

    $colleagueTries = $call('GET', '/api/employees-management/' . $me['id'] . '/documents', $colleague['token']);

    $colleagueTries['status'] !== 200
        ? $ok('a colleague is refused the employee-documents endpoint (HTTP ' . $colleagueTries['status'] . ')')
        : $bad('any employee can list another employee documents');

    if ($hr) {
        $hrReads = $call('GET', '/api/employees-management/' . $me['id'] . '/documents', $hr['token']);

        $hrReads['status'] === 200
            ? $ok('HR can list an employee document set')
            : $bad('HR was refused: HTTP ' . $hrReads['status'] . ' ' . json_encode($hrReads['body']));

        collect($hrReads['body']['data'] ?? [])->contains('id', $row->id ?? 0)
            ? $ok('and sees the document')
            : $bad('HR sees an empty list');
    } else {
        $bad('no HR or administrator profile on this tenant - section 2 could not run');
    }

    // ── 3. THE TENANT IDOR IS SHUT ──────────────────────────────────────────
    //
    // The old route took the target id from the URL and the TENANT from the
    // request body, with no role gate. This is that hole, asserted shut.
    echo "\n══ 3. one organisation cannot reach another documents ══\n";

    $otherTenant = (int) $db->table('school_setup')->where('id', '!=', $tenant)->value('id');
    $outsider = $make('administrator', 'outsider', $otherTenant) ?: $make('employee', 'outsider', $otherTenant);

    if (!$outsider) {
        $bad("tenant $otherTenant has no usable profile - section 3 could not run");
    } else {
        /*
         * An ADMINISTRATOR of another organisation - the strongest role there is,
         * so passing the route gate is not the question. Whether the controller
         * checks the SUBJECT against the caller own tenant is.
         */
        $cross = $call('GET', '/api/employees-management/' . $me['id'] . '/documents', $outsider['token']);

        $cross['status'] === 404
            ? $ok("an administrator of tenant $otherTenant gets 404, not a list")
            : $bad('cross-tenant read returned HTTP ' . $cross['status'] . ': ' . json_encode($cross['body']));

        $crossDownload = $call('GET', '/api/account/documents/' . ($row->id ?? 0) . '/download', $outsider['token']);

        $crossDownload['status'] === 404
            ? $ok('and cannot download it either')
            : $bad('cross-tenant download returned HTTP ' . $crossDownload['status']);
    }

    // ── 4. THE FILE IS SERVED BY US, NOT BY A GUESSABLE URL ─────────────────
    echo "\n══ 4. downloads go through the permission check ══\n";

    $download = $call('GET', '/api/account/documents/' . ($row->id ?? 0) . '/download', $me['token']);

    $download['status'] === 200
        ? $ok('the owner can download their own document')
        : $bad('the owner was refused their own document: HTTP ' . $download['status']);

    $colleagueDownload = $call('GET', '/api/account/documents/' . ($row->id ?? 0) . '/download', $colleague['token']);

    $colleagueDownload['status'] === 404
        ? $ok('and a colleague in the same organisation cannot')
        : $bad('a colleague downloaded another person document: HTTP ' . $colleagueDownload['status']);

    /*
     * Stored PRIVATE. The old path wrote `public` with a key of
     * {userId}{YmdHis}.ext - guess the pattern, read the document, no sign-in.
     */
    /*
     * ── WHY THIS IS A SOURCE CHECK AND NOT getVisibility() ──────────────────
     *
     * The first version asserted
     * `Storage::disk('digitalocean')->getVisibility($path) === 'private'` and it
     * FAILED - not because the code is wrong, but because `Storage::fake()` swaps
     * in a local adapter that reports 'public' for everything. Verified directly:
     * writing with `['visibility' => 'private']`, writing with the string
     * 'private', and even calling `setVisibility('private')` afterwards all read
     * back as 'public' on a fake.
     *
     * So that assertion was measuring the test harness. Asserting it against the
     * real Space instead would mean uploading to a live bucket from an evidence
     * script, which is worse.
     *
     * What IS testable here is that the code asks for private, and the
     * consequence - that reading a document goes through a permission check - is
     * already asserted above: a colleague in the same organisation gets 404 from
     * the download endpoint.
     */
    $controllerSource = file_get_contents(
        (new ReflectionClass(\App\Http\Controllers\HRMS\EmployeeDocumentController::class))->getFileName()
    );

    str_contains($controllerSource, "'visibility' => 'private'")
        ? $ok('the upload asks for private visibility, not public')
        : $bad('the object is written public - a guessed key would read it with no authentication');

    !str_contains($controllerSource, "'visibility' => 'public'")
        ? $ok('and no path in this controller writes a public object')
        : $bad('something here still writes public objects');

    // ── 5. REMOVING ONE IS SCOPED TO ITS OWNER ──────────────────────────────
    echo "\n══ 5. removing one is scoped to its owner ══\n";

    $colleagueDeletes = $call('DELETE', '/api/account/documents/' . ($row->id ?? 0), $colleague['token']);

    $colleagueDeletes['status'] === 404
        ? $ok('a colleague cannot delete my document')
        : $bad('a colleague deleted another person document: HTTP ' . $colleagueDeletes['status']);

    $db->table('staff_document')->where('id', $row->id ?? 0)->whereNull('deleted_at')->exists()
        ? $ok('and it is still there')
        : $bad('the document was deleted by somebody who does not own it');

    $call('DELETE', '/api/account/documents/' . ($row->id ?? 0), $me['token']);

    $db->table('staff_document')->where('id', $row->id ?? 0)->whereNotNull('deleted_at')->exists()
        ? $ok('the owner can remove it, and it soft-deletes so it can be restored')
        : $bad('the owner could not remove their own document');

    // ── 6. WHAT MAY BE UPLOADED IS AN ALLOW-LIST ────────────────────────────
    echo "\n══ 6. an executable and a bogus type are refused ══\n";

    $before = $db->table('staff_document')->where('user_id', $me['id'])->count();

    try {
        $call('POST', '/api/account/documents', $me['token'], [
            'document_title' => 'Not a document',
            'document_type_id' => $typeId,
        ], ['document' => UploadedFile::fake()->create('shell.php', 4, 'application/x-php')]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        // The validator threw, which is the refusal.
    }

    $db->table('staff_document')->where('user_id', $me['id'])->count() === $before
        ? $ok('a .php upload stores nothing')
        : $bad('an executable was accepted');

    /*
     * And the type has to be real. The eight existing live rows point at
     * `document_type_id = 1` in a table that has no such row, because nothing
     * ever checked.
     */
    try {
        $bogus = $call('POST', '/api/account/documents', $me['token'], [
            'document_title' => 'Bad type',
            'document_type_id' => 999999,
        ], ['document' => UploadedFile::fake()->create('ok.pdf', 5, 'application/pdf')]);

        ($bogus['status'] ?? 0) === 422
            ? $ok('and a document type that does not exist is refused')
            : $bad('any integer is accepted as a document type: HTTP ' . ($bogus['status'] ?? '?'));
    } catch (\Illuminate\Validation\ValidationException $e) {
        $ok('and a document type that does not exist is refused');
    }
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account, row or object kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
