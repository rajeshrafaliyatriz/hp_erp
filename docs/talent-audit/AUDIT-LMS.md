# G2G MODULE INTEGRITY AUDIT — LMS

**Date:** 2026-09-05 · **Method:** source + live database + live API
**Verified against:** dev (MariaDB 10.11.9) and live (MariaDB 10.1.48, `hp.triz.co.in`)

> Unlike the previous LMS review, this one ran against a **deployed** backend. Every
> status code below is an observed response from production, not a reading of source.

---

## 1. VERDICT — AMBER

The learning spine works end to end and is demonstrably in use: `lms_content_progress`
has gone from **2 rows to 11**, across three different employees, and one learner has
opened all six media types in a single course. Tenant isolation on reads is proven with
two live tokens from two organisations.

It is not GREEN for one reason: **`POST /api/enroll` will enrol a caller into another
organisation's course.** The validation rule checks the course exists globally and never
that it belongs to the caller's tenant. Proven on live, then reverted.

Two sub-modules remain structurally unfinished — Sessions and Assessments — and the
Course Builder's rules engine has never executed in production.

---

## 2. SCORECARD

| Dimension | Status | Note |
|---|---|---|
| Front door | GREEN | Catalogue reachable by every role |
| 360° lifecycle | AMBER | 3 handoffs NOT-WIRED |
| Role journeys (admin / HR / employee) | GREEN | All three walk end to end |
| External actors | N/A | LMS has no non-employee actor |
| 1 · Data source | GREEN | 10/10 screens service-backed, no fixtures |
| 2 · API integrity | GREEN | 86 paths called, 81 backed, 5 dead scaffold with no callers |
| 3 · CRUD completeness | AMBER | No attempt submission for assessments |
| 4 · Validation | GREEN | Proven at the API with the browser bypassed |
| 5 · Business rules | RED | `lms_course_settings` = 0 rows; the engine never runs |
| 6 · Data integrity | AMBER | No foreign keys on any 2026-era LMS table |
| 7 · Error handling | GREEN | 401 / 404 / 422 correct and legible |
| 8 · Real data and scale | GREEN | Unicode, emoji, 400-char and bad-FK all handled |
| 9 · RBAC + tenant isolation | RED | Reads scoped; one write path is not |
| 10 · Integration | AMBER | Sessions contribute nothing |
| 11 · Workflow integrity | AMBER | `course.completed` still never emitted |
| 12 · Calculation integrity | GREEN | Progress derived once, server-side |
| 13 · Audit trail | AMBER | `updated_by` / `deleted_by` absent from the enrolment table |
| 14 · UX readiness | GREEN | Loading, empty and error states present |
| 15 · Production readiness | AMBER | Tenant indexes added; monitoring unverified |

---

## 3. PART A — THE FRONT DOOR

- **Begins at** LMS → Learning → Learning Catalog (`learning-catalog.tsx`), a card grid.
- **Started by** admin or hr, who create the course; **every** role can browse and enrol.
- **Reachable** from the server-driven nav (`tblmenumaster_g2g`, submenu 182). Admin and
  HR additionally get Administration → Course Builder (submenu 84).
- **First entity created:** a course — `POST /api/lms/courses` → `sub_std_map`.
- **No missing way in.** Until 2026-09-04 there was one: browsing was gated on an author
  check, so employees found a list with no way to join anything while My Learning told
  them to enrol there. Closed.

---

## 4. PART B — LIFECYCLE, LAYER BY LAYER

| # | Stage | Who | Frontend | Backend | Database (live rows) | Wired | Break |
|---|---|---|---|---|---|---|---|
| 1 | Create course | admin/HR | `create-course-page.tsx` | `LmsCourseController@store` | `sub_std_map` 98 | yes | — |
| 2 | Add modules | admin/HR | builder step 2 | `@storeChapter` | `chapter_master` 177 | yes | — |
| 3 | Add lessons | admin/HR | builder, `course-authoring.tsx` | `@storeContent` | `content_master` 169 | yes | — |
| 4 | Set course rules | admin/HR | builder steps 4–5 | `@saveSettings` | `lms_course_settings` **0** | yes | **DEAD-DATA** |
| 5 | Publish to catalogue | admin/HR | `learning-catalog.tsx` | `@index` | same table | yes | — |
| 6 | Assign to people | admin/HR | `course-audience-panel.tsx` | `@assignAudience` | `lms_assignments` 59 | yes | — |
| 7 | Self-enrol | any | card Enrol | `LmsCourseEnrollController@store` | `lms_course_enroll` 1497 | yes | see F-71 |
| 8 | Appears in My Learning | employee | `learning-delivery-workspace.tsx` | `@courses` | joined | yes | — |
| 9 | Open a lesson | employee | `LessonViewer` | `@course` | `content_master` | yes | — |
| 10 | Progress saved | employee | heartbeat + mark | `@saveProgress` | `lms_content_progress` **11** | yes | — |
| 11 | Attend a session | employee | `sessions-calendar.tsx` | `LmsSessionController` | `lms_virtual_classroom` 2 | **no** | **NOT-WIRED** |
| 12 | Sit an assessment | employee | Assessments tab | **MISSING** | `question_paper` 80 | **no** | **BE-MISSING** |
| 13 | Mark complete | employee | Mark as complete | `@completeCourse` | `lms_course_enroll` | yes | — |
| 14 | Certificate | employee | `CertificatePanel` | `@issueCertificate` | `lms_certificates` 1 | yes | — |
| 15 | Feeds competency | — | — | `CertificateIssuer` exists | `g2g_event` | **no** | **NOT-WIRED** |

