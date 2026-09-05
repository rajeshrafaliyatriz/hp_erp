# Talent Management — remediation progress

One page. What is done, what is left, and what is deliberately not being done.
Source of truth for the path; each sprint has its own write-up with the evidence.

Audit: `AUDIT-TALENT-MANAGEMENT.md` (verdict RED). Findings run F-53…F-65.

---

## Done

| Sprint | What it closed | Write-up |
|---|---|---|
| **0** | Tooling trust gate. `tsc` baseline **7**, both databases reachable, `route:list` 1847. Captured the before-state so later fixes could be proven, not asserted. | `SPRINT-0-VERIFICATION.md` |
| **1** | **F-53** candidate PII readable by any employee → 403. Screening results cross-tenant read (**executed breach**, 200 → 404). `TalentOfferController::reject()` had no tenant filter *and* wrote an invalid enum under `STRICT_TRANS_TABLES` (was returning 500). Two routes revived that pointed at methods which never existed. Legacy ATS **writes** moved to token-derived tenancy. | `SPRINT-1-SECURITY.md` |
| **1b** | **The mobility hole Sprint 1 missed.** Its edits stopped at `routes/api.php:1646`; the mobility group starts at :1734. `POST /api/mobility/promotions` with `status:"Completed"` rewrote another employee's `tbluser` + `org_designation` — ungated, 3 tables, no transaction, no subject tenant-check. All 15 writes gated, reads left open, transactions added. | `SPRINT-1B-MOBILITY.md` |
| **2** | The severed joint: offer **accept** did not exist, so no hire ever became an employee. `EmployeeFactory` extracted (not copied) from the directory controller so both writers agree. `talent_offer_acceptances` on both DBs. Idempotent twice over. | `SPRINT-2-HIRE-SPINE.md` |
| **3** | Two kanban columns that silently no-op'd (`Assessment`, `Offered`) — the checked assignment was dead code below an unconditional one. Hiring decision wrong in **three** ways. `DELETE /api/feedback/{id}` built (a confirmed button led to 405). Workflow detail route. **F-28** invented admin KPIs → real counts. **F-55** `Accept: application/json` — restores field-level validation messages product-wide. | `SPRINT-3-CONTRACTS.md` |
| **data** | 58 `talent_job_applications` rows with `status = ''` — a bulk import that landed empty, invisible to every filter. Repaired to `Pending Review` on both hosts, reversal SQL saved. | `_local-backups/REVERSAL-2026-09-03-empty-status.sql` |
| **4a** | The candidate had **no surface at all**. Public per-tenant careers page + apply, rate limited (a new control — the app had none). `careers_slug` on both DBs. | `SPRINT-4A-CAREERS.md` |

| **4b** | Candidate answers their **own** offer with no login: signed, expiring, single-use token, **hash stored not the token**, every failure a uniform 410. Acceptance collapsed into one service both HR and the candidate call. **Candidates kept as people** — `talent_candidates`, keyed on a hashed email because the natural index would exceed live's 767-byte cap; backfill found **57 repeat applicants**. Consent is a column, defaults off. Mail enabled for tenant 6 only, **with the tripwire amended in the same change**. | `SPRINT-4B-OFFER-LINK.md` |

---

| **5** | Seven of eight handoffs closed — see below. Review scoring had a working endpoint, service and hook and **no caller**. Probation termination was a dead end. Internal Job Postings **could not be used by any tenant but one** (a per-tenant code against a global unique → 500). Nine `Api/Performance` writes made atomic, and the wrappers proved real (all tables InnoDB, rollback demonstrated). Interview feedback could not hold Gujarati or an emoji — it destroyed them at write time. The tenant index was missing entirely. | `SPRINT-5-HANDOFFS.md` |

---

## Sprint 5 in detail — 7 of 8

- ✅ **1. Review scoring has a screen.** 6 of 235 reviews had ever been rated. No backend work.
- ✅ **2. Probation termination opens an exit case**, in one transaction, via a shared
  `OffboardingCaseFactory`.
- ✅ **3. Mobility.** The "Hire" tooltip was a **label** bug — it did what the honestly-named "Offer"
  button does; renamed. The real gap was after: an offered applicant was a dead end, so **Draft
  transfer** now pre-fills the existing transfer dialog from the application. Found and fixed a
  defect that made Internal Job Postings unusable for every tenant except the first to post.
