# HRIT Management — progress tracker

One page. What is done, what is left, and where we are. Update this as work lands.

- **Audit:** `AUDIT-HRIT-MANAGEMENT.md` — verdict **RED**. Findings **F-87 … F-132** (46 total; 13 raised during remediation, two of them by Sprint 6 against its own work).
- **Module:** HRIT Solutions (m5) — 12 sub-modules.
- **Test tenants:** tenant **3** (all nine roles have a live user) and tenant **6** (939 attendance rows).
- **Live host:** `202.47.117.220/hp_erp`, MariaDB 10.11.9.

---

## Where we are - in plain English

**44 of 46 findings are closed. Two remain, and both need a decision rather than more code.**

**The most serious defect in the whole engagement was found after the last sprint, by trying to
measure something else.** Monthly Payroll was showing HR **two of tenant 3's 122 employees** - and
an administrator two, an HR Manager one. Payroll was being run for two people. The screen showed no
error, because a short list does not look like a truncated one; you would have to know the headcount
to notice.

The cause: the code decides who may see the whole institute from a hardcoded list of profile
*names*, matched exactly, while the screen sends a role *key*. They have never matched. It is not
something this project introduced - the older value missed the list too - it has simply always been
broken, and nobody had counted the rows.

It was found because I could not reproduce the slow-payroll case in order to re-measure it. **The
measurement I could not get was itself the bug.**

**And with that fixed, the slow-payroll finding could finally be measured and closed.** The audit
recorded this endpoint timing out at 60 seconds. It now returns the full 122-employee month in
**under 3 seconds** - about 11x faster than it was after Sprint 2, and 22x faster than the timeout
it started as.

**One stale test was corrected rather than explained away.** Sprint 5's probe had HR approving a
leave request directly, which now correctly returns 403 - tenant 3 has not put HR in its approval
chain. The probe encoded the pre-chain world; it was updated, and the reason is written into the
file so nobody reads the change as a regression being hidden.

## Progress

| Measure | S0 | S1 | S2 | S3 | S4 | S5 | S6 | S7 | **Now** |
|---|---|---|---|---|---|---|---|---|---|
| Findings **closed** | 0 | 10 | 17 | 20 | 24 | 28 | 33 | 35 | **44 of 46 - 96%** |
| Sprints complete | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | **9 of 9 - 100%** |
| Sub-modules **GREEN** | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | **0 of 12 - 0%** |

**No sub-module is GREEN, after nine sprints, and that is deliberate.** Green means the whole
lifecycle is proven - front door, business rules, validation at the API, and behaviour at realistic
volume. Every sub-module is AMBER: they work, they are gated, and their rules bite. Calling one
green because it works when you use it carefully is the exact claim this audit exists to catch.

**F-132 is the argument for that caution, made concrete.** Monthly Payroll passed every check this
project ran for eight sprints - gated correctly, no duplicate payslips, a month lock, output
byte-identical across runs - and it was returning **two of 122 employees** the whole time. Nothing
caught it until somebody counted the rows. Marking a sub-module green before it has been driven at
real volume would have made exactly that mistake official.

**The denominator kept moving, and that is the honest number.** 45 findings, not the 37 the audit
opened with: twelve were raised during remediation (F-120 … F-131), including **two that Sprint 6
introduced and closed itself** after an adversarial review of its own work, and one that had been
fixed in Sprint 2 and wrongly carried as open until Sprint 8 checked.

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
| **What is genuinely left** | **F-111 needs your decision, not code.** Does a flat pay-head cap mean "pay the excess over it" or "clamp to it"? Tenant 47 is a live institute with 597 users and 924 salary structures; changing that arithmetic without an answer would change real salaries. **F-105 needs the shift *template* built**, which is a feature rather than a fix - and the per-employee roster it would replace already works. Plus the **18 review candidates** from Sprint 6 that could not be verified before that review hit a session limit - unverified, **not refuted**. | F-105, F-111 |

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
