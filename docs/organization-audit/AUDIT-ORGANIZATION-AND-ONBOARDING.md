# G2G MODULE INTEGRITY AUDIT — Organization (m1) and Onboarding

Auditor: independent pass, 2026-09-07. Nothing was changed: no edits, no
migrations, no commits. Every write-side finding is read from source; the one
cross-tenant leak below is **demonstrated with a read**, never a write.

Findings continue the platform sequence. Highest existing is **F-132**
(`Docs/hrit-audit/`), so this register starts at **F-133**.

---

## 1. VERDICT — **RED**

> **A brand-new organisation that signs up through this product lands on an
> empty sidebar, and the two screens it would need first refuse everyone who
> opens them.**

That is one sentence with two independent causes, both verified:

- A tenant created by the product's own signup endpoint receives rights to
  11 menus. Simulated against the real `displaySidebarMenu`, tenant `xyz`
  (1000011) renders **0 modules**. So does `Fiber Valley` (1000018) — which has
  **967 employees**.
- Organization Profile and Department Management are mounted without the `role`
  prop they require, so both return `AccessDenied` **for every user including
  the administrator**, while `DepartmentManagementController` holds 1,290 lines
  of working functionality nobody can reach.

RED is not a judgement about effort. Employee Directory, Role & Permissions and
the entire Talent employee-onboarding module are genuinely production-grade. The
verdict is RED because the module's **front door does not open**, and because a
tenant can read another tenant's employee list with a valid token.

---

## 2. SCORECARD

| Area | Verdict | One-line reason |
|---|---|---|
| **Front door** | **RED** | A fresh tenant's admin sees 0 modules; the two org screens refuse everyone |
| **360° lifecycle** | **RED** | Setup → profile → departments → people is broken at the first two stages |
| **Role journeys** | **RED** | 6 of 9 roles do not exist on 10 of 12 live tenants (`role_key` NULL) |
| **External actors** | **AMBER** | Offer + assessment portals are real; no new-hire self-service capture |
| 1 · Data source | **RED** | Fixture data rendered as tenant data on 3 screens; 1 dropdown has no real source at all |
| 2 · API integrity | GREEN | Routes, methods and shapes agree where they exist |
| 3 · CRUD completeness | AMBER | Departments/employees/rights complete; sister companies have no create path |
| 4 · Validation | AMBER | API-layer validation present; browser-only in the setup wizard (which persists nothing) |
| 5 · Business rules | AMBER | Department hierarchy + cycle prevention are correct; no rule engine for setup order |
| 6 · Data integrity | AMBER | FK + transactions good in `EmployeeFactory`; `assigned_to` writes a name into an int column |
| 7 · Error handling | AMBER | Dashboard `empty_reason` is exemplary; the wizard swallows save failures silently |
| 8 · Real data / scale | AMBER | 967-employee tenant renders an empty sidebar; directory itself paginates correctly |
| **9 · RBAC + tenant isolation** | **RED** | **P0 demonstrated**: cross-tenant read of compliance records + 122 employees |
| 10 · Integration | AMBER | `employee.hired` → onboarding journey works; setup wizard reaches nothing |
| 11 · Workflow integrity | **RED** | No server-side record that setup happened; "Go Live" writes `localStorage` |
| 12 · Calculation | N/A | The module computes nothing a person would dispute |
| 13 · Audit trail | AMBER | `EmployeeFactory` records events; disciplinary `created_by` is caller-supplied |
| 14 · UX readiness | AMBER | Good empty states on dashboards; mojibake and 3 conflicting step counts in setup |
| 15 · Production readiness | AMBER | No tests on either side for this module (`tests/Feature` holds only `ExampleTest`) |

---

## 3. PART A — THE FRONT DOOR

**Where the module begins.** `/module/organizational-management` (menu 1), a
container. The first real screen is Organization Profile (menu 12) or Department
Management (menu 13).

**Who starts it.** `administrator`, immediately after signup.

**Can they find it?** *Partly, and then it fails.* `DEFAULT_MENU_RIGHTS`
(`SchoolSetupController.php:283`) grants the admin Department Management (13) but
**not** Organization Profile (12). And the granted screen is one of the two that
refuse everyone. Demonstrated:

```
tenant 6      (set up)         rights=542   modules in sidebar = 8
tenant 1000011 (fresh signup)  rights=2     modules in sidebar = 0
tenant 1000018 (967 employees) rights=0     modules in sidebar = 0
```

