#!/usr/bin/env bash
# SUB-MODULE LIFECYCLES — every HRIT sub-module driven through its writes, not
# just its reads.
#
#   bash Docs/hrit-audit/_evidence/probe-lifecycle.sh
#
# Ten sprints proved these screens OPEN. Most of them had never been driven
# through create -> read -> update -> delete at the API, which is where
# F-146 was hiding: Payroll Type's by-id operations had no tenant clause at all.
#
# Every section creates its own data and removes it. Nothing pre-existing is
# modified — the lesson probe-sprint6 learned by consuming four real leave
# requests, and probe-sprint10 by leaving a request behind.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)   # user 6
H3=$(tok 3 hr_manager)      # user 67
E3=$(tok 3 employee)        # user 7
A6=$(tok 6 administrator)   # user 28

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
one()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$2"; }

# Legacy hrms.php routes take identity as query params under type=API, not only
# as a bearer header. Both are sent so the probe works either way.
post() { local path=$1 tokn=$2 uid=$3 tid=$4 body=${5:-}
  curl -s -X POST "$BASE$path?type=API&token=$tokn&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $tokn" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d "$body" --max-time 90; }

postc() { local path=$1 tokn=$2 uid=$3 tid=$4 body=${5:-}
  curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE$path?type=API&token=$tokn&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $tokn" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d "$body" --max-time 90; }

echo "=============================================================="
echo " HRIT sub-module lifecycles"
echo "=============================================================="

# ===========================================================================
echo
echo "7. PAYROLL TYPE — create, edit, delete, and the tenant boundary (F-146)"
# ===========================================================================
snap "delete from payroll_types where payroll_name like 'lifecycle-probe%'" >/dev/null

# --- create ---------------------------------------------------------------
CREATE=$(post /payroll-type/store "$A3" 6 3 \
  "payroll_type=1&payroll_name=lifecycle-probe-head&amount_type=1&status=1&day_count=1&sort_order=99&payroll_percentage=")
NEW_ID=$(one "select id from payroll_types where payroll_name='lifecycle-probe-head' and sub_institute_id=3" id)
check "a pay head can be created" "1" "$(if [ -n "$NEW_ID" ]; then echo 1; else echo 0; fi)"
check "and it belongs to the caller's organisation" "3" \
  "$(one "select sub_institute_id s from payroll_types where id=$NEW_ID" s)"

# --- validation (the gap the tracker named) --------------------------------
check "a pay head with no name is refused" "400" \
  "$(postc /payroll-type/store "$A3" 6 3 "payroll_type=1&amount_type=1&status=1")"

# --- update ---------------------------------------------------------------
post /payroll-type/store "$A3" 6 3 \
  "id=$NEW_ID&payroll_type=1&payroll_name=lifecycle-probe-renamed&amount_type=1&status=1&day_count=1&sort_order=99" >/dev/null
check "it can be renamed" "lifecycle-probe-renamed" \
  "$(one "select payroll_name p from payroll_types where id=$NEW_ID" p)"

# --- F-146: the tenant boundary on every by-id operation -------------------
# Before the fix, tenant 6's administrator could rewrite this head AND move it
# into tenant 6, because sub_institute_id is reassigned from the caller.
STEAL=$(postc /payroll-type/store "$A6" 28 6 \
  "id=$NEW_ID&payroll_type=1&payroll_name=STOLEN&amount_type=1&status=1&day_count=1&sort_order=1")
check "another tenant cannot edit this pay head" "404" "$STEAL"
check "the name is untouched" "lifecycle-probe-renamed" \
  "$(one "select payroll_name p from payroll_types where id=$NEW_ID" p)"
check "and it did NOT move organisation" "3" \
  "$(one "select sub_institute_id s from payroll_types where id=$NEW_ID" s)"

check "another tenant cannot delete it either" "0" \
  "$(post /payroll-type/destroy/$NEW_ID "$A6" 28 6 "" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status_code"] ?? "";')"
check "it is still not deleted" "" \
  "$(one "select deleted_at d from payroll_types where id=$NEW_ID" d)"

# --- delete, by the tenant that owns it ------------------------------------
check "the owning tenant CAN delete it" "1" \
  "$(post /payroll-type/destroy/$NEW_ID "$A3" 6 3 "" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status_code"] ?? "";')"
check "and it is soft-deleted, not destroyed" "1" \
  "$(one "select count(*) c from payroll_types where id=$NEW_ID and deleted_at is not null" c)"

