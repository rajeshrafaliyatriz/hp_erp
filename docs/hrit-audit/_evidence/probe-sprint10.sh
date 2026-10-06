#!/usr/bin/env bash
# Phase 10. The attendance audit trail, and the correction path nobody had driven.
#
# probe-sprint2 proved the regularisation endpoint REFUSES bad input (four 422s).
# It never created a successful one, so approve -> correct -> attendance rewritten
# had never been executed end to end. That path overwrites somebody's recorded
# hours and payroll reads timestamp_diff off the row it rewrites.
#
# This probe creates its own data and removes it. It does not touch any existing
# regularisation or attendance row - the lesson probe-sprint6 learned the hard way
# when it consumed four real leave requests.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

EMP=$(tok 3 team_employee)     # user 582
HRM=$(tok 3 hr_manager)        # user 67
EMP_ID=582

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

snap() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
one()  { snap "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d[$argv[1]] ?? "";' "$2"; }

api() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json' \
         -H 'Content-Type: application/json' -d "$b" --max-time 40
  else
    curl -s -X "$m" "$BASE$p" -H "Authorization: Bearer $t" -H 'Accept: application/json' --max-time 40
  fi; }

DAY="2026-08-19"

# ---------------------------------------------------------------------------
echo "=============================================================="
echo " Phase 10 - attendance correction and its audit trail"
echo "=============================================================="
echo
echo "0. Clear this probe's own ground (never anyone else's)"
# ---------------------------------------------------------------------------
snap "delete from hrms_attendance_regularisations where user_id=$EMP_ID and day='$DAY'" >/dev/null
snap "delete from hrms_attendances where user_id=$EMP_ID and day='$DAY'" >/dev/null
snap "delete from g2g_event where type like 'attendance.%' and sub_institute_id=3" >/dev/null

# A known starting attendance row, so 'what did it used to say' has an answer
# this probe controls rather than inherits.
snap "insert into hrms_attendances (user_id, sub_institute_id, day, punchin_time, punchout_time, timestamp_diff, created_at)
      values ($EMP_ID, 3, '$DAY', '$DAY 10:30:00', '$DAY 16:00:00', '05:30:00', now())" >/dev/null
check "a starting attendance row exists" "05:30:00" "$(one "select timestamp_diff t from hrms_attendances where user_id=$EMP_ID and day='$DAY'" t)"

# ---------------------------------------------------------------------------
echo
echo "1. The employee raises a correction"
# ---------------------------------------------------------------------------
RAISE=$(api POST /api/attendance/regularisations "$EMP" \
  "{\"sub_institute_id\":3,\"day\":\"$DAY\",\"requested_in_time\":\"09:00\",\"requested_out_time\":\"18:00\",\"reason\":\"phase-10 probe - forgot to punch in\"}")
