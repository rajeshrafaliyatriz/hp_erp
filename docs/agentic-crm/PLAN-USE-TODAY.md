# Plan: make the CRM usable today (4-hour window, 5 Nov 2026 09:19 to about 13:20 IST)

The owner's ask: "we want to start using the complete CRM today, we have 4 hours, do not cut the plan, say what to complete first so that after 4 hours we can use the CRM in G2G fully." This file is the ORDER of the work, not a cut: every slice of `PLAN.md` stays; what changes is what is finished, proven and released first. Written about 09:25 IST. Rules unchanged (`SESSION-RULES.md`, `briefs/COMMON-RULES.md`): never push, deploy, touch the live database or send anything without the owner's word; nothing new in the sidebar.

## What "usable" means (the acceptance test, written before the work)

A tenant-6 person can do these twelve daily jobs in the real stack (real browser, real API, dev database), unaided, with zero API errors (400 or more) and zero console errors:

| # | Daily job | Screen(s) | State at 09:30 |
|---|---|---|---|
| J1 | See new signals, decide on them with a reason, turn one into a lead | Signal Review, Market Signals | built, browser-seen (3 Nov) |
| J2 | Create, find, filter and open a lead; see its product, consent, evidence | Leads | built, browser-seen (4 Nov) |
| J3 | Convert a lead into organisation, contact and opportunity (no fake contact) | Leads, Convert dialog | built (S6), conversion NOT yet seen in a browser |
| J4 | Open an organisation: 360, facts to accept or dismiss, the agent's brief | Organizations, Contacts | built, browser-seen (4 Nov) |
| J5 | Add a note and a task on a record; the agent does the same by itself | record pages, Agent panel | built, browser-seen |
| J6 | Move an opportunity through its stages, give a coded loss reason | Opportunities & Deals | backend built (S11a); screens NOT yet showing the loss reason or product |
| J7 | Build a quote, turn it into a sales order and an invoice | Quotes, Sales Orders, Invoices | legacy screens (shipped Oct), not re-checked in this effort |
| J8 | Run a campaign: see its product and funnel, the leads it produced | CRM Campaigns (menu row hidden), GTM Campaigns | backend built (S9a); screens built but unproven (S9b); the CRM Campaigns menu row is hidden for everyone |
| J9 | Check a draft against the outreach rules before anything could be sent | Outreach Approvals (menu row hidden) | gate built (S10a), unproven; screen not built (S10b); sending stays OFF |
| J10 | Ask the chat a question about the CRM and get an answer with links | CRM chat button | built, browser-seen (4 Nov) |
| J11 | Approve what the agent proposes; set how much it may do alone | Agent Run History (3 tabs) | built, browser-seen except an accept from the queue |
| J12 | Read the forecast and the pipeline report | Forecast, Reports | legacy screens; the daily record is built (S11a) |

Proof that counts is not mine: the day-in-the-life script run in a real browser (J1 to J12, a zero-error budget, screenshots), SQL counts read independently, the team's own baseline for regressions, and, at the end, a real person on tenant 6 doing the jobs.

## Who can use it (the raw facts, read 5 Nov from the dev database)

Tenant 6 has 23 active users: 2 Admin, 18 Employee, 1 HR, 1 Executive, 1 Auditor; only 3 have ever logged in (1 Admin, 2 Employee). **Only the Admin profile (16) holds any CRM right today**; HR holds Outreach Approvals (a hidden row) and the Signals rows. So "the team can use the CRM" needs the owner to choose which profile gets which rows (rights are granted leaf by leaf, never an ancestor). The grant list is in `ROLLOUT-RUNBOOK.md` when written.

## The order (critical path first; nothing dropped)

**Block 1, 09:30 to 10:30** (three tracks in parallel, one browser consumer at a time):
- A. Finish S10a: its mutation proofs, then my verification and commit (the outreach gate: with it, J9 is safe).
- B. Finish S9b: its mutation proofs, then verification; J8 screens.
- C. The day-in-the-life script (J1 to J12) written and run on the real stack by one agent; every failure it finds is a defect I triage first.
- Me: baseline full suites on isolated checkouts of HEAD; the static MariaDB 10.1 linter for every new migration; the rights matrix.

**Block 2, 10:30 to 12:00:** fix what the script found (smallest, tested); S11b-lite (loss reason, product and the forecast record on the opportunity screens: J6, J12); S10b (the checks on the existing Outreach Approvals screen: J9); real-browser passes of S9b and S10b; the CRM Campaigns and Outreach Approvals menu rows (a decision for the owner, see below).

**Block 3, 12:00 to 13:20:** the full day-in-the-life run again from a clean state; full PHP and front-end suites; the rollout runbook (ordered live migrations with the 10.1 findings, backups, rollback, grants, order of deploy: migrate first, then code); artifact version 15; hand-over; cleanup of every throw-away row, token and server.

**After that, only on the owner's word:** push, deploy, live migrations (about 30 new migrations since S2, all dev only), the menu and rights changes on live. There is no deploy pipeline I can run (push is not deploy).

## Decisions the owner must make to "use it today" (also in `HANDOVER-4NOV.md`)

1. Which profiles get CRM rights (suggestion in the runbook after the audience count above).
2. Unhide the CRM Campaigns row (menu 453, status 0) and Outreach Approvals (499, status 0)? Without it J8 and J9 have no door in the sidebar.
3. The go for push, deploy and live migrations, and when.

## Log (append only)

- 5 Nov about 09:25: plan written; baseline suites and three tracks started (S10a finish, S9b finish, the day-in-the-life script).
