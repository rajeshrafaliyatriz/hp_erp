# G2G MODULE INTEGRITY AUDIT — HRIT Management (m5)

**Date:** 2026-09-05
**Auditor:** independent re-audit, module scope
**Repos:** `hp_erp` (Laravel 12) · `g2gv0` (Next.js 16)
**Module:** HRIT Solutions — Attendance Management, Leave Management, Payroll Management (12 sub-modules)
**Findings:** F-87 … F-132 (continues the sequence in `FIX-PLAN-v2.md`; highest prior id was F-86)
**Status:** AUDIT ONLY. No application code was changed. Five probe rows were written to the live
database to prove authorization holes and were removed the same session — see §9.

---

## 1. VERDICT

**RED.**

The single reason: **leave authorization does not exist, and the product ships a screen that
claims it does.** `hrms_leave_role_permissions` states, in tenant 3's own live data, that the
`Employee` role has `approve_leave = 0` and scope `Self`. No leave read or write consults that
table. Signed in as an ordinary employee I applied for my own leave, **approved it myself**,
**withdrew a colleague's request**, **created a leave type**, and finally **rewrote the permission
matrix to grant the Employee role organization-wide approval, configuration and user-management
rights** — five requests, five successes, every one of which had to be a 403. The Roles & Access
tab is a form that writes a row nothing reads.

Payroll is in the same condition by a different route: its only gate is a React component. Every
role — employee, recruiter, reporting manager, auditor — reads the tenant's complete salary
structure over HTTP, and the response includes every employee's **bcrypt password hash**.

Separately, and independently disqualifying: **Monthly Payroll Report cannot load for anyone**,
including administrators. It returns HTTP 500 on every call.

**What is genuinely good, and should not be lost in the above:** tenant isolation *holds*. A
tenant 6 token cannot read tenant 3 data through any HRIT endpoint I tested — `ResolvesApiIdentity`
and `PayrollController::payrollTenantId()` both correctly ignore an attacker-supplied
`sub_institute_id`. Unicode round-trips byte-for-byte. Leave Requests, Payroll Type, Salary
Structure, Payroll Deduction and Salary Certificate are real screens on real endpoints with real
server-side filtering, pagination and CSV export. This module is not a mock-up. It is a competent
frontend on top of an authorization layer that was never built and business rules that were never
written.

---

## 2. TOOLING TRUST GATE

`AUDIT-FINDINGS-v1.md` reported TypeScript errors that were artefacts of its own broken install.
Nothing below was believed until the tool producing it was checked.

| Check | Command | Result |
|---|---|---|
| Frontend typecheck | `cd g2gv0 && npm run typecheck` | **2 errors**, neither in HRIT (`lib/ai/backend/laravel-gateway.ts:81`, `packages/conversational-ai-core/src/conversation.ts:67`) |
| Frontend build | `cd g2gv0 && npm run build` | **clean**, exit 0 |
| Laravel routes | `php artisan route:list` | **1834 routes**, no fatal |
| Laravel serve | `php artisan serve` → `GET /up` | **200** |
| Live DB | `.env`-parsed PDO to `202.47.117.220/hp_erp` | **connected**, MariaDB **10.11.9** |

**Two of my own tools lied during this audit, and both were caught before anything was reported:**

1. `grep -P` is unavailable in this Git Bash (`-P supports only unibyte and UTF-8 locales`), so the
   first RBAC probe run sent **empty tokens** and every endpoint answered 401. Read carelessly that
   is "the module is properly locked down" — the exact opposite of the truth. Rerun with an `awk`
   lookup, the same endpoints answered 200.
