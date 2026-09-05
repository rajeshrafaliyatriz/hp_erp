# Sprint 1 — Authorization and identity

**Closed:** F-87, F-88, F-89, F-90, F-91, F-92, F-93, F-100, F-103, F-104 — **10 of 33**.
**Raised:** F-120, F-121, F-122.
**Live changes:** one migration (+ reversal), no data edits left standing.

---

## What this sprint was for

The audit executed five authorization holes against the live database. This sprint closes them,
and closes the two that made them worse: payroll had no server-side gate at all, and the frontend
was deciding who you are by pattern-matching your job title.

The organising idea: **`hrms_leave_role_permissions` already said who may do what.** It had a
working screen, 21 populated rows per tenant, and no reader. Sprint 1 does not invent a permission
model — it connects the one the product already ships.

---

## The five holes, before and after

Same script, same tenant, same user — `Docs/hrit-audit/_evidence/probe-writes.sh`, run as tenant 3
user 7, whose own row in the matrix reads `approve_leave = 0, scope = 'Self'`.

| # | Probe | Before | After |
|---|---|---|---|
| W1 | Employee applies for their own leave | 201 | **201** — still works, deliberately |
| W2 | The same employee approves their own leave | **200** | **403** `You do not have permission to decide leave requests.` |
| W3 | Employee withdraws a colleague's request | **200** | **403** `You can only withdraw your own leave request.` |
| W4 | Employee creates a leave type | **201** | **403** `You do not have permission to change leave types.` |
| W5 | Employee grants themselves org-wide rights | **200** | **403** `You do not have permission to change leave role permissions.` |

W1 matters as much as the rest: a gate that also blocks the person the screen is for is not a fix.

---

## How it works now

### `App\Support\RoleKey` — one answer to "what role is this user?"

`role_key` is the stable identifier; the display name is a label a tenant can edit. `RequireProfile`
already knew that and documented it; nothing else did. Resolution is now in one place: `role_key`,
then an **exact** legacy-name match, then null — and null grants nothing. There is no substring step.

`RequireProfile` was refactored onto it rather than keeping a second copy of the alias table.
Its HTTP behaviour is unchanged.

### `ResolvesLeaveAuthority` — the matrix, made load-bearing

Answers two questions: *may this caller do X* (the six permission columns) and *to whom* (the scope
column — Self, Team, Department, Organization). Applied to:

- `decision` / `bulkDecision` — `approve_leave`, plus scope, plus **never your own request**
- `bulkDecision` — additionally `bulk_operations`
- `destroy` — ownership; an approver cancelling someone else's leave is a *decision*, not a withdrawal
- `index`, `show` — scope, with out-of-scope answering **404 rather than 403**, so a refusal does
  not confirm that a particular colleague has a leave request
- `LeaveReportApiController::filtered()` and `balance()` — the same scope. Closing F-103 on
  `/requests` alone would have moved the hole, not shut it: the register returns identical rows
- all six queries in `LeaveDashboardController`, via one `scopedRequests()` helper — its activity
  feed renders named rows
- every write in `LeaveTypeApiController`, `HolidayApiController`, `LeaveWorkflowApiController` —
  `configure_settings`

**One deliberate escape hatch.** `administrator` always keeps `configure_settings`. `saveRoles`
already refuses a matrix where nobody can configure, but it does not stop an administrator removing
it from *themselves* — an unrecoverable lockout fixable only by a DBA. The hatch is narrow: it does
**not** grant `approve_leave`, so an administrator deliberately kept out of the approval chain
stays out. The matrix is allowed to say that.

### Payroll: a gate on the server

`hrit.role:admin,hr` on both payroll route groups. It is a new middleware rather than the existing
`profile:` because these URLs serve two surfaces — the token-authenticated frontend and the
session-authenticated Blade screens — and `profile:` 401s anything without a token. `RequireHritRole`
extends `RequireProfile` and overrides only identity resolution: token, then session, **never the
request body**. The same order `payrollTenantId()` already used for the tenant.

The attendance routes in the same file are deliberately *not* gated — employees punch in and out
through some of them. Their own exposure is **F-120**, Sprint 3.

### Password hashes

`$hidden = ['password', 'remember_token', 'otp']` on both `tbluser` models. `employeeDetails()`
selects `tbluser.*` and its output is returned verbatim by two payroll endpoints; fixing the model
fixes every consumer, which an explicit column list in one helper would not.

Checked before adding: only `authController:42` reads the password, as an attribute
(`Hash::check($password, $user->password)`), which `$hidden` does not affect. The mobile login still
returns `otp` because it reads through `DB::table()`, not the model.

### Monthly Payroll Report opens again (F-93)

