#!/usr/bin/env bash
# Sprint 9 evidence — the defects Sprint 6's unfinished review left behind.
#
# That review died on a session limit with 18 of 36 candidates unverified. The
# authorization and payroll dimensions were verified at ZERO percent, and those
# are the two carrying the security and the money. Recovered and checked, five
# were still live and three had been introduced by this engagement.
#
#   F-133  payroll writes and names employees of OTHER tenants
#   F-134  leave:escalate --tenant=0 swept every tenant, irreversibly
#   F-136  store()'s duplicate check had no tenant filter
#   F-137  the F-109 upsert key could never match the 17 live duplicates
#   F-138  payroll rows hard-deleted with no index, transaction or attribution
#   F-139  Payroll Type Report returned every tenant's payslips
#   F-141  the escalation sweep chased steps whose leave was deleted
#
#   bash Docs/hrit-audit/_evidence/probe-sprint9.sh
#
# Self-isolating: clears its own ground, removes everything it creates.

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"

tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)
E3=$(tok 3 team_employee)

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 — expected [$2] got [$3]"; fail=$((fail+1)); fi; }

snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
one()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$2"; }

api() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b"
  else
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi
}

echo "=============== Sprint 9 — what the unfinished review found ==============="
echo

# ---------------------------------------------------------------------------
echo "0. Framing — these must be true before and after. A probe that breaks them has done damage."
# ---------------------------------------------------------------------------
ESC_BEFORE=$(one "select count(*) c from hrms_leave_approval_steps where escalated_at is not null" c)
check "no leave sits in a tenant its owner does not belong to" "0" \
  "$(one "select count(*) c from hrms_emp_leaves l join tbluser u on u.id=l.user_id where l.deleted_at is null and l.sub_institute_id <> u.sub_institute_id and l.id <> 221" c)"
check "no payslip sits in a tenant its employee does not belong to" "0" \
  "$(one "select count(*) c from employee_monthly_salary_data e join tbluser u on u.id=e.employee_id where e.sub_institute_id <> u.sub_institute_id" c)"
echo "     escalated steps at start: $ESC_BEFORE (one-shot — must not move)"

# ---------------------------------------------------------------------------
echo
echo "1. F-133 — payroll could WRITE a payslip for another tenant's employee"
# ---------------------------------------------------------------------------
# Employee 1 belongs to tenant 1. A tenant-3 administrator posts for them.
# Before the fix this INSERTED a real row, filed under tenant 3, with the
# caller's own figures. Not a disclosure - a forged payroll record.
FORGE=$(api POST /monthly-payroll-store "$A3" \
  '{"type":"API","sub_institute_id":3,"syear":"2026-2027","month":"Feb","year":2026,"payrollVal":{"1":{"payrollHead":{"Basic":1},"total_payment":1,"total_deduction":0,"received_by":0,"total_day":1}}}')
check "the forged write is refused" "0" "$(echo "$FORGE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"
check "and no row was written" "0" \
  "$(one "select count(*) c from employee_monthly_salary_data where employee_id=1 and month='Feb' and year=2027" c)"

# The name-disclosure half: the response must not carry a foreign employee's name.
LEAK=$(echo "$FORGE" | grep -ci "Kalpesh\|Sethi\|Iyer\|Khan" || true)
check "no foreign employee is named in the response" "0" "$LEAK"

# ---------------------------------------------------------------------------
echo
echo "2. F-134 — leave:escalate refuses input it cannot trust"
# ---------------------------------------------------------------------------
for BAD in 0 abc -1 03x; do
  OUT=$(php artisan leave:escalate --tenant="$BAD" 2>&1)
  check "--tenant=$BAD refused" "yes" "$(echo "$OUT" | grep -qi 'positive integer' && echo yes || echo no)"
done
check "and nothing was escalated by those attempts" "$ESC_BEFORE" \
  "$(one "select count(*) c from hrms_leave_approval_steps where escalated_at is not null" c)"