- ✅ **4. `journey.stage` holds `offer_accepted`.** The filter dropdown had always offered "Offer
  Accepted" and it could only ever return nothing. Widening it exposed a worse pre-existing bug:
  **KPI tiles drilled down on a different question from the one they counted** — one read "1" and
  opened a list of 2 different hires. Count and filter now share a constant.
- ✅ **5. F-58 transactions** — the nine orphan-capable writes. ~25 single-entity writes still lack
  one; their failure mode is a lost audit-log row, not an orphan. Stated, not hidden.
- ✅ **6. F-60 latin1 → utf8mb4** on both hosts. Checked for double-encoding first (1 high-byte row,
  0 double-encoded) and confirmed with each server that its `latin1` is cp1252, so the conversion is
  lossless.
- ✅ **7. Index** `(sub_institute_id, status)` on both hosts. I predicted the optimiser would ignore
  it at 281 rows; it did not, and the tenant-only count is now answered from the index alone.

**Corrected here:** my earlier claim that every exit case had a null manager was wrong — `store()`
takes `manager_id` from the request and the bad column was only a fallback. The fallback has never
fired, and fixing it changes nothing today because `reporting_manager_id` is populated on **0 of 299**
rows on live. A latent bug closed, not a repair.

---

- ✅ **8. F-59's two empty tables kept and made real.** Both gained `sub_institute_id` (the finding
  closed), plus `deleted_at`, and `role` went ENUM → VARCHAR. Two controllers, two screens — the
  Administration **Permissions** tab stopped saying "Module Under Construction" and became the
  hiring team roster, and the candidate Screening tab gained the recruiter's own review beside the
  AI analysis. Seeded on both hosts through the controllers, 6 members and 8 reviews.

**Corrected here too:** I said both tables had "no code path". `talent_team_members` is a registered
entry in the department merge/delete engine — dropping it would have removed a table the product was
still reading, and its rows now surface a line that dialog had never once printed.

| **6** | Deleted the dead v1 generation. `tsc` **7 → 4**, routes **1864 → 1815**. Both live sub-blocks inside the v1 route block (`/talent/dashboard*`, `/talent/admin/workflows*`) kept; `AdminWorkflowController`, `TalentDashboardController`, `ResolvesTalentContext` kept. | `SPRINT-6-AND-LIVE-REAUDIT.md` |
| **re-audit** | Drove one full hire→review lifecycle through **tenant 6 on the live host (128.199.17.97)**, every role. It had never completed one there. Found and fixed four HIGH findings by running it: **F-67** an executed cross-tenant application write/theft, **F-68** every v2 Eloquent create 500ing on MariaDB 10.1, **F-69** offer create refusing an allowlisted tenant + a status update blanking a posting. All re-verified on live; host restored to baseline. | `SPRINT-6-AND-LIVE-REAUDIT.md`, `AUDIT-TALENT-MANAGEMENT.md` F-67…F-69 |

---

## Remaining

Sprints 0–6 and the live re-audit are done. What is left is the standing RED items in the original
audit that were out of scope for this remediation (see the findings register), plus the deliberately
deferred features below.

**Deferred, deliberately:**

- **Candidate assessment — re-confirmed, still the right call.** There is no candidate-facing
  assessment surface anywhere; the "Assessment" kanban column writes a status string nothing reads.
  Re-measured: `competency_assessment_attempt.user_id` has **no FK**, there is **no `subject_type`**,
  `mine()` selects the test by job role (a candidate has none), and `approve()` upserts
  `competency_kasba_rating` on that same `user_id` — so a synthetic candidate id equal to a real
  employee id would rate a real person. Needs a discriminator, a non-jobrole test rule, a magic-link
  taker page, and a gate on `approve()`. Its own sprint. See `TALENT-DEEP-RECHECK.md`.
- **Interview slot picking.** `talent_interview_schedules` models one fixed moment and has no
  create-migration. Needs a new child table and two screens — a separate feature.
- **F-23** `mockProfileData` renders in the routed Recruitment screen. Fix, do not delete.

---

## Known traps — read before touching these

- **Two databases disagree about invalid writes.** App DB has `STRICT_TRANS_TABLES` (errors); live has
  `NO_ENGINE_SUBSTITUTION` (silently writes `''`). That is how 58 rows lost their status.
