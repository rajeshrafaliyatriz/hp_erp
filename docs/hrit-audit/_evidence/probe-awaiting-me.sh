#!/usr/bin/env bash
# F-208. "My Pending Approvals" means it now.
#
#   bash Docs/hrit-audit/_evidence/probe-awaiting-me.sh
#
# The Leave Requests screen shipped a preset labelled "My Pending Approvals"
# that applied `status=pending` and nothing else, so it returned every pending
# request in the caller's scope - including ones sitting with somebody else. It
# was renamed to "Pending Approvals" because the API could not express the
# scope its label claimed. `awaiting_me=1` is that missing expression.
#
# THE TRAP THIS PROBE EXISTS TO AVOID: the filter returns 0 for every token on
# this deployment, because no token's role matches a pending step inside that
# token's scope. A filter that only ever returns zero cannot be told from one
# that returns nothing at all. So this creates a request that IS awaiting a
# specific approver, and asserts it appears for them and for nobody else.
#
# Creates its own leave and removes it by marker (F-157/F-163).
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

# Sprint 6 established this chain: user 582 reports to 581 (Reporting Manager,
# scope Team) and sits in department 1930 with 580 (Department Head). So a
# request from 582 lands on 581's desk and nobody else's.
SUBJECT=$(tok 3 team_employee)    # user 582 - raises the request
RM=$(tok 3 reporting_manager)     # user 581 - should see it
DH=$(tok 3 department_head)       # user 580 - should NOT, it is not their step
HR=$(tok 3 hr_manager)            # user 67  - should NOT
EMP=$(tok 3 employee)             # user 7   - not an approver at all

MARKER='awaiting-me probe'

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

sql() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

# The next non-Sunday on or after +N days (F-157: a fixed offset lands on the
# weekly off depending on the day the probe runs, and the apply is refused).
workday() { php -r '$d=strtotime("+".$argv[1]." days"); while((int)date("w",$d)===0){$d=strtotime("+1 day",$d);} echo date("Y-m-d",$d);' "$1"; }

awaiting() {  # awaiting <token>  -> how many requests are awaiting THEM
  curl -s -m 60 "$BASE/api/leave/requests?token=$1&awaiting_me=1&per_page=1" \
    -H 'Accept: application/json' | jq_ pagination.total; }

pending_visible() {  # everything pending in their scope, for contrast
  curl -s -m 60 "$BASE/api/leave/requests?token=$1&status%5B0%5D=pending&per_page=1" \
    -H 'Accept: application/json' | jq_ pagination.total; }

teardown() {
  ids=$(sql "select group_concat(id) g from hrms_emp_leaves where comment='$MARKER'" | jq_ g)
  if [ -n "$ids" ]; then
    sql "delete from hrms_leave_approval_steps where leave_id in (0,$ids)" >/dev/null
    sql "delete from hrms_emp_leaves where comment='$MARKER'" >/dev/null
  fi
}

echo
echo "================ F-208 - awaiting_me ================"
trap teardown EXIT
teardown   # start-of-run clear, so a crashed run cannot poison the next

# A balance to spend, or the apply is refused for a reason unrelated to this.
curl -s -m 60 -X PUT "$BASE/api/leave/allocations" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$HR\",\"allocations\":[{\"department_id\":1930,\"leave_type_id\":4,\"value\":10}]}" >/dev/null

echo
echo "1. Before: nothing of ours exists"
BEFORE_RM=$(awaiting "$RM")
check "the reporting manager starts with a known count" "yes" \
      "$([ -n "$BEFORE_RM" ] && echo yes || echo no)"

echo
echo "2. A request is raised that lands on the reporting manager's desk"
FROM=$(workday 38)
NEW=$(curl -s -m 60 -X POST "$BASE/api/leave/requests" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$SUBJECT\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"$FROM\",\"to_date\":\"$FROM\",\"comment\":\"$MARKER\"}")
LEAVE=$(echo "$NEW" | jq_ data.id)
check "it was created" "yes" "$([ -n "$LEAVE" ] && echo yes || echo no)"

STEP=$(sql "select approver_role r from hrms_leave_approval_steps where leave_id=$LEAVE and status='pending' order by step_order limit 1" | jq_ r)
check "  and its pending step is the reporting manager's" "reporting_manager" "$STEP"

echo
echo "3. It appears for the approver whose step it is"
AFTER_RM=$(awaiting "$RM")
check "the reporting manager's awaiting count went up by one ($BEFORE_RM -> $AFTER_RM)" "yes" \
      "$([ "$AFTER_RM" = "$((BEFORE_RM + 1))" ] && echo yes || echo no)"

echo
echo "4. And for nobody else - which is the whole point of the label"
# The Department Head CAN see it (it is in their department) but it is not
# their step yet, so it must not be reported as awaiting THEM. That contrast is
# the assertion: visible > 0, awaiting = 0.
DH_VISIBLE=$(pending_visible "$DH")
DH_AWAITING=$(awaiting "$DH")
check "the department head can see pending requests ($DH_VISIBLE)" "yes" \
      "$([ "$DH_VISIBLE" != "" ] && [ "$DH_VISIBLE" -gt 0 ] 2>/dev/null && echo yes || echo no)"
check "  but none is awaiting THEM" "0" "$DH_AWAITING"

HR_VISIBLE=$(pending_visible "$HR")
check "HR can see pending requests ($HR_VISIBLE)" "yes" \
      "$([ "$HR_VISIBLE" != "" ] && [ "$HR_VISIBLE" -gt 0 ] 2>/dev/null && echo yes || echo no)"
check "  but none is awaiting them either" "0" "$(awaiting "$HR")"

echo
echo "5. A non-approver is told nothing is waiting, not refused"
c=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$BASE/api/leave/requests?token=$EMP&awaiting_me=1&per_page=1" -H 'Accept: application/json')
check "an employee asking gets 200, not 403" "200" "$c"
check "  and an empty list" "0" "$(awaiting "$EMP")"

echo
echo "6. Deciding it takes it off the desk"
curl -s -m 60 -X POST "$BASE/api/leave/requests/$LEAVE/decision" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d "{\"token\":\"$RM\",\"status\":\"approved\"}" >/dev/null
check "the reporting manager is back to where they started" "$BEFORE_RM" "$(awaiting "$RM")"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left behind" "0" \
      "$(sql "select count(*) c from hrms_emp_leaves where comment='$MARKER'" | jq_ c)"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
