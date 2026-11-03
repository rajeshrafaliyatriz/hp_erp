# S3 browser walkthrough of the STANDALONE Organisation 360 screen - 2026-11-03

Context: this tested the separate sidebar entry (`/module/crm/organisation-360`). The owner then objected that it duplicates the existing Organizations screen, so the screens are being folded into Organizations (decision row 2026-11-03 in `DECISIONS.md`). The components and the API stay, so these findings carry over. The folded screens get their own walkthrough (evidence: `D:\dev\agentic-crm-research\evidence-s3\browser-folded\`).

Method: a walkthrough agent drove real Chromium (Playwright) as the tenant-6 administrator against the local Laravel + Next dev servers and the dev database, on throw-away rows named "S3 CHECK ...". Raw material (outside the repo): `D:\dev\agentic-crm-research\evidence-s3\browser\` (`run.log`, 13 axe JSON files, 31 screenshots). The agent was stopped early at my request after about 44 minutes.

Tags: **agent-reported** = from the agent's report, not re-run by me. **verified** = I read the source myself.

## Result (agent-reported): final full run on re-seeded data, phases 1-8: 114 PASS, 15 FAIL

| Check | Result |
|---|---|
| 1 List (tiles equal the API summary, per-row match, facets, clear filters, paging) | PASS (axe failures only) |
| 2 Possible duplicates (confirm, reject by keyboard, rejected pair not re-offered, 409 shows a readable alert) | PASS except the place-conflict pair (D2) |
| 3 Foxtrot detail (all tabs, contact, deal, note, links open existing screens, no undefined/null text) | PASS |
| 4 Alpha detail (signal status in words) | PASS |
| 5 Edit (PATCH, persists, 422 path with aria-invalid and aria-describedby) | PASS; message not announced (minor) |
| 6 Create CRM organisation (one live row; deleted-name case asks to restore, no duplicate row) | PASS |
| 7 Not found | PASS (breadcrumb ends in "...") |
| 8 Keyboard | focus does not enter the confirm question (D1); one arrow-key sub-check INCONCLUSIVE |
| 9 Phone 390 px | NOT RUN in the final run; an earlier partial run: 28 PASS, 1 FAIL (Back button 20 px, D7) |
| 10 axe wcag2a/aa/21a/21aa | FAIL on every page except the confirm dialog: all "serious", none critical (details below) |
| 12 No-rights view | NOT RUN (skipped on purpose). **403 in a real browser is still unproven.** |

Instruments (agent-reported): every API response >= 400 was provoked on purpose (409 stale pair, 422 cleared field, 409 deleted_name_exists x2, 404 x2); no uncaught page errors; console errors only the browser's own 404 lines.

## axe (agent-reported, raw summary)
- list: colour-contrast x13 (Target chip and CRM/GTM/Signals chips, 4.31:1 vs 4.5), listitem x3 (breadcrumb).
- detail tabs: colour-contrast x4 (Engaged chip 1.89:1, source chips 4.31:1), listitem x4.
- notices: success text 2.1:1, error 3.97:1.
- Baseline: the EXISTING `/module/crm/signal-review` shows the same two rule classes, so the shared chip variants and the shell breadcrumb are pre-existing, not new.

## Defects

| # | Defect | Status |
|---|---|---|
| D1 | Focus does not enter the confirm question: `components/ui/confirm-dialog.tsx:52-66` renders Cancel as a plain Button; `alert-dialog.tsx` exports no AlertDialogCancel, so Radix has nothing to focus. Affects every confirm in the app | agent-reported with a focus-trail probe; fix scheduled (minimal, flagged) |
| D2 | A place-conflict pair reads "100% of the name words match", same as an ordinary suggestion | **verified in source**: `OrgResolver::pairs()` returns only key/left/right/basis/score |
| D3 | Pairs that can never be confirmed are still offered (after confirming Charlie School with its GTM account, the other Charlie pairs stay and return 409) | **verified in source**: `pairs()` excludes same-identity, same-type, rejected and planned pairs, but not "would put two rows of one source into one identity" |
| D4 | Contrast of this screen's chips and notices, and of the shared StatusBadge variants `pending` (1.89:1) and `processing` (4.31:1) | agent-reported; the shared variants also fail on Signal Review (pre-existing) |
| D5 | Shell breadcrumb puts `<li>` in `<nav>` without `<ol>` (`components/ui/breadcrumb.tsx`) on every page | agent-reported; pre-existing (same on the Signal Review baseline) |
| D6 | A lone-source organisation's header says "linked, reason not recorded" | agent-reported, minor |
| D7 | Icon-only Back button shrinks to 20 px on a phone (`components/shared/scaffolds/detail-page.tsx:52-55`) | agent-reported, minor |
| D8 | Tab accessible names run label and count together ("Signals0"); not-found breadcrumb ends in "..." | agent-reported, minor |

## State left behind
Seed rows ("S3 CHECK ...") and their identities are still on dev, mutated (Charlie confirmed, Echo rejected, Golf has a CRM organisation, Hotel restored). The administrator's Organisation 360 grant is in place (dry run: "already has the screen"). Both dev servers are still running for the folded-screen verification. Cleanup is `php D:\dev\agentic-crm-research\scripts\g2g\s3_browser_db.php cleanup D:\dev\agentic-crm-research\scratch\s3-seed-state.json`.

## What this does and does not prove
It proves the identity data, the API and the components behave correctly for the seeded cases in a real browser, and it found real defects (two confirmed in source). It does not prove the rights-less (403) view, phone width in the final run, or anything about the folded screens.
