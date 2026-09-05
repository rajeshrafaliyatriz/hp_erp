# Sprint 5 — closing the handoffs

**All eight items done.**

**A correction to what this file said earlier**, before anything else. It claimed
`OffboardingController::store()` had given *every* exit case a null manager. That is wrong, and
measuring it is what showed why — see "Corrections" at the end.

---

## 1 · Performance review scoring now has a screen ✅

`PUT /api/performance/reviews/{id}` has had a full validator, a service method **and** a hook
(`use-performance.ts:767`) since the module was built, with **zero components calling any of it**. The
sidebar showed ratings read-only. The only path a rating had ever taken into the database was the
calibration grid — **6 of 235 live reviews** carry one.

Added a **Record ratings** editor to the Employee Overview sidebar: self, manager, overall and
potential, plus employee and manager comments. Collapsed by default, so the panel looks exactly as it
did until somebody chooses to rate. Errors render inside the panel above the fields; only changed
fields are sent, so pressing Save cannot overwrite a value somebody else edited while the panel was
open.

**No backend work.** Verified against the database, not the status code:

```
review 275   self 3.40 → 3.50, manager 3.20 → 4.00, comments updated   HTTP 200
manager_rating 99                                                      HTTP 422
tenant-3 token on a tenant-6 review                                    HTTP 404
```

**The inputs stop at 5 although the API accepts 0–10.** `ratingLabel()` clamps to bands 1–5, so a 7.4
would store happily and render "7.4 – Outstanding" (audit **F-62**, still open at the API). The screen
will not be the thing that creates one.

### A mistake worth recording

Restoring my test row, I set the ratings to NULL instead of to their original values — review 275
already had `self 3.40 / manager 3.20 / overall 3.20` and a manager comment, and I had truncated that
comment to 24 characters in my own output, so I could not retype it.

It was recovered from `s_performance_activity_log`, whose `changes` column records old **and** new per
field. The row is now byte-identical to its pre-test state, verified against that log. The lesson is
the one this project keeps teaching: **capture the whole before-state, not a display-truncated
version of it.**

---

## 2 · Probation termination now opens an exit case ✅

Terminating a probation set `confirmation_status`, cancelled the journey, and stopped. No exit case,
no clearance checklist, no exit interview, and not even `tbluser.terminated_date` — so the Lifecycle
Timeline for a terminated hire stayed blank forever and the person stayed on the books until somebody
noticed by hand.

`App\Services\Talent\OffboardingCaseFactory` is the one place an exit case is created from, and both
`OffboardingController::store()` and the probation decision now go through the same shape — the rule
that produced `EmployeeFactory` and `OfferAcceptanceService`.

The termination and the exit case commit in **one transaction**: a terminated employee with no exit
process is worse than a rolled-back termination.

```
POST /api/onboarding/probation/8/terminate
  -> "Probation terminated. An exit case has been opened in Offboarding."
     offboarding_case_id = 4

journey 8            pending / not-started  ->  terminated / cancelled
exit cases (tenant 6) 0 -> 1
case 4  employee 2577 · involuntary · Involuntary/Termination · Notice Period
        8 clearance tasks and 4 documents seeded
Offboarding Center   shows the case, EC-2026-00004
```

It targets the **v2** offboarding controller deliberately: the v1
`Api\Talent\OffboardingCaseController` writes `case_code`, `resignation_date`, `fnf_status`,
`owner_id` and `closed_at`, **none of which exist** on `talent_offboarding_cases`. Building against v1
would have produced an "Unknown column" that looked like a bug in the handoff.

### Two defects found on the way

**A journey started from an accepted offer had no employee attached.** `storeFromOffer` predates the
accept path — when an offer could only ever be "sent", there was no employee to point at. Since
Sprint 2 an accepted offer produces a `tbluser` row and `talent_offer_acceptances` records which one,
so the journey is now born linked. It matters beyond tidiness: probation confirmation mirrors onto
`tbluser` and a termination now opens an exit case, and **both are skipped when `employee_id` is
null**. A journey with no employee is a journey whose outcome goes nowhere.

