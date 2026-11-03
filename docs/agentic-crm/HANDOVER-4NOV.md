# Handover for the morning of 4 Nov 2026

Written by the assistant while the owner slept. Updated as work finishes; newest status first. **Nothing is pushed, deployed, sent or run on live.** Everything below is on the dev database and the local branches `Milan-2` (hp_erp) and `milan1` (g2gv0).

## Status at 02:20

- Built, verified by me (tests re-run, rows re-read) and committed: Product Master folded into Products (Y), Organisation 360 folded into Organizations (X), the Organisation agent with Agent Run History (Z), contrast fixes for the agent screens and Signal Review, and the merge of the team's `origin/main` into both branches (track M).
- Running when this was written: S4 (contact facts ledger and consent): backend S4a and front end S4b. Final full test runs on the merged branches are in progress in isolated checkouts.
- Plan completion: about 29% (rough count of the plan's own session estimates).

## Waiting for your word

1. **Push** `Milan-2` and `milan1` (you said: at the very end). Both already contain the merge of `origin/main` (hp_erp 3 commits, g2gv0 30 commits behind before the merge).
2. **Live rollouts (each needs your go and the scratch-table probe of DB-RULES):** (a) the five agent migrations (`2026_12_10_0900*`) before the agent code is deployed; (b) the identity tables of S3 (`2026_12_09_090000/090100`) and the Product Master table (`2026_12_07_09*`) before the Organizations and Products screens are deployed (until then the new routes answer "not set up" and the screens look as before); (c) the Signal Review menu-row move (`2026_12_10_120000`, changes nobody's access); (d) S2 deploy and the grant. Not on live today except S2's database changes (your go of 3 Nov).
3. **Tell the team (Harshit), not done by me:** in the team's own `origin/main` (0126241a) 22 tests fail and 1 errors without an app key; causes found: `OpportunityController` lines 997, 1001, 1029 use `o.product_key` unguarded (11 tests), `presentOpportunity` read `region_scope` and `icp_version` unguarded (7 tests; we made those two reads tolerant), their commit `701fb1ab` double-encoded `ProductCatalog.php`, `IcpProfileController.php` and `SubProductIcpOwnershipTest.php` (a visible `â†’` in the sub-product run label at `ProductCatalog.php:264`), `POST /api/departments-management/{id}/merge` is registered twice. Details: `D:\dev\merge-trial\out\` (baseline-failures.tsv, routes-*.txt).

## Decisions that are yours (all two-way doors)

- **Who may view Agent Run History** (menu 503): on tenant 6 the Admin, HR and three more profiles already have view; they will read every agent brief once runs are listed.
- **The `[Agent] ` prefix** on a note's text (the `created_via` column marks it either way).
- **Products read rights:** anyone with Products view can now read product lines, ICP states, signal counts and an item's price books (never ICP content or signal rows). **Organizations:** the 360 is readable (contacts, deals, signal decisions) by anyone who can open the organization; the old Account 360 sections still need the Market Signals right (one line in `routes/api.php` would change that).
- **ICP column on a product line** counts sub-products with an ACTIVE profile; the Portfolio's own summary can disagree for a draft or an ICP stored only in the offer's config. Align later or accept.
- **Cost test** `RunCostTest` uses the team's legacy ICP switch (as their tests do); the new default path is not exercised there.
- **Wording:** "product line" for the top-level group in the Products screen.

## Done with evidence (where to look)

`00-PROGRESS.md` (log entries per track), `DB-CHANGELOG.md` (rows 26 to 33), `DECISIONS.md`, `FEATURE-LEDGER.md`, per-track evidence under `_evidence/` (s7-agent-gateway incl. `caller-verification-2026-11-03.md`, y-products-fold-in, s3-folded-*), the published plan page (artifact version 13).

## Failed or open, with output

- Real-browser check of the finished, merged screens (Products, Organizations with the agent panel, Agent Run History, the four Signals tabs, Signal Review in the sidebar under the team's navigation) is not done yet: the servers were kept free for track X's runs. Queue item 4.
- Findings in shared code nobody touched: `components/ui/button.tsx:11` hover contrast 4.32:1; the 390 px shell menu (`gtg-sidebar.tsx:490`, `:625`, `gtg-user-menu.tsx:196`); `form-field.tsx:52` silent 3.97:1 field error; `detail-page.tsx:44` prints the backend's raw message (on dev it showed a SQL error with the database host).
- Performance on dev: Account 360 about 90 s to show (single-threaded PHP over a remote database; 16 of 41 statements are column-existence checks that can be batched).
- Dev data to clean after the browser check: the "S3 CHECK" seed rows (state file `D:\dev\agentic-crm-research\scratch\s3-seed-state.json`), one old walkthrough token on dev, scratch worktrees `D:\dev\merge-trial\*` and branch `merge-trial-3nov`.
- The agent's runs 1 and 3 and tasks 470 and 471 are left on the dev tenant 6 on purpose (real proof); delete if in the way.