- **`Schema::hasTable()` throws on live.** Use the `information_schema` helper.
- **`ROW_FORMAT` differs** — Dynamic on dev, Compact on live. Dev accepts index prefixes live rejects
  (767-byte cap).
- **The v1 `OffboardingCaseController` cannot execute** — it writes five columns that do not exist.
  Any offboarding work targets `Api\Offboarding\OffboardingController`.
- **Two live routes hide inside the v1 block** — `/talent/dashboard*` and `/talent/admin/workflows*`.
  Deleting the block wholesale takes out two working screens.
- **`services/talent/index.ts` and `admin-data.ts` look dead and are not** — both export types used by
  live code.
- **hp_brain is a separate product sharing this database.** Nothing in this work touches any
  `hpbrain_*` table.
- **Check the database, not the status code.** Sprint 3 found a 200 that wrote nothing and a 200 that
  wrote `Banana`.
- **MySQL's `latin1` is cp1252, not ISO-8859-1.** It matters when converting: byte `0x97` becomes an
  em-dash, not an invisible control character. Ask the server, don't reason about it.
- **A KPI's drill-down filter is not the same thing as its count.** Two Onboarding tiles asked
  different questions from the lists they opened. Worth checking the others.
- **Per-tenant codes need per-tenant uniques.** `s_mobility_jobs.job_id` generated per tenant against
  a global unique broke the feature for every tenant but one. Look for the same shape elsewhere.
- **Soft-deleting a test row is not cleaning up.** It leaves permanent junk. Hard-delete probe rows
  and prove the table matches a snapshot taken first.
- **A table can look dead while a generic registry still reads it.** `talent_team_members` had no
  model, no migration, no controller and no route — and was listed in
  `DepartmentMergeService::DEPARTMENT_ID_TABLES`, so three live routes queried it. Before calling
  anything dead, grep the registries, not just the feature code.
- **The two hosts disagree about tenant 6's department ids** (app 87…1860, live 117…2198) and about
  which departments exist at all. Never hard-code a department id in a seeder or a fixture — resolve
  it by name, per host.
- **The 128.199 host runs MariaDB 10.1.48; the app's default host runs 10.11.** Eloquent's column
  introspection (`getColumnListing`, used by `$guarded` models) selects `generation_expression`,
  which 10.1 lacks — so an Eloquent `create()` that works on the default host 500s on 128.199. A bug
  that only appears on one host is invisible until you actually run against that host (F-68).
- **Test on the host the brief names, not the convenient one.** F-67 and F-68 were both invisible on
  the app's default database and only surfaced when the lifecycle was run against tenant 6 on the
  live host.
- **`talent_jobpostingcontroller::update()` and the application `update()` wrote request identity
  columns** (`sub_institute_id`, `updated_by`) and, for postings, blanked absent fields. When
  touching a legacy `talent_*` writer, check both: identity from the token, and only-sent-fields on
  the write.

## Gates every sprint must pass

```
npx tsc --noEmit   7 (→ 4 after Sprint 6)      npm run build   exit 0, seen
npx eslint         no worse than 101           git diff --stat components/ui/   empty
migrations         one file at a time, BOTH databases, then proved equal
every endpoint     404 cross-tenant · 403 wrong role
```

The brief's stated lint baseline of "4" is stale — measured, it is **101**, verified by stashing.

## Phase 1 — candidate assessment (in progress)

**Landed and proved on tenant 6:**

- **F-70 — AI marking never worked.** Root cause was format imitation, not DeepSeek. Full write-up
  in `AUDIT-TALENT-MANAGEMENT.md`. Measured 6/6 complete markings, zero retries, after the fix.
- **`CandidateAssessmentService`** — the one place that decides shortlisting. HR's
  `qualification_marks` is compared in MARKS (what HR was asked to set), not percent. At or above,
  the application moves `Assessment → Interview Scheduled` in the same transaction as the result and
  a `candidate.assessment.graded` event. Below it, the candidate stays put for a person to decide —
  a failing score never auto-rejects.
- **A paper still awaiting human marking is not judged.** If the AI fails on the written half,
  `total` counts only the auto-scored MCQs, so a strong candidate would look weak. It holds at
  `submitted` and asks for a person instead.
- **Idempotent.** The move is scoped to `status = 'Assessment'`, so a replayed or late result cannot
  drag somebody back out of Offered or Hired. Proved: re-running finalise moves nobody twice.

