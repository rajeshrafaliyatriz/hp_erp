#!/usr/bin/env bash
# OFFICE HOURS, SET PER DEPARTMENT AND APPLIED TO ITS EMPLOYEES.
#
#   bash Docs/hrit-audit/_evidence/probe-department-schedules.sh
#
# WHY THIS PROBE IS DIFFERENT FROM THE OTHERS
#
# Every other probe in this directory reads, or writes one attendance row. This
# one tests a BULK WRITE across a department's employees - the operation whose
# previous incarnation was deleted from the product because it silently
# flattened Saturday. 100 employees in one tenant have a Saturday that ends at
# 14:00; a blanket 09:00-18:00 wiped it with no record and no warning.
#
# So it seeds its OWN department and its OWN employees, deliberately with
# DIFFERENT Saturdays, applies, and asserts the difference survived. Running it
# against real employees would be the same mistake the deleted feature made.
#
# SELF-SEEDING AND SELF-CLEANING. Everything it creates is tagged with the
# marker below and removed on the way out, including on failure.
#
# RUN THIS ALONE. `php artisan serve` is single-threaded, so a second probe
# running at the same time queues behind this one, and one abandoned slow
# request wedges the server - every call then returns 000 and the output reads
# as a mass regression rather than a dead server.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }
snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
val()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "";'; }

A6=$(tok 6 administrator)   # tenant 6 HR
E6=$(tok 6 employee)        # a tenant-6 employee - may not set office hours
A3=$(tok 3 administrator)   # tenant 3 HR - the cross-tenant assertion
TENANT=6

# Everything this probe creates carries this marker, so the teardown can find
# it without a list of ids to keep in step.
MARK='zzprobe-schedule'

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

post() {   # post <path> <token> <json> ; echoes HTTP code, body in zzsched.out
  curl -s -o storage/app/zzsched.out -m 60 -w '%{http_code}' -X POST \
    "$BASE/api/attendance/admin/$1" \
    -H "Authorization: Bearer $2" -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -d "$3"
}

get() {    # get <path+query> <token>
  curl -s -o storage/app/zzsched.out -m 60 -w '%{http_code}' \
    "$BASE/api/attendance/admin/$1" \
    -H "Authorization: Bearer $2" -H 'Accept: application/json'
}

body() { php -r '$d=json_decode(file_get_contents("storage/app/zzsched.out"),true);'" $1"; }

teardown() {
  snap "delete from hrms_department_schedules where department_id in
          (select id from hrms_departments where department like '%$MARK%')" >/dev/null 2>&1
  snap "delete from tbluser where email like '%$MARK%'" >/dev/null 2>&1
  snap "delete from hrms_departments where department like '%$MARK%'" >/dev/null 2>&1
  snap "delete from g2g_event where type='department.schedule.applied'
          and payload like '%$MARK%'" >/dev/null 2>&1
  rm -f storage/app/zzsched.out storage/app/zzschedgrid.out
}

echo
echo "============ Department office hours ============"
trap teardown EXIT
teardown     # a crashed previous run must not seed this one's counts

# ---------------------------------------------------------------- seed
#
# TWO EMPLOYEES WITH DIFFERENT SATURDAYS. That is the whole point: employee A
# finishes at 18:00 on Saturday, employee B at 14:00. Applying a blanket
# Saturday must be VISIBLE in the preview as "1 would change", and must not be
# something the apply does without being told to.
echo
echo "0. Seeding a department and two employees with different Saturdays"