REG_ID=$(echo "$RAISE" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? ($d["id"] ?? "");')
check "the request was created" "1" "$(if [ -n "$REG_ID" ]; then echo 1; else echo 0; fi)"

# ---------------------------------------------------------------------------
echo
echo "2. The applicant cannot decide their own"
# ---------------------------------------------------------------------------
SELF=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/attendance/regularisations/$REG_ID/decision" \
  -H "Authorization: Bearer $EMP" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"sub_institute_id":3,"status":"approved"}' --max-time 40)
check "self-decision refused" "403" "$SELF"

# ---------------------------------------------------------------------------
echo
echo "3. HR approves, and the attendance row is rewritten"
# ---------------------------------------------------------------------------
api POST "/api/attendance/regularisations/$REG_ID/decision" "$HRM" \
  '{"sub_institute_id":3,"status":"approved","reviewer_comment":"phase-10 probe"}' >/dev/null
check "punch-in corrected to 09:00" "$DAY 09:00:00" \
  "$(one "select punchin_time p from hrms_attendances where user_id=$EMP_ID and day='$DAY'" p)"
check "the recorded duration changed" "09:00:00" \
  "$(one "select timestamp_diff t from hrms_attendances where user_id=$EMP_ID and day='$DAY'" t)"

# ---------------------------------------------------------------------------
echo
echo "4. The trail - attendance used to emit nothing at all"
# ---------------------------------------------------------------------------
check "the decision was recorded" "1" \
  "$(one "select count(*) c from g2g_event where type='attendance.regularisation.decided' and sub_institute_id=3" c)"
check "the correction was recorded" "1" \
  "$(one "select count(*) c from g2g_event where type='attendance.corrected' and sub_institute_id=3" c)"

# The point of the whole exercise: the row's previous value survives the write.
PAYLOAD=$(one "select payload p from g2g_event where type='attendance.corrected' and sub_institute_id=3 limit 1" p)
check "the before-image names the OLD punch-in" "1" \
  "$(echo "$PAYLOAD" | grep -c '10:30:00')"
check "and the after-image names the new one" "1" \
  "$(echo "$PAYLOAD" | grep -c '09:00:00')"
check "it records that a row was amended, not created" "1" \
  "$(echo "$PAYLOAD" | grep -c '"created_row":false')"

# events:project is a SCHEDULED command (routes/console.php:63), not synchronous.
# Nothing reaches g2g_audit_log until it runs, so drain it here rather than
# asserting against a queue that has not been read yet.
php artisan events:project >/dev/null 2>&1
check "both reached the audit log" "2" \
  "$(one "select count(*) c from g2g_audit_log where sub_institute_id=3 and type like 'attendance.%'" c)"

# ---------------------------------------------------------------------------
echo
echo "5. A rejection touches no attendance row"
# ---------------------------------------------------------------------------
snap "delete from hrms_attendance_regularisations where user_id=$EMP_ID and day='$DAY'" >/dev/null
R2=$(api POST /api/attendance/regularisations "$EMP" \
  "{\"sub_institute_id\":3,\"day\":\"$DAY\",\"requested_in_time\":\"07:00\",\"reason\":\"phase-10 probe - to be rejected\"}")
R2_ID=$(echo "$R2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? ($d["id"] ?? "");')
api POST "/api/attendance/regularisations/$R2_ID/decision" "$HRM" \
  '{"sub_institute_id":3,"status":"rejected","reviewer_comment":"phase-10 probe"}' >/dev/null
check "the attendance row is untouched by a rejection" "$DAY 09:00:00" \
  "$(one "select punchin_time p from hrms_attendances where user_id=$EMP_ID and day='$DAY'" p)"
check "a rejection records the decision" "2" \
  "$(one "select count(*) c from g2g_event where type='attendance.regularisation.decided' and sub_institute_id=3" c)"
check "but records no correction" "1" \
  "$(one "select count(*) c from g2g_event where type='attendance.corrected' and sub_institute_id=3" c)"

# ---------------------------------------------------------------------------
echo
echo "6. Clean up everything this probe made"
# ---------------------------------------------------------------------------
snap "delete from hrms_attendance_regularisations where user_id=$EMP_ID and day='$DAY'" >/dev/null
snap "delete from hrms_attendances where user_id=$EMP_ID and day='$DAY'" >/dev/null
snap "delete from g2g_audit_log where sub_institute_id=3 and type like 'attendance.%'" >/dev/null
snap "delete from g2g_event where type like 'attendance.%' and sub_institute_id=3" >/dev/null
check "no probe regularisation left behind" "0" \
  "$(one "select count(*) c from hrms_attendance_regularisations where user_id=$EMP_ID and day='$DAY'" c)"
check "no probe attendance row left behind" "0" \
  "$(one "select count(*) c from hrms_attendances where user_id=$EMP_ID and day='$DAY'" c)"
# NOT a total-count assertion. Draining events:project above also projects any
# events already sitting unprojected - on the first run here that was 7 leave.*
# and task.* rows with a genuine backlog. Catching those up is the scheduler
# doing its job, not this probe leaving a mess; asserting a stable total would
# fail for the wrong reason, and would pass only by luck when the queue happens
# to be empty. What this probe OWNS is the attendance rows, so that is what it
# checks.
check "no attendance row left in the audit log" "0" \
  "$(one "select count(*) c from g2g_audit_log where type like 'attendance.%'" c)"
check "no attendance event left in the store" "0" \
  "$(one "select count(*) c from g2g_event where type like 'attendance.%'" c)"

echo

echo
echo "7. The five requests the RED verdict was built on"
# ---------------------------------------------------------------------------
# §1 opens with five requests an ordinary employee made SUCCESSFULLY in Sprint 0
# - apply, self-approve, withdraw a colleague's, create a leave type, rewrite the
# permission matrix. Four of the five had to be a 403.
#
# They were re-verified once by hand when F-87..F-91 closed. Until now no STANDING
# probe re-asserted them, so the audit's headline claim rested on a check nobody
# re-ran. That is the gap this section closes.
#
# Nothing here is created. Each refused call is asserted to have changed nothing,
# which is only meaningful against a row that already exists.
E7=$(tok 3 employee)          # user 7: approve_leave=0, scope='Self'

code_as() { local m=$1 p=$2 t=$3 b=${4:-}
  if [ -n "$b" ]; then
    curl -s -o /dev/null -w '%{http_code}' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$b" --max-time 40
  else
    curl -s -o /dev/null -w '%{http_code}' -X "$m" "$BASE$p" -H "Authorization: Bearer $t" \
      -H 'Accept: application/json' --max-time 40
  fi; }

# (1) applying for your own leave is the one that SHOULD be allowed - and the
#     SAME request is then used for (2), because "applied, then approved it
#     myself" is the exact pair §1 reports.
APPLIED=$(api POST /api/leave/requests "$E7" \
  "{\"sub_institute_id\":3,\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-24\",\"to_date\":\"2026-12-24\",\"comment\":\"phase-10 authz probe\"}")
MINE=$(echo "$APPLIED" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? ($d["id"] ?? "");')
check "applying for your own leave is allowed" "1" \
  "$(if [ -n "$MINE" ]; then echo 1; else echo 0; fi)"

# (2) ...and must NOT be approvable by the person who raised it
MINE_STATUS=$(one "select status s from hrms_emp_leaves where id=$MINE" s)
check "must NOT approve their own request" "403" \
  "$(code_as POST "/api/leave/requests/$MINE/decision" "$E7" '{"sub_institute_id":3,"status":"approved"}')"
check "and that request is unchanged" "$MINE_STATUS" \
  "$(one "select status s from hrms_emp_leaves where id=$MINE" s)"

# (3) a colleague's request is not theirs to withdraw
COLLEAGUE=$(one "select id from hrms_emp_leaves where sub_institute_id=3 and user_id<>7 and deleted_at is null order by id desc limit 1" id)
check "must NOT withdraw a colleague's request" "403" \
  "$(code_as DELETE "/api/leave/requests/$COLLEAGUE" "$E7")"
check "the colleague's request is untouched" "" \
  "$(one "select deleted_at d from hrms_emp_leaves where id=$COLLEAGUE" d)"

# (4) leave configuration is an organisation-wide setting
TYPES_BEFORE=$(one "select count(*) c from hrms_leave_types where sub_institute_id=3 and deleted_at is null" c)
check "must NOT create a leave type" "403" \
  "$(code_as POST /api/leave/leave-types "$E7" '{"sub_institute_id":3,"leave_type":"phase-10 probe","leave_type_id":"LTY999"}')"
check "and no leave type was created" "$TYPES_BEFORE" \
  "$(one "select count(*) c from hrms_leave_types where sub_institute_id=3 and deleted_at is null" c)"

# (5) the one that granted itself the other four
check "must NOT rewrite the permission matrix" "403" \
  "$(code_as PUT /api/leave/roles "$E7" '{"sub_institute_id":3,"role_key":"employee","approve_leave":1,"scope":"All"}')"
check "the Employee role still cannot approve" "0" \
  "$(one "select approve_leave a from hrms_leave_role_permissions where sub_institute_id=3 and role_name='Employee' limit 1" a)"

# The request raised in (1) is this probe's own and goes with it. probe-sprint6
# learned this the hard way by consuming four real leave requests, and the fix
# there was to clean up at the point of creation rather than hope.
snap "delete from hrms_leave_approval_steps where leave_id=$MINE" >/dev/null
snap "delete from hrms_emp_leaves where id=$MINE" >/dev/null
check "the probe's own request is cleaned up" "0" \
  "$(one "select count(*) c from hrms_emp_leaves where user_id=7 and from_date='2026-12-24'" c)"

echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