**Proved 3/3 consecutive full runs**, each on a throwaway application that is deleted afterwards:

```
score 90.00 / 100.00   percent 90   qualified=YES   awaiting=0   moved=YES
application status        : Interview Scheduled    PASS
events written            : 1                      PASS
event tenant/entity       : 6/talent_job_application#978 PASS
competency ratings 98->98 : must not change        PASS
```

That last row is the one that matters most: a candidate result never becomes an employee's
competency rating. The gate in `approve()` / `feedReviewCycles()` is what stops it.

**Still open in Phase 1:** recruitment-scope generation (`generate()` is competency-map driven and
reads `s_user_jobrole`; the recruitment path needs `s_jobrole` + sector/skills/tasks), the invite
endpoint and email, the public magic-link taker page, and the result block on the Screening tab.

**Note:** 11 `candidate.assessment.graded` events from these runs remain in `g2g_event` on the app
host. The store is append-only by design ("a mistake is corrected by a compensating event"), so they
were not deleted. They reference applications that no longer exist. Say the word and I will add a
compensating event or remove them as test data.

## Phase 1 — candidate assessment: COMPLETE, proved end to end

**20/20 assertions pass** over real HTTP against tenant 6, on a real catalogue
role (Senior Physiotherapist, Healthcare — one of the industries you said you had
no idea how to hire for).

```
HR creates a blueprint (4 questions, 100 marks, pass mark 40)   PASS
pass mark above the total is refused                            PASS  422
a second blueprint for the same role is refused clearly         PASS  422
HR invites -> DeepSeek writes the paper                         PASS
  marks sum to the blueprint total (100)                        PASS
  questions cite no competency item                             PASS
candidate opens the link with NO login                          PASS
  answer key NOT leaked (correct_option)                        PASS
  answer key NOT leaked (model_answer)                          PASS
answers save as they type (4/4)                                 PASS
submit                                                          PASS
  outcome NOT revealed to the candidate                         PASS
  link cannot be reused                                         PASS  410
every written answer AI-marked, with feedback stored            PASS
below the mark -> stayed in Assessment                          PASS
pass mark set to the exact score -> moves (>= boundary)         PASS
competency ratings untouched (98 -> 98)                         PASS
```

### What was built

| Piece | Where |
|---|---|
| Public token routes (410 uniform, throttled) | `routes/api.php`, `CandidateAssessmentResponseController` |
| HR blueprints, invite, result | `Api/Talent/TalentAssessmentController` (6 routes) |
| Shortlisting rule, one place | `Services/Talent/CandidateAssessmentService` |
| Generation from the 3,347-role catalogue | `Services/Talent/RecruitmentAssessmentGenerator` |
| Candidate page, no login | `app/assessment/[token]/page.tsx` |
| The paper, shared by both audiences | `components/domain/competency/assessment-paper.tsx` |
| Result on the recruiter's Screening tab | `candidate-assessment-block.tsx` |

### No duplicate screens — checked, not assumed

A frontend sweep found **no existing candidate-assessment UI**: zero hits for
`blueprint`, `magic link`, `take test`, `sit test`, and no `*assess*` route
segment. The five existing `cm-assessment-*` screens are all session-bound, and
`cm-my-assessment` **cannot** be repointed at a candidate even in principle —
`GET /competency/ai-assessment/mine` takes no subject, deriving the paper from the
caller's job role via their Sanctum token. A candidate has neither.

So rather than add a second paper, the existing markup was **extracted**:
`assessment-paper.tsx` now renders the paper for both the employee screen and the
candidate page. Net effect on `cm-my-assessment.tsx`: **-3,457 characters of
markup**, same behaviour.

### Two bugs found only by testing end to end

- **F-71 — MCQ options were silently truncated on live.** `correct_option` and
  `selected_option` were `VARCHAR(50)`, holding the option's FULL TEXT. A real
  generated option was 101 characters. The app host is strict so it errored; LIVE
  IS NOT, so it would have stored a cut copy and marked that question wrong for
  everyone, forever, with no error. This predates candidate assessment — the
  employee flow had it too and had simply never met a long option. Widened to
  `VARCHAR(255)` on both hosts (neither column indexed, verified first) by
  `2026_09_04_120000_widen_assessment_option_columns`, with the generator capped
  at 240 and a validator that drops an over-long question instead of failing the
  paper.
