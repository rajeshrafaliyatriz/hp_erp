# Authorization boundary audit — Talent Management & Competency

**Scope** every endpoint under competency / performance / onboarding / offboarding / mobility /
talent that touches a **per-employee record** — a row with an owning person.
**Date** 2026-09-30 · **Repo** `hp_erp` @ `Milan-2` · **Changes made** none. This is a read-only audit.

Cross-referenced against the **rights table** (`tblgroupwise_rights_g2g`, read on both hosts) so
each finding carries *who can actually open the screen that calls it*.

> **`lib/gtg-nav-visibility.ts` is not that control.** It was the obvious source for "who sees
> this screen" and it records `'cm-certifications': EVERYONE` at line 76 — but **nothing imports
> it**. `isMenuVisible()` and `canAccessMenu()` are exported and have zero callers; the only other
> mention in the repo is a comment in `lib/ai/conversation/permission.service.ts`. It is a
> statement of intent that no longer runs. Every visibility claim below is therefore measured from
> the rights table and `displaySidebarMenu`, not from that file.

---

## STATUS: CLOSED, 2026-09-30

Every ranked finding below is fixed. The audit text is kept as written — it is the
record of what was wrong — with this section as the record of what was done.

| # | Finding | Closed by |
|---|---|---|
| **S1** | Any employee sets any colleague's rating | A **field-level** rule in `PerformanceReviewController::update`, not a route gate: an employee self-rates, so a gate would have locked them out of their own review. Three paths — the subject, the row's own `manager_id`, then the role tier |
| **S2** | One call rewrites many ratings, then seals them | `subject:hr_elevated` on the whole calibration group, `grid` read included |
| **S3** | Mandatory learning assigned to anyone | Targets intersected with `tbluser` for the caller's tenant, rejects reported in the envelope, plus `subject:people_managers` on the route |
| **S4** | The entire Offboarding block ungated | One `subject:hr_elevated` wrapper on the group — 14 routes |
| **S5** | Self-issue a credential, then verify it | `subject:hr_elevated` on all 7 certification writes. The employee's read surface is `GET /competency/my-certifications`, which takes no subject |
| **S6** | Self-approval of appraisals, salary, bonus | `subject:hr_elevated` on all three tabs' writes |
| **S7** | Development plans and goals | `subject:people_managers` on the writes |
| **S8** | Assessments | `subject:people_managers` on create/destroy, `hr_elevated` on the cycle review |
| **S9** | Succession slates and pools readable by all | `subject:people_managers` on those three reads. `/mobility/jobs` stays open — it is the job board |
| **S10** | Bulk per-employee reads | Scoped to the caller unless elevated: reviews, applications, transfers, promotions. Dashboard feed names redacted |
| **S11** | A divergent second role list | Deleted; `CapabilityProgressController` now calls the authority |
| **S12** | Library governance ungated | `subject:hr_elevated` on `update` and `bulkApprove` |

### A fourth thing, found only by re-reading the plan against the diff

Route gates alone did **not** finish items 10-12. Three gaps survived the first pass:

- **`user_id_target` was validated only as `integer`** in certification, development-plan
  and assessment writes — no tenant check — so a foreign-tenant id could be written into the
  owner column while the row carried *this* tenant's `sub_institute_id`. Exactly the defect
  fixed in `LearningAssignmentController`, in three more places. Now `competencySubject()`,
  which answers tenancy and ownership in one call.
- **`assessor_id` had the same hole** and the audit named only `user_id`. Dropped to null
  when it is not a tenant member — an optional field should not abort the write, and must not
  store an id this organisation cannot see.
- **A credential could be verified by the person holding it.** `store()` forces
  `verification_status = 'pending'` and says a credential must never verify itself — then
  `update()` stamped `verified_by` with the caller's own id and checked nothing, so the holder
  verified it in a second request. **The route gate does not close this**: it limits the route
  to HR, and an HR user holds credentials of their own. Same shape as the S6 appraisal
  self-approval, one table over. The bulk path had it too; the caller's own rows are now
  excluded and the skipped count reported.

