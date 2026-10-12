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

# grep -cF, but a MISSING FILE reports "missing-file" rather than 0 - a
# deleted file otherwise satisfies every "expect 0" assertion.
countfix() { if [ -f "$1" ]; then grep -cF "$2" "$1" || true; else echo "missing-file"; fi; }

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
  snap "delete from hrms_employee_roster_provenance where user_id in
          (select id from tbluser where email like '%$MARK%')" >/dev/null 2>&1
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



# ------------------------------- 7. hours an EMPLOYEE chose are left alone
#
# THE FAILURE THIS EXISTS TO PREVENT, AGAIN.
#
# The previous version of department shift-setting was deleted from the product
# for silently flattening Saturday across a department; 100 employees in one
# tenant finish at 14:00. Section 4 proves a Monday apply does not touch
# Saturday. This section proves something different and newer: that an apply
# which IS asked to write Saturday still leaves alone the employees who chose
# their own Saturday through an approved request.
#
# `hrms_employee_roster_provenance` is what makes that answerable. A missing row
# means "we do not know where this came from", which is true of every roster
# written before this shipped - so the skip count starts at zero and fills
# forward. This probe stamps its OWN fixture rather than relying on any existing
# row.
echo
echo "7. An employee's own hours survive a department apply"

# CLEAR THE PROVENANCE SECTION 4 STAMPED, FIRST.
#
# Section 4 applied a department schedule to these same employees, and an apply
# stamps `department_apply` provenance for every weekday it wrote - so A and B
# both already have a saturday row here. Without this delete the insert below
# hits `herp_tenant_user_weekday_uniq` (Duplicate entry '6-<id>-saturday'),
# snapshot.php dies on the PDOException, B is never marked as employee-set, and
# every assertion in this section fails in a way that reads exactly like the
# fourth bucket being broken.
#
# The tbluser columns below were already being reset for the same reason. The
# provenance rows that the same apply wrote were missed.
snap "delete from hrms_employee_roster_provenance
       where sub_institute_id = $TENANT and weekday = 'saturday'
         and user_id in ($A_ID, $B_ID)" >/dev/null

# Employee B asked for and was granted a 14:00 Saturday. Stamped directly
# because this probe does not own an approved request - the request lifecycle is
# asserted in probe-employee-schedule-requests.sh.
snap "insert into hrms_employee_roster_provenance
        (sub_institute_id, user_id, weekday, source, source_ref_id, set_by, set_at, created_at, updated_at)
      values ($TENANT, $B_ID, 'saturday', 'employee_request', 999, 28, now(), now(), now())" >/dev/null
check "employee B's Saturday is marked as their own choice" "employee_request" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$B_ID and weekday='saturday'")"
check "  and employee A's is not marked at all" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance
           where user_id=$A_ID and weekday='saturday'")"

# Put the differing Saturdays back - section 4 applied over them.
snap "update tbluser set saturday_in_date='09:00:00', saturday_out_date='18:00:00' where id=$A_ID" >/dev/null
snap "update tbluser set saturday_in_date='09:00:00', saturday_out_date='14:00:00' where id=$B_ID" >/dev/null
check "the fixture is back: A finishes 18:00, B finishes 14:00" "18:00:00|14:00:00" \
  "$(val "select concat(
       (select saturday_out_date from tbluser where id=$A_ID), '|',
       (select saturday_out_date from tbluser where id=$B_ID)) v")"

echo
echo "   the preview counts them separately, before anything is written"
check "the preview runs" "200" "$(post schedules/preview "$A6" "$PV")"
check "  one employee is reported as having set their own hours" "1" \
  "$(body 'echo $d["data"]["per_weekday"][0]["employee_set"] ?? "missing";')"
# Two counts, because they are different numbers. "3 employees will be left
# alone" needs distinct PEOPLE; a weekday-row sum would say 21 for three people
# across seven days.
check "  as one distinct person to be left alone" "1" \
  "$(body 'echo $d["data"]["employees_left_alone"] ?? "missing";')"
check "  and named, so the confirmation can say who" "1" \
  "$(body 'echo count($d["data"]["employees_left_alone_list"] ?? []);')"
check "  the override is off unless asked for" "" \
  "$(body 'echo $d["data"]["override_employee_hours"] ? "1" : "";')"
check "  and A, who chose nothing, is still counted as a change" "1" \
  "$(body 'echo $d["data"]["per_weekday"][0]["would_change"];')"

# ---------------------------------------------------------------------------
# THE SEMANTICS THAT BIT THE UI, PINNED WHERE THEY CAN ACTUALLY BE TRUE.
#
# `employees_left_alone` means the people this apply will LEAVE ALONE, so with
# the override ON the honest answer is ZERO - nobody is being left alone. That
# is correct, and the confirmation dialog was reading it live to decide whether
# to render the override warning AT ALL: ticking the box made the warning and
# the box itself disappear, leaving an armed override with nothing on screen
# saying so and no way to untick it.
#
# THIS SITS HERE, BEFORE ANY APPLY, AND THAT PLACEMENT IS THE ASSERTION.
# It was first written after the override apply further down, where it passed -
# VACUOUSLY. By that point B's provenance had already been rewritten to
# `department_apply` by the apply itself, so B was not employee-set, every
# count was 0, and "employees_left_alone is 0" was true for a reason that had
# nothing to do with the override. Here B IS employee-set, so the zero means
# what it claims and `total_employee_set` has something to count.
# ---------------------------------------------------------------------------
check "with the override ON, employees_left_alone is 0" "0"   "$(post schedules/preview "$A6" '{"department_id":'"$DEPT"',"weekdays":["saturday"],"override_employee_hours":true}' >/dev/null
     body 'echo $d["data"]["employees_left_alone"] ?? "missing";')"
