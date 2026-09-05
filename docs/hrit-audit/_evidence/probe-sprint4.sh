#!/usr/bin/env bash
# Sprint 4 verification. Leave business rules and the entitlement front door.
#
# Self-contained: it creates its own entitlement grant, proves the rules bite
# against it, then removes everything it made. Run it twice and it behaves the
# same way.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }

probe() { # want label method url [json]
  local want="$1" label="$2" method="$3" url="$4" data="$5"
  local body code mark
  if [ -n "$data" ]; then
    body=$(curl -s -m 60 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$data" "$url")
  else
    body=$(curl -s -m 60 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' "$url")
  fi
  code=$(printf '%s' "$body" | tail -1)
  [ "$code" = "$want" ] && mark=PASS || mark=FAIL
  printf '%-4s want=%-3s got=%-3s  %-42s %s\n' "$mark" "$want" "$code" "$label" \
    "$(printf '%s' "$body" | head -c 150 | tr -d '\n' | cut -c1-118)"
}

EMP3=$(T 3 employee); HRM3=$(T 3 hr_manager)

echo "===== F-101: a leave type must belong to the caller's own tenant ====="
probe 422 "tenant 3 employee uses tenant 6's type id 9" POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":9,\"day_type\":\"full\",\"from_date\":\"2026-12-20\",\"to_date\":\"2026-12-21\",\"comment\":\"s4 probe\"}"

echo "===== F-102: the three the audit proved by calling the API directly ====="
probe 422 "365-day leave (was 201)"      POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-01\",\"to_date\":\"2027-11-30\",\"comment\":\"s4 probe\"}"
probe 422 "leave dated 1990 (was 201)"   POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"1990-01-01\",\"to_date\":\"1990-01-05\",\"comment\":\"s4 probe\"}"
probe 422 "a Sunday only - all weekly off" POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-09-06\",\"to_date\":\"2026-09-06\",\"comment\":\"s4 probe\"}"

echo "===== F-96: entitlement has a front door, and only HR may open it ====="
probe 200 "hr_manager reads the grid"    GET "$BASE/api/leave/allocations?token=$HRM3"
probe 403 "employee tries to set a grant" PUT "$BASE/api/leave/allocations" \
  "{\"token\":\"$EMP3\",\"allocations\":[{\"department_id\":35,\"leave_type_id\":4,\"value\":12}]}"
probe 422 "a grant above the 180-day cap" PUT "$BASE/api/leave/allocations" \
  "{\"token\":\"$HRM3\",\"allocations\":[{\"department_id\":35,\"leave_type_id\":4,\"value\":500}]}"

echo "===== the balance is zero until somebody grants one ====="
printf '  before: '
curl -s -m 60 "$BASE/api/leave/balances?token=$EMP3" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["leave_types"]??[] as $t){ if($t["leave_type"]==="Annual Leave") echo "Annual Leave total=".$t["total"]." remaining=".$t["remaining"]."\n"; }'

probe 200 "hr grants 3 days to department 35" PUT "$BASE/api/leave/allocations" \
  "{\"token\":\"$HRM3\",\"allocations\":[{\"department_id\":35,\"leave_type_id\":4,\"value\":3}]}"

printf '  after:  '
curl -s -m 60 "$BASE/api/leave/balances?token=$EMP3" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["leave_types"]??[] as $t){ if($t["leave_type"]==="Annual Leave") echo "Annual Leave total=".$t["total"]." remaining=".$t["remaining"]."\n"; }'

echo "===== ...and now the balance rule bites ====="
probe 422 "5 working days against a 3-day balance" POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-11-09\",\"to_date\":\"2026-11-13\",\"comment\":\"s4 probe\"}"
probe 201 "2 working days, within balance" POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-11-09\",\"to_date\":\"2026-11-10\",\"comment\":\"s4 probe\"}"
probe 422 "overlapping dates" POST "$BASE/api/leave/requests" \
  "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-11-10\",\"to_date\":\"2026-11-11\",\"comment\":\"s4 probe\"}"

echo "===== F-95: what the accepted request was actually charged ====="
php Docs/hrit-audit/_evidence/snapshot.php \
  "select from_date, to_date, day_type, chargeable_days from hrms_emp_leaves where comment='s4 probe' and deleted_at is null" \
  | sed 's/^/  /'
echo "  (2026-11-09 to 2026-11-13 is Mon-Fri = 5; the weekend is not charged)"

echo "===== clean up everything this script created ====="
php -r '
$lines=file(".env"); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"\x27"); }
$p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]);
echo "  leaves removed:     ".$p->exec("delete from hrms_emp_leaves where comment=\"s4 probe\"")."\n";
echo "  grants removed:     ".$p->exec("delete from hrms_leave_allocation where sub_institute_id=3")."\n";
echo "  tenant 3 leaves:    ".$p->query("select count(*) from hrms_emp_leaves where sub_institute_id=3 and deleted_at is null")->fetchColumn()." (baseline 29)\n";
echo "  allocation rows:    ".$p->query("select count(*) from hrms_leave_allocation")->fetchColumn()." (baseline 1)\n";'
