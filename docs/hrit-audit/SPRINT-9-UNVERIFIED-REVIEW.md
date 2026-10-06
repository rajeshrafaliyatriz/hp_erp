# Sprint 9 — What the unfinished review found

**Closed:** F-105, F-111, F-133, F-134, F-136, F-137, F-138, F-139, F-141 — **9**, taking the
register to **53 of 53**.
**Corrected:** F-109, which this project had recorded as closed and was not.
**Live changes:** three migrations (+ two reversal scripts).

---

## The reason this sprint exists

Sprint 6 ran an adversarial review over its own work: 36 candidates across five dimensions, each
verified by a second reviewer. It reported **15 confirmed** and then died on a session limit with
**18 unverified**. Those were recorded as *"unverified, not refuted"* and carried, unenumerated,
through Sprints 7 and 8 — the write-ups say so three times.

Recovering them from the review's own subagent transcripts changed the picture:

> **The authorization and payroll dimensions were verified at 0%.** The "15 survived" headline
> covers three dimensions, not five. The two that went unexamined are the ones carrying the
> security and the money.

**Five were still live. Three had been introduced or widened by this remediation.**

Every one below was verified personally against the working tree and live data before being acted
on. Two of the agent's claims did not survive that check and are recorded at the bottom.

---

## F-133 — payroll wrote and named other tenants' employees — CRITICAL

Two halves. The disclosure half is the one Sprint 8 introduced; the other is worse.

**Disclosure.** Sprint 8 made the save response name the employees who got no payslip — "naming
them is the difference between a warning and a task", the write-up said. The lookup was
`whereIn('id', $noPayslip)` with **no tenant filter**, and `tbluser` ids are globally unique. The
response used to be the constant `"Inserted Successfully"`; making it useful made it an
employee-name oracle across every organisation on the platform.

**Forgery.** `monthlyPayrollStore` has **no validation of any kind**, and `payrollVal`'s keys are
employee ids straight from the request. Executed on live:

```
POST /monthly-payroll-store   as a TENANT-3 administrator
     payrollVal = {"1": {...}}          employee 1 belongs to TENANT 1

-> {"message":"1 employee(s) saved for Feb 2027.","status":"1"}
-> row 34: employee_id=1  sub_institute_id=3  total_payment=1.00
```

A real payslip for another organisation's employee, filed under the caller's tenant, with figures
the caller chose. The row was removed by hand.

Fixed by intersecting the payload's keys against the caller's own employees before the write loop.
Foreign ids are dropped and their **count** reported without their names — reporting them silently
would be a save that looks like it did more than it did.

---

## F-137 and the correction to F-109

**The documentation was wrong, and this is the part worth reading.**

Sprint 6 claimed F-109 fixed the duplicate payslips. It did not, and could not have:

```
SELECT DISTINCT month FROM employee_monthly_salary_data  ->  'May'  'Aug'  'july'
the screen posts 'Jul'   (Helpers::getMonths)

'Jul' != 'july'     -- length, not case; no collation reconciles them
```

The seventeen duplicates were spelled `july`. The upsert key matched `month` exactly, so it never
saw them — a save would have written an **eighteenth** row.

**The Sprint 6 probe printed `employee-months holding more than one payslip: 1` and passed.** It
observed the surviving cluster and asserted nothing about it. *A number printed is not a number
checked* — that is the lesson from this one, and it is a different failure from the earlier
overstatements in this audit, which were wrong claims rather than unchecked ones.

The split reached further than the upsert: the payslip delete, the PDF lookup, the annual Form 16
report, the month lock (a lock taken as `Jul` was **bypassable** by posting `july`), and My HR's
own ordering, where `FIELD(month, 'Dec', …)` scored `'july'` as **0**. The Jan–Mar payroll-year
rule was separately case-sensitive, so a lowercase `january` filed under the wrong year.

**One canonical spelling**, decided in `Helpers::canonicalMonth()`, which **returns null rather
than guessing** — a month this system cannot name is not a month it should file a payslip under.
Then a repair migration: `july` → `Jul`, 22 rows collapsed to 6.

```
Sep        -> 1 row, 10000
september  -> 1 row, 20000     the same row, corrected
SEP        -> 1 row, 30000     still the same row
Smarch     -> refused, not filed
january    -> filed under 2027, exactly as 'Jan' does
```

Collapsing was defensible because all 17 rows were **byte-identical in every figure** — one
distinct value each for payment, deduction, days and the salary JSON. They differed only in `id`
and `created_at`. Nothing financial was lost, and the reversal script carries all 17 verbatim.

---

## F-138 — and why the obvious fix was the wrong one

The upsert had no unique index, no transaction around its update+delete, a **hard** delete, and set
`updated_at` but never `updated_by` — despite `payrollActorId()` existing for exactly that.

The obvious answer was a soft delete. **It is unusable here.** MariaDB has no partial indexes and
NULLs are distinct in a `UNIQUE` key, so tombstoned rows would either collide with the survivor or
force `deleted_at` into the key — which would leave the live rows unconstrained and defeat the
index entirely.

So supersession is recorded as an **event** instead, carrying the complete before-image:

