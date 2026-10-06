# Sprint 8 — The employee's own view, and the two screens that had never worked

**Closed:** F-106, F-110, F-118, F-122, F-130, F-131 — **6**, plus **F-108**, which was fixed in
Sprint 2 and had been carried as open in the register ever since.
**Total: 42 of 45.**
**Live changes:** none. No migration, no schema change — the first sprint since Sprint 0 with none.

---

## F-110 — the Salary Certificate was UNUSABLE, not unused. Q5 answered.

`hrms_salary_certificate` held **0 rows across the whole platform**. The audit could not tell
whether nobody had ever used the screen or whether the screen could not work, and left it as
**Q5**. Answering it took one call:

```
POST /hrms-salary-certificate-report   {employee_id: 1, year: 2026, …}

ErrorException: Undefined array key 0
  PayrollController.php:874
```

`get_salary_certificate_html()` dereferenced `$get_all_details[0]` with **no guard**, and that
array is empty for any employee with no salary structure *for that year*.
`employee_salary_structures` holds **8 rows for the entire platform**, so almost every
(employee, year) a user could pick fatalled before reaching the insert.

That is the whole explanation for zero rows in the table's lifetime. Confirmed by the converse:
the *one* combination on live that does have a structure — employee 10, tenant 3, 2026 —
succeeded and wrote **the first row this table has ever held**.

The download path had the same shape one screen later (`->pdf_html` on a row that never existed),
so every download was also a fatal.

**Now:** a refusal that says what to do, at **422**.

```
Vikram Sethi has no salary structure for 2026, so there is no salary breakdown
to certify. Add one under Salary Structure for that year, then generate the
certificate again.
```

**And the 422 is itself a correction.** My first version threw a `RuntimeException`, which Laravel
renders as HTTP 500 with an `exception` key — so a message that correctly said *"add a salary
structure"* arrived looking like a crash. `probe-sprint8.sh` failed it, and it was changed. "You
have not set this up yet" is a 422.

### F-131, found by making F-110 run at all

```php
"… since <b>{$joining_year}</b>. Her monthly salary breakup is as follows:"
```

Hardcoded, on a document an employee takes to a bank. The gender **was** being read — `$his` is
derived from `u.gender` in the loop below — and then used only further down, so the certifying
sentence called everyone "her" regardless.

`M → His`, `F → Her`, **anything else → Their**. Unknown or unrecorded gender gets the neutral
form rather than a guess: a certificate that misgenders somebody is worse than one that is
neutral, and this one goes to a third party under the institution's name. Both branches proven.

This was never in the audit, because the code path had never executed.

---

## F-130 — an employee could not see their own payslip

Not "it was hard to find". **No route served it.** `monthlyPayrollPdf` is registered inside
`routes/hrms.php`'s `hrit.role:admin,hr` group, so the only path to a payslip was the HR console
and an employee asking about last month's pay had to ask a person.

**My HR** — `/api/my-hr/{summary, payslips, payslips/{month}/{year}/pdf}` plus a screen.

**No endpoint takes an `employee_id`.** That is the design, not an omission: the subject is
resolved from the token, so "my payslip" cannot become "anyone's payslip" by adding an id to a
URL. A client that cannot express the wrong request cannot send it.

```
same endpoint, two callers:
  administrator  → 1 payslip   (their own)
  employee       → 0 payslips  (their own)

?employee_id=6&user_id=6 as the employee  → still 0
```

The PDF reuses the existing generator rather than a second implementation that could disagree
with HR about somebody's pay. The screen also shows **where each pending leave request has got
to** — "waiting on Department Head, step 2" — which is only sayable because of Sprint 6's chain,
and **why** a salary certificate is unavailable rather than offering a button that will refuse.

**No role gate on these routes, deliberately.** The question is not which roles may read a
payslip; it is that each person may read exactly one. A gate would be the wrong tool.

---

## F-122 — every unauthenticated browser hit was a 500

```php
return redirect()->route('login');   // there is no route NAMED 'login'
```

The page is named **`login.index`**. Its URI is `/login`, which is exactly what made the wrong
name look right. Every session timeout on any of ~1,800 routes rendered a 500 instead of a login
page — a broken product, from an expired session.

One word. Proven: `/monthly-payroll` unauthenticated → **302 → /login**; the API still → **401**.

---

## F-106 — there were two, not one

The audit named one: `leave_type` validated `max:191` against a `varchar(30)` column, so a long
name became a 500 whose body carried the database host, port and schema.

