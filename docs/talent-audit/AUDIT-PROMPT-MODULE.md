# G2G MODULE INTEGRITY AUDIT — `<MODULE NAME>`

> Run this once per module. When every module has a report, run
> `AUDIT-PROMPT-PRODUCT.md`, which consumes them.

---

## YOUR ROLE

You are an independent auditor. You did not build this and you owe nobody a
pass. Your job is to answer one question about `<MODULE NAME>`:

> **Is this genuinely production-ready end to end, or does it only look
> complete on screen?**

A module is not complete because the UI is built, the API returns 200, or a
developer says it was tested. Two failure modes have already been found in this
product and you are expected to hunt them:

1. **Screens that render invented data** instead of the tenant's own.
2. **Forms whose only validation is in the browser** — which a caller who skips
   the browser does not have.

---

## GROUND RULES — NON-NEGOTIABLE

These exist because the first audit of this product (`AUDIT-FINDINGS-v1.md`)
was wrong in eight documented ways. Do not repeat them.

1. **Every claim carries `file:line` and a re-runnable command.** A finding
   nobody can reproduce is an opinion.
2. **Verify your own tooling before reporting its output.** v1 reported a list
   of TypeScript errors that were entirely artifacts of its own failed
   `npm install`. Confirm the install, the build and the DB connection work
   before believing anything they tell you.
3. **Never state that something is absent without listing the searches you
   ran.** v1 said "no Task-to-LMS bridge exists" when one did, under another name.
4. **Do not overstate.** v1's headline "the API is unauthenticated" was the
   wrong framing and it *hid* the real bug. Describe the mechanism precisely,
   not dramatically.
5. **Read the code; do not trust comments, names, or `FIX-PLAN-v2.md`.** That
   plan records fixes as landed. Re-verify anything you depend on.
6. **Separate verified from inferred.** Label inferences `INFERRED`.
7. **No percentages.** "90% done" is not a status. Use RED / AMBER / GREEN.
8. **Change nothing.** This is an audit. No edits, no migrations, no commits.

---

## THE PRODUCT, IN ONE PARAGRAPH

G2G is a multi-tenant HR / talent / capability ERP. Laravel backend (`hp_erp`),
Next.js frontend (`g2gv0`). Tenancy is **in-row**: root tables carry
`sub_institute_id` + `syear`; child tables inherit through a parent join. Live
DB is MariaDB 10.1.48. Nine roles are defined per tenant on
`tbluserprofilemaster` — `employee`, `reporting_manager`, `department_head`,
`hr_executive`, `hr_manager`, `administrator`, `executive`, `auditor`,
`recruiter`. Modules are numbered `m0`–`m7`, routed through
`hooks/content-map-m*.ts` and `lib/gtg-navigation.ts`.

---

# PART A — THE FRONT DOOR

*Most audits skip this, and it is where whole modules turn out to be
unreachable.*

Answer plainly:

- **Where does this module begin?** Name the first screen a person lands on.
- **Who starts it?** Which of the nine roles performs the first action.
- **Can they find it?** Is it in the navigation for that role, or reachable only
  by typing a URL?
- **What is the first thing they create?** Name the entity and the endpoint.
- **Is there a way in that nobody built?** For example, work that must begin
  with a request from someone who has no screen to make it.

If a module has no front door for the role meant to start it, that is **RED**
however finished the rest looks.

---

# PART B — THE 360° LIFECYCLE, LAYER BY LAYER

A stage can fail in four different places, and "partial" hides which one. So
check **all three layers separately** for every stage, then check whether they
are actually joined to each other.

Map the module's end-to-end cycle. One row per stage, in the order the work
really happens.

| # | Stage | Who acts (role) | FRONTEND | BACKEND | DATABASE | WIRED? | Handoff to next stage | Break type |
|---|---|---|---|---|---|---|---|---|

Fill each layer with evidence, not a tick:

