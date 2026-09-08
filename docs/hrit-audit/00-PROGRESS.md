# HRIT Management — progress tracker

One page. What is done, what is left, and where we are. Update this as work lands.

- **Audit:** `AUDIT-HRIT-MANAGEMENT.md` — verdict **RED**. Findings **F-87 … F-141** (53 total; 20 raised during remediation, five of them against this project's own work).
- **Module:** HRIT Solutions (m5) — 12 sub-modules.
- **Test tenants:** tenant **3** (all nine roles have a live user) and tenant **6** (939 attendance rows).
- **Hosts:** the app's own database is `202.47.117.220/hp_erp` (MariaDB **10.11.9**) - this is what
  `.env` points at and where every sprint's work was verified. A second host, `128.199.17.97/hp_erp`
  (MariaDB **10.1.48**), is configured as the connection named `live` and is **not** the one the app
  uses, despite the name. Both are now fully migrated; see *Deployment state* below.

---

## Deployment state - both hosts migrated, 2026-09-07

Both databases now report **0 pending migrations**. Getting the second one there uncovered something
worth recording, because it was not a defect in any migration:

**`128.199.17.97` was 65 migrations behind and could not be migrated at all.** `php artisan migrate`
died on the first one - *"Table `s_user_skill_application` already exists"* - and applied nothing.
The cause was **drift, not a bad migration**: tables and columns had been added to that host by hand
over months without the `migrations` table ever being told. Fifty-six migrations were asking to
create things that were already there.

Recording them as run asserts their schema is present, so that assertion was **checked rather than
assumed** - every table and every column each migration would create, compared against
`information_schema` on that host. Fifty-six matched completely and were recorded (bookkeeping only,
no schema touched). Nine had genuinely never run and were executed. 56 + 9 = 65.

**Two things had to be fixed in the repo to get there**, and both are the same bug:

- `Schema::hasColumn()` selects `generation_expression`, a column MariaDB gained in **10.2**. On
  10.1 it dies before the migration's own logic runs. This had already stopped three earlier
  migrations on this host.
- The workaround already in the tree - `SHOW COLUMNS FROM x LIKE ?` - **does not work either**, and
  its docblock claimed it worked everywhere. MariaDB 10.1 refuses to *prepare* a `SHOW` statement
  carrying a placeholder (`1064 ... near '?'`). Seven migrations carried this broken helper with a
  comment asserting it was portable.

Both are now replaced, in **11 migrations**, by a `column_name` lookup against `information_schema`,
which binds normally and never touches `generation_expression`. The false portability claim in the
docblocks is corrected rather than deleted.

**What actually changed on `128.199.17.97`:** 296 rows of plaintext passwords cleared; 22 payslips
collapsed to 6 with `july` canonicalised to `Jul` and the unique period index created; one
`user_onboarding_status` table created. Leave data was untouched (41 rows before and after), and
that host had **zero** orphaned approval steps - the 26 on the app host were created by this audit's
own probes, which never ran here. Reversal:
`_reversals/REVERSAL-2026-09-07-migrate-128.199.17.97.sql`, with before/after figures for every line.

**Regression check after migrating:** 112 probe assertions across sprints 1, 5, 6, 7, 8, 9 and F-132
- **0 failures**.

---

## Where we are - in plain English

**Phase 10 took the module from RED to AMBER, and found the reason it is not GREEN.**

The audit's 53 findings were all closed after nine sprints. What was left was the audit's own
unfinished homework: a release gate untouched since Sprint 0, a golden-transaction table whose every
failure cited a finding that had since been fixed, and an integrity checklist that asked for
calculations to be reconciled and **contained no payroll figure at all**.

Doing that reconciliation is what changed the verdict - in both directions.

**The good half.** Cross-tenant isolation is now proven for fetching a record *by its id*, not just
for lists - that was the single caveat on the audit's only original pass. Attendance got an audit
trail, which it never had, and it was the write that most needed one: approving a correction
overwrites somebody's recorded hours, and payroll reads those hours. Golden transactions were re-run
for the first time since Sprint 0 and now stand at **9 of 12 passing**, against 2 of 12.

**The bad half, and it is the headline.** Nobody had ever checked a payslip's arithmetic. Doing it
found that **no payslip in the system agrees with its own stored figures** - six out of six. The
cause is a single line: when payroll is saved, the server files the totals the browser sent it and
never recalculates them. The sums it computes are only ever used to draw the screen.

That is not fixed, on purpose. Recalculating would be the obvious answer and is not safe to do
unilaterally: every payslip already issued would then disagree with what the system would produce
for it, and people have been paid against those numbers. **It needs the customer's decision**, and
it is the one thing standing between AMBER and GREEN that is not simply "we have no data big enough
to test with".

**Something else worth admitting.** The verdict at the top of the audit opens with five things an
ordinary employee was able to do in Sprint 0 - including approving their own leave and granting
themselves organisation-wide rights. Those were fixed and checked once, by hand. **No repeatable
test had re-checked them since.** The headline claim of the whole audit had gone ten phases on a
check nobody re-ran. There is one now.

---

**All 53 original findings are closed. The module is still not GREEN, and those are different statements.**

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

**What is still missing - updated after Phase 10, because three of these four moved.** Payroll
arithmetic **has now been checked by hand**, and that is exactly how F-142 was found. Of the four
negative tests that had never been run, **two are now covered** by existing assertions and one is
covered for payroll but not for leave; only "network drop mid-save" genuinely remains, and it needs
a client that abandons a connection. Scale is **measured where volume exists** - 1001 employees,
939 attendance rows - and unmeasurable where it does not: no organisation on this deployment has
more than 13 leave requests or 6 payslips. And nobody from the business has signed anything off.
That last one has not moved at all, and cannot be moved by me.

## Progress

| Measure | S0 | S1 | S2 | S3 | S4 | S5 | S6 | S7 | **Now** |
|---|---|---|---|---|---|---|---|---|---|
| Findings **closed** | 0 | 10 | 17 | 20 | 24 | 28 | 33 | 35 | **53 of 53 - 100%** |
| Findings **closed** (Phase 11) | - | - | - | - | - | - | - | - | **+4** - F-146..F-149 |
| Findings **open** | - | - | - | - | - | - | - | - | **4** - F-142..F-145 |
| Probe assertions | - | - | - | - | - | - | - | - | **222, 0 failures** |
| Verdict | RED | RED | RED | RED | RED | RED | RED | RED | **AMBER** |
| Sprints complete | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | **10, plus Phases 10 and 11** |
| Sub-modules **GREEN** | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | **0 of 12 - 0%** |

**No sub-module is GREEN, and closing every finding did not change that.** Green means the whole
lifecycle is proven - front door, business rules, validation at the API, and behaviour at realistic
volume. Phase 10 measured what could be measured and the numbers are healthy, but the volume that
matters most for this module - many leave requests, many payslips - **does not exist on this
deployment to test against**. And payroll now has a reason of its own: a sub-module whose payslips
cannot be derived from their own inputs (F-142) cannot be called green whatever else passes.

**F-132 and F-137 are the argument for that caution, and they make it twice.** Monthly Payroll
passed every check this project ran for eight sprints - gated, no duplicate payslips, a month lock,
byte-identical output - while returning **two of 122 employees**. And the duplicate-payslip fix was
recorded as closed for three sprints while its own test printed the surviving duplicates. Both were
found by counting rows, not by reading code. Marking a sub-module green before it has been driven at
real volume would make exactly that mistake official.

Sub-module status:

| Sub-module | Sprint 0 | **Now** | Driven end to end? | Why |
|---|---|---|---|---|
| Attendance Tracking | RED | AMBER | **yes** | punch in -> punch out -> hours recorded, proven; correction lifecycle complete with the approver's queue; Phase 10 gave it an **audit trail** carrying the before-image |
| Attendance Reports | RED | AMBER | **yes** | the report runs, is gated against an employee (403) and returns only the caller's organisation |
| Leave Dashboard | AMBER | AMBER | read-only surface | scoped; balances real; "View" opens the detail panel |
| Leave Requests | RED | AMBER | **yes** | apply -> two-step approval -> balance falls -> cancel returns the days, all asserted; escalation and notifications live |
| Leave Reports | AMBER | AMBER | read-only surface | scoped; day counts corrected; Saved tab persists |
| Leave Configuration | RED | AMBER | **yes** | leave types and holidays driven create -> rename -> toggle -> delete, each refused across the tenant boundary and refused to an employee |
| Payroll Type | AMBER | AMBER | **yes** | full CRUD **and** validation now tested at the API - which is how **F-146** was found: every by-id operation was cross-tenant, and the edit path *moved* the pay head to the attacker's organisation |
| Salary Structure | RED | AMBER | read + gate | gated, no password hashes, every listed employee in the caller's organisation; tenant-47 pay rule still open (Q1) |
| Payroll Deduction | RED | AMBER | **yes** | an adjustment saves and reads back under the canonical month; **11 of 12 legacy rows remain unreachable** (F-143), referred to the tenant |
| Monthly Payroll Report | RED | **RED** | **yes** | opens, no duplicates, month lock works - but **no payslip agrees with its own stored figures** (F-142). Back to RED on the one thing a payroll screen is for |
| Salary Certificate | AMBER | AMBER | **yes** | **the first certificate this product has ever produced now exists.** It had written zero rows platform-wide because a second crash (F-147) sat in front of the writer; it also recorded neither author nor date (F-148) |
| Form 16 | AMBER | AMBER | **yes** | **the employee picker returned nobody** to any API caller (F-149) - a silent 200 with an empty list. Fixed; the picker and the screen both work |

**Ten of twelve are now driven through their writes, not just their reads.** The two marked
"read-only surface" are dashboards and report views that have no writes of their own - they render
what the other sub-modules store.

**That change of method is what found F-146 to F-149.** Ten sprints proved these screens *open*.
Four defects were sitting behind the first write each screen had never been asked to perform, and
one of them - a pay head changing owner across the tenant boundary - is as serious as anything in
this audit.

## Done

| Sprint | What it closed | Write-up |
|---|---|---|
| **Phase 11** | **Every sub-module driven through its WRITES, not just its reads** - and four defects were waiting behind the first write each screen had never been asked to perform. **F-146 is the serious one:** every by-id operation on Payroll Type looked the record up globally, and because the save reassigns the organisation from the caller, another tenant's administrator did not merely edit a pay head - they **took ownership of it**, silently breaking every salary structure that referenced it. Proven by doing it, then fixed. **Salary Certificate produced the first document in the product's life:** the table held zero rows platform-wide because a second crash (F-147) sat in front of the writer, and it recorded neither who issued it nor when (F-148) - on a document employees hand to banks. **Form 16's employee picker returned nobody** to any API caller (F-149), a silent HTTP 200 with an empty list; the audit had recorded that method as "dead, nothing calls it" and it was live, its own duplicate copy in another controller having drifted into working correctly. | `PHASE-11-SUBMODULE-LIFECYCLES.md` |
| **Phase 10** | **The audit's own unfinished homework - and the reason this module is not GREEN.** The release gate had not been touched since Sprint 0 and the golden-transaction table still failed on findings that were long since fixed. Re-run: **9 of 12 golden transactions pass**, against 2 of 12. **Q3 closed** - cross-tenant fetch *by id*, the one caveat on the audit's only original pass, proven in both directions. **Attendance got an audit trail**, the write that most needed one: approving a correction overwrites somebody's recorded hours and payroll reads those hours; the before-image is now kept. And driving it revealed that approve-correct-rewrite had **never been executed at all** - the existing probe only ever proved the endpoint refuses bad input. Then the reconciliation the brief asked for in Sprint 0 and nobody had done: **no payslip agrees with its own stored figures, 6 of 6** (F-142), because the server files the browser's totals and never recalculates them. Left unfixed on purpose - it needs the customer (Q8). Also found: the verdict's own five headline requests **had no repeatable test** in ten phases. There is one now. | `PHASE-10-CLOSING-THE-GATE.md` |
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
| **What is left after Phase 10** | **F-142 needs the customer, not code (Q8).** Payroll totals are filed as the browser sends them; recomputing them server-side would make every payslip already issued disagree with what the system would now produce, and people have been paid against those numbers. **Scale is measured where volume exists** - 1001 employees, 939 attendance rows, both healthy - and there is **no volume anywhere on this deployment** for leave, payroll or approvals to measure against (13 rows and 6 payslips at most). **Two golden transactions** (mid-month joiner, LWP) cannot be honestly proven while F-142 stands, because they would test what the screen computes rather than what gets stored. **F-143 and F-144** are referred to the tenant (Q9) rather than guessed at - they touch money. **F-145**, the unbounded employee directory, is a latent risk at 29 ms today. **Q1** needs a contract; **Q6** - duplicated controller pairs - still open. **One negative test** genuinely remains: network drop mid-save. And **domain sign-off**, which cannot come from me. | F-142 … F-145 |

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