**`OffboardingController::store()` reads `$employee->reportmanager`, which is not a column on
`tbluser`** — the real one is `reporting_manager_id`. It gets away with it because it selects the
whole row, so the undefined property reads as null and *every exit case it has ever created silently
has no manager*. Selecting columns explicitly turned that into a hard error, which is how it surfaced.
The new factory uses the right column; the existing controller still has the silent version and is
listed below.

---

---

## 3 · Mobility: a mislabelled button, and a feature no tenant could use ✅

The lead said `mobility-center.tsx:1303` writes `status='Offered'` and stops, and that the fix was
either to wire it up or relabel it. Re-verifying changed the answer twice.

**First, the file is `mobility-succession/mobility-center.tsx`, not `mobility/`.** The path in the
plan did not exist.

**Second, it is a label bug, not a missing feature.** There are two buttons that fire the identical
action. The one in the table is labelled **"Offer"** and sets the status to `Offered` — honest. The
icon button above it had `title="Hire"` and does exactly the same thing. Nothing was broken except
the word. It now says **Offer**, so the two agree.

**The real gap was what came next.** Both applicant lists show actions only while the status is
`Applied`, `Screening` or `Interviewing` — so the moment someone was offered the role they became a
dead end on screen, and moving them meant opening Record Lateral Transfer and retyping their name,
their current role and the target department. That is precisely the "retyping a name into a second
screen" this remediation exists to remove.

Offered applications now carry a **Draft transfer** action that opens the existing dialog pre-filled
from the application — no new dialog, no new endpoint, no restyle. It opens as `Pending`, so it
*proposes* the move for approval rather than making it.

### And a defect that made the whole feature unusable

Posting an internal job as tenant 6 returned **HTTP 500 with a stack trace**:

```
Duplicate entry 'INT-2026-001' for key 's_mobility_jobs_job_id_unique'
```

The job code is generated **per tenant** (`MobilityJobController:120`) and was constrained
**globally**. Tenant 3 held `INT-2026-001` on both hosts, so **every other tenant's first internal
posting of the year died on a duplicate key** — an uncaught exception, not a validation message.
Internal Job Postings worked for exactly one institute.