- **Two MCQ scorers had already drifted.** `AiAssessmentController` compared
  `selected_option` exactly; my candidate path lower-cased and trimmed
  `answer_text`. Same paper, two marks. Now one implementation —
  `AssessmentScoringService::scoreMultipleChoice()` — called by both.

### Gates

```
tsc 4 (baseline, unchanged)   eslint 101 (baseline, unchanged)
npm run build exit 0, /assessment/[token] in the route table
git diff --stat components/ui/ empty
migrations run on BOTH hosts and proved identical
DeepSeek spend for the whole session: ~$0.01 ($1.69 -> $1.68)
```

All test rows removed afterwards, and one correction: posting 353's `jobrole_id`
was restored to NULL — my E2E's restore captured an already-modified value on a
second run, so it had been left at 976 while all 12 other tenant-6 postings are
NULL.

## Phase 2 — the broken things: COMPLETE, each proved by execution

Every item was verified before and after by calling the API and reading the
database, never by reading the diff.

### 1. Offboarding — two dead tabs restored (5/5)

`index()` returned no `clearance_tasks`, `exit_interview_done` or
`exit_interview_date`, though `$query->get()` selects `*` — the row carried them
and the projection dropped them. Only `show()` returned them.

```
BEFORE  list keys: caseId, department, employee, exitReason, exitType, id,
                   lastWorkingDay, location, noticeDate, owner, status, updatedOn
        clearance_tasks ABSENT · exit_interview_done ABSENT

AFTER   list carries clearance_tasks                     PASS
        four departments represented (IT, HR, Finance, Admin)  PASS
        clearing 4 of 8 tasks moves the list to 50%      PASS
        a saved interview is visible in the LIST         PASS
```

The failure was silent: every case read 0% cleared forever, the four department
columns rendered a green "N/A" that looks like a pass, and the Exit Interviews
list said "Pending"/"Not Scheduled" even for an interview saved a moment before —
because the save worked and the list could not see it.

### 2. Mobility — the filter that could never match, and a cross-tenant leak (7/7)

```
departments returned by /mobility/filters: 1226 -> 50   (13 tenants -> this one)
?department=87  (id, what the UI sends)   0 -> 1 rows
?department=Information Technology (name) 1 -> 1 rows   (still works)
?department=<another tenant's id>              0 rows
?department=99999999 (unresolvable)            0 rows   (not "everything")
```

`filters()` was the only list in its own method without a tenant predicate —
employees and jobroles both had one. 1,226 other organisations' department names
were readable by any authenticated user.

### 3. Location / grade / job type — derived, not invented

The backend never served them; the client carried five Indian cities, a grade
ladder and three job types, none of which match what tenants store. Now derived
from distinct values in the tenant's own rows, so an option exists exactly when
something matches it — and renders "None recorded yet" when there is nothing,
rather than five options that all return empty.

### 4. Complete no longer destroys the justification (4/4)

`'remarks' => $request->input('remarks')` was unconditional, and the client sends
only a status on a stage change — so Complete NULLed the reason for the move, in
a column rendered beside the button. Guarded with `has()` on both transfers and
promotions, so an explicit empty string still clears it.

### 5. A job-role name could poison an employee's record — and did (7/7)

`$allocatedStandards = $jobroleId ?: $name` wrote the typed NAME into
`tbluser.allocated_standards` on a lookup miss. That column holds a numeric
`s_user_jobrole` id in **21 of 21** populated rows in tenant 6.

**This is not theoretical — I triggered it.** Completing one test promotion set
real employee 28's `allocated_standards` to `'Senior Analyst'` where the correct
value was `'4342'`. It had to be restored from the live host, which had been left
untouched. A miss now leaves the column alone and logs; the mobility record still
completes and `org_designation` is still updated.

Two more found on the way:

- **Completing a promotion with no `proposed_designation` returned a 500.**
  The column is nullable but `org_designation.designation` is NOT NULL, so the
  insert failed *inside* the transaction and rolled the status change back too —
  the user saw an error and nothing happened, with no clue why. Now refused
  before the transaction with a sentence naming the field.
- **`s_mobility_transfers` writes now leave `department_id` alone only when
  intended** — the department move always applies, since it is an id either way.

### 6. Invented analytics — the backend was the source (verified fix)

