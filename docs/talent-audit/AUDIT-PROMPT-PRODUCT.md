# G2G FULL-PRODUCT INTEGRITY AUDIT

> Run this **after** every module has an `AUDIT-<MODULE>.md` from
> `AUDIT-PROMPT-MODULE.md`. Those reports are your input, not your conclusion.

---

## YOUR ROLE

Modules can each pass their own audit while **the product fails as a system**.
That is the specific failure this audit exists to catch. Your question is not
"does each module work" — that has already been asked. It is:

> **Does a real person's journey survive being handed between modules, and does
> a number mean the same thing everywhere it appears?**

The previous audit of this product reached this verdict:

> *"The lifecycle the product is named for does not exist. LMS completions,
> task outcomes and competency scores do not flow into one another. The modules
> share an employee table and nothing else."*

Your job is to determine whether that is still true, module by module and
handoff by handoff, and to say so in those terms.

---

## GROUND RULES

Identical to the module audit, and they matter more here because the claims are
bigger:

1. `file:line` and a re-runnable command for every claim.
2. Verify your own tooling before trusting its output.
3. Never assert absence without listing the searches you ran.
4. Precise over dramatic. Name the mechanism.
5. Re-verify anything you take from `FIX-PLAN-v2.md` or a module report.
6. Label inferences `INFERRED`.
7. RED / AMBER / GREEN. No percentages.
8. Change nothing.

**One extra rule for this pass:** where a module report and the code disagree,
**the code wins** — and record the disagreement, because it means that module's
audit was run carelessly and its other findings are now suspect.

---

# PART 1 — GOLDEN RECORD JOURNEYS

Create synthetic records and follow each through the **entire** product,
crossing every module boundary. This is the core of the audit.

### Journey A — `CAND-TEST-001`, a person who does not work here yet

This is the journey the product is sold on. It crosses **Talent →
Organization → Capability Intelligence → Task Management**, and every arrow is
a place the chain can break.

```
                                          MODULE            LAYERS TO CHECK
Workforce need / requisition              Talent            FE / BE / DB
   ↓
Job posting                               Talent            FE / BE / DB
   ↓ published where? is it public?
Candidate discovers and applies           Talent            ← without a login?
   ↓
Screening                                 Talent
   ↓
Assessment round                          Talent            ← can the candidate SIT it?
   ↓
Interview scheduled                       Talent            ← is the candidate told?
   ↓                                                          can they accept a slot?
Feedback and scoring                      Talent
   ↓
Hiring decision                           Talent
   ↓
OFFER LETTER issued                       Talent            ← generated how? sent how?
   ↓                                                          can the candidate ACCEPT?
Offer accepted                            Talent            ← is there an endpoint at all?
   ↓
ENROLMENT / onboarding journey            Talent            ← started by whom?
   ↓
EMPLOYEE RECORD created                   Organization      ← does ANYTHING create it,
   ↓                                                          or is it retyped by hand?
Appears in EMPLOYEE DIRECTORY             Organization      ← same person, or a duplicate?
   ↓
Department + job role + reporting line    Organization
   ↓
Expected competencies for that role       Capability        ← does the role carry them?
   ↓
CAPABILITY RATING — initial               Capability        ← who rates a brand-new hire?
   ↓                                                          self? manager? nobody?
Gap identified → learning assigned        Capability → LMS
   ↓
Payroll, leave balance, assets            HRMS
   ↓
First task assigned                       Task Management
```

At **every** arrow, state one of:

- **WIRED** — the next stage is triggered automatically. Name the code that does it.
- **MANUAL** — a human must retype data into another screen. **This is a gap.**
  Say exactly what must be re-keyed and where, because that is the daily cost.
- **ABSENT** — nothing carries the work forward at all.

And for every arrow marked MANUAL or ABSENT, say **which layer is missing**
using the break taxonomy from the module audit — FE-MISSING, BE-MISSING,
DB-MISSING, NONE-BUILT, NOT-WIRED, DEAD-DATA. "The handoff is broken" is not
actionable; "the endpoint exists but no screen calls it (FE-MISSING,
`routes/api.php:1577`)" tells someone what to build.

**The single question this journey answers:** can one person be hired,
enrolled, added to the directory, given a job role, rated on capability and
assigned their first task — without anyone retyping their name into a second
screen? If not, count the re-keying points. That number is the product's real
integration gap.

### Journey B — `EMP-TEST-001`, an employee's working life

