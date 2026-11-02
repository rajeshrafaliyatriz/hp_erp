# Agentic CRM — Progress

Living log for building the agentic CRM one slice at a time.

**Read this file first in any new session, then the current slice file in `slices/`.** Update it after every task and at the end of every session, so a cold session can resume with zero re-derivation. After any context compaction, re-read this file and the slice file before doing anything else. Do not rely on a chat summary.

- Plan (approved 2026-11-02): `PLAN.md` in this folder (copy of the planning-session plan).
- Published pages (private artifact): https://claude.ai/artifact/1ZoowKicrqvTLrunZwE2aK (plan page, architecture page, build plan page).
- Source HTML of those pages, kept outside this repo: `D:\dev\agentic-crm-research\published-pages\`.
- Research behind the architecture: `D:\dev\agentic-crm-research\` (notes, report, sensitivity script, read-only db3 scripts).
- Rules: `SESSION-RULES.md` (the eight steps), `DB-RULES.md` (database), `DECISIONS.md` (owner decisions), `DB-CHANGELOG.md` (every migration and data update).

## Next action

**S1 (Product Master) is built and browser-checked on dev: all 13 tasks done.** Nothing is pushed or deployed; live is untouched. The code is in local commits (hp_erp branch `Milan-2`, g2gv0 branch `milan1`). Next, in this order:

1. **The owner reviews the finished screen.** To open it locally: `cd hp_erp && php artisan serve --host=127.0.0.1 --port=8000` (bind `127.0.0.1`, not `localhost`), and `cd g2gv0 && node node_modules/next/dist/bin/next dev -p 3000`, then sign in at `http://localhost:3000` as a tenant-6 administrator and open Sales & Marketing › Product Master (menu row 505, granted to the Admin profile on dev). Take the owner's notes: keep, change, or sign off. The old Portfolio and CRM Products screens stay as they are.
2. **Decide how the Product Master stays in step with the old screens** (see "Open owner decisions", item 5). It is the one real gap left in S1.
3. **Republish the plan page** (the artifact the owner asked to keep current) with S1's status: its source is `D:\dev\agentic-crm-research\published-pages\` (`build-plan.html`, the "Where we are" panel); keep it in light mode, same link.
4. **Live rollout of S1:** only after the owner's explicit "go" and a scratch-table probe on db3 (`DB-RULES.md`): table, menu row, grant (tenant 6 first), backfill; then deploy backend and front end together (the routes exist only in the working tree). Not requested.
5. **S2, the Signals front door:** starts with its design gate (a one-page brief and a light-mode mock-up) once S1 is signed off. Its first jobs include cleaning up the three older failing tests noted in the traps.

## Slice checklist