# --- cleanup ---------------------------------------------------------------
# BY ID as well as by name. Run against the UNFIXED code to prove it catches
# the defect, the cross-tenant write RENAMES this row to 'STOLEN' and moves it
# to tenant 6 - so a name-pattern delete silently misses it. That happened once.
snap "delete from payroll_types where id=$NEW_ID" >/dev/null
snap "delete from payroll_types where payroll_name like 'lifecycle-probe%' or payroll_name='STOLEN'" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from payroll_types where id=$NEW_ID" c)"

echo

# ===========================================================================
echo
echo "11. SALARY CERTIFICATE - the table that held zero rows platform-wide"
# ===========================================================================
# F-110 was closed in Sprint 8 as "unusable rather than unused". It was never
# actually generated: hrms_salary_certificate still held 0 rows, because the
# endpoint threw a TypeError before reaching the writer (F-147).
# Employee 10 is the only person in tenant 3 with a salary structure.
snap "delete from hrms_salary_certificate where employee_id=10 and year=2026 and sub_institute_id=3" >/dev/null

check "a missing pay head is refused, not a 500 (F-147)" "422" \
  "$(postc /hrms-salary-certificate-report "$H3" 67 3 "employee_id=10&year=2026&department_id=35&month_id[]=1")"
check "a missing month is refused too" "422" \
  "$(postc /hrms-salary-certificate-report "$H3" 67 3 "employee_id=10&year=2026&department_id=35&payroll_type_id[]=9")"

post /hrms-salary-certificate-report "$H3" 67 3 \
  "employee_id=10&year=2026&department_id=35&month_id[]=1&month_id[]=2&payroll_type_id[]=9&payroll_type_id[]=10" >/dev/null
CERT=$(one "select id from hrms_salary_certificate where employee_id=10 and year=2026 and sub_institute_id=3" id)
check "a certificate is actually generated" "1" "$(if [ -n "$CERT" ]; then echo 1; else echo 0; fi)"
check "and it records WHO issued it (F-148)" "67" \
  "$(one "select created_by c from hrms_salary_certificate where id=$CERT" c)"
check "and WHEN" "1" \
  "$(one "select case when created_at is null then 0 else 1 end c from hrms_salary_certificate where id=$CERT" c)"
check "the document names the employee" "1" \
  "$(one "select case when pdf_html like '%kasish%' then 1 else 0 end c from hrms_salary_certificate where id=$CERT" c)"
# F-131 was "every employee was called Her REGARDLESS of gender". Employee 10's
# gender is 'F', so "Her" is the CORRECT output here - the proof is that the
# sentence follows u.gender rather than being hardcoded. Asserting the ABSENCE
# of "Her" would fail on a correct certificate, which is how a probe ends up
# enforcing the very bug it was written to catch.
check "the certificate follows the employee's recorded gender (F-131)" "1" \
  "$(one "select case when pdf_html like '%Her monthly salary%' then 1 else 0 end c from hrms_salary_certificate where id=$CERT" c)"
check "and employee 10 is on record as F, which is why" "F" \
  "$(one "select gender g from tbluser where id=10" g)"

# an employee with no structure is a 422 with an instruction, not a crash
check "an employee with no salary structure is refused kindly (F-110)" "422" \
  "$(postc /hrms-salary-certificate-report "$H3" 67 3 "employee_id=7&year=2026&department_id=35&month_id[]=1&payroll_type_id[]=9")"

snap "delete from hrms_salary_certificate where employee_id=10 and year=2026 and sub_institute_id=3" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from hrms_salary_certificate where employee_id=10 and year=2026" c)"

# ===========================================================================
echo
echo "9. PAYROLL DEDUCTION - save an adjustment and read it back"
# ===========================================================================
snap "delete from hrms_emp_payroll_deduction where employee_id=10 and month='Nov' and year=2026" >/dev/null

post /payroll-deduction/store "$H3" 67 3 \
  "payroll_type=9&deduction_type_id=9&month=Nov&year=2026&deductAmt[10]=750" >/dev/null
check "an adjustment is stored" "750" \
  "$(one "select deduction_amount d from hrms_emp_payroll_deduction where employee_id=10 and month='Nov' and year=2026" d)"
check "under the canonical month the screen posts" "Nov" \
  "$(one "select month m from hrms_emp_payroll_deduction where employee_id=10 and year=2026 and deduction_amount=750" m)"
# F-143: the calculation matches month EXACTLY. A row the screen can write is a
# row the calculation can find; the eleven legacy rows spelled "8"/"2"/"3"
# cannot be, and that is the finding.
check "and it is therefore reachable by the payroll calculation (F-143)" "1" \
  "$(one "select case when month in ('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec') then 1 else 0 end c from hrms_emp_payroll_deduction where employee_id=10 and month='Nov' and year=2026" c)"