# BY role_key OR BY NAME.
#
# Tenant 6's original three profiles - Admin, Employee, HR - have role_key NULL;
# RoleKey resolves them through LEGACY_NAMES on the lowercased name. A lookup on
# role_key alone found nothing here, and every insert after it broke on an empty
# id. The same divergence is why the menu grant needed a repair migration.
PROF=$(val "select id v from tbluserprofilemaster
            where sub_institute_id=$TENANT and deleted_at is null
              and (role_key='employee' or lower(trim(name))='employee')
            order by id limit 1")
check "found an employee profile in tenant $TENANT to attach them to" "1" \
  "$([ -n "$PROF" ] && echo 1 || echo 0)"

# is_calculated is NOT NULL with no default on this table. I measured which
# tbluser columns were required and assumed hrms_departments was fine; it is not.
snap "insert into hrms_departments (department, status, is_calculated, sub_institute_id, created_at, updated_at)
      values ('$MARK dept', 1, 0, $TENANT, now(), now())" >/dev/null
DEPT=$(val "select id v from hrms_departments where department='$MARK dept'")
check "department created" "1" "$([ -n "$DEPT" ] && echo 1 || echo 0)"

# A: Mon-Sat worked with Saturday 09:00-18:00, AND a working Sunday 10:00-16:00.
#
# The Sunday matters. Without it, every employee's Sunday already matched the
# template's "not worked, no hours", the apply had nothing to do, and the two
# assertions about it were true before the apply ran - vacuous in exactly the
# way the documents probe was.
snap "insert into tbluser
        (first_name, last_name, email, password, user_profile_id, sub_institute_id,
         department_id, status, monday, saturday, sunday,
         monday_in_date, monday_out_date,
         saturday_in_date, saturday_out_date,
         sunday_in_date, sunday_out_date, created_at, updated_at)
      values ('Zz', 'Longsat', 'a.$MARK@example.invalid', 'x', $PROF, $TENANT,
              $DEPT, 1, 1, 1, 1,
              '09:00:00', '18:00:00',
              '09:00:00', '18:00:00',
              '10:00:00', '16:00:00', now(), now())" >/dev/null

# B: the same week, but Saturday ends at 14:00 - the case that was flattened.
snap "insert into tbluser
        (first_name, last_name, email, password, user_profile_id, sub_institute_id,
         department_id, status, monday, saturday, monday_in_date, monday_out_date,
         saturday_in_date, saturday_out_date, created_at, updated_at)
      values ('Zz', 'Shortsat', 'b.$MARK@example.invalid', 'x', $PROF, $TENANT,
              $DEPT, 1, 1, 1, '09:00:00', '18:00:00', '09:00:00', '14:00:00', now(), now())" >/dev/null

# C: nothing set at all - one of the 2,008.
snap "insert into tbluser
        (first_name, last_name, email, password, user_profile_id, sub_institute_id,
         department_id, status, created_at, updated_at)
      values ('Zz', 'Noroster', 'c.$MARK@example.invalid', 'x', $PROF, $TENANT,
              $DEPT, 1, now(), now())" >/dev/null

if [ -z "$PROF" ] || [ -z "$DEPT" ]; then
  echo "  ABORT - the seed failed, so nothing below would be testing the product."
  printf "  %d passed, %d failed\n" "$pass" "$((fail + 1))"
  exit 1
fi

A_ID=$(val "select id v from tbluser where email='a.$MARK@example.invalid'")
B_ID=$(val "select id v from tbluser where email='b.$MARK@example.invalid'")
C_ID=$(val "select id v from tbluser where email='c.$MARK@example.invalid'")
check "three employees created" "1" \
  "$([ -n "$A_ID" ] && [ -n "$B_ID" ] && [ -n "$C_ID" ] && echo 1 || echo 0)"
check "  and their Saturdays genuinely differ to begin with" "18:00:00|14:00:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"

# ---------------------------------------------------------------- 1. read
echo
echo "1. Reading the department list"

check "HR can read it" "200" "$(get schedules "$A6")"
check "  the seeded department is there" "1" \
  "$(body 'echo (int) !!array_filter($d["data"] ?? [], fn($x)=>$x["department_id"] === (int) "'"$DEPT"'");')"
check "  with its three employees counted" "3" \
  "$(body 'foreach ($d["data"] ?? [] as $x) { if ($x["department_id"] === (int) "'"$DEPT"'") { echo $x["employee_count"]; return; } } echo "not-found";')"
check "  all seven weekdays present even with no schedule set" "7" \
  "$(body 'foreach ($d["data"] ?? [] as $x) { if ($x["department_id"] === (int) "'"$DEPT"'") { echo count($x["week"]); return; } } echo "not-found";')"
check "  and flagged as having none yet" "" \
  "$(body 'foreach ($d["data"] ?? [] as $x) { if ($x["department_id"] === (int) "'"$DEPT"'") { echo $x["has_schedule"] ? "1" : ""; return; } } echo "not-found";')"

echo
echo "   who may not"
check "an employee is refused" "403" "$(get schedules "$E6")"
check "no token" "401" "$(get schedules "")"
check "another tenant's HR does not see this department" "0" \
  "$(get schedules "$A3" >/dev/null; body 'echo count(array_filter($d["data"] ?? [], fn($x)=>$x["department_id"] === (int) "'"$DEPT"'"));')"

# ---------------------------------------------------------------- 1b. the grid reads the RIGHT weekday
#
# Two live endpoints select only `tbluser.monday_in_date` and compare EVERY day
# of the week against it; the Early Going Report reads Saturday's column for
# Thursday. Both are index arithmetic gone wrong, and the new grid does the same
# per-weekday lookup - so it has to be shown to get it right rather than assumed
# to.
#
# Employee B is the fixture: Monday 09:00-18:00, Saturday 09:00-14:00. If the
# grid read Monday's column for Saturday, B's Saturday cell would say 18:00.
#
# 1999-01-04 is a Monday and 1999-01-02 a Saturday - checked, not assumed:
#   date -d 1999-01-04 +%A  ->  Monday
#   date -d 1999-01-02 +%A  ->  Saturday
#
# AND IT HAS TO RUN HERE, BEFORE ANYTHING IS APPLIED. Placed at the end of the
# probe it asserted the SEEDED hours against rows that sections 2-5 had already
# overwritten with the department template, and failed on all seven - while the
# values it actually got (Monday 18:30, Saturday 13:30) were two DIFFERENT
# applied times, i.e. proof the grid was reading the right weekday all along.
# Employee C also still has no roster at this point, which is what makes the
# last assertion mean anything.
echo
echo "1b. The grid reports each day's own rostered hours"

check "the fixture's Monday and Saturday genuinely differ" "18:00:00|14:00:00" \
  "$(val "select concat(monday_out_date,'|',saturday_out_date) v from tbluser where id=$B_ID")"

GRIDOUT=storage/app/zzschedgrid.out
curl -s -o "$GRIDOUT" -m 90 \
  "$BASE/api/attendance/admin/grid?month=1999-01&per_page=200" \
  -H "Authorization: Bearer $A6" -H 'Accept: application/json'

cell() {   # cell <user_id> <date> <field>
  php -r '$d=json_decode(file_get_contents("storage/app/zzschedgrid.out"),true);
    foreach ($d["data"]["employees"] ?? [] as $e) {
      if ((int) $e["user_id"] === (int) "'"$1"'") { echo $e["days"]["'"$2"'"]["'"$3"'"] ?? "null"; return; }
    } echo "employee-not-in-grid";'
}

# KNOWN-BAD 2026-10-07, by hand - the schedules harness only mutates
# DepartmentScheduleController and this assertion tests the GRID, in
# AttendanceAdminController. Both shift lines were rewritten to read
# monday_in_date / monday_out_date for every day, which is the bug two live
# endpoints have:
#
#   FAIL  the Saturday cell carries SATURDAY's shift, not Monday's
#         - expected [14:00] got [18:00]
#
# The first attempt changed only the ternary CONDITION and left the value
# reading $weekday, so the assertion stayed green and looked vacuous. It was the
# mutation that was wrong, not the detector - worth knowing before weakening
# anything here.
check "the Monday cell carries Monday's shift" "18:00" "$(cell "$B_ID" 1999-01-04 shift_out)"
# >>> THE ONE THAT CATCHES THE MONDAY-FOR-EVERY-DAY BUG <<<
check "the Saturday cell carries SATURDAY's shift, not Monday's" "14:00" \
  "$(cell "$B_ID" 1999-01-02 shift_out)"
check "  and the opening time too" "09:00" "$(cell "$B_ID" 1999-01-02 shift_in)"
# The employee whose Saturday matches Monday must still read correctly - without
# this, a grid that returned the SATURDAY column for every day would also pass
# the assertion above.
check "the other employee's Saturday is its own 18:00" "18:00" \
  "$(cell "$A_ID" 1999-01-02 shift_out)"
check "  and their Monday is also 18:00, so the two are distinguished by the DATA" "18:00" \
  "$(cell "$A_ID" 1999-01-04 shift_out)"
# A day the employee does not work has no rostered hours to report.
check "the employee with no roster reports no shift" "null" \
  "$(cell "$C_ID" 1999-01-04 shift_in)"

rm -f "$GRIDOUT"

# ---------------------------------------------------------------- 2. save
echo
echo "2. Saving the week (the template only - no employee changes yet)"

WEEK='{"department_id":'"$DEPT"',"week":[
  {"weekday":"monday","is_working":true,"in_time":"09:30","out_time":"18:30"},
  {"weekday":"saturday","is_working":true,"in_time":"09:30","out_time":"13:30"},
  {"weekday":"sunday","is_working":false,"in_time":null,"out_time":null}]}'

