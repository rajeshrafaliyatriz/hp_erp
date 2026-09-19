#!/usr/bin/env bash
# THE RESOLUTION FLOWS, PROVEN IN TENANT 6.
#
#   bash Docs/hrit-audit/_evidence/probe-resolution-flows.sh
#
# WHY THIS EXISTS, AND WHY IN TENANT 6
#
# Phase 15 surfaced three problems and built the tooling to resolve them, but
# only the REFUSAL paths were ever proven:
#
#   probe-stage1  proved re-dating an adjustment to "8" is refused
#   probe-stage1  proved a Save that drops a head deletes money, and that
#                 carrying it through preserves it
#
# What was never proven is that a SUCCESSFUL resolution actually works - that
# re-dating an orphan to a real month takes it off the list and files it where
# payroll will find it, and that removing one removes it. A repair path that has
# only ever been tested by watching it refuse is not a tested repair path.
#
# The real orphans live in tenant 3 - "healthcare", 122 active users, somebody
# else's live payroll. They are not a test fixture and this probe does not touch
# them. Tenant 6 is Scholar Clone, has ZERO payroll data of its own, and is the
# right place to prove the machinery.
#
# Everything is marked by a dedicated probe PAY HEAD, so teardown is by marker
# and cannot be malformed (F-157). Nothing pre-existing is read or written.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A6=$(tok 6 administrator)   # user 28
E6=$(tok 6 employee)        # user 63 - must be refused

TENANT=6
SUBJECT=29                  # a real tenant-6 employee
SCRATCH_YEAR=2099           # cannot collide with anything real
HEAD_NAME='ZZPROBE Resolution Head'

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

sql() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

orphans() {   # the list the panel renders
  curl -s -m 60 -G "$BASE/payroll-deduction/orphans?type=API&token=$A6&sub_institute_id=$TENANT&user_id=28" \
    -H 'Accept: application/json'; }

