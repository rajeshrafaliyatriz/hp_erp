#!/usr/bin/env bash
# Sprint 5 verification. Data integrity, cancel-after-approval, the queue.
BASE=http://127.0.0.1:8000
T() { awk -F"\t" -v a="$1" -v b="$2" '$1==a && $2==b {print $4}' Docs/hrit-audit/_evidence/tokens.tsv; }
probe() { local want="$1" label="$2" method="$3" url="$4" data="$5"
  local body code mark
  if [ -n "$data" ]; then
    body=$(curl -s -m 60 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$data" "$url")
  else
    body=$(curl -s -m 60 -w $'\n%{http_code}' -X "$method" -H 'Accept: application/json' "$url")
  fi
  code=$(printf '%s' "$body" | tail -1)
  [ "$code" = "$want" ] && mark=PASS || mark=FAIL
  printf '%-4s want=%-3s got=%-3s  %-40s %s\n' "$mark" "$want" "$code" "$label" \
    "$(printf '%s' "$body" | head -c 130 | tr -d '\n' | cut -c1-96)"
}
EMP3=$(T 3 employee); HRM3=$(T 3 hr_manager)

# UPDATED AFTER SPRINT 6. This probe used to have the HR Manager approve the
# request directly, and that now returns 403 - correctly. Tenant 3's configured
# chain is [reporting_manager] (multi_level off, hr_enabled = 0), so HR is not
# an approver there and F-124's chain refuses them.
#
# Nothing regressed: the probe encoded the pre-chain world. It now approves as
# the ADMINISTRATOR, who can always decide (the documented escape hatch in
# LeaveApprovalWorkflow::roleMayDecide), so this file keeps testing what it is
# for - cancel-after-approval - instead of accidentally re-testing who may
# approve, which is probe-sprint6.sh's job.
ADM3=$(T 3 administrator)

echo "===== F-94 / F-123: the DATABASE now refuses what it used to accept ====="
php Docs/hrit-audit/_evidence/snapshot.php \
  "select constraint_name, referenced_table_name from information_schema.key_column_usage where table_schema='hp_erp' and table_name='hrms_emp_leaves' and column_name='leave_type_id'" | sed 's/^/  FK  /'
php Docs/hrit-audit/_evidence/snapshot.php \
  "select column_name, is_nullable from information_schema.columns where table_schema='hp_erp' and table_name='hrms_emp_leaves' and column_name in ('from_date','user_id','leave_type_id')" | sed 's/^/  /'
php Docs/hrit-audit/_evidence/snapshot.php \
  "select count(*) unusable_rows_still_live from hrms_emp_leaves hel left join hrms_leave_types hlt on hlt.id=hel.leave_type_id where hel.deleted_at is null and (hlt.id is null or hel.from_date is null)" | sed 's/^/  /'

echo "===== the 'Unassigned' bucket is gone from the leave report ====="
curl -s -m 60 "$BASE/api/leave/reports/summary?token=$HRM3" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["rows"]??[] as $r) printf("  %-18s total=%-4s days=%s\n", $r["leave_type"], $r["total"], $r["days"]);'

echo "===== F-105 golden transaction: cancel after approval ====="
curl -s -m 60 -o /dev/null -X PUT -H 'Content-Type: application/json' \
  -d "{\"token\":\"$HRM3\",\"allocations\":[{\"department_id\":35,\"leave_type_id\":4,\"value\":10}]}" "$BASE/api/leave/allocations"
NEW=$(curl -s -m 60 -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-14\",\"to_date\":\"2026-12-15\",\"comment\":\"s5 probe\"}" "$BASE/api/leave/requests")
ID=$(printf '%s' "$NEW" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["id"] ?? "";')
probe 422 "cancel while still pending - refused"  POST "$BASE/api/leave/requests/$ID/cancel" "{\"token\":\"$EMP3\"}"
probe 200 "an approver approves"                  POST "$BASE/api/leave/requests/$ID/decision" "{\"token\":\"$ADM3\",\"status\":\"approved\"}"
probe 200 "the applicant cancels their own"       POST "$BASE/api/leave/requests/$ID/cancel" "{\"token\":\"$EMP3\",\"reason\":\"plans changed\"}"
printf '  balance after cancelling: '
curl -s -m 60 "$BASE/api/leave/balances?token=$EMP3" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["leave_types"]??[] as $t) if($t["leave_type"]==="Annual Leave") echo "total=".$t["total"]." used=".$t["used"]." remaining=".$t["remaining"]."\n";'

echo "===== the approver's queue is gated by the API, not by the component ====="
probe 403 "employee opens the review queue"  GET "$BASE/api/attendance/regularisations?token=$EMP3&scope=team"
probe 200 "hr_manager opens the review queue" GET "$BASE/api/attendance/regularisations?token=$HRM3&scope=team"

echo "===== clean up ====="
php -r '
$lines=file(".env"); $e=[];
foreach($lines as $l){ $l=trim($l); if($l===""||$l[0]==="#")continue; $p=explode("=",$l,2); if(count($p)<2)continue; $e[trim($p[0])]=trim($p[1]," \"\x27"); }
$p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]);
echo "  leaves removed: ".$p->exec("delete from hrms_emp_leaves where comment=\"s5 probe\"")."   grants removed: ".$p->exec("delete from hrms_leave_allocation where sub_institute_id=3")."\n";
echo "  tenant 3 live leaves: ".$p->query("select count(*) from hrms_emp_leaves where sub_institute_id=3 and deleted_at is null")->fetchColumn()."   allocations: ".$p->query("select count(*) from hrms_leave_allocation")->fetchColumn()."\n";'