check "HR saves it" "200" "$(post schedules "$A6" "$WEEK")"
check "  three weekday rows stored" "3" \
  "$(val "select count(*) v from hrms_department_schedules where department_id=$DEPT")"
check "  Saturday's hours are the ones sent" "09:30:00|13:30:00" \
  "$(val "select concat(in_time,'|',out_time) v from hrms_department_schedules
           where department_id=$DEPT and weekday='saturday'")"
check "  Sunday is stored as not worked" "0" \
  "$(val "select is_working v from hrms_department_schedules
           where department_id=$DEPT and weekday='sunday'")"
check "  and it is attributed to the caller, not the request" "28" \
  "$(val "select updated_by v from hrms_department_schedules
           where department_id=$DEPT and weekday='monday'")"

# SAVING MUST NOT WRITE EMPLOYEES. The split between template and apply is the
# whole safety mechanism; if Save also wrote, the preview would be theatre.
check "NO employee was changed by saving" "18:00:00|14:00:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"

echo
echo "   saving again does not duplicate"
check "a second save is accepted" "200" "$(post schedules "$A6" "$WEEK")"
check "  still three rows, not six" "3" \
  "$(val "select count(*) v from hrms_department_schedules where department_id=$DEPT")"

echo
echo "   what saving refuses"
check "a closing time before the opening time" "422" \
  "$(post schedules "$A6" '{"department_id":'"$DEPT"',"week":[{"weekday":"monday","is_working":true,"in_time":"18:00","out_time":"09:00"}]}')"
