import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

# ---- plan: two new items the review surfaced ----
p = os.path.join(D, "08-connection-plan.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| F-05 | `reporting_manager_id` + `head_user_id` + cycle validation | Q-B1 | 2, 4, 9 | **M** · `tbluser`, `hrms_departments` migration | every approval flow | — | AT-F05 | DB | Not started |",
"""| F-05 | `reporting_manager_id` + `head_user_id` + cycle validation | Q-B1 | 2, 4, 9 | **M** · `tbluser`, `hrms_departments` migration | every approval flow | — | AT-F05 | DB | ✅ **APPLIED** (`f293edb0`) |
| **F-05a** | **Call `ReportingLineValidator::canAssign()` from EVERY write path that sets `reporting_manager_id`** | Q-B1 | 2, 4, 9 | **S** · employee create/edit · onboarding · bulk import · admin screens | the guarantee itself | F-05 | AT-F05a | API | **NOT STARTED — the guarantee is theoretical until this lands** |
| **F-05b** | **Manager assignment mechanism** — bulk and individual, for `reporting_manager_id` **and** `head_user_id` | Q-B1 | 2, 4, 9 | **M** | Slice 2's demo | F-05, F-05a | AT-F05b | SCREEN | **NOT STARTED** |""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- register: the two risks, stated plainly ----
p = os.path.join(D, "07-gap-register.md"); t = io.open(p, encoding="utf-8").read()
anchor = "## G-SEC-12 — caller-supplied audit provenance"
t = t.replace(anchor, """## G-ORG-01 — the no-cycle guarantee is theoretical until every write path calls it · **S2**

**`ReportingLineValidator` exists (`f293edb0`) and nothing calls it.**

MySQL cannot express "no cycles", so the guarantee lives in application code — which
means it holds **only where the code runs**. Every path that sets
`reporting_manager_id` must call `canAssign()` first:

| Write path | Must call `canAssign()` |
|---|---|
| Employee create | ☐ |
| Employee edit | ☐ |
| Onboarding (assigning a new hire to a manager) | ☐ |
| **Bulk import** | ☐ — **the most likely to create the first cycle** |
| Any admin/org-chart screen | ☐ |

> **A validator nothing calls is documentation.** The first bulk import that sets
> managers without it creates exactly the cycle the validator was written to
> prevent, and team-scope resolution stops terminating.

**Tracked as plan item F-05a**, with "must call `canAssign()`" as an acceptance
criterion on each path.

---

## G-ORG-02 — the role model has nobody in six of its nine roles · **S3, by design for now**

**0 of 387 users have a `reporting_manager_id`**, and six of the nine roles were
created empty. Both are **correct** — the columns are new and nothing has assigned
them yet.

But the consequence is worth stating: **reporting coverage is 0%, so every
manager-dependent flow is gated off**, and a role model with nobody in it
demonstrates nothing. Slice 2's demo ("three roles, three different products")
needs users actually holding those roles, and Slice 3's manager-confirmation step
needs a reporting line that exists.

**The assignment mechanism — bulk and individual, for `reporting_manager_id` and
`head_user_id` — is tracked as plan item F-05b.** It was not in the plan before
this review; it is now.

---

""" + anchor)
io.open(p, "w", encoding="utf-8").write(t)

# ---- implementation log ----
p = os.path.join(D, "09-implementation-log.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("\n---\n\n## R7 applied retroactively", """
---

## D-011 · 4b-prep (a) and (b)

**2026-08-07** · commit **`dd25e450`**

### (a) Does §3.1–3.7 carry a Recruiter column? — **NO, and no derivation is needed**

**All seven §3.x tables carry eight columns.** Recruiter is absent from every one.

**But its permissions are already decided** — `03-rbac-matrix.md` **Q-D1**
(line 736) holds a complete Recruiter table. It is **module-level, not
screen-level**, which is why it never merged into §3.1–3.7:

| Module | Recruiter |
|---|---|
| Talent → Recruitment | **V C E D A** |
| Talent → Onboarding | V *(handover of hired candidates only)* |
| Organization → Employee Directory | V *(basic fields only, per A1)* |
| Competency → Framework & Role Mapping | V *(read required competencies for a requisition)* |
| Competency → Employee Profiles / ratings | **–** |
| Talent → Performance | **–** |
| HRIT → Payroll | **–** |
| Everything else | – |

**So the gap is a FORMAT gap, not a decision gap.** Recruiter needs expanding from
8 module rows to per-screen rows for the seed — mechanical, since *"everything else
= –"*. **No permission is being re-derived.**

⚠️ **For approval before 4b runs:** the expansion itself. Every screen inside the
four granted modules inherits that module's mark; every other screen is `–`.

### (b) The nine canonical roles — **APPLIED**

| | |
|---|---|
| **Changed** | `role_key` + `data_scope` + `is_system` stamped on the three existing profiles; six missing roles created, empty, per tenant |
| **Files** | `database/seeders/Phase3RoleSeeder.php` |
| **Verification** | **9 role_keys × 11 tenants** · 99 of 103 profiles keyed · **user assignment unchanged** (employee 238 · administrator 76 · hr_manager 72) |
| **Acceptance** | Idempotent, re-runnable. **Touches no rights rows** — that is 4b |

Mapping applied as directed: Employee → `employee` (self) · HR → `hr_manager`
(organization) · Admin → `administrator` (organization). Created empty:
`reporting_manager` (team), `department_head` (department), `hr_executive`
(department), `executive`, `auditor`, `recruiter`.

**4 profiles remain unkeyed** — `ZZ Audit Role v2` ×2, `Organization Administrator`,
`Deparment Administrator` *(sic)*. Left alone deliberately: they are not among the
nine, and one has a live user. **Flagged, not touched.**

### (c) The screen→menu mapping — **NOT STARTED**

The reviewable deliverable. Next turn.

---

## R7 applied retroactively""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- progress ----
p = os.path.join(D, "00-progress.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| 4b-prep | **Nine roles + `role_key` + the screen→menu mapping**, for review | **NEXT** |",
"""| 4b-prep(a) | Recruiter column check | ✅ done — **no Recruiter column in §3.x; Q-D1 has it module-level.** A format gap, not a decision gap. **Expansion awaits approval** |
| 4b-prep(b) | Nine roles + `role_key` + `data_scope` | ✅ done (`dd25e450`) — 9 × 11 tenants |
| 4b-prep(c) | **Screen→menu mapping CSV, for review** | **NEXT** |
| **F-05a** | **Call `canAssign()` from every write path** | **NOT STARTED — G-ORG-01.** The no-cycle guarantee is theoretical until this lands |
| **F-05b** | Manager assignment mechanism (bulk + individual) | **NOT STARTED — G-ORG-02.** Slice 2's demo needs it |""")
io.open(p, "w", encoding="utf-8").write(t)

# ---- current state ----
p = os.path.join(D, "13-current-state.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("**Built: 10.**", "**Built: 11.**")
t = t.replace("| Rights matrix population (4b) | ~~tri-state columns~~ ✅ · ~~role model~~ ✅ **(f293edb0)** — **now blocked only on 4b-prep**: the nine roles seeded + the screen→menu mapping |",
              "| Rights matrix population (4b) | ~~tri-state columns~~ ✅ · ~~role model~~ ✅ · ~~nine roles~~ ✅ **(dd25e450)** — **now blocked only on the screen→menu mapping (4b-prep c) and approval of the Recruiter expansion** |")
io.open(p, "w", encoding="utf-8").write(t)
print("documents written")
