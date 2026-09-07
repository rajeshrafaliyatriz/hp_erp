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
