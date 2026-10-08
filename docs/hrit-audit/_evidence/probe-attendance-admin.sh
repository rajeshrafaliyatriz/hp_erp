#!/usr/bin/env bash
# THE ATTENDANCE WRITE PATHS: WHO MAY WRITE, AND WHAT THEY GET BACK.
#
#   bash Docs/hrit-audit/_evidence/probe-attendance-admin.sh
#
# WHAT WAS WRONG
#
# `hrms-attendance-in-time/store` and `.../out-time/store` sit OUTSIDE the role
# group in routes/hrms.php, gated only by "is logged in", and used
# `$request->employee` raw. So any authenticated user could write any employee's
# punch times, and the out-time lookup had no sub_institute_id filter at all -
# it reached across tenants. The route comment has always described them as
# "the employee's own attendance, and their own punches"; the code did not
# enforce it.
#
# They are not role-gated in the fix, deliberately. No React or Blade screen
# calls them, but they carry the type=API shape a mobile client would use, and
# an HR gate would stop an employee punching for THEMSELVES - breaking the one
# thing the routes exist for. The subject is forced to the caller instead.
#
# AND A SECOND BUG, WORSE THAN IT LOOKS
#
# Both ended with `is_mobile($type, ..., null, ...)`, and is_mobile called
# array_walk_recursive() on it. PHP 8 throws a TypeError on null, so every API
# punch answered 500 - AFTER writing the row. A client that retries on failure
# would punch twice, and hrms_attendances has no unique index on (user_id, day)
# to stop it.
#
# SELF-SEEDING AND SELF-CLEANING. Year 2099 cannot collide with a real record.
#
# RUN THIS ALONE. `php artisan serve` is single-threaded, so a second probe
# running at the same time queues behind this one, and one abandoned slow
# request wedges the server - every call then returns 000 and the output reads
# as a mass regression rather than a dead server.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }
FE="../g2gv0"

E6=$(tok 6 employee)        # user 63, tenant 6 - the subject
A6=$(tok 6 administrator)   # tenant 6 HR
E3=$(tok 3 employee)        # tenant 3 - an outsider
A3=$(tok 3 administrator)   # tenant 3 HR - THE cross-tenant assertion
H3=$(tok 3 hr_manager)      # tenant 3, role_key hr_manager rather than admin
AU3=$(tok 3 auditor)        # may READ the org's attendance, may not change it

SUBJECT=63
OTHER=29                    # another tenant-6 employee; must never be writable
DAY=2099-01-11

# The admin-correction half needs a day in the PAST: the controller refuses a
# future one, since a day that has not happened cannot have been worked, and an
# admin route that allowed it would be the easy way to put a figure into payroll
# for a day nobody worked. 1999 was measured empty on both hosts:
#   select count(*) from hrms_attendances where day between '1999-01-01' and '1999-12-31'  -> 0
ADAY=1999-01-11
SUBJ3=7                     # a tenant-3 employee, for the same-tenant case

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

countfix() { if [ -f "$1" ]; then grep -cF "$2" "$1" || true; else echo "missing-file"; fi; }

rows_on() {   # rows_on <user_id>
  php Docs/hrit-audit/_evidence/snapshot.php \
    "select count(*) c from hrms_attendances where user_id=$1 and day='$DAY'" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["c"] ?? "err";'
}

punch() {   # punch <in|out> <token> <employee> ; echoes the HTTP code
  if [ "$1" = "in" ]; then
    curl -s -o storage/app/zzpunch.out -m 40 -w '%{http_code}' -X POST \
      "$BASE/hrms-attendance-in-time/store?type=API&token=$2&sub_institute_id=6" \
      -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded' \
      -d "employee=$3" -d "indate=$DAY" -d "intime=09:15"
  else
    curl -s -o storage/app/zzpunch.out -m 40 -w '%{http_code}' -X POST \
      "$BASE/hrms-attendance-out-time/store?type=API&token=$2&sub_institute_id=6" \
      -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded' \
      -d "employee=$3" -d "outdate=$DAY" -d "outtime=18:30"
  fi
}

