# 01b — Scope triage: the 104 non-live navigation rows

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


**Answers Q-A3.** One pass, grouped by module, with a recommendation and a
one-line reason for every row. Approve or amend in a single review; flows will be
designed only for rows marked **SHIP**.

Generated from `_evidence/menu-tree.txt`; regenerate with `_evidence/triage.py`.

---

## Legend

| Verdict | Meaning |
|---|---|
| **SHIP** | Enable and design flows for it in Phase 3 — it is needed by a golden thread or by a decision already taken |
| **DEFER** | Real product value, but not Phase 3. Keep code and data, keep it hidden, record it as deferred scope |
| **DELETE** | Remove the nav row. Either a duplicate of something live, or belongs to the other product in this monolith |

`DELETE` means *remove the navigation row*, not drop tables. Nothing is deleted
without your approval (working rule §2.7); this column is a recommendation only.

**Totals: 12 SHIP · 27 DEFER · 65 DELETE.**

---

## 1. Reports — 45 rows

Q-A4 settled this: one reporting home that reads from all modules, not per-module
report screens. So the 45 legacy rows are **superseded by that decision**, not
individually valuable. Their *titles* are still useful — they are a free
requirements list for the consolidated home, harvested in §7 below.

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 6 | Reports (module root) | **SHIP** | Becomes the single consolidated reporting home |
| 122 | Organization Management Report | DELETE | Superseded by the consolidated home |
| 117–121, 123–125 | Communication / Complaint / Counselling / Document / Frontdesk / HRMS / LMS / Log Report (containers) | DELETE | Superseded |
| 126–153, 159, 160, 183–185, 195–197 | 34 individual report leaves | DELETE | Superseded — titles harvested in §7 |

Two are worth calling out before they are deleted, because they are the only
existing implementations of things the brief needs:

| id | Row | Note |
|---:|---|---|
| 185 | Employee Skill Coverage Matrix Report | The only built skill-coverage view. Its backend (`EmployeeSkillCoverageMatrixController`, 4 routes) is live and was secured in Phase 1. **Harvest into the consolidated home rather than lose it** |
| 184 | Employee Directory Analytics Report | Backend live (7 routes, `/reports/employee-directory/*`). Same treatment. Note it is also the endpoint that takes ~50s (F-48) |

---

## 2. Organizational Management — 22 rows

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 26 | **Skill Gap Analysis** | **SHIP** | Golden threads 1 and 4 both require it. Currently the single most important disabled screen in the product |
| 15 | Group wise right management | **SHIP** | Q-B1 adds a Manager role; group-level rights are how it gets administered. `tblgroupwise_rights` already exists and is populated |
| 16 | Individual right management | **SHIP** | Same — per-user override on top of group rights |
| 17 | Search Employees by Skills | **SHIP** | Directly feeds Mobility & Succession (golden thread 6) and internal-first recruitment (thread 7) |
| 161 | Organization Dashboard | DEFER | Useful, but no flow depends on it |
| 14 | Admin and Configuration Module | DEFER | Scope unclear; overlaps Organization Profile |
| 9 | Communication Tools (container) | **DEFER — with a caveat** | Every golden thread ends in "notify someone". Phase 3 needs a **notification mechanism**, but not necessarily these five manual send-message screens. See §6 |
| 27–31 | Send SMS / Notification / Email / Email Other / WhatsApp | DEFER | Manual broadcast tools, not part of any automated flow |
| 25 | Certifications | DELETE | Duplicate — Competency → Certifications is the live owner (D5) |
| 24 | Skills (external form) | **HOLD** | Pending Q-A5 — see §5 |
| 10, 32 | Template Management | DEFER | Overlaps Talent → HR Template Engine (also deferred); pick one later |
| 11, 33 | Complaint Mgmt | DELETE | Grievance handling is out of the six-module product |
| 18 | Task Assignment & Progress | DELETE | Duplicate — Task Management owns this entirely |
| 19, 20, 21 | Suggestion to organization / department / employee | DELETE | No definable purpose; no backing implementation found |

---