The lesson is the one this engagement keeps relearning: *a gate answers who may reach the
endpoint, never what they may do once there.*

Proven by `_evidence/prove-talent-authorization.php` — **71 assertions, 0 failures**, run
against tenant 6 inside a rolled-back transaction. Half of those assertions are the
regression half: **every refused call repeated as HR, expecting 200.** A guard that refuses
everybody looks exactly like a guard that works.

### What the fix is, in one paragraph

`app/Support/SubjectAuthority.php` holds two named tiers and one ordered verdict. A new
`subject:` middleware enforces a tier at the route; `performanceSubject()` and
`offboardingSubject()` enforce it per row, alongside the `competencySubject()` that already
existed. Five divergent role lists became two named tiers.

**`profile:` could not do this job**, which is why the middleware is new:
`RoleKey::ALIASES` has **no alias for `department_head` at all**, and `profile:admin,hr`
excludes `executive` and `auditor` — roles whose purpose is to read the organisation.

### Three things found while fixing, that the audit had wrong or missed

1. **`competencySubject()` was not too permissive — it was too RESTRICTIVE, and silently.**
   It read `p.role_key` raw, bypassing `RoleKey::LEGACY_NAMES`. 30 of 42 live profiles have
   `role_key = NULL` and resolve only through that table. So **20 live profiles passed
   `profile:admin,hr` at the route and were then refused by the guard** — tenant 7's HR
   profile (59 users), tenant 7's Admin (31), tenant 3's Admin (31), tenant 3's HR (12).
   Nine organisations' administrators were being told *"You may only access your own
   competency profile."* Fixed by resolving through `RoleKey`, so a gate and a row guard can
   never disagree about what a caller is.
2. **`reporting_manager_id` is not NULL for everyone.** Three docblocks and this audit said
   it was. It is populated on 8 of 2345 rows on the app database (tenant 3 only) and 0 of 299
   on live, while `department_id` is populated on 2331 of 2345. Corrected in all four places.
3. **The dashboard leak had five sources, not one.** The audit named the offboarding items.
   Onboarding, mobility moves, recruitment and the performance activity line all emitted
   names too. Worse, the performance line **cannot be redacted at read time**:
   `logPerformanceActivity` bakes the employee's name into the stored `description`, so that
   source is omitted entirely for non-elevated callers, and the durable fix — log an id,
   resolve the name per reader — is recorded as outstanding.

### Deliberately not done, as decisions

- **Backfilling `tbluser.role_key`.** 30 of 42 live profiles rely on a three-entry legacy-name
  table. Nothing is at risk today (measured), but a tenant whose HR profile is renamed to
  anything but `admin`/`hr` resolves to null, and **null grants nothing**. This changes who
  can reach every gated route in the product, not just Talent, so it belongs in its own change.
- **Real per-team scope.** Needs reporting lines populated. `PEOPLE_MANAGERS` is tenant-wide
  until then, by decision, and narrows to team scope with no call-site changes when it lands.
- **`LEAVE_ELEVATED` and `TaskPermissionMiddleware::ELEVATED`.** Two more copies of a role
  list, in modules outside this audit.
- **The activity-log storage shape.** See finding 3 above.

---

## The finding in one sentence

`competencySubject()` — the ownership guard — **exists, works, is documented, and is used by 18
endpoints. 119 endpoints never adopted it and carry no role gate either**, so any authenticated
employee can read, and in 59 cases write, per-employee records belonging to any colleague in
their organisation.

The tenant boundary holds everywhere. No path was found where a request-supplied tenant reaches
a query — `ResolvesApiIdentity.php:56-76` makes the token's own `sub_institute_id` win
unconditionally. **This audit is entirely about the second boundary, not the first.**

## Why it happened — and why it will happen again if only the endpoints are fixed

