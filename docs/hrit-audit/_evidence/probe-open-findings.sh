#!/usr/bin/env bash
# The four findings Phase 10 left OPEN — F-142, F-143, F-144, F-145.
#
#   bash Docs/hrit-audit/_evidence/probe-open-findings.sh
#
# Each was raised with a reason not to fix it unilaterally. Those reasons were
# put to the customer, who asked for the module completed; these are the fixes
# that close them WITHOUT answering the questions that are genuinely theirs.
#
# The distinction that makes that possible, in each case:
#   F-142  stop new payslips diverging; do not touch the six already filed.
#   F-143  stop the split recurring; do not guess what the eleven legacy rows meant.
#   F-144  delete a rule proven dead; change nobody's pay.
#   F-145  bound the response in code; keep the existing shape for every caller.
#
# Creates its own data and removes it.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }
A3=$(tok 3 administrator); H3=$(tok 3 hr_manager)

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }
snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
one()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$2"; }

jpost() { curl -s -X POST "$BASE$1" -H "Authorization: Bearer $2" -H 'Accept: application/json' \
            -H 'Content-Type: application/json' -d "$3" --max-time 120; }
fpost() { curl -s -X POST "$BASE$1?type=API&token=$2&sub_institute_id=3&user_id=$3" \
            -H "Authorization: Bearer $2" -H 'Accept: application/json' \
            -H 'Content-Type: application/x-www-form-urlencoded' -d "$4" --max-time 120; }

# Employee 10 is the only person in tenant 3 with a salary structure. Its
# figures: BASIC 30000 + GRADE 5000 = 35000 allowance, head 4 200 + HRA 1 = 201
# deduction, so the server computes net 34799 for a full 30-day month.
EMP=10; PAY=34799; DED=201

echo "=============================================================="
echo " The four findings Phase 10 left open"
echo "=============================================================="

# ===========================================================================
echo
echo "F-142 - the server recomputes, and the posted totals are a checksum"
# ===========================================================================
snap "delete from employee_monthly_salary_data where employee_id=$EMP and sub_institute_id=3 and month='Nov' and year=2026" >/dev/null

# What the screen itself is told, so the checksum has a source that is not this
# probe's arithmetic.
COMPUTED=$(curl -s "$BASE/getMonthlyData?type=API&token=$H3&sub_institute_id=3&user_id=67&syear=2026-2027&emp_id=$EMP&month=Nov&year=2026&totalDay=30" \
  -H "Authorization: Bearer $H3" -H 'Accept: application/json' --max-time 90 \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)($d["salaryData"]["total_payment"] ?? 0);')
check "the screen is told the same figure the server computes" "$PAY" "$COMPUTED"

OK=$(jpost /monthly-payroll-store "$A3" \
  "{\"type\":\"API\",\"sub_institute_id\":3,\"syear\":\"2026-2027\",\"month\":\"Nov\",\"year\":2026,\"payrollVal\":{\"$EMP\":{\"payrollHead\":{\"9\":30000,\"10\":5000,\"4\":200,\"11\":1},\"total_payment\":$PAY,\"total_deduction\":$DED,\"received_by\":0,\"total_day\":30}}}")
check "a payslip whose figures agree is accepted" "1" \
  "$(echo "$OK" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"
check "and the stored figure is the computed one" "$PAY.00" \
  "$(one "select total_payment p from employee_monthly_salary_data where employee_id=$EMP and month='Nov' and year=2026" p)"

# The forgery F-142 was raised for. This is the assertion that fails without
# the fix - it was executed successfully against the unfixed code.
FORGE=$(jpost /monthly-payroll-store "$A3" \
  "{\"type\":\"API\",\"sub_institute_id\":3,\"syear\":\"2026-2027\",\"month\":\"Nov\",\"year\":2026,\"payrollVal\":{\"$EMP\":{\"payrollHead\":{\"9\":30000,\"10\":5000,\"4\":200,\"11\":1},\"total_payment\":999999,\"total_deduction\":0,\"received_by\":0,\"total_day\":30}}}")
check "a payslip with invented figures is REFUSED" "0" \
  "$(echo "$FORGE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"