**Break-type distribution:** NOT-WIRED 3 · BE-MISSING 1 · DEAD-DATA 1 · FE-MISSING 0 · DB-MISSING 0 · NONE-BUILT 0

No FE-MISSING and no DB-MISSING says the module was built layer-complete. The NOT-WIRED
count says it was built in pieces that were never joined to each other.

---

## 5. PART C — ROLE JOURNEYS (admin, HR, employee)

| Role | Reach it? | Can do | Should do | Gap |
|---|---|---|---|---|
| administrator | yes | create, author, assign, govern, browse, learn | same | none found |
| hr_manager / hr_executive | yes | same as admin for LMS | same | none found |
| employee | yes | browse, enrol, learn, mark complete, claim certificate | also sit assessments | F-74 |

**Menu vs API.** Access is enforced server-side, not by hiding menus:
`guardLmsProfile($request, ['admin','hr'])` reads the profile from the token's owner.
Authoring endpoints refuse a non-author regardless of what the client sends.

**Empty-handed start.** The catalogue renders an empty state naming the next action
("Courses are created in Administration → Course Builder"); My Learning links to the
catalogue rather than merely naming it.

**Dead ends.** One remains: an employee who finishes a course with lessons outstanding
can mark it complete but cannot obtain a certificate, and the screen explains why.

---

## 6. PART D — EXTERNAL ACTORS

