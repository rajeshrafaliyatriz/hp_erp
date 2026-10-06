# Talent Management — the true state, and what "100%" would actually require

You asked how this module is 100% perfect. It is not, and this is the list. Everything here is
file:line verified or executed; nothing is inferred.

I also owe you a correction on how I framed the last report. I said "the lifecycle runs end to end"
and "17 dead buttons" in the same breath without reconciling them. The lifecycle *path* does run —
I executed it on the live host. That is a much narrower claim than the module being finished, and
putting the two side by side without saying so made the work look more complete than it is.

---

## Part 1 — What was already built, that I nearly duplicated

You were right to ask "did you make any duplicate screen?". Investigating the assessment sub-module
you mentioned found that **most of an assessment engine already exists and works**:

| Already there | Where |
|---|---|
| Server-side DeepSeek client, budget floor, truncation handling, token accounting | `app/Services/DeepSeekService.php`, `config/deepseek.php` |
| AI **generation** of a test from a job role | `AiAssessmentController::generate()` :57 |
| Take / start / submit / auto-score MCQ | `AiAssessmentController` :500, :798, :581 |
| **AI marking of written answers by DeepSeek** | `AssessmentScoringService::scoreShortAnswers()` :79 |
| Rating **proposals** needing human approval, never silent rating changes | `competency_assessment_rating_proposal` |
| **A live admin generator screen** | `cm-assessment-generator.tsx`, menu id 155, `status=1` on **both** databases |
| Taker screen, result screen, admin marking console | `cm-my-assessment.tsx`, `cm-assessment-result.tsx`, `cm-assessment-console.tsx` |

**A second generator would have been a genuine duplicate of a working screen.** The plan is now to
extend these, not rebuild them.

It also answers your industry worry without you having to guess: `s_jobrole` holds **3,347 roles
across 44 sectors** — Healthcare 193, Financial Services 186, Built Environment 141 — with
`s_jobrole_skills` (64,923) and `s_jobrole_task` (55,868) behind them. The admin picks the role; the
sector and its real skills and tasks become the prompt. The AI is not inventing what a nurse needs.

### The genuine gaps for candidate assessment

1. **No coding question type.** `FORMATS = ['mcq','short_answer']` (`AiAssessmentController:50`).
   The taker UI has two branches and everything non-MCQ falls through to a plain `<Textarea>` —
   no editor, no language, no run. `format` is `varchar(30)` on purpose, so adding a type is code.
2. **Generation needs `jobrole_competency_map`, which has 46 rows.** Most of the 3,347 roles cannot
   generate anything, and `question.kasba_item_id` is `NOT NULL` — an aptitude question cites no
   competency item, so your use case is blocked by the schema as it stands.
3. **`talent_job_postings` has no `jobrole_id`** — recruitment cannot reach any of the role material.
4. Nothing happens when HR drags a candidate to Assessment.

---

## Part 2 — Done in this pass (the safety foundation)

Built first, deliberately, so the dangerous path is closed before anything can reach it.

- **`subject_type` on `competency_assessment_attempt`, `_response`, `_rating_proposal`**, plus
  `ai_feedback` on `_response`. Both uniques widened to include it
  (`uq_caa_test_user_subject`, `car_question_user_subject_unique`) so a candidate and an employee
  sharing an id can never collide. Run on **both hosts, proved identical**.
- **`approve()` now refuses a non-employee subject.** It upserts `competency_kasba_rating` **twice**
  — on `(tenant, user_id, kasba_item_id)` *and* on `(tenant, user_id, kasba_type, item_id)` — and
  neither table has a foreign key. Without the guard a candidate id equal to a real employee's id
  would have written a competency rating onto that employee's record.
- **`feedReviewCycles()` refuses a non-employee too**, so a candidate's score can never land in
  someone's performance review cycle.
- **`ai_feedback` is now stored.** DeepSeek already returns one sentence of reasoning per marked
  answer and the code threw it away — nobody marked down could ever see why.

### A live bug fixed on the way

**`submit()` silently discarded every answer** for anyone whose job role didn't match the test.
`mayTake()` (which guards `start()`) has three branches — open / my role / **assigned to me** — and
`submit()` had only two. So an admin could assign a test, the person could sit it, press Submit,
and get **HTTP 200 with `status: 1`** while every answer was counted as `dropped`. Nothing errored.
The three branches now match.

---

## Part 3 — What is actually broken, found by deep tracing

These are new, and several are worse than dead buttons.

### HIGH — fabricated personal data shown as a real employee record

`talent-profile-view.tsx` renders `mockProfileData` and **is reachable in three clicks**:
Recruitment → any candidate → **View Full Profile** (`candidate-detail-panel.tsx:361`), which
replaces the whole screen (`recruitment-center.tsx:275-282`). `profileId` is accepted and never
used, so **every user sees the same invented person**: "Priya Sharma", with an **Aadhaar number, a
PAN, a PF number**, blood group, DOB, personal phone, salary-revision history and an "Aadhaar
Card.pdf" attachment — under a green **"Active Employee"** badge. A recruiter cannot tell it is
fake. There are also 9 dead links and 3 inert menu items on that screen.

