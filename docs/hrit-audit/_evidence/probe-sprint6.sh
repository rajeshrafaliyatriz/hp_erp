#!/usr/bin/env bash
# Sprint 6 evidence. F-124 (the approval chain) and F-109 (duplicate payslips).
#
# The claim being tested is NOT "a chain exists". It is: what the Leave
# Configuration screen saves now changes what the product does. So the probe
# writes a configuration through the API, applies for leave, and shows the
# approval behaviour change to match.
#
#   bash Docs/hrit-audit/_evidence/probe-sprint6.sh
#
# grep -P is unavailable in this Git Bash, which is how Sprint 0's first probe
# run sent empty tokens and read 401 as "properly locked down". awk instead.

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"

tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

ADMIN=$(tok 3 administrator)
EMP=$(tok 3 team_employee)   # Vikram (582): reports to the RM, sits in the DH's department
RM=$(tok 3 reporting_manager)
DH=$(tok 3 department_head)
HRM=$(tok 3 hr_manager)

pass=0; fail=0
check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
  else echo "  FAIL  $1 — expected [$2] got [$3]"; fail=$((fail+1)); fi
}

api() { # api <method> <path> <token> [body]
  local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b"
  else
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi
}

jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

echo "================ Sprint 6 — approval chain and payroll upsert ================"
echo

# ---------------------------------------------------------------------------
echo "1. The configuration screen's switches now build a chain"
# ---------------------------------------------------------------------------
# Turn multi-level ON for tenant 3, two levels: Reporting Manager then
# Department Head. This is exactly what the screen posts.
api PUT /api/leave/workflow "$ADMIN" \
  '{"reporting_manager_enabled":true,"department_head_enabled":true,"hr_enabled":false,"multi_level_enabled":true,"multi_level_count":2,"escalation_enabled":true,"escalation_time":24,"escalation_unit":"hours","escalate_to":"hr"}' >/dev/null

CHAIN=$(php artisan tinker --execute="echo json_encode(app(App\Services\Leave\LeaveApprovalWorkflow::class)->chainFor(3));" 2>/dev/null | tr -d '\r')
check "chain for tenant 3 is reporting_manager then department_head" \
  '["reporting_manager","department_head"]' "$CHAIN"

# ---------------------------------------------------------------------------
echo
echo "2. A new request opens the chain it was submitted under"
# ---------------------------------------------------------------------------
LT=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select id from hrms_leave_types where sub_institute_id=3 and deleted_at is null order by id limit 1" \
  | jq_ id)

FROM=$(php -r 'echo date("Y-m-d", strtotime("+45 days"));')
TO=$FROM

CREATED=$(api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$FROM\",\"to_date\":\"$TO\",\"day_type\":\"full\",\"comment\":\"sprint6 chain probe\"}")
LEAVE=$(echo "$CREATED" | jq_ data.id)
echo "     leave id $LEAVE"

STEPS=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select group_concat(concat(step_order,':',approver_role,'=',status) order by step_order) s
     from hrms_leave_approval_steps where leave_id=$LEAVE" | jq_ s)
check "two steps: step 1 pending, step 2 waiting" \
  "1:reporting_manager=pending,2:department_head=waiting" "$STEPS"

# ---------------------------------------------------------------------------
echo
echo "3. HR holds Organization scope and STILL cannot skip to the end"
# ---------------------------------------------------------------------------
# This is the whole point. Before this sprint the HR Manager's single approval
# decided the request, whatever the tenant had configured.
HRTRY=$(api POST "/api/leave/requests/$LEAVE/decision" "$HRM" '{"status":"approved"}')
check "HR Manager refused at step 1" "0" "$(echo "$HRTRY" | jq_ status)"
echo "     -> $(echo "$HRTRY" | jq_ message)"

ST=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select status from hrms_emp_leaves where id=$LEAVE" | jq_ status)
check "request untouched by the refused attempt" "pending" "$ST"

# ---------------------------------------------------------------------------
echo
echo "4. Step 1 approves — and the request does NOT become approved"
# ---------------------------------------------------------------------------
RM1=$(api POST "/api/leave/requests/$LEAVE/decision" "$RM" '{"status":"approved","hod_comment":"ok from RM"}')
check "step 1 accepted" "1" "$(echo "$RM1" | jq_ status)"
check "not final" "" "$(echo "$RM1" | jq_ workflow.final)"
check "now waiting on department_head" "department_head" "$(echo "$RM1" | jq_ workflow.next)"
echo "     -> $(echo "$RM1" | jq_ message)"