- **FRONTEND** — the component and route (`file:line`), or `MISSING`.
- **BACKEND** — the route + controller method (`file:line`), or `MISSING`.
- **DATABASE** — the table, whether a migration creates it, whether it exists
  on live, and **how many rows it holds**. A table that exists but is empty in
  production is a different finding from one that is full.
- **WIRED?** — do the three actually talk to each other? A screen that calls a
  route that writes a table it was never meant to is *three layers present and
  still broken*.

## The break taxonomy — classify every incomplete stage

| Break type | Meaning | Why it matters |
|---|---|---|
| **FE-MISSING** | Backend and table exist; no screen | Capability built and paid for, nobody can reach it |
| **BE-MISSING** | Screen exists; no route behind it | The button that does nothing. Users think they did the thing |
| **DB-MISSING** | Screen and route exist; nothing persists | Data accepted and silently lost |
| **NONE-BUILT** | No layer exists | An honest gap — cheapest to fix, least dangerous |
| **NOT-WIRED** | All three exist, not joined | **The most dangerous.** Looks finished in every demo |
| **DEAD-DATA** | All three exist and joined, zero rows on live | Either nobody uses it, or the write path fails silently |

**NOT-WIRED and DEAD-DATA are the ones this audit exists to catch.** Any
competent reviewer finds NONE-BUILT. Only tracing all three layers finds these.

Report a count of each break type. That distribution tells you what kind of
trouble the module is in: many BE-MISSING means a frontend built ahead of an
API; many DEAD-DATA means a feature nobody could ever complete.

Then list explicitly:

- **Stages with a screen but no API** — the button that does nothing.
- **Stages with an API but no screen** — capability nobody can reach.
- **Handoffs that do not exist** — stage N completes and stage N+1 is never
  told. *These are the workflow gaps, and they are the point of this section.*
- **Status strings the UI writes that the backend does not accept**, and vice
  versa. Compare them character for character against the backend enum or
  validation rule.

**The standard expected here.** In Talent, an acceptable finding reads:
*"Assessment has a kanban column and nothing behind it — no assessment entity,
no invite, no scoring, and the status it writes is outside the backend's
accepted set"*, with a file and line for each half of the claim. That is the
resolution required. "Assessment: partial" is not a finding.

---

# PART C — ROLE JOURNEYS

For **each** of the nine roles, walk the module as that person.

| Role | Can they reach it? | What can they do? | What should they be able to do? | Gap |
|---|---|---|---|---|

Then, per role, three checks:

1. **Menu vs API.** Hiding a screen is not access control. For every action the
   role must *not* perform, call the endpoint directly as that role and record
   the status code. A 200 is **critical**.
2. **Empty-handed start.** Sign in as this role with no data yet. Does the
   module say what to do first, or show a blank screen?
3. **Dead ends.** Any point where the role finishes their part and cannot hand
   it on, or cannot see what happened next.

---

# PART D — EXTERNAL AND SELF-SERVICE ACTORS

*The most commonly missing half of an HR product.*

Some people a module is about are **not employees with logins**: a job
candidate, a new hire before their record exists, a learner, an employee using
self-service rather than an HR console.

For every such actor:

- **Do they have any surface at all** — a portal, a link, an email they can act
  on, a form?
- **Trace the exact route.** Is it inside the authenticated layout? If so they
  cannot reach it, and any screen built for them is unreachable.
- **Can they complete what the module needs from them** — submit an
  application, sit an assessment, accept an interview slot, accept an offer,
  upload a document?
- **If the only contact is an outbound email, can they answer it through the
  product?** An email with no endpoint behind it is a dead end, not a feature.

State it in plain words. *"The candidate has no screen, no login and no
assessment anywhere in the codebase; the only thing that reaches them is an
emailed PDF they cannot reply to"* is a finding. *"Candidate portal: partial"*
is not.

---

# PART E — THE 15-POINT INTEGRITY CHECKLIST

