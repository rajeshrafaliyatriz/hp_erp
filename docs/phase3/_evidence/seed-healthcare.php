<?php
/**
 * TASK 2 — ONE ORGANISATION, SEEDED END TO END.
 *
 * TENANT 3, "healthcare". Chosen on measurement, not preference: 108 users,
 * 339 job roles, **70 courses** (more than every other tenant combined), 71
 * departments, and it is the only tenant holding all NINE role_key profiles
 * (ids 7, 8, 9, 51-56). Step 9 needs courses, and only this tenant has them.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY EVERY PERSON HERE IS NEW, AND IT IS NOT A SHORTCUT
 *
 *   The instruction was "use existing users where possible; if a user already has
 *   a job role or a manager, leave them and pick another."
 *
 *   ALL 108 EXISTING TENANT-3 USERS ALREADY HAVE `allocated_standards` SET.
 *   Measured, not assumed. There is no "another" to pick. Assigning a job role to
 *   any of them would overwrite one, which the same instruction forbids.
 *
 *   And logins need known passwords. Setting a password on a real person's
 *   account is the most destructive overwrite available here - it locks them out
 *   of a live system. So: NEW users, NEW departments, NEW job roles. Everything
 *   additive, every id recorded, nothing existing touched.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every row created is written to docs/phase3/_changes/SEED-REGISTER-2026-08-11.md
 * with its table and id, so the whole slice is identifiable and removable.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Org\ReportingLineValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const TENANT = 3;
const PASSWORD = 'G2GDemo@2026';
const STAMP = '2026-08-11';

$reg = [];                       // table => [ids]
function reg(string $table, $id) { $GLOBALS['reg'][$table][] = $id; return $id; }

$log = [];
function say(string $s) { $GLOBALS['log'][] = $s; echo $s . "\n"; }

$validator = new ReportingLineValidator();

say("\n================ SEEDING TENANT 3 (healthcare) ================\n");

// ── GUARD: refuse to run twice ──────────────────────────────────────────────
$already = DB::table('competency')->where('sub_institute_id', TENANT)
    ->where('code', 'like', 'HC-%')->count();
if ($already > 0) {
    say("REFUSING: {$already} HC-* competencies already exist in tenant " . TENANT . '.');
    say('This script is not idempotent by design - re-running would create a SECOND');
    say('slice rather than reconcile with the first. Remove the previous seed using');
    say('the register, or edit the codes. Nothing was written.');
    exit(1);
}

// ── 2. DEPARTMENTS ──────────────────────────────────────────────────────────
say('2. DEPARTMENTS');
$departments = [
    'Clinical Nursing',
    'Allied Health & Therapy',
    'Patient Services & Care Coordination',
];
$deptIds = [];
foreach ($departments as $name) {
    $deptIds[$name] = reg('hrms_departments', DB::table('hrms_departments')->insertGetId([
        'department'          => $name,
        'parent_id'           => 0,
        'roles_responsibility' => $name,
        'status'              => 1,
        'is_calculated'       => 0,
        'sub_institute_id'    => TENANT,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]));
    say(sprintf('   #%-6d %s', $deptIds[$name], $name));
}

// ── 3. JOB ROLES ────────────────────────────────────────────────────────────
say("\n3. JOB ROLES");
$roleSpec = [
    'Clinical Nursing' => [
        'Staff Nurse (Medical Ward)',
        'Senior Staff Nurse (Medical Ward)',
        'Nurse Unit Manager',
    ],
    'Allied Health & Therapy' => [
        'Physiotherapist (Inpatient)',
        'Occupational Therapist (Rehabilitation)',
    ],
    'Patient Services & Care Coordination' => [
        'Patient Care Coordinator',
        'Ward Administration Officer',
    ],
];
$roleIds = [];
foreach ($roleSpec as $dept => $roles) {
    foreach ($roles as $r) {
        $roleIds[$r] = reg('s_user_jobrole', DB::table('s_user_jobrole')->insertGetId([
            'jobrole'          => $r,
            'department'       => $dept,
            'department_id'    => $deptIds[$dept],
            'status'           => 'Active',
            'sub_institute_id' => TENANT,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]));
        say(sprintf('   #%-6d %-42s (%s)', $roleIds[$r], $r, $dept));
    }
}

// ── 4. COMPETENCIES + KASBA BUNDLES ─────────────────────────────────────────
say("\n4. COMPETENCIES (KASBA bundles - TARGET where a canonical skill exists, HOLDING otherwise)");

/**
 * Each competency carries KASBA items. Where the label matches a real tenant-3
 * skill in s_users_skills the item is stored as a TARGET (item_id); where no
 * canonical row exists it stays a HOLDING label (item_label) - the designed
 * two-state behaviour, exercised for real rather than in a test.
 */
