#!/usr/bin/env bash
# Sprint 8 evidence — the last one.
#
#   F-110  Salary Certificate had written 0 rows in the platform's lifetime.
#          The audit left "unused or unusable?" open as Q5. It is UNUSABLE.
#   F-122  Every unauthenticated browser hit returned 500.
#   F-130  An employee could not see their own payslip. No route served it.
#
#   bash Docs/hrit-audit/_evidence/probe-sprint8.sh
#
# Self-isolating, per the lesson from Sprint 7: it clears its own ground first
# and removes everything it creates.

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"

tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

ADMIN=$(tok 3 administrator)
EMP=$(tok 3 team_employee)      # Vikram (582) — no salary structure, no payslip
PLAIN=$(tok 3 employee)

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 — expected [$2] got [$3]"; fail=$((fail+1)); fi; }

api() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b"
  else
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi
}

code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }

snap "delete from hrms_salary_certificate" >/dev/null

echo "======================= Sprint 8 — the last one ======================="
echo

# ---------------------------------------------------------------------------
echo "1. F-130 — an employee can see their own payslip. There was no route at all."
# ---------------------------------------------------------------------------
S=$(api GET "/api/my-hr/summary?sub_institute_id=3&syear=2026-2027" "$EMP")
check "My HR summary answers" "1" "$(echo "$S" | jq_ status)"

P=$(api GET "/api/my-hr/payslips?sub_institute_id=3&syear=2026-2027" "$ADMIN")
check "the payslip list answers" "1" "$(echo "$P" | jq_ status)"
echo "     administrator's own payslips: $(echo "$P" | jq_ data)"

# THE PART THAT MATTERS: there is no employee_id to tamper with. Two callers,
# same endpoint, different answers - decided by the token, not by a parameter.
A_COUNT=$(api GET "/api/my-hr/payslips?sub_institute_id=3" "$ADMIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);')
E_COUNT=$(api GET "/api/my-hr/payslips?sub_institute_id=3" "$EMP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);')
check "administrator sees their own payslip" "1" "$A_COUNT"
check "the employee sees theirs, which is none" "0" "$E_COUNT"

# And an id in the query string changes nothing, because nothing reads one.
SPOOF=$(api GET "/api/my-hr/payslips?sub_institute_id=3&employee_id=6&user_id=6" "$EMP" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"] ?? []);')
check "passing employee_id=6 does NOT show employee 6's payslip" "0" "$SPOOF"

MISSING=$(code "$BASE/api/my-hr/payslips/Jan/2099/pdf" -H "Authorization: Bearer $EMP" -H 'Accept: application/json')
check "a month you have no payslip for is 404" "404" "$MISSING"

# ---------------------------------------------------------------------------
echo
echo "2. F-110 — the Salary Certificate was UNUSABLE, not unused"
# ---------------------------------------------------------------------------
# employee_salary_structures holds 8 rows for the entire platform. The builder
# dereferenced $get_all_details[0] with no guard, so every (employee, year)
# without a structure - which is nearly all of them - fatalled before the
# insert. That is why the table held 0 rows in its lifetime, and it answers Q5.
NOSTRUCT=$(api POST /hrms-salary-certificate-report "$ADMIN" \
  '{"type":"API","sub_institute_id":3,"syear":"2026-2027","department_id":1930,"employee_id":582,"year":2026,"month_id":["Jan"],"payroll_type_id":[1],"reason":"bank loan"}')
MSG=$(echo "$NOSTRUCT" | jq_ message)
case "$MSG" in
  *"no salary structure for 2026"*) check "no structure gives a sentence, not a stack trace" "ok" "ok" ;;
  *) check "no structure gives a sentence, not a stack trace" "ok" "got: $MSG" ;;
esac
echo "     -> $MSG"

# A configuration gap is a 422, not a 500. The first version of this fix threw
# a RuntimeException, so the right message arrived looking like a crash - this
# probe caught it, which is what the check is for.
check "no exception key: this is a refusal, not a crash" "" "$(echo "$NOSTRUCT" | jq_ exception)"
NOSTRUCT_CODE=$(code -X POST "$BASE/hrms-salary-certificate-report" -H "Authorization: Bearer $ADMIN"   -H 'Accept: application/json' -H 'Content-Type: application/json'   -d '{"type":"API","sub_institute_id":3,"syear":"2026-2027","department_id":1930,"employee_id":582,"year":2026,"month_id":["Jan"],"payroll_type_id":[1],"reason":"bank loan"}')
check "and it answers 422, not 500" "422" "$NOSTRUCT_CODE"

# The one combination on the whole platform that HAS a structure.
api POST /hrms-salary-certificate-report "$ADMIN" \
  '{"type":"API","sub_institute_id":3,"syear":"2026-2027","department_id":35,"employee_id":10,"year":2026,"month_id":["Jan"],"payroll_type_id":[1],"reason":"bank loan"}' >/dev/null

CERTS=$(snap "select count(*) c from hrms_salary_certificate" | jq_ c)
check "a certificate is written where a structure exists" "1" "$CERTS"

# F-131, found here: the certificate called every employee "Her".
GENDER=$(snap "select case when pdf_html like '%Her monthly salary%' then 'Her'
                          when pdf_html like '%His monthly salary%' then 'His'
                          when pdf_html like '%Their monthly salary%' then 'Their'
                          else '?' end g
                 from hrms_salary_certificate limit 1" | jq_ g)
EXPECT=$(snap "select case when gender='M' then 'His' when gender='F' then 'Her' else 'Their' end g
                 from tbluser where id=10" | jq_ g)
check "the certificate uses the employee's own gender, not a hardcoded one" "$EXPECT" "$GENDER"

# ---------------------------------------------------------------------------
echo
echo "3. F-122 — an unauthenticated browser hit was a 500 on every route"
# ---------------------------------------------------------------------------
# authMiddleware redirected to route('login'). There is no route NAMED 'login' -
# the page is named 'login.index', and its URI is /login, which is what made the
# mistake look right. Every session timeout rendered a 500.
BROWSER=$(code "$BASE/monthly-payroll")
check "an unauthenticated browser hit redirects" "302" "$BROWSER"
echo "     -> $(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/monthly-payroll")"

APIHIT=$(code "$BASE/api/my-hr/summary" -H 'Accept: application/json')
check "an unauthenticated API hit is still 401, not a redirect" "401" "$APIHIT"

# ---------------------------------------------------------------------------
echo
echo "4. Nothing earlier regressed"
# ---------------------------------------------------------------------------
DECIDE=$(api POST "/api/leave/requests/4/decision" "$PLAIN" '{"status":"approved"}')
check "F-87 still closed: an employee cannot decide leave" "0" "$(echo "$DECIDE" | jq_ status)"

PAYROLL=$(code "$BASE/monthly-payroll" -H "Authorization: Bearer $PLAIN" -H 'Accept: application/json')
check "F-91 still closed: an employee cannot reach payroll" "403" "$PAYROLL"

LOCK=$(code -X POST "$BASE/monthly-payroll-lock" -H "Authorization: Bearer $PLAIN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"type":"API","sub_institute_id":3,"month":"Dec","year":2026,"action":"lock"}')
check "F-129 still closed: an employee cannot lock a month" "403" "$LOCK"

# ---------------------------------------------------------------------------
echo
echo "5. Clean up"
# ---------------------------------------------------------------------------
snap "delete from hrms_salary_certificate" >/dev/null
echo "     certificate rows removed; nothing else was written"

echo
echo "=============================================================="
echo "  PASS $pass   FAIL $fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