| Slice | Title | Status |
|---|---|---|
| S0 | Working system, baseline, safety | **done 2026-11-02** (9 of 9 tasks) |
| S1 | Product Master | **built and browser-checked on dev, 13 of 13 tasks** (2026-11-02); waiting for the owner's review and for the go to roll out to live |
| S2 | Signals front door | not started |
| S3 | Organisation 360 | not started |
| S4 | Contact facts ledger and consent | not started |
| S5 | Contact and organisation discovery | not started |
| S6 | Leads | not started |
| S7 | Agent gateway and autonomy settings | not started |
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
5. **Keeping the Product Master in step with the old screens (found by checking the data on 2 Nov).** The master is filled once by `product-master:backfill`. An offer added later in the old Portfolio screen, or an item added in CRM Products, is not in the Product Master until the backfill is run again (the owner edits the live Portfolio, so this will happen). The backfill is idempotent, so it is safe to repeat. Options: (a) run it for an organisation from a button on the Product Master list, administrators only ("Sync with Portfolio and CRM catalogue"), with a notice that says how many are missing; (b) have the Portfolio and CRM product create paths also write the master row (always current, but it touches two other screens' code); (c) run it nightly (needs the scheduler, which production has never run). **Recommendation: (b), with (a) kept as the repair button.** Not built; S2 onward needs the master to be complete, so decide before live.
6. **The owner's review of the finished screen** (item 1 of "Next action"). The design gate approved the mock-up, not the built screen.

Raised later at each slice's design gate: dismiss-reason list and the CRM Signal Inbox's fate (S2); identity table vs bridge columns for organisations (S3); allowed discovery sources and GeM terms (S5); the send and delete ceiling (S7); the canonical campaign table (S9).

## Known traps (condensed; the memory index has the full list)

- Live MariaDB is 10.1.48: no JSON column type, no SKIP LOCKED, not strict, Compact row format. `json()` migrations fail there. Compare `SHOW COLUMNS` on both hosts. A migration is not a schema. Diff the `migrations` table. Never run `migrate --database=live` unscoped. The two hosts are not copies of each other (live is ahead for Signals, dev for `crm_agent_runs`).
- **`App\Support\ColumnExists::has()` throws on live until commit `02d4d896` is deployed (D20).** In migrations use a literal `SHOW COLUMNS … LIKE 'col'`, an `information_schema` select, or `Schema::hasColumn` (see `DB-RULES.md`). Do not deploy this branch to production without D20's fix.
- Raw DDL must state `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; dev's database default is latin1.
- New migration files must sort after the newest existing one (newest seen: `2026_12_06_100000_…`, later than the calendar date). List the folder before naming.
- Menu rows are matched by `access_link`, never by numeric id. Grant leaf rows only; count the audience first.
- `sub_institute_id` can be text, NULL or CSV on old tables; never scope a grant or revoke on it. `role_key` is NULL on the app host. `tbluser.status` is 1/0.
- Run probes one at a time (`artisan serve` is single-threaded).
- g2gv0: call eslint and tsc as `node node_modules/<tool>/bin/...` (`npx` picks the wrong version). Browser tests need two localStorage keys plus a cookie, and `localhost` not `127.0.0.1`. An unmemoized `getLaravelContext()` in an effect dependency loops silently.
- A concurrent session shares the git identity: confirm authorship from the diff, not the author name.
- Git tracks this folder as lowercase `docs/` while Windows shows `Docs/`. `git add Docs/agentic-crm` silently stages nothing once the files are tracked; use `git add docs/agentic-crm`, and check `git status` before every commit.
- A bash heredoc can collapse a double backslash in PHP or JS source; write such files with the Write tool, not a heredoc.
- PHPUnit here is 11.5: `--no-ansi` is not an option (use `--colors=never`). A mutation runner or any wrapper must prove its own baseline is green and count a "kill" only when PHPUnit reports failed tests, otherwise a command error looks like a 100% kill. Read the raw output of the first mutation before trusting a sweep.
- Three test files already fail on unchanged code: `CustomProductApiTest` (1 error, fixture lacks a NOT NULL `normalized_name`), `ProductDrivenDiscoveryTest` (4) and `ProductMatchingTest` (2), both `no such table: crm_leads`. Do not mistake them for regressions. Prove it by running them with HEAD's copy of the files you changed (back up, `git show HEAD:path`, restore, `cmp`), not with `git stash` (a leftover stash already exists).
- **Driving the screens in a browser** (details in `_evidence/s1-tasks8-13-frontend-2026-11-02.md` and the memory note `g2gv0-browser-test-auth`): open the front end at `localhost:3000`, but bind the backend to `127.0.0.1:8000` (where g2gv0's `.env` points; a `localhost` bind answers on the other address family only); use Playwright's role queries (the app's `Select` is `role="combobox"`; a required field's label includes a decorative `*`, so match labels with a regex); wait for the menu that opened a drawer to finish fading before the next key or click, because its closing layer takes an early Escape (shared Radix behaviour, not a drawer bug); wait for data, not the first render (the dev server is single-threaded and React's dev mode doubles each fetch).
- Throw-away scripts that rewrite source with `String.replace` break on `$&` and `$'` in the replacement text (they paste the match or the rest of the file in). Use a function replacer, or `split`/`join`.
- The CRM list endpoint reads `itemType` (camelCase) and answers 422 without it. A request I need to make with a second user's token can be refused by the auto-mode classifier; do not route around it.
- Test records made through the UI cannot be removed through the API (no hard delete): record each id as it is created and delete only rows matching that id, the organisation, the creating user and the name, in one transaction, then compare with a baseline taken before the first write. Tools: `D:\dev\agentic-crm-research\scripts\g2g\s1_browser_db.php`, `s1_browser_inspect.php`, `s1_browser_session.php` (mints and deletes a 3-hour token).
- No agent worktrees. C: is nearly full; heavy work goes on D:.
- Nothing may send email, SMS or any message. Sending stays off everywhere.

## Log

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