| # | Check | What to verify | Severity |
|---|---|---|---|
| 1 | **Data source** | Live DB/API vs mock, fixture, fallback or hardcoded. Trace **UI field → service method → route → controller → table → row**. If you cannot complete that chain it is RED. | Critical |
| 2 | **API integrity** | Route exists, method matches, request and response shapes agree with the caller. | Critical |
| 3 | **CRUD completeness** | Create, read, update, delete, list, search, filter, paginate — and whether delete is soft or hard. | Critical |
| 4 | **Validation, four layers** | Browser → API → business rule → DB constraint. Fill the matrix in E.1. | Critical |
| 5 | **Business rules** | Do the HR rules hold — probation, notice period, leave accrual, eligibility, approval thresholds? | Critical |
| 6 | **Data integrity** | Foreign keys, orphans, duplicates, nullable-when-required, transactions around multi-table writes. | Critical |
| 7 | **Error handling** | 400 / 401 / 403 / 404 / 409 / 422 / 500 / timeout / offline. Does the UI say something a person can act on? | High |
| 8 | **Real data and scale** | Unicode names, long text, emoji, historical rows, tenants with 3,000+ employees. | High |
| 9 | **RBAC + tenant isolation** | See E.2. **The highest-risk area in this product.** | Critical |
| 10 | **Integration / data flow** | Does data reach the next module, or stop at this one's tables? | Critical |
| 11 | **Workflow integrity** | Statuses, approvals, escalation, notifications — do events fire, retry, and avoid duplicates? | High |
| 12 | **Calculation integrity** | See E.3. | Critical |
| 13 | **Audit trail** | Who changed what, when, from what value to what, and why. | High |
| 14 | **UX / operational readiness** | Loading, empty and error states; double-click submit; refresh mid-save; exports. | Medium |
| 15 | **Production readiness** | Logging, monitoring, failure recovery, N+1 queries, response times. | Critical |

### E.0 — The database layer, on its own

The layer most audits never open. For every table this module reads or writes:

| Table | Migration exists | Exists on dev | Exists on live | Rows on live | Tenant column | Written by | Read by |
|---|---|---|---|---|---|---|---|

Then answer:

- **Is any table written but never read?** Data going into a hole.
- **Is any table read but never written?** It will always look empty.
- **Zero rows on live** — is the feature unused, or does the write path fail?
  Check the controller for a swallowed exception before concluding "unused".
- **Does every root table carry `sub_institute_id` + `syear`?** If a child table
  omits them, can you always reach a parent that has them? Prove the join.
- **Two tables for one concept?** Look for an older and a newer generation of
  the same idea living side by side. If both exist, find every file that writes
  **both** — those are the drift points, and merge/delete operations that
  update only one leave orphans.
- **Do the columns match what the API sends?** A field the form collects, the
  API accepts, and the table has no column for is silently discarded.

Run the counts yourself against the live database. Do not take a row count from
a comment in the code — comments go stale, and at least one in this repo
already has.

### E.1 — Validation matrix

One row per field or rule. Frontend-only validation is **not validation**.

| Field / rule | Browser | API | Business rule | DB constraint |
|---|---|---|---|---|

For at least three fields, **prove** the API layer: call the endpoint directly,
browser bypassed, with an invalid value. Record what happened.

### E.2 — Tenant isolation (P0)

For every endpoint in this module:

- Does the controller derive the tenant from **the token's owner** or from a
  **request parameter**? Taking `sub_institute_id` from the request is the
  known platform-wide defect — every instance is **critical**.
- Take a valid token for tenant A and request a record owned by tenant B by id.
  **A 200 is a P0.** A 403 is also wrong — it confirms the row exists. The
  correct answer is **404**.
- Can the caller pass `user_id` and have a write attributed to someone else?
- List any route in this module registered with no auth middleware at all.

### E.3 — Calculation audit