resolve() {   # resolve <id> <action> [month]
  curl -s -m 60 -X POST \
    "$BASE/payroll-deduction/orphans/resolve?type=API&token=$A6&sub_institute_id=$TENANT&user_id=28&id=$1&action=$2${3:+&month=$3}" \
    -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded'; }

HEAD_ID=""

teardown() {
  # By marker: the probe's own pay head. Adjustments reference it, so they go
  # first. A marker cannot come back empty the way an id list can.
  hid=$(sql "select id from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | jq_ id)
  if [ -n "$hid" ]; then
    sql "delete from hrms_emp_payroll_deduction where sub_institute_id=$TENANT and deduction_type=$hid" >/dev/null
    sql "delete from payroll_types where id=$hid" >/dev/null
  fi
  sql "delete from employee_salary_structures where sub_institute_id=$TENANT and year=$SCRATCH_YEAR" >/dev/null
}

echo
echo "================ Resolution flows, tenant 6 ================"
trap teardown EXIT
teardown   # start-of-run clear (F-163)

# A pay head of our own, INACTIVE - so the salary-structure grid will not render
# it, which is the condition F-174 is about.
sql "insert into payroll_types
      (payroll_name, payroll_type, amount_type, payroll_percentage, day_count, status, sort_order, sub_institute_id, created_at)
     values ('$HEAD_NAME', 2, 1, 0, 0, 0, 99, $TENANT, now())" >/dev/null
HEAD_ID=$(sql "select id from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | jq_ id)
check "probe pay head created (inactive, so the grid will not render it)" "yes" \
      "$([ -n "$HEAD_ID" ] && echo yes || echo no)"

echo
echo "1. An orphan appears, exactly as healthcare's eleven do"
# month "8" - the same shape as the real ones: a number where a name belongs.
sql "insert into hrms_emp_payroll_deduction
      (month, year, employee_id, deduction_type, deduction_amount, sub_institute_id, created_at)
     values ('8', $SCRATCH_YEAR, $SUBJECT, $HEAD_ID, 4321, $TENANT, now())" >/dev/null

N=$(orphans | jq_ total)
check "the panel reports the amount payroll is skipping" "4321" "$N"
ID=$(orphans | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["orphans"][0]["id"] ?? "";')
check "  and names the row" "yes" "$([ -n "$ID" ] && echo yes || echo no)"
RAW=$(orphans | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["orphans"][0]["month"] ?? "";')
check "  reporting its month VERBATIM, not interpreted" "8" "$RAW"
ENTERED=$(orphans | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo substr((string)($d["orphans"][0]["created_at"] ?? ""),0,10);')
check "  and when it was entered - the evidence that settles it" "$(date +%Y-%m-%d)" "$ENTERED"

echo
echo "2. Re-dating it to a real month FILES it - the path never proven before"
MSG=$(resolve "$ID" set-month Aug | jq_ message)
check "the server confirms where it went" "Filed under Aug $SCRATCH_YEAR. It will be applied the next time that month is generated." "$MSG"
check "  it is off the orphan list" "0" "$(orphans | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["orphans"]??null)?count($d["orphans"]):"err";')"
STORED=$(sql "select month m from hrms_emp_payroll_deduction where id=$ID" | jq_ m)
check "  and stored as a month payroll can match" "Aug" "$STORED"

echo
echo "3. The clash guard - two adjustments cannot occupy the same slot"
sql "insert into hrms_emp_payroll_deduction
      (month, year, employee_id, deduction_type, deduction_amount, sub_institute_id, created_at)
     values ('3', $SCRATCH_YEAR, $SUBJECT, $HEAD_ID, 999, $TENANT, now())" >/dev/null
ID2=$(sql "select id from hrms_emp_payroll_deduction where sub_institute_id=$TENANT and deduction_type=$HEAD_ID and month='3'" | jq_ id)
MSG=$(resolve "$ID2" set-month Aug | jq_ message)
check "re-dating onto an occupied slot is refused, with the reason" \
      "There is already an adjustment for that employee, head and month. Remove one of them, or choose a different month." "$MSG"
check "  and the row is unchanged" "3" "$(sql "select month m from hrms_emp_payroll_deduction where id=$ID2" | jq_ m)"

echo
echo "4. Removing one removes it - the other path never proven"
MSG=$(resolve "$ID2" delete | jq_ message)
check "the server says what happened" "Adjustment removed. It was never applied to a payslip." "$MSG"
check "  it is soft-deleted, not destroyed" "1" \
      "$(sql "select count(*) c from hrms_emp_payroll_deduction where id=$ID2 and deleted_at is not null" | jq_ c)"
check "  and gone from the list" "0" "$(orphans | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["orphans"]??null)?count($d["orphans"]):"err";')"

echo
echo "5. Only HR and admin may touch any of this"
c=$(curl -s -m 60 -o /dev/null -w '%{http_code}' -G "$BASE/payroll-deduction/orphans?type=API&token=$E6&sub_institute_id=$TENANT&user_id=63" -H 'Accept: application/json')
check "an employee cannot read the list" "403" "$c"
c=$(curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
  "$BASE/payroll-deduction/orphans/resolve?type=API&token=$E6&sub_institute_id=$TENANT&user_id=63&id=$ID&action=delete" \
  -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded')
check "  nor resolve one" "403" "$c"

echo
echo "6. F-174 in tenant 6: a Save must not delete a head the grid cannot show"
sql "insert into employee_salary_structures
      (employee_id, employee_salary_data, year, sub_institute_id, created_at, updated_at)
     values ($SUBJECT, '{\"13\":20000,\"$HEAD_ID\":7777}', $SCRATCH_YEAR, $TENANT, now(), now())" >/dev/null

amount_on() {
  sql "select employee_salary_data d from employee_salary_structures
        where employee_id=$SUBJECT and year=$SCRATCH_YEAR and sub_institute_id=$TENANT" \
  | php -r '$r=json_decode(stream_get_contents(STDIN),true); $data=json_decode($r["d"] ?? "{}", true) ?: [];
      echo array_key_exists($argv[1], $data) ? (string) $data[$argv[1]] : "ABSENT";' "$1"; }

check "seeded on a head the grid renders" "20000" "$(amount_on 13)"
check "  and one it does not (inactive)" "7777" "$(amount_on "$HEAD_ID")"

# Exactly what the FIXED frontend posts: the rendered head, plus the carried one.
curl -s -m 60 -X POST "$BASE/employee-salary-structure/store?type=API&token=$A6&sub_institute_id=$TENANT&user_id=28&syear=$SCRATCH_YEAR" \
  -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "emp[$SUBJECT][0]=M" \
  -d "emp[$SUBJECT][13][0]=13" -d "emp[$SUBJECT][13][1]=20000" -d "emp[$SUBJECT][13][2]=Basic" -d "emp[$SUBJECT][13][3]=1" \
  -d "emp[$SUBJECT][$HEAD_ID][0]=$HEAD_ID" -d "emp[$SUBJECT][$HEAD_ID][1]=7777" -d "emp[$SUBJECT][$HEAD_ID][2]=head-$HEAD_ID" -d "emp[$SUBJECT][$HEAD_ID][3]=0" >/dev/null

check "after a Save, the unrendered amount survives" "7777" "$(amount_on "$HEAD_ID")"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left in tenant 6" "0" \
      "$(sql "select count(*) c from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | jq_ c)"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
