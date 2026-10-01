<?php

/**
 * There must be exactly ONE place that spells out an elevated-role list.
 *
 * S11 existed because a list was copied. The audit counted five copies, no two
 * the same: one included reporting_manager, one included department_head, two
 * dropped the read-only oversight roles. Running this check found FOUR MORE the
 * audit never counted, in helpers, task documents, employee documents and task
 * instructions - all four byte-identical to RECORD_OWNERS, so consolidating
 * them was provable rather than a judgement.
 *
 * Consolidating fixed the past. This stops the tenth.
 *
 *   php Docs/talent-audit/_evidence/check-one-role-list.php
 */

$root = dirname(__DIR__, 3);

/**
 * Files allowed to name roles.
 *
 * SubjectAuthority holds the tiers. RoleKey is the VOCABULARY itself - the
 * role_key list and the route-argument aliases - so it must spell them out;
 * that is its whole job.
 */
const ALLOWED = [
    'app/Support/SubjectAuthority.php',
    'app/Support/RoleKey.php',
];

/**
 * A role list that makes a DECISION - not one that describes an audience.
 *
 * Two refinements, both learned by running this:
 *
 * 1. Three or more role_keys. `['administrator']` and two-role pairs appear all
 *    over as "admin only" checks and are not tiers; flagging them is noise, and
 *    a check that cries wolf gets deleted.
 *
 * 2. It must be the argument to in_array() or the value of a const. That is
 *    what separates an authorization tier from an audience list.
 *    NextStepsService has five role arrays of the form
 *    `'roles' => ['employee', 'reporting_manager', ...]`, and they say WHICH
 *    ROLES SEE A SUGGESTION CARD, not who may act on a record. Folding those
 *    into SubjectAuthority would conflate being shown something with being
 *    allowed to do it - which is the precise confusion that produced the bugs
 *    this whole pass is about.
 */
function rolesPattern(): string
{
    $role = "'(?:administrator|hr_manager|hr_executive|executive|auditor|reporting_manager|department_head|recruiter|employee)'";
    $list = '\[\s*' . $role . '\s*,\s*' . $role . '\s*,\s*' . $role . '[^\]]*\]';

    return '/(?:in_array\s*\([^;]*?' . $list . '|const\s+\w+\s*=\s*' . $list . ')/s';
}

/**
 * The source with comments removed.
 *
 * Needed because PayrollController explains a bug in prose that quotes two
 * role_keys, and flagging a comment for describing the problem would be
 * exactly the false positive that trains people to ignore the report.
 */
function withoutComments(string $php): string
{
    $stripped = '';

    foreach (token_get_all($php) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

$offenders = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace(chr(92), '/', substr($file->getPathname(), strlen($root) + 1));

    if (in_array($relative, ALLOWED, true)) {
        continue;
    }

    $scanned++;
    $body = (string) file_get_contents($file->getPathname());

    if (preg_match_all(rolesPattern(), withoutComments($body), $hits)) {
        $offenders[$relative] = array_map(
            static fn ($hit) => preg_replace('/\s+/', ' ', $hit) . ' ...',
            $hits[0]
        );
    }
}

printf('scanned %d php files under app/%s', $scanned, PHP_EOL);
printf('allowed to name roles: %s%s%s', implode(', ', ALLOWED), PHP_EOL, PHP_EOL);

if ($offenders === []) {
    echo 'PASS - only SubjectAuthority and RoleKey spell out a role list.' . PHP_EOL;
    exit(0);
}

echo 'FAIL - these files spell out a role list of their own:' . PHP_EOL;

foreach ($offenders as $file => $hits) {
    printf('  %s%s', $file, PHP_EOL);

    foreach ($hits as $hit) {
        printf('      %s%s', $hit, PHP_EOL);
    }
}

echo PHP_EOL;
echo 'Name a tier on App\Support\SubjectAuthority instead. A second copy of an' . PHP_EOL;
echo 'authorization table is how the first stops being the only one.' . PHP_EOL;

exit(1);
