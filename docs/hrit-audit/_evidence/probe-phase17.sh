#!/usr/bin/env bash
# THE SEVEN REPORTED PROBLEMS, AND THE ONES BEHIND THEM.
#
#   bash Docs/hrit-audit/_evidence/probe-phase17.sh
#
# Four of these were not what they looked like from the screen:
#
#   - "Attendance 5%" beside "Absent 100%" was two endpoints disagreeing: the
#     KPI hardcoded today and ignored the selected range, while the weekly
#     summary emitted six labels whatever the range, so a one-day selection got
#     five phantom days at absent = headcount - 0.
#   - The Monthly Attendance Report 500'd for anyone who had approved leave that
#     month, reading a `reason` property off a table whose column is `comment`.
#   - The holiday join used '=>' - not a SQL operator. Laravel rewrites the
#     clause to `from_date = '=>'`, so it never matched and working days were
#     overstated by every holiday in the period.
#   - Working days then subtracted a GROUP_CONCAT string of holiday ids as if it
#     were a count: "12,45,7" casts to 12.
#
# Static assertions cover the frontend fixes that a curl cannot reach.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }
FE="../g2gv0"

A3=$(tok 3 administrator)
A6=$(tok 6 administrator)

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

get() { curl -s -m 60 -G "$BASE$2" -H "Authorization: Bearer $1" -H 'Accept: application/json' "${@:3}"; }

# grep -c exits 1 on no match, so the usual `|| echo 0` prints TWO zeros - and a
# file that does not exist reads identically to one with no match, which would
# let a moved or renamed component pass as a clean result. Count only what can
# actually be opened, and say so when it cannot.
countin() {   # countin <file> <pattern>
  if [ -f "$1" ]; then grep -c "$2" "$1" || true; else echo "missing-file"; fi
}

echo
echo "================ Phase 17 ================"

# ------------------------------------------------ 1. the monthly report 500
echo
echo "1. Monthly Attendance Report survives an employee who took leave"
# user 6 / tenant 3 has approved leave in 2026-06. Reading a `reason` property
# threw ErrorException before the column name was corrected to `comment`.
R=$(get "$A3" "/api/employee-attendance-monthly-report" \
      --data-urlencode "sub_institute_id=3" --data-urlencode "user_id=6" --data-urlencode "month=2026-06")
check "it answers rather than throwing" "" \
  "$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["exception"] ?? "";')"
check "  the month is returned in full" "30" \
  "$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"]["daily_report"] ?? []);')"
# The frontend read `leave.type`; the server sends `leave_type`. The Leave
# column exported blank for as long as both were true.
check "  a leave day carries leave_type" "yes" \
  "$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     foreach(($d["data"]["daily_report"]??[]) as $x) if(!empty($x["leave"]["leave_type"])) { echo "yes"; return; } echo "no";')"
check "  and its reason, from the comment column" "yes" \
  "$(echo "$R" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     foreach(($d["data"]["daily_report"]??[]) as $x) if(!empty($x["leave"]["reason"])) { echo "yes"; return; } echo "no";')"

# ------------------------------------------------ 2. the KPI range
echo
echo "2. The KPI row describes the period the filter says it does"
K=$(get "$A6" "/api/attendance/kpi" \
      --data-urlencode "sub_institute_id=6" --data-urlencode "from_date=2026-06-01" --data-urlencode "to_date=2026-06-30")
check "the window is echoed back" "2026-06-01" "$(echo "$K" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["from_date"] ?? "";')"
check "  and it is the one asked for, not today" "2026-06-30" "$(echo "$K" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["to_date"] ?? "";')"
# tbluser.status is 1/0; counting every row inflated the denominator with
# disabled and deleted accounts.
DBN=$(php Docs/hrit-audit/_evidence/snapshot.php \
  "select count(*) c from tbluser where sub_institute_id=6 and status=1 and deleted_at is null" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["c"] ?? "";')
check "  headcount counts only active, undeleted users" "$DBN" \
  "$(echo "$K" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["active_employees"] ?? "";')"

# ------------------------------------------------ 3. the weekly series
echo
echo "3. The trend plots the days that were asked about"
W1=$(get "$A6" "/api/attendance/weekly-summary" \
      --data-urlencode "sub_institute_id=6" --data-urlencode "from_date=2026-06-10" --data-urlencode "to_date=2026-06-10")
check "a one-day range is one point, not six" "1" \
  "$(echo "$W1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["labels"] ?? []);')"
check "  at day granularity" "day" "$(echo "$W1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["granularity"] ?? "";')"
W2=$(get "$A6" "/api/attendance/weekly-summary" \
      --data-urlencode "sub_institute_id=6" --data-urlencode "from_date=2026-06-01" --data-urlencode "to_date=2026-06-30")
check "a 30-day range is 30 points" "30" \
  "$(echo "$W2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["labels"] ?? []);')"
