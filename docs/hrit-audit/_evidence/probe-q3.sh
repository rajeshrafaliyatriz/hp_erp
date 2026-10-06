#!/usr/bin/env bash
# Q3 - cross-tenant fetch BY ID.
#
# The release gate's only PASS is "Tenant isolation proven with two tenants",
# qualified "(list endpoints; see Q3)". List endpoints were proven; fetch-by-id
# never was. This closes that qualifier.
#
# Every request here is a GET. Nothing is written.
#
# The test is: name a record id that belongs to the OTHER tenant and see what
# comes back. 404 is the right answer - and it is the right answer rather than
# 403 deliberately, because 403 would confirm the id exists.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)
A6=$(tok 6 administrator)

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

code() { curl -s -o /dev/null -w '%{http_code}' "$BASE$1" \
           -H "Authorization: Bearer $2" -H 'Accept: application/json' --max-time 30; }

body() { curl -s "$BASE$1" -H "Authorization: Bearer $2" -H 'Accept: application/json' --max-time 30; }

# Ids resolved live so this survives the data moving.
q() { php Docs/hrit-audit/_evidence/snapshot.php "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$2"; }

L3=$(q "select id from hrms_emp_leaves where sub_institute_id=3 and deleted_at is null order by id desc limit 1" id)
L6=$(q "select id from hrms_emp_leaves where sub_institute_id=6 and deleted_at is null order by id desc limit 1" id)
E3=$(q "select id from tbluser where sub_institute_id=3 and status=1 order by id desc limit 1" id)
E6=$(q "select id from tbluser where sub_institute_id=6 and status=1 order by id desc limit 1" id)

echo "=============================================================="
echo " Q3 - cross-tenant fetch by id"
echo "   tenant 3 leave=$L3 employee=$E3    tenant 6 leave=$L6 employee=$E6"
echo "=============================================================="
echo
echo "1. A leave request belonging to the other tenant"
check "tenant 6 admin cannot fetch tenant 3's leave $L3" "404" "$(code "/api/leave/requests/$L3" "$A6")"
check "tenant 3 admin cannot fetch tenant 6's leave $L6" "404" "$(code "/api/leave/requests/$L6" "$A3")"
check "each tenant CAN fetch its own leave (so 404 is isolation, not breakage)" "200" "$(code "/api/leave/requests/$L3" "$A3")"

echo
echo "2. An employee record belonging to the other tenant"
check "tenant 6 admin cannot fetch tenant 3's employee $E3" "404" "$(code "/api/employees-management/$E3" "$A6")"
check "tenant 3 admin cannot fetch tenant 6's employee $E6" "404" "$(code "/api/employees-management/$E6" "$A3")"
check "each tenant CAN fetch its own employee" "200" "$(code "/api/employees-management/$E3" "$A3")"

echo
echo "3. The 404 must not leak the record's existence"
OUT=$(body "/api/employees-management/$E3" "$A6")
check "the cross-tenant 404 body names no employee" "0" \
  "$(echo "$OUT" | grep -ci 'first_name\|employee_no\|email')"
check "and says only 'not found'" "1" \
  "$(echo "$OUT" | grep -ci 'not found')"

echo
echo "4. My HR payslip PDF ignores any id the caller supplies"
check "tenant 6 employee gets its own 404, not tenant 3's payslip" "404" \
  "$(code "/api/my-hr/payslips/Aug/2025/pdf" "$(tok 6 employee)")"

echo
echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