ST=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select status from hrms_emp_leaves where id=$LEAVE" | jq_ status)
check "request still pending after one approval of two" "pending" "$ST"

# ---------------------------------------------------------------------------
echo
echo "5. The same approver cannot approve twice"
# ---------------------------------------------------------------------------
RM2=$(api POST "/api/leave/requests/$LEAVE/decision" "$RM" '{"status":"approved"}')
check "reporting manager refused at step 2" "0" "$(echo "$RM2" | jq_ status)"

# ---------------------------------------------------------------------------
echo
echo "6. Step 2 approves — now it is approved"
# ---------------------------------------------------------------------------
DH1=$(api POST "/api/leave/requests/$LEAVE/decision" "$DH" '{"status":"approved","hod_comment":"ok from DH"}')
check "step 2 accepted" "1" "$(echo "$DH1" | jq_ status)"
check "final" "1" "$(echo "$DH1" | jq_ workflow.final)"

ST=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select status from hrms_emp_leaves where id=$LEAVE" | jq_ status)
check "request approved after BOTH approvals" "approved" "$ST"

echo "     chain as recorded:"
php Docs/hrit-audit/_evidence/snapshot.php \
  "select step_order, approver_role, status, approver_name, comment
     from hrms_leave_approval_steps where leave_id=$LEAVE order by step_order"

# ---------------------------------------------------------------------------
echo
echo "7. Escalation stamps a step that has waited too long"
# ---------------------------------------------------------------------------
# Raise a request OF OUR OWN to age, and age that one.
#
# The first version of this probe took the oldest live pending step instead, so
# every run escalated and then approved somebody's REAL leave request - four
# runs, four genuine tenant 3 requests approved as HR before it was noticed.
# They were restored by hand (leaves 4, 5, 7, 8 back to pending, their steps
# back to pending, escalated_at cleared). A probe must never consume production
# work to prove a point.
ESC_FROM=$(php -r 'echo date("Y-m-d", strtotime("+70 days"));')
ESC_CREATED=$(api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$ESC_FROM\",\"to_date\":\"$ESC_FROM\",\"day_type\":\"full\",\"comment\":\"sprint6 escalation probe\"}")
ESC_LEAVE=$(echo "$ESC_CREATED" | jq_ data.id)

STALE=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select id from hrms_leave_approval_steps
    where leave_id=$ESC_LEAVE and status='pending'
    order by step_order limit 1" | jq_ id)

php Docs/hrit-audit/_evidence/snapshot.php \
  "update hrms_leave_approval_steps set pending_since = date_sub(now(), interval 30 hour) where id=$STALE" >/dev/null

php artisan leave:escalate --tenant=3 2>&1 | tail -8

ESC=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select escalated_to from hrms_leave_approval_steps where id=$STALE" | jq_ escalated_to)
check "overdue step escalated to hr" "hr" "$ESC"

# And escalation WIDENS: HR can now decide a step assigned to the reporting manager.
STALE_LEAVE=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select leave_id from hrms_leave_approval_steps where id=$STALE" | jq_ leave_id)
HRESC=$(api POST "/api/leave/requests/$STALE_LEAVE/decision" "$HRM" '{"status":"approved","hr_remarks":"escalated to HR"}')
check "HR may decide the escalated step" "1" "$(echo "$HRESC" | jq_ status)"

RERUN=$(php artisan leave:escalate --tenant=3 2>&1 | tail -2)
echo "     re-run: $RERUN"

# ---------------------------------------------------------------------------
echo
echo "8. F-109 — a month saved twice leaves ONE payslip"
# ---------------------------------------------------------------------------
DUPES=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select count(*) c from (select employee_id from employee_monthly_salary_data
     group by employee_id, month, year, sub_institute_id having count(*)>1) d" | jq_ c)
echo "     employee-months holding more than one payslip: $DUPES"