*Re-verify:* `php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-frontdoor.php';"`

**First thing they create.** Nothing — there is no create path. `org_details`
holds **4 rows for 12 live tenants**; 8 organisations have no profile, and no
screen creates one. Organization Profile is an *edit* screen for a record that
signup does not make.

**A way in that nobody built.** Yes: the tenant itself. `POST /api/school-setup`
(`routes/api.php:1617`) creates organisations, carries **no auth middleware**,
and has no frontend anywhere in `g2gv0`.

---

## 4. PART B — THE 360° LIFECYCLE

| # | Stage | Who acts | FRONTEND | BACKEND | DATABASE | WIRED? | Handoff | Break |
|---|---|---|---|---|---|---|---|---|
| 1 | Create the organisation | — | **MISSING** | `SchoolSetupController.php:44` | `school_setup` 12 rows | no UI | none | **FE-MISSING** |
| 2 | Choose modules | admin | `module-configuration-page.tsx:29` hardcoded | **MISSING** | **MISSING** | no | none | **BE+DB-MISSING** |
| 3 | Organisation identity | admin | `organization-information.tsx:55` | `organizationDetailsController.php:41,89` | `org_details` **4 rows / 12 tenants** | **screen refuses everyone** | — | **NOT-WIRED** |
| 4 | Sister companies | admin | button at `:294` has no `onClick` | `organizationDetailsController.php:168` | `org_sister_details` 4 | no | — | **FE-MISSING** |
| 5 | Departments | admin/HR | `department-list.tsx:249` | `DepartmentManagementController.php` (1290 lines) | `hrms_departments` 1,235 | **screen refuses everyone** | — | **NOT-WIRED** |
| 6 | Dept SOPs / policies / rules | HR | tabs wired ✅ | `:176` | sops **1**, policies **0**, rules **0** platform-wide | yes | — | **DEAD-DATA** |
| 7 | Roles exist | — | — | `Phase3RoleSeeder` | **6 of 9 roles missing on 10 of 12 tenants** | never run on live | breaks `profile:` gates | **DEAD-DATA** |
| 8 | Rights per role | admin | `role-permissions.tsx:35` ✅ | `tblmenumasterG2gController.php:195` ✅ | `tblgroupwise_rights_g2g` — **4,238 of 4,759 rows have NULL tenant** | yes | ✅ | GREEN (data rot) |
| 9 | Create employees | admin/HR | `employee-directory.tsx:90` ✅ | `EmployeeDirectoryController` + `EmployeeFactory.php:82` ✅ | `tbluser` 299 ✅ | ✅ | `employee.hired` ✅ | **GREEN** |
| 10 | Reporting line | admin/HR | no field | — | `reporting_manager_id` **0 of 299** | no | blocks leave `Team` scope | **DEAD-DATA** |
| 11 | Employee onboarding | HR | 4,800 lines ✅ | 7 controllers ✅ | `talent_onboarding_*` ✅ | ✅ | ✅ | **GREEN** |
| 12 | Compliance library | HR | `compliance-library-management.tsx:57` | `instituteDetailController.php:206` | `master_compliance` 20, **tenant 6 = 0** | yes, but tenant-unsafe | — | **DEAD-DATA + P0** |
| 13 | Disciplinary library | HR | `disciplinary-management.tsx:192` | `discliplinaryManagementController.php:173` | `discliplinary_management` 6, **tenant 6 = 0** | write path tenant-unsafe | — | **DEAD-DATA** |
| 14 | Record that setup is done | admin | `localStorage` only | **MISSING** | **MISSING** | no | none | **BE+DB-MISSING** |

### Break-type distribution

| Break | Count | Reading |
|---|---|---|
| NOT-WIRED | **2** | The dangerous ones. Complete backends, screens that refuse everyone |
| DEAD-DATA | **5** | Tables joined and reachable with zero rows where it matters |
| FE-MISSING | 2 | Tenant creation; sister-company create |
| BE-MISSING / DB-MISSING | 3 | Module choice and setup completion persist nowhere |
| GREEN | 3 | Employee Directory, Role & Permissions, employee onboarding |

Five DEAD-DATA and two NOT-WIRED is the signature of **a module demoed on one
prepared tenant**. Every green stage is one that a person exercises daily on
tenant 6; every dead one is a stage only a *new* tenant would reach.

### Handoffs that do not exist

1. **Signup → setup.** A tenant is created and nothing tells anyone to configure
   it. No email, no first-run screen, no gate rows.