Rather than change that line, **every `varchar` in the module's thirteen tables was checked
against every `max:` rule that writes it** (`_evidence/width-audit.php`). It found a second:
`HolidayController`'s `holiday_name`, `max:255` against `varchar(191)`.

Both corrected to the column's own number. Proven at the boundary:

```
40 characters → 422  "The leave type field must not be greater than 30 characters."
30 characters → accepted
```

**One false positive, checked rather than filed.** The scanner also flagged
`EmployeeDirectoryController`'s `reason` (`max:1000`) against
`hrms_attendance_regularisations.reason varchar(255)`. It matches on column *name* across tables;
that controller writes `reason` to `tbluser`. Recorded because a scanner that over-matches is
only useful if somebody verifies its output.

---

## F-118 — five methods that 404

`getAttendanceRecords → /attendance`, `checkIn → /attendance/check-in`,
`checkOut → /attendance/check-out`, `getComplianceItems → /compliance`,
`updateComplianceStatus → /compliance/{id}`. None registered; a repo-wide search found **no
caller** for any of them.

All five deleted, with the reasoning left in their place. `checkIn`/`checkOut` were an earlier
generation of `punchIn`/`punchOut`, which work — keeping both meant the file advertised a 404
beside the method that succeeds, which is how somebody picks the wrong one. **Removed rather than
repointed:** a second name for `punchIn` is a duplicate, not a fix.

---

## F-108 — a bookkeeping error in my own register

Marked open through seven sprints. It was fixed in **Sprint 2**, when `use-attendance.ts` was
de-fixtured: the frontend's second percentage formula was deleted and the API's `percentege` is
what renders. Verified in this sprint's sweep — no second formula survives anywhere.

Recorded as a correction rather than quietly ticked off. A findings register that says a defect
is open when it is not is wrong in the same way as one that says it is closed when it is not.

---

## F-121 — two more N+1s, and the finding stays OPEN

| Where | Was | Now |
|---|---|---|
| `monthlyPayrollCreate` | one `employee_monthly_salary_data` SELECT **per employee** | one `whereIn` |
| `helpers.php@employeeDetails` | one `hrms_departments` SELECT **per employee** | one `pluck` |

The second matters more than its count suggests: `employeeDetails()` is **shared** by monthly
payroll, the payroll register and several HRMS screens, so the same waste was paid on each. At
122 employees and a measured 39.7 ms round trip that is ~244 queries ≈ **9.7 s of pure latency**
on this endpoint alone. Output verified unchanged.

**And it is not closed.** I could not reproduce the audit's 122-employee load through the API in
this session — `/monthly-payroll/create` as an administrator returns **2** employees, because
`employeeDetails()` narrows by subordinates for some profiles. Three runs at 0.33–0.48 s with
byte-identical output is a **2-employee** measurement and says nothing about the case the finding
is about. The fix is real; the symptom has not been re-measured; the finding stays open.

---

## Verification

| Check | Result |
|---|---|
| `probe-sprint8.sh` | **16 / 16 PASS** |
| `probe-sprint7.sh` | 12 / 12 PASS |
| `probe-sprint6.sh` | 20 / 20 PASS |
| `probe-sprint5.sh` | PASS |
| `probe-sprint1.sh` | 16 PASS, 0 FAIL |
| `npx tsc --noEmit` | 2 errors, **both another workstream's**; zero in HRIT |
| `npm run build` | clean |
| Live writes | **none** — the certificate probe rows removed, nothing else touched |

---

## What is left — honestly

**Three findings, and none of them is a loose end I chose not to tie.**

- **F-105 — shift configuration.** The controllers exist; `hrms_in_out_times` and
  `hrms_job_titles` are empty with no migration. Building the shift *template* is a feature, not a
  fix, and the per-employee roster it would replace already works (Sprint 2).
- **F-111 — a tenant id in a salary calculation.** Blocked on **Q1**, which is a business
  decision: does a flat pay-head cap mean "pay the excess over the cap" or "clamp to it"? Tenant 47
  is a live institute with 597 users and 924 salary structures. Changing that arithmetic without an
  answer would change real salaries.
- **F-121 — monthly payroll speed.** Two more N+1s removed; the symptom not re-measured, above.

**Also outstanding:** the **18 review candidates** from Sprint 6 that could not be verified before
that review hit a session limit (`_evidence/sprint6-review.md`) — unverified, **not refuted**.
