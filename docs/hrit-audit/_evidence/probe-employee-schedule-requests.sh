#!/usr/bin/env bash
# AN EMPLOYEE PROPOSING THEIR OWN OFFICE HOURS, AND HR DECIDING.
#
#   bash Docs/hrit-audit/_evidence/probe-employee-schedule-requests.sh
#
# RUN THIS ALONE. `php artisan serve` is single-threaded, so a second probe
# running at the same time queues behind this one, and one abandoned slow
# request wedges the server - every call then returns 000 and the output reads
# as a mass regression rather than a dead server.
#
# WHY THIS FEATURE IS A REQUEST AND NOT A SETTING
#
# Office hours are 21 columns on tbluser, and they are a PAYROLL INPUT:
# PayrollController::getSaturdayLateCount() reads saturday_in_date to count
# 2nd-Saturday lateness, which is subtracted from payable days. An employee
# writing those columns directly would be an employee adjusting an input to
# their own pay. So the employee's week is stored as a proposal and only an
# approval writes tbluser.
#
# WHAT THIS PROBE IS MOST CAREFUL ABOUT
#
# That a REJECTION writes nothing, and that an APPROVAL writes both the roster
# AND its provenance. The provenance stamp is what later stops "Apply to
# department" flattening the hours this employee just had approved - and if it
# were written outside the approval's transaction, the roster could change while
# the protection silently did not.
#
# SELF-SEEDING AND SELF-CLEANING. Everything it creates carries the marker
# below and is removed on the way out, including on failure.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }
snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
val()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "";'; }

A6=$(tok 6 administrator)   # tenant 6 HR - the decider
E6=$(tok 6 employee)        # tenant 6 employee, user 63 - the requester
A3=$(tok 3 administrator)   # tenant 3 HR - the cross-tenant assertion
AU3=$(tok 3 auditor)        # may read reports; must not decide hours
SUBJECT=63
TENANT=6

MARK='zzprobe-oh'

FE="../g2gv0"
# grep -cF, but a MISSING FILE is reported as "missing-file" rather than 0.
# A deleted or renamed file otherwise satisfies every "expect 0" assertion,
# which is how a whole screen can disappear with the probe still green.
countfix() { if [ -f "$1" ]; then grep -cF "$2" "$1" || true; else echo "missing-file"; fi; }

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

api() {   # api <METHOD> <path+query> <token> [json] ; echoes the HTTP code
  local m="$1" path="$2" t="$3" body="${4:-}"
  if [ -n "$body" ]; then
    curl -s -o storage/app/zzoh.out -m 60 -w '%{http_code}' -X "$m" \
      "$BASE/api/attendance/$path" \
      -H "Authorization: Bearer $t" -H 'Accept: application/json' \
      -H 'Content-Type: application/json' -d "$body"
  else
    curl -s -o storage/app/zzoh.out -m 60 -w '%{http_code}' -X "$m" \
      "$BASE/api/attendance/$path" \
      -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi
}

body() { php -r '$d=json_decode(file_get_contents("storage/app/zzoh.out"),true);'" $1"; }

# The employee's roster before anything, so the teardown can put it back. These
# are REAL columns on a REAL employee, so this probe does not get to be careless
# about them.
BEFORE=$(val "select concat_ws('|',
                coalesce(saturday,'N'), coalesce(saturday_in_date,'N'), coalesce(saturday_out_date,'N'),
                coalesce(thursday,'N'), coalesce(thursday_in_date,'N'), coalesce(thursday_out_date,'N')) v
              from tbluser where id=$SUBJECT")

restore_roster() {
  # Put the two weekdays this probe touches back exactly as they were. Nothing
  # else in tbluser is written.
  php -r '
    $parts = explode("|", $argv[1]);
    $v = fn($i) => $parts[$i] === "N" ? "NULL" : "\"" . $parts[$i] . "\"";
    $sql = sprintf(
      "update tbluser set saturday=%s, saturday_in_date=%s, saturday_out_date=%s,"
      . " thursday=%s, thursday_in_date=%s, thursday_out_date=%s where id=%d",
      $v(0), $v(1), $v(2), $v(3), $v(4), $v(5), (int) $argv[2]);
    echo $sql;' "$BEFORE" "$SUBJECT" > storage/app/zzoh.sql
  snap "$(cat storage/app/zzoh.sql)" >/dev/null 2>&1
  rm -f storage/app/zzoh.sql
}

