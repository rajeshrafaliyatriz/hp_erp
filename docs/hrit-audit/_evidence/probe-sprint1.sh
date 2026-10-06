#!/usr/bin/env bash
# Sprint 1 verification. Refusals AND the positive paths - a gate that also
# blocks the people who need the screen is not a fix.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
probe() { local want="$1" label="$2" method="$3" url="$4"
  local body code mark
  body=$(curl -s -m 120 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' "$url")
  code=$(printf '%s' "$body" | tail -1)
  if [ "$code" = "$want" ]; then mark="PASS"; else mark="FAIL"; fi
  printf '%-4s want=%-3s got=%-3s  %-46s %s\n' "$mark" "$want" "$code" "$label" \
    "$(printf '%s' "$body" | head -c 120 | tr -d '\n' | cut -c1-95)"
}
EMP3=$(T 3 employee); ADM3=$(T 3 administrator); HRM3=$(T 3 hr_manager)
AUD3=$(T 3 auditor); REC3=$(T 3 recruiter); DH3=$(T 3 department_head); RM3=$(T 3 reporting_manager)

echo "===== F-91: payroll must refuse everyone except admin/HR ====="
probe 403 "employee  -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$EMP3&sub_institute_id=3&user_id=7&syear=2026"
probe 403 "auditor   -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$AUD3&sub_institute_id=3&user_id=590&syear=2026"
probe 403 "recruiter -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$REC3&sub_institute_id=3&user_id=588&syear=2026"
probe 403 "dept_head -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$DH3&sub_institute_id=3&user_id=580&syear=2026"
probe 403 "rep_mgr   -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$RM3&sub_institute_id=3&user_id=581&syear=2026"
probe 403 "employee  -> /payroll-type"              GET "$BASE/payroll-type?type=API&token=$EMP3&sub_institute_id=3&user_id=7"
probe 403 "employee  -> /monthly-payroll/create"    GET "$BASE/monthly-payroll/create?type=API&token=$EMP3&sub_institute_id=3&user_id=7&month=Apr&year=2026"

echo "===== ...and must still SERVE admin and HR ====="
probe 200 "admin     -> /employee-salary-structure" GET "$BASE/employee-salary-structure?type=API&token=$ADM3&sub_institute_id=3&user_id=6&syear=2026"
probe 200 "hr_mgr    -> /payroll-type"              GET "$BASE/payroll-type?type=API&token=$HRM3&sub_institute_id=3&user_id=67"
probe 200 "hr_mgr    -> /hrms-salary-certificate"   GET "$BASE/hrms-salary-certificate?type=API&token=$HRM3&sub_institute_id=3&user_id=67"

echo "===== F-93: Monthly Payroll Report must OPEN (was 500 for everyone) ====="
probe 200 "admin     -> /monthly-payroll/create"    GET "$BASE/monthly-payroll/create?type=API&token=$ADM3&sub_institute_id=3&user_id=6&month=Apr&year=2026"
probe 200 "hr_mgr    -> /monthly-payroll/create"    GET "$BASE/monthly-payroll/create?type=API&token=$HRM3&sub_institute_id=3&user_id=67&month=Apr&year=2026"

echo "===== F-100: the sample-data routes are gone ====="
probe 404 "employee  -> /hrms/myleave/7"            GET "$BASE/hrms/myleave/7?type=API&token=$EMP3&sub_institute_id=3&user_id=7"
probe 404 "employee  -> /hrms/leavehistory/7"       GET "$BASE/hrms/leavehistory/7?type=API&token=$EMP3&sub_institute_id=3&user_id=7"

echo "===== F-92: no password hashes anywhere in a payroll response ====="
for r in administrator hr_manager; do
  n=$(curl -s -m 120 "$BASE/employee-salary-structure?type=API&token=$(T 3 $r)&syear=2026" | grep -o '"password"' | wc -l)
  o=$(curl -s -m 120 "$BASE/employee-salary-structure?type=API&token=$(T 3 $r)&syear=2026" | grep -o '"otp"' | wc -l)
  printf '%-4s %-46s password keys=%s  otp keys=%s\n' "$([ "$n" -eq 0 ] && [ "$o" -eq 0 ] && echo PASS || echo FAIL)" "$r salary structure body" "$n" "$o"
done
