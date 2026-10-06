<?php
/**
 * X-20 — ONE COMPETENCY PER DISTINCT REFERENCED SKILL. (G-DATA-11, option A)
 *
 * THE PREMISE WAS CHECKED BEFORE ANYTHING WAS WRITTEN, and it held:
 *
 *   competency_id POINTS AT A SKILL ROW. It is the old conflation, not a bundle.
 *
 *   - MEANING: competency_id=1 -> "Auditing and Assurance Standards",
 *     =2 -> "Audit Frameworks", =4 -> "Engagement Execution". The referring
 *     plan actions ("Complete the required certification course") are development
 *     ACTIONS FOR that skill.
 *   - STRUCTURE: neither s_users_skills nor master_skills has a single KASBA
 *     dimension column. They are flat skill records. They cannot be bundles.
 *   - REFERENT: 805 of 805 references have the skill row's tenant EQUAL to the
 *     referring row's tenant in `s_users_skills`. Four independent tables, 100%.
 *     That settles s_users_skills over master_skills, which an id-join could not.
 *
 * SO A WHOLESALE MIGRATION WOULD IMPORT THE CONFLATION INTO THE NEW MODEL.
 * Instead: ONE COMPETENCY PER DISTINCT REFERENCED SKILL, containing that skill as
 * its SINGLE KASBA item of type `skill`. A one-item bundle is a valid bundle.
 * Nothing is lost, and customers enrich the other four dimensions later - which is
 * the seed-library import's job, not this migration's.
 *
 * NOTHING IS DELETED. s_users_skills and master_skills are untouched. The original
 * value of every re-pointed reference is preserved in a new `legacy_skill_id`
 * column, so "re-point" never means "overwrite and lose".
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const HOLDERS = [
    's_competency_plan_actions',
    's_competency_development_plans',
    's_competency_certifications',
    'lms_assignments',
];

// PREDICTIONS, STATED BEFORE THE WRITE (R19). The script REFUSES if they moved -
// the X-18 pattern, kept for every approved bulk write.
const PREDICT_SKILLS = 199;
const PREDICT_REFS   = 805;

echo "\n================ X-20 — COMPETENCY SPACE MIGRATION ================\n\n";

// ── 0. RE-VERIFY THE PREMISE AT RUN TIME ────────────────────────────────────
$refs = 0;
foreach (HOLDERS as $t) {
    $refs += DB::table($t)->whereNotNull('competency_id')->where('competency_id', '!=', 0)->count();
}
$pairs = collect();
foreach (HOLDERS as $t) {
    $pairs = $pairs->merge(
        DB::table($t)->whereNotNull('competency_id')->where('competency_id', '!=', 0)
            ->select('sub_institute_id', 'competency_id')->get()
            ->map(fn ($r) => (int) $r->sub_institute_id . '|' . (int) $r->competency_id)
    );
}
$distinct = $pairs->unique()->values();

printf("references found : %d  (predicted %d)\n", $refs, PREDICT_REFS);
printf("distinct (tenant, skill) : %d  (predicted %d)\n\n", $distinct->count(), PREDICT_SKILLS);

if ($refs !== PREDICT_REFS || $distinct->count() !== PREDICT_SKILLS) {
    echo "REFUSING TO WRITE - the set moved since the decision was taken.\n";
    echo "Triz approved a migration of 805 references over 199 skills. Writing a\n";
    echo "different set would be writing something nobody agreed to.\n";
    exit(1);
}

// Every referenced skill must resolve, in the referring row's own tenant.
$unresolved = [];
foreach ($distinct as $p) {
    [$tenant, $skillId] = array_map('intval', explode('|', $p));
    $ok = DB::table('s_users_skills')->where('id', $skillId)
        ->where('sub_institute_id', $tenant)->exists();
    if (!$ok) $unresolved[] = $p;
}
printf("skills that FAIL to resolve in their own tenant: %d\n", count($unresolved));
if ($unresolved) {
    echo "  HELD (F-07b): " . implode(', ', array_slice($unresolved, 0, 10)) . "\n";
}
echo "\n";

// ── 1. PROVENANCE COLUMN ────────────────────────────────────────────────────
echo "1. PRESERVING THE ORIGINAL VALUE (so 're-point' never means 'lose')\n";
foreach (HOLDERS as $t) {
    if (!Schema::hasColumn($t, 'legacy_skill_id')) {
        Schema::table($t, function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_skill_id')->nullable()->after('competency_id')
                ->comment('X-20: the s_users_skills id this row referenced before the competency-space migration');
        });
        echo "   $t: legacy_skill_id added\n";
    } else {
        echo "   $t: legacy_skill_id already present\n";
    }
}

// ── 2. ONE COMPETENCY PER DISTINCT REFERENCED SKILL ─────────────────────────
echo "\n2. CREATING ONE COMPETENCY PER DISTINCT REFERENCED SKILL\n";
$map = [];        // "tenant|skillId" => new competency id
$created = 0; $reused = 0; $items = 0; $held = [];

foreach ($distinct as $p) {
    [$tenant, $skillId] = array_map('intval', explode('|', $p));

    $skill = DB::table('s_users_skills')->where('id', $skillId)
        ->where('sub_institute_id', $tenant)->first(['id', 'title', 'skill_code']);

    if (!$skill) { $held[] = $p; continue; }

    $code = 'SKILL-' . $skillId;

    $existing = DB::table('competency')->where('sub_institute_id', $tenant)
        ->where('code', $code)->value('id');

    if ($existing) { $map[$p] = (int) $existing; $reused++; continue; }

    $cid = DB::table('competency')->insertGetId([
        'sub_institute_id'    => $tenant,
        'code'                => $code,
        'name'                => $skill->title,
        'description'         => 'Migrated by X-20 from s_users_skills #' . $skillId
                               . '. One-item bundle: enrich with knowledge, ability, behaviour'
                               . ' and attitude items via the seed-library import.',
        'competency_type'     => 'migrated',
        'criticality'         => 'medium',
        'requires_assessment' => 1,
        'status'              => 'active',
        'version'             => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
    $map[$p] = $cid;
    $created++;

    // THE SINGLE KASBA ITEM. kasba_type='skill' - one of five dimensions, which is
    // exactly the distinction Q-A2 drew and this migration must not re-blur.
    DB::table('competency_kasba_item')->insert([
        'sub_institute_id' => $tenant,
        'competency_id'    => $cid,
        'kasba_type'       => 'skill',
        'item_id'          => $skillId,      // TARGET state - a canonical row exists
        'item_label'       => null,
        'weight'           => 1.00,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $items++;
}

printf("   competencies created : %d\n", $created);
printf("   reused (already made): %d\n", $reused);
printf("   KASBA items created  : %d  (one per competency, kasba_type='skill', TARGET)\n", $items);
printf("   HELD (unresolvable)  : %d\n", count($held));

// ── 3. RE-POINT ─────────────────────────────────────────────────────────────
echo "\n3. RE-POINTING THE REFERENCES\n";
$repointed = []; $skipped = [];

foreach (HOLDERS as $t) {
    $n = 0; $miss = 0;
    $rows = DB::table($t)->whereNotNull('competency_id')->where('competency_id', '!=', 0)
        ->whereNull('legacy_skill_id')          // never re-point twice
        ->get(['id', 'sub_institute_id', 'competency_id']);

    foreach ($rows as $row) {
        $key = (int) $row->sub_institute_id . '|' . (int) $row->competency_id;
        if (!isset($map[$key])) { $miss++; continue; }

        DB::table($t)->where('id', $row->id)->update([
            'legacy_skill_id' => (int) $row->competency_id,   // preserved FIRST
            'competency_id'   => $map[$key],
        ]);
        $n++;
    }
    $repointed[$t] = $n;
    $skipped[$t] = $miss;
    printf("   %-34s re-pointed %-5d held %d\n", $t, $n, $miss);
}
printf("   TOTAL re-pointed: %d of %d\n", array_sum($repointed), PREDICT_REFS);

// ── 4. VERIFY ───────────────────────────────────────────────────────────────
echo "\n4. VERIFICATION\n";
$nowResolve = 0; $total = 0;
foreach (HOLDERS as $t) {
    $total += DB::table($t)->whereNotNull('competency_id')->where('competency_id', '!=', 0)->count();
    $nowResolve += DB::table("$t as h")->join('competency as c', 'c.id', '=', 'h.competency_id')
        ->whereNotNull('h.competency_id')->where('h.competency_id', '!=', 0)->count();
}
printf("   references resolving in `competency` : %d of %d (%.1f%%)  - was 0%%\n",
    $nowResolve, $total, $total ? 100 * $nowResolve / $total : 0);

$crossTenant = 0;
foreach (HOLDERS as $t) {
    $crossTenant += DB::table("$t as h")->join('competency as c', 'c.id', '=', 'h.competency_id')
        ->whereColumn('c.sub_institute_id', '!=', 'h.sub_institute_id')->count();
}
printf("   cross-tenant references created      : %d (must be 0)\n", $crossTenant);
printf("   s_users_skills rows                  : %d (untouched)\n", DB::table('s_users_skills')->count());
printf("   master_skills rows                   : %d (untouched)\n", DB::table('master_skills')->count());
printf("   provenance preserved                 : %d rows carry legacy_skill_id\n",
    array_sum(array_map(fn ($t) => DB::table($t)->whereNotNull('legacy_skill_id')->count(), HOLDERS)));

// ── 5. X-19, NOW UNBLOCKED ──────────────────────────────────────────────────
echo "\n5. X-19 - course_competency_map now has ONE declared referent\n";
$before = DB::table('course_competency_map')->count();

$pairsX19 = DB::table('lms_assignments')
    ->whereNotNull('competency_id')->whereNotNull('course_id')
    ->whereNotNull('legacy_skill_id')            // only rows X-20 re-pointed
    ->select('sub_institute_id', 'course_id', 'competency_id')
    ->distinct()->get();

$w = 0; $skip = 0; $perTenant = [];
foreach ($pairsX19 as $p) {
    $courseOk = DB::table('sub_std_map')->where('id', $p->course_id)
        ->where('sub_institute_id', $p->sub_institute_id)->exists();
    $compOk = DB::table('competency')->where('id', $p->competency_id)
        ->where('sub_institute_id', $p->sub_institute_id)->exists();
    if (!$courseOk || !$compOk) { $skip++; continue; }

    $exists = DB::table('course_competency_map')
        ->where('sub_institute_id', $p->sub_institute_id)
        ->where('course_id', $p->course_id)->where('competency_id', $p->competency_id)->exists();
    if ($exists) { $skip++; continue; }

    DB::table('course_competency_map')->insert([
        'sub_institute_id' => $p->sub_institute_id,
        'course_id'        => $p->course_id,
        'competency_id'    => $p->competency_id,
        'proficiency_level' => null,
        'is_primary'       => 0,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $w++;
    $perTenant[$p->sub_institute_id] = ($perTenant[$p->sub_institute_id] ?? 0) + 1;
}
printf("   candidate pairs : %d\n", $pairsX19->count());
printf("   written         : %d\n", $w);
printf("   HELD            : %d (course or competency does not resolve in that tenant)\n", $skip);
foreach ($perTenant as $t => $n) printf("      tenant %-4d %d\n", $t, $n);
printf("   course_competency_map: %d -> %d\n", $before, DB::table('course_competency_map')->count());

echo "\n";
