# Scale — what HRIT does at 500 employees

**Measured 2026-09-19** on the application's own database (`mysql`,
`202.47.117.220`, `web.triz.co.in`), so every figure includes a real network
round-trip rather than a loopback.

Re-run it yourself:

```bash
SCALE_EMPLOYEES=500 php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-seed.php';"
                    php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-measure.php';"
                    php artisan tinker --execute="require '<abs>/Docs/hrit-audit/_evidence/scale-teardown.php';"
```

---

## Why this needed seeding at all

Scale was the last release-gate line that could not be closed with the data on
this deployment. The largest real tenant held **90 leave rows and one payslip**.
The two big tenants — 1000000 "Sunrise" (1001 users) and 1000018 "fibervalley"
(963) — are **real organisations with zero HRIT data**, so seeding those was not
acceptable.

Tenant 6 is **Scholar Clone Pvt. Ltd.**, the customer's own organisation, and
they chose it for this.

The cohort was **removed after measuring**. Tenant 6 is back to its baseline of
23 users / 50 departments / 5 leave rows / 0 payslips / 0 structures.

## The cohort

| | |
|---|---|
| Employees | 500 (plus the tenant's own 23) |
| Leave requests | 3,000 — six per employee, spread across 2026, mixed statuses |
| Salary structures | 500, year 2026 |
| Payslips | 500, Aug 2026 |
| Attendance | the tenant's existing 939 rows |

Every row carried a `ZZPROBE` marker and an `@scale.invalid` email — a reserved
TLD (RFC 2606) that can never resolve, so no seeded address could reach a real
inbox. Teardown is **by marker, never by id**: that is F-157's lesson, where a
teardown keyed on ids deleted nothing when one id came back empty and left the
suite permanently red.

---

## Results

Second run of each, so a cold buffer pool is not reported as the cost of the
feature.

| What the screen does | Rows | Time |
|---|---|---|
| Employee list (active) | 522 | **3.6 ms** |
| Leave list, one page of 10 | 10 | **3.0 ms** |
| Leave counts by status | 4 | **4.0 ms** |
| Leave trend, 12 months | 12 | **6.0 ms** |
| Payroll register, one month | 500 | **4.7 ms** |
| Bank advice, one month | 500 | **16.4 ms** |
| Salary structures, one year | 500 | **6.5 ms** |
| Department attendance summary | 8 | **2.7 ms** |

Nothing above one second. **The queries are not the problem.**

---

## What the exercise actually found — F-207

`payrollBankWiseReport` called `employeeDetails()` **once per payslip**, a
joined query against `tbluser` and `tbluserprofilemaster` for every row.

| | Queries | Time |
|---|---|---|
| Before | **1,001** | **6,323 ms** |
| After | **3** | **138 ms** |

That is 334× fewer queries and 46× faster, for a report that is run once a
month by finance and had a screen built on it in this same phase.

**It was invisible until the cohort existed.** The largest tenant on this
deployment had one payslip, so the loop ran once and cost nothing. This is the
whole argument for measuring at a size the product will actually meet.

The fix is one call keyed by `employee_id`. `employeeDetails()` already accepts
an empty employee id to mean "everyone in the tenant" and applies the same
status and visibility rules either way, so it returns exactly the same rows —
it just stops asking 500 times.

### The guard

`scale-measure.php` counts **queries**, not milliseconds. A timing varies with
the network and the buffer pool; a query count does not. If it ever climbs back
toward the row count, the per-row lookup is back.

Proven to fire: reinstating the old loop took it from `3 queries` to
`1001 queries for 500 rows` and printed
`PER-ROW LOOKUP IS BACK`.

---

## What this does NOT establish

- **One tenant, one shape.** 500 employees in ONE department, six leave rows
  each, one payroll month. A tenant with 50 departments, five years of history
  and 40 pay heads would exercise different plans.
- **Nothing above 500.** Linear behaviour is *suggested* by constant query
  counts, not proven. The N+1 above was linear too, and unacceptable.
- **No concurrency.** Every figure is a single caller. Twenty people generating
  payroll at once is a different question, and this does not answer it.
- **Not the HTTP stack.** These are the queries the screens run, which is where
  a scale defect lives. Page-render cost is not measured here.