`ResolvesCompetencyContext` carries two things: `competencyContext()`, which resolves tenant and
actor, and `competencySubject()`, which decides whether the caller may act on the *named person*.

`ResolvesPerformanceContext` and `ResolvesOffboardingContext` were written as copies of it. They
copied `competencyContext()`, `activeFilter()`, the paging helpers, the activity logger and the
diff builder. **They did not copy `competencySubject()`.** There is no equivalent in either
trait, so no controller using them could have called one.

`ResolvesPerformanceContext.php:17-22` warns about a *different* problem, and the warning was
followed faithfully:

> *"the `user_id` trap. On every /api/performance/\* call `user_id` is the CONTEXT ACTOR… A
> controller that writes an owner column must take the subject from `user_id_target`"*

Every Performance controller does exactly that. `user_id_target` makes the **attribution**
correct — the right person's name ends up on the row. It says nothing about **who may name that
person**. The advice was sound, was followed, and is orthogonal to this audit. That is the trap:
the module looks careful, and the carefulness is pointed elsewhere.

## The guard that should have been used

`app/Http/Controllers/Api/Competency/Concerns/ResolvesCompetencyContext.php:78` —
`competencySubject(array $context, $requestedId)`. Two checks, both required:

1. the subject must belong to the **caller's own** tenant — so an elevated role cannot reach
   across organisations (`:90-95`, **404 not 403**);
2. the caller must **be** the subject (`:99`), or hold an elevated role (`:103-108`).

```php
private const COMPETENCY_ELEVATED = [          // :55-57
    'administrator', 'hr_manager', 'hr_executive', 'executive', 'auditor',
];
```

`department_head` and `reporting_manager` are **deliberately absent** (`:49-53`): their
legitimate scope is "my department" / "my team", and neither can be evaluated while
`tbluser.reporting_manager_id` is NULL for every user (G-ORG-02).

Its docblock names the bug class this audit is measuring (`:62-69`):

> *"**G-COMP-SEC-01**: every method on EmployeeCompetencyProfileController took `$id` straight
> from the route and never compared it to the caller. **The tenant boundary held; the ownership
> boundary did not exist.** … anyone could raise their own ratings or lower someone else's …
> **and a tampered rating does not announce itself.**"*

That sentence was written about one controller. It is true of 119 endpoints.

### A missing route gate does not mean anonymous access

Routes with no `api.token` middleware are still not public: `competencyContext()`,
`performanceContext()` and `offboardingContext()` each return 401 without a valid token.
**"No middleware" means no *role* gate, not no auth.** Every finding below assumes a legitimate,
logged-in employee of the same organisation — which is exactly the threat model that matters for
an HR module.

---

## Verdicts

| Verdict | Count | Meaning |
|---|---|---|
| **TENANT-ONLY WRITE** | **59** | Any employee can modify another employee's record |
| **TENANT-ONLY** (read) | **60** | Any employee can read another employee's record |
| **SAFE** | **108** | Token-owner subject (15) · `competencySubject()` (18) · elevated-role gate (75) |
| **CONFIG** | ~165 | Library / taxonomy / framework rows with no owning person — tenant scope is the complete and correct boundary |

Distribution of the 119 exposures:

| Area | Write | Read | Screen visibility (from the rights table) |
|---|---|---|---|
| Performance | 30 | 17 | `performance`, `performance-reviews`, `compensation`, `manager-hub` = **EVERYONE** |
| Competency | 19 | 30 | `cm-certifications`, `cm-development-career`, `cm-assessments` = **EVERYONE** |
| Offboarding | 10 | 4 | `offboarding` = **EVERYONE** |
| Mobility (reads only) | 0 | 8 | `mobility-succession` = **EVERYONE** |
| Talent dashboard | 0 | 1 | `tm-dashboard` = **EVERYONE** |

The worst combination is an **EVERYONE** screen over a **TENANT-ONLY WRITE** endpoint. Every row
above except the Mobility and dashboard reads is that combination.

