#!/usr/bin/env bash
# Scale probe — the release gate's last open line.
#
#   bash Docs/hrit-audit/_evidence/probe-scale.sh
#
# READ ONLY. Every call below is a GET. Nothing here writes.
#
# tokens.tsv carries tenants 3 (122 users) and 6 (939 attendance rows) only.
# Tenant 1000000 (1001 users) has no token and one is NOT minted here — that
# would be an INSERT. Its numbers come from probe-scale-query.php instead,
# which times the underlying QUERIES, not the endpoints.

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
REPS="${REPS:-5}"

tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)
H3=$(tok 3 hr_manager)
E3=$(tok 3 employee)
A6=$(tok 6 administrator)
E6=$(tok 6 employee)

# time_total + http_code + response size, REPS times, print median
# usage: t "<label>" "<url>" "<bearer>"
t() {
  local label="$1" url="$2" tk="$3"
  local times=() codes=() sizes=()
  for i in $(seq 1 "$REPS"); do
    read -r c s tt < <(curl -s -o /dev/null -m 300 \
      -w '%{http_code} %{size_download} %{time_total}\n' \
      -H "Authorization: Bearer $tk" -H 'Accept: application/json' "$url")
    codes+=("$c"); sizes+=("$s"); times+=("$tt")
  done
  local med
  med=$(printf '%s\n' "${times[@]}" | sort -n | awk -v n="$REPS" 'NR==int((n+1)/2){print}')
  printf '  %-58s  %s  %8s B  median %7.3f s   [%s]\n' \
    "$label" "${codes[0]}" "${sizes[0]}" "$med" "$(printf '%s ' "${times[@]}")"
}

echo "=============================================================================="
echo " HRIT scale probe   BASE=$BASE   reps=$REPS   $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
echo "=============================================================================="
echo
echo "-- A. TENANT 3 (122 active users, 42 attendance rows, 103 leave rows) -------"
echo
t "monthly-payroll/create Apr 2026 (no saved payslips)" \
  "$BASE/monthly-payroll/create?type=API&token=$H3&sub_institute_id=3&user_id=67&month=Apr&year=2026" "$H3"
t "monthly-payroll/create Sep 2026" \
  "$BASE/monthly-payroll/create?type=API&token=$H3&sub_institute_id=3&user_id=67&month=Sep&year=2026" "$H3"
t "employee-salary-structure" \
  "$BASE/employee-salary-structure?type=API&token=$H3&sub_institute_id=3&user_id=67&syear=2026-2027" "$H3"
t "employees-management (directory, all rows)" \
  "$BASE/api/employees-management?sub_institute_id=3&syear=2026-2027" "$A3"
t "employees-management/reference-data" \
  "$BASE/api/employees-management/reference-data?sub_institute_id=3&syear=2026-2027" "$A3"
t "attendance/weekly-summary" \
  "$BASE/api/attendance/weekly-summary?sub_institute_id=3&syear=2026-2027" "$A3"
t "attendance/kpi" \
  "$BASE/api/attendance/kpi?sub_institute_id=3&syear=2026-2027" "$A3"
t "attendance/employees" \
  "$BASE/api/attendance/employees?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/reports/summary" \
  "$BASE/api/leave/reports/summary?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/reports/register (limit default 500)" \
  "$BASE/api/leave/reports/register?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/reports/balance" \
  "$BASE/api/leave/reports/balance?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/requests" \
  "$BASE/api/leave/requests?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/balances" \
  "$BASE/api/leave/balances?sub_institute_id=3&syear=2026-2027" "$A3"
t "leave/dashboard" \
  "$BASE/api/leave/dashboard?sub_institute_id=3&syear=2026-2027" "$A3"
t "dashboard/hr/summary" \
  "$BASE/api/dashboard/hr/summary?sub_institute_id=3&syear=2026-2027" "$A3"
t "dashboard/hr/workforce (6-month attendance scan)" \
  "$BASE/api/dashboard/hr/workforce?sub_institute_id=3&syear=2026-2027" "$A3"
t "dashboard/hr/signals" \
  "$BASE/api/dashboard/hr/signals?sub_institute_id=3&syear=2026-2027" "$A3"
t "dashboard/me/summary (employee)" \
  "$BASE/api/dashboard/me/summary?sub_institute_id=3&syear=2026-2027" "$E3"
t "dashboard/me/signals (employee, 6-month scan)" \
  "$BASE/api/dashboard/me/signals?sub_institute_id=3&syear=2026-2027" "$E3"
t "my-hr/summary (employee)" \
  "$BASE/api/my-hr/summary?sub_institute_id=3&syear=2026-2027" "$E3"
t "attendance/my-attendance (employee)" \
  "$BASE/api/attendance/my-attendance?sub_institute_id=3&syear=2026-2027" "$E3"
t "attendance/regularisations" \
  "$BASE/api/attendance/regularisations?sub_institute_id=3&syear=2026-2027" "$A3"

echo
echo "-- B. TENANT 6 (22 active users, 939 attendance rows) -----------------------"
echo
t "monthly-payroll/create Apr 2026" \
  "$BASE/monthly-payroll/create?type=API&token=$A6&sub_institute_id=6&user_id=28&month=Apr&year=2026" "$A6"
t "employees-management (directory)" \
  "$BASE/api/employees-management?sub_institute_id=6&syear=2026-2027" "$A6"
t "attendance/weekly-summary" \
  "$BASE/api/attendance/weekly-summary?sub_institute_id=6&syear=2026-2027" "$A6"
t "attendance/kpi" \
  "$BASE/api/attendance/kpi?sub_institute_id=6&syear=2026-2027" "$A6"
t "dashboard/hr/workforce (6-month attendance scan)" \
  "$BASE/api/dashboard/hr/workforce?sub_institute_id=6&syear=2026-2027" "$A6"
t "dashboard/hr/summary" \
  "$BASE/api/dashboard/hr/summary?sub_institute_id=6&syear=2026-2027" "$A6"
t "leave/reports/balance" \
  "$BASE/api/leave/reports/balance?sub_institute_id=6&syear=2026-2027" "$A6"
t "my-hr/summary (employee)" \
  "$BASE/api/my-hr/summary?sub_institute_id=6&syear=2026-2027" "$E6"
t "attendance/my-attendance (employee)" \
  "$BASE/api/attendance/my-attendance?sub_institute_id=6&syear=2026-2027" "$E6"

echo
echo "-- C. WEEKLY SUMMARY OVER THE WHOLE OF TENANT 6's ATTENDANCE ----------------"
echo "   (from_date/to_date widened so all 939 rows are in range)"
echo
t "attendance/weekly-summary 2000-01-01..2030-12-31 (tenant 6)" \
  "$BASE/api/attendance/weekly-summary?sub_institute_id=6&syear=2026-2027&from_date=2000-01-01&to_date=2030-12-31" "$A6"

echo
echo "done."
