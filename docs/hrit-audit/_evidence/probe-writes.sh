#!/usr/bin/env bash
# Sprint 0 evidence. WRITE probes. Every row touched here is either created by
# this script or restored by revert-writes.sql, which is written as we go.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
EMP3=$(T 3 employee)   # tenant 3, user 7, profile role_key=employee, approve_leave=0, scope=Self

post() { local label="$1" url="$2" data="$3"
  local body code
  body=$(curl -s -m 30 -w $'\n%{http_code}' -X POST -H 'Accept: application/json' \
         -H 'Content-Type: application/json' -d "$data" "$url")
  code=$(printf '%s' "$body" | tail -1)
  printf '%-56s %s  %s\n' "$label" "$code" "$(printf '%s' "$body" | head -c 220 | tr -d '\n' | cut -c1-160)"
}
del() { local label="$1" url="$2"
  local body code
  body=$(curl -s -m 30 -w $'\n%{http_code}' -X DELETE -H 'Accept: application/json' "$url")
  code=$(printf '%s' "$body" | tail -1)
  printf '%-56s %s  %s\n' "$label" "$code" "$(printf '%s' "$body" | head -c 220 | tr -d '\n' | cut -c1-160)"
}

MARKER='HRIT audit probe - delete me'

# F-163. Everything below used to be created and left behind.
#
# The header claims these rows are "restored by revert-writes.sql, which is
# written as we go". No such file is written, and nothing removed W1's leave.
# So every run of the full suite added one more PENDING Annual Leave request for
# user 7, each one consuming part of the 3-day balance probe-sprint4 grants
# itself - until sprint4's "2 working days, within balance" started returning
# 422 "you have 0 remaining". The suite reported 295/296 from code that had not
# changed, which is F-157 exactly: a probe whose result depends on how many
# times it has been run before is not evidence.
#
# Cleared at the START as well as the end, so a run that dies halfway cannot
# poison the next one.
sweep() { php -r '
$lines=file(".env"); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"\x27"); }
$p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]);
$n =$p->prepare("delete from hrms_emp_leaves where comment = ?"); $n->execute([$argv[1]]);
$t =$p->prepare("delete from hrms_leave_types where leave_type = ?"); $t->execute(["HRIT AUDIT PROBE"]);
echo "  ".$argv[2].": removed ".$n->rowCount()." leave row(s), ".$t->rowCount()." leave type(s)\n";' "$MARKER" "$1"; }

sweep "start-of-run clear"

echo "===== W1. Employee applies for their own leave (legitimate) ====="
NEW=$(curl -s -m 30 -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-11-03\",\"to_date\":\"2026-11-04\",\"comment\":\"HRIT audit probe - delete me\"}" \
  "$BASE/api/leave/requests")
echo "  $NEW"
ID=$(printf '%s' "$NEW" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? "";')
echo "  new leave id = $ID"

echo "===== W2. The SAME employee approves their OWN leave (must be 403) ====="
post "employee self-approves #$ID" "$BASE/api/leave/requests/$ID/decision" \
     "{\"token\":\"$EMP3\",\"status\":\"approved\",\"hr_remarks\":\"audit probe\"}"

echo "===== W3. Employee withdraws SOMEONE ELSE's pending request #219 (must be 403) ====="
del "employee deletes another user's #219" "$BASE/api/leave/requests/219?token=$EMP3"

echo "===== W4. Employee creates a LEAVE TYPE (config write, must be 403) ====="
post "employee creates leave type" "$BASE/api/leave/leave-types" \
     "{\"token\":\"$EMP3\",\"leave_type\":\"HRIT AUDIT PROBE\",\"no_of_leave\":99,\"status\":1}"

echo "===== W5. Employee grants THEMSELVES approve rights (must be 403) ====="
curl -s -m 30 -X PUT -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"token\":\"$EMP3\",\"roles\":[{\"id\":8,\"role_name\":\"Employee\",\"scope\":\"Organization\",\"approve_leave\":true,\"view_reports\":true,\"configure_settings\":true,\"bulk_operations\":true,\"escalation_rights\":true,\"user_management\":true}]}" \
  -w $'\n%{http_code}\n' "$BASE/api/leave/roles" | tail -3

echo "===== clean up everything this script created ====="
sweep "end-of-run teardown"
