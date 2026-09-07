#!/usr/bin/env bash
# F-132 and F-121, after the last sprint.
#
#   F-132  Monthly Payroll returned 2 of tenant 3's 122 employees to an
#          administrator, and 1 to an HR Manager. Payroll for two people.
#   F-121  With that fixed, the audit's 122-employee case reproduces and the
#          endpoint can finally be re-measured.
#
#   bash Docs/hrit-audit/_evidence/probe-f132.sh

set -uo pipefail
cd "$(dirname "$0")/../../.."

BASE="${BASE:-http://127.0.0.1:8000}"
TOK="Docs/hrit-audit/_evidence/tokens.tsv"
OUT="${TMPDIR:-/tmp}/f132"

tok() { awk -F'\t' -v r="$1" '$1=="3" && $2==r {print $4}' "$TOK"; }

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 — expected [$2] got [$3]"; fail=$((fail+1)); fi; }

roster() { # roster <role>
  local t; t=$(tok "$1")
  curl -s -o "$OUT.json" \
    "$BASE/monthly-payroll/create?type=API&sub_institute_id=3&syear=2026-2027&month=Aug&year=2025&user_profile_name=$1" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json'
  php -r '$d=json_decode(@file_get_contents($argv[1]),true); echo count($d["employeeDetails"] ?? []);' "$OUT.json"
}

code() { # code <role>
  local t; t=$(tok "$1")
  curl -s -o /dev/null -w '%{http_code}' \
    "$BASE/monthly-payroll/create?type=API&sub_institute_id=3&syear=2026-2027&month=Aug&year=2025&user_profile_name=$1" \
    -H "Authorization: Bearer $t" -H 'Accept: application/json'
}

echo "============ F-132 / F-121 ============"
echo
echo "1. The roster is complete for everyone the payroll gate admits"
echo "   (the screen sends user.role — a role_key — which never matched the profile-name list)"
for r in administrator hr_manager hr_executive; do
  printf "     %-16s " "$r"
  n=$(roster "$r"); echo "$n employees"
  check "$r sees the whole institute" "122" "$n"
done

echo
echo "2. And the gate itself is unchanged"
for r in department_head reporting_manager employee auditor; do
  check "$r is still refused" "403" "$(code "$r")"
done

echo
echo "3. F-121 — the audit's case, finally measurable"
echo "     audit: 500 at 60.5s / 61.0s / 66.1s   ·   after Sprint 2: 200 at 58.7s / 39.5s / 30.8s"
A=$(tok administrator)
URL="$BASE/monthly-payroll/create?type=API&sub_institute_id=3&syear=2026-2027&month=Aug&year=2025&user_profile_name=administrator"
for i in 1 2 3; do
  curl -s -o "$OUT-$i.json" -w "     run $i: HTTP %{http_code}  %{time_total}s  %{size_download} bytes\n" \
    "$URL" -H "Authorization: Bearer $A" -H 'Accept: application/json'
done
IDENT=$(php -r '$h=[]; foreach([1,2,3] as $i) $h[]=md5_file($argv[1]."-$i.json"); echo count(array_unique($h))===1?"yes":"no";' "$OUT")
check "byte-identical across runs" "yes" "$IDENT"

echo
echo "4. The department names are right, not just present"
php -r '
$d=json_decode(file_get_contents($argv[1]."-1.json"),true);
$blank=0; foreach($d["employeeDetails"] as $e) if(($e["department"] ?? "-")==="-") $blank++;
echo "     fallback \"-\" in the response: $blank\n";' "$OUT"
DB=$(php Docs/hrit-audit/_evidence/snapshot.php "select count(*) c from tbluser u
       left join hrms_departments d on d.id=u.department_id and d.sub_institute_id=3 and d.status=1
      where u.sub_institute_id=3 and u.status=1 and d.id is null" \
     | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["c"];')
APP=$(php -r '
$d=json_decode(file_get_contents($argv[1]."-1.json"),true);
$b=0; foreach($d["employeeDetails"] as $e) if(($e["department"] ?? "-")==="-") $b++; echo $b;' "$OUT")
check "the collapsed lookup matches the database exactly" "$DB" "$APP"

rm -f "$OUT.json" "$OUT"-*.json
echo
echo "=============================================================="
echo "  PASS $pass   FAIL $fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
