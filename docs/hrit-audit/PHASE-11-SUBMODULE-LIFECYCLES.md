# Phase 11 — every sub-module driven through its writes

**Raised and closed:** F-146, F-147, F-148, F-149 — **4, all fixed and proven.**
**Method:** ten of twelve sub-modules driven create → read → update → delete at the API.
**Verification:** **222 assertions across 12 probes, 0 failures** (was 171 across 11).

---

## The method, and why it mattered

Ten sprints proved these screens **open**. `probe-scale` measured thirty endpoints; every one
returned 200. The tracker recorded eleven of twelve sub-modules as AMBER and, for several of them,
gave the reason as some version of *"not yet tested at the API"*.

This phase did the thing that sentence was standing in for: **drove each sub-module through its
writes.** One new probe, `probe-lifecycle.sh`, 51 assertions, each section creating its own data and
removing it.

**Four defects were waiting behind the first write each screen had never been asked to perform.**
None of them is visible from a read. All four are now closed, and each one's assertions were checked
against the *unfixed* code to confirm they actually fail without the fix.

---

## F-146 — a pay head that changes owner — CRITICAL

Every by-id operation on `payroll_types` looked the record up globally: `PayrollType::find($request->id)`
for the edit, `PayrollType::where('id',$id)->update(...)` for the delete, and `find($id)` again for
the edit form. `PayrollType` has no global scope, and the routes sit inside
`Route::middleware('hrit.role:admin,hr')` — which proves the caller's **role** and never their
**organisation**.

The edit path is the serious one, because `payrollStore` reassigns the tenant from the caller a few
lines after the lookup. Run against the unfixed code:

```
tenant 3 creates pay head id 14 'lifecycle-probe-renamed'
tenant 6's administrator POSTs /payroll-type/store  id=14  payroll_name=STOLEN

  another tenant cannot edit this pay head - expected [404] got [200]
  the name is untouched      - expected [lifecycle-probe-renamed] got [STOLEN]
  and it did NOT move organisation - expected [3] got [6]     <- CHANGED OWNER
  another tenant cannot delete it either - expected [0] got [1]
```

So it is not "one tenant can edit another's configuration". It is **one tenant can take ownership of
another's configuration** — and `employee_salary_data` is keyed by pay-head id, so every salary
structure in the original organisation referencing that head silently stops resolving a component of
somebody's pay. No error, because the JSON key simply no longer matches a head that organisation can
see.

**Fixed** by scoping all three lookups, and by **refusing with 404** rather than falling through to
creating a new head — a save that quietly does something other than what was asked is exactly how
F-109 survived three sprints.

---

## F-147 and F-148 — the first salary certificate this product has ever produced

`hrms_salary_certificate` held **zero rows platform-wide**. F-110 closed in Sprint 8 having fixed
the crash for employees with no salary structure, and recorded the table as *"unusable rather than
unused"*. It was still unused, because a **second** crash sat in front of the writer:

```
POST /hrms-salary-certificate-report  (without payroll_type_id)
  -> count(): Argument #1 ($value) must be of type Countable|array, null given
  -> HTTP 500
```

`month_id` and `payroll_type_id` were read straight into `implode()` and `whereIn()`. A missing
required field is a **422**, not a stack trace — the correction F-106 already made for leave.

With that fixed, the certificate generated. And then the row showed the second problem:

```
id 16   employee 10   created_by (empty)   created_at (empty)
```

`'created_by' => session()->get('user_id')` — **null under `type=API`**, where there is no session —
and no `created_at` at all. On a document an employee hands to a bank, on the institution's
letterhead, there was no record of which HR user issued it or when. Same class as F-138, which found
the payslip upsert setting `updated_at` and never `updated_by`; `payrollActorId()` exists for
precisely this and both branches now use it.

```
after: id 17   employee 10   created_by 67   created_at 2026-09-08 10:46:49
```

**A note on the probe, because it nearly enforced a bug.** The first version asserted the certificate
does *not* say "Her". F-131 was "every employee was called Her **regardless** of gender" — but
employee 10's gender is on record as `F`, so "Her" is the *correct* output. The assertion was
inverted and would have failed a correct certificate. It now asserts the sentence **follows
`u.gender`**, with the employee's recorded gender asserted alongside it so the reason is visible.

---

## F-149 — Form 16 could not list a single employee

`getEmployeeLists`, the picker behind Form 16, read the tenant from `$request->session()` with **no
`type=API` branch**. A token caller has no session, so `sub_institute_id` resolved to null, the query
filtered on null, and the answer was:

```
{"employees":[],"department_id":"35","employee_id":null}      HTTP 200
```

A **silent** failure — an empty picker and no error, which reads as "this department has no
employees" rather than as a fault. That is why it lasted.

**Two corrections to the audit's own record fall out of this.**

The method was filed as *"dead but broken — nothing calls it."* It is routed at
`routes/hrms.php:89` and Form 16 is its caller. The search behind that claim looked for callers of
the method **name** and missed the route registration. It was live, and returning nothing.

And the cause is **Q6 made concrete**. `HrmsController::getEmployeeLists` — the other copy of the
same method — **already had the `type=API` branch**. Two copies of one method drifted, and only one
of them worked. Q6 asks which of the duplicated controller pairs are still called; this is the answer
for one of them, and it cost Form 16 its employee picker.

---

## What is now driven end to end

| Sub-module | Driven through writes | Found |
|---|---|---|
| Attendance Tracking | punch in → punch out → hours | — |
| Attendance Reports | report runs, gated, tenant-scoped | — |
| Leave Requests | apply → approve → balance → cancel | — |
| Leave Configuration | leave types + holidays, full CRUD, both boundaries | — |
| Payroll Type | create → validate → rename → delete | **F-146** |
| Payroll Deduction | adjustment saved and read back | — |
| Salary Certificate | generated, validated, attributed | **F-147, F-148** |
| Form 16 | picker + screen | **F-149** |
| Salary Structure | read + gate + tenant scope | — |
| Monthly Payroll | covered by `probe-sprint9` | (F-142, open) |
| Leave Dashboard, Leave Reports | read-only surfaces — no writes of their own | — |

---

## Verification

| Probe | Result |
|---|---|
| `probe-lifecycle` — **new**, the sub-module lifecycles | **51 / 51** |
| `probe-sprint1` … `probe-sprint10`, `probe-q3`, `probe-f132` | 171, **0 failures** |
| **Total** | **222 assertions, 0 failures** |

Each new finding's assertions were run against the **unfixed** code and confirmed to fail — five
fail for F-146, one for F-149.

**And one thing the probe now says out loud.** Two of Form 16's three assertions pass *vacuously* on
the broken endpoint: "no foreign employees" and "another tenant sees none of ours" are both trivially
true of an **empty list**. Only "the picker returns anybody at all" fails without the fix. That is
F-109's lesson in a new place — an assertion that cannot fail is not a test — and it is written into
the probe as a comment so the next person does not delete the one line doing the work.

**No live schema changed.** Every section creates its own data and removes it; the probe deletes by
**id** rather than by name, because when run against the unfixed code the cross-tenant write
*renames* its own row and a name-pattern cleanup silently misses it. That happened once, and the
leftover row was removed by hand.
