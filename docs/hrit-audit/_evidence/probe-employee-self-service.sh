#!/usr/bin/env bash
# THE EMPLOYEE'S HALF OF HRIT.
#
#   bash Docs/hrit-audit/_evidence/probe-employee-self-service.sh
#
# WHY THIS EXISTS
#
# Phase 15 finished HRIT from the administrator's side. Testing it afterwards
# surfaced a different gap, and it was not a list of small bugs: the module had
# never been built for the person it is mostly about.
#
#   - An employee had NO MENU PATH TO LEAVE. Menu 103 "Leave Requests" was
#     granted to the `employee` profile in one tenant out of eleven, and its
#     parent 94 in the same one. 209 active employees could not navigate to
#     apply for leave - only a button inside My HR reached it.
#   - The My HR payslip download answered 302 to /login. The route is
#     auth:sanctum and the button was a plain <a href>, which sends no
#     Authorization header.
#   - Form 16 threw a 500 for EVERYONE, HR included: it queried
#     `fees_map_years`, a table that exists on neither host.
#   - A salary certificate covering a whole year could not be stored: twelve
#     month ids joined is 26 characters and the column was varchar(20).
#
# WHAT THIS PROBE HAS TO AVOID
#
# Every endpoint here resolves its subject from the TOKEN and takes no
# employee_id, so "an employee cannot read a colleague" is true by construction
# and a probe that only ever watches a 403 would prove nothing. So the positive
# case is CONSTRUCTED - a real payslip and salary structure are seeded for a
# real employee - and then the same token is asked for somebody else's data in
# four different ways. Both halves are asserted; neither alone is evidence.
#
# Tenant 6 is Scholar Clone, which has no payroll data of its own. Everything is
# marked by a dedicated probe pay head and a year no real record can occupy, so
# teardown is by marker and cannot be malformed (F-157).
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

E6=$(tok 6 employee)        # user 63, tenant 6
E3=$(tok 3 employee)        # user 7,  tenant 3 - a different person entirely
A3=$(tok 3 administrator)

TENANT=6
SUBJECT=63
OTHER=29                    # a real tenant-6 employee who is NOT the caller
YEAR=2099                   # cannot collide with anything real
MONTH=Jan
HEAD_NAME='ZZPROBE selfserve head'

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

sql() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
val() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$1"; }

# GET as an employee, with the token in the HEADER - which is the whole point of
# the F-209 fix and therefore how this probe must call.
as() { curl -s -m 90 "$BASE$2" -H "Authorization: Bearer $1" -H 'Accept: application/pdf, application/json'; }
code() { curl -s -m 90 -o /dev/null -w '%{http_code}' "$BASE$2" -H "Authorization: Bearer $1" -H 'Accept: application/pdf, application/json'; }

HEAD_ID=""

teardown() {
  hid=$(sql "select id from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | val id)
  sql "delete from employee_monthly_salary_data where sub_institute_id=$TENANT and year=$YEAR" >/dev/null
  sql "delete from employee_salary_structures  where sub_institute_id=$TENANT and year=$YEAR" >/dev/null
  sql "delete from hrms_salary_certificate     where sub_institute_id=$TENANT and year=$YEAR" >/dev/null
  if [ -n "$hid" ]; then sql "delete from payroll_types where id=$hid" >/dev/null; fi
}

echo
echo "================ The employee's half of HRIT ================"
trap teardown EXIT
teardown   # start-of-run clear (F-163)

# ---------------------------------------------------------------- 1. NAVIGATION
echo
echo "1. The sidebar an employee is actually served"
# Fetched from the endpoint that builds it, not reasoned from the rights table:
# a leaf under a container the profile cannot view never enters the sidebar, and
# that interaction is exactly what a table query misses.
sidebar() {
  curl -s -m 60 -G "$BASE/user/ajax_sidebar_menu_g2g" \
    --data-urlencode "type=API" --data-urlencode "token=$1" \
    --data-urlencode "sub_institute_id=$2" --data-urlencode "profile_id=$3" \
    -H 'Accept: application/json'
}
hrit_leaves() {
  php -r '$d=json_decode(stream_get_contents(STDIN),true); $out=[];
    foreach(($d["data"]??[]) as $m){
      if(strpos($m["access_link"]??"","/module/hrit-solutions")!==0) continue;
      foreach(($m["menus"]??[]) as $mn){
        if(empty($mn["submenus"])) { $out[]=$mn["id"]; continue; }
        foreach($mn["submenus"] as $sm) $out[]=$sm["id"];
      }
    }
    sort($out); echo implode(",",$out);'
}

S6=$(sidebar "$E6" 6 17 | hrit_leaves)
check "tenant 6 employee sees exactly the four screens that are theirs" "100,103,305,309" "$S6"
S3=$(sidebar "$E3" 3 9 | hrit_leaves)
check "tenant 3 employee, independently, the same four" "100,103,305,309" "$S3"

# The regression this is really for: 103 was absent in 9 of 11 tenants.
case ",$S6," in *,103,*) r=yes;; *) r=no;; esac
check "  Leave Requests is reachable from the menu at all" "yes" "$r"
case ",$S6," in *,140,*|*,105,*|*,106,*|*,110,*|*,109,*) r=leaked;; *) r=none;; esac
check "  and no payroll screen is offered to an employee" "none" "$r"

