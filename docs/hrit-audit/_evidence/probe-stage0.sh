#!/usr/bin/env bash
# STAGE 0 - the four defects that were not waiting for a screen.
#
#   bash Docs/hrit-audit/_evidence/probe-stage0.sh
#
# F-158  POST api/designation_leave resolved to HrmsController@store, a method
#        that does not exist, because a later duplicate registration shadowed the
#        real one. Every call 500'd.
# F-159  GET api/employee-attendance-monthly-report had no route gate, and its
#        own token check ran only when the CALLER passed type=API. Even with a
#        token it never asked whether user_id was the caller, so any employee
#        could read a colleague's punch times, lateness and leave REASONS.
# F-160  showSalaryStructureReport read session() with no type=API branch, so an
#        API caller resolved to tenant null and the report was always empty.
# F-161  earlyGoingHrmsAttendanceReport echoed the employee id into the response
#        body before the JSON, so the body was `12{...}` and the client's
#        response.json() threw - blanking all four datasets on the screen.
#
# Read-only. Creates nothing, so there is nothing to tear down.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)    # user 6,  tenant 3
A6=$(tok 6 administrator)    # user 28, tenant 6 - the only tenant with real attendance volume
E3=$(tok 3 employee)         # user 7,  tenant 3
T3=$(tok 3 team_employee)    # user 582, tenant 3

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$@"; }
body() { curl -s --max-time 60 "$@"; }

echo
echo "F-158  designation_leave resolves to a method that exists"
# Before: 500 (Method HrmsController::store does not exist).
# After:  401 without a token - which only the token-gated HrmsLeaveController
#         route can produce. A 500 here means the duplicate is back.
c=$(code -X POST "$BASE/api/designation_leave" -H 'Accept: application/json')
check "no token -> 401, not 500" "401" "$c"

echo
echo "F-159  monthly attendance report is gated, and HR-or-self is enforced"
# The gate itself.
c=$(code "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=7&month=2026-08" \
      -H 'Accept: application/json')
check "no token -> 401" "401" "$c"

# The bypass that used to work: omit type, and the in-controller check was skipped.
c=$(code "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=7&month=2026-08&type=web" \
      -H 'Accept: application/json')
check "no token, type omitted -> still 401" "401" "$c"

# Self: an employee reading their own row is the point of the endpoint.
c=$(code "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=7&month=2026-08" \
      -H "Authorization: Bearer $E3" -H 'Accept: application/json')
check "employee reads OWN row -> 200" "200" "$c"

# Colleague: same tenant, different person. This is the read that used to work.
c=$(code "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=582&month=2026-08" \
      -H "Authorization: Bearer $E3" -H 'Accept: application/json')
check "employee reads COLLEAGUE -> 403" "403" "$c"

# And the refusal must not be silent about why.
m=$(body "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=582&month=2026-08" \
      -H "Authorization: Bearer $E3" -H 'Accept: application/json' \
   | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["message"] ?? "";')
check "refusal names the rule" "You may only view your own attendance." "$m"

# HR keeps the access the screen needs.
c=$(code "$BASE/api/employee-attendance-monthly-report?sub_institute_id=3&user_id=582&month=2026-08" \
      -H "Authorization: Bearer $A3" -H 'Accept: application/json')
check "administrator reads anyone -> 200" "200" "$c"

echo
echo "F-160  salary structure report resolves a tenant under type=API"
# Before: session() only -> null -> `where sub_institute_id = null` -> [] always.
# The assertion is on the KEY existing and the tenant resolving, not on a row
# count: tenant 3 may legitimately have no structures for a given year, and an
# assertion that passes only when data happens to exist is the vacuous trap.
r=$(body -X POST "$BASE/salary-structure-report?type=API" \
      -H "Authorization: Bearer $A3" -H 'Accept: application/json' \
      -H 'Content-Type: application/x-www-form-urlencoded' \
      -d 'emp_id=0&department_id=0&year=0')
has=$(echo "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d) && array_key_exists("salaryStructure",$d) ? "yes" : "no";')
check "returns a salaryStructure key" "yes" "$has"
hdr=$(echo "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["headers"]) ? "yes" : "no";')
check "payroll heads resolved (proves tenant != null)" "yes" "$hdr"

echo
echo "F-161/F-162  early-going report: filter reaches the query, JSON stays JSON"
#
# The first version of this section sent emp_id[] and passed even with the echo
# PUT BACK - a vacuous assertion. The reason is F-162: under type=API the
# controller read `employee_id`, which nothing sends, so $employee_id was always
# 0, the `if` never ran and the echo never executed. The probe was asserting on
# a branch it was not reaching.
#
# So assert the BRANCH IS REACHED first. If this row fails, the two below prove
# nothing regardless of what they say.
# Legacy hrms.php routes under type=API take identity as QUERY PARAMS, not a
# bearer header. Sending only the header returns {"message":"Token not
# provided"} - which parses as JSON and starts with "{", so the two assertions
# below passed on the error body until this was fixed. Both are sent now.
#
# Tenant 6 on 2025-11-26, because the assertion needs a day with SEVERAL
# employees punched out: tenant 3 has 17 such rows in total and none of them
# share a date, so "filtered" and "unfiltered" would both be 0 there and the
# check would pass without proving anything.
early() { body -G "$BASE/show-early-going-hrms-attendance-report?type=API&token=$A6&sub_institute_id=6&user_id=28" \
      -H "Authorization: Bearer $A6" -H 'Accept: application/json' \
      -d "$1" -d 'department_id=0' -d 'date=2025-11-26'; }

rows() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["hrmsList"] ?? null) ? count($d["hrmsList"]) : "err";'; }

# Unfiltered must return several; one employee must return fewer. If the filter
# is ignored both are the same number, which is what F-162 actually did.
n_all=$(early 'emp_id=0'     | rows)
n_one=$(early 'emp_id[]=34'  | rows)
check "unfiltered day returns more than one row (got $n_all)" "many" \
      "$([ "$n_all" != "err" ] && [ "$n_all" -gt 1 ] 2>/dev/null && echo many || echo "$n_all")"
check "emp_id[] narrows the result ($n_all -> $n_one)" "narrower" \
      "$([ "$n_one" != "err" ] && [ "$n_all" != "err" ] && [ "$n_one" -lt "$n_all" ] 2>/dev/null && echo narrower || echo same)"

# Only now is the echo assertion meaningful: the branch it lived in is reached.
r=$(early 'emp_id[]=34')
ok=$(echo "$r" | php -r '$s=stream_get_contents(STDIN); json_decode($s,true); echo json_last_error()===JSON_ERROR_NONE ? "parses" : "garbage";')
check "with an employee selected, body parses as JSON" "parses" "$ok"
lead=$(echo "$r" | php -r '$s=ltrim(stream_get_contents(STDIN)); echo $s === "" ? "empty" : substr($s,0,1);')
check "body starts with { and not a stray digit" "{" "$lead"

echo
echo "  ---------------------------------------------"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