```
Joins → probation → confirmation
   ↓
Job role assigned → expected competencies set
   ↓
Competency self-rating → manager rating → gap identified
   ↓
Gap → recommended learning                ← does the bridge exist?
   ↓
Course assigned → completed
   ↓
Completion → competency score updated     ← does it flow back?
   ↓
Competency → task eligibility             ← does it gate anything?
   ↓
Tasks assigned → completed → outcomes
   ↓
Task outcomes → performance review        ← does the review see them?
   ↓
Review → appraisal → compensation
   ↓
Promotion or internal mobility
   ↓
Transfer → new department, role, manager  ← does everything follow?
   ↓
Resignation → notice → clearance → settlement → access revoked
   ↓
Historical records preserved
```

For each arrow the same verdict: **WIRED / MANUAL / ABSENT**, plus the break
type and the layer at fault.

### Journeys are different per role, but they share the same data

The same hire is a different journey depending on who you are, and the audit
must walk each one. They must all agree on the facts:

| Role | Their journey through the hire | Must be able to see / do |
|---|---|---|
| **Administrator** | Configure roles, competencies, approval chains — before anyone is hired | Everything, but should not be the person doing daily work |
| **HR (hr_executive / hr_manager)** | Requisition → posting → screening → offer → enrolment → directory | The whole pipeline, and the new joiner appearing in the directory |
| **Recruiter** | Sourcing → screening → interviews → feedback | Their own pipeline; not payroll |
| **Reporting manager** | Interview feedback → receives the new joiner → rates capability → assigns first task | Their team only |
| **Employee (the new hire)** | Accept offer → onboard → self-rate capability → see assigned tasks | Their own record only |
| **Candidate (not yet an employee)** | Apply → assessment → interview → accept offer | Their own application — see Part D of the module audit |

For each role, walk the journey and record where it **stops**. A role whose
journey ends with "and then someone else must be told by email" has found a
workflow gap, and it is the same gap the arrows above describe — seen from a
person's side rather than the data's.

**The cross-check that matters:** at the end of the hire, do HR, the reporting
manager and the employee all see the *same* person, with the *same* job role
and the *same* capability rating? If any two disagree, name the two tables
behind the disagreement.

### Journey C — the mutation tests

Change one thing and see what fails to notice:

| Change | What must follow | Does it? |
|---|---|---|
| Employee changes department | Reporting line, approvals, dashboards, leave approver | |
| Employee changes job role | Expected competencies, catalogue tasks, gaps, learning | |
| Employee resigns | Task reassignment, payroll stop, leave accrual stop, access | |
| Employee is promoted | Salary, band, approval limits, succession pool | |
| A competency is renamed | Ratings, frameworks, gaps, reports, history | |
| A department is merged | Employees, tasks, projects, policies, history | |
| A job role is deleted | Employees holding it, catalogue tasks, postings | |
| `syear` rolls over | See Part 3 | |

---

# PART 2 — CROSS-MODULE INTEGRITY

Build the real map, then test each edge.

```
Organization ──→ Talent ──→ Competency ──→ LMS
     │              │            │           │
     └──────────────┴─── Task Management ────┘
                          │
                    Main Dashboard
```

For **every** edge:

- **Which direction does data actually move?** Both ways, one way, or neither.
- **What carries it** — a foreign key, an API call, a scheduled job, a webhook,
  or a person retyping it?
- **What happens when the source changes after the fact?**
- **Is the edge tenant-safe?** A join that crosses tenants is a P0.

Then answer the product's founding question directly:

> Does a **task outcome** change a **competency score**?
> Does a **course completion** change a **competency score**?
> Does a **competency gap** cause **learning** to be recommended?
> Does any of it reach a **performance review**?

If the answer is no, say so plainly and quantify what exists instead.

---

# PART 3 — `syear` ROLLOVER AND HISTORY

The G2G equivalent of an academic-year transition, and a serious operational
risk.

```
syear 2025-2026
   ↓ close
Carry forward: employees, roles, reporting lines
   ↓
Leave balances — carried, lapsed, or encashed?
   ↓
New payroll structures, new appraisal cycle
   ↓
Open tasks and projects — do they move or strand?
   ↓
Competency ratings — do they carry or reset?
   ↓
syear 2026-2027
```

Then the test that matters most:

> **Does opening a new `syear` change what last year's reports say?**

Historical rows must not mutate because current configuration changed. Check
every report that groups by year, and every calculation that reads a rate,
formula or structure **as it is now** rather than **as it was then**.

---

# PART 4 — TENANT ISOLATION (P0, PRODUCT-WIDE)

