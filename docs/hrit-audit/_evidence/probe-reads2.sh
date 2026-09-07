#!/usr/bin/env bash
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
probe() { local label="$1" method="$2" url="$3"
  local body code
  body=$(curl -s -m 30 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' "$url")
  code=$(printf '%s' "$body" | tail -1)
  printf '%-56s %s  %s\n' "$label" "$code" "$(printf '%s' "$body" | head -c 220 | tr -d '\n' | cut -c1-170)"
}
EMP3=$(T 3 employee); ADM3=$(T 3 administrator); HRM3=$(T 3 hr_manager); DH3=$(T 3 department_head); RM3=$(T 3 reporting_manager); REC3=$(T 3 recruiter)

echo "===== F. ATTENDANCE API ====="
probe "emp GET /api/attendance/my-attendance"   GET "$BASE/api/attendance/my-attendance?token=$EMP3&from_date=2026-04-01&to_date=2026-04-30"
probe "emp GET /api/attendance/kpi"             GET "$BASE/api/attendance/kpi?token=$EMP3"
probe "emp GET /api/attendance/weekly-summary"  GET "$BASE/api/attendance/weekly-summary?token=$EMP3&from_date=2026-04-01&to_date=2026-04-30"
probe "emp GET /api/attendance/report-filters"  GET "$BASE/api/attendance/report-filters?token=$EMP3"
probe "emp GET /api/attendance/employees"       GET "$BASE/api/attendance/employees?token=$EMP3"
probe "emp GET /departmentwise-attendance-report/create" GET "$BASE/departmentwise-attendance-report/create?type=API&token=$EMP3&sub_institute_id=3&user_id=7&from_date=2026-04-01&to_date=2026-04-30&department_id=0"
probe "emp GET /show-early-going-hrms-attendance-report" GET "$BASE/show-early-going-hrms-attendance-report?type=API&token=$EMP3&sub_institute_id=3&user_id=7&date=2026-04-15&department_id=0&emp_id=0"
probe "emp GET /hrms/myleave/7 (sample data?)"  GET "$BASE/hrms/myleave/7?type=API&token=$EMP3"
probe "emp GET /hrms/leavehistory/7"            GET "$BASE/hrms/leavehistory/7?type=API&token=$EMP3"

echo "===== G. PAYROLL as HR / ADMIN (does the screen even load?) ====="
probe "hr  GET /monthly-payroll/create"         GET "$BASE/monthly-payroll/create?type=API&token=$HRM3&sub_institute_id=3&user_id=67&month=Apr&year=2026"
probe "adm GET /monthly-payroll/create"         GET "$BASE/monthly-payroll/create?type=API&token=$ADM3&sub_institute_id=3&user_id=6&month=Apr&year=2026"
probe "adm GET /getMonthlyData"                 GET "$BASE/getMonthlyData?type=API&token=$ADM3&sub_institute_id=3&user_id=6&emp_id=150&totalDay=26&month=Apr&year=2026"
probe "adm GET /payroll-deduction"              GET "$BASE/payroll-deduction?type=API&token=$ADM3&sub_institute_id=3&user_id=6&status=1&submit=Search&deduction_type=9&month=Apr&year=2026"
probe "adm GET /hrms-salary-certificate"        GET "$BASE/hrms-salary-certificate?type=API&token=$ADM3&sub_institute_id=3&user_id=6"

echo "===== H. ROLES WITH NO BUSINESS IN PAYROLL ====="
for r in department_head reporting_manager recruiter; do
  TK=$(T 3 $r)
  probe "$r GET /employee-salary-structure"     GET "$BASE/employee-salary-structure?type=API&token=$TK&sub_institute_id=3&syear=2026"
done