# ---------------------------------------------------------------------------
echo
echo "9. F-126 — a finished request cannot be re-decided"
# ---------------------------------------------------------------------------
# The hole the chain OPENED. decision() enforced the chain inside `if ($step)`,
# and a finished chain has no pending step - so the check was skipped entirely
# and applyDecision() rewrote the status with no state guard at all. The
# backfill made it reachable on live: it wrote a CLOSED chain onto every
# already-decided request, so the "predates the chain" fall-through stopped
# catching legacy rows and started catching FINISHED ones.
#
# $LEAVE is approved from step 6 above. Try to reject it.
REDO=$(api POST "/api/leave/requests/$LEAVE/decision" "$HRM" '{"status":"rejected"}')
check "an approved request cannot be re-decided" "0" "$(echo "$REDO" | jq_ status)"
echo "     -> $(echo "$REDO" | jq_ message)"

ST=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select status from hrms_emp_leaves where id=$LEAVE" | jq_ status)
check "it is still approved, not rejected" "approved" "$ST"

# ---------------------------------------------------------------------------
echo
echo "10. Sent back is not a rejection — the chain restarts"
# ---------------------------------------------------------------------------
# recordDecision() used to treat anything that was not an approval as a
# rejection and skip every remaining step, so a sent-back request could never be
# approved again: its chain was closed and nothing reopened it. And store()
# matched only 'pending' rows, so re-submitting made a SECOND leave row.
SB_FROM=$(php -r 'echo date("Y-m-d", strtotime("+95 days"));')
SB=$(api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$SB_FROM\",\"to_date\":\"$SB_FROM\",\"day_type\":\"full\",\"comment\":\"sprint6 sent-back probe\"}")
SB_LEAVE=$(echo "$SB" | jq_ data.id)

api POST "/api/leave/requests/$SB_LEAVE/decision" "$RM" '{"status":"sent_back","hod_comment":"add the handover note"}' >/dev/null

SBSTEP=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select group_concat(concat(step_order,':',status,'/',coalesce(decision,'-')) order by step_order) s
     from hrms_leave_approval_steps where leave_id=$SB_LEAVE" | jq_ s)
check "step 1 records sent_back, step 2 back to waiting" \
  "1:sent_back/sent_back,2:waiting/-" "$SBSTEP"

# Re-submit the SAME dates. It must edit the same row, not create a second one.
BEFORE=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select count(*) c from hrms_emp_leaves where user_id=582 and from_date='$SB_FROM' and deleted_at is null" | jq_ c)
api POST /api/leave/requests "$EMP" \
  "{\"leave_type_id\":$LT,\"from_date\":\"$SB_FROM\",\"to_date\":\"$SB_FROM\",\"day_type\":\"full\",\"comment\":\"amended with handover note\"}" >/dev/null
AFTER=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select count(*) c from hrms_emp_leaves where user_id=582 and from_date='$SB_FROM' and deleted_at is null" | jq_ c)
check "re-submitting edits the same row, no duplicate" "$BEFORE" "$AFTER"

REOPENED=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select concat(l.status,'|',s.status) v from hrms_emp_leaves l
     join hrms_leave_approval_steps s on s.leave_id=l.id and s.step_order=1
    where l.id=$SB_LEAVE" | jq_ v)
check "request pending again, step 1 pending again" "pending|pending" "$REOPENED"

# And it can now actually be approved, which it could not before.
api POST "/api/leave/requests/$SB_LEAVE/decision" "$RM" '{"status":"approved"}' >/dev/null
api POST "/api/leave/requests/$SB_LEAVE/decision" "$DH" '{"status":"approved"}' >/dev/null
SBFINAL=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select status from hrms_emp_leaves where id=$SB_LEAVE" | jq_ status)
check "a sent-back request can be approved after amendment" "approved" "$SBFINAL"

# ---------------------------------------------------------------------------
echo
echo "11. Restore — tenant 3 goes back to the chain it had before the probe"
# ---------------------------------------------------------------------------
api PUT /api/leave/workflow "$ADMIN"   '{"reporting_manager_enabled":true,"department_head_enabled":true,"hr_enabled":false,"multi_level_enabled":false,"multi_level_count":2,"escalation_enabled":true,"escalation_time":24,"escalation_unit":"hours","escalate_to":"hr"}' >/dev/null
api DELETE "/api/leave/requests/$LEAVE" "$EMP" >/dev/null 2>&1
php Docs/hrit-audit/_evidence/snapshot.php   "update hrms_emp_leaves set deleted_at=now() where id in ($LEAVE,$ESC_LEAVE,$SB_LEAVE)" >/dev/null
echo "     multi-level switched back off; probe requests $LEAVE, $ESC_LEAVE and $SB_LEAVE removed"

echo
echo "=============================================================="
echo "  PASS $pass   FAIL $fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