```
g2g_event   payroll.payslip.superseded   16 rows recorded
            -> g2g_audit_log             via AuditLogProjector, no new wiring
```

That closed three things at once: this finding's "no trace", **payroll's first audit trail** (leave
got one in Sprint 7; payroll and attendance emitted nothing), and the clean table the unique index
needed. `UNIQUE (sub_institute_id, employee_id, year, month)` now makes the duplicates *impossible*
rather than merely unwritten.

---

## The other three

**F-136** — `store()`'s re-apply lookup had no tenant filter, so a leave row in another
organisation was matched and its `sub_institute_id` rewritten. A live row this reached:
`id=221, user_id=86, leave_tenant=1, user_tenant=3, "i am not feeling well"`. **Sprint 6's F-127
fix widened it** by adding `sent_back` to the matched statuses — a fix that enlarged an unnoticed
hole. Now proven: a tenant-3 caller posting that user and date creates a **new** row and 221 is
byte-identical.

**F-134** — `(int)'abc'` and `(int)'0'` are both `0`, and Laravel's `when()` skips on any falsy
value, so `leave:escalate --tenant=0` dropped the tenant filter and swept everything. The stamp is
**one-shot**, so it could not be undone. The command now refuses anything that is not a positive
integer, and the service tests `!== null` rather than truthiness — because the next caller will not
know its safety depends on a cast in another file.

**F-139** — Payroll Type Report read `employee_monthly_salary_data` filtered on month and year
alone, returning every organisation's names, gross and deductions to any tenant that opened it.

**F-141** — the escalation sweep read the steps table alone. Its FK is `ON DELETE CASCADE`, which
sounds like protection and is **inert** because this module soft-deletes everywhere. 26 steps on
live belonged to deleted requests, and the hourly sweep was stamping them and notifying five HR
users about requests nobody could open. Every one of the 26 was created by **this audit's own
probes**, which soft-deleted with raw SQL instead of through `destroy()`. Fixed at the cause:
probes 6 and 7 now close their steps, and the sweep joins the parent.

---

## The two decided findings

**F-105 — closed by deletion.** Four reasons compounded, any one sufficient: the Blade views do not
exist either, so creating the tables would not have un-broken the screens; the routes carried **no
role gate**, so once the tables existed any authenticated employee could rewrite everyone's roster;
`bulkUserShiftUpdateController` took `user_id` from the request and updated `tbluser` with no tenant
filter; and it wrote one time pair to Monday–Saturday, omitting Sunday — which would have erased the
Saturday half-days **203 active employees** have. The per-employee roster that already works is
untouched.

**F-111 — closed as configuration, not as an answer.** `FlatCapRule` resolves the behaviour per
organisation from `tenant_setting` (reused, not a new table), falling back to the Sprint 1 list and
then to **clamp**. Proven by **equivalence**: the new rule returns exactly what the old hardcoded
`in_array` returned, for every listed tenant plus 1, 3, 6, 7, 999 — zero mismatches. **Q1 is still
open.** Which behaviour *should* be the default is a question about somebody's contract; what
changed is that it is now askable of a tenant instead of of a constant.

---

## Two agent claims that did not survive checking

Recorded because the standing rule is to verify rather than relay:

- The design proposed **soft-deleting** superseded payslips. Correct instinct, wrong mechanism —
  it would have blocked the unique index. Replaced with the event.
- The recovery report described leave 221 as re-tenantable with care. Checking the data showed
  **two** wrong columns, not one, and that tenant 3 has no sick-leave type at all — so no mapping
  exists that is not an invention. **221 was left untouched** and raised for the tenant to decide.

---

## Verification

| Check | Result |
|---|---|
| `probe-sprint9.sh` | **34 / 34 PASS** |
| probes 1, 5, 6, 7, 8, f132 | all PASS — no regressions |
| Live integrity | 0 cross-tenant payslips, 0 orphaned steps, 0 duplicate employee-months |

| Live change | Reversal |
|---|---|
| `2026_09_07_100000` closed 26 orphaned steps | `REVERSAL-2026-09-07-orphaned-approval-steps.sql` — every id and original status |
| `2026_09_07_110000` month canonicalised, 22 → 6 rows, unique index | `REVERSAL-2026-09-07-payroll-month-canonical.sql` — all 17 rows verbatim |
| `2026_09_07_120000` seeded flat-cap behaviour | `migrate:rollback`; seeds nothing on this deployment |

---

## All 53 findings are closed. The module is still not GREEN.

Those are different statements and the difference matters:

- **Scale has never been tested at realistic volume.** The release gate has said "not reached"
  since Sprint 0. F-132 is the argument for taking that seriously: Monthly Payroll passed every
  check for eight sprints while returning two of 122 rows.
- **Payroll arithmetic has never been independently reconciled.** §E.3 has five rows and not one is
  a payroll figure — no PF, PT, net or Form 16 total has been hand-checked, though the brief asked.
- **Q1, Q3 and Q6 are open.** Q3 is the sole qualifier on the release gate's only PASS.
- **Four negative tests the audit itself lists have never been run** — refresh-during-save, network
  drop, two users editing one row, back button after submit.
- **Domain sign-off has not been sought**, and cannot be by me.