2. My PDO probe connection defaulted to **latin1** (the server's `character_set_server` is latin1),
   so Gujarati and emoji read back as `???` and I nearly filed a Unicode-corruption finding. Writing
   the same string through Laravel and re-reading over a `SET NAMES utf8mb4` connection showed the
   bytes were always intact. **There is no Unicode defect.** Recorded because it is the second time
   this repo's audits have produced a false finding from an unverified tool.

**The working tree moved during this audit, and it was checked.** Two other interactive sessions were
editing this repository while the audit ran (`routes/api.php` at 13:26, `config/app.php` at 13:20,
`app/Support/CandidateLink.php` at 13:19 — all after the probes). Their changes are LMS-session and
Talent-assessment work; the only line mentioning attendance is
`POST /lms/sessions/{id}/attendance`, which is LMS session attendance and unrelated to
`hrms_attendances`. **No file this audit examined was modified during it** —
`git status app/Http/Controllers/{Api/Leave,Api/Attendance,Payroll,HRMS} app/Services/Leave
app/Helpers routes/hrms.php database/migrations` returns empty. Every finding below stands against
the tree as read.

**Test tenants.** Tenant **3** is the only tenant on live with an active user for all nine
`role_key`s, and it holds real HRIT data (29 leave requests, 42 attendance rows, 3 leave types,
7 payroll types). It is the audit tenant. Tenant **6** (939 attendance rows, 22 users) is the
second tenant, used for isolation tests.

```
select p.role_key, min(u.id) from tbluser u
  join tbluserprofilemaster p on p.id = u.user_profile_id
 where u.status = 1 and u.sub_institute_id = 3 and p.role_key <> '' group by p.role_key;
-- administrator 6 | auditor 590 | department_head 580 | employee 7 | executive 589
-- hr_executive 579 | hr_manager 67 | recruiter 588 | reporting_manager 581
```

---

## 3. SCORECARD

| # | Check | Verdict | One-line reason |
|---|---|---|---|
| — | **Front door** | 🟠 AMBER | 11 of 12 sub-modules are reachable; leave *entitlement* has no screen anywhere |
| — | **Lifecycle** | 🔴 RED | 6 NOT-WIRED, 4 FE-MISSING, 3 DEAD-DATA, 1 BE-MISSING across the module |
| — | **Role journeys** | 🔴 RED | The menu is the only gate; 5 of 9 roles do not exist in the frontend's role model |
| — | **External / self-service actors** | 🔴 RED | An employee cannot see their own payslip, salary certificate or real leave balance |
| 1 | Data source | 🔴 RED | Attendance Tracking renders a fixture leave balance and a fixture holiday for every tenant |
| 2 | API integrity | 🔴 RED | `GET /monthly-payroll/create` returns 500 for every role |
| 3 | CRUD completeness | 🟠 AMBER | Leave and payroll CRUD are complete; attendance has no update path from the UI |
| 4 | Validation (4 layers) | 🔴 RED | Shape validation only; no business rule reaches the API; one rule contradicts its own column |
| 5 | Business rules | 🔴 RED | No balance, overlap, holiday, weekly-off or closed-period rule exists |
| 6 | Data integrity | 🔴 RED | `leave_type_id` foreign key points at `tbluser`; 15 of 29 rows are mis-typed as a result |
| 7 | Error handling | 🟠 AMBER | Good banners on the newer screens; raw SQL exceptions leak from the API |
| 8 | Real data and scale | 🟠 AMBER | Unicode verified correct; no pagination on several payroll reads |
| 9 | **RBAC + tenant isolation** | 🔴 RED / 🟢 GREEN | RBAC absent (5 holes executed). **Tenant isolation holds** — tested and passed |
| 10 | Integration / data flow | 🔴 RED | Approved leave does not reach attendance; attendance reaches payroll through a 500 |
| 11 | Workflow integrity | 🟡 AMBER | **Sprint 6:** `hrms_leave_workflow_settings` now drives a real approval chain (F-124). Amber, not green, until notifications land |
| 12 | Calculation integrity | 🔴 RED | A Saturday–Sunday leave is charged as 2 days; attendance % has two different formulas |
| 13 | Audit trail | 🟠 AMBER | `created_by`/`updated_by` are stamped from identity, but no before/after and no reason |
| 14 | UX / operational readiness | 🟠 AMBER | Strong loading/empty/error states; ~12 controls do nothing when clicked |
| 15 | Production readiness | 🔴 RED | A primary screen 500s; password hashes are served to every caller |

---

## 4. PART A — THE FRONT DOOR

| Sub-module | First screen | Who starts it | In their nav? | First entity | Endpoint |
|---|---|---|---|---|---|
| Attendance Tracking | `attendance-tracking/page.tsx` | `employee` | yes | `hrms_attendances` row | `POST /api/attendance/punch-in` |
| Attendance Reports | `attendance-reports/page.tsx` | `hr_manager`, `administrator` | yes | none — read only | `GET /api/attendance/kpi` |
| Leave Dashboard | `leave-dashboard/page.tsx` | all | yes | none — read only | `GET /api/leave/dashboard` |
| Leave Requests | `leave-requests/page.tsx` | `employee` | yes | `hrms_emp_leaves` row | `POST /api/leave/requests` |
| Leave Reports | `leave-reports/page.tsx` | `hr_manager` | yes | none | `GET /api/leave/reports/summary` |
| Leave Configuration | `leave-configuration/page.tsx` | `hr_manager` | yes | `hrms_leave_types` row | `POST /api/leave/leave-types` |
| **Leave entitlement** | **none** | **`hr_manager`** | **no** | **`hrms_leave_allocation`** | **none** |
| Payroll Type | `payroll-type/page.tsx` | `hr_manager` | yes | `payroll_types` row | `POST /payroll-type/store` |
| Salary Structure | `salary-structure/page.tsx` | `hr_manager` | yes | `employee_salary_structures` | `POST /employee-salary-structure/store` |
| Payroll Deduction | `payroll-deduction/page.tsx` | `hr_manager` | yes | `hrms_emp_payroll_deduction` | `POST /payroll-deduction/store` |
| Monthly Payroll Report | `monthly-payroll/page.tsx` | `hr_manager` | yes | `employee_monthly_salary_data` | `GET /monthly-payroll/create` → **500** |
| Salary Certificate | `salary-certificate/page.tsx` | `hr_manager` | yes | `hrms_salary_certificate` | `POST /hrms-salary-certificate-report` |
| Form 16 | `form-16/page.tsx` | `hr_manager` | yes | none — read only | `POST /form16-report` |

**The way in that nobody built.** Leave *entitlement* is the number every balance, every
"remaining", and every over-application check depends on. It lives in `hrms_leave_allocation`.
The only writer in the entire codebase is a private helper, `LeaveTypeApiController::syncAllocation()`
([LeaveTypeApiController.php:265](../../app/Http/Controllers/Api/Leave/LeaveTypeApiController.php#L265)),
which fires as a side effect of saving a leave type. There is **no screen to grant an employee or a
department an entitlement**, and on live the table holds **one row for the whole platform**
(`{"id":1,"value":12,"leave_type_id":10,"sub_institute_id":1}`). Tenants 3, 6 and 7 have none.

Consequence, measured on live: `GET /api/leave/balances?employee_id=6` for tenant 3 returns
`overall: {total: 0, used: 7, remaining: 0}` — an employee who has taken seven days against an
entitlement of zero, with the product still reporting zero remaining and still accepting new
applications. **This alone makes Leave Management RED regardless of the security findings.**

---

## 5. PART B — THE 360° LIFECYCLE

### 5.1 Attendance

| # | Stage | Who | FRONTEND | BACKEND | DATABASE | WIRED? | Handoff | Break |
|---|---|---|---|---|---|---|---|---|
| 1 | Punch in | employee | `attendance-tracking/page.tsx:344` | `AttendanceTrackingApiController@punchIn` | `hrms_attendances` — 994 rows | yes | → punch out | — |
| 2 | Punch out | employee | same button, `action='out'` | `@punchOut` | same | yes | → day summary | — |
| 3 | See my month | employee | `attendance-calendar-drawer.tsx` | `@myAttendance` | same | yes | — | — |
| 4 | Leave balance on that screen | employee | `employee-snapshot-widget` | `/api/leave/balances` **exists** | `hrms_leave_allocation` — 1 row | **no** | — | **NOT-WIRED** |
| 5 | Upcoming holidays on that screen | employee | `upcoming-events-widget` | `/api/leave/holidays/upcoming` **exists** | `hrms_holidays` — 18 rows | **no** | — | **NOT-WIRED** |
| 6 | Attendance alerts | employee | `attendance-alerts-widget` | none | none | **no** | — | **NONE-BUILT** |
| 7 | My requests tile | employee | `my-requests-widget` | none | none | **no** | — | **NONE-BUILT** |
| 8 | Regularise a wrong day | employee | dead button (`onClick: () => {}`) | `POST update_user_att` **exists** | `hrms_attendances` | **no** | → approval | **FE-MISSING** |
| 9 | Approve a regularisation | reporting_manager | none | none | none | — | — | **NONE-BUILT** |
| 10 | Shift / expected hours | administrator | none in m5 | `shiftMasterController` | `tbluser_shift_master` — **table absent** | **no** | → late/early calc | **DB-MISSING** |
| 11 | Department report | hr_manager | `attendance-reports/page.tsx` | `departmentAttendanceReportCreate` | `hrms_attendances` | yes | → payroll days | — |
| 12 | Export the report | hr_manager | `console.log('Export clicked')` | none | — | **no** | — | **BE-MISSING** |
| 13 | Saved report presets | hr_manager | static array, `console.log` | none | none | **no** | — | **NONE-BUILT** |
| 14 | Attendance → payroll days | hr_manager | Monthly Payroll screen | `getTotalDays()` | `hrms_attendances` | **500** | → payslip | **NOT-WIRED** |

### 5.2 Leave

| # | Stage | Who | FRONTEND | BACKEND | DATABASE | WIRED? | Handoff | Break |
|---|---|---|---|---|---|---|---|---|
| 1 | Configure leave types | hr_manager | `LeaveTypesTab.tsx` | `LeaveTypeApiController` | `hrms_leave_types` — 10 rows | yes | → entitlement | — |
| 2 | **Grant entitlement** | hr_manager | **none** | side effect only | `hrms_leave_allocation` — **1 row** | **no** | → balance | **FE-MISSING** |
| 3 | Configure holidays | hr_manager | `HolidayCalendarTab.tsx` | `HolidayApiController` | `hrms_holidays` — 18 rows | yes | → day count | **NOT-WIRED** (day count ignores them) |
| 4 | Configure weekly-off | hr_manager | `HolidayCalendarTab.tsx` | `@storeWeekdays` | `hrms_weekdays` — 21 rows | yes | → day count | **NOT-WIRED** (same) |
| 5 | Configure approval workflow | hr_manager | `ApprovalWorkflowTab.tsx` | `LeaveWorkflowApiController` | `hrms_leave_workflow_settings` — 3 rows | **yes (Sprint 6)** | → `hrms_leave_approval_steps` → approval | ✅ **WIRED** (F-124) |
| 6 | Configure role permissions | hr_manager | `RolesAccessTab.tsx` | `@saveRoles` | `hrms_leave_role_permissions` — 21 rows | writes only | → every decision | **NOT-WIRED** |
| 7 | Apply for leave | employee | `LeaveApplyDrawer.tsx` | `@store` | `hrms_emp_leaves` — 37 rows | yes | → approver | — |
| 8 | Approve / reject | approver | `LeaveRequestDetailsDrawer` | `@decision` | same | yes, **ungated** | → balance | **NOT-WIRED** to §6 |
| 9 | Withdraw | applicant | none (API only) | `@destroy` | same | **ungated** | — | **FE-MISSING** |
| 10 | Cancel after approval | employee | none | none | status enum has `cancelled` | **no** | — | **NONE-BUILT** |
| 11 | Balance decrement | system | — | derived, not stored | `hrms_leave_allocation` | n/a | → payroll LWP | **DEAD-DATA** |
| 12 | Leave → attendance calendar | system | calendar shows `leave` | `@myAttendance` reads leaves | both | yes | — | — |
| 13 | Leave → payroll (LWP) | system | Monthly Payroll | `monthlyPayrollReport` LWP loop | both | **500** | → payslip | **NOT-WIRED** |

### 5.3 Payroll

| # | Stage | Who | FRONTEND | BACKEND | DATABASE | WIRED? | Handoff | Break |
|---|---|---|---|---|---|---|---|---|
| 1 | Define pay heads | hr_manager | `payroll-type/page.tsx` | `@payrollType/@payrollStore` | `payroll_types` — 13 rows | yes | → structure | — |
| 2 | Set salary structure | hr_manager | `salary-structure/page.tsx` | `@employeeSalaryStructure` | `employee_salary_structures` — 8 rows | yes | → monthly | — |
| 3 | Roll over to next year | hr_manager | rollover dialog | `@rolloverEmployeeSalaryStructure` | same | yes | — | — |
| 4 | Monthly deductions | hr_manager | `payroll-deduction/page.tsx` | `@payrollDeduction*` | `hrms_emp_payroll_deduction` — 12 rows | yes | → monthly | — |
| 5 | **Run monthly payroll** | hr_manager | `monthly-payroll/page.tsx` | `@monthlyPayrollCreate` | `employee_monthly_salary_data` — 22 rows | **500** | → payslip | **NOT-WIRED** |
| 6 | Save the run | hr_manager | Save button | `@monthlyPayrollStore` | same | yes, **no upsert** | → PDF | duplicates |
| 7 | Lock / finalise a month | hr_manager | none | none | no column | **no** | — | **NONE-BUILT** |
| 8 | Payslip PDF | hr_manager | `monthlyPayslipPdfUrl` | `@monthlyPayrollPdf` | staff documents | yes | → employee | — |
| 9 | **Employee sees own payslip** | employee | **none** | route exists | — | **no** | — | **FE-MISSING** |
| 10 | Salary certificate | hr_manager | `salary-certificate/page.tsx` | `@hrmsSalaryCertificateReport` | `hrms_salary_certificate` — **0 rows** | yes | → PDF | **DEAD-DATA** |
| 11 | Form 16 | hr_manager | `form-16/page.tsx` | `@form16Report` | derived | yes | — | — |
| 12 | Statutory remittance | hr_manager | none | none | none | — | — | **NONE-BUILT** |

### 5.4 Break-type distribution

| Break | Count | Where |
|---|---|---|
| **NOT-WIRED** | **6** | leave balance widget, holidays widget, workflow settings, role permissions, holiday/weekly-off in day count, attendance→payroll |
| **FE-MISSING** | 4 | leave entitlement, leave withdrawal, attendance regularisation, employee payslip |
| **DEAD-DATA** | 3 | `hrms_leave_allocation` (1 row), `hrms_salary_certificate` (0 rows), `hrms_in_out_times` (0 rows) |
| **NONE-BUILT** | 5 | attendance alerts, my-requests tile, regularisation approval, cancel-after-approval, payroll lock |
| **BE-MISSING** | 1 | attendance report export |
| **DB-MISSING** | 1 | `tbluser_shift_master` — controllers exist, table does not |

Six NOT-WIRED is the shape of this module's trouble: **the layers are all built and not joined.**
Every one of those six demos perfectly.

### 5.5 Status strings — UI vs backend, character for character

| Written by | Value | Accepted by backend? |
|---|---|---|
| `leave-requests/page.tsx:53` | `'sent-back'` (hyphen) | **no** — the enum is `sent_back` (underscore). The page maps it at :199 on export only; the badge label lookup at :274 falls through to the raw string |
| `LeaveOptionsController:81` | `sent_back`, `cancelled`, `approved_lwp` | yes |
| `leave-mappers.ts` | maps `sent_back` → `'sent-back'` for display | — |
| Attendance FE | `'half-day'` | API returns `half_day` in `attendance_status` for some rows; `mapAttendanceEntry` silently coerces anything unrecognised to **`'present'`** ([use-attendance.ts:74](../../../g2gv0/hooks/use-attendance.ts#L74)) |

The `sent-back`/`sent_back` split is handled, but only in two of the three places it is used. The
attendance coercion is the dangerous one: an absence with an unexpected status label renders as
**Present**.

---

## 6. PART C — ROLE JOURNEYS

The frontend recognises **four** roles. The platform defines **nine**. The mapping is
substring matching on a profile's *display name*:

```ts
// g2gv0/lib/laravel-session.ts:80-96
if (name.includes('admin')) return 'admin'
if (name === 'hr' || name.includes('human resource') || name.includes('hr ')) return 'hr'
if (name.includes('department head') || name.includes('dept head') ||
    name.includes('hod') || name.includes('manager') || name.includes('supervisor')) return 'dept-head'
return 'employee'
```

| `role_key` | Maps to | Can reach HRIT? | What they can actually do (proven at the API) | Gap |
|---|---|---|---|---|
| `employee` | `employee` | Attendance, Leave | **Everything.** Approved own leave, deleted a colleague's, created a leave type, granted itself org-wide rights, read the full salary table | RBAC absent |
| `reporting_manager` | **`dept-head`** | +Reports | Full payroll read (200, with password hashes). No team scoping anywhere | over-granted by `includes('manager')` |
| `department_head` | `dept-head` | +Reports | Full payroll read (200). No department scoping | no scoping |
| `hr_executive` | `hr` | all | Everything HR can | scope column says `Department`, unenforced |
| `hr_manager` | `hr` | all | Everything | correct by accident |
| `administrator` | `admin` | all | Everything | correct |
| `executive` | **`employee`** | Attendance, Leave only | Cannot see the reports the role exists for | under-granted |
| `auditor` | **`employee`** | Attendance, Leave only | Yet reads the whole salary table over HTTP (200) | menu says no, API says yes |
| `recruiter` | **`employee`** | Attendance, Leave only | Also reads the whole salary table (200) | same |

**Menu vs API — the probe.** `Docs/hrit-audit/_evidence/probe-reads2.sh`, section H:

```
department_head   GET /employee-salary-structure   200  {"employees":[{"id":122,...,"password":"$2y$12$iq4EY...
reporting_manager GET /employee-salary-structure   200  {"employees":[{"id":122,...,"password":"$2y$12$iq4EY...
recruiter         GET /employee-salary-structure   200  {"employees":[{"id":122,...,"password":"$2y$12$iq4EY...
```

Three roles the menu hides payroll from read the tenant's entire salary table, with password
hashes, on the first try. **Hiding a screen is not access control**, and this module has nothing
else.

**Empty-handed start.** Signed in as `employee` in tenant 3 with no leave applied: Leave Requests
shows a correct empty state with guidance. Attendance Tracking shows a **fixture** — "Casual 12,
Earned 7, Sick 0" and "Independence Day, 15 Aug 2026" — for an employee who has neither. That is
worse than a blank screen: it is a confident wrong answer.

**Dead ends.** `employee` can apply for leave and then cannot withdraw it (no UI). `reporting_manager`
has no queue of their team's requests — the list is org-wide or nothing. `auditor` has no read-only
surface at all despite being a defined role.

---

## 7. PART D — EXTERNAL AND SELF-SERVICE ACTORS

| Actor | Surface | Can they complete what the module needs? |
|---|---|---|
| **Employee using self-service** | Attendance Tracking + Leave Requests, both inside the authenticated shell | **Partly.** Can punch and apply. **Cannot** see a real leave balance (fixture), withdraw a request, regularise a wrong punch, download their own payslip, or request a salary certificate |
| **Employee wanting their payslip** | **none** | No. `monthlyPayslipPdfUrl()` is called only from `monthly-payroll/page.tsx`, behind the HR gate. There is no employee-facing route |
| **Employee wanting a salary certificate** | **none** | No. Generation is HR-only; `hrms_salary_certificate` has 0 rows on live |
| **New joiner before payroll exists** | Attendance only | Their salary structure must be created by HR first; nothing prompts anyone that it is missing. `getMonthlyData` answers `{"status_code":0,"message":"Salary Structure Not Found !!"}` and no screen surfaces it |
| **Leaver / ex-employee** | none | No final-settlement path in m5 at all |

In plain words: **HRIT has no self-service half.** Everything an employee is supposed to *receive*
from this module — a balance, a payslip, a certificate, a corrected attendance day — is either
HR-only or fabricated on their screen.

---

## 8. PART E — THE INTEGRITY CHECKLIST

### E.0 — The database layer

Counts taken 2026-09-05 with `COUNT(*)` against `202.47.117.220/hp_erp`, the host named by `.env`.

| Table | Migration | On live | Rows | Tenant col | Written by | Read by |
|---|---|---|---|---|---|---|
| `hrms_attendances` | yes | yes | **994** | `sub_institute_id` | `HrmsController`, `AttendanceTrackingApiController` | 5 controllers + `PayrollController` |
| `hrms_emp_leaves` | yes | yes | **37** | yes | `LeaveRequestApiController` | `LeaveAnalyticsService`, payroll LWP |
| `hrms_leave_types` | **no CREATE** | yes | **10** | yes | `LeaveTypeApiController` | analytics, reports |
| `hrms_leave_allocation` | yes | yes | **1** | yes | `syncAllocation()` only | `entitlementByType()` |
| `hrms_holidays` | yes | yes | **18** | yes | `HolidayApiController` | dashboard, `getTotalDays` |
| `hrms_weekdays` | yes | yes | **21** | yes | `HolidayApiController` | `WorkforceTrendService` |
| `hrms_leave_role_permissions` | yes | yes | **21** | yes | `LeaveWorkflowApiController` | **its own screen only** |
| `hrms_leave_workflow_settings` | yes | yes | **3** | yes | `LeaveWorkflowApiController` | **its own screen only** |
| `payroll_types` | yes | yes | **13** | yes | `PayrollController` | payroll screens |
| `employee_salary_structures` | yes ×2 | yes | **8** | yes | `PayrollController` | monthly, Form 16 |
| `employee_monthly_salary_data` | yes | yes | **22** | yes | `PayrollController` | payslip PDF |
| `hrms_emp_payroll_deduction` | yes | yes | **12** | yes | `PayrollController` | monthly calc |
| `hrms_salary_certificate` | yes | yes | **0** | yes | `PayrollController:789` | `:765`, `:840` |
| `hrms_in_out_times` | no | yes | **0** | — | `HrmsInOutTime` model | nothing |
| `hrms_job_titles` | no | yes | **0** | — | `HrmsController` | `LeaveAnalyticsService:248` |
| `tbluser_shift_master` | no | **NO** | — | — | `shiftMasterController` | `bulkUserShiftUpdateController` |
| `tbluser_shift_records` | no | **NO** | — | — | `userShiftRecord` model | — |

**Written but never read:** `hrms_leave_role_permissions` and `hrms_leave_workflow_settings`. Both
are complete, populated, tenant-scoped tables whose only consumer is the form that wrote them.

**Read but never written:** `hrms_job_titles` — joined by `LeaveAnalyticsService:248` to supply the
`designation` on every leave row, and empty on live, so every leave request in the product shows a
blank designation.

**Zero rows on live — unused, or a failing write path?** `hrms_salary_certificate` has a working
write path (`insert` at `PayrollController:789`, no swallowed exception around it) that has simply
never been exercised: the screen requires a department, an employee, a year, months and pay heads,
and tenant 3 has 2 salary structures for 122 employees. **Unused, not broken** — but unusable at
scale until structures exist. `hrms_in_out_times` is genuinely dead: no migration, no reader.

**Every root table carries `sub_institute_id`.** Verified for all 13 live HRIT tables. Child access
always joins a parent that has it.

**Two generations of the same idea, living side by side:**

| Concept | Older | Newer | Both still routed? |
|---|---|---|---|
| Attendance report | `HrmsController@hrmsAttendanceReport` (web) | `AttendanceReportApiController` (api) | **yes** |
| Leave dashboard | `HrmsLeaveController@getLeaveDashboard` (**sample data**) | `LeaveDashboardController` (real) | **yes** |
| Leave types | `leave/LeaveTypeController` | `Api/Leave/LeaveTypeApiController` | **yes** |
| Holidays | `leave/HolidayController` | `Api/Leave/HolidayApiController` | **yes** |
| Leave distribution | `HRITDashboard/LeaveDistribution` | `Leave/LeaveDistributionApiController` | **yes**, both registered ([api.php:815](../../routes/api.php#L815), [:872](../../routes/api.php#L872)) |

Five duplicated pairs. The old generation is still reachable and, in the leave-dashboard case,
still returns invented numbers.

**Columns the API sends that the table cannot hold:** `leave_type` is validated at `max:191` and
declared `varchar(30)`. With `STRICT_TRANS_TABLES` on live this is a 500, not a truncation — see F-106.

### E.1 — Validation matrix

Every row proven by calling the endpoint directly with `curl`; the browser was never involved.
Full transcript: `_evidence/probe-validation.out`.

| Field / rule | Browser | API | Business rule | DB constraint | Proof |
|---|---|---|---|---|---|
| `to_date >= from_date` | ✓ | ✓ **422** | ✓ | — | `"The to date field must be a date after or equal to from date."` |
| `comment` required | ✓ | ✓ **422** | ✓ | — | `"A reason is required."` |
| `leave_type` not empty | ✓ | ✓ **422** | ✓ | — | `"The leave type field is required."` |
| `leave_type` length | ✓ (none) | ✗ `max:191` | ✗ | `varchar(30)` | 40 chars → **500 SQLSTATE[22001]** with the DB host in the body |
| `leave_type_id` belongs to my tenant | ✗ | ✗ | ✗ | FK points at `tbluser` | tenant 3 employee applied with tenant 6's type id 9 → **201** |
| Leave within balance | ✗ | ✗ | ✗ | ✗ | 365-day request → **201** |
| Leave not in a closed period | ✗ | ✗ | ✗ | ✗ | leave dated **1990-01-01** → **201** |
| No overlapping request | ✗ | ✗ | ✗ | ✗ | no check exists in `store()` |
| Day count excludes holidays / weekly-off | ✗ | ✗ | ✗ | — | see E.3 |
| Approver is entitled to approve | ✗ | ✗ | ✗ | — | see F-87 |
| Unicode (emoji, Gujarati) | ✓ | ✓ | ✓ | `utf8mb4` | sent `e0aab0…f09f8e89`, stored `E0AAB0…F09F8E89` — **identical** |

### E.2 — Tenant isolation (P0) — **PASS**

This is the one area the module gets right, and it is worth stating clearly because the platform's
history is the opposite.

| Probe | Expected | Actual |
|---|---|---|
| Tenant 6 employee token + `sub_institute_id=3` on `/api/leave/requests` | own tenant only | **200, `data: []`** — tenant 6 has no 2026 leave. Tenant 3's rows were not returned |
| Tenant 6 employee token + `sub_institute_id=3` on `/payroll-type` | own tenant only | **200**, every row `"sub_institute_id":6` |
| Tenant 6 employee token + `sub_institute_id=3` on `/employee-salary-structure` | own tenant only | **200**, tenant 6 employees only |

The mechanism is correct by construction: `ResolvesApiIdentity` takes the tenant from the token's
owner and *ignores* a differing request value ([ResolvesApiIdentity.php:58-78](../../app/Http/Controllers/Api/Concerns/ResolvesApiIdentity.php#L58-L78)),
and `PayrollController::payrollTenantId()` orders token → session → **never the request body**
([PayrollController.php:62-71](../../app/Http/Controllers/Payroll/PayrollController.php#L62-L71)).

*Not tested:* fetching a specific record of tenant B by id (`/api/leave/requests/{id}`) — the ids I
had for tenant 6 fell outside tenant 3's leave-year window, so a 404 would not have distinguished
"correctly refused" from "out of range". **Open question Q3.**

**Attribution.** A caller cannot pass `user_id` and have a write recorded as someone else:
`payrollActorId()` and `leaveContext()` both take the actor from the token. Verified by reading
both traits; the request's `user_id` never reaches `created_by`.

**Routes with no auth middleware:** none in HRIT. `routes/hrms.php` is registered inside
`Route::middleware(['web','auth','session','menu'])` in
[bootstrap/app.php:31](../../bootstrap/app.php#L31), which covers the two file-level routes at the
bottom of `hrms.php` as well. *(I initially read those two as unauthenticated. They are not —
`GET /hrms/myleave/7` with no token returns 401. Corrected before reporting; the invented-data
defect at that route is real and is F-100.)*

### E.3 — Calculation audit

Expected values were computed by hand from tenant 3's **own configuration** before the system was
asked. Tenant 3's weekly pattern (`hrms_weekdays`): Mon–Fri `full`, **Saturday `half`**,
**Sunday `weekend`**.

| Input | Expected (hand) | System | Verdict |
|---|---|---|---|
| Leave #20, 2026-04-04 (Sat) → 2026-04-05 (Sun) | Sat 0.5 + Sun 0 = **0.5 days** | **2 days** | **FAIL — 4×** |
| Leave #21, 2026-06-12 (Fri) → 2026-06-13 (Sat) | 1 + 0.5 = **1.5 days** | **2 days** | **FAIL** |
| Leave #19, 2026-03-05 → 2026-03-18 | 10 weekdays + 2 Saturdays × 0.5 = **11 days** | **14 days** | **FAIL** |
| Attendance %, user 63, Aug 2026 | 0 punches / 21 working days = **0%** | `percentege: 0` | PASS |
| Balance, user 6 tenant 3 | entitlement unknown, used 7 | `total 0, used 7, remaining 0` | **FAIL** — remaining cannot be 0 when total is 0 and used is 7 |

**Root cause of the day-count failures.** `LeaveAnalyticsService::requestDays()` calls
`countDays($from, $to, $dayType ?: 1, '')` — passing **empty string** as `$skipday`
([LeaveAnalyticsService.php:262](../../app/Services/Leave/LeaveAnalyticsService.php#L262)). That
selects the `else` branch of the helper:

```php
// app/Helpers/helpers.php:952-960
else {
    if($dayType!=''){
        $mainDays = $fromDate->diffInDays($toDate) + 1;
        $daysCount = ($mainDays*$dayType);       // <- every calendar day, times the day type
    }
```

Calendar days × day type. The helper has a `skip_sunday` mode and it is never requested; even that
mode would only handle Sundays, not the tenant's configured Saturday half-day and not the 18
holidays the product lets HR maintain.

**Duplicated formula — attendance percentage, two different answers:**

| Where | Formula |
|---|---|
| Backend `AttendanceTrackingApiController:222` | `(presentDays + lateDays) / workingDays × 100` |
| Frontend `attendance-tracking/page.tsx:139-144` | `present / (present + late + leave + absent) × 100` |

With present 15, late 3, leave 2, absent 1 and 21 working days: backend **85.7%**, frontend
**71.4%**. The screen displays the frontend's number and discards the API's `percentege`.

**Tenant id baked into a formula:**

```php
// app/Http/Controllers/Payroll/PayrollController.php:419
if($amount_type==1 && $Per_Flat!=0 && $value[1] > $Per_Flat && $sub_institute_id==47){
```

A salary-structure branch that runs for exactly one organisation. Tenant 47 does not appear in the
live `school_setup` data I sampled, so this condition is currently unreachable — which makes it
worse, not better: nobody can tell what it was protecting.

**Money and rounding.** `round(..., 2)` throughout; no integer truncation found on currency.

---

## 9. FINDINGS REGISTER

> **Probe safety note.** F-87 … F-91 were proven by executing them against the live database. Every
> row touched was captured beforehand (`_evidence/before-219.json`), reverted in the same session by
> `_local-backups/REVERSAL-2026-09-05-hrit-audit-probes.sql`, and the restoration verified: tenant 3
> is back to **29 live leave requests and 3 leave types**, request #219 is `pending` with
> `deleted_at = NULL`, and permission row #8 is back to `scope='Self', approve_leave=0`.

#### F-87 — Any employee can approve their own leave — CRITICAL
**What:** `POST /api/leave/requests/{id}/decision` verifies the tenant and the row's existence, never the approver.
**Where:** [LeaveRequestApiController.php:237-280](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L237)
**Evidence:** the method checks only that the row exists in the caller's tenant:
```php
$exists = DB::table('hrms_emp_leaves')->where('id', $id)
    ->where('sub_institute_id', $context['sub_institute_id'])
    ->whereNull('deleted_at')->exists();
if (!$exists) { return response()->json([...], 404); }
$updated = $this->applyDecision([$id], $request->input('status'), ...);
```
Executed as tenant 3 `employee` (user 7), whose own row in `hrms_leave_role_permissions` reads
`approve_leave = 0, scope = 'Self'`:
```
POST /api/leave/requests    -> 201 {"data":{"id":223}}          (applied for own leave)
POST /api/leave/requests/223/decision {"status":"approved"}
                            -> 200 {"message":"Leave request updated successfully","updated_count":1}
```
**Impact:** every leave balance, every payroll LWP figure and every absence record in the product is self-certifiable. There is no approval in Leave Management; there is a status field anyone can set.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-writes.sh` (section W2).
**Fix sketch:** resolve the caller's `role_key` → `hrms_leave_role_permissions.approve_leave` and scope, and refuse when the subject is outside it — including refusing self-approval outright.
**Status:** ✅ **CLOSED in Sprint 1** — approve_leave + scope + self-approval refused, all three checked in decision(). Re-verified: 200 -> **403**.

#### F-88 — Any employee can withdraw another employee's leave request — CRITICAL
**What:** `destroy()` checks tenant and status, never ownership.
**Where:** [LeaveRequestApiController.php:344-375](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L344)
**Evidence:** the comment says "Withdrawal by the applicant" and the code never identifies the applicant:
```php
$leave = DB::table('hrms_emp_leaves')->where('id', $id)
    ->where('sub_institute_id', $context['sub_institute_id'])->whereNull('deleted_at')->first();
if ($leave->status !== 'pending') { return ... 422; }
DB::table('hrms_emp_leaves')->where('id', $id)->update(['deleted_at' => now(), ...]);
```
Executed: user 7 deleted request **#219**, which belongs to user 54 → `200 {"message":"Leave request withdrawn successfully"}`. Restored.
**Impact:** any employee can silently cancel any colleague's pending leave. The victim's request disappears from every list with no notification.
**Re-verify:** `probe-writes.sh` (W3).
**Fix sketch:** `where('user_id', $context['user_id'])` unless the caller holds approval rights over the subject.
**Status:** ✅ **CLOSED in Sprint 1** — withdrawal requires ownership. Re-verified: 200 -> **403**.

#### F-89 — Any employee can grant themselves organization-wide leave rights — CRITICAL
**What:** `PUT /api/leave/roles` rewrites the permission matrix with no role check.
**Where:** [LeaveWorkflowApiController.php:125-199](../../app/Http/Controllers/Api/Leave/LeaveWorkflowApiController.php#L125)
**Evidence:** the only guard is `leaveContext()` (identity, not authority); the validator at :137-141 checks the *shape* of the permissions being granted, not the right to grant them. Executed as tenant 3 `employee`:
```
PUT /api/leave/roles  {"roles":[{"id":8,"role_name":"Employee","scope":"Organization",
                                 "approve_leave":true,...,"user_management":true}]}
-> 200 {"message":"Role permissions saved successfully",
        "data":[{"id":8,"role_name":"Employee","scope":"Organization","approve_leave":true,...
```
**Impact:** the product's own access-control screen is writable by the least-privileged role. Even after F-87 and F-88 are fixed by reading this table, this endpoint hands the table to the attacker.
**Re-verify:** `probe-writes.sh` (W5). **Fix this one first** — it is the escalation path for the fixes to the other two.
**Fix sketch:** `configure_settings` required, resolved from the caller's own role, evaluated before the write.
**Status:** ✅ **CLOSED in Sprint 1** — saveRoles/saveWorkflow require configure_settings. Re-verified: 200 -> **403**.

#### F-90 — Any employee can create and delete leave types and holidays — CRITICAL
**What:** every write on `LeaveTypeApiController`, `HolidayApiController` and `LeaveWorkflowApiController` is ungated. The route group carries no middleware.
**Where:** [routes/api.php:826](../../routes/api.php#L826) `Route::prefix('leave')->group(...)` — no `middleware()`; controllers have no role branch (`grep -n "role_key\|403" app/Http/Controllers/Api/Leave/LeaveTypeApiController.php` → no hits).
**Evidence:** `POST /api/leave/leave-types` as tenant 3 `employee` → `201 {"message":"Leave type added successfully","data":{"id":11}}`. Deleted afterwards.
**Impact:** an employee can delete the leave types every historical request references, or add one with an arbitrary entitlement. Deleting a type does not repair the requests pointing at it — see F-94 for what that already looks like.
**Re-verify:** `probe-writes.sh` (W4).
**Fix sketch:** `->middleware('profile:admin,hr')` on the configuration sub-group, plus the same `configure_settings` check as F-89.
**Status:** ✅ **CLOSED in Sprint 1** — every write in the three configuration controllers requires configure_settings. Re-verified: 201 -> **403**.

#### F-91 — Payroll's only access gate is a React component — CRITICAL
**What:** the Laravel payroll routes carry `['auth','session','menu']` and no role gate; `MenuMiddleware` returns early for `type=API`; the whole control is client-side.
**Where:** [routes/hrms.php:41](../../routes/hrms.php#L41), [MenuMiddleware.php:30-33](../../app/Http/Middleware/MenuMiddleware.php#L30-L33), [payroll-shell.tsx:28](../../../g2gv0/components/domain/hrms/hrit/payroll-management/shared/payroll-shell.tsx#L28)
**Evidence:**
```php
// MenuMiddleware.php:30
if ($type == "API"  ||  $request->get('type') == "JSON") { return $next($request); }
```
```tsx
// payroll-shell.tsx:28 — the entire payroll authorization model
if (!isLoading && (!user || !['admin', 'hr'].includes(user.role))) { return <AccessRestricted/> }
```
Executed with an `employee` token: `GET /employee-salary-structure` → **200**, full tenant salary table. Same for `department_head`, `reporting_manager`, `recruiter`, `auditor`.
**Impact:** every salary in the organisation is readable by everyone in it. The write routes (`/employee-salary-structure/store`, `/payroll-type/store`, `/payroll-deduction/store`) sit behind the same non-gate, so an employee can also *set* salaries.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-reads.sh` (section B), `probe-reads2.sh` (section H).
**Fix sketch:** `->middleware('profile:admin,hr')` on the payroll group in `routes/hrms.php`; keep `payroll-shell.tsx` as presentation only.
**Status:** ✅ **CLOSED in Sprint 1** — `hrit.role:admin,hr` on both payroll route groups. Re-verified: employee, auditor, recruiter, department_head and reporting_manager all **403**; admin and HR still **200**.

#### F-92 — Payroll endpoints return every employee's password hash — CRITICAL
**What:** `employeeDetails()` selects `tbluser.*`, and two payroll endpoints return it unfiltered.
**Where:** [PayrollController.php:322-325](../../app/Http/Controllers/Payroll/PayrollController.php#L322) (`employeeSalaryStructure`), `payrollDeduction` (`all_emp`)
**Evidence:** live response body, any authenticated role:
```json
{"employees":[{"id":122,"user_name":"aarna.kadakia",
  "password":"$2y$12$iq4EYFhV.GLZWLzOt8iK1ecdpTkToMf3T\/FgKnv2Bk8pdBBpu325S", ...
```
**Impact:** 122 bcrypt hashes per request, offline-crackable, handed to anyone with any token. Combined with F-91 the audience is every employee. This is a reportable data breach in most jurisdictions.
**Re-verify:** `curl "…/employee-salary-structure?type=API&token=<any>&syear=2026" | grep -o '"password":"[^"]*"' | head`
**Fix sketch:** an explicit column list in `employeeDetails()`; never `select *` from `tbluser`.
**Status:** ✅ **CLOSED in Sprint 1** — `$hidden` on both tbluser models. Re-verified: 0 `password` and 0 `otp` keys in the salary-structure body.

#### F-93 — Monthly Payroll Report returns 500 for every role — CRITICAL
**What:** a synthetic `Request` built inside the controller carries no token and no session, and the tenant resolver it is handed then calls `$request->session()`, which throws.
**Where:** [PayrollController.php:2157](../../app/Http/Controllers/Payroll/PayrollController.php#L2157) → [:2630](../../app/Http/Controllers/Payroll/PayrollController.php#L2630) → [:69](../../app/Http/Controllers/Payroll/PayrollController.php#L69)
**Evidence:**
```php
// :2157  — no 'token' key, and a synthetic Request has no session store
$request2 = new Request(['type'=>"API",'sub_institute_id'=>$sub_institute_id, ... ]);
$emp_att = $this->getTotalDays($request2);
// :2630
public function getTotalDays(Request $request){ $sub_institute_id = $this->payrollTenantId($request);
// :64-69
$fromToken = $this->apiTenantId($request);      // null: no token on $request2
if ($fromToken) { return $fromToken; }
$fromSession = $request->session()->get('sub_institute_id');   // throws
```
Live, all three roles:
```
hr  GET /monthly-payroll/create  -> 500 {"message":"Session store not set on request.","exception":"RuntimeException"}
adm GET /monthly-payroll/create  -> 500  (same)
emp GET /monthly-payroll/create  -> 500  (same)
```
The branch fires whenever an employee has **no saved row** for the month — i.e. always, on a first run. `employee_monthly_salary_data` holds 22 rows platform-wide, which is consistent with the screen never having completed a run.
**Impact:** Monthly Payroll Report is unusable. It is also the only place attendance days reach payroll, so the attendance→payroll handoff is dead with it. A second copy of the same pattern sits at [:1582](../../app/Http/Controllers/Payroll/PayrollController.php#L1582) in the LWP path.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-reads2.sh` (section G).
**Fix sketch:** `getTotalDays()` should take the tenant id as a parameter rather than re-deriving identity from a request that has none.
**Status:** ✅ **CLOSED in Sprint 1** — `getTotalDays()` takes the tenant as a parameter instead of re-deriving identity from a synthetic request. Re-verified: 500 → **200** for admin and HR.
> **Caveat added in Sprint 2, and it matters.** Closing this only revealed F-121: the screen then began 500ing again at PHP's 60-second limit on every run. It is reliably 200 again after Sprint 2's N+1 fix, but at 31–59 s it is not yet usable at scale. "Monthly Payroll Report opens" is true; "is fast enough to use" is not, and F-121 stays open.

#### F-94 — `leave_type_id`'s foreign key points at `tbluser`; 15 of 29 live rows are mis-typed — CRITICAL
**What:** the migration constrains a leave's *type* to a valid *user id*, so a user id written into the leave-type column is accepted by the database.
**Where:** [2025_09_11_053231_hrms_emp_leaves.php:44-46](../../database/migrations/2025_09_11_053231_hrms_emp_leaves.php#L44)
**Evidence:**
```php
$table->foreign('user_id')      ->references('id')->on('tbluser')  ->onDelete('NO ACTION')...
$table->foreign('leave_type_id')->references('id')->on('tbluser')  ->onDelete('NO ACTION')...
                                                    ^^^^^^^ should be hrms_leave_types
```
Live: `information_schema` confirms `hrms_emp_leaves_leave_type_id_foreign → tbluser`. Consequence in tenant 3:
```
leave_type_id | rows | owning tenant
11            |  15  | (none — no such leave type has ever existed; ids run 1..10)
 6            |   7  | 3
 4            |   6  | 3
 1            |   1  | 1        <- another tenant's leave type
```
`tbluser.id = 11` exists (Rajesh Rafaliya, tenant 3), which is precisely why the FK allowed it. Those 15 rows were created **2026-06-19**, three months before this audit, and `hrms_leave_types` has never contained an id 11.
**Impact:** **52% of tenant 3's live leave requests have no leave type.** `GET /api/leave/reports/summary` reports them as `{"leave_type":"Unassigned","total":15,"days":15}`. They cannot be counted against any entitlement, cannot be filtered, and cannot be reported on.
**Re-verify:**
```sql
select hel.leave_type_id, count(*), max(hlt.sub_institute_id) from hrms_emp_leaves hel
  left join hrms_leave_types hlt on hlt.id = hel.leave_type_id
 where hel.sub_institute_id = 3 and hel.deleted_at is null group by 1;
```
**Fix sketch:** repoint the FK at `hrms_leave_types` after repairing or quarantining the 15 rows; add `sub_institute_id` scoping to the `exists:` rule (F-101).
**Status:** ✅ **CLOSED in Sprint 5** — the foreign key now references `hrms_leave_types`, and `from_date`, `user_id` and `leave_type_id` are `NOT NULL`. The 15 rows were soft-deleted first (reversible; they were a demo seed — same second, same four employees, comments "Monday pattern" and "Friday/post-payday"). Proven at the database, not the application: an INSERT with `leave_type_id = 11` is now refused with `1452 Cannot add or update a child row`, and one with a null `from_date` with `1364 Field 'from_date' doesn't have a default value`, while a valid row still inserts. **The "Unassigned" bucket has disappeared from the leave summary report.**

#### F-95 — Leave day count ignores weekly-offs and public holidays — CRITICAL
**What:** leave is charged in calendar days. A Saturday-to-Sunday request costs 2 days in a tenant whose own configuration says Saturday is a half day and Sunday is a weekend.
**Where:** [LeaveAnalyticsService.php:262](../../app/Services/Leave/LeaveAnalyticsService.php#L262), [helpers.php:952-960](../../app/Helpers/helpers.php#L952)
**Evidence:** `countDays($fromDate, $toDate ?: $fromDate, $dayType ?: 1, '')` — the fourth argument is the skip mode and it is always empty, selecting `$daysCount = (diffInDays + 1) * $dayType`. Live confirmation for real approved rows in tenant 3:
```
GET /api/leave/requests/20 -> "from_date":"2026-04-04","to_date":"2026-04-05","duration":"2 days","days":2
                              (Saturday to Sunday; tenant 3 weekdays: saturday=half, sunday=weekend)
GET /api/leave/requests/21 -> "2026-06-12".."2026-06-13","duration":"2 days"   (Fri+Sat; correct 1.5)
```
**Impact:** employees are over-charged leave, by up to 100% on a weekend request. `hrms_weekdays` (21 rows) and `hrms_holidays` (18 rows) are maintained by HR through a working screen and change no number in the product.
**Re-verify:** the two curls above, against `select * from hrms_weekdays where sub_institute_id=3`.
**Fix sketch:** one shared day-count service reading `hrms_weekdays` + `hrms_holidays`, used by apply, balance, report and payroll alike.
**Status:** ✅ **CLOSED in Sprint 4** — one `LeaveDayCounter` service reads the tenant's own `hrms_weekdays` and `hrms_holidays`. The result is computed once at write time and stored on `hrms_emp_leaves.chargeable_days`, so the reports sum a column instead of re-deriving a third version of it, and an approved request keeps the cost it was approved at when HR later adds a holiday. **The backfill reproduced the audit's hand-computed figures exactly**: leave #19 14.0 → **11.0**, #20 2.0 → **0.5**, #21 2.0 → **1.5**. The raw-SQL copy in `LeaveReportApiController::DAYS_EXPR` and both `countDays()` call sites now read the column.

#### F-96 — Every leave entitlement is zero — CRITICAL
**What:** entitlement is read from `hrms_leave_allocation`, which has **one row on the entire live platform**, and no screen creates rows in it.
**Where:** [LeaveAnalyticsService.php:133-150](../../app/Services/Leave/LeaveAnalyticsService.php#L133); sole writer [LeaveTypeApiController.php:265](../../app/Http/Controllers/Api/Leave/LeaveTypeApiController.php#L265)
**Evidence:** `select * from hrms_leave_allocation` → `{"id":1,"value":12,"leave_type_id":10,"sub_institute_id":1}`. And live, for tenant 3's administrator:
```json
GET /api/leave/balances?employee_id=6
{"data":{"leave_types":[{"leave_type":"Annual Leave","total":0,"used":2,"remaining":0}, ...],
         "overall":{"total":0,"used":7,"remaining":0}}}
```
**Impact:** the balance shown to every employee in every tenant except one is zero. "Remaining 0" is displayed for an employee who has used 7 days against a total of 0, and `store()` still accepts the next application. No balance rule can be written until this has a front door.
**Re-verify:** `select sub_institute_id, count(*) from hrms_leave_allocation group by 1;`
**Fix sketch:** a Leave Entitlement screen (year × department × employee) writing this table; make it the first tab of Leave Configuration.
**Status:** ✅ **CLOSED in Sprint 4** — an **Entitlements** tab on Leave Configuration, second in the list because a leave type with no entitlement grants nobody anything. `GET/PUT /api/leave/allocations`, gated on `configure_settings`. Proven live: an employee's Annual Leave read `total=0 remaining=0`, HR granted 3 days to their department, and the same call then read `total=3 remaining=3`.

#### F-97 — Attendance Tracking renders a fixture leave balance and a fixture holiday — HIGH
**What:** the hook sets mock objects in its `finally` block, so they are used on success as well as on failure.
**Where:** [use-attendance.ts:9-24,132-133](../../../g2gv0/hooks/use-attendance.ts#L9)
**Evidence:**
```ts
const mockLeaveBalance: LeaveBalance = { casual: 12, earned: 7, sick: 0, pending: 1 }
const mockUpcomingEvents: Event[] = [{ id:'e1', title:'Independence Day', date:'2026-08-15', ... }]
...
} finally {
  setLeaveBalance(mockLeaveBalance)      // unconditional
  setUpcomingEvents(mockUpcomingEvents)  // unconditional
  setLoading(false)
}
```
`GET /api/leave/balances` and `GET /api/leave/holidays/upcoming` both exist, are token-authenticated and work; neither is called from this screen.
**Impact:** every employee in every tenant is told they have 12 casual and 7 earned days, and that their next holiday is 15 August 2026. Tenant 3's real holidays are Gandhi Jayanti, "on duty", "day off" and Independence Day; tenant 6's are different again. This is the "screens that render invented data" failure the audit brief exists to find.
**Re-verify:** sign in as any employee, open Attendance Tracking, compare the Employee Snapshot widget with `GET /api/leave/balances`.
**Fix sketch:** call the two endpoints that already exist; delete both constants.
**Status:** ✅ **CLOSED in Sprint 2** — the two fixtures deleted from `use-attendance.ts`; the hook now calls `/api/leave/balances` and `/api/leave/holidays/upcoming`, which already existed and were never called. The four hardcoded leave types are gone from the balance card, modal and snapshot widget — they render whatever the tenant has configured.

#### F-98 — Attendance alerts and "My Requests" are hardcoded arrays — HIGH
**What:** two dashboard widgets render literals.
**Where:** [attendance-tracking/page.tsx:99-111](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L99)
**Evidence:**
```tsx
const ATTENDANCE_ALERTS = [
  { id: 'a1', text: 'Missing Punch-Out (Jun 18)', severity: 'critical' },
  { id: 'a2', text: 'Regularization Pending (1)', severity: 'warning' }, ... ]
const MY_REQUESTS = [ { id:'r1', type:'Regularization', status:'Pending', count:1 }, ... ]
```
**Impact:** every employee sees a critical alert about a missing punch-out on 18 June and one pending regularisation, forever, whoever they are. There is no regularisation feature (F-107), so the alert refers to something that cannot exist.
**Re-verify:** open the screen as two different employees; the alerts are identical.
**Fix sketch:** derive alerts from `hrms_attendances` (open shifts, missing punch-outs) and requests from `hrms_emp_leaves` + the regularisation table F-107 introduces.
**Status:** ✅ **CLOSED in Sprint 2** — `GET /api/attendance/self-summary` serves real alerts derived from the caller's own punches and their real request counts. Proven: a seeded missing punch-out produced *"Missing punch-out on 02 Sep"* and *"1 late arrival this month"*, and an employee with nothing outstanding gets an empty panel that says so.

#### F-99 — Attendance Reports: Export, Print and Saved Reports do nothing — HIGH
**What:** three visible controls are `console.log`, and the saved-report dropdown is a static list.
**Where:** [attendance-reports/page.tsx:353-363](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-reports/page.tsx#L353), [report-data.ts:393](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-reports/services/report-data.ts#L393)
**Evidence:**
```tsx
const handleExport = () => { console.log('Export clicked') }
const handlePrint  = () => { console.log('Print clicked') }
const handleSavedReportChange = (value: string) => { console.log('Saved report:', value) }
```
```ts
export const savedReports = [ { value:'last-month', label:'Last Month Report' },
                              { value:'q1-2026',    label:'Q1 2026 Report' }, ... ]
```
`savedReports` is imported at :7 and rendered into the live filter bar at :788.
**Impact:** the module's only reporting screen cannot produce a file.

> **SHARPENED 2026-09-05, during Sprint 3.** This finding said the handlers were `console.log`, which
> implied there were buttons wired to them. **There were no Export or Print controls on the screen at
> all** — `handleExport` and `handlePrint` were defined and bound to nothing, dead code beside a
> Saved Reports dropdown that *was* rendered and did nothing. Worse than filed, and only visible by
> grepping for the handler names rather than trusting the finding.

**Status:** ✅ **CLOSED in Sprint 3** — Export and Print controls added and working. Export writes the
view the user is actually looking at (grouped or daily), through the existing `downloadCsv()` from
`payroll-shell.tsx` rather than a second CSV writer. Print uses the browser dialog with a print
stylesheet that drops the filter bar, tabs and buttons, so it prints as a report.

The **Saved Reports dropdown was removed rather than made real.** Its three entries — "Last Month
Report", "Q1 2026 Report", "This Week Report" — were date ranges, which is exactly what the Quick
Filter beside it already is. It was a broken duplicate of a working control, so it went, and the
ranges it promised (Last Month, This Quarter, This Year) were added to the Quick Filter, where they
now apply. Building a saved-report table would have been inventing an entity to justify a control
that duplicated its neighbour.

#### F-100 — A live endpoint returns invented leave balances — HIGH
**What:** `GET /hrms/myleave/{employeeId}` and `/hrms/leavehistory/{employeeId}` return a hardcoded block, and ignore the employee id's relationship to the caller.
**Where:** [HrmsLeaveController.php:296-331](../../app/Http/Controllers/HRMS/HrmsLeaveController.php#L296), routed at [routes/hrms.php:186-187](../../routes/hrms.php#L186)
**Evidence:**
```php
// Sample data
$leaveSummary = ['total_leaves' => '20', 'used_leaves' => '5', 'remaining_leaves' => '15'];
$leaveTypes = [['leave_type' => 'Casual Leave', 'used' => '7', 'total' => '14'], ...];
```
Live, with a tenant 3 employee token: `200 {"data":{"leave_summary":{"total_leaves":"20","used_leaves":"5","remaining_leaves":"15"}, ...`
**Impact:** a mobile client or integration calling the obvious-looking endpoint gets fiction. The path takes an arbitrary `employeeId` and the controller never checks it against the caller, so it is also an identity hole waiting for the day it returns real data. `LeaveDashboardController` is the real implementation and is unrelated to this route.
**Re-verify:** `curl "…/hrms/myleave/7?type=API&token=<any employee token>"`
**Fix sketch:** delete both routes and the two methods, recording in the removal comment what they were for — the convention `routes/hrms.php` already uses.
**Status:** ✅ **CLOSED in Sprint 1** — both routes and both methods deleted, with what they meant recorded in `routes/hrms.php`. Re-verified: **404**.

#### F-101 — Leave apply accepts another tenant's leave type — HIGH
**What:** `exists:hrms_leave_types,id` is not scoped to the caller's tenant.
**Where:** [LeaveRequestApiController.php:153](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L153)
**Evidence:** `'leave_type_id' => 'required|integer|exists:hrms_leave_types,id'`. Leave type **9** belongs to tenant **6** (`Earned Leave`). Applied as a tenant **3** employee → `201 {"data":{"id":224}}`. Removed afterwards.
**Impact:** a leave request that cannot be reported on: `LeaveAnalyticsService::requestsQuery()` joins `hrms_leave_types` with `hlt.sub_institute_id = <caller>`, so the row renders with an empty leave type and lands in the "Unassigned" bucket alongside F-94's 15 rows. It also leaks the existence and ids of another tenant's configuration.
**Re-verify:** `probe-validation.out`, line "leave_type_id from ANOTHER tenant".
**Fix sketch:** `Rule::exists('hrms_leave_types','id')->where('sub_institute_id', $context['sub_institute_id'])`.
**Status:** ✅ **CLOSED in Sprint 4** — `Rule::exists(...)->where('sub_institute_id', ...)`. Re-verified: a tenant 3 employee applying with tenant 6's leave type id 9 was 201 Created and is now **422** — *"That leave type is not available to your organisation."*

#### F-102 — Leave apply enforces no business rule at all — HIGH
**What:** `store()` validates shapes and writes. No balance, overlap, closed-period, probation or notice rule exists anywhere in the path.
**Where:** [LeaveRequestApiController.php:146-230](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L146)
**Evidence:** live, as tenant 3 employee:
```
365-day leave, 2026-12-01 .. 2027-11-30     -> 201 Created
leave dated 1990-01-01 .. 1990-01-05        -> 201 Created
```
The only guard against duplicates is an upsert keyed on `(user_id, from_date, status='pending')` at :205-213, which a different start date walks straight past.
**Impact:** balances cannot be trusted, closed periods can be reopened by an employee, and payroll's LWP figure is derived from rows nobody validated.
**Re-verify:** `probe-validation.out`, first section.
**Fix sketch:** a `LeaveApplicationPolicy` invoked before the write: balance (needs F-96), overlap, leave-year bounds, and the day count from F-95.
**Status:** ✅ **CLOSED in Sprint 4**, with one stated looseness — four rules in `store()`, each with a message a person can act on. Re-verified against the audit's own probes: the 365-day request and the 1990-dated request were both 201 Created and are now **422**; overlapping dates are refused by date range rather than by start-date key; a range that is entirely weekly-off is refused; and the balance rule bites once an entitlement exists (5 days against a 3-day balance → 422).

> **The looseness, stated rather than hidden.** An entitlement of **0** is not read as "no balance,
> refuse". Because `hrms_leave_allocation` holds one row for the whole platform, every entitlement is
> currently zero, and enforcing the rule strictly would have refused *every leave request in the
> product* the moment it shipped. So the rule is: enforce the balance where the tenant has configured
> one, allow it through where they have not. This resolves itself as entitlements are set through the
> screen F-96 adds, and it is written down here and in the code rather than left as a silent gap.

#### F-103 — Every role sees every employee's leave — HIGH
**What:** `index()` scopes by tenant only; the frontend's sole narrowing is a URL parameter.
**Where:** [LeaveRequestApiController.php:40-91](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L40), [leave-requests/page.tsx:66](../../../g2gv0/components/domain/hrms/hrit/leave-management/leave-requests/page.tsx#L66)
**Evidence:** `const showMine = searchParams.get('mine') === '1'` — there is no control that sets it, and the page header reads `"${total} Requests • Organization-wide"` for everyone. As a tenant 3 employee: `GET /api/leave/requests?per_page=200` → 200, every request in the organisation, with names, departments and reasons.
**Impact:** leave reasons are health and family information. Tenant 3's own matrix gives the Employee role scope `Self`. The product publishes the lot.
**Fix sketch:** scope the query by the caller's `hrms_leave_role_permissions.scope` — Self / Team / Department / Organization.
**Status:** ✅ **CLOSED in Sprint 1** — scope applied to /requests, /reports/*, and all six dashboard queries. Re-verified per role: Self roles see only their own rows (12->4, 54->4, 150->1), Department 0 in an empty department and 13 in a populated one, Organization 18. Before, every role saw 18.

#### F-104 — The frontend derives roles by substring-matching a display name — HIGH
**What:** four roles, matched on editable text, standing in for nine stable `role_key`s.
**Where:** [laravel-session.ts:80-96](../../../g2gv0/lib/laravel-session.ts#L80), [types/role.ts:1](../../../g2gv0/types/role.ts#L1)
**Evidence:** `export type Role = 'admin' | 'hr' | 'dept-head' | 'employee'`, and the matcher quoted in §6. Live consequences: `Reporting Manager` matches `includes('manager')` → `dept-head`; `Auditor`, `Executive` and `Recruiter` match nothing → `employee`; **any profile a tenant renames to contain "admin" becomes an administrator.**
**Impact:** three roles are over-granted or under-granted in the menu, and one is a self-service privilege escalation for any tenant admin who can rename a profile. The backend solved exactly this — [RequireProfile.php](../../app/Http/Middleware/RequireProfile.php) documents the same substring collision and keys on `role_key` instead — and the frontend never adopted it.
**Fix sketch:** send `role_key` in the login payload; widen `Role` to the nine keys; keep the display-name fallback only for the legacy profiles `RequireProfile` already lists.
**Status:** ✅ **CLOSED in Sprint 1** — frontend Role widened to the nine role_keys; `role_key` added to the login payload; substring matching deleted.

#### F-105 — Shift configuration: controllers exist, tables do not — HIGH
**What:** `tbluser_shift_master` and `tbluser_shift_records` are absent from the live database; two registered controllers read and write them.
**Where:** [shiftMasterController.php:59](../../app/Http/Controllers/HRMS/shiftMasterController.php#L59), [bulkUserShiftUpdateController.php:63](../../app/Http/Controllers/HRMS/bulkUserShiftUpdateController.php#L63), routed at [routes/hrms.php:163-164](../../routes/hrms.php#L163)
**Evidence:**
```
GET /hrms/user_shift_master -> 500 SQLSTATE[42S02] Table 'hp_erp.tbluser_shift_master' doesn't exist
select table_name from information_schema.tables where table_name like '%shift%';  -- 0 rows
```
No migration creates either table. `hrms_in_out_times` — the other candidate source — has **0 rows**.
**Impact:** the two shift-**template** admin screens (`hrms/user_shift_master`, `hrms/user_bulk_shift_update`) 500 on every call, on both `hp_erp` deployments.

> **CORRECTED 2026-09-05, during Sprint 2.** This finding first read *"there is no expected-start,
> expected-end or shift length anywhere in the product"*. **That is wrong.** The per-employee roster
> exists on `tbluser` as fourteen `time` columns — `monday_in_date` / `monday_out_date` through
> `sunday_*` — and it is **populated**: 102 of 122 active users in tenant 3, 100 of 181 in tenant 7,
> with real values (08:00–18:00, 09:00–18:00, `sunday_in_date` NULL for a non-working day).
> `HrmsController:1693-1721` already reads them to compute the early-going report's `expected_time`.
>
> What is actually missing is narrower: `tbluser_shift_master` is the *template* table that a bulk
> update copies **into** those columns. Its absence breaks the two admin screens that manage
> templates; it does not leave the product without expected times.
>
> Second single-host generalisation of this audit (the first was F-111). Evidence:
> ```
> 202.47.117.220/hp_erp        tbluser_shift_master ABSENT   hrms_in_out_times 0
> 128.199.17.97/hp_erp         tbluser_shift_master ABSENT   hrms_in_out_times 0
> lms.triz.co.in/triz_erp_21   tbluser_shift_master 3 rows   hrms_in_out_times 18
> ```
> Rows on the deployment that has it: `10 to 7  10:00:00-19:00:00`, `9:30 to 6:30`, `General`.

**Q2 is answered.** `tbluser_shift_master` is the shift source. `hrms_in_out_times` is **not** a
roster at all — its 18 rows on triz_erp_21 are recorded punch times per user per day, a second,
older generation of `hrms_attendances`. It belongs in the duplicate-generations list in §E.0, not
here.
**Fix sketch:** the dashboard can read the `tbluser` roster today — no table needed. Create
`tbluser_shift_master` separately so the two template screens stop 500ing.
**Status:** Sprint 2 — dashboard now reads the real per-employee roster. The template table and its
two screens remain broken and are **still open**.

#### F-106 — Validation allows 191 characters into a `varchar(30)`, returning a raw SQL error — HIGH
**What:** the rule and the column disagree; with `STRICT_TRANS_TABLES` the result is a 500 whose body contains the database host, port and schema.
**Where:** [LeaveTypeApiController.php:86](../../app/Http/Controllers/Api/Leave/LeaveTypeApiController.php#L86); column `hrms_leave_types.leave_type varchar(30)`
**Evidence:** a 40-character name as `hr_manager`:
```
500 {"message":"SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column
 'leave_type' at row 1 (Connection: mysql, Host: 202.47.117.220, Port: 3306, Database: hp_erp,
 SQL: insert into `hrms_leave_types` ...
```
**Impact:** a 422 with a usable message becomes a 500 that publishes infrastructure detail to any caller. `APP_DEBUG` is the reason the trace is verbose, but the 500 itself is a validation defect.
**Re-verify:** the curl in `probe-validation.sh`, third leave-type case, with 40 `B`s.
**Status:** ✅ **CLOSED in Sprint 8**, and **there were two, not one**. Rather than change the line the report named, every `varchar` in the module's thirteen tables was checked against every `max:` rule that writes it (`_evidence/width-audit.php`). It found `hrms_leave_types.leave_type` **and** `HolidayController`'s `holiday_name` (`max:255` against `varchar(191)`). Both corrected to the column's own number. Proven at the boundary: 40 characters → **422** *"The leave type field must not be greater than 30 characters"* with no infrastructure detail; 30 characters → accepted.
**One false positive, checked rather than filed:** the tool also flagged `EmployeeDirectoryController`'s `reason` (`max:1000`) against `hrms_attendance_regularisations.reason varchar(255)`. It matches on column NAME across tables; that controller writes `reason` to `tbluser`, not to the regularisation table. Reported here because a scanner that over-matches is only useful if its output is verified.
**Fix sketch:** `max:30` to match the column, and confirm `APP_DEBUG=false` in production.

#### F-107 — Attendance regularisation: a backend with no caller and a button with no backend — HIGH
**What:** `POST update_user_att` exists and corrects an attendance row. Nothing in `g2gv0` calls it. The Quick Action that would is `onClick: () => {}`.
**Where:** [routes/hrms.php:181](../../routes/hrms.php#L181) → `HrmsController@updateUserAttendance`; [attendance-tracking/page.tsx:93](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L93)
**Evidence:** `grep -rn "update_user_att\|updateUserAttendance\|regulariz" g2gv0/{services,hooks,components,lib}` returns three hits, all of them the dead button and the two fixture strings from F-98.
**Impact:** an employee whose punch is wrong has no way to say so, while the capability to fix it is built and paid for. It is also the single most-requested attendance feature in any HR product.
**Fix sketch:** a request → approve → apply lifecycle on a new `hrms_attendance_regularisations` table, with `update_user_att` as the apply step.
**Status:** ✅ **CLOSED in Sprint 2** — full lifecycle built and proven — raise → re-raise edits rather than duplicates → employee refused the review queue (403) → HR sees it → applicant cannot self-approve (403) → HR approves → the attendance row is created with `timestamp_diff 08:40:00`, hand-checked. Double-decision refused. `RegularisationDrawer` is the employee's surface.

#### F-108 — Attendance percentage is computed twice, differently — HIGH
**What:** the backend and the frontend each implement the formula, and they disagree.
**Where:** [AttendanceTrackingApiController.php:222](../../app/Http/Controllers/Api/Attendance/AttendanceTrackingApiController.php#L222), [attendance-tracking/page.tsx:139-144](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L139)
**Evidence:** backend `(presentDays + lateDays) / workingDays`; frontend `present / (present + late + leave + absent)`. The API's `percentege` is returned and discarded.
**Impact:** with 15 present, 3 late, 2 leave, 1 absent over 21 working days the two answers are 85.7% and 71.4%. Whichever is right, one of them is on a screen.
**Fix sketch:** delete the frontend copy; render `percentege`.
**Status:** ✅ **CLOSED in Sprint 2** — and **this register said it was open until Sprint 8**, which is a bookkeeping error worth recording rather than quietly correcting. The frontend copy was deleted when `use-attendance.ts` was de-fixtured; `setAttendancePercentage(Math.round(Number(response.percentege ?? 0)))` reads the API's figure and the comment above it explains why. Verified in Sprint 8's close-out sweep: no second formula survives anywhere in `hooks/use-attendance.ts` or the attendance components. Two of the eight findings I was carrying as "open" were nothing of the kind — see also the sweep note in `00-PROGRESS.md`.

#### F-109 — Saving monthly payroll twice creates duplicate payslips — HIGH
**What:** `monthlyPayrollStore` inserts unconditionally; there is no upsert and no unique key.
**Where:** `PayrollController@monthlyPayrollStore`; the caller already documents it at [payroll.ts:663-666](../../../g2gv0/services/hrms/payroll.ts#L663)
**Evidence:** the service comment reads *"The controller INSERTs unconditionally - it has no upsert - so only rows without an existing monthlyData record may be submitted, otherwise the month gets duplicate payslips."* The frontend works around it by filtering; nothing stops a second client, a retry, or a double-click.
**Impact:** two payslips for one employee-month, two PDFs filed, and a total that double-counts.
**Fix sketch:** a unique index on `(sub_institute_id, employee_id, month, year)` and `updateOrInsert`, plus the month-lock from the plan's Sprint 6.

> **CORRECTION (Sprint 6).** This finding said *"not currently observable on live because F-93
> prevents the screen loading at all"*. That was **wrong**, and wrong in the direction that
> understates it — F-93 blocked the *report*, not this write path, and the duplicates were
> already there. Counted on live:
> ```
> select employee_id, month, year, sub_institute_id, count(*) c
>   from employee_monthly_salary_data group by 1,2,3,4 having c > 1;
>
>   employee_id=1  month=july  year=2026  sub_institute_id=1  c=17
> ```
> Seventeen payslips for one employee-month. The audit inferred the impact from the code and
> the frontend's warning comment instead of counting the rows, which is exactly the step its
> own ground rules require. Counting takes one query.

**Status:** ✅ **CLOSED in Sprint 6.** `monthlyPayrollStore` now matches on `(employee_id, month, year, sub_institute_id)` and updates in place; where duplicates already exist the earliest row is kept (it holds the original `created_at`) and the rest removed, so a corrected month leaves **one** payslip. Proven through the real endpoint: three saves → 1 row, `40000 → 45000 → 45000`. A unique index was **not** added: the 17 pre-existing duplicates would have to be destroyed to create one, and they are only collapsed when that month is deliberately re-saved.

#### F-110 — Salary Certificate has never written a row — HIGH
**What:** `hrms_salary_certificate` holds 0 rows across the whole live platform.
**Where:** write path [PayrollController.php:789](../../app/Http/Controllers/Payroll/PayrollController.php#L789); readers at :765 and :840
**Evidence:** `select count(*) from hrms_salary_certificate` → **0**. The write is a plain `insert` with no surrounding try/catch, so a silent failure is not the explanation.
**Impact:** either nobody has ever generated a certificate, or the screen's preconditions (a department, an employee, a year, months, pay heads — and a salary structure, of which tenant 3 has 2 for 122 employees) are unreachable in practice. The PDF download at `:840` reads a row that never exists, so it can only ever fail.
**Re-verify:** `select count(*) from hrms_salary_certificate;`
**Fix sketch:** drive one certificate end to end for tenant 3 and find out which precondition blocks it; then surface that precondition on the screen.

> **Q5 ANSWERED (Sprint 8): it is UNUSABLE, not unused.** Driving it end to end took one call:
> ```
> POST /hrms-salary-certificate-report  {employee_id: 1, year: 2026, ...}
> ErrorException: Undefined array key 0   PayrollController.php:874
> ```
> `get_salary_certificate_html()` dereferenced `$get_all_details[0]` with no guard, and that
> array is empty for any employee with **no salary structure for that year**.
> `employee_salary_structures` holds **8 rows for the entire platform**, so almost every
> (employee, year) a user could pick fatalled before reaching the insert. That is the whole
> explanation for 0 rows in the table's lifetime.
> Confirmed by the converse: the *one* combination on live that does have a structure —
> employee 10, tenant 3, 2026 — succeeded and wrote **the first row this table has ever held**.
>
> The download path at `:840` had the same unguarded shape one screen later
> (`->pdf_html` on a row that never existed), so every download was also a fatal.

**Status:** ✅ **CLOSED in Sprint 8.** The builder returns `null` instead of fatalling and the caller answers **422** with a sentence that says what to do — *"Vikram Sethi has no salary structure for 2026, so there is no salary breakdown to certify. Add one under Salary Structure for that year."* The download aborts 404 rather than dereferencing a missing row. Proven: refusal where there is no structure, a written certificate where there is. **The 422 is itself a correction** — the first version of this fix threw a `RuntimeException`, so the right message arrived as an HTTP 500 looking like a crash; `probe-sprint8.sh` failed it and it was changed.

#### F-111 — A tenant id is baked into a salary calculation — HIGH
**What:** a salary-structure branch that applies to exactly one organisation.
**Where:** [PayrollController.php:419](../../app/Http/Controllers/Payroll/PayrollController.php#L419)
**Evidence:** `if($amount_type==1 && $Per_Flat!=0 && $value[1] > $Per_Flat && $sub_institute_id==47){`
**What it does:** for a **Flat** pay head with a configured cap, tenant 47 gets `entered − cap`; every other tenant gets `cap`. Two meanings for one configuration, selected by id. The `elseif` beside it carries the comment *"added for another institutes on 14-05-2025"* — so `== 47` was the original behaviour and the general case was bolted on later.
**Impact:** a rule no tenant can see, configure or audit — and it is **not** dead.

> **CORRECTED 2026-09-05, during Sprint 1.** This finding first read *"tenant 47 has no rows in the HRIT tables on live, so the branch is currently unreachable"*. True of the application host, false of the product. Tenant 47 exists on the third deployment with **597 users and 924 salary structures**. Checking one host and generalising is the mistake this audit's own ground rules forbid; recorded rather than quietly edited.
>
> ```
> 202.47.117.220/hp_erp        tenant 47: school_setup=0 users=0   salary_structures=0
> 128.199.17.97/hp_erp         tenant 47: school_setup=0 users=0   salary_structures=0
> lms.triz.co.in/triz_erp_21   tenant 47: school_setup=1 users=597 salary_structures=924
> ```

**Fix sketch:** **do not change the arithmetic.** Move the id out of the expression into named configuration so the rule is visible and settable, then take the domain decision separately — see Q1, now sharpened. Sprint 1 does the first half only.
**Status:** Sprint 1 — id extracted to `config/payroll.php`, arithmetic byte-identical. Q1 still open.

---

### Findings raised during remediation

Thirteen defects found while fixing the above (F-120 … F-132), filed here so the register stays the single
list. Two of
them — F-126 and F-127 — were introduced BY Sprint 6 and closed in it, found by an adversarial
review of that sprint’s own work rather than by its probe.

#### F-124 — The approval workflow a tenant configures controls nothing — HIGH
**What:** `hrms_leave_workflow_settings` is written and re-read by the Leave Configuration screen and by **no other code in the product**. A tenant that configures a two-stage chain — Reporting Manager then Department Head — and a 24-hour escalation gets neither. One approval from anybody holding `approve_leave` decides the request.
**Where:** [ApprovalWorkflowTab.tsx](../../../g2gv0/components/domain/hrms/hrit/leave-management/leave-configuration/components/ApprovalWorkflowTab.tsx) writes it; [LeaveWorkflowApiController.php:216](../../app/Http/Controllers/Api/Leave/LeaveWorkflowApiController.php#L216) stores it; [LeaveRequestApiController::decision](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php) never consulted it.
**Why this has no earlier id:** the audit recorded it in the scorecard (row 11, *"written by a screen and read by nothing"*) and in the Leave lifecycle table (row 5, **NOT-WIRED**) but never gave it a finding of its own. It is filed here rather than folded into F-89, which is a different defect about `hrms_leave_role_permissions`.
**Searches run:** `grep -rn "hrms_leave_workflow_settings\|HrmsLeaveWorkflowSetting\|workflow_settings" app/ routes/ database/migrations/` — 10 hits, every one inside `LeaveWorkflowApiController`, its model, or its own migration.
**Evidence:** all three live rows have escalation enabled and nothing has ever escalated:
```
sub_institute_id=1  rm=1 dh=1 hr=0  multi_level=0  escalation_time=24  escalate_to=hr
sub_institute_id=3  rm=1 dh=1 hr=0  multi_level=0  escalation_time=24  escalate_to=hr
sub_institute_id=7  rm=1 dh=1 hr=0  multi_level=0  escalation_time=24  escalate_to=hr
```
**Impact:** separation of duties is unenforceable. An HR Manager with Organization scope approves anything in one click regardless of the chain the institute defined, and an approval that sits untouched for a week escalates to nobody. The screen demonstrates a capability the product does not have.
**Second defect inside the same screen:** the Escalate-To dropdown posts `department-head`, `hr` and `admin` — a third spelling of roles that the switches above it call `department_head` and that `role_key` also calls `department_head`. Any chain built from it naively would stamp a step no role could satisfy.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-sprint6.sh`
**Status:** ✅ **CLOSED in Sprint 6.** `hrms_leave_approval_steps` + `LeaveApprovalWorkflow` make the settings load-bearing; `leave:escalate` scheduled hourly; the dropdown's spellings normalised in `ESCALATE_ALIASES`. Proven live: an HR Manager with Organization scope refused at step 1, a request still `pending` after one approval of two, `approved` only after both.

#### F-125 — Monthly payroll fatally crashes on an employee with no salary structure — HIGH
**What:** `EmployeeSalaryStructure::…->first()` can return `null` and is dereferenced unguarded one screen later.
**Where:** [PayrollController.php:1542](../../app/Http/Controllers/Payroll/PayrollController.php#L1542) fetches it; the original line ~1605 read `json_decode($employeeSalaryStructure->employee_salary_data, true)` with no null check.
**Evidence:** reproduced on live with employee 582 (tenant 3), who has no salary structure:
```
Attempt to read property "employee_salary_data" on null
  PayrollController.php(1605): monthlyPayrollPdf(Request, 582, 'Nov', 2026, 'storeDoc')
  PayrollController.php(2543): monthlyPayrollStore(Request)
  HTTP 500
```
**Impact:** worse than a plain crash. From `monthlyPayrollStore` the fatal lands **after** `employee_monthly_salary_data` has been written, so the caller sees a 500 for a save that partly succeeded, and the payslip PDF and its `staff_document` row are never created. Whether the month saved is unknowable from the response.
**Found by:** proving F-109's upsert, not by the audit — the audit never ran payroll for an employee without a structure.
**Re-verify:** POST `/monthly-payroll-store` for an employee with no row in `employee_salary_structures`; expect 200 and a named warning, not a 500.
**Status:** ✅ **CLOSED in Sprint 6.** `monthlyPayrollPdf` returns `null` for a missing structure and logs it; the month still saves and the response **names** the employees who got no payslip rather than counting them.

#### F-132 — Monthly Payroll showed HR two of 122 employees — CRITICAL
**What:** `employeeDetails()` decides who may see the whole institute from a hardcoded list of **profile display names**, matched case-sensitively. The React screen sends `user.role` — a **role_key**. They have never matched, so every HR and administrator caller fell through to the subordinate filter.
**Where:** [helpers.php](../../app/Helpers/helpers.php) — `$profileArr = ["Admin","Super Admin","School Admin","Assistant Admin"]`; fed from [PayrollController.php](../../app/Http/Controllers/Payroll/PayrollController.php) `monthlyPayrollCreate`, which read `$request->user_profile_name`.
**What the fallback does:** `getSubCordinates()` returns the caller plus anyone whose `tbluser.employee_id` points at them — not an org chart, and for most people just themselves.
**Evidence, measured on live before the fix:**
```
GET /monthly-payroll/create   tenant 3, 122 active employees
  administrator  ->    2 employees
  hr_manager     ->    1 employee
```
**Impact:** **payroll was being run for two people.** The screen showed no error, because a short list is not visibly a truncated one — an HR user would have to know the headcount to notice. This is the most serious defect found in the whole engagement and the audit did not catch it, because Part B walked the lifecycle for *one* employee and never asked whether the roster was complete.
**NOT caused by Sprint 1's `role_key` migration.** Before it, `mapProfileNameToRole` returned `'admin'` (lowercase), and `in_array()` is case-sensitive, so that missed the list too. Confirmed from the diff. This has always been broken.
**How it was found:** trying to reproduce F-121's 122-employee load in order to re-measure it. The measurement I could not get was itself the bug.
**Re-verify:** `GET /monthly-payroll/create?...&user_profile_name=administrator` as each role; expect 122 for admin/hr, 403 for everyone else.
**Status:** ✅ **CLOSED in Sprint 8.** Two changes. The controller **resolves the profile name from the caller's own record** instead of reading it from the request — "which profile am I" is an identity claim, and sending `Admin` would previously have widened the result set. And the helper now also admits `administrator`, `hr_manager` and `hr_executive` **by `role_key`**, which is what the payroll routes' own `hrit.role:admin,hr` gate already says they may see. The display-name list is **kept**, not replaced: other callers and older sessions still pass those names, and removing it would narrow people it currently admits.
**Blast radius, checked rather than assumed:** of twelve call sites, **only this one** passes both `$userProfileName` and `$profileUserId`, so no other screen's results change. Verified after: administrator, hr_manager and hr_executive all see **122**; department_head, reporting_manager, employee and auditor all get **403** from the route gate, as before.

#### F-130 — An employee cannot see their own payslip — HIGH
**What:** not "it is hard to find" — **no route serves it**. `monthlyPayrollPdf` is registered inside `routes/hrms.php`'s `hrit.role:admin,hr` group, so the only path to a payslip is through the HR console.
**Where:** [routes/hrms.php:101](../../routes/hrms.php#L101), inside the block opened at :70.
**Searches run:** `grep -rn "payslip\|monthlyPayrollPdf" routes/` — one route, inside the HR gate. No `my-*`, `self`, or `me` prefix exists anywhere in the module.
**Impact:** the audit's Part D gap. An employee asking about last month's pay has to ask a person. The same is true of their own leave balance outside the leave screens and their own salary certificate.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-sprint8.sh` section 1.
**Status:** ✅ **CLOSED in Sprint 8.** `/api/my-hr/{summary,payslips,payslips/{month}/{year}/pdf}` plus a **My HR** screen. **No endpoint takes an `employee_id`** — the subject is resolved from the token, so "my payslip" cannot become "anyone's payslip" by adding an id to a URL. Proven: the same endpoint returns 1 payslip to the administrator and 0 to an employee, and `?employee_id=6` changes nothing. The payslip PDF reuses the existing generator rather than a second implementation that could disagree about somebody's pay.

#### F-131 — The salary certificate calls every employee "Her" — MEDIUM
**What:** `"Her monthly salary breakup is as follows"` was hardcoded into a document an employee takes to a bank.
**Where:** `get_salary_certificate_html()`, the certifying sentence.
**Evidence:** the gender **was** being read — `$his` is derived from `u.gender` in the loop below — and then used only further down, so the sentence above it was fixed text regardless.
**Impact:** an institution-branded document, sent to a third party, misgendering its own staff.
**Found by:** driving F-110 end to end. Not in the audit, because the code path had never executed.
**Status:** ✅ **CLOSED in Sprint 8.** `M → His`, `F → Her`, **anything else → Their** — unknown or unrecorded gender gets the neutral form rather than a guess, because a certificate that misgenders somebody is worse than one that is neutral. Proven on both branches.

#### F-128 — The module has never sent a notification of any kind — HIGH
**What:** not "the notifications are wrong" — there are none. No HRIT screen, controller, job or service tells anybody anything.
**Where:** searched `app/Http/Controllers/Api/Leave/`, `app/Http/Controllers/HRMS/`, `app/Http/Controllers/Payroll/`, `app/Services/Leave/` for `Notification`, `Mail::`, `notify`, `g2g_notification`, `EventRecorder` — **zero hits** before this sprint. The platform has a complete notification stack (`EventRecorder` → `g2g_event` → `ReactEvents` → `NotificationDispatcher` → `RecipientResolver` → `NotificationComposer` → `NotificationSender` → `g2g_notification` → the bell) built for LMS and Talent; HRIT emitted nothing into it.
**Impact:** an employee applies for leave and their approver finds out by opening the screen. An approver decides and the employee finds out the same way. A request sits for a week and nobody is told — which is how tenant 3 came to hold requests pending since January and March (see F-123). The escalation added in Sprint 6 escalated to *nobody*.
**Why it could not have been fixed earlier:** [RecipientResolver.php:30-33](../../app/Services/Notifications/RecipientResolver.php#L30) states the rule and the measurement behind it — *"There is no org-chart fallback because there is no org chart. Any notification whose only plausible recipient is 'the employee's manager' cannot be delivered to anyone and is deferred, not shipped."* That was correct. Sprint 6's `hrms_leave_approval_steps` changed the facts: it names the exact **role** that must decide **this** request, so the recipient became a stored fact rather than an inference.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-sprint7.sh`
**Status:** ✅ **CLOSED in Sprint 7.** Three event types (`leave.submitted`, `leave.decided`, `leave.escalated`) added to the existing stack — **no new notification infrastructure, and no frontend work at all**, because the bell already existed and was already wired. Proven live: the Reporting Manager told on submit; on approval the employee told *and* the Department Head told it is now their turn; five HR users told on escalation. Email untouched and still behind `G2G_NOTIFY_EMAIL`.

#### F-129 — A finished, paid payroll month can be silently re-saved — HIGH
**What:** `employee_monthly_salary_data` has no state. A month that has been paid and a month still being edited are the same rows, and nothing distinguishes them.
**Where:** `PayrollController@monthlyPayrollStore`; the table's own schema — no status, no lock, no closed flag.
**Impact:** F-109 stopped a re-save *duplicating* a month. It did not stop one happening. Once salaries are paid, rewriting the figures behind them changes what the payslips said without any record that it happened or why. Distinct from F-109 and worth its own id: one is a data-integrity defect, this is a missing lifecycle state.
**Re-verify:** `probe-sprint7.sh` section 6.
**Status:** ✅ **CLOSED in Sprint 7.** `payroll_month_locks`, one row per `(tenant, month, year)` — locked is a fact about a month, not about 122 payslips that would then have to agree. Enforced **at the write** in `monthlyPayrollStore`, not on the screen (F-91's lesson). Reopening requires a reason, records who and when, and keeps the history rather than deleting the row. Proven: locked save refused with the figures unchanged, reopen without a reason refused, employee locking → 403.

#### F-126 — A decided leave request could be decided again, by one approver — CRITICAL
**What:** `decision()` never read `hrms_emp_leaves.status`. It checked the tenant, the row, `approve_leave`, scope and self-approval — and no state at all. `applyDecision()`'s `UPDATE` had only `id` and `sub_institute_id` predicates.
**Where:** [LeaveRequestApiController.php:443](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php#L443) selected only `id, user_id`; the chain check lived inside `if ($step)`.
**This is a hole Sprint 6 OPENED, not one it inherited.** Before the chain, a re-decision was at least still one approver overwriting one status. Afterwards it became worse in a specific way: the chain is enforced *inside* `if ($step)`, a finished chain has no `pending` step, so `currentStep()` returns `null` and **the entire chain block is skipped**. And the backfill wrote a *closed* chain onto every already-decided request — so the branch commented "this request predates the chain" stopped catching legacy rows and started catching **finished** ones.
**Evidence:** on live, tenant 3 held 4 approved and 1 rejected request. `hrms_leave_role_permissions` grants HR Manager, Administrator and Executive `approve_leave` with **Organization** scope. Every guard that existed passed for all three on all five rows.
**Impact:** a rejected leave request could be flipped to approved on a single signature, with `hrms_leave_approval_steps` — and therefore the approval chain the screen renders — still saying *rejected at step 1*. `bulkDecision()` did the same for a whole list at once. `cancel()` and `destroy()` have always guarded status; `decision()` was the outlier.
**How it was found:** an adversarial review of Sprint 6's own work, run as a five-dimension fan-out with independent verifiers. 36 candidates, 15 survived verification, this was the only critical. **Not** found by `probe-sprint6.sh`, which walked one request forward through a chain and never tried to walk a finished one backwards.
**Re-verify:** `bash Docs/hrit-audit/_evidence/probe-sprint6.sh` — section 9.
**Status:** ✅ **CLOSED in Sprint 6**, in the same sprint that opened it. `decision()` refuses anything that is not `pending` with a 422; `bulkDecision()` filters to `pending` in its query.

#### F-127 — "Send back" destroyed the chain and could never be undone — HIGH
**What:** `recordDecision()` treated every non-approval as a rejection and skipped all remaining steps, and `store()`'s re-apply upsert matched only `status = 'pending'`.
**Where:** [LeaveApprovalWorkflow.php](../../app/Services/Leave/LeaveApprovalWorkflow.php) `recordDecision()`; [LeaveRequestApiController.php](../../app/Http/Controllers/Api/Leave/LeaveRequestApiController.php) `store()`.
**Impact:** `sent_back` means *amend this and resubmit*, so the chain must survive and restart. Instead it was closed, and nothing reopened it — a sent-back request could never be approved again. Worse, re-submitting the same dates created a **second** leave row, because the upsert did not match a `sent_back` row: the employee ended up with two requests and the first stuck for ever.
**Also:** a step's `status` could not express the difference between *rejected*, *cancelled* and *sent back*, so the chain told an employee their request had been **rejected** when it had been sent back for a missing handover note.
**How it was found:** the same review. No probe exercised `sent_back` — it is in `DECISION_STATUSES` and was never tested.
**Re-verify:** `probe-sprint6.sh` section 10.
**Status:** ✅ **CLOSED in Sprint 6.** A `decision` column records what was actually chosen; `sent_back` returns the chain to step 1 via `reopenFor()`; the upsert matches `pending` **and** `sent_back`. Proven: send back → resubmit the same dates → **one** row, chain restarted, then approved through both steps.

#### F-123 — Two live leave requests have no dates, no reason and no leave type — MEDIUM
**What:** `hrms_emp_leaves` permits a leave request with everything nullable, and two such rows exist on live.
**Where:** [2025_09_11_053231_hrms_emp_leaves.php](../../database/migrations/2025_09_11_053231_hrms_emp_leaves.php) — `from_date`, `to_date`, `user_id`, `leave_type_id` and `comment` are all nullable with no default.
**Evidence:** found by Sprint 4's `chargeable_days` backfill, which had to skip them:
```
id=12  tenant 6  user 28  from_date NULL  to_date NULL  comment NULL  status pending  created 2026-01-02
id=18  tenant 3  user  6  from_date NULL  to_date NULL  comment NULL  status pending  created 2026-03-04
```
**Impact:** two requests that have been sitting `pending` for months. They cannot be approved meaningfully, cost nothing, appear in the pending count on every dashboard, and are invisible to any date filter — so nobody looking at a date range will ever see them to clear them. Small in number, but they are the same shape as F-94's 15 mis-typed rows: the table accepts data the product cannot use.
**Note:** `store()` has always validated `from_date` as required, so these did not come through the API being fixed in this sprint. Something else wrote them — the legacy Blade screen or a direct insert.
**Re-verify:** `select id, from_date, comment, status from hrms_emp_leaves where from_date is null;`
**Fix sketch:** decide with the tenant whether to cancel or complete them (Q6), then `NOT NULL` on `from_date`, `user_id` and `leave_type_id` so the table stops accepting a request that is not one. Sprint 5, alongside the F-94 repair.
**Status:** ✅ **CLOSED in Sprint 5** — closed with F-94 by the same migration. The two dateless rows were soft-deleted and `from_date` made `NOT NULL`.

#### F-120 — Attendance reports are readable by every role — HIGH
**What:** the legacy attendance routes carry no role gate, exactly as payroll did before F-91.
**Where:** [routes/hrms.php](../../routes/hrms.php) — `departmentwise-attendance-report/create`, `show-early-going-hrms-attendance-report`, `hrms-attendance-report`, `get-employees-list` sit in the `['auth','session','menu']` group with no `hrit.role`.
**Evidence:** an `employee` token returned 200 from both endpoints the Attendance Reports screen uses, with every employee's attendance totals (`probe-reads2.out`, section F).
**Impact:** the nav shows Attendance Reports to admin/HR/oversight only; the API shows it to everyone. Same class as F-91, different module.
**Why not fixed in Sprint 1:** employees legitimately punch in and out through neighbouring routes in that same group, so the gate has to be drawn per route rather than per group, and the screens that call them are being reworked in Sprint 3 anyway. Gating them blind now risks breaking the punch path this sprint cannot re-test.
**Fix sketch:** `hrit.role:admin,hr` on the report routes only, leaving `hrms-attendance*` punch routes open. Sprint 3.
**Status:** ✅ **CLOSED in Sprint 3** — gated on **both** surfaces, because closing one would move the hole rather than shut it: the legacy routes in `routes/hrms.php` via `hrit.role:admin,hr,executive,auditor`, and the four `/api/attendance/*` report endpoints the screen actually reads via `profile:` with the same list. That list matches the REPORTING group in the frontend's `gtg-nav-visibility.ts` exactly, so the menu and the API now agree about who may read a report.

Drawn per route, not per group, exactly as the deferral said it would have to be. Self service stays open and was re-tested: `my-attendance`, `self-summary`, `regularisations` and the legacy punch screen all still answer an employee. Verified 16/16 in `_evidence/probe-sprint3.out`, including `auditor` and `executive` — the read-only oversight roles — being **admitted**, since hiding the reports from them would remove the one thing those roles exist to look at.

#### F-121 — Monthly Payroll Report is too slow to use — HIGH
**What:** an N+1 against a database on another host. `getTotalDays()` ran one COUNT query **per day, per employee**, and `monthlyPayrollCreate()` calls it once per employee.
**Where:** [PayrollController.php:2712](../../app/Http/Controllers/Payroll/PayrollController.php#L2712)
**Evidence:** one month for tenant 3 is 26 non-Sunday days × 122 employees = **~3,172 attendance queries**, plus ~370 more for the per-employee holiday, leave, user and saved-payslip lookups. Measured round trip to `202.47.117.220`: **39.7 ms**, so the attendance loop alone was ~126 s of pure latency.

| | 3 consecutive runs |
|---|---|
| Before | **500** at 60.5 s, 61.0 s, 66.1 s — `Maximum execution time of 60 seconds exceeded` |
| After the Sprint 2 fix | **200** at 58.7 s, 39.5 s, 30.8 s — output **byte-identical** (279,817 bytes) |

**Sprint 8 — two more N+1s removed, and NOT claimed as closed.**

Two per-employee loops were collapsed to one query each:

| Where | Was | Now |
|---|---|---|
| `PayrollController@monthlyPayrollCreate` | one `employee_monthly_salary_data` SELECT **per employee** | one `whereIn`, keyed by `employee_id` |
| `helpers.php@employeeDetails` | one `hrms_departments` SELECT **per employee** | one `pluck('department','id')` |

The second matters more than its count suggests: `employeeDetails()` is **shared** by monthly
payroll, the payroll register and several HRMS screens, so the same waste was being paid on each
of them. At tenant 3's 122 active employees and a measured 39.7 ms round trip, the two loops
together were ~244 queries ≈ **9.7 s of pure latency** on this endpoint alone.

**RE-MEASURED AT THE FULL ROSTER — and the reason it was hard to reproduce turned out to be a
separate, worse defect.**

The 2-employee response was not a test artefact. It is what the product actually did: see **F-132**
below. Once that was fixed, the audit's own case reproduced exactly — 122 employees, 280,170 bytes
against the audit's 279,817 (the data has moved slightly since).

| | 3 consecutive runs, tenant 3, 122 employees |
|---|---|
| Audit (before Sprint 2) | **500** at 60.5 s, 61.0 s, 66.1 s — execution time exceeded |
| After Sprint 2 | **200** at 58.7 s, 39.5 s, 30.8 s |
| **After Sprint 8** | **200** at **2.78 s, 2.96 s, 2.70 s** — 280,170 bytes, byte-identical across runs |

**Roughly 11× faster than Sprint 2, and 22× faster than the timeout it started as.**

The helper change was verified against the database rather than eyeballed: the response carries 118
named departments and 4 `'-'` fallbacks, and the database independently reports exactly 4 employees
whose `department_id` matches no active department in tenant 3.

**Status:** ✅ **CLOSED in Sprint 8.**

**Impact:** before the fix the screen was unusable again, by a different 500 from the one F-93 closed. It is now reliably 200 and still too slow: 31–59 s at 122 employees, against a brief that names tenants with 3,000+.
**Fixed so far (Sprint 2):** the per-day attendance loop collapsed to one grouped query — ~3,172 queries removed, 89% of the total — and a `$userData` lookup that was assigned and never read deleted. Deliberately kept the same shape (COUNT per day, not a presence check, no new soft-delete filter) because `$totalAtt` sums these; this is a performance fix, not a change of answer.
**Still open:** ~370 per-employee round trips remain — holidays, leaves, saved payslip and department, each hoistable into one grouped query. **Sprint 6.**
**Re-verify:** `curl -s -m 200 -w '%{http_code} %{time_total}
' "…/monthly-payroll/create?type=API&token=<hr>&sub_institute_id=3&user_id=67&month=Apr&year=2026"`

#### F-122 — Every unauthenticated browser hit on an HRMS route is a 500 — MEDIUM
**Status:** ✅ **CLOSED in Sprint 8.** One word: `route('login')` → `route('login.index')`. Proven: an unauthenticated browser hit on `/monthly-payroll` now answers **302 → /login** instead of 500, and an API hit still answers **401** rather than being redirected. The URI *is* `/login`, which is exactly what made the wrong name look right.
**What:** `authMiddleware` redirects unauthenticated browsers to a route name that does not exist.
**Where:** [authMiddleware.php:42](../../app/Http/Middleware/authMiddleware.php#L42) — `return redirect()->route('login');`
**Evidence:** the route is registered as **`login.index`**, not `login` (`php artisan route:list --name=login`). So:
```
GET /payroll-type             (browser, no session) -> 500  Route [login] not defined
GET /hrms-job-title           (browser, no session) -> 500  Route [login] not defined
GET /departmentwise-emplist   (browser, no session) -> 500  Route [login] not defined
```
**Impact:** a logged-out user opening any Blade HRMS URL gets a stack trace instead of the login page. Platform-wide, not HRIT-specific — every route behind this middleware is affected.
**Note:** found while checking that Sprint 1's payroll gate had not broken the browser path. It had not — the three routes above include two this sprint never touched, which is how it was identified as pre-existing rather than introduced.
**Fix sketch:** `redirect()->route('login.index')`, or name the GET route `login`. One line, but it is outside HRIT's blast radius — raise with the platform owner rather than changing it inside a module sprint.

---

#### F-112 — Twelve controls that do nothing when clicked — MEDIUM
**What:** buttons and menu items rendered as live controls with empty or logging handlers.
**Where:**
| Control | File:line |
|---|---|
| 5 × Quick Actions (Apply Leave, Regularize, Mark WFH, Download Timesheet, View Monthly Report) | [attendance-tracking/page.tsx:91-97](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L91) |
| `MyRequestsWidget onViewAll` | [attendance-tracking/page.tsx:203](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L203) |
| Row "eye" action, no `onClick` | [attendance-reports/page.tsx:219-226](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-reports/page.tsx#L219) |
| Export / Print / Saved report | attendance-reports/page.tsx:353-363 (also F-99) |
| "Customize Columns" | [leave-requests/page.tsx:429](../../../g2gv0/components/domain/hrms/hrit/leave-management/leave-requests/page.tsx#L429) |
| "View" in Recent Leave Requests | [RecentLeaveRequests.tsx:102](../../../g2gv0/components/domain/hrms/hrit/leave-management/leave-dashboard/components/RecentLeaveRequests.tsx#L102) |
**Impact:** the user believes they performed an action. Apply Leave in particular has a working drawer three files away.
**Fix sketch:** wire each to the endpoint or drawer that already exists; delete the ones with no destination.
**Status:** 🟠 **PARTLY closed in Sprint 2 — 6 of 12.**

> **CORRECTED 2026-09-05, at the start of Sprint 3.** Sprint 2 marked this finding ✅ CLOSED. That
> was wrong, and it is the exact defect this audit exists to catch, committed by the person writing
> it: F-112 lists **twelve controls across three screens**, and Sprint 2 only touched Attendance
> Tracking. Six were still `console.log` or had no handler when the sprint was declared done.
>
> | Control | Screen | State |
> |---|---|---|
> | 5 × Quick Actions | Attendance Tracking | ✅ Sprint 2 |
> | `MyRequestsWidget onViewAll` | Attendance Tracking | ✅ Sprint 2 |
> | Export / Print / Saved report | Attendance **Reports** | ❌ still `console.log` |
> | Row "eye" action | Attendance **Reports** | ❌ still no `onClick` |
> | "Customize Columns" | Leave Requests | ❌ still inert |
> | "View" | Leave Dashboard | ❌ still `console.log` |
>
> A finding that spans screens cannot be closed by a sprint that fixes one of them. Sprint 3 closed
> the four on Attendance Reports; Sprint 5 closed the last two.

**Status:** ✅ **CLOSED in Sprint 5 — 12 of 12.** The Leave Dashboard's "View" now opens the detail
drawer that page already rendered and already had a handler for; it had simply never been passed to
the component. "Customize Columns" on Leave Requests now lists the eight optional columns and toggles
them, remembered per browser.

#### F-113 — The dashboard date is hardcoded — MEDIUM
**Where:** [attendance-tracking/page.tsx:81](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L81)
**Evidence:** `const CURRENT_DATE_LABEL = 'Today, 22 Jun 2026'` — rendered at :167 on the calendar button.
**Impact:** the primary attendance screen states the wrong date every day except one.
**Status:** ✅ **CLOSED in Sprint 2** — the date comes from the clock; the ring and its caption come from `shift.expectedMinutes`, and an employee with no roster is told so instead of being shown an invented 8h30m.

#### F-114 — "Saved" reports are not saved — MEDIUM
**Where:** [leave-reports/page.tsx:88-90](../../../g2gv0/components/domain/hrms/hrit/leave-management/leave-reports/page.tsx#L88)
**Evidence:** `useState(() => new Set(reports.filter(r => r.saved).map(r => r.id)))` — component state seeded from a static catalogue, no persistence.
**Impact:** the Saved tab resets on every refresh and is identical for every user.
**Status:** ✅ **CLOSED in Sprint 5** — the Saved tab persists per browser. It was component state seeded from a static `report.saved` flag, so it reset on refresh and showed every user the same thing. A starred report is a per-person display preference, not tenant configuration, so it lives in the browser rather than in a new table. Every read and write is wrapped — storage throws outright in a private window rather than returning null.

#### F-115 — Location always reads "Office" — MEDIUM
**Where:** [use-attendance.ts:75](../../../g2gv0/hooks/use-attendance.ts#L75), [attendance-tracking/page.tsx:456](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-tracking/page.tsx#L456)
**Evidence:** `location: entry.ipaddress_in ? 'Office' : undefined`, then `{record.location || 'Office'}`.
**Impact:** a Location column that is a constant, next to a Mark WFH button that does nothing (F-112).
**Status:** ✅ **CLOSED in Sprint 2** — `hrms_attendances.work_mode` added (office / home / field). The Location column renders the real value and the punch control sets it.

#### F-116 — An unrecognised attendance status renders as Present — MEDIUM
**Where:** [use-attendance.ts:74](../../../g2gv0/hooks/use-attendance.ts#L74)
**Evidence:** `status: apiStatus && KNOWN_STATUSES.includes(apiStatus) ? apiStatus : 'present'`
**Impact:** the backend emits `half_day` in places while the frontend's set contains `'half-day'`; any mismatch silently becomes **Present**. A defaulting rule for a status should never default to the favourable value.
**Status:** ✅ **CLOSED in Sprint 2** — an unrecognised status is now `undefined` and renders as "Unknown". `half_day` is aliased to `half-day` rather than falling through. A defaulting rule for a status must never default to the flattering answer.

#### F-117 — The error retry punches instead of retrying — MEDIUM
**Where:** [use-attendance.ts:166-169](../../../g2gv0/hooks/use-attendance.ts#L166)
**Evidence:**
```ts
const retry = () => { setError(null); punch(todayRecord?.punchIn && !todayRecord?.punchOut ? 'out' : 'in') }
```
`retry` is returned by the hook after a *load* failure, and calls `punch()`. **Impact:** retrying a failed page load writes an attendance punch. Currently unexploded because no component binds `retry`.
**Status:** ✅ **CLOSED in Sprint 2** — `retry()` reloads. It used to call `punch()` — a retry offered after a failed *load* that wrote an attendance punch.

#### F-118 — Service methods pointing at routes that do not exist — MEDIUM
**Where:** [services/hrms/index.ts:237-240,297-300](../../../g2gv0/services/hrms/index.ts#L237)
**Evidence:** `getAttendanceRecords` → `/attendance`, `checkIn` → `/attendance/check-in`, `checkOut` → `/attendance/check-out`, `getComplianceItems` → `/compliance`, `updateComplianceStatus` → `/compliance/{id}`. None is registered: `php artisan route:list --path=attendance` lists only `my-attendance`, `punch-in`, `punch-out`, `report-filters`, `employees`, `weekly-summary`, `kpi`.
**Impact:** five exported methods that 404 on call. Dead surface area that reads as capability.
**Re-verified in Sprint 8:** all five confirmed dead — `php artisan route:list --path=api/attendance` lists `my-attendance`, `punch-in`, `punch-out`, `self-summary`, `regularisations`, `kpi`, `weekly-summary`, `report-filters`, `employees`; there is no bare `/attendance`, no `check-in`/`check-out`, and no `/compliance` surface at all. A repo-wide search found **no caller** for any of them.
**Status:** ✅ **CLOSED in Sprint 8.** All five deleted, with the reasoning left in their place. `checkIn`/`checkOut` were an earlier generation of `punchIn`/`punchOut`, which work — keeping both meant the file advertised a 404 beside the method that succeeds, which is how somebody picks the wrong one. Removed rather than repointed: a second name for `punchIn` is a duplicate, not a fix.

#### F-119 — Mock datasets still exported from the report service — MEDIUM
**Where:** [report-data.ts:43,241,299](../../../g2gv0/components/domain/hrms/hrit/attendance-management/attendance-reports/services/report-data.ts#L43)
**Evidence:** `earlyGoingMockData`, `departmentReportMockData`, `employeeReportMockData` — three fully-populated fixture arrays ("Amit Sharma", "Engineering", 45 employees). No component imports them today; `savedReports` from the same file *is* imported and rendered (F-99).
**Impact:** one import away from a screen rendering invented departments again. The page's own comment at :336 — *"API rows only - the report never falls back to sample data"* — is true and is one line away from ceasing to be.
**Status:** ✅ **CLOSED in Sprint 3** — `services/report-data.ts` deleted outright. The three fixture datasets had no importer; `savedReports` did, and its control is gone. The one type worth keeping, `EarlyGoingRecord`, moved to `attendance-reports/types.ts`, where nothing can accidentally import a fixture beside it.

---

## 10. WORKFLOW GAPS, RANKED BY STRANDED WORK

1. **Leave entitlement → balance → every rule downstream** (F-96). No screen creates an entitlement,
   so no balance is real, so no over-application check can be written, so no LWP figure can reach
   payroll. One missing form strands the whole leave lifecycle. *Largest gap in the module.*
2. **Approval decision → nothing** (F-87, F-89, F-103). Approval writes a status. It consults no
   permission, honours no workflow, notifies nobody, and decrements nothing. Two configured tables
   (21 + 3 rows) and two complete screens are stranded behind it.
3. **Attendance → payroll** (F-93). The only bridge is `getTotalDays()`, reached exclusively through
   a screen that 500s. Every attendance row ever captured is stranded short of payroll.
4. **Holidays and weekly-offs → day count** (F-95). 39 rows of tenant-maintained configuration
   change no number anywhere.
5. **Regularisation** (F-107). Backend built, no caller. One screen away from working.
6. **Payroll → employee** (Part D). Payslips and certificates are generated and never delivered;
   the employee has no surface to receive them.

---

## 11. OPEN QUESTIONS — not guessed

- **Q1. Should a flat pay-head cap mean "pay the excess over the cap" or "clamp to the cap"?** (F-111)
  Tenant 47 gets the first, every other tenant gets the second, and the difference is a hardcoded id.
  Tenant 47 is a **real institute with 597 users and 924 salary structures** on
  `lms.triz.co.in/triz_erp_21`, so this is a live pay difference, not dead code. Needs the person who
  wrote it or the customer's contract. **Do not "unify" the two branches blind** — one of them is
  somebody's payslip.
- **Q2. Which table is the intended shift source** — `hrms_in_out_times` (0 rows, no migration) or
  `tbluser_shift_master` (absent, has controllers)? (F-105) A product decision, not a code one.
- **Q3. Cross-tenant fetch by id.** Isolation passed on list endpoints. Fetching tenant B's record
  by id was not conclusively tested — the tenant 6 ids available fall outside tenant 3's leave-year
  window, so a 404 would be ambiguous. Needs a seeded pair of comparable rows in two tenants.
- **Q4. The 15 mis-typed leave rows** (F-94). Repair to a real leave type, or quarantine? Only the
  tenant knows what those 15 requests were meant to be. They were bulk-created on 2026-06-19.
- **Q7. The two dateless leave requests** (F-123, ids 12 and 18). Cancel them, or ask the two
  employees what they meant to book? Both have been pending for months and neither has a comment to
  go on. A tenant decision, not a code one.
- **Q5. Is `hrms_salary_certificate` unused or unusable?** (F-110) Needs one full generation
  attempt with a real HR user on a tenant that has salary structures.
- **Q6. Which of the five duplicated controller pairs (§E.0) are still called by anything** — a
  mobile app or integration outside these two repos would not appear in either codebase.

---

## 12. MASTER-SHEET ROW

| Module | Front door | Lifecycle | Roles | External | Data live | API | CRUD | Validation | Rules | RBAC/Tenant | Integration | Calc | Scale | Errors | Audit | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| HRIT Management (m5) | 🟠 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🟠 | 🔴 | 🔴 | 🔴 / 🟢 | 🔴 | 🔴 | 🟠 | 🟠 | 🟠 | **RED** |

---

## 13. RELEASE GATE

```
Front door reachable by the role that starts it   ✗  leave entitlement has no screen (F-96)
360° lifecycle complete, every handoff wired      ✗  6 NOT-WIRED
Every role journey walks end to end               ✗  5 of 9 roles absent from the frontend model
External actors can do what the module needs      ✗  no employee self-service half
Live data — no fixtures rendered                  ✗  F-97, F-98, F-100
API + CRUD complete                               ✗  monthly payroll 500s (F-93)
Validation at API and DB, not only the browser    ✗  F-101, F-102, F-106
Business rules correct                            ✗  F-95, F-96
Tenant isolation proven with two tenants          ✓  PASS (list endpoints; see Q3)
RBAC proven at the API, not the menu              ✗  five holes executed
Cross-module data flow proven                     ✗  attendance→payroll dead
Calculations independently reconciled             ✗  3 of 5 FAIL
Error handling + audit trail                      ✗  raw SQL leaked; no before/after trail
Scale tested at realistic volume                  —  not reached
Golden transactions pass                          ✗  see §14
Domain sign-off                                   —  not sought
```

---

## 14. PART F — GOLDEN TRANSACTIONS

| Story | Expected | Actual | Verdict |
|---|---|---|---|
| Apply → approve → balance falls | balance decrements by the working days taken | applied ✓, approved by **the applicant** ✓, balance unchanged (entitlement 0) | **FAIL** |
| Half-day leave | 0.5 deducted | `day_type='0.5'`, `to_date` forced to `from_date` — correct | PASS |
| Leave spanning a holiday | holiday excluded | counted as a working day (F-95) | **FAIL** |
| Leave beyond balance | refused | 365 days accepted (F-102) | **FAIL** |
| Leave in a closed period | refused | 1990 accepted (F-102) | **FAIL** |
| Cancel after approval | status `cancelled`, balance returned | no path exists | **FAIL** |
| Punch in → punch out → hours | duration recorded | works; `timestamp_diff` correct | PASS |
| Missing punch-out → regularise | employee raises, manager approves | no path (F-107) | **FAIL** |
| Run a month's payroll | payslips created | **HTTP 500** (F-93) | **FAIL** |
| Mid-month joiner | pro-rated | unreachable behind F-93 | not reached |
| Unpaid leave → payslip | LWP reduces net | unreachable behind F-93 | not reached |
| Employee downloads payslip | PDF | no employee surface | **FAIL** |

---

## 15. PART G — NEGATIVE TESTING

| Case | Result |
|---|---|
| Missing required field (`comment`) | **422** with a usable message ✓ |
| `to_date` before `from_date` | **422** ✓ |
| Empty `leave_type` | **422** ✓ |
| Very long text (300 chars) | **422** ✓ … but 40 chars → **500** (F-106) |
| Emoji + Gujarati + combining diacritic | **stored byte-identical** ✓ |
| Another tenant's `leave_type_id` | **201 accepted** ✗ (F-101) |
| Another tenant's data by `sub_institute_id` | **ignored, own tenant returned** ✓ |
| Reference to a non-existent leave type | **already in production**, 15 rows ✗ (F-94) |
| No token | **401** on every HRIT endpoint ✓ |
| Wrong `syear` | silently normalised to the current leave year — no error, no warning |
| Double-click Save | leave apply is idempotent on `(user_id, from_date, pending)`; payroll save is **not** (F-109) |
| Expired token | rejected — `ResolvesApiIdentity` checks `expires_at`, unlike `authMiddleware` |

*Not run: refresh-during-save, network drop mid-save, two users editing one row, back button after
submit. These need a browser session and belong with the Sprint 8 hardening suite.*

---

## 16. EVIDENCE INDEX

| File | What it holds |
|---|---|
| `_evidence/mint-audit-tokens.php` | mints one Sanctum token per `role_key` (named `hrit-audit`) |
| `_evidence/tokens.tsv` | tenant · role · user id · token — **deleted after the audit; `.gitignore`d** |
| `_evidence/probe-reads.sh` / `.out` | RBAC read probes, tenant isolation, anonymous access |
| `_evidence/probe-reads2.sh` / `.out` | attendance + payroll across roles; the 500s |
| `_evidence/probe-writes.sh` / `.out` | the five executed authorization holes |
| `_evidence/probe-validation.sh` / `.out` | E.1 validation matrix, Part G negatives |
| `_evidence/snapshot.php` | read-only SQL against the `.env` host, `SET NAMES utf8mb4` |
| `_evidence/before-219.json` | pre-probe state of the row F-88 deleted |
| `_local-backups/REVERSAL-2026-09-05-hrit-audit-probes.sql` | the reversal, already applied |

**Housekeeping — done, not owed.** All eleven `hrit-audit` tokens were revoked at the end of this
session (`delete from personal_access_tokens where name='hrit-audit'` → 11 rows, 0 remaining), and
`tokens.tsv` was deleted and added to `.gitignore`. To reproduce any probe, re-mint with
`php artisan tinker --execute="require '<abs path>/Docs/hrit-audit/_evidence/mint-audit-tokens.php';"`.