echo
echo "  The same contract across every tenant, not just the two with tokens"
BAD=$(sql "select count(*) c from tblgroupwise_rights_g2g r
             join tbluserprofilemaster p on p.id=r.profile_id
            where p.role_key='employee' and r.can_view=1
              and r.menu_id in (95,105,106,107,108,109,110,140,307,308,310,311,101,162,163,164,102,104,165,166,167)" | val c)
check "no employee profile anywhere holds an HR-only screen" "0" "$BAD"
MISSING=$(sql "select count(*) c from tbluserprofilemaster p
                where p.role_key='employee'
                  and not exists (select 1 from tblgroupwise_rights_g2g r
                                   where r.profile_id=p.id and r.menu_id=103 and r.can_view=1)" | val c)
check "every employee profile can reach Leave Requests" "0" "$MISSING"

# ---------------------------------------------------------------- 2. SEED
echo
echo "2. A real month of pay, so the positive case is a real one"
sql "insert into payroll_types
      (payroll_name, payroll_type, amount_type, payroll_percentage, day_count, status, sort_order, sub_institute_id, created_at)
     values ('$HEAD_NAME', 1, 1, 0, 0, 1, 97, $TENANT, now())" >/dev/null
HEAD_ID=$(sql "select id from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | val id)
check "probe pay head created" "yes" "$([ -n "$HEAD_ID" ] && echo yes || echo no)"

sql "insert into employee_salary_structures
      (employee_id, employee_salary_data, year, sub_institute_id, created_at, updated_at)
     values ($SUBJECT, '{\"$HEAD_ID\":\"25000\"}', $YEAR, $TENANT, now(), now())" >/dev/null
sql "insert into employee_monthly_salary_data
      (sub_institute_id, total_deduction, total_payment, employee_id, month, total_day, year,
       employee_salary_data, salary_amount, created_at, updated_at)
     values ($TENANT, 0, 25000, $SUBJECT, '$MONTH', 30, $YEAR, '{\"$HEAD_ID\":\"25000\"}', 25000, now(), now())" >/dev/null

# ---------------------------------------------------------------- 3. PAYSLIP PDF
echo
echo "3. The payslip download - F-209's headline"
PS=$(as "$E6" "/api/my-hr/payslips")
URL=$(echo "$PS" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
  foreach(($d["data"]??[]) as $r) if ((int)$r["year"]===2099) { echo $r["pdf_url"]; return; }')
check "the payslip is listed with a server-built url" "yes" "$([ -n "$URL" ] && echo yes || echo no)"

# WITH the header - what the fixed frontend now does.
H=$(curl -s -m 90 -o /tmp/probe-ess.pdf -w '%{http_code}' "$URL" -H "Authorization: Bearer $E6")
check "  fetched with the token it returns the PDF" "200" "$H"
check "  and it really is a PDF" "%PDF-" "$(head -c 5 /tmp/probe-ess.pdf | tr -d '\0')"

# WITHOUT it - what a plain <a href> did, and the reason the button was broken.
# Asserted so the day someone "simplifies" this back to a link, this goes red.
H=$(curl -s -m 90 -o /dev/null -w '%{http_code}' "$URL")
check "  opened as a bare link it is still refused, as it must be" "302" "$H"

# The frontend half. A link cannot carry the header, so the control must not be
# one - this is the assertion that would have caught the original bug.
MYHR=../g2gv0/components/domain/hrms/hrit/my-hr/page.tsx
# grep -c exits 1 on no match, so `|| echo 0` would print TWO zeros. The
# file's absence must read as 'missing', never as a clean pass.
if [ -f "$MYHR" ]; then LINKED=$(grep -c 'href={payslip.pdf_url}' "$MYHR"); else LINKED=missing; fi
check "  and My HR no longer renders it as an <a href>" "0" "$LINKED"

# ---------------------------------------------------------------- 4. BREAKDOWN
echo
echo "4. The pay breakdown, and whose it is"
B=$(as "$E6" "/api/my-hr/pay-breakdown")
check "the month is returned with its components" "25000" \
  "$(echo "$B" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["months"][0]["net"] ?? "";')"
check "  named from payroll_types, not rendered as an id" "$HEAD_NAME" \
  "$(echo "$B" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["months"][0]["components"][0]["name"] ?? "";')"
check "  and classified as an earning" "earning" \
  "$(echo "$B" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["months"][0]["components"][0]["kind"] ?? "";')"

echo
echo "  The subject is the token. Four ways of asking for somebody else:"
for q in "employee_id=$OTHER" "emp_id=$OTHER" "user_id=$OTHER" "sub_institute_id=3&user_id=$OTHER"; do
  N=$(as "$E6" "/api/my-hr/pay-breakdown?$q" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["months"][0]["net"] ?? "none";')
  check "  ?$q still returns the caller's own" "25000" "$N"
done

# The other half: a different person, same endpoint, gets their own nothing
# rather than this. Without this the four above could pass on a hardcoded value.
check "a different employee sees their own months, not these" "0" \
  "$(as "$E3" "/api/my-hr/pay-breakdown" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"]["months"] ?? []);')"
check "  and an unauthenticated caller gets nothing" "401" \
  "$(curl -s -m 30 -o /dev/null -w '%{http_code}' "$BASE/api/my-hr/pay-breakdown" -H 'Accept: application/json')"

# ---------------------------------------------------------------- 5. CERTIFICATE
echo
echo "5. The salary certificate - zero had ever been generated, platform-wide"
H=$(curl -s -m 90 -o /tmp/probe-ess-sc.pdf -w '%{http_code}' "$BASE/api/my-hr/salary-certificate/$YEAR" -H "Authorization: Bearer $E6")
check "an employee can issue their own" "200" "$H"
check "  and it is a PDF" "%PDF-" "$(head -c 5 /tmp/probe-ess-sc.pdf | tr -d '\0')"

# F-212. Twelve month ids joined is 26 characters; the column was varchar(20),
# so a certificate covering a full year could not be stored - by HR either.
check "  covering all twelve months, which used not to fit" "1,2,3,4,5,6,7,8,9,10,11,12" \
  "$(sql "select month from hrms_salary_certificate where sub_institute_id=$TENANT and year=$YEAR and employee_id=$SUBJECT" | val month)"
check "  filed against the employee who asked" "$SUBJECT" \
  "$(sql "select employee_id from hrms_salary_certificate where sub_institute_id=$TENANT and year=$YEAR" | val employee_id)"

# The refusal, with its reason - F-110's root cause said out loud instead of
# being a stack trace.
MSG=$(as "$E3" "/api/my-hr/salary-certificate/$YEAR" | val message)
check "an employee with no structure is told why, not crashed" \
  "A salary certificate for $YEAR cannot be issued yet - there is no salary structure on record for you for that year. Ask HR to add one under Salary Structure." "$MSG"

# ---------------------------------------------------------------- 6. FORM 16
echo
echo "6. Form 16 - which threw a 500 for every caller, HR included"
F=$(as "$E6" "/api/my-hr/form-16/$YEAR")
check "it answers at all" "1" "$(echo "$F" | val status)"
# F-210. The fallback was date('m'), a window that moved every time it was read.
check "  over April to March, not a window that moves" "01/Apr/$YEAR" \
  "$(echo "$F" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["from_date"] ?? "";')"
check "  ending in the following year" "31/Mar/$((YEAR+1))" \
  "$(echo "$F" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["to_date"] ?? "";')"

# F-211. The HR endpoint returned tbluser's 99 columns, password hash included.
echo
echo "  F-211: and the HR endpoint it delegates to no longer ships a credential"
LEAK=$(curl -s -m 60 -X POST "$BASE/form16-report?type=API&token=$A3&sub_institute_id=3&user_id=$(tok 3 administrator >/dev/null; awk -F'\t' '$1==3 && $2=="administrator"{print $3}' "$TOK")&syear=2025" \
   -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded' \
   -d "emp_id=6" -d "year=2025" -d "department_id=0" \
 | php -r '$d=json_decode(stream_get_contents(STDIN),true); $e=$d["get_employee_detail"]??null;
   /*
    * "no-payload", NOT "none". Caught by known-badding this very assertion:
    * with the F-210 bug reinstated the endpoint 500s, there is no
    * get_employee_detail to inspect, and an array_intersect over nothing
    * returns nothing - so the leak check PASSED while the endpoint was
    * completely broken. A detector that reports "clean" when it could not look
    * is the failure mode this suite exists to not have.
    */
   if (!is_array($e) || $e === []) { echo "no-payload"; return; }
   $bad=array_values(array_intersect(["password","plain_password","otp","fcm_token"], array_keys($e)));
   echo $bad ? implode(",",$bad) : "none";')
check "no credential column in the Form 16 payload" "none" "$LEAK"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left in tenant 6" "0" \
  "$(sql "select count(*) c from payroll_types where sub_institute_id=$TENANT and payroll_name='$HEAD_NAME'" | val c)"
check "  nor any scratch payslip" "0" \
  "$(sql "select count(*) c from employee_monthly_salary_data where sub_institute_id=$TENANT and year=$YEAR" | val c)"
rm -f /tmp/probe-ess.pdf /tmp/probe-ess-sc.pdf
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