# ... while the weekday-row count does NOT move with the tick, which is why it
# is the mode-independent signal a UI should gate on.
OVR_SET=$(body 'echo $d["data"]["total_employee_set"] ?? "missing";')
check "  but total_employee_set still counts them" "yes"   "$([ "$OVR_SET" != "0" ] && [ "$OVR_SET" != "missing" ] && echo yes || echo "no [$OVR_SET]")"
# Back to the default mode, so the sections below are not reading a preview
# that was taken with the override on.
post schedules/preview "$A6" "$PV" >/dev/null

echo
echo "   the apply leaves them alone by default"
check "applying Saturday is accepted" "200" "$(post schedules/apply "$A6" "$PV")"
# >>> THE ASSERTION THIS SECTION EXISTS FOR <<<
check "  B'S CHOSEN 14:00 SURVIVED" "14:00:00" \
  "$(val "select saturday_out_date v from tbluser where id=$B_ID")"
check "  while A, who chose nothing, was updated" "13:30:00" \
  "$(val "select saturday_out_date v from tbluser where id=$A_ID")"
check "  and the message says who was left alone" "1" \
  "$(body 'echo strpos($d["message"] ?? "", "left alone") !== false ? 1 : 0;')"
# The apply stamps its OWN work, or the next apply cannot tell it from a choice.
check "  A's Saturday is now stamped as a department apply" "department_apply" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$A_ID and weekday='saturday'")"
check "  and B's stamp was NOT overwritten, because B was skipped" "employee_request" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$B_ID and weekday='saturday'")"

echo
echo "   the override overwrites, and says the word"
check "applying with the override is accepted" "200" \
  "$(post schedules/apply "$A6" '{"department_id":'"$DEPT"',"weekdays":["saturday"],"override_employee_hours":true}')"
check "  B's 14:00 is now the department's 13:30" "13:30:00" \
  "$(val "select saturday_out_date v from tbluser where id=$B_ID")"
check "  the message uses the word overwritten" "1" \
  "$(body 'echo stripos($d["message"] ?? "", "overwritten") !== false ? 1 : 0;')"
check "  and B's provenance is now the department's" "department_apply" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$B_ID and weekday='saturday'")"
# "false" is TRUTHY in PHP, and this is the flag that decides whether somebody's
# chosen Saturday is overwritten - so the string must not enable it.
check "the literal string \"false\" does NOT enable the override" "422" \
  "$(post schedules/apply "$A6" '{"department_id":'"$DEPT"',"weekdays":["saturday"],"override_employee_hours":"false"}')"

echo
echo "   the confirmation dialog does not read the mode-dependent count live"
OH="../g2gv0/components/domain/hrms/hrit/attendance-management/manage-employee-attendance/office-hours.tsx"
check "the people count is captured once, not per render" "1"   "$(countfix "$OH" 'const [employeeSet] = React.useState(() => ({')"
check "  so the override block survives being ticked" "1"   "$(countfix "$OH" 'const leftAlone = employeeSet.count')"

echo
echo "   an HR edit through Employee Directory re-stamps, so Apply stops skipping"
# THE WRITER THAT GETS FORGOTTEN. Without this, HR editing an employee here
# leaves a stale employee_request stamp and the next apply skips somebody whose
# hours HR itself just set - reporting them as having chosen their own.
snap "delete from hrms_employee_roster_provenance where user_id=$B_ID" >/dev/null
snap "insert into hrms_employee_roster_provenance
        (sub_institute_id, user_id, weekday, source, source_ref_id, set_by, set_at, created_at, updated_at)
      values ($TENANT, $B_ID, 'saturday', 'employee_request', 999, 28, now(), now(), now())" >/dev/null
snap "update tbluser set saturday_out_date='14:00:00' where id=$B_ID" >/dev/null
check "B is employee-set again" "1" \
  "$(post schedules/preview "$A6" "$PV" >/dev/null; body 'echo $d["data"]["employees_left_alone"];')"

check "HR edits B's schedule through the directory" "200" \
  "$(curl -s -o storage/app/zzsched.out -m 60 -w '%{http_code}' -X PUT \
      "$BASE/api/employees-management/$B_ID" \
      -H "Authorization: Bearer $A6" -H 'Accept: application/json' \
      -H 'Content-Type: application/json' \
      -d '{"schedule":[{"day":"saturday","working":true,"in_time":"09:00","out_time":"16:00"}]}')"
check "  the stamp moved to hr_directory" "hr_directory" \
  "$(val "select source v from hrms_employee_roster_provenance
           where user_id=$B_ID and weekday='saturday'")"
# >>> AND THEREFORE <<<
check "  so the apply no longer skips B" "0" \
  "$(post schedules/preview "$A6" "$PV" >/dev/null; body 'echo $d["data"]["employees_left_alone"];')"

echo
echo "  ---------------------------------------------"
teardown
check "no provenance rows left behind" "0" \
  "$(val "select count(*) v from hrms_employee_roster_provenance
           where user_id in ($A_ID, $B_ID, $C_ID)")"
check "nothing of this probe's is left - employees" "0" \
  "$(val "select count(*) v from tbluser where email like '%$MARK%'")"
check "  departments" "0" \
  "$(val "select count(*) v from hrms_departments where department like '%$MARK%'")"
check "  schedules" "0" \
  "$(val "select count(*) v from hrms_department_schedules where department_id=$DEPT")"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
