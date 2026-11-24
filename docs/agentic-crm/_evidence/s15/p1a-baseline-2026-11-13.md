# P1-A baseline (before any change of track S15-P1A), 13 Nov 2026

Repo: `C:\Users\MILAN\Downloads\g2gv0`, branch `milan1`, HEAD `cd12597`, `git status` clean at the start.

## tsc
Command: `node node_modules/typescript/bin/tsc --noEmit -p .`
Result: exit 0, no output (0 errors). 8.5 s.

## Tests that read the Signals components, plus `lib/signals/*`
Command (one line):
`node --import tsx --test lib/signals/*.test.ts lib/signals/*.test.tsx lib/company-opportunities-wiring.test.ts lib/signals-screen.test.ts lib/signal-run-view.test.ts lib/signal-list.behavior.test.tsx lib/signal-fallback.test.ts lib/region-fixes.test.tsx lib/region-scope.test.tsx lib/region-location-enhancement.test.tsx lib/research-steps.test.ts lib/sub-product-run.test.ts lib/manual-opportunity.test.ts lib/product-key.test.ts lib/source-link.test.ts lib/product-selection.test.ts lib/expandable-run-notice.test.tsx`

Result: 632 tests, 630 pass, 2 fail, 23.4 s.
The 2 failures are the known ones (not mine): `lib/expandable-run-notice.test.tsx`
- subtest 3 "Expanded State: expands inline, fetches sources, renders metrics, zero reasons, and source table"
- subtest 4 "Filter Tabs & Search: filters table rows by status and search keyword"

This is the "before" number for every later comparison: after my work the same command must show the 2 known failures and nothing else, with the test count higher by the tests I add.
