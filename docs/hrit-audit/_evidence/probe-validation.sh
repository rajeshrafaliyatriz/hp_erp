#!/usr/bin/env bash
# Sprint 0 evidence. E.1 validation matrix + Part G negative tests.
# Every request here is expected to be REJECTED. A 2xx is the finding.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
HRM3=$(T 3 hr_manager); EMP3=$(T 3 employee)

post() { local label="$1" url="$2" data="$3"
  local body code
  body=$(curl -s -m 30 -w $'\n%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$data" "$url")
  code=$(printf '%s' "$body" | tail -1)
  printf '%-54s %s  %s\n' "$label" "$code" "$(printf '%s' "$body" | head -c 200 | tr -d '\n' | cut -c1-140)"
}

echo "===== E.1 LEAVE APPLY - business rules at the API ====="
post "to_date BEFORE from_date"        "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-20\",\"to_date\":\"2026-12-01\",\"comment\":\"neg\"}"
post "leave_type_id from ANOTHER tenant" "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":9,\"day_type\":\"full\",\"from_date\":\"2026-12-20\",\"to_date\":\"2026-12-21\",\"comment\":\"neg\"}"
post "365-day leave (no balance check?)" "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-01\",\"to_date\":\"2027-11-30\",\"comment\":\"neg\"}"
post "leave dated 1990 (closed period?)"  "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"1990-01-01\",\"to_date\":\"1990-01-05\",\"comment\":\"neg\"}"
post "missing comment"                  "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-20\",\"to_date\":\"2026-12-21\"}"
# F-163. This is the ONE request in this file that is supposed to succeed - a
# Gujarati comment is valid input, not a negative test - and nothing removed the
# row it created. Combined with probe-writes.sh W1, each full-suite run left two
# more PENDING Annual Leave requests against user 7, draining the balance that
# probe-sprint4 grants itself and turning its "within balance" assertion red.
#
# An ASCII marker is prefixed so the row can be found again. It has to be: the
# Gujarati arrives over this probe's HTTP path as "??? ???? ?? ??", so matching
# on the unicode would match nothing. (That mangling is the PROBE's, not the
# product's - Laravel's connection is utf8mb4 and storing the same string
# through the model layer round-trips it intact. Checked before assuming.)
post "emoji + Gujarati in comment"      "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-22\",\"to_date\":\"2026-12-22\",\"comment\":\"neg-unicode રજા જોઈએ છે 🎉\"}"

echo "===== E.1 LEAVE TYPE - as HR (the role that legitimately configures) ====="
post "no_of_leave = -50"                "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"NEG probe minus\",\"no_of_leave\":-50,\"status\":1}"
post "empty leave_type name"            "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"\",\"no_of_leave\":5,\"status\":1}"
post "300-char leave_type name"         "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"$(printf 'A%.0s' $(seq 1 300))\",\"no_of_leave\":5,\"status\":1}"

echo "===== E.1 HOLIDAY ====="
post "holiday to_date before from_date" "$BASE/api/leave/holidays" "{\"token\":\"$HRM3\",\"holiday_name\":\"NEG probe\",\"from_date\":\"2026-12-25\",\"to_date\":\"2026-12-01\",\"department\":\"0\"}"

echo "===== Part G: cross-tenant object ids ====="
post "decide a TENANT 6 leave with a TENANT 3 admin token" "$BASE/api/leave/requests/226/decision" "{\"token\":\"$(T 3 administrator)\",\"status\":\"approved\"}"

echo "===== clean up the one request here that legitimately succeeds ====="
# F-163. See the note above the unicode case. Matched on the ASCII prefix, and
# also on the mangled form, so rows left by runs BEFORE the marker existed are
# swept up too rather than sitting there forever draining the balance.
php -r '
$lines=file(".env"); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"\x27"); }
$p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]);
$n=$p->prepare("delete from hrms_emp_leaves where comment like ? or comment = ?");
$n->execute(["neg-unicode%", "??? ???? ?? ??"]);
echo "  unicode probe rows removed: ".$n->rowCount()."\n";
$t=$p->exec("delete from hrms_leave_types where leave_type in (\"NEG probe minus\",\"\")");
echo "  stray leave types removed:  ".$t."\n";
$h=$p->exec("delete from hrms_holidays where holiday_name = \"NEG probe\"");
echo "  stray holidays removed:     ".$h."\n";'
