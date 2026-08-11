# User flow — Department Head

**Written 2026-08-12, against what exists.** Measured on route guards and **write
rights**, not view — comparing `can_view` is what produced a retracted conclusion
about `hr_manager`, and a role's power is what it can write.

`role_key = department_head`. The profile exists in every tenant. It has users.

---

## 0. THE SHORT VERSION

**This role exists, is defined, holds write rights in the permission matrix, and
cannot reach a single guarded API route.**

Not "has limited scope". Not "waits on data". **Every `profile:`-guarded endpoint
refuses it, and the rights matrix says it may write to seven screens.** The two
authorization systems disagree, and unlike `hr_manager` — who gets *more* than the
matrix allows — this role gets *less*.

**The one thing that does work is employee-level self-service** (their own
competency gap, verified 200). Stated here rather than only in §3, because a
reader who takes away "reaches nothing" would be picturing a broken login. It is
not broken: **its BASELINE works and its ELEVATION is absent.**

---

## 1. What the measurement says

### Route guards — **zero**

    guarded routes in routes/api.php   38
      profile:admin,hr                 32
      profile:admin,hr,manager          6
      naming department_head            0

And the alias map decides it (`RequireProfile::ALIASES`):

    'admin'    => ['administrator']
    'hr'       => ['hr_manager', 'hr_executive']
    'manager'  => ['hr_manager', 'reporting_manager']    <- NOT department_head

**`department_head` appears in no alias.** `manager` does not cover it. There is
no argument-set in any route file that admits this role.

### Rights matrix — **seven write screens**

    view menus   51
    write menus   7

    Department Management · Assignments · Sessions & Calendar
    Projects & Workstreams · Dependencies & Workstreams
    Recruitment · Mobility & Succession

**The matrix grants what the routes refuse.** Nothing reconciles them, because no
route consults the matrix — that is G-BLOCK-01, and the guard that would fix it is
built, proven, and cannot be registered.

### Scope — **deliberately withheld, in code**

`ResolvesCompetencyContext` and `ResolvesLeaveContext` both leave
`department_head` out of their elevated lists, with the same comment:

> *"department_head and reporting_manager are DELIBERATELY ABSENT. Their
> legitimate scope is 'my department' and 'my team', and neither can be evaluated
> while `tbluser.reporting_manager_id` is NULL for every user (G-ORG-02). They
> return here as team scope, the day reporting-line coverage exists."*

### The gate that measures exactly this, and cannot act on it

    reporting_coverage   t1 blocked 0.00%   t3 blocked 6.56%   threshold 50%
    platform-wide        8 of 401 employees have a manager

**The gate enforces nothing**, and the reason is this role's whole situation: **a
gate cannot enforce against features that were deferred waiting for it.** The
department-scoped features were never switched on — pending the coverage this gate
measures — so there is nothing for it to refuse.

---

## 2. THE THREE MECHANISMS, AND WHAT EACH ONE SAYS

This is the part worth reading. Three independent systems have an opinion about
this role, and **no two of them agree**:

| mechanism | what it says about department_head | can it change? |
|---|---|---|
| **Rights matrix** (`tblgroupwise_rights_g2g`) | may write to 7 screens | **yes** — an admin edits a row |
| **Route guards** (`profile:` + `ALIASES`) | may reach nothing | **no** — hardcoded in a `const` |
| **Scope resolvers** (`*_ELEVATED` const arrays) | has no scope to evaluate | **no** — hardcoded, with a comment |

**Two of the three are frozen in code and nothing re-evaluates them.** No
measurement brings this role back; a person has to remember the comments and edit
the arrays. **A hardcoded absence cannot be told from an oversight by anything
except a comment** — which is precisely what the matrix and the gate were built to
replace.

---

## 3. What a Department Head can do today, end to end

**Nothing ELEVATED. Employee-level self-service only.**

Measured by request, not derived from the alias map - as
`rajesh.iyer@healthcare.g2g`, profile 52, tenant 3:

    GET   /api/competency/nine-box        403  You do not have permission...
    POST  /api/competency/definitions     403  You do not have permission...
    GET   /api/readiness/gates            403  You do not have permission...
    POST  /api/reporting-line/assign      403  You do not have permission...
    GET   /api/competency/gap             200  <- REACHES IT

**MY FIRST DRAFT SAID "NOTHING THROUGH THE API" AND THAT WAS WRONG.** The gap
endpoint is not `profile:`-guarded; it authorises through
`ResolvesCompetencyContext`'s SUBJECT check - own gap, or an elevated role. A
Department Head is not elevated, so they get exactly what an Employee gets:
**their own gap, and nobody else's.**

The correction matters because the two claims sound alike and are not. "Can reach
nothing" would be a broken login. "Can reach only what any employee can reach" is
a role whose ELEVATION is absent while its baseline works - and that is the true
state.

A person logging in authenticates, receives a session, and gets a sidebar - the
menu rights are real, so the navigation renders - **over endpoints that refuse
every department-scoped thing those menus imply.**

The nine-login walkthrough asserts this role's sidebar is non-empty and it passes.
**That check measures navigation, not capability** - the same distinction that let
a browser-verified screen turn out to be unreachable. *Renders* and *works* are
different claims.

---

## ⛔ What a Department Head must NEVER see

Unchanged from the other roles and enforced by the same code — another tenant's
anything, a `0` where nothing was measured, an import that resolved an ambiguity
for them, `hpbrain_*` data.

**And one specific to this role:** *department-wide data for a department the
system cannot establish they head.* There is no reporting line, so there is no
department to scope to. **Granting org-wide access in the meantime would be a
wider grant than the one being closed** — which is exactly why the scope was
withheld rather than approximated.

---

## 4. Where this role stands

### ✅ Works today, end to end

Authentication, session, sidebar rendering, the menu rights themselves, and
**employee-level self-service** - their own competency gap, verified 200. That is
the complete list, and every item on it is something an Employee also has.

### ⛔ Dead-ended, and on what

| dead end | waits on |
|---|---|
| Every ELEVATED API route refuses this role (4 of 4 probed) | **the alias map** — `department_head` is in no argument-set. A one-line change, gated on the scope decision below |
| Department-scoped competency and leave access | **reporting-line coverage** — 8 of 401. The const arrays hardcode the absence and nothing re-evaluates them |
| The 7 write screens the matrix grants | **G-BLOCK-01** — the matrix guard is built and proven and cannot be registered |
| `reporting_coverage` refuses nothing | **the features it would gate** — deferred pending the coverage it measures |

### 🔗 Remaining plan items this role depends on

`G-BLOCK-01` (structural — the guard cannot be registered) · **reporting-line
coverage** (X-16 built the writer; the data is 8 of 401) · the
**role-lists-defer-to-the-gate** convergence item · `G-ORG-02`.

---

## 5. WHY THIS FLOW WAS WORTH WRITING

`administrator.md` ends with *"none of these block the Administrator's current
flows — every one extends something already working."*

**This one ends with the opposite: every item on the list blocks the only flows
this role has.** And the three flows together are the diagnosis promoted into
`08-connection-plan.md` §1:

> **The product is complete for the person who configures it and incomplete for
> the people who use it.**

Department Head is the sharpest instance. **It is not an unbuilt role — it is a
role that was built three times, by three mechanisms, which do not agree with each
other.** The permission matrix says yes, the route guards say no, and the scope
resolvers say *not yet, and nothing will ever tell us when*.

**A customer demo that opens this login sees a working navigation over an
inaccessible product.** That is worse than a missing menu, because it looks
finished until something is clicked.
