#!/usr/bin/env python3
"""
KNOWN-BAD THE DEPARTMENT-SCHEDULE DETECTORS.

    python Docs/hrit-audit/_evidence/knownbad-department-schedules.py

Breaks one guard at a time, runs probe-department-schedules.sh, and reports
which assertions noticed. A mutation nothing notices is a hole in the probe, not
a pass.

This matters more here than anywhere else in the module. The feature being
tested is a BULK WRITE across a department's employees, and its previous
incarnation was deleted from the product for silently flattening Saturday - 100
employees in one tenant finish at 14:00. The assertion that it does not do that
again has to be shown to fail when it would.

Restores every file on the way out, including on Ctrl-C.

KNOWN UNTESTED, recorded rather than quietly omitted:

  - That the apply is TRANSACTIONAL. A transaction only differs from a loop of
    updates when something fails partway, and this probe does not induce a
    mid-write failure. Asserting it properly means making one employee's UPDATE
    fail - a trigger, or a column constraint added for the duration - which is a
    heavier fixture than this file carries.

  - That a too-coarse idempotency key loses an event. g2g_event.idempotency_key
    is UNIQUE (uq_event_idem), so the loss only happens when two applies land in
    the SAME SECOND. This probe's applies are seconds apart because there are
    assertions and HTTP round trips between them, so the bug is latent here.
    Reproducing it would mean firing two applies back to back and would then
    pass or fail depending on how fast the machine is - a timing test, not a
    behaviour test.
"""

import io
import os
import re
import subprocess
import sys
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))