$competencySpec = [
    ['HC-CLIN-01', 'Clinical Assessment & Triage', 'clinical', 'high', [
        ['knowledge', 'Physiological assessment fundamentals', 3.0],
        ['skill',     'Patient triage',                        4.0],
        ['ability',   'Prioritise under time pressure',        3.0],
    ]],
    ['HC-MED-01', 'Medication Administration Safety', 'clinical', 'critical', [
        ['knowledge', 'Pharmacology and dosage calculation',   4.0],
        ['skill',     'Medication administration',             4.0],
        ['behaviour', 'Double-check discipline',               3.0],
    ]],
    ['HC-IPC-01', 'Infection Prevention & Control', 'clinical', 'critical', [
        ['knowledge', 'Infection control protocols',           3.0],
        ['behaviour', 'Hand hygiene compliance',               4.0],
        ['attitude',  'Challenging unsafe practice',           2.0],
    ]],
    ['HC-COMM-01', 'Patient Communication & Empathy', 'behavioural', 'high', [
        ['skill',     'Patient communication',                 4.0],
        ['attitude',  'Empathy in distressing situations',     3.0],
        ['behaviour', 'Active listening',                      3.0],
    ]],
    ['HC-DOC-01', 'Clinical Documentation', 'functional', 'medium', [
        ['knowledge', 'Record-keeping standards',              3.0],
        ['skill',     'Clinical documentation',                3.0],
    ]],
    ['HC-REHAB-01', 'Rehabilitation Programme Design', 'clinical', 'high', [
        ['knowledge', 'Rehabilitation principles',             4.0],
        ['skill',     'Exercise prescription',                 4.0],
        ['ability',   'Adapt a programme to progress',         3.0],
    ]],
    ['HC-MAN-01', 'Manual Therapy Technique', 'clinical', 'high', [
        ['skill',     'Manual therapy',                        5.0],
        ['knowledge', 'Musculoskeletal anatomy',               3.0],
    ]],
    ['HC-CARE-01', 'Care Plan Coordination', 'functional', 'high', [
        ['skill',     'Care planning',                         4.0],
        ['ability',   'Coordinate across disciplines',         3.0],
        ['behaviour', 'Follow-through on actions',             3.0],
    ]],
    ['HC-HAND-01', 'Escalation & Handover', 'functional', 'critical', [
        ['knowledge', 'Escalation criteria',                   3.0],
        ['skill',     'Structured handover (SBAR)',            4.0],
    ]],
    ['HC-SUP-01', 'Team Supervision', 'leadership', 'high', [
        ['ability',   'Supervise and delegate',                4.0],
        ['behaviour', 'Give developmental feedback',           3.0],
        ['attitude',  'Accountability for team outcomes',      3.0],
    ]],
];

$compIds = []; $itemIds = []; $target = 0; $holding = 0;

