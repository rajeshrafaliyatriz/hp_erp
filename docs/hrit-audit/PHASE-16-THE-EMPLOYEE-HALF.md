# Phase 16 — the employee's half of HRIT

**Raised and closed:** F-209 … F-213 — **5 findings**, four of them things that
had *never worked for anybody*.
**Verification:** **443 assertions across 27 probes, 0 failures**, re-runnable.
**Frontend:** `next build` green, `tsc` clean in HRIT.
**Migrations:** 2 new, applied to **both** hosts, each with a reversal.
**Trigger:** *"the payroll slip download option is not still, some templates not
available, some features is not working, also for the employee account for hrit
module which screen we should show"*

---

## The plan was wrong about the screens, and the truth was worse

The plan recorded that an employee sees **seven** HRIT screens, three of them
organisation-wide HR analytics that ought to be taken away. It was built from a
rights query I wrote, and two things in it were wrong.

Fetched instead from the endpoint that actually builds the sidebar:

```
GET /user/ajax_sidebar_menu_g2g?type=API&token=<employee>&profile_id=9
  HRIT Management
    Attendance Management -> Attendance Tracking, Monthly Attendance Report
    My HR
```

**Three screens, not seven.** And the org-wide analytics were never granted —
they exist on exactly one tenant's employee profile out of eleven.

The second error was arithmetic. I had filtered `tbluser` on `status='active'`;
the column holds `1`/`0`, and MySQL coerces `'active'` to `0`, so I had counted
**disabled** accounts and reported that tenant 1 had 2 employees and tenant 3
had none. The real figures: **211 active employees across seven tenants.**

Both mistakes pointed away from the actual defect.

### F-209 — 209 employees could not find leave

Menu 103 **Leave Requests** is granted to the `employee` profile in **one tenant
out of eleven**, and its parent 94 **Leave management** in the same one. A leaf
under a container the profile cannot view never enters the sidebar, so in every
other organisation the whole leave section is simply absent.

The screen is not broken and the API is not closed — `LeaveRequestApiController`
scopes a Self-scoped caller to their own rows, and applying forces the subject
to the caller. **The feature works. It is not in the menu.** The only way in is
a button inside My HR, which is why this arrives as *"some features is not
working"* rather than as an obvious hole.

The same migration corrects the opposite error in the same profiles: tenant 1's
employees held `can_view` on the entire payroll menu, and on `live` **both**
employee profiles did. Every one of those screens refuses when opened — 403 from
`hrit.role:admin,hr`, "Access Restricted" from `PayrollPageShell`. No data
leaked. What leaked was the user's time.

Every employee profile on both hosts is now identical: `5, 93, 94, 100, 103,
305, 309`.

---

## The payslip download was broken by construction

Not missing — **built so that it could not work.**

`MyHrController:68` hands the browser a `pdf_url` with no credential on it. The
route is `auth:sanctum`. The control was `<a href={payslip.pdf_url}>`, and a
browser following a link sends no `Authorization` header. Measured:

| | |
|---|---|
| Opened as `<a href>` — the real case | **302 → `/login`** |
| Same URL with the bearer header | **200, `application/pdf`, 162 KB, `%PDF-`** |

So the endpoint and the generator were always fine. The employee pressed
Download and got a login page.

**The token was not put in the URL to fix it**, although that is what the legacy
payroll routes still do and it would have been one line. `api-client.ts` already
states the reason: *a URL is written to access logs, browser history and Referer
headers, and a harvested Sanctum token is a working credential for the whole
API.* The file is fetched with the header and handed over as a blob instead —
and `downloadMonthlyPayslip` moves the HR path off `?token=` too.

The payslip also now exists **where people read a month's pay**, not only on the
data-entry grid: Payroll Register and Payroll History gained a per-row control,
and Payroll History's hook had to stop discarding the `employee_id` it was
already grouping by. On Monthly Payroll the control was an unlabelled icon that
**disappeared** when the row was unsaved or the session had not resolved; it is
now always rendered and says why it is disabled.

---

## Three things that had never worked for anyone

Found by building the employee path — each broke for HR identically.

### F-210 — Form 16 threw a 500 on every call, for every role

```
SQLSTATE[42S02]: Base table or view not found: 1146
Table 'hp_erp.fees_map_years' doesn't exist
```

`fees_map_years` exists on **neither host**, no migration creates it, and that
line was its only reference in `app/`. Form 16 has never once been produced.

The author had written `?? date('m')` fallbacks, so they expected the row to be
optional — but a missing **table** throws where a missing **row** returns null,
and the fallbacks were unreachable. The table is now checked before it is
queried.

**And the fallback itself was wrong.** `date('m')` is the current calendar
month, so the period would have run from this month of `$year` to this month of
`$year+1` — a twelve-month window that moves every time the page is opened, on
a tax document. April to March is what the surrounding code already assumes
(`$next_year = $year + 1` only makes sense for a year ending in the next one).
Now `01/Apr/2025 → 31/Mar/2026`.

### F-212 — a salary certificate could not cover a year

`hrms_salary_certificate.month` records which months a certificate states, as a
comma-joined list. It was `varchar(20)`. Twelve months joined is **26
characters**:

```
1,2,3,4,5,6,7,8,9,10,11,12
```

Eight months fit. Eleven do not. So the HR screen has always crashed when a user
ticked a full financial year — which is the ordinary case for a bank or a visa.
Widened to 64, and `payroll_type_id` from 50 to 255 for the same fault one
column across. Widening only, so no existing value is touched.

### F-213 — the certificate was written, then the request died