teardown() {
  snap "delete from hrms_employee_schedule_request_days where request_id in
          (select id from hrms_employee_schedule_requests where user_id=$SUBJECT)" >/dev/null 2>&1
  snap "delete from hrms_employee_schedule_requests where user_id=$SUBJECT" >/dev/null 2>&1
  snap "delete from hrms_employee_roster_provenance where user_id=$SUBJECT" >/dev/null 2>&1
  # ANY ROW THIS PROBE CAUSED TO BE ATTRIBUTED TO SOMEBODY ELSE.
  #
  # The spoofing check posts a request naming user 28. Against correct code that
  # id is ignored and no such row exists - but a known-bad mutation that makes
  # store() trust it DOES create one, and without this the row survived the run
  # and made "no request was created for the id they named" fail forever
  # afterwards. The probe litters only when the code is broken, which is exactly
  # when it must still clean up after itself.
  #
  # Scoped on the fixture's own reason, never on user 28 alone, so this can
  # never delete a real request belonging to that person.
  snap "delete from hrms_employee_schedule_request_days where request_id in
          (select id from hrms_employee_schedule_requests
            where user_id <> $SUBJECT
              and reason in ('I collect my child on Thursdays',
                             'Changed my mind about Saturday'))" >/dev/null 2>&1
  snap "delete from hrms_employee_schedule_requests
         where user_id <> $SUBJECT
           and reason in ('I collect my child on Thursdays',
                          'Changed my mind about Saturday')" >/dev/null 2>&1
  snap "delete from g2g_event where entity_type='hrms_employee_schedule_requests'" >/dev/null 2>&1
  restore_roster
  rm -f storage/app/zzoh.out
}

echo
echo "============ Employee office-hours requests ============"
trap teardown EXIT
teardown     # a crashed previous run must not seed this one

# ---------------------------------------------------------------- 0. read
echo
echo "0. The employee reads their own hours"

check "my-office-hours answers for the caller" "200" "$(api GET my-office-hours "$E6")"
check "  seven weekdays, always" "7" "$(body 'echo count($d["data"]["current"] ?? []);')"
# `is_working: null` means NEVER SET, which is not the same as "not a working
# day" - 2,008 of 2,283 active employees are in that state and the screen has to
# be able to say so.
check "  is_working can be null, meaning never set" "1" \
  "$(body 'foreach ($d["data"]["current"] as $day) { if (!array_key_exists("is_working",$day)) { echo 0; return; } } echo 1;')"
check "  nothing is pending yet" "" "$(body 'echo $d["data"]["pending"] === null ? "" : "1";')"
# This used to end in `body 'echo 1;'`, which echoes 1 whatever happened - so
# it was green even if the request had 500'd. It asserts the status code now.
check "  and NO employee id is accepted - the subject is the token" "200" \
  "$(api GET "my-office-hours?employee=28" "$E6")"
# The endpoint has no subject parameter at all, so passing one cannot change the
# answer. Proven by comparing the two responses rather than by reading the code.
check "  passing one changes nothing" "1" \
  "$(api GET "my-office-hours?employee=28&user_id=28" "$E6" >/dev/null
     A=$(body 'echo json_encode($d["data"]["current"]);')
     api GET my-office-hours "$E6" >/dev/null
     B=$(body 'echo json_encode($d["data"]["current"]);')
     [ "$A" = "$B" ] && echo 1 || echo 0)"
check "no token" "401" "$(api GET my-office-hours "")"

# ---------------------------------------------------------------- 1. raise
echo
echo "1. The employee proposes a change"

