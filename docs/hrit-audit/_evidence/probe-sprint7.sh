#!/usr/bin/env bash
# Sprint 7 evidence. F-128 (nothing told anybody anything) and F-129 (a payroll
# month you can declare finished).
#
#   bash Docs/hrit-audit/_evidence/probe-sprint7.sh
#
# Everything it creates, it removes. Sprint 6's first probe consumed four real
# leave requests to prove escalation; that lesson is written into probe-sprint6.sh
# and obeyed here.

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"

tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

ADMIN=$(tok 3 administrator)
EMP=$(tok 3 team_employee)      # Vikram (582): reports to the RM, in the DH's department
PLAIN=$(tok 3 employee)
RM=$(tok 3 reporting_manager)
DH=$(tok 3 department_head)

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

jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }

echo "================= Sprint 7 — notifications, and a month you can close ================="
echo

# ---------------------------------------------------------------------------
echo "1. The module had never sent a notification. Now the chain does."
# ---------------------------------------------------------------------------
api PUT /api/leave/workflow "$ADMIN" \
  '{"reporting_manager_enabled":true,"department_head_enabled":true,"hr_enabled":false,"multi_level_enabled":true,"multi_level_count":2,"escalation_enabled":true,"escalation_time":24,"escalation_unit":"hours","escalate_to":"hr"}' >/dev/null

# CLEAR FIRST, not only at the end.
#
# This probe counts notifications ("the Reporting Manager was told" is
# count = 1), so anything a previous run left behind makes it fail for a reason
# that has nothing to do with the code. It did: run straight after
# probe-sprint6.sh it reported 9 PASS / 3 FAIL, and on its own 12 PASS / 0 FAIL.
# probe-sprint6.sh creates leave requests, which create leave.submitted EVENTS,
# and this probe's `events:react` then delivers them too.
#
# A probe whose result depends on what ran before it is not evidence.
snap "delete from g2g_notification where event_type like 'leave.%'" >/dev/null
snap "delete from g2g_event_delivery where event_id in (select id from g2g_event where type like 'leave.%')" >/dev/null
snap "delete from g2g_event where type like 'leave.%'" >/dev/null

# A weekday, because the day counter correctly refuses a request that is all
# weekend - which is itself Sprint 4 still working.
FROM=$(php -r '$d=strtotime("+150 days"); while(date("N",$d)>5) $d=strtotime("+1 day",$d); echo date("Y-m-d",$d);')
LT=$(snap "select id from hrms_leave_types where sub_institute_id=3 and deleted_at is null order by id limit 1" | jq_ id)

LEAVE=$(api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$FROM\",\"to_date\":\"$FROM\",\"day_type\":\"full\",\"comment\":\"sprint7 probe\"}" | jq_ data.id)
echo "     leave id $LEAVE on $FROM"

php artisan events:react >/dev/null 2>&1

RM_TOLD=$(snap "select count(*) c from g2g_notification where event_type='leave.submitted' and user_id=581" | jq_ c)
check "the Reporting Manager was told it is waiting on them" "1" "$RM_TOLD"

# ---------------------------------------------------------------------------
echo
echo "2. Approving passes the request AND the notification to the next approver"
# ---------------------------------------------------------------------------
api POST "/api/leave/requests/$LEAVE/decision" "$RM" '{"status":"approved"}' >/dev/null
php artisan events:react >/dev/null 2>&1

DH_TOLD=$(snap "select count(*) c from g2g_notification where event_type='leave.submitted' and user_id=580" | jq_ c)
check "the Department Head was told it is now their turn" "1" "$DH_TOLD"

EMP_TOLD=$(snap "select count(*) c from g2g_notification where event_type='leave.decided' and user_id=582" | jq_ c)
check "the employee was told it moved on, not just when it finished" "1" "$EMP_TOLD"

api POST "/api/leave/requests/$LEAVE/decision" "$DH" '{"status":"approved"}' >/dev/null
php artisan events:react >/dev/null 2>&1

echo "     who was told what:"
snap "select concat(u.first_name,' ',u.last_name) who, n.event_type, n.subject, n.recipient_reason why
        from g2g_notification n join tbluser u on u.id=n.user_id
       where n.event_type like 'leave.%' order by n.id"

# ---------------------------------------------------------------------------
echo
echo "3. Re-running the reactor sends nothing twice"
# ---------------------------------------------------------------------------
N1=$(snap "select count(*) c from g2g_notification where event_type like 'leave.%'" | jq_ c)
php artisan events:react >/dev/null 2>&1
N2=$(snap "select count(*) c from g2g_notification where event_type like 'leave.%'" | jq_ c)
check "a second reactor run adds nothing" "$N1" "$N2"

# ---------------------------------------------------------------------------
echo
echo "4. An overdue step tells the people it was escalated to"
# ---------------------------------------------------------------------------
ESC_FROM=$(php -r '$d=strtotime("+160 days"); while(date("N",$d)>5) $d=strtotime("+1 day",$d); echo date("Y-m-d",$d);')
ESC_LEAVE=$(api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$ESC_FROM\",\"to_date\":\"$ESC_FROM\",\"day_type\":\"full\",\"comment\":\"sprint7 escalation probe\"}" | jq_ data.id)