`$request->session()->flash('success', …)` runs unconditionally **after** the
insert. On the web router that is fine. The `api` group has no session
middleware, so `$request->session()` throws `Session store not set on request` —
*after* the certificate had already been filed. The row was correct and the
caller still got a 500: the worst of both, and invisible to anyone testing
through the HR screen.

### F-211 — a password hash in the Form 16 payload

`$res['get_employee_detail'] = DB::table('tbluser')->…->first()` returns **99
columns**, `password` among them, rendered into a browser. No account on either
host currently stores a plaintext password (0 of 2,373 and 0 of 299), so what
shipped was the bcrypt hash — still a credential leaving the database for a
screen that never asked for it, and `plain_password` would have gone the same
way the day anything populated it. Narrowed to the 13 columns the two consumers
actually read.

---

## What an employee can now do

Three new endpoints, and the shape matters more than the list. They are **not**
the HR routes with the gate widened — widening `hrit.role:admin,hr` would let
any employee read any colleague's pay, because those endpoints take an
`employee_id`. **These have no such parameter.** The subject is the token's
owner and there is nothing to name anybody else with.

| | |
|---|---|
| `GET /api/my-hr/pay-breakdown` | every month, with the pay heads behind each figure |
| `GET /api/my-hr/salary-certificate/{year}` | your own certificate, as a PDF |
| `GET /api/my-hr/form-16/{year}` | your own figures |

`employee-payroll-history` was deliberately **not** reused for the breakdown: it
returns the organisation's entire roster in the same response, and handing an
employee the staff directory in order to show them their own payslip is a worse
trade than reading two tables.

**My HR became the hub.** The flat payslip list is replaced by `MyPayBreakdown`
— each month opens to show earnings and deductions by head — and `MyDocuments`
offers the certificate and Form 16 for the years a structure exists for, so no
button is offered that will refuse. Both built from the kit already in the repo.

One deliberate piece of honesty on that screen: when a payslip's components do
**not** add up to what was filed, it says so, in the employee's own terms. Eleven
adjustments on this deployment were filed against a month payroll never matched
(F-173, ₹343,001). The person holding a payslip next to a bank statement is the
most likely to notice, and the screen should not present a reconciled total it
has no grounds to claim.

---

## The detector that reported clean because it could not look

`probe-employee-self-service.sh` is **32 assertions**, and every new detector was
known-bad'd — re-broken on purpose to watch it go red. Four did. **One did not.**

The F-211 check reads the Form 16 payload and asserts no credential column is in
it. With F-210's bug reinstated the endpoint 500s, there is no payload, an
`array_intersect` over nothing returns nothing — and the leak check **passed
while the endpoint was completely broken.**

It now reports `no-payload`, which fails. A detector that says "clean" when it
could not look is the exact failure this suite exists not to have, and it is the
third phase running in which known-badding has caught one.

The probe also had to avoid a second trap. Every new endpoint resolves its
subject from the token and takes no `employee_id`, so "an employee cannot read a
colleague" is true *by construction* — a probe that only ever watched a 403
would prove nothing. So the positive case is **constructed**: a real payslip and
salary structure are seeded in tenant 6, and the same token then asks for
somebody else's data four ways (`employee_id`, `emp_id`, `user_id`,
`sub_institute_id`). All four return the caller's own. A second employee,
independently, gets their own nothing rather than this.

---

## Not done, and said plainly

- **Document templates (Stage B) are not built.** The instruction was to build a
  separate mechanism rather than wire the craft.js one, and that is still the
  right call — `/api/templates` stores drag-and-drop canvas JSON for the Talent
  offer-letter builder, and a payroll document is a letterhead plus fielded
  values. Nothing was started, so nothing is half-built. The salary certificate
  is still assembled as an inline PHP string with no letterhead
  (`get_salary_certificate_html`), which is what a template mechanism would
  replace.
- **The payslip PDF hardcodes one institution's seal.**
  `employeeSalaryPdf.blade.php:107` fetches
  `https://erp.triz.co.in/Images/MMIS_stamp.png` at render time, so every
  tenant's payslip carries MMIS's stamp. Left alone deliberately: replacing a
  seal on a pay document is a decision each organisation has to make, and
  `school_setup` already holds their own `Logo`.
- **Form 16 here is not the statutory Form 16**, and the screen now says so. That
  is issued by the deductor against filed TDS returns; nothing here files
  anything.
- **Tenant 1000019** holds rights on 305 and 309 but not on the module row (5) or
  its container (93), so neither screen reaches its sidebar. It has **0 users**,
  and inventing the missing rows would be guessing at an organisation nobody
  uses. Flagged, not touched.
- **`auditor` and `executive` profiles hold 137 grants** on HRIT editors across
  tenants 5–11. Whether an oversight role should open a payroll editor is a
  rights-policy question, not a frontend one. Out of scope, still true.
- **Ten missing `HRMS.*` Blade views** (`hrms_attendance.index`,
  `hrms_leave_allocation.*` and others) throw `View not found` on any non-API
  hit. The React screens always send `type=API` and are unaffected, so this is
  reachable only by opening those legacy URLs in a browser.

---

## Where the module stands

| | Phase 15 | Now |
|---|---|---|
| Findings closed | 124 | **129** |
| Probe assertions | 411 across 26 | **443 across 27** |
| HRIT screens an employee can reach | 3 | **4** |
| Employee self-service endpoints | 3 | **6** |

Still **AMBER**, and for the same reason as Phase 15: domain sign-off. What
changed is that the module is no longer only an HR console. The three documents
an employee ever asks for — payslip, salary certificate, Form 16 — are now
reachable by the employee, and all three were produced end to end against a real
payslip before this was written.
