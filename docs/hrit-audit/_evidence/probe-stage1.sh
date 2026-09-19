#!/usr/bin/env bash
# STAGE 1 - the money decisions.
#
#   bash Docs/hrit-audit/_evidence/probe-stage1.sh
#
# Q9  / F-173  Adjustments payroll has never applied, because their month is
#              stored as "8" rather than "Aug". Surfaced, never guessed.
# Q10 / F-174  Salary structures that reference a pay head which is no longer
#              active. The screen did not render those amounts - and because
#              the store OVERWRITES employee_salary_data with exactly what is
#              posted, not rendering them meant DELETING them on the next Save.
#
# F-174 is the one to read twice. It is not a stale reference; it is a silent
# deletion triggered by an ordinary Save on a screen nobody had edited.
#
# Creates its own structure in a scratch year (2099) and removes it. Never
# touches a real structure.
set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
tok() { awk -F'\t' -v t="$1" -v r="$2" '$1==t && $2==r {print $4}' "$TOK"; }

A3=$(tok 3 administrator)   # user 6, tenant 3
E3=$(tok 3 employee)        # user 7, tenant 3 - must be refused

# The scratch structure. Year 2099 cannot collide with anything real, and
# employee 7 has no structure of its own.
SUBJECT=7
SCRATCH_YEAR=2099
LIVE_HEAD=9      # BASIC        - status 1, so the grid renders it
DEAD_HEAD=2      # daycount gg  - status 0, so the grid does NOT

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

sql() { php Docs/hrit-audit/_evidence/snapshot.php "$1"; }
jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=explode(".",$argv[1]); foreach($k as $s){ if(!is_array($d)||!array_key_exists($s,$d)){echo ""; exit;} $d=$d[$s]; } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

# The amount currently stored against one head of the scratch structure.
amount_on() {
  sql "select employee_salary_data d from employee_salary_structures
        where employee_id=$SUBJECT and year=$SCRATCH_YEAR and sub_institute_id=3" \
  | php -r '$r=json_decode(stream_get_contents(STDIN),true);
      $raw = $r["d"] ?? "{}";
      $data = json_decode($raw, true) ?: [];
      echo array_key_exists($argv[1], $data) ? (string) $data[$argv[1]] : "ABSENT";' "$1"
}

seed() {
  sql "delete from employee_salary_structures
        where employee_id=$SUBJECT and year=$SCRATCH_YEAR and sub_institute_id=3" >/dev/null
  # Written directly so the starting state is exact: 30000 on a head the grid
  # shows, 5000 on a head it does not.
  sql "insert into employee_salary_structures
        (employee_id, employee_salary_data, year, sub_institute_id, created_at, updated_at)
       values ($SUBJECT, '{\"$LIVE_HEAD\":30000,\"$DEAD_HEAD\":5000}', $SCRATCH_YEAR, 3, now(), now())" >/dev/null
}

teardown() {
  sql "delete from employee_salary_structures
        where employee_id=$SUBJECT and year=$SCRATCH_YEAR and sub_institute_id=3" >/dev/null
}

# Posts a structure save. Extra emp[...] tuples may be appended by the caller,
# which is exactly how the fixed frontend carries the unrendered heads.
save_structure() {
  local extra=${1:-}
  curl -s -m 60 -X POST "$BASE/employee-salary-structure/store?type=API&token=$A3&sub_institute_id=3&user_id=6&syear=$SCRATCH_YEAR" \
    -H "Authorization: Bearer $A3" -H 'Accept: application/json' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    -d "emp[$SUBJECT][0]=M" \
    -d "emp[$SUBJECT][$LIVE_HEAD][0]=$LIVE_HEAD" \
    -d "emp[$SUBJECT][$LIVE_HEAD][1]=30000" \
    -d "emp[$SUBJECT][$LIVE_HEAD][2]=BASIC" \
    -d "emp[$SUBJECT][$LIVE_HEAD][3]=1" \
    $extra
}

echo
echo "================ Stage 1 - Q9 and Q10 ================"
trap teardown EXIT

echo
echo "1. Q9 / F-173 - the adjustments payroll has never applied"
ORPH=$(curl -s -m 60 -G "$BASE/payroll-deduction/orphans?type=API&token=$A3&sub_institute_id=3&user_id=6" \
        -H "Authorization: Bearer $A3" -H 'Accept: application/json')
n=$(echo "$ORPH" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["orphans"]??null)?count($d["orphans"]):"err";')
check "the endpoint lists them" "11" "$n"
check "and totals what is being skipped" "343001" "$(echo "$ORPH" | jq_ total)"

