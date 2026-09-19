#!/usr/bin/env bash
# STAGE 2b - F-166. Bank-wise Payment Advice, end to end.
#
#   bash Docs/hrit-audit/_evidence/probe-stage2b.sh
#
# POST /payroll-bank-wise-report was implemented, routed, gated behind
# hrit.role:admin,hr and returning clean JSON, with NO CALLER anywhere in the
# frontend. It is the only report that produces what finance needs to move the
# money: for a month, every employee actually paid, with bank_name, account_no
# and ifsc_code.
#
# This screen is about payment instructions, so the assertions are about who may
# read them, which organisation's they are, and whether the shape the UI depends
# on is really there.
#
# Read-only. Creates nothing.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)   # user 6,  tenant 3 - has the one 2025 payslip
H3=$(tok 3 hr_manager)      # user 67, tenant 3
E3=$(tok 3 employee)        # user 7,  tenant 3 - must be refused
A6=$(tok 6 administrator)   # user 28, tenant 6 - must not see tenant 3's

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

# Legacy hrms.php routes take identity as query params under type=API.
advice() { local t=$1 tid=$2 uid=$3 mon=$4 yr=$5
  curl -s -m 60 -X POST "$BASE/payroll-bank-wise-report?type=API&token=$t&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -d "month=$mon&year=$yr"; }

acode() { local t=$1 tid=$2 uid=$3
  curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
    "$BASE/payroll-bank-wise-report?type=API&token=$t&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d 'month=Aug&year=2025'; }

echo
echo "================ Stage 2b - Bank-wise Payment Advice ================"
echo
echo "1. Who may read the payment instructions"
check "administrator -> 200" "200" "$(acode "$A3" 3 6)"
check "hr_manager    -> 200" "200" "$(acode "$H3" 3 67)"
# F-91: a React component must never be the only thing saying no.
check "employee      -> 403" "403" "$(acode "$E3" 3 7)"

echo
echo "2. The shape the screen depends on"
# Aug 2025 is the only month with a payslip on this deployment - the six filed
# payslips are all 2025 except one, which is Jul 2026. Asserting against a month
# that HAS data is the point: a probe that only ever sees an empty list cannot
# tell a working filter from a broken one.
R=$(advice "$A3" 3 6 Aug 2025)
n=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["employees"]??null)?count($d["employees"]):"err";')
check "Aug 2025 returns the filed payslip" "1" "$n"

for field in bank_name account_no ifsc_code employee_no first_name; do
  has=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    $e=$d["employees"][0]??null; $u=(array)($e["usersDetails"]??[]);
    echo array_key_exists($argv[1],$u) && trim((string)$u[$argv[1]])!=="" ? "yes" : "no";' "$field")
  check "  usersDetails carries $field" "yes" "$has"
done

pay=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["employees"][0]["total_payment"]??"";')
check "  and the net pay it is paying" "81300.00" "$pay"

echo
echo "3. A month with no payroll is EMPTY, not an error"
# The screen renders this as "No payroll was generated for ...", which is only
# honest if the endpoint really did answer. An error here would be rendered as
# an error instead - that distinction is the whole point of the empty/error
# split on this screen.
n=$(advice "$A3" 3 6 Feb 2024 | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["employees"]??null)?count($d["employees"]):"err";')
check "Feb 2024 answers with an empty list" "0" "$n"

echo
echo "4. It is one organisation's payroll, not the platform's"
# Tenant 6 has no payslips at all. If the tenant clause were missing it would
# see tenant 3's - which is somebody else's salary and bank account.
n=$(advice "$A6" 6 28 Aug 2025 | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["employees"]??null)?count($d["employees"]):"err";')
check "tenant 6 sees none of tenant 3's payslips" "0" "$n"

# And the tenant is taken from the TOKEN, not the query string. A tenant 6
# admin naming tenant 3 must still get tenant 6's answer.
n=$(advice "$A6" 3 28 Aug 2025 | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["employees"]??null)?count($d["employees"]):"err";')
check "  even when the request claims sub_institute_id=3" "0" "$n"

echo
echo "================ F-168/F-169 - Employee Payroll History ================"

hist() { local t=$1 tid=$2 uid=$3 yr=$4 emp=${5:-0}
  curl -s -m 60 -X POST "$BASE/employee-payroll-history?type=API&token=$t&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -d "year=$yr&emp_id=$emp&department_id=0"; }

hcode() { curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
    "$BASE/employee-payroll-history?type=API&token=$1&sub_institute_id=$2&user_id=$3" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d 'year=2025-2026&emp_id=0&department_id=0'; }

echo
echo "5. Who may read it"
check "administrator -> 200" "200" "$(hcode "$A3" 3 6)"
check "employee      -> 403" "403" "$(hcode "$E3" 3 7)"

echo
echo "6. F-168 - the employee id is the PAYSLIP's, not tbluser's staff code"
# tbluser has its own `employee_id` column. With no select on the join it
# shadowed employee_monthly_salary_data.employee_id, so payslip 22 - which
# belongs to employee 6 - was reported as employee_id 11 (that person's staff
# code). Anything following that id lands on the wrong person.
H=$(hist "$A3" 3 6 2025-2026)
eid=$(echo "$H" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["currentYearemployeeDetails"][0]["employee_id"] ?? "";')
check "reports the payslip's employee_id" "6" "$eid"
# Both are present and they are DIFFERENT values - which is the whole reason the
# shadowing was invisible until someone compared them.
eno=$(echo "$H" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["currentYearemployeeDetails"][0]["employee_no"] ?? "";')
check "  and still carries the staff code separately" "EMP001" "$eno"

echo
echo "7. F-169 - the screen must not silently drop money"
# `header` is built from payroll_types WHERE status = 1. A filed payslip stores
# amounts against whatever head ids were used at the time. On payslip 22 only
# 6,205 of 81,300 sits on a live head of its own tenant: 35,000 is on
# soft-deleted heads and 52,500 on TENANT 1's. A table rendering only the named
# columns would lose three quarters of the payslip, so the screen renders the
# union and flags what it cannot name. These assertions pin the gap that makes
# that necessary - if it ever closes, the UI's warning should go with it.
named=$(echo "$H" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $h=$d["header"]??[]; echo is_array($h)?count($h):0;')
used=$(echo "$H" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $r=$d["currentYearemployeeDetails"][0]["data"]??[]; echo is_array($r)?count($r):0;')
check "the payslip uses more heads than the active list names ($used used, $named named)" "yes" \
      "$([ "$used" -gt "$named" ] 2>/dev/null && echo yes || echo no)"

unnamed=$(echo "$H" | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
$h=(array)($d["header"]??[]); $r=(array)($d["currentYearemployeeDetails"][0]["data"]??[]);
$n=0; foreach($r as $id=>$amt){ if(!array_key_exists((string)$id,$h)) $n++; } echo $n;')
check "  and some of them cannot be named at all" "yes" \
      "$([ "$unnamed" -gt 0 ] 2>/dev/null && echo yes || echo no)"

# The number the UI is protecting: money booked against heads it cannot name.
#
#   head  1   50,000   belongs to TENANT 1        not in header
#   head  2    5,000   soft-deleted, status 0     not in header
#   head  3   20,000   soft-deleted, status 0     not in header
#   head  5    2,500   belongs to TENANT 1        not in header
#   heads 6-8      0   belong to TENANT 1         not in header
#                 ---
#               77,500  of a stated net of 81,300
#
# Head 4 (10,000) is ALSO soft-deleted but still carries status = 1, so it does
# appear in `header` and is not counted here - which is the Q10 case showing up
# in a report rather than a structure.
hidden=$(echo "$H" | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
$h=(array)($d["header"]??[]); $r=(array)($d["currentYearemployeeDetails"][0]["data"]??[]);
$s=0; foreach($r as $id=>$amt){ if(!array_key_exists((string)$id,$h)) $s+=(float)$amt; } echo (int)$s;')
check "  a header-only table would hide 77,500 of this payslip" "77500" "$hidden"

echo
echo "8. Still one organisation's payroll"
n=$(hist "$A6" 6 28 2025-2026 | php -r '$d=json_decode(stream_get_contents(STDIN),true); $r=$d["currentYearemployeeDetails"]??[]; echo is_array($r)?count($r):"err";')
check "tenant 6 sees none of tenant 3's history" "0" "$n"


echo
echo "================ F-172 - Payroll Register ================"

reg() { local t=$1 tid=$2 uid=$3 mon=$4 yr=$5
  curl -s -m 60 -X POST "$BASE/payroll-report?type=API&token=$t&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -d "month=$mon&year=$yr&department_id=0"; }

rcode() { curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
    "$BASE/payroll-report?type=API&token=$1&sub_institute_id=$2&user_id=$3" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d 'month=Aug&year=2025&department_id=0'; }

rows_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["employeeDetails"]??null)?count($d["employeeDetails"]):"err";'; }

echo
echo "9. Who may read it"
check "administrator -> 200" "200" "$(rcode "$A3" 3 6)"
check "employee      -> 403" "403" "$(rcode "$E3" 3 7)"

echo
echo "10. The reconciliation the screen exists for"
R=$(reg "$A3" 3 6 Aug 2025)
check "Aug 2025 returns the filed payslip" "1" "$(echo "$R" | rows_)"
for field in lwp_days leave_days absent_days full_name employee_no; do
  has=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    $r=(array)($d["employeeDetails"][0]??[]); echo array_key_exists($argv[1],$r) ? "yes" : "no";' "$field")
  check "  the row carries $field" "yes" "$has"
done

echo
echo "11. F-172 - the payslip id is not overwritten by the user id"
# selectRaw listed `u.id` AFTER employee_monthly_salary_data.*, so the payslip's
# own id was replaced by the joined user id: the Aug 2025 row is payslip 22 and
# this reported 6. Aliased to user_id, so both are available and neither lies.
pid=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["employeeDetails"][0]["id"] ?? "";')
uid_=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["employeeDetails"][0]["user_id"] ?? "";')
check "id is the payslip's own" "22" "$pid"
check "  and the joined user id is still there, named" "6" "$uid_"


echo
echo "12. F-172 - the year is a KEYED map, and the key is what the query wants"
# Helpers::getPairYears() returns {'2025': '2025-2026', ...}. The KEY is the
# value the query needs; the pair is only a label, which is why the Blade
# dropdown has always been <option value="2025">2025-2026</option>.
#
# The first version of this probe assumed the PAIR was the value and asserted
# that sending it returned an empty register. It does not - it 500s with
# Carbon's "Trailing data". That is worse than empty, and it is exactly what the
# frontend would have produced had the hook kept flattening the keyed map with
# Object.values(). Both halves are pinned here.
keys=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $y=(array)($d["years"]??[]); echo implode(",", array_keys($y));')
labels=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $y=(array)($d["years"]??[]); echo implode(",", array_values($y));')
check "years is keyed by single year, not a flat list (keys: $keys)" "yes" \
      "$(case ",$keys," in *,0,*) echo no;; ,,) echo no;; *) echo yes;; esac)"
check "  and its labels are the financial-year pairs" "yes" \
      "$(case "$labels" in *-*) echo yes;; *) echo no;; esac)"

label=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $y=array_values((array)($d["years"]??[])); echo end($y) ?: "";')
key=$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $y=array_keys((array)($d["years"]??[])); echo end($y) ?: "";')
c=$(curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
    "$BASE/payroll-report?type=API&token=$A3&sub_institute_id=3&user_id=6" \
    -H "Authorization: Bearer $A3" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d "month=Aug&year=$label&department_id=0")
check "  posting the LABEL ($label) is a 500, not an empty register" "500" "$c"
check "  posting the KEY ($key) returns the register" "1" "$(reg "$A3" 3 6 Aug "$key" | rows_)"
echo
echo "13. Still one organisation's payroll"
check "tenant 6 sees none of tenant 3's register" "0" "$(reg "$A6" 6 28 Aug 2025 | rows_)"

echo
echo "================ F-160 - Salary Structure Report ================"

ssr() { local t=$1 tid=$2 uid=$3 yr=${4:-0}
  curl -s -m 60 -X POST "$BASE/salary-structure-report?type=API&token=$t&sub_institute_id=$tid&user_id=$uid" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -d "year=$yr&emp_id=0&department_id=0"; }

scode() { curl -s -m 60 -o /dev/null -w '%{http_code}' -X POST \
    "$BASE/salary-structure-report?type=API&token=$1&sub_institute_id=$2&user_id=$3" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' -d 'year=0&emp_id=0&department_id=0'; }

scount() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["salaryStructure"]??null)?count($d["salaryStructure"]):"err";'; }

echo
echo "14. Who may read it"
check "administrator -> 200" "200" "$(scode "$A3" 3 6)"
check "employee      -> 403" "403" "$(scode "$E3" 3 7)"

echo
echo "15. F-160 - it resolves a tenant, so it can actually feed a screen"
# Before the fix this read session() with no type=API branch: tenant null, every
# `where sub_institute_id = null` matched nothing, and the report came back
# empty however it was filtered. A row count > 0 is the regression guard - an
# assertion on the KEY alone would pass just as happily with the bug back.
S=$(ssr "$A3" 3 6 0)
n=$(echo "$S" | scount)
check "returns this organisation's structures (got $n)" "yes" \
      "$([ "$n" != "err" ] && [ "$n" -gt 0 ] 2>/dev/null && echo yes || echo no)"
check "  and names the active pay heads" "yes" \
      "$(echo "$S" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["headers"]) ? "yes" : "no";')"
check "  every row belongs to the caller's organisation" "yes" \
      "$(echo "$S" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
        foreach($d["salaryStructure"]??[] as $r){ if((int)($r["sub_institute_id"]??0)!==3){ echo "no"; exit; } } echo "yes";')"

echo
echo "16. Still one organisation's structures"
check "tenant 6 sees none of tenant 3's structures" "0" "$(ssr "$A6" 6 28 0 | scount)"
echo
echo "  ---------------------------------------------"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