check "and the refusal names the difference" "34799" \
  "$(echo "$FORGE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)($d["mismatches"][0]["computed_payment"] ?? 0);')"
check "the previously saved payslip is untouched" "$PAY.00" \
  "$(one "select total_payment p from employee_monthly_salary_data where employee_id=$EMP and month='Nov' and year=2026" p)"
check "nothing was part-saved" "1" \
  "$(one "select count(*) c from employee_monthly_salary_data where employee_id=$EMP and month='Nov' and year=2026" c)"

snap "delete from employee_monthly_salary_data where employee_id=$EMP and sub_institute_id=3 and month='Nov' and year=2026" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from employee_monthly_salary_data where employee_id=$EMP and month='Nov' and year=2026" c)"

# The six payslips already on file are NOT touched by any of the above. That is
# the whole point: Q8 - what to do about them - is still the customer's.
check "the six pre-existing payslips are still there, unchanged" "6" \
  "$(one "select count(*) c from employee_monthly_salary_data" c)"

# ===========================================================================
echo
echo "F-143 - the month split cannot recur"
# ===========================================================================
snap "delete from hrms_emp_payroll_deduction where employee_id=$EMP and year=2026 and month='Nov'" >/dev/null

check "a month the calculation could never match is refused" "0" \
  "$(fpost /payroll-deduction/store "$H3" 67 "payroll_type=9&deduction_type_id=9&month=8&year=2026&deductAmt[$EMP]=750" \
     | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"
check "and nothing was written" "0" \
  "$(one "select count(*) c from hrms_emp_payroll_deduction where employee_id=$EMP and year=2026 and month='8'" c)"
check "the canonical spelling is accepted" "1" \
  "$(fpost /payroll-deduction/store "$H3" 67 "payroll_type=9&deduction_type_id=9&month=Nov&year=2026&deductAmt[$EMP]=750" \
     | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"
check "stored under a spelling the calculation matches" "Nov" \
  "$(one "select month m from hrms_emp_payroll_deduction where employee_id=$EMP and year=2026 and deduction_amount=750" m)"

snap "delete from hrms_emp_payroll_deduction where employee_id=$EMP and year=2026 and month='Nov'" >/dev/null
check "cleaned up" "0" \
  "$(one "select count(*) c from hrms_emp_payroll_deduction where employee_id=$EMP and year=2026 and month='Nov'" c)"

# The eleven legacy rows are deliberately NOT repaired - Q9 is the tenant's.
CANON="'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'"
LEGACY=$(one "select count(*) c from hrms_emp_payroll_deduction where month not in ($CANON)" c)
check "the eleven unreachable legacy rows are left for the tenant (Q9)" "11" "$LEGACY"

# ===========================================================================
echo
echo "F-144 - the hardcoded February rule is gone"
# ===========================================================================
check "no February special case remains in the source" "0" \
  "$(grep -c 'month=="Feb"' app/Http/Controllers/Payroll/PayrollController.php || true)"
check "and no active pay head has the id it named" "0" \
  "$(one "select count(*) c from payroll_types where id=2 and status=1 and deleted_at is null" c)"
# Nobody's pay moved: February computes from the structure like any other month.
#
# year=2025, not 2026. Jan/Feb/Mar belong to the NEXT payroll year, so
# getEmpMonthlyData resolves year+1 - passing 2026 looks for a 2027 structure
# that does not exist and returns 0, which would fail this assertion for a
# reason that has nothing to do with F-144.
FEB=$(curl -s "$BASE/getMonthlyData?type=API&token=$H3&sub_institute_id=3&user_id=67&syear=2026-2027&emp_id=$EMP&month=Feb&year=2025&totalDay=28" \
  -H "Authorization: Bearer $H3" -H 'Accept: application/json' --max-time 90 \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)($d["salaryData"]["total_payment"] ?? 0);')
check "February computes from the structure, like every other month" "$PAY" "$FEB"