---

## Ranked findings

Severity is *what an ordinary employee can change and who would notice* — not how exotic the
attack is. None of these needs a tool beyond the browser devtools console.

### S1 — Any employee can set any colleague's performance rating
`routes/api.php:2093` → `PerformanceReviewController::update:257` · **no middleware**

Accepts `self_rating`, `manager_rating`, `overall_rating`, `potential_rating`,
`manager_comments`, `status` (`:271-282`). Row lookup is tenant-only (`:265`).
`unset($validated['user_id'])` at `:287` protects the *owner column* — nothing else.

**Verified directly.** The three neighbouring routes on the same table are gated and this one
is not:

```php
Route::post('/performance/reviews/bulk',        …)->middleware('profile:admin,hr');      // :2090
Route::put ('/performance/reviews/{id}',        …);                                       // :2093  ← no gate
Route::post('/performance/reviews/{id}/advance',…)->middleware('profile:admin,hr,manager');// :2094
Route::delete('/performance/reviews/{id}',      …)->middleware('profile:admin,hr');       // :2096
```

The gated routes are the ones that *look* dangerous. The ungated one is the one that writes the
rating. That rating is the input to 9-box (`NineBoxController:39`), calibration, compensation and
bonus recommendations.

### S2 — One call rewrites many ratings, then freezes them
`routes/api.php:2142` → `PerformanceCalibrationController::calibrate:351` · **no middleware**

`ratings[]` of `{review_id, rating}` (`:373-375`) overwrites `overall_rating` across an arbitrary
set of reviews, scoped only by `sub_institute_id` + `calibration_session_id` (`:394-397`). Then
`POST .../lock` (`:2143`) makes the result immutable — `calibrate` refuses when locked
(`:365-367`), so a tampered grid can be sealed.

### S3 — Any employee can assign mandatory learning to the whole organisation
`routes/api.php:762` → `LearningAssignmentController::store:149` · **no middleware**

**Verified directly**, and worse than "no ownership check": `$targets` goes from
`user_ids[]`/`employee_id` (`:178-179`) straight into `insert()` (`:213`) with
`approval_status => 'approved'` hard-coded (`:196`) and `assigned_by_id` set to the caller.
**The target ids are never checked to exist, or to belong to the tenant.** Compare
`AssessmentReviewController:188-189`, which does exactly that check on the same shape of input.

So this is both an authorization defect and a data-integrity one: ids from another tenant can be
inserted carrying *this* tenant's `sub_institute_id`.

### S4 — The entire Offboarding block is ungated
`routes/api.php:2407-2428` · **the group carries no middleware**

**Verified directly.** 10 writes: `POST /cases` with a client-supplied `employee_id` (`:427` —
tenant membership *is* checked at `:436-443`; ownership is not), `updateStatus`, `decideClearance`,
`updateClearance`, `updateDocuments`, `uploadDocument`, `addComment`, `updateExitInterview`,
`update`, `destroy`.

Any employee can open an **involuntary exit case** against a colleague with a reason and a last
working day, write their exit-interview record, upload a document to their exit file, or delete a
live case.

The sibling Onboarding block 150 lines earlier was explicitly wrapped for precisely this reason
(`:2248-2255`):

> *"This whole block had no role check at all - any logged-in user of any role could manage any
> employee's onboarding. Wrapped in the same profile:admin,hr gate…"*

**Offboarding was missed in that pass.** This is the cheapest fix in the audit — one
`Route::middleware('profile:admin,hr')->group()` wrapper — and the closest precedent is already
in the file.

### S5 — Self-issue a credential, then mark it verified
`routes/api.php:720, 828, 825` → `CertificationController:385, 496-497, 564`

`store` takes `user_id_target ?: user_id` (`:385`). `update` retargets the owner (`:496-497`).
Create correctly forces `verification_status='pending'` (`:398-403`) — and then `update`
(`:501-505`) lets anyone stamp it `verified` with their own id as `verified_by`. `bulk` (`:564`)
verifies, rejects, revokes or deletes by `ids[]`, filtered by tenant only (`:591-593`).