2. **Module choice → rights.** Ticking a module writes to a `Map` in the Next.js
   process. Nothing ever writes `tblgroupwise_rights_g2g`, so the choice has no
   effect on anything.
3. **Setup wizard → the product.** All three wizard steps write only to that same
   `Map`. No Laravel call exists in `app/organization/setup/page.tsx`.
4. **Employee created → reporting line.** `EmployeeFactory` never sets
   `reporting_manager_id`; it is 0 of 299 platform-wide, which zeroes the
   `reporting_coverage` gate for every tenant and collapses leave `Team` scope.

---

## 5. FINDINGS REGISTER

#### F-133 — Organization Profile and Department Management refuse every user — CRITICAL
**What:** Both screens are mounted without the `role` prop they require, so both
render `AccessDenied` for everyone including administrators.
**Where:** `g2gv0/components/shell/gtg-app-shell.tsx:112`;
`hooks/use-content-map-utils.ts:3`;
`components/domain/organization/organization-information.tsx:55,86`;
`components/domain/organization/department-management/department-list.tsx:249,481`
**Evidence:**
```tsx
// gtg-app-shell.tsx:112 — no props
{ContentComponent ? <ContentComponent /> : <ComingSoonFallback active={active} />}

// use-content-map-utils.ts:3 — `any` erases the requirement, so tsc is silent
export type LazyComponent = LazyExoticComponent<ComponentType<any>>

// organization-information.tsx:55,86
export function OrganizationInformation({ role }: { role: Role }) {
  const access = getAccess('organization-information', role)   // role === undefined
  ...
  if (access === 'none') { return <AccessDenied role={roleLabel(role)} /> }

// types/role.ts:125
return MATRIX[page][role] ?? 'none'
```
**Impact:** The most complete backend in the module — hierarchy, reparenting with
cycle prevention, merge with impact analysis, HOD assignment, bulk employee
moves, CSV export — is unreachable. A new tenant cannot create a department, and
therefore cannot create an LMS course (422 "Invalid Department ID"), post a job,
or open a Task project.
**Re-verify:** sign in as an administrator and open Organization Profile.
**Fix sketch:** both components already call `useAuth()`; derive `role` there and
drop the prop. Narrow `LazyComponent` so a required prop fails the build.

#### F-134 — Any tenant can read another tenant's compliance records and staff list — CRITICAL (P0)
**What:** `instituteDetailController` takes `sub_institute_id` from the request
body, so a valid token from tenant A returns tenant B's data.
**Where:** `app/Http/Controllers/settings/instituteDetailController.php:35,45,50`
**Evidence:**
```php
$sub_institute_id = $request->get('sub_institute_id');          // :35
$res['complainceData'] = DB::table('master_compliance as mc')
    ->where('mc.sub_institute_id', $sub_institute_id)...         // :47
$res['userDetails'] = employeeDetails($sub_institute_id,"",1);   // :50
```
**Demonstrated** (dev, read-only): user #1 of tenant 1, holding a tenant-1 token,
requested `sub_institute_id=3` and received **HTTP 200**, all 3 of tenant 3's
compliance records, and **122 of tenant 3's employees with usernames**.
**Impact:** Cross-tenant disclosure of staff directories and compliance posture.
The correct response is 404.
**Re-verify:** `php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-idor.php';"`
**Fix sketch:** adopt `ResolvesApiIdentity`; take the tenant from the token.

#### F-135 — Compliance records can be edited or deleted across tenants — CRITICAL
**What:** `update` and `destroy` filter on `id` alone.
**Where:** `instituteDetailController.php:354,388`
**Evidence:** `DB::table('master_compliance')->where('id',$id)->update(...)` — no
`sub_institute_id` clause.
**Impact:** Any authenticated user can alter or soft-delete another
organisation's compliance record by id. **Not demonstrated — a write against
another tenant's live data is not an acceptable test.**
**Fix sketch:** add the tenant clause; scope by the token's tenant.

#### F-136 — Disciplinary records: tenant resolved then discarded — CRITICAL
**What:** `store` computes the tenant from the token and then inserts whatever
the client sent; `update`/`destroy` have no tenant clause.
**Where:** `app/Http/Controllers/settings/discliplinaryManagementController.php:163,173,291,334`
**Evidence:**
```php
$sub_institute_id = $this->apiTenantId($request);      // :163 computed…
$insertData = $request->except('_token','type','token','user_id');
$insertData['created_by'] = $request->reported_by;     // caller-controlled
discliplinaryManagementModel::insert([$insertData]);   // …and never used
```
**Impact:** Disciplinary incidents — the most sensitive records in the module —
can be written into another tenant, and their `created_by` attribution is
supplied by the caller. `g2gActorId()` exists and is used only in `destroy`.
**Fix sketch:** use the resolved tenant; take audit columns from the actor.