foreach ($competencySpec as [$code, $name, $type, $crit, $items]) {
    $cid = reg('competency', DB::table('competency')->insertGetId([
        'sub_institute_id'    => TENANT,
        'code'                => $code,
        'name'                => $name,
        'description'         => $name . ' - seeded capability slice, ' . STAMP,
        'competency_type'     => $type,
        'criticality'         => $crit,
        'requires_assessment' => 1,
        'status'              => 'active',
        'version'             => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]));
    $compIds[$code] = $cid;

    foreach ($items as [$kasba, $label, $weight]) {
        // TARGET if the tenant already has this skill by name; HOLDING otherwise.
        $skillId = DB::table('s_users_skills')
            ->where('sub_institute_id', TENANT)
            ->where('title', $label)
            ->value('id');

        $iid = reg('competency_kasba_item', DB::table('competency_kasba_item')->insertGetId([
            'sub_institute_id' => TENANT,
            'competency_id'    => $cid,
            'kasba_type'       => $kasba,
            'item_id'          => $skillId ?: null,
            'item_label'       => $skillId ? null : $label,
            'weight'           => $weight,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]));
        $itemIds[$code][] = ['id' => $iid, 'label' => $label, 'kasba' => $kasba];
        $skillId ? $target++ : $holding++;
    }
    say(sprintf('   #%-5d %-12s %-36s %d items', $cid, $code, $name, count($items)));
}
say(sprintf('   -> %d KASBA items: %d TARGET (item_id), %d HOLDING (item_label)',
    $target + $holding, $target, $holding));

// ── 5. jobrole_competency_map ───────────────────────────────────────────────
say("\n5. jobrole_competency_map (the table the whole chain resolves against - 0 rows before this)");
$mapSpec = [
    'Staff Nurse (Medical Ward)'              => [['HC-CLIN-01',3,1],['HC-MED-01',3,1],['HC-IPC-01',4,1],['HC-COMM-01',3,0]],
    'Senior Staff Nurse (Medical Ward)'       => [['HC-CLIN-01',4,1],['HC-MED-01',4,1],['HC-HAND-01',4,1],['HC-DOC-01',3,0]],
    'Nurse Unit Manager'                      => [['HC-SUP-01',4,1],['HC-HAND-01',4,1],['HC-IPC-01',4,1],['HC-CARE-01',3,0]],
    'Physiotherapist (Inpatient)'             => [['HC-REHAB-01',4,1],['HC-MAN-01',4,1],['HC-COMM-01',3,1]],
    'Occupational Therapist (Rehabilitation)' => [['HC-REHAB-01',4,1],['HC-CARE-01',3,1],['HC-COMM-01',3,0]],
    'Patient Care Coordinator'                => [['HC-CARE-01',4,1],['HC-COMM-01',4,1],['HC-DOC-01',3,1]],
    'Ward Administration Officer'             => [['HC-DOC-01',3,1],['HC-COMM-01',3,1]],
];
$mapCount = 0;
foreach ($mapSpec as $role => $rows) {
    foreach ($rows as [$code, $req, $mand]) {
        reg('jobrole_competency_map', DB::table('jobrole_competency_map')->insertGetId([
            'sub_institute_id'     => TENANT,
            'jobrole_id'           => $roleIds[$role],
            'competency_id'        => $compIds[$code],
            'required_proficiency' => $req,
            'is_mandatory'         => $mand,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]));
        $mapCount++;
    }
    say(sprintf('   %-42s %d competencies', $role, count($rows)));
}
say("   -> $mapCount requirement rows");

// ── 6. PEOPLE ───────────────────────────────────────────────────────────────
say("\n6. EMPLOYEES + LOGINS (new users - all 108 existing tenant-3 users already hold a job role)");

$profiles = [
    'administrator'     => 7,
    'hr_manager'        => 8,
    'employee'          => 9,
    'reporting_manager' => 51,
    'department_head'   => 52,
    'hr_executive'      => 53,
    'executive'         => 54,
    'auditor'           => 55,
    'recruiter'         => 56,
];