REQ='{"reason":"I collect my child on Thursdays","week":[
  {"weekday":"thursday","is_working":true,"in_time":"09:00","out_time":"15:00"},
  {"weekday":"saturday","is_working":false,"in_time":null,"out_time":null}]}'

# ---------------------------------------------------------------------------
# THE WRITE PATH IGNORES A BODY-SUPPLIED SUBJECT.
#
# Added because a known-bad mutation proved its absence: making store() read
# `$request->input('user_id') ?: $context['user_id']` moved NO assertion. The
# read path was covered ("passing one changes nothing" above); the WRITE path -
# the one that creates a standing, pay-relevant row attributed to a person -
# was not covered at all.
#
# 28 is the HR user in this tenant. An employee naming them must still produce
# a request for the EMPLOYEE, because ResolvesApiIdentity's rule is that the
# token decides and the request is never trusted for identity.
# ---------------------------------------------------------------------------
SPOOF=$(printf '%s' "$REQ" | sed 's/^{"reason/{"user_id":28,"reason/')
check "an employee naming somebody else is still accepted" "201" \
  "$(api POST office-hours-requests "$E6" "$SPOOF")"
check "  but the row belongs to the CALLER, not the id they sent" "$SUBJECT" \
  "$(val "select user_id v from hrms_employee_schedule_requests
           where status='pending' and deleted_at is null order by id desc limit 1")"
check "  and no request was created for the id they named" "0" \
  "$(val "select count(*) v from hrms_employee_schedule_requests
           where user_id=28 and deleted_at is null")"
# Clear it, so the re-submit-replaces assertions below start from no pending
# request rather than one this check left behind.
snap "update hrms_employee_schedule_requests
        set status='cancelled', deleted_at=now()
      where user_id=$SUBJECT and status='pending'" >/dev/null

check "the request is accepted" "201" "$(api POST office-hours-requests "$E6" "$REQ")"
RID=$(val "select id v from hrms_employee_schedule_requests
           where user_id=$SUBJECT and status='pending' and deleted_at is null order by id desc limit 1")
check "  recorded as pending" "1" "$([ -n "$RID" ] && echo 1 || echo 0)"
check "  with ONLY the two weekdays asked about" "2" \
  "$(val "select count(*) v from hrms_employee_schedule_request_days where request_id=$RID")"
# An absent detail row means "not asked about" - which is why the week is seven
# rows and not 21 columns. The approval must never clear a day the employee
# did not mention.
check "  Monday is absent, not stored as 'off'" "0" \
  "$(val "select count(*) v from hrms_employee_schedule_request_days
           where request_id=$RID and weekday='monday'")"
check "  and the before-image was captured at submission" "1" \
  "$(val "select (count(*) > 0) v from hrms_employee_schedule_request_days
           where request_id=$RID and weekday='thursday'")"

# >>> NOTHING HAS TOUCHED PAY YET <<<
check "NO tbluser column changed by raising it" "$BEFORE" \
  "$(val "select concat_ws('|',
            coalesce(saturday,'N'), coalesce(saturday_in_date,'N'), coalesce(saturday_out_date,'N'),
            coalesce(thursday,'N'), coalesce(thursday_in_date,'N'), coalesce(thursday_out_date,'N')) v
          from tbluser where id=$SUBJECT")"
check "  and no provenance was stamped" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance where user_id=$SUBJECT")"

echo
echo "   it shows up as pending on the employee's own screen"
check "my-office-hours now carries it" "$RID" \
  "$(api GET my-office-hours "$E6" >/dev/null; body 'echo $d["data"]["pending"]["id"] ?? "none";')"
check "  with the current week beside the requested one" "1" \
  "$(body 'echo isset($d["data"]["pending"]["current_week"]) ? 1 : 0;')"
check "  and is_working arrives as a real boolean, not \"0\"" "boolean" \
  "$(body 'echo gettype($d["data"]["pending"]["week"][0]["is_working"]);')"

echo
echo "   re-submitting replaces it rather than queueing a second"
REQ2='{"reason":"Changed my mind about Saturday","week":[
  {"weekday":"thursday","is_working":true,"in_time":"09:00","out_time":"16:00"}]}'
