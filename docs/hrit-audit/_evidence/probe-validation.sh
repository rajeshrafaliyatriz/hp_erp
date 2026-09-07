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
post "emoji + Gujarati in comment"      "$BASE/api/leave/requests" "{\"token\":\"$EMP3\",\"leave_type_id\":4,\"day_type\":\"full\",\"from_date\":\"2026-12-22\",\"to_date\":\"2026-12-22\",\"comment\":\"રજા જોઈએ છે 🎉\"}"

echo "===== E.1 LEAVE TYPE - as HR (the role that legitimately configures) ====="
post "no_of_leave = -50"                "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"NEG probe minus\",\"no_of_leave\":-50,\"status\":1}"
post "empty leave_type name"            "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"\",\"no_of_leave\":5,\"status\":1}"
post "300-char leave_type name"         "$BASE/api/leave/leave-types" "{\"token\":\"$HRM3\",\"leave_type\":\"$(printf 'A%.0s' $(seq 1 300))\",\"no_of_leave\":5,\"status\":1}"

echo "===== E.1 HOLIDAY ====="
post "holiday to_date before from_date" "$BASE/api/leave/holidays" "{\"token\":\"$HRM3\",\"holiday_name\":\"NEG probe\",\"from_date\":\"2026-12-25\",\"to_date\":\"2026-12-01\",\"department\":\"0\"}"

echo "===== Part G: cross-tenant object ids ====="
post "decide a TENANT 6 leave with a TENANT 3 admin token" "$BASE/api/leave/requests/226/decision" "{\"token\":\"$(T 3 administrator)\",\"status\":\"approved\"}"
