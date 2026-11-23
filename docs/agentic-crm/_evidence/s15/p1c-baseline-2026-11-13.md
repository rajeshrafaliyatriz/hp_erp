# P1-C baseline (before any change of mine), 13 Nov 2026

Repo `C:\Users\MILAN\Downloads\g2gv0`, branch `milan1`, HEAD `cd6e20c`, working tree clean (`git status --short` printed nothing). hp_erp was read only.

| check | command | result |
|---|---|---|
| types | `node node_modules/typescript/bin/tsc --noEmit -p .` | exit 0, 0 errors (11 s) |
| whole front-end suite | `node --import tsx --test "lib/**/*.test.ts" "lib/**/*.test.tsx" "packages/**/*.test.ts"` (TAP reporter, kept outside the repo) | **3,326 tests, 592 suites, 3,324 pass, 2 fail**; the two failures are `lib/expandable-run-notice.test.tsx` subtests 3 and 4 (the known ones) |

The leaf-test list used for the before/after account is produced from the TAP output by a small parser (`tapleaves.mjs`, kept outside the repo): it reproduces node's own counts exactly (3,326 leaf tests, 592 suites, the same 2 failures), so the diff of the "before" and "after" lists is a faithful account of every test added or removed.

## Per-file leaf-test counts of the files this track touches (each run alone, before any change)

| file | tests | pass |
|---|---|---|
| lib/signals/signal-review-list.test.tsx | 16 | 16 |
| lib/signals/signal-review-drawer.test.tsx | 20 | 20 |
| lib/signals/signal-review-drawer-extra.test.tsx | 27 | 27 |
| lib/signals/signal-generate-dialog.test.tsx | 42 | 42 |
| lib/signals/generate-model.test.ts | 20 | 20 |
| lib/signals/signal-run-service.test.ts | 12 | 12 |
| lib/signals/signal-export.test.tsx | 12 | 12 |
| lib/signals/export-csv.test.ts | 30 | 30 |
| lib/signals/contrast.test.ts | 35 | 35 |
| lib/signal-review-address-gone.test.ts | 6 | 6 |
| lib/signals/list-model.test.ts | 22 | 22 |

## Importer census of everything on the delete list (read with grep, before deleting)

- `components/domain/crm/signal-review-view|list|drawer|parts.tsx`, `signal-generate-dialog.tsx`: imported only by each other and by the Review-only tests (`signal-review-list.test.tsx`, `signal-review-drawer*.test.tsx`, `signal-generate-dialog.test.tsx`, `signal-export.test.tsx` line 175). No page, no content map entry (P1-B removed it), no other component.
- `components/domain/crm/signal-runs-panel.tsx`, `signal-export-button.tsx` (one-line re-exports): imported by `signal-review-view.tsx` and by `lib/signals/signal-export.test.tsx` (line 37).
- `lib/signals/generate-model.ts`: imported by `signal-generate-dialog.tsx`, `signal-review-view.tsx`, `generate-model.test.ts`, `signal-generate-dialog.test.tsx`.
- `lib/signals/generate-fixtures.ts`: imported by `generate-model.test.ts`, `signal-generate-dialog.test.tsx` and `signal-export.test.tsx` (for `pressButton` only).
- `services/signal-review/signal-run-service.ts`: imported by `signal-generate-dialog.tsx`, `signal-review-view.tsx`, `generate-fixtures.ts`, `generate-model.ts`, `signal-generate-dialog.test.tsx`, `signal-run-service.test.ts`.
- `SignalExportButton` (components/domain/signals/signal-export-button.tsx) and `collectSignals` (lib/signals/export-csv.ts): `SignalExportButton` is used by `signal-review-view.tsx` and `signal-export.test.tsx`; `collectSignals` only by `export-csv.test.ts` (nine tests).
- `lib/signals/list-model.ts`: `SIGNAL_LIST_QUERY`, `queryToParams`, `PER_PAGE`, `SORT_CHOICES`, `clearFiltersPatch` and `rangeText` are used only by the two Review files and by `list-model.test.ts`; `STATUS_CHOICES`, `FRESHNESS_CHOICES`, `reviewStatusOf`, `isNarrowed`, `listScreenState`, `productChoices`, `fitChoices`, `fitLabel`, `fitSummary`, `summaryTiles`, `applyStatusChange` and `Choice` are imported by `opportunity-query.ts`, `signal-list.tsx`, `company-opportunities.tsx`, `signal-parts.tsx`, `opportunity-row.ts`, `export-csv.ts`, `fit-score.ts` and `lib/leads/model.ts`, so they stay.
