# Talent Management — deep re-check

Two questions: **where does a candidate sit an assessment**, and **is every screen, form and
component actually working**. Both answered by tracing and executing, not by reading labels.

A note on method: I launched a seven-agent parallel sweep for this and it died immediately on an
account session limit, so this was done solo. It is therefore narrower than a full per-control
sweep — what is covered is stated, and what is not is listed at the end rather than implied.

---

## 1 · Where does a candidate give an assessment? — **nowhere. It does not exist.**

### The pipeline stage is a label

The recruitment kanban has an **Assessment** column. Sprint 3 made that status actually writable
(it used to be a silent no-op). But moving a candidate there writes the string `Assessment` to
`talent_job_applications.status` and **nothing reads it**:

```
grep -rn "'Assessment'" --include=*.php app/   ->  only the STATUSES const and its own comments
```

No workflow, no notification, no test assignment. The column is a place to park someone.

### There is no candidate-facing assessment surface at all

```
routes/api.php  /candidate.*assessment/   0 hits
                /assessment.*candidate/   0 hits
                /public.*assessment/      0 hits
                /careers.*assessment/     0 hits
frontend candidate pages: app/careers/[slug], app/offer/[token]   — and nothing else
```

A candidate can find a job, apply, and answer an offer. There is no third thing they can do.

### The engine that exists is for employees, and cannot take a candidate as it stands

The machinery is real and reachable: `competency_assessment_test / _question / _attempt /
_response / _rating_proposal`, driven by `Api\Competency\AiAssessmentController`
(`/competency/ai-assessment/mine`, `/start`, `/submit`, `/attempts/{id}/mark`) and scored by
`App\Services\Competency\AssessmentScoringService`.

Four concrete blockers, each measured:

| # | Blocker | Evidence |
|---|---|---|
| 1 | **No foreign key on the taker** | `competency_assessment_attempt.user_id bigint` — `FKs: NONE`. The database will accept any integer as a taker. |
| 2 | **No subject discriminator** | The attempt table has no `subject_type` / `candidate_id`. Nothing distinguishes a candidate from an employee. |
| 3 | **The test is chosen by job role** | `mine()` reads `tbluser.jobtitle_id` / `allocated_standards` and returns an error if there is no job role (`AiAssessmentController` ~:508-517). A candidate has neither. |
| 4 | **Approving a result rates a person by that same id** | `AssessmentScoringService::approve()` upserts `competency_kasba_rating` on `['sub_institute_id','user_id','kasba_item_id']`. Combined with (1) and (2), a synthetic candidate id equal to a real employee id would **write a competency rating onto that real employee.** |

That last one is why this was deferred rather than bolted on, and re-checking confirms the
reasoning was right.

### What building it would actually take

Not a fix — a feature:

1. A `subject_type` discriminator on the attempt/response tables (or a separate candidate-attempt
   table), so a candidate can never collide with an employee id.
2. A test-selection rule that does not depend on a job role — per posting, or per application.
3. A taker surface a candidate can reach with no account: the same signed, expiring, single-use
   magic-link pattern the offer response already uses (`OfferLinkService` is the model to copy).
4. A gate on `approve()` so a candidate result can never reach `competency_kasba_rating`.

Until then, **the honest description of the Assessment column is "a stage marker", not "an
assessment"** — and it should probably say so on screen.

---

## 2 · Are the screens, forms and components working?

### Wiring: clean

**Every frontend API call resolves to a registered route.** Extracted every `apiClient.*` /
`buildApiUrl` path from the talent services and matched each against `route:list` (1308 routes),
allowing for `{param}` segments:

```
frontend API calls checked: 45      calls with NO matching route: 0
```

The phantom-endpoint class the original audit found — `/candidates`, `/onboarding-tasks`,
`/performance-reviews`, none of which existed in Laravel — is **gone**, deleted with the dead
`talentService` block in Sprint 6.

### Every module's read and write endpoint answers correctly

A read should be 200; a write with an empty body should be **422** (the validator fires), not 404
(no route) or 500 (broken):

```
onboarding journeys 200/422      performance cycles      200/422    mobility transfers  200/422
onboarding tasks    200/422      performance goals       200/422    mobility promotions 200/422
offboarding cases   200/422      performance appraisals  200/422    mobility succession 200/422
admin hiring team   200/422      performance bonus       200/422    mobility pools      200/422
                                 performance compensation 200/422
                                 performance calibration  200/422
```