/** login => [first, last, role_key, jobrole|null, department|null] */
$people = [
    ['Aarti',   'Deshmukh', 'administrator',     null,                                      null],
    ['Nikhil',  'Rao',      'hr_manager',        null,                                      'Clinical Nursing'],
    ['Sunita',  'Menon',    'hr_executive',      null,                                      'Clinical Nursing'],
    ['Rajesh',  'Iyer',     'department_head',   'Nurse Unit Manager',                      'Clinical Nursing'],
    ['Farida',  'Khan',     'reporting_manager', 'Senior Staff Nurse (Medical Ward)',       'Clinical Nursing'],
    ['Vikram',  'Sethi',    'employee',          'Staff Nurse (Medical Ward)',              'Clinical Nursing'],
    ['Meera',   'Pillai',   'employee',          'Staff Nurse (Medical Ward)',              'Clinical Nursing'],
    ['Joseph',  'Mathew',   'employee',          'Physiotherapist (Inpatient)',             'Allied Health & Therapy'],
    ['Anjali',  'Bose',     'employee',          'Occupational Therapist (Rehabilitation)', 'Allied Health & Therapy'],
    ['Imran',   'Sheikh',   'employee',          'Patient Care Coordinator',                'Patient Services & Care Coordination'],
    ['Divya',   'Nair',     'employee',          'Ward Administration Officer',             'Patient Services & Care Coordination'],
    ['Kabir',   'Chandra',  'recruiter',         null,                                      null],
    ['Leela',   'Varma',    'executive',         null,                                      null],
    ['George',  'Thomas',   'auditor',           null,                                      null],
];

$userIds = []; $logins = [];
foreach ($people as [$first, $last, $roleKey, $jobrole, $dept]) {
    $login = strtolower($first . '.' . $last) . '@healthcare.g2g';
    $uid = reg('tbluser', DB::table('tbluser')->insertGetId([
        'user_name'          => $login,
        'first_name'         => $first,
        'last_name'          => $last,
        'email'              => $login,
        'password'           => Hash::make(PASSWORD),
        'user_profile_id'    => $profiles[$roleKey],
        'sub_institute_id'   => TENANT,
        'status'             => 1,
        'allocated_standards' => $jobrole ? $roleIds[$jobrole] : null,
        'department_id'      => $dept ? $deptIds[$dept] : null,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]));
    $userIds[$first . ' ' . $last] = $uid;
    $logins[] = [$roleKey, $login, $uid, $jobrole, $dept];
    say(sprintf('   #%-6d %-22s %-18s %s', $uid, $first . ' ' . $last, $roleKey, $jobrole ?: '-'));
}

// ── 2b. DEPARTMENT HEADS (head_user_id has never been used) ─────────────────
say("\n2b. head_user_id - never populated before this");
$heads = [
    'Clinical Nursing'                     => 'Rajesh Iyer',
    'Allied Health & Therapy'              => 'Joseph Mathew',
    'Patient Services & Care Coordination' => 'Imran Sheikh',
];
foreach ($heads as $dept => $person) {
    DB::table('hrms_departments')->where('id', $deptIds[$dept])->update(['head_user_id' => $userIds[$person]]);
    say(sprintf('   %-40s head = %s (#%d)', $dept, $person, $userIds[$person]));
}

// ── 7. REPORTING LINES ──────────────────────────────────────────────────────
say("\n7. REPORTING LINES - every write through ReportingLineValidator::canAssign()");
$lines = [
    'Vikram Sethi'  => 'Farida Khan',
    'Meera Pillai'  => 'Farida Khan',
    'Farida Khan'   => 'Rajesh Iyer',
    'Joseph Mathew' => 'Rajesh Iyer',
    'Anjali Bose'   => 'Joseph Mathew',
    'Imran Sheikh'  => 'Rajesh Iyer',
    'Divya Nair'    => 'Imran Sheikh',
    'Sunita Menon'  => 'Nikhil Rao',
];
$assigned = 0; $refused = 0;
foreach ($lines as $person => $manager) {
    $verdict = $validator->canAssign($userIds[$person], $userIds[$manager]);
    if (!$verdict['ok']) {
        say(sprintf('   REFUSED %-18s -> %-18s %s', $person, $manager, $verdict['reason']));
        $refused++;
        continue;
    }
    DB::table('tbluser')->where('id', $userIds[$person])->update(['reporting_manager_id' => $userIds[$manager]]);
    $assigned++;
    say(sprintf('   %-18s -> %-18s ok', $person, $manager));
}

