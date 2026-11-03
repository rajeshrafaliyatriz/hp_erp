# Agentic CRM — Progress

Living log for building the agentic CRM one slice at a time.

**Read this file first in any new session, then the current slice file in `slices/`.** Update it after every task and at the end of every session, so a cold session can resume with zero re-derivation. After any context compaction, re-read this file and the slice file before doing anything else. Do not rely on a chat summary.

- Plan (approved 2026-11-02): `PLAN.md` in this folder (copy of the planning-session plan).
- Published pages (private artifact): https://claude.ai/artifact/1ZoowKicrqvTLrunZwE2aK (plan page, architecture page, build plan page).
- Source HTML of those pages, kept outside this repo: `D:\dev\agentic-crm-research\published-pages\`.
- Research behind the architecture: `D:\dev\agentic-crm-research\` (notes, report, sensitivity script, read-only db3 scripts).
- Rules: `SESSION-RULES.md` (the eight steps), `DB-RULES.md` (database), `DECISIONS.md` (owner decisions), `DB-CHANGELOG.md` (every migration and data update).

## Next action

<!-- progress-update-3nov-2045 -->
**Read `briefs/COMMON-RULES.md`, then the brief of the track you work on (`briefs/X-organizations-fold-in.md`, `briefs/Y-products-fold-in.md`, `briefs/Z-organisation-agent.md`).** Three build tracks were running when this was written (3 Nov, about 20:45 IST) after a session limit had cut the first attempt off; their uncommitted work is in the working trees of hp_erp (`Milan-2`) and g2gv0 (`milan1`). **Nothing is pushed; nothing new is on live.** Check `git status` in both repos before anything else, and do not attribute commits by author name (a second session shares this git identity and commits too, for example the reports engine).

**What the owner decided on 3 Nov** (text and reasons in `DECISIONS.md`, rules in `SESSION-RULES.md`, plan changes in `PLAN.md` section 0):
- **No duplicate screens.** Product Master (menu 505) and Organisation 360 (menu 507) are removed as sidebar entries (rolled back on dev, verified: no rows, no rights) and folded INTO the existing Products and Organizations screens. Their data layers (`g2g_product_master`, `g2g_org_accounts`, resolvers, backfills, APIs, the merge fix) stay as plumbing.
- **The Signals screen design is frozen until the very end** (four tabs: Company Opportunities, Ingestion Engine, Product Portfolio, Partner Network; the live design is `origin/main`, Harshit's branch is inside it). Business logic inside may change. **Ingestion Engine and Partner Network are protected.**
- **A visible agent is wanted now** (Comp AI's pattern): the Organisation agent, a thin slice of S7, pulled forward.
- Pushing is only at the very end, by the owner's word.

**State of the three tracks** (partial state verified by the caller at 20:20; the cut-off agents' own reports were lost):
1. **Y, Products fold-in: DONE, verified and committed on 3 Nov at about 23:15** (hp_erp `8a779ea1`; g2gv0 `32a5bb1` (the content map) and `5f37ca9`; nothing pushed). One Products screen with a Catalogue / Product lines switch; write-through in every writer of the three product tables; `product-master:repair`; menu 505 rolled back on dev. Open: the real-browser check (Y started no server) and the owner's review of the wording "product line".
2. **X, Organizations fold-in: DONE, verified and committed on 4 Nov at about 00:50** (hp_erp `fd3b095b`; g2gv0 `4210fdb`, then my contrast fix `838751e`; nothing pushed). Scope switch, merged Account 360 tab with the agent panel mounted, defects D1 to D8 fixed, menu 507 rolled back on dev. Real browser: 91 checks, all pass in the last runs. Open: findings in shared code it did not touch (see the log).
3. **Z, Organisation agent: DONE, verified and committed on 3 Nov at about 22:25** (hp_erp `153d959e`, `d308a387`; g2gv0 `b45b4c6`, `c7eeb29`; nothing pushed, 5 tables on dev only). The panel exists but is NOT yet mounted on the Organization page until track X's work is committed (X already imports it in `account-360.tsx`). The first real run's brief was wrong in three ways; fixed (see the log). Open: the real-browser check, the menu-503 decision.
4. **Done by the caller:** the Signal Review menu move (`2026_12_10_120000`, dev batch 565, 8 tests, 6 of 6 mutations caught) because the live Signals navigation would drop that row from the sidebar.

**After the three tracks report:** (a) re-run every claimed test yourself and spot-check two claims per report; (b) run the full PHP suite ONCE, sequentially, with nothing else running (about 7 minutes; the 3 older failures are `ScholarCloneIcpProposalTest`, `IcpPackResearchTest`, `ProductDrivenDiscoveryTest`); (c) one combined real-browser walkthrough: Organizations (scope switch, Account 360 tab, agent panel), Products (new columns and tabs), Agent Run History, and the four Signals tabs unchanged; (d) check `git diff --stat -- components/ui` shows only the permitted tiny edits (confirm dialog Cancel, breadcrumb `<ol>`); (e) commit each track separately, then update the ledger, the changelog (rows from the track reports), the S01, S03 and S07 slice files and republish the artifact; (f) clean up the "S3 CHECK" seed rows and the minted token, stop the servers.

**Still open for the owner** (none blocks building): the live go for the Signal Review menu move when S2 is deployed; the S2 live follow-ups in "Open owner decisions" item 10; the push itself at the very end.

**Merging `origin/main` (the team keeps moving the Signals code) — checked 3 Nov 22:20 with `git merge-tree` on the COMMITTED state only, nothing touched:** g2gv0 `origin/main` is 30 commits ahead of `milan1` (it brings the live Signals design) with ONE conflict, `hooks/content-map-crm.ts` (they changed 1 line, we added 12: keep both). hp_erp `origin/main` is 3 commits ahead of `Milan-2` (Harshit's sub-product ICP refactor and its tests, plus the rbac fix that is also on our branch) with ONE conflict, `app/Http/Controllers/Api/Signals/OpportunityController.php` (both sides edited it: ours +110 lines for the S2 review / act / promote work, theirs +92). The refactor also rewrites `ProductIcpController` (-152 lines), which track Y hooks into, and adds `SubProductIcp` / `ProductCatalog` logic that the Product Master reads: expect a second conflict once Y is committed, and run `SubProductIcpOwnershipTest`, `ProductMasterTest` and `CustomProductApiTest` after merging. **Plan:** merge `origin/main` into both branches locally AFTER tracks X and Y are committed (a merge with their uncommitted edits in the tree would collide with them), resolve, run the full suites, then confirm the Signal Review row is in the sidebar with the live navigation code. Not pushed. Verified at the same time: no Signals design file (`components/domain/signals/*`, `lib/signals-nav.ts`, `app/signals|ingestion|portfolio|partners`) and no Ingestion / Partner backend code has been touched on our side.

## Overnight queue (owner asleep, authorised 4 Nov 00:15 IST: "continue working until you complete our plan")

**Rules while the owner is away:** never push; never touch the live database or deploy; never send any message; nothing outward-facing is decided by the assistant (it goes to `HANDOVER-4NOV.md`); the Signals design, Ingestion Engine and Partner Network stay frozen; temp files only in the scratchpad (never `/tmp`); no tokens or secrets printed. A recurring cron job (`17,47 * * * *`, session-only, expires after 7 days) re-enters this queue after a usage-limit reset: read this section, `ListAgents`, `git status` in both repos, then continue.

**State at 00:15:** tracks Y and Z are done and committed; track X (Organizations fold-in, agent `a33a85adf067e17bb`) is in its 4th real-browser run; track M (agent `ab1b709f15b44e56f`, brief `briefs/M-merge-origin-main.md`) fixes the merge of `origin/main` in scratch worktrees under `D:\dev\merge-trial\` and delivers two patch files in `D:\dev\merge-trial\out\`. The caller's uncommitted work in g2gv0: the contrast fix `lib/agent/tones.ts` + `contrast.test.ts` + four agent components (depends on X's `lib/org-accounts/tones.ts`, so commit it AFTER X).
**Update 00:35:** three tracks run at once: X (`a33a85adf067e17bb`, Organizations fold-in, 4th browser run 89 pass / 2 fail), M (`ab1b709f15b44e56f`, merge fix in scratch trees), S4a (`a73b01c7537868230`, contact facts ledger and consent backend, brief `briefs/S4a-facts-backend.md`, slice file `slices/S04-contact-facts-and-consent.md`). The pristine `origin/main` baseline suite has 22 failures and 1 error of its own (1,556 tests): not ours, M classifies them. Agent fixes committed: `79da059f` (an empty timeline is not a date; RUP shown as INR). S4b (front end of S4) waits for X to be committed. Max three big agents at once (a fourth exhausted the usage limit once).
**Update 00:55:** X, Y and Z are verified and committed in both repos (hp_erp `fd3b095b`, `8a779ea1`, `153d959e`; g2gv0 `4210fdb`, `5f37ca9`, `b45b4c6`) plus contrast fixes (`838751e` agent screens, `c948059` Signal Review). Running now: M (`ab1b709f15b44e56f`, merge fix, writes `D:\dev\merge-trial\out\REPORT.md`), S4a (`a73b01c7537868230`, backend), S4b (`a584d834a2fd49543`, front end, brief `briefs/S4b-facts-frontend.md`). Next for the caller when M reports: merge `origin/main` into both real branches, apply M's patches, full suites, combined browser check, cleanup, artifact. Do not edit `signal-generate-dialog.tsx` or `product-signals-icp-tab.tsx` before M's frontend patch is applied (it touches them).
**Update 02:20:** both real branches now contain the merge of `origin/main` (hp_erp `e7d87061` + fix `b37ad2dd`; g2gv0 `ec4c27d` + fix `05221b1`); track M done (its patches applied, tests re-run: 126 backend, 780 front-end). Queue items 2 done; item 3 (final full suites) running in isolated checkouts of the real HEADs under `D:\dev\merge-trial`. S4a and S4b resumed after the 1:10 limit reset.
**Update 02:25 (4 Nov):** item 3 done (final suites: PHP 15 failures + 1 error identical to the team's own `origin/main` baseline, front end 1836 of 1837 with one timing flake that passes alone). S4a (contact facts ledger and consent, backend) verified and committed (hp_erp `78c43449`). The organisation-merge hook for facts and consent (S4a's queued gap) is done and committed (`14bc36ab`, verified: the merge, facts-merge, scan and parse suites, 43 tests, all pass; two mutations killed: removing the hook fails `FactsMergeTest`, copying preference rows before the registry walk fails `OrganisationMergeTest`). Two defects found on the way and fixed: (1) `Throwable` was not imported in `OrganisationMerger`, so the bookkeeping `catch` would never have matched and a facts failure would have stopped a merge; (2) `ConsentRecorder::carryOver` copied the removed organisation's preference rows, so the registry then dropped the moved row as a duplicate, which now takes a flag (`$copyMissingPreferences`) and a merge only lets the opt-out win. The combined real-browser run (item 4) is finishing its phases 4 and 5; so far: the Products switch passes 2.1 to 2.5, but axe still finds 7 `color-contrast` nodes on the product lines page and 3 on Agent Run History, and Agent Run History scrolls sideways at 390 px (scrollWidth 846); the sidebar and not-found checks (1.3 to 1.5) are script defects (the sidebar is buttons, not links), not product defects. S4b (frontend) is still running (`a584d834a2fd49543`).
**Update 03:10 (4 Nov):** item 4 (the combined real-browser check) done on the dev stack, four runs (`D:\dev\agentic-crm-research\evidence-s3\combined-4nov-b` to `-e`): 0 API answers of 400 or more, 0 console errors, 0 page errors, 0 failed requests on every run; phases 1 to 6 pass except the items below. What it found, all mine and fixed (g2gv0 `58972e8`, `ffd9e84`, each with a test that computes the contrast from `app/globals.css` or scans the source, and mutations proving the test can fail): (1) the product-line readiness, Sold and ICP chips drawn with the shared StatusBadge were 1.89 to 2.03:1 and the active side of the Catalogue / Product lines switch was 4.31:1; (2) the Agent Run History badges (shared `Badge navy`) were 4.31:1; (3) the shared `PageTabs` selected tab was 4.32:1 in the dark theme; (4) Agent Run History scrolled sideways on a 390 px phone (scrollWidth 877) because the `sr-only` text in the last column is absolutely positioned and escaped the scroll box, the box is now `relative`. Verified after the fixes in the same real browser: axe finds no serious or critical violation in the light theme on Products (product lines), Agent Run History, Signal Review, the Suggestions tab, the contact page and Account 360; Agent Run History at 390 px is 390. The four Signals pages still render with their four tabs (design untouched) and Signal Review is found by the menu search; Product Master and Organisation 360 are gone. The new S4 screens were exercised with real data: five facts were proposed through the real `FactWriter` on dev, one accepted from the Suggestions tab (the contact's mobile column was written: verified in the database, "Accepted. The mobile number was written to the record."), one dismissed with a reason ("Dismissed. This value will not be suggested again."), the contact page shows the consent line and the Evidence section, Account 360 shows the Evidence section. Still failing in the DARK theme and NOT mine: the shell breadcrumb (`gtg-breadcrumb.tsx:50`) and the `text-primary` tab and link on the team's contact and organization pages (see `HANDOVER-4NOV.md`). The script's own defects (sidebar is icons and buttons, not links; not-found wording; clicking the wrong Dismiss button) were fixed in `combined-check-3nov.cjs`, never counted as product failures. Item 5 was done earlier (79da059f). Backend S7b verified and committed (`70f536b1`: 168 agent tests re-run by me, two mutations of my own killed on top of the track's 67); the front end S7c (the Waiting for you and Autonomy tabs on Agent Run History) and the leads tracks S6a and S6b are running. Dev rows to clean at the end: the five `s4_check` facts and the "S3 CHECK" rows (`s4_browser_db.php cleanup`, `s3_browser_db.php cleanup`).

**Queue, in order (tick as done, with the evidence path):**
1. ~~Verify and commit track X~~ **Done 4 Nov 00:50** (206 PHP tests and 578 front-end tests re-run; menu rollback, admin rights, frozen Signals paths, `components/ui` diff and harness removal spot-checked; committed `fd3b095b`, `4210fdb`, `838751e`). DB-CHANGELOG rows 22 and 24 marked rolled back, row 33 for the seed data.
2. Merge `origin/main` into `Milan-2` and `milan1` (resolutions are in the scratch trees: `OpportunityController.php` keep both sides; `content-map-crm.ts` keep Signal Review routes, take the team's signal-inbox mapping, drop the unused import) and apply track M's patches. The team's own `origin/main` fails 8+ of its own tests (`Undefined property: stdClass::$region_scope`): those are theirs, list them, do not hide them.
3. Full PHP suite once and `npm test` once, sequentially, nothing else running; compare with the pristine `origin/main` baseline.
4. Combined real-browser check (`D:\dev\agentic-crm-research\scripts\browser\combined-check-3nov.cjs`, phases 1 to 5, plus X's walkthrough as a regression), a fresh agent run per brief (check each sentence against the rows with `scripts\g2g\check_run_brief.php <run id>`), dark mode and 390 px.
5. Fix the two agent-brief defects the second real run showed (see the log): "since none logged" read as a date, and currency "RUP" echoed from `crm_opportunities.currency`.
6. Clean up: "S3 CHECK" seed rows (`s3_browser_db.php` cleanup), minted tokens `pm-walkthrough-*` on dev (they were found with a month-long expiry), stop servers, `git worktree remove` the scratch worktrees and delete branch `merge-trial-3nov`, remove copies of `vendor` on D:.
7. Republish the Artifact (version 13) with verified status; memory notes.
8. Then the plan, slice by slice, each with a written brief, disjoint file ownership, tests, mutation proofs, evidence, and a commit per track: S4 (contact facts ledger and consent), S5 (discovery research first), S6 (leads), the rest of S7 (settings screen for autonomy, budgets), S8 (Ask), S9, S10 (no sending), S11, S12, S13; S14 is the owner's. Dev database only; every live step is queued for the owner.
## Slice checklist

| Slice | Title | Status |
|---|---|---|
| S0 | Working system, baseline, safety | **done 2026-11-02** (9 of 9 tasks) |
| S1 | Product Master | **data layer, API and write-through built; on 3 Nov the owner asked for NO separate screen: menu 505 removed on dev (rolled back, verified by Y and by the caller), the abilities folded into the existing Products screen (Catalogue / Product lines switch), built, verified and committed (hp_erp `8a779ea1`; g2gv0 `32a5bb1`, `5f37ca9`); the real-browser check is pending**; not on live |
| S2 | Signals front door | **built, verified on dev, database rolled out on live (3 Nov, owner's go)**; waits for the code deployment (owner merges and deploys) and then the grant; the owner's review of the screen is pending |
| S3 | Organisation 360 | **identity layer, API and merge fix built; on 3 Nov the owner asked for NO separate screen: menu 507 removed on dev (rolled back, verified), folded into the existing Organizations screens (scope switch, merged Account 360 tab with the agent panel), built, verified and committed (hp_erp `fd3b095b`; g2gv0 `4210fdb`); not yet checked together with the other tracks**; not on live, not pushed |
| S4 | Contact facts ledger and consent | **built and verified on dev, committed (hp_erp `78c43449`, `14bc36ab`; g2gv0 `34aca54`); NOT on live; a real-browser pass of the new screens is running (4 Nov 02:20)**. Backend: facts ledger with evidence, scoring and bands, accept/dismiss exactly once, the consent gate, write-through of opt-outs, `consent:reconcile`, and the organisation merge keeps facts, a dismissal and the opt-out. Front end: a Suggestions tab on Contacts, a consent line and Evidence section on the contact page, an Evidence section inside Account 360 (no new menu entry). Not done: S4's live rollout (3 tables, 3 migrations) and `consent:reconcile --apply` wait for the owner's go |
| S5 | Contact and organisation discovery | not started |
| S6 | Leads | not started |
| S7 | Agent gateway and autonomy settings | **thin slice pulled forward (3 Nov, owner wants a visible agent): the Organisation agent. Backend, API, run command, panel and Agent Run History on real data built, verified and committed (hp_erp `153d959e`, `d308a387`; g2gv0 `b45b4c6`, `c7eeb29`), 5 tables on dev only, one real run INR 0.075; the real-browser check and the mount on the Organization page wait for track X's work**; the rest of S7 (tool loop, budgets, settings screen) keeps its place |
| S8 | Ask (chat) | not started |
| S9 | Campaigns to leads | not started |
| S10 | Playbooks and outreach | not started |
| S11 | Pipeline and forecast capture | not started |
| S12 | Agent builder, triggers, run history | not started |
| S13 | Paperwork re-skin | not started |
| S14 | Retire old screens | not started (owner decides, screen by screen) |

## Open owner decisions

None blocks building. For later:

1. Is the 10,000-credit limit 10,000 for each of Tavily and Context.dev, or one shared pool? (needed in S5)
2. Production server: email and SMS flags (`G2G_NOTIFY_EMAIL`, `CRM_EMAIL_SENDING_ENABLED`, `GTM_OUTREACH_SENDING_ENABLED`, `GTM_OUTREACH_SCHEDULER_ENABLED`), queue driver, scheduler cron (needed before scheduled research in S2). The owner has confirmed the email master and sender are set up for tenant 6 and has not confirmed SMS or WhatsApp.
3. Does the deployed backend match branch `Milan-2`? The column-check fix (D20) is a local commit; do not deploy this branch to production without it.
4. The owner's "go" for each live migration, when S1 reaches it. Dev needs no go.
5. ~~Keeping the Product Master in step with the old screens~~ **Resolved 3 Nov:** option (b) was built. Every writer of `crm_products`, `g2g_product_offers` and `g2g_signal_products` calls a guarded sync after its own write, and `product-master:repair` (dry run by default) covers the two offline import commands and anything written by hand (`DECISIONS.md`). No screen button.
6. **The owner's review of the finished screen** (item 1 of "Next action"). The design gate approved the mock-up, not the built screen.

7. ~~S2 gate questions~~ **answered 3 Nov** (`DECISIONS.md`). Two of the answers were the assistant's own calls at the owner's request, so the owner may change them on review: the nine dismiss reasons (config `signals.review.reasons`; `no_budget` left out, `out_of_region` and `competitor_in_place` added) and "the scheduler is not switched on by S2".
8. **Model prices for run cost (new, 3 Nov):** a run's dollar cost can only be shown once each AI model has a price. None is configured, so cost is NULL (shown as a dash) and only tokens are recorded. Decide who supplies the per-million-token prices of the model in use (for example deepseek-flash) and where they live. Not needed to roll S2 out.
9. **The owner's review of the finished Signal Review screen** (same pattern as S1): `http://localhost:3000/module/crm/signal-review`, Sales & Marketing › Market Signals › Signal Review (menu row 506 on dev, Admin profile 16 of organisation 6).

10. **S2 live follow-ups, updated 3 Nov (evening).** (a) The Signal Review menu row sits under Market Signals on live (menu 488, no rights). The live Signals navigation hides every child of Market Signals except the four tabs, so before the grant the row should be moved to the Sales & Marketing root with `2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root` (ran on dev; needs the owner's go for live; changes nobody's access because the row has no rights yet). (b) Deploy backend (`Milan-2`) and front end (`milan1` after merging `origin/main`), then `signals:grant-review --tenant=6 --database=live --apply`. (c) Not on live: the pricing migrations and default rate, slice 1 (so the Product facet shows "All products" only), slice 3, the agent tables. (d) `git fetch` and merge `origin/main` into `milan1` before any push, and test that the Signal Review row is visible in the sidebar with the live navigation code.
11. **The Organisation agent (new, 3 Nov, none blocks building).** (a) Who may view **Agent Run History** (menu 503): on dev tenant 6 the Admin (16), HR (18), 69, 71 and 72 profiles already have view rights on it, so once real runs are listed those roles can read every agent brief of the organisation (a brief cites CRM and signal data). Decide whether to narrow it. (b) Keep or drop the `[Agent] ` prefix on a note's text (the `created_via = 'agent'` column marks it either way; the prefix makes it visible on screens that do not read the column). (c) The defaults `alone` for notes and tasks and `approval` for links are the owner's standing default; the autonomy table has no settings screen yet (an administrator's SQL until the full S7). (d) The first real run left task 470 on the owner's own tenant-6 calendar on dev (a follow-up for CRM organisation 135, due 17 Nov); delete it if it is in the way.

Raised later at each slice's design gate: identity table vs bridge columns for organisations (S3); allowed discovery sources and GeM terms (S5); the send and delete ceiling (S7); the canonical campaign table (S9).

## Known traps (condensed; the memory index has the full list)

- Live MariaDB is 10.1.48: no JSON column type, no SKIP LOCKED, not strict, Compact row format. `json()` migrations fail there. Compare `SHOW COLUMNS` on both hosts. A migration is not a schema. Diff the `migrations` table. Never run `migrate --database=live` unscoped. The two hosts are not copies of each other (live is ahead for Signals, dev for `crm_agent_runs`).
- **`App\Support\ColumnExists::has()` throws on live until commit `02d4d896` is deployed (D20).** In migrations use a literal `SHOW COLUMNS … LIKE 'col'`, an `information_schema` select, or `Schema::hasColumn` (see `DB-RULES.md`). Do not deploy this branch to production without D20's fix.
- Raw DDL must state `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; dev's database default is latin1.
- New migration files must sort after the newest existing one (now `2026_12_08_090300_create_signal_review_menu_row`; S3 starts at `2026_12_09_`). List the folder before naming.
- **Fetch before you claim a file is missing** (3 Nov): the "two live-only migrations that are in no branch" were on `origin/main` all along; the design gate had not fetched since 2 Nov. `git fetch` first, then look.
- **Signals code is now level with `origin/main`** (merged 3 Nov, `4fe2886b`) and dev's Signals schema equals live's apart from the S2 columns. Still compare `SHOW COLUMNS` / `SHOW INDEX` on both hosts before any live step, not the migration files.
- **Cost in dollars is NULL everywhere today:** `ai_usage_events.estimated_cost_usd` is NULL on every row on dev and live (0 of 116 and 0 of 114 Signals rows), because no model price is configured. Tokens are recorded. A run's `ai_cost_usd` is therefore NULL (unknown) until prices exist; the Runs tab shows a dash, never $0.
- `AiModelClient` forwards top-level `related_type` / `related_id`; the nested `meter` option is read only by `DeepSeekService`. Anything attributing an AI call to a record passes the flat keys.
- `Carbon::parse` accepts relative words ("next week") and returns a date from the clock; never use it to validate a stored date. `DateStatus` checks `YYYY-MM-DD` with `checkdate`.
- Menu rows are matched by `access_link`, never by numeric id. Grant leaf rows only; count the audience first.
- `sub_institute_id` can be text, NULL or CSV on old tables; never scope a grant or revoke on it. `role_key` is NULL on the app host. `tbluser.status` is 1/0.
- **g2gv0 whole suite on 3 Nov (`npm test`, 997 tests): 3 failures, all in `lib/signals-screen.test.ts` ("Signals sidebar": 4 !== 3 tabs), none caused by S2.** They fail identically with the pre-S2 `hooks/content-map-crm.ts` (the only existing front-end file S2 changed: 4 added lines). The slice's own folder `lib/signal-review/` is 190 tests, all green.
- **Artisan keeps one command per name**, so two classes with the same `$signature` silently shadow each other. S2's first product backfill took `signals:backfill-product` from the older command that fills a run's product (docs/product-driven-signals.md); it was found only by the full-suite run. Mine is now `signals:backfill-product-master`, and `SignalsBackfillTest::test_no_two_commands_share_a_name` guards it. Check `php artisan list --raw` before naming a command, and **run the full suite before calling a track done** (the targeted batches I ran earlier were all green).
- **The tool's safety check refused two things on 3 Nov and they are NOT to be worked around:** pushing `Milan-2` and `milan1` to the remote ("out-of-place publication"; the owner's "roll out" was about the database and deployment), and minting a dev token for another user (Employee 29) for a 403 check ("identity not given"). Both need the owner's explicit word in the conversation.
- **Timing-sensitive test:** `GeminiCredentialFailoverTest::test_per_day_quota_cools_down_much_longer_than_per_minute` failed once in a full run that overlapped my migrations and scripts; it passes alone, in suite order after the new files, and in a quiet full run. Rerun on a quiet machine before blaming code. Run the full suite with nothing else heavy going.
- **Pricing (S2 add-on):** a rate in `ai_models` is per 1,000 tokens in the row's `currency` (default INR); calls are priced when made into `ai_usage_events.estimated_cost` + `cost_currency`; `estimated_cost_usd` is written only for dollar prices; `AiUsageMeter` keeps a per-process column cache so an un-migrated host behaves as before. `UsageController` still reads `estimated_cost_usd` only (a rupee-priced call shows tokens and no money there). The platform AI module usage screen reports a cost currency.
- A bash heredoc collapses backslashes in regular expressions too (`\\b`, `\\s`): write any patch script that contains a regex with the Write tool, not a heredoc. And repo files mix CRLF and LF: a multi-line anchor must match the file's own line endings.
- **Usage limit:** four large agents started at once exhausted the session limit and all four died within minutes with nothing saved (resets at a fixed clock time; resume them with `SendMessage` to the agent id, which keeps what they read). Size parallel work to the quota, tell each agent to write and test files as it goes, and expect at most two or three big tracks at a time.
- **Git Bash quirks on this machine:** `date` is the local (IST) clock but `TZ=Asia/Kolkata date` falls back to UTC; `pgrep` does not exist (a monitor loop using it exits at once and reports a false finish: wait on the real task-completion notice); a new file added with a single lowercase path (`git add docs/agentic-crm/slices/X.md`) can be silently skipped, use the directory form `git add docs/agentic-crm`.
- Run probes one at a time (`artisan serve` is single-threaded).
- g2gv0: call eslint and tsc as `node node_modules/<tool>/bin/...` (`npx` picks the wrong version). Browser tests need two localStorage keys plus a cookie, and `localhost` not `127.0.0.1`. An unmemoized `getLaravelContext()` in an effect dependency loops silently.
- A concurrent session shares the git identity: confirm authorship from the diff, not the author name.
- Git tracks this folder as lowercase `docs/` while Windows shows `Docs/`. `git add Docs/agentic-crm` silently stages nothing once the files are tracked; use `git add docs/agentic-crm`, and check `git status` before every commit.
- A bash heredoc can collapse a double backslash in PHP or JS source; write such files with the Write tool, not a heredoc.
- PHPUnit here is 11.5: `--no-ansi` is not an option (use `--colors=never`). A mutation runner or any wrapper must prove its own baseline is green and count a "kill" only when PHPUnit reports failed tests, otherwise a command error looks like a 100% kill. Read the raw output of the first mutation before trusting a sweep.
- **Full PHP suite on 3 Nov after S2 (1777 tests): 3 failures, none caused by S2** (proved by running them with the pre-S2 versions of every file S2 edited: identical failures). `ScholarCloneIcpProposalTest` (an ICP value over the limit gives no "must not exceed 8" message) and `IcpPackResearchTest` (14 searches where at most 8 are expected) come from the merged `origin/main` ICP/researcher changes: tell the author. `ProductDrivenDiscoveryTest::test_each_product_loads_its_own_stored_offers…` fails only after `CustomProductApiTest` has run, because `ProductCatalog::$custom` is a static cache and `payroll_cloud` leaks from one test into the next (hidden before, while `CustomProductApiTest` errored out early; reproduces with just those two files). `CustomProductApiTest` and `ProductMatchingTest` now pass. To prove a failure is not yours, back up the files you changed, `git show <pre-change commit>:path > path`, run, restore, `cmp` (not `git stash`).
- **Driving the screens in a browser** (details in `_evidence/s1-tasks8-13-frontend-2026-11-02.md` and the memory note `g2gv0-browser-test-auth`): open the front end at `localhost:3000`, but bind the backend to `127.0.0.1:8000` (where g2gv0's `.env` points; a `localhost` bind answers on the other address family only); use Playwright's role queries (the app's `Select` is `role="combobox"`; a required field's label includes a decorative `*`, so match labels with a regex); wait for the menu that opened a drawer to finish fading before the next key or click, because its closing layer takes an early Escape (shared Radix behaviour, not a drawer bug); wait for data, not the first render (the dev server is single-threaded and React's dev mode doubles each fetch).
- Throw-away scripts that rewrite source with `String.replace` break on `$&` and `$'` in the replacement text (they paste the match or the rest of the file in). Use a function replacer, or `split`/`join`.
- The CRM list endpoint reads `itemType` (camelCase) and answers 422 without it. A request I need to make with a second user's token can be refused by the auto-mode classifier; do not route around it.
- Test records made through the UI cannot be removed through the API (no hard delete): record each id as it is created and delete only rows matching that id, the organisation, the creating user and the name, in one transaction, then compare with a baseline taken before the first write. Tools: `D:\dev\agentic-crm-research\scripts\g2g\s1_browser_db.php`, `s1_browser_inspect.php`, `s1_browser_session.php` (mints and deletes a 3-hour token).
- No agent worktrees. C: is nearly full; heavy work goes on D:.
- Nothing may send email, SMS or any message. Sending stays off everywhere.
- **A session limit kills background agents and the next session cannot resume them** (3 Nov: three tracks died together at 7:20pm after about 50 minutes; `ListAgents` showed none afterwards). What survives: their files in the working tree and their transcripts in `~/.claude/projects/<project>/<session id>/subagents/agent-<id>.jsonl` (the `tasks/` output files are gone). Write every track's brief to `docs/agentic-crm/briefs/` BEFORE dispatching so a replacement agent can continue, make agents save files and tests as they go, and expect to lose about 50 minutes of three-agent work per limit window.
- **The live Signals navigation hides the children of Market Signals** (verified by running it, `_evidence/s2-signal-review-live-nav-2026-11-03.md`): `collapseSignalsNav` in `lib/signals-nav.ts` on `origin/main` returns the Signals parent with `children: []`; only the four tabs and Signal Inbox are folded in. Any other menu row under Market Signals is invisible in the live sidebar. Put new Signals-adjacent screens at the Sales & Marketing root.
- **Never run two copies of the full PHP suite at once** (they share storage paths: a different set of unrelated Lms tests fails in each). Only a clean sequential run counts. `phpunit.xml` sets a 1 GB memory limit; the suite peaks near 660 MB.
- The partial-state check of a cut-off track: `git status`, the transcript's last tool calls (a small script reading the jsonl, never cat it), the dev database (`tblmenumaster_g2g` columns are `menu_name`, `access_link`, `parent_id`, `level`, `sort_order`, `status`; `migrations` batches), and the fast test tier over the track's files.

## Log

### 2026-11-04 (00:20 to 00:55) — Track X (Organizations fold-in) reported; verified and committed; parallel tracks started

**Built (by track X, committed by the caller):** on the Organizations list a scope switch (`?scope=crm|discovered|duplicates`), the Discovered panel (create the CRM organisation, the restore question), the Possible duplicates panel; the merged Account 360 tab (why now, linked records with the reason in words, signals, contacts with consent, open deals, the old sections) with the Organisation agent panel mounted once and the merge callbacks wired; `OrgEnsure` and the creation hook; rights through the two Organizations rows; 503 "not set up" on a host without the identity tables; `OrgLinker::merge` takes the agent's history along; the Account 360 card's last activity is the newest entry that has already happened (my finding F4). Shared edits (confirm dialog Cancel, breadcrumb list, an optional confirm tone) proved visually identical on the four Signals pages.

**Verified (how):** the 14-file PHP batch 206 tests, 73,066 assertions OK; front end 578 of 578 (org-accounts, agent with my contrast test, scaffolds, product-master); `tsc` the 7 known errors (+1 in the generated `.next/dev/types`); dev database re-read: no menu row mentions organisation-360 or product-master, 0 rights rows on 505 and 507, admin rights rows 45443 and 45556 intact; `components/ui` diff is exactly the 3 permitted files (diff read: only additive or equivalent changes, the only `<Breadcrumb>` caller passes no className); frozen Signals files untouched; harness removed.

**Found:** (a) X's real-browser axe run found the Agent mark's contrast failure in MY earlier track (fixed and committed, `838751e`, with a test that computes the contrast from the real theme). (b) A network drop contaminated one of X's runs (the rights restore still ended equal to the dump). (c) Findings in shared code nobody touched: `components/ui/button.tsx:11` hover contrast 4.32:1, the 390 px shell menu (`gtg-sidebar.tsx:490`, `:625`, `gtg-user-menu.tsx:196`), `signal-review-parts.tsx:40` date pills 2.03:1 (our own S2 screen: to fix), `form-field.tsx:52` silent 3.97:1 field error, `detail-page.tsx:44` prints a raw backend message (it showed a SQL error with the database host on dev). (d) Account 360 took about 90 s to show on dev (41 statements, 16 of them column-existence checks): batching them is the cheap win.

**Also this block:** the second real agent run (identity 2, INR 0.0776): all facts true, two wording defects fixed (`79da059f`); trial merge of `origin/main` in scratch worktrees: one text conflict per repo, one design change (ICP per sub-product), the team's own main fails 22 of its own tests (track M classifies, brief `briefs/M-merge-origin-main.md`); track S4a started (contact facts and consent, `briefs/S4a-facts-backend.md`); artifact version 13 published.

**Not verified:** the agent panel and Agent Run History in a real browser together with the finished Organization page; dark theme in a real browser; non-administrator profiles in a browser; production-like speed.

**Next:** the overnight queue above.
### 2026-11-03 (22:50 to 23:20) — Track Y (Products fold-in) reported; verified and committed

**Built (by track Y, committed by the caller):** the Products screen now has two scopes: it opens on the Catalogue exactly as before, and a "Catalogue | Product lines" switch under the title shows the product lines with their sub-products (ID, readiness, Sold, Signals, ICP; collapse, search, Active / Archived / All); the scope is kept in the address (`?scope=lines`). A product line or an unsold sub-product opens `/module/crm/sales/products/lines/{id}` (tabs Overview, Signals & ICP, Sell in CRM, Usage; no menu row); a sold sub-product links to its catalogue page; the Catalogue gains Product line / Signals / ICP columns only when an item is tied to a sub-product. Backend: `ProductMasterSync` write-through (guarded) in every writer of `crm_products`, `g2g_product_offers` and `g2g_signal_products`, `product-master:repair`, `ProductLinks`, `lines` in the Products list answer, the product-master API under the Products row's rights. The Product Master menu row (505), its grant command, migration, pages and views are removed. Commits: hp_erp `8a779ea1`; g2gv0 `32a5bb1`, `5f37ca9`.

**Verified (how), by the caller, not taken from the report:** the 13 PHP files of the report: 248 tests, 2,044 assertions OK (2 min 27 s; the four product-master files alone: 109 tests, no deprecation); `node --import tsx --test "lib/product-master/*.test.ts" "lib/product-master/*.test.tsx"`: 180 pass, 35 suites; eslint clean on every file of the track; `tsc` 7 errors, none in the track's files; dev database re-read: no menu row 505 (by id or access_link), 0 rights rows, no `migrations` row, rows 468, 469, 490 to 493, 501 and 506 present, `ProductLinks::summary(6, 'product')` = available true, linked 0, productLines 5, subProducts 25 (the five lines are shared rows of organisation 0); a search of every writer of the three product tables: only the two offline import commands are unhooked, as the report says; no leftover reference to the removed routes in either repo; no key-like string in the track's files.

**Found:** (a) the report said its content-map change was "only my 3 lines", but the same hunks also held track X's removal of the Organisation 360 route and left an orphaned comment: committed on its own first (`32a5bb1`, comment removed). (b) The product-master API moved from an administrator-only leaf to the Products row's rights, so anyone with Products view can now read product lines, sub-products, readiness, ICP state, Signals counts and an item's price books (never ICP content or signal rows): recorded as a plan decision with its reason. (c) Two PHPUnit deprecations appear in the 13-file batch and are not from the product-master files. (d) C: reached 100% at about 21:10 (both Y and Z had one write fail with a full disk and redid it); it is at 91% now, and every committed file was tested after.

**Not verified:** a real browser or a real HTTP call with a token; `next build`; MariaDB 10.1; the mutation sample of Y's breakages (to be re-run after X's browser run, because a mutation briefly alters shared source files).

**Next:** X's report; then the sample mutations for Y, the full suites, the combined browser check (Products switch and tabs, Organizations scope switch and Account 360 with the agent panel, Agent Run History, the four Signals tabs unchanged, Signal Review in the sidebar), the merge of `origin/main` (see "Merging origin/main"), artifact version 13.
### 2026-11-03 (22:00 to 22:30) — Track Z (the Organisation agent) reported; verified, committed, two review findings fixed

**Built (by track Z, committed by the caller):** the Organisation agent, thin S7: `app/Domain/AgentGateway/*`, `RecordNote` / `RecordTask`, the API (7 routes), `agent:organisation-run` (refuses `live`), 5 migrations on dev (batches 560 to 564), the panel `OrgAgentPanel` and Agent Run History on real data (menu 503; demo rows, fixed 100% and the fake "Run Deal Coach" button removed). One real run on dev: Tapovan Vidya Vihar, 11.7 s, INR 0.075, one task applied alone (task 470). Details: `slices/S07-agent-gateway-thin.md`. Commits: hp_erp `153d959e` (the track's work, unchanged), `d308a387` (the caller's review fixes); g2gv0 `b45b4c6`, `c7eeb29`.

**Verified (how), by the caller, not taken from the report** (`_evidence/s7-agent-gateway/caller-verification-2026-11-03.md`): the report's 17 backend files re-run: 206 tests, 73,378 assertions OK; 123 front-end tests pass; eslint clean; `tsc` 7 errors, none in the agent's files; 3 of the track's 62 backend breakages re-run and killed by the same named tests; the report's database claims re-read on dev (migrations, table shapes, run 1, proposal 1, usage row 827, task 470, events 11057/11058, menu 503 rights); the "no send / delete / merge" claim re-checked by searching the domain folder; no key-like string in the track's files.

**Found and fixed by the caller (two commits above):** the real run's brief checked sentence by sentence against the source rows was wrong in three ways. F1 "last active 2026-10-22" / "no activity for ~2 weeks" about a deal made 4 days earlier (the 360's timeline holds only notes, tasks at their scheduled time, signal decisions and leads). F2 "no contact details" about a contact that has an email and a phone, and "no next step" without being shown one (the model inferred absence from not being shown). F3 evidence links to a removed page and to a page that never existed. Fixed with 4 new tests; 11 of 11 deliberate breakages of the fixes caught; the 17-file batch is now 210 tests, 73,427 assertions, green. F4 (the Account 360 card's `why_now.last_activity_at` has the same timeline flaw) is track X's file: sent to X.

**Not verified:** anything in a real browser (panel on the Organization page, Agent Run History, 403 view, dark mode, contrast); MariaDB 10.1 (live); the real model producing a note or a link proposal; whether the corrected context makes the next brief accurate (only a real run shows it; do it in the combined browser check, INR 0.075).

**Left for the owner (new open decision 11):** who may view Agent Run History (menu 503: profiles 16, 18, 69, 71, 72 of tenant 6 already have view and will see every agent brief), whether a note's text keeps the `[Agent] ` prefix, and the `alone` defaults for notes and tasks.

**Next:** X and Y are still running; when they report: re-run their tests, spot-check two claims each, tell X to add the one line in `OrgLinker::merge` (`AgentSubjects::repoint`) if its report lacks it, then the full suite once, the combined browser check, commit, republish the artifact (version 13).

### 2026-11-03 (20:00 to 20:45) — owner feedback after a session limit: no duplicate screens, Signals design frozen, tracks resumed

**Built (by the caller):** the re-read and state check (progress log, decisions, rules, plan, the published artifact's outline, git and database state, the cut-off agents' transcripts); `briefs/COMMON-RULES.md` and three track briefs; the decisions and plan changes (`DECISIONS.md`, `PLAN.md` section 0, `SESSION-RULES.md`); `2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root` with `SignalReviewMenuMoveTest` (8 tests, 6 of 6 mutations caught, applied on dev as batch 565, DB-CHANGELOG row 25); three build tracks dispatched in parallel; the published artifact republished as **version 12** (build-plan page: the 3 Nov instructions, the fold-in, the frozen Signals design, S1/S3/S7 notes, in-progress items as to-do ticks; checked at 1280 and 390 px with no errors or sideways scroll; sources and patch script in `D:\dev\agentic-crm-research\published-pages\` and `publish\patch-build-plan-3nov-evening.cjs`).

**Verified (how):** dev database: menu rows 505 and 507 gone with 0 rights rows, rows 490 to 493, 501 and 506 present (SELECTs, 20:15); the five agent migrations applied (batches 560 to 564) and the agent tables empty; `g2g_product_master` 55 rows, `g2g_org_accounts` 58 rows (46 real plus 12 "S3 CHECK" seed rows still to clean up). Fast tier at 20:20: 359 tests, the product-master and organisation tests green except the in-progress ones (CrmAccount360Test 2, AgentGatewayApiTest 4, OrganisationReferencesScanTest 1). Signals design: `git log origin/main..origin/harshit` is empty (Harshit's branch is inside main) and `git log origin/main..milan1 -- <signals design paths>` is empty (none of our commits touched the design); `milan1` differs from `origin/harshit` on 14 Signals files only because it is behind. **Verified by running it:** `collapseSignalsNav` from `origin/main` (b32f436) over a dev-shaped menu tree drops a Signal Review row that sits under Market Signals and shows it at the root (`_evidence/s2-signal-review-live-nav-2026-11-03.md`; hence the menu move).

**Not verified:** the results of the three tracks (running); the sidebar behaviour of the live Signals navigation in a browser; anything on live; a 403 in a real browser.

**Next:** see "Next action". **Status: S1, S3 and the agent are being rebuilt into the existing screens; overall about 3.5 of 15 slices done, with S1, S3 and S7-thin in flight.**

### 2026-11-04 (small hours) — Slice 3 (Organisation 360): decisions, schema, validators; four build tracks started

**Raw material first:** scored five kinds of matcher against the links the system already holds (live: exact name 6 of 6, 0 false; domain alone 4 of 6; every fuzzy threshold from 0.5 to 0.9 adds nothing; one real duplicate inside the Signals companies; dev: one same-name pair the labels count as a false positive but is almost certainly an unlinked true match, so the system's links give only a lower bound). **Decided** (nine records, six lenses, a door each; `slices/S03-organisation-360.md`). **Written and tested:** the identity table and the rejected-suggestions table and the email-widening migration (7 tests, a mutation caught). **Validators prepared that are neither the assistant nor the owner:** a 35-pair labelling sheet for the sales owner (outside the repo), a production-invariants script (13 invariants straight from the tables) and a before-checksum of the three source tables on dev and live, axe-core installed in a scratch folder. **Tracks** (resolver/backfill, API, merge fix, screens) were stopped together by the session limit and resumed. Nothing is on any database; nothing is pushed.

### 2026-11-03 (night) — mock-up gaps closed, AI prices in rupees, S3 facts gathered (dev only)

**Owner's answers this evening** (full text in `DECISIONS.md`): review everything at the end; the scheduler question (answered: it is the server clock, not an agent; GTM agents are button-only; signal research becomes the first scheduled agent in S7/S12; on live nothing runs on a clock at all: `OPS-SCHEDULER.md`); prices in rupees, supplied by the organisation and the developer company, option left to me; "also fix the gaps"; the goal restated as a full working enterprise agentic CRM.

**Built (four parallel tracks, reviewed by me):** Turn into a lead, Fit and score tab, Generate signals, Export; a currency-aware rate card with calls priced when made and run cost in rupees (backend, Runs tab, model manager inputs, platform usage screen currency fix). **Verified:** front end 441 tests in the affected folders, tsc only the 14 known errors, eslint clean; full PHP suite 1850 tests with only the 3 known non-S2 failures (a fourth, a timing test, failed once under load and not in a quiet run); the real-browser checks (gaps walkthrough 36/38 + Generate 8/8, the two failures being my script's); the rupee chain on the real MariaDB 9/9 (47,350 in + 6,150 out = ₹2.07216); three pricing migrations and the rate applied on dev one at a time. **Found:** my pricing design would have shown rupee amounts as dollars on the platform's AI usage screen (fixed); heredoc and CRLF traps again; two safety-check refusals (push, other-user token).

**Not done / waiting on the owner:** the push of both branches; the grant on live (after the screen is deployed); the pricing migrations and the default rate on live; slice 1 on live; a 403 check with a real rights-less user; the S3 design gate (facts gathered in `_evidence/s3-design-gate-checks-2026-11-03.md`, slice file and mock-up not yet written).

### 2026-11-03 (evening) — S2 database rolled out on live, on the owner's explicit go

**Authorisation:** the owner answered my question listing the four database changes, the backfills, the grant and the deployment with "yes roll out i give you the permissions". **Done:** read-only pre-flight; dev-vs-live schema diff (only the S2 columns differ); scratch-table rehearsal on live's MariaDB 10.1.48, 16 of 16; backup of `g2g_company_opportunities` (85 rows) outside the repo with a SHA-256 of every column except the two target columns; the four migrations one at a time (batches 405 to 408), each verified against dev; `signals:backfill-date-status` and `signals:backfill-reviews` for organisation 6 (dry run, apply, re-run). **Verified on live by independent SQL:** the checksum of every other column is identical before and after (no other change in any of the 85 rows); date status 16 / 9 / 49 (74 signals: one more than at the design gate), 0 rows disagree with their dates; 5 history rows, every decided signal has one; other organisations untouched; no scratch table left; menu row 488 with 0 rights rows.

**Not done, deliberately:** the code deployment (no pipeline I can run; PRs for the owner), the grant (after the screen is deployed), the product backfill and the Product Master (slice 1 is not on live). **Safety checked before going:** the S2 code tolerates a live database without `g2g_product_master` (guarded name lookup, try/catch stamping), so deploying it ahead of slice 1 cannot break the original Signals screen. **Not verified on live:** the new screen and endpoints, a real decision, the cost capture on a real run, concurrent deciders on 10.1.

**Next:** close the three mock-up gaps (Turn into a lead, Generate signals, Export, Fit and score tab; being built), merge `origin/main` into `milan1`, push both branches and open the PRs; then the pricing in rupees (decision below), the scheduler decision (S7/S12), and the S3 design gate.

### 2026-11-03 (build day) — S2 Signals front door built, run on dev and checked in a real browser

**Built** (all committed locally, nothing pushed): merged `origin/main` (`4fe2886b`); five main-branch migrations run on dev so it equals live; the S2 schema (decision history table, product/date columns and four indexes on signals, cost columns on runs) with `DateStatus`; then six tracks in parallel to `slices/S02-contract.md`: decision service + `POST /decisions` + `GET /reviews` + five events (A), feed filters and `date_info` (B), run cost capture (C), three backfill commands + menu row + Admin-only grant (D), the Signal Review screen (E1) and its dialog, history list and Runs tab (E2); plus `SignalStamps` so new signals are stamped at creation. g2gv0 polish: "No product" wording and run product names.

**Verified (how):** schema on dev with `SHOW CREATE TABLE`/`SHOW INDEX`, dev vs live diff equal apart from S2 columns; combined PHP batch 567 tests OK, 190 front-end tests, `tsc` clean for the slice (14 pre-existing errors elsewhere), eslint clean; every track and I proved tests can fail with mutations (about 40, one unexplained extra failure logged); the date SQL on real MariaDB 10.11 and 10.1.48 (read-only) gives 19/2/27 (dev) and 16/8/49 (live) and the freshness buckets partition the table; backfills applied on dev with second runs writing nothing and an independent SQL check (0 mismatches, every decided signal has history, 0 dangling product pointers); menu row 506 with one rights row (profile 16) and nothing above the leaf; **real-browser walk-through 71/71 + 7/7 phone**; events 7 for 7 decisions; cleaned to baseline. Details: `_evidence/s2-*.md`.

**Read in source:** `AiModelClient::meta()` (flat attribution keys), `MarketImporter` create and update paths, `SignalReviewService` (one transaction, lock, conflict before unchanged), `SignalsGrantReviewCommand` (rights lookup by menu and profile only).

**A regression of mine, found by the full suite after the walk-through:** the first name of the product-link backfill, `signals:backfill-product`, was already taken by an older command (it fills a run's `product_key`); mine shadowed it, so on dev `php artisan signals:backfill-product` ran mine. Renamed to `signals:backfill-product-master` (data on dev unaffected: same code), a guard test added and proven to fail on the clash, the older command's tests green again. Track review missed it because I ran targeted test batches, not the whole suite, before committing. **Corrections to earlier claims:** the two "live-only" migrations were on `origin/main` (fetch first); the contract's `meter` option does not exist for `AiModelClient`; `RunAccounting` was taken (now `RunCost`); the "16 vs 3" date gap is two populations (24 live rows carry an assessment note and all agree with the columns); dollar cost is NULL on every usage row on both hosts.

**Not verified:** a 403 for a user without the edit right in a browser; concurrent deciders and the duplicate-event path on MariaDB; keyboard and screen-reader use; anything on live; the dollar-cost path with real prices.

**Next:** the owner's review of the screen and answers on model prices (decisions 8 and 9); on the owner's "go", task 14 (live rollout); then S3 design gate. **Status: S2 built and verified on dev, 14 of 15 tasks; overall about 17% of the plan.**

### 2026-11-03 — S2 design gate: facts re-checked, slice file and mock-up written, artifact republished (no S2 code)

**Built**
- Artifact versions **8** (S1 status on the "Where we are" panel, 3 done ticks, open items) and **9** (adds `s2-mockup.html` and the S2 text in `build-plan.html`). Sources: `D:\dev\agentic-crm-research\published-pages\`.
- `slices/S02-signals-front-door.md` (goal, scope, precondition, database changes, backend, front end, six owner questions, 12 acceptance checks, 15 tasks, risks).
- `_evidence/s2-design-gate-checks-2026-11-03.md` (every plan claim S2 depends on, re-checked; findings F-S2-1 to F-S2-8).
- Read-only tools outside the repo: `D:\dev\agentic-crm-research\scripts\g2g\s2_measure.php`, `s2_diff.php` (every connection `SET SESSION TRANSACTION READ ONLY`; counts and column names only).
- `FEATURE-LEDGER.md` S2 row.

**Verified (ran it, read the output)**
- Live (organisation 6): 73 signals, **16 event-dated / 8 source-dated only / 49 undated** (78% without an event date), 5 ever reviewed (7%), **0 decision notes ever written**, 74 runs all manual (44 success, 19 failed, 9 skipped, 2 partial), last run 2 Nov 15:33. Dev: 48 signals, 19 / 2 / 27, 3 reviewed, 84 runs.
- Zero `signals.*` events on either host; `g2g_platform_task_runs` has two rows per host and none is a signals task; every Signals `ai_usage_events` row has an empty `related_type`; neither host has cost columns on runs.
- **Live is ahead of this branch on Signals** (extra columns `product_key`, `offer_id`, `customer_model`, `icp_*`, `region_scope`, `scope_key`, `stage_stats`; a UNIQUE `(sub_institute_id, fingerprint)`; five migration rows dev lacks). Two of those migration files (`2026_12_07_090000_add_scope_to_g2g_company_opportunities`, `2026_12_07_100000_add_uniqueness_to_signal_opportunities`) are in no branch of this clone; `2026_10_31_090000_add_research_run_configuration_scope` is on `origin/main` and `origin/harshit` but not on `Milan-2`. `origin/main` is 18 commits ahead, including edits to `OpportunityController`, `ProductCatalog`, `ResearchRunScope`.

**Read in source**
- `OpportunityController::act` (lines 552-585) overwrites the review columns, saves twice, has no state check; its route is gated by `platformright:/signals,edit` (`routes/api.php:3554`). The old screen sends only "Contacted" (`follow-up`) and "Not relevant" (`dismiss`), no reason or note (`scholar-signals-view.tsx` 668-669).
- Scheduler guard `config('signals.scheduled_research', false)` at `routes/console.php:273`.
- `StructuredAi::json` passes no `meter` option (lines 42-48); other modules pass `related_type`/`related_id` in `meter`.
- Events: `EventCatalogue::SHIPPED` needs a named consumer; `AuditLogProjector::handles()` returns true for every type, so register with `'AuditLogProjector' => PROJECTOR` like `crm.lead.created`.
- Signals already has a menu branch (`/module/crm/signals`, leaves `/signals`, `/ingestion`, `/portfolio`, `/partners`); both `/signals` and `/module/crm/signals` render `MarketSignalsView` → `ScholarSignalsView`. `origin/main` deactivates the Signal Inbox menu row; `crm_signal_inbox` is empty on both hosts.

**Corrections to the plan**
- The `date_status` backfill cannot come from `assessment.evidence.date_status` (present on only 24 of 73 live rows, 9 of 48 on dev); it comes from the columns, with the JSON as a cross-check.
- `stage_stats` is unrun only on **dev**; on live it exists.
- D16 ("`json()` tables cannot exist on live") is too strong: `crm_signal_inbox` exists on live with 25 columns although its migration (which uses `json()`) has no row in live's `migrations` table. How it was created is unknown; S2 does not depend on it.
- The Signals menu row is a new leaf under the existing Signals parent, not a new "Today › Signals" row.
- Plan numbers refreshed: "75% undated, 8% reviewed, 72 runs" is now 78% / 7% / 74.

**Not verified:** how the two live-only migrations are written (columns and indexes are verified from the database, not their code); whether the production scheduler or a queue worker runs; where per-run search-credit counts come from; whether `ProductProfileService::isDue()` exists and is unused.

**Next:** the owner answers the six questions on the S2 mock-up page. Then S2 task 1 (merge `origin/main`, find the two migration files).

**Status line:** S2: design gate open, task 0 of 15 done, no code; overall S0 and S1 built, about 7% of the plan (a rough count by sessions, same figure as the artifact).

### 2026-11-02 (end of session, stopped at the owner's request) — S1 tasks 8 to 13: the screens are built and checked in a real browser

**Built**

- **g2gv0** (local commits `fed8775`, `047c928`, `54bc01e`; nothing pushed): the scaffolds in `components/shared/scaffolds/` (list page with its list state in the URL, detail page and ARIA tabs, an accessible field and form sections, a record drawer with one close-guard, a promise-based confirm), and the **Product Master screen**: the list, the record page (Overview, Sell in CRM, Prices, Usage), one drawer form for a product, a sub-product and a catalogue item, archive and restore. Route `/module/crm/product-master` and `/[id]`, content-map entry, service and types. `components/ui` untouched.
- **hp_erp** (`879b4824`, `ad2dd429`): `GET /api/product-master/meta` (the choices a form may offer and what the caller may do), the offer's About text and the catalogue GL account in a record, and separate restore flags (restore follows the edit right, archive the delete right). 9 routes now.

**Verified (how)**

- 114 front-end tests; **175 deliberate mutations, 174 caught** and the 175th is equivalent. The sweeps found 12 weak tests (for example a reversed arrow key that ended on the same tab, a count of 0 drawn as bare text, a dialog never checked to close) and all were strengthened. PHP: 36 write tests; 26 mutations of the new backend code caught after 3 test gaps were closed.
- `tsc` 14 errors before and after (none in new files); ESLint clean; the Product Master and Portfolio PHP suites green.
- **A real-browser walk-through, 73 of 73 checks (run 7 of 7)**, as a tenant-6 administrator on the local Laravel and the dev database: list, search and filter in the URL, the New… menu, the discard guard (and the page still clickable after it), field-level server errors, creating all three kinds, the record page and tabs by keyboard, edit (the Portfolio offer and the CRM row really changed, readiness stays unconfirmed), archive and restore (including the refusal for a product with an active sub-product), the three other screens seeing the new records, another organisation's id is a 404, a 390 px screen. Evidence: `_evidence/s1-tasks8-13-frontend-2026-11-02.md`.
- The browser found and I fixed: the product picker not showing a just-created product, a misleading "0 sub-products in 0 products", and (by reading the code) a stale response overwriting a newer one; plus the Restore right. One shared quirk left as a note: an Escape pressed within half a second of choosing a "New…" item is swallowed by the closing menu.
- **Cleaned up:** the test records were removed by id, organisation, creator and name, and the counts match the baseline taken before the first write; the minted login token was deleted; both dev servers I started were stopped.

**Not verified**

- A non-administrator who has the screen's right, and a no-rights 403 with a real token, in the browser (the second token was refused; the PHP and unit tests cover both).
- Nothing ran on live; nothing is pushed or deployed. The owner has not yet looked at the finished screen. Dark mode not looked at.
- The Product Master is not kept in step with offers or CRM items added later in the old screens (owner decision 5).

**Next:** see "Next action".

### 2026-11-02 (end of day) — S1 task 6: write API built, mutation-checked and committed (code only, no database change)

**Built**

- Commits `66173abc` (write API) and `34aa2dab` (a test pinning the duplicate-key predicate). Create a product, a sub-product or a catalogue-only item; update; archive; restore. 8 routes. Each write: every access check first, then one transaction (offer, catalogue item, master row). The Product Master never bypasses the rights of the screens it writes through (administrator for a product; the Portfolio's right for an offer; the Products or Services right for a catalogue item). Table of routes and rights in `_evidence/s1-task6-write-api-2026-11-02.md`.
- Fixed on the way: the first writer turned any database error into a 409 (the rollback test caught it); only a duplicate-key error is a clash now, and anything else surfaces.

**Verified (how)**

- 29 write tests (190 assertions); 54 with the two earlier Product Master files; the related suites (Portfolio/ICP, ICP editor, column check, parse test, catalogue, key and fit rules) are green.
- **59-mutation sweep** (a rule broken on purpose, tests run, original restored byte-for-byte): first run 37 killed and 17 survived. 15 of the survivors were real rules no test pinned down (updating a product needs an administrator; another organisation's tax rate on three paths; rollback on three of four transactions; item edit right; a shared offer is never written; parent must be an active product; `productId` immutable; the menu-rights branch; and a master-only key clash). 11 new tests closed them. Final: 57 killed, 2 survive on purpose (the controller's and the writer's tenant checks cover each other; both removed together is killed). Tooling kept outside the repo: `D:\dev\agentic-crm-research\scripts\mutation\product-master-write\`.
- The sweep's first run was invalid (PHPUnit 11 rejects `--no-ansi`, so every mutation "failed"). Found by reading the raw output of one run; the runner now proves its own baseline (new trap above).
- `CustomProductApiTest`, `ProductDrivenDiscoveryTest` and `ProductMatchingTest` fail identically with HEAD's copies of the two tracked files I changed, so they are pre-existing.
- Real column sets read from the dev and live schemas (read-only `SHOW COLUMNS`): every NOT NULL column without a default, on all four tables the writer touches, is supplied by the writer. Dev is strict mode; live is not.
- `php artisan route:list`: 8 routes with the intended `view/add/edit/delete` middleware.

**Not verified**

- No real HTTP write on the dev database yet (task 13). Tests use in-memory sqlite with fixture tables. The MariaDB duplicate-key branch is tested with constructed exceptions only.
- Two simultaneous creates of the same offer id are not raced in a test (the unique index plus the 409 mapping handle it).
- Nothing on live; nothing pushed.

**Next:** see "Next action".

### 2026-11-02 (late night) — S1 task 5: menu row, grant command and read API built and run on dev

**Built**

- Commits `a0dc79a3` (menu migration + `product-master:grant`) and `782748d8` (routes, controller, 11 tests). Menu row "Product Master" under the CRM root; rights only via the grant command (leaf only, administrator only, organisation by organisation); read API behind `platformright` with no administrator bypass.
- Run on dev: menu row id 505; organisation 6's Admin profile (16) granted; exactly one rights row added; an Employee profile has no access.

**Verified (how)**

- 33 tests, 219 assertions pass (ProductMasterTest, ProductMasterApiTest, PhpFilesParseTest, ColumnExistsTest). Four deliberate mutations of the tenant and rights logic each failed the right test and were reverted (files byte-identical).
- Rights resolution read from `MenuRight` and `RequirePlatformRight` source; leaf-only grant checked by rights row id (max id 45416, one row at or above it).

**Not verified**

- No real HTTP call with a real token on dev yet (task 13). Nothing on live.
- The cache of menu ids (`MenuRight::idForAccessLink`, 5 minutes) means a freshly created row can take up to 5 minutes to be seen by the API on a running server.

**Next:** see "Next action".

### 2026-11-02 (night) — S1 tasks 2 to 4: table, resolver and backfill built and run on dev

**Built**

- Commits `c8c80c73` (migrations + model) and `2d12f2c4` (resolver, backfill command, tests). New: `g2g_product_master`; `ProductResolver`; `ProductMasterBackfill` + `product-master:backfill`; `tests/Feature/ProductMasterTest.php` (14 tests).
- Design change from the plan: the corrective `g2g_signal_products` migration now only creates the table where it is missing (dev). The live table is empty and works, so converting production schema for a cosmetic difference was dropped. Recorded in `DB-CHANGELOG.md`.
- Run on dev: both migrations (one file at a time, `--pretend` first) and the backfill (`--all --apply`): 5 shared products and 25 sub-products for each of organisations 1 and 6.

**Verified (how)**

- `SHOW CREATE TABLE` on dev matches the design (utf8mb4, no ON UPDATE, 4 unique keys). Second backfill run created nothing; 0 orphans (queries).
- 14 tests pass; three deliberate mutations each failed the right tests and were reverted (files byte-identical).
- Dry run against live (read only): organisation 6 would get 5 products and 22 sub-products; one noted oddity (sub-product EB has the same id as its product).

**Not verified**

- The DDL has never run on MariaDB 10.1; it is plain, and the scratch-table probe on db3 comes first, after the owner's go.
- Nothing has run on live. Live Portfolio counts drift (the owner edits it).

**Next:** see "Next action".

### 2026-11-02 (evening) — S0 closed; S1 design approved with the owner's changes; building starts

**Built**

- The owner answered the design-gate and S0 questions (`DECISIONS.md`, evening round). Decisions: Product Master under the CRM module (admin only); "product" and "sub-product"; prices come from price books; no SMS switch now (D6); credits are for both Tavily and Context.dev; no Docker, use db3; email master is set up for tenant 6.
- Read-only checks on live to answer "check by yourself": `_evidence/s1-design-gate-checks-2026-11-02.md` (the 32 signals with no product, the real 22-offer Portfolio, price-book rules, email and SMS per tenant).
- Mock-up revised with the real product list, price books and the 32-signals analysis; artifact version 7. Build plan page updated (decisions table, S0 done, S1 wording).
- S0 closed (9 of 9).

**Verified (how)**

- `QualificationGate::BUSINESS_FIT` and the controller validation accept only eb, scholar, g2g and multiple (grep): the Bundle and HE products cannot be stored today.
- Price-book rule read from the `CrmPriceBookController` source comment; live counts of price books, items, products and quotes are all 0 (queries).
- Only tenant 6 has outbound email enabled; SMS gateway belongs to tenant 3 (queries).
- Local checks of the revised pages at 1280, 390 and 360 px: no overflow, no script errors, light background, links resolve.

**Not verified**

- Production env flags, queue driver, scheduler cron, deployed code version.
- The published URLs were not re-opened after publishing.
- Live counts drift: the owner added an offer on 2 Nov at 12:45 (22 now, 21 at baseline).

**Next:** see "Next action".

### 2026-11-02 (later) — S0: baseline taken, three code defects fixed

**Built**

- Read-only baseline on dev and live: `_evidence/s0-baseline-2026-11-02/` (`FINDINGS.md`, `summary.txt`, `mysql.json`, `live.json`). `DB-RULES.md` rewritten from verified results.
- Local commits (not pushed): `0be5aa66` D1 (deals controller parse error; stage history records the real stage), `4c41e9a4` D21 (`SkillMatrixController` stray unscoped line from the 6 Oct merge; same error on `origin/main`), `02d4d896` D20 (`ColumnExists::has` throws on live), `723523a7` (a test that parses every PHP file).

**Verified (how)**

- Column-check forms and the real `ColumnExists::has` run against both hosts: it throws error 1064 on live, returns true on dev.
- `g2g_signal_products` has run on live (INT UNSIGNED, utf8, 0 rows) and is missing on dev: from `SHOW COLUMNS` and `information_schema` on both.
- `crm_products` has 0 rows on both hosts; live has 21 offers (all tenant 6), no shared offers, no duplicates.
- Parse scan of 2,007 files: only D1 and D21. `php -l` clean after the fixes. 22 tests pass; the parse test fails on a planted broken file. Three failures in a wider run also fail on the unchanged code (stash check), so they are pre-existing.

- Artifact republished twice: version 5 (baseline results, D12 correction, D20 and D21, a progress panel, the "consistent with your answers" wording fix) and version 6 (the slice 1 mock-up `s1-mockup.html`, linked from the build plan). Locally checked at 1280, 390 and 360 px: no overflow, no script errors, light background under a dark-mode browser, links resolve. The mock-up's counts come from the baseline (37 scholar, 3 eb, 32 without product, all organisations on live) and its names from `config/portfolio_seed_data.json` (25 offers; live has 21). One field I had invented ("Visible to Signals") was removed because the data model has no such thing.

**Not verified**

- Production environment flags, queue driver and scheduler cron; whether deployed code matches this branch.
- The published URLs themselves were not re-opened after publishing; the checks ran on the local files.
- The mock-up is an illustration; its list is not the live list.

**Next:** see "Next action" above. The S1 design gate is open; nothing else proceeds until the owner responds, except D6, which also waits for the owner.

### 2026-11-02 — S0 started: plan approved, artifact published, log system created

**Built**

- Approved the slice-by-slice build plan (`PLAN.md`) after two rounds: v1 (map and order) and v2 (database design per slice, defect register).
- Published the build plan page and corrected the two older pages; artifact version 4 at the link above. Fixes: dropped the old option letters ("option B" was a separate agent service, ruled out by the owner), replaced "separate TypeScript agent service" with "agent layer inside the G2G backend (PHP)", added the owner's answers.
- Created this folder: `00-PROGRESS.md`, `DECISIONS.md`, `DB-CHANGELOG.md`, `DB-RULES.md`, `FEATURE-LEDGER.md`, `SESSION-RULES.md`, `PLAN.md`, `slices/S00`, `slices/S01`, `_evidence/`.

**Verified**

- The three published pages load in a browser at 1280, 390 and 360 px with no horizontal overflow, no script errors, a light background even with a dark-mode browser, and working cross-links. (Checked with a local Playwright run on the HTML files, not on the published URL.)
- D1, D2 and D3 confirmed by reading the source lines (see `_evidence/defect-register-2026-11-02.md`).

**Not verified**

- Every item tagged `agent-read` in the defect register and schema notes. No database has been contacted. Migration files describe the intended schema only.
- The published URL itself was not re-opened after publishing.

**Next:** S0 tasks 3 to 7 (see `slices/S00-working-system.md`). Nothing has been committed yet.

## Session end checklist (copy into each log entry)

- [ ] Checklist row updated
- [ ] Next action rewritten (one line)
- [ ] Built / Verified (how) / Not verified / Next written
- [ ] New traps added above
- [ ] DB-CHANGELOG.md updated if any migration or data update ran
- [ ] Last chat message gives a one-line status with a rough percent