14 of 14. (My first pass reported `performance/bonus` as 404 — that was my wrong path,
`bonus-awards` instead of `bonus`. The product is fine.)

Together with the live lifecycle run in the previous pass — apply → screen → offer → accept →
employee → onboard → confirm → terminate → exit case → cycle → rating, all executed against tenant
6 on the live host — the main flows are demonstrably working end to end.

### Mock data: exactly one component, and it is the known one

```
components/domain/talent/profile/talent-profile-view.tsx:40
    import { mockProfileData } from './talent-profile-data'
```

**That is the only one.** No other Talent component imports fixture data. This is the known finding
**F-23**, and the re-check confirms its scope is one screen, not a pattern.

### Dead controls: 17 buttons that do nothing when clicked

Of 282 `<Button>` elements across the Talent screens, 23 have no handler; six of those are
page-number indicators or a regex artifact. The remaining **17 are real: a visible, labelled
control that does nothing when clicked.**

| Screen | Dead controls |
|---|---|
| Administration (`admin-center.tsx`) | **Audit Logs** (:155), **Create New** (:158), **More Actions** (:440), two icon buttons (:260 filter, :331) |
| Mobility (`mobility-center.tsx`) | **More Filters** (:941), **Save View** (:944), the **Table / Board / Timeline** view switchers (:975/:978/:981), one icon (:993) |
| Recruitment (`recruitment-center.tsx`) | **More Filters** (:446), two icon buttons (:544, :723) |
| Candidate panel | one icon button (:164) |
| Talent Profile (`talent-profile-view.tsx`) | **View Organizational Chart** (:287), **View Team** (:291), **Send Message** (:295), **Request Update** (:299) — this is the F-23 mock screen |

The Mobility **Table / Board / Timeline** switchers are the most misleading of these: three
view-mode tabs that imply the data can be seen three ways, and none of them switch anything.

### Authentication: Talent is properly protected

Probed every main Talent endpoint with **no token at all**:

```
job-applications 401   interview-panel/list 401   interview-schedules 401
feedback 401           talent-offers 405 (method)  job-postings 500 (see below)
```

No anonymous data leak. This matters because 269 Talent routes carry no route-level middleware —
which, as the original audit established, is **not** the same as being unauthenticated. Probing
confirms the controllers check the token themselves.

### One genuinely open endpoint, outside Talent — a bypass shape worth knowing

`GET /api/ai-generated-assessment/question/index` returns **HTTP 200 with no token**, because its
auth check is wrapped in a conditional:

```php
$type = $request->type;
if ($type == "API") {            // <- omit type=API and authentication is skipped entirely
    ... token checks, 401 ...
}
$subInstituteId = $this->apiTenantId($request);
$questions = QuestionMaster::with('answers')->where('sub_institute_id', $subInstituteId)->get();
```

**Impact today: none.** The anonymous caller's tenant resolves to null, so the query matches
nothing, and the table is empty. But the shape is an auth bypass, and eight controllers use
`if ($type == "API") { ...auth... }` rather than the safe early-return
`if ($type !== "API") return 400;` that `TalentOfferController` and
`talent_screening_results_controller` use. In Talent it is not exploitable — route middleware and
the controllers' own checks catch it — but the pattern should be normalised to the early return.

Also minor: `GET /api/job-postings` without `type=API` returns **500 "View [talent.index] not
found"** — it falls into a web branch looking for a Blade view. No trace, no data disclosed; it
should be a 400.

---

## What this re-check did NOT cover

Stated so the absence is not mistaken for a pass. The parallel sweep that would have covered these
died on a session limit:

- Per-control tracing inside each screen's **dialogs and drawers** — I verified the endpoints they
  submit to exist and validate, not every field of every form.
- The **offboarding clearance tracker** and **exit interview questionnaire** internals: whether the
  JSON written by one screen is read correctly by another.
- **Calibration's 9-box grid** rendering, and the two incompatible 9-box scales (known F-63).
- Whether every **filter dropdown option** returns rows (one such bug was found and fixed in
  Onboarding this sprint; the other modules' filters are unchecked).
- **Mobility transfer/promotion completion** writes into `tbluser` / `org_designation` — gated and
  transactional as of Sprint 1b, but the resulting data shape was not re-verified here.