check "a weekday that is not a weekday" "422" \
  "$(post schedules "$A6" '{"department_id":'"$DEPT"',"week":[{"weekday":"funday","is_working":true}]}')"
check "another tenant's HR cannot write this department" "404" \
  "$(post schedules "$A3" "$WEEK")"
check "  and 404, not 403, so it does not confirm the department exists" "404" \
  "$(post schedules "$A3" "$WEEK")"
check "an employee cannot write it" "403" "$(post schedules "$E6" "$WEEK")"

# ---------------------------------------------------------------- 3. preview
echo
echo "3. The preview, before anything is written"

PV='{"department_id":'"$DEPT"',"weekdays":["saturday"]}'
check "HR previews a Saturday apply" "200" "$(post schedules/preview "$A6" "$PV")"
check "  and it says it applied nothing" "" "$(body 'echo $d["applied"] ? "1" : "";')"
check "  three employees in scope" "3" "$(body 'echo $d["data"]["employees"];')"
check "  it flags that a weekend day is included" "saturday" \
  "$(body 'echo implode(",", $d["data"]["includes_weekend"]);')"

# THE NUMBER THAT MATTERS. Two employees hold a Saturday somebody chose
# (18:00 and 14:00); one holds nothing. A preview that reported "3 would be
# set" would be hiding exactly what the deleted feature hid.
check "  two employees would have EXISTING hours changed" "2" \
  "$(body 'echo $d["data"]["per_weekday"][0]["would_change"];')"
check "  one employee would have hours set for the first time" "1" \
  "$(body 'echo $d["data"]["per_weekday"][0]["would_set"];')"
check "  none already match" "0" \
  "$(body 'echo $d["data"]["per_weekday"][0]["already_match"];')"

check "STILL nothing written after a preview" "18:00:00|14:00:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"

echo
echo "   the preview refuses what the apply would refuse"
check "a weekday with no saved hours" "422" \
  "$(post schedules/preview "$A6" '{"department_id":'"$DEPT"',"weekdays":["tuesday"]}')"
check "no weekdays named at all" "422" \
  "$(post schedules/preview "$A6" '{"department_id":'"$DEPT"'}')"
check "another tenant's department" "404" "$(post schedules/preview "$A3" "$PV")"

# ---------------------------------------------------------------- 4. apply
echo
echo "4. Applying Monday only - Saturday must be untouched"

check "applying Monday is accepted" "200" \
  "$(post schedules/apply "$A6" '{"department_id":'"$DEPT"',"weekdays":["monday"]}')"
check "  and it says it applied" "1" "$(body 'echo $d["applied"] ? 1 : "";')"
check "  Monday's hours landed on employee A" "09:30:00|18:30:00" \
  "$(val "select concat(monday_in_date,'|',monday_out_date) v from tbluser where id=$A_ID")"