For every number a human could dispute:

```
Known input → expected result you computed by hand → system result → MATCH / FAIL
```

The person who wrote the formula does not supply the expected value. Flag any
calculation implemented in **both** frontend and backend — duplicated formulas
drift. Flag hardcoded constants that should be tenant configuration, any tenant
id baked into a formula, and any rounding or integer truncation applied to money.

---

# PART F — GOLDEN TRANSACTIONS

Run the module's real scenarios as whole stories, not as endpoint calls. Not
"can I create a record", but "does the right thing happen".

- **Hiring** — candidate applies, is screened, sits an assessment, is
  interviewed by two people, is offered, accepts, becomes an employee.
- **Joining** — offer accepted → employee record → department → role → payroll
  → leave balance → assets → first task.
- **Leave** — apply, approve, reject, cancel after approval, half-day, leave
  spanning a holiday, leave beyond balance, leave in a closed period.
- **Payroll** — mid-month joiner, mid-month leaver, unpaid leave, arrears,
  statutory deductions, a component that should differ by tenant.
- **Competency** — self-rating, manager rating, the two disagreeing,
  re-rating, an expired assessment.
- **Task** — assign to several people, recurrence, reassignment, overdue,
  approval, rejection with rework.
- **Exit** — resignation, notice period, clearance, final settlement, access
  revocation, historical records preserved.

For each: expected outcome, actual outcome, verdict.

---

# PART G — NEGATIVE TESTING

Do not only ask "can I do it". Ask "how do I break it".

```
Missing required field          Duplicate unique value
Reference to a deleted parent   Another tenant's id
Wrong syear                     Very long text
Emoji, Hindi and Gujarati text  Leading/trailing whitespace
Double-click Save               Refresh during save
Network drop during save        Expired token
Two users editing one row       Back button after submit
```

Production bugs live here.

---

# PART H — OUTPUT FORMAT

Produce one file: `AUDIT-<MODULE>.md`.

**1. Verdict** — one paragraph. RED / AMBER / GREEN and the single reason.

**2. Scorecard** — one row per Part E check, plus Front door, Lifecycle, Role
journeys, External actors.

**3. Lifecycle table** — Part B, complete.

**4. Findings register** — continue the ID sequence in `FIX-PLAN-v2.md`:

```
#### F-NN — <one-line title> — <CRITICAL|HIGH|MEDIUM|LOW>
What:       one sentence.
Where:      file:line
Evidence:   the code, quoted.
Impact:     what a real person loses, or a real attacker gains.
Re-verify:  a command anyone can run.
Fix sketch: one or two lines. Do not implement it.
```

**5. Workflow gaps** — the handoffs that do not exist, ranked by how much work
is stranded behind each.

**6. Open questions** — what you could not determine from source. **Do not
guess these.** Say what you would need: a live login, a second tenant, a domain
decision.

**7. Master-sheet row:**

```
| Module | Front door | Lifecycle | Roles | External | Data live | API | CRUD | Validation | Rules | RBAC/Tenant | Integration | Calc | Scale | Errors | Audit | Verdict |
```

---

## RELEASE GATE

`<MODULE>` is **VERIFIED** only when every line is true:

```
Front door reachable by the role that starts it   ✓
360° lifecycle complete, every handoff wired      ✓
Every role journey walks end to end               ✓
External actors can do what the module needs      ✓
Live data — no fixtures rendered                  ✓
API + CRUD complete                               ✓
Validation at API and DB, not only the browser    ✓
Business rules correct                            ✓
Tenant isolation proven with two tenants          ✓
RBAC proven at the API, not the menu              ✓
Cross-module data flow proven                     ✓
Calculations independently reconciled             ✓
Error handling + audit trail                      ✓
Scale tested at realistic volume                  ✓
Golden transactions pass                          ✓
Domain sign-off                                   ✓
```

Anything less is RED or AMBER. There is no third option and no percentage.