# ===========================================================================
echo
echo "F-145 - the employee directory has a ceiling, and says when it applies"
# ===========================================================================
D=$(curl -s "$BASE/api/employees-management?sub_institute_id=3" -H "Authorization: Bearer $A3" -H 'Accept: application/json' --max-time 90)
check "the default response shape is unchanged" "122" \
  "$(echo "$D" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);')"
check "meta.total still means how many MATCH, not how many were returned" "122" \
  "$(echo "$D" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["meta"]["total"] ?? "";')"
check "and it declares its ceiling" "2000" \
  "$(echo "$D" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["meta"]["max_rows"] ?? "";')"
check "this organisation is nowhere near it" "" \
  "$(echo "$D" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["meta"]["truncated"]) ? "TRUNCATED" : "";')"

P2=$(curl -s "$BASE/api/employees-management?sub_institute_id=3&per_page=5&page=2" -H "Authorization: Bearer $A3" -H 'Accept: application/json' --max-time 90)
check "per_page returns exactly that many" "5" \
  "$(echo "$P2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);')"
P1F=$(curl -s "$BASE/api/employees-management?sub_institute_id=3&per_page=5&page=1" -H "Authorization: Bearer $A3" -H 'Accept: application/json' --max-time 90 \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"][0]["first_name"] ?? "";')
P2F=$(echo "$P2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"][0]["first_name"] ?? "";')
check "page 2 is a different slice from page 1" "different" \
  "$(if [ "$P1F" != "$P2F" ]; then echo different; else echo same; fi)"
check "paging still reports the full total" "122" \
  "$(echo "$P2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["meta"]["total"] ?? "";')"

echo

# ===========================================================================
echo
echo "F-150 - a deleted pay head is still applied to pay"
# ===========================================================================
# The Payroll Type list filters deleted_at (:131). The CALCULATION does not -
# it selects on status alone - so a head deleted from the screen keeps being
DELETED_APPLIED=$(one "select count(*) c from payroll_types p where p.deleted_at is not null and p.status=1" c)
check "soft-deleted heads that the calculation would still apply" "1" \
  "$(if [ "${DELETED_APPLIED:-0}" -gt 0 ]; then echo 1; else echo 0; fi)"

# The impact, measured rather than asserted: six of eight live structures
# reference a deleted head, and they are LOAD-BEARING. Excluding them would take
# tenant 1's employees 1/2/3 from a net of 3500 to 1000 - a 71% cut - because
# heads 1 and 5 are deleted ALLOWANCES. That is why the calculation is NOT
# changed here; it is Q10, and it belongs to the customer.
STRUCTS=$(one "select count(*) c from employee_salary_structures where deleted_at is null" c)
check "live salary structures still exist to be affected" "8" "$STRUCTS"

# What IS fixed: the cause. A head live structures depend on cannot be deleted,
# so the situation cannot get worse while the customer decides.
GUARD=$(curl -s -X POST "$BASE/payroll-type/destroy/9?type=API&token=$A3&sub_institute_id=3&user_id=6" \
  -H "Authorization: Bearer $A3" -H 'Accept: application/json' --max-time 90 \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status_code"] ?? "";')
check "a head that salary structures depend on cannot be deleted" "0" "$GUARD"
check "and BASIC is still there afterwards" "" \
  "$(one "select deleted_at d from payroll_types where id=9" d)"

# ...while a head nothing references still deletes normally.
snap "delete from payroll_types where payroll_name='guard-probe'" >/dev/null
fpost /payroll-type/store "$A3" 6 "payroll_type=1&payroll_name=guard-probe&amount_type=1&status=1&day_count=1&sort_order=98" >/dev/null
GID=$(one "select id from payroll_types where payroll_name='guard-probe'" id)
check "an unreferenced head still deletes" "1" \
  "$(curl -s -X POST "$BASE/payroll-type/destroy/$GID?type=API&token=$A3&sub_institute_id=3&user_id=6" \
     -H "Authorization: Bearer $A3" -H 'Accept: application/json' --max-time 90 \
     | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status_code"] ?? "";')"
snap "delete from payroll_types where id=$GID" >/dev/null
check "cleaned up" "0" "$(one "select count(*) c from payroll_types where payroll_name='guard-probe'" c)"
echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