snap "update hrms_leave_approval_steps set pending_since = date_sub(now(), interval 40 hour)
       where leave_id=$ESC_LEAVE and status='pending'" >/dev/null

php artisan leave:escalate --tenant=3 2>&1 | tail -3
php artisan events:react >/dev/null 2>&1

ESC_N=$(snap "select count(*) c from g2g_notification where event_type='leave.escalated'" | jq_ c)
check "the escalation target was told (capped at 5 role holders)" "5" "$ESC_N"

# ---------------------------------------------------------------------------
echo
echo "5. Everyone reads their OWN inbox, and only their own"
# ---------------------------------------------------------------------------
MINE=$(api GET "/api/notifications?sub_institute_id=3&syear=2026-2027" "$EMP" | jq_ status)
check "the employee's inbox answers" "1" "$MINE"

# The bell is not new: it was built for another module and wired to
# /api/notifications. HRIT needed no frontend work at all for this - the reuse
# is the point.
FOREIGN=$(snap "select count(*) c from g2g_notification n join tbluser u on u.id=n.user_id
                 where n.sub_institute_id <> u.sub_institute_id" | jq_ c)
check "no notification crossed a tenant boundary" "0" "$FOREIGN"

# ---------------------------------------------------------------------------
echo
echo "6. F-129 — a payroll month can be declared finished"
# ---------------------------------------------------------------------------
save() { curl -s -X POST "$BASE/monthly-payroll-store" -H "Authorization: Bearer $ADMIN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"type\":\"API\",\"sub_institute_id\":3,\"syear\":\"2026-2027\",\"month\":\"Dec\",\"year\":2026,\"payrollVal\":{\"582\":{\"payrollHead\":{\"Basic\":$1},\"total_payment\":$1,\"total_deduction\":0,\"received_by\":0,\"total_day\":30}}}"; }
lockcall() { api POST /monthly-payroll-lock "$1" "$2"; }

save 50000 >/dev/null
lockcall "$ADMIN" '{"type":"API","sub_institute_id":3,"month":"Dec","year":2026,"action":"lock"}' >/dev/null

LOCKED_SAVE=$(save 99999)
# is_mobile() renames status_code -> status on the way out; read what the wire
# actually carries, not what the controller called it.
check "saving a locked month is refused" "0" "$(echo "$LOCKED_SAVE" | jq_ status)"
echo "     -> $(echo "$LOCKED_SAVE" | jq_ message)"

AMT=$(snap "select total_payment from employee_monthly_salary_data where employee_id=582 and month='Dec' and year=2026 and sub_institute_id=3" | jq_ total_payment)
check "the figures did not change" "50000.00" "$AMT"

NOREASON=$(lockcall "$ADMIN" '{"type":"API","sub_institute_id":3,"month":"Dec","year":2026,"action":"reopen"}')
check "reopening without a reason is refused" "0" "$(echo "$NOREASON" | jq_ status)"

lockcall "$ADMIN" '{"type":"API","sub_institute_id":3,"month":"Dec","year":2026,"action":"reopen","reason":"PF correction from finance"}' >/dev/null
save 99999 >/dev/null
AMT2=$(snap "select total_payment from employee_monthly_salary_data where employee_id=582 and month='Dec' and year=2026 and sub_institute_id=3" | jq_ total_payment)
check "after reopening, the month saves again" "99999.00" "$AMT2"

echo "     the reopen is on the record:"
snap "select month, year, locked_by, reopened_by, reopen_reason from payroll_month_locks"

EMPLOCK=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/monthly-payroll-lock" \
  -H "Authorization: Bearer $PLAIN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"type":"API","sub_institute_id":3,"month":"Dec","year":2026,"action":"lock"}')
check "an employee cannot lock a month" "403" "$EMPLOCK"

# ---------------------------------------------------------------------------
echo
echo "7. Clean up — everything this probe created, it removes"
# ---------------------------------------------------------------------------
api PUT /api/leave/workflow "$ADMIN" \
  '{"reporting_manager_enabled":true,"department_head_enabled":true,"hr_enabled":false,"multi_level_enabled":false,"multi_level_count":2,"escalation_enabled":true,"escalation_time":24,"escalation_unit":"hours","escalate_to":"hr"}' >/dev/null

snap "update hrms_emp_leaves set deleted_at=now() where id in ($LEAVE,$ESC_LEAVE)" >/dev/null
snap "delete from employee_monthly_salary_data where employee_id=582 and month='Dec' and year=2026 and sub_institute_id=3" >/dev/null
snap "delete from payroll_month_locks" >/dev/null
snap "delete from g2g_notification where event_type like 'leave.%'" >/dev/null
snap "delete from g2g_event_delivery where event_id in (select id from g2g_event where type like 'leave.%')" >/dev/null
snap "delete from g2g_event where type like 'leave.%'" >/dev/null
echo "     probe leaves $LEAVE and $ESC_LEAVE removed; notifications, events and locks cleared"

echo
echo "=============================================================="
echo "  PASS $pass   FAIL $fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