teardown() {
  # BY ERA, NOT BY A LIST OF DAYS.
  #
  # This used to delete exactly $DAY and $ADAY. Then the known-bad harness
  # removed the future-day guard, the probe's own "2099-06-01 must be refused"
  # request succeeded, and the edit row it left behind sat on a third day
  # nothing cleaned. The next run read three edits where it expected two and
  # reported a bug that did not exist.
  #
  # 1999 predates the system and 2099 is seventy years out, so neither era can
  # hold a real record; cleaning by era cannot fall out of step with the days
  # the probe happens to use.
  local era="(day < '2000-01-01' or day > '2090-01-01')"
  php Docs/hrit-audit/_evidence/snapshot.php \
    "delete from hrms_attendances where $era" >/dev/null 2>&1
  php Docs/hrit-audit/_evidence/snapshot.php \
    "delete from hrms_attendance_edits where $era" >/dev/null 2>&1
  php Docs/hrit-audit/_evidence/snapshot.php \
    "delete from g2g_event where type='attendance.corrected' \
       and (payload like '%1999-%' or payload like '%2099-%')" >/dev/null 2>&1
  rm -f storage/app/zzpunch.out storage/app/zzadmin.out storage/app/zzgrid.out
}

# ---- the admin correction endpoint -----------------------------------------
# correct <token> <employee> <day> <in|-> <out|-> <reason|-> ; echoes HTTP code
correct() {
  local args=(-d "user_id=$2" -d "day=$3")
  [ "$4" != "-" ] && args+=(-d "in_time=$4")
  [ "$5" != "-" ] && args+=(-d "out_time=$5")
  [ "$6" != "-" ] && args+=(-d "reason=$6")
  curl -s -o storage/app/zzadmin.out -m 40 -w '%{http_code}' -X POST \
    "$BASE/api/attendance/admin/corrections" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' "${args[@]}"
}

# edits <token> [query] ; echoes HTTP code, body in zzadmin.out
edits() {
  curl -s -o storage/app/zzadmin.out -m 40 -w '%{http_code}' \
    "$BASE/api/attendance/admin/edits${2:-}" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json'
}

# grid <token> [query] ; echoes HTTP code, body in zzgrid.out
grid() {
  curl -s -o storage/app/zzgrid.out -m 90 -w '%{http_code}' \
    "$BASE/api/attendance/admin/grid${2:-}" \
    -H "Authorization: Bearer $1" -H 'Accept: application/json'
}

# gq <php-EXPRESSION over $d> ; one value out of the last grid body.
gq() { php -r '$d=json_decode(file_get_contents("storage/app/zzgrid.out"),true); echo '"$1"';'; }

