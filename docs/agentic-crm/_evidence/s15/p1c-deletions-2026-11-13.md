# P1-C deletions: the proof that no importer remains, group by group (13 Nov 2026)

Tool: `importers.mjs` (kept outside the repo, in the session scratch folder). It reads every `.ts .tsx .mjs .js` file of `app components hooks lib services packages types` and resolves `import ... from`, `export ... from`, a dynamic `import('...')` and `require('...')` with a literal, for `@/...` and relative paths, so `crm/signal-runs-panel` is never confused with the surviving `signals/signal-runs-panel`. A file that is itself in the group is not counted as an importer of the others. `tsc --noEmit -p .` was run after each group (0 errors every time). The deletions are plain file removals in the working tree (nothing staged, nothing committed).

## Group A: the Signal Review screen, its Generate dialog, the two re-exports and their four test files

Targets: `components/domain/crm/{signal-review-view,signal-review-list,signal-review-drawer,signal-review-parts,signal-generate-dialog,signal-runs-panel,signal-export-button}.tsx`, `lib/signals/{signal-review-list,signal-review-drawer,signal-review-drawer-extra,signal-generate-dialog}.test.tsx`.

Before touching anything else: `importers outside the group: 1`, `components/domain/crm/signal-review-view.tsx: lib/signals/signal-export.test.tsx` (its second describe, "Export on the Signal Review screen", 3 tests, deleted per the test map; its first describe was already retargeted to `ExportCsvButton`). After removing that describe and its imports: `TOTAL importers outside the group: 0`; a plain grep for `SignalReviewView|SignalReviewList|SignalReviewDrawer|SignalGenerateDialog|signal-review-(view|list|drawer|parts)|signal-generate-dialog|domain/crm/signal-(runs-panel|export-button)` finds only strings and a comment in `lib/signal-review-address-gone.test.ts` (the detector's examples). Deleted; `tsc` 0 errors.

## Group B: the Generate model, its fixtures, the run service and their tests

Targets: `lib/signals/{generate-model.ts,generate-model.test.ts,generate-fixtures.ts,signal-run-service.test.ts}`, `services/signal-review/signal-run-service.ts`.

`TOTAL importers outside the group: 0` (run after group A). A plain grep for `generate-model|generate-fixtures|signal-run-service|signalRunService|SignalRunApi|RunSnapshot|StartRunRequest|parseRunSnapshot` found only two comments (in `lib/signals/run-failure.ts` and `lib/signals/run-failure.test.ts`, both corrected in the same step) outside the group. `generate-fixtures.ts` was read by `signal-export.test.tsx` for `pressButton` only; that file now has its own four-line `pressButton` that asserts the button before clicking. Deleted; `tsc` 0 errors; the three comments that still described the dialog (`run-failure.ts`, `run-failure.test.ts`, `services/signals/run-refusal.ts`) were corrected.

## Group C: the Review's export adapter and wrapper

`grep -rn -E "SignalExportButton|collectSignals"` over the front end found only the two definitions and comments I wrote in tests. Removed `SignalExportButton` (`components/domain/signals/signal-export-button.tsx`) and `collectSignals` (`lib/signals/export-csv.ts`) with their now-unused imports (`SignalReviewApi`, `SignalListParams`, `SignalListResponse`) and the "goes with the Review screen at step 1.7" comments. Their tests were retargeted, not deleted (test map 2.7 and 2.8). `tsc` 0 errors; eslint clean.

## Group D: the Review-only parts of `lib/signals/list-model.ts`

What the surviving code takes from `list-model` (every import, production and tests, read by grep): `productChoices` (company-opportunities.tsx); `fitChoices, FRESHNESS_CHOICES, reviewStatusOf, STATUS_CHOICES, summaryTiles, Choice` (signal-list.tsx); `fitSummary` (signal-parts.tsx, export-csv.ts); `fitLabel` (lib/leads/model.ts, fit-score.ts); `FRESHNESS_CHOICES, isNarrowed, listScreenState, reviewStatusOf, STATUS_CHOICES, Choice, ListScreenState` (opportunity-query.ts); `applyStatusChange` (opportunity-row.ts); `reviewStatusOf` (opportunity-query.test.ts). Nobody outside `list-model.ts` and `list-model.test.ts` takes `PER_PAGE`, `SORT_CHOICES`, `SIGNAL_LIST_QUERY`, `queryToParams`, `clearFiltersPatch` or `rangeText` from it (the other `clearFiltersPatch` and `rangeText` that the grep also finds are `lib/org-accounts/scope-model.ts` and `lib/signal-dates.ts`, other modules' own functions). Removed those six, and 11 of the 22 tests of `list-model.test.ts` (test map 2.9). `tsc` 0 errors; eslint clean; the 11 remaining tests pass.

## Group E: the test router's default, the address guard, the contrast scan

`lib/signals/router-harness.ts` default pathname `/signal-review` to `/signals` (no other test relied on the default: every other caller passes its own pathname). The allowance for it in `lib/signal-review-address-gone.test.ts` is removed (the six tests pass without it). The contrast scan is updated (see `p1c-proofs-2026-11-13.md` section 3). Mutations G1, G2, C1, C1b, C2, C3 prove all three can still fail (`p1c-mutations-final-2026-11-13.md`).

## After all groups: modules that lost their last importer

`orphans.mjs` lists every module that no file imports. Before: 116 modules with no importer. After: the same 116 (no new one) and two fewer modules that only tests imported (`components/domain/crm/signal-review-view.tsx`, `lib/signals/generate-fixtures.ts`, both deleted). So the deletions orphaned nothing and no extra file needed to go.