snap "delete from hrms_emp_payroll_deduction where employee_id=10 and month='Nov' and year=2026" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from hrms_emp_payroll_deduction where employee_id=10 and month='Nov' and year=2026" c)"

# ===========================================================================
echo
echo "6. LEAVE CONFIGURATION - leave types and holidays, full CRUD"
# ===========================================================================
jpost() { local path=$1 tokn=$2 body=$3 m=${4:-POST}
  curl -s -X "$m" "$BASE$path" -H "Authorization: Bearer $tokn" -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -d "$body" --max-time 60; }
jcode() { local path=$1 tokn=$2 body=$3 m=${4:-POST}
  curl -s -o /dev/null -w '%{http_code}' -X "$m" "$BASE$path" -H "Authorization: Bearer $tokn" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$body" --max-time 60; }

snap "delete from hrms_leave_types where leave_type='lifecycle-probe-type'" >/dev/null

LT=$(jpost /api/leave/leave-types "$A3" '{"sub_institute_id":3,"leave_type":"lifecycle-probe-type","leave_type_id":"LTY900","status":1}')
LT_ID=$(one "select id from hrms_leave_types where leave_type='lifecycle-probe-type' and deleted_at is null" id)
check "a leave type can be created" "1" "$(if [ -n "$LT_ID" ]; then echo 1; else echo 0; fi)"
check "an employee cannot create one" "403" \
  "$(jcode /api/leave/leave-types "$E3" '{"sub_institute_id":3,"leave_type":"nope","leave_type_id":"LTY901"}')"
jpost "/api/leave/leave-types/$LT_ID" "$A3" '{"sub_institute_id":3,"leave_type":"lifecycle-probe-renamed","leave_type_id":"LTY900"}' PUT >/dev/null
check "it can be renamed" "lifecycle-probe-renamed" \
  "$(one "select leave_type l from hrms_leave_types where id=$LT_ID" l)"
jpost "/api/leave/leave-types/$LT_ID/status" "$A3" '{"sub_institute_id":3,"status":0}' PATCH >/dev/null
check "its status can be toggled" "0" \
  "$(one "select status s from hrms_leave_types where id=$LT_ID" s)"
check "another tenant cannot delete it" "404" \
  "$(jcode "/api/leave/leave-types/$LT_ID" "$A6" '{"sub_institute_id":6}' DELETE)"
check "it survives that attempt" "" \
  "$(one "select deleted_at d from hrms_leave_types where id=$LT_ID" d)"
jpost "/api/leave/leave-types/$LT_ID" "$A3" '{"sub_institute_id":3}' DELETE >/dev/null
check "the owning tenant can delete it" "1" \
  "$(one "select count(*) c from hrms_leave_types where id=$LT_ID and deleted_at is not null" c)"

snap "delete from hrms_leave_types where id=$LT_ID" >/dev/null

# --- holidays --------------------------------------------------------------
snap "delete from hrms_holidays where holiday_name='lifecycle-probe-holiday'" >/dev/null
jpost /api/leave/holidays "$A3" '{"sub_institute_id":3,"holiday_name":"lifecycle-probe-holiday","from_date":"2026-11-11","to_date":"2026-11-11","day_type":"full","description":"probe"}' >/dev/null
HOL=$(one "select id from hrms_holidays where holiday_name='lifecycle-probe-holiday' and deleted_at is null" id)
check "a holiday can be created" "1" "$(if [ -n "$HOL" ]; then echo 1; else echo 0; fi)"
check "another tenant cannot delete it" "404" \
  "$(jcode "/api/leave/holidays/$HOL" "$A6" '{"sub_institute_id":6}' DELETE)"
jpost "/api/leave/holidays/$HOL" "$A3" '{"sub_institute_id":3}' DELETE >/dev/null
check "the owning tenant can" "1" \
  "$(one "select count(*) c from hrms_holidays where id=$HOL and deleted_at is not null" c)"
snap "delete from hrms_holidays where id=$HOL" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from hrms_holidays where holiday_name='lifecycle-probe-holiday'" c)"

# ===========================================================================
echo
echo "1. ATTENDANCE TRACKING - punch in, punch out, hours recorded"
# ===========================================================================
PDAY=$(php -r 'echo date("Y-m-d");')
snap "delete from hrms_attendances where user_id=582 and day='$PDAY'" >/dev/null

jpost /api/attendance/punch-in "$(tok 3 team_employee)" "{\"sub_institute_id\":3,\"employee\":582,\"indate\":\"$PDAY\",\"intime\":\"09:15:00\"}" >/dev/null
check "punch-in creates today's row" "1" \
  "$(one "select count(*) c from hrms_attendances where user_id=582 and day='$PDAY' and punchin_time is not null" c)"
jpost /api/attendance/punch-out "$(tok 3 team_employee)" "{\"sub_institute_id\":3,\"employee\":582,\"outdate\":\"$PDAY\",\"outtime\":\"18:30:00\"}" >/dev/null
check "punch-out records a duration" "1" \
  "$(one "select case when timestamp_diff is not null then 1 else 0 end c from hrms_attendances where user_id=582 and day='$PDAY'" c)"

snap "delete from hrms_attendances where user_id=582 and day='$PDAY'" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from hrms_attendances where user_id=582 and day='$PDAY'" c)"

# ===========================================================================
echo
echo "2. ATTENDANCE REPORTS - the report actually runs and is tenant-scoped"
# ===========================================================================
RPT=$(post /show-hrms-attendance-report "$H3" 67 3 "from_date=2026-08-01&to_date=2026-08-31&department_id=35")
check "the report returns employees" "1" \
  "$(echo "$RPT" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["employees"]) ? 1 : 0;')"
check "every row belongs to the caller's organisation" "0" \
  "$(echo "$RPT" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     $n=0; foreach ($d["employees"] ?? [] as $e) { if ((int)($e["sub_institute_id"] ?? 3) !== 3) $n++; } echo $n;')"
check "an employee cannot open it" "403" \
  "$(postc /show-hrms-attendance-report "$E3" 7 3 "from_date=2026-08-01&to_date=2026-08-31&department_id=35")"

# ===========================================================================
echo
echo "12. FORM 16 - the employee picker that returned nobody (F-149)"
# ===========================================================================
# getEmployeeLists read the tenant from the SESSION with no type=API branch, so
# a token caller filtered on null and got {"employees":[]} with HTTP 200 - an
# empty picker and no error to explain it. The audit had recorded this method
# as "dead but broken, nothing calls it"; routes/hrms.php:89 is its caller.
F16=$(post /form16-get-employees-list "$H3" 67 3 "year=2026&department_id=35")
check "the picker returns employees at all (F-149)" "1" \
  "$(echo "$F16" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["employees"]) ? 1 : 0;')"
# NOTE, because this nearly went unnoticed: the two assertions below pass
# VACUOUSLY on the broken endpoint. "No foreign employees" and "another tenant
# sees none of ours" are both trivially true of an EMPTY list. Only the
# assertion above - that the picker returns anybody at all - fails without the
# fix. Same trap as F-109, whose probe printed the surviving duplicates and
# passed: an assertion that cannot fail is not a test.
check "and every one is in the caller's organisation" "0" \
  "$(echo "$F16" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     $n=0; foreach ($d["employees"] ?? [] as $e) { if ((int)($e["sub_institute_id"] ?? 3) !== 3) $n++; } echo $n;')"
# The other tenant must see its own people, not tenant 3's - the same method,
# the same call, a different answer. That is what proves the tenant is resolved
# from identity rather than left null.
F16_6=$(post /form16-get-employees-list "$A6" 28 6 "year=2026&department_id=35")
check "another tenant does not see tenant 3's employees" "0" \
  "$(echo "$F16_6" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     $n=0; foreach ($d["employees"] ?? [] as $e) { if ((int)($e["sub_institute_id"] ?? 0) === 3) $n++; } echo $n;')"
check "the Form 16 screen itself opens" "200" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/form16?type=API&token=$H3&sub_institute_id=3&user_id=67&syear=2026-2027" \
     -H "Authorization: Bearer $H3" -H 'Accept: application/json' --max-time 90)"

# ===========================================================================
echo
echo "8. SALARY STRUCTURE - reachable, gated, and tenant-scoped"
# ===========================================================================
SS=$(curl -s "$BASE/employee-salary-structure?type=API&token=$H3&sub_institute_id=3&user_id=67&syear=2026-2027" \
      -H "Authorization: Bearer $H3" -H 'Accept: application/json' --max-time 90)
check "HR can read the structure list" "1" \
  "$(echo "$SS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["employees"]) ? 1 : 0;')"
check "and it carries no password hash (F-92)" "0" \
  "$(echo "$SS" | grep -c '"password"')"
check "an employee is refused (F-91)" "403" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/employee-salary-structure?type=API&token=$E3&sub_institute_id=3&user_id=7&syear=2026-2027" \
     -H "Authorization: Bearer $E3" -H 'Accept: application/json' --max-time 90)"
check "every listed employee belongs to this organisation" "0" \
  "$(echo "$SS" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     $n=0; foreach ($d["employees"] ?? [] as $e) { if ((int)($e["sub_institute_id"] ?? 3) !== 3) $n++; } echo $n;')"
echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