# gqs <php-STATEMENTS over $d> ; the body does its own echo.
#
# gq prepends `echo`, which is correct for a single expression and wrong for
# anything longer - `echo $n = 0; foreach (...) ...; echo $n;` prints both values
# and reads as "00", and `echo foreach` is a parse error. Also provides
# subject(), because "the cell for employee 63" is asked five times below and a
# top-level `return` inside a foreach is a worse way to write it.
gqs() { php -r '$d=json_decode(file_get_contents("storage/app/zzgrid.out"),true);
  function subject($d, $id) {
    foreach ($d["data"]["employees"] ?? [] as $e) { if ((int) $e["user_id"] === $id) return $e; }
    return null;
  }'" $1"; }

# one scalar out of hrms_attendances / hrms_attendance_edits
att()  { php Docs/hrit-audit/_evidence/snapshot.php \
           "select $1 v from hrms_attendances where user_id=$2 and day='$3' and deleted_at is null" \
         | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "";'; }
edit() { php Docs/hrit-audit/_evidence/snapshot.php \
           "select $1 v from hrms_attendance_edits where user_id=$2 and day='$3' order by id $4 limit 1" \
         | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "";'; }
nedits() { php Docs/hrit-audit/_evidence/snapshot.php \
             "select count(*) v from hrms_attendance_edits where user_id=$1 and day='$2'" \
           | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "err";'; }

echo
echo "============ Attendance write paths ============"
trap teardown EXIT
teardown   # start-of-run clear (F-163)

# ---------------------------------------------- 1. the hole
echo
echo "1. Nobody may punch for somebody else"
check "an employee punching IN for a colleague is refused" "403" "$(punch in "$E6" "$OTHER")"
check "  and punching OUT for them is refused" "403" "$(punch out "$E6" "$OTHER")"
# The refusal must also not have written anything on the way to refusing.
check "  and nothing was written for the colleague" "0" "$(rows_on $OTHER)"
# An outsider has no business here at all.
check "an employee of another tenant is refused" "403" "$(punch in "$E3" "$SUBJECT")"
# HR is refused too, on THIS route. Correcting somebody else's attendance is an
# administrative act with its own endpoint; this one is self-service only.
check "even HR is refused on the self-service route" "403" "$(punch in "$A6" "$SUBJECT")"

# ---------------------------------------------- 2. the legitimate path
echo
echo "2. An employee can still punch for themselves"
# The half that matters. Gating these routes by role would have satisfied every
# assertion above and broken the only thing they are for.
check "punch in succeeds" "200" "$(punch in "$E6" "$SUBJECT")"
check "  and answers with a body, not an empty 500" "1" \
  "$(php -r '$d=json_decode(file_get_contents("storage/app/zzpunch.out"),true); echo $d["status"] ?? "none";')"
check "punch out succeeds" "200" "$(punch out "$E6" "$SUBJECT")"
check "  and answers too" "1" \
  "$(php -r '$d=json_decode(file_get_contents("storage/app/zzpunch.out"),true); echo $d["status"] ?? "none";')"
check "  exactly one row exists for the day" "1" "$(rows_on $SUBJECT)"

echo
echo "3. The row says what was actually asked for"
# The in-time branch parsed a bare "09:15" with Carbon, which yields TODAY at
# 09:15 - so editing an older day stamped it with today's date. Only the insert
# branch concatenated the date correctly.
check "the punch is dated the day requested, not today" "$DAY 09:15:00" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
     "select punchin_time from hrms_attendances where user_id=$SUBJECT and day='$DAY'" \
   | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["punchin_time"] ?? "";')"
check "  and carries the caller's tenant" "6" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
     "select sub_institute_id from hrms_attendances where user_id=$SUBJECT and day='$DAY'" \
   | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["sub_institute_id"] ?? "";')"

# ---------------------------------------------- 4. the CSV regression
echo
echo "4. The export writes data, not a template literal"
SHELL_TSX="$FE/components/domain/hrms/hrit/payroll-management/shared/payroll-shell.tsx"
# Mine, shipped in phase 17 and merged: `${'$'}{csv}` evaluates to "$" followed
# by the literal text {csv}, so every exported file carried six characters and
# no data. It passed tsc, passed next build, and passed the phase-17 probe,
# which only ever asserted that csvText EXISTED.
check "the broken interpolation is gone" "0" "$(countfix "$SHELL_TSX" "\${'\$'}{csv}")"
check "  and the rows are interpolated" "1" "$(countfix "$SHELL_TSX" "\${csv}")"


# ---------------------------------------------- 5. the HR correction endpoint
#
# The route carries profile:admin,hr - that says WHO may ask. It does not say
# whom they may ask ABOUT. The assertion that actually tests the controller is
# the cross-tenant one: a plain employee is stopped by the route gate before the
# controller runs, so an employee-only test passes with the tenant check DELETED.
# That exact mistake let a vacuous assertion through in the documents probe.
echo
echo "5. HR corrects an employee's day"

teardown      # the era clean, up front too: a crashed previous run must not
              # inflate this one's counts. See the note on teardown().

check "tenant-6 HR corrects a tenant-6 employee" "200" \
  "$(correct "$A6" "$SUBJECT" "$ADAY" "09:15" "18:30" "Forgot+to+punch+out")"
check "  the punch-in landed on the right day" "$ADAY 09:15:00" "$(att punchin_time $SUBJECT $ADAY)"
check "  the punch-out landed too" "$ADAY 18:30:00" "$(att punchout_time $SUBJECT $ADAY)"
# Payroll reads timestamp_diff. 09:15 -> 18:30 is 9h15m. The two writers in the
# codebase disagree about HH:MM vs HH:MM:SS, into a TIME column; assert the one
# this path produces rather than assuming.
check "  timestamp_diff was recomputed, not left stale" "09:15:00" "$(att timestamp_diff $SUBJECT $ADAY)"
check "  the day is attributed to the HR actor" "28" "$(att updated_by $SUBJECT $ADAY)"

echo
echo "   and the trail records it"
check "one edit row exists" "1" "$(nedits $SUBJECT $ADAY)"
check "  it is flagged as having created the day" "1" "$(edit created_row $SUBJECT $ADAY asc)"
check "  the before-image is empty, because there was no row" "" "$(edit before_in_time $SUBJECT $ADAY asc)"
check "  the after-image holds what was written, in the stored shape" "$ADAY 09:15:00" "$(edit after_in_time $SUBJECT $ADAY asc)"
check "  the reason was kept" "Forgot to punch out" "$(edit reason $SUBJECT $ADAY asc)"
check "  and who changed it" "28" "$(edit created_by $SUBJECT $ADAY asc)"

# "Keep the original and who changed it" was the user's choice between that and
# overwriting. A second correction is the case that tests it: the first version
# of this controller keyed its event 'admin:{employee}:{day}', which deduplicated
# the second correction away - the row would change and the history would say it
# had not.
echo
echo "   a SECOND correction to the same day keeps the first"
check "the second correction is accepted" "200" \
  "$(correct "$A6" "$SUBJECT" "$ADAY" "09:45" "-" "Badge+reader+was+slow")"
check "  two edit rows now, not one overwritten" "2" "$(nedits $SUBJECT $ADAY)"
check "  the newest records the OLD time as its before" "$ADAY 09:15:00" \
  "$(edit before_in_time $SUBJECT $ADAY desc)"
check "  ...and the new time as its after" "$ADAY 09:45:00" \
  "$(edit after_in_time $SUBJECT $ADAY desc)"
check "  the untouched punch-out survived a one-sided edit" "$ADAY 18:30:00" \
  "$(att punchout_time $SUBJECT $ADAY)"
check "  and it is no longer a row creation" "0" "$(edit created_row $SUBJECT $ADAY desc)"
check "  the duration followed the change" "08:45:00" "$(att timestamp_diff $SUBJECT $ADAY)"

echo
echo "   who may NOT"
check "an employee is refused" "403" \
  "$(correct "$E6" "$SUBJECT" "$ADAY" "07:00" "-" "Trying+it+on")"
check "  and changed nothing" "$ADAY 09:45:00" "$(att punchin_time $SUBJECT $ADAY)"
check "an auditor is refused - read is not write" "403" \
  "$(correct "$AU3" "$SUBJ3" "$ADAY" "07:00" "-" "Trying+it+on")"

# >>> THE ONE THAT TESTS THE CONTROLLER <<<
check "HR of ANOTHER tenant is refused" "404" \
  "$(correct "$A3" "$SUBJECT" "$ADAY" "06:00" "-" "Cross-tenant")"
check "  404 and not 403, so a refusal does not confirm the employee exists" "404" \
  "$(correct "$A3" "$SUBJECT" "$ADAY" "06:00" "-" "Cross-tenant")"
check "  and the outsider changed nothing" "$ADAY 09:45:00" "$(att punchin_time $SUBJECT $ADAY)"
check "  and wrote no edit row" "2" "$(nedits $SUBJECT $ADAY)"

# The same token, used where it is legitimate, must still work - otherwise the
# assertion above would pass for a controller that refuses everybody.
check "the SAME tenant-3 token works on its own employee" "200" \
  "$(correct "$A3" "$SUBJ3" "$ADAY" "10:00" "17:00" "Same+tenant,+allowed")"
check "  hr_manager counts as HR, not just administrator" "200" \
  "$(correct "$H3" "$SUBJ3" "$ADAY" "10:05" "-" "hr_manager+may+correct")"

echo
echo "   what the endpoint refuses outright"
check "no reason given" "422" "$(correct "$A6" "$SUBJECT" "$ADAY" "09:00" "-" "-")"
check "neither time given" "422" "$(correct "$A6" "$SUBJECT" "$ADAY" "-" "-" "Nothing+to+do")"
check "a day that has not happened yet" "422" \
  "$(correct "$A6" "$SUBJECT" "2099-06-01" "09:00" "-" "Future")"
check "a malformed day" "422" "$(correct "$A6" "$SUBJECT" "11-01-1999" "09:00" "-" "Bad+date")"
check "no token at all" "401" "$(correct "" "$SUBJECT" "$ADAY" "09:00" "-" "Anonymous")"

echo
echo "   the edit log reads back, scoped"
check "HR can read the log" "200" "$(edits "$A6" "?user_id=$SUBJECT")"
check "  and sees its own tenant's two edits" "2" \
  "$(php -r '$d=json_decode(file_get_contents("storage/app/zzadmin.out"),true); echo is_array($d["data"]??null)?count($d["data"]):"err";')"
check "  with the actor named, not just an id" "1" \
  "$(grep -c 'changed_by_name' storage/app/zzadmin.out || true)"
# A tenant-3 admin asking for a tenant-6 employee's history gets an empty list,
# not somebody else's rows. 200 with zero rows is the right answer here: unlike
# the write path there is nothing to confirm or deny - the filter simply matches
# nothing.
check "another tenant's HR sees none of it" "0" \
  "$(edits "$A3" "?user_id=$SUBJECT" >/dev/null; php -r '$d=json_decode(file_get_contents("storage/app/zzadmin.out"),true); echo is_array($d["data"]??null)?count($d["data"]):"err";')"
check "an employee cannot read the log at all" "403" "$(edits "$E6")"
check "the month filter narrows it" "2" \
  "$(edits "$A6" "?user_id=$SUBJECT&month=1999-01" >/dev/null; php -r '$d=json_decode(file_get_contents("storage/app/zzadmin.out"),true); echo is_array($d["data"]??null)?count($d["data"]):"err";')"
check "  and a different month excludes it" "0" \
  "$(edits "$A6" "?user_id=$SUBJECT&month=1999-02" >/dev/null; php -r '$d=json_decode(file_get_contents("storage/app/zzadmin.out"),true); echo is_array($d["data"]??null)?count($d["data"]):"err";')"

echo
echo "   the platform event was emitted, once per correction"
check "two attendance.corrected events for THIS employee" "2" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
      "select count(*) v from g2g_event where type='attendance.corrected' \
         and payload like '%\"employee_id\":$SUBJECT%' and payload like '%$ADAY%'" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "err";')"
# The second correction is the one that matters: the first version of this
# controller keyed its idempotency on {employee}:{day}, so this count was 1.
check "  and two for the tenant-3 subject, not one deduplicated away" "2" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
      "select count(*) v from g2g_event where type='attendance.corrected' \
         and payload like '%\"employee_id\":$SUBJ3%' and payload like '%$ADAY%'" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "err";')"
check "  each carries the reason it was given" "1" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
      "select count(*) v from g2g_event where type='attendance.corrected' \
         and payload like '%Badge reader was slow%'" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "err";')"

php Docs/hrit-audit/_evidence/snapshot.php \
  "delete from g2g_event where type='attendance.corrected' and payload like '%$ADAY%'" >/dev/null 2>&1


# ---------------------------------------------- 6. the month grid
#
# The grid is one request for the whole screen. There was no endpoint that
# returned more than one employee's month, so this is new: built on the
# per-employee monthly report it would have been 50 requests for a department
# and 2,283 for an organisation.
echo
echo "6. The month grid"

check "HR reads the grid" "200" "$(grid "$A6" "?month=2026-10&per_page=5")"
check "  a full month of days, not a window" "31" "$(gq 'count($d["data"]["days"] ?? [])')"
check "  paged on employees, honouring per_page" "5" \
  "$(gq 'min(5, count($d["data"]["employees"] ?? []))')"
check "  every employee carries every day" "1" \
  "$(gq 'count(array_unique(array_map(fn($e)=>count($e["days"]), $d["data"]["employees"] ?? []))) === 1 ? 1 : 0')"
check "  and that count is the month length" "31" \
  "$(gq 'count($d["data"]["employees"][0]["days"] ?? [])')"
check "  the meta totals are coherent" "1" \
  "$(gq '($m=$d["data"]["meta"]) && $m["total"] >= count($d["data"]["employees"]) && $m["total_pages"] >= 1 ? 1 : 0')"

echo
echo "   who may read it"
check "an employee is refused" "403" "$(grid "$E6" "?month=2026-10")"
check "an auditor is refused - the reporting grants are a different screen" "403" \
  "$(grid "$AU3" "?month=2026-10")"
check "no token" "401" "$(grid "" "?month=2026-10")"
check "a month that is not a month" "422" "$(grid "$A6" "?month=October")"
check "no month at all" "422" "$(grid "$A6")"

# The grid has no per-employee subject parameter, so the cross-tenant test is
# "does tenant 3's HR see tenant 6's people" rather than a refusal code.
echo
echo "   tenant isolation"
grid "$A6" "?month=2026-10&per_page=200" >/dev/null
php -r '$d=json_decode(file_get_contents("storage/app/zzgrid.out"),true);
  file_put_contents("storage/app/zzids6.out", implode(",", array_column($d["data"]["employees"] ?? [], "user_id")));'
grid "$A3" "?month=2026-10&per_page=200" >/dev/null
check "the two tenants' grids share no employee" "0" \
  "$(php -r '
     $a = array_filter(explode(",", (string) @file_get_contents("storage/app/zzids6.out")), "strlen");
     $d = json_decode(file_get_contents("storage/app/zzgrid.out"), true);
     $b = array_map("strval", array_column($d["data"]["employees"] ?? [], "user_id"));
     echo count(array_intersect($a, $b));')"
check "  and both returned somebody" "1" \
  "$(php -r '
     $a = array_filter(explode(",", (string) @file_get_contents("storage/app/zzids6.out")), "strlen");
     $d = json_decode(file_get_contents("storage/app/zzgrid.out"), true);
     echo (count($a) > 0 && count($d["data"]["employees"] ?? []) > 0) ? 1 : 0;')"
rm -f storage/app/zzids6.out

# The three resolutions that exist because the module could not agree on them.
# Monthly Attendance Report reads a 0 weekday flag as a weekend, so an employee
# with no roster shows a month of weekends; Attendance Tracking reads Mon-Sat as
# worked, so the same employee shows a month of absences. 2,008 of 2,283 active
# employees are in that state. This endpoint answers 'unset' instead of picking.
echo
echo "   what an empty cell says"
grid "$A6" "?month=2026-10&per_page=200" >/dev/null
check "a future day is 'upcoming', never 'absent'" "0" \
  "$(gqs '
     $n = 0;
     foreach ($d["data"]["days"] as $i => $day) {
       if (!$day["is_future"]) continue;
       foreach ($d["data"]["employees"] as $e) {
         if (($e["days"][$day["date"]]["status"] ?? "") === "absent") $n++;
       }
     }
     echo $n;')"
check "  and there ARE future days in this month, so that assertion bit" "1" \
  "$(gq 'count(array_filter($d["data"]["days"], fn($x)=>$x["is_future"])) > 0 ? 1 : 0')"
check "an employee with no roster reads 'unset', not weekend or absent" "0" \
  "$(gqs '
     $n = 0;
     foreach ($d["data"]["employees"] as $e) {
       if ($e["has_roster"]) continue;
       foreach ($e["days"] as $c) {
         if (in_array($c["status"], ["weekend","absent"], true)) $n++;
       }
     }
     echo $n;')"
check "  and the banner count matches the rows" "1" \
  "$(gqs '
     $c = count(array_filter($d["data"]["employees"], fn($e)=>!$e["has_roster"]));
     echo $c === (int) $d["data"]["meta"]["without_roster"] ? 1 : 0;')"

echo
echo "   a correction reaches the grid"
php Docs/hrit-audit/_evidence/snapshot.php \
  "delete from hrms_attendances where day='$ADAY'" >/dev/null 2>&1
php Docs/hrit-audit/_evidence/snapshot.php \
  "delete from hrms_attendance_edits where day='$ADAY'" >/dev/null 2>&1

check "the day starts with nothing recorded" "1" \
  "$(grid "$A6" "?month=1999-01&per_page=200" >/dev/null; gqs '
     $e = subject($d, 63);
     echo $e === null ? "subject-not-in-grid"
        : (in_array($e["days"]["'"$ADAY"'"]["status"], ["absent","unset"], true) ? 1 : 0);')"
check "HR corrects it" "200" \
  "$(correct "$A6" "$SUBJECT" "$ADAY" "09:15" "18:30" "Grid+round+trip")"
grid "$A6" "?month=1999-01&per_page=200" >/dev/null
check "  the cell now reads present" "present" \
  "$(gqs '$e = subject($d, 63); echo $e === null ? "missing" : $e["days"]["'"$ADAY"'"]["status"];')"
check "  with the times that were entered" "09:15/18:30" \
  "$(gqs '$e = subject($d, 63); $c = $e["days"]["'"$ADAY"'"] ?? null; echo $c === null ? "missing" : $c["in"]."/".$c["out"];')"
check "  and is flagged as changed by HR" "1" \
  "$(gqs '$e = subject($d, 63); echo $e === null ? "missing" : ($e["days"]["'"$ADAY"'"]["edited"] ? 1 : 0);')"
check "  the next day was not touched" "0" \
  "$(gqs '$e = subject($d, 63); echo $e === null ? "missing" : ($e["days"]["1999-01-12"]["edited"] ? 1 : 0);')"

# ---------------------------------------------- 7. the menu and the screen agree
#
# NOTHING ELSE CATCHES THIS. The sidebar resolves a leaf by its submenu id and
# its accessLink; the frontend map has to carry the same two values the database
# row does. tsc cannot see the database, the migration cannot see the TSX, and
# the symptom is a menu entry that opens a blank page - which looks like a
# broken screen, not a mismatched number.
#
# The id itself was the near-miss: the obvious next id, 312, is free on
# 202.47.117.220 and TAKEN by "Platform Services" on 128.199.17.97. These
# migrations guard on the id, so 312 would have inserted on one host and
# silently skipped on the other.
echo
echo "7. The menu row and the frontend map agree"

MENU_ID=433
LINK="/module/hrit-solutions/attendance-management/manage-employee-attendance"
MAP="$FE/hooks/content-map-m5.ts"
SCREEN="$FE/components/domain/hrms/hrit/attendance-management/manage-employee-attendance/page.tsx"

for HOST in mysql live; do
  check "menu $MENU_ID exists on $HOST, active, under Attendance Management" "1|93" \
    "$(php -r '
       require "vendor/autoload.php"; $a=require "bootstrap/app.php";
       $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       $r = Illuminate\Support\Facades\DB::connection("'"$HOST"'")
            ->table("tblmenumaster_g2g")->where("id",'"$MENU_ID"')->first();
       echo $r ? (($r->status)."|".($r->parent_id)) : "absent";')"
  check "  and its access_link is the one the map uses" "$LINK" \
    "$(php -r '
       require "vendor/autoload.php"; $a=require "bootstrap/app.php";
       $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       echo Illuminate\Support\Facades\DB::connection("'"$HOST"'")
            ->table("tblmenumaster_g2g")->where("id",'"$MENU_ID"')->value("access_link") ?? "absent";')"
  # SET EQUALITY, IN BOTH DIRECTIONS.
  #
  # The first version of this assertion counted profiles granted the menu whose
  # role_key is outside admin/hr, and expected 0. It was green while 32 profiles
  # across 16 tenants were MISSING the grant - every Admin and HR profile on
  # 202.47.117.220, whose role_key is null and whose NAME is what identifies
  # them. Only "HR Executive" had it. A subset check cannot see under-granting,
  # and under-granting is the failure that actually happened.
  #
  # This asks RoleKey the question RequireProfile asks, and compares the two
  # sets. Over-granting and under-granting both go red.
  check "  the menu grant matches exactly who the route admits, on $HOST" "ok" \
    "$(php -r '
       require "vendor/autoload.php"; $a=require "bootstrap/app.php";
       $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       use Illuminate\Support\Facades\DB; use App\Support\RoleKey;
       $db = DB::connection("'"$HOST"'");
       $granted = $db->table("tblgroupwise_rights_g2g")->where("menu_id",'"$MENU_ID"')
                     ->pluck("profile_id")->map("intval")->unique()->sort()->values()->all();
       $should = $db->table("tbluserprofilemaster")->whereNull("deleted_at")
                    ->get(["id","name","role_key","sub_institute_id"])
                    ->filter(fn($p) => RoleKey::satisfies(RoleKey::fromProfile($p), ["admin","hr"])
                                       && (int) ($p->sub_institute_id ?? 0) > 0)
                    ->pluck("id")->map("intval")->unique()->sort()->values()->all();
       $missing = array_values(array_diff($should, $granted));
       $extra   = array_values(array_diff($granted, $should));
       if ($should === []) { echo "nobody-should-have-it"; return; }
       if ($missing === [] && $extra === []) { echo "ok"; return; }
       echo "missing=" . count($missing) . " over=" . count($extra);')"
  check "  and somebody has it, so that is not vacuous" "1" \
    "$(php -r '
       require "vendor/autoload.php"; $a=require "bootstrap/app.php";
       $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       echo Illuminate\Support\Facades\DB::connection("'"$HOST"'")
            ->table("tblgroupwise_rights_g2g")->where("menu_id",'"$MENU_ID"')->count() > 0 ? 1 : 0;')"
done

check "the frontend map carries submenu $MENU_ID" "1" "$(countfix "$MAP" "submenuId: '$MENU_ID'")"
check "  on that exact accessLink" "1" "$(countfix "$MAP" "accessLink: '$LINK'")"
check "  pointing at a component that exists" "1" "$([ -f "$SCREEN" ] && echo 1 || echo 0)"
check "  and the lazy import resolves to that file" "1" \
  "$(countfix "$MAP" "attendance-management/manage-employee-attendance/page")"
# The screen must not be the one that claims to be a gate. The route and the
# controller are the gate; a component check is a hint (F-91).
check "the screen reuses the shared CSV writer, not its own" "1" \
  "$(countfix "$SCREEN" "csvText, downloadCsv")"


# ---------------------------------------------- 8. the four extras
#
# Bulk actions, inline approvals, adding a missing day, export and print.
# These are assertions on the frontend source because that is where the
# invariants live - each one is a regression that compiles, typechecks and
# looks right.
echo
echo "8. The four extras"

HOOK="$FE/hooks/use-attendance-admin.ts"
QUEUE="$FE/components/domain/hrms/hrit/attendance-management/attendance-tracking/components/regularisation-queue.tsx"

# BULK: allSettled, not all.
#
# Promise.all rejects on the first failure and discards every other result, so a
# bulk write where one employee is refused would report a failure and say
# nothing about the eighteen that landed - or worse, leave the operator
# believing none did. allSettled is what makes "changed 18 of 20" possible.
check "the bulk write uses allSettled" "1" "$(countfix "$HOOK" "Promise.allSettled")"
check "  and not Promise.all" "0" "$(countfix "$HOOK" "Promise.all(")"
check "  and reports the partial count" "1" "$(countfix "$HOOK" 'Changed ${ok} of ${results.length}')"
check "the screen routes bulk through it" "1" "$(countfix "$SCREEN" "correctMany(")"
# A bulk action must act on who is ON SCREEN. Ticking three people, changing the
# department filter and pressing the button must not write to three employees
# the operator is no longer looking at.
check "  on the narrowed selection, not the raw one" "1" \
  "$(countfix "$SCREEN" "effective.map((userId) =>")"

# INLINE APPROVALS: the existing queue, not a second one.
#
# RegularisationQueue already exists and is used by Attendance Tracking. A
# second implementation would drift, and the one thing it must not drift on is
# WHO may approve: that component asks for scope=team and renders nothing on
# 403, so the server decides. A reimplementation that hid a card instead would
# be a React component acting as a gate (F-91).
check "the approver queue is reused, not reimplemented" "1" \
  "$(countfix "$SCREEN" "import { RegularisationQueue }")"
check "  it exists where that import points" "1" "$([ -f "$QUEUE" ] && echo 1 || echo 0)"
check "  and approving refreshes the grid in one step" "1" \
  "$(countfix "$SCREEN" "<RegularisationQueue onDecided=")"
check "  the screen defines no second queue of its own" "0" \
  "$(countfix "$SCREEN" "scope: 'team'")"

# ADD A MISSING DAY: the same endpoint, which inserts when the day has no row.
check "the dialog says when it is creating a day rather than editing one" "1" \
  "$(countfix "$SCREEN" "Add a missing day")"

# PRINT: scoped to this screen.
#
# An unscoped `@media print` block would reformat every other printed page in
# the product - A4 landscape and forced backgrounds on a payslip, for instance.
# Every rule here is prefixed with this screen's own wrapper class.
check "the print rules exist" "1" "$(countfix "$SCREEN" "@media print")"
check "  scoped to this screen's wrapper" "1" "$(countfix "$SCREEN" "mea-print-root flex w-full")"
check "  the sticky column is released for paper" "1" \
  "$(countfix "$SCREEN" 'position: static !important')"
# The cell colours ARE the data here - a grey "non-working" and a red "absent"
# are the same cell once a printer drops the fills.
# TWO, not one: the substring matches the -webkit- prefixed line and the
# standard one, and both are deliberate - Safari still needs the prefix. A
# count of 1 would mean one of them had been "tidied" away.
check "  and the cell colours are forced to print, prefixed and not" "2" \
  "$(countfix "$SCREEN" "print-color-adjust: exact")"

# EXPORT: csvText on everything a spreadsheet would reinterpret.
check "the export wraps the employee code" "1" \
  "$(countfix "$SCREEN" 'csvText(employee.employee_code ?? ')"
check "  and the punch times" "1" "$(countfix "$SCREEN" 'csvText(`${cell.in ?? ')"

echo
echo "  ---------------------------------------------"
teardown
check "nothing of this probe's is left" "0" "$(rows_on $SUBJECT)"
check "  nor on the correction day" "0" "$(nedits $SUBJECT $ADAY)"
check "  nor for the tenant-3 subject" "0" "$(nedits $SUBJ3 $ADAY)"
# Era-wide, so a row left on a day this probe does not name is still caught -
# which is exactly what went wrong once.
check "  nor anywhere in either scratch era" "0" \
  "$(php Docs/hrit-audit/_evidence/snapshot.php \
      "select count(*) v from hrms_attendance_edits \
         where day < '2000-01-01' or day > '2090-01-01'" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["v"] ?? "err";')"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