check "  and on the employee who had nothing" "09:30:00|18:30:00" \
  "$(val "select concat(monday_in_date,'|',monday_out_date) v from tbluser where id=$C_ID")"
check "  that employee's Monday flag is now set" "1" \
  "$(val "select monday v from tbluser where id=$C_ID")"

# >>> THE ASSERTION THIS WHOLE PROBE EXISTS FOR <<<
#
# A Monday apply must not touch Saturday. The deleted version of this feature
# wrote the whole week whenever it wrote anything, which is how 100 people lost
# a 14:00 finish. There is no "all" on the server for exactly this reason.
check "SATURDAY WAS NOT FLATTENED by a Monday apply" "18:00:00|14:00:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"
check "  and Sunday was not touched either" "16:00:00|1" \
  "$(val "select concat(sunday_out_date,'|',sunday) v from tbluser where id=$A_ID")"

echo
echo "   applying Saturday explicitly DOES change it"
check "the apply is accepted" "200" \
  "$(post schedules/apply "$A6" "$PV")"
check "  and both Saturdays now read the department's hours" "13:30:00|13:30:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"
check "  Monday was not re-written by a Saturday apply" "09:30:00|18:30:00" \
  "$(val "select concat(monday_in_date,'|',monday_out_date) v from tbluser where id=$A_ID")"

echo
echo "   a second identical apply is a no-op, and says so"
check "it is accepted" "200" "$(post schedules/apply "$A6" "$PV")"
check "  and reports that it changed nothing" "" "$(body 'echo $d["applied"] ? "1" : "";')"

echo
echo "   a non-working day writes the flag, not midnight hours"
check "applying Sunday (is_working false, no hours)" "200" \
  "$(post schedules/apply "$A6" '{"department_id":'"$DEPT"',"weekdays":["sunday"]}')"
check "  the flag went from 1 to 0" "0" "$(val "select sunday v from tbluser where id=$A_ID")"
# The template says "not worked" and carries no hours. Writing null into the
# time columns here would read downstream as 00:00 and turn a day off into a day
# whose shift starts at midnight - so the hours are deliberately left as they
# were, which only means something because A HAD hours on Sunday.
check "  and the 10:00-16:00 hours were left alone, not nulled" "10:00:00|16:00:00" \
  "$(val "select concat(sunday_in_date,'|',sunday_out_date) v from tbluser where id=$A_ID")"

echo
echo "   who may apply"
check "an employee may not" "403" \
  "$(post schedules/apply "$E6" "$PV")"
check "another tenant's HR may not" "404" \
  "$(post schedules/apply "$A3" "$PV")"
check "  and the employees are unchanged after that refusal" "13:30:00" \
  "$(val "select saturday_out_date v from tbluser where id=$B_ID")"

# ---------------------------------------------------------------- 5. the record
echo
echo "5. The before-image, which the deleted version could not produce"

check "an applied event was recorded" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where type='department.schedule.applied' and entity_id=$DEPT")"
check "  it names the weekdays it applied" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where type='department.schedule.applied' and entity_id=$DEPT
             and payload like '%saturday%'")"
# The point of the whole thing: six months later, what did this used to be?
check "  and carries the 14:00 Saturday it overwrote" "1" \
  "$(val "select (count(*) > 0) v from g2g_event
           where type='department.schedule.applied' and entity_id=$DEPT
             and payload like '%14:00%'")"
check "  attributed to the HR actor, not the request" "28" \
  "$(val "select actor_id v from g2g_event
           where type='department.schedule.applied' and entity_id=$DEPT
           order by id desc limit 1")"
# Three applies changed something - Monday, Saturday, Sunday - so three events.
# The repeat Saturday apply changed nothing and correctly emitted none.
#
# This is the assertion that catches a too-coarse idempotency key:
# g2g_event.idempotency_key is UNIQUE (uq_event_idem), so two applies that
# produce the same key lose the second one silently inside a try/catch. Keyed on
# department + second + actor, Monday and Saturday landing in one second would
# read as 2 here.
check "  one event per apply that changed something, and no more" "3" \
  "$(val "select count(*) v from g2g_event
           where type='department.schedule.applied' and entity_id=$DEPT")"


echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left - employees" "0" \
  "$(val "select count(*) v from tbluser where email like '%$MARK%'")"
check "  departments" "0" \
  "$(val "select count(*) v from hrms_departments where department like '%$MARK%'")"
check "  schedules" "0" \
  "$(val "select count(*) v from hrms_department_schedules where department_id=$DEPT")"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