`s_competency_certifications` feeds compliance reporting. **This is the screen the review
started from.**

### S6 — Self-approval of appraisals, salary revisions and bonuses
`routes/api.php:2119, 2127, 2135` (+ bulk `2115, 2123, 2131`) · **no middleware**

`PerformanceAppraisalController::decision:238` — `action=approve` sets
`approver_id = $context['user_id']` (`:274`) with no gate. Compensation and bonus follow the same
pattern. Screen `compensation` = **EVERYONE**.

### S7 — Development plans and goals
`routes/api.php:724, 742, 746-748, 2110-2112` · retarget via `user_id_target`
(`DevelopmentPlanController:421, 498`; `PerformanceGoalController:115-118`). Create, retarget and
delete another person's plans, plan actions and goals.

### S8 — Assessments
`routes/api.php:716` → `AssessmentController:69, 90` — launch an assessment naming an arbitrary
`user_id` *and* `assessor_id`. `routes/api.php:588` → `AssessmentCycleController::reviewAssessment:397`
— approve, calibrate or reject somebody else's.

### S9 — Succession slates and talent pools are readable by everyone
`routes/api.php:2365, 2366, 2367`. Mobility **writes** are correctly gated (`:2374`); the reads
were left open under a comment that only justifies opening the **job board** (`:2338-2341`):

> *"Reads are open to any authenticated member of the tenant: an internal job board is meant to
> be browsed"*

A job board is meant to be browsed. A succession slate is not the job board.

### S10 — Bulk per-employee reads
`:719`+`:824` certifications (`export` uncapped to 5,000 rows, `:141-149`) · `:723` plans
(`user_id_filter`, `:112`) · `:2091` reviews · `:584`/`:594`/`:595` cycle ratings · `:751`
career-path explorer (`employee_id`, `:339`) · `:739` whole-tenant employee directory · `:2186`
talent dashboard, whose activity feed emits `"<name> submitted <exit_type>"`
(`TalentDashboardController:619-632`) — **any employee learns who is resigning and whether it was
voluntary.**

### S11 — A second, divergent copy of the elevated-role list
`CapabilityProgressController::roster:187-197` hard-codes
`['administrator','hr_manager','hr_executive','reporting_manager']`. It **adds**
`reporting_manager`, which `COMPETENCY_ELEVATED` excludes by name and with a stated reason, and
**drops** `executive` and `auditor`.

Not a per-employee exposure — it leaks movement counts. It is listed because it is *drift*, and
drift is how the first list stops being the only list. Same class as the compliance ladder this
pass just consolidated into `CertificationCompliance`.

### S12 — Library governance (not per-employee)
`ApprovalController::update:303` / `bulkApprove:379` — routes `:507`/`:505`, ungated. Any
employee can publish or reject any competency or framework in the library.
Also `routes/api.php:1555` `POST /api/evaluation`, whose own route comment admits it:
*"the controller does not check that the caller is on the panel for the interview being scored."*

---

## What is safe — stated as plainly as the failures

**The self-service surface is correct everywhere it was checked.** `my-capability`, `my-rating`,
the five `ai-assessment` employee endpoints, `onboarding/next-steps` and
`performance/saved-views` all derive the subject from the token and accept no subject field.
`MyRatingController:87-110` goes beyond the contract: the item being self-rated must be one the
caller's **own role requires**.

**G-COMP-SEC-01 is closed on the controller it was found on.** All 14 subject-taking methods of
`EmployeeCompetencyProfileController` call `competencySubject()`. `CompetencyGapController:70`,
`CapabilityProgressController::index:74` and `JobroleTaskCompetencyMapController:472, 713` adopted
the same guard — and `:453-467` shows it being applied deliberately, not copy-pasted.

