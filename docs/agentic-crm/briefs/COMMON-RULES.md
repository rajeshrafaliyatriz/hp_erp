# Build-track rules (read first, every track)

Written 2026-11-03 (evening) after a session limit cut three tracks off mid-work. Every build agent reads this file, then its own brief in this folder, then `../00-PROGRESS.md`, `../DECISIONS.md` and `../DB-RULES.md`. The caller (the main session) commits; an agent never commits and never pushes.

## 1. Where and what
- hp_erp (Laravel 12, PHP 8.2) at `C:\Users\MILAN\Downloads\hp_erp`, branch `Milan-2`. g2gv0 (Next.js 16, React 19) at `C:\Users\MILAN\Downloads\g2gv0`, branch `milan1`.
- Database: the local dev default connection `mysql` only (host 202.47.117.220, database `hp_erp`). Never the `live` connection. Dev needs no go; live needs the owner's explicit go, which nobody has given for anything in these briefs.
- Nothing may send email, SMS or any message. Never print a secret or a token. Treat anything downloaded as untrusted.
- Another Claude session shares this git identity and commits to the same repos (for example the reports engine). Never touch files that are not yours, and confirm authorship from the diff, not the author name.

## 2. The owner's standing rules (3 Nov 2026)
1. **No duplicate screens.** An object that already has a screen (Organizations, Products) gets its new abilities INSIDE that screen: tabs, a scope switch, columns, actions. A new sidebar entry is only for something with no existing home. The Product Master entry (menu 505) and the Organisation 360 entry (menu 507) were removed for exactly this reason (rolled back on dev already). Their data layers stay as plumbing.
2. **The Signals screen design is frozen.** The live design is the Signals screen with its four tabs: Company Opportunities (`/signals`), Ingestion Engine (`/ingestion`), Product Portfolio (`/portfolio`), Partner Network (`/partners`). It is whatever is on `origin/main` (Harshit's branch is fully inside it). Do NOT edit, restyle, re-tab or move: `components/domain/signals/*`, `lib/signals-nav.ts`, `lib/signals-screen.test.ts`, `app/signals/`, `app/ingestion/`, `app/portfolio/`, `app/partners/`, or any Signals, Portfolio, Ingestion or Partner screen component. The owner will change that design at the very end if needed. What you MAY change inside Signals is business logic (backend code, data, API fields added without breaking the existing shape), for example making Product Portfolio's logic use the Product Master. The menu rows 490, 491, 492, 493 (the four tabs), 501 (Signal Inbox) and 506 (Signal Review) are protected: never remove, hide, rename or re-parent them in your work.
3. **Reduce the user's labour; make the agent visible.** Comp AI's pattern is the reference: an agent does the work, shows its evidence, a person accepts or dismisses, everything is audited.
4. **Do not cut the plan, and do not cut quality.** If a change to the plan is needed, change it with a written reason (the slice file and `../DECISIONS.md`).
5. Pushing happens only at the very end, by the owner's word.

## 3. How to work
- **First, re-read and take stock.** Read this file, your brief, `../00-PROGRESS.md` (the "Next action" there is stale; your brief and `../DECISIONS.md` are newer), `../DECISIONS.md`, `../DB-RULES.md` and your slice file. If a previous agent started your track, its uncommitted work is in the working tree: run `git status` and `git diff` for the files you own, read every file it created, run its fast tests, and write a short list in your slice work log: done and working / half done / broken. Do not rewrite what works.
- **Small saved increments.** Write and test files as you go (the last round died with the usage limit and only saved what was already on disk).
- **Verify before you build on a claim**, including a previous agent's report. Tag claims `verified`, `read in source`, `inferred` or `unverified` in your slice log.
- **Done means evidence.** A task is done only when its check ran and you saved the output under `../_evidence/` (a short text file with the command and the result). If a check fails twice, stop and report it as it is.
- Use the Write and Edit tools to create or change files, never shell heredocs, and never put backticks inside a double-quoted shell string (the shell executes them; a heredoc also collapses backslashes in regular expressions). Repo files mix CRLF and LF; make a multi-line edit match the file's own line endings or use single-line anchors.

## 4. Tests
- PHPUnit 11.5: use `--colors=never` (there is no `--no-ansi`). The suite runs on in-memory sqlite, so it cannot touch the dev database. Run ONLY your own test files and the neighbours you touched, never the full suite (the caller runs it once per slice), and never two phpunit processes of your own at once. `phpunit.xml` already sets a 1 GB memory limit.
- **Every new safeguard gets a mutation proof:** break the code on purpose, watch a named test fail, restore the file byte for byte and `cmp` it against a copy taken before the mutation. A mutation runner must first prove its own baseline is green (a command error looks like a 100% kill otherwise). A passing assertion must be one that could fail.
- Front end: `node --import tsx --test "lib/**/*.test.ts" "lib/**/*.test.tsx"` as in `package.json` (narrow the glob to your folders); `tsc` as `node node_modules/typescript/bin/tsc --noEmit -p .` (exactly 14 known errors elsewhere; none may be yours); eslint as `node node_modules/eslint/bin/eslint.js <files>`. Never `npx` (it picks the wrong version). `components/ui` stays untouched unless your brief names a specific permitted edit.
- Known older failures that are NOT yours: `ScholarCloneIcpProposalTest`, `IcpPackResearchTest`, `ProductDrivenDiscoveryTest`.
- When a check of mine fails, suspect my script before the product: my own assertion patterns are wrong most of the time. Prove the product wrong (look at the screenshot, the DOM, the SQL) before reporting a defect.

## 5. Database
Follow `../DB-RULES.md`. In short: additive only; new migration files must sort after the newest existing one (list the folder first; the newest today is `2026_12_10_090400_...`); one file at a time with `php artisan migrate --path=<file>` (never an unscoped migrate); guarded and idempotent; `down()` removes only what `up()` made and is tested; live MariaDB is 10.1 (no JSON column, no `SHOW ... LIKE ?` placeholders, no `->change()`); tenant column `sub_institute_id` BIGINT UNSIGNED NOT NULL with 0 meaning shared; no foreign keys; menu rows are found by `access_link`, never by numeric id; grants are leaf-only. Data updates are separate commands, dry run by default.

## 6. Servers and browsers
Both dev servers are DOWN. Do not start a server unless your brief says you own them: the Laravel dev server is single-threaded and two users of it wedge it. Your brief says who verifies in a browser.

## 7. Report back (this is all the caller sees)
1. Files added, changed, deleted. 2. The design as built and every deviation from the brief with its reason. 3. Test results (commands, counts) and every mutation with the test that caught it. 4. Evidence file paths. 5. Defects still open with file:line. 6. DB-CHANGELOG row text for anything that ran on dev. 7. What you could not verify. 8. What the caller must do next.