This is finding **F-23**, and it is more serious than "renders mock data".

### HIGH — the Offboarding clearance tracker always reads 0%

`OffboardingController::index()` does not include `clearance_tasks` in its projection (`:245-271`);
only `show()` does (`:358`). Executed:

```
GET /offboarding/cases      -> no clearance_tasks at all
GET /offboarding/cases/4    -> clearance_tasks: [8 items]
```

So the **Clearance Tracker tab shows 0% for every case forever**, even with all 8 items cleared, and
the four department columns render a green **"N/A"** that looks like a pass. The **Exit Interviews
list shows "Pending" and "Not Scheduled" for everyone** — including a case you just saved an
interview against. You save, see the success banner, return to the list, and it says it never
happened. Beside this sit two **hardcoded** bars: "Handover Progress 60%" and "Clearance Progress
30%", invented numbers next to real fields.

### HIGH — Mobility's department filter can never match

Options carry `hrms_departments.id` as the value; the controller compares it to the
`s_mobility_jobs.department` **varchar**. Executed:

```
/mobility/jobs                        total 1
/mobility/jobs?department=1868        total 0   <- what the UI sends
/mobility/jobs?department=Accountancy total 1   <- what the controller wants
```

**Every department option returns zero rows.** And `MobilityOverviewController::filters()` is **not
tenant-scoped for departments** — it returns **1,226 departments across all tenants** when tenant 6
owns 50. That is a cross-tenant name leak, and picking a foreign one makes Record Transfer 404.

Location, Grade and Job Type options are hardcoded lists that mostly cannot match the data
(`location` options are five Indian cities; the only value in the table is `surat`).

### HIGH — completing a transfer or promotion destroys the remarks, and can poison a job role

`update()` writes `'remarks' => $request->input('remarks')`, and the client never sends it on a
status change — so **Complete silently overwrites the justification with NULL**, in a column
rendered immediately left of the button. Separately, `to_jobrole` and `proposed_designation` are
**free-text inputs**, and a lookup miss falls back to writing the typed **name** into
`tbluser.allocated_standards`, which holds a **numeric id** in all 289 populated rows. One typo
corrupts the employee's job role for every join that reads it.

### MEDIUM — invented analytics presented as live

`mobility-center.tsx:1105-1199`: "Talent Movement Summary" shows **46 moves (20 transfers, 16
promotions, 10 lateral)** with a drawn donut, and "Succession Coverage" shows **22**. Reality:
`s_mobility_transfers` has **4** rows, `s_mobility_promotions` **0**, succession plans **0**. These
sit between two genuinely live cards.

### MEDIUM — the Recruitment bulk bar is entirely dead

All five controls — Move Stage, Assign Recruiter, Send Email, Add Tag, More — have no handler, so
the row checkboxes below them do nothing either. **Move Stage is a one-line wire** (`moveCandidate`
already works); the rest have no backend.

### The 17 dead buttons, now classified

Not a flat list — each has an answer: **WIRE 4** (Move Stage; Admin "View all roles" → the live
Permissions tab; Mobility sort-by; View Team), **BUILD 3** (Audit Logs, Create New, org chart),
**REMOVE 10** (redundant filter icons, the Table/Board/Timeline switchers where only one view
exists, Save View with no table behind it, Send Message and Request Update with no endpoint).

Also: **all four Administration KPI tiles link to the same tab** — "View audit logs" and "View all
templates" both land on Workflows.

### Verified GOOD, so you know what is solid

- **Performance calibration and the 9-box are correct.** Calibration writes real ratings in a
  transaction with an activity log; the 9-box is read-only **by design** and refuses to place
  unmeasured people. If someone says the 9-box "doesn't work" they mean they cannot drag — which is
  deliberate.
- **Recruitment, Performance and Offboarding filters are clean.** Options are served from real data
  or normalised to match. Only Mobility's are broken.
- **Exit interview save/round-trip works**; it is the *list* that cannot see it.
- Every frontend API call resolves to a registered route; all 14 module endpoints read 200 / write 422.

---

## What "100%" requires, as a list

1. Finish candidate assessment: `jobrole_id` on postings, a recruitment blueprint, a `coding`
   format, a magic-link taker, and results advisory on the Screening tab.
2. Fix Offboarding `index()` to return `clearance_tasks` — restores two whole tabs.
3. Fix Mobility's department filter and tenant-scope its filter endpoint.
4. Stop Complete from nulling remarks; make job role a picker, not free text.
5. Delete `talent-profile-view.tsx` and its fabricated PII, and point the button at the real
   employee profile that already exists.
6. Delete the invented Mobility analytics, or wire them to the real counts.
7. Resolve the 17 dead controls: 4 wire, 3 build, 10 remove.
8. Normalise the `if ($type == "API") { ...auth... }` shape in eight controllers.

Only when that list is empty is "100%" a claim worth making — and it should be made against the
list, not as an adjective.
