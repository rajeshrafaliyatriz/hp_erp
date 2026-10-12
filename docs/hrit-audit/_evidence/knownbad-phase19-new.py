"""
Known-bad for the detectors Phase 19 ADDED.

The two existing harnesses (knownbad-attendance-admin.py,
knownbad-department-schedules.py) cover the assertions that existed before this
phase. This one covers only the new ones, and only the load-bearing ones - the
assertions that, if vacuous, would let the whole feature ship broken while the
probe stayed green.

Phase 18 taught this twice: the Saturday guard was doubled, so breaking either
half alone moved no assertion; and two assertions were vacuous until a mutation
proved them so. Section 7 of the schedules probe just repeated the lesson a
third way - two of its assertions passed VACUOUSLY because they ran after an
apply had already rewritten the provenance they depended on.

SIDECAR DISCIPLINE: the original goes to a `.knownbad-orig` file on disk before
anything is mutated, and is removed only after a byte-for-byte verified restore.
A sidecar found on disk at startup means a previous run was killed - it is
restored before anything else happens.

RUN IT ALONE. `php artisan serve` is single-threaded; a second probe running
alongside makes every request return 000, which reads as a mass regression.

    python Docs/hrit-audit/_evidence/knownbad-phase19-new.py
"""

import io
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))

DEPT = os.path.join(ROOT, 'app', 'Http', 'Controllers', 'Api', 'Attendance',
                    'DepartmentScheduleController.php')
ESR = os.path.join(ROOT, 'app', 'Http', 'Controllers', 'Api', 'Attendance',
                   'EmployeeScheduleRequestController.php')
PROV = os.path.join(ROOT, 'app', 'Services', 'Attendance', 'RosterProvenance.php')

SCHED_PROBE = 'Docs/hrit-audit/_evidence/probe-department-schedules.sh'
REQ_PROBE = 'Docs/hrit-audit/_evidence/probe-employee-schedule-requests.sh'

# (name, file, probe, find, replace, assertions that MUST go red)
MUTATIONS = [
    (
        'the employee-set skip is removed - an apply flattens a chosen Saturday',
        DEPT, SCHED_PROBE,
        """                    if (!$override) {""",
        """                    if (false) {""",
        ["B'S CHOSEN 14:00 SURVIVED"],
    ),
    (
        'isEmployeeSet always says no - the fourth bucket never fills',
        PROV, SCHED_PROBE,
        """        return ($forUser[$weekday]['source'] ?? null) === self::EMPLOYEE_REQUEST;""",
        """        return false;""",
        ['one employee is reported as having set their own hours',
         'as one distinct person to be left alone'],
    ),
    (
        # `$userId   = (int) $context['user_id'];` appears once per method in
        # this file, so the anchor includes store()'s own next line.
        'store() trusts a user id from the request body',
        ESR, REQ_PROBE,
        """        $userId   = (int) $context['user_id'];
        $tenantId = (int) $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'week'              => 'required|array|min:1|max:7',""",
        """        $userId   = (int) ($request->input('user_id') ?: $context['user_id']);
        $tenantId = (int) $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'week'              => 'required|array|min:1|max:7',""",
        ['but the row belongs to the CALLER, not the id they sent',
         'and no request was created for the id they named'],
    ),
]


def sidecar(path):
    return path + '.knownbad-orig'


def restore_if_abandoned(path):
    side = sidecar(path)
    if not os.path.exists(side):
        return False
    original = io.open(side, encoding='utf-8').read()
    if io.open(path, encoding='utf-8').read() != original:
        io.open(path, 'w', encoding='utf-8', newline='\n').write(original)
        print('  restored %s from an abandoned sidecar' % os.path.basename(path))
    os.remove(side)
    return True


def run(probe):
    """Returns (passed, failed, [failing assertion labels])."""
    result = subprocess.run(
        ['bash', probe], cwd=ROOT, capture_output=True, text=True, timeout=1800,
    )
    out = result.stdout + result.stderr
    match = re.search(r'(\d+) passed, (\d+) failed', out)
    passed, failed = (int(match.group(1)), int(match.group(2))) if match else (0, -1)
    fails = [line.split(' - expected')[0].replace('FAIL', '').strip()
             for line in out.splitlines() if 'FAIL' in line]
    return passed, failed, fails


def main():
    print()
    print('====== Known-bad: the detectors Phase 19 added ======')
    print()

    for path in (DEPT, ESR, PROV):
        restore_if_abandoned(path)

    # Baselines, so a red assertion later is attributable to the mutation.
    baselines = {}
    for probe in (SCHED_PROBE, REQ_PROBE):
        passed, failed, _ = run(probe)
        print('  baseline %-52s %d passed, %d failed' % (probe.split('/')[-1], passed, failed))
        if failed != 0:
            print('  STOP - that probe is not green to begin with. Fix that first.')
            return 1
        baselines[probe] = passed
    print()

    holes, good = [], 0

    for name, path, probe, find, replace, expect in MUTATIONS:
        source = io.open(path, encoding='utf-8').read()

        if source.count(find) != 1:
            print('  SKIP  %s' % name)
            print('        anchor found %d times - the code moved; this mutation is stale'
                  % source.count(find))
            continue

        io.open(sidecar(path), 'w', encoding='utf-8', newline='\n').write(source)
        io.open(path, 'w', encoding='utf-8', newline='\n').write(
            source.replace(find, replace, 1))

        try:
            _, failed, fails = run(probe)
        finally:
            io.open(path, 'w', encoding='utf-8', newline='\n').write(source)
            assert io.open(path, encoding='utf-8').read() == source, 'restore mismatch'
            os.remove(sidecar(path))

        caught = [e for e in expect if any(e in f for f in fails)]

        if len(caught) == len(expect):
            good += 1
            print('  GOOD  %s' % name)
            print('        -> %d assertion(s) red, including %r' % (failed, caught[0]))
        else:
            missed = [e for e in expect if e not in caught]
            holes.append((name, missed))
            print('  HOLE  %s' % name)
            print('        -> %d failed, but these did NOT go red: %s' % (failed, missed))

    print()
    for probe, expected in baselines.items():
        passed, failed, _ = run(probe)
        print('  restored %-52s %d passed, %d failed' % (probe.split('/')[-1], passed, failed))
        if failed != 0 or passed != expected:
            print('  !! the probe is NOT back to its baseline - investigate before trusting this')
            return 1
    print()

    if holes:
        print('  %d HOLE(S) - these assertions do not test what they claim:' % len(holes))
        for name, missed in holes:
            print('    - %s  (silent: %s)' % (name, missed))
        return 1

    print('  all %d new detectors were shown to fail when their guard was broken.' % good)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print('\n  interrupted - restoring files')
        for path in (DEPT, ESR, PROV):
            restore_if_abandoned(path)
        sys.exit(130)