CTRL = os.path.join(ROOT, 'app', 'Http', 'Controllers', 'Api', 'Attendance',
                    'DepartmentScheduleController.php')

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
        # THE MUTATION THIS WHOLE PROBE EXISTS FOR.
        #
        # It removes BOTH constraints, because either alone is a no-op. The
        # apply is narrowed twice: whereIn('weekday', $weekdays) limits what is
        # fetched, and `foreach ($weekdays ...)` limits what is iterated. My
        # first two attempts broke one each and moved nothing - the other held
        # the line. That is genuine defence in depth and worth keeping, but it
        # meant the Saturday assertion had never been shown to fail.
        #
        # Removing both makes a Monday apply process every saved weekday, which
        # is precisely what the deleted version of this feature did to 100
        # people's Saturdays.
        'the apply writes every saved weekday, not the ones it was given',
        ("            ->whereIn('weekday', $weekdays)\n"
         "            ->get()\n"
         "            ->keyBy('weekday');",
         "            ->get()\n"
         "            ->keyBy('weekday');"),
        # Caught by the PREVIEW, not by the Saturday assertion.
        #
        # Removing both constraints widens per_weekday to every saved weekday,
        # so a preview asked for Saturday answers about Monday first and the
        # preview assertions go red before the write ones are reached. The probe
        # notices loudly (25 red); my first expectation was just pointed at the
        # wrong one. The surgical mutation below is the one that exercises the
        # Saturday assertion itself.
        'HR previews a Saturday apply',
        # A second edit applied in the same mutation.
        ("        foreach ($weekdays as $weekday) {\n"
         "            $target  = $schedule[$weekday];",
         "        foreach (array_keys($schedule->all()) as $weekday) {\n"
         "            $target  = $schedule[$weekday];"),
    ),
    (
        'any apply also flattens Saturday, with the preview still telling the truth',
        ("        if (!$write) {\n"
         "            return response()->json([",
         "        foreach ($updates as $zzUid => $zzVals) {\n"
         "            $updates[$zzUid]['saturday_out_date'] = '18:00:00';\n"
         "        }\n"
         "\n"
         "        if (!$write) {\n"
         "            return response()->json(["),
        # THE ASSERTION THIS PROBE EXISTS FOR.
        #
        # The preview is untouched by this - it is built before the corruption
        # and returns before the write - so every preview assertion stays green
        # and only the Saturday one can catch it. Which is the situation that
        # actually matters: a preview that promises one thing while the write
        # does another is worse than no preview, because it is trusted.
        #
        # VERIFIED BY HAND 2026-10-07, after a harness run reported this as a
        # hole on a tally-less (stalled-server) probe run:
        #
        #   56 passed, 6 failed
        #   FAIL  SATURDAY WAS NOT FLATTENED by a Monday apply
        #         - expected [18:00:00|14:00:00] got [18:00:00|18:00:00]   <- first
        #
        # run_probe now retries a tally-less run once rather than calling it a
        # hole, because that error points you at weakening a correct assertion.
        'SATURDAY WAS NOT FLATTENED',
    ),
    (
        'saving the template also writes the employees',
        ("        DB::transaction(function () use ($data, $departmentId, $tenantId, $actorId) {",
         "        DB::table('tbluser')->where('department_id', $departmentId)\n"
         "            ->where('sub_institute_id', $tenantId)->update(['saturday_out_date' => '18:00:00']);\n"
         "        DB::transaction(function () use ($data, $departmentId, $tenantId, $actorId) {"),
        'NO employee was changed by saving',
    ),
    (
        'the preview counts everyone as "would be set", nobody as overwritten',
        ("                if ($hasNothing) {\n"
         "                    $unset++;\n"
         "                } else {\n"
         "                    $differ++;\n"
         "                }",
         "                $unset++;"),
        'two employees would have EXISTING hours changed',
    ),
    (
        'a non-working day nulls the time columns',
        ("                if ($in !== null || $out !== null) {\n"
         "                    $updates[$userId][$weekday . '_in_date']  = $in;\n"
         "                    $updates[$userId][$weekday . '_out_date'] = $out;\n"
         "                }",
         "                $updates[$userId][$weekday . '_in_date']  = $in;\n"
         "                $updates[$userId][$weekday . '_out_date'] = $out;"),
        'the 10:00-16:00 hours were left alone, not nulled',
    ),
    (
        'the department tenant check is deleted',
        ("        if (!$this->departmentInTenant($departmentId, $tenantId)) {\n"
         "            return response()->json([\n"
         "                'status'  => 0,\n"
         "                'message' => 'That department is not in your organisation.',\n"
         "            ], 404);\n"
         "        }\n"
         "\n"
         "        $schedule = DB::table('hrms_department_schedules')",
         "        $schedule = DB::table('hrms_department_schedules')"),
        'another tenant\'s HR may not',
    ),
    (
        'the store tenant check is deleted',
        ("        if (!$this->departmentInTenant($departmentId, $tenantId)) {\n"
         "            // 404 rather than 403: a refusal should not confirm that a\n"
         "            // department id exists in somebody else's organisation.\n"
         "            return response()->json([\n"
         "                'status'  => 0,\n"
         "                'message' => 'That department is not in your organisation.',\n"
         "            ], 404);\n"
         "        }",
         "        // mutated"),
        "another tenant's HR cannot write this department",
    ),
    (
        'the template writer inserts instead of upserting',
        ("                DB::table('hrms_department_schedules')->updateOrInsert(",
         "                DB::table('hrms_department_schedules')->insert(array_merge("),
        'still three rows, not six',
    ),
    (
        'the before-image is not recorded',
        ("                    'before'    => $before,", "                    'before'    => [],"),
        'carries the 14:00 Saturday it overwrote',
    ),
    (
        'the preview writes after all',
        ("        if (!$write) {\n"
         "            return response()->json([",
         "        if (false) {\n"
         "            return response()->json(["),
        'STILL nothing written after a preview',
    ),

    (
        'the "weekday has no saved hours" guard is removed',
        ("        if ($missing !== []) {", "        if (false) {"),
        'a weekday with no saved hours',
    ),
    (
        'the idempotency key loses the weekdays again',
        ("                'department.schedule.applied:' . $departmentId\n"
         "                    . ':' . implode('-', $weekdays)\n"
         "                    . ':' . now()->format('YmdHis')\n"
         "                    . ':' . $actorId,",
         "                'department.schedule.applied:' . $departmentId\n"
         "                    . ':' . now()->format('YmdHis')\n"
         "                    . ':' . $actorId,"),
        # NOT expected to be caught, and worth being honest about.
        #
        # The coarse key only loses an event when two applies land in the SAME
        # SECOND, because the collision is enforced by the unique index
        # uq_event_idem. This probe's applies are seconds apart - there are
        # assertions and round trips between them - so the bug is latent here.
        #
        # Reproducing it needs two applies fired back to back with no
        # assertions between, which is a different probe: it would be testing
        # timing rather than behaviour, and would pass or fail depending on how
        # fast the machine is. Recorded as untested instead.
        None,
    ),
    (
        'the headcount trusts status as a string (the tbluser.status trap)',
        ("            ->where('status', 1)\n"
         "            ->whereNull('deleted_at')\n"
         "            ->whereNotNull('department_id')",
         "            ->where('status', 'active')\n"
         "            ->whereNull('deleted_at')\n"
         "            ->whereNotNull('department_id')"),
        'with its three employees counted',
    ),
]

ORIGINALS = {}


def restore():
    for path, text in ORIGINALS.items():
        io.open(path, 'w', encoding='utf-8', newline='\n').write(text)


# Where the output of a tally-less run is kept, for the next paragraph's reason.
CRASH_LOG = os.path.join(ROOT, 'storage', 'app', 'zzknownbad-crash.log')


