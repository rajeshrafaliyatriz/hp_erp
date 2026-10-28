#!/usr/bin/env python3
"""
KNOWN-BAD THE DETECTORS.

    python Docs/hrit-audit/_evidence/knownbad-attendance-admin.py

A passing probe proves nothing until each assertion has been shown to FAIL when
the thing it checks is broken. This breaks one guard at a time, runs the probe,
and reports which assertions noticed.

This exists because of a specific miss. The employee-documents probe asserted
"an outsider cannot delete this document" and passed - with the controller's
tenant check DELETED. The route gate `profile:admin,hr` stopped the plain
employee the assertion used, so the controller never ran and the missing check
was invisible. The assertion was green and vacuous for its entire life.

Each mutation below names the assertion that MUST go red. A mutation that
changes nothing is reported as a hole in the probe, not as a pass.

Restores every file on the way out, including on Ctrl-C.
"""

import io
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))

CTRL = os.path.join(ROOT, 'app', 'Http', 'Controllers', 'Api', 'Attendance',
                    'AttendanceAdminController.php')
CORR = os.path.join(ROOT, 'app', 'Services', 'Attendance', 'AttendanceCorrector.php')
PROBE = os.path.join(ROOT, 'Docs', 'hrit-audit', '_evidence', 'probe-attendance-admin.sh')

# The frontend lives in the sibling repo. The menu id and the accessLink have to
# agree across a database row, a migration and a TSX map, and nothing but this
# checks that: tsc cannot see the database, the migration cannot see the TSX,
# and the symptom is a sidebar entry that opens a blank page.
MAP = os.path.join(ROOT, '..', 'g2gv0', 'hooks', 'content-map-m5.ts')

# The self-service punch endpoints. Added in phase 19, because a corrected day
# used to be destroyed by one click on the employee's own screen.
TRACK = os.path.join(ROOT, 'app', 'Http', 'Controllers', 'Api', 'Attendance',
                     'AttendanceTrackingApiController.php')

# ---------------------------------------------------------------------------
# Each mutation: a name, the file, a (find, replace) pair, and the substring of
# the assertion label that must appear among the failures.
# ---------------------------------------------------------------------------
# ───────────────────────────────────────────────────────────────────────────
# Crash-safe mutation, because a killed process does not run `finally`.
#
# I killed this harness mid-mutation once and the mutated controller stayed on
# disk - a line writing '18:00:00' onto every employee in the department, live
# in the working tree. The grep I used to confirm the restore checked three
# markers I happened to remember and missed the one that mattered.
#
# So: the original goes to a .orig sidecar on DISK before anything is mutated,
# and is removed only after a byte-for-byte verified restore. A sidecar found at
# startup means the previous run died; it is restored from and reported, rather
# than being left for a probe to trip over.
# ───────────────────────────────────────────────────────────────────────────

def sidecar(path):
    return path + '.knownbad-orig'


def recover(path):
    """Restore from a sidecar left by a killed run. Returns True if it acted."""
    side = sidecar(path)
    if not os.path.exists(side):
        return False
    original = io.open(side, encoding='utf-8').read()
    if io.open(path, encoding='utf-8').read() != original:
        io.open(path, 'w', encoding='utf-8', newline='\n').write(original)
        print('  RECOVERED %s from a sidecar - a previous run was killed mid-mutation'
              % os.path.basename(path), flush=True)
    os.remove(side)
    return True


def arm(path, text):
    """Write the sidecar before the first mutation."""
    io.open(sidecar(path), 'w', encoding='utf-8', newline='\n').write(text)


def restore_verified(path, text):
    """Restore and prove it, rather than assuming the write landed."""
    io.open(path, 'w', encoding='utf-8', newline='\n').write(text)
    back = io.open(path, encoding='utf-8').read()
    if back != text:
        raise RuntimeError('restore of %s did not verify - DO NOT COMMIT, the file is mutated'
                           % path)


def disarm(path):
    side = sidecar(path)
    if os.path.exists(side):
        os.remove(side)

