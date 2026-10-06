#!/usr/bin/env bash
# Sprint 2 verification. Attendance Tracking: real roster, real alerts, real
# request counts, and the regularisation lifecycle end to end.
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
  printf '%-4s want=%-3s got=%-3s  %-44s %s\n' "$mark" "$want" "$code" "$label" \
    "$(printf '%s' "$body" | head -c 130 | tr -d '\n' | cut -c1-100)"
}
EMP3=$(T 3 employee); HRM3=$(T 3 hr_manager)

echo "===== F-98 / F-113: the dashboard's three widgets are served by the API ====="
probe 200 "employee -> /attendance/self-summary" GET "$BASE/api/attendance/self-summary?token=$EMP3"
echo "  hr_manager (rostered Saturday 09:00-14:00 in the database):"
curl -s -m 60 "$BASE/api/attendance/self-summary?token=$HRM3" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo "    shift  : ".json_encode($d["shift"])."\n    alerts : ".count($d["alerts"]??[])."\n    requests: ".json_encode(array_map(fn($r)=>$r["type"].":".$r["pending"], $d["requests"]??[]))."\n";'

echo "===== F-97: the endpoints the dashboard now calls instead of fixtures ====="
probe 200 "employee -> /leave/balances"          GET "$BASE/api/leave/balances?token=$EMP3"
probe 200 "employee -> /leave/holidays/upcoming" GET "$BASE/api/leave/holidays/upcoming?token=$EMP3&limit=5"

echo "===== F-107: regularisation is gated the same way leave is ====="
probe 403 "employee -> review queue"             GET "$BASE/api/attendance/regularisations?token=$EMP3&scope=team"
probe 200 "hr_mgr   -> review queue"             GET "$BASE/api/attendance/regularisations?token=$HRM3&scope=team"
probe 200 "employee -> my own requests"          GET "$BASE/api/attendance/regularisations?token=$EMP3"

echo "===== validation is at the API, not just the browser ====="
probe 422 "no times given"        POST "$BASE/api/attendance/regularisations" "{\"token\":\"$EMP3\",\"day\":\"2026-08-31\",\"reason\":\"probe\"}"
probe 422 "no reason given"       POST "$BASE/api/attendance/regularisations" "{\"token\":\"$EMP3\",\"day\":\"2026-08-31\",\"requested_in_time\":\"09:00\"}"
probe 422 "out before in"         POST "$BASE/api/attendance/regularisations" "{\"token\":\"$EMP3\",\"day\":\"2026-08-31\",\"requested_in_time\":\"18:00\",\"requested_out_time\":\"09:00\",\"reason\":\"probe\"}"
probe 422 "a future day"          POST "$BASE/api/attendance/regularisations" "{\"token\":\"$EMP3\",\"day\":\"2099-01-01\",\"requested_in_time\":\"09:00\",\"reason\":\"probe\"}"
probe 422 "invalid work mode"     POST "$BASE/api/attendance/punch-in" "{\"token\":\"$EMP3\",\"employee\":\"7\",\"indate\":\"2026-09-05\",\"intime\":\"09:00\",\"work_mode\":\"moon\"}"