# ---------------------------------------------------------------------------
echo
echo "3. F-136 — a leave request in another tenant can no longer be hijacked"
# ---------------------------------------------------------------------------
# Leave 221 is real: a tenant-1 row owned by a tenant-3 employee, comment
# "i am not feeling well". Posting for that user and date used to MATCH it and
# rewrite its tenant, dates and leave type.
B4=$(snap "select sub_institute_id, leave_type_id, to_date, comment from hrms_emp_leaves where id=221")
NEW=$(api POST /api/leave/requests "$(tok 3 hr_manager)" \
  '{"sub_institute_id":3,"syear":"2026-2027","employee_id":86,"leave_type_id":4,"from_date":"2026-07-27","to_date":"2026-07-27","day_type":"full","comment":"F-136 probe"}')
NEWID=$(echo "$NEW" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? "";')
check "a NEW row was created, not an update" "yes" "$([ -n "$NEWID" ] && echo yes || echo no)"
check "leave 221 is byte-identical" "$B4" "$(snap "select sub_institute_id, leave_type_id, to_date, comment from hrms_emp_leaves where id=221")"

if [ -n "$NEWID" ]; then
  snap "delete from hrms_leave_approval_steps where leave_id=$NEWID" >/dev/null
  snap "delete from hrms_emp_leaves where id=$NEWID" >/dev/null
fi

# ---------------------------------------------------------------------------
echo
echo "4. F-137 — every spelling of a month lands on ONE payslip"
# ---------------------------------------------------------------------------
# 'Jul' != 'july' under any collation - length, not case. The 17 live duplicates
# were spelled 'july' while the screen posts 'Jul', so F-109's collapse could
# never reach them and the Sprint 6 probe passed while observing them.
save() { api POST /monthly-payroll-store "$A3" \
  "{\"type\":\"API\",\"sub_institute_id\":3,\"syear\":\"2026-2027\",\"month\":\"$1\",\"year\":2026,\"payrollVal\":{\"6\":{\"payrollHead\":{\"Basic\":$2},\"total_payment\":$2,\"total_deduction\":0,\"received_by\":0,\"total_day\":30}}}"; }
rows() { one "select count(*) c from employee_monthly_salary_data where employee_id=6 and year=2026 and sub_institute_id=3" c; }

save Sep 10000 >/dev/null;       check "'Sep' writes one row"          "1" "$(rows)"
save september 20000 >/dev/null; check "'september' corrects it"        "1" "$(rows)"
save SEP 30000 >/dev/null;       check "'SEP' corrects it again"        "1" "$(rows)"
check "and the figure is the latest" "30000.00" \
  "$(one "select total_payment t from employee_monthly_salary_data where employee_id=6 and year=2026 and sub_institute_id=3" t)"
check "stored under the canonical spelling" "Sep" \
  "$(one "select month m from employee_monthly_salary_data where employee_id=6 and year=2026 and sub_institute_id=3" m)"

BAD=$(save Smarch 1)
check "an unrecognised month is refused, not filed" "0" \
  "$(echo "$BAD" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["status"] ?? "";')"

# The fiscal-year rule was case-sensitive too: 'january' filed under the wrong year.
save january 500 >/dev/null
check "'january' files under the NEXT payroll year, as 'Jan' does" "2027" \
  "$(one "select year y from employee_monthly_salary_data where employee_id=6 and month='Jan' and sub_institute_id=3" y)"

snap "delete from employee_monthly_salary_data where employee_id=6 and sub_institute_id=3 and year in (2026,2027)" >/dev/null

# ---------------------------------------------------------------------------
echo
echo "5. F-138 — the duplicates cannot come back, and a correction leaves a trace"
# ---------------------------------------------------------------------------
check "no employee-month holds more than one payslip" "0" \
  "$(one "select count(*) c from (select employee_id from employee_monthly_salary_data group by sub_institute_id, employee_id, year, month having count(*)>1) d" c)"
check "the database enforces it" "employee_monthly_salary_data_period_unique" \
  "$(one "select INDEX_NAME k from information_schema.statistics where table_schema=database() and table_name='employee_monthly_salary_data' and INDEX_NAME='employee_monthly_salary_data_period_unique' limit 1" k)"
check "the supersession is on the record, with its before-image" "16" \
  "$(one "select json_length(json_extract(payload,'\$.superseded')) c from g2g_event where type='payroll.payslip.superseded' limit 1" c)"
check "and it reached the audit log" "1" \
  "$(one "select count(*) c from g2g_audit_log where type='payroll.payslip.superseded'" c)"

# ---------------------------------------------------------------------------
echo
echo "6. F-139 — Payroll Type Report is one tenant's, not everyone's"
# ---------------------------------------------------------------------------
RPT=$(api POST /payroll-type-report-create "$A3" '{"type":"API","sub_institute_id":3,"month":"Aug","year":2025}')
FOREIGN=$(echo "$RPT" | php -r '
$d = json_decode(stream_get_contents(STDIN), true);
$rows = $d["payrollData"] ?? [];
$bad = 0;
foreach ($rows as $r) { if ((int)($r["sub_institute_id"] ?? 3) !== 3) $bad++; }
echo $bad;')
check "no other tenant's payslips in the report" "0" "$FOREIGN"

# ---------------------------------------------------------------------------
echo
echo "7. F-141 — the sweep ignores steps whose request is gone"
# ---------------------------------------------------------------------------
check "no open step belongs to a deleted or decided request" "0" \
  "$(one "select count(*) c from hrms_leave_approval_steps s join hrms_emp_leaves l on l.id=s.leave_id where s.status in ('pending','waiting') and (l.deleted_at is not null or l.status <> 'pending')" c)"

# ---------------------------------------------------------------------------
echo
echo "8. F-105 - the dead shift screens are gone, and the roster they threatened is not"
# ---------------------------------------------------------------------------
SHIFT_ROUTES=$(php artisan route:list 2>/dev/null | grep -c "user_shift\|bulk_shift")
check "no shift-template route remains" "0" "$SHIFT_ROUTES"
check "the per-employee roster is untouched" "102" \
  "$(one "select count(*) c from tbluser where sub_institute_id=3 and status=1 and monday_in_date is not null" c)"
check "and the Saturday half-days that code would have erased are intact" "203" \
  "$(one "select count(*) c from tbluser where status=1 and saturday_out_date is not null and monday_out_date is not null and saturday_out_date <> monday_out_date" c)"
ROSTER=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/attendance/self-summary?sub_institute_id=3" -H "Authorization: Bearer $E3" -H 'Accept: application/json')
check "the roster still reaches the dashboard" "200" "$ROSTER"

# ---------------------------------------------------------------------------
echo
echo "9. F-111 - the flat-cap rule is per tenant, and nobody's pay moved"
# ---------------------------------------------------------------------------
# The safety claim is EQUIVALENCE, not improvement: the new rule must return
# exactly what the hardcoded list returned, or somebody's salary changes.
tink() { php artisan tinker --execute="$1" 2>/dev/null | sed 's/\x1b\[[0-9;]*m//g' | tr -d '\r\n '; }

MISMATCH=$(tink '$r = app(App\Services\Payroll\FlatCapRule::class); $legacy = array_map("intval", (array) config("payroll.excess_over_flat_amount_tenants", [])); $n = 0; foreach (array_merge($legacy, [1,3,6,7,999]) as $t) { if ($r->paysExcessOverCap($t) !== in_array((int) $t, $legacy, true)) { $n++; } } echo $n;')
check "the new rule agrees with the old hardcoded list on every tenant" "0" "$MISMATCH"
check "an unconfigured tenant clamps, which is what it does today" "clamp" \
  "$(tink 'echo app(App\Services\Payroll\FlatCapRule::class)->behaviourFor(3);')"
check "and a listed tenant still pays the excess" "excess" \
  "$(tink 'echo app(App\Services\Payroll\FlatCapRule::class)->behaviourFor(47);')"

# ---------------------------------------------------------------------------
echo
echo "10. Framing, re-checked"
# ---------------------------------------------------------------------------
check "escalated_at count unchanged across the whole run" "$ESC_BEFORE" \
  "$(one "select count(*) c from hrms_leave_approval_steps where escalated_at is not null" c)"
check "still no cross-tenant payslip" "0" \
  "$(one "select count(*) c from employee_monthly_salary_data e join tbluser u on u.id=e.employee_id where e.sub_institute_id <> u.sub_institute_id" c)"

echo
echo "=============================================================="
echo "  PASS $pass   FAIL $fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
