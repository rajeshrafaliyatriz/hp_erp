# Module write-up 6 — **OTHER** (4 sub-modules)

**Deliberately short.** CRM is deferred scope, Reports is a consolidation decision
already taken, Agentic is in scope but narrow, HRIT is largely dashboards over
tables other modules own. **Proportionate effort, not Talent-sized.**

**Status:** `Analysis Done`. No code changed by this document.

Sub-modules: HRIT Dashboard · Agentic AI · Reports · CRM.

---

## 1. CRM — **out of scope, and it stays out**

`Q-A4`: **DEFERRED, not deleted.** Code and data intact, hidden from navigation,
no Phase 3 design or connection work.

**Nothing audited. Nothing proposed.** Recorded here only so the module list is
complete and so a later reader does not mistake absence for oversight.

---

## 2. Reports — the decision is made; the work is one item

`Q-A4` + the reporting decision (2026-08-05): **one consolidated reporting home
reading all modules**, not per-module report screens. The 45 legacy reports are
**not** being rebuilt.

**Three reports are mandatory and none of the 45 covered them:**

| Required report | Why it does not exist today |
|---|---|
| **Competency gap** | needs `jobrole_competency_map` vs `s_skill_matrix` — neither joinable today (`G-DATA-06`) |
| **Development plan** | plans exist; **no link to the gap they address** (`M-02`) |
| **Certification expiry** | expiry is on the held credential, but **there is no certification type**, so coverage cannot be counted (`G-CERT-01`) |

> **All three are blocked by the same foundations.** The reporting home is not
> gated on report-writing effort — it is gated on the joins existing. That is worth
> stating plainly: building it first would produce three empty reports.

### Guard failures on report routes

| Route | Evidence |
|---|---|
| `LeaveSummaryReportController@leaveSummaryReportShow` | FAIL — response changed with tenant B |
| `taskController@taskAnalysisReport` | FAIL — tenant B data returned when impersonating |
| `HrmsController@departmentAttendanceReport` | FAIL |
| ~~`PayrollController@monthlyPayrollReport`~~ | **FAIL at 379,541 bytes — now FIXED (D-004)** |

**Report endpoints are over-represented among the leaks**, and the reason is
structural: a report's whole job is to aggregate across a tenant, so it takes the
tenant as a parameter and rarely re-derives it. **Reports should be first in the
data-class fix order after candidate/personal data.**

---

## 3. Agentic AI — in scope, narrow, and one confirmed leak

`Q-A4`: **IN** — live and reachable.

| Finding | |
|---|---|
| `ExcelAutomationAgentController@credentialStatus` · `api/excel-agent/credentials` | **Guard FAIL** — response changed with tenant B |
| `ExcelAutomationAgentController@downloadTemplate` · `api/excel-agent/template` | **Guard FAIL** — 0 bytes for own tenant, 58 for the other |

**`credentialStatus` is the one to look at first.** A route reporting *credential*
status that varies by a caller-supplied tenant is reporting on **another tenant's
integration credentials**. Not read line by line yet — **candidate, per R6** — but
it belongs high in the data-class order.

The second is odd rather than alarming: the caller's own tenant returns **nothing**
while the impersonated one returns content. That reads like a template seeded for
one tenant only, not a leak of substance. **Flagged, not claimed.**

---

## 4. HRIT Dashboard — dashboards over other modules' tables

HRIT owns almost no data of its own; it aggregates Organization, Leave and
Attendance. Its findings are therefore **already recorded against the owning
modules**:

| Item | Owned by |
|---|---|
| `JobroleApiController` joins `s_user_jobrole.department_id → hrms_departments.id` | **Organization** — this is the join `D1`/`L-01` proves Competency never populates |
| `HrmsController` — 31 routes, 45 request reads, no trait, no `->tokenable` | **security queue** — the largest unread C21 candidate |
| `jobroletaskcontroller`, `jobroletexonomycontroller` — 1 guard FAIL each | security queue |
| `AttendanceApiController`, `LeaveDistribution` | Leave/Attendance, outside Phase 3's golden threads |

**`HrmsController` is the single largest unread controller in the phase** — 31
routes, same shape as `skillLibraryController` before D-003. It has not been read
line by line and is **not** claimed as a finding.

---

## 5. §5.1 — new work versus already-approved work

| Item | Verdict | Maps to |
|---|---|---|
| Consolidated reporting home | **ALREADY APPROVED** | Q-A4 + the 2026-08-05 reporting decision |
| The three mandatory reports | **ALREADY APPROVED**, and **blocked** by G-DATA-06 / G-CERT-01 / M-02 | — |
| CRM | **DEFERRED** | Q-A4 |
| 6 guard FAILs in scope | **ALREADY SCHEDULED** | part of the 46 |
| `HrmsController` read | **NEW** — a reading task, not a build task | C21 |

**Tally: 0 new build items.** **Sixth consecutive module.**

---

## 6. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **O-03** | Fix `ExcelAutomationAgentController@credentialStatus` | Agentic → security | A credential-status route that varies by caller-supplied tenant reports on **another tenant's integration credentials** | **XS–S** | read it first (R6) | `c23-result-FULL-912.json` |
| **O-04** | Fix the three report-route leaks | Reports → security | Report endpoints aggregate across a tenant by design, so they take the tenant as a parameter and rarely re-derive it | **S** | — | §2 |
| **O-05** | Read `HrmsController` | security queue | **31 routes, 45 request reads, no trait** — the largest unread candidate in the phase | **S** (reading) | — | C21 |

**Deliberately NOT proposed:** the reporting home itself. It is approved and
specified; **building it before the joins exist would produce three empty
reports.** It sequences after the foundations, not before.

---

## 7. Status

`Analysis Done`. **4 sub-modules, proportionate depth.** CRM untouched by design.
Reports shown to be **foundation-blocked, not effort-blocked**. Agentic has one
credential-status leak worth early attention.

**Module count: 30 of 32.** **Gate C effectively closes here** — the remaining 2
are CRM's deferred rows.

**Next: `08-connection-plan.md`**, assembled from the CONNECTIONS TO BUILD sections
across all six write-ups.