The known platform-wide defect: guards that validate a token and then take the
tenant from a **request parameter** instead of from the token's owner.

Do this as a census, not a sample:

1. Count every API controller. For each, record how it resolves
   `sub_institute_id` — **from the token's user**, **from the request**, or
   **not at all**.
2. Publish the ratio. It is the single most important number in this audit.
3. List every route registered with **no** auth middleware.
4. With two real tenants, take tenant A's token and attempt to read and write
   tenant B's records in **every** module. Record the status code for each.
   **200 is a P0. 403 is also wrong — it confirms existence. 404 is correct.**
5. Test identity spoofing: can a caller set `user_id` and have a write
   attributed to another person? Check the audit trail afterwards to see whose
   name it recorded.
6. Test unauthenticated account creation and privilege escalation: can anyone
   create a user, in any tenant, with any role?

---

# PART 5 — NUMBERS THAT MUST AGREE

Trust dies the first time a dashboard and a list disagree.

For every headline figure in the product:

```
Dashboard tile   =   the list it summarises   =   the export   =   a direct DB query
```

Check at minimum: headcount, attrition, open positions, attendance percentage,
leave balance, payroll totals, competency coverage and maturity, task
completion, project progress, course completion, appraisal distribution.

For each, report the four values and whether they match. Any mismatch is
**critical** regardless of size — a rounding difference and a logic difference
look identical to the person who spots them.

Also flag:

- Any figure computed in **both** frontend and backend.
- Any hardcoded constant standing in for a real input.
- Any tenant id baked into a formula.
- Any money value truncated rather than rounded.

---

# PART 6 — SCALE AND PRODUCTION READINESS

Test at the volume the product claims to serve, not at demo volume:

```
3,000 employees          40,000 tasks
600,000 attendance rows  100,000 competency ratings
50,000 LMS interactions  Several years of history
Hundreds of concurrent logins
```

Measure p50 and p95 response times, DB query counts (hunt N+1), memory, failed
requests and queue delays. Then: logging, monitoring, alerting, backup,
restore-tested-not-just-configured, and what happens when a dependency is down.

---

# PART 7 — AI AND AGENTIC FEATURES

Audit these **separately** from CRUD. Traditional software is
`input → rule → output`; these are not.

```
Context → ground truth → expected behaviour → actual output → accuracy / safety
```

Cover every AI surface: generated task details, execution models (ESO),
screening and matching, recommendations, the agentic module. Test for
hallucinated facts about real employees, decisions the model should not make
alone, prompt injection through user-supplied text, whether outputs are
tenant-scoped, whether personal data reaches a third-party model, and whether a
human can see and override every automated decision. State clearly which
automated decisions currently have **no** human review step.

---

# PART 8 — OUTPUT

Produce `AUDIT-PRODUCT.md`.

**1. Executive verdict** — one paragraph. Can this ship? If not, name the
blocking classes of defect, not the count.

**2. Master sheet** — every module, one row:

```
| Module | Front door | Lifecycle | Roles | External | Data live | API | CRUD | Validation | Rules | RBAC/Tenant | Integration | Calc | Scale | Errors | Audit | E2E | Verdict |
```

RED / AMBER / GREEN only.

**3. Journey verdicts** — Journeys A, B and C, each with its arrows marked
wired / manual / absent, and a count of arrows that are absent.

**4. Cross-module map** — the diagram with each edge marked, and a plain answer
to the founding question of Part 2.

**5. Tenant isolation census** — the ratio from Part 4.1 and the two-tenant
test results.

**6. Number reconciliation** — the four-way table from Part 5.

**7. Consolidated findings** — merged from the module audits, deduplicated,
re-verified, ranked by severity. Keep the `F-NN` IDs stable; note any that the
module audits got wrong.

**8. Corrections** — where a module report disagreed with the code. Name the
report. This tells you which audits to re-run.

**9. Release readiness** — for each module, the gate below with every line
ticked or not.

**10. Open questions** — what could not be determined from source. Do not guess.

**11. The one-paragraph version** — what you would tell someone who has ninety
seconds.

---

## THE ONLY DEFINITION OF DONE

```
DEVELOPED → DEV TESTED → QA TESTED → DOMAIN TESTED
   → INTEGRATION TESTED → SECURITY TESTED → VERIFIED
```

Only **VERIFIED** counts toward completion. A module is not done because its UI
is built and its API returns 200. It is done when a real person's journey
crosses it and comes out the other side with the right data, in the right
tenant, and a number that agrees with every other place it appears.