Migration `2026_09_03_160000` replaces the global unique with `(sub_institute_id, job_id)` — 408
bytes against live's 767 cap, matching how tenant-scoped identity is done everywhere else here
(`talent_candidates`' `(sub_institute_id, email_key)`). The generator now takes the sequence as a
**number**: `orderByDesc('job_id')` sorted lexically, so `INT-2026-999` outranked `INT-2026-1000`
and the thousandth posting of a year would have reused a number — silent before, a hard failure now
that the constraint works.

```
tenant 6 posts an internal job     ->  INT-2026-001   (was a 500)
employee 63 applies                ->  application 1
Offer                              ->  status Offered
Draft transfer pre-fills           user_id 63 (Milan Baldaniya) · to_department_id 87
                                    (Information Technology) · to_jobrole 'ZZ Probe Senior Analyst'
                                    · remarks "Internal move from INT-2026-001"
submit                             ->  transfer 49, status Pending, user 63
employee token on that route       ->  403      tenant-3 admin on transfer 49  ->  404
```

`from_jobrole` comes through empty because employee 63 has no designation on record. It is an
editable field, left for the user rather than invented.

---

## 4 · `journey.stage` could not hold `offer_accepted` ✅

The reproduction is sharper than the lead. The stage **filter dropdown is built from
`DEFAULT_STAGES`**, which has always included "Offer Accepted" — and selecting it could only ever
return nothing, because the validator and the stage map made that value unwritable. The label map
`onbStageLabel()` already knew `'offer_accepted' => 'Offer Accepted'`; only the validators, the map
and the TS union were left behind.

It is also reachable a second way, which I reproduced: reopen the Offer Accepted step on a journey
and the timeline's current step becomes `offer_accepted` while the badge still reads Preboarding.

No migration — the column is already `varchar(30)`.

### The part that would have broken if I had only done what the lead said

Widening the vocabulary puts journeys in a stage that the Onboarding overview counted in **no KPI
tile at all** — tile 1 counted `stage = 'preboarding'` exactly. Widening the count then exposed a
worse, pre-existing bug: **each tile's "View all" filter asked a different question from its own
count.**

```
Active Onboarding   counts  stage IN (first_day, orientation, team_integration, probation)
                            AND status IN (open)
                    opened  status = 'in-progress'
```

Measured with four seeded journeys: the tile read **1** and its View-all opened a list of **2
entirely different hires** — neither of them the one it had counted.

Both tiles now build their count and their filter from the same constant, so they cannot drift
apart again, and the journeys list accepts a comma-separated set for `stage` and `status`. Tiles 3
and 5 were checked and already agreed; tile 4 is a ratio with no drill-down.

```
stage=offer_accepted        0 journeys  ->  1     (the filter option that never worked)
Preboarding Pending   tile 3  list 3    ok
Active Onboarding     tile 1  list 1    ok        (was 1 vs 2 different rows)
first-day / probation-due   already agreed, unchanged
```

Four probe journeys were created and then **hard-deleted**; soft-deleting test rows would have left
permanent junk. The tables are byte-identical to the snapshot taken first.

---

## 5 · F-58: transactions in Api/Performance ✅ (priority set)

Confirmed absent by four separate greps, all empty, against **48** `DB::transaction` uses elsewhere
in `app/`. Every write method in the module is at minimum a two-table write — the entity plus
`s_performance_activity_log` — with no transaction.

**First, that these wrappers are real and not decoration.** All 11 `s_performance_*` tables are
InnoDB on **both** hosts, so a rollback actually rolls back. Proven on the real tables:

```
before      review 275.updated_at = '2026-09-03 12:40:46'   log rows = 161
inside txn  review.updated_at     = '2001-01-01 00:00:00'   log rows = 162   <- both writes visible
threw       deliberate failure, after both writes landed
after       review 275.updated_at = '2026-09-03 12:40:46'   log rows = 161
```

Nine methods wrapped, chosen because they can orphan rows rather than merely lose a log line:

| method | what half-application leaves behind |
|---|---|
| `PerformanceCycleController::launch()` | a cycle marked **active** with only some of its people in it — and the loop skips anyone who already has a review, so a re-run tops up the gap and hides that it ever half-failed |
| `Calibration::destroy()` | delete-without-detach leaves reviews pointing at a session that is gone, which no screen can show or clear — **unrecoverable through the UI** |
| `Calibration::store()` | a session whose `participant_count` disagrees with the reviews pointing at it; the grid reads one, the list reads the other |
| `Calibration::calibrate()` | some people on their calibrated rating and the rest on their pre-meeting one — and since the calibrated value overwrites `overall_rating`, the row cannot tell you which |
| `Calibration::lock()` | a one-way door half-open: reviews advanced but not locked reports "advanced 0" next time; locked but not advanced cannot be edited to fix |
| `Review::bulk()` | some reviews advanced, and the single log entry written after the loop says nothing happened |
| `Appraisal / Bonus / Compensation ::bulk()` | half an approved batch carrying an approver and a timestamp with no trail of who applied it. `mark_paid` stamps a payout date |

No `Mail::`, `event()`, `dispatch()` or `Http::` anywhere in the module, so there is no
side-effect-commits-but-DB-rolls-back hazard and the wrappers are unconditionally safe.

Tested against the database on tenant 6, all five calibration/cycle paths end to end:

```
launch(28,29)         2 reviews created, cycle updated
store()               session 12, 3 reviews attached, participant_count 3  (agrees)
calibrate()           updated 2, skipped [999999]      a bad id is reported, not an abort
destroy()             session deleted AND all 3 reviews detached together
bulk advance x2       both reviews self_review -> calibration
lock(force)           locked AND advanced 2 -> final_review, together
delete a locked one   refused, by design
```

Review 275 was attached and detached during this and **its ratings never changed**. Tenant 6 is
byte-identical to the snapshot afterwards.

**What I did not do, and why.** The remaining ~25 single-entity `store`/`update`/`decision`/`destroy`
methods still have no transaction. Their failure mode is a missing audit-log row, not an orphan —
real, but a different severity, and wrapping 25 methods mechanically for that is a large diff with
its own risk. I considered a transaction middleware over the route group and rejected it: these
controllers return 422 responses rather than throwing, so a wrapper would commit the failures.

---

## 6 · F-60: latin1 → utf8mb4 ✅ (both hosts)

Both hosts agreed exactly: 12 non-utf8mb4 columns, the only two `talent_*` tables of 24 still on
latin1. The damage is not cosmetic — measured by writing into the column:

```
'સરસ ઉમેદવાર'        stored as  3F 3F 3F 3F 3F 3F 3F ...   "???????"
'Great candidate 👍'  stored as  ... 20 3F                   "Great candidate ?"
```

The characters are **destroyed at write time**, not mangled. Six of the twelve columns are the
free-text interview feedback fields, in an ERP sold in Gujarat.

**The check that decided the method.** `CONVERT TO CHARACTER SET` is the wrong tool when a latin1
column already holds bytes that are really UTF-8, because it re-encodes them again, silently and
permanently. Scanned all ten character columns on both hosts: **1 row** with any high byte, **0**
already valid UTF-8. Safe.

**And a correction to the salvaged analysis.** That one row —
`talent_evaluation_form.notes` id=31 — holds a single `0x97`, which was read as ISO-8859-1 (an
invisible control character, so the em-dash would have been lost). But MySQL's `latin1` is **cp1252**,
not ISO-8859-1. Asked each server directly rather than assuming:

```
SELECT HEX(CONVERT(_latin1 0x97 USING utf8mb4))  ->  E28094  on both hosts
```

U+2014 EM DASH. Lossless, and it now reads as its author meant:

```
before   ...high potential 97 recommend hire.
after    ...high potential — recommend hire.
```

```
non-utf8mb4 columns remaining   0 / 0        rows 124 + 33, unchanged on both
columns 26, indexes 3, structure across hosts IDENTICAL
round-trip probe  'સરસ ઉમેદવાર 👍 — "curly"'  EXACT   (written, verified, rolled back)
```

Only one index sits on a character column — `panel_name varchar(50)` = 200 bytes against live's
767-byte cap under `ROW_FORMAT=Compact` — which is why the rebuild was safe.

---

## 7 · The missing index ✅ (both hosts)

No index contained `sub_institute_id` on either host, so every tenant-scoped query was a full table
scan. `(sub_institute_id, status)`, named `tapp_tenant_status_idx` explicitly.

Composite rather than the bare column because the distribution is lopsided: tenant 7 holds 150 of
281 rows (53.4%), so a tenant-only index still visits half the table for the busiest tenant. Adding
`status` takes the worst case to 58 rows (20.6%), and `WHERE sub_institute_id = ? AND status = ?`
appears five times in `EmployeeDirectoryAnalyticsController` alone. Leading with the tenant keeps the
tenant-only queries served by the leftmost prefix, so one index covers both shapes.

**I was wrong about the effect and the measurement corrected me.** I expected the optimiser to keep
table-scanning at 281 rows. It does not — identical on both hosts:

```
                   before                        after
tenant only        ALL  key=NULL  rows=281  ->   ref  rows=150  Using index   (never touches the table)
tenant + status    ALL  key=NULL  rows=281  ->   ref  rows=58    Using index condition
tenant + newest    ALL  key=NULL  rows=281  ->   ref  rows=150   filesort remains
```

The migration comment has been corrected to say this rather than my prediction.

**Not fixed, deliberately:** the recruitment list orders by `applied_date`
(`talent_jobapplicationcontroller.php:591`) and still filesorts. That needs a second index, which on
a 281-row table costs more in write amplification than it returns. Worth revisiting an order of
magnitude later.

`status` is `varchar(30)` utf8mb4 — 8 + 120 bytes, and the index name is 22 characters. Both well
inside live's limits.

---

---

## 8 · F-59: the two empty tables, kept and made real ✅

The proposal in this file was to **drop** `talent_resume_screenings` and `talent_team_members`. You
decided to keep them, add the tenant column and seed tenant 6 so there is something to demo. That
was the better call, and verifying it corrected me twice.

### Correction: "no code path" was wrong for one of them

I wrote that both had "0 rows and no code path on either host". True of `talent_resume_screenings` —
no model, no migration, no controller, no route, nothing in the frontend, and its only mentions
anywhere were three of my own audit documents.

**`talent_team_members` is not dead.** It is a registered entry in the department merge/delete
engine:

```
app/Services/Org/DepartmentMergeService.php:89   'talent_team_members' => 'team members'
app/Console/Commands/DepartmentsDedupe.php:83    'talent_team_members'
```

`impact()` counts it, `merge()` repoints its `department_id`, `release()` NULLs it on delete, and
three live routes reach it. `department-delete-merge-dialog.tsx:170` renders the breakdown — nobody
had ever seen the line because `.filter(b => b.count > 0)` hides an empty table. **Dropping it would
have removed a table the product was still reading.**

### Correction: neither table has ever had a migration

They existed only on the two databases. `2026_09_03_170000` is also the act of adopting them into
version control, which is why it is written defensively.

### What was built

**Schema** (`2026_09_03_170000`, both hosts, proved identical):
`sub_institute_id BIGINT UNSIGNED NOT NULL` on both — F-59 closed. `deleted_at` on both, because
every sibling `talent_*` table has it and its absence is precisely what the audit criticised about
`s_mobility_talent_pool_members` hard-deleting with no trail. `talent_team_members.role` **ENUM →
VARCHAR(30)**, the house rule, with `HiringTeamController::ROLES` now the vocabulary. Index byte
maths: `ttm_tenant_role_idx` 128 bytes / 19 chars, `trs_tenant_application_idx` 16 bytes / 26 chars,
against a 767-byte and 64-character cap. The existing FK survived.

**Backend** — `HiringTeamController` and `ResumeScreeningController`. Tenant from the token, never
the request; the tenant predicate inside the id lookup so a foreign row is a 404 not a 403; the
subject validated against the caller's tenant before any write (the Sprint 1b lesson); writes in
transactions (F-58). Reads gated `admin,hr,recruiter`, roster writes `admin,hr`. 8 new routes,
1856 → **1864**.

**Frontend** — the Administration **Permissions** tab, which rendered "Module Under Construction"
alongside four others, is now the hiring team roster: role counts, search and filter, activate and
remove, and an add dialog whose picker can only offer valid people because the endpoint serves the
assignable list with the roster. The four other tabs still say they are unbuilt, because they are.
And the candidate panel's **Screening** tab gains a *Recruiter resume review* block under the AI
analysis — score with a band, keywords as chips, comments, and who signed it when.

**The third surface cost nothing.** Seeded rows carrying `department_id` made the department
delete/merge dialog print a line it had never printed in the product's history.

### The two screening tables are not duplicates

`talent_screening_results` (285 rows, live) keys on `candidate_id` and holds the **AI verdict** —
competency match, cultural fit, DeepSeek's analysis. `talent_resume_screenings` keys on
`application_id` and holds **a person's review of one application**. One is what the model computed;
the other is what a recruiter decided and is the part a hiring decision can be made to point at.
They sit beside each other in the same tab on purpose.

### Seeded through the controllers, not into the tables

`TalentHiringTeamDemoSeeder` follows `Tenant6DemoSeeder`'s rule — a real Sanctum token for tenant
6's administrator, every write through the controller a click would reach, refusals reported rather
than worked around. It never supplies a tenant id. Seeding this way doubles as proof the endpoints
work.

It is a separate class because `Tenant6DemoSeeder` also seeds the competency model and those writes
are not safely repeatable; re-running it to reach two new sections risked duplicating competency
rows. That trade-off is written in the file.

**Departments are resolved by name, per host** — the two databases number tenant 6's departments
differently (app 87…1860, live 117…2198) and live has no "Information Technology" or "Support" at
all. A seeder holding literal ids would have put people in the wrong department on one host. Live
reported both misses and fell back to each person's own department, so **no row landed without one**.

```
APP   6 added, 0 refused, 0 skipped   8 screenings recorded
LIVE  6 added, 0 refused, 0 skipped   8 screenings recorded   (2 departments resolved by fallback)
second run, both hosts: "already present, leaving them alone"  -> still 6 and 8
```

### Probed against the database, not the status code

```
role = "Banana"                       422, no row written
user_id from tenant 3                 404, no row written
department from another tenant        404, no row written
same person added twice               422, one row
employee token read / write           403 / 403
tenant-3 admin updating a t6 row      404 (not 403)
screening on a tenant-3 application   404
ai_score 150                          422
payload said reviewed_by: 99999       row records 28 — the token's owner
```

That last one is the point of the field: a sign-off recorded under somebody else's name would be
worth nothing exactly when it mattered.

---

## Corrections to this document

**`OffboardingController::store()` has *not* given every exit case a null manager.** I wrote that,
and it is wrong. `store()` takes `manager_id` from the request (validator `:389`) and
`$employee->reportmanager` was only the **fallback** (`:437`). The three existing cases all have a
manager, because the request supplied one.

What is true is narrower, and measured on both hosts:

```
exit cases                 APP 4 · LIVE 3
with a null manager_id     1  — case 4, the one I created via the new factory
tbluser.reporting_manager_id populated on   8 of 2372 (APP)   0 of 299 (LIVE)
null-manager cases whose employee has a reporting manager to recover:  0
```

So the fallback has never once fired — and **correcting it changes nothing today**, because the
column it should read is empty for almost everybody. It is a latent bug now closed
(`reporting_manager_id`, with the whole-row select that hid it), **not a data repair. There is
nothing to back-fill.** `talent_offers.reportmanager` does exist, which is where the copy-paste came
from.

## Gates

```
tsc 7 (baseline)   build exit 0, seen   eslint 101 (baseline)   components/ui empty
routes 1856 -> 1864 (8 new, item 8's two controllers)
migrations 2026_09_03_140000 / _150000 / _160000 / _170000: present and identical on BOTH hosts
```

eslint went to 104 on the first pass — three `react-hooks/set-state-in-effect` errors in the two new
components, the same rule that caught Sprint 4a. Fixed properly rather than suppressed: the effects
now set state only after an `await`, and the add dialog resets by remounting on a key instead of
clearing its fields in an effect. Back to 101, none from the new files.

## Anything this sprint left in a shared database

Every probe row was removed and each affected table proved byte-identical to a snapshot taken
beforehand — tenant-6 journeys, tenant-6 `s_performance_*`, and the mobility job / application /
transfer.

What is intentionally left, on **both** hosts:

- **Four migrations** — `_140000` utf8mb4, `_150000` the index, `_160000` the mobility unique,
  `_170000` the tenant columns.
- **`talent_evaluation_form.notes` id=31** gained a real em-dash, from the charset conversion.
- **Item 8's demo data for tenant 6** — 6 hiring team members and 8 resume screenings, written
  through the controllers. This is the demo and it stays; re-running the seeder is a no-op.

## Test data on tenant 6

- Journey **8** created from offer 29, then terminated — it and exit case **4** are the demo.
- Review 275 restored to its exact pre-test state, twice over.

## How to demo

**Ratings:** Performance → pick an employee → **Record ratings** in the sidebar. The tiles above it
change, and the value survives a refresh — it is in the database, not component state.

**Termination:** Onboarding → Probation & Confirmation → terminate a hire. Open Offboarding: the exit
case is there, with the clearance checklist already seeded. Nobody re-entered the person.

**Internal move:** Mobility → Internal Job Postings → post one (this now works for your tenant at
all) → an employee applies → **Offer** → **Draft transfer**. The transfer dialog opens with the
person, the target department and the role already filled in.

**The KPI tiles:** Onboarding → click **View all** on Preboarding Pending or Active Onboarding. The
number on the card and the length of the list now match.

**Gujarati feedback:** Recruitment → interview feedback → type in Gujarati or paste an emoji. It
comes back as it was typed instead of as question marks.

**The hiring team:** Talent → Administration → **Permissions**. The tab used to say "Module Under
Construction"; it is now the roster, with six people across the three roles. Add someone — the
picker only offers people not already on it.

**The recruiter's review:** Recruitment → open a candidate → **Screening**. Under the AI analysis
there is now a scored review with keywords, comments and the reviewer's name. Try candidates on
applications 437 (91%, strong) and 440 (34%, weak) to see both ends.

**The line nobody built:** Organization → Departments → delete or merge the department those people
are in. The confirmation now says **"N team members"** — that count comes from this table and had
never once been displayed before it had rows.