The audit claimed the frontend discarded real figures. That was **half right**:
the numbers on the wire were invented too.

```php
'transfers'     => max(20, $completedTransfers),   // real: 4
'promotions'    => max(16, $completedPromotions),  // real: 0
'lateral_moves' => max(10, $completedTransfers / 2),
'ready_now'     => max(6,  $readyNow),             // real: 0
'no_successor'  => 0,                              // hardcoded
```

…under a comment reading *"Fallback dummy to look complete if database is fresh"*.

A floor is worse than a constant: with 4 real transfers it reported 20, with 25
it would report 25 — sometimes true, and nothing said which.

```
movement_summary   : 20/16/10 = 46  ->  4/0/0 = 4
succession_coverage: 6/10/6/0 = 22  ->  0/0/0/0 = 0
```

`lateral_moves` was `$completedTransfers / 2` — a fraction where a headcount was
shown. It now counts completed transfers whose job role did not change, which is
a real definition. `no_successor` is counted rather than asserted to be zero: it
is the one figure meant to prompt action and could never read as anything but
reassuring.

The two cards are wired to that data with a conic-gradient ring, so the arcs and
the legend are computed from the same numbers and cannot disagree. Zero renders
as a flat ring and a plain "0" — an empty organisation is a real answer.

### Gates

```
tsc 4 (baseline)   eslint 101 (baseline)   npm run build exit 0
git diff --stat components/ui/ empty
```

All test rows removed; employee 28 restored and re-verified (21 of 21
`allocated_standards` values numeric again).

## Phase 3 — dead controls: 63 → 35, and a detector to keep it there

### The gate is a scanner, not a list

`scripts/dead-controls.mjs` walks all 70 talent + competency screens and reports
buttons/menu items with no handler, state written and never read, view modes that
can be selected but never render, and hardcoded numerals in progress/value props.
It exits non-zero, so it sits beside `tsc`, `eslint` and `build`.

This replaced the previous approach, which could not be trusted: the last audit
produced 45 claims and its verification pass died, so 43 were never checked.

**Three false-positive classes were found and fixed in the scanner itself**, each
because a real pattern was being flagged:

| Wrongly flagged | Why it is fine |
|---|---|
| `setCategory('all')` in `clearAll()` | a filter reset, and `category === 'all'` is read |
| `if (state === 'error') { return … }` | an early-return render branch |
| a comment saying *"was `Progress value={60}`"* | the scanner was reading its own evidence |

### Cleared to zero

| Screen | Before | After |
|---|---|---|
| `recruitment-center.tsx` | 8 | **0** |
| `offboarding-center.tsx` | 8 | **0** |
| `mobility-center.tsx` | 7 | **0** |
| `admin-center.tsx` | 5 | **0** |

**Built, not removed** — the policy you asked for. Where a real endpoint existed
it was wired; where none did, the control says so on hover instead of failing
silently:

- **Recruitment bulk bar** — Move Stage now moves the selection (sequentially, so
  the optimistic rollbacks cannot interleave); More clears/selects. Assign
  Recruiter, Send Email and Add Tag are **disabled with the reason**: there is no
  recruiter column, no bulk-mail route and no tag table.
- **Offboarding** — the History toggle rendered nothing and **blanked the page**;
  it is now a timeline grouped by month. The drawer's Overview tab was a static
  mockup: "Notice Period 30 Days" is computed from the case's own dates, the case
  owner and approver come from the record (they were "Priya Sharma" and
  "Rahul Das" on every case), Next Actions are the real pending clearance items
  and unverified documents, and both progress bars are measured — `value={60}`
  and `value={30}` were literals beside live fields.
- **Mobility** — Table / Board / Timeline all render now (Board groups by status,
  Timeline by posted month, both from the same `jobs` array — no new endpoint).
  Sort-by was a label with a chevron and no menu. Save View is **disabled with
  the reason**: saved views have no storage outside Performance, and generalising
  `s_performance_saved_views` is a two-host schema change, not a button.
- **Administration** — the **Audit Logs** tab is real. It reads `g2g_event`
  directly via a new `GET /api/talent/admin/audit-logs`, because that store is
  written in the same transaction as the change it describes and has no UPDATE or
  DELETE path. Verified: 37 events for tenant 6, real types and actors.

### A dead control tsc had been reporting all along