// The validator is not decoration: prove it refuses a cycle rather than trusting it.
$cycle = $validator->canAssign($userIds['Rajesh Iyer'], $userIds['Vikram Sethi']);
say(sprintf('   CYCLE TEST  Rajesh Iyer -> Vikram Sethi : %s',
    $cycle['ok'] ? 'ACCEPTED - THE VALIDATOR IS NOT WORKING' : 'refused ("' . $cycle['reason'] . '")'));

// ── 8. RATINGS - some measured, some deliberately not ───────────────────────
say("\n8. RATINGS - deliberately incomplete, so \"Not yet assessed\" appears on screen");

$assessor = $userIds['Farida Khan'];
$ratingPlan = [
    // person => [competency code => rating for EVERY item, or 'PARTIAL'/'NONE']
    'Vikram Sethi' => ['HC-CLIN-01' => 3, 'HC-MED-01' => 2, 'HC-IPC-01' => 'PARTIAL', 'HC-COMM-01' => 'NONE'],
    'Meera Pillai' => ['HC-CLIN-01' => 4, 'HC-MED-01' => 4, 'HC-IPC-01' => 4,         'HC-COMM-01' => 3],
    'Joseph Mathew' => ['HC-REHAB-01' => 3, 'HC-MAN-01' => 'PARTIAL', 'HC-COMM-01' => 'NONE'],
    'Anjali Bose'  => ['HC-REHAB-01' => 2, 'HC-CARE-01' => 'NONE', 'HC-COMM-01' => 'NONE'],
    'Imran Sheikh' => ['HC-CARE-01' => 4, 'HC-COMM-01' => 4, 'HC-DOC-01' => 3],
    // Divya Nair: NOTHING AT ALL. A person with a role, requirements and zero
    // measurements - the "no data yet" state, which is different from a gap.
];
$rated = 0; $left = 0;
foreach ($ratingPlan as $person => $plan) {
    foreach ($plan as $code => $value) {
        $items = $itemIds[$code];
        if ($value === 'NONE') { $left += count($items); continue; }
        $take = $value === 'PARTIAL' ? 1 : count($items);
        foreach (array_slice($items, 0, $take) as $i => $item) {
            reg('competency_kasba_rating', DB::table('competency_kasba_rating')->insertGetId([
                'sub_institute_id' => TENANT,
                'user_id'          => $userIds[$person],
                'kasba_item_id'    => $item['id'],
                'rating'           => $value === 'PARTIAL' ? 2 : (int) $value,
                'assessor_id'      => $assessor,
                'source'           => 'manual',
                'note'             => 'Seeded capability slice ' . STAMP,
                'rated_at'         => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]));
            $rated++;
        }
        $left += count($items) - $take;
    }
}
say(sprintf('   %d items rated, %d left UNMEASURED on purpose', $rated, $left));
say('   Divya Nair has a job role and requirements and NO ratings at all.');

// ── 9. COURSES -> COMPETENCIES ──────────────────────────────────────────────
say("\n9. course_competency_map - so a gap resolves to something");
$courses = DB::table('sub_std_map')->where('sub_institute_id', TENANT)->whereNull('deleted_at')
    ->orderBy('id')->limit(12)->get(['id', 'display_name']);

$courseMapSpec = [
    'HC-IPC-01', 'HC-MED-01', 'HC-COMM-01', 'HC-CLIN-01',
    'HC-REHAB-01', 'HC-CARE-01', 'HC-HAND-01', 'HC-DOC-01',
];
$mapped = 0;
foreach ($courses as $i => $course) {
    if (!isset($courseMapSpec[$i])) break;
    reg('course_competency_map', DB::table('course_competency_map')->insertGetId([
        'sub_institute_id'  => TENANT,
        'course_id'         => (int) $course->id,
        'competency_id'     => $compIds[$courseMapSpec[$i]],
        'proficiency_level' => 3,
        'is_primary'        => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]));
    $mapped++;
    say(sprintf('   course %-5d %-44s -> %s', $course->id,
        mb_substr((string) $course->display_name, 0, 43), $courseMapSpec[$i]));
}
say("   -> $mapped course->competency rows");