#### F-137 — A new tenant's sidebar is empty — CRITICAL
**What:** Signup grants 11 menus across 2 modules; Talent, LMS, HRIT, Task
Management and Agentic AI (54 screens) get no rights rows. In practice the
sidebar renders nothing.
**Where:** `app/Http/Controllers/Api/signup_api/SchoolSetupController.php:283`
**Evidence:** measured above — 1000011 → 0 modules, 1000018 (967 employees) → 0.
**Impact:** Every organisation created through the product is unusable on arrival.
**Fix sketch:** grant per-module rights as part of module selection (F-140).

#### F-138 — Tenant data is rendered mixed with fixture data — HIGH
**What:** Blank organisation fields fall back field-by-field to a hardcoded
fixture, so real and invented data appear together and cannot be told apart.
**Where:** `organization-information.tsx:38-52`; `lib/gtg-org-data.ts:10-30`
**Evidence:** dev tenant `xyz` has `org_details` with **6 fields filled, 9
blank**; the blanks render *GapstoGrowth Technologies*' CIN, industry, address
and "1284 employees" beside that tenant's own name and email.
**Impact:** A demo or a decision made on this screen may rest on another
company's numbers.
**Fix sketch:** delete the fallback; render an honest empty state with a next
action.

#### F-139 — The Compliance Library's Department list is invented — HIGH
**What:** Five fabricated departments and fifteen fabricated employee names are
the **only** source for the form's Department select — not a fallback.
**Where:** `components/domain/hrms/compliance-discipline/compliance-library-management-shared.tsx:40-46,153-159`
**Impact:** Every compliance record is filed against a department that does not
exist in that organisation. The chosen string is written to
`master_compliance.standard_name`, a standards field, not a department key.
**Fix sketch:** source from `hrms_departments`.

#### F-140 — Module selection persists in a Map in the web process — HIGH
**What:** The screen imports no service and "saves" to `globalThis.gtgOnboardingStore`.
**Where:** `g2gv0/app/api/onboarding/route.ts:8`; `components/settings/module-configuration-page.tsx:29,92,197`
**Impact:** Lost on redeploy, not shared between instances, keyed by `userId` so
two admins of one organisation disagree, and readable/writable with no auth.
**Fix sketch:** persist per tenant and write the corresponding rights rows.

#### F-141 — The Organization Setup wizard cannot be opened by anyone — HIGH
**What:** Its guard tests role keys that do not exist.
**Where:** `g2gv0/app/organization/setup/page.tsx:266`
**Evidence:** `['admin','hr'].includes(user.role)` where `Role` is
`administrator | hr_manager | …` (`types/role.ts:15-24`). Same class at
`app/organization/compliance-management/page.tsx:18`.
**Impact:** 561 lines plus 1,437 lines of step components are unreachable. Behind
the guard they are fiction anyway — hardcoded "ABC Technologies Pvt. Ltd.", three
sample employees, a CSV import that never reads the file
(`page.tsx:339-343`), and a delete that ignores its own id (`:431-435`).
**Fix sketch:** replace rather than repair — see Sprint 5.

#### F-142 — A department head is scoped to a department named "Engineering" — HIGH
**What:** A fixture string is used as an authorization rule.
**Where:** `components/domain/organization/department-management/department-list.tsx:322`
**Evidence:** `departments.filter(d => d.name === 'Engineering' || d.parent === 'Engineering')`
**Impact:** Every organisation without a department called "Engineering" shows its
department heads an empty list.
**Fix sketch:** scope by the caller's own `department_id`.

#### F-143 — Six of nine roles do not exist on most tenants — HIGH
**What:** `Phase3RoleSeeder` loops tenants and has never been run on live. 10 of
12 tenants have `role_key = NULL` and lack `reporting_manager`,
`department_head`, `hr_executive`, `executive`, `auditor`, `recruiter`.
**Impact:** `RequireProfile` gates, `hrms_leave_role_permissions` and
`gtg-nav-visibility` all key on `role_key`. Roles created through Role &
Permissions also get no `role_key` (`tblmenumasterG2gController.php:266`), so a
role made in the UI satisfies no server-side gate.

