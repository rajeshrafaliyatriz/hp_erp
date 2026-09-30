<?php

/**
 * VERIFICATION: nobody who should keep Talent access loses it.
 *
 * The new `subject:` gates refuse an unresolvable profile by design, and role
 * resolution leans on a three-entry legacy-name table: 30 of 42 live profiles
 * have role_key = NULL and pass only because RoleKey::LEGACY_NAMES maps the
 * lowercased names 'admin' and 'hr'. A tenant that renames its HR profile to
 * "People Ops" resolves to null, and null grants nothing.
 *
 * So this is not a nice-to-have check. It is the one that would have caught the
 * menu-225 incident, and it runs against BOTH hosts.
 *
 *   php Docs/talent-audit/_evidence/check-nobody-locked-out.php
 */

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\RoleKey;
use App\Support\SubjectAuthority;
use Illuminate\Support\Facades\DB;

/** Roles that are SUPPOSED to lose reach - that is the point of the change. */
const EXPECTED_TO_LOSE = ['employee', 'recruiter'];

/**
 * Words in a profile NAME that suggest it is a privileged one.
 *
 * Needed because a profile with role_key = NULL whose name is not in
 * LEGACY_NAMES resolves to nothing, and "nothing" is indistinguishable from
 * "employee" to the guards. So the resolved key cannot answer "should this one
 * have kept access" - only the name can hint at it.
 *
 * A hit here is not proof of a problem; it is the thing a human must look at.
 */
const PRIVILEGED_WORDS = ['admin', 'hr', 'manager', 'head', 'exec', 'audit', 'director', 'officer', 'lead'];

function looksPrivileged(string $name): bool
{
    $name = strtolower($name);

    foreach (PRIVILEGED_WORDS as $word) {
        if (str_contains($name, $word)) {
            return true;
        }
    }

    return false;
}

$problems = 0;

foreach (['mysql' => 'app', 'live' => 'live'] as $conn => $label) {
    echo "===== $label =====\n";

    $profiles = DB::connection($conn)->table('tbluserprofilemaster')
        ->whereNull('deleted_at')
        ->orderBy('sub_institute_id')->orderBy('id')
        ->get(['id', 'name', 'role_key', 'sub_institute_id']);

    $losing = [];

    foreach ($profiles as $p) {
        // Resolved exactly the way RequireProfile / RequireSubjectAuthority do.
        $key = trim((string) ($p->role_key ?? ''));
        $via = 'role_key';

        if ($key === '') {
            $key = RoleKey::LEGACY_NAMES[strtolower(trim((string) $p->name))] ?? '';
            $via = $key === '' ? 'NOTHING' : 'legacy-name';
        }

        $hr  = SubjectAuthority::roleSatisfies($key, SubjectAuthority::HR_ELEVATED);
        $mgr = SubjectAuthority::roleSatisfies($key, SubjectAuthority::PEOPLE_MANAGERS);

        if ($hr || $mgr) {
            continue;
        }

        // Does this profile hold Talent today, and does anybody use it?
        $holdsTalent = DB::connection($conn)->table('tblgroupwise_rights_g2g')
            ->where('profile_id', $p->id)->where('menu_id', 3)->where('can_view', 1)->exists();
        $users = DB::connection($conn)->table('tbluser')->where('user_profile_id', $p->id)->count();

        if (!$holdsTalent || $users === 0) {
            continue;
        }

        /*
         * Expected when the profile resolves to a role meant to lose reach - OR
         * when it resolves to nothing and its name gives no reason to think it
         * is privileged. An unresolvable profile named "Employee" is an
         * employee; an unresolvable one named "People Ops" is the case this
         * check exists to surface.
         */
        $expected = $key !== ''
            ? in_array($key, EXPECTED_TO_LOSE, true)
            : !looksPrivileged((string) $p->name);

        if (!$expected) {
            $problems++;
        }

        $losing[] = sprintf(
            '  %-9s tenant %-8s profile %-4s %-22s role=%-18s via=%-11s users=%-4s %s',
            $expected ? 'expected' : 'LOOK AT ME',
            $p->sub_institute_id, $p->id, $p->name,
            $key === '' ? 'UNRESOLVABLE' : $key, $via, $users,
            $expected ? '' : '<- privileged-looking name, resolves to nothing'
        );
    }

    printf("profiles that hold Talent, have users, and satisfy NEITHER tier: %d\n", count($losing));
    foreach ($losing as $line) {
        echo $line . "\n";
    }
    echo "\n";
}

printf("%s\n", $problems === 0
    ? 'PASS - every profile losing reach is an employee or recruiter, which is the intent.'
    : "FAIL - $problems profile(s) have a privileged-looking name and resolve to nothing." . PHP_EOL
      . 'Backfill their role_key, or add the name to RoleKey::LEGACY_NAMES, before shipping.');

exit($problems === 0 ? 0 : 1);
