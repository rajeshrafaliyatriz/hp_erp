# HRIT Management — progress tracker

One page. What is done, what is left, and where we are. Update this as work lands.

- **Audit:** `AUDIT-HRIT-MANAGEMENT.md` — verdict **RED**. Findings **F-87 … F-141** (53 total; 20 raised during remediation, five of them against this project's own work).
- **Module:** HRIT Solutions (m5) — 12 sub-modules.
- **Test tenants:** tenant **3** (all nine roles have a live user) and tenant **6** (939 attendance rows).
- **Live host:** `202.47.117.220/hp_erp`, MariaDB 10.11.9.

---

## Where we are - in plain English

**All 53 findings are closed. The module is still not GREEN, and those are different statements.**

**Sprint 9 exists because Sprint 6's review never finished.** It checked its own work, found 36
possible problems, confirmed 15 - and then ran out of budget with **18 unchecked**. Those were
honestly labelled "unverified, not refuted" and then carried, unlisted, for two sprints.

Recovering them was uncomfortable. **The two areas never checked were security and payroll** - the
two that matter most - and five of the eighteen were still real. Three of those five had been
introduced by this project's own fixes.

**The worst one:** an administrator at one organisation could create a payslip for an employee at a
different organisation, with figures of their choosing. Proven by doing it on the live database, then
removing the row. A second, gentler version of the same hole let the save response read out other
organisations' staff names.

**And the documentation was wrong.** Sprint 6 claimed it had fixed the duplicate payslips. It had
not, and could not have: the seventeen duplicate rows are stored as "july" while the screen sends
"Jul", so the fix could never find them. The test printed the surviving duplicates on screen and
passed anyway. *A number printed is not a number checked.* Corrected in place, and now actually
fixed - one spelling everywhere, the duplicates collapsed, and a database constraint so they cannot
come back.

**Payroll also got an audit trail**, which it never had. Correcting a payslip now records what it
used to say and who changed it.

**Two long-standing items closed by decision rather than by building.** The broken shift screens were
deleted - they had no tables, no views, no security gate, and the bulk update would have erased the
Saturday half-days that 203 employees have. And the salary rule that behaved differently for one
organisation became a per-organisation setting, seeded so that **nobody's pay changed** - proven by
showing the new rule gives exactly the same answer as the old one for every organisation.

**What is still missing is not a bug list.** Nothing has been tested at realistic volume; payroll
arithmetic has never been checked by hand against a payslip; four of the audit's own negative tests
have never been run; and nobody from the business has signed anything off. Those are what stand
between "every defect found is fixed" and "this is proven to work".

## Progress

| Measure | S0 | S1 | S2 | S3 | S4 | S5 | S6 | S7 | **Now** |
|---|---|---|---|---|---|---|---|---|---|
| Findings **closed** | 0 | 10 | 17 | 20 | 24 | 28 | 33 | 35 | **53 of 53 - 100%** |
| Sprints complete | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | **10 of 10** |
| Sub-modules **GREEN** | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | **0 of 12 - 0%** |

**No sub-module is GREEN, and closing every finding did not change that.** Green means the whole
lifecycle is proven - front door, business rules, validation at the API, and behaviour at realistic
volume. The last of those has never been done.

**F-132 and F-137 are the argument for that caution, and they make it twice.** Monthly Payroll
passed every check this project ran for eight sprints - gated, no duplicate payslips, a month lock,
byte-identical output - while returning **two of 122 employees**. And the duplicate-payslip fix was
recorded as closed for three sprints while its own test printed the surviving duplicates. Both were
found by counting rows, not by reading code. Marking a sub-module green before it has been driven at
real volume would make exactly that mistake official.

Sub-module status:

| Sub-module | Sprint 0 | **Now** | Why |
|---|---|---|---|
| Attendance Tracking | RED | AMBER | fixtures gone, buttons wired, correction lifecycle complete **including the approver's queue** |
| Attendance Reports | RED | AMBER | Export and Print work, mocks deleted, reporting gated; paging deferred to Sprint 6 |
| Leave Dashboard | AMBER | AMBER | scoped; balances real; **"View" now opens the detail panel** |
| Leave Requests | RED | AMBER | rules bite, cancel-after-approval works, multi-stage approval with escalation, **and every party is now notified at every stage**; untested at scale |
| Leave Reports | AMBER | AMBER | scoped; day counts corrected; **"Unassigned" bucket gone**; Saved tab now persists |
| Leave Configuration | RED | AMBER | gated and enforced; Entitlements tab exists; **the workflow tab now builds a real approval chain and shows what it will do** |
| Payroll Type | AMBER | AMBER | gated server-side; validation not yet tested at the API |
| Salary Structure | RED | AMBER | gated; no password hashes - tenant-47 pay rule still open (Q1) |
| Payroll Deduction | RED | AMBER | gated; no password hashes |
| Monthly Payroll Report | RED | AMBER | opens reliably; saving twice no longer duplicates payslips; **a month can be locked and reopened with a reason**; still 31-59s at 122 employees (F-121) |
| Salary Certificate | AMBER | AMBER | gated; still zero rows ever written (F-110) |
| Form 16 | AMBER | AMBER | gated; only 2 salary structures exist for 122 employees |

## Done

| Sprint | What it closed | Write-up |
|---|---|---|
| **9** | **What Sprint 6's unfinished review left behind.** That review died with 18 of 36 candidates unchecked, and the two dimensions never checked were authorization and payroll. Five were still live; three had been introduced by this project. An administrator could write a payslip for **another organisation's employee** - proven on live, then removed. The response also read out foreign staff names. **F-109 was recorded as closed and was not**: the seventeen duplicates are spelled `july`, the screen posts `Jul`, and the Sprint 6 probe printed the surviving cluster and passed anyway. Now one canonical spelling, 22 rows collapsed to 6, and a UNIQUE index so they cannot return - plus payroll's **first audit trail**, because a soft delete would have defeated that index. Also closed: F-105 by deleting screens that would have erased 203 employees' Saturday half-days, and F-111 as per-tenant configuration proven to change nobody's pay. | `SPRINT-9-UNVERIFIED-REVIEW.md` |
| **8** | **The employee's own view, and the two screens that had never worked.** An employee could not see their own payslip - no route served it. **My HR** now shows their leave, their payslips and where each pending request has got to; none of its endpoints takes an employee id, so "my payslip" cannot become "anyone's payslip". The Salary Certificate, which had written **zero rows in the life of the product**, turned out to be **unusable rather than unused** - it crashed on any employee without a salary structure, and there are eight on the whole platform. Fixed, along with the hardcoded "Her" it printed on every certificate. Every signed-out browser hit stopped being a 500. The one validation mismatch the audit named turned out to be two. **No live data changes at all** - the first sprint since the audit with none. | `SPRINT-8-SELF-SERVICE.md`, `DEMO-SPRINT-8.md` |
| **7** | **Notifications, and a payroll month you can close.** The module had never sent a notification of any kind - approvers found out a request existed by opening the screen. Three event types added to the platform's **existing** notification stack; the bell already existed and was already wired, so there was no frontend work at all. Apply, and your manager is told; approve, and both the employee and the next approver are told. Escalation now reaches five HR users instead of nobody. And a payroll month can be declared finished: a locked month refuses the save at the server, and reopening it demands a reason that is stored with a name and a time. | `SPRINT-7-NOTIFICATIONS.md`, `DEMO-SPRINT-7.md` |
| **6** | **The approval chain, and payroll that stops duplicating itself.** `hrms_leave_workflow_settings` was the last configuration table in this module that controlled nothing - three live rows, a working screen, no reader anywhere. It now builds a real chain, one row per required approval, frozen onto each request when it is raised. Proven live: an HR Manager with Organization scope **refused** at step 1, a request still pending after one approval of two, approved only after both. Escalation runs hourly and widens who may act rather than reassigning. Payroll stopped INSERTing blind - live data held **17 payslips for one employee-month**. Then an adversarial review of this sprint's own work found a **critical** bug it had just introduced: a finished request could be decided again, because the chain check only ran while a step was open. Closed in the same sprint, with tests. | `SPRINT-6-APPROVAL-CHAIN.md`, `DEMO-SPRINT-6.md` |
| **5** | **Leave lifecycle and data integrity.** The leave table stopped accepting requests that are not requests: the foreign key that pointed at the wrong table entirely is corrected, three columns made NOT NULL, and 17 unusable rows soft-deleted - proven at the database, which now rejects both kinds of bad row. Cancel-after-approval built, with the balance returning by itself. F-112 finally closed at 12 of 12. The Saved reports tab now saves. And the approver's regularisation queue, promised in Sprint 2, is on the dashboard - hidden by the server's refusal rather than by the component. | `SPRINT-5-LEAVE-LIFECYCLE.md`, `DEMO-SPRINT-5.md` |
| **4** | **Leave rules and the missing front door.** The Entitlements tab - the screen the module never had, for the number every balance is computed from. One day-counter service reading the organisation's own working week and holidays, replacing three copies of the same wrong sum; the result stored on the row so reports cannot disagree with what the employee was told, and the backfill reproduced the audit's hand-computed figures exactly. Four apply-time rules that refuse what the audit proved was being accepted. And a column widened before shipping, because the new screen would otherwise have silently rounded 12.5 to 12. | `SPRINT-4-LEAVE-RULES.md`, `DEMO-SPRINT-4.md` |
| **3** | **Attendance Reports.** Export and Print built — the audit had them as dead handlers; in fact the buttons did not exist. The "Saved Reports" dropdown removed as a broken duplicate of the Quick Filter beside it, with the ranges it promised added to the control that works. `report-data.ts` and a whole duplicate filters component deleted. The row "eye" wired to a drawer that already existed and was rendered by nothing. Attendance reporting gated on **both** surfaces — legacy routes and the API the screen reads — with self-service re-tested and still open. **And Sprint 2's F-112 claim corrected: it was marked closed with half its controls still dead.** | `SPRINT-3-REPORTS.md`, `DEMO-SPRINT-3.md` |
| **2** | **Attendance Tracking.** Every fixture on the dashboard replaced with the employee's own data — the leave balance and next holiday now come from endpoints that already existed and had never been called. The shift ring reads each person's real roster (which the audit had wrongly said did not exist; corrected in writing). Five dead Quick Actions wired by reusing screens that were already there. Attendance regularisation built end to end — request, review, apply — around a backend correction that had sat unused. `work_mode` added, closing both the dead "Mark WFH" button and the Location column that always said "Office". And the ~3,200-query N+1 that was making Monthly Payroll Report time out, collapsed to one query. | `SPRINT-2-ATTENDANCE.md`, `DEMO-SPRINT-2.md` |
| **1** | **Authorization and identity.** The five executed holes closed and re-proven with the same script. Payroll gated on the server (it had been gated in the browser only) and stripped of the password hashes it was returning. Monthly Payroll Report's 500 traced to a synthetic request with no identity, and fixed. The frontend moved off guessing roles from job titles onto the nine real `role_key`s — which turned 24 stale comparisons into compile errors and found every screen that had assumed the old four. `hrms_leave_role_permissions` now governs: it had a screen, 21 rows per tenant, and no reader. | `SPRINT-1-AUTHORIZATION.md`, `DEMO-SPRINT-1.md` |
| **0** | The audit. Tooling trust gate first (`tsc` **2 errors**, both outside HRIT; `next build` clean; `route:list` **1834**; live DB reachable). Then Parts A–H against all 12 sub-modules, with five authorization holes **executed** on live and reverted the same session. Two of my own tools produced false readings and were caught before anything was filed — a `grep -P` failure that made every endpoint look locked down, and a latin1 PDO connection that made Unicode look corrupted. Neither made it into a finding. | `AUDIT-HRIT-MANAGEMENT.md` |

---

## Remaining

| Sprint | What it closes | Findings |
|---|---|---|
| **What is left, and none of it is a defect** | **Scale.** The release gate has said "not reached" since Sprint 0. Read-only timings against tenant 1000000 (1001 users) and tenant 6 (939 attendance rows) - no tenant has both. **Payroll arithmetic reconciled by hand**: §E.3 has five rows and not one is a payroll figure; no PF, PT, net or Form 16 total has ever been checked against a hand-computed value, though the brief asked for it. **Q3** - cross-tenant fetch by id - is the sole qualifier on the release gate's only PASS. **Q1** needs a contract, not code. **Q6** - four duplicated controller pairs still routed. **Four negative tests** the audit itself lists and has never run. And **domain sign-off**, which cannot come from me. | none open |

**Deliberately deferred, and said out loud:** statutory remittance (PF/ESI/TDS filing) and final
settlement on exit are not in m5 today and are not in this plan. They are a separate module-sized
piece of work, not a gap in what exists.

---

## Open questions blocking specific work

These are in §11 of the audit and are **not** guessed. Sprint 1 can start without them; Sprints 4–6
need answers.

| # | Question | Blocks |
|---|---|---|
| Q1 | Should a flat pay-head cap mean "pay the excess" (tenant 47) or "clamp to the cap" (everyone else)? Tenant 47 is a **real institute with 597 users** on a third deployment — do not unify the branches blind. | F-111 (Sprint 6) |
| Q5 | Is Salary Certificate unused, or unusable? | F-110 (Sprint 6) |

---

## Standing rules for every sprint

- Every live migration ships with a reversal script in `_local-backups/`.
- Reuse before rebuild. `ResolvesApiIdentity`, `RequireProfile`, `LeaveAnalyticsService`,
  `downloadCsv()`, the leave drawers and `DataTable` are correct and already here.
- No duplicate functionality. §E.0 of the audit lists **five** duplicated controller pairs; where
  two generations of one idea exist, one is removed.
- Verify the tool before believing the tool. It produced two false readings in Sprint 0 alone.