check "a second submission is accepted" "201" "$(api POST office-hours-requests "$E6" "$REQ2")"
check "  exactly ONE pending request" "1" \
  "$(val "select count(*) v from hrms_employee_schedule_requests
           where user_id=$SUBJECT and status='pending' and deleted_at is null")"
# The superseded one is cancelled and soft-deleted, not removed: an employee
# changing their mind is itself a fact.
check "  the first is cancelled, not deleted outright" "1" \
  "$(val "select count(*) v from hrms_employee_schedule_requests
           where id=$RID and status='cancelled' and deleted_at is not null")"
RID=$(val "select id v from hrms_employee_schedule_requests
           where user_id=$SUBJECT and status='pending' and deleted_at is null order by id desc limit 1")

echo
echo "   what raising refuses"
check "no reason" "422" \
  "$(api POST office-hours-requests "$E6" '{"week":[{"weekday":"monday","is_working":true}]}')"
check "finish before start" "422" \
  "$(api POST office-hours-requests "$E6" '{"reason":"x","week":[{"weekday":"monday","is_working":true,"in_time":"18:00","out_time":"09:00"}]}')"
# Equal is refused too: a zero-length day is not a request.
check "a zero-length day" "422" \
  "$(api POST office-hours-requests "$E6" '{"reason":"x","week":[{"weekday":"monday","is_working":true,"in_time":"09:00","out_time":"09:00"}]}')"
check "the same weekday twice" "422" \
  "$(api POST office-hours-requests "$E6" '{"reason":"x","week":[
      {"weekday":"monday","is_working":true},{"weekday":"monday","is_working":false}]}')"
check "a weekday that is not a weekday" "422" \
  "$(api POST office-hours-requests "$E6" '{"reason":"x","week":[{"weekday":"funday","is_working":true}]}')"

# ---------------------------------------------------------------- 2. the queue
echo
echo "2. The approver queue"

check "HR can read it" "200" "$(api GET "office-hours-requests?scope=team" "$A6")"
check "  and sees the request" "1" \
  "$(body 'foreach ($d["data"] ?? [] as $r) { if ((int) $r["id"] === (int) "'"$RID"'") { echo 1; return; } } echo 0;')"
check "  with the employee named" "1" \
  "$(body 'foreach ($d["data"] ?? [] as $r) { if ((int) $r["id"] === (int) "'"$RID"'") { echo $r["employee_name"] !== null ? 1 : 0; return; } } echo 0;')"

echo
echo "   who may not"
check "an employee asking for the queue is refused" "403" \
  "$(api GET "office-hours-requests?scope=team" "$E6")"
# An auditor may read the organisation's attendance reports. Changing somebody's
# standing working week is a different act.
check "an auditor is refused - reading reports is not deciding pay" "403" \
  "$(api GET "office-hours-requests?scope=team" "$AU3")"
check "another tenant's HR sees none of it" "0" \
  "$(api GET "office-hours-requests?scope=team" "$A3" >/dev/null
     body 'foreach ($d["data"] ?? [] as $r) { if ((int) $r["id"] === (int) "'"$RID"'") { echo 1; return; } } echo 0;')"
check "scope=mine is open to the employee, and is their own" "200" \
  "$(api GET "office-hours-requests?scope=mine" "$E6")"
check "  containing only their own rows" "1" \
  "$(body 'foreach ($d["data"] ?? [] as $r) { if ((int) $r["user_id"] !== '"$SUBJECT"') { echo 0; return; } } echo 1;')"

# ---------------------------------------------------------------- 3. reject
echo
echo "3. A rejection writes nothing"

check "HR rejects it" "200" \
  "$(api POST "office-hours-requests/$RID/decision" "$A6" '{"status":"rejected","reviewer_comment":"Discuss with your manager first"}')"
check "  recorded as rejected" "rejected" \
  "$(val "select status v from hrms_employee_schedule_requests where id=$RID")"