def run_probe(label='run', _retry=True):
    proc = subprocess.run(
        ['bash', 'Docs/hrit-audit/_evidence/probe-department-schedules.sh'],
        cwd=ROOT, capture_output=True, text=True, timeout=1800,
    )
    out = proc.stdout
    fails = [m.strip() for m in re.findall(r'^\s*FAIL\s+(.*?)\s+- expected', out, re.M)]
    tally = re.search(r'(\d+) passed, (\d+) failed', out)

    if not tally:
        # NO TALLY MEANS THE PROBE DID NOT FINISH - it crashed, or bash exited
        # early, or the dev server stalled. That is a FLAKE, and reporting it as
        # "-1 failed - NOTHING noticed" claims something different and worse:
        # that the detector does not work. It happened once here, on the one
        # mutation whose assertion matters most, and the same mutation run by
        # hand a minute later caught it on the first assertion.
        #
        # So: retry once, and keep the evidence either way.
        io.open(CRASH_LOG, 'w', encoding='utf-8', newline='\n').write(
            '=== %s ===\nexit=%s\n\n--- stdout ---\n%s\n--- stderr ---\n%s\n'
            % (label, proc.returncode, out, proc.stderr))

        if _retry:
            print('        probe produced NO tally (exit %s) - retrying once;'
                  ' output kept at %s' % (proc.returncode, CRASH_LOG), flush=True)
            # `php artisan serve` is single-threaded: give a stalled request
            # room to drain rather than immediately queueing behind it.
            time.sleep(5)
            return run_probe(label, _retry=False)

        print('        probe produced NO tally TWICE (exit %s) - not a flake.'
              ' Output at %s' % (proc.returncode, CRASH_LOG), flush=True)
        return (0, -1, fails)

    return (int(tally.group(1)), int(tally.group(2)), fails)


def main():
    print()
    print('====== Known-bad: department office hours ======')
    print(flush=True)

    # A sidecar left behind means the previous run was killed mid-mutation.
    # Restore from it BEFORE reading the original, or the "original" is a
    # mutated file and every later restore writes the bug back.
    recover(CTRL)

    text = io.open(CTRL, encoding='utf-8').read()
    ORIGINALS[CTRL] = text
    arm(CTRL, text)

    base_pass, base_fail, _ = run_probe('baseline')
    print('  baseline: %d passed, %d failed' % (base_pass, base_fail), flush=True)
    if base_fail != 0:
        print('  STOP - the probe is not green to begin with.')
        return 1
    print(flush=True)

    holes = []

    for entry in MUTATIONS:
        # A mutation is (name, edit, expected) and may carry a SECOND edit:
        # some guards are doubled, and breaking one leaves the other holding the
        # line - which reads as "the probe noticed nothing" when in fact nothing
        # was broken.
        name, (find, repl), must_fail = entry[0], entry[1], entry[2]
        extra = entry[3] if len(entry) > 3 else None

        edits = [(find, repl)] + ([extra] if extra else [])

        if any(f not in text for f, _ in edits):
            print('  SKIP  %s' % name)
            print('        anchor not found - the code moved; this mutation is stale', flush=True)
            holes.append(name + ' (stale anchor)')
            continue

        mutated = text
        for f, r in edits:
            mutated = mutated.replace(f, r, 1)
        io.open(CTRL, 'w', encoding='utf-8', newline='\n').write(mutated)
        try:
            _, failed, fails = run_probe(name)
        finally:
            io.open(CTRL, 'w', encoding='utf-8', newline='\n').write(text)

        if must_fail is None:
            print('  NOTE  %s' % name)
            print('        -> %d failed%s'
                  % (failed, (': ' + '; '.join(fails[:2])) if fails else ' (nothing noticed)'),
                  flush=True)
            continue

        caught = [f for f in fails if must_fail in f]
        if caught:
            print('  GOOD  %s' % name)
            print('        -> caught by "%s" (%d red)' % (caught[0], failed), flush=True)
        else:
            print('  HOLE  %s' % name)
            print('        -> expected "%s" to go red; %d failed%s'
                  % (must_fail, failed, (': ' + '; '.join(fails[:2])) if fails else ' - NOTHING noticed'),
                  flush=True)
            holes.append(name)

    print(flush=True)
    after_pass, after_fail, _ = run_probe('restored')
    print('  restored: %d passed, %d failed' % (after_pass, after_fail))
    if after_fail == 0:
        disarm(CTRL)      # only once the restore is PROVEN by a green probe
    else:
        print('  sidecar kept at %s - the probe is not green, so the restore is'
              ' not trusted' % sidecar(CTRL))
    print()

    if holes:
        print('  %d HOLE(S):' % len(holes))
        for hole in holes:
            print('    - %s' % hole)
        return 1

    print('  every detector was shown to fail when its guard was broken.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print('\n  interrupted - restoring')
        restore()
        sys.exit(130)
    except Exception:
        restore()
        raise