**Not applicable, and that is correct.** Every LMS actor is an employee with a login.
Searches run: `candidate`, `public`, `guest`, `portal`, `invite` across
`components/domain/lms/`, `services/lms/`, and the LMS route groups — no external-facing
surface exists and none is required. (The candidate assessment under
`/api/candidate-assessment/*` belongs to Talent's hiring flow, not to LMS.)

---

## 7. PART E — INTEGRITY CHECKS

### E.0 — The database layer

| Table | Migration | Live rows | Tenant col | Written by | Read by |
|---|---|---|---|---|---|
| `sub_std_map` | yes (2025_06_02) | 98 | yes | `LmsCourseController` | catalogue, player, competency |
| `chapter_master` | yes | 177 | yes + syear | `@storeChapter` | player |
| `content_master` | yes | 169 | yes + syear | `@storeContent` | player |
| `lms_course_enroll` | **added 2026-09-02** | 1497 | yes (nullable) | `EnrolmentWriter`, enrol controller | player, catalogue |
| `lms_assignments` | yes | 59 | yes | assignment controller, `LearningAssigner` | assignments screen |
| `lms_content_progress` | yes | **11** | yes | `@saveProgress` | player, catalogue |
| `lms_certificates` | yes | 1 | yes | `@issueCertificate` | certificates screen |
| `lms_course_settings` | yes | **0** | yes | `@saveSettings` | eligibility check |
| `lms_course_prerequisites` | yes | **0** | yes | `@savePrerequisites` | eligibility check |
| `lms_virtual_classroom` | yes | 2 | yes + syear | `LmsSessionController` | sessions screen |
| `lms_session_registrations` | yes | 3 | yes | session controller | sessions screen |
| `question_paper` | yes | 80 | yes + syear | assessment authoring | Assessments tab (read only) |
| `course_jobrole_map` | yes | 71 | yes | **`@assignAudience` (new)** | `LearningAssigner` |
| `course_competency_map` | yes | 65 | yes | competency controller | learning assigner |

- **Written but never read:** none found.
- **Read but never written:** `lms_course_settings` and `lms_course_prerequisites` are
  read by the eligibility check and hold zero rows — see F-72.
- **Zero rows on live:** settings, prerequisites. Not a swallowed exception —
  `saveSettings` returns early when the request carries no `settings` key.
- **Root tables carry the tenant.** Children reach it by parent join; proven for
  `content_master → chapter_master → sub_std_map`.
- **Two generations for one concept:** yes — `sub_std_map` is a K-12 subject/standard map
  reused as the HR course table. `standard_id` is named for `standard` and points at
  `hrms_departments`; `subject_id` is polymorphic. Out of scope to replace, but every
  query in this module inherits the ambiguity.
- **Missing:** no foreign keys on any table added after 2026-07-28.

### E.1 — Validation, proven at the API

Browser bypassed; live endpoint; token supplied.

| Field / rule | Browser | API | Business rule | DB |
|---|---|---|---|---|
| `display_name` required | yes | **422 proven** | — | NOT NULL |
| `display_name` length | yes | **422 proven** (400 chars) | — | varchar 191 |
| `standard_id` required | yes | **422 proven** | — | — |
| `standard_id` must exist in tenant | no | **422 proven** ("Invalid Department ID") | yes | — |
| `course_id` on enrol must exist | no | **422 proven** | **NO tenant check — F-71** | — |
| `status` required | yes | **422 proven** | — | NOT NULL |
| `start_date` | no | defaulted | today | NOT NULL |
| Unicode / emoji names | — | **201 accepted** | — | utf8mb4 |
| Whitespace trimming | no | **not trimmed** — F-76 | — | — |

### E.2 — Tenant isolation, two live tokens

| Probe | Result | Verdict |
|---|---|---|
| Tenant 6 token reads its own course 174 | **200** | correct |
| Tenant 6 token reads tenant 3's course 83 | **404** | correct — not 403 |
| Tenant 6 token + `sub_institute_id=3` in the URL | **404** | correct — the token beats the parameter |
| Tenant 3 token reads its own course 83 | **200** | correct |
| Tenant 3 token reads tenant 6's course 174 | **404** | correct |
| Invalid token | **401** | correct |
| **Tenant 6 token ENROLS into tenant 3's course** | **200, row written** | **F-71, CRITICAL** |

Every LMS controller derives the tenant from the token's owner via `ResolvesLmsIdentity`.
No LMS route is registered without a controller-level guard.

### E.3 — Calculations

`progress_percent` is the only disputable number and is computed **once**, server-side, as
completed lessons over total lessons. No duplicate frontend implementation
(`grep -rn "progress_percent" services/ components/` returns renders, never arithmetic).
No hardcoded constant, no tenant id in a formula, no money.

One inherited risk: `total_content` counts `content_master` by `subject_id`, a link held
by convention rather than a foreign key.

---

## 8. PART F — GOLDEN TRANSACTIONS

| Scenario | Expected | Actual | Verdict |
|---|---|---|---|
| Author a course with six media types | all six render | all six opened by a real learner on live | **PASS** |
| HR assigns → learner sees it | appears in My Learning | proven; 58 historical assignments recovered | **PASS** |
| Learner opens lesson 2 without finishing lesson 1 | allowed | PDF completed while video only in progress | **PASS** |
| Self-enrol from the catalogue | enrolled | 500 until 2026-09-05, now correct | **PASS** (after fix) |
| Enrol in a foreign tenant's course | refused | **succeeded** | **FAIL — F-71** |
| Complete a course, claim certificate | certificate issued | works at 100%; refused below | **PASS** |
| Attend a session, expect progress | progress moves | nothing happens | **FAIL — F-73** |
| Sit an assessment | attempt recorded | no attempt path exists | **FAIL — F-74** |

---

## 9. PART G — NEGATIVE TESTING

| Input | Result |
|---|---|
| Missing required field | 422 with the field named |
| 400-char name into varchar(191) | 422, length message |
| Hindi + Gujarati + emoji | **201 created** — handled correctly |
| Leading/trailing whitespace | 201, stored untrimmed (F-76) |
| `standard_id` pointing at nothing | 422 "Invalid Department ID" |
| `course_id` pointing at nothing | 422 |
| Another tenant's `course_id` on read | 404 |
| Another tenant's `course_id` on enrol | **200 — F-71** |
| Invalid token | 401 |

---

## 10. FINDINGS REGISTER

#### F-71 — Cross-tenant enrolment write — CRITICAL
**What:** `POST /api/enroll` accepts any course id in the product, not only the caller's tenant's.
**Where:** `app/Http/Controllers/lms_course_enroll/LmsCourseEnrollController.php:361` (and `:464`).
**Evidence:** `'course_id' => 'required|integer|exists:sub_std_map,id'` — global existence, no tenant predicate.
**Proven on live:** a tenant-6 token enrolled user 28 into tenant 3's course 83; response
`"Course Enroll added successfully!"`; row 12555 written with `enrolment.sub_institute_id=6`
against `course.sub_institute_id=3`. Tenant 3's catalogue then showed **2 learners where 1 was theirs**.
**Impact, precisely:** not a disclosure. The read path is scoped and the foreign course did
not appear in the learner's list — user 28 still saw 8 courses, not 9. It is a **write into
another organisation's relationship space** that inflates their learner and completion
figures. Before the probe live held **0** such rows, so the hole is latent, not exploited.
The probe row was deleted; live is back to 0.
**Re-verify:** `POST /api/enroll` with a tenant-A token and a tenant-B `course_id`; expect 404, observe 200.
**Fix sketch:** scope the existence rule to the caller's tenant, as `assignAudience` already does.

#### F-72 — The Course Builder rules engine has never run — HIGH
**What:** every rule the wizard collects is inert in production.
**Where:** `lms_course_settings` — 0 rows on dev and live.
**Evidence:** `checkEnrolmentEligibility()` takes the `if (!$settings) return $open;` branch for every course.
**Impact:** passing score, availability window, approval requirement, department and role
restriction are collected and never enforced. A course marked restricted is open to everyone.
**Re-verify:** `SELECT COUNT(*) FROM lms_course_settings;`

#### F-73 — Attending a session contributes nothing — HIGH
**What:** sessions attach to a course and count toward nothing.
**Where:** `LmsLearningController.php` — zero references to `lms_virtual_classroom` or `lms_session_registrations`.
**Impact:** an employee can attend every session and remain at 0% with no certificate.
**Re-verify:** `grep -c "lms_virtual_classroom\|lms_session_registrations" app/Http/Controllers/Api/LmsLearningController.php` → 0

#### F-74 — No way to sit an LMS assessment — HIGH
**What:** the Assessments tab shows history and offers no attempt.
**Where:** `routes/api.php:987-991` — authoring verbs only.
**Impact:** 80 question papers nobody can answer.
**Note:** `/candidate-assessment/{token}/submit` is Talent's hiring assessment, deliberately out of scope.
**Fix sketch:** server-scored attempt endpoints. Do not expose the legacy web scorer — it grades from a client-supplied correctness flag.

#### F-75 — `course.completed` is never emitted — MEDIUM
**Where:** `EventCatalogue.php:92` declares it, `CertificateIssuer.php:46` handles it, nothing emits it.
**Impact:** completion produces no competency evidence; `CertificateIssuer` is unreachable code.
**Fix sketch:** emit at genuine 100% only — a declared completion must not mint an unearned certificate.

#### F-76 — Whitespace not trimmed — LOW
`"   Spaced   "` accepted (201) and stored as sent. Duplicate-looking catalogue entries.

#### F-77 — Dead service scaffold — LOW
`services/lms/index.ts` calls `/courses`, `/assignments`, `/certifications`; none exist.
`grep -rn "lmsService" components/ app/ hooks/` returns **no callers**. Harmless today, a trap later.

---

## 11. WORKFLOW GAPS, RANKED BY STRANDED WORK

1. **Assessments** — 80 question papers built and unanswerable.
2. **Course rules** — every setting the builder collects, never enforced.
3. **Sessions** — a whole sub-module whose attendance means nothing.
4. **Competency feedback** — completion produces no capability evidence.

---

## 12. OPEN QUESTIONS — NOT GUESSED

1. **Is `lms_course_settings` never written, or written and failing?** Needs a course created
   through the browser wizard with the request watched. I proved the table is empty, not why.
2. **Should session attendance count toward completion?** A domain decision.
3. **Should a declared completion issue a certificate?** Still unanswered.
4. **43 of 97 courses have no lessons.** Abandoned drafts or a broken import? Needs an owner.

---

## 13. MASTER-SHEET ROW

```
| LMS | GREEN | AMBER | GREEN | N/A | GREEN | GREEN | AMBER | GREEN | RED | RED | AMBER | GREEN | GREEN | GREEN | AMBER | AMBER |
```

---

## 14. METHOD NOTES — WHERE THIS AUDIT WAS WRONG BEFORE IT WAS RIGHT

Recorded because the ground rules require it.

- A first pass at the API cross-check reported **86 of 86 paths missing**. The route
  extraction regex was broken. Caught because the number was absurd, not because the
  tooling was verified first.
- 15 "missing" governance paths were an artifact of flattening a `const BASE` template
  literal. They exist — 26 `lms/governance` routes are registered.
- Three negative tests were first read as unicode, length and foreign-key failures. All
  three had actually failed on a missing `status` field. Re-run correctly, **unicode and
  emoji pass**. The first reading would have been a fabricated finding.

## 15. AUDIT ARTEFACTS — ALL REMOVED

Two test courses soft-deleted, the cross-tenant probe enrolment deleted, both audit
tokens deleted. Verified: **0 cross-tenant enrolments remain on live.**