`monthlyPayrollCreate()` builds `new Request([...])` with no token and no session and hands it to
`getTotalDays()`, which asked it for the caller's identity. `payrollTenantId()` correctly refuses to
trust a request body, found no token, fell through to `$request->session()`, and a synthetic Request
has no session store: **500 on every call, for every role.**

`getTotalDays()` now takes the tenant as a parameter. The caller already resolved it from the real
request before building the synthetic one.

> This was a **security fix that broke a screen** — the G-SEC-10 tenant hardening swapped ~17 sites
> to `payrollTenantId()`, and two of them were internal calls on a request that has no identity to
> give. Worth remembering when the remaining sprints harden anything else.

### Frontend: nine roles, no guessing

`authController` now sends `role_key`. `types/role.ts` is keyed on the nine role_keys.
`mapProfileNameToRole` prefers it and falls back to an **exact** legacy-name match.

Widening the type turned every `role === 'admin' || role === 'hr'` in the product into a compile
error — 24 of them, in 12 files. That is the compiler listing every screen that had assumed the old
four-role vocabulary. They now call `isHrAdmin(role)`, which resolves to
`['administrator','hr_manager','hr_executive']` — exactly what the old `'admin' | 'hr'` buckets
contained, so no screen's access was silently re-drawn.

`gtg-nav-visibility.ts` was re-keyed. `executive` and `auditor` had been falling through to
`employee`; a naive expansion would have left them seeing **no menu at all**, so they are named
explicitly in a `REPORTING` group — read-only oversight is what those roles are for.

### Two roles the matrix never had

`hrms_leave_role_permissions` seeded seven roles; the platform defines nine. With the table now
governing, "no row" means "deny everything", so `auditor` and `recruiter` would have lost access to
their own leave. Migration `2026_09_05_120000` backfills them for tenants already seeded;
`defaults()` covers new ones. Idempotent, and it skips rows a tenant has since edited.

---

## Verification

`Docs/hrit-audit/_evidence/probe-sprint1.out` — 14 assertions, all PASS.

```
F-91  employee / auditor / recruiter / department_head / reporting_manager -> 403 on payroll
      admin and hr_manager -> 200 on salary structure, payroll type, salary certificate
F-93  monthly-payroll/create -> 200 for admin AND hr_manager (was 500 for both)
F-100 /hrms/myleave/7 and /hrms/leavehistory/7 -> 404
F-92  0 "password" keys and 0 "otp" keys in the salary-structure body
```

**Scope, proven per branch** rather than assumed from an empty result:

| Scope | Test | Result |
|---|---|---|
| Self | users 12, 54, 150 — each has 4, 4, 1 own in-year rows | **4, 4, 1** |
| Department | user 579 in dept 1930 (no leave) → moved to dept 81 (13 rows) → restored | **0 → 13 → restored** |
| Organization | hr_manager, administrator, executive, auditor | **18** each |
| Team | users 580/581 — their reports have no in-year leave | **0**, correctly |

Before this sprint, **every one of those nine roles saw 18.**

Baselines held: `tsc` **2 errors** (both pre-existing, outside HRIT), `next build` **clean**,
`route:list` **1839**. That is not 1834 − 2: two other sessions were adding Talent and LMS routes
to this repo in parallel, so the absolute count moved for reasons unrelated to this sprint. What
Sprint 1 is accountable for is the two sample-data routes it removed, and `route:list --path=myleave`
returns nothing.

---

## Live database

| Change | Reversal |
|---|---|
| Migration `2026_09_05_120000` — Auditor + Recruiter rows for tenants 1, 3, 7 | `_local-backups/REVERSAL-2026-09-05-sprint1-role-backfill.sql`, or `migrate:rollback` |
| Probe rows (1 leave request, created and deleted) | removed in-session |
| User 579's department, moved to prove Department scope | restored in-session; before-state in `_evidence/before-579.json` |

Verified back to baseline: tenant 3 has **29** live leave requests, user 579 is in department
**1930**, and all **14** `hrit-audit` tokens are revoked.

---

## What this sprint did not do, and why

- **F-120** (attendance reports open to every role) — the same class as F-91, but the gate must be
  drawn per route because employees punch through neighbouring routes in that group. Sprint 3, where
  those screens are being reworked and can be re-tested.
- **F-121** (Monthly Payroll 28s) — only visible *because* F-93 was fixed; before, it failed fast.
  Sprint 6.
- **F-122** (unauthenticated browser → 500) — platform-wide, in `authMiddleware`, affecting every
  route behind it. One line, but outside a module sprint's blast radius. Raise with the platform owner.
- **F-111** (tenant 47 in a salary formula) — the id moved to `config/payroll.php`; **the arithmetic
  is byte-identical**. Tenant 47 turned out to be a live institute with 597 users and 924 salary
  structures on a third deployment, so the two branches are somebody's payslip. Q1 stays open.