// ── REGISTER ────────────────────────────────────────────────────────────────
$md = "# SEED REGISTER — tenant 3 (healthcare), " . STAMP . "\n\n";
$md .= "Every row created by `docs/phase3/_evidence/seed-healthcare.php`.\n";
$md .= "**Recorded so the slice is identifiable and removable. Nothing existing was\n";
$md .= "touched: all 108 pre-existing tenant-3 users already held a job role, so every\n";
$md .= "person here is new.**\n\n";
$md .= "| Table | Rows | IDs |\n|---|---:|---|\n";
$total = 0;
foreach ($reg as $table => $ids) {
    $total += count($ids);
    $md .= sprintf("| `%s` | %d | %s |\n", $table, count($ids), implode(', ', $ids));
}
$md .= "\n**Total rows created: $total**\n\n";
$md .= "## Updates to rows created by this same script (not to pre-existing rows)\n\n";
$md .= "- `hrms_departments.head_user_id` set on the 3 departments above.\n";
$md .= "- `tbluser.reporting_manager_id` set on $assigned of the new users.\n\n";
$md .= "## Logins\n\n| Role | Email | Password |\n|---|---|---|\n";
foreach ($logins as [$rk, $login, $uid, $jr, $dept]) {
    $md .= sprintf("| `%s` | %s | `%s` |\n", $rk, $login, PASSWORD);
}
$md .= "\n## Removal\n\nDelete in this order (children first):\n";
$md .= "`competency_kasba_rating` -> `competency_kasba_item` -> `jobrole_competency_map`\n";
$md .= "-> `course_competency_map` -> `competency` -> `tbluser` -> `s_user_jobrole`\n";
$md .= "-> `hrms_departments`, using the ids above.\n";

file_put_contents(__DIR__ . '/../_changes/SEED-REGISTER-' . STAMP . '.md', $md);
say("\nregister written: docs/phase3/_changes/SEED-REGISTER-" . STAMP . '.md');
say(sprintf('TOTAL ROWS CREATED: %d', $total));

// ── COVERAGE ────────────────────────────────────────────────────────────────
say("\n================ COVERAGE AFTER ================");
$usersT = DB::table('tbluser')->where('sub_institute_id', TENANT)->count();
$withMgr = DB::table('tbluser')->where('sub_institute_id', TENANT)
    ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '!=', 0)->count();
$allMgr = DB::table('tbluser')->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '!=', 0)->count();
$allUsers = DB::table('tbluser')->count();

say(sprintf('reporting-line coverage  tenant %d : %d of %d (%.1f%%)', TENANT, $withMgr, $usersT, 100 * $withMgr / max($usersT, 1)));
say(sprintf('reporting-line coverage  platform : %d of %d (%.1f%%)  - was 0 of 387', $allMgr, $allUsers, 100 * $allMgr / max($allUsers, 1)));
say(sprintf('jobrole_competency_map            : %d rows  - was 0', DB::table('jobrole_competency_map')->count()));
say(sprintf('competency                        : %d rows  - was 0', DB::table('competency')->count()));
say(sprintf('competency_kasba_item             : %d rows  - was 0', DB::table('competency_kasba_item')->count()));
say(sprintf('competency_kasba_rating           : %d rows  - was 0', DB::table('competency_kasba_rating')->count()));
say(sprintf('course_competency_map             : %d rows  - was 0', DB::table('course_competency_map')->count()));
say(sprintf('hrms_departments.head_user_id set : %d      - was 0', DB::table('hrms_departments')->whereNotNull('head_user_id')->count()));

$rolesLive = DB::table('tbluser as u')->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
    ->where('u.sub_institute_id', TENANT)->whereNotNull('p.role_key')
    ->distinct()->count('p.role_key');
say(sprintf('role_keys with a live login in t%d : %d of 9', TENANT, $rolesLive));

echo "\n";
