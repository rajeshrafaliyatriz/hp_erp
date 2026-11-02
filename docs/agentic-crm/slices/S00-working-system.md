# S0 — Working system, baseline, safety

**Status:** in progress (started 2026-11-02). **Rough size:** 1–2 sessions. **Database changes:** none (read-only baseline only).

## Goal

Set up the log and rules, measure the real databases, and close two safety gaps before any feature work.

## Scope

In: the Docs folder, the git-ignored `CLAUDE.local.md`, a memory note, saved research findings, `DB-RULES.md` confirmed against real helper classes, a read-only baseline on dev and live, the D1 fix, the SMS master switch, updated published pages.
Out: any feature work, any migration, any live write.

## Tasks

| # | Task | Status | Evidence |
|---|---|---|---|
| 1 | Publish the updated artifact (build plan page, corrected option letters, PHP agent layer instead of a separate service, light mode, same link) | **done 2026-11-02** (version 4) | `00-PROGRESS.md` log; source in `D:\dev\agentic-crm-research\published-pages\` |
| 2 | Create `Docs/agentic-crm/`, `CLAUDE.local.md` (git-excluded), memory note; save schema findings and defect register to `_evidence/` | **done 2026-11-02** | files in this folder; `CLAUDE.local.md` confirmed ignored by `git check-ignore`; memory notes `agentic-crm-log-every-session` and `g2g-agentic-crm-build-plan` |
| 3 | Read `ColumnExists`, `LegacyMariaDbSchemaGrammar` and two recent migrations; finish `DB-RULES.md` | **done 2026-11-02** | `DB-RULES.md`; one conflict left for the live test (see work log) |
| 4 | Read-only baseline on dev and live (see below) | **done 2026-11-02** | `_evidence/s0-baseline-2026-11-02/` (`FINDINGS.md`, `summary.txt`, `mysql.json`, `live.json`); scripts in `D:\dev\agentic-crm-research\scripts\g2g\` |
| 5 | Fix D1 (delete the stray lines in `DealController.php`), then `php -l` over `app/` to list any other file that does not parse | **done 2026-11-02** | commit `0be5aa66`; the parse scan (`D:\dev\agentic-crm-research\scripts\parse_scan.php`, 2,007 files) found exactly 2 errors: D1 and D21 |
| 5b | **New:** fix D21 (`SkillMatrixController.php` stray line from the 6 Oct merge) and add a test that parses every PHP file | **done 2026-11-02** | commits `4c41e9a4` (D21) and `723523a7` (`tests/Unit/PhpFilesParseTest.php`); the test fails on a planted broken file and passes clean |
| 6 | Add a CRM SMS master switch, default off, with a test (D6). The baseline found one SMS gateway row on each host (not tenant 6), so the risk is real for that tenant | **blocked on owner OK** (open decision 3) | |
| 6b | **New:** fix `ColumnExists::has()` (D20: throws on live) with the literal-quoted form and a test | **done 2026-11-02** (code only; not deployed) | commit `02d4d896`; `tests/Unit/ColumnExistsTest.php`; `FINDINGS.md`, defect register D20 |
| 7 | Owner answers the three blocking questions | open | `00-PROGRESS.md` |

## Baseline to take (task 4), read-only, on dev and live

- Table presence and `SHOW COLUMNS` for every table listed in `_evidence/schema-reading-2026-11-02.md`.
- The `migrations` table: rows present on one host and not the other, for CRM, GTM, Signals, Portfolio and task-management migrations.
- Row counts **excluding soft-deleted rows**, for tenant 6 and in total (plain `COUNT(*)` overstates; see the memory note on soft deletes and placeholder rows).
- Menu rows by `access_link` on both hosts (ids differ between hosts).
- Whether `g2g_signal_products`, `crm_agent_runs`, `crm_consent_preferences`, `crm_signal_inbox`, `crm_signal_topics`, `crm_automation_rules`, `agentic_agents` and `agentic_agent_runs` exist on live.
- The most recent rows of `g2g_platform_task_runs` (is the scheduler running at all?).
- Per tenant: `school_setup.outbound_email_enabled`, and counts of SMS gateway rows (`g2g_integration_credentials` with provider `sms`, and `sms_api_details`): **counts only, never keys**.
- Use the read-only scripts pattern in `D:\dev\agentic-crm-research\scripts\g2g\` (each starts with `SET SESSION TRANSACTION READ ONLY`).

## Acceptance (done when)

- The files exist and `00-PROGRESS.md` is current.
- The baseline is saved under `_evidence/` and summarised in `00-PROGRESS.md`.
- D1 is confirmed by a syntax check (if the owner approves the fix).
- The published pages are current.

## Work log

### 2026-11-02

- Task 1 done: published artifact version 4. Verified locally in a browser (no overflow at 1280, 390 and 360 px, light background under a dark-mode browser, nav and cross-links resolve). Not re-opened on the published URL.
- Task 2 in progress: Docs folder, progress, decisions, DB changelog, DB rules, feature ledger, session rules, S0 and S1 files created.
- Found while reading and confirmed by reading source (not by running): D1, D2, D3 (`_evidence/defect-register-2026-11-02.md`).
- Noted, to fix on the published pages: the merge-section caption says hybrid is "the one you chose", but the owner approved it implicitly through the plan rather than choosing it by name. Reword to "consistent with your answers".
- Task 2 done: all files created; `CLAUDE.local.md` is git-excluded (`git check-ignore -v` shows `.git/info/exclude:62`); `git status` shows only the new `Docs/agentic-crm/` folder as untracked. Two memory notes added and indexed.
- Task 3 done (read in source, **verified**): `ColumnExists::has` runs `SHOW COLUMNS … LIKE ?` with a bound parameter, but four migrations say that form fails on live with "Syntax error near '?'". The repo is internally inconsistent here. I could not settle it by reading, so the S0 task 4 baseline tests the four forms on live (see end of `DB-RULES.md`). Until then new migrations use the literal or `information_schema` forms. Also verified: `LegacyMariaDbSchemaGrammar` is installed for every mysql/mariadb connection (`AppServiceProvider.php:122-137`), so `Schema::hasColumn` works; `g2g_signal_products` migration confirmed "PREPARED, NOT RUN" with INT UNSIGNED and utf8 (D12 now verified); the first-`useCurrent()` ON UPDATE hazard and the legacy zero-date `sql_mode` workaround are documented in `2026_10_08_110000` and apply to S2.
- A correction I made to my own earlier claim: the Signals subagent said `ColumnExists::has` is the safe helper; reading the class showed it uses the very form that report says fails. Agent reports need spot-checks (rule 3) and this was one.
- Task 4 done (**verified**: queries run, output saved). Read-only scripts on both hosts (`SET SESSION TRANSACTION READ ONLY`, SELECT and SHOW only, counts and names). Headlines: (a) bound `SHOW COLUMNS … LIKE ?` fails on live and so does the real `ColumnExists::has()` (D20, 19 call sites, some unguarded; deploying this branch could break live Signals); (b) `g2g_signal_products` has run on live (INT UNSIGNED, utf8, 0 rows) and is missing on dev, so D12 needs a corrective migration, not an in-place edit; (c) `crm_products` is empty on both hosts and live has 21 offers (all tenant 6), no shared offers, no duplicates, so the Product Master backfill is tiny; (d) 32 of 72 live signals have no `business_fit`; (e) `gtm_forecast_snapshots` already exists on both hosts (no per-owner column); (f) `crm_agent_runs` is missing on live; (g) scheduler history is 2 rows per host from September; (h) one SMS gateway row per host (D6 real); (i) dev is strict and live is not, live tables are Compact row format, dev's database default charset is latin1; (j) live is ahead of dev for Signals columns and dev is ahead for `crm_agent_runs`. Full list in `FINDINGS.md`.
- Corrections to earlier reports: the Signals subagent said `ColumnExists::has` is the safe helper (wrong, verified); the "70 columns" for `g2g_company_opportunities` is the dev count (live has 76); the earlier "71 signals" was 72 at baseline time (one more row).
- Docs updated from the baseline: `DB-RULES.md` (rewritten with verified results), defect register (D6, D12, D14 refined, D20 added), S1 slice file, `00-PROGRESS.md`.
- Tasks 5, 5b and 6b done (**verified**: commands run, output seen). `php -l` confirmed D1 at line 398 before the fix and a clean file after. The parse scan over `app`, `routes`, `config`, `database/migrations` and `tests` (2,007 files) found only D1 and D21. D21's fix (delete line 468) reproduces the pre-merge file `712f68e0` exactly (diff empty, ignoring CRLF); the same parse error is on `origin/main`. D1 fix also changes the stage-history row to record `$salesStage` and `$prob`, which the intended any-stage conversion needs; the function never ran after commit `291fb05b`, so no wrong history rows exist. D20: the real helper threw 1064 on live and returned true on dev before the fix.
- Tests: `ColumnExistsTest` (4), `PhpFilesParseTest` (4) and `GuardableColumnCheckTest` pass (22 tests). The parse test fails when a broken file is planted. A wider run (`tests/Unit/Gtm`, `tests/Unit/Crm`, four Feature files; 198 tests) shows 1 error and 2 failures that **also fail on the unchanged code** (verified with `git stash`): `CustomProductApiTest` (sqlite `g2g_companies.normalized_name` NOT NULL), `ResearchRunProgressTest` (a missing sqlite `crm_leads` table; a 255-character message truncation). They are pre-existing and unrelated.
- Local commits (not pushed): `0be5aa66` D1, `4c41e9a4` D21, `02d4d896` D20, `723523a7` parse test.
- D6 (CRM SMS master switch) is **not** done: it changes behaviour for the one tenant that has an SMS gateway row, so it waits for the owner's decision.
- Databases were contacted read-only (dev and live); no writes anywhere.