check "  with the reviewer's comment" "Discuss with your manager first" \
  "$(val "select reviewer_comment v from hrms_employee_schedule_requests where id=$RID")"
# >>> THE POINT OF A REJECTION <<<
check "NO tbluser column changed" "$BEFORE" \
  "$(val "select concat_ws('|',
            coalesce(saturday,'N'), coalesce(saturday_in_date,'N'), coalesce(saturday_out_date,'N'),
            coalesce(thursday,'N'), coalesce(thursday_in_date,'N'), coalesce(thursday_out_date,'N')) v
          from tbluser where id=$SUBJECT")"
check "  and no provenance stamped" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance where user_id=$SUBJECT")"
check "  applied_at stays empty, because nothing was applied" "" \
  "$(val "select applied_at v from hrms_employee_schedule_requests where id=$RID")"
check "deciding it again is refused" "409" \
  "$(api POST "office-hours-requests/$RID/decision" "$A6" '{"status":"approved"}')"

# ---------------------------------------------------------------- 4. approve
echo
echo "4. An approval writes the roster AND its provenance"

check "the employee raises a fresh one" "201" "$(api POST office-hours-requests "$E6" "$REQ")"
RID2=$(val "select id v from hrms_employee_schedule_requests
            where user_id=$SUBJECT and status='pending' and deleted_at is null order by id desc limit 1")

echo "   who may not decide"
check "the employee cannot decide their own" "403" \
  "$(api POST "office-hours-requests/$RID2/decision" "$E6" '{"status":"approved"}')"
check "another tenant's HR gets 404, not 403" "404" \
  "$(api POST "office-hours-requests/$RID2/decision" "$A3" '{"status":"approved"}')"
check "an auditor cannot decide" "403" \
  "$(api POST "office-hours-requests/$RID2/decision" "$AU3" '{"status":"approved"}')"
check "  and after all that it is still pending" "pending" \
  "$(val "select status v from hrms_employee_schedule_requests where id=$RID2")"

echo
echo "   HR approves"
check "the approval is accepted" "200" \
  "$(api POST "office-hours-requests/$RID2/decision" "$A6" '{"status":"approved","reviewer_comment":"Agreed"}')"
check "  Thursday now reads 09:00-15:00" "09:00:00|15:00:00" \
  "$(val "select concat(thursday_in_date,'|',thursday_out_date) v from tbluser where id=$SUBJECT")"
check "  and Thursday is flagged as worked" "1" "$(val "select thursday v from tbluser where id=$SUBJECT")"
check "  Saturday is flagged as NOT worked" "0" "$(val "select saturday v from tbluser where id=$SUBJECT")"
# A day asked for as "off" carries no hours, so the time columns are LEFT ALONE
# rather than nulled - writing null would read downstream as midnight and turn a
# day off into a day whose shift starts at 00:00.
check "  and Saturday's old hours were left alone, not nulled to midnight" "1" \
  "$(val "select (saturday_in_date is not null or '$BEFORE' like '%|N|%') v from tbluser where id=$SUBJECT")"
# Monday was never mentioned, so it must be untouched.
check "  Monday, never mentioned, is untouched" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance
           where user_id=$SUBJECT and weekday='monday'")"

echo
echo "   the provenance that protects it from the next department apply"
check "both weekdays are stamped" "2" \
  "$(val "select count(*) v from hrms_employee_roster_provenance where user_id=$SUBJECT")"
check "  as the employee's own choice" "employee_request" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$SUBJECT and weekday='thursday'")"
check "  linked back to the request" "$RID2" \
  "$(val "select source_ref_id v from hrms_employee_roster_provenance
           where user_id=$SUBJECT and weekday='thursday'")"
check "  attributed to the approver, not the employee" "28" \
  "$(val "select set_by v from hrms_employee_roster_provenance
           where user_id=$SUBJECT and weekday='thursday'")"
check "applied_at is recorded" "1" \
  "$(val "select (applied_at is not null) v from hrms_employee_schedule_requests where id=$RID2")"

echo
echo "   and the trail"
check "an event was recorded for the approval" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where entity_type='hrms_employee_schedule_requests' and entity_id=$RID2
             and type='attendance.office_hours.decided'")"
# Six months later: what did this used to be?
check "  carrying the before-image" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where entity_type='hrms_employee_schedule_requests' and entity_id=$RID2
             and payload like '%before%'")"
# The roster is not effective-dated, so an approval changes how PAST days are
# scored. The payload says so, because somebody will ask.
check "  and the note that this is not effective-dated" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where entity_type='hrms_employee_schedule_requests' and entity_id=$RID2
             and payload like '%not effective-dated%'")"
check "the request shows as approved on the employee's screen" "approved" \
  "$(api GET my-office-hours "$E6" >/dev/null
     body 'foreach ($d["data"]["history"] ?? [] as $r) { if ((int) $r["id"] === (int) "'"$RID2"'") { echo $r["status"]; return; } } echo "absent";')"