# The month is reported EXACTLY as stored. Normalising it here would be the
# guess the whole finding exists to avoid.
raw=$(echo "$ORPH" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["orphans"][0]["month"] ?? "";')
check "  the stored month is reported verbatim" "8" "$raw"

# Every one of them also points at a head that cannot be used, which is why
# re-dating alone would not make them apply.
deadheads=$(echo "$ORPH" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
  $n=0; foreach($d["orphans"]??[] as $o){ if(!empty($o["head_deleted_at"]) || (string)($o["head_status"]??"") !== "1") $n++; } echo $n;')
check "  and every one names an unusable pay head" "11" "$deadheads"

c=$(curl -s -m 60 -o /dev/null -w '%{http_code}' -G "$BASE/payroll-deduction/orphans?type=API&token=$E3&sub_institute_id=3&user_id=7" \
      -H "Authorization: Bearer $E3" -H 'Accept: application/json')
check "an employee cannot read them" "403" "$c"

echo
echo "2. Q9 - a month this system cannot name is still refused"
# The same F-143 guard new writes go through. If this ever passes, the repair
# path has become a second way to create the problem it exists to clear.
m=$(curl -s -m 60 -X POST "$BASE/payroll-deduction/orphans/resolve?type=API&token=$A3&sub_institute_id=3&user_id=6&id=2&action=set-month&month=8" \
      -H "Authorization: Bearer $A3" -H 'Accept: application/json' -H 'Content-Type: application/x-www-form-urlencoded' | jq_ message)
check "re-dating to \"8\" is refused, with the reason" \
      'Choose a month. "8" is not one this system can file under.' "$m"
still=$(curl -s -m 60 -G "$BASE/payroll-deduction/orphans?type=API&token=$A3&sub_institute_id=3&user_id=6" \
        -H "Authorization: Bearer $A3" -H 'Accept: application/json' \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d["orphans"]??null)?count($d["orphans"]):"err";')
check "  and nothing was changed by the attempt" "11" "$still"

echo
echo "3. Q10 / F-174 - the Save that used to delete money"
seed
check "scratch structure seeded with a live head" "30000" "$(amount_on $LIVE_HEAD)"
check "  and an amount on a head the grid does not render" "5000" "$(amount_on $DEAD_HEAD)"

# THE DEFECT. This is exactly what the old frontend posted: every ACTIVE head,
# and nothing else. The controller rewrites employee_salary_data from it.
save_structure >/dev/null
check "posting only the rendered heads DELETES the other amount" "ABSENT" "$(amount_on $DEAD_HEAD)"

# The rendered head comes back as 25000, not the 30000 posted, and that is
# CORRECT: head 9 is amount_type 1 (Flat) with payroll_percentage 25000, and
# tenant 3 is not in config('payroll.excess_over_flat_amount_tenants') ([47]),
# so employeeSalaryStructureStore takes the configured flat amount and ignores
# what the grid sent. Asserting 30000 here would have been asserting that a
# working business rule is broken. What matters is that the save WORKED, so the
# value is captured and re-checked after the second save instead.
LIVE_AFTER=$(amount_on $LIVE_HEAD)
check "  the rendered head was saved (flat rule applied: posted 30000 -> $LIVE_AFTER)" "yes"       "$([ "$LIVE_AFTER" != "ABSENT" ] && [ "$LIVE_AFTER" != "" ] && echo yes || echo no)"

# THE FIX. The frontend now also posts the heads it could not render, which is
# what saveSalaryStructure's `carriedValues` does.
seed
save_structure "-d emp[$SUBJECT][$DEAD_HEAD][0]=$DEAD_HEAD -d emp[$SUBJECT][$DEAD_HEAD][1]=5000 -d emp[$SUBJECT][$DEAD_HEAD][2]=head-$DEAD_HEAD -d emp[$SUBJECT][$DEAD_HEAD][3]=0" >/dev/null
check "carrying it through the save PRESERVES it" "5000" "$(amount_on $DEAD_HEAD)"
# The invariant that matters: carrying the extra head changes nothing about
# the rendered one.
check "  and leaves the rendered head exactly as the first save did" "$LIVE_AFTER" "$(amount_on $LIVE_HEAD)"

echo
echo "4. Q10 - how much of the live data this applies to"
# Not an assertion about a number that must stay the same - it is the reason
# the warning exists. If it reaches zero the structures have been re-pointed
# and the warning can go.
affected=$(php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$n = 0;
foreach (Illuminate\Support\Facades\DB::table("employee_salary_structures")->where("year", "!=", 2099)->get() as $s) {
    $data = json_decode($s->employee_salary_data, true) ?: [];
    foreach (array_keys($data) as $hid) {
        $t = Illuminate\Support\Facades\DB::table("payroll_types")->where("id", $hid)->first();
        if (!$t || (int) $t->sub_institute_id !== (int) $s->sub_institute_id || $t->deleted_at || !$t->status) { $n++; break; }
    }
}
echo $n;' 2>/dev/null)
total=$(sql "select count(*) c from employee_salary_structures where year != 2099" | jq_ c)
echo "     $affected of $total live structures reference a head that is not active"
check "the warning has something real to report" "yes" \
      "$([ "$affected" != "" ] && [ "$affected" -gt 0 ] 2>/dev/null && echo yes || echo no)"

echo
echo "  ---------------------------------------------"
teardown
left=$(sql "select count(*) c from employee_salary_structures where year=$SCRATCH_YEAR" | jq_ c)
check "nothing of this probe's is left behind" "0" "$left"
printf "  %d passed, %d failed\n" "$pass" "$fail"
echo
[ "$fail" -eq 0 ]
