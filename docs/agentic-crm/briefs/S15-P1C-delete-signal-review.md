# Track S15-P1C: delete the old Signal Review screen, losing no test coverage (front end, g2gv0)

Read `COMMON-RULES.md` first (its section 2 carries the 13 Nov exception), then `../DECISIONS.md` (entry 2026-11-13) and `../slices/S15-product-portfolio-merge.md` (plan P1 step 1.7, and the P1-A, P1-A2 and P1-B logs plus the caller verification notes; the final PARITY table in the P1-A2 log says every Review feature now lives on `/signals`). Written 13 Nov 2026. g2gv0 HEAD `cd6e20c`, clean tree at your start. You work in g2gv0 only; hp_erp is read only except the slice log and your evidence. Another track (P2) works in hp_erp, backend only.

## Goal
The Signal Review SCREEN has no users left: both addresses redirect to `/signals` (P1-B), the sidebar never draws it, and Company Opportunities does everything it did (P1-A, P1-A2). Delete its code, so a new reader finds one place for signals, and so nobody can resurrect a second screen by importing the old one. **No test coverage may be lost**: a behaviour a deleted test pinned must still be pinned by a test that stays.

## Delete (after you have proved each has no remaining importer: `grep -rn` the name and run `tsc`)
- `components/domain/crm/`: `signal-review-view.tsx`, `signal-review-list.tsx`, `signal-review-drawer.tsx`, `signal-review-parts.tsx` (the one-line re-export that keeps the drawer compiling), `signal-generate-dialog.tsx`, and the two one-line re-exports `signal-runs-panel.tsx` and `signal-export-button.tsx` (their real files live in `components/domain/signals/`).
- `lib/signals/`: `generate-model.ts`, `generate-fixtures.ts` (once nothing reads it), `generate-model.test.ts`, and the Review-only tests: `signal-review-list.test.tsx`, `signal-review-drawer.test.tsx`, `signal-review-drawer-extra.test.tsx`, `signal-generate-dialog.test.tsx`, and the Review adapter parts of `signal-export.test.tsx` (keep what tests code that stays: `export-csv.ts` `collectPages`/`buildCsv`).
- `services/signal-review/signal-run-service.ts` and its test (the refusal classes already live in `services/signals/run-refusal.ts`), `SignalExportButton` and `collectSignals` (the Review's adapter in `components/domain/signals/signal-export-button.tsx` and `lib/signals/export-csv.ts`), and anything else that only the Review screen used (find it by deleting and running `tsc`; list every extra file you remove in your report).
- Decide and say what to do with `lib/signals/list-model.ts` functions that only the Review used (`tileSummary`, the Review's own query config): keep what `opportunity-query.ts` or other screens import; delete the rest only with the grep proof.
**Do not delete:** `components/domain/crm/signal-inbox*.tsx` (the Signal Inbox is a separate legacy screen the owner said to leave), `services/signal-review/{runs-service,signal-review-service,decision-error,signal-types,types}.ts` (the Company Opportunities card and Run health use them), `lib/signals/{run-failure,export-csv,format,fit-score,lead-action,decision-actions,decision-failure,list-model,signal-display}.ts` and what they need, the redirect pages and `lib/signal-review-redirect*`, `lib/signals-nav*` (the sidebar fold must stay: the menu row 506 stays in the database).

## Edit
- `lib/signals/router-harness.ts`: the default `pathname` becomes `/signals`; remove its allowance in `lib/signal-review-address-gone.test.ts` (the guard must then pass WITHOUT it and still fail when the old address is written in a source file: mutate to prove it).
- `lib/signals/contrast.test.ts` and any other scan that lists or counts `signal-review-*.tsx` files (it expects at least 5): update to the files that remain, with the same strictness on the remaining new components; no scan may become weaker (say what each scan covered before and after).
- `lib/leads/screen-kit.ts`, the exported name `signalReviewHref`, the attribute `data-signal-review-link` and the screen-kit option `signalReview`: rename them to say what they are now (a link to a signal on Company Opportunities) only if you can do it with `tsc` as the net and no test text changes meaning; otherwise leave them and list them as follow-ups.

## The test map (the heart of this track)
Before deleting any test file, write a table into `../_evidence/s15/p1c-test-map-2026-11-13.md`: for every test (or group) in a file you delete, the behaviour it pinned and the test that pins it now (file and test name), or "pinned code is deleted". Behaviours with NO replacing test: write the replacing test in a file that stays (use `lib/signals/company-opportunities-screen.test.tsx` and `lib/signals/screen-harness.ts` as the pattern), then delete. Count tests before and after (`# tests` of the whole suite, now 3,326) and account for the difference line by line.
Mutation rule: for each replacing test you WRITE, break the code it covers (a named test fails), restore byte for byte and `cmp`. Prove the baseline green first.

## Proofs
`tsc` 0 errors; eslint `--max-warnings=0` on every file you touched; the whole front-end suite with only the 2 known failures (`lib/expandable-run-notice.test.tsx` subtests 3 and 4); `grep -rn "signal-review" app components hooks lib services` shows only: the redirect pages and their helper/tests, the sidebar fold and its test, the guard test, `services/signal-review/` imports, the exported helper name if you kept it, and comments. List every remaining hit with a reason. `git diff --stat components/ui components/shell app hooks` empty (except none expected).
**Hazard (found on 13 Nov):** a failing `assert.ok(expr)` WITHOUT a message late in a long `.tsx` test can run for minutes at full CPU; give every `assert.ok` you write a message. Clicking a missing button inside `act()` hangs: guard it.

## Do not
Edit `components/ui`, `components/shell`, `app/**`, `hooks/**`, any backend file, any menu row or migration; commit or push; start a server; use `npx`. Use Write/Edit (no heredocs).

## Report (COMMON-RULES section 7) plus
The files deleted (full list), the test map summary, the before/after test counts, the remaining `signal-review` hits. Append a "P1-C" log to `../slices/S15-product-portfolio-merge.md`; evidence under `../_evidence/s15/p1c-*`.
