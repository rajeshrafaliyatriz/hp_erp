# Agentic CRM — Session rules

These eight steps apply to every session on this effort. They exist because the work is large, sessions lose context, and the log is the only thing that survives. A one-line pointer to this file is in `hp_erp/CLAUDE.local.md` (git-excluded, so teammates' sessions are not affected).

1. **Start.** Read `00-PROGRESS.md`, then the current slice file in `slices/`. Say in three lines what the next task is. After any context compaction, re-read both before anything else. Never rely on a chat summary.
2. **During.** One task at a time. After each task, append to the slice's work log: what was done, the command run, the result, the evidence path. Tag every claim `verified` (I read or ran it myself), `read in source`, `inferred` or `unverified`. A subagent's report is `agent-read`, not `verified`.
3. **Verify first.** Re-check any earlier claim, including subagent reports and anything in the defect register tagged `agent-read`, against the code or database before building on it. Spot-check at least two claims from every subagent report. Earlier sessions found that plans built on unchecked claims were wrong (see the Phase 1 correction in `Docs/sales-marketing-merge/00-PROGRESS.md`).
4. **Done means evidence.** A task is done only when its check ran and the output is saved under `_evidence/`. If a check fails twice, stop, log it and report it to the owner. Do not paper over a failure.
5. **End.** Update `00-PROGRESS.md`: checklist row, a log entry (Built, Verified with how, Not verified, Next), the one-line next action, new traps. The last chat message gives a one-line status with a rough percent, for example "Slice 2: 5 of 12 tasks; overall 1 of 15 slices".
6. **Commits.** Small commits per task, only the files that task touched. Commit only when the owner has asked for commits or approved this plan's commit rule; never push unless asked. A second session shares this git identity, so confirm authorship from the diff, not the author name. Logs are committed together with the code they describe.
7. **Design gate.** Each slice starts with a one-page brief and a light-mode mock-up, published privately. The owner approves or changes it before any code.
8. **Retire line.** Each slice ends with "Old screen: keep, hide or retire, decided by the owner on <date>". The default is keep. Nothing is hidden or removed without that decision.

## Standing limits

- Nothing sends email, SMS or any message. Sending stays off everywhere.
- Never print secrets, keys or tokens. Report key names only.
- **No duplicate screens (owner, 3 Nov 2026; replaces "new screens are built beside the old ones").** Before building any screen, print the menu tree (`tblmenumaster_g2g`: `menu_name`, `access_link`, `parent_id`, root 199) and search `hooks/content-map-crm.ts` and `components/domain/crm/` for the object. If a screen already serves it (Organizations, Products, Contacts, Leads...), the new ability goes INSIDE that screen: a tab, a scope switch, columns, an action. A new sidebar entry is only for something with no existing home. A new screen is still granted to tenant 6 only until the owner says otherwise.
- **The Signals screen design is frozen (owner, 3 Nov 2026).** The live design (Harshit's branch, fully inside `origin/main`) is one Signals screen with four tabs: Company Opportunities, Ingestion Engine, Product Portfolio, Partner Network. Do not edit `components/domain/signals/*`, `lib/signals-nav.ts`, `app/signals|ingestion|portfolio|partners` or any of those screens' design before the very end. Business logic inside them (backend, data, the Product Master feeding Product Portfolio) may change. Menu rows 490 to 493 (the tabs), 501 (Signal Inbox) and 506 (Signal Review) are protected: never remove, hide or rename them.
- **Parallel tracks:** at most three big agents at a time (four exhausted the usage limit), each with a written brief in `briefs/` (`COMMON-RULES.md` plus one file per track), disjoint file ownership, no commits by agents, no servers except the one track that owns them, and the caller re-runs every claimed test and spot-checks two claims per report.
- Database changes follow `DB-RULES.md`. Live changes need an explicit owner "go".
- No agent worktrees.