MUTATIONS = [
    (
        'the controller tenant check is deleted',
        CTRL,
        ("        if (!$inTenant) {", "        if (false) {"),
        'HR of ANOTHER tenant is refused',
    ),
    (
        'the role gate is deleted (controller side)',
        CTRL,
        ("        if (!RoleKey::satisfies(RoleKey::forUserId($actorId), self::ALLOWED)) {\n"
         "            return response()->json([\n"
         "                'status'  => 0,\n"
         "                'message' => 'You may not change another employee\\'s attendance.',\n"
         "            ], 403);\n"
         "        }",
         "        // mutated"),
        # EXPECTED TO BE NOTICED BY NOTHING, and that is not a hole.
        #
        # RequireProfile resolves the caller with RoleKey::forUser() and tests
        # it with RoleKey::satisfies() - the same two calls the controller
        # makes. So the controller's role check is a true duplicate of the
        # route gate, and no token can make the two disagree; there is no
        # outside test that distinguishes them.
        #
        # It stays anyway. `updateUserAttendance` is the cautionary case in this
        # very module: role-gated, and with no caller, so the gate was the only
        # thing anyone had checked while the method itself reached across
        # tenants. If this controller is ever mounted on a route without the
        # gate, its own check is what is left.
        None,
    ),
    (
        'the idempotency key goes back to {employee}:{day}',
        CTRL,
        ("'attendance.corrected:admin:edit:' . $correction['edit_id']",
         "'attendance.corrected:admin:' . $subjectId . ':' . $data['day']"),
        'not one deduplicated away',
    ),
    (
        'the edit trail is never written',
        CTRL,
        ("            $applied['edit_id'] = DB::table('hrms_attendance_edits')->insertGetId($editRow);",
         "            $applied['edit_id'] = 0;"),
        'one edit row exists',
    ),
    (
        'the before-image is not captured',
        CTRL,
        ("                'before_in_time'   => $applied['before']['punchin_time'] ?? null,",
         "                'before_in_time'   => null,"),
        'the newest records the OLD time as its before',
    ),
    (
        'the future-day guard is removed',
        CTRL,
        ("        if (Carbon::parse($data['day'])->isAfter(Carbon::today())) {",
         "        if (false) {"),
        'a day that has not happened yet',
    ),
    (
        'a reason is no longer required',
        CTRL,
        ("            'reason'   => 'required|string|max:255',",
         "            'reason'   => 'nullable|string|max:255',"),
        'no reason given',
    ),
    (
        'the both-times-empty guard is removed',
        CTRL,
        ("        if (empty($data['in_time'] ?? null) && empty($data['out_time'] ?? null)) {",
         "        if (false) {"),
        'neither time given',
    ),
    (
        'the edits list is not tenant-scoped',
        CTRL,
        ("            ->where('e.sub_institute_id', $tenantId);",
         "            ->whereNotNull('e.sub_institute_id');"),
        "another tenant's HR sees none of it",
    ),
    (
        'the edits list ignores the month filter',
        CTRL,
        ("        if ($request->filled('month')) {", "        if (false) {"),
        'a different month excludes it',
    ),
    (
        'timestamp_diff is not recomputed',
        CORR,
        ("            'timestamp_diff' => $this->duration($punchIn, $punchOut),",
         "            'timestamp_diff' => null,"),
        'timestamp_diff was recomputed',
    ),
    (
        'the datetime is left in the shape the caller sent',
        CORR,
        ("        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;",
         "        return $value ?: null;"),
        'the after-image holds what was written',
    ),
    (
        'an omitted time overwrites the existing one with null',
        CORR,
        ("                                                          : ($existing->punchout_time ?? null));",
         "                                                          : null);"),
        'the untouched punch-out survived a one-sided edit',
    ),
    (
        'the corrector ignores the tenant when finding the day',
        CORR,
        ("            ->where('sub_institute_id', $row->sub_institute_id)\n"
         "            ->whereNull('deleted_at')\n"
         "            ->whereDate('day', $day)",
         "            ->whereNull('deleted_at')\n"
         "            ->whereDate('day', $day)"),
        # Also expected to be noticed by nothing HERE, for a narrower reason.
        #
        # tbluser.id is the primary key, so an employee belongs to exactly one
        # organisation and `user_id = X and day = Y` can only return that
        # employee's row - the tenant predicate adds nothing for a subject the
        # controller has already confirmed is in the caller's tenant.
        #
        # It is NOT redundant in general, and this is the gap: an attendance row
        # can carry a sub_institute_id the employee no longer has, if they were
        # moved between organisations. Without the predicate, HR of the new
        # organisation would edit a row stamped with the old one. Constructing
        # that needs a moved employee, which this probe does not build -
        # recorded here as untested rather than passed off as covered.
        None,
    ),
    (
        'the grid is not scoped to the caller\'s organisation',
        CTRL,
        ("            ->where('u.sub_institute_id', $tenantId)",
         "            ->whereNotNull('u.sub_institute_id')"),
        "the two tenants' grids share no employee",
    ),
    (
        'the grid trusts status as a string (the tbluser.status trap)',
        CTRL,
        ("            ->where('u.status', 1)", "            ->where('u.status', 'active')"),
        # tbluser.status is 1/0, never 'active'. MySQL coerces the string to 0,
        # so this does not error - it silently returns the DISABLED users and
        # hides every active one. Named here because the inversion is invisible
        # unless something counts the rows.
        'and both returned somebody',
    ),
    (
        'a future day falls through to absent',
        CTRL,
        ("                } elseif ($day['is_future']) {\n"
         "                    $status = 'upcoming';",
         "                } elseif (false) {\n"
         "                    $status = 'upcoming';"),
        "a future day is 'upcoming', never 'absent'",
    ),
    (
        'an employee with no roster is called absent or weekend',
        CTRL,
        ("                } elseif (!$hasRoster) {\n"
         "                    $status = 'unset';",
         "                } elseif (false) {\n"
         "                    $status = 'unset';"),
        "an employee with no roster reads 'unset'",
    ),
    (
        'the no-roster banner counts the wrong thing',
        CTRL,
        ("                    'without_roster' => collect($employeesOut)->where('has_roster', false)->count(),",
         "                    'without_roster' => 0,"),
        'the banner count matches the rows',
    ),
    (
        'the edited marker is never set',
        CTRL,
        ("                    'edited'    => isset($edited[$key]),",
         "                    'edited'    => false,"),
        'and is flagged as changed by HR',
    ),
    (
        'the frontend map points at the obvious-but-wrong menu id 312',
        MAP,
        ("submenuId: '433', component: ManageEmployeeAttendancePage",
         "submenuId: '312', component: ManageEmployeeAttendancePage"),
        'the frontend map carries submenu 433',
    ),
    (
        'the frontend map and the menu row disagree about the link',
        MAP,
        ("accessLink: '/module/hrit-solutions/attendance-management/manage-employee-attendance'",
         "accessLink: '/module/hrit-solutions/attendance-management/manage-attendance'"),
        'on that exact accessLink',
    ),
    (
        # THE ONE THAT MATTERS MOST IN THIS PHASE.
        #
        # Without the guard, one click on the employee's own "Punch In" sets
        # punchin_time to the current clock and NULLS punchout_time and
        # timestamp_diff - destroying HR's correction and the duration payroll
        # reads, while hrms_attendance_edits still says the correction applied.
        'the punch-in guard is removed, so a closed day is overwritten',
        TRACK,
        ("            if ($refusal = $this->closedDayRefusal($record, $formattedDate)) {\n"
         "                return $refusal;\n"
         "            }",
         "            // mutated"),
        'THE CORRECTION SURVIVED',
    ),
    (
        'the punch-out guard is removed on the supplied-time path',
        TRACK,
        ("            if ($refusal = $this->closedDayRefusal($attendance, $dateOnly)) {\n"
         "                return $refusal;\n"
         "            }",
         "            // mutated"),
        # Section 9's "and the 18:30 is still 18:30" also catches this, but on
        # the 1999 fixture - where a supplied out-time IS in range, so that one
        # is a genuine catch rather than the accident described below. Pointed
        # at 9b anyway, so both punch-out expectations rest on the real-day
        # fixture and neither can be protected by the TIME overflow.
        'and 18:30 is still 18:30',
    ),
    (
        # The worse of the two punch-out paths: no open row is found on a closed
        # day, and the else branch replaced the punch-out with Carbon::now().
        #
        # POINTED AT SECTION 9b, NOT SECTION 9, AND THAT IS THE WHOLE POINT.
        #
        # This was first expected to turn "the correction survived that too"
        # red, in section 9. It did not, and the reason is worth keeping: that
        # section's fixture is the 1999 scratch day, and from a 1999 punch-in
        # the un-guarded code computes durationBetween(punchin, now) = about
        # 243,190 hours, which is outside MySQL's TIME range (838:59:59). The
        # UPDATE is rejected with SQLSTATE 22007 and the request 500s BEFORE the
        # destructive write lands - so the row survived for a reason that had
        # nothing to do with the guard.
        #
        # Measured, not reasoned: the log line is
        #   Incorrect time value: '243190:11' for column
        #   hp_erp.hrms_attendances.timestamp_diff
        #
        # Section 9b exists because of this. It runs the same case on a RECENT
        # day, proven empty first, where the duration is valid and the write
        # would succeed - which is the only place in the probe that can tell the
        # guard apart from the out-of-range accident.
        #
        # VERIFIED BY HAND 2026-10-09 with the guard removed:
        #   FAIL  THE CORRECTED OUT-TIME SURVIVED
        #         - expected [2026-10-06 18:30:00] got [2026-10-09 ...]
        'the punch-out guard is removed on the no-time path',
        TRACK,
        ("            if ($refusal = $this->closedDayRefusal($existingRecord, $dateOnly)) {\n"
         "                return $refusal;\n"
         "            }",
         "            // mutated"),
        'THE CORRECTED OUT-TIME SURVIVED',
    ),
    (
        # OVER-REFUSAL, the opposite failure. Dropping the punchout_time
        # condition refuses any day with a punch-in - which would break
        # re-punching an open day, the legitimate "wrong device" case these
        # endpoints exist for. A guard that is too wide is still a broken guard.
        'the guard refuses an OPEN day too, not just a closed one',
        TRACK,
        ("        if (!$row || !$row->punchin_time || !$row->punchout_time) {",
         "        if (!$row || !$row->punchin_time) {"),
        'the employee re-punches it',
    ),
    (
        'the corrector stops forcing status to 1',
        CORR,
        ("            'status'         => 1,\n", ""),
        "status is now 1, so the employee's own screen can see it",
    ),
    (
        'the corrector stops recording the prior status',
        CORR,
        ("                'status'         => (int) $existing->status,\n", ""),
        "the prior 0 is in the event's before-image",
    ),
    (
        'readSubject drops its role check, so any employee can read a colleague',
        TRACK,
        ("        if (!RoleKey::satisfies(RoleKey::forUserId($callerId), ['admin', 'hr'])) {\n"
         "            return response()->json([\n"
         "                'status'  => 0,\n"
         "                'message' => 'You may only view your own attendance.',\n"
         "            ], 403);\n"
         "        }",
         "        // mutated"),
        'an employee naming a colleague is refused',
    ),
    (
        'readSubject drops its tenant check, so HR reads across organisations',
        TRACK,
        ("        if (!$inTenant) {\n"
         "            return response()->json([\n"
         "                'status'  => 0,\n"
         "                'message' => 'That employee is not in your organisation.',\n"
         "            ], 404);\n"
         "        }\n"
         "\n"
         "        return $subjectId;",
         "        return $subjectId;"),
        'HR of ANOTHER tenant gets 404, not 403',
    ),
    (
        # The original bug: the subject came from the token and the parameter was
        # silently discarded, so the screen answered about the wrong person.
        'myAttendance goes back to resolving its subject from the token only',
        TRACK,
        ("        $userId = $this->readSubject($request, $context);",
         "        $userId = $context['user_id'];"),
        'and answers about them, not about the caller',
    ),
    (
        # THE FENCE.
        #
        # punchSubject() refuses a mismatched employee and is the only thing
        # stopping an HR role writing a punch as somebody else (G-ATT-SEC-01).
        # readSubject() permits exactly what it refuses, and the two now sit
        # side by side - so the realistic future mistake is somebody "unifying"
        # them. This mutation is that mistake, and it must be caught.
        'punchIn resolves its subject the way a READ does',
        TRACK,
        ("        $employeeId = $this->punchSubject($request, $context);\n"
         "        if (!is_int($employeeId)) {\n"
         "            return $employeeId;\n"
         "        }\n"
         "\n"
         "        $subInstituteId = $context['sub_institute_id'];\n"
         "        $formattedDate = Carbon::parse($request->input('indate'))->format('Y-m-d');",
         "        $employeeId = $this->readSubject($request, $context);\n"
         "        if (!is_int($employeeId)) {\n"
         "            return $employeeId;\n"
         "        }\n"
         "\n"
         "        $subInstituteId = $context['sub_institute_id'];\n"
         "        $formattedDate = Carbon::parse($request->input('indate'))->format('Y-m-d');"),
        'an admin punching IN for another employee is still refused',
    ),
]