## 3. HRIT Management — 17 rows

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 167 | Holiday Master | **SHIP** | Working days and holidays are needed for task due-date and SLA calculation (golden thread 2) and leave accrual. `holiday` table + controller already exist |
| 166 | Leave Allocation | **SHIP** | Leave Configuration is live but allocation is where entitlement is actually set; the pair is incomplete without it |
| 181 | HRIT Dashboard | DEFER | No flow depends on it |
| 107 | Rollover Salary Structure | DEFER | Payroll-internal, outside the six-module connection scope |
| 97, 112 | Document Management System / Manage Document | **DEFER — flag** | Onboarding (live) collects documents. If it has nowhere to store them, this becomes SHIP. **Resolve during Gate C onboarding audit** |
| 98, 113 | Organization Handbook / Manage Handbook | DEFER | Policy publication; nice, not connective |
| 96, 111 | Compliance Management / Compliance library | DELETE | Duplicate — Organization → Compliance Library is live (D9) |
| 115, 116 | Deceplinry management / Deceplinary action | DELETE | Duplicate — Organization → Disciplinary Library is live. Also misspelled |
| 99, 114 | Front Desk Operations / Front Desk | DELETE | Belongs to the K-12 product in this monolith |
| 162, 163, 164 | Attendance Report / Early going / Department Wise | DELETE | Duplicates of live Attendance Reports, and superseded by the consolidated reporting home (D10) |

---

## 4. LMS — 10 rows

All ten are **duplicates of capability that Q-A2 assigns to Competency.** Competency
defines and measures; LMS builds. Assessment and self-rating are measurement, so
they belong in Competency.

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 77, 86 | Assesment library / Assessment List | DELETE | Competency → Assessments owns assessment. Two assessment engines is exactly the duplication the ownership model forbids (D6) |
| 78, 87, 88 | Skill Assessment / Search skill / Self skill rating | DELETE | Self-rating belongs in Competency → Employee Profiles, which already has the write path to `s_skill_matrix` |
| 79, 89, 90, 91, 92 | Career Explorer / Career Awareness / Interest Profiler / Knowing Yourself | DELETE | K-12 career-guidance content. Competency → Development & Career Paths is the corporate equivalent and is live |

**Caveat before deleting 87/88:** "Self skill rating" may be the only existing
*employee-facing* self-assessment UI. Competency → Employee Profiles is currently a
self-view (verified in `01-inventory.md` §4). Check during the Gate C competency
audit whether any behaviour is worth harvesting first.

---

## 5. Talent Management — 5 rows

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 50 | Compensation | DEFER | The Talent PDF specifies it in depth (pay bands, increment matrices, bonus rules). Substantial module in its own right; not required by any golden thread |
| 198 | HR Template Engine | DEFER | Overlaps Organization → Template Management; pick one home later |
| 180, 179, 169 | Talent Onboarding / Dashboard / Employee Onboarding | DELETE | Duplicates — Talent → Onboarding (id 48) is live and backed by `/api/onboarding/*` (D8) |

---

## 6. CRM — 4 rows

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 199, 200, 201, 202 | CRM / Marketing / Leads / Master Fields | **DEFER** | Per Q-A4: keep code and data intact, stay hidden, no Phase 3 work. Already `status=0`, so no action needed — recorded so it is not silently lost |

---

## 7. Agentic AI — 1 row

| id | Row | Verdict | Reason |
|---:|---|---|---|
| 187 | Pal | **DELETE** | Your Q-A5 decision. External Streamlit host, disabled, referenced nowhere in either codebase |

---

## 8. Requirements harvested from the deleted report rows

Deleting 44 report nav rows loses nothing if the consolidated home covers what they
named. The distinct reporting needs they represent:

| Domain | Reports named |
|---|---|
| Attendance | attendance, new attendance, monthly, department-wise, early-going |
| Leave | leave, leave summary, leave encashment |
| Payroll | payroll, payroll type, bankwise, salary structure, user payroll history |
| Communication | SMS, email, WhatsApp, notification, registered user |
| LMS | quiz progress, question-wise, employee analysis, my learning |
| Organization | employee info, workforce analytics, HR workforce analysis, human productivity |
| Competency | **employee skill coverage matrix** |
| Task | task analysis |
| Other | complaint, counselling, document, frontdesk, log |

Two observations for the consolidated design:

- There is **no competency gap report** in the list, and no development-plan or
  certification-expiry report. The three things the product is being sold on are
  the three things nobody built a report for.
- Task has exactly one report ("Task Analysis"), which matches the brief's
  complaint that Task → Reports & Analysis does not work properly.

---

## 9. What changes if you approve

| Verdict | Rows | Effect on Phase 3 |
|---|---:|---|
| SHIP | 12 | Enter the RBAC matrix and get user flows designed. Adds Skill Gap Analysis, rights management ×2, skill search, holiday master, leave allocation, consolidated reports |
| DEFER | 27 | Recorded in `00-progress.md` as deferred scope. No flows designed. Code and data untouched |
| DELETE | 65 | Nav rows removed after your approval. **No tables dropped.** Report titles already harvested above |
| HOLD | 1 | "Skills" (id 24) — pending Q-A5 |

Live screens after triage: **63 + 12 = 75**.
