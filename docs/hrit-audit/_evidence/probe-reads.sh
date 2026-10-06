#!/usr/bin/env bash
# Sprint 0 evidence. READ-ONLY probes: no request here writes anything.
# Usage: bash Docs/hrit-audit/_evidence/probe-reads.sh
BASE=http://127.0.0.1:8000
T() { awk -F"	" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }

probe() { # label method url
  local label="$1" method="$2" url="$3"
  local body code
  body=$(curl -s -m 25 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' "$url")
  code=$(printf '%s' "$body" | tail -1)
  printf '%-58s %s  %s\n' "$label" "$code" "$(printf '%s' "$body" | head -c 200 | tr -d '\n' | cut -c1-150)"
}

EMP3=$(T 3 employee); ADM3=$(T 3 administrator); AUD3=$(T 3 auditor); HRM3=$(T 3 hr_manager)
EMP6=$(T 6 employee)

echo "===== A. Leave API as an EMPLOYEE (tenant 3, user 7) ====="
probe "GET  /api/leave/requests (org-wide?)"     GET "$BASE/api/leave/requests?token=$EMP3&per_page=200"
probe "GET  /api/leave/leave-types"              GET "$BASE/api/leave/leave-types?token=$EMP3"
probe "GET  /api/leave/roles (permission matrix)" GET "$BASE/api/leave/roles?token=$EMP3"
probe "GET  /api/leave/workflow"                 GET "$BASE/api/leave/workflow?token=$EMP3"
probe "GET  /api/leave/reports/register"         GET "$BASE/api/leave/reports/register?token=$EMP3"

echo "===== B. PAYROLL web routes as an EMPLOYEE (browser gate only) ====="
probe "GET  /payroll-type"                       GET "$BASE/payroll-type?type=API&token=$EMP3&sub_institute_id=3&user_id=7"
probe "GET  /employee-salary-structure"           GET "$BASE/employee-salary-structure?type=API&token=$EMP3&sub_institute_id=3&user_id=7&syear=2026"
probe "GET  /monthly-payroll/create"              GET "$BASE/monthly-payroll/create?type=API&token=$EMP3&sub_institute_id=3&user_id=7&month=Apr&year=2026"
probe "GET  /hrms-salary-certificate"             GET "$BASE/hrms-salary-certificate?type=API&token=$EMP3&sub_institute_id=3&user_id=7"
probe "GET  /form16 (documented fatal)"           GET "$BASE/form16?type=API&token=$EMP3&sub_institute_id=3&user_id=7"

echo "===== C. AUDITOR and RECRUITER reach (should be read-only / none) ====="
probe "auditor  GET /employee-salary-structure"   GET "$BASE/employee-salary-structure?type=API&token=$AUD3&sub_institute_id=3&user_id=590&syear=2026"
probe "auditor  GET /api/leave/requests"          GET "$BASE/api/leave/requests?token=$AUD3"

echo "===== D. TENANT ISOLATION (tenant 6 token, tenant 3 ids) ====="
probe "t6 emp GET /api/leave/requests"            GET "$BASE/api/leave/requests?token=$EMP6&sub_institute_id=3"
probe "t6 emp GET /payroll-type as tenant 3"      GET "$BASE/payroll-type?type=API&token=$EMP6&sub_institute_id=3&user_id=63"
probe "t6 emp GET salary-structure as tenant 3"   GET "$BASE/employee-salary-structure?type=API&token=$EMP6&sub_institute_id=3&user_id=63&syear=2026"

echo "===== E. NO TOKEN AT ALL ====="
probe "anon GET /hrms/myleave/7"                  GET "$BASE/hrms/myleave/7"
probe "anon GET /hrms/leavehistory/7"             GET "$BASE/hrms/leavehistory/7"
probe "anon GET /api/leave/requests"              GET "$BASE/api/leave/requests"
probe "anon GET /payroll-type"                    GET "$BASE/payroll-type?type=API"
probe "anon GET /api/attendance/my-attendance"    GET "$BASE/api/attendance/my-attendance"