`admin-center.tsx` passed `onValueChange` to a `Select` whose prop is `onChange`.
The prop was silently ignored, so the **Module filter never fired**. It was two of
the four baseline `tsc` errors. **tsc is now 2, down from 4.**

## The lifecycle: one real break found and closed

You asked that the ten submodules be connected rather than ten separate screens.
The mechanism already exists — `g2g_event` plus reactors (`LearningAssigner`,
`CertificateIssuer`, `CapabilityEvidenceProjector`). The gap is which modules
*emit*.

**`employee.role_assigned` was emitted by exactly one place**: the HR Employee
Directory screen (`EmployeeDirectoryController:387`). So a promotion or transfer
completed through **Mobility & Succession changed the employee's role and told
nobody** — and `LearningAssigner` reacts to that event by assigning the new role's
**mandatory** courses (`LearningAssigner:92,178`).

The consequence: someone moved by HR through the directory got their new role's
training. The identical move made through Mobility did not, and nothing reported
the difference.

Both mobility completion paths now emit it, inside the caller's transaction.
Proved 8/8 — two completions, two events, with `source=mobility.transfer` and
`source=mobility.promotion` in the payload.

```
Recruitment ──assessment.graded──> Interview        (built, Phase 1)
Offer accept ──employee.hired────> Onboarding + LearningAssigner   (existed)
Mobility ────employee.role_assigned──> LearningAssigner   (WAS MISSING, now emits)
Offboarding ──employee.offboarded──> reactors          (existed)
Everything ──────────────────────> Administration audit tab  (built)
```

### Gates

```
tsc 2 (was 4 — one baseline bug fixed)   eslint 102 = pristine repo baseline
npm run build exit 0                     git diff --stat components/ui/ empty
node scripts/dead-controls.mjs           35 (was 63)
```

**A correction on the eslint number.** I had been reporting a baseline of 101.
Measuring it properly — stashing every uncommitted change — the pristine repo is
**102**. The 101 was my own earlier improvement, not the repo's starting point.
Current 102 is therefore net-neutral, not a regression.

---

## The five onboarding workstreams (F-85, F-86)

**IT Provisioning · Learning & Training · Payroll Setup · Benefits Enrollment · Compliance**

I first told you these "are not built at all". **That was wrong.** The plumbing was
complete — five cards, a category filter, `talent_onboarding_tasks` with
`category`, `owner_label`, `due_date`, `sort_order`. What was missing was in two
layers, and each needed a different fix.

**Layer 1 — nothing seeded the checklist (F-85).** Journey creation seeded stages
only; the sole writer of a task row was a human pressing "Add Task". Across the
whole installation there was **1 task**, so all five cards read *"No tasks yet"*
on every journey in every organisation. Offboarding already had
`DEFAULT_CLEARANCE_TASKS` — the exit door had a default checklist and the entry
door had none. Fixed with a 15-task template seeded beside `seedStages()`, due
dates as offsets from the joining date (payroll −5, IT −3, compliance +1,
benefits +7), and a null joining date producing **null** due dates rather than
offsets from today.

**Layer 2 — a ticked task was the only record (F-86).** Ticking "Issue laptop"
left no serial, so exit had nothing to reclaim; ticking "Acknowledge handbook"
left no version, so a re-issued handbook read as already signed. Three tables
added, payroll needed none (the nine `tbluser` columns already existed), and
Learning deliberately **reads** `lms_course_enroll` rather than keeping a second
list that would drift from the role mapping.

It lives under the five cards on the existing Preboarding tab — clicking a card
now opens what that workstream recorded, where before it only filtered the task
table. No sixth tab: F-81 had just de-duplicated them.

```
Journey created ──seedTasks()──> it=4 learning=2 payroll=4 benefits=2 compliance=3
Card clicked ────────────────> the assets / benefits / acks / payroll behind the count
Payroll saved ───────────────> tbluser, the same columns the Employee Directory reads
Asset returned ──────────────> serial frees up; offboarding can reclaim it (not wired yet)
```

### Gates

```
tsc 2 = baseline                      eslint 102 = pristine repo baseline
npm run build exit 0                  git diff --stat components/ui/ empty
node scripts/dead-controls.mjs 0      node scripts/offer-defaults.test.mjs 9/9
seeding proof 8/8                     capture proof 20/20 over HTTP, tenant 6
migration parity 14/14 on both hosts  every test row removed afterwards
```
