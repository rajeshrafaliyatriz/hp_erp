<?php
/**
 * EVIDENCE — one organisation, one identity, and never another company's logo.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Three tables described an organisation and disagreed about how many existed:
 * `school_setup` 12 rows on live, `org_details` 4, `institute_detail` 5. The
 * organisation screen read the logo from `org_details`, so for EIGHT of twelve
 * tenants there was nothing to read - a logo could be uploaded, stored, and never
 * appear anywhere except a PDF certificate.
 *
 * Worse: `scholar_clone.png` was the stored logo of organisations 1
 * ("Triz High School"), 5 ("IT") and 6 ("Scholar Clone") on BOTH databases. Two
 * customers were branded with a third customer's mark, and nothing in the product
 * would ever have said so.
 *
 * ── THE ASSERTION THAT MATTERS MOST ─────────────────────────────────────────
 *
 * Section 2: no two organisations share a logo file. That is the one that was
 * false on live, it is the one a human would never notice (each screen looks fine
 * in isolation), and it is the one that recurs the moment two tenants are seeded
 * from one template.
 *
 * ── AND WHY THIS RUNS AGAINST REAL DATA, NOT A FIXTURE ──────────────────────
 *
 * Sections 1 and 2 read the tenants that actually exist, in a transaction, without
 * writing. A fixture would prove the rule and say nothing about the twelve live
 * organisations - which is where the collision was.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-organisation-identity.php';"
 */

use Illuminate\Support\Facades\DB;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);

\Illuminate\Support\Facades\Mail::fake();

$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

// ── 1. EVERY ORGANISATION HAS A STATUTORY RECORD ────────────────────────────
echo "══ 1. every organisation has both of its records ══\n";

$orgs = $db->table('school_setup')->count();

$missing = $db->table('school_setup')
    ->whereNotIn('id', function ($q) {
        $q->select('sub_institute_id')->from('org_details')->whereNotNull('sub_institute_id');
    })
    ->pluck('SchoolName');

$missing->isEmpty()
    ? $ok("all $orgs organisations have an org_details row")
    : $bad($missing->count() . " of $orgs organisations have no statutory record: " . $missing->take(5)->implode(', '));

/*
 * And not two. A second row would make `save()`'s "update the existing one"
 * ambiguous, and which one a reader got would depend on row order.
 */
$doubled = $db->table('org_details')
    ->selectRaw('sub_institute_id, COUNT(*) as n')
    ->whereNotNull('sub_institute_id')
    ->groupBy('sub_institute_id')
    ->havingRaw('COUNT(*) > 1')
    ->pluck('sub_institute_id');

$doubled->isEmpty()
    ? $ok('and none has two, so there is no ambiguity about which is the record')
    : $bad('tenants with more than one org_details row: ' . $doubled->implode(', '));

// ── 2. NO TWO ORGANISATIONS WEAR THE SAME LOGO ──────────────────────────────
echo "\n══ 2. a logo file belongs to exactly one organisation ══\n";

$shared = $db->table('school_setup')
    ->selectRaw("Logo, COUNT(*) as n, GROUP_CONCAT(SchoolName SEPARATOR ' | ') as names")
    ->whereNotNull('Logo')
    ->where('Logo', '<>', '')
    ->groupBy('Logo')
    ->havingRaw('COUNT(*) > 1')
    ->get();

$shared->isEmpty()
    ? $ok('no logo file is used by more than one organisation')
    : $bad($shared->count() . ' logo file(s) shared: '
        . $shared->map(fn ($r) => "{$r->Logo} -> {$r->names}")->implode('; '));

/*
 * The two columns must agree. They drifted because `save()` writes both while the
 * older web controller wrote only `school_setup.Logo`, so a screen reading either
 * one was showing something true about a different moment.
 */
$disagreeing = $db->table('school_setup as s')
    ->join('org_details as o', 'o.sub_institute_id', '=', 's.id')
    ->whereRaw("TRIM(COALESCE(s.Logo, '')) <> TRIM(COALESCE(o.logo, ''))")
    ->count();

$disagreeing === 0
    ? $ok('and the two logo columns agree for every organisation')
    : $bad("$disagreeing organisation(s) hold a different logo in each column");

// ── 3. THE RULE THAT RELEASED THEM IS DERIVED, NOT A LIST OF IDS ────────────
//
// Asserted on the OUTCOME rather than on the migration's code: the organisation
// whose name matches the filename is the one that kept it. Hardcoding ids would
// have fixed three rows and left the next collision to be found by a customer.
echo "\n══ 3. the organisation that kept the shared logo is the one it names ══\n";

$scholar = $db->table('school_setup')->where('id', 6)->first(['SchoolName', 'Logo']);
$triz = $db->table('school_setup')->where('id', 1)->first(['SchoolName', 'Logo']);

if (!$scholar || !$triz) {
    $bad('organisations 1 and 6 are not both present on this database');
} else {
    trim((string) $scholar->Logo) === 'scholar_clone.png'
        ? $ok('"Scholar Clone" kept scholar_clone.png - its name matches the file')
        : $bad('organisation 6 lost its own logo: ' . var_export($scholar->Logo, true));

    trim((string) $triz->Logo) === ''
        ? $ok('"Triz High School" no longer wears it, and falls back to its own initials')
        : $bad('organisation 1 still holds ' . var_export($triz->Logo, true));
}

// ── 4. THE API SERVES AN IDENTITY FOR EVERY ORGANISATION ────────────────────
echo "\n══ 4. /api/organization/profile answers for a tenant with no statutory data ══\n";

DB::beginTransaction();

try {
    /*
     * A tenant chosen because it had NO org_details row before the migration -
     * exactly the case where the old screen showed nothing. Its administrator is
     * used, because the endpoint is role-gated.
     */
    $tenant = (int) $db->table('school_setup')->orderByDesc('id')->value('id');

    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)
        ->whereNotNull('role_key')
        ->value('id');

    if (!$profileId) {
        $bad("tenant $tenant has no profile with a role_key - cannot call the endpoint");
    } else {
        $userId = $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Identity',
            'last_name' => 'Reader',
            'email' => 'identity.reader@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('IdentityCheck123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = \App\Models\auth\tbluserModel::find($userId)->createToken('Identity laptop')->plainTextToken;

        $request = \Illuminate\Http\Request::create('/api/organization/profile', 'GET', [
            'type' => 'API', 'token' => $token,
        ]);
        $request->headers->set('Accept', 'application/json');
        $body = json_decode($kernel->handle($request)->getContent(), true);

        $identity = $body['data']['identity'] ?? null;

        is_array($identity)
            ? $ok('the response carries an identity block')
            : $bad('no identity block: ' . json_encode($body));

        if (is_array($identity)) {
            ($identity['name'] ?? null) === $db->table('school_setup')->where('id', $tenant)->value('SchoolName')
                ? $ok('naming the organisation from school_setup, which every tenant has')
                : $bad('the name does not match school_setup: ' . var_export($identity['name'] ?? null, true));

            array_key_exists('logo_url', $identity)
                ? $ok('and a logo_url, so the screen never builds a bucket path itself')
                : $bad('no logo_url - the screen would have to know the storage layout');
        }
    }
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account or token kept)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
