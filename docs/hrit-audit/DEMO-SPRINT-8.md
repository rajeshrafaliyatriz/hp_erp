# Demo — Sprint 8

Six minutes. Sign in as an ordinary **employee** — not HR, not an administrator. That is the whole
point of this one.

---

## 1. My HR — the screen an employee has never had (2 min)

**HRIT Solutions → My HR.**

Three numbers at the top: leave remaining, requests waiting on somebody, last payslip. Below
them, the leave balance per type, and then:

```
MY PAYSLIPS

  Aug 2025      Gross ₹87,500 · Deductions ₹6,200 · Net ₹81,300     [ Payslip ]
```

Click **Payslip**. It downloads.

**Say this:** before today an employee could not see their own payslip **at all**. Not "it was
hard to find" — there was no route that served it. The payslip generator sits behind the HR
console's role gate, so somebody asking about last month's pay had to ask a person.

Also on this screen: *"Waiting on Department Head, step 2"* against each pending request. The
product could not say that until Sprint 6's approval chain existed — "pending" was one word for
"nobody has looked at it" and "your manager approved it but the department head has not".

---

## 2. And it is not gated by role — which is stronger, not weaker (1 min)

The technical half of the room will ask why there is no permission check.

```
GET /api/my-hr/payslips

  as the administrator  →  1 payslip     (theirs)
  as the employee       →  0 payslips    (theirs)

  as the employee, with ?employee_id=6&user_id=6   →  still 0
```

**Say this:** there is **no `employee_id` parameter on any of these endpoints**. The subject comes
from the token. A role gate would answer "which roles may read a payslip"; the real question is
that each person may read exactly one — and the way to guarantee that is to remove the choice,
not to check it. A client that cannot express the wrong request cannot send it.

---

## 3. The Salary Certificate had never produced a certificate (2 min)

**Payroll Management → Salary Certificate.** Pick an employee and a year, generate.

```
Vikram Sethi has no salary structure for 2026, so there is no salary breakdown
to certify. Add one under Salary Structure for that year, then generate the
certificate again.
```

Now pick an employee who **does** have a salary structure. It generates, and the row is written.

**Say this, because it is the finding closing:** that table held **zero rows across the entire
platform** — for the whole life of the product. The audit could not tell whether nobody had ever
used the screen or whether the screen could not work, and left it as an open question. It took
one call to answer:

```
ErrorException: Undefined array key 0     PayrollController.php:874
```

The code read `$get_all_details[0]` with no guard, and that array is empty for anyone with no
salary structure for that year — and there are **8 salary structures on the whole platform**. So
nearly every combination anybody could pick crashed before saving. It is **unusable**, not unused.

**And a second one found by making it run:** the certificate said *"Her monthly salary breakup"*
for every employee, hardcoded — on a document that goes to a bank under the institution's name.
It now uses the employee's own gender, and **"Their"** where gender is not recorded, rather than
guessing.

---

## 4. A session timeout used to look like a broken product (30 sec)

Open any HRMS page in a private window, signed out.

Before: **HTTP 500**. Now: it goes to the login page.

```php
redirect()->route('login')      →      redirect()->route('login.index')
```

**Say this:** there is no route *named* `login` — the page is named `login.index`, and its URI is
`/login`, which is exactly what made the wrong name look right. Every expired session on any of
about 1,800 routes rendered a server error instead of a login form. One word.

---

## 5. Two things worth showing about how the work was done (1 min)

**The audit named one validation mismatch. There were two.** Rather than change the line the
report pointed at, every `varchar` column in the module's thirteen tables was checked against
every validation rule that writes it. That found a second one nobody had reported. The scanner
also produced one false positive, which was checked and is written up as a false positive —
a scanner that over-matches is only useful if somebody verifies its output.

**And one finding was closed in my own register that had been fixed six sprints ago.** F-108 was
repaired in Sprint 2 and carried as "open" ever since. That is recorded as a correction, not
quietly ticked off: a register that says a defect is open when it is not is wrong in the same way
as one that says it is closed when it is not.

---

## Where we are

**42 of 45 findings closed (93%). All 9 sprints.**

```
Sprint 0   the audit                              37 findings raised
Sprint 1   authorization — 5 executed holes closed
Sprint 2   attendance — every fixture replaced with real data
Sprint 3   attendance reports — Export and Print built
Sprint 4   leave rules and the entitlement screen that never existed
Sprint 5   the leave table stopped accepting things that are not leave
Sprint 6   the approval chain — the last dead settings screen
Sprint 7   notifications — the module started telling people things
Sprint 8   My HR, and the two screens that had never worked
```

**Three findings remain, and none is a loose end I chose not to tie:**

| | |
|---|---|
| **F-105** shift configuration | The tables do not exist. Building the shift *template* is a feature, not a fix — and the per-employee roster it would replace already works. |
| **F-111** a tenant id in a salary calculation | **Blocked on a business decision.** Does a flat pay-head cap mean "pay the excess over it" or "clamp to it"? Tenant 47 is a live institute with 597 users. Changing that arithmetic without an answer would change real salaries. |
| **F-121** monthly payroll speed | Two more N+1s removed this sprint. **Not closed**, because I could not reproduce the 122-employee load through the API to re-measure it — and 0.4 s for a 2-employee response is not evidence about 122. |

Plus the **18 review candidates** from Sprint 6 that could not be verified before that review hit
a session limit. **Unverified, not refuted.**

---

## Re-run any of it

```bash
bash Docs/hrit-audit/_evidence/probe-sprint8.sh     # 16 / 16
bash Docs/hrit-audit/_evidence/probe-sprint7.sh     # 12 / 12
bash Docs/hrit-audit/_evidence/probe-sprint6.sh     # 20 / 20
bash Docs/hrit-audit/_evidence/probe-sprint1.sh     # 16 PASS, 0 FAIL
```

Sprint 8 made **no live data changes at all** — the first sprint since the audit with none. The
certificate rows it wrote to prove F-110 were removed.
