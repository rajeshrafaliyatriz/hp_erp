#!/usr/bin/env bash
# FRONTEND WIRING — the checks whose absence let dead controls and an
# unreachable screen ship past twelve phases of backend probes.
#
#   bash Docs/hrit-audit/_evidence/probe-frontend-wiring.sh
#
# Every other probe here talks to the API. All 253 of those assertions passed
# while the Leave Dashboard's calendar was hardcoded to June 2026, four KPI
# deltas were invented constants, two labelled buttons were wired to () => {}
# passed down as PROPS, and My HR - the only screen showing an employee their
# own payslip - had no menu row and so could not be reached by navigating.
#
# An endpoint probe cannot see any of that. This checks the layer above it.
# READ-ONLY: makes no writes of any kind.
set -uo pipefail
cd "$(dirname "$0")/../../.."

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1));
          else echo "  FAIL  $1 - expected [$2] got [$3]"; fail=$((fail+1)); fi; }

# One PHP pass does the work: it reads content-map-m5.ts, queries the menu
# tables, and scans the component tree with COMMENTS STRIPPED - so a comment
# describing a defect is not counted as the defect. (The first version of this
# file failed its own no-op check on two comments I had just written.)
OUT=$(php artisan tinker --execute="require '$(pwd -W)/Docs/hrit-audit/_evidence/frontend-wiring-check.php';" 2>/dev/null \
      | sed 's/\x1b\[[0-9;]*m//g' | tr -d '\r')
v() { echo "$OUT" | grep -m1 "^$1=" | cut -d= -f2-; }

echo "=============================================================="
echo " HRIT frontend wiring   ($(v files_scanned) component files scanned)"
echo "=============================================================="

echo
echo "1. The menu/route contract - the check nobody was running"
check "content-map-m5 routes the 12 sub-modules plus My HR" "13" "$(v routed)"
check "every routed screen has a menu row" "0" "$(v routed_without_menu)"
# A live leaf under a live parent with no route falls through to
# ComingSoonFallback and renders "Application Shell Ready" - a developer
# placeholder. A leaf under a DISABLED parent is not in the sidebar at all, so
# it is not a dead link; conflating the two produced a wrong finding once.
check "no reachable menu item renders the developer placeholder" "0" "$(v live_leaf_no_route)"

echo
echo "2. My HR is reachable (the F-130 correction)"
check "My HR has a live menu row" "1" "$(v myhr_menu)"
# canView() reads ($rights->can_view ?? 0) == 1, so a menu with no rights row is
# invisible - absence is how revocation is expressed here. The row alone would
# have left My HR exactly as unreachable as it was.
check "and every profile can view it" "0" "$(v myhr_rights_gap)"

echo
echo "3. No control wired to a handler that does nothing"
check "no () => {} handler props" "0" "$(v noop_handlers)"
check "no href=\"#\"" "0" "$(v hash_href)"

echo
echo "4. No invented figures rendered as real data"
check "no hardcoded KPI trend deltas" "0" "$(v fake_trend)"
check "no calendar pinned to a fixed month" "0" "$(v pinned_month)"
check "no placeholder letter where an icon belongs" "0" "$(v letter_icon)"

echo
echo "5. Components reachable, not merely exported"
# A barrel re-export counts as a reference, which is why the first orphan scan
# found none while eight drifted duplicates of live panels sat in the tree.
check "the drifted attendance duplicates are gone" "0" "$(v drifted_duplicates)"

echo
echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
