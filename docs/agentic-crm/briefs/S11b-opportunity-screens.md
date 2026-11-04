# Track S11b: the opportunity screens show the product and ask for a coded loss reason (brief for a build agent, small, full stack)

Read `COMMON-RULES.md` first, then `../slices/S11-pipeline-and-forecast.md` (the contract and the S11a work log: what the backend accepts and answers), `../PLAN-USE-TODAY.md` (this is daily job J6), `../DECISIONS.md` (no duplicate screens). Written 4 Nov 2026 (about 09:45 IST). The backend is committed (hp_erp `46232884`).

## Goal (job J6: move an opportunity through its stages and close it Lost with a reason)

The EXISTING opportunity screens (`components/domain/crm/opportunity-list-view.tsx`, `opportunity-kanban-view.tsx`, `opportunity-detail-page.tsx`, `create-opportunity-modal.tsx`, g2gv0) learn two things the backend now knows, and nothing else changes:
1. **Product.** Each opportunity shows its product (a Product column in the list, shown only when at least one row has one; a read-only line in the header of the detail page; an optional Product select in the create and edit form, reading `GET /api/product-master/options` the way the Leads filter and the campaign form do; sent as `productMasterId`, `null` when cleared, nothing when untouched).
2. **A coded loss reason.** When a person moves an opportunity to Closed Lost (on the kanban board and in the edit form) they choose a reason from the coded list (price, no budget, no decision, competitor, timing, no response, other; the labels come from the server) and may add free text; the choice is sent as `lossReasonCode` with the stage change. Today the kanban asks with a browser prompt for free text only (see `opportunity-kanban-view.tsx` around line 172): replace the prompt with a proper accessible dialog (the shared dialog and `use-confirm` scaffolds in `components/shared/scaffolds/`, focus returns to the card, Escape cancels and the card goes back). The detail page shows the reason in words (label, plus the free text when there is some). The server may require a reason (`crm_sales.loss_reason_required`, OFF today): when it refuses with code `loss_reason_required` the dialog says so next to the select.

## The one backend gap you may fix (additive, with tests)

The client has no way to learn the allowed codes: `lossReasonCode` is validated against `OpportunityRules::lossReasonCodes()` but nothing lists them. Add them to an EXISTING read the opportunity screens already make (the opportunities options or picklists answer; read `CrmOpportunityController` and `services/crm/index.ts` to find it), as `lossReasons: [{code, label}]`, additive, with a test in `tests/Feature/PipelineLinksApiTest.php` or a new `OpportunityOptionsTest.php` and a mutation proof; do not add a route if an existing answer can carry it. Do not change any other backend file. The S11a files are committed and finished: edit only what this needs.

## Raw material

The files named above and their tests; `services/crm/index.ts` and `types/crm.ts` (add OPTIONAL fields, both casings, as `lib/leads/model.ts` and `lib/campaigns/model.ts` do: read them and reuse the pattern, do not copy); `lib/leads/*` and `lib/campaigns/*` (pure rules, tones, screen kit); the tones `lib/org-accounts/tones.ts` (words carry the meaning, text-foreground on a solid surface, only the border takes a status colour, no shared Badge or StatusBadge); `lib/agent/contrast.test.ts` (the scan list: add your new files).

## Tasks

1. Re-read the contract and the S11a controller; list in your slice log every field you rely on, marked optional (an older server must render as before).
2. The backend gap (above) with its test and mutation.
3. Types, service readers and a pure model `lib/opportunities/model.ts` (the reason's words, when the Product column shows, the payload rules: send `productMasterId` only when chosen or changed, `lossReasonCode` only with Closed Lost).
4. The dialog, the list column, the detail lines, the form select; whole-screen jsdom tests over faked services including axe, the contrast scan, mutation proofs for every rule and guard (break, see the named test fail, restore byte for byte, `cmp`); `tsc` (only the known errors elsewhere), eslint `--max-warnings=0` on your files, `git diff --stat components/ui` empty; the PHP test files you touched, one phpunit process.
5. Reason about 390 px in the code (the dialog must fit; no table inside a scroll box without `relative`).

## Do not

Edit `components/ui`, `components/shell`, any Signals / Ingestion / Partner file, the Leads, Campaign, Agent, Ask and Facts files, or any backend file other than the one gap. No push, no commit (the caller commits), no live database, no message of any kind, no server (the caller verifies in a real browser), never `npx`. Other agents work in these trees now (S10a in hp_erp: OutreachGate, outreach routes and mailers; S9b in g2gv0: campaign files and `types/crm.ts`, `services/crm/index.ts`; the browser tester only reads): never stage or edit files that are not yours, and for the two shared g2gv0 files (`types/crm.ts`, `services/crm/index.ts`) make small additive edits only, re-reading the file immediately before each edit.

## Report (COMMON-RULES section 7)

Files added or changed, the backend change, deviations with reasons, test counts and mutation results, what you could not exercise against real data (dev has 1 or 2 opportunities: the caller will use the browser), what the caller must check. Keep `../slices/S11-pipeline-and-forecast.md` current (append-only work log, an S11b section).
