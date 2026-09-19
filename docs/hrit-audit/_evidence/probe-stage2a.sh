#!/usr/bin/env bash
# STAGE 2a - F-164. Taking your own leave request back.
#
#   bash Docs/hrit-audit/_evidence/probe-stage2a.sh
#
# Both endpoints were implemented, permission-correct, and reachable by nothing:
#
#   DELETE /api/leave/requests/{id}         withdraw a PENDING request (own)
#   POST   /api/leave/requests/{id}/cancel  cancel an APPROVED, unstarted one
#
# leaveService.withdrawRequest was DEFINED at services/hrms/leave.ts:582 with
# zero call sites; there was no cancelRequest at all. So an employee could apply
# for leave and never take it back - every cancellation went through HR by
# message.
#
# This asserts the rules the drawer's canWithdraw/canCancel now mirror. If the
# server and the UI ever disagree, the UI offers a button that is certain to be
# refused, which is worse than no button.
#
# Creates its own data, clears by its own MARKER at start AND end (F-157/F-163),
# and never touches a pre-existing row.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

EMP=$(tok 3 employee)        # user 7
OTHER=$(tok 3 team_employee) # user 582 - a colleague, for the ownership checks
HR=$(tok 3 hr_manager)       # user 67  - grants the allocation
# F-124. Tenant 3's chain is ONE step, and that step is reporting_manager - not
# HR. Approving with the HR token returns "You are not the approver for this
# step", the request stays pending, and every cancel assertion below then fails
# for a reason that has nothing to do with cancel. Approve through the chain.
RM=$(tok 3 reporting_manager) # user 581

MARKER='stage2a takeback probe'

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

api() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -m 60 -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b"
  else
    curl -s -m 60 -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi; }

codeof() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -m 60 -o /dev/null -w '%{http_code}' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b"
  else
    curl -s -m 60 -o /dev/null -w '%{http_code}' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json'
  fi; }

jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

# The next non-Sunday on or after +N days. F-157: a fixed offset lands on the
# weekly off depending on which day the probe is run, the apply is correctly
# refused, and assertions fail for a reason unrelated to the code under test.
workday() { php -r '$d=strtotime("+".$argv[1]." days"); while((int)date("w",$d)===0){$d=strtotime("+1 day",$d);} echo date("Y-m-d",$d);' "$1"; }

sql() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }

sweep() {
  php -r '
$lines=file(".env"); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"\x27"); }
$p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]);
$ids=$p->prepare("select id from hrms_emp_leaves where comment = ?"); $ids->execute([$argv[1]]);
$list=$ids->fetchAll(PDO::FETCH_COLUMN);
if ($list) {
  $in=implode(",", array_map("intval",$list));
  $p->exec("delete from hrms_leave_approval_steps where leave_id in ($in)");
  $p->exec("delete from hrms_emp_leaves where id in ($in)");
}
$p->exec("delete from hrms_leave_allocation where sub_institute_id=3 and created_by=7");
echo "  ".$argv[2].": removed ".count($list)." leave row(s)\n";' "$MARKER" "$1"
}

echo
echo "================ Stage 2a - withdraw and cancel ================"
sweep "start-of-run clear"

# A balance to spend. Without one every apply below 422s on "0 remaining" and
# the whole file goes red for a reason that is not what it is testing.
api PUT /api/leave/allocations "$HR" \
  '{"allocations":[{"department_id":35,"leave_type_id":4,"value":10}]}' >/dev/null

mk() { # mk <from> -> echoes new leave id
  api POST /api/leave/requests "$EMP" \
    "{\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"$1\",\"to_date\":\"$1\",\"comment\":\"$MARKER\"}" \
    | jq_ data.id; }

echo
echo "WITHDRAW - own PENDING request"
L1=$(mk "$(workday 40)")
check "a request was created to work with" "yes" "$([ -n "$L1" ] && echo yes || echo no)"

# A colleague must not be able to withdraw it. This is F-88, re-asserted here
# because the UI now exposes the button and a regression would be silent.
c=$(codeof DELETE "/api/leave/requests/$L1" "$OTHER")
check "a colleague cannot withdraw it -> 403" "403" "$c"
st=$(sql "select status from hrms_emp_leaves where id=$L1" | jq_ status)
check "  and it is untouched" "pending" "$st"

