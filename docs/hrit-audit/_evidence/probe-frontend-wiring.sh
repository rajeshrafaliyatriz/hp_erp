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
# The count is deliberate, not incidental: it catches a route SILENTLY
# DISAPPEARING, which the two checks below cannot - they only ever look at the
# routes that are present. Raise it when a screen is added, and say which.
#   13 -> 14  F-166, Bank-wise Payment Advice     (menu 307)
#   14 -> 15  F-169, Employee Payroll History    (menu 308)
#   15 -> 16  F-171, Monthly Attendance Report  (menu 309)
#   16 -> 18  F-172, Payroll Register (310) + Salary Structure Report (311)
check "content-map-m5 routes the 12 sub-modules, My HR and the five new reports" "18" "$(v routed)"
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
echo
echo "2b. Bank-wise Payment Advice is reachable, and NOT by everyone (F-166)"
check "it has a live menu row" "1" "$(v bank_menu)"
# The inverse of the My HR check directly above, on purpose. My HR must be
# viewable by EVERY profile; this one lists every employee's salary and bank
# account, so it must be viewable by admin/hr and nobody else.
check "some profiles can view it" "yes" "$([ "$(v bank_rights)" -gt 0 ] 2>/dev/null && echo yes || echo no)"
check "and it is granted to exactly the admin/hr set" "$(v bank_rights_expected)" "$(v bank_rights)"
check "no profile outside that set was granted it" "0" "$(v bank_rights_leaked)"
# The one that would actually catch a too-generous migration: strictly fewer
# than ALL profiles. The first version of this line compared bank_rights against
# bank_rights_expected, which are equal by construction two lines above - it
# passed for a reason that had nothing to do with what it claimed to check.
check "restricted - not granted to every profile ($(v bank_rights) of $(v profiles_total))" "yes" \
      "$([ "$(v bank_rights)" -lt "$(v profiles_total)" ] 2>/dev/null && echo yes || echo no)"

echo
echo "2d. Monthly Attendance Report - every profile, because it is self-service (F-171)"
check "it has a live menu row" "1" "$(v monthly_att_menu)"
# Deliberately the SAME shape as My HR and the OPPOSITE of 307/308. The
# controller enforces HR-or-self, so a rights row grants an employee their own
# month only. If this ever narrows, the controller check went with it.
check "and every profile can view it" "0" "$(v monthly_att_rights_gap)"

echo
echo "2c. A menu item named 'Report' must not mount a data-entry grid (F-167)"
# Menu 140 mounts MonthlyPayrollPage - editable day inputs, Generate Payroll,
# per-row Delete. It was called "Monthly Payroll Report", so someone clicking a
# Report could delete a filed payslip.
check "menu 140 is named for what it actually opens" "Monthly Payroll" "$(v menu140_name)"

echo "3. No control wired to a handler that does nothing"
check "no () => {} handler props" "0" "$(v noop_handlers)"
check "no href=\"#\"" "0" "$(v hash_href)"

echo
echo "4. No invented figures rendered as real data"
check "no hardcoded KPI trend deltas" "0" "$(v fake_trend)"
check "no calendar pinned to a fixed month" "0" "$(v pinned_month)"
check "no placeholder letter where an icon belongs" "0" "$(v letter_icon)"
# F-175. A named metric assigned a literal 0. `earlyGoing: 0` fed a table column
# AND a charted series with its own legend entry - a flat zero line labelled as
# a measurement, on every tenant and every date range. A column of zeros is a
# claim ("nobody left early"), not an absence.
check "no metric hardcoded to zero" "0" "$(v constant_zero_metric)"
# F-175. Two Group By options sharing one branch: "Date" grouped by DEPARTMENT
# and differed only by a column holding the same string on every row.
check "no two Group By options share a branch" "0" "$(v merged_group_branch)"

echo
echo "5. Nothing offered that the API cannot serve"
# Leave Reports listed FIFTEEN reports against THREE endpoints; the preview
# showed the same summary whichever was chosen, and Export wrote that summary
# under the selected report's filename.
check "the report catalogue matches the endpoints that exist" "3" "$(v reports_offered)"
# Columns that can only ever be a constant dash read as "still loading", not as
# "this view does not carry that field".
check "no column hardcoded to a dash" "0" "$(v dash_only_columns)"
# A Print button with no print stylesheet prints the application, not the report.
check "every Print button has a print stylesheet" "0" "$(v print_without_styles)"

echo
echo "6. Layout contracts - Phase 17"
# Five widgets at h-full in one CSS grid: stretch means the tallest sets them
# all, so one long holiday name grew the entire row. A .slice() bounds a card
# as well as a max-height does, so a capped list is not counted here.
check "no card can grow without limit in a stretch grid" "0" "$(v unbounded_card)"
# The shell offsets the sidebar with padding-left, but Tailwind breakpoints key
# off VIEWPORT width - so expanding it removed 188px while the grid kept its
# column count. These two grids must follow @container/content instead.
check "the two crowded grids follow container width, not the window" "0" "$(v viewport_grid_cols)"
# A bare 2025-09-01 becomes a date serial in Excel and renders ###### as soon as
# the column is narrow. payroll-shell exports csvText() for exactly this.
check "no date or clock time exported without being forced to text" "0" "$(v bare_date_export)"

echo
echo "7. Components reachable, not merely exported"
# A barrel re-export counts as a reference, which is why the first orphan scan
# found none while eight drifted duplicates of live panels sat in the tree.
check "the drifted attendance duplicates are gone" "0" "$(v drifted_duplicates)"

echo
echo "=============================================================="
printf "  PASS %d   FAIL %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
