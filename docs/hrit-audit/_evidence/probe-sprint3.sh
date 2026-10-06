#!/usr/bin/env bash
# Sprint 3 verification. F-120: attendance REPORTING is gated, self service is not.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
probe() { local want="$1" label="$2" url="$3"
  local body code mark
  body=$(curl -s -m 60 -w $'\n%{http_code}' -H 'Accept: application/json' "$url")
  code=$(printf '%s' "$body" | tail -1)
  [ "$code" = "$want" ] && mark=PASS || mark=FAIL
  printf '%-4s want=%-3s got=%-3s  %-48s %s\n' "$mark" "$want" "$code" "$label" \
    "$(printf '%s' "$body" | head -c 110 | tr -d '\n' | cut -c1-85)"
}
EMP3=$(T 3 employee); HRM3=$(T 3 hr_manager); ADM3=$(T 3 administrator)
AUD3=$(T 3 auditor); EXE3=$(T 3 executive); REC3=$(T 3 recruiter); DH3=$(T 3 department_head)
Q="type=API&sub_institute_id=3"

echo "===== F-120: legacy attendance REPORT routes now refuse non-reporting roles ====="
probe 403 "employee  -> departmentwise-attendance-report/create" "$BASE/departmentwise-attendance-report/create?$Q&token=$EMP3&user_id=7&from_date=2026-04-01&to_date=2026-04-30&department_id=0"
probe 403 "recruiter -> show-early-going-...-report"             "$BASE/show-early-going-hrms-attendance-report?$Q&token=$REC3&user_id=588&date=2026-04-15&department_id=0&emp_id=0"
probe 403 "dept_head -> hrms-attendance-report"                  "$BASE/hrms-attendance-report?$Q&token=$DH3&user_id=580"

echo "===== ...and still serve the roles the menu shows it to ====="
probe 200 "hr_mgr    -> departmentwise-attendance-report/create" "$BASE/departmentwise-attendance-report/create?$Q&token=$HRM3&user_id=67&from_date=2026-04-01&to_date=2026-04-30&department_id=0"
probe 200 "admin     -> show-early-going-...-report"             "$BASE/show-early-going-hrms-attendance-report?$Q&token=$ADM3&user_id=6&date=2026-04-15&department_id=0&emp_id=0"
probe 200 "auditor   -> departmentwise-attendance-report/create" "$BASE/departmentwise-attendance-report/create?$Q&token=$AUD3&user_id=590&from_date=2026-04-01&to_date=2026-04-30&department_id=0"
probe 200 "executive -> departmentwise-attendance-report/create" "$BASE/departmentwise-attendance-report/create?$Q&token=$EXE3&user_id=589&from_date=2026-04-01&to_date=2026-04-30&department_id=0"

echo "===== the new API report endpoints, gated the same way ====="
probe 403 "employee  -> /api/attendance/kpi"            "$BASE/api/attendance/kpi?token=$EMP3"
probe 403 "employee  -> /api/attendance/employees"      "$BASE/api/attendance/employees?token=$EMP3"
probe 403 "employee  -> /api/attendance/report-filters" "$BASE/api/attendance/report-filters?token=$EMP3"
probe 200 "hr_mgr    -> /api/attendance/kpi"            "$BASE/api/attendance/kpi?token=$HRM3"
probe 200 "auditor   -> /api/attendance/weekly-summary" "$BASE/api/attendance/weekly-summary?token=$AUD3"

echo "===== SELF SERVICE must stay open - an employee punches through these ====="
probe 200 "employee  -> /api/attendance/my-attendance"  "$BASE/api/attendance/my-attendance?token=$EMP3"
probe 200 "employee  -> /api/attendance/self-summary"   "$BASE/api/attendance/self-summary?token=$EMP3"
probe 200 "employee  -> /api/attendance/regularisations" "$BASE/api/attendance/regularisations?token=$EMP3"
probe 200 "employee  -> hrms-attendance (legacy punch screen)" "$BASE/hrms-attendance?$Q&token=$EMP3&user_id=7"