`CapabilityProgressController::index:74` is the best pattern in the codebase:

```php
competencySubject($context, $request->input('user_id') ?: $context['user_id'])
```

It **defaults to the caller**, so the self-service screen sends no id at all and the same endpoint
serves HR correctly.

Also gated and verified: the Onboarding block (`:2255`), Mobility writes (`:2374`), the competency
audit log (`:871`), KASBA ratings (`:674-681`, with subject-tenant checks at `:109-115`,
`:313-316`), and Talent Acquisition throughout (`profile:admin,hr,recruiter`). Candidate-facing
careers and offer-response surfaces are opaque-token-scoped to a single candidate and deliberately
session-free.

**`PerformanceSavedViewController:148, 204` is the only ownership boundary anywhere in
`Api\Performance\`** — `if ((int) $view->user_id !== (int) $context['user_id']) return 403`. It
exists, so the pattern is not unknown to that namespace; it was applied to saved views and to
nothing else.

---

## Recommended order — cheapest and highest severity first

Nothing here has been changed. This ordering is a recommendation, not a plan of record.

| # | Action | Cost | Closes |
|---|---|---|---|
| 1 | Wrap `routes/api.php:2407-2428` in `profile:admin,hr` | one line | S4 (10 writes) |
| 2 | Gate `PUT /performance/reviews/{id}` and `.../calibrate` + `.../lock` | three lines | S1, S2 |
| 3 | Validate targets in `LearningAssignmentController::store` against the tenant | ~4 lines, copy `AssessmentReviewController:188-189` | S3 |
| 4 | Add `performanceSubject()` / `offboardingSubject()` to the two traits, then adopt | the real fix | S1, S6, S7 and any future copy |
| 5 | Gate the appraisal / compensation / bonus `decision` routes | six lines | S6 |
| 6 | Gate certification write routes, or adopt `competencySubject()` in the controller | S5 | S5 |
| 7 | Gate mobility succession/pool reads; drop names from the dashboard feed | S9, S10 | |
| 8 | Delete the second role list at `CapabilityProgressController:187-197` | one line | S11 |

**A role gate and an ownership guard are not interchangeable.** A gate answers "may this *kind*
of user touch this screen"; the guard answers "may this user touch *this row*". Steps 1-3 and 5-7
are gates — they stop an ordinary employee but still let any HR user act on anyone, which is
usually intended. Step 4 is the only item that closes the class.

## Honest limits of this audit

- The 108 SAFE endpoints were verified at route+guard granularity. Of the 75 whose safety rests on
  an elevated-role gate, **the subject's tenant was not re-verified line by line in every one** —
  it is present in `KasbaRatingController:109-115, 313-316` and
  `AssessmentReviewController:188-189`, and was not individually confirmed in
  `MobilityPromotionController:95` / `MobilityTransferController:127`.
- The CONFIG count (~165) is a subtraction over the 464 `Route::` declarations matching these
  modules, enumerated at **controller** granularity, not route granularity. It is approximate and
  labelled as such.
- No exploit was run. Every claim is read from source, and the four marked **verified directly**
  were re-read independently of the sweep that first reported them.
- Screen visibility is measured from `tblgroupwise_rights_g2g` on both hosts, because
  `lib/gtg-nav-visibility.ts` turned out to be unwired (see the note at the top). Where a finding
  says a screen is open to **EVERYONE**, that means the rights table grants it to the tenant's
  employee profile — which an administrator can still withhold per profile. The exposure is
  *configurable*, not absent.
- Measured for the admin certification screen (menu 158) on tenant 6 before this pass:
  the Employee profile held `can_view = 1` on **both** hosts. On live the Talent container was
  also granted, so the screen was genuinely reachable; on app the container was missing, so the
  right was held but unreachable — F-209's shape. Both were corrected in this pass by
  `2026_09_30_110200`, which revokes that row, and `110300`, which finishes the job for a
  NULL-tenant row the first, wrongly-scoped delete could not see.

## What this pass actually changed

The audit above is unchanged by it: **none of S1-S12 is fixed.** What was built is the
employee half, plus the two defects that were already in scope.

| Change | Effect |
|---|---|
| `MyCertificationsController` + `GET /competency/my-certifications` | An employee sees and downloads their own credentials over an endpoint with **no subject parameter**. Proven: called with a colleague's id as `user_id`, `user_id_filter` and `user_id_target`, in query and body, all four shapes returned only the caller's row |
| `CertificationCompliance` (new class) | The compliance ladder, `daysToExpiry` and the 60-day window moved out of `CertificationController` so HR and the employee cannot disagree about a credential. Eight boundary cases pass, and the controller's constants are asserted identical |
| Menu 401 "My Certifications", menu 404 "My Capability" | Both pinned, both hosts, rights granted to **every profile that can open Talent Management** |
| `CmMyCapabilityScreen` wired | A complete 299-line screen that was in no content map. Its three endpoints were called with a plain employee's token on tenant 6 first — 200, 200, 200 |
| Menu 158 revoked from every `employee` profile | The admin Certification Center, whose controller has no ownership check at all (S5). This is the only *reduction* in access in this pass |
| `CertificationController::store`/`update` backfill placement | `department_id`, `jobrole` and `jobrole_id` are inherited from the credential's owner when the caller omits them. They were NULL on every certification created through the UI, so those rows were invisible to the Department filter and could not satisfy a department-scoped requirement — which under-counted the "Compliant Employees" KPI |
| `certificates-tab.tsx` download + verify | Both were relative paths resolving against the Next origin, where neither route exists; both 404'd. They now use `lmsCertificateService`, which already knew how to reach them |

### Three defects found in this pass's own migrations, and fixed

Recorded because each is an instance of a class this audit is about, and each was found by
measuring the result rather than by reading the code back:

1. **A tenant-scoped delete that revoked nothing.** The first revoke of menu 158 filtered on
   `sub_institute_id`. That column is `text NULL` with 4,379 NULL rows on live, and
   `displaySidebarMenu` reads rights with **no tenant predicate** — so profile 3 "Employee"
   kept the right on a NULL-tenant row and the screen stayed reachable. *A write path that
   filters on a column the read path ignores does not revoke anything.* Fixed in
   `2026_09_30_110300`.
2. **A CSV tenant id that coerced to `1`.** The grant looped `DISTINCT sub_institute_id` from
   the rights table, which contains `'1,2,3,4,5,6,7,8,9,10,11'`. Compared against a `bigint`
   column MySQL coerces that to `1`, so tenant 1's profiles matched twice and got duplicate
   rows — 9 on app, 3 on live. Same migration.
3. **A half-applied menu shift.** Making room and inserting were two statements with no
   transaction; the insert failed on an id taken minutes earlier by an unrelated row, the
   shift stayed, and Talent's children ran 1..8 then jumped to 11 on one host and not the
   other. Nothing looked broken — a gap sorts the same as no gap — the damage was that the two
   databases stopped agreeing. Fixed in `2026_09_30_110350`; both inserts are now atomic.

### One hygiene observation, not fixed

`tblgroupwise_rights_g2g` holds rows for profile ids that do not exist in
`tbluserprofilemaster` — 4 on live (profiles 31-34), granting menu 3 among others. They grant
nothing today, because a profile nobody can hold cannot sign in. They are noted because they
inflate every rights count in this document and will mislead the next audit.

## See also

- `Docs/talent-audit/FILTER-INTEGRITY.md` — the companion sweep of all 156 filter controls.
- `app/Support/Competency/CertificationCompliance.php` — the compliance ladder, consolidated in
  this pass so HR and the employee cannot disagree about a credential.
- `app/Http/Controllers/Api/Competency/MyCertificationsController.php` — the read-only employee
  endpoint added in this pass, built to the no-subject-parameter pattern above.