ORIGINALS = {}


def save(path):
    if path not in ORIGINALS:
        # A sidecar means the previous run was killed mid-mutation. Restore from
        # it BEFORE reading, or the "original" cached here is itself a mutated
        # file and every later restore writes the bug back in.
        recover(path)
        text = io.open(path, encoding='utf-8').read()
        ORIGINALS[path] = text
        arm(path, text)
    return ORIGINALS[path]


def restore_all():
    for path, text in ORIGINALS.items():
        restore_verified(path, text)
        disarm(path)


def run_probe():
    """Returns (passed, failed, [failing assertion labels])."""
    out = subprocess.run(
        ['bash', 'Docs/hrit-audit/_evidence/probe-attendance-admin.sh'],
        cwd=ROOT, capture_output=True, text=True, timeout=1200,
    ).stdout
    fails = [m.strip() for m in re.findall(r'^\s*FAIL\s+(.*?)\s+- expected', out, re.M)]
    tally = re.search(r'(\d+) passed, (\d+) failed', out)
    return (int(tally.group(1)), int(tally.group(2)), fails) if tally else (0, -1, fails)


def main():
    print()
    print('=========== Known-bad: attendance admin corrections ===========')
    print()

    base_pass, base_fail, _ = run_probe()
    print('  baseline: %d passed, %d failed' % (base_pass, base_fail))
    if base_fail != 0:
        print('  STOP - the probe is not green to begin with. Fix that first.')
        return 1
    print()

    holes = []

    for name, path, (find, repl), must_fail in MUTATIONS:
        text = save(path)
        if find not in text:
            print('  SKIP  %s' % name)
            print('        anchor not found - the code moved; this mutation is stale')
            holes.append(name + ' (stale anchor)')
            continue

        io.open(path, 'w', encoding='utf-8', newline='\n').write(text.replace(find, repl, 1))
        try:
            passed, failed, fails = run_probe()
        finally:
            restore_verified(path, text)

        if must_fail is None:
            # Not a detector claim - just report what happened, so a mutation
            # that SHOULD be caught by something else is visible rather than
            # quietly assumed.
            print('  NOTE  %s' % name)
            print('        -> %d failed%s' % (failed, (': ' + '; '.join(fails[:3])) if fails else ' (nothing noticed)'))
            continue

        caught = [f for f in fails if must_fail in f]
        if caught:
            print('  GOOD  %s' % name)
            print('        -> caught by "%s" (%d assertion(s) red)' % (caught[0], failed))
        else:
            print('  HOLE  %s' % name)
            print('        -> expected "%s" to go red; %d failed%s'
                  % (must_fail, failed, (': ' + '; '.join(fails[:3])) if fails else ' - NOTHING noticed'))
            holes.append(name)

    print()
    after_pass, after_fail, _ = run_probe()
    print('  restored: %d passed, %d failed' % (after_pass, after_fail))
    if after_fail == 0:
        for path in ORIGINALS:
            disarm(path)     # only once the restore is PROVEN by a green probe
    else:
        print('  sidecars KEPT - the probe is not green, so the restore is not'
              ' trusted. Re-run and it will restore from them.')
    print()

    if holes:
        print('  %d HOLE(S) - these assertions do not test what they claim:' % len(holes))
        for h in holes:
            print('    - %s' % h)
        return 1

    print('  every detector was shown to fail when its guard was broken.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print('\n  interrupted - restoring files')
        restore_all()
        sys.exit(130)
    except Exception:
        restore_all()
        raise