W3=$(get "$A6" "/api/attendance/weekly-summary" \
      --data-urlencode "sub_institute_id=6" --data-urlencode "from_date=2026-01-01" --data-urlencode "to_date=2026-12-31")
check "a year buckets into weeks rather than 365 points" "week" \
  "$(echo "$W3" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["granularity"] ?? "";')"
# absent = totalUsers - presentCount went negative once a bucket spanned days
# and the same person was counted once per day.
check "  no percentage escapes 0..100" "ok" \
  "$(echo "$W3" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     foreach(["present","absent","late"] as $k) foreach(($d[$k]??[]) as $v)
       if($v < 0 || $v > 100){ echo "out-of-range:$k=$v"; return; } echo "ok";')"

# ------------------------------------------------ 4. the report catalogue
echo
echo "4. The report catalogue comes from the server"
C=$(get "$A6" "/api/leave/reports/catalog" --data-urlencode "sub_institute_id=6")
check "it lists the reports that exist" "3" "$(echo "$C" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["data"]["reports"] ?? []);')"
# The counts were frozen in a useMemo with an empty dependency array and
# contradicted the "Showing X of Y" line on the same card.
check "  and the category counts agree with that list" "ok" \
  "$(echo "$C" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     $r=$d["data"]["reports"]??[]; $c=$d["data"]["categories"]??[];
     $all=null; $sum=0;
     foreach($c as $row){ if($row["name"]==="All Reports") $all=$row["count"]; else $sum+=$row["count"]; }
     echo ($all===count($r) && $sum===count($r)) ? "ok" : "all=$all sum=$sum reports=".count($r);')"
check "  every report names the endpoint that produces it" "ok" \
  "$(echo "$C" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
     foreach(($d["data"]["reports"]??[]) as $r) if(empty($r["endpoint"])){ echo "missing on ".$r["id"]; return; } echo "ok";')"

# ------------------------------------------------ 5. frontend, statically
echo
echo "5. The frontend fixes a request cannot reach"
CAL="$FE/components/ui/calendar.tsx"
# The nav bar spans the full caption row; without pointer-events-none its empty
# middle swallowed every click meant for the month and year selects.
check "the calendar nav no longer eats the caption's clicks" "1" \
  "$(countin "$CAL" 'nav: "pointer-events-none absolute')"
check "  and the chevrons take their events back" "2" \
  "$(countin "$CAL" 'pointer-events-auto h-7 w-7')"

SHELL_TSX="$FE/components/domain/hrms/hrit/payroll-management/shared/payroll-shell.tsx"
check "the CSV writer can force a cell to text" "1" \
  "$(countin "$SHELL_TSX" 'export function csvText')"

MAR="$FE/components/domain/hrms/hrit/attendance-management/monthly-attendance-report/page.tsx"
check "  and the monthly report uses it for dates" "1" \
  "$(countin "$MAR" 'csvText(day.date)')"
check "  the Leave column reads the key the server sends" "1" \
  "$(countin "$MAR" 'day.leave?.leave_type')"

# Grid stretch: five widgets at h-full in one row means the tallest sets them all.
AT="$FE/components/domain/hrms/hrit/attendance-management/attendance-tracking/components/widgets"
check "the attendance widgets are height-bounded" "5" \
  "$(ls "$AT"/*.tsx >/dev/null 2>&1 && grep -l 'max-h-\[26rem\]' "$AT"/*.tsx 2>/dev/null | wc -l | tr -d ' ' || echo missing-dir)"

# THE CALENDAR FIX IS NOT ONE COMPONENT. Three separate date-navigation idioms
# live in this module, and only one of them goes through components/ui/calendar:
#   - ui/calendar via DatePicker      - the nav-overlay bug, fixed above
#   - a hand-rolled grid in the leave dashboard - had NO controls at all
#   - a native <input type="month"> in attendance tracking - always worked
# Asserted separately, because fixing the shared component touched neither of
# the other two and "the calendar is fixed" would have been wrong.
LCAL="$FE/components/domain/hrms/hrit/leave-management/leave-dashboard/components/LeaveCalendarDrawer.tsx"
check "the leave calendar can leave the current month" "1"   "$(countin "$LCAL" 'const shiftMonth =')"
check "  and can get back to today" "1"   "$(countin "$LCAL" 'const isCurrentMonth =')"

ACAL="$FE/components/domain/hrms/hrit/attendance-management/attendance-tracking/components/attendance-calendar-drawer.tsx"
# This one was already correct; asserted so a later "consistency" pass does not
# quietly replace a working control with the shared one.
check "the attendance calendar keeps its working month input" "1"   "$(countin "$ACAL" 'type="month"')"

SHELL_APP="$FE/components/shell/gtg-app-shell.tsx"
# Tailwind breakpoints key off viewport width, so expanding the sidebar took
# 188px out of every page while the grid kept its column count.
check "the content area is a container query context" "1" \
  "$(countin "$SHELL_APP" 'className="@container/content')"

echo
echo "  ---------------------------------------------"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