#### F-144 — Sister companies cannot be created — MEDIUM
**Where:** `organization-information.tsx:294` (no `onClick`), `:145`
(`sister_companies` never sent). The controller path
(`organizationDetailsController.php:168-214`) is unreachable from the UI.

#### F-145 — `assigned_to` round-trips a name into an integer column — MEDIUM
**Where:** `compliance-library-management.tsx:49` reads a name; `:144` options
are ids; `:204` posts the name into `master_compliance.assigned_to`
(`unsignedBigInteger`).

#### F-146 — No server-side record that setup happened — MEDIUM
**What:** "Go Live" writes `localStorage.setItem('gtg-portal-live','true')`
(`portal-review-page.tsx:191`) — per browser, per device, invisible to the server
and to every colleague.

#### F-147 — Readiness gates are unreachable and mostly unenforced — MEDIUM
**What:** `/organization/readiness` is correct and wired, but has no menu row and
is linked from nowhere. Only 1 of 5 gates is consulted anywhere
(`CompetencyGapController.php:94`), while the acknowledge dialog tells the admin
that switching the other four off will "stop all manager-dependent flows".
`RecomputeReadinessGates.php:54` iterates only tenants that already have rows, so
a new tenant never gets any.

#### F-148 — Own profile screen renders a fixture and every edit button is inert — MEDIUM
**Where:** `components/profile/profile-dashboard.tsx:60-66`; edit controls at
`:87,:133,:138` and in all five cards have no handlers. The real API result is
fetched and used only for `SkillsPanel` (`:188`).

#### F-149 — Three conflicting step counts and mojibake in the setup chrome — LOW
**Where:** `module-configuration-page.tsx:118` says "STEP 1 OF 5" while its own
`SETUP_STEPS` has 8 and the layout is passed `currentStep={2}`;
`app/organization/setup/page.tsx:81` has 6. Source contains `â€"`, `ðŸ‘‹`, `â˜‘`.

---

## 6. WORKFLOW GAPS, RANKED BY WORK STRANDED

1. **Rights are never granted per module** — strands 54 screens across 5 modules.
2. **The two org screens refuse everyone** — strands ~1,300 lines of controller.
3. **Nothing persists from setup** — strands ~2,000 lines of wizard UI.
4. **No reporting line is ever captured** — strands leave `Team` scope, approval
   routing, and the `reporting_coverage` gate for every tenant.
5. **Roles are not seeded** — strands every `profile:`-gated route for 10 tenants.

---

## 7. OPEN QUESTIONS

- **Should signup be authenticated?** `POST /api/school-setup` is open. Whether
  it should be self-service, invite-only or internal is a business decision.
- **Which modules is a tenant entitled to?** There is no licensing or plan
  concept anywhere. Module selection currently means "which to show", not "which
  were bought". Needs a product decision before F-140 is built.
- **Is `standard_name` meant to hold a department?** The Compliance Library posts
  a department into a standards column. Either the column or the form is wrong,
  and the schema does not say which.

---

## 8. MASTER-SHEET ROW

```
| Module | Front door | Lifecycle | Roles | External | Data live | API | CRUD | Validation | Rules | RBAC/Tenant | Integration | Calc | Scale | Errors | Audit | Verdict |
| Organization + Onboarding | RED | RED | RED | AMBER | RED | GREEN | AMBER | AMBER | AMBER | RED | AMBER | N/A | AMBER | AMBER | AMBER | **RED** |
```

## 9. RELEASE GATE

```
Front door reachable by the role that starts it   ✗  F-133, F-137
360° lifecycle complete, every handoff wired      ✗  4 handoffs missing
Every role journey walks end to end               ✗  F-143
External actors can do what the module needs      ~  no new-hire capture
Live data — no fixtures rendered                  ✗  F-138, F-139, F-148
API + CRUD complete                               ~  F-144
Validation at API and DB, not only the browser    ~
Business rules correct                            ✓  hierarchy + cycle prevention
Tenant isolation proven with two tenants          ✗  F-134 demonstrated
RBAC proven at the API, not the menu              ✗  F-134, F-135, F-136
Cross-module data flow proven                     ~  employee.hired ✓, setup ✗
Calculations independently reconciled             n/a
Error handling + audit trail                      ~  F-136
Scale tested at realistic volume                  ✗  967-employee tenant: 0 modules
Golden transactions pass                          ✗  "new organisation joins" fails at step 1
Domain sign-off                                   pending
```
