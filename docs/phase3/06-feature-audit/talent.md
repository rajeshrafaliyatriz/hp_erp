# Module write-up 5 — **TALENT** (7 sub-modules)

**C18 format.** Sweep hits · hand-read of the primary controllers · C35 payload
checklist (both directions) · §5.1 reconciliation · CONNECTIONS TO BUILD.

**Status:** `Analysis Done`. No code changed by this document.

Sub-modules: Talent Dashboard · Recruitment · Onboarding · Performance Reviews &
Appraisals · Mobility & Succession · Offboarding · Administration.

**The largest module: 7 sub-modules, ~1,072 raw inventory elements.**

---

## 1. Sweep hits landing in this module

Worked from the **recovered `c23-result-FULL-912.json`**, not a re-run — the
instrument has changed twice since that run, and the records are the better source.

| Sweep | Hits | Consequence |
|---|---|---|
| **C27** (verified class) | **4 of the 5 known "trait present, still reads from request" controllers are Talent's**: `talent_interviewschedules` (8 routes, 6 hits), `talent_jobposting` (6/5), `talent_interviewpanel` (5/5), `talent_jobapplication` (8/4). Plus `TalentOfferController` (trait, 1 hit) | **Talent is where the "trait looks like safety" pattern concentrates** |
| **C23 guard** (executed) | **`talent_interviewpanelController@getInterviewPanel` · `api/interview-panel/list` — FAIL: tenant B data returned when impersonating** | **The C27 class, executed and confirmed, in this module** |
| **S-1** (verified) | none of the four headline tables is Talent-owned | Talent is a **consumer** of the string-joined data, not a producer |

### The one confirmed breach

`talent_interviewpanelController` **imports the trait and still leaks.** That is
G-SEC-10's shape — the trait's presence reading as safety — now proven in a second
module. **Interview panel composition is personal data about candidates and staff.**

---

## 2. Golden threads 5 and 7 — both broken, and in the same way

### Thread 7 · Recruitment ← job-role competency requirements

Q-D1 recorded that Recruiter *"retains read of job-role competency requirements so
requisitions and scorecards generate from the framework"*.

**Checked: nothing in `Api/Talent/` or `talent_*` references
`s_user_skill_jobrole`, `s_jobrole_skills`, or any competency mapping table.**

**The read does not exist.** A requisition cannot be generated from the framework,
because Recruitment has never been connected to it.

### Thread 5 · Performance ↔ competency

**"Competency" appears in Performance only as a STRING.**

| Where | What it is |
|---|---|
| `PerformanceGoalController.php:93,167` | `'category' => 'nullable\|in:kra,kpi,okr,competency,project'` — a **validator enum value** |
| `PerformanceOverviewController.php:314` | `['value' => 'competency', 'label' => 'Competency']` — a **filter dropdown label** |

**No join to `s_skill_matrix`, `s_users_skills`, or any competency table anywhere in
`Api/Performance/`.** A goal can be *labelled* competency-related; nothing links it
to a competency record or a measured rating.

> **This is the LMS pattern again: the word is present, the join is not.**
> Three modules now — Competency↔LMS, Competency↔Recruitment,
> Competency↔Performance — where a vocabulary of connection exists without the
> connection.

**The 9-box grid cannot read capability.** It has performance on one axis and
nothing to put on the other.

---

## 3. C35 checklist — payload vs validator vs insert, both directions

| Form | Files read | Verdict |
|---|---|---|
| Performance goal | `PerformanceGoalController.php:93` (validator) · `:167` (update) · insert | ✅ **Clean, and well-built** — closed enum on `category`, symmetric validate/update |
| Job posting | `talent_jobpostingcontroller` | ⚠️ **C27 class** — trait imported, 5 request reads. Payload not yet the issue |
| Job application | `talent_jobapplicationcontroller` | ⚠️ C27 class, 4 reads |
| Interview panel | `talent_interviewpanelController` | ❌ **Confirmed guard FAIL.** Fix before payload review |
| Onboarding task | `Api/Talent/OnboardingTaskController` + `Api/Onboarding/OnboardingTaskController` | ⚠️ **Two controllers of the same name in different namespaces** — a duplication candidate, not yet read |
| Offer | `TalentOfferController` (trait, `tokenable`-aware) | ✅ Reads its actor correctly |

**Inverse direction — accepted but never sent:** none found. **The L-01 pattern
remains Competency-specific across all five modules audited.** That is now a
finding in itself: the inverse defect is *not* systemic, it is one screen's.

---

## 4. §5.1 — new work versus already-approved work

| Item | Verdict | Maps to |
|---|---|---|
| Candidate portal identity (`portal_identity`) | **ALREADY APPROVED** | Q-D4, §10 step 7. **Portal itself is DEFERRED scope** |
| Recruiter role + framework read | **ALREADY APPROVED** (role), **NEW** (the read) | Q-D1 recorded the read as intended; §2 shows it was never built |
| Performance ↔ competency link | **NEW** | Nothing in Gate B specifies it; the 9-box assumed it existed |
| `talent_interviewpanel` tenant fix | **ALREADY SCHEDULED** | one of the 46 |
| 4 C27 controllers | **ALREADY SCHEDULED** | G-SEC-10's class |
| Duplicate `OnboardingTaskController` | **NEW** — candidate | — |

**Tally: 2 new, 1 partly, 3 already scheduled.** **Fifth consecutive module** where
the substantive work was specified in Gate B.

> **State this in `08-connection-plan.md`:** across all five modules, Gate C found
> almost nothing that Gate B's domain model had not already anticipated. **That is
> what a correct model looks like** — the audit is confirming the design, not
> redirecting it.

---

## 5. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **TL-01** | Fix `talent_interviewpanelController` — trait adoption | Talent → security | **Confirmed executed leak** on interview panel data (candidates and staff). Proven fix shape (D-003) | **XS–S** | — | `c23-result-FULL-912.json` |
| **TL-02** | Performance goal `category='competency'` → a real `competency_id` | Performance ↔ Competency | **The 9-box has nothing to put on its capability axis.** Today "competency" is a dropdown label with no referent | **M** | `competency` table (§10 step 3) | `PerformanceGoalController.php:93,167` |
| **TL-03** | Requisitions and scorecards read `jobrole_competency_map` | Competency → Recruitment | **Thread 7 as Q-D1 intended it.** Recruitment currently has no view of what a role requires | **M** | `jobrole_competency_map` (§10 step 3) | §2 above |
| **TL-04** | Resolve the two `OnboardingTaskController`s | within Talent | Two same-named controllers in different namespaces is the `contentLibraryControllerOld` shape — a divergence waiting to happen | **S** | read both first (R6) | §3 |

**Deliberately NOT proposed:** the candidate portal. Q-D4 put it in **deferred
scope** — Phase 3 defines the identity model and conversion step, not the
applicant-facing product. Re-proposing it here would reopen a settled scope
decision.

---

## 6. Status

`Analysis Done`. **7 sub-modules.** One confirmed tenant leak, four C27 candidates,
and **two golden threads shown broken in the same way** — a vocabulary of
connection without the connection.

**Module count: 26 of 32.** Next: Other (4) → 30, then `08-connection-plan.md`.