c=$(codeof DELETE "/api/leave/requests/$L1" "$EMP")
check "the applicant withdraws their own -> 200" "200" "$c"
gone=$(sql "select count(*) c from hrms_emp_leaves where id=$L1 and deleted_at is not null" | jq_ c)
check "  row is soft-deleted, not hard-deleted" "1" "$gone"
open=$(sql "select count(*) c from hrms_leave_approval_steps where leave_id=$L1 and status in ('pending','waiting')" | jq_ c)
check "  no approval step left in anyone's queue" "0" "$open"

echo
echo "WITHDRAW - the cases the UI must NOT offer"
L2=$(mk "$(workday 44)")

# Approved directly, not through /decision, and the reason matters.
#
# Tenant 3's chain is one step and that step is reporting_manager, whose scope
# is "Team". User 581 holds the reporting_manager token but sits in department
# 1930; user 7 is in department 35, so 581 is not on user 7's chain and the API
# correctly answers "Leave request not found". HR Manager has Organization
# scope but cannot jump step 1.
#
# Getting a real chain approval here would mean provisioning a manager for
# user 7 - which is probe-sprint6's subject, covered there by 20 assertions.
# This file is about what cancel and withdraw do to an approved row, so the row
# is put in that state directly and the chain is left out of it.
sql "update hrms_emp_leaves set status='approved', updated_at=now() where id=$L2" >/dev/null
sql "update hrms_leave_approval_steps set status='approved', decided_at=now() where leave_id=$L2" >/dev/null
st=$(sql "select status from hrms_emp_leaves where id=$L2" | jq_ status)
check "second request is approved, ready for the cancel path" "approved" "$st"

c=$(codeof DELETE "/api/leave/requests/$L2" "$EMP")
check "withdraw on an APPROVED request -> 422" "422" "$c"
m=$(api DELETE "/api/leave/requests/$L2" "$EMP" | jq_ message)
check "  and it says why" "Only a pending leave request can be withdrawn" "$m"

echo
echo "CANCEL - own APPROVED request that has not started"
c=$(codeof POST "/api/leave/requests/$L2/cancel" "$EMP" '{"reason":"plans changed"}')
check "the applicant cancels it -> 200" "200" "$c"
st=$(sql "select status from hrms_emp_leaves where id=$L2" | jq_ status)
check "  status is cancelled" "cancelled" "$st"
open=$(sql "select count(*) c from hrms_leave_approval_steps where leave_id=$L2 and status in ('pending','waiting')" | jq_ c)
check "  no approval step left open" "0" "$open"

echo
echo "CANCEL - the cases the UI must NOT offer"
L3=$(mk "$(workday 48)")
c=$(codeof POST "/api/leave/requests/$L3/cancel" "$EMP")
check "cancel on a PENDING request -> 422" "422" "$c"
m=$(api POST "/api/leave/requests/$L3/cancel" "$EMP" | jq_ message)
check "  and it points at the right verb" "This request has not been approved yet - withdraw it instead." "$m"

# Leave that has already started. Written directly because the apply endpoint
# correctly refuses a past date - the rule under test is cancel's, not apply's.
PAST=$(php -r 'echo date("Y-m-d", strtotime("-3 days"));')
sql "insert into hrms_emp_leaves
      (sub_institute_id, department_id, user_id, leave_type_id, day_type, from_date, to_date,
       chargeable_days, comment, status, created_at, updated_at)
     values (3, 35, 7, 4, 'full', '$PAST', '$PAST', 1, '$MARKER', 'approved', now(), now())" >/dev/null
L4=$(sql "select max(id) id from hrms_emp_leaves where comment='$MARKER' and status='approved'" | jq_ id)
c=$(codeof POST "/api/leave/requests/$L4/cancel" "$EMP")
check "cancel on leave that has STARTED -> 422" "422" "$c"
m=$(api POST "/api/leave/requests/$L4/cancel" "$EMP" | jq_ message)
check "  and it sends them to HR" "This leave has already started. Ask HR to correct it." "$m"

echo
echo "  ---------------------------------------------"
sweep "end-of-run teardown"
left=$(sql "select count(*) c from hrms_emp_leaves where comment='$MARKER'" | jq_ c)
check "nothing of this probe's is left behind" "0" "$left"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