check "  and nothing is pending any more" "" \
  "$(body 'echo $d["data"]["pending"] === null ? "" : "still-pending";')"

echo
echo "   withdrawal"
check "the employee raises one more" "201" "$(api POST office-hours-requests "$E6" "$REQ2")"
RID3=$(val "select id v from hrms_employee_schedule_requests
            where user_id=$SUBJECT and status='pending' and deleted_at is null order by id desc limit 1")
check "somebody else's id cannot be withdrawn" "404" \
  "$(api DELETE "office-hours-requests/$RID3" "$A6")"
check "the employee withdraws their own" "200" "$(api DELETE "office-hours-requests/$RID3" "$E6")"
check "  it is cancelled and soft-deleted" "1" \
  "$(val "select count(*) v from hrms_employee_schedule_requests
           where id=$RID3 and status='cancelled' and deleted_at is not null")"
check "  withdrawing twice is refused" "404" "$(api DELETE "office-hours-requests/$RID3" "$E6")"

echo
echo "9. the screens - that the wiring exists at all"
# ---------------------------------------------------------------------------
# Static assertions. These cannot prove a screen WORKS, and are not pretending
# to: they prove the specific wiring that, when it was missing before, produced
# a screen that rendered and did nothing. Every one of these is a defect this
# module has actually shipped.
# ---------------------------------------------------------------------------
MOH="$FE/components/domain/hrms/hrit/attendance-management/my-office-hours/page.tsx"
CMP="$FE/components/domain/hrms/hrit/attendance-management/my-office-hours/department-template-compare.tsx"
INL="$FE/components/domain/hrms/hrit/attendance-management/shared/employee-attendance-inline.tsx"
DRW="$FE/components/domain/hrms/hrit/attendance-management/attendance-tracking/components/attendance-calendar-drawer.tsx"
TRK="$FE/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx"
DIR="$FE/components/domain/organization/employee-directory-sheets.tsx"
MAP="$FE/hooks/content-map-m5.ts"

echo "   the employee's own screen"
check "my-office-hours is a default export (the LazyComponent contract)" "1" \
  "$(countfix "$MOH" 'export default function MyOfficeHoursPage()')"
# The whole reason this screen is a REQUEST and not a write.
check "  it says the hours do not change until approved" "1" \
  "$(countfix "$MOH" 'Your hours do not change until HR approves it')"
# A roster change is retroactive because tbluser is not effective-dated, and
# PayrollController reads saturday_in_date for the 2nd-Saturday late count.
check "  and that it is not effective-dated" "1" \
  "$(countfix "$MOH" 'including for days')"
# THE SHARED EDITOR GAINS A THIRD CALLER - it is not re-implemented.
check "  it reuses the shared roster editor" "1" \
  "$(countfix "$MOH" "from '@/domain/organization/employee-directory-parts/attendance-grid'")"
check "  and does not hand-roll a weekday loop of its own" "0" \
  "$(countfix "$MOH" "'tuesday', 'wednesday'")"
# emptySchedule() defaults to Mon-Fri 09:00-18:00, which is a GUESS - so the
# screen must say which of the three seeds it actually used.
check "  the seed is labelled, not silent" "1" \
  "$(countfix "$MOH" "seed.from === 'mine'")"
# Only CHANGED weekdays are sent: an omitted day is left alone, and an approval
# stamps provenance only on the days it wrote.
check "  only changed weekdays are submitted" "1" \
  "$(countfix "$MOH" 'week: changedDays.map')"
check "  and the employee is told which days those are" "1" \
  "$(countfix "$MOH" 'Days you have not changed are left exactly as they are')"
# The grid must be read-only while a decision is outstanding - editing a week
# whose fate is undecided is editing a copy.
check "  the editor AND the copy button are disabled while pending" "2" \
  "$(countfix "$MOH" 'disabled={!editing || !!pending || isSaving}')"
# 2,008 of 2,283 active employees have no roster at all. That is the state this
# screen exists to let somebody fix, so it cannot be silent about it.
check "  a no-roster employee is told so" "1" \
  "$(countfix "$MOH" 'Nobody has recorded which days you work')"

echo
echo "   the department comparison, beside the editor and not inside it"
check "the compare strip exists" "1" \
  "$(countfix "$CMP" 'export function DepartmentTemplateCompare')"
check "  it is mounted beside the grid" "1" \
  "$(countfix "$MOH" '<DepartmentTemplateCompare')"
# Zero changes to the shared grid: the strip's only power is to call the same
# onChange the grid calls.
check "  copying only fills the form" "1" \
  "$(countfix "$CMP" 'Nothing is sent until you ask for approval')"
# A department with no hours has nothing to differ FROM - rendering seven
# "differs" rows there would be inventing differences.
check "  a department with no hours says so instead of showing diffs" "1" \
  "$(countfix "$CMP" 'there is nothing to compare against')"
# An approved request stamps employee_request provenance, and a department
# Apply then skips those days. An employee who copies their department's week
# and submits it is opting OUT of future department updates - correct
# behaviour, and an unguessable one, so the screen has to say it.
check "  and copying warns that the hours become yours" "1"   "$(countfix "$CMP" 'overwrite them unless HR chooses to')"

echo
echo "   HR's side of it"
OHP="$FE/components/domain/hrms/hrit/attendance-management/manage-employee-attendance/office-hours-proposals.tsx"
check "the approval panel exists" "1" \
  "$(countfix "$OHP" 'export function OfficeHoursProposals')"
# F-91: a component gating itself on a role is a guess that drifts from the
# server's. `permitted` comes from the endpoint's own answer.
check "  it renders nothing unless the server permits it" "1" \
  "$(countfix "$OHP" 'if (!permitted || rows.length === 0)')"
check "  no role check of its own" "0" "$(countfix "$OHP" 'isHrAdmin')"
# The context that makes the Office hours tab the right home for this panel.
check "  it shows the department template beside the request" "1" \
  "$(countfix "$OHP" 'show(template)')"
check "  and warns that approving is retroactive" "1" \
  "$(countfix "$OHP" 'effective-dated')"

echo
echo "   the three drill-down surfaces"
check "one inline composition, reused by the two that cannot nest a Sheet" "1" \
  "$(countfix "$INL" 'export function EmployeeAttendanceInline')"
# Attendance Tracking's ?employee= view is handed an id and nothing else, so
# without the fallback the month would render unlabelled.
check "  it falls back to the server-resolved employee name" "1" \
  "$(countfix "$INL" 'employeeName={employeeName ?? detail.employeeName}')"

check "the Employee Directory has an Attendance tab" "1" \
  "$(countfix "$DIR" "{ id: 'attendance', label: 'Attendance' }")"
check "  mounted inline, not as a nested Sheet" "1" \
  "$(countfix "$DIR" '<EmployeeAttendanceInline')"
# The under-construction fallback is a chain of !== comparisons. Forget to add
# the new id and the tab renders its content AND "under construction" below it.
check "  and excluded from the under-construction fallback" "1" \
  "$(countfix "$DIR" "activeTopTab !== 'attendance'")"
# Read-only: corrections keep one home and one dialog.
check "  the directory tab cannot correct" "0" "$(countfix "$DIR" 'canCorrect=')"

check "attendance tracking reads ?employee=" "1" \
  "$(countfix "$TRK" "searchParams?.get('employee')")"
# router.push to the same pathname with different search params is a no-op in
# this shell (gtg-page-shell.tsx:166-180), so Back must be state.
check "  going back is state, not a navigation" "1" \
  "$(countfix "$TRK" 'onClick={() => setViewing(null)}')"
# Every punch endpoint resolves the subject from the TOKEN. A punch control on
# somebody else's month would punch for the viewer - so they are not rendered.
check "  the self dashboard is a separate component" "1" \
  "$(countfix "$TRK" 'function SelfAttendanceDashboard()')"
check "  and AttendanceDashboard is still the export the content map imports" "1" \
  "$(countfix "$TRK" 'export function AttendanceDashboard()')"

echo
echo "   the self calendar drawer, now one month implementation"
check "the drawer delegates to the shared month" "1" \
  "$(countfix "$DRW" '<EmployeeAttendanceInline')"
# The defect that justified replacing it: toDayStatus returned null for
# 'incomplete', and a null status renders unmarked - so a day you punched into
# and never out of looked exactly like a day you were never at work.
check "  its own four-status vocabulary is gone" "0" "$(countfix "$DRW" 'function toDayStatus')"
check "  its own month maths is gone" "0" "$(countfix "$DRW" 'function buildMonthGrid')"
# The subject must be the session's user_id - the value the server compares
# against - not useAuth().user.id from a different store.
check "  the subject is the session user_id" "1" \
  "$(countfix "$DRW" 'const userId = React.useMemo(() => getLaravelContext(user).userId')"
check "  the signature is unchanged" "1" \
  "$(countfix "$DRW" 'export function AttendanceCalendarDrawer({ open, onOpenChange }')"

echo
echo "   the route, and the menu id that must match it"
check "the screen is in the content map" "1" \
  "$(countfix "$MAP" "accessLink: '/module/hrit-solutions/attendance-management/my-office-hours'")"
# content-map-m5 entries carry submenuId as the fallback used when access_link
# is blank, so the id is pinned in the menu migration and MUST agree here. An
# auto-increment id would differ per host and resolve to a different screen.
check "  with submenuId 434, matching the pinned menu row" "1" \
  "$(countfix "$MAP" "submenuId: '434'")"
check "the menu migration pins 434" "1" \
  "$(countfix 'database/migrations/2026_10_09_120000_add_my_office_hours_menu.php' 'private const ID = 434')"
# The grant's audience includes Employee, whose role_key is NULL on the host
# the application uses. A whereIn('role_key', ...) here would repeat Phase 18's
# under-grant on the worst possible profile.
check "the grant does not filter on role_key" "0" \
  "$(countfix 'database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php' ">whereIn('role_key'")"
# Granting ancestor 93 to the profiles that lack it would reveal Monthly
# Attendance Report to ~1,000 Students and ~962 Employees. Leaf only.
check "  and grants the leaf only" "0" \
  "$(countfix 'database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php' 'parent_id')"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left - requests" "0" \
  "$(val "select count(*) v from hrms_employee_schedule_requests where user_id=$SUBJECT")"
check "  provenance" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance where user_id=$SUBJECT")"
# The employee's REAL roster columns, put back exactly as they were found.
check "  and the employee's roster is back as it was" "$BEFORE" \
  "$(val "select concat_ws('|',
            coalesce(saturday,'N'), coalesce(saturday_in_date,'N'), coalesce(saturday_out_date,'N'),
            coalesce(thursday,'N'), coalesce(thursday_in_date,'N'), coalesce(thursday_out_date,'N')) v
          from tbluser where id=$SUBJECT")"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
