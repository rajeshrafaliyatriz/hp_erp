# Agentic CRM — Progress

Living log for building the agentic CRM one slice at a time.

**Read this file first in any new session, then the current slice file in `slices/`.** Update it after every task and at the end of every session, so a cold session can resume with zero re-derivation. After any context compaction, re-read this file and the slice file before doing anything else. Do not rely on a chat summary.

- Plan (approved 2026-11-02): `PLAN.md` in this folder (copy of the planning-session plan).
- Published pages (private artifact): https://claude.ai/artifact/1ZoowKicrqvTLrunZwE2aK (plan page, architecture page, build plan page).
- Source HTML of those pages, kept outside this repo: `D:\dev\agentic-crm-research\published-pages\`.
- Research behind the architecture: `D:\dev\agentic-crm-research\` (notes, report, sensitivity script, read-only db3 scripts).
- Rules: `SESSION-RULES.md` (the eight steps), `DB-RULES.md` (database), `DECISIONS.md` (owner decisions), `DB-CHANGELOG.md` (every migration and data update).

## Next action

1. **Wait for the owner** (design gate, no S1 code until approved): approve or change the S1 mock-up (`s1-mockup.html` in the artifact; source `D:\dev\agentic-crm-research\published-pages\s1-mockup.html`) and answer its four questions (menu place, words, the 32 unassigned signals, prices now or later). The proposals in the page apply to anything skipped.
2. After approval: S1 task 1 (the owner's "go" for the dev migration), then S1 tasks 2 to 13 in order (`slices/S01-product-master.md`).
3. D6 (CRM SMS master switch) still waits for the owner's OK (open decision 3). Open decisions 1 and 2 (credits unit; schema export and Docker) are not blocking S1.

## Slice checklist

| Slice | Title | Status |
|---|---|---|
| S0 | Working system, baseline, safety | **in progress**: 7 of 9 tasks done; only task 6 (D6 SMS switch) and the owner's answers remain |
| S1 | Product Master | **design gate open**: mock-up published (artifact v6), waiting for the owner's approval |
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

Blocking slices 0 and 1:

1. What unit and provider do the 10,000 credits cover (Tavily, Context.dev, both)?
2. May the assistant take a read-only schema export of live, and is Docker available, for an optional MariaDB 10.1 rehearsal?
3. **D6 only:** OK to add a CRM SMS master switch, default off? One SMS gateway row exists on each host (not tenant 6), so switching it off by default would stop that tenant's SMS until the flag is turned on. (D1, D20 and D21 were fixed in S0 as local commits `0be5aa66`, `02d4d896`, `4c41e9a4`, test `723523a7`; nothing is pushed or deployed.)

Also for the owner (found by the baseline): what are the values of `G2G_NOTIFY_EMAIL`, `CRM_EMAIL_SENDING_ENABLED`, `GTM_OUTREACH_SENDING_ENABLED`, `GTM_OUTREACH_SCHEDULER_ENABLED`, the queue driver and the scheduler cron on the production server? And does the deployed backend match branch `Milan-2`? (D20 is fixed in code but not deployed; until the branch is deployed, live runs whatever it runs now. The branch should not be deployed without D20's fix.)

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
- No agent worktrees. C: is nearly full; heavy work goes on D:.
- Nothing may send email, SMS or any message. Sending stays off everywhere.

## Log

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
