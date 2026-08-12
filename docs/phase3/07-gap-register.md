# 07 — Gap register

Every gap found in Phase 3, with a stable ID, severity, owning module and the work
it implies. Started early (normally a Gate C artefact) at Triz's request.

**Read the METHODOLOGICAL RESULT first, then `G-DATA-06`.** The first says what
counts as evidence in this register; the second is the finding that explains the
others.

**`G-DATA-06`** It is the finding that explains the others: the
product's load-bearing relationships are joined by name, not by key. The security
findings that follow are breaches to be closed; **G-DATA-06 is why the modules do
not connect in the first place.**

Severity: **S1** blocks a sale or breaks data integrity · **S2** breaks a core
workflow · **S3** degrades the product · **S4** cosmetic.

| Status | Meaning |
|---|---|
| `OPEN` | Confirmed, not addressed |
| `PARTIAL` | Partly addressed in an earlier phase |
| `DESIGNED` | Fix specified in a Gate B/D document, not built |

---

---

---

---

---

---

---

# G-PLAN-01 - **SEVEN OF THE REMAINING ITEMS ARE CATALOGUES NOBODY HAS WRITTEN** - **S2**

Counted, not estimated. **These are not connections to build. They are relationships
that must be AUTHORED, and no amount of code produces them:**

| Item | Target | State |
|---|---|---:|
| **L-14** | `jobrole_task_competency_map` | **0 rows** |
| **TL-03** | `talent_requisitions` | **TABLE DOES NOT EXIST** |
| **TL-02(b)** | a competency on a performance goal | **no such column** |
| **L-09** | `certification_type` | **0 rows** |
| **TL-04** | `talent_onboarding_tasks` | **1 row** |
| **X-14** | `talent_onboarding_journeys` | **1 row** |
| **X-08** | the seed-library import | **IS the authoring mechanism** |

**7 of the remaining 33.**

> ### THE PLAN'S REMAINING SIZE IS SMALLER THAN IT LOOKS IN BUILD TERMS AND
> ### LARGER IN ONBOARDING TERMS.
>
> Seven items cannot be finished by writing code. They are finished when a customer
> - or a seed library - **authors the relationship**. Counting them as build work
> has been overstating what is left to build and understating what is left to
> populate.

**X-08 is the lever.** It is the only one of the seven that is itself a mechanism:
the seed-library import is how the other six get their content. **Its five
requirements are already recorded under G-SEED-01.**

**Not in this count** because they are populated: `course_competency_map` (56),
`course_jobrole_map` (71), `jobrole_competency_map` (23) - all authored by this
phase's own work, which is the caveat that travels with Q-E1-Q1.

---

# G-PLAN-02 - **L-01 AND L-02's TRIGGER FIRED AND NOBODY NOTICED** - unblocked

Both were scheduled on *"`department_id` populated"*. **It is populated:**

| | |
|---|---:|
| `s_user_jobrole.department_id` | **4,513 of 4,736 (95.3%)** |
| `s_users_skills.department_id` | **3,924 of 5,171 (75.9%)** |

**They are unblocked and have been for some time.**

**This is the 9-box correction's shape a second time in one turn:** a condition was
recorded, the condition was met, and **nothing re-read the schedule.** A trigger
nobody re-checks is a park with extra steps.

**Practice:** every scheduled item's trigger is re-measured when its area is next
opened - not when someone remembers.

### APPLIED IMMEDIATELY - EVERY SCHEDULED TRIGGER, RE-MEASURED

| Trigger | Measured | Fired? |
|---|---:|---|
| `department_id` populated | jobrole **95.3%**, skill **75.9%** | **FIRED** - L-01, L-02 |
| `course_competency_map` populated | **56 rows** | **FIRED - X-13 unblocked, and nobody noticed** |
| X-11 ships | `certification.issued` wired | **FIRED** - already actioned |
| reporting-line coverage | **8 of 401 (2.0%)** | partial - not enough for `capability.flag_raised` |
| capability coverage | **5 users** with any rating | partial |
| `task_hygiene` | **2,088 of 2,271 overdue (91.9%)** | **PROBABLY NEVER.** Not drifting toward firing; reclassified DEFERRED_INDEFINITELY in EventCatalogue |
| `certification_type` populated | **0 rows** | **NOT A TRIGGER - AN AUTHORING DEPENDENCY.** It fires only when someone authors certification types. Moved to G-PLAN-01 |

**Three of seven have fired. Two were known; `course_competency_map` was not.**

> **The sweep cost one query and unblocked a third item.** G-PLAN-02 predicted the
> answer would not be zero - it was three. **A scheduled item is only scheduled if
> something re-reads the condition.**

---

# G-TASK-03 - **`delay_category` IS EMPTY BECAUSE THE STATE IS UNREACHED** - **S3** - T-02 re-scoped

**T-02 was "surface `delay_category`". It should not be surfaced.**

| | |
|---|---:|
| tasks | **2,271** |
| with `delay_category` | **0 (0.0%)** |
| with `delay_reason` | 1 |

**Status across the whole system:**

```
PENDING 2,087 | NULL 157 | COMPLETED 22 | IN-PROGRESS 4 | ON HOLD 1
```

**ONE task has ever been ON HOLD.** `delay_category` is written only when a task
goes on hold, so **an empty column is the correct consequence of a workflow nobody
uses** - not a defect in the field.

> **Surfacing it would add a column rendering NULL on 2,271 rows and make the
> screen worse.** The finding is the deliverable: **4 in-progress and 1 on-hold
> across 2,271 tasks means the workflow is not being used.**

### TWO INDEPENDENT MEASUREMENTS OF ONE CONDITION

**The `task_hygiene` readiness gate already defers `task.overdue`** on the grounds
that it would fire 2,245 times and be noise before it was useful. That was measured
from the OVERDUE side.

**This is the same truth measured from the STATE side:** almost nothing ever leaves
PENDING, so almost nothing is ever on hold, so `delay_category` is never written.

> **Two independent measurements of one condition is stronger than either alone** -
> they were taken months apart, from different tables, for different items, and
> they agree. **The workflow is not being used**, and both the overdue flood and
> the empty delay field are consequences of that single fact.

**This finding belongs beside the `task_hygiene` gate**, not in a UI item.

**That is a product-adoption fact, not a wiring gap**, and it belongs beside the
task-hygiene readiness gate rather than in a UI item. **T-02 removed from the
screen-work tracker.**

---

---

---

---

# G-SEC-26 - **FOUR INDEPENDENT IMPLEMENTATIONS OF IDENTITY RESOLUTION** - **S2**

Found by widening the identity assertion after a near-miss it would not have
caught. **Not the defect I was hunting, and structurally more important.**

| Implementation | Behaviour on a mismatched tenant |
|---|---|
| `ResolvesApiIdentity::resolveApiIdentity` (the shared trait) | **silently ignores** the request value |
| `DependencyController::context` | falls back to request only when the user has none |
| `MyTasksController::context` | same |
| `ProjectController::context` | same |
| `ExcelAutomationAgentController::resolveSubInstituteId` | **THROWS** on a mismatch |

**Four places to fix the next identity defect, and G-SEC-12's sweep touched one.**

> ### THIS EXPLAINS WHY "SCOPED BY SHAPE, NOT BY DEFECT" KEEPS RECURRING.
> **A sweep that fixes one implementation leaves the others to drift
> independently.** The recurrence is not carelessness; it is what happens when a
> property has four homes.

### ⚠ THE TWIST, AND IT MUST BE READ BEFORE ANYONE TIDIES THIS

**The copies are not uniformly worse. One is BETTER than the thing it duplicates.**
`ExcelAutomationAgentController` **refuses** a mismatched tenant with an exception;
the shared trait **ignores** it. Its super-admin branch is gated on `is_admin === 1`
**and** a null/zero own tenant - **measured: 0 of 2 admin accounts qualify**, so
the branch is unreachable with current data.

**So consolidation is a REAL DECISION - which behaviour wins? - not obvious
cleanup.** Collapsing four resolvers into the weakest one would be a regression
performed as tidying.

**NOT CONSOLIDATED. That is its own item**, and it needs the behaviour decision
first.

### THE ASSERTION GAP THIS EXPOSES

The suite tests a property of code **shape**. **There is no assertion that identity
resolution happens in ONE PLACE** - which is the property that would actually
prevent recurrence. **Add it when the seven are closed**, same single-writer shape
as `TaskStatusWriter` and the reporting-line assertion.

---

# ⚠ G-SEC-27 - **CROSS-TENANT WRITE** - **S1** - ✅ **CONFIRMED AND FIXED 2026-08-11**

> ## A READ EXPOSES. THIS PLANTED DATA THAT LOOKED NATIVE.
>
> **Nothing in the victim organisation would ever show that the row came from
> outside.** Worse than every read leak in G-SEC-11, because a read is discovered
> when someone notices data they should not see - and this leaves a job role
> sitting in a customer's library, indistinguishable from their own, forever.

**PROVEN END TO END**, `docs/phase3/_evidence/g-sec-27-probe.php`:

```
attacker: user 6, tenant 3        victim: tenant 1
HTTP 201  "JD data saved successfully."
s_user_jobrole: +1 row IN TENANT 1, plus a library_map row
```

### THE FIX, AND WHY IT IS NOT VALIDATION

```php
// BEFORE - request body first, identity last
$payload['sub_institute_id'] = $payload['sub_institute_id']
    ?? $request->header('sub_institute_id')
    ?? $request->session()->get('sub_institute_id');

// AFTER
$payload['sub_institute_id'] = $this->tenantFromIdentity($request);
```

> ### A VALUE THAT MUST EQUAL THE IDENTITY'S IS A VALUE WITH NO REASON TO BE SENT.
> ### NOT VALIDATED - NOT READ.

`tenantFromIdentity()` reads the token, then the session, and returns **NULL**
otherwise; the existing `required|integer` rule then refuses. **Failing closed is
the point: a JD written into an unknown organisation is the defect being
replaced**, and no fallback improves on a refusal.

**VERIFIED BY THE PROBE THAT FOUND IT** - same attacker, same victim: HTTP 201, a
row created **in tenant 3, the caller's own**, and **zero** in the victim tenant.
The request still reaches the write, so the negative result means something.

**SPREAD CLOSED:** `AnalyzeJDController` and `GenerateQuestionsController` both
clean. The inverted convention was confined to one file.

---

# ⭐ PROBE DISCIPLINE - **TWO PROPERTIES, TWO WAYS TO BE SILENTLY WRONG**

**One probe carried both defects, and both were found by RUNNING it, neither by
reading it.**

### 1. IT MUST BE ABLE TO REACH THE CODE UNDER TEST

The first run returned **HTTP 422** on `department` and `industry` - fields with
nothing to do with tenancy - and printed **"NOT confirmed as a write leak."**
**A probe rejected before the code under test, with a verdict written as though it
had arrived.** One more field inverted the answer.

> **A 4xx before the code under test is NOT A RESULT. It is a broken probe, and no
> verdict line may be printed on it.**

**Fourth instance this phase** of a negative from an instrument that never reached
its target. **R16 applies to probes exactly as to sweeps.**

### 2. ITS CHECK MUST DISTINGUISH THE TWO OUTCOMES IT IS TESTING

**Worse than the first.** The marker search was not tenant-scoped, so after the fix
it still reported a hit - the row in tenant 3, **where it now correctly lands**.

> **A check that cannot tell "written to the victim" from "written to yourself" is
> unable to answer the only question the probe exists to ask.** It would have
> reported a leak forever against a working fix.

**These are SEPARATE properties.** Reaching the code says nothing about whether the
check can read the answer. **Both must be demonstrated before a probe's verdict is
evidence.**

**OPEN:** does any other negative result in this register come from a probe that
never reached its target, or whose check could not discriminate? **One pass when
the queue has room.**

---



**The seventh of the seven, and the only one that is real.**

```php
$payload['sub_institute_id'] = $payload['sub_institute_id']        // REQUEST BODY FIRST
    ?? $request->header('sub_institute_id')
    ?? $request->session()->get('sub_institute_id');               // identity LAST
```

**Every other helper in this codebase puts identity first. This one puts it last.**

| | |
|---|---|
| route | `POST /api/gemini/save-jd`, middleware `api.token` - **authenticated** |
| validation | `'sub_institute_id' => 'required|integer'` - **an integer, never the caller's own** |
| use | line 61 casts it; lines 75, 120, 153 **write it into three tables** |

**This is C27's class with the precedence inverted.**

> ### A READ EXPOSES. A WRITE CORRUPTS ANOTHER TENANT'S DATA WITH SOMETHING THAT
> ### LOOKS NATIVE TO IT.
>
> Worse than the read leaks G-SEC-11 catalogues, and it jumps the queue by the
> standing rule **if the probe confirms it**.

**NOT CONFIRMED FROM A READ.** A live probe is needed - a token from one tenant
writing to another, verified end to end, rows cleaned up. **Asserting a leak from
source has bitten three times this phase.**

---

# G-SEC-28 - **FIVE REQUEST-TENANT FALLBACKS COMPENSATE FOR A CONDITION THAT DOES NOT OCCUR** - **S3, cleanup item**

**Measured: `tbluser` holds 401 rows. NOT ONE has a NULL or zero
`sub_institute_id`. 0 of 401.**

Every cleared helper reaches the request only when identity supplies nothing - and
identity always supplies something. **They are dead code by measurement.**

> ### THE SHAPE THE ASSERTION FLAGS IS LEGITIMATE ONLY IN A WORLD THAT NO LONGER
> ### EXISTS.
>
> That is the justification for keeping the check **unnarrowed**. Narrowing it to
> fit five legitimate cases would tune it to a world that has already gone.

**THE CLEANUP, AS ITS OWN ITEM - NOT NOW:**

Remove the five fallbacks, and `ExcelAutomationAgentController`'s super-admin
branch, which is the same shape (**0 of 2 admin accounts qualify**). The assertion
then becomes a real guard instead of 5-for-5 wrong.

> **PRECONDITION, AND IT MUST LAND WITH THE CLEANUP:** this is true only while the
> column stays clean. **The cleanup ships with an assertion that no account has a
> null or zero tenant** - otherwise we delete a compensation and reintroduce the
> condition later, with nothing to catch it.

---

## S-04 SCOPED BEFORE READING - **93% is actionable, and the list is stale**

Checked against the 51 **before** reading a single controller, because finding out
afterwards has cost a turn three times.

    FAIL rows in the C23 sweep        46   across 25 distinct controllers
    BLOCKED by the 51                  3   routes / 3 controllers
    ACTIONABLE                        43   routes / 22 controllers

    blocked: CompetencyDashboardController, EmployeeDirectoryAnalyticsController,
             HrmsLeaveController

### THIS CORRECTS THE REGISTER'S OWN BLOCK TABLE

G-BLOCK-01's cost table records **S-04 as spanning `Api/` x 23 and `talent/` x 5**,
which reads as most of it being blocked. **It is 3 of 46.** The block table was
describing the span of the 51 itself, not the overlap with S-04's candidates - and
those are different numbers.

**Two documents disagreed and the measurement settled it**: batch 1's
classification of S-04 as *"BUILD, genuinely open"* was right; the block table
overstated it. **Recorded rather than quietly corrected, because the block table
is what travels with the escalation.**

### AND THE 37 IS STALE - **at least 3 of 46 are already resolved**

    ExcelAutomationAgentController@credentialStatus    O-03: probed, refuses a foreign tenant
    ExcelAutomationAgentController@downloadTemplate    O-03: same
    AJAXController@getSkillCompetency                  audited; the helper is load-bearing and held

**The sweep ran 2026-08-06. It is six days old and already wrong about three
routes** - all three changed by this engagement, none of them re-measured against
the sweep that named them.

**A FAIL list is a photograph, not a fact.** It was true when taken; every fix
since has made it less true, and nothing marks which entries have expired. On this
phase's record with unre-examined numbers, **the real count of remaining leaks is
unknown and is certainly below 46.**

### WHAT S-04 ACTUALLY IS

**Not "hand-verify 37 candidates".** It is:

1. **Re-run the C23 property** against the 43 actionable routes - the harness
   exists, it executed 912 routes once, and re-running it is cheaper than reading
   twenty-two controllers.
2. **Read only what still fails.** Every route that now passes was fixed by
   something and needs no reader at all.

**The row is smaller than it looks and the sweep is the reason.** Re-measuring
first is the same discipline that closed four rows and saved three - applied to a
number instead of a population.

---

## THE THREE MEASURE-FIRST ROWS - **all three survive. The run of closures ends.**

Four consecutive rows had just closed on an empty population, so these three were
measured before building. **None of them closes.**

| row | population | verdict |
|---|---|---|
| **L-18** Importance -> `weight` | `s_users_skills.importance` **exists**; 27 of 226 kasba items already carry a non-default weight (3.00 x15, 4.00 x10, 2.00 x1, 5.00 x1) | **BUILD, open** - both sides real |
| **L-20** three `*_tags` -> shared categories | `custom_tags` **943 rows / 706 distinct**; `category` 3,976/16; `sub_category` 3,971/470; `micro_category` 3,969/352 | **BUILD, open** - and 706 distinct free-text tags collapsing into 16 categories is the largest real consolidation left |
| **TL-03** requisitions read `jobrole_competency_map` | `talent_job_postings` **126 rows**; the map holds 23 | **BUILD, open** - both sides real |

**This is the useful negative result.** Measuring first is not a way of closing
rows - it is a way of knowing which ones are worth building. **Four closed, three
did not, and the three that survived are now known to be worth the effort rather
than assumed to be.**

### ⚠ A NEAR-MISS ON TL-03, AND IT IS THE SIXTH

My first query asked for tables matching `%requisition%` and returned **NONE**. I
was one sentence from recording TL-03 as a fifth population-zero row.

**The requisitions are called `talent_job_postings`.** 126 rows.

**Sixth instance of the search scope being the population** - after `services/`,
the wrong `can_view` column, the `jobroles` meta key, the class-name pattern on
`Reports/`, and the `s_users_skills.skill` column that does not exist. **The guard
worked this time only because a zero from a search now prompts the question rather
than the conclusion.**

---

## L-05's RULE IS NOW AT THE COLUMN

    s_users_skills.status  COMMENT:
      "L-05: filter with status != 'Inactive', NEVER status = 'Active'.
       NULL means nobody marked this row, not that it is inactive - 1,197 of
       5,171 are NULL and 0 are Inactive. status = 'Active' would silently drop
       23% of the library."

**A rule in a document is remembered; a rule at the column is met.** 103 columns
in this schema already carry comments, so this is how the schema says things.

The definition was **restated verbatim from `information_schema`** rather than
from memory - a `MODIFY` that retypes a column from a developer's recollection is
how a comment change silently becomes a schema change. Verified after: type,
nullability, 5,171 rows and 1,197 NULLs all unchanged.

### ⚠ AND THE DRY RUN CAUGHT A DEFECT I DID NOT READ

The dry run printed:

    default='NULL'

**A quoted string, not SQL NULL** - `information_schema` returns the *string*
`'NULL'` on this MySQL version. My statement wrapped it in quotes again, producing
`DEFAULT 'NULL'`, an invalid enum value. **MySQL refused it and nothing changed.**

**The dry run printed the defect and I ran the apply anyway.** A dry run whose
output nobody reads is not a safeguard - it is a delay. **The schema refused a
wrong assumption for the second time this phase**, after the `right_*` enum
truncation.

---

## THE THREE QUESTION-FIRST ROWS, MEASURED - **two close, and one closes with a finding**

### T-02 Surface `delay_category` - **CLOSES. Nothing to surface.**

    task.delay_category non-empty : 0 of 2,271
    task.delay_reason  non-empty  : 1 of 2,271
    tables with a delay* column   : task (only)

**Surfacing a column nobody fills shows an empty column.** The mechanism is
trivial and the population is zero. **Closed as DECIDED, with the trigger: when
tasks start carrying a delay category, surface it.**

Consistent with `task_hygiene`'s own reading - 2,088 of 2,271 overdue and 4
in-progress across the whole table. **The task workflow is not being used, so its
workflow-only fields are empty by consequence, not by oversight.**

### L-05 Honour Status at assignment time - **CLOSES, AND THE REASON IS A DEFECT AVOIDED**

Every status column in the chain carries exactly ONE non-null value:

    s_jobrole         Active x 3,347
    s_user_jobrole    Active x 4,729,  NULL x 7
    s_users_skills    Active x 3,974,  NULL x 1,197
    competency        active x 209
    sub_std_map       1 x 96

**There is no inactive, archived or draft row anywhere in the chain.** So
"honour Status at assignment time" would exclude **nothing** - which is the
population-zero result the other three rows had.

**EXCEPT IT WOULD NOT BE HARMLESS.**

`s_users_skills` holds **1,197 rows with a NULL status - 23% of the library.** A
filter written as `where status = 'Active'` excludes every one of them.

**So the mechanism has a population after all, and it is the wrong one.** It would
not remove skills someone deactivated - **there are none.** It would remove skills
**nobody ever marked**, which is `NULL` read as "not active."

**That is unmeasured-as-zero, in a filter, and it would have deleted a quarter of
the skill library from every assignment screen.** Nothing about the code would
have looked wrong: `where status = 'Active'` is exactly what the row asked for.

**Closed as DECIDED. If status is ever honoured, the rule is `status != 'Inactive'`,
never `status = 'Active'`** - absence of a mark is not a mark of absence, and the
column has no inactive value to test against anyway.

### WHAT THE FOUR TOGETHER SAY

    X-08(b)   0 of 26 resolvable
    L-04      0 of 70 selectable
    T-02      0 of 2,271 populated
    L-05      0 inactive rows - and 1,197 NULLs a naive filter would have eaten

**Four consecutive rows where the mechanism was fine and the data was not.** This
is not a pattern in the plan; **it is the plan's normal case**, and it is why the
question-first rows were measured before anything else in the queue.

**Three of the four closed without being built. The fourth changed direction.**

---

## A CHECK THAT RETURNS ZERO MATCHES IS INDISTINGUISHABLE FROM A CLEAN RESULT

**Fifth instance of a hand-rolled check losing to the artefact it was checking**,
and this one is the undifferentiated-signal family arriving **inside the
classification itself.**

Verifying whether O-04's report-route leaks were blocked, I ran a pattern keyed on
class names:

    grep -rl "class .*Report" app/Http/Controllers/Reports/   ->  no output

**I read "no output" as "none of them are in the 51".** The truth is the pattern
matched nothing at all - those controllers do not have `Report` in their class
names. Checking the file list directly:

    Reports/ controllers among the 51 : 7

**I would have reported O-04 as OPEN on a check that had not examined anything.**

**A zero from a search and a zero from a population are different claims**, and
nothing in the output distinguishes them. The same shape as the two zeros, as the
`services/` grep, as the wrong-column read - **but this one would have entered a
count the owner is taking to an escalation.**

**The guard is the one already written down: a check whose result is zero must
name its known-positive.** A pattern that cannot demonstrate it matches something
has not been shown to be looking.

---

## L-04 DECISION - **OPTION 3: FREE TEXT, WITH A TRIGGER** (Triz, 2026-08-12)

Same ruling X-03 made for `certification_qualifications`, **for a stronger
reason**: that field's picker would have rendered empty; **this one would orphan
real data.** 70 rows already hold values a picker would refuse, so a customer
editing any job role would find **their own value gone from the list.**

**TRIGGER: when a customer's job levels are mapped to a grading scale, this
becomes a picker.** That mapping is AUTHORING and belongs with the seed-library
import, not with a form field.

### WHY NOT THE OTHER TWO, RECORDED SO NEITHER IS RE-PROPOSED

- **Option 1, map the vocabularies** - requires asserting that ENTRY means SFIA
  level 1 or 2. **That is a claim about a customer's grading scheme that neither
  of us can make**, and it would silently reshape 70 existing rows.
- **Option 2, source the picker from the data** - a dropdown of the five words
  someone typed. **Correct, invisible to a customer, and it loses the SFIA link
  that was the row's whole purpose.**

---

## THE ROW'S SIZE WAS NEVER THE PROBLEM. THE ROW'S POPULATION WAS.

**X-08(b) and L-04 are one shape**, and it is now the standing reason the size
check measures a row's data before reading its code.

| | the mechanism | the population it would serve |
|---|---|---|
| **X-08(b)** | a picker matching held labels to catalogue items | **0 of 26** - four dimensions have no catalogue; the fifth's 8 labels are clinical and the catalogue is finance |
| **L-04** | a picker sourcing job level from SFIA | **0 of 70** - the catalogue says 1-7, the data says ENTRY/MID/SENIOR/ADVANCED/EXECUTIVE |

**Both are G-SEED-01's correction: THE DISTANCE IS VOCABULARY, NOT DIMENSION.**
Both were rated small, and both estimates were right about the code and silent
about the data.

**A row's size tells you what it costs to build. Only its population tells you
whether building it does anything.**

### THE COUNTERFACTUAL THAT MAKES IT CONCRETE

**The matching screen would have passed review, passed its tests, rendered an
empty picker 26 times out of 26, and nothing about the code would have looked
wrong.**

That is what a correct implementation of a wrong row looks like: it does not fail,
it does not error, and it is not visibly defective. **It is simply useless, and no
technique applied to the code could have revealed that** - only counting the rows
it would serve.

---

## L-04 SIZED - **nineteenth row, and the SAME defect as X-08(b) one row later**

Rated **XS after X-03**. It is not a wiring job; **it would orphan every row it
touches.**

### THREE VOCABULARIES FOR ONE FIELD

    s_level_responsibility        levels 1-7, SFIA (112 rows = 7 levels x 16 attributes)
                                  Follow, Assist, Apply, Enable, Ensure/advise,
                                  Initiate/influence, Set strategy
    s_user_jobrole.job_level      ENTRY, MID, SENIOR, ADVANCED, EXECUTIVE
    library-config.ts             placeholder: 'e.g. L3'

**Three, in one field, none agreeing.**

### WHAT A PICKER WOULD DO

    rows with a job_level         70
    selectable from a 1-7 picker   0   (0.0%)

    SENIOR 21 - ADVANCED 18 - MID 16 - ENTRY 11 - EXECUTIVE 4
    every one NOT IN CATALOGUE

**Wiring the picker as the row describes would make all 70 existing values
unselectable.** A customer editing any job role would find the field blank and
their value gone from the list - **the control would not just be useless, it would
orphan real data.**

### THE SAME DEFECT, ONE ROW APART

**X-08(b):** a picker whose catalogue does not contain any of the 26 labels.
**L-04:** a picker whose catalogue does not contain any of the 70 values.

Both are **G-SEED-01's correction**: *the distance is vocabulary, not dimension.*
Both were rated small because the mechanism is small. **Neither row's size was the
problem; both rows' POPULATION was.**

**And L-04 is worse**, because X-08(b)'s holdings had no prior state to lose,
whereas here 70 rows already hold values a picker would refuse.

### WHAT THE ROW ACTUALLY NEEDS - a decision, not a build

Three options, none takeable without the owner:

1. **Map the vocabularies.** ENTRY->1, MID->3, SENIOR->5, ADVANCED->6,
   EXECUTIVE->7 or similar. **That mapping is a claim about the customer's grading
   scheme and nobody here can make it** - the numbers are SFIA's, the words are
   theirs.
2. **Source the picker from the DATA, not the catalogue** - offer the five values
   that exist, exactly as `sourceValues()` already does for departments. Loses the
   SFIA link the row was written for.
3. **Leave it free text**, as X-03 left `certification_qualifications`, with the
   same reasoning and a trigger.

**Filed, not built.** Option 2 is the only one that changes nothing a customer can
see; option 1 is the only one that delivers what the row intended.

---

## X-08(b) DECISION - **PROMOTE, NOT MATCH** (Triz, 2026-08-12)

The population settles it: **matching has 0, promoting has 26.** And the deeper
reason is already on record - `s_users_skills` is finance-and-compliance
flavoured, the holdings are clinical, and **G-SEED-01's correction says THE
DISTANCE IS VOCABULARY, NOT DIMENSION.** Four of five dimensions have no catalogue
at all, so **18 of 26 have nothing to match against by construction.**

### THE COUNTERFACTUAL, KEPT

**Building the matching direction first would have produced a screen that is
correct, complete, and useless for every row it would ever show.** It would have
passed review, passed its tests, rendered an empty picker 26 times out of 26, and
nothing about the code would have looked wrong.

**That is the sharpest argument this phase has produced for measuring a population
before building a UI over it** - and the cost of the measurement was one query.

### FOUR CONDITIONS, because it writes into a shared library

1. **NOT AUTOMATIC.** The customer confirms each promotion. **An entry created
   from import data is a claim about their vocabulary, and they should make it
   rather than inherit it** - the same reasoning that never auto-lowers a rating.
2. **PROVENANCE TRAVELS WITH THE ENTRY.** Created-from-a-held-import-label, and
   which competency it came from. **A library entry with no origin is
   indistinguishable from one someone authored** - and once it is in the library,
   nobody can tell the difference by looking.
3. **PROMOTION AND UPGRADE ARE ONE TRANSACTION.** The catalogue entry is created
   AND the HOLDING becomes a TARGET, or neither happens. **A promoted label that
   stays HOLDING is the two-paths-disagreeing defect again** - the same row
   readable as both resolved and unresolved depending on which side you ask.
4. **THE 18 CANNOT BE PROMOTED ANYWHERE.** knowledge, ability, attitude and
   behaviour have no canonical table. **Say so on the screen rather than offering
   an action that cannot complete.** Creating those four tables is a separate
   product decision, NOT TAKEN.

**Filed with its conditions. Not built.**

---

## ⭐ A SCREEN CAN IGNORE A DESIGN DOCUMENT; IT CANNOT IGNORE A FIELD THAT IS NOT THERE

**The most reusable thing this phase has produced about API design.** Three
instances, one mechanism.

| where | the field | what its absence would allow |
|---|---|---|
| readiness gates | `losing` | the acknowledge button rendered without saying what is lost |
| gap view | coverage travels with the level | a level shown as if fully measured |
| task-competency map | the empty-read `note` | 0 rows rendering as "no data" rather than "none authored yet" |

**The mechanism: the API cannot return the value without also returning the thing
that makes it honest.** Not "the response includes a warning" - **the honest
qualifier is inseparable from the datum it qualifies.**

### THE TEST

**If a requirement reads "the UI must also show X", ask whether X can travel with
the data.**

- **It can** -> put it in the payload. The requirement is now enforced by the
  shape of the response, and a screen that omits it has to actively discard
  something it was handed.
- **It cannot** -> it is a note somebody has to remember, and it will be forgotten
  by whoever builds the second screen against that endpoint.

**A requirement carried in a document is remembered. One carried in the payload is
enforced.**

---

## X-08(b) SIZED - **the resolvable population is ZERO, and it points the loop the other way**

Eighteenth row. Not wrong about size - **wrong about direction.**

### HELD ROWS WERE NEVER LOST

The importer commits an unmatched item as `HELD_AS_LABEL`: `item_label` set,
`item_id` null. So "held-row resolution" is not recovery. **It is upgrading a
HOLDING to a TARGET**, and the holdings are already in the database.

    competency_kasba_item    226 rows
      TARGET  (item_id)      200
      HOLDING (item_label)    26

### BUT ONLY ONE DIMENSION HAS SOMEWHERE TO RESOLVE TO

    skill        8   resolvable against s_users_skills
    knowledge    7   NO CANONICAL TABLE
    behaviour    4   NO CANONICAL TABLE
    ability      4   NO CANONICAL TABLE
    attitude     3   NO CANONICAL TABLE

**18 of 26 cannot be resolved by anything**, because four of the five KASBA
dimensions have no catalogue table at all. That is not a gap in this item - it is
the state `CompetencyDefinitionController` already documents.

### AND THE EIGHT THAT COULD BE RESOLVED, CANNOT BE

    skill holdings with an exact catalogue match: 0 of 8

    Patient triage - Medication administration - Patient communication
    Clinical documentation - Exercise prescription - Manual therapy
    Care planning - Structured handover (SBAR)

`s_users_skills` holds 5,171 rows and none of them is any of these. **The
catalogue is finance and compliance flavoured; the holdings are clinical.** The
tenant-3 seed authored healthcare competencies against a library that has never
contained healthcare skills.

**RESOLVABLE POPULATION TODAY: ZERO.**

### SO THE LOOP RUNS THE OTHER WAY

An enrichment screen offering *"pick the catalogue item this label means"* would
present, for all 26 holdings, **an empty picker** - the same defect X-03 already
ruled on: *a picker over an empty table looks like a closed list and offers
nothing.*

**The useful direction is the opposite one: PROMOTE THE LABEL INTO THE
CATALOGUE.** The customer's words are already the content (F-07b); what is missing
is not a match but an entry. That is *"add 'Patient triage' to your skill
library"*, not *"which existing skill is this?"*

**This is a design correction, not a build estimate**, and building the matching
direction first would have produced a screen that is correct, complete, and
useless for every row it would ever be shown.

**Filed, not built.** The direction is a product decision.

---

## A REQUIREMENT CARRIED IN THE PAYLOAD IS ENFORCED; ONE CARRIED IN A DOCUMENT IS REMEMBERED

Third instance, so it is named once here rather than a third time in a commit.

| where | field | what it stops |
|---|---|---|
| readiness gates | `losing` | a screen rendering the acknowledge button without saying what is lost |
| gap view | coverage travels with the level | a level shown as if fully measured |
| task-competency map | the empty-read `note` | 0 rows rendering as "no data" instead of "none authored yet" |

**In each case the API cannot return the value without also returning the thing
that makes it honest.** A screen can ignore a design document; it cannot ignore a
field that is not there, and it cannot render the control without the caveat if
the caveat arrives in the same object.

**The test:** if the requirement is "the UI must also show X", ask whether X can
travel with the data. If it can, the requirement is enforced. If it cannot, it is
a note somebody has to remember.

---

## L-14 SIZED - **the seventeenth row, wrong in a fifth way: rated L, it is an analogue of something already built**

Opened from what was already evidenced, so the measurement adds rather than
repeats.

### WHAT WAS ALREADY KNOWN

- the **catalogue link** `jobrole_task_competency_map` is **0 rows** and must be
  **AUTHORED, not derived** - deriving it from the instance was tried and refuted;
- the **instance link** `task.skill_id` works: **1,514 of 2,271**, re-measured;
- **Q-E1-Q1 is ANSWERED** - tasks are the exception, the confidence tag keeps its
  original meaning, with the caveat that every populated row on both sides was
  created by this phase's own work and no customer has authored a catalogue.

**So the row does NOT read "wire the override rule".** That question is closed. The
row is the write path, and the write path does not exist.

### WHAT THE MEASUREMENT ADDS

    jobrole_task_competency_map    0 rows   NO controller, NO route, NO write path
    s_jobrole_task            55,961 rows   GLOBAL seed library, no tenant column
    competency                   209 rows
    map columns: id, sub_institute_id, jobrole_task_id, competency_id, timestamps

**The table is real, correctly shaped, and unreachable.** Nothing in `app/` or
`routes/` names it.

### AND THE ANALOGUE IS ALREADY BUILT

    jobrole_competency_map   23 rows   RoleCompetencyMapController: index/store/destroy
                                       + a matrix UI (role-mapping/roles|matrix|cell)
    jobrole_task_competency_map  0     nothing

**Same shape, same tenant column, same Q-C1 pattern** - a global seed library with
a tenant-owned mapping onto it. L-14 is that mechanism one level down: jobrole
task -> competency instead of jobrole -> competency.

**Rated `L`. It is S-M**, because the pattern it needs is written, routed and
working twenty lines away.

### THE SPLIT THE ROW HIDES

**L-14 is a BUILD whose CONTENT is an AUTHORING dependency** - the same split as
X-08. Building the write path is small. **Filling the table is a customer act**,
and 0 rows will remain 0 rows until one performs it.

**That distinction has to be in the row**, or the next person reads a shipped
write path with an empty table as an unfinished build.

---

## X-03 IS DONE - **the size check found the work already built**

Sixteen plan rows in sixteen, and this one is wrong in a **fourth** way. The last
four were wrong differently each time:

    O-03    named a fix for a leak nobody had located
    X-15    named a file that did not exist
    X-07    a finished design with nothing built under it
    X-03    ALREADY BUILT, still rated M-L and "Not started"

### WHAT EXISTS

    library-config.ts   460 lines   an 11-member `source` union, 11 fields carrying one
    library-form.tsx    390 lines   sourceValues() resolves each source to real values
    /api/competency/library/meta    HTTP 200, supplies every source

**Measured end to end, not read** - the API called as a real administrator in
tenant 3:

    departments         18      related_skills       547
    sub_departments     40      job_titles           346
    micro_categories    24      learning_resources    67
    industries           1      proficiency_levels     6
    task_types           1      invisible_types        3
    jobroles           338 distinct, derived from jobroles_by_department (27 depts)

**Eleven of eleven resolve and every one is populated.**

### A CORRECTION ON THE WAY

My first probe reported `jobroles` **ABSENT from meta** - I looked for a key of
that name. `sourceValues()` derives it from `jobroles_by_department` instead. **I
checked the wrong key and nearly filed a gap that does not exist**, which is the
settled pattern's fourth shape again: the scope of what I looked at was the
population I judged.

### AND THE DECISION IT ALREADY CARRIES

`library-config.ts` line 181 holds an X-03 ruling written when the row was
supposedly not started:

> *"STAYS FREE TEXT. `certification_type` holds 0 rows, and a picker over an empty
> table looks like a closed list and offers nothing. Scheduled on
> `certification_type populated`, not parked."*

**A picker over an empty table looks like a closed list and offers nothing** is the
sentence to keep - it is the unmeasured-as-zero principle applied to a UI control.

### WHAT THIS LEAVES

`L-01` and `L-02` (department on the Job Role and Skill forms) were rated **XS
after X-03** and both `department -> departments` fields exist and resolve. **They
are done too.** `L-04` (Job Level -> `s_level_responsibility`) has no matching
source in the union and is the one that remains.

**The plan's staleness is now itself measurable:** four consecutive rows, four
different kinds of wrong, and one of them wrong because the work was finished and
nobody closed the row.

---

## A CLASS THAT CANNOT BE LOADED AND IS NEVER NAMED PRODUCES NO ERROR

`app/Http/Controllers/libraries/jobroleLibrary1Controller.php` declared
**`class jobroleLibrary2Controller`**. PSR-4 maps a class to a file of the SAME
name, so the class could not be autoloaded: `class_exists()` returned false.

**And nothing referenced either name** - no route, no controller, nothing in
either repository. `routes/web.php` routes `jobroleLibraryController`, a
different and correctly-named file.

### WHY NOTHING CAUGHT IT

**It was invisible rather than broken.** A class that cannot load and is never
named throws nothing. `php -l` passes - the syntax is fine. The router never asks
for it. No test references it. **The linter, the suite and the router all agreed
with its absence**, because absence is exactly what they observe.

**It surfaced only as a reflection failure** during the `g2gActorId`
consolidation: the behaviour probe reached 14 of 15 classes, and the fifteenth
could not be reflected on. **The gap in a count was the only signal that the file
existed in this state.**

### RENAMED, NOT DELETED

The class now matches its file. It is loadable, and the consolidation assertion
reaches **15 of 15, 30 assertions, no behaviour change** - where before it could
only reach 14.

**Deleting was not taken.** It is a near-duplicate of `jobroleLibraryController` -
the same six methods, 820 lines against 681 - and only that one is routed.
**Whether this variant should exist at all is a separate decision**, and the fix
here is the name, so the file is at least loadable and its contents can be
compared before anyone decides.

### ⚠ FILED, NOT TAKEN - **two near-duplicate job-role library controllers**

    jobroleLibraryController    681 lines   ROUTED (jobrole_library resource)
    jobroleLibrary1Controller   820 lines   loadable now, referenced by nothing

Same six methods. One is live, one has never been reachable. **The larger file is
the unrouted one**, which is the wrong way round for a superseded copy and worth a
read before either is removed.

---

## SETTLED PATTERN - **A CLAIM DERIVED FROM CODE IS A CLAIM ABOUT WHAT THE CODE SAYS, NOT WHAT IT DOES**

Three instances this phase, all mine, all corrected by RUNNING the thing. Recorded
as a pattern rather than a fourth incident.

| claim | derived from | measurement | what the reading missed |
|---|---|---|---|
| `hr_manager` "differs by one menu" | `can_view` counts | write counts: 14 menus apart | **the wrong column** - a role's power is what it can WRITE |
| `department_head` "nothing through the API" | the alias map | probe: 403 x4, **200 on `competency/gap`** | **a route that is not `profile:`-guarded** |
| `jobrole-tasks` "an anonymous caller" | the fetch it builds | `curl` -> **401, and 0 callers** | **whether the code RUNS at all** |

**Every one was correct about the source and wrong about the system.** Reading
tells you what a line says; only running tells you whether that line is reached,
by whom, with what result.

**The tell is a claim about BEHAVIOUR justified by a citation of SOURCE.** "This
sends no token", "this role is in no alias", "these differ by one menu" - each is
true of the text and none is a statement about what happens.

**And the correction is always cheap:** a curl, a probe, a reflection call. In all
three cases the measurement took less time than the reading that produced the
error.

### A FOURTH SHAPE, SAME TURN - **the search scope IS the population**

Removing the inert `api_key`, I grepped `app/ components/ lib/ hooks/` and wrote
*"its only consumer"*. There were **two** - `services/` was not in my search.
`tsc` found it.

**A claim about a population made from part of it**, which is the two-zeros error
in a grep. The finding was right; **the scope was the error.**

---

## ⛔ G-BLOCK-01 BLOCKS A THIRD THING - **the path to the suite's true green**

`table_data` is declared in **`routes/web.php`** - one of the 51, carrying 9
insertions and 4 deletions of in-progress work.

    Route::get('table_data',[AJAXController::class, 'GetTableData'])->name('table_data');

**Putting middleware on it is the agreed path to a true green** - it turns an
unmeasurable population into a bounded one, after which
`tableDataRequestedTenant` deletes on a **census** rather than on a sample of two
repositories. **It cannot be done.** Editing that file would either commit
somebody else's work or leave mine uncommitted - the trap already fallen into once
with `bootstrap/app.php`.

The route's own line is untouched by the foreign edit, which changes nothing: the
file is the unit git commits.

**Three capabilities now blocked on the same 51:** the matrix guard cannot be
registered, the resolver consolidation cannot be landed, and the suite cannot
reach a true green.

### AN ERROR IN THE CONDITION I WAS GIVEN, RECORDED AS SUCH

The instruction was: *"if no anonymous caller exists, `tableDataRequestedTenant`
deletes on the same footing as the other three."*

**The footing is not the same, and accepting the framing would have been the
wrong-population error inside the item whose evidence rule exists to prevent it.**
The other three were deleted on a CENSUS - 0 of 401 accounts, a complete
population with no outside. `table_data` has **no middleware**, so two
repositories are not the population; they are the visible part of it.

Recorded because a condition can be wrong the same way a measurement can, and the
owner asked for their error to be written down rather than only the correction.

---

## ⚠ RETRACTION - **"THE ANONYMOUS CALLER IS REAL" WAS WRONG. IT IS DEAD CODE.**

Reported 2026-08-12 and corrected the same day, on the way to migrating it.

`app/api/jobrole-tasks/route.ts` calls `readLaravelSession()`, which begins:

    if (typeof window === 'undefined') return null

**It is a Next.js SERVER-SIDE API route**, so `window` is always undefined, so the
session is always null, so it returns 401 before reaching `table_data`. Measured,
not read:

    GET /api/jobrole-tasks?jobRoleId=1  ->  401  {"message":"Unauthorized"}

**And nothing calls it.** Callers of `/api/jobrole-tasks` across the frontend: **0.**

So the chain is dead at both ends: no caller, and it could not succeed if it had
one.

### THE CORRECTED POPULATION

    table_data callers, measured    2
      job-posting-form.tsx          'use client', sends Authorization: Bearer   AUTHENTICATED
      app/api/jobrole-tasks         server-side, always 401, zero callers        DEAD

**Anonymous callers: ZERO.** Not "none observed" - none that can execute.

### WHY I GOT IT WRONG, AND IT IS THE SAME ERROR TWICE

I read the file, saw `api_key` and `filters[sub_institute_id]` and no bearer
token, and concluded it was an anonymous caller. **That is a claim about what the
code SENDS. Whether it RUNS is a different question and I did not ask it.**

Same shape as `department_head` ("nothing through the API" - the probe found
self-service intact) and `hr_manager` ("one menu apart" - I read the wrong
column). **Three times now: a claim derived correctly from code and wrong about
behaviour.** The correction each time came from running it.

### WHAT IT MEANS FOR `tableDataRequestedTenant` - **AND WHY IT IS NOT A CENSUS**

Within the two repositories, no anonymous caller exists, so the helper compensates
for nothing measurable here.

**BUT THE OTHER THREE FALLBACKS WERE DELETED ON A CENSUS** - 0 of 401 accounts,
a complete population with no outside. **This is not that.** `GET table_data`
carries **no middleware at all**, so it is reachable by anything that knows the
URL: a mobile client, an integration, a script. **Two repositories are not the
population; they are the part of it I can see.**

**So the deletion is a DECISION, not a consequence** - and calling it "the same
footing as the other three" would be the wrong-population error one more time, in
the very item whose evidence rule was written to prevent it.

---

## `api_key` IS INBOUND-CHECKED NOWHERE - **a control that looks like authentication in the caller and is inert at the receiver**

Same family as a check whose name overstates its layer - **except the misleading
artefact is PRODUCTION CODE, and the reader it misleads is a developer deciding
whether a route needs a guard.**

All four backend mentions are **outbound third-party keys**, not inbound credentials:

    SessionController      gemini.api_key        -> reports whether Gemini is configured
    contentController      GOOGLE_API_KEY        -> outbound to googleapis.com/youtube
    DeepSeekService        deepseek.api_key      -> Http::withToken(), outbound
    GammaService           gamma.api_key         -> outbound fallback

**The backend has no inbound `api_key` mechanism at all.** The frontend's
`resolveHpApiKey()` and `params.set('api_key', ...)` send a credential to an API
that has no concept of one.

**A credential nobody checks is worse than none, because it reads as protection.**
Anyone auditing that call sees a key on the wire and moves on.

---

## G-MIG-01 ANSWERED BY A ROUTE-LEVEL AUDIT - **the anonymous caller is real, and there is exactly one**

The evidence a log count was explicitly rejected for. **Who actually calls
`table_data` - by route, by caller shape, and whether any path reaches it without
an identity.**

### THE ROUTE

    GET table_data  ->  AJAXController@GetTableData   middleware: NONE

**No `auth`, no `session`, no `profile`.** Unauthenticated at the route layer by
construction, which is why the helper needs a request fallback at all.

### THE CALLERS - **two, both in the frontend, and they differ**

    hp_erp resources/ and public/      0 files   <- no Blade or server-side caller
    g2gv0                              2 files

| caller | identity it sends | anonymous? |
|---|---|---|
| `components/domain/talent/recruitment/job-posting-form.tsx` (2 fetches) | `Authorization: Bearer ${session.token}` | **no** |
| `app/api/jobrole-tasks/route.ts` | `api_key` + `filters[sub_institute_id]`, **no bearer token** | **YES** |

### THE ANONYMOUS ONE IS ANONYMOUS IN THE BACKEND'S TERMS

`tableDataAuthenticated()` accepts a session `user_id` or a resolvable personal
access token. The proxy route sends neither: it runs server-side, holds the
session in Next, and forwards **the tenant as a filter** rather than the identity
that proves it.

**So `tableDataRequestedTenant` is load-bearing today.** Deleting it breaks
`app/api/jobrole-tasks/route.ts`. The hold was correct, and it is now correct for
a measured reason rather than a refused one.

### ⚠ AND THE `api_key` IT SENDS IS NEVER CHECKED

    api_key referenced in app/ : 4 files
    any of them on the table_data path : NONE

`SessionController`, `contentController`, `DeepSeekService`, `GammaService` -
none is reachable from `GetTableData`. **The proxy attaches a credential the
backend does not read.** It looks like authentication in the caller and is inert
at the receiver: a control that appears present and is not, which is why the
route reads as protected to anyone reading the frontend.

### WHAT G-MIG-01 BECOMES

**A migration item with a named population of ONE.** Make
`app/api/jobrole-tasks/route.ts` forward the session's bearer token the way
`job-posting-form.tsx` already does. When it does:

- `tableDataAuthenticated()` returns true for every caller;
- `tableDataRequestedTenant` deletes on the same footing as the three G-SEC-28
  fallbacks - a compensator for a condition that no longer occurs;
- the suite's private-helper check reaches a true green **by the callers changing,
  never by the check changing.**

**The suite stays red until then, and that is the correct state.**

---

## G-SEC-11's TWO DIFFERING ROUTES - **MEASURED AS ONE. The candidate does not hold.**

With all five ExcelAutomationAgent routes decided, the differing responses should
have been explicable as differing refusals. Measured across tenants 3 and 6:

    credentialStatus   DIFFER   (200 vs 200, different bodies)
    downloadTemplate   same
    testConnection     same
    saveCredentials    same
    upload             same

**One, not two.** The candidate explanation is not confirmed and is not promoted.

**AND THE ONE THAT DIFFERS IS CORRECT.** `credentialStatus` returns tenant 3's
credential row to tenant 3 and tenant 6's to tenant 6, because each tenant has its
own. **THAT IS WHAT CORRECT TENANT SCOPING LOOKS LIKE.** A sweep that flags
"responses differ by tenant" flags correct isolation - **a LEAK would show
IDENTICAL responses**, both tenants seeing the same rows.

**The signal was inverted.** Whatever G-SEC-11's sweep measured, "differing" was
being read as suspicious when it is the healthy outcome.

**The count is not reconciled and is not being explained away.** Two candidates
for the missing second route: the sweep passed inputs this measurement did not, or
O-03's own fix changed `saveCredentials` and `testConnection` from differing
error payloads to uniform ones. **Neither is established, so neither is recorded
as the reason.** The routes are decided on their own evidence - all five refuse a
foreign tenant - and G-SEC-11's count stays an unexplained discrepancy rather than
a loose end pretending to be closed.

---

## O-03 IS 5 OF 5 - **the held route is decided**

`POST /api/excel-agent/upload` was HELD, not cleared, because the first probe hit
the validator and never reached the guard:

    upload  3->6   HTTP 422  The file field is required.

**A 4xx before the code under test is not a result.** Four routes were decided and
this one was recorded as held, because *"the request was rejected"* and *"the
guard rejected it"* are different claims.

**Resolved with a real `.xlsx`** - a ZIP carrying the three parts that make PHP's
`finfo` report the spreadsheet mime, built in the probe rather than committed as a
binary: **a fixture whose construction is visible can be checked; a blob cannot.**

    caller 3 asks 3   ->  422  Unable to read Excel file.
    caller 3 asks 6   ->  403  Invalid sub institute access.
    caller 6 asks 3   ->  403  Invalid sub institute access.

### THE DISCRIMINATOR IS THE ORDER, NOT THE STATUS

Own tenant reaches the PARSER and fails there - the fixture has no sheet data, so
422 is correct and expected. A foreign tenant is stopped EARLIER, at the guard.
**The guard fires before the parse**, which is the same reachability argument that
decided `saveCredentials`: what a caller can and cannot get to, rather than what
it is told.

**A 422 that differs from a 403 by WHERE it happens is a stronger result than two
different messages would be** - the message can be rewritten by a catch; the
ordering cannot.

**O-03: all five routes refuse a foreign tenant.**

---

## A SYNTAX CHECK AGREES WITH A SEMANTIC MISTAKE

From the `g2gActorId` consolidation, and it is the clearest demonstration this
phase has produced of why **"no behaviour change" is asserted, not assumed.**

The consolidation removed fifteen method bodies and inserted the trait `use`
**among the FILE-LEVEL IMPORTS instead of inside the class body.**

    use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;   <- an IMPORT
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;         <- WHERE MINE WENT

    class jobroletaskcontroller extends Controller
    {
        use ResolvesApiIdentity;                                   <- where a TRAIT is applied

**A file-level `use X;` is an import. A trait is applied by a `use X;` inside the
class.** So the bodies were gone and the trait was never applied: fifteen
controllers called a method that did not exist.

**`php -l` WAS CLEAN ON ALL FIFTEEN, THROUGHOUT.** It is syntactically valid to
import a trait and never use it. The linter agreed with the mistake because the
mistake is well-formed.

### WHAT CAUGHT IT

The behaviour assertion, reporting **`MISSING METHOD` on four classes and ZERO
assertions run**. Not a subtle wrong answer - a loud absence, from the check that
was insisted on precisely because *no behaviour change* is a property to
demonstrate rather than expect.

**Had it been assumed, this would have shipped looking finished** - consolidated,
lint-clean, and broken at every call site.

### THE GENERAL FORM

**A syntax check agrees with a semantic mistake.** `php -l`, a type-checker, a
successful build and a green parse all answer "is this well-formed", and a
well-formed wrong thing passes every one of them. **The class of defects they
cannot see is exactly the class where the code means something other than
intended** - which is most of them.

---

## THE SCOPE RULE'S SHARPER INSTANCE - **`context`, 3 of 7, six distinct bodies**

`g2gActorId` was cleared as one helper and there were fifteen; it generalised
**by accident**, because all fifteen were byte-identical.

**`context` could not have.**

    files with a `context` helper : 7
    distinct bodies               : 6
    cleared by the register       : 3

**Clearing three of seven non-identical helpers generalises to nothing.** The
three that were cleared say nothing whatever about the other four, and the entry
did not say how many there were.

The other four - `ResolvesLmsIdentity`, `SkillDevelopmentController`,
`TaskListController`, `WorkspaceController` - **measure clean**: no request-tenant
read, consistent with the suite's private-helper check naming only two offenders.
**But that was established on 2026-08-12, not when they were cleared.** For four
turns the register implied a coverage it never had.

### THE QUESTION THAT PRODUCED BOTH FINDINGS, NOW ANSWERED

*Does any register clearance name a helper without stating how many copies exist?*

    resolveSubInstituteId       1 file,  1 body    scope was total
    tableDataRequestedTenant    1 file,  1 body    scope was total
    tableDataTenant             1 file,  1 body    scope was total
    g2gActorId                 15 files, 1 body    generalised BY ACCIDENT
    context                     7 files, 6 bodies  COULD NOT GENERALISE

**Two of five had a scope problem and neither entry mentioned a count.** Every
clearance now carries one. **A CLEARANCE OF ONE INSTANCE IS NOT A CLEARANCE OF A
PATTERN, EVEN WHEN IT TURNS OUT TO BE** - and the only way to know which it is, is
to count before writing the verdict.

---

# PROBE AUDIT OF THE CLEARANCES - **2026-08-12**

**Scope:** a probe that FOUND a defect proved it could reach and discriminate. A
probe that CLEARED something may never have arrived. So only clearances were
re-read - and the first thing the audit found was that **none of the six were
cleared by a probe at all. They were cleared by READING the chain.** A read has no
reach-or-discriminate property; it has R20's boundary.

| clearance | reach | discriminate | verdict |
|---|---|---|---|
| `DependencyController::context` | n/a | n/a | **SUPERSEDED** - G-SEC-28 deleted the fallback and probed before/after |
| `MyTasksController::context` | n/a | n/a | **SUPERSEDED** - same |
| `ProjectController::context` | n/a | n/a | **SUPERSEDED** - same |
| `ExcelAutomationAgentController::resolveSubInstituteId` | yes | yes | **STANDS** - later probed behaviourally, 4 cases, both throws verified |
| `AJAXController::tableDataRequestedTenant` | **yes, on the second attempt** | yes | **STANDS** - measured below |
| `g2gActorId` | yes | yes | **STANDS on its merits, and the entry was wrong about its scope** |

## THE AUDIT'S OWN FIRST PROBE HAD THE DEFECT IT WAS AUDITING FOR

Attempt 1 on `tableDataRequestedTenant` built a request with **no identity at
all** and reported:

    tableDataTenant()          : NULL
    tableDataRequestedTenant() : '999'
    the ?? pair yields         : '999'      -> "CLEARANCE DOES NOT STAND"

**That was the documented fallback working as designed.** The claim under test is
*"proven identity wins outright"*, and a request with no identity cannot test it.
**The probe never reached the condition** - property (1), failing silently, inside
the file written to catch exactly that.

Attempt 2, with a real token for tenant 3 and a foreign tenant in the request:

    tableDataTenant()          : '3'
    tableDataRequestedTenant() : '999'
    the ?? pair yields         : '3'        -> CLEARANCE STANDS

**Identity wins with a foreign tenant present.** That is the claim, and it is now
measured rather than read.

## `g2gActorId` - THE CLAIM STANDS; THE CLEARANCE'S SCOPE WAS ACCIDENTAL

The register cleared **`jobroletaskcontroller::g2gActorId`**, as one helper in one
class. Measured:

    definitions of g2gActorId : 15
    byte-identical bodies     : 15 of 15 (same md5 across every file)
    the named class           : not among them - the real path is
                                app/Http/Controllers/libraries/jobroletaskcontroller.php,
                                not Api/

The body is what the entry said: `apiUserId()` first, `$request->session()`
fallback, **no request-supplied value** - a session is server-side, not caller
input. **The claim is correct.**

**But it was made about one copy of a helper that exists fifteen times.** It
generalises only because all fifteen are byte-identical - **and nobody had
measured that.** The clearance was right for a reason that was never checked. **A
clearance of one instance is not a clearance of a pattern, even when it turns out
to be.**

## WHAT THE AUDIT CHANGES

**Nothing goes back to CANDIDATE.** All six stand: three superseded by code that
was later deleted and probed, three now backed by measurement rather than reading.

**What changed is the basis, not the verdict** - and that was the point. Five of
the six had been resting on a read since the triage, and one of those reads was
about the wrong class and one-fifteenth of the surface.

---

# TRIAGE OF THE SEVEN - **COMPLETE: 6 CLEARED, 1 CANDIDATE**

**Cleared by reading the chain (R20), never by shape:**

| Helper | Verdict |
|---|---|
| `DependencyController::context` | local copy of the resolver; token owner wins, request is the no-tenant fallback |
| `MyTasksController::context` | same |
| `ProjectController::context` | same |
| `ExcelAutomationAgentController::resolveSubInstituteId` | **stricter than the trait** - throws on mismatch; super-admin branch unreachable (0 of 2) |

| `AJAXController::tableDataRequestedTenant` | **fallback, not a source.** The call site reads `tableDataTenant() ?? tableDataRequestedTenant()` - proven identity wins outright, documented in a comment, missing tenant fails closed with a 400, anonymous reads logged to a migration worklist (G-SEC-19's work) |
| `jobroletaskcontroller::g2gActorId` | token first, session fallback, **no request value** - and it resolves an ACTOR, not a tenant |

**NOT CLEARED: `SaveJDController::normalizePayload` - see G-SEC-27.**

> ### THE HELPER IS CLEARED. THE ROUTES ARE NOT.
>
> G-SEC-11 recorded `ExcelAutomationAgentController` with **2 differing routes**.
> This method cannot produce that. **Clearing the routes from a clean helper would
> be clearing a route by reading a helper** - the two differing routes stay
> unexplained and belong to **S-04's 37 unverified candidates** until the rest of
> that controller is read.

---

# ⛔⛔ G-BLOCK-01 IS STRUCTURAL, NOT A QUEUE PROBLEM - **2026-08-12**

## A BUILT AND PROVEN GUARD THAT CANNOT BE REGISTERED, BECAUSE ITS ONE LINE LIVES IN SOMEONE ELSE'S UNCOMMITTED FILE

That is the whole statement. Everything below is the evidence for it.

Until today this block DELAYED items. It now COSTS A SHIPPED CAPABILITY:
matrix-enforced authorization is written, tested, and cannot be switched on.

### WHAT IS COMMITTED AND WORKING

| commit | contents |
|---|---|
| **`610d06c9`** | `app/Http/Middleware/RequireMenuRight.php` - the guard, 139 lines, full precedence - and `routes/api.php`, both readiness routes carrying `menuright:225,view` and `menuright:225,edit` |
| **`21de09d2`** | `matrix-guard-probe.php` - the acceptance test - plus `G-NAV-02` menu creation + backup and `G-NAV-02b` rights seeding |

### WHAT IS NOT COMMITTED - **ONE LINE**

    bootstrap/app.php    M  (one of the 51)
    +            'menuright' => \App\Http\Middleware\RequireMenuRight::class,

**Without that line the middleware alias does not resolve and neither route can
name the guard.** The guard exists, is correct, is proven, and does nothing.

`bootstrap/app.php` is one of the 51 files under the owner's control. Committing
it would commit 36 lines of their in-progress work - which was attempted by
accident this turn, caught, and reversed: the commit was rebuilt without it and
the baseline re-verified at 51.

### THE PROOF THAT IT WORKS, so the block is not hiding an unfinished thing

Run against menu 225 with `hr_manager` holding `can_view=1, can_edit=0`:

    administrator                       -> 409  past the guard
    hr_manager                          -> 403  "Your role does not have edit
                                                 rights on this screen (menu 225)."
    FLIP THE ROW (grant can_edit)       -> 409  ALLOWED
    FLIP BACK    (revoke can_edit)      -> 403  refused again
    INDIVIDUAL DENY over GROUP ALLOW    -> 403  individual DENY wins

**The refusal demonstrably comes from the matrix, not from a name in a route
file.** A refusal that survived the row changing would have proved nothing; this
one follows the row in both directions.

### WHAT IT COSTS, PRECISELY

- An **HR Manager can still acknowledge a readiness gate**, commit a framework
  import, and rewrite the reporting line - three configuration acts
  `03-rbac-matrix.md` 3.1 denies them. The fix for that is written and unusable.
- The **admin screen for controlling HR and employee rights** (menus 15/16) is
  blocked behind it, because a screen editing rules nothing enforces is worse
  than no screen.
- The **readiness screen remains unreachable** in the product; its menu row was
  created and rolled back rather than left half-applied.

### ⛔ IT NOW BLOCKS A SECOND CAPABILITY, NOT A SECOND ITEM

**2026-08-12.** Both are decided, both are correct, both wait on the same files.

| capability | state | what it waits on |
|---|---|---|
| **Matrix-enforced authorization** | built, proven, **cannot be registered** | one line in `bootstrap/app.php` |
| **Identity-resolver consolidation** | decided (THROW), **cannot be landed** | 21 of 54 exposed callers are in the 51 |
| **The suite reaching a TRUE GREEN** | the only earned path is blocked | `table_data`'s route is in `routes/web.php`, one of the 51 |

**The consolidation was stopped before any edit.** Adding the throw to
`ResolvesApiIdentity` changes behaviour for all 76 consumers; 54 of those
reference a request tenant, so a mismatch is possible there; **21 of those 54 are
files that cannot be committed.** Four of the 21 are CONTEXT WRAPPER TRAITS -
`ResolvesAgenticContext`, `ResolvesMobilityContext`, `ResolvesOffboardingContext`,
`ResolvesOnboardingContext`, `ResolvesPerformanceContext` - so **the blast radius
is wider than the file count**, because each fans out to every controller in its
domain.

**THE 54 IS AN UPPER BOUND, NOT A RUNTIME COUNT.** It counts where a tenant CAN be
supplied. "Currently passing a MISMATCHED tenant" is a property of live requests
and the true number is somewhere between 0 and 54. **Consolidating on the
assumption it is 0 would be the wrong-population error again** - the same shape as
the two zeros, and it is why this stopped rather than proceeded.

**This is the difference between a blocked queue and a blocked product.** One item
waiting is a schedule problem. Two finished capabilities that cannot be switched
on is the block deciding what the product does.

**AND THE THIRD IS THE HARDEST TO ARGUE WITH.** `table_data` carries no
middleware, so its caller population is unbounded and unmeasurable. Putting
middleware on it bounds that population, after which `tableDataRequestedTenant` -
the last offender in the suite - deletes on a **census** instead of a sample of
two repositories. **That is the only path to a true green that is earned rather
than tuned.** The route is declared in `routes/web.php`, one of the 51.

So: two built things cannot be switched on, **and the project's own quality signal
cannot be made honest.** A red that is correct and permanent is not a quality
signal; it is a standing exception, and standing exceptions are what people stop
reading.

---

### THE MATRIX AND THE ROUTES DISAGREE IN FOUR DIRECTIONS

**One table, and it completes the case.** Measured 2026-08-12 across all nine
roles, on route guards and WRITE rights:

| role | the matrix says | the routes say | direction |
|---|---|---|---|
| `hr_manager` | view-only on configuration (35 write menus, none of them config) | **full write** - all 32 `admin,hr` routes | **MORE than allowed** |
| `department_head` | write on 7 screens | **nothing elevated** - in no alias | **LESS than granted** |
| `executive` / `auditor` | read 72 / 81 menus, write 0 | **nothing at all** - in no alias | **LESS, and it costs the role's whole purpose** |
| `hr_executive` | 23 write menus vs `hr_manager`'s 35 - a deliberate 12-menu narrowing | **identical to `hr_manager`** - one alias covers both | **THE DISTINCTION IS INVISIBLE** |

### THE SYMMETRY IS THE ARGUMENT

**`hr_manager` gets MORE than the matrix allows. `department_head` gets LESS.**
Same enforcement gap, opposite directions - and **an administrator can correct
neither**, because both live in a `const` array.

**A hardcoded alias list can be wrong in both directions at once, and the only
mechanism an admin can actually edit is the one nothing reads.** That is the case
for matrix enforcement, and it is stronger than "HR can do too much": the failure
is not a bad grant, it is that **the grant and the enforcement are different
artefacts and only one of them is editable.**

### AND THE FOURTH ROW IS THE WORST

`hr_executive` is not over- or under-granted. **The matrix draws a real, deliberate
distinction - department scope versus organization scope, twelve write menus - and
no route can see it**, because `'hr' => ['hr_manager', 'hr_executive']` collapses
them.

**That is the alias approach failing at the precise thing it was chosen for.** It
exists to express which roles may reach a route. Here it cannot express a
distinction the spec, the matrix and the seed all agree on.

---

### THE DATABASE IS WHERE IT STARTED

Menus 225/226 and their 44 rights rows were created, used to prove the guard, and
**rolled back**. Re-running is one command each. Held deliberately rather than
re-applied into a block.

---

# THE PRECEDENCE CAN ONLY REVOKE - **an admin screen that can only take rights away**

**Raise this before menu 16 is designed, not after.** Whoever builds "Individual
right management" must meet this fact before the layout.

### THE MEASUREMENT

    can_view / can_add / can_edit / can_delete    tinyint(1) NOT NULL DEFAULT 0
    right_view / right_add / right_edit / ...     enum('allow','deny') NULL

`can_*` **cannot distinguish "explicitly denied by the group" from "not
granted".** Both are `0`. There is no third state.

### WHAT THAT DOES TO THE DECIDED ORDER

    individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny

Reading group DENY as `can_x = 0` - the only reading the column supports:

| state | outcome | what individual ALLOW does |
|---|---|---|
| `can_x = 1` | group already allows | **nothing** - it was already permitted |
| `can_x = 0` | group DENY, which outranks | **nothing** - it is outranked |

**INDIVIDUAL ALLOW IS DEAD IN BOTH BRANCHES.** An individual grant can never
widen access beyond the group.

### WHY IT IS A PRODUCT DECISION AND NOT A BUG

The behaviour is defensible: the group matrix becomes a hard upper bound, and no
per-person exception can quietly exceed it. That is the safer direction.

**But it is a different product from the one asked for.** "An admin screen where
the administrator controls what HR can do and what an employee can do" implies
granting as well as revoking. **As built, menu 16 can only ever REMOVE rights.**

### THE TWO WAYS OUT, NEITHER TAKEN

1. **Accept it.** Menu 16 becomes an exceptions screen: revoke-only, and its
   labels must say so. Cheapest, and honest, provided the screen does not offer a
   grant control that cannot work.
2. **Make group DENY expressible.** `can_*` would need to become nullable, so
   `NULL` = not granted and `0` = explicit deny. **NOT DONE:** altering the shape
   of a column 89 live rows depend on is not a side effect of adding a guard.

**Implemented as stated and documented rather than quietly re-ordered.** Silently
promoting individual ALLOW above group DENY would have made the screen work as
expected and made the precedence a fiction.

---

# ⛔ G-BLOCK-01 - **AN S1 CROSS-TENANT LEAK IS WAITING BEHIND UNCOMMITTED WORK** - blocking since 2026-08-11

**Nobody decided this on purpose.** It has been reported every turn as *"the 51
files: untouched"*, which reads as tidiness. **It is a blocker on the
highest-priority remaining item in the queue.**

## PROJECTING INTO A TABLE WITH NO `event_id` - **the answer, so the next one does not re-derive it**

`competency_evidence` has no `event_id` column and no unique key, so
`updateOrInsert` had nothing obvious to key on. The options looked like: add a
column (schema change, approval, migration), or accept duplicate rows on replay.

**Neither was needed.** The table already had `idx_ce_source` on
`(source_type, source_id)`. The projector writes `source_type` = THE EVENT TYPE
and `source_id` = THE EVENT ID, and replays overwrite instead of appending.

### WHY THIS IS NOT A WORKAROUND

**THE SOURCE OF A PROJECTED ROW GENUINELY IS THE EVENT THAT PRODUCED IT.** The
columns are being used for exactly what they are named for. A row is traceable
back to the stream through an index that already exists, and idempotency falls
out of that rather than being bolted on.

A schema change avoided **by using the schema correctly** - which is the same
lesson as the table-ahead-of-the-work entry above, one level down: read what is
there before adding to it.

**For the next projector against a table with no `event_id`:** look for a
`source`-shaped pair first. If one exists, it is almost certainly meant for this.

---

## THE RESOLUTION CHECK'S LIMIT - **a green `assertInvariants()` does not mean the catalogue is correctly filed**

Recorded beside the check because a green run reads stronger than it is.

I filed `employee.hired` in `NOT_NOTIFIED`, which records dropped
**notifications**, when what had happened was that the **event** lost its only
consumer. **Every invariant passed.** The entry was well-formed, its consumer
names resolved, its shape was right - it was simply filed against a decision
nobody took.

**The check asks whether a declared consumer RESOLVES. It does not ask whether an
entry is in the RIGHT LIST.** Those are different questions and the first one
being green says nothing about the second.

### TWO CHEAP RULES ADDED, ONE JUDGEMENT LEFT UNMECHANISED

    DOUBLE-FILED   an event in BOTH SHIPPED and NOT_SHIPPED
    UNLINKED PAIR  an event deferred as an EVENT and separately dropped as a
                   NOTIFICATION, where the two entries do not reference each
                   other. Legal - readiness_gate.changed is exactly this - but
                   silence would let an accidental version look identical to the
                   deliberate one.

Both carry a known-negative: the overlap detector sees 0 real overlaps and 1 on a
synthetic one, so it can discriminate.

**NOT mechanised, and stated instead:** whether a `NOT_NOTIFIED` entry describes a
notification decision or an event decision. That is a judgement about what a
sentence means, and no cheap invariant reaches it. **It cannot be read off a green
run, so it is written down here.**

---

## X-07 DECISIONS - **two gates changed, one dropped, one reconciled**

> ### READ THIS FIRST: **DROPPING A GATE DOES NOT DROP A FEATURE.**
>
> A gate is a READINESS GAUGE. It reports whether a tenant's data is complete
> enough for a capability to be worth switching on. **The capability underneath is
> untouched by anything on this page.** Removing a broken thermometer does not
> turn off the heating.
>
> Nothing below removes, disables, or defers a feature. One gauge was rescaled
> (`jobrole_definition`), one was removed because it could not measure anything
> (`task_competency_link`), and one was confirmed accurate (`task_hygiene`).
> **A reader a year from now will not know this unless it is written here.**


### `jobrole_definition` - **PERCENTAGE DROPPED, COUNT ADOPTED. SEC 4 SUPERSEDED.**

Sec 4 gave it **70% enable / 55% disable**. Those thresholds are **SUPERSEDED, not
silently replaced**, and the reason is recorded so nobody restores them:

**There is no denominator.** `s_user_jobrole` is the tenant's job-role LIBRARY,
not an assignment table - it has no `user_id` at all. No "expected number of job
roles" exists for an organisation, so 70% OF WHAT has no answer. **Inventing a
population would be this product deciding a customer's standard for them.**

    SUPERSEDED   enable >= 70%   disable < 55%
    IN FORCE     enable >= 10 roles defined   disable < 5   unit = count
                 tenant-configurable: a 12-person company and a hospital group
                 are not the same organisation

**THE GATE WORKS AND ALWAYS DID. ONLY ITS MEASUREMENT CHANGED** - from a
percentage with no denominator to a count. Measured today, and every tenant is
already past the threshold:

    tenant 3   347 roles      tenant 2   214 roles      tenant 7   120 roles
    enable >= 10                                        all READY

Nothing was blocked by this and nothing becomes blocked by the change.

### `task_competency_link` - **DROPPED. It gets no row, ever.**

`jobrole_task_competency_map` keys on `jobrole_task_id` into `s_jobrole_task`,
which has **no `sub_institute_id`** - a GLOBAL seed library under Q-C1. It cannot
be joined to `task`, the tenant's own work items, at all. The 67% measured this
phase was about LIBRARY tasks.

**THE GATE WAS DROPPED. THE LINKING IS NOT DROPPED.** Both halves, so neither is
mistaken for abandoned work:

| half | table | measured | status |
|---|---|---|---|
| **INSTANCE link — WORKS** | `task.skill_id` | **1,514 of 2,271 = 66.7%** | golden thread 2's actual signal, live today |
| **CATALOGUE link — MISSING** | `jobrole_task_competency_map` | **0 rows** | filled by a CUSTOMER through X-08's importer — an AUTHORING item, not broken code |

**Why the gauge went and the work stayed.** The gate read
`jobrole_task_competency_map` -> `s_jobrole_task`, a GLOBAL seed library with no
`sub_institute_id`. **It would report the SAME NUMBER FOR EVERY TENANT. A gauge
that reads identically for everyone measures nothing** - it cannot distinguish a
ready tenant from an empty one, which is the only thing a gate exists to do.

This is the wrong-population error appearing in a GATE rather than in a
measurement - the same shape as the two zeros, one layer up, where it would have
silently governed several other items.

**L-14 established that the catalogue link must be AUTHORED, not derived**, which
is precisely why 0 rows is the expected state before a customer imports one. If a
gate is ever wanted here it gates on the authored catalogue once one exists -
X-08's territory.

### `task_hygiene` - **RECONCILED IN ONE PASS. Ships enabled.**

3.9% (tenant 3) against a known 8.1% looked like drift. It was not:

    platform-wide tasks              2271     <- the known figure's denominator
    overdue AND status not terminal  2088 = 91.9%  ->  hygiene 8.1%   EXACT MATCH

**Same definition, different population.** The 8.1% is the PLATFORM aggregate;
3.9% is tenant 3's own. Nothing drifted and no definition differs. The comparison
was cross-population - **my own instance of the error, in the reconciliation
rather than in the system.**

Also measured, not assumed: **the `task` table has no `due_date` or `end_date`
column at all.** Only `task_date` can carry a deadline. The harness names that
basis rather than implying it.

---

## ONE UNREACHABLE GUARD REMOVED, ONE REACHABLE GUARD KEPT - **same method, same turn**

`ReadinessGateAcknowledger::acknowledge()` was written with two null checks. One
was deleted minutes later; the other stayed. **The pairing is the record**, because
"remove unreachable branches" and "keep defensive guards" read as contradictory
advice until you see them applied to the same method.

| guard | verdict | why |
|---|---|---|
| `warning_days === null` | **REMOVED** | the column is `NOT NULL DEFAULT 14`. **The data cannot reach it.** G-SEC-28's shape, in code I had written minutes earlier |
| `at_risk_since === null` | **KEPT** | the column IS nullable. A row can reach `at_risk` without the clock ever starting - hand-edited, or a future path that sets state without the timestamp |

**The discriminator is the schema, not intuition.** Both guards looked equally
prudent while writing them; one of them was guarding a state the database
forbids. The check that settles it takes one query, and reading the column
definition is what separates a guard from a comment that happens to compile.

**And the removal did not weaken anything.** The default of 14 lives in the
SCHEMA on purpose - visible in the table, alterable per row by an admin. Moving
it into the class would have been the opposite of a guard: a constant quietly
overriding a value someone configured.

---

## DEMONSTRATING THAT CONFIG DECIDES REQUIRES TWO CONFIGS, NOT ONE PASSING CASE

From X-07c, and reusable wherever a value claims to be configurable.

To show `warning_days` is genuinely read from the row rather than hardcoded, one
successful acknowledgement proves nothing - a constant of 14 would produce it
too. **Hold the input fixed and vary only the configuration:**

    20 days elapsed, warning_days=30  ->  REFUSED
    20 days elapsed, warning_days=7   ->  ACKNOWLEDGED

**A constant in the code could not produce two different answers from the same
elapsed time.** The same shape proved X-07d's role guard - `administrator` 200,
`hr_manager` 200, `employee` 403 - because a route that returned 200 for one role
tested nothing about whether the guard was consulted.

**The general form: a control is only shown to be live when two settings of it
produce two outcomes.** One green is compatible with the control being ignored.

---

## A GUARD YOU ROUTE AROUND IS DECORATION

I wrote `col()` in the X-07b harness specifically so that a guessed column would
yield **HELD** instead of a crash. Then I did not call it on three of the six
metrics, and the run died on `s_user_jobrole.user_id`.

**The rule was not wrong and the design was not wrong. It failed AT THE POINT OF
USE.** Every earlier lesson in this register is about a check that could not see
far enough; this one is about a check that could see perfectly and was not asked.

The distinction matters for how it gets fixed: a check with a blind spot is
improved by widening it, but a guard that is skipped is only fixed by making the
skip impossible or by noticing it. **Writing the guard was the easy half. Routing
every access through it was the half that mattered, and I did the easy half and
believed I had done both.**

Same family as R28 - when a control appears to work, ask why. Here the control did
not appear to work; it simply was not consulted, and nothing distinguishes an
unconsulted guard from an absent one except reading the call sites.

---

## `reporting_coverage` HAS **ZERO** ENFORCEMENT POINTS - **and the reason is the finding**

Measured before building, as the second gate was meant to test whether the
enforcement pattern generalises. It does not - **and what stops it is more
interesting than a missing hook.**

    reporting_manager_id populated : 8 of 401 platform-wide
    gate t1 : blocked 0.00%      gate t3 : blocked 6.56%
    roles that would consume it  : department_head, reporting_manager (both defined)

### THERE IS NOTHING TO GATE, BECAUSE THE FEATURES WERE NEVER TURNED ON

All three would-be consumers already handle the missing reporting line - by **not
offering the capability at all**:

| consumer | how it handles no reporting line |
|---|---|
| `ResolvesCompetencyContext` | `department_head` and `reporting_manager` **DELIBERATELY ABSENT** from the elevated list. *"They return here as team scope, the day reporting-line coverage exists."* |
| `ResolvesLeaveContext` | same, same reason, same words |
| `RecipientResolver` | documents that **there is no employee -> manager edge in this product** |

**So a `reporting_coverage` gate would refuse features that do not exist yet.**
`capability_coverage` was enforceable because gap reporting IS built and DOES
work; this one is not, because everything downstream of it was withheld pending
the very coverage the gate measures.

### THE TWO MECHANISMS DO DISAGREE - **just not in the shape expected**

The worry was a gate refusing where a NULL manager already fails. It is worse and
quieter than that: **the role lists and the gate are TWO EXPRESSIONS OF ONE
DECISION, in two different forms.**

    the gate       measures coverage and reports blocked/at_risk/ready
    the role lists HARDCODE the absence, in a const array, with a comment

One is a gauge that moves with the data. The other is a decision frozen in code
that **nothing will ever re-evaluate** - no measurement makes those roles come
back; a person has to remember the comment and edit the array. **A hardcoded
absence cannot be told from an oversight by anything except a comment, and that
is precisely what the gate was built to replace.**

### WHAT SHOULD HAPPEN, AND WHY NOT NOW

**The convergence point:** when reporting coverage is real, `COMPETENCY_ELEVATED`
and `LEAVE_ELEVATED` should ask the gate rather than hardcode the answer - the
role lists defer to the measurement instead of duplicating its conclusion. That
is one enforcement point serving several features, which is the shared-guard shape
rather than the per-feature check.

**It is not built now** because building it would re-enable two roles' scope on
the strength of a gate reading 6.56%, which is the opposite of what the gate says.
The item is: *when coverage clears, the roles return BY MEASUREMENT.* Filed, not
done.

### WHAT THIS SAYS ABOUT X-15

**The pattern does not generalise on one example.** `capability_coverage` is so
far the only gate with a live consumer, and its enforcement point was easy
precisely because the feature already worked. X-15's trigger says *"more features
enforce gates"* - **it still reads one.**

---

## A HARDCODED PROFILE ID IS A HARDCODED TENANT WEARING A DISGUISE

`user_profile_id` is **per tenant**. Tenant 3's administrator is profile **7**;
tenant 1's is **1**. So `where('user_profile_id', 1)` does not mean "an admin" -
it means "tenant 1's admin", and it reads like the former.

That is what pinned Slice 1's chain check to tenant 1 for the whole phase, and
nobody chose it. Selecting by `role_key` is the discipline the middleware already
uses, and this is **the third time `role_key` has resolved something that looked
like a different problem** - after the rights matrix and `RequireProfile`'s alias
map.

**Queued, not now:** one grep for anything else selecting by a hardcoded profile
id.

---

## ⛔ TENANT 1 STAYS AT 0.00% COVERAGE - **DELIBERATELY. DO NOT "FIX" IT.**

Decided 2026-08-11. Written here because the next person will see a tenant failing
its own readiness gate and reach for the seed script.

**IT IS THE ONLY PLACE WE CAN SEE WHAT A NEW CUSTOMER SEES ON DAY ONE:** a correct
refusal explaining the product is not ready yet, with the reason and the remedy.
Every other tenant is either seeded or empty. Losing this one loses the only view
of the product's first-run experience that is not a guess.

**AND SEEDING IT WOULD BE A DIFFERENT ACT.** Tenant 1 holds original, real-looking
data about people whose records we did not create. Writing invented capability
ratings there is not the same as seeding a tenant built to demonstrate - it would
be manufacturing measurements about real-looking individuals.

**SLICE 1'S DEMO MOVED TO TENANT 3 INSTEAD.** It ran in tenant 1 because that is
where it was first built, not because anyone chose it. Tenant 3 is the demo
tenant - nine logins, the 9-box, the framework, coverage past its gate - so the
chain belongs there. Confirmed green after the move:
`chain: required 3, measured 1, gap 2, survives rename`.

The suite's precondition line still reports `t1=blocked(0.00)` and still says
**THAT IS THE GATE WORKING, NOT A BROKEN CHAIN.** That line is the guard against
someone later reading tenant 1's state as a defect.

**One incidental cause worth keeping:** the check was pinned to tenant 1 by
`user_profile_id = 1`. **Profile ids are PER TENANT** - tenant 3's admin is
profile 7 - so a hardcoded profile id is a hardcoded tenant wearing a disguise.
Selection is now by `role_key`.

### THE SEED'S TWO CHOICES, KEPT

**Ratings at 2-3, not 5.** A seed that rated everyone 5 would clear the gate and
lie about the workforce. **MEASURED IS THE CLAIM, NOT GOOD.**

**The 21 kasba items already in use, not new ones.** Inventing items to satisfy a
gate manufactures the very measurement the gate exists to detect.

**And the gate was cleared by three honest recomputes rather than by setting the
state** - `sustained_periods=3` satisfied for real, `blocked 4.10% -> ready
55.74%`. Tenth instance of the principle, and the cleanest: the system did not
manufacture a claim nobody made, even when the claim would have been convenient
and one UPDATE away.

---

## ENFORCING `capability_coverage` TURNS OFF THE THING PHASE 3 EXISTS TO DEMONSTRATE

**A finding, not a test problem.** The first time a gate has told us something
true about THIS PRODUCT'S OWN STATE rather than a customer's.

    capability_coverage, at the moment enforcement landed:
      11 of 11 tenants BLOCKED
      tenant 3 = 4.10%   every other tenant = 0.00%   threshold 50%

Gap reporting returned 409 platform-wide, and four suite checks failed - including
**Slice 1's capability chain**, which is the thing this phase was built to show
working end to end.

**THE GATE IS BEHAVING EXACTLY AS DESIGNED.** A gap computed from 4% coverage is a
confident-looking number about nothing. Nothing here is a bug. The gate measured
the demo and the demo did not pass.

### WHY THAT IS WORTH SAYING IN THOSE WORDS

Every gate until now was a gauge pointed at a hypothetical customer's data. This
one was pointed at ours, and **a demo tenant that cannot pass its own readiness
gate means anyone opening the nine logins sees a product refusing itself** - with
a correct, well-worded refusal explaining that the product is not ready to be
used. That is worse than a missing feature, because it is the product telling the
truth about itself at the worst possible moment.

### TWO FIXES, NOT ALTERNATIVES

| | what it makes honest | what it is |
|---|---|---|
| **Walkthrough precondition** | the TESTS | the gate declared as a fixture, like seeded users and roles. **NOT an exemption and NOT a lowered threshold** - both were refused unasked, either would be inventing a customer's standard to get a green |
| **Seed tenant 3 past 50%** | the DEMO | 63 users measured, 126 rows, `source='seed_x07_coverage'`, removable in one statement |

**The seed cleared the gate BY MEASUREMENT, not by setting it.** Three recomputes,
`sustained_periods=3` satisfied honestly: `blocked 4.10% -> ready 55.74%`. A
hand-set gate would have been the claim nobody computed - the thing this phase has
refused nine times.

Ratings are seeded at 2-3 on the scale, deliberately. **A seed that rated everyone
5 would clear the gate and lie about the workforce**; "measured" is the claim, not
"good". And the kasba items are the 21 already in use - inventing items to satisfy
a gate would be manufacturing the measurement the gate exists to detect.

### THE RED THAT REMAINS, AND WHY IT IS NOT TUNED AWAY

Slice 1's chain check runs against **tenant 1**, which is still at 0.00% and still
blocked. Its 409 read as a broken capability chain, which it is not. The
precondition check now reports **every tenant the suite exercises**, so the red
says what it is:

    t1=blocked(0.00) t3=ready(55.74) - GAP CHECKS FOR TENANT 1 WILL 409.
    THAT IS THE GATE WORKING, NOT A BROKEN CHAIN.

**Missing that tenant 1 was in scope is what made a working gate look like a
broken chain for one turn.** The lesson is small and repeatable: a precondition
must cover every population the tests touch, not the one you were thinking about.

---

## A PAPER REACTOR WITH A BODY - **the shape that would pass every check while doing nothing**

X-15's size check, and it is worth more than the item it stopped.

A `FeatureGateApplier` built today would have `handles()` and `dispatch()`, would
resolve to a real class, and would **satisfy G-EVT-03's kind-aware invariant.**
It would pass every check in the catalogue. And it would do nothing, because
**nothing reads a gate**: no feature/flag/toggle table exists, and the only
readers of `tenant_readiness_gate` were the three files that write it.

**THE THIRD LAYER'S BLIND SPOT ARRIVED IN THE VERY ITEM THAT WOULD HAVE PROVED
THE CHECK INSUFFICIENT.** G-EVT-03 added a shape test and recorded that a shape
test cannot see behaviour. One item later, the shape test would have certified a
class with a body and no effect. **The check is not wrong; it is exactly as
strong as it was said to be, and this is what that costs.**

### CONTRAST WITH GapRecalculator - **the same size check, a different verdict**

| | GapRecalculator | FeatureGateApplier |
|---|---|---|
| what the check found | no gap table, gaps computed as queries | no flag table, no feature reads a gate |
| **why** | **contradicted by a RECORDED DECISION** - `NOT_SHIPPED` already says gaps are DERIVED, not a state change | **designed deliberately** in §4, with the asymmetry specified |
| what is missing | **nothing.** The work was already done, elsewhere, on purpose | **the CONSUMPTION SIDE.** The producer exists; nobody reads it |
| verdict | **DROPPED** - nothing to wait for | **DEFERRED** - trigger: a feature enforces a gate |

**An absent thing is not one finding.** One absence meant a decision already taken;
the other means half a design built. Only reading why it is absent tells them
apart, and the same instrument produced both.

---

## THE FIRST ENFORCEMENT POINT - **`capability_coverage` -> gap reporting**

A gate has been a gauge nobody read. `CompetencyGapController::show()` now asks
`ReadinessGateEnforcer` and refuses when the answer is no. One enforcer class, so
the next gate does not grow its own.

    state=blocked  value=4.1   -> HTTP 409   refuses
        why    : This needs capability coverage at 50% or above. It is currently 4.1%.
        remedy : Measure capability - 5 of 122 employees have a measurement
    state=at_risk  value=4.1   -> HTTP 200   ALLOWS  (the asymmetry)
    state=ready    value=60    -> HTTP 200   allows
    state=blocked  value=NULL  -> HTTP 200   ALLOWS  (never computed)

**NEVER-COMPUTED DOES NOT BLOCK.** A gate nobody has run has made no claim, and a
feature must not be switched off by the ABSENCE of a measurement - the
unmeasured-as-zero error would otherwise arrive as a disabled product. `NULL` and
`0` stay different all the way down: `0` is a measurement that came out zero.

**at_risk ALLOWS.** Falling below the threshold starts a warning period; only a
human acknowledgement blocks. The enforcer is the third place that rule is now
enforced rather than described.

**THE REFUSAL SAYS WHY AND WHAT WOULD FIX IT.** A silent empty list would leave a
customer unable to tell "no gaps" from "gap reporting is off" - the same error in
a new place.

### ⚠ BLAST RADIUS, MEASURED BEFORE BUILDING

    capability_coverage: 11 of 11 tenants BLOCKED
    tenant 3 = 4.10%, every other tenant = 0.00%, threshold 50%

**Gap reporting now returns 409 for every tenant.** That is the gate working as
designed - a gap computed from 4% coverage is a confident-looking number about
nothing - but it is a live product change across the whole platform, not a
quiet one, and it is recorded here as such rather than discovered later.

---

## THREE LAYERS OF ONE LESSON - **shape declared / name resolves / behaviour performed**

Each check reaches exactly one layer, and a green at one says nothing about the
next. Written out because the three were found in that order, days apart, each
one passing every check that existed before it.

| layer | the check | what it caught | what it could NOT see |
|---|---|---|---|
| **SHAPE DECLARED** | `assertInvariants()` kinds, named-consumer test | a malformed declaration | a consumer that does not exist |
| **NAME RESOLVES** | G-EVT-01's `resolveConsumer()` | **11 declarations naming 6 classes never written** | a class that exists and is the wrong kind of thing |
| **BEHAVIOUR PERFORMED** | G-EVT-03's kind-aware shape test | **`ProficiencyService`: PROJECTOR on 4 events, never invoked by the event path** | whether the method, when called, does the right thing |

**PROFICIENCYSERVICE WAS DECLARED A PROJECTOR ON FOUR EVENTS AND HAS NEVER BEEN
INVOKED BY THE EVENT PATH.** It resolved, so G-EVT-01's check passed it. That
check asks whether a NAME resolves, never whether the thing behind it does the
work.

**AND THE THIRD LAYER IS NOT THE LAST ONE.** The kind-aware test is still a SHAPE
test: it proves a class is the kind of thing that could do the work, not that it
does. **A CONSUMER CAN BE THE RIGHT KIND OF THING AND STILL NOT DO THE WORK.**
Only a write proves the fourth layer, and no invariant in this file reaches it.

## THE ARGUMENT FOR VERIFYING BEFORE A DECISION THAT LOOKS OBVIOUS

**Quote this the next time skipping a probe looks safe.**

The drop of `GapRecalculator` was correct. The reason given for it was not:

> *"ProficiencyService already projects the proficiency a gap is derived from."*

**FALSE.** It projects nothing. The drop survives on a different and stronger
leg - `rollUp()` derives proficiency **on read** from `competency_kasba_rating`,
so nothing is stored anywhere and nothing needs recalculating.

**Right conclusion, very nearly the wrong reason, and the difference surfaced only
because the probe ran before the decision.** Had the drop been recorded first, the
register would now carry a false premise attached to a true verdict - the worst
kind to find later, because the verdict looks like it validates the reasoning.

**R26, sharpest instance.** The kind-aware invariant's first version returned 12
violations naming reactors verified working in earlier turns - and my own
known-negative had printed `NotificationDispatcher project()=no handle()=no`
directly beside those reds. **The counter-evidence was on screen before the test
was written.**

---

## G-EVT-03 - **A CLASS THAT RESOLVES IS NOT THEREBY A CONSUMER** - and GapRecalculator DROPPED

`ProficiencyService` was declared **PROJECTOR on four events**. It has one public
method, `rollUp()`, a query. No `handles()`, no `project()`, no `CONSUMER`, no
ledger entry, no mention of any event type. Its only callers are
`CompetencyGapController` and `NineBoxController` - both READ paths. **Nothing in
the event path has ever invoked it.**

G-EVT-01's check asks whether a declared name RESOLVES. This one resolved.
**RESOLVING IS NOT DOING THE WORK**, and the gap between those two is exactly the
size of a class that exists and is the wrong kind of thing.

Four declarations removed. **No event died** - each keeps a real consumer, so the
named-consumer test still passes.

### THE NEW INVARIANT, AND R26 ON THE WAY TO IT

My first shape test asked for `project()` or `handle()` and returned **12
violations naming reactors verified working in earlier turns**. The real shape is
`handles()` plus `project()` for a projector and `dispatch()` for a reactor - and
**my own known-negative had printed `NotificationDispatcher project()=no
handle()=no` right beside the reds.** I wrote the test before reading what a
reactor exposes. The corrected test is kind-AWARE, which is stronger than the one
I meant to write: a P must project, an R must dispatch.

    violations: 0
    CapabilityEvidenceProjector / TaskStatusProjector  as P  pass
    NotificationDispatcher / CertificateIssuer         as R  pass
    ProficiencyService                                 as P  FAILS  <- the target

### GapRecalculator - **DROPPED, not deferred**

A deferral implies a trigger and there is nothing to wait for. No `%gap%` table
exists; gaps are computed as queries in three places; and `NOT_SHIPPED` already
records *"gaps are DERIVED, not a state change."* Building it would reverse a
decision, not fill a gap.

**A CORRECTION TO MY OWN ARGUMENT.** I first justified the drop by saying
*"ProficiencyService already projects the proficiency a gap is derived from."*
**That premise was false** - it projects nothing. The drop survives on the other
leg, which is stronger: `rollUp()` derives proficiency **on read** from
`competency_kasba_rating`. Nothing is stored anywhere, so nothing needs
recalculating. The right conclusion for very nearly the wrong reason.

### THE TWO-SIDED LESSON, SAME SIZE CHECK

| | what was found | what it meant |
|---|---|---|
| `CapabilityEvidenceProjector` | a table, empty, fully designed | **an empty table is not evidence of an unbuilt design** - build it |
| `GapRecalculator` | no table, beside a recorded decision not to have one | **no table plus a recorded decision is evidence of a design already taken** - do not build it |

Read the columns before believing the row. The same instrument produced opposite
verdicts on the same day, and neither was guessable from the plan text.

---

## A TABLE THAT WAS AHEAD OF THE WORK - **`competency_evidence` was already designed for Q-B3**

First time this phase a table has been ahead of the work rather than behind it.
Recorded because it **changes the sizing of anything that assumed the
confirmation path needed designing.**

    direction         enum('positive','negative','neutral')   NOT NULL
    dismissed_reason, dismissed_by, dismissed_at
    kasba_type        enum(skill, knowledge, ability, attitude, behaviour)
    source_type, source_id   + idx_ce_source
    rows: 0

`direction` NOT NULL plus the `dismissed_*` triple **is Q-B3's confirmation path,
in the schema.** Evidence carries a sign; a human dismisses it with a reason,
recorded and timestamped. Nothing about the confirmation half needed designing -
it needs a UI surface and a writer. The table was empty because nobody wrote the
writer, not because the design was missing.

**The general lesson:** an empty table is not evidence of an unbuilt design.
G-DATA-10 was re-filed as *empty table, not a schema gap*, and this is the same
distinction from the other side - **empty AND fully designed.** Read the columns
before sizing the work.

### AND THE OTHER HALF - **DECLARED REFERENTS, per R24**

    competency_evidence     system-OBSERVED, projected from events    0 rows
    s_competency_evidence   user-UPLOADED artefacts                  13 rows
                            title, link, file_path, evidence_type

**Two tables one prefix apart, holding different concepts.** Neither is a
migration or duplicate of the other. Worse, `s_` elsewhere in this schema means
*tenant-owned canonical* (Q-C1) - and here it does not. **This is the G-DATA-11
shape before it happens: a join between them would succeed and mean nothing.**

Each now carries a comment naming the other, in the code that writes it. The
comment says DO NOT MERGE and do not write to one expecting the other's content,
because the next person meets these two names in an autocomplete list.

---

## G-EVT-02 - **THE CONFIRMATION HALF OF Q-B3** - filed, not started

`CapabilityEvidenceProjector` writes evidence and stops. This is the rest, and it
is deliberately NOT in that class: a projector that changed proficiency would be
taking a decision a human should take. **The sixth instance of the principle -
the system does not manufacture a claim nobody made.**

**Q-B3's rules, attached so the next person does not re-derive them:**

1. **Evidence written IMMEDIATELY on every failure.** Done - the projector does
   this, unconditionally, with no threshold applied at write time.
2. **Manager flagged AT A THRESHOLD.** Not at every event. The flag is a separate
   signal from the evidence.
3. **PROFICIENCY CHANGED ONLY ON EXPLICIT CONFIRMATION.** Never automatically,
   never at the threshold - the threshold raises a flag, a human resolves it.
   `dismissed_reason` / `dismissed_by` / `dismissed_at` are where the resolution
   lands and they already exist.
4. **THRESHOLDS ARE TENANT-CONFIGURABLE.** Four rejections may mean something
   different at one customer than another. A hardcoded number would be this
   product deciding a customer's standard for them.

Needs a UI surface. Sized when its screen is read, not before.

---

## G-EVT-01 - **THE CATALOGUE DECLARES 11 CONSUMERS THAT DO NOT EXIST** - S1 for authority, not for security

Found by X-15's size check, which asked one question - *where is
`FeatureGateApplier`?* - and got the answer *nowhere*.

    declarations in SHIPPED naming an absent class : 11
    distinct absent classes                        : 6

    CapabilityEvidenceProjector   task.rejected, task.reopened,
                                  capability.flag_resolved, certification.issued
    GapRecalculator               assessment.completed, course.completed,
                                  employee.role_assigned
    OnboardingLauncher            employee.hired
    AccessRevoker                 employee.offboarded
    TaskReassigner                employee.offboarded
    FeatureGateApplier            readiness_gate.changed

### WHY THIS IS WORSE THAN SIX MISSING FILES

`EventCatalogue::SHIPPED` is **the authority on what exists**. Every other
statement in this phase about what the event store does is read off it. It has
been asserting the existence of six classes that were never written, and
`assertInvariants()` never noticed because it validates the SHAPE of the
declarations - projector/reactor kinds, notification rules - and never once asked
whether the names resolve. **A PAPER REACTOR PASSES EVERY EXISTING CHECK.**

### IT DID NOT JUST SIT THERE. IT DECIDED SOMETHING.

X-06 removed `NotificationDispatcher` from `readiness_gate.changed` with this
reason, still in the file:

> *"FeatureGateApplier already does the only thing anyone wanted done. Nobody acts
> on being told."*

And `NOT_SHIPPED` records the notification as **DROPPED, not deferred**, because
*"there is nothing to wait for."* **Both rest on a class that does not exist.**
The second clause - nobody acts on being told - may well still hold, but it was
not the argument made. The argument made was that something else already handles
it. **THE DECISION IS NOT WRONG YET; IT IS UNSUPPORTED, WHICH IS A DIFFERENT
THING AND HAS TO BE RE-TAKEN RATHER THAN RE-ASSERTED.**

### R26 EARNED ITS KEEP ON THE WAY

The check's first red said **15**. Four were `ProficiencyService`, which is real
and lives in `App\Services\Competency\`, not beside the catalogue. The check had
hardcoded one namespace. **The first red was partly the check** - so the resolver
now finds a class anywhere under `app/`, and carries a known-negative in both
directions INCLUDING a real class that is NOT beside the catalogue, which is the
exact case the first version got wrong.

11 survived that correction, against a scan of the whole tree.

### WHAT THIS DOES NOT SAY

It does not say the event store is broken. Events still record, project, and
replay; the shipped reactors that DO exist were each verified when they landed.
It says the catalogue's SHIPPED list cannot currently be quoted, and this phase
has quoted it repeatedly.

---

## ⚠ RETRACTION - **"EMPLOYEE REFUSAL VERIFIED IN A REAL BROWSER" WAS WRONG**

Reported as verified on 2026-08-11. It was not. Filed on its own rather than as a
correction inside X-07d, because the failure is about the instrument and outlives
the item.

**What happened.** The screen had a doubled `api/` prefix -
`api/api/readiness/gates` - which 404s. The harness counted **error blocks**, so a
404 and a 403 were the same observation. It saw an error block on the employee's
screen and reported **"employee is refused" - a PASS.**

**THE ROLE GUARD WOULD HAVE BEEN CERTIFIED ON THE STRENGTH OF A TYPO.** The page
was broken for everyone; the employee's refusal was indistinguishable from the
admin's breakage, and only the admin's was reported as a failure.

**Third instance of the undifferentiated-signal mistake**, after O-03's catch and
X-07d's login - **and this one was in the instrument written to fix the first
two.** The rule caught its own author.

**The fix.** The refusal is now matched as a SPECIFIC SENTENCE (`Admin and HR
only`). Anything else is `broken`, and **broken never passes for anyone**. After
the fix: admin `rendered:5` three times, employee `refused` three times, PASS 9
FAIL 0 UNSTABLE 0.

### KEEP THE CAUSES STRAIGHT - **the platform boundary had NO PART in this**

Two true facts that would be easy to merge into one false one:

| | |
|---|---|
| **The boundary is real and is now written down** | `artisan serve` is single-threaded; `PHP_CLI_SERVER_WORKERS` is a POSIX fork feature measured at **4.5 vs 4.4 here - it does nothing**. Every screen item inherits it. It is G-UI-02's cause |
| **It did not cause this screen's trouble** | With the URL corrected, three runs agreed perfectly. **UNSTABLE never fired.** The instability was a 404 racing the loading state |

**Nobody should later read the boundary as the explanation for X-07d.** The
boundary cost a turn because it was unwritten - the harness comment said
*"PHP_CLI_SERVER_WORKERS is not optional"* and the measurement said *"it does
nothing here"*, and the two notes lived apart. That is what it cost. It did not
break the screen; a typo did, and a blunt instrument hid it.

---

## AN UNDIFFERENTIATED ERROR MESSAGE CANNOT DISTINGUISH ITS OWN CAUSES

**Two investigations sent the wrong way this phase, same family, different layer.**

**O-03, at the CATCH.** `saveCredentials` returned *"Failed to save Google Sheet
credentials."* for a tenant refusal AND for a Google outage. One string, two
causes, and the route could not be measured at all until the guard was lifted out
of the swallowing catch.

**X-07d, at the LOGIN.** `GET /login?type=API` returned *"Invalid User Id And
Password"* for a real tenant-1 administrator AND for a seeded tenant-3 account.
Two populations failing identically read as one systemic cause - the request
shape - and it was two different wrong passwords. **The reasoning that two
populations failing alike points at something structural is normally right; it
fails exactly when the error string is too coarse to separate them.**

**THE TEST IS NOT "IS THE MESSAGE ACCURATE" BUT "CAN TWO DIFFERENT CAUSES PRODUCE
IT".** Both messages above were accurate. Both were useless. An accurate message
that covers two causes is indistinguishable from a wrong one when you are trying
to tell those causes apart.

**What to do instead, both times:** stop reading the message and find a signal
that differs. O-03 used REACHABILITY (an own-tenant call reaches a 422 that a
cross-tenant call never does) and STATE (a catch can rewrite a message, it cannot
un-write a row). X-07d used a direct call with a known-good credential. **Neither
answer came from the string.**

---

## A GUARD INSIDE A TRY WHOSE CATCH REWRITES EVERYTHING RETURNS THE SAME BYTES FOR A REFUSAL AND AN OUTAGE

Found in O-03, filed on its own because the shape is general and the item is not.

`saveCredentials` and `testConnection` had the tenant guard as the FIRST statement
inside a try whose single catch turned every failure into one message:

    caller 3 asks tenant 6  ->  500  "Failed to save Google Sheet credentials."
    caller 3 asks tenant 3  ->  500  "Failed to save Google Sheet credentials."

**The guard was working.** It threw on the mismatch exactly as designed. The catch
then rewrote the throw into the same 500 a Google outage produces.

### TWO CONSEQUENCES, AND THE SECOND IS THE ONE NOBODY LOOKS FOR

1. **The caller is told the wrong thing.** "Server error" for what is a refusal.
   A client retries a 500. It does not retry a 403.
2. **THE ROUTE CANNOT BE MEASURED.** A cross-tenant probe returned byte-identical
   output to the own-tenant probe. Not a wrong answer - NO answer. Any sweep over
   this route reports whatever its author expected, in either direction, and the
   green and the red are equally unearned.

The second is why this is a defect and not a cosmetic issue. **A guard you cannot
observe is indistinguishable from an absent one, and it fails in the safe
direction only for as long as nobody edits it.**

### THE FIX ADDS NO GUARD

The guard moved into its own try ahead of the work, matching `credentialStatus`
and `downloadTemplate`, which already answered 403. Nothing was added. **A catch
was stopped from hiding what was already there.**

    BEFORE  caller 3 asks 6 -> 500 Failed to save Google Sheet credentials.
    AFTER   caller 3 asks 6 -> 403 Invalid sub institute access.
    own-tenant path unchanged: 422 Google Sheet ID or URL is required.

### HOW IT WAS PROVEN WHILE THE MESSAGE STILL LIED

The message was useless, so neither instrument used it.

- **REACHABILITY.** With no `google_sheet_id`, an own-tenant call reaches the 422
  further down; a cross-tenant call never does. The guard's position is proven by
  what the caller can and cannot get to, not by what it is told.
- **STATE.** A full row snapshot of `institute_google_credentials`, identical
  before and after. **A CATCH CAN REWRITE A MESSAGE; IT CANNOT UN-WRITE A ROW.**

Both carry their known-negative (R29): the reachability check shows the 422 IS
reachable on the own-tenant path, so its absence cross-tenant means something; the
snapshot is compared against a mutated copy of itself to show it can see a change
at all. The mutation is done on the STRING, not the table - writing to the shared
database to test the test is how the next finding gets made.

### THE SWEEP - **CANDIDATES, NOT FINDINGS**

How many other guards sit inside a try with a catch-all that discards the
exception message?

    candidates across app/Http/Controllers : 1
    AnalyzeJDController.php  (47 statements in the try, catch discards the message)

**One, and it is one of the 51.** It cannot be read or fixed under G-BLOCK-01, so
it is recorded and waits. Its known-negative is the same file O-03 fixed: the
sweep no longer names `saveCredentials` or `testConnection`, so it discriminates
on the thing that actually changed.

**This was invisible until measured.** It is not visible in review - the code
reads as correct, because it IS correct. Only the response tells you, and the
response is what the catch overwrote.

---

## TWO ZEROS, ONE OF THEM EVIDENCE - a worked pair

Filed as a method result, not under any item. Both numbers came up in the same
turn, both were zero, both looked like clearance. One was. Recorded here because
the next person will meet this shape again and the two are not distinguishable
by the number.

### ZERO #1 - a census. **REAL EVIDENCE.**

    accounts with a NULL or zero sub_institute_id : 0 of 401

Every row of a populated table was examined. The zero is the ANSWER to the
question. There is no account the count could have missed, because the count
covered the population. This is what justified deleting five request-tenant
fallbacks: they compensated for a condition that provably does not occur.

### ZERO #2 - an absence in a log. **NOT EVIDENCE.**

    "table_data" mentions in the log   : 0
    "anonymous read" entries           : 0
    log range                          : 2026-01-28 -> now (~6.5 months)

This looked identical and is not. The population here is not "the callers of
table_data" - it is "the callers who happened to exercise the path during a
window in which the product has no customers". A zero over a period nobody used
the system measures the period, not the code. **An absence of use is not an
absence of callers.**

### THE THIRD THING, WITHOUT WHICH ZERO #2 IS MEANINGLESS IN A FURTHER WAY

    local.INFO entries in the same log : 97

That is the logger's KNOWN-POSITIVE. Without it there are two live explanations
for the zero and no way to choose: nobody called the path, OR logging never
reaches this file at all. The 97 eliminates the second. So the zero is a REAL
ABSENCE - it is just an absence of the wrong thing.

Three states, not two, and only the third is worth arguing about:

| | what the zero means |
|---|---|
| broken instrument | nothing - the instrument cannot see |
| real absence, wrong population | the window was quiet |
| census of the population | the condition does not exist |

### WHY THIS IS R6, NOT A NEW RULE

R6 says a pattern produces candidates and only a measurement produces a finding.
Zero #2 IS a measurement - it just measures a different population than the one
the claim is about. **R6's sharper form: a measurement of the WRONG POPULATION
is a pattern wearing a number.** The number does not upgrade it. What would
upgrade it is measuring the right population: the routes, not the logs.

### WHAT IT COST TO GET THIS RIGHT

Nothing, and that is the point. Zero #2 would have cleared
`AJAXController::tableDataRequestedTenant` for deletion, the suite would have
gone green, and an endpoint with anonymous callers would have lost the only
tenant source it has. **The green would have been bought with a regression.**

---

## G-MIG-01 - RETIRE THE ANONYMOUS `table_data` CALLERS - **OPEN, NOT BLOCKING**

`AJAXController::tableDataRequestedTenant` reads the tenant from the request.
It is the last offender the private-helper assertion names, and it **STAYS**.

**Why it stays.** `/api/table_data` has anonymous callers. With no authenticated
identity there is no resolved tenant to thread in, so the request read is the
only source of a tenant the endpoint has. Deleting it does not fix a leak; it
breaks a working path. Its removal belongs to the migration that retires the
anonymous callers, not to G-SEC-28, which removed fallbacks that compensated for
a measured-impossible condition.

**Evidence that WOULD justify removal - and the evidence that would not.**

- ✅ **A ROUTE-LEVEL AUDIT OF WHO ACTUALLY CALLS `table_data`.** Enumerate the
  callers from the routes and the frontend, not from behaviour. Every caller
  either authenticates or is migrated to an endpoint that does. When the last
  anonymous caller is gone, the read has no reason to exist and goes with it.
- ❌ **NOT A LOG COUNT.** 0 mentions in 6.5 months was already measured and
  already rejected - see the two-zeros pair above. Recorded here so the next
  person does not repeat the reasoning and reach the opposite conclusion.

**The suite stays red until this lands**, and the check carries a note saying so.
It goes green by the endpoint changing, never by the check changing.

---

## O-04 - BLOCKED

Three report-route leaks, subset of **G-SEC-11 (S1)**. **All seven `Reports/`
controllers carry foreign uncommitted work:**

```
DepartmentDistributionController      HiringAnalyticsController
DepartmentSizeController              KpiController
EmployeeDirectoryAnalytics/           OrganizationGrowthController
  EmployeeDirectoryAnalyticsController   <- NAMED IN THE C23 WORKLIST AS LEAKING
EmployeeLifecycleController
```

**G-SEC-11's headline, for scale:** a tenant-7 employee calling `GET /api/skills`
with `sub_institute_id=3` receives **297,582 bytes of another organisation's skill
library** against **84,363 bytes** of their own. 48 routes across 30 controllers
differ by tenant, and **48 is a floor** - 454 GET routes were untestable and 864
write routes are untested.

## THE FULL COST OF THE BLOCK - every queue item touching the 51

| Item | Blocked by | Severity |
|---|---|---|
| **O-04** three report-route leaks | all 7 `Reports/` controllers | **S1 (G-SEC-11)** |
| **O-05** read `HrmsController` (31 routes) | `HRMS/` × 3 | S2 |
| **L-11's last 4 sites** | `CompetencyDashboardController` | S2 (G-DATA-06) |
| **TL-04** the two OnboardingTaskControllers | `Api/Onboarding/` | S3 |
| **S-04** 37 guard candidates hand-verified | spans `Api/` × 23, `talent/` × 5 | **S1 candidates** |
| **S-03** remaining leaks in data-class order | same span | **S1** |

**Two S1 items and one S1 candidate set.** The block is not one item wide.

**The 51 span:** `Api/` 23 · `Reports/` 7 · `talent/` 5 · `routes/` 5 · `HRMS/` 3
· `user/` 2 · six singletons · `bootstrap/app.php`.

## HOW IT SURFACED

**R18f(v)'s stronger form, one turn after being recorded.** I went to take O-04,
checked `git status` on the target files first because the rule now says to, and
found all seven held. **Without that check I would have edited foreign work and
found out afterwards.**

## REPORTING CHANGE

**No longer reported as "the 51 files: untouched".** From here it is **BLOCKED
WORK WITH ITS COST** - the second reads as what it is. Triz is resolving the 51;
nothing changes until he says so.

---

# ⭐ THE SYSTEM DOES NOT MANUFACTURE A CLAIM NOBODY MADE - one principle, four instances

Each was reasoned separately and they are the same rule. **Any item that would
fill a gap by inference is checked against this.**

| # | Instance | The claim that would have been manufactured |
|---|---|---|
| 1 | **Unmeasured is not a box** (9-box) | placing Divya would turn *"we have not measured this person"* into *"this person is weak"* |
| 2 | **Nullable on purpose** (TL-02a) | forcing an author to pick a competency for *"deliver the Q3 migration"* invents a development intent |
| 3 | **Recommends, does not assign** (X-13) | writing a suggestion into `lms_assignments` turns *"you might want this"* into *"you must do this"* |
| 4 | **Previews, does not import** (X-08a) | *"these 3,347 roles are yours now"* is a claim, and the customer makes it |

**Instances 1 and 2 are the same principle at opposite ends** - one refuses to
invent an output, the other refuses to force an input. **3 and 4 both refuse to
convert a suggestion into a decision.**

> **The tell: any time the system would be more useful if it just picked
> something, ask who is making the claim. If the answer is "the code", it is
> manufacturing one.**

---

# SHAPE-02 - **A SCHEMA ENCODING AN ASSUMPTION ABOUT PROVENANCE** - two instances

| Instance | The assumption | How it surfaced |
|---|---|---|
| `task_id = 0` sentinel | every row came from a task, so 0 could mean "none" | a second source arrived |
| **`suggested_course.task_id` NOT NULL** | **every suggestion came from a task** | **X-13 recommended from an expiring certification, which has none** |

**Both are a schema saying something it was not designed to say, and both surfaced
only when a SECOND SOURCE ARRIVED.** A column that is NOT NULL because there has
only ever been one source is a claim about provenance disguised as a constraint.

**Two instances make it worth checking for elsewhere** - specifically, NOT NULL
foreign keys on tables that acquired a second writer this phase. **Not swept; when
the queue has room.**

---

# G-TASK-04 - **AN OVERRIDE CANNOT BE DEFINED AGAINST AN EMPTY AUTHORITY** - the pattern for every later override

Q-E1 decided: **the catalogue wins; the instance is a confidence-tagged override.**
L-14 is the first item to implement it, and it cannot be implemented as written.

| Side | State |
|---|---:|
| **INSTANCE** `task.skill_id` | **1,514 of 2,271 (66.7%)**, of which **1,512 resolve by key in-tenant** |
| **CATALOGUE** `s_user_jobrole_task` | 85,663 rows |
| **CATALOGUE competency link** `jobrole_task_competency_map` | **0 rows** |

**The catalogue has nothing to win with.** "Catalogue wins" against an empty
catalogue would silently discard 1,514 observations a human made at task creation.

> ### AN OVERRIDE CANNOT BE DEFINED AGAINST AN EMPTY AUTHORITY.
> ### The confidence tag has to distinguish TWO POPULATED CLAIMS, not one claim
> ### and a blank.
>
> **This is the pattern for every later override in this product**, and it is
> **G-DATA-10's shape a third time**: the bridge exists, the rule is decided, the
> table is empty.

### ⚠ THE DERIVATION WAS TRIED AND IT CANNOT RUN. L-14 IS PARKED.

**The framing was wrong, not the derivation.** Measured before writing anything:

| Hop | State |
|---|---|
| instance -> catalogue | **NO KEY EXISTS.** `s_user_jobrole_task` shares only `id`, `task_type`, tenant and audit stamps with `task`. The only join is `task.task_title = catalogue.task` |
| ...and that text join | **10,570 join rows; 1,989 distinct task instances match - against 1,514 that have a `skill_id` at all** |
| skill -> competency | **0.** X-20's competencies cover skills referenced in **tenant 1**; the tasks carrying `skill_id` are in **tenant 7 (1,000)** and **tenant 3 (514)** |

**1,989 > 1,514 means the text join matches tasks that have no `skill_id`**, and each
matching instance fans out to ~7 catalogue rows.

> ### A TASK TITLE MAPPING TO MANY (jobrole, task) ENTRIES IS CORRECT FOR A
> ### CATALOGUE AND USELESS AS A KEY.
>
> **That is not a gap to populate around. It is the absence of a relationship.**

**THE CATALOGUE LINK MUST BE AUTHORED, NOT DERIVED** - which makes it Q-C1's
seed-library import's work, not L-14's.

**L-14 RE-FILED:** not "wire the override rule", not "derive the catalogue" -
**blocked on a catalogue nobody has authored.**

**REFUSED, and recorded so it is not proposed again:** extending X-20's rule to
tenants 3 and 7 to make the second hop work. That is **X-20 applied to a different
population for the convenience of this item** - the same trap in a new tenant, and
X-20's whole point was that a wholesale migration imports the conflation.

**SCHEDULED, NOT PARKED:** a real key from `task` to `s_user_jobrole_task` would
make the first hop sound. **Trigger: a catalogue exists to key into.** It helps
only once one does.

---

# ❓ Q-E1-Q1 - **DOES A TASK CATALOGUE EVER EXIST?** - a question against Q-E1, not a decision

**Q-E1 decided catalogue-wins and assumed the catalogue would eventually exist. For
tasks it may never.**

If a hand-picked instance value is **the only claim there ever is**, then the
confidence tag is not distinguishing *catalogue* from *override*. It is marking a
single claim as **HUMAN-PICKED RATHER THAN DERIVED**.

**That is a smaller rule and possibly the correct one.**

**Why it matters beyond tasks:** **G-TASK-04** says an override cannot be defined
against an empty authority. The answer here may be that **for tasks there is no
authority at all** - which changes what the tag means *everywhere it is used*, not
just here.

**ANSWERED 2026-08-11: tasks are the exception; the tag keeps its original
meaning.** competency<->course has **56 catalogue / 48 instance**;
competency<->jobrole has **23 / 295**. Two of three overrides have both sides
populated, so the smaller "human-picked rather than derived" reading applies only
where no authority exists.

> ### ⚠ RE-OPEN THIS THE FIRST TIME A REAL CUSTOMER AUTHORS A CATALOGUE.
>
> **Every row on both populated sides was created by this phase's own work** -
> X-19, X-20 and the tenant-3 seed. **No customer has authored a catalogue.** The
> tag's meaning is therefore settled **BY CONSTRUCTION RATHER THAN BY USE**, and a
> real customer's library could contradict it.
>
> The caveat travels WITH the answer, not behind it.

---

**SUPERSEDED RESOLUTION (2026-08-11, tried and refuted):** derive the catalogue's first content FROM the
instances - each of the 1,512 resolving values is a `(job role task, competency)`
observation made by a person. Marked **derived-from-instances**, not authored.
**Then both sides are populated and the override rule means something.**
Conflicting pairs are **HELD**, not picked between. Bulk write: counts first.

---

# G-LIB-09 - **AN IMPACT COUNT BY TITLE OVER-REPORTS BY 6x** - **S2** - measured before L-06 was written

L-06 shows the user what a deletion would break. **The count is not a display
detail; computing it the obvious way would lie to them.**

| How the dependants of a skill are counted | Rows |
|---|---:|
| `s_user_skill_jobrole` actual rows | **79,295** |
| joined by **KEY** + tenant | **79,294** |
| joined by **TITLE** + tenant | **80,531** (**+1,237, +1.6%**) |
| joined by **TITLE**, no tenant condition | **479,623** (**+399,092 - SIX TIMES**) |

**The +1.6% is duplicate skill titles inside one tenant fanning out.** The 6x is
what happens if the tenant condition is omitted - the same omission L-11 chased
through join clauses, here reached through a feature rather than a sweep.

> **A user told "deleting this affects 479,623 records" would never delete
> anything.** A user told "1,237 more than are really there" would not notice.
> **One is obviously wrong and the other is quietly wrong, and the quiet one is
> worse.**

> ### A user told *"deleting this affects 479,623 records"* would never delete
> ### anything. A user told *"1,237 more than are really there"* would not notice.
> ### **ONE IS OBVIOUSLY WRONG, THE OTHER IS QUIETLY WRONG, AND THE QUIET ONE IS
> ### WORSE.**

### HOW IT ARRIVED, AND WHY THAT MATTERS MORE THAN THE NUMBER

**A FEATURE FOUND IT. THE SWEEPS DID NOT.**

L-11's sweep found this class **once** - and then produced **29 candidates and
zero verified findings**. Building against real data has now found it **twice**:
G-DASH-01, and this. Both times the mechanism was identical: **two ways of asking
one question, disagreeing.**

> **The sweeps looked for the SHAPE of the defect in the code. The features hit
> the CONSEQUENCE of it in the data.** A sweep can only find what its pattern can
> express; a feature that needs a correct number finds whatever is making the
> number wrong. **That is the practical form of "only a measurement produces a
> finding" (R6) - and it says where to look, not just what to trust.**

**This is G-DASH-01's shape a second time**, and it arrived the same way: two ways
of asking the same question disagreeing. **L-06's count is computed BY KEY**, and
the difference is why the item is worth building rather than a nicety.

**`competency_kasba_item` holds 200 key-based references** and needs no such care -
it was built with the key from the start.

---

# G-UI-02 - **HARNESS DEFECT, NOT A PRODUCT DEFECT** - RE-FILED 2026-08-11

> ## The employee sidebar was never broken. My test server was.
>
> ### ⚠ MECHANISM UNEXPLAINED - AND THE SYMPTOM IS NON-DETERMINISTIC.
>
> **Three runs, three different sidebar item counts on the same build and the same
> data: 1, then 6, then 2.** That is a RACE, not a configuration.
>
> **Both candidate mechanisms are eliminated by observation:**
>
> | Candidate | Test | Result |
> |---|---|---|
> | a leftover server winning the port | `tasklist` before start | **0 php processes** - no squatter |
> | request starvation under load | sidebar after **1** login vs after **10** | **2 and 2** - load makes no difference |
>
> `PHP_CLI_SERVER_WORKERS` is a POSIX fork feature and **does nothing on Windows**
> (measured: ratio 4.5 with it set, 4.4 without). So the run where all five modules
> rendered was not explained by anything I set.
>
> **WHAT IS SETTLED:** the sidebar HAS rendered all five modules on this build, so
> no product defect prevents it. **WHAT IS NOT:** why it usually does not.
>
> **The guard now reports the concurrency ratio on every X-21 run**, so the next
> occurrence carries its own diagnostic rather than needing another investigation.
> **That is worth more than a fourth pass now** - a race diagnosed from three
> conflicting observations would be a guess dressed as a finding.


`php artisan serve` is **single-threaded**. The app fires several requests during
load; one in-flight request starves the rest, and the sidebar's was the one left
hanging - **issued into a server that never answered it.**

**PROVEN.** With `PHP_CLI_SERVER_WORKERS=8`:

```
sidebar response status: 200
sidebar items: Main Dashboard, Organizational Management,
               Competency Management, LMS, HRIT Management, Task Management
```

All five modules. The product was correct throughout.

### THE ELIMINATION HISTORY STAYS, BECAUSE IT WAS REAL WORK

Five eliminations, **every one correct**, and every one against the wrong layer:
the render condition (`filteredNav = modules`, no filter) · the request shape ·
the tree builder · the context guard (`ready=true`, token/tenant/user all present)
· the double-mount (one hook instance, not two).

> ### I WAS ANSWERING "WHICH LINE" WHEN THE QUESTION WAS "WHICH SERVER".
>
> Six turns. The instrument was wrong, not the reasoning - which is why five
> correct eliminations never converged. **Eliminations that are individually sound
> and collectively fruitless are evidence about the QUESTION.**

### WHAT IT COST, AND THE RULE IT EARNS

**X-21 had a false-negative mode and had been in it.** Everything it reported about
navigation described my server, not the product.

> ## A HARNESS MUST NOT BE ABLE TO ENTER ITS FALSE-NEGATIVE MODE SILENTLY.
>
> **R25's shape one level in:** an untested assumption about my own TOOLING rather
> than my own capability. I built X-21 to remove a manual step and never asked what
> it would do if its own dependencies misbehaved. **A harness that fails silently
> in one mode is worse than no harness, because everything it PASSES becomes
> uncertain too.**

**Severity withdrawn.** It was never S2 against the product. It was an S1 against
the harness, and it is being fixed as one.

---

# (superseded heading kept below for the original filing)

## G-UI-02, AS ORIGINALLY FILED - **S2**

The nav API returns **five modules** for a seeded employee - correct labels,
correct `access_link`s, rights present. **The sidebar renders none of them.**

```
module button matching /competency/i : 0
"My Capability" item                 : 0
sidebar text nodes: G2G | GapstoGrowth | HRMS Platform | Main Dashboard
```

**An employee cannot navigate anywhere but the dashboard, on ANY module.**
G-UI-01 was one symptom of this, not a separate defect.

### THE CONSEQUENCE, PLAINLY

**The nine seeded logins would show an employee who appears to have no product at
all.** Six of the fourteen seeded people are employees. Anyone opening those
credentials sees a dashboard and nothing else, and would reasonably conclude the
capability work was never built.

**That is what a manual walkthrough would have found. X-21 found it instead** - and
found it by failing to arrive, which no source assertion or API check can do.

### ROLE-DEPENDENT

The administrator's client-side navigation works (`/dashboard` ->
`/module/organizational-management/organization-setup/organization-profile`,
verified). The employee's does not.

### WHAT IS RULED OUT

| Ruled out | How |
|---|---|
| rights | `can_view=1`, row present, menu returned by the API for this profile |
| menu row shape | derived field-by-field from working sibling 156 |
| content-map entry | `submenuId` present; `accessLink` **byte-identical** to the stored value (len 62, hex tails match) |
| the `@/domain/*` alias, the container file | both resolve; `tsc` unchanged |
| **the nav-query race** | **REFUTED.** `GtgAppShell`'s effect returns early on `modules.length <= 1` AND lists `modules` as a dependency, so it neither runs early nor fails to re-run. The race was anticipated and guarded, with a comment saying so |

### ONE OBSERVATION, NOT A HYPOTHESIS

When `parseRoutePath` returns null there is **no else branch** - `active` stays at
`DEFAULT_ACTIVE` permanently. That is consistent with the dashboard fallback but
does not explain why `keyByPath` lacks the path, nor why the sidebar renders no
modules. **Recorded as an observation because it has not been tested.**

**Not a cross-tenant leak and not an unauthenticated write, so it does not jump
the queue.** The fix belongs here, not to G-UI-01 - whose mount is committed and
inert and starts working the moment this does.

---

# G-UI-01 - **PROBABLY CORRECT ALL ALONG** - RE-FILED 2026-08-11, pending harness confirmation

> **The evidence now points to the mount having been right from the moment it
> landed.** G-UI-02 - the reason the screen could not be reached - was my
> single-threaded test server, not the product.

Menu row 224, its 89 view-only rights rows, the container and the content-map
entry were all verified individually: byte-identical `access_link`, `submenuId`
present, alias resolving, `tsc` clean. **Every part checked out and the whole
appeared broken**, which is exactly what a starved navigation request produces.

**NOT YET CONFIRMED**, and stated as such rather than claimed: the harness fix has
not completed, so the screen has not been opened in a browser. **The evidence points
one way and the confirmation is outstanding.**

## G-UI-01, AS ORIGINALLY FILED - **S1**

> **Slice 1's entire deliverable - the gap view, the "Not yet assessed" screen,
> the thing this phase is sold on - is unreachable in the running application.**

| Evidence | |
|---|---|
| `CmMyCapability` exported from `cm-my-capability.tsx` | **yes** |
| listed in `components/domain/competency/index.ts` (the barrel) | **NO** |
| listed in `hooks/content-map-m2.ts` (the accessLink -> component route map) | **NO** |
| imported anywhere in the codebase | **NO** |

Its siblings are both mounted, so the mechanism is not in doubt:

```
{ accessLink: '/module/competency-management/competency-library/command-center', component: CmCommandCenter }
{ accessLink: '/module/competency-management/competency-library', submenuId: '34',  component: CmCompetencyLibrary }
```

**There is no such line for CmMyCapability.**

### HOW IT SURVIVED EVERY CHECK UNTIL NOW

**Every source assertion about this component PASSES**, because the component is
correct:

- Tier 2: unmeasured renders "Not yet assessed", no bar, no number - **PASS**
- Tier 2: a level cannot render without its coverage - **PASS**
- Tier 1: the API returns 4 required / 1 met / 2 gap / 1 unmeasured - **PASS**
- `tsc`: clean

**The code is right. The wiring does not exist.** Nothing that reads a file can
see a missing entry in a different file, and no API check can see that the screen
consuming it is unrouted.

### THIS IS WHAT X-21 WAS BUILT FOR, AND IT FOUND IT ON ITS SECOND RUN

The browser could not reach the screen. **That failure to arrive IS the finding** -
the same shape as the dead bell, inverted: the bell was a control with no data
behind it; this is a component with no route in front of it.

> **A source assertion cannot prove a screen is REACHABLE. Only a browser can, and
> only by trying to reach it.**

### RELATED, AND NOW OBVIOUS IN HINDSIGHT

The X-21 item-8 check reported *"walked 1 screen, Not yet assessed not reached"*
and I twice assumed my navigation was wrong. **R26 says a new check's first red is
more likely to be the check than the code - and twice it was. The third time it
was the code.** The rule is a prior, not a verdict.

**FIX: one export in the barrel and one row in `content-map-m2.ts`, against a menu
row whose `accessLink` exists for the tenant.** NOT DONE THIS TURN: it needs the
right `accessLink`/`submenuId`, and guessing one produces a screen that renders
for nobody - which is the bug being fixed.

---

# ⭐⭐ THE METHODOLOGICAL RESULT OF THE PHASE — **A PATTERN PRODUCES CANDIDATES; ONLY A MEASUREMENT PRODUCES A FINDING**

> **This supersedes the softer version of R6** ("candidates are not findings").
> R6 said candidates must be verified. **It did not say what counts as
> verification** — and that gap is where every over-report of this phase lived.

## The number that looked verified and was not

```
29  ->  27  ->  19  ->  13  ->  4  ->  0
raw    minus   minus   minus  minus  minus false
       comments global  key    files positives
                side   joins        FOUND BY READING
```

**THE BOTH-SIDES CHECK TURNED 29 INTO 13 AND FELT LIKE VERIFICATION. IT WAS STILL
PATTERN WORK.** It ran against the schema, it removed a real class of false
positive, it was predicted in advance and it was correct as far as it went — and
it left a count that was still wrong by all of it.

### Three independent flaws, none visible to the others, **all over-reporting in the same direction**

| # | Flaw | Why it was invisible |
|---|---|---|
| 1 | **single-line matching** | a tenant condition on the NEXT line of a multi-line `ON` clause is simply not in the string being matched |
| 2 | **a file-wide alias map when aliases are QUERY-scoped** | `s` bound twice in one file; the map kept the last, so a join against the **global** `s_jobrole` scored as tenant-scoped |
| 3 | **`whereColumn` judged without its sibling `where`** | the tenant filter sits two lines down inside the same `EXISTS`, outside the matched expression |

**That they all erred the same way is the point.** Independent flaws should
scatter. These did not, because each one shares the same root: **a pattern sees
only the text it was pointed at, and every one of them was pointed at too little.**

## Only the finding that was MEASURED survived

**`CompetencyDashboardController` holds** — and it holds on numbers, not on a match:

| Evidence | Value |
|---|---|
| roles resolved by **text** | **4,716** |
| roles resolved by **key** | **4,393** |
| joined rows that were **cross-tenant** | **161,695 of 253,479** |

**That is DATA.** It does not depend on how a line was written, whether a clause
wrapped, or which table an alias meant. **Every pattern-produced candidate died
under reading. The one structural count did not.**

## Third confirmation of the same thing

**Two sweeps produced real findings all phase, and BOTH were structural counts,
not pattern matches:**

| Sweep | Kind | Outcome |
|---|---|---|
| **F-07b link resolution** — % of rows whose text value resolves to a key | measurement | real, and drove a backfill from 0% to 100% |
| **G-DASH-01 / G-DATA-06** — row counts by text vs by key | measurement | real, and is the headline finding |
| L-11's wider class — regex over join clauses | pattern | **29 candidates, 0 findings** |
| G-SEC-12's static check — regex over method bodies | pattern | first version: **9 offenders, 0 real** (8 legitimate subjects, 1 a comment) |
| L-11's count itself | pattern | **7 successive corrections** |

## WHERE TO LOOK, NOT ONLY WHAT TO TRUST

> ### A SWEEP CAN ONLY FIND WHAT ITS PATTERN CAN EXPRESS; A FEATURE THAT NEEDS A
> ### CORRECT NUMBER FINDS WHATEVER IS MAKING THE NUMBER WRONG.

**The evidence, side by side:**

| Approach | Result |
|---|---|
| **L-11's sweep** - regex over join clauses, looking for the SHAPE of the defect | **29 candidates, ZERO verified findings** |
| **G-DASH-01** - a dashboard needing a correct row count | **161,695 of 253,479 joined rows cross-tenant** |
| **G-LIB-09** - a delete dialog needing a correct impact count | **479,623 vs 79,294 - six times reality** |

**The same class, found twice by building and never once by sweeping.**

### AND THE THIRD INSTRUMENT: AN ENUMERATION - WITH ITS OWN LIMIT

T-01's smoke assertion **named five write sites** where my greps had returned JSON
keys and validation rules. **A pattern finds what it can express; an enumeration
finds what is there.**

**And it over-reported by 67%.** Two of the five - `ProjectController` and
`DeadlineExtensionController` - write `task_management_projects` and
`task_deadline_extensions`. The check tested for `table('task')` **anywhere in the
file**, then matched any status update in it.

> ### AN ENUMERATION FINDS WHAT IS THERE - AT THE SCOPE YOU GIVE IT.
>
> Both halves travel together. Without the second, the first becomes an argument
> for trusting enumerations, which is the same error one level on.

**The fix that generalises:**

> ### A KNOWN-POSITIVE PROVES A PATTERN CAN SEE; ONLY A KNOWN-NEGATIVE PROVES IT
> ### CAN DISCRIMINATE.
>
> **R16's sharper form.** The old assertion HAD a known-positive and passed it - it
> could see `->update([... 'status' ...])`. Nothing tested whether it could tell
> `task` from `task_management_projects`. **Third instance of file scope where
> statement scope was needed.**

A sweep hunts the defect's shape **in the code**. A feature hits its consequence
**in the data** - and it cannot be fooled, because a wrong number is wrong
regardless of how the wrongness is written.

**This is the practical half of the conclusion below.** R6 says only a measurement
produces a finding; this says **where the measurements come from**: build the thing
that needs the number right.

## The standing conclusion

> ### A PATTERN PRODUCES CANDIDATES. ONLY A MEASUREMENT PRODUCES A FINDING.
>
> A pattern match may **open** an investigation. It may never **close** one.
> Promotion from candidate to finding requires one of:
>
> 1. **a count that differs** — the same question asked two ways, disagreeing (F-07b, G-DASH-01), or
> 2. **an executed observation** — a request sent, a response seen (G-SEC-24's HTTP 200 with no token), or
> 3. **a human read of the site itself**, with enough surrounding lines to see what the pattern could not.
>
> **A refined pattern is still a pattern.** Narrowing 29 to 13 changes the count,
> not the class of evidence. **The refinement is not the verification.**

**Applied cost of ignoring this:** four join clauses that were already correct were
one command away from being rewritten, in a file carrying no tests, on a shared
remote database.

---

---

---

---

# G-DATA-11 - **TWO COMPETENCY ID SPACES** - **S1** - ✅ **CLOSED 2026-08-11 (X-20)**

> **RESOLVED: option A, and the premise check changed what option A MEANT.**
>
> "Migrate 805 rows" would have imported the conflation into the new model. The
> referenced things are SKILLS - verified by meaning and by structure before a row
> was written - so the migration is **one competency per distinct referenced skill,
> each holding that skill as its single KASBA item.**
>
> **199 competencies, 199 KASBA items, 805 of 805 references re-pointed, 0 held,
> 0 cross-tenant, both skills tables untouched, provenance preserved in
> `legacy_skill_id`.** X-19 then wrote its 48 pairs, 0 held.
>
> **The referent is now DECLARED** - in `02-domain-model.md`, and in the column
> comment of all eight tables carrying `competency_id`. The absence of that
> declaration is what let two meanings grow side by side.



> Found while preparing X-19. **It stops X-19 and it is bigger than X-19.**

`competency_id` appears on five tables. **805 rows carry one. Not one of them
resolves in `competency`.**

| Holder | rows | resolves in `competency` | resolves in `master_skills` / `s_users_skills` |
|---|---:|---:|---:|
| `s_competency_certifications` | 220 | **0%** | **100%** |
| `s_competency_plan_actions` | 377 | **0%** | **100%** |
| `s_competency_development_plans` | 160 | **0%** | **100%** |
| `lms_assignments` | 48 | **0%** | **100%** |
| `jobrole_competency_map` | 0 | - | - |

**`competency` held 0 rows until this seed.** It is the table the CAPABILITY CHAIN
resolves against - `CompetencyGapController` joins
`jobrole_competency_map -> competency`, and `ProficiencyService` reads
`competency_kasba_item`.

### SO THE PRODUCT HAS TWO DISJOINT MEANINGS OF "COMPETENCY"

| | Space A - **the chain** | Space B - **everything else** |
|---|---|---|
| master | `competency` (+ `competency_kasba_item`) | `master_skills` / `s_users_skills` |
| used by | gap view, proficiency roll-up, Slice 1 | plans, plan actions, certifications, assignments |
| rows before this turn | **0** | **805 referencing rows** |

**Slice 1 built the chain on the new, empty space while every row of real
competency data sat in the old one.** Both are internally consistent. Nothing
crosses.

### WHY IT SURFACED ONLY NOW

Both spaces were consistent as long as nothing joined them. **X-19 is the first
item that would have written a single table (`course_competency_map`) from BOTH** -
48 pairs recovered from `lms_assignments` (space B) into a table the gap chain
reads (space A).

> **`course_competency_map.competency_id` HAS NO DECLARED REFERENT.** Writing both
> spaces into it would make the column meaningless, and the corruption would look
> like data.

### X-19 IS HELD IN FULL. NOT PARTIALLY - ENTIRELY.

F-07b discipline says ambiguous rows are held, never guessed. **The ambiguity here
is not in individual rows; it is in what the destination column MEANS.** All 48 are
held.

**This is Triz's decision, and it is a product decision, not a build one:**

1. **Space A wins** - the chain is canonical; plans/certifications/assignments must
   migrate their `competency_id` to `competency` ids. Largest change, one meaning.
2. **Space B wins** - `competency` is abandoned and the chain re-points at
   `s_users_skills`. Discards Slice 1's KASBA model, which space B cannot express.
3. **Both, bridged** - a mapping table between them. **A second path to the same
   answer** - the thing G-DATA-10 was just corrected for proposing.

**The seed took option 1 for tenant 3 only**, because the chain had to work for the
walkthrough: its `course_competency_map` rows are space A. **That is 8 rows in one
tenant and it is not a decision** - it is a demonstration, recorded and removable.

---

---

# G-SEED-01 - **26 OF 27 KASBA ITEMS LANDED AS HOLDING LABELS** - **S3** - a finding about the SEED LIBRARY

The tenant-3 slice defined 10 clinical competencies with 27 KASBA items. **Exactly
1 matched a canonical `s_users_skills` title. 26 did not**, and stayed as HOLDING
labels.

**This is the holding state working exactly as designed** - and it is also a
measurement nobody had:

### ⚠ CORRECTED 2026-08-11 - THE DISTANCE IS **VOCABULARY**, NOT **DIMENSION**

**X-08(a)'s by-dimension breakdown corrects this finding three turns after I filed
it.** The original said most items fail because four of the five KASBA dimensions
are not skills. **True, and incomplete:**

| Dimension | Items | Resolved |
|---|---:|---:|
| **skill** | **8** | **0** |
| knowledge | 7 | 0 |
| behaviour | 5 | 1 |
| ability | 4 | 0 |
| attitude | 3 | 0 |

> ### THE SKILL DIMENSION RESOLVED 0 OF 8 - AND SKILL IS THE DIMENSION THE
> ### LIBRARY EXISTS FOR.
>
> *"Patient triage"* and *"Medication administration"* **are** skills. A 551-skill
> library does not hold them. **The gap is domain vocabulary, not dimensional
> mismatch.**

**G-SEED-01 was right about WHY most items fail and incomplete about WHICH.** The
dimensional argument explains 19 of 27; it does not explain the 8 that should have
matched and did not.

**CONSEQUENCE - A SIXTH REQUIREMENT, AND IT IS LARGER THAN THE OTHER FIVE:**

## X-08(b) — THE PARTIAL-FAILURE DECISION, AND THE RULE THE FIRST RUN EARNED

**ONE TRANSACTION. NOT RESUMABLE.** Decided before the code, because it changes
the shape.

> ### A HALF-IMPORTED FRAMEWORK IS NOT A SMALLER FRAMEWORK.
>
> It is a competency model **with holes the customer cannot see**, and every gap
> view computed against it would be quietly wrong. **Worse than an outright
> failure** - the same family as every silent-wrongness this project has closed.

**THE DRY RUN ALREADY BUYS WHAT RESUMABILITY WOULD.** The customer sees the whole
outcome before committing, so a mid-write failure is a **system fault**, not a
content surprise - and *"nothing happened, here is why"* is the correct response
to a system fault. Resumability would need a durable record of what landed,
written **inside** the operation that might fail: a second thing to get wrong, for
a case a transaction removes.

**WHAT WOULD MAKE THIS WRONG, named so resumability arrives as a decision:** a
framework exceeding what one transaction can hold, or an import that must span a
session. Neither is true at the validated 5,000-row ceiling.

### THE RULE THE FIRST RUN EARNED

The dry run predicted **4 items**; the write created **3**. **The write was
right** - one item already existed on an existing competency, and the dry run
checked for existing COMPETENCIES but not existing ITEMS.

**Two paths that could disagree, on the first run of the write half.** Uncaught,
it would have shipped a preview overstating by 25% on any file touching an
existing competency - **and a customer's trust in the preview is the entire basis
for the one-transaction decision.**

> ### THE WRITE REUSES THE FLAG RATHER THAN RE-DERIVING IT.
> ### A SECOND DERIVATION IS A SECOND CHANCE TO DISAGREE.
>
> **Applies anywhere a preview and an action share a decision.** The check moved
> into the shared path; the write now reads the flag the preview reported.

---

## R18f(v)'s STRONGER FORM — **CHECK `git status` BEFORE EDITING ANYTHING OUTSIDE PATHS YOU CREATED**

R18f(v) says check before a revert. **That is too narrow.** 51 files in this repo
are Milan's uncommitted work and are not to be touched; I ran an edit against a
file in `libraries/` **without checking whether it was one of them.** The
arithmetic afterwards showed it was clean - **but I did not know that when I ran
it, and "it turned out fine" is not the standard.**

**The working-tree assertion tells you AFTERWARDS. It cannot stop the edit.**

---

## ⚠ ONE-LINER: A MODULE-LEVEL NAMING FAILURE, NOT TWO INCIDENTS

`competencyLibraryImport` writes `s_users_skills`. **`CompetencyController::store()`
did the same thing** (G-RBAC-02b). **Twice in one module makes it a pattern**, and
it is the same root that produced the Skill Library rename: **things named
"competency" that operate on skills.**

**Worth one sweep when the queue has room:** what else in the competency module is
named for competency and operates on skills? **Not now.**

---

## X-08(b) DECISION - **BRING-YOUR-OWN IMPORTER FIRST** (Triz, 2026-08-11)

Three shapes were on the table. **(2) bring-your-own, then (3) the enrichment
loop. NOT (1) domain libraries.**

| # | Why |
|---|---|
| **(2) BYO importer** | **The only shape needing NO authoring from anyone.** With seven authoring items already blocking the plan (G-PLAN-01), that outweighs its individual value |
| | **It matches how enterprise buyers arrive.** A hospital group with 400 nurses already HAS a framework - in a spreadsheet, from a consultancy, or in the system they are replacing. **Making them retype it is the worst possible first impression, and it is the current behaviour** |
| **(3) enrichment loop** | Follows naturally and is **half-built**: the HOLDING state exists and already reports coverage. Import what they have; labels become canonical through use |
| **(1) domain libraries** | **DEFERRED. A content business, not a software one** - an authoring commitment that never ends, for verticals we do not work in |

> ### THE IMPORTS ARE THE EVIDENCE FOR WHICH DOMAINS ARE WORTH AUTHORING.
>
> A healthcare library built **before** any healthcare customer imports theirs
> would be authored from **guesses about what a hospital's framework contains**.
> **Build the second from what the first three customers bring** - (2) feeding
> (1), not the reverse.

**That inversion is the correction:** the instinct is that domain libraries make
the importer more valuable. It is the other way round.

### X-08(b) SCOPE, AND ONE THING IT MUST NOT DO

CSV/spreadsheet into the tenant's **own** rows. Names resolved to ids **at import
time** - Q-C1's original position, and the one place where *"whose copy does this
name mean"* has exactly one answer. **All five KASBA dimensions**, because
G-SEED-01's correction means a customer's framework carries knowledge, ability,
attitude and behaviour items too.

> **DO NOT BUILD A VALIDATOR THAT REJECTS VOCABULARY IT DOES NOT RECOGNISE.**
> That is the generic-library failure in a new place: the customer's words are the
> content, and the importer's job is to accept them, not to grade them.

---

### R6. THE LIBRARY MUST SUPPLY DOMAIN-SPECIFIC SKILL VOCABULARY, NOT ONLY ALL FIVE DIMENSIONS

**A library covering all five dimensions in generic terms would still resolve
nothing for a hospital.** R1 (all five dimensions) is necessary and not sufficient.
This changes X-08(b)'s cost: it is not one library, it is a library **per domain**,
or a mechanism for a customer to bring their own.

> **THE CANONICAL SKILL LIBRARY AND REAL COMPETENCY VOCABULARY ARE ALMOST
> DISJOINT.** Items like *"Hand hygiene compliance"*, *"Double-check discipline"*,
> *"Structured handover (SBAR)"* and *"Empathy in distressing situations"* have no
> canonical row, because the library holds **skills** - and four of the five KASBA
> dimensions are **not skills**. Knowledge, attitude, behaviour and ability were
> never going to match a skill library.

**Why it matters commercially:** this is what a real customer's first import will
look like. **A new tenant should expect most of its KASBA items to arrive as
labels**, and the product must be good at that state rather than treating it as an
error. It also sizes the seed-library import feature: its job is supplying the four
non-skill dimensions, which nothing currently does.

**Filed about the LIBRARY, not about the seed.** The seed is the instrument that
measured it.

---

## ⭐ REQUIREMENTS FOR Q-C1's SEED-LIBRARY IMPORT — **derived from this measurement, not a note beside it**

> **This is what a real customer's first import will look like.** The tenant-3
> slice is the only evidence anyone has about that, so it sets the requirements.

### R1. THE LIBRARY MUST CARRY ALL FIVE KASBA DIMENSIONS, NOT JUST SKILLS

**The measured cause:** `s_users_skills` and `master_skills` hold **skills**. Four
of the five KASBA dimensions are not skills, so **they could never match** —
*"Hand hygiene compliance"* (behaviour), *"Double-check discipline"* (behaviour),
*"Empathy in distressing situations"* (attitude), *"Prioritise under time
pressure"* (ability) have no canonical row and never will while the library is
skills-only.

**Requirement:** the import supplies knowledge, attitude, behaviour and ability
items as first-class library entries. **Without this, 4 of 5 dimensions are
permanently HOLDING for every customer.**

### R2. MOSTLY-HOLDING IS THE NORMAL FIRST STATE, NOT AN ERROR

**26 of 27 items landed as labels.** The product must be **good** at that state:
screens readable, gaps computable, `item_label` never rendered as a failure.
**An import that reports "26 unmatched" as errors will read as a broken import.**

### R3. THE IMPORT PROMOTES HOLDING → TARGET IN PLACE, WITHOUT REWRITING BUNDLES

A tenant defines bundles first and acquires canonical items later. Promotion sets
`item_id` and clears `item_label` **on the existing row** — it must not require
rebuilding the competency, or every enrichment becomes a migration.

### R4. IT MUST BE ABLE TO PRODUCE A ONE-ITEM BUNDLE, AND SAY SO

X-20 created **199 one-item bundles** from migrated skills. **A one-item bundle is
valid, and it is also visibly incomplete.** The import is what enriches them, so it
must be able to distinguish "deliberately one item" from "not enriched yet".

### R5. VOCABULARY DISTANCE IS A NUMBER THE PRODUCT SHOULD REPORT

**1 of 27** is the coverage figure for a clinical tenant against the shipped
library. **A customer should see that number before importing**, not discover it
afterwards — it is the honest answer to "how much of this is ready?".

---

# G-ORG-02b - **`head_user_id` AND `reporting_manager_id` HOLD REAL DATA FOR THE FIRST TIME** - progress note

| | before | after |
|---|---:|---:|
| `hrms_departments.head_user_id` populated | **0** | **3** |
| `reporting_manager_id` populated (platform) | **0 of 387** | **8 of 401** |

Small, and it is the **first data those columns have ever held**. Every write went
through `ReportingLineValidator::canAssign()`, and a deliberate cycle
(Rajesh Iyer → Vikram Sethi) was **REFUSED** - so the validator is now exercised,
not merely present (**G-ORG-01**).

**X-16 is what makes this general.** One seeded department tree is a demonstration;
a reporting line for 387 people needs the assignment mechanism.

---

# G-DATA-10 - **`course_competency_map` IS EMPTY. THAT IS THE WHOLE GAP.** - **S2**
### RE-FILED 2026-08-11. My first framing called it a schema gap. **It is not.**

> **THE CORRECTION, AND IT IS TRIZ'S:** the plan->course path was never meant to run
> through a `course_id` on the plan action. It runs **plan action names a
> COMPETENCY -> `course_competency_map` finds the courses that build it** - the
> competency-derived path, decided as the DEFAULT, with `course_jobrole_map`
> reserved for role-mandatory learning that is not gap-driven (the S4 ruling).
>
> **Adding `course_id` to `s_competency_plan_actions` would create a SECOND PATH TO
> THE SAME ANSWER - the duplication this phase exists to remove.**
> **THE PROPOSAL IS WITHDRAWN. No column is being added.**

**VERIFIED IN CODE, not accepted on argument.** `LearningAssigner::fromDevelopmentPlan()`
already resolves exactly that way: collect `competency_id` from the plan's actions
and the plan itself, then query `course_competency_map`. **The code was right and my
prose about it was wrong** - the same shape as the `NotificationComposer` docblock,
inverted.

**So this is Q-B4, the highest-priority connection item, and it is an EMPTY-TABLE
gap: populate `course_competency_map`.**

### WHAT EXISTS TO POPULATE IT FROM - reported before proposing how

| Candidate source | Finding |
|---|---|
| **`lms_assignments`** | **48 distinct (course, competency) pairs. 48 of 48 course_ids resolve to a real course; 48 of 48 competency_ids resolve in `master_skills`.** All carry `source='competency'` - somebody already assigned these courses FOR these competencies. **THE MAPPING IS IMPLICIT IN THE ASSIGNMENT HISTORY.** |
| `sub_std_map.subject_category` | 94 of 95 populated, but the values are `Task`, `Technical`, `Skill`, `course`, `jobrole`, `Functional`, `Creative` - **categories, not competencies** |
| `sub_std_map.proficiency` | **2 of 95.** Negligible |
| `sub_std_map.certificate_validity_months` | **0 of 95** |
| `sub_std_map.jobrole` | 72 of 95 - the ROLE side (X-18), not the competency side |
| chain `course.jobrole -> s_competency_certification_requirements.jobrole -> competency_id` | **0 pairs derivable.** Dead end, though it looked promising: that table does hold (jobrole, competency_id) on 15 rows |
| `ai_course_outlines` | 59 rows, **no competency column**. `outline`/`input_fields` are longtext; reading competencies out of prose is inference, not a source |
| `certification_competency_map` | **0 rows** |

### HOW FAR THE ONE REAL SOURCE WOULD GET US

| | |
|---|---|
| distinct competencies named by plan actions | **167** |
| distinct competencies in `lms_assignments` | **42** |
| **overlap - plan competencies a 48-pair bridge would cover** | **41** |

**A 48-row backfill makes roughly a quarter of plan competencies resolvable to a
course.** Real, incomplete, and honest about which.

### ⚠ A NEIGHBOUR FOUND WHILE MEASURING: `jobrole_competency_map` IS ALSO 0 AT REST

Slice 1's chain seeds it and cleans up, so the passing smoke check does **not** mean
the table holds production data. **The role->competency link is as empty as the
course->competency one.** Same family, and it is not the same item.

### PROPOSED: **X-19, POPULATE `course_competency_map` FROM ASSIGNMENT HISTORY**

48 pairs, F-07b's discipline: derive, verify both ends resolve, **hold anything that
does not, delete nothing**. Bulk write - **raised for decision, not taken (R13).**

---

# G-NOTIF-02 - **SIX NOTIFICATIONS WHOSE "ACT ON IT" LINK 404s** - **S3** - FIXED

X-06 deferred `certification.issued` partly because *"its action link would point
at a certificate screen that has not been built"*. **That reason applied to all six
events it DID ship, and I checked none of them.**

| Template | Path shipped | Exists? |
|---|---|---|
| `task.rejected` | `/tasks/{id}` | **no** |
| `assessment.completed` | `/competency/my-capability` | **no - there is no `/competency` route** |
| `certification.expiring` | `/competency/my-capability` | **no** |
| `development_plan.approved` | `/competency/development-plan/{id}` | **no** |
| `employee.offboarded` | `/talent/offboarding/{id}` | **no** |
| `rights.changed` | `/settings/my-access` | **no - `/settings` exists, that child does not** |

### THE USEFUL PART IS NOT THE BUG. IT IS THAT I STATED THE TEST AND DID NOT RUN IT.

X-06's own deferral reason for `certification.issued` was, verbatim:

> *"its action link would point at a certificate screen that has not been built"*

**I wrote that sentence, applied it to one event, and shipped six others with the
same defect in the same file, in the same hour.** The test was not missing. It was
written down, correct, and applied exactly once.

**A stated test is not an applied test**, and the gap between them is invisible
precisely because the statement makes it feel handled. This is R23's family again:
**writing the right thing next to the code is not evidence about the code.**

**Every path was invented from the shape of the domain rather than read from the
router.** The check that would have caught it costs one `find app -name page.tsx`.

**They cannot simply be corrected.** Competency, task and talent screens are
reached through `/module/[moduleId]/[menuId]/[submenuId]`, and those ids come from
`tblmenumaster_g2g` **at runtime, per tenant**. **There is no static path to
hardcode.**

**FIXED:** all set NULL except `development_plan.approved`, which keeps
`/lms/training-records/assignment` - real, and genuinely where X-12 writes. The
bell already renders a link-less message. **A message that says what happened is
worth having; a link that breaks is not.**

**A smoke check now compares every `action_path` against a route list verified from
`g2gv0/app/**/page.tsx`.** A deep-link resolver (menu ids -> path) is the real fix
and belongs with X-17's flows, which will need it anyway.

---

# G-DATA-09 - **DUPLICATE. SEE `G-ORG-02`.**

I raised this as a new finding on 2026-08-11. **It is not new.** `G-ORG-02` has
recorded 0 of 387 since the Gate B review, and `G-ORG-01` records the unused
validator beside it.

**What X-06 actually added was the CENSUS** - the 15 other manager columns, and
the proof that every one of them is per-CASE. That evidence now lives in
`G-ORG-02` where it belongs. **The ID is kept rather than deleted so that anything
citing it still resolves.**

> **Raising a duplicate is a reading failure, not a bookkeeping one.** The two
> `Resolves*Context` traits I have edited this phase BOTH carry the string
> `G-ORG-02` in a comment about this exact gap. **R20: the boundary of what you
> read is the boundary of what you know** - and I had read those files.

---

# G-NOTIF-01 - the notification bell was a picture of a bell - **S3**

`gtg-header.tsx` and `gtg-header-base.tsx` each carried their own copy of a menu
that rendered **"You're all caught up" and a hardcoded "New" badge at the same
time**, unconditionally, with no request behind either. Two contradictory claims,
neither measured, neither able to change.

### AN INSTANCE OF **S-4b's CLASS — DEAD UI — NOW FOUND IN THE FRONTEND

S-4b swept for **state gates that can never change** and, after two checker bugs,
confirmed **1**. This is the same class in its most user-visible form: not a gate
that never flips, but **a control that renders two contradictory claims at once**
— a "New" badge and "You're all caught up" — **with no request behind either.**

**The escalation over S-4b's instance:** a dead state gate hides a feature. **A
dead notification bell tells the user, continuously and in the shell of every
screen, that nothing needs their attention.** It is the failure mode that looks
most like working software.

**S-4b's sweep could not have found it.** That sweep looked for `useState` gates
whose setter is never called. This component had no state to gate — it had no
DATA. **A sweep for gates that never flip cannot see a control that never asks.**

**Two copies is the finding, not the placeholder.** A control can be dead in two
places at once and look maintained in both. **FIXED in X-06:** one shared
component, reading `/api/notifications`; a failed fetch reports a failure instead
of an empty inbox.

---

# ⭐ G-DATA-06 — THE HEADLINE FINDING · **S1**

> ## 283,126 relationship rows are held together by string matching, not keys.

**This is promoted above the security findings deliberately.** The security gaps
are breaches to be closed; **this one explains why the product does not function as
a connected system.** Until now L-11 was an argument. It is now a measurement.

### ⭐ READ WITH `G-FLOW-26` — THEY ARE A MATCHED PAIR AND TOGETHER THEY ARE THE DIAGNOSIS

| | |
|---|---|
| **G-DATA-06 — the SUPPLY side** | the relationships that **do** exist are joined by **strings**, not keys |
| **G-FLOW-26 — the DEMAND side** | three relationships the product is **sold on** do not exist at all — they are **named and not built** |

**The single concrete illustration: the 9-box grid has performance on one axis
and nothing to put on the other.** Performance has never been able to read a
capability measurement, because the join was never built — only the word
*competency* appears, as a dropdown label and a validator enum value.

Neither finding alone explains the product's state. Together they do.

## Exactly what the number counts

**283,126 = the sum of the ROW COUNTS of four tables**, each verified individually
against the live schema:

| Table | Rows | Text columns naming another entity, with no `*_id` |
|---|---:|---|
| `s_user_jobrole_task` | **85,662** | `jobrole` |
| `s_user_skill_jobrole` | **79,295** | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_skills` | **62,208** | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_task` | **55,961** | `jobrole` |
| **Total rows** | **283,126** | |

### What it INCLUDES
Rows in the four tables that carry the product's load-bearing relationships —
**which job role needs which skill**, and **which tasks belong to which job role**.
Every one of those relationships is resolved by matching a **name string**.

### What it EXCLUDES — state this whenever the figure is used
- **It is not 283,126 defects.** It is 283,126 rows in four tables, every one of which resolves its relationship by string.
- **It is not a count of columns.** That is the separate figure of 32 (below), and the two must never be conflated.
- **It does not include** the other 28 cross-table text references, which are much smaller.
- **It does not mean the data is wrong today.** It means **any rename silently detaches it**, and nothing can join to it by key.
- **Provenance:** test data (R2). The *structure* is the finding, not the volume — a customer's own library would have the same shape.

## Why this is the precondition, not one connection among twenty-three

A job role cannot be told what capability it requires; an employee's measured skill
cannot be tied to the role that needs it; a task cannot report the competency it
exercised. **Every golden thread crosses at least one of these four tables.**
`L-11` is therefore **not a connection to be prioritised against others — it is the
precondition for the rest of them existing.**

## C30 — the three lists · only (a) is quotable

The sweep found **49** populated text columns naming an owned entity with no
matching `*_id`. That 49 **must not be quoted whole.**

| List | Count | Quotable? |
|---|---:|---|
| **(a) REFERENCES — cross-table AND self-reference. The finding** | **36** | ✅ **Yes** |
| (b) Own identity — `hrms_departments.department`, `s_user_jobrole.jobrole`, `s_users_skills.skill_code`. The name legitimately lives here | 5 | ❌ Not a defect |
| (c) Attribute noise — `skill_status`, `skill_importance`, `jobrole_category`, `skill_type` | 8 | ❌ Noise |

**The rule was corrected, not hand-patched.** The first version tested only *"is
this the owner table"*, which wrongly classified a self-reference — a column on
`s_user_jobrole` pointing at a **different** job role is still a reference. The
corrected rule requires **both** that the table owns the entity **and** that the
column denotes *this row's own* identity; a `related_`/`sub_`/`_ids` qualifier
fails the second test. Re-running moved the quotable count **32 → 36** and pushed
six owner-table attributes into noise.

**The 283,126 figure is independent of this classification.** Those four tables
were verified individually, table by table; the 36 comes from a schema-wide rule.
**They are two separate measurements and must never be presented as one** — which
is precisely why correcting the rule from 32 to 36 left 283,126 untouched. State
that independence whenever both figures appear near each other.

---

# G-DATA-08 — `s_skill_matrix` has no tenant column · **CANDIDATE, not confirmed**

The per-employee **capability measurement store** (169 rows) has **no
`sub_institute_id`**. It is tenant-scoped **only indirectly**, via
`user_id` → `tbluser.sub_institute_id`.

**Defensible if every query joins `tbluser`. Silently global if any query counts
or aggregates the matrix directly.**

Surfaced while hand-verifying a C34 candidate: `CompetencyDashboardController@index`
returns an **identical body to both tenants despite filtering on `sub_institute_id`**
at lines 27, 39 and 46. That combination is best explained by a panel reading the
matrix without the join — but **that is a hypothesis, not a verdict**, and
confirming it means reading the panel queries.

**The decision it forces now:** §10 step 12 rebuilds this table as
`skill_matrix_item`. **Whether that carries a tenant column must be decided
deliberately, not inherited.**

---

## G-CERT-01 addendum — `verified_by` was caller-supplied too · ✅ **BOTH HALVES CLOSED**

**Recorded here, not only under G-SEC-12, because a reader looking at
certifications must find it.**

`CertificationController` had **two** trust defects on the same record, and they
compound:

| Half | Defect | Closed by |
|---|---|---|
| **1** | `verification_status` taken from the request — **a credential could declare itself verified** | **D-002** (`2026-08-06`) |
| **2** | `verified_by` taken from the request — **a caller could also name WHO verified it** | **D-006** (`d70a204c`) |

**Together they meant a credential could assert both that it was verified and who
signed it off.** Neither claim was checkable, and nothing looked wrong.

> **Certification trustworthiness is what a regulated customer tests first.** A
> credential that verifies itself, signed by a person who never saw it, is the
> exact artefact an auditor asks to see the provenance of.

**Both halves are now closed.** `verification_status` is server-set to `pending` on
create; `verified_by` resolves from the token via `g2gActorId()`. The legitimate
verify path still works and still stamps `verified_by` / `verified_at` — but from
identity, not from input.

⚠️ **Still open:** that verify path is **not role-gated** (G-SEC-01). Anyone who can
reach it can verify. Logged, not fixed here.

---

# G-DATA-07 — `s_library_map.skill_ids` packs ids into a TEXT column · **S2** (C31)

**Worse than the string-join problem, and it deserves its own line.**

`s_library_map.skill_ids` holds **multiple skill ids packed into one TEXT column**,
populated on **3,270 rows**.

| | String join (G-DATA-06) | **Packed ids (this)** |
|---|---|---|
| Can be joined at all? | yes, on a name | **NO** — not one-to-one, not one-to-many, not any way |
| Requires `LIKE '%,3,%'` to query? | no | **yes** — and it matches `13`, `23` and `130`. **This is a WRONG-RESULTS bug, not a speed problem**: skill 3 appears to be mapped wherever skill 13 or 130 is. "Unindexable" understates it |
| Breaks on a delimiter in the value? | n/a | **yes, silently** |

A string join is a *bad* key. A packed list is **not a key at all** — every read
becomes a substring scan that is both slow and wrong at the boundaries.

**Fix:** a proper join table `library_map_skill (library_map_id, skill_id)`, backfilled
by splitting the column, with unparseable rows reported rather than guessed (§10.0
migration path). **Cost: S** — 3,270 rows, one new table, one backfill, and the
readers are few.

**Not folded into G-DATA-06** because the remedy is different: G-DATA-06 needs a
key column added beside a name; this needs a *table* that does not exist.

---

## G-FLOW-26 — a vocabulary of connection without the connection · **S2**

**Three modules now show the same shape: the word "competency" is present, the join
is not.**

| Thread | Where | What exists | What does not |
|---|---|---|---|
| **3** · Competency ↔ LMS | `library-config.ts:172` | a *Learning Resources* **text field** | any course reference (`L-08`) |
| **5** · Competency ↔ Performance | `PerformanceGoalController.php:93,167`; `PerformanceOverviewController.php:314` | `'competency'` as a **validator enum value** and a **filter label** | **any join to `s_skill_matrix`, `s_users_skills` or a competency table — none exists in `Api/Performance/`** |
| **7** · Competency → Recruitment | Q-D1 recorded the read as intended | nothing | **zero references to `s_user_skill_jobrole` / `s_jobrole_skills` in `Api/Talent/` or `talent_*`** |

### Why this is its own gap and not three

**A reader of the code would conclude these modules are connected.** The
vocabulary is there — a goal category called *competency*, a filter labelled
*Competency*, a field called *Learning Resources*. **Each is a label with no
referent.**

**The 9-box grid is the clearest casualty:** it has performance on one axis and
**nothing to put on the other**, because Performance has never been able to read a
capability measurement.

**This is the demand-side counterpart to `G-DATA-06`.** G-DATA-06 says the
relationships that *do* exist are joined by string; **G-FLOW-26 says three of the
relationships the product is sold on do not exist at all** — they are named and
not built.

**Connections:** `TL-02` (Performance), `TL-03` (Recruitment), `L-08` (LMS).

---

## FIX ORDER FOR THE REMAINING TENANT LEAKS — by DATA CLASS, not route count

**Decided 2026-08-06.** Route count is the wrong ordering: it optimises for
closing many at once rather than for closing the worst first.

| Tier | Data class | First items |
|---|---|---|
| **1** | **Candidate / personal data** | **`talent_interviewpanelController`** — interview panel records cover **candidates: people outside the company who never agreed to be in the system.** Once Q-D4's portal exists this is external PII and a leak is a **regulatory** matter, not only a commercial one. Then the other three C27 Talent controllers |
| **2** | **Payroll-adjacent** | `PayrollController` ✅ **done (D-004)**; `HrmsLeaveController`, `ApplyLeaveController`, `LeaveTypeController`, `LeaveSummaryReportController` |
| **3** | **Credentials / integrations** | `ExcelAutomationAgentController@credentialStatus` — reports on **another tenant's integration credentials** |
| **4** | **Competency and learning content** | `skillLibraryController` ✅ **done (D-003)**; `skillcontroller`, `assignmentController`, `courseController`, the rest |

**`talent_interviewpanelController` goes first among everything remaining**, ahead
of `assignmentController` (6 routes) and `HrmsController` (3).

---

## G-ORG-01 — the no-cycle guarantee is theoretical until every write path calls it · **S2**

**`ReportingLineValidator` exists (`f293edb0`) and nothing calls it.**

MySQL cannot express "no cycles", so the guarantee lives in application code — which
means it holds **only where the code runs**. Every path that sets
`reporting_manager_id` must call `canAssign()` first:

| Write path | Must call `canAssign()` |
|---|---|
| Employee create | ☐ |
| Employee edit | ☐ |
| Onboarding (assigning a new hire to a manager) | ☐ |
| **Bulk import** | ☐ — **the most likely to create the first cycle** |
| Any admin/org-chart screen | ☐ |

> **A validator nothing calls is documentation.** The first bulk import that sets
> managers without it creates exactly the cycle the validator was written to
> prevent, and team-scope resolution stops terminating.

**Tracked as plan item F-05a**, with "must call `canAssign()`" as an acceptance
criterion on each path.

---

## G-ORG-02 — the role model has nobody in six of its nine roles · **S3 → S2, RE-GRADED 2026-08-11**

> **RE-GRADED because it stopped being "by design for now".** It was S3 while
> nothing needed the reporting line. X-06 needed it, and could not deliver
> `capability.flag_raised` to anybody. **A gap that blocks a shipped feature is
> not a deferred nicety.**

### THE CENSUS (added by X-06 — this is what makes it a measurement)

| Source | Populated |
|---|---|
| `tbluser.reporting_manager_id` | **0 of 387** |
| `tbluser.supervisor_opt` | a FLAG — 4 "Supervisor", 57 "Subordinate", **no link between them** |
| the other 15 manager-ish columns | **per-CASE, never per-person**: `talent_offboarding_cases.manager_id` 3/3, `task_management_projects.manager_id` 3/3, `s_performance_reviews.manager_id` 16/228 |
| **write paths that set `reporting_manager_id`** | **ZERO. There is no mechanism, not even a single-user one.** |

**The last row is the finding.** F-05a is written as *"call the validator from
every write path"* — **there are no write paths to call it from.** F-05a cannot be
done before F-05b; the plan has them in the wrong order.

### WHY IT STAYED INVISIBLE

**THE COLUMN EXISTING IS WHY EVERY DESIGN CONVERSATION ASSUMED THE RELATIONSHIP
DID TOO.** Nothing reads a column to check whether it is empty.

### WHAT IT BLOCKS — more than was listed

| Blocked | Where |
|---|---|
| `capability.flag_raised` notification | `EventCatalogue::NOT_NOTIFIED` (X-06) |
| Dept Head + Reporting Manager re-grants | the 4b rights matrix, parked on coverage |
| golden thread 2's manager-confirmation step | Slice 3 |
| every approval flow in the plan | F-05's own "unblocks" column |
| **`ResolvesCompetencyContext::COMPETENCY_ELEVATED`** | two roles deliberately absent |
| **`ResolvesLeaveContext::LEAVE_ELEVATED`** | same two roles, same reason |

The last two were already in the code as comments naming `G-ORG-02`.

**BUILD ITEM: X-16.** Absorbs F-05a and F-05b, which have sat NOT STARTED since
Gate B.


**0 of 387 users have a `reporting_manager_id`**, and six of the nine roles were
created empty. Both are **correct** — the columns are new and nothing has assigned
them yet.

But the consequence is worth stating: **reporting coverage is 0%, so every
manager-dependent flow is gated off**, and a role model with nobody in it
demonstrates nothing. Slice 2's demo ("three roles, three different products")
needs users actually holding those roles, and Slice 3's manager-confirmation step
needs a reporting line that exists.

**The assignment mechanism — bulk and individual, for `reporting_manager_id` and
`head_user_id` — is tracked as plan item F-05b.** It was not in the plan before
this review; it is now.

---

## G-SEC-12 — caller-supplied audit provenance · **S1** · ✅ **CLOSED 2026-08-07** (`d70a204c`)

**`created_by` / `updated_by` taken from the request body.** S-3 found the pattern
**33 times**; `PayrollController` lines 167 and 238 are two, now fixed.

### Why this is a different class from the tenant leaks

> **A leak exposes data. This CORRUPTS THE RECORD OF WHO DID WHAT** — the evidence
> you would rely on when investigating a leak.
>
> A caller can attribute their own write to another user, and the audit trail
> records it **as fact**. Nothing looks wrong.

### ⛔ THIS BLOCKS THE EVENT STORE

`05-data-flow-contracts.md` assumes `actor_id` on every event is trustworthy. **If
actor identity can be caller-supplied anywhere, the event store inherits a
corrupted audit trail on day one — the exact thing it exists to provide.**

**Sequenced BEFORE the event store in the Gate D order.** Recorded in both
documents.

### Required

1. **The complete verified list (R6).** 33 candidates, hand-classified **IDENTITY** (must come from the token) vs **SUBJECT** (legitimately supplied — *"generate this employee's payslip"*). Same method that worked on PayrollController: read each site, trace what the value feeds.
2. **Fix shape:** mirror `payrollActorId()` — token first, session fallback, **never request input**.
3. ✅ **DONE.** The rule cleared **all 76 mechanically — zero ambiguous, zero hand-reads needed.** ⚠️ **Scope was 76 sites across 16 files, not 33** — S-3's figure counted only `created_by`; this covers every provenance column. **The estimate was low by 2.3×.** Fixed with `g2gActorId()` per file. Re-scan: **0 remain.**

---

## G-MAP-01 — the "one-line fix" does not exist · **re-costed**

Checked before changing anything. `QuickCreateKind`
(`services/competency/command-center.ts:111`) has **five** kinds —
`competency`, `framework`, `assessment`, `certification`, `development-plan` —
and `CREATE_ENDPOINTS` has the matching five. **There is no `role-mapping` kind,
and no create endpoint for it.**

So *"point the button at the right handler"* is impossible: **there is no handler
to point at.** That is the same finding from the other side — role mapping has no
create path, and the button is bound to `framework` because that is the only thing
available.

**RESOLVED 2026-08-06: the button was REMOVED**, with approval and an R8
checklist (`g2gv0` commit `cb2f6a5`). Not disabled, not annotated — **a control
that quietly does the wrong thing is worse than an absent one**: the user asked for
a role mapping, got a framework, and was told it succeeded. **M-03 is its
reinstatement** and it stays gone until the create path exists.

**M-03 stands as S–M**, and its real content is confirmed: build the create path
(surfacing `SchoolSetupController.php:392-408`'s existing bulk insert), then wire
the button to it.

---

## G-OPS-01 — the trait behind both shipped security fixes was untracked · **S1 (near-miss, now closed)**

**Found by an accident, not by a check.** A mangled shell command truncated
`00-progress.md` to zero bytes. It was untracked in git, so nothing could restore
it — which prompted an audit of what else was unversioned.

> **`ResolvesApiIdentity.php` — the F-01 trait that BOTH D-003 and D-004 depend on
> — was untracked.**
>
> Had it been lost the same way, **the two shipped tenant-isolation fixes would
> have silently reverted.** The calling code would still compile. The controllers
> would still respond. `skillLibraryController::competencyLibraryContext()` would
> still return an array. **The leaks would simply reopen, with no error anywhere.**
>
> **Only a C23 re-run would have caught it — and only if someone thought to run
> one.**

Four more load-bearing artefacts were in the same state: `RequireApiToken`,
`RequireProfile`, the `s_skill_matrix` alignment migration, and all three Phase 1
audit scripts.

### Net

**A corrupted tracking file triggered an audit that found five load-bearing
artefacts one mistake from disappearing. That is a better outcome than the accident
cost.**

### Closed by

| | |
|---|---|
| `fb284a06` | 87 Phase 3 files |
| `2849500e` | the Phase 1 security artefacts |
| `732f1ce` (g2gv0) | the frontend halves of D-001 and D-002, which were **not even committed** |
| **R18** | `docs/phase3/` is committed after every write to `00-progress.md` |

### ⚠️ Still open — committed is not backed up (C39)

| Repo | Remote | State |
|---|---|---|
| `hp_erp` | `github.com/rajeshrafaliyatriz/hp_erp.git`, tracking `origin/Milan-2` | **10 unpushed commits.** All of Phase 3 exists only on this machine |
| `g2gv0` | `github.com/zeeltank/g2gv0.git`, branch `milan1` | Now committed; **1 unpushed** |

### C41 — the same exposure, twenty times the size

**75 modified files sit uncommitted in `hp_erp`'s working tree** — the Phase 1/2
work. An untracked trait was one accident from silently reverting two shipped
security fixes; **75 uncommitted files in the same working tree is that exposure
at twenty times the scale.**

**They touch security artefacts.** The modified set includes
`ApprovalController`, `CertificationController`, `CompetencyController`,
`LmsGovernanceController`, `AJAXController`, `JobroleApiController` and the
`Resolves*Context` traits — i.e. **the F-01 tenant-resolution work itself**.

**Not mine to commit.** Raised so it can go to whoever owns them.

**Exposure is reduced, not removed.** A lost machine or a bad reset still takes
Phase 3 with it. **Pushing is Triz's call** — `hp_erp` carries 8 unpushed commits
that are not mine, and pushing is outward-facing.

---

---

# ⭐ G-SEC-07 — THE PRODUCT HAS NO WORKING PERMISSIONS · **S1** · THIRD AND FINAL STATE

> ## THE FINDING, worded so it can be repeated verbatim
>
> **The Next.js product's sidebar reads `tblgroupwise_rights_g2g` via
> `tblmenumasterG2gController::displaySidebarMenu`, filtered by `profile_id`, with
> absence denying (`?? 0`). `can_view = 1` on all 4,879 rows and
> `can_add` / `can_edit` / `can_delete` = 0 on all of them. Every profile sees the
> same 157 menus and no profile holds any action right.**
>
> **THE PRODUCT BEING SOLD HAS NO WORKING PERMISSIONS; roles differ in name only.**
>
> The seeder's placeholder intent is real, but **it was never replaced and the
> product ships on it.**

## The evidence chain, one link per step

| Step | Artefact |
|---|---|
| Next.js calls | `/user/ajax_sidebar_menu_g2g` — `services/navigation/sidebar.ts:41` |
| Route | `routes/user.php:44` |
| Controller | `tblmenumasterG2gController::displaySidebarMenu` |
| Menus from | `tblmenumaster_g2gModel` |
| **Rights from** | **`tblgroupwise_rights_g2gModel`, `where('profile_id', …)`** |
| Applied as | `if (! $this->canView(...)) { continue; }` — **a hard filter, not decoration** |
| Absence means | `return ($rights->can_view ?? 0) == 1;` — **deny** |

## Version history — both prior states, struck through

| Date | State | Why it was wrong |
|---|---|---|
| ~~2026-08-05~~ | ~~**S1** — "the rights matrix carries no information; enforcing it would grant everyone everything"~~ | **Right conclusion, incomplete evidence.** It never established which interface read the table |
| ~~2026-08-07 (morning)~~ | ~~**S3** — "the live table is differentiated; the uniform `can_view=1` is a deliberate placeholder; 4b is invisible to users"~~ | **WRONG.** It found differentiated data in `tblgroupwise_rights` and assumed that was the live table. It is the **Blade** product's. And it let the seeder's stated *intent* stand for the product's *behaviour* (**R10c**) |
| **2026-08-07 (final)** | **S1 — as stated above** | Traced from the frontend call to the controller query. One endpoint, one controller, one table |

**Three states of one finding is untidy. Hiding two of them would be worse.**

## What follows

**4b is not curation. It is the fix**, and it is **the most visible change in the
plan** — the first thing in this phase a person will see.

### Two asymmetries the diff must separate

| Direction | Risk |
|---|---|
| **Action rights** (`add`/`edit`/`delete`) | **PURELY ADDITIVE.** Nothing holds them today, so populating can only **grant**. **Low risk** |
| **`can_view`** | **SUBTRACTIVE.** Every profile currently sees all 157 menus, so real rights **REMOVE** menus from every profile **including Administrator**. **This is where the risk sits, and what the review gate is for** |

---

# Q-A5 — EXTERNAL DESTINATIONS, ALL THREE IN ONE PLACE

**For a security review.** Every destination outside our origin that the product
loads or links to, and what leaves with the request.

| Screen | Host | What leaves our origin |
|---|---|---|
| Taxonomy Ontology (menu 43) | **`skill-ontology-neo4j.vercel.app`** | **`sub_institute_id` in the URL**, plus the customer's IP, **on every page load** |
| Skills link | *(recorded under Q-A5)* | — |
| Pal link (menu 187) | *(recorded under Q-A5; `status=0`, removed from the seed)* | — |

**The ontology iframe carries `sandbox` without `allow-same-origin` and
`referrerPolicy="no-referrer"`.** Those are right and are not being
re-litigated — **but they do not answer a procurement question about data leaving
our origin on every page load.** The exposure is the **tenant id and the request
itself**, not the session.

**C-T3-ONT (M) is the real fix, not an enhancement** — it removes the external
host and the tenant id from the URL *as well as* making the graph true. Blocked
on F-07b, as scheduled.

---

# G-NAV-02 — THE SIDEBAR ENDPOINT TAKES profile_id FROM THE REQUEST · **S2**

Found while building the R9 harness, not by looking for it.

`tblmenumasterG2gController::displaySidebarMenu:32` reads
`$profile_id = $request->input('profile_id')` and the guard (`:326`) checks only
that **a** valid token was supplied — **never that the caller holds that profile.**

Any authenticated user can request **any** profile's sidebar.

**RAISED TO S2 from S3.** The impact reasoning — structure, not data — is right,
but it under-weighted what structure gives away: **an employee can enumerate which
screens every role holds.** That is **reconnaissance** — it maps where the
interesting endpoints are, and it erodes the value of the rights that were just
applied.

**Effort is one line, in G-SEC-12's family: resolve `profile_id` from the token's
user, never from the request.** Fixed with the security queue, not after it.

> **THIS DEFECT IS WHY ONE TOKEN COULD TEST NINE ROLES.**
> When it is fixed the R9 harness needs **nine tokens**, one per role.
> **The harness must be updated in the same commit**, or verification breaks
> silently — passing because it can no longer ask the question, not because the
> answer is good.

**Same class as the subject-from-request findings**, and it is the reason the R9
harness can vary `profile_id` on one token to check nine roles. **The defect is
what makes the test convenient**, which is worth stating plainly rather than
quietly relying on.

---

# G-SEED-01 — THE MARK PARSER READ QUALIFIER TEXT AS PERMISSIONS · **S1, CAUGHT PRE-APPLY**

**Found by inspecting Employee's AFTER list by name** — Learning Dashboard showed
`V E` where §3.x says `V (self)`. The gate that exists for exactly this caught it.

The parser stripped punctuation but kept the qualifier's **letters**:

| §3.x cell | Parsed as | Granted |
|---|---|---|
| `V (self)` | `VSELF` | **V + EDIT** |
| `V (member)` | `VMEMBER` | **V + EDIT** |
| `V (own punch)` | `VOWNPUNCH` | **V + CREATE** |
| `V (org — basic fields)` | `VORGBASICFIELDS` | **V + CREATE + EDIT + DELETE** |

**The employee directory — the screen denied precisely because it exposes too
much — was being seeded with full create, edit and delete.** Across all four
qualified roles, not just Employee.

### Root cause, and it is the same one as G-RBAC-01

**The qualifier and the mark live in one cell.** Every qualifier is data the table
cannot express, sitting in the same string as data it can. The parser had no way
to tell them apart because the format does not distinguish them.

### Fix, in three parts

1. **Strip the qualifier before reading marks**, in both forms it takes —
   parenthesised `V (self)` and comma-separated `V, self-register`.
2. **Whitelist the result to `VCEDAX`.** An unrecognised letter means a format
   this parser has not seen, and silently granting on it is the bug itself.
3. **Report anomalies and grant nothing** for them.

### What the whitelist then caught, which nothing else would have

```
3.1 | Employee Directory | reporting_manager => "V (org basic) + V full (team)"
3.1 | Employee Directory | department_head   => "V (org basic) + V full (dept)"
```

**A compound mark**: org-wide basic fields *plus* full records for the caller's
team. **Two different scopes in one cell.** It cannot be expressed by a menu
boolean at all, so it grants nothing — the same verdict Employee Directory already
has for Employee, now reached mechanically for the other two roles.

**Consequence, stated plainly: Reporting Manager and Department Head lose the
Employee Directory too** (47 and 48 menus, down from 49 and 50). Same reasoning as
the Employee decision, and it lands in the same place — **§3.8**.

---

# G-SEED-02 — DISABLED MENUS WERE BEING GRANTED · **S2, CAUGHT PRE-APPLY**

Name matching was blind to `status`. `Certifications` matched **menu 25**
(`status=0`, under User Management) as well as **menu 158** (`status=1`, the
Competency screen actually meant) — and menu 158 was denied by the qualifier read
while **menu 25 was granted**.

A disabled menu does not render, so the grant was **invisible today** and would
**light up silently the day the menu was enabled**. `Holiday Master` (167) the
same.

Fixed by excluding `status != 1` from matching. **Gate A totals did not move**,
which confirms the defect was latent rather than live.

Three screens moved to `unmapped = DENIED` as a result, including **Skill Gap
Analysis** — so the generator now reaches G-RBAC-02's verdict **on its own**,
independently of the read that found it.

---

# G-LMS-SEC-01 — LMS ASSIGNMENT ENDPOINTS WERE UNAUTHENTICATED · **S1** · **FIXED**

> ## THE FIRST UNAUTHENTICATED EXPOSURE OF THE PHASE
>
> **Every other finding in this register required a valid token.** Tenant
> breaches, forged provenance, missing ownership checks, over-broad grants - all
> presuppose an authenticated caller who then reaches too far.
>
> **This one required nothing.** No token, no account, no session. An anonymous
> caller could read the enrolment register and reach the approval path.
>
> **The distinction matters to a buyer**, and it is why this outranks findings
> with a larger blast radius: everything else is a privilege question. This was
> an open door.

Queue item 1. `assignmentController` carried **four** stacked defects, and the
worst was not the one it was queued for.

### 1. Authentication was OPTIONAL — the one that matters

```php
private function validateToken(Request $request) {
    $type = $request->type;
    if ($type == "API") { ...check the token... }
    return null;              // <- no type, no check, request proceeds
}
```

**Omitting the `type` parameter skipped authentication entirely.** These are API
routes (`routes/api.php:615-625`) and **none of them carries middleware**, so this
method was the only control on the endpoint.

**Proven through the full stack before the fix:**

| Request | Result |
|---|---|
| `GET /api/lmsAssignment`, **no token, no `type`** | **HTTP 200, 20,777 bytes** of learner names and course names |
| `POST /api/lmsAssignment/review/{id}`, **no token** | reached the record lookup — **404 only because the id did not exist** |

An anonymous caller could **read the enrolment register and approve enrolments.**

### 2. The role came from the request

`$request->input('user_profile_name')` — so `user_profile_name=admin` granted
review rights.

### 3. Failure-open on an empty profile

`if ($profile !== '' && !str_contains(...))` — **omitting the parameter passed the
guard altogether.** This is what the item was queued for, and it is the third
defect, not the first.

### 4. Tenant from the request

`$request->sub_institute_id ?? $request->header(...)`, at **9 sites**.

> **All four are the exact defects `ResolvesLmsIdentity`'s own header records as
> CLOSED for the other LMS controllers.** They were still live here — so this is a
> **regression as well as a hole**, and evidence that the G-SEC-12 sweep was
> scoped by controller, not by defect.

### Fixed

The controller now `use`s `ResolvesLmsIdentity`. `validateToken()` delegates to
`guardLmsToken()` — **always, with no opt-out**; both profile guards delegate to
`guardLmsProfile()`, which resolves the role from the **token's** user and
**refuses an unresolvable profile**; all 9 tenant reads go through `lmsTenantId()`.

### Verified — and the two questions kept separate

**WHO MAY CALL THIS** — closed:

| | before | after |
|---|---|---|
| no token, no `type` | **200, 20,777 bytes** | **401** |
| forged `user_profile_name=admin` | **200** | **401** |
| `review` as employee | permitted | **403** |
| `review` as administrator | permitted | **404** (passes the gate; record absent) |

**WHICH ROWS COME BACK** — **NOT closed, and route/auth guards do not answer it:**

| | after |
|---|---|
| `GET /api/lmsAssignment` as **employee** | **200, all 48 rows** — the whole tenant's enrolment register |

**An employee still sees every learner's enrolments.** That is a **row-scope**
question of exactly the kind G-RBAC-01's 121 qualifiers are about, and it is
**unresolved**. Authentication being fixed must not be read as the screen being
safe.

---

# G-HARNESS-01 — IDENTITY LEAKS BETWEEN REQUESTS IN A SINGLE-PROCESS HARNESS · **METHOD DEFECT**

Reusing one HTTP kernel for several requests in one PHP process **caches the first
request's resolved identity and reuses it for every later request.**

**Demonstrated, not inferred** — the same two calls, order reversed:

```
order: emp-first     employee -> 403      admin -> 403      <- admin wrongly refused
order: admin-first   admin    -> 404      employee -> 404   <- employee wrongly allowed
```

**Whoever goes first decides both outcomes.** A production request is a fresh
container, so this is a **harness defect, not a product defect** — but it silently
falsifies any identity-dependent multi-request check.

### What it affects, stated precisely

| Check | Affected? |
|---|---|
| **G-LMS-SEC-01 verification** | **Was affected — re-run one request per process.** The table above is the corrected result |
| **G-COMP-SEC-01 verification** | **Re-run and unchanged** (own 200 / colleague 403 / cross-tenant 404). The leak held identity at the intended caller throughout, so the original result was right — **by luck, not by design** |
| **R9 nine-role sidebar check** | **Not affected.** It varies `profile_id` in the request, not the identity, and one acting user is intended throughout. Its nine counts differ from each other and match Gate A independently |
| **C23 tenant guard** | **FLAGGED, not cleared.** It issues two requests per URI in one process. Its calls carry **no token**, so there is no identity to cache — but **C24's release gate rests on this guard**, so it gets its own verification rather than an argument. Not re-run here |

**Rule going forward: one request per process for anything identity-dependent.**

### The same class, found alongside it - the wrong model

`App\Models\User` is backed by the **`users`** table. Real tokens are issued
against `App\Models\auth\tbluserModel`, backed by **`tbluser`**:

| tokenable_type | tokens |
|---|---:|
| `App\Models\auth\tbluserModel` | **4,511** |
| `App\Models\User` | 14 |

The first tests minted tokens on `App\Models\User` - **a different table, with
different ids and a NULL `user_profile_id`**. It produced a plausible false
"admin regression".

> **A test against the wrong model proves nothing about the running system.**
> Both affected verifications were re-run against `tbluserModel` and hold.

---

# C23 HARNESS — VERIFIED, NOT ARGUED

**C24's release gate rests on this guard, so it was measured rather than reasoned
about.**

### The argument I gave was wrong

I said C23 was probably unaffected by G-HARNESS-01 "because its calls carry no
token". **It does carry one.** `c23-tenant-guard.php:34` defines `TOKEN_A` and
`call_route()` sends it **both** as a request parameter **and** as a Bearer
header. The question was open, not closed.

### Method

For a sample stratified across **every verdict class**, re-run the same
baseline/attack pair **one request per process** and compare against the recorded
verdict. Agreement on FAIL and PASS is what clears the guard; any disagreement
invalidates it.

### Result — 20 of 23 agree

| Recorded | Isolated re-run | Agreement |
|---|---|---|
| **PASS** ×8 | identical body ×8 | **8/8** |
| **FAIL** ×5 (`job-role-tasks`, `jobroletexonomies`, `skills`, `role-similarity`, `audit/export`) | **CHANGED** ×5 | **5/5** |
| **VACUOUS** ×3 | identical ×3 | **3/3** |
| **LEAK-NOSCOPE** ×3 | identical ×3 | **3/3** — correct: LEAK-NOSCOPE *is* identical-but-carrying-tenant-B-markers |
| **FAIL** ×3 (`api/lmsAssignment/stats`, `/learners`, `/enrollments`) | identical | **explained, see below** |

### The three disagreements are my own fix, not a harness defect

All three are `assignmentController` routes. **G-LMS-SEC-01 replaced its nine
`$request->sub_institute_id` reads with `lmsTenantId()` earlier in this same
session.** The recorded run predates that change. The route now ignores the
supplied tenant, so the two responses are identical — which is the fix working.

**Incidental confirmation:** the tenant half of G-LMS-SEC-01 is now independently
verified by C23's own instrument, not only by my targeted test.

### Why it holds

Both requests in a C23 pair use the **same token**, so a cached identity is the
**same** identity and the leak has nothing to change. The tenant claim under test
travels in a **request parameter**, which is not what gets cached.

**VERIFIED — with its limit stated:** this is a **23-route stratified sample of
912**, not an exhaustive re-run. It covers every verdict class and both
directions. **C23's verdicts stand, and C24's gate may rest on them.**

---

# G-SEC-13 — `if ($type == "API")` IS A SIGNATURE, NOT AN INCIDENT · **S1** · **CANDIDATES (R6)**

`PayrollController` had it. `assignmentController` had it. Both times it meant the
same thing: **THE CALLER DECIDES WHETHER CHECKS RUN.**

Swept across the whole codebase rather than treated as two incidents.

| Measure | Count |
|---|---:|
| Controllers where a token check is gated on `$type == "API"` | **46** |
| Routes reaching them | **420** |
| **Routes with NO auth middleware of any kind** | **132** |

**132 routes** whose only authentication control is a check the caller switches
off by omitting one parameter.

### Top of the candidate set

| Controller | Routes with no middleware |
|---|---:|
| `AJAXController` | 18 |
| `libraries\skillLibraryController` | 17 |
| `lms\assignment\assignmentController` | **11 — PROVEN exposed, now fixed** |
| `talent\talent_interviewschedulescontroller` | 10 |
| `talent\talent_jobapplicationcontroller` | 10 |
| `talent\talent_jobpostingcontroller` | 8 |
| `libraries\jobroletaskcontroller` | 7 |
| `libraries\jobroletexonomycontroller` | 7 |
| `Api\skillcontroller` | 7 |
| `dashboards\SkillDashboardController` | 7 |

**CANDIDATES, not findings (R6).** One is proven — `assignmentController` returned
**HTTP 200 and 20,777 bytes to an anonymous caller**. The other 121 are
**presumed dangerous until read or probed**: the signature is identical and the
middleware is absent.

**Proxy named (R10):** *controller contains `$type == "API"` near a token check*
**and** *no route middleware matches `/auth|sanctum|profile|token/`*. A controller
passes the proxy and is still safe if some other guard runs first; it fails the
proxy and is still dangerous if its check is weak for another reason. **The
behavioural probe is the settling instrument.**

### CORRECTION — the probe did not hang, and the numbers below supersede the 132

I reported the first probe as **hung, blocking on an outbound call**. **That was
wrong.** It died with `Allowed memory size of 536870912 bytes exhausted` before
printing anything. Diagnosed from its own output once it completed, not guessed.

**The 132 is a route-level count across ALL verbs, including `{id}` routes that
cannot be probed blind.** It is the right number for *"routes with no auth
middleware reaching the signature"* and the wrong number for *"routes an anonymous
caller can reach"*. Both are stated so neither is quoted for the other (R10).

### The behavioural result — chunked so one fatal costs one chunk

| Measure | Count |
|---|---:|
| GET routes, no `{id}`, no auth middleware | **57** |
| Probed to completion | **52** |
| **Died mid-request** | **1** |
| **Answered 200 to an anonymous caller** | **4** |

**The four:**

| Route | Body |
|---|---|
| `api/kpis` | `{"status":true,...,"overallSkillCoverage":0,...}` — **a live metrics endpoint answering anonymously**. Zeroes here because this tenant has no data, not because it refuses |
| `DeepSeekChat` | Proxies to the DeepSeek API and returned its upstream error. **Anonymous access to a paid AI proxy** — cost and abuse, not disclosure |
| `api/ai-generated-assessment/question/index` | `"data":[]` — structure works, table empty |
| `api/candidate` | `"data":[]` — **re-probed against tenants 7 (150 rows) and 3 (107 rows) and still returned 0**, so a further filter is applied. Reachable, not proven to disclose |

**Honest reading: reachable surface is proven; record disclosure is not** — apart
from `assignmentController`, which is proven and fixed. The 121 remaining
candidates are **not** thereby cleared: `{id}` routes and all POST/PUT/DELETE
routes were never probed, and that is where `assignmentController`'s approval path
sat.

---

# G-SEC-15 — AN ANONYMOUS REQUEST CAN EXHAUST SERVER MEMORY · **S1**

> **RAISED FROM S2.** It discloses nothing, which is why it first read as S2 —
> but **availability is a security property.** No credential, no rate limit to
> defeat, no skill: one URL, repeated from a browser. *"Anyone on the internet can
> take the API down"* ends a procurement conversation as fast as a data leak.
>
> **Fix is BOTH, not either: auth middleware AND a bounded result set.**
> Auth alone leaves an authenticated user able to do it; a bound alone leaves the
> endpoint open.

`AJAXController@getSkillCompetency`, route `getSkillCompetency` — **no auth
middleware, no `{id}` parameter**.

An unauthenticated GET **exhausted a 512MB PHP memory limit** inside
`Connection::execute()`. It is the one route of 57 that killed its own probe
process.

**THREE problems in one route**, and any one alone leaves it exploitable:

1. **No authentication** — declared in `routes/web.php` with the comment *"Rajesh for only API temporary created for data fetch"* and no middleware.
2. **Unbounded result set** — a four-way join over the largest tables ending in `->get()` with no limit.
3. **Tenant from the request, defaulting to a hardcoded `?? 2`** — an absent parameter silently served tenant 2's data.

### FIXED — all three, because the fix had to be both and turned out to be three

`resolveApiIdentity()` supplies authentication **and** tenant; the query is bounded
and paginated (`limit` default 500, hard ceiling 2000, with `offset`, and
`limit`/`offset`/`count` returned so truncation is visible rather than silent).

| | before | after |
|---|---|---|
| anonymous GET | **512MB memory exhaustion** | **401** |
| authenticated GET | unbounded | **200, 500 rows, paginated** |
| absent `sub_institute_id` | served **tenant 2** | resolved from the token |

---

# G-SEC-16 — UNBOUNDED RESULT SETS ARE SYSTEMIC, NOT SPECIFIC · **S2** · **CANDIDATES (R6)**

The question *"do other routes load unbounded sets?"* is **different from
disclosure and had never been asked.** Asked now.

**108 GET routes** with no auth middleware reach a method that calls `->get()` with
no `limit`, `paginate`, `take`, `first`, `chunk` or `cursor` anywhere in its body.
The heaviest carry **three joins**:

| Route | Action | Joins |
|---|---|---:|
| `api/skill-development/progress` | `SkillDevelopmentController@getSkillProgress` | 3 |
| `api/leave-distribution` | `HRITDashboard\LeaveDistribution@leaveDistribution` | 3 |
| `api/leave/distribution` | `Leave\LeaveDistributionApiController@index` | 3 |
| `api/candidate` | `talent\candidate\candidateController@getCandidate` | 3 |
| `api/feedback` | `talent\feedback\feedbackController@getAllFeedback` | 3 |
| `api/pending-feedback` | `talent\feedback\feedbackController@getPendingFeedback` | 3 |

### Read this carefully — "no auth middleware" is NOT "unauthenticated"

**The controller's code is not the endpoint's behaviour**, and the inverse applies
here: **most of these controllers authenticate INSIDE the controller** through
`ResolvesApiIdentity`. Only **4 of 57** probed routes answered an anonymous caller.
So:

- **the anonymous-DoS surface is small** — G-SEC-15 was the live instance;
- **the authenticated-DoS surface is 108 routes wide**, and that still matters,
  because **any logged-in employee can exhaust server memory** on a tenant with
  real data volumes.

**This is why the fix had to be BOTH.** Auth alone would have left 108 routes
reachable by every authenticated user in the product.

**Proxy named (R10):** *method body contains `->get()` and no bounding call*. Wrong
in both directions — a naturally-small `WHERE` bounds without a `limit` (false
positive), and a method can paginate one query while another runs unbounded (false
negative). **Candidates, not findings.**

---

---

# G-SEC-14 — THE G-SEC-12 SWEEP WAS SCOPED BY CONTROLLER, NOT BY DEFECT · **S1**

**The third time a sweep's SCOPE rather than its LOGIC produced the miss.**

All four defects in `assignmentController` are ones `ResolvesLmsIdentity`'s header
records as **CLOSED**. They were closed *in the controllers the sweep visited*. The
sweep asked *"is this controller fixed?"* — never *"where else does this shape
occur?"*

### Re-swept by DEFECT SHAPE. Live instances, in files already "fixed"

| Site | Shape | Why it is live |
|---|---|---|
| **`libraries\skillLibraryController:300`** | role from request | `if ($request->user_profile_name == "Admin") { $appStatus = 'Approved'; }` — **a caller sets their own submission to Approved by passing `user_profile_name=Admin`.** A self-approval bypass. **This file was fixed by D-003 — for TENANT, not for role** |
| **`Payroll\PayrollController:1304-1307`** | role from request | `$user_profile = $request->user_profile_name` |
| **`Payroll\PayrollController:2116`** | role from request | `$userProfile = $request->user_profile_name`, passed straight into `employeeDetails($sid, …, $userProfile, $profileUserId)` — **an attacker-supplied role decides which employees are returned.** Sits **two lines** from `payrollTenantId()` and `payrollActorId()`, the D-004 fixes |
| `lms\lms_apiController:741` | role from request | candidate, use not yet traced |
| `lms\questionpaperController:57` | role from request | session-first, request as fallback |

**`PayrollController:2116` is the clearest evidence of the class:** the identity and
tenant on that line were fixed, and the **role on the next line was not** — because
the sweep was looking for tenant and actor, not for role.

### The correction to method

**A sweep must be defined by the DEFECT SHAPE and run across the whole codebase,
never by a list of files.** A file is "fixed" only for the shapes that were
searched in it.

---

# G-AUTH-01 — AUTHORIZATION MATCHED ON DISPLAY-NAME SUBSTRINGS · **S2** · **FIXED**

`RequireProfile::profileMatches()` compared the caller's **profile display name**
against the route's argument **by substring**. `str_contains('reporting manager',
'manager')` is true, so a Reporting Manager passed a gate written for HR Managers.

**Not closed by side effect.** The competency ownership check stops today's
instance reaching anything, but **the matcher would do it again** for any future
role whose name contains another's — `hr_executive`/`hr_manager`,
`department_head`/`head`. **This is precisely the failure `role_key` was
introduced (D-010) to end: authorization must key on a stable identifier, never
on wording a tenant can edit.**

### Fixed — exact match on role_key

Route arguments keep their vocabulary (`admin`, `hr`, `manager`) so no route file
changes; an `ALIASES` map resolves each to the `role_key`s it means, compared with
`in_array(..., true)`. An alias the map does not know **grants nothing** rather
than falling through to a looser comparison.

**13 profiles predate `role_key` and 4 of them have users**, so they resolve
through `LEGACY_NAMES` by **exact** name — not substring.

### Verified by differencing old against new, on the real arg-sets

Both arg-sets actually used in `routes/` (`profile:admin,hr` ×17 and
`profile:admin,hr,manager` ×6), across all 112 profiles:

**Exactly one profile decides differently, and it has zero users** — id 38
*"Deparment Administrator"*, which passed only because its name contains
`admin`. **That is the collision being removed, and a department administrator is
not an institute administrator.** Denied deliberately, recorded so it is a
decision and not an oversight.

### The same matcher elsewhere — swept, not assumed

| Site | Status |
|---|---|
| `RequireProfile:89` | **FIXED** |
| `ResolvesLmsIdentity:101` (`guardLmsProfile`) | substring — **open** |
| `LmsLearningController:1537` | substring — **open** |
| `lms/assignment/assignmentController:376,441` | substring — **open**, and worse: `$profile !== '' && !str_contains(...)` means **an empty profile is treated as permitted**, the exact bug `ResolvesLmsIdentity`'s header says was closed. Blade surface, so out of the product scope — but it is the same live database |

Queued behind the security items rather than fixed here: each needs its own
old-vs-new difference check, and batching them would hide which one moved what.

---

# G-COMP-SEC-01 — ANY EMPLOYEE CAN READ ANY COLLEAGUE'S COMPETENCY PROFILE · **S1** · **FIXED**

> ## CORRECTION — THE WRITE CLAIM WAS WRONG
>
> I reported this as **read AND write**, and called it *"silent corruption of the
> product's core asset"* — worse than payroll. **The write half was wrong.**
>
> **All five write routes already carried `middleware('profile:admin,hr,manager')`**
> — `addSkill`, `updateSkill`, `saveNotes`, `storeEvidence`, `deleteEvidence`
> (`routes/api.php:347,348,351,355,356`). `RequireProfile` resolves the profile
> from the token's user and refuses an unresolvable one. **An ordinary employee
> could not raise their own ratings or lower a colleague's.**
>
> **How I got it wrong:** I read the controller and never read the route file.
> The controller genuinely has no ownership check — that part is accurate — but
> **a route-level guard is part of the endpoint's protection and I treated the
> controller as the whole of it.**
>
> **This is R10c in a new place: the controller's code is not the endpoint's
> behaviour.** The same mistake as taking a seeder's intent for its effect.
>
> **What remains, and it is still S1:** all **eight READ routes carry no
> middleware at all**, so any authenticated employee could read any colleague's
> full competency profile — skills, ratings, assessor, manager, notes, evidence,
> career path. That is exposure of the same kind as payroll, on the data this
> phase exists to build.

**The most serious finding of the build phase, and worse than payroll.**

`EmployeeCompetencyProfileController` takes the subject from the **route**, and
never compares it to the caller:

| Method | Line | Subject |
|---|---|---|
| `show(Request $request, $id)` | `:15`, filters at `:26, 95, 159` | `$id` — route parameter |
| `addSkill(Request $request, $id)` | `:238`, writes at `:260, 273` | `$id` — route parameter |
| `updateSkill(Request $request, $id, $matrixId)` | `:318` | `$id` — route parameter |

`competencyContext()` authenticates the caller and yields the tenant, so the
**tenant** boundary holds. **The ownership boundary does not exist** in the
controller — and on the eight read routes nothing else supplies it.

### Why it still ranks first

**Read exposure, on the data the whole phase is built to produce.** Competency
ratings are more sensitive than most HR fields: they drive promotion readiness and
succession shortlists, and an employee who can see a colleague's ratings can see
where they stand against them.

**And it is the screen that must come back first** — golden thread 1 cannot be
demonstrated to an employee until Competency 154–158 is re-granted, which cannot
happen until this is closed.

### Fix

`resolveApiIdentity()` **plus an ownership check on `$id`**, the same shape as
`ResolvesLmsIdentity`. Self-service reads and writes must resolve `$id` to the
caller unless the caller holds a role that legitimately acts on others.

### FIXED — 2026-08-10

`ResolvesCompetencyContext::competencySubject()` resolves the route's `$id` to a
subject the caller may act on, and **all 13 methods** call it — not only the
three originally named. Two checks, both required:

1. **the subject must be in the caller's own tenant**, checked first so an
   elevated caller cannot probe a cross-tenant id for existence;
2. **the caller must be the subject, or hold an elevated `role_key`.**

Keyed on `role_key` (D-010), not on a substring of the display name.

**`department_head` and `reporting_manager` are deliberately absent from the
elevated set.** Their legitimate scope is *my department* / *my team*, and neither
is evaluable while `reporting_manager_id` is NULL for every user (**G-ORG-02**).
Granting them org-wide access would be **wider than the grant being closed**.

**Verified through the real request path:**

| Request | Result |
|---|---|
| own profile | **200** |
| colleague's profile | **403** |
| absent / cross-tenant id | **404** |

**Side effect worth its own line.** `RequireProfile` matches by **substring**, so
`str_contains('reporting manager', 'manager')` is true — a Reporting Manager
passed the *write* gate for **any** employee in the tenant, not just their team.
The new ownership check closes that too, because `reporting_manager` is not an
elevated `role_key`.

**Re-grant of Competency 154–158 is now unblocked** — first on the re-grant list.

---

# G-AUTH-02 — THE LAST TWO SUBSTRING MATCHERS · **S2** · **FIXED**

`ResolvesLmsIdentity::guardLmsProfile` and `LmsLearningController::canAuthor` both
matched a **substring of the display name**. Same class as G-AUTH-01.

**Neither was failure-open** — both already refused an empty profile. The defect
was collision only: `str_contains('reporting manager', 'manager')`, and the same
trap waiting for `hr_executive`/`hr_manager` and `department_head`/`head`.

**`assignmentController:376,441`'s failure-open clause is CONFIRMED already fixed**
under G-LMS-SEC-01 — only the comments describing it remain. **Not re-fixed, not
left half-recorded.**

### Fixed — one shared matcher, so the two gates cannot drift

`lmsRoleMatches()` on the trait, used by both. Exact `role_key` comparison, the
**same ALIASES and LEGACY_NAMES mapping as `RequireProfile`**, so all three gates
agree by construction rather than by coincidence. Call sites keep their `admin`/`hr`
vocabulary and none moved.

### Verified to G-AUTH-01's standard

Old differenced against new across **all 112 profiles** and **every argument set
actually in use** (`['admin','hr']` — the only one either gate passes):

**1 profile decides differently. 0 users hold it.**
Id 38 *"Deparment Administrator"*, which passed only because its name contains
`admin` — the collision being removed, and the same profile G-AUTH-01 denied
deliberately.

**Identical outcome to G-AUTH-01, which is the expected result of a shared
mapping — and checking rather than assuming it is the point.**

---

# L-11's WIDER CLASS - THE CHECKER WAS WRONG THREE TIMES. **THE COUNT IS NOT A FINDING.**

**I was one command away from converting four joins that were already correct.**
Stopped by reading the code at each site instead of trusting the count.

## The sequence: 29 -> 13 -> 4 -> **0 verified outside the dashboards**

| Stage | Count | What removed the rest |
|---|---:|---|
| raw pattern | 29 | - |
| minus commented-out | 27 | - |
| minus one-side-global | 19 | the both-sides check (correct, and predicted) |
| minus `.id` key joins | **13** | filter excluded `_id` but not a bare `id` - **9 key joins reported as text joins** |
| minus foreign-work files | 4 convertible | `jobrolecontroller` carries uncommitted work |
| **minus false positives found by READING** | **0** | see below |

## The three checker flaws, each found only by reading the site

1. **SINGLE-LINE MATCHING.** `AJAXController:1112` and `:1116` DO carry tenant
   conditions - on the NEXT line:
   ```php
   $join->on('us.skill', '=', 'u.title')
        ->on('us.sub_institute_id', '=', 'u.sub_institute_id');   // <- never seen
   ```
   A multi-line ON clause looked bare to a line-at-a-time pattern.

2. **FILE-WIDE ALIAS MAP.** `AJAXController:834` joins **`s_jobrole` - the GLOBAL
   library** - to `s_user_skill_jobrole`. The alias `s` is bound twice in that
   file (`s_jobrole` and `s_skill_knowledge_ability`); my map kept the last, so a
   global-table join was scored as tenant-scoped. **Aliases are scoped to a query,
   not to a file.**

3. **whereColumn WITHOUT ITS SIBLING CLAUSE.** `CommandCenterService:72` pairs
   `whereColumn('sj.jobrole','jr.jobrole')` with
   `->where('sj.sub_institute_id', $subInstituteId)` two lines down, inside the
   same EXISTS. Tenant IS applied.

## What survives, and on what evidence

**Only `CompetencyDashboardController`'s 4 sites - and they survive because they
were MEASURED, not matched:** 4,716 roles by text vs 4,393 by key, with 161,695
of 253,479 joined rows cross-tenant (G-DASH-01). **That is data, not a pattern.**

> **The wider class does not exist on current evidence.** 29 candidates produced
> **zero** verified findings outside the file already known from measurement.
>
> **A count from a pattern is not a finding count**, and this is the strongest
> instance of that in the phase: three independent flaws, each invisible to the
> others, all pointing the same way - **over-reporting.**

## The rule this earns

**Before converting ANY site a pattern found, read the site.** The both-sides
check turned 29 into 13 and felt like verification; it was still pattern work.
**Only reading the four lines around each join produced the truth.**

---

# G-SEC-24b - I INTRODUCED C27's DEFECT WHILE FIXING A SECURITY HOLE - **S2** - **FIXED, AND NOW CHECKED**

**At item 46, with every rule and guard in place.** The G-SEC-24 fix resolved the
identity and then, five lines later, read the tenant from the request:

```php
$identity = $this->resolveApiIdentity($request);     // authenticated
...
$subInstituteId = $request->sub_institute_id ?? $request->header(...);  // then trusted the caller
```

**Authenticated, then trusted the caller's tenant.** C27's exact class - *trait
present, still reads from request* - written by me, during a fix for the same
family of defect.

> **This is not a lapse to apologise for. It is evidence that the defect is
> genuinely easy to write**, which is an argument for A CHECK rather than for more
> care.

## The check now exists, and it would have caught it as it was typed

Added to the smoke suite: **no method that resolves an identity may also read
`sub_institute_id` or `user_profile_name` from the request.** Static, cheap, runs
in the standard 70-second pass.

### Two refinements the first version needed - both from its own false positives

1. **`user_id` EXCLUDED.** The first version flagged 9 methods; **8 were reading
   `user_id` as a legitimate SUBJECT** (the person being assessed). That is
   G-SEC-12's own IDENTITY vs SUBJECT distinction, and flagging it would cry wolf
   on eight valid methods until the check was ignored.
2. **COMMENTS STRIPPED.** The 9th - `LmsLearningController::isInstructor` - matched
   a **comment describing a defect that had already been fixed**. *A pattern that
   reads prose is not reading code.*

**After both: 0 offenders. The suite is GREEN at 25 checks.**

## A related discipline, from the restore that silently missed

`restore.py` searched for `$request->sub_institute_id;` against a file reading
`$request->sub_institute_id ?? $request->header(...)`. **It matched nothing,
reported nothing, and restored nothing** - the fourth pattern-match failure of the
session.

**R16's extension now applies to RESTORES as well as sweeps:** validate the
pattern against a known positive before trusting a zero result, and **a restore
that finds nothing must say so loudly rather than completing quietly.**

---

# G-DASH-01 - THE TEXT JOINS ARE OVER-COUNTING TODAY, NOT JUST FRAGILE - **S1**

**Found by the equivalence check L-11's conversion required, before converting
anything.** I expected text-join and id-join to return the same counts and to be
converting for rename-safety. **They do not agree, and the text join is wrong.**

| Join | Distinct job roles |
|---|---:|
| `jr.jobrole = jt.jobrole` (**what ships today**) | **4,716** |
| the same, plus a tenant condition | 4,522 |
| `jr.id = jt.jobrole_id` (**by key**) | **4,393** |

**The dashboards over-report by 323 roles - 7.4% - and the error has TWO distinct
causes.**

### Cause 1: the join crosses tenants. 64% of joined rows.

`jr.jobrole = jt.jobrole` carries **no tenant condition in the ON clause**. Tenant
scoping is applied afterwards to ONE side, so a job role in one tenant matches
task rows in another that merely share a name.

| Joined rows | Count |
|---|---:|
| same tenant | 91,784 |
| **CROSS-TENANT** | **161,695** |

**Nearly two thirds of the rows feeding these dashboards belong to another
organisation.** This is a live cross-tenant data-correctness defect, and it is
invisible in the output: the number just reads high.

### Cause 2: duplicate names within one tenant

**91 (tenant, jobrole) groups are duplicated - 129 extra rows.** Tenant 1 has
**"Vice President" eleven times**, "Marketing Manager" six, "Product Manager"
five. A name join matches all of them; **a key join resolves to the one row that
was meant.**

### Why this changes L-11's justification

L-11 was scoped as *"a rename would detach these queries"* - a FUTURE risk.
**It is a PRESENT defect.** The conversion does not merely make the dashboards
rename-safe; **it corrects numbers that are wrong on screen right now.**

> **The equivalence check was meant to prove the conversion was behaviour-
> preserving. It proved the opposite, and that is the more valuable result:
> converting will CHANGE these figures, and the new ones are the correct ones.**

**Nothing converted yet.** Doing so silently would have moved a headline number
with no explanation attached.

---

# G-XPROD-01 - CROSS-PRODUCT READ LEAK: HP BRAIN'S HR DATA IN G2G'S ADMIN UI - **S1** - **FIXED**

**A G2G tenant-1 administrator was shown 141 of HP BRAIN's audit rows** - `Person`
and `Department` entities, actions `manager_change`, `department_assignment`,
`archive`. **Another product's operational HR data, displayed in our admin UI.**

`LmsGovernanceController::auditLogs` read `hpbrain_audit_logs` and scoped it with
`scopeAuditToTenant`, which matches `tenant_id` against **both** the numeric
institute id **and** the string `t{id}`. **That is correct WITHIN G2G and
meaningless ACROSS PRODUCTS** - HP Brain uses the same column for its own
tenants, and 141 of its rows carry `t1`.

## Why nothing caught it - the FOURTH instance of one lesson

**Every check so far looked for leaks WITHIN G2G, across tenants.** C23 compares
tenant A against tenant B. The `{id}` probe varied ids inside one product. This
leak is **across PRODUCTS**, which no sweep was shaped to see.

> ### A check only sees differences its comparison set can express.

| # | Instance | What the comparison set could not express |
|---|---|---|
| 1 | **C28** | every tenant seeded from the same libraries - no unique marker |
| 2 | **G-SEC-17** | a route pinned to a THIRD tenant, against a set of {A, B} |
| 3 | **G-RECON-01** | a plan written in capabilities cannot name a missing event |
| 4 | **G-XPROD-01** | a tenant-vs-tenant check cannot see a PRODUCT-vs-PRODUCT leak |

**Instances 1-3 were about data and vocabulary. This one is about the BOUNDARY
ITSELF** - the sweeps assumed one product owned the schema.

## Fixed by C-SEP-01, which closes BOTH directions in one change

**This is not cleanup. It is a leak fix.**

| Direction | Before | After |
|---|---|---|
| **WRITE** | `LmsGovernanceController` inserted into `hpbrain_audit_logs` | emits a `governance.*` event; `AuditLogProjector` writes `g2g_audit_log` |
| **READ** | 4 sites read `hpbrain_audit_logs`, showing HP Brain rows | 4 sites read `g2g_audit_log`, scoped on numeric `sub_institute_id` |

### The cross-write had NEVER FIRED

G2G writes entity types `user`, `role`, `permission_matrix`. All 342 stored rows
are `Person`, `Department`, `Organization`, `Capability`, `Authorization` - HP
Brain's vocabulary. **Zero overlap.** It was **a latent coupling, not an
integration anyone depended on**, which is what made removal risk-free.

**Q-C4 settles the remaining question as policy, not code:** if HP Brain were to
expect G2G to populate a shared table, **that expectation is exactly what the
decision forbids.** Integration is API-only.

### Verified through the real request path

| Check | Result |
|---|---|
| `hpbrain_audit_logs` rows | **342 before, 342 after - UNTOUCHED** |
| one governance action emitted | `g2g_audit_log` 0 -> 1 |
| Audit tab | HTTP 200, **1 row**: `entity=role action=create actor=1 source=g2g` |
| **HP Brain rows visible to a G2G admin** | **0 - LEAK CLOSED** |
| filter options | derived from G2G's own events only |

**Nothing copied, nothing cleaned, nothing deleted.** The 342 rows remain exactly
as they were.

---

# Q-C4 — RE-EXAMINED AND **CONFIRMED**, 2026-08-10. Not superseded.

**HP Enterprise Brain and G2G are NOT to be merged. They stay separate products.**
Q-C4 stands as written: no runtime dependency, no shared tables, no cross-writes,
future integration **API only**. Harvesting the schema design **stays approved** -
reusing a design is not coupling the products.

**Marked CONFIRMED rather than superseded**, so the record shows it was
re-examined and held rather than never revisited.

**One correction to the record itself:** Q-C4's original text already said
*"sharing this database"*. The shared database was **known when the decision was
made** - it was recorded as *"a risk to document"*, not as a surprise.

---

## THE CHECK — reported from the live system

| Question | Answer |
|---|---|
| Do `hpbrain_*` tables sit in G2G's schema? | **YES — 105 tables in `hp_erp`**, ~107,488 rows |
| Does `hpbrain_schema_migrations` exist there? | **YES — 38 rows.** HP Brain's own migration system runs inside G2G's schema |
| Does G2G code touch an `hpbrain_*` table? | **YES — one file.** `LmsGovernanceController` |
| Is the cross-WRITE still live? | **YES — `:102` `DB::table('hpbrain_audit_logs')->insert([...])`.** Plus 4 READ sites (`:204`, `:1107`, `:1153`, `:1156`). The table holds **342 rows** |

**A separate `hp_brain` database also still exists (57 tables)** — fewer than the
105 co-located in `hp_erp`, so the co-located set is not a leftover copy.

> ### SEPARATION IS LIVE WORK, NOT OBSOLETE
> The tables are co-located, HP Brain's migrations run in G2G's schema, and the
> single cross-write Q-C4 marked *"to be removed"* is **still there**.

### The cross-write removal is now UNBLOCKED

Q-C4 said the cross-write goes **because "G2G writes its own audit log"**. At the
time G2G had none. **It does now:** `g2g_audit_log`, built in item 6 slice 2
(D-031) as a projection of `g2g_event`.

So the removal is no longer blocked on a missing destination - it is a
redirection: `LmsGovernanceController` emits an event, and `AuditLogProjector`
writes `g2g_audit_log`. **The same shape as `TaskAuditService`'s conversion**,
which is already done and verified.

### Registered as its own item, with a cost

| Item | Cost | Files |
|---|:-:|---|
| **C-SEP-01** — remove the cross-write: `LmsGovernanceController` emits events instead of writing `hpbrain_audit_logs`; its 4 read sites move to `g2g_audit_log` | **M** | `app/Http/Controllers/Api/LmsGovernanceController.php` (5 sites), `app/Services/Events/` |
| **C-SEP-02** — the schema separation itself: move 105 `hpbrain_*` tables and `hpbrain_schema_migrations` out of `hp_erp` | **L** | infrastructure, not application code. **Not Phase 3 work** unless Triz says otherwise |

**C-SEP-01 is application work and can proceed. C-SEP-02 is infrastructure and is
flagged, not scheduled.**

---

# G-RBAC-02b — THE SPEC-ASPIRATION PATTERN, IN CODE RATHER THAN IN THE SPEC · **S2**

**An instance of G-RBAC-02, not a new class:** *a name promising a capability that
was never built.* Previously found in `03-rbac-matrix.md` (Payroll's "own payslip",
Skill Gap Analysis). **Here it is in the product itself.**

`routes/api.php` served `CompetencyController` under the alias
`CompetencyCrudController`, and its `store()` **inserts into `s_users_skills`** - a
flat skill row.

> **What the product calls "creating a competency" has never created a competency
> in Q-A2's sense** (a named bundle of KASBA items). The endpoint, the controller
> name and three UI labels all say competency; the row is a skill.

**The UI says it too** — `cm-command-center.tsx:51` *"Create Competency"*,
`cm-competency-library.tsx:458` *"Edit/Create Competency"*, `:944` again, and the
form field is labelled **"Competency Name"**. `services/competency/command-center.ts:146`
maps `competency → /competency/competencies`.

### Fixed, at S, without growing

- **Route alias renamed** to `SkillLibraryCrudController`, so the route file says
  what it does.
- **Both controllers now name the other** and state the distinction, so two
  endpoints differing by name only cannot mislead the next reader.
- **`CompetencyController` left functionally untouched** — the library screen reads
  what it writes, and changing the target to save a rename would break a working
  screen.

### The UI labels are REPORTED, not silently changed

**Four user-facing strings call a skill a competency**, and the screen's own field
is *"Competency Name"*. **Renaming them is a product-naming decision, not a
defect fix:** the screen is called the Competency Library, so changing the button
alone would leave it inconsistent, and changing the whole screen's vocabulary is
larger than S.

**Recommendation:** rename the screen's vocabulary to *Skill* in one pass, once
`CompetencyDefinitionController` has its own UI (item 2) — at which point the
product will have both concepts and the distinction is worth showing the user.
**Not done inside Slice 1.**

---

# G-FLOW-25 — REOPEN UNDETECTABLE · **CLOSED 2026-08-10**

**Golden thread 2's F2 signal is buildable for the first time.**

`task_status_history` (D-034) makes a reopen detectable: a transition INTO an
active status FROM a terminal one. Verified on four transitions, **2 of 2 reopens
detected**, including the case where `approve_status='approved'` is the terminal
marker rather than the status.

### Why it was undetectable, and why that vindicates M2

**A reopen cannot be seen from the task row at all.** By the time a task is
reopened, the row that said *"completed"* **has been overwritten** — the task table
holds only the CURRENT state. The transition exists nowhere except the event
stream.

> **M2 split F2 from F1 and made F2 wait for transition history. That was
> right, and this is the retroactive proof: the signal genuinely did not exist
> until the store did.** It could not have been built earlier by trying harder —
> there was nothing to read.

A projection is exactly the tool for this: **it holds what the source table
forgets.**

---

# G-RECON-01 — A NAME-BASED CHECK CANNOT FIND WHAT THE OTHER SIDE DOES NOT NAME · **METHOD**

Raised as its own entry, not a footnote, because **it is the more valuable half of
the reactor reconciliation.**

The catalogue's 9 reactors were checked against `08-connection-plan.md`: 3 carried,
6 missing, 5 items added. **The reverse check — does the plan imply an EVENT the
catalogue lacks? — returned nothing.**

**That result is not a clean bill.**

`08-connection-plan.md` **names no events at all.** The only dotted tokens in 852
lines are column references (`task.skill_id`, `task.status`). **The plan speaks in
CAPABILITIES; the contracts speak in EVENTS.** A name-based comparison between
them can only ever fail in one direction.

> ## THIRD INSTANCE — a check can only see what its comparison set can express

| # | Instance | What the comparison set could not express |
|---|---|---|
| 1 | **C28** | Every tenant seeded from the same global libraries, so no title was unique — markers had to be hand-picked |
| 2 | **G-SEC-17** | A route pinned to a THIRD tenant produces no difference for a set of {A, B} |
| 3 | **G-RECON-01** | A plan written in capabilities cannot name a missing event |

**All three: the limit was in WHAT WAS COMPARED, not in how.** Instances 1 and 2
were about data; this one is about vocabulary — **two documents can both be
correct and still be unreconcilable by matching.**

### The real check, SCHEDULED with its trigger

Closing this needs a **semantic pass**: read each of the plan's ~45 items and ask
*"what state change does this imply, and does the catalogue carry it?"* That is
real work, not a grep.

**Deliberately NOT run now.** It cannot truly complete until the connections are
being built, and it belongs after the foundations rather than in front of them.

> **TRIGGER: when Tier 3 connection work starts** — that is when a missing event
> would actually bite. **Filed with a trigger, per the same rule the event
> catalogue enforces at build time: a deferred item without a trigger is one
> nobody will remember to enable.**

---

# G-SEC-23 — CROSS-TENANT READ BY AN ORDINARY EMPLOYEE · **S1** · **JUMPS THE PAUSE**

**Found by the `{id}` read probe. Hand-verified (R6), not inferred from a status code.**

An **Employee** token (user 198, tenant 7) reads **tenant 3's** records by changing
the number in the URL.

| Route | Reach chain (R20) | Evidence |
|---|---|---|
| **`api/user-signup/{id}`** | `routes/api.php:967` → `Api\signup_api\UserSignupController@show` → middleware **`api,api.token`** — authenticates, **does not tenant-scope** | id=6 (tenant 3) → **HTTP 200, 4,224 bytes**, containing tenant 3's user *"kalpesh"* |
| **`api/feedback/{id}`** | `routes/api.php:812` → `talent\feedback\feedbackController@getFeedback` → **NO middleware** | id=18 (tenant 3) → **HTTP 200, 916 bytes**, containing *"rajaram@gmail.com"* |

**Verification method:** fetch the tenant-3 row's own identifying field from the
database, then assert it appears in the response body. Two other candidates
(`skill_library/competency/{id}/detail`, `competency/library/skills/{id}`) returned
**404 for the same ids** — correctly tenant-scoped, and **ruled out rather than
counted**.

## The probe's numbers — separate, with definitions attached (R10)

| Measure | Definition | Count |
|---|---|---|
| Requests issued | GET, real ids, employee token, chunked | **1,819** |
| Routes probed | `api/*` GET with exactly one `{param}` | **113 of 113** |
| **REACHABLE** | HTTP 200 with a body > 60 bytes, using **other-tenant** ids | **23 routes** |
| **DISCLOSING** | hand-verified to contain **another tenant's data** | **2 confirmed so far** |

> **REACHABLE IS NOT DISCLOSING.** 23 is the candidate set; 2 are confirmed and
> **21 remain unverified**. Quoting 23 as a leak count would be the same error as
> quoting 132 unguarded routes as 132 open doors.

## VERIFICATION OF ALL 23 — complete (option 2)

**114 route+id pairs**, each tested to the same standard: fetch the tenant-3 row's
own identifying field from the database, assert it appears in the response body.

| Verdict | Routes |
|---|---:|
| **DISCLOSING** | **3** |
| NOT DISCLOSING | 20 |
| INDETERMINATE | 0 |

### Grouped by reach chain — and they do NOT share one fix

| Chain | Routes |
|---|---|
| **middleware `api`, NO auth** | `api/feedback/{id}` — *rajaram@gmail.com* · `api/competency/audit/user-actions/{userId}` — *kalpesh* |
| **AUTHENTICATED BUT UNSCOPED** | **`api/user-signup/{id}`** — 4,224 bytes, *kalpesh* |

> **`api/user-signup/{id}` is the more serious.** It carries `api.token`, so it
> **authenticates and then does not tenant-scope**. The fix is NOT "add auth" —
> it is **the same missing layer as G-SEC-09, in a route that LOOKS protected.**
> A reviewer skimming the route file would call it guarded.

### FIXED — by CHAIN, and re-verified

| Chain | Route | Fix |
|---|---|---|
| **A** — `api` group, no auth | `api/feedback/{id}` | Auth **and** tenant clause. `$subInstituteId` was already being *resolved and then never used*; auth was gated on `$type == "API"` (G-SEC-18 form 1) |
| **A** | `api/competency/audit/user-actions/{userId}` | Both activity queries were already scoped - **the NAME lookup at `:556` was not**, so a foreign user's activity was empty but their name was still returned. Narrow, and real: it confirms an id exists in another tenant and attaches a person to it |
| **B** — authenticated, unscoped | `api/user-signup/{id}` | Tenant clause only. **The fix is not "add auth"** |

> **THE GROUPING EARNED ITSELF TWICE.** The audit route's leak is **name-only**,
> not record-level — materially narrower than the other two. **Patched ad hoc, all
> three would have been written up as equivalent cross-tenant leaks.**
> **Overstating a finding costs the same credibility as understating one.**

**Re-verified with the corrected verifier, all 23 routes, 114 route+id pairs:**

| Verdict | before | after |
|---|---:|---:|
| **DISCLOSING** | **3** | **0** |
| NOT DISCLOSING | 20 | 23 |
| INDETERMINATE | 0 | 0 |

**All three moved to NOT DISCLOSING. The other 20 are unchanged** - checked
explicitly, not assumed.

### THE HARNESS CORRECTION — the strongest argument for the whole discipline

**The first verifier would have published "1 of 23": an UNDER-COUNT presented as
complete.**

That is **harder to catch later than an over-count**, because **nobody re-examines
a small number that looks thorough.** An inflated figure invites challenge; a
modest one invites trust.

It was caught only because a **known-positive disagreed** (R16) - a route already
confirmed DISCLOSING came back NOT DISCLOSING, and that contradiction was the only
signal. Without the earlier hand-verification of those two routes there would have
been nothing to disagree with.

**Two of the three most consequential measurement errors of this phase were caught
by R16** - S-2's 111 phantom endpoints, and this. **It is the rule with the best
strike rate.**

### The mechanism (R4, ~15th)

The first verifier kept only the **largest** response per route and tested that one
id against markers from whichever table it was pooled from. For
`api/user-signup/{id}` that selected `id=1` — not the `tbluser` id actually
disclosed — and returned **NOT DISCLOSING for a route already confirmed
DISCLOSING at id=6**.

**It would have reported 1 of 23.** Caught because a known-positive disagreed
(R16). Corrected to test **every** 200-returning id against **its own** row's
markers; a route discloses if **any** id discloses. The corrected run reproduces
both original confirmations **and finds one more**.

**Also observed, for the capacity workstream (G-SEC-16):**
`api/templates/{id}/versions` returned **3.4 MB** in one response.

**192 HTTP 500s** across the run are unclassified — a route erroring on a foreign
id is not evidence either way, and they are recorded rather than scored.

---

# G-CHAIN-01 — THE CHAIN NEITHER HALF SHOWS ALONE · **S1** · **CLOSED AT BOTH ENDS**

> **door → catalogue → raw interpolation**

| Hop | Site | What it contributed |
|---|---|---|
| **1. The door** | `CustomModuleController:74` | `table_name` validated as `required|string|unique` — **arbitrary characters accepted** — and the feature **creates tables from it** |
| **2. The catalogue** | MySQL `information_schema` | A table created from that string puts an **attacker-chosen identifier into MySQL's own catalogue** |
| **3. The raw statement** | `AJAXController:226` — ``DB::select("SHOW COLUMNS FROM `$tableName`")`` | `$tableName` comes from `SHOW TABLES`. **Safe ONLY because the catalogue cannot hold a backtick-bearing name** — which was true only while nobody could create one |

**Each hop is defensible on its own. The chain is not.** `AJAXController:226` was
reviewed and passed as *"the value comes from the catalogue, not the request"* —
**and that was true by accident**, dependent on a validation two files away that
did not exist.

**Closed at hop 1 (G-SEC-20, whitelist at the door) and hop 3's premise is now
guaranteed rather than assumed (G-SEC-21, validation at the point of use).**

**Recorded as its own entry because neither finding shows it.** It is visible only
by reading G-SEC-20 and G-SEC-21 together.

---

# SHAPE-01 — "TRUSTED BECAUSE OF WHERE IT CAME FROM" · **FILED, NOT SWEPT**

**Data treated as safe because of its SOURCE, when that source is itself fed by
user input.**

Two instances, both from this round, both where the safety argument was about
provenance rather than content:

| Instance | The argument made | Why it held |
|---|---|---|
| `CustomModuleController:225` | *"`table_name` comes from the database, not the request"* | **False** — the database got it from the request, unvalidated |
| `AJAXController:226` | *"`$tableName` comes from MySQL's catalogue"* | **True only by accident** — see G-CHAIN-01 |

> **"It is from the database" and "it is from the catalogue" have both now been
> used as safety arguments in this codebase, and one of them was only true by
> accident.**

**FILED as a known shape with these two instances. NOT swept** — the queue is long
enough, and the decision on when it gets a sweep is Triz's.

---

# G-SEC-22 — `tableDelete` HAD NO AUTHENTICATION AND NO TENANT SCOPING · **S1** · **FIXED**

Left open deliberately when G-SEC-20 was fixed, so an injection fix did not
quietly become an authorisation fix. Closed here.

### Reach chain (R20)

| Layer | Finding |
|---|---|
| Route | `routes/web.php:190` — `DELETE /custom-module/table-delete/{id}` |
| Group | `Route::group(['prefix' => 'custom-module'], ...)` — **prefix only** |
| Middleware | **`web` alone** — session and CSRF, **no `auth`** |
| Method | Took `$id`, found the row, dropped the table, deleted the row. **No tenant, no user, no auth check** |

### Fixed

`customModuleTenantId()` — token first (for the mobile/API callers `is_mobile()`
serves), session second (the rest of the controller reads the tenant from the
session at `:24`, `:69`, `:310`), **null when neither identifies the caller**.
`tableDelete` refuses on null, and both the lookup **and** the delete are scoped to
that tenant.

**Verified:**

| Request | Result |
|---|---|
| anonymous | **401 Unauthenticated** |
| authenticated, id not in the caller's tenant | **404 Module not found** |
| legitimate row | **untouched** — 1 row before and after |

---

# G-SEC-21 — STORED-THEN-EXECUTED: IT IS A DESIGN ISSUE, NOT A LIST OF SITES · **S1** · **FIXED**

**Bounded sweep, one question:** *does any DB-sourced string reach DDL, a raw
statement, or a dynamic table/column name?*

## Size — within the bound, so fixed rather than escalated

| Surface | Result |
|---|---|
| `Schema::create/drop/table/rename` taking a variable | **none** |
| `DB::statement` / `DB::unprepared` splicing a variable | **one** — `CustomModuleController:259`, already guarded by G-SEC-20 |
| Dynamic table names | **`CustomModuleController` + `DynamicModel`** — 21 uses, plus 6 external call sites |
| `DB::table($var)` elsewhere (`ApprovalController`, `LibraryController`, `DevelopmentPlanController`) | **not in scope** — the value comes from **hardcoded PHP maps**, not the database, and the builder wraps identifiers |
| `AJAXController:226` — `DB::select("SHOW COLUMNS FROM \`$tableName\`")` | `$tableName` comes from **`SHOW TABLES`**, i.e. MySQL's own catalogue — **not the request**. See the chain below |

## THE STRUCTURAL ANSWER — validation existed ONLY at creation

**No.** The custom-module feature validates `table_name` **nowhere except
`tableStore`**. Every downstream use — `Schema::hasTable`, `Schema::hasColumn`,
`DynamicModel::readRecords/readSingleRecord/createRecord/updateRecord/deleteRecord`
— **trusts the stored value.**

> **So it is a DESIGN issue, and the fix is validation at each execution site —
> not a per-site patch.**
>
> **Validation at the door is necessary and NOT sufficient**, because the door was
> open for the entire life of the feature and the values are already stored.
> G-SEC-20 proved the door was unlocked.

## FIXED — one guard, at the point of use

`DynamicModel::assertSafeTable()` — **every** dynamic table name passes through
it, and nothing else can set one:

- `setTable()` (so `initialize()` inherits it),
- all five static entry points.

A table name is an **identifier, never data**. Anything outside `[A-Za-z0-9_]`
throws — **loudly, not silently**: failing quiet would return an empty result that
reads like *"no records"*.

**Verified:**

| Input | Result |
|---|---|
| `tbluser` | allowed |
| `Z_probe;SELECT/**/1` (the G-SEC-20 payload) | **refused** |
| ``x`; DROP TABLE y; --`` | **refused** |
| `readRecords` / `deleteRecord` with a payload | **refused** |

## A CHAIN WORTH RECORDING, now broken at both ends

`AJAXController:226` interpolates a table name from `SHOW TABLES` into a raw
statement. That is safe **only because MySQL's catalogue cannot contain a
backtick-bearing name unless one was deliberately created** — and until G-SEC-20,
`table_name` accepted arbitrary characters and the feature created tables from it.

**Door (G-SEC-20) → catalogue → raw statement (`AJAXController:226`).** Closed at
the door and now at the point of use; recorded because the chain is only visible
when the two findings are read together.

---

# G-SEC-20 — SECOND-ORDER SQL INJECTION: ARBITRARY TABLE DROP · **S1** · **FIXED**

**The most severe finding of the phase.** One read settled it, as scoped.

### The question that decided it: CAN A USER INFLUENCE `table_name` AT CREATION?

**Yes.**

| Step | Line | What happens |
|---|---|---|
| Validation | `:74` | `'required|string|unique:...'` — **no character constraint** |
| "Sanitising" | `:82-84` | `str_replace(' ','_')` and a `Z_` prefix. **Nothing else** |
| Storage | `:99`, `:106` | `$customModuleTable->save()` — **saved BEFORE any table is created**, so the row persists whatever the caller sent |
| Execution | `:225` | `DB::statement('DROP TABLE IF EXISTS ' . $table->table_name)` |

**Not injectable where it executes; injectable where it is stored.** Exactly the
second-order shape.

### The accidental mitigation, and why it fails

`str_replace(' ','_')` defeats the naive `; DROP TABLE x` — the spaces become
underscores and the keywords break. **But MySQL accepts an empty block comment as
a separator, so a payload needs no spaces at all.**

### Proven by test, safely

```
payload            : Z_probe;SELECT/**/1
after sanitiser    : Z_probe;SELECT/**/1     <- survives intact, no space to replace
would execute      : DROP TABLE IF EXISTS Z_probe;SELECT/**/1
RESULT             : *** ACCEPTED - multi-statement executes ***
```

The DROP named a table that does not exist and the injected statement was a
`SELECT`. **Nothing real was touched** — but the acceptance is the whole finding:
`Z_a;DROP/**/TABLE/**/tbluser;--` would have run.

### REACH CHAIN (R20)

| Layer | Finding |
|---|---|
| **Route** | `routes/web.php:190` — `DELETE /custom-module/table-delete/{id}` |
| **Group** | `Route::group(['prefix' => 'custom-module'], ...)` — **prefix only** |
| **Middleware** | **`web` only** — session and CSRF. **NO `auth`** |
| **Method** | `tableDelete():214` — **no authentication check, no tenant check, no user check.** It takes `$id`, finds the row, and executes |

**Stated precisely, not maximally:** CSRF means a pure outsider needs a token from
a page first, which is a real barrier. **But there is no authentication and no
tenant scoping**, so any session that can obtain a CSRF token can delete **any
tenant's** module and drop whatever its stored name expands to.

### FIXED — both locks, per the G-SEC-15 principle

1. **At the door** — `'regex:/^[A-Za-z0-9_ ]+$/'` and `max:60` on `table_name`.
2. **At the execution site** — `safeTableName()` returns null unless the stored
   value matches `^[A-Za-z0-9_]+$`, and the caller **skips the statement** rather
   than running it. For rows written before the validation existed.
3. **`SHOW TABLES LIKE` at `:27`** — the same value, the same exposure — is now
   **bound** rather than interpolated.

**Verified:**

| Stored name | Result |
|---|---|
| `Z_customers` | allowed |
| `Z_probe;SELECT/**/1` | **REFUSED — statement skipped** |
| `Z_a;DROP/**/TABLE/**/tbluser;--` | **REFUSED** |

**Existing data checked before the fix: 1 row, and it matches the whitelist** — so
nothing legitimate is broken and no stored payload is sitting in the table.

### STILL OPEN — deliberately not fixed in this pass

**`tableDelete` has no tenant scoping and no auth check.** The injection is
closed; **the missing authorisation is not**. It needs the same treatment as the
other identity findings and is queued rather than bundled into an injection fix.

---

# G-SEC-19 — THE INJECTION SWEEP · **S1**

**A class this phase had never asked about.** Every prior finding was identity or
scoping. The question here is distinct, under G-SEC-18's discipline:

> ### Does any request-supplied value reach raw SQL, a `raw()` call, a `whereRaw`, an `orderBy` on a request field, or a concatenated query string?

**No count is quoted that has not been hand-verified.**

## PROVEN — payload changed the result

| Site | Evidence |
|---|---|
| `lmsmappingController::getData` / `getDataPre` | `chapter_id=1` → **0 rows**; `chapter_id=1' OR '1'='1` → **10 rows**. **FIXED**, payload now returns 0 |

## CONFIRMED BY READ — request value reaches raw SQL, no payload fired

Stated as a weaker claim than "proven", deliberately.

| Site | Path | Status |
|---|---|---|
| `lms/courseController:112-113 → :137` | `$grade` / `$standard` from `$request->input()`, concatenated into `DB::select` | **FIXED** — `?` placeholders + `$bindings` |
| `lms/counselling/counsellingExamController:345 → :399` | `$online_exam_id` from `$request->get()`, concatenated into `DB::select`. (`$user_id` came from the session) | **FIXED** — `?` placeholders + bindings array |

## RULED OUT — ORDER BY and LIMIT, tested rather than assumed

Included because they are *"commonly missed as they do not look like a WHERE
clause"*. **They are not injectable here**, and the test says why:

| Payload into `orderBy($col)` | Rendered SQL |
|---|---|
| `id` | ``order by `id` asc`` |
| `id, (SELECT 1)` | ``order by `id, (SELECT 1)` asc`` |
| ``id`; DROP TABLE x; --`` | ``order by `id``; DROP TABLE x; --` asc`` — **the backtick is DOUBLED, no break-out** |
| direction `asc; DROP TABLE x` | **REJECTED** — *"Order direction must be asc or desc"* |

**Laravel wraps the column as an identifier and escapes internal backticks, and
validates the direction.** `AJAXController:377` passes `$request->sort_order`
straight in, and the worst outcome is a SQL error on a non-existent column — a
robustness issue, **not injection**.

> ## CLASS CLOSED, WITH EVIDENCE — NOT LEFT UNEXAMINED
>
> **This leg produces ZERO findings, not a list of candidates.** Reporting the ~14
> `orderBy($var)` sites as an injection count would have been a fabricated number.

`whereRaw` / `orderByRaw` / `havingRaw` / `selectRaw` carrying a request value:
**none found.**

> **Do not re-open the ORDER BY / LIMIT leg on a pattern match.** A grep returns
> ~14 `orderBy($var)` sites; the builder's own output shows every one of them is
> wrapped and escaped. **The class is closed by test**, the same standing as any
> proven negative — and reporting those 14 would have been a fabricated count of a
> vulnerability that does not exist here.

## STILL OPEN — named, not silently dropped

- `CustomModuleController:225` — `DB::statement('DROP TABLE IF EXISTS ' . $table->table_name)`. The value comes from the **database**, not the request, so it is not directly injectable — but **whether `table_name` is user-controlled at creation time is unexamined.** A stored-then-executed path.
- POST/PUT bodies were not swept; this pass covered controller source, so the coverage is by code, not by verb — but the `{id}` and write-verb probe remains the way to exercise them.

---

# G-SEC-19 — SQL INJECTION IN `lmsmappingController` · **S1** · **FIXED**

**Found by running the fail-closed verification, not by looking for injection.**

`getData` and `getDataPre` both build `$extra` by **string concatenation** and
splice it into raw SQL:

```php
$extra .= " AND chapter_id = '".$chapter_id."'";   // $chapter_id = $request->get('chapter_id')
...
$data = Db::select('SELECT * FROM lms_mapping_type AS a WHERE a.parent_id=0 '.$extra.'
    UNION SELECT * FROM lms_mapping_type AS b WHERE b.parent_id != 0 '.$extra);
```

**The request value reaches the database as SYNTAX, not as data.**

### Proven behaviourally

| `chapter_id` | rows |
|---|---:|
| absent | 10 |
| `1` (honest, no match) | **0** |
| `1' OR '1'='1` | **10** |

The payload changed the result. That is the whole proof.

### Fixed — bound, both methods

`?` placeholders with a `$bindings` array, passed as `Db::select($sql, array_merge($bindings, $bindings))`
(the UNION repeats `$extra`, so the bindings are supplied twice, in order).
`AND globally = '1'` stays a literal — no request input is involved.

**After the fix:** absent → 10, `1` → 0, payload → **0**. The payload now behaves
exactly like an honest non-matching value, and the legitimate paths are unchanged.

---

# THE FAIL-CLOSED VERIFICATION — WHAT IT ACTUALLY FOUND

Asked for as a one-line-each confirmation. It found **two defects and one
correction**, which is why it was worth asking for.

### 1. A bug in my own fix — `session()` throws, it does not return null

```php
$request->session()?->get('sub_institute_id')   // WRONG
```

`$request->session()` raises **"Session store not set on request"** when there is
none, so the null-safe operator never gets the chance to help. **An API call with
an unusable token would have died with a 500 instead of failing closed.**

Corrected to `$request->hasSession() ? $request->session()->get(...) : null` in all
six controllers.

> **`null` failing closed was the right instinct; `null` never arrived.** The
> requirement *"not an error, and not a query with the clause silently dropped"*
> is exactly what caught it.

### 2. The routes are behind auth middleware — so 401 proves the route, not the resolver

An anonymous HTTP request returns **401** and never reaches the fixed code. The
property had to be exercised **at the method**, with a Request carrying no token
and no session. **A green HTTP probe here would have proved nothing.**

### 3. Correction — `lms_mapping_type` is a GLOBAL reference table

The verification reported *"10 rows returned, query has no tenant clause"* and I
first read that as a leak. **It is not.** `lms_mapping_type` has **no
`sub_institute_id` column** (56 rows, global). **No tenant clause is correct
there**, and my check counted `final_data` — which comes from that global table —
rather than the tenant-scoped `chapter_topic_data`.

**Confirmed fail-closed:** the two tenant-scoped queries
(`chapter_master`, `topic_master`) both filter on `sub_institute_id` and return
empty when it is null.

---

# CORRECTION — `getDataPre` IS FLAG-GATED

I reported it as the worst instance because it *"needs nothing — no flag, no
omission"*, and proposed the framing that it is **currently wrong for every
caller**. **That was wrong, and I am correcting it before it is recorded.**

`lmsmappingController::index:51-52` calls `getDataPre($request)` **only when
`$request->has('preload_lms')`**. The literal assignment is unconditional *inside*
the method; the method is not unconditionally reached.

**So it is form 3 like the others**, not a fourth and worse form. No customer has
been silently reading tenant 1's content on every request; they would have to hit
a `preload_lms` URL.

> **This is the same error as G-COMP-SEC-01, one level down: I read the method and
> not its caller.** There the controller's code was not the endpoint's behaviour;
> here the method's code was not the method's reachability. **Same shape, second
> occurrence — the boundary of what you read is the boundary of what you know.**

**The downstream-consumer check was still run** and is what produced the
correction: the only caller is `index()`, and one other controller
(`lms_lessonplanController:29`) uses `preload_lms` **correctly**, resolving the
tenant from the session — so it was never in the affected set.

---

# G-SEC-18 — **A REQUEST FIELD SWITCHING THE IDENTITY MODEL** · **S1** · the named pattern

Three variants of **one idea**, each found separately, each treated as its own
incident until now. **They are one pattern, and the sweep should look for the
pattern — not run three greps.**

> **The caller supplies a field, and the server changes WHO IT THINKS THEY ARE —
> or whether it asks at all.**

### The three known forms

| # | Form | Instance | What the field does |
|---|---|---|---|
| 1 | **Switches the CHECK off** | `if ($type == "API") { …verify token… }` | Omit `type` → **authentication does not run** (G-SEC-13: 46 controllers, 132 unguarded routes) |
| 2 | **Switches the TENANT by default** | `$sub_institute_id = $request->sub_institute_id ?? 2` | Omit the field → **become tenant 2** (G-SEC-15; `AnalyzeJDController:28` → tenant 3) |
| 3 | **Switches the IDENTITY by flag** | `if ($request->has('preload_lms')) { $sub_institute_id = 1; $user_profile_name = 1; }` | Set the flag → **become tenant 1 with an elevated role** (G-SEC-17) |

Form 3 is not a fallback at all. It is *"if the caller sets a flag, become tenant 1
with an elevated role"* — **the identity model itself is selected by the request.**

### `lmsmappingController::getDataPre` — CORRECTED, see the correction above

The assignment is unconditional **inside** the method, but the method is reached
**only** via `preload_lms` (`index:51-52`). **It is form 3, not a worse fourth
form.** My original framing — *"needs nothing, wrong for every caller"* — came
from reading the method without its caller and is withdrawn.

### What the sweep looks for, from now on

**ONE QUESTION, NOT THREE GREPS** — kept verbatim, because three greps each miss
what they were not written for and one question does not:

> ### Does any request-supplied value reach a decision about WHO the caller is, WHAT TENANT they are in, or WHETHER A CHECK RUNS? Tenant literals, role literals, and guard conditions keyed on a request
field are all the same finding.

### FIXED — all nine sites of form 3

`resolvedTenantId()` in the six affected controllers: **token first, session for
the Blade screens that have no token, and `null` when neither identifies the
caller** — so every `where sub_institute_id = ?` matches nothing. Failing closed,
the contract `ResolvesApiIdentity` already documents. `chapterController`'s
hardcoded `$user_profile_name = 1` is resolved from the session the same way.

All six controllers verified to load with the trait resolved.

---

# C23 / C24 — THE THIRD-TENANT BLIND SPOT · recorded against BOTH

**A route pinned to a THIRD tenant is invisible to a two-tenant differential
test.** It returns the same response as tenant A and as tenant B, so it scores
**PASS**. C28's marker scan searches only for tenant-B strings, and tenant 1 is
neither A nor B.

> **Today's verified-green read half has a known shape it cannot detect.**

### Second time the marker set's composition decided what could be seen

| | Limit |
|---|---|
| **C28** | Every tenant is seeded from the same global libraries, so no title was unique — markers had to be hand-picked, and personal names excluded |
| **G-SEC-17** | A route pinned to tenant 1 produces no difference for a comparison set of {A, B} to express |

**Both times the limit was in WHAT WAS COMPARED, not in how.**

> ## A differential test can only see differences its comparison set can express.

### The fix needs both legs — neither covers it alone

**(a) a third-tenant marker pass** — necessary, but **it generalises badly**: a
route pinned to tenant 5 would still pass.

**(b) the STATIC check for tenant and role literals in resolution paths**, which
G-SEC-17 has now produced and which is bounded by the code rather than by the
sample of tenants.

**(b) folds into C23's suite as a standing check — it runs WITH the guard, not
beside it.** A static check kept in a separate script is a check that stops being
run.

### Before the write half is built

**Confirm the write half does not inherit the two-tenant design.** If it does, it
inherits this blind spot, and discovering that after building 772 routes' worth of
coverage would be expensive. **Registered as a precondition on the write half, not
a follow-up.**

---

# G-SEC-17 — HARDCODED TENANT LITERALS IN RESOLUTION PATHS · **S1**

The `?? 2` in `getSkillCompetency` was **the second instance of a signature**, not
an incident: `AnalyzeJDController:28` falls back to tenant **3**, `getSkillCompetency`
defaulted to tenant **2**. **Both were found by accident, neither by looking.**

Swept deliberately. **Nine sites across seven LMS controllers assign a tenant
literal UNCONDITIONALLY** — not as a fallback:

| File | Line |
|---|---|
| `lms/chapterController` | 113 |
| `lms/contentController` | 35, 535 |
| `lms/flashcard/flashcardController` | 37, 119 |
| `lms/lmsmappingController` | 94, 155 |
| `lms/teacher_resource/lms_teacherResourceController` | 52 |
| `lms/topicController` | 35 |

```php
// chapterController::getData
if ($request->has('preload_lms')) {
    $sub_institute_id = 1;          // <- not a fallback. An assignment.
    ...
    $user_profile_name = 1;         // <- and the role, too
}

// lmsmappingController::getDataPre
$sub_institute_id = 1;              // <- unconditional
```

**Any caller who sets `preload_lms` reads tenant 1's LMS content**, whatever tenant
they belong to. The role is hardcoded alongside it.

### Why C23 could never have found this

**C23's proxy is differential:** call as tenant A, call as tenant B, compare. A
route hardcoded to tenant 1 returns **the same response both times** — so it scores
**PASS**. C28's marker scan only searches for **tenant B** strings, and tenant 1 is
neither A nor B.

> **A route pinned to a THIRD tenant is invisible to a two-tenant differential
> test.** This is a structural blind spot in the guard, not a bug in it — and it
> is the second time the marker set's composition has decided what could be seen.
>
> **C24's read half needs a third-tenant marker pass before it can be called
> complete.** Recorded against the gate.

---

# G-ATT-SEC-01 — PUNCH IN OR OUT AS A COLLEAGUE · **S1** · **FIXED**

### Route file first, as required

`routes/api.php:586-590`. The group carries **NO middleware**, and the route file's
own comment declares the intent: *"Self service - my attendance calendar and
punches"*. **Nothing at the route layer supplied the missing check** — so the
controller reading `$request->input('employee')` was the whole of the control.

`'employee' => 'required'` validated that the parameter was **present**, never that
it was **the caller**.

### Fixed

`punchSubject()` resolves the subject to `$context['user_id']` — token-derived —
across all three sites (`punchIn`, `punchOut`, and the punch-out fallback lookup at
`:353`, which the first pass missed and a follow-up grep caught).

A mismatched `employee` is **refused, not silently ignored**: rewriting it would
hide a client bug and leave the audit trail disagreeing with what the client
believed it sent.

**Verified, one request per process (G-HARNESS-01):**

| Request | Result |
|---|---|
| caller 2, `employee=3` (a colleague) | **403** — *"You may only record your own attendance."* |
| caller 2, `employee=2` (self) | **200** — punch saved |

**Test data removed** (R8: the row was read before deleting — id 994,
`ipaddress_in=127.0.0.1`, today, no punch-out; unmistakably the probe's). **This is
a shared remote database, so the cleanup is not optional.**

### WHICH ROWS COME BACK — asked separately, and it is clean here

`myAttendance` filters on `$context['user_id']` (`:44`, `:86`) — **token-derived,
caller-scoped**. Unlike `assignmentController`, the row-scope half of this screen
needs nothing.

**Menu 100 is therefore a re-grant candidate; menu 101 (Attendance Reports) is
NOT** — it is a different controller and has not been assessed.

---

---

# G-LEAVE-SEC-01 — **FIXED**, and it was a WRITE, not only a read

### REACH CHAIN — established before reporting, per the boundary rule

| Layer | Finding |
|---|---|
| **Route** | `routes/api.php:521` — `Route::prefix('leave')->group(...)` |
| **Middleware** | **NONE on the group.** The controller's own context guard is the entire control |
| **Callers** | `LeaveRequestApiController::store():146` and `LeaveOptionsController::balances():96` |

**Nothing upstream supplied the missing check.**

### Correction to my own severity framing

I described this as *"passing `employee_id` returns a colleague's leave"* — a read.
**Line 174 is inside `store()`.** So an employee could **file a leave request AS a
colleague**: a write, attributed to someone else, entering an approval workflow.
`balances():96` is the read half.

### Fixed

`leaveSubject()` in `ResolvesLeaveContext`, the same shape as
`competencySubject()`: the subject must be the caller, or the caller must hold an
elevated `role_key`, and the subject must be in the caller's own tenant (checked
first, so a cross-tenant id cannot be probed for existence).

`department_head` and `reporting_manager` are **absent** from the elevated set for
the same reason as in competency — their scope is *my department* / *my team*, and
neither is evaluable while `reporting_manager_id` is NULL for every user
(**G-ORG-02**). They return with reporting coverage, not with a fix.

**Verified, one request per process:**

| Request | Result |
|---|---|
| `employee_id=3` (a colleague) | **403** — *"You may only act on your own leave."* |
| `employee_id=2` (self) | **200** |
| no `employee_id` at all | **200** — the honest path is unchanged |

### WHICH ROWS COME BACK — asked separately, and it is NOT clean

Two things the identity fix does **not** address, both still open:

| Site | Problem |
|---|---|
| `LeaveRequestApiController::show():98` | Filters `hel.id = $id` and the tenant, **no caller check** — any employee reads any colleague's leave request by id. **This is an `{id}` route, i.e. exactly the half the probe has not reached** |
| `LeaveRequestApiController::index():40` | No caller filter — appears tenant-wide |

**Menus 102/103/104 stay DENIED.** The identity defect is closed; the row-scope
question is not, and a route guard would not answer it either.

---

---

# G-TALENT-01 — TALENT HAS NO SELF-SERVICE SCOPING AT ALL · **BUILD ITEM, NOT A PATCH**

**Zero caller-scoped queries across all 11 Talent controllers.** Not a leak to
patch — **a module where the self-service concept was never built.**

§3.x grants Employee five Talent screens on qualifiers — *referrals · own
checklist · self-appraisal · internal jobs · own exit* — and **none of those flows
exist in enforceable form.**

**Filed against §3.8 and the Talent connection items**, not against the security
queue. Patching a boundary that was never drawn is not a fix; the flows have to
be built.

---

# G-RBAC-01 — 121 GRANTS CARRY A QUALIFIER THE TABLE CANNOT EXPRESS · **S2**

**Measured 2026-08-10, not asserted.** Of the grants in `03-rbac-matrix.md`
§3.1–3.7, **121 carry a parenthetical qualifier that is doing the real work** —
and `tblgroupwise_rights_g2g` **cannot express any of it.**

| Role | Qualified grants |
|---|---:|
| Department Head | **36** |
| Reporting Manager | **35** |
| **Employee** | **31** |
| HR Executive | **19** |
| **Total** | **121** |

The table holds **one boolean per action per menu**. No row scope. No field scope.
So `V (own payslip)`, `V (team)`, `V (dept)` and `V (org — basic fields)` all
flatten to the same thing: **full access to the screen**.

**This is exactly what §3.8's field-level layer exists for**, and its scope is now
**measured rather than asserted**.

### The two kinds, and they resolve differently

| Kind | Examples | Resolution |
|---|---|---|
| **"OWN X"** | own payslip · own checklist · self · own exit · referrals | **Read the controller.** If it already scopes by the caller's `user_id`, the qualifier describes behaviour that exists and a bare `V` is safe. **Record the file:line proving it** |
| **"TEAM / DEPT X"** | team · dept · own dept | **DENY for now.** Enforcement needs `reporting_manager_id`, which exists but is **NULL for all 387 users**. Registered against the reporting-coverage readiness gate — they turn on when coverage does |

> **Where the controller does not scope, DENY.** A missing menu is a support
> ticket; an over-grant on compensation is a security finding. Same asymmetry as
> additive-vs-subtractive.

---

# G-RBAC-02 — SPEC-ASPIRATION MASQUERADING AS A PERMISSION DECISION · **S2**

**§3.1–3.7 was written from role-wise feature specs describing what the product
SHOULD do.** Where a qualifier refers to a capability that was **never built**,
there is nothing to enforce the qualifier on — **and the safe reading is always
DENY.**

### The proven instance

**`Payroll (all 7 screens)` → Employee `V (own payslip)`.**

**There is no payslip screen among the seven.** The seven are Payroll Type, Salary
Structure, Rollover Salary Structure (disabled), Payroll Deduction, Form 16, Salary
Certificate and Monthly Payroll Report — **four configuration screens, two personal
documents that take `employee_id` from the request, and one org-wide report.**

Granting the bare `V` would have shown **238 employees the organisation's salary
structure and payroll deductions** on the day the permissions fix shipped.

### CONFIRMED AS A PATTERN, NOT AN ANECDOTE

**Two independent cases, different modules, different evidence:**

| Case | Evidence |
|---|---|
| **Payroll** `V (own payslip)` | No payslip screen exists among the seven |
| **Skill Gap Analysis** `V (self)` | `status=0`, **no component and no nav entry** in the Next.js app |

> **§3.1–3.7 CANNOT be read as a description of current behaviour.**
> It is a specification of *intended* behaviour. Every qualifier in it must be
> tested against the controller before it becomes a grant.

**This is now load-bearing in the §3.8 scoping argument.**

### Flagged as likely siblings

- **Skill Gap Analysis** `V (self)` — marked (SHIP), but the menu is `status=0` with no component behind it (**G-A-04**). A qualifier on a capability that does not exist.
- **Every other §3.x grant whose qualifier implies a capability that cannot be located** — to be flagged as each batch is read.

**These are not permission decisions. They are aspirations, and they must not be
seeded as grants.**

---

# G-DUP-01 — TWO PARALLEL RIGHTS SYSTEMS FOR ONE CONCEPT · **S2**

**One live with real data, one placeholder for a newer sidebar.** Same duplication
pattern this phase exists to remove, and **the plan must decide it explicitly
rather than inherit it.**

| | Blade | Next.js |
|---|---|---|
| Menu tree | `tblmenumaster` (200) | `tblmenumaster_g2g` (188) |
| Rights | `tblgroupwise_rights` (1,254) | `tblgroupwise_rights_g2g` (4,879) |
| Reader | `MenuMiddleware` | `tblmenumasterG2gController::displaySidebarMenu` |
| Individual rights | `tblindividual_rights` (0 rows) | *(shared)* |

**Menu trees overlap but do not match: 170 ids in both · 30 legacy-only · 18
g2g-only.**

Reconciliation by profile — distinct menus with `can_view=1`:

| Role | Legacy | _g2g |
|---|---:|---:|
| Admin | **200** | 157 |
| HR | **71** | 150 |
| Employee | **169** | 157 |

**Admin loses 43 menus and HR gains 79 if the _g2g seed were taken as-is.** That
diff is the deliverable for the consolidation item, not a side effect of 4b.

**❌ X-01c IS CANCELLED.** *(2026-08-07)* **There is nothing to consolidate: two
products, two tables, each keeps its own.** The Blade UI is out of scope
(G-SCOPE-01), so its rights table is not ours to reconcile, migrate or retire.



---

# ⚠️ WHAT ELSE WAS MEASURED ON THE _g2g TABLES — inheritance check

| Artefact | Table used | Inherits the error? |
|---|---|---|
| `audit-authorization.py` → **G-SEC-04** | `tblgroupwise_rights_g2g` | ⚠️ **Partly.** Its route-to-menu map is against the **Next.js** tree. Correct **for the Phase 3 product**, but it does **not** describe the Blade UI |
| `dump-menu.php`, `nav-crossref.py` → **Gate A inventory**, `01-inventory.md`, `01b-scope-triage.md` | `tblmenumaster_g2g` | ⚠️ **Same.** The 104-row triage and the whole nav inventory describe the **Next.js** sidebar |
| `audit-auth-sweep.py`, `audit-route-controllers.py` | no rights/menu table | ✅ unaffected |
| **G-NAV-01** (menu row 219 fix) | `tblmenumaster_g2g` | ⚠️ fixed the **Next.js** tree only |

**The honest reading: this is not an error, it is a SCOPE that was never stated.**
Phase 3's product is the **Next.js** front-end, so measuring its tree was right.
**But every nav and menu figure in this phase describes the Next.js sidebar and
says nothing about the Blade UI**, and no document said so until now.

**No number needs re-deriving.** They need the qualifier attached.

---

---

# G-SCOPE-01 — THE BLADE UI IS OUT OF SCOPE · ✅ **CLOSED 2026-08-07 by decision**

**Not a documentation gap — an unexamined surface.** Every nav, menu, triage and
route-to-menu figure in this phase describes the **Next.js** sidebar. The Blade UI
has its own menu tree, its own rights table, and **its screens were never in the
audit's scope.**

## The evidence, both directions

| For "live and maintained" | |
|---|---|
| Blade views on disk | **173** |
| Controllers returning `view()` | **21** (46 call sites) |
| **Last change to `resources/views`** | **2026-07-31** |
| Last change to `routes/api.php`, for comparison | 2026-08-03 |
| Its own middleware stack | `MenuMiddleware` + `authMiddleware`, actively used |
| Its rights table | **1,254 rows, genuinely differentiated** — someone configured this |

| For "legacy on the way out" | |
|---|---|
| — | **No evidence found.** No deprecation notice, no redirect-to-Next.js, no removal commits |

**Verdict on the evidence: the Blade UI is LIVE and was touched three days before
the API surface.** Nothing suggests retirement.

## What was and was not covered — the precision matters

| | Covered? |
|---|---|
| **Blade ROUTES, tenant isolation** | ✅ **YES.** The C23 guard ran across **all six route files**, including `web.php`, `hrms.php`, `lms.php`, `settings.php`, `user.php`. Blade's routes are inside the 46 failures and the 66 token-reachable controllers |
| Blade **screens, menus, rights** | ❌ **NO.** Gate C's six write-ups audited the Next.js screens only |
| Blade **menu tree** (`tblmenumaster`, 200 rows, **30 not in `_g2g`**) | ❌ **NO** |
| Blade **rights** (`tblgroupwise_rights`, 1,254 rows) | ❌ **NO** |

**So the security hole is narrower than "unaudited surface" suggests** — tenant
isolation *was* tested there, because `authMiddleware` accepts a token and the
guard walked every route file. **What is unexamined is its features, menus and
rights.**

## Recommendation

**Treat it as LEGACY, DEFERRED — and say so explicitly, rather than leaving it
undeclared.**

Reasoning:

1. **Phase 3's product is the Next.js front-end.** Every golden thread, every module write-up and the whole connection plan target it. Auditing Blade now would restart Gate C for a UI the plan does not build on.
2. **Its routes are already covered for the one risk that matters most** — tenant isolation, via C23.
3. **The 30 legacy-only menus go with it.** They are screens the Next.js product does not have and, on the evidence of the triage, does not intend to.
4. **But it is live**, so "deferred" must mean *declared and dated*, not *forgotten* — with a stated intent to retire it, and X-01c's consolidation as the first step.

⚠️ **If Triz says it is staying**, then it needs its own Gate C pass and its rights
table needs the same curation as `_g2g` — and X-01c cannot retire either table.

## ✅ DECIDED 2026-08-07 — OUT OF SCOPE

> **Phase 3 continues with the NEW G2G INTERFACE (Next.js) only. The Blade UI is
> NOT the product being built.**

**Not "deferred with intent to retire" — simply not the product.** If it later
needs work, that is its own phase.

| | |
|---|---|
| The 30 legacy-only menus and `tblgroupwise_rights` (1,254 rows) | **belong to it** |
| Audit it? | **No** |
| Curate its rights? | **No** |
| Retire or modify its tables? | **No. Leave it entirely alone** |
| The scope qualifier on every nav/menu/triage/route-to-menu figure | **now correct BY DEFINITION rather than by accident** |

**⚠️ ONE EXCEPTION STANDS: Blade routes remain inside the C23 tenant-isolation
scope**, because `authMiddleware` accepts a token and they are reachable.
**Security coverage does not narrow with product scope.**

---

## Data provenance — read before any row-count conclusion

**M3.** Several findings rest on row counts (99% overdue, 1 progress row, 0
certificates, 169 capability ratings). Their meaning depends entirely on what this
database *is*.

### Verdict — OWNER-STATED, 2026-08-05

> **This database contains TEST DATA ONLY. There is no production tenant and no
> real customer.** The 4,518 tokens across 299 days are the owner's own team
> working on the product during development.

### The tag to apply

> **[PROVENANCE: test data — no production tenant, stated by owner]**
> Row-count findings mean **"never exercised"**. No customer is affected by any
> defect found so far, and nothing needs a separate urgent timeline.

### Methodological note — worth more than the finding

My first answer to this question was **wrong**, and wrong in an instructive way. I
asked the right question ("is this real usage or seed data?") and then answered it
**by inference from the same data** — reading 299 distinct login days as evidence
of organic customer use, when it was the development team.

The inference was reasonable and the conclusion was false. Provenance is not a
property of the data; it is a fact about the world that only the owner holds.

**Rule adopted:** *for any question of the form "what does this data represent?",
ask the owner and wait. Do not infer provenance from the artefact whose provenance
is in question.*

---

## The standing rules

All four came from the same failure mode: **a self-consistent method producing a
confident wrong answer.** None is caught by checking harder within the same
evidence.

| # | Rule | Came from |
|---|---|---|
| **R1** | **No number from an audit script is quoted until cross-checked by a SECOND independent method** — a different parser, a manual count of a sample, or a query from the other side | `G-QUAL-02` — a regex silently dropped 52 routes; the authorization audit under-reported |
| **R2** | **Provenance is asked, never inferred.** For "what does this data represent?", ask the owner and wait | M3 — 299 login days read as customer usage; it was the dev team |
| **R3** | **Before quoting any count derived from parsing, look at FIVE ACTUAL ROWS by eye.** **Extension:** *"this is test data"* is a fact about the world, **not a statement about any particular table's value** — curated reference libraries live happily in a test database. Disposability must be measured, never inferred from provenance | S1 — "9,400 capability measurements" were 293 real items and 9,105 characters of a double-encoded string. Extension — the throwaway hypothesis for L-15/16/17/19, which the row counts refuted |
| **R15** | **ASSERT THE COLUMN EXISTS BEFORE QUERYING ITS VALUES**, in the same script, failing loudly if it does not | C33 — *"column absent"* and *"value NULL"* returned the **same result** and were indistinguishable. The query measured *"did I get null"*, not *"is this row global"* — **C22's proxy defect at query level**. Right conclusion here; on a table that **does** have the column, the same shape would have read a tenant-owned row as global, wrong in the reassuring direction (R11) |
| **R16** | **EVERY SWEEP NAMES A KNOWN-POSITIVE UP FRONT AND MUST DETECT IT BEFORE ANY OUTPUT IS QUOTED** | S-5 was built to rediscover the Command Center divergent-vocabulary defect **and missed it**, so its clean result meant nothing. Same principle as C29: the C23 guard was not trusted until it watched a route go FAIL → PASS. **Origin cases: S-5** (built to rediscover the Command Center divergent-vocabulary defect, missed it) **and S-2** (rewrite failed its gate; **111 endpoints withheld**, with the reason visible in the output itself — `per_page`, `last_page` and `range_label` are **response** fields, not payload fields. Without the gate that number enters a document and takes a long time to unpick) |
| **R12** | **Every turn lands at least one queued item.** A turn may not be new investigation plus a question. If a discovery consumes the turn, the queued item still ships | Tokens (3 turns) and sweeps S-1/S-2/S-5 (5 turns) were repeatedly displaced. From the owner's side that is indistinguishable from them not happening |
| **R13** | **Standing authority — decide and report, do not ask:** minting tokens / test rows in the named tenant (registered in `11-verification-protocol.md` §6) · any read-only investigation · reclassifying my own estimates · adding register candidates · ordering work within an agreed queue · correcting prior statements. **Still ask:** destructive or bulk writes, deletions, schema changes, user-facing workflow changes, and any collision between instruction and evidence | Three turns ended on "your call" where unblocked work existed |
| **R14** | **Turns end at boundaries, not at questions.** Ask the question AND continue whatever it does not block | as above |
| **R18b** | **Anything merged verbatim from a recovery carries a DATE STAMP.** A stale line looks identical to a current one | *"2 of 32 delivered"* came back in a recovered Gate checklist and **survived three write-ups unnoticed**, contradicting a line 80 rows above it |
| **R19** | **A NUMBER ASSEMBLED FROM OTHER NUMBERS IS A NEW CLAIM.** Re-derive it end to end, with one filter, before publishing. **Verifying each input separately does not verify their combination** | **V6.** Three of four headline errors came from combining figures derived at different moments with different filters — and all three **overstated**, flipping R11's direction. Under-reporting hid risk; over-reporting costs credibility |
| **R19b** | **AN AGGREGATE IS NOT AN EXPERIENCE.** A total summed across tenants, users or profiles must never be quoted as if it described one of them. State the per-unit figure, or say explicitly that the number is an aggregate | **G-SEC-07.** *"Employee sees 1,657 menus vs Admin 1,500"* was summed across **11 tenants**. What **one user** sees is **151 vs 136**. Same family as R19: a figure assembled from parts and then read as something it never measured. **The inversion survives and remains the finding** |
| **R17** | **(a) Before writing any new measurement script, check whether an existing artefact already answers the question — reuse it. (b) When a new measurement disagrees with an old one, resolve the discrepancy BEFORE either is used** — R4b applied to my own artefacts, not just to the codebase | **C38.** The tooling was right and the correct number was already in `c21-result.json`. I wrote a fresh, narrower grep, got 17 against its 30, and acted on mine without comparing |
| **R10c** | **INTENT IS NOT BEHAVIOUR.** A comment, a `$description`, a commit message or a design note explains what someone MEANT. Only the running code says what HAPPENS. Never let the first stand for the second | **G-SEC-07, second correction.** `SeedG2gDefaultViewRights`' description says the uniform `can_view=1` is a placeholder *"until an admin curates real rights"* — **true about intent, and it was never replaced.** The product ships on the placeholder. **The same proxy error this project has now caught six times**, one level up: not a checker measuring a proxy, but a *document* standing in for a behaviour |
| **R10b** | **A PROXY THAT NAMES ONE COLUMN MEASURES ONE COLUMN, NOT THE CLASS.** Before writing down any scope figure, ask: **"what else does this pattern look like under a different name?"** Where the answer is unknown, mark it **ESTIMATE PENDING** rather than guess | **G-SEC-12.** S-3 counted `created_by` and reported **33**. The real class spans `created_by`, `updated_by`, `verified_by` and `reviewer_id` — **one pattern under four names — and the true figure was 76. Low by 2.3×.** ESTIMATE PENDING was correct and should be used the same way again |
| **R11** | **A SCOPE-SHRINKING ASSUMPTION IS VERIFIED BEFORE IT IS USED TO SHRINK ANYTHING.** State it, test it, then apply it — never the reverse | **Four consecutive errors all ran in the reassuring direction.** Random tooling error would split evenly between overstating and understating; these did not. The cause is structural: **an assumption that shrinks the work is adopted more readily than one that expands it, and the result looks tidier, so nobody questions it.** This is the only rule that would have caught all four in advance |
| **R10** | **EVERY CHECKER TESTS A PROXY — NAME IT.** Any document quoting a script's number must state (a) the property we care about, (b) the proxy actually measured, (c) how something passes the proxy and fails the property **and vice versa** | **C22** — Phase 1's sweep used *"calls `findToken()`"* as a proxy for *"resolves identity correctly"*. A controller that validates a token and discards its owner satisfies that proxy **perfectly**. That is how G-SEC-09 survived a phase dedicated to finding it |
| **R4** | **When a checker disagrees with the artefact it is checking, THE CHECKER IS THE PRIMARY SUSPECT.** Investigate the tool before reporting the artefact as wrong. A failure list is a hypothesis, never a result | Calibration C1/C1b, the Q-L1 sweep, and sweep S-4b — **eight disagreements, eight times the tool was wrong, zero times the codebase was** |

R3 is the cheapest and would have caught the most. The S1 result is why it exists:
migrating blind would have **manufactured 9,105 fake capability measurements about
real people**. Four separate counts in this phase were self-consistent and wrong,
and every one would have been caught by opening five rows.

**THE ASYMMETRY THAT MATTERS.** Cases 9 and 10 both **understated** the risk, and
that is the more dangerous direction. **A checker that under-reports produces false
confidence** — and that is exactly how `skillLibraryController` survived Phase 1: a
sweep that counted `findToken()` as a guard reported it as protected. An
over-reporting checker wastes an afternoon; an under-reporting one closes a phase
with a tenant-boundary breach still open.

R4 is the newest and the most uncomfortable, because every one of its first five cases
failed in the *reassuring* direction for the tool and the *accusing* direction for
the codebase — a checker that reports a 15% error rate feels like a checker doing
its job. The tally, in order:

| # | Reported | Real | The checker's actual bug |
|---:|---:|---:|---|
| 1 | 24 of 159 (15.1%) | 0 | Literal URI matching — a concrete segment never matched a `{param}` route |
| 2 | 3 of 159 (1.9%) | 0 | Counted an elided `...` prefix as a path segment |
| 3 | 10 of 206 (4.9%) | 0 | Could not read compound `api` notation (`"POST/PUT due_date"` is a payload field) |
| 4 | 6 of 206 (2.9%) | 0 | Applied `"POST or PUT /x"` shorthand's PUT to the collection instead of the member URI |
| 5 | 1 of 206 (0.5%) | 0 | Frontend-only file index, so a legitimately backend-anchored row was "unparseable" |
| 9 | C15: *"0 hits in trait-using controllers, so all 64 are legacy"* | — | "No trait" ≠ "not an API". `skillLibraryController` defines its **own** context method and has 12 API routes |
| 10 | C15: *"`jobroleLibrary1Controller` is unrouted"* | — | Grepped 2 route files. **There are seven.** Three controllers called unrouted are routed in `lms.php` / `settings.php` |
| 13 | PayrollController: *"18 sites in three resolution styles"* | **26 sites in FOUR** · **C38: the ENUMERATION was fine; my ad-hoc grep was not.** `c21-tenant-enum.py`'s regex has an empty alternative `(?:input\(|get\(|header\(|)`, so it **does** match `$request->sub_institute_id` — verified on all four shapes. It reported **30 hits** for PayrollController. I then wrote a narrower one-off grep for fix planning, got **17**, and acted on that. **The right number was already in an artefact I had produced. R1 — cross-check with a second method — would have caught it, and I never compared the two.** So the 66-controller scope, the 460 hits and the C27 list are **NOT understated**, and no controller was cleared by a blind instrument | `$request->sub_institute_id` — **magic property access** — matches neither `->get()` nor `->input()`. 9 sites invisible, two of them **overwriting a correctly-resolved value two lines later**. Caught only because the guard stayed red |
| 12 | C28: **4 `LEAK-NOSCOPE` routes** | **0** | Markers were selected by excluding tenant 7 only — never the other tenants, never the **global** `s_jobrole` / `master_skills` libraries. Routes serving the global catalogue tripped them. **First case to OVER-report**; the other eleven all understated |
| 11 | *"Blade route files are session-authenticated, so the tenant is already fixed"* | **FALSE** | `authMiddleware` accepts **a session OR a bare Sanctum token**. Four of six route files are token-reachable. Scope went **35 → 66 controllers**. Fourth consecutive error in the reassuring direction |
| 6 | 24 columns swept, **25 rows to explain** | — | Swept `business_link` once when there are **two** columns: `business_link` (KASA) and the misspelt `bussiness_links` (Skill). One field went unchecked |
| 7 | S-4b: 9 dead state gates | **1** | A setter passed **by reference** is a real call site — `onSelect={setSelected}`, `.then(setVerification)` never match `setSelected(` |
| 8 | (same run) | — | **Initial `useState` value ignored.** `useState(true)` starts satisfied; `setLoading(false)` only turns it off |

Had rate 1 been quoted, it would have condemned an accurate sub-module and
triggered a needless re-derivation of ~3,200 rows.

### R4's sixth case, and the narrower rule it earns

Case 6 differs from 1–5: nothing was over-reported. Two counts that should have
matched — 24 columns swept, 25 rows to explain — simply did not, and the gap was a
field nobody had looked at.

Re-checked, `bussiness_links` turned out **not** to be the clean zero the other 23
were. `skillLibraryController.php` and `SchoolSetupController.php:232,264` both
select and copy it. No behavioural consumer, so the conclusion held — but that was
luck, not method.

> **R4b — When two counts that should match do not, resolve the discrepancy before
> using EITHER number.** Not after, and not only if the conclusion looks like it
> might change.

**"Not strictly unread" and "clean zero" are different claims**, and a document
that asserts the second when the first is true has overstated its evidence even
though its recommendation was right. The temptation is to skip the check once the
answer looks unaffected; that is exactly when the check is cheapest and the
overstatement most likely to survive into a customer-facing document.

### R8 — pre-deletion checklist

**A different failure class from R1–R4.** Those catch a *tool* reporting wrongly.
R8 catches a **plan premise** being wrong — and no sweep or audit would have found
it. Only opening the file before deleting it did.

> **Before deleting any file, row or column, produce:**
> 1. **Everything it exports, and everything that references it, with call sites**
> 2. **Which of those have other homes, and where each will move**
> 3. **What becomes orphaned — reported, never silently removed**
> 4. **Whether anything outside the deleted scope changes behaviour**

**Earned by L-03R.** The panel file also exported `dash()`, used at **four live
sites** in the library table. Deleting the file on the strength of *"the panel is
unreachable"* — which was true — **would have broken every library table on all
eight tabs.** The premise was correct and the plan was still wrong.

**Applies to every remaining deletion:** the 65 nav rows, the four `s_skill_matrix`
blob columns, the free-text columns after backfill, and any dead component S-4
finds.

### R9 — after any server-side contract change, re-read every frontend consumer

> **Checklist:** which frontend files call this endpoint · what does each assume
> about the response · **does any optimistic update now assert something the server
> no longer does.**

**Earned by D-002.** The server was changed so restore returns a competency to
`Pending`. An optimistic update in `cm-competency-library.tsx:824` still asserted
`approve_status: 'Approved'` — **the UI would have shown Approved while the
database held Pending.** Static analysis cannot see it; both halves compile. Only
reading the consumer after changing the server finds it.

The same pass caught a second one: the edit payload still **sent** `status`, a
field the server now ignores — the silent-data-loss pattern this audit exists to
remove.

**Highest risk ahead: the rights-matrix population and the event store**, which
change behaviour across many screens at once. A single re-read is not enough there;
each changed endpoint needs its consumers enumerated.

> **Anchor regexes must handle `\r\n`.** Line endings have now defeated a pattern
> **twice** — once on a `$`-anchored trait-use match, once earlier in the phase.
> Costs nothing to guard; prevents the third.

Applied together: R3 before counting, R1 before quoting, R2 before interpreting,
**R4 before accusing, R8 before deleting, R9 after changing a contract.**

### ⭐ The first correction to move in the REASSURING direction

**`G-FLOW-24`, 2026-08-06.** Every prior correction in this phase made a finding
worse. This one made one **better**: `delay_category` is empty because **exactly
one task of 2,271 has ever reached `ON HOLD`** - not because nothing writes it.
`MyTasksController.php:164` validates a closed enum and `:205` writes it.

**It is provenance, not a structural defect.**

> **A register that only ever escalates is being fed, not checked.** Recording the
> one item that got better is how you tell the difference.

**Carried into golden thread 2:** the overdue/stall rule **cannot rely on
`delay_category` being populated**, because the state that fills it has been
reached once. In production it would populate; on this data it proves nothing.

---

### The direction, now beyond doubt

**Thirteen cases. Thirteen under-reports from scope-narrowing assumptions. Zero
over-reports from them.** The single over-report (case 12) came from a rule I had
*tightened*, not loosened.

R11's mechanism is not a hypothesis any more: **an assumption that shrinks the work
is adopted more readily than one that expands it, and the tidier result draws less
scrutiny.** Case 13 is the sharpest instance — a partial fix that would have read as
complete in the source, caught **only because the guard stayed red.** That is
C23-before-fixes demonstrated rather than argued.

### The provenance fact has now saved the project three times

**Recorded because it is the strongest argument for finishing the foundation work
before the first client.**

| # | Finding | What it would have cost with customers | What it costs now |
|---:|---|---|---|
| 1 | **G-DATA-04** — double-encoded blobs | Migrating blind would have manufactured **9,105 fake capability measurements about real people** | Fix the writer, normalise, move on |
| 2 | Breaking schema changes (Correction 2) | Every "for now" compromise becomes permanent | Near-free to undo |
| 3 | **G-SEC-09** — tenant-boundary breach | **Any tenant reads and writes any other tenant's competency library.** Disclosure, contractual breach, and the first thing an enterprise security review tests | A trait adoption |

**There are no customers, so nothing is exposed.** Three times now, "test data, no
clients" has converted a catastrophe into a cheap fix.

**That luck is a depreciating asset.** Each of these was found *because* someone
went looking; the next one may not be. **This is the case for finishing the
foundation before the first tenant is created** — and it is why C24 makes that a
company-level release precondition rather than an engineering preference.

### Consequences applied

| Area | Effect |
|---|---|
| Every row-count finding | Reads as "never exercised". No customer impact. No separate urgent timeline |
| Readiness-gate thresholds (`02-domain-model.md` §8) | **Cannot be calibrated from this data.** Six gates kept; thresholds labelled **UNCALIBRATED DEFAULTS**, to be tuned against the first real tenant |
| Breaking changes | **Nearly free today, expensive forever after.** Drives Correction 2 — every "for now" compromise re-examined |

Tagged conclusions:

| Finding | Number | Correct reading |
|---|---|---|
| `G-FLOW-05` | 0 certificates / 1,426 enrolments | **Never exercised.** Chain verified complete; nobody has completed content |
| `manager.md` §1.1 | 2,245 of 2,271 overdue | **Test data only.** Says nothing about task management as a discipline. Drives the readiness gate (M1), never a product-wide rule |
| `employee.md` §2 | 169 ratings / 386 users | **Never exercised.** The structural chain is the finding, not the sparsity |
| `02-domain-model.md` §8 | all six gate thresholds | **Uncalibrated.** Cannot be tuned against test data |
| `G-FLOW-24` | `delay_category` 0 rows | ⚠️ **CORRECTED 2026-08-06 — it IS provenance.** A write path **does** exist: `MyTasksController.php:164` validates a closed enum and `:205` writes it, gated on `status === 'ON HOLD'`. **Exactly 1 task of 2,271 has ever been ON HOLD.** The column is empty because the state that fills it has been reached once, not because nothing writes it. **The first finding in this phase to move in the reassuring direction** — recorded because a register that only ever escalates is not being checked properly |

### A second trap, avoided

`personal_access_tokens.last_used_at` is **NULL on all 4,518 rows**, which looks
like "nobody ever used a token". It is not evidence of anything: this application
resolves tokens manually via `PersonalAccessToken::findToken()`, and only Laravel's
`auth:sanctum` guard updates that column.

Note the pattern — that trap and the provenance error are both cases of reading
meaning into an artefact that cannot carry it.

**Findings that do NOT depend on provenance** — schema, code paths, route counts,
missing tables, absent join keys — are unaffected. Every S1 in this register is of
that kind.

---

## Counting method — read once, referenced everywhere

Stated in one place per correction **C2**, so every route figure in Phase 3 means
the same thing.

| Term | Definition | Count |
|---|---|---:|
| **API routes** | Uncommented `Route::{get,post,put,patch,delete,any}(...)` declarations in `routes/api.php` | **739** |
| — of which write routes | `POST` / `PUT` / `PATCH` / `DELETE` | **394** |
| `Route::resource` declarations | Each expands to ~7 further routes at runtime | 10 (not counted above) |
| Registered routes, whole app | `php artisan route:list`, all route files, resources expanded | 1,683 |

**Correction applied 2026-08-05.** `03-rbac-matrix.md` previously said 739 and
`07-gap-register.md` said 687. Both were measuring honestly; the parser behind the
687 matched only the short-alias form `[Ctrl::class, 'method']` and silently
dropped **52 routes** written with a fully-qualified inline class
(`[\App\Http\Controllers\...::class, 'method']`) — 11 of them on
`assignment\assignmentController` alone.

That is a defect in the audit tool, not a discrepancy in the writing, and it
under-reported the problem. Fixed in `scripts/audit-authorization.py`; **all
figures below are the corrected ones.** Both documents now say 739.

*Under-counting an authorization audit is worse than not running one — it produces
false assurance. Worth noting the correction came from a reader reconciling two
numbers, which is exactly why the counting method now lives in one place.*

---

## G-SEC-01 — 279 write routes have no authorization check · **S1** · `OPEN`

**The single most commercially serious finding in Phase 3.** This is what
enterprise security reviews test, and it is the kind of finding that stops a
procurement process.

Measured by `scripts/audit-authorization.py` over all **739** API routes. Note this
measures **authorization** ("may this caller do this?"), which is a different and
harder question than the **authentication** ("who is this caller?") that Phase 1
closed.

| | All routes | Write routes |
|---|---:|---:|
| Role-gated (some authorization) | 124 | 111 |
| **Authenticated only — no role check** | **609** | **279** |
| Unknown (method not resolvable) | 6 | 4 |
| **Total** | **739** | **394** |

**In plain terms: 71% of every write endpoint in the product can be called by any
authenticated user, including a plain Employee.** Phase 1 established that the
caller is who they claim and belongs to the tenant they claim. It did not
establish that they are *allowed* to do what they are asking.

Notably, **none of the 52 previously-missed routes were role-gated** — the
role-gated count stayed at 124 while auth-only rose from 558 to 609. Every route
the tool had been blind to was unguarded.

### Highest-risk unguarded write routes, by controller

| Controller | Unguarded writes | Why it matters |
|---|---:|---|
| `Competency\LibraryController` | 19 | Any employee can create/edit/delete the tenant's competency library — the master data the whole product resolves against |
| `Agentic\WorkflowController` | 9 | Agent workflow definitions |
| `Api\LmsLearningController` | 8 | Course content and progress |
| `Offboarding\OffboardingController` | 8 | Exit cases — access revocation implications |
| `Competency\FrameworkController` | 7 | Competency frameworks |
| `Competency\CertificationController` | 7 | Certifications — proof of capability |
| `assignment\assignmentController` | 7 | **Previously invisible to the audit** |
| `Competency\DevelopmentPlanController` | 6 | Development plans, including other people's |
| `libraries\skillLibraryController` | 6 | Skill master data |
| `Onboarding\OnboardingJourneyController` | 6 | Onboarding journeys |
| `Competency\StudioController` | 5 | Framework authoring |
| `Performance\PerformanceActivityController` | 5 | **Performance review activity** |
| `Performance\PerformanceAppraisalController` | 5 | **Appraisals** |
| `Performance\PerformanceCompensationController` | 5 | **Compensation recommendations** |
| `Performance\PerformanceBonusController` | 5 | **Bonus decisions** |
| `Performance\PerformanceCalibrationController` | 5 | **Rating calibration** |
| `Onboarding\OnboardingTaskController` | 5 | Onboarding tasks |
| `Agentic\AgentController` / `Agentic\RunController` | 10 | Agent definitions and runs |

The Performance cluster is the sharpest: **25 unguarded write routes across
appraisal, compensation, bonus and calibration.** An employee who can call
`PerformanceCompensationController` directly can alter compensation
recommendations. That is a finding a buyer's security team will find in an
afternoon.

`CompetencyLibraryController` at 19 is the widest blast radius: it is tenant master
data that frameworks, assessments, courses and tasks all resolve against.

### Why this is not the same as the Phase 1 finding

| Phase 1 (closed) | This (open) |
|---|---|
| Caller identity was taken from the request | Caller identity is now trustworthy |
| One tenant could read another's data | Tenant isolation verified from both sides |
| **Authentication** | **Authorization** |

Phase 1 made "who is calling" reliable. That is the precondition for this fix, not
a substitute for it.

### Fix

Fully specified in `03-rbac-matrix.md` §4 (three enforcement layers) and §5 (9-step
sequencing). Prerequisites: A3's route→menu map (delivered,
`_evidence/route-to-menu-map.csv`) and the role model.

**Sequencing — highest risk first**, to be carried into `08-connection-plan.md`:

| # | Group | Unguarded writes | Why first |
|---:|---|---:|---|
| 1 | Performance (activity, appraisal, compensation, bonus, calibration) | 25 | Money and ratings |
| 2 | Competency master data (library, framework, studio, skill library) | 37 | Blast radius across every module |
| 3 | Certifications + development plans | 13 | Proof of capability; regulated industries |
| 4 | Onboarding / offboarding | 19 | Lifecycle and access-revocation implications |
| 5 | LMS content + assignments | 15 | Course integrity |
| 6 | Leave / holiday | 8 | Entitlement |
| 7 | Agentic | 19 | Powerful, narrower user base |
| 8 | Remaining | ~143 | |

**Evidence:** `_evidence/authorization-coverage.json` (per-route),
`_evidence/route-to-menu-map.csv`. Reproduce with
`python scripts/audit-authorization.py --csv`.

---

## G-SEC-02 — 208 routes map to no menu, so no rights row can govern them · **S1** · `OPEN`

Direct consequence of A3. `tblgroupwise_rights_g2g` is keyed by `menu_id`; a route
with no menu has nothing to check against and will be silently skipped by any
middleware keyed that way — the most dangerous kind of gap, because coverage
metrics will read as complete.

**208** (revised up from 158 after the 739-route correction). Worst:
`Agentic\WorkflowController` 13, `assignment\assignmentController` 11,
`Offboarding\OffboardingController` 11.

**Fix:** declare the mapping explicitly in `routes/api.php`
(`->defaults('menu', '…')`) rather than inferring it. Detail in
`03-rbac-matrix.md` §5A/A3.

---

## THE THING THAT RENDERS IS NOT THE THING THAT WORKS - **one family, three instances**

Named once here rather than three times, because it is a single confusion wearing
three costumes. **Every instance was a green check measuring the near thing while
the far thing was assumed.**

| instance | the check measured | what was assumed | how it broke |
|---|---|---|---|
| **VERIFIED vs REACHABLE** | the readiness screen renders and behaves, driven by a real browser | that a customer could get to it | no menu row, no content-map entry. X-21 navigated by URL |
| **RENDERS vs WORKS** | the content map resolves to a live menu row | that the screen behind it functions | a check over a map sees only what the map mentions |
| **NAVIGATION vs CAPABILITY** | the nine-login walkthrough: sidebar 200, non-empty, expected breadth | that the role can use what it lists | `department_head` PASSES this check and every elevated endpoint refuses it |

### THE SHAPE

**A check that stops at the boundary of one layer certifies that layer and is read
as certifying the stack.** The browser proved the page; the menu proved the link;
the sidebar proved the list. None of them proved a user could do the thing, and
each was quoted as if it had.

### WHY IT KEEPS HAPPENING

The near thing is cheap to measure and the far thing is not. A sidebar returns 200
in milliseconds; establishing that a Department Head can actually run a
department-scoped report requires knowing what that means and having the data to
try it. **The cheap measurement is not wrong - it is just answering a different
question, and its greenness is indistinguishable from the answer you wanted.**

### THE TEST

**Name the layer the check ends at, in the check's own output.** The suite's
walkthrough now would read *"sidebar renders"* rather than *"the role works" -*
and `department_head` passing it would stop looking like good news.

---

## ⚠ THE READINESS SCREEN IS UNREACHABLE - **G-UI-01's SHAPE, A SECOND TIME**

    menu rows matching 'readiness'      0
    content-map entries for readiness   0
    the screen                          BUILT, TYPE-CHECKED, BROWSER-VERIFIED

**Nobody can navigate to it.** X-07d was verified four turns ago by a harness that
went **straight to `/organization/readiness`** - which proves the screen works and
says nothing whatever about whether the product can reach it.

**A URL is not a route into a product.** The harness answered "does this page
render and behave" and was read as "is this feature available". Those are
different questions and only the first was ever asked.

Same for `PUT /terminology`: 0 menu rows.

### R26 EARNED ITS KEEP - **it was ONE screen, not five**

My first check compared built page routes against menu `access_link`s and reported
**five** unreachable screens: readiness, hierarchy, employees, departments,
portal-review.

**Four of those were false.** Menus link to `/module/<module>/<menu>`, and a
dynamic route resolves the menu through `hooks/content-map-m*.ts` to a component
under `@/domain/organization/*`. Those screens are reachable by a path my check did
not model. **The check tested the wrong thing and would have raised four
non-defects against working screens.**

Only `readiness` appears in **neither** the menus **nor** any content map.

### THE CHECK ADDED, AND WHAT IT CANNOT SEE

`every content-map menu id resolves to a live menu row` - it catches a content map
naming a menu that no longer exists, which is a screen wired to nothing.

**It cannot see a screen with NO entry at all**, and that is exactly the readiness
case. A check over a map only sees what the map mentions. **The absence of an entry
is invisible to any check that starts from the entries** - which is why this is
recorded as a finding rather than handed to an assertion and forgotten.

### THE GENERAL FORM

**Verified and reachable are different claims, and X-21 only ever measured the
first.** Every screen item in this phase inherits that: the harness navigates by
URL, so a screen with no menu row passes every check it has and is invisible in
the product. The tracker counts unverified screens; **nothing counted unreachable
ones.**

---

# TWO DIMENSIONS WEARING ONE SCHEMA - **beside the two-zeros pair**

    s_skill_knowledge_ability   168,538 rows, and it is NOT one population

      attitude      1,039 rows   100.0% resolve to s_user_attitude
      behaviour     1,050 rows   100.0% resolve to s_user_behaviour
      knowledge    86,851 rows     6.5% resolve
      ability      79,598 rows     0.0% resolve

**166,449 of the 168,538 rows sit in the two dimensions that barely match
anything.** Two dimensions are a working key-resolved join; two are free text
wearing the same schema.

**A single "168,538 rows" describes neither.** Same shape as the two-zeros pair -
**one table, two populations, and the aggregate is true of no part of it.** The
pair was two counts that were both zero for different reasons; this is one count
that is large for one reason and large for the opposite reason.

**What made it visible was splitting by the column that names the population**
(`classification`) rather than counting the table. **The aggregate was never
wrong - it was never about anything.**

---

# DECIDED - **ITEM-TO-ITEM WILL NOT BE BUILT**

**Filed as DECIDED, not deferred, so nobody re-proposes it.**

All three mechanisms answer the same question - *which knowledge / ability /
attitude / behaviour does this thing require* - and differ only in what sits on
the left and how honestly the right is keyed. **`competency_kasba_item` is that
relationship expressed correctly**, at the level the rest of the chain already
resolves at.

**A third mechanism saying what the second says is the duplication this phase has
spent itself removing.**

## SCHEDULED WITH A TRIGGER - `s_skill_knowledge_ability` AS A MIGRATION SOURCE

**Not built, not dropped. Seeded, and only when the trigger fires:**

    TRIGGER   a tenant has competency rows to attach items to
    SCOPE     the 2,089 attitude + behaviour rows that resolve 100%
    EXCLUDED  the 166,449 knowledge + ability rows - converting them would
              manufacture exactly the dangling pointers the validation branch
              was built to prevent three turns ago

**No new mechanism, no deletion.**

---

# DEPARTMENT - **F-07b's ANSWER IS ALREADY IMPLEMENTED, AND THE DATA IS CLEAN**

Measured before writing the picker, and it changes the job.

## THE DATA

    s_users_skills                     5,171 rows
      department_id populated          3,924
      department text populated        3,884
      BOTH                             3,839
      neither                          1,202

    WHERE BOTH ARE POPULATED (3,839)
      AGREE                            3,839
      DISAGREE                             0
      id points at a nonexistent row       0

    TEXT BUT NO ID (45 rows)
      resolve by exact name               45
      DO NOT RESOLVE                       0

**The unmatched report Triz asked for is EMPTY.** Zero disagreement between the
two columns, zero dangling ids, and all 45 text-only rows resolve by name.
**Nobody had asked whether the two columns agree, and they do - completely.**

## WHO POPULATED THEM - **this engagement did, and the finding is narrower than it read**

`LibraryController` already carries L-01/L-02 (G-LIB-01):

    names become ids AT WRITE TIME, and unmatched is HELD, NOT GUESSED -
    the name stays in `department`, `department_id` stays NULL

**That IS F-07b's ruling, already in the code.** So the earlier finding -
*"competency uses SELECT DISTINCT over free text while LMS uses
hrms_departments"* - **is narrower than it read.** The WRITE side already
resolves correctly. **Only the dropdown's option source still reads DISTINCT
text.**

**A finding about a module turned out to be a finding about one dropdown.** The
write path had been fixed earlier in this same engagement and my later measurement
described the read path as though it were the whole story - **the fourth time a
measurement of one layer has been reported as a property of the feature.**

## WHAT IS ACTUALLY LEFT

**Not a migration and not a write-path fix.** The picker's options should come
from `hrms_departments` (1,181 rows) instead of `SELECT DISTINCT department`, so
a tenant with no skill rows can still choose a department. **X-03's ruling still
applies: suggested-not-closed, because a genuinely new department must remain
typeable** - and the write path already handles that case correctly by holding
the text and leaving the id NULL.

---

# A CHECK MAY ONLY BE CHANGED WHEN THE CLAIM IT ENCODES HAS BEEN DISPROVED BY MEASUREMENT, NEVER BECAUSE IT WENT RED

**The qualifier is the whole rule.** Without it, *"the check was outdated"* becomes
the excuse for tuning any red, and every guard in this suite becomes negotiable
the moment it is inconvenient.

**Second time a green was bought by a document being wrong rather than the code -
and THE FIRST WHERE THE CHECK ITSELF WAS THE DOCUMENT.** That is the sharper half:
**a suite is a set of claims, and a claim can go stale exactly like a plan row.**
The suite has been treated as the thing that judges the code; it is also a thing
the code can outgrow, and nothing was watching for that.

**The replacement being STRICTER is what makes it credible.** It now requires the
UI list and the server's `ITEM_TABLES` to agree, and fails if the old
`=== 'skill'` branch survives. **Widening the UI without the server branch can no
longer ship.** A change that loosened the check would have been the other thing.

---

# UNMEASURED NEEDED THREE MECHANISMS, NOT ONE

**Eleventh instance of the principle, and the first that could not be held by a
single mechanism:**

    1. a rating of 0 is REFUSED         so "unrated" and "rated badly" can never
                                        share a column
    2. absence IS the row not existing  rollUp reads no row as measured=false and
                                        excludes it from BOTH the level and the
                                        coverage numerator
    3. DELETE exists                    so "we no longer have a view on this" can
                                        be said without writing something false

**Any two without the third leaks.** Without (1) a zero means both things; without
(2) absence needs a sentinel value; without (3) the only way to retract a rating
is to overwrite it with a lie.

**Two fixture items on purpose** - the known-negative discipline applied to a
FIXTURE rather than to a pattern. **With one item coverage can only be 0 or 1, so
a partial-coverage bug is invisible.** A fixture that cannot express the failure
is the same defect as a property with too few verdicts.

---

# ITEM-TO-ITEM - **THE MECHANISM ALREADY EXISTS, TWICE, AND NEITHER IS A NEW ONE**

## THE FOUR TABLES CARRY NO REFERENCES AT ALL

    s_user_knowledge   business_link (804) - knowledge_tags (804) - sub_institute_id
    s_user_ability     business_link (1,015) - ability_tags (1,015) - sub_institute_id
    s_user_attitude    business_link (18) - attitude_tags (18) - sub_institute_id
    s_user_behaviour   business_link (39) - behaviour_tags (39) - behaviour_alternatives (39)

**No `skill_id`, no `jobrole_id`, no `department_id`, no `competency_id`, and no
pointer to each other.** A URL, free-text tags, and the tenant. **The four
dimensions are flat lists.**

## DOES `s_users_skills` REFERENCE THEM? **NO**

    related_skills   943   tinytext   skill -> skill, BY TEXT
    skill_maps        94   tinytext
    sub_skills       938   varchar
    department_id  3,924   int        <- a real id column, and it is the DEPARTMENT one

**Nothing in `s_users_skills` points at knowledge, ability, attitude or
behaviour.** Note `department_id` is populated on 3,924 of 5,171 rows - **relevant
to the department picker two items later, and measured here by accident.**

## TWO TABLES ALREADY JOIN DIMENSIONS

    s_skill_knowledge_ability   168,538 rows   skill_id -> classification_item (TEXT)
      knowledge 86,851 - ability 79,598 - behaviour 1,050 - attitude 1,039

    s_library_map                 3,323 rows   jobrole -> comma-lists of ids
      ALL 3,323 are type=jobrole - 3,270 carry skill_ids - 328 carry knowledge_ids

## AND THE TEXT JOIN RESOLVES AT WILDLY DIFFERENT RATES

    knowledge   200 sampled    13 resolve by exact title    6.5%
    ability     200 sampled     0 resolve                   0.0%
    attitude    200 sampled   200 resolve                 100.0%
    behaviour   200 sampled   200 resolve                 100.0%

**Attitude and behaviour are effectively 1:1 with their libraries** (1,039 and
1,050 join rows against 655 and 694 library rows). **Knowledge and ability are
almost entirely unresolvable text** - 166,449 of the 168,538 rows sit in the two
dimensions that barely match anything.

**A single "168,538 rows" number would have hidden this completely.** The table is
not one thing: **it is two dimensions that work and two that are free text wearing
the same schema.**

## MY READ - **ITEM-TO-ITEM IS NOT NEEDED, AND BUILDING IT WOULD BE THE THIRD MECHANISM**

**Competency bundling already carries it, and carries it better.**

    s_skill_knowledge_ability   skill -> dimension       TEXT, unvalidated, 0-100% resolving
    s_library_map               jobrole -> dimension     COMMA-LISTS, one writer (the JD parser)
    competency_kasba_item       competency -> dimension  IDS, validated per dimension,
                                                         tenant-scoped, 12/12 proved

**All three answer the same question - which knowledge / ability / attitude /
behaviour does this thing require.** They differ only in what sits on the left and
how honestly the right is keyed. **`competency_kasba_item` is that relationship
expressed correctly**, and a competency is the level the rest of the chain
resolves at: job roles map to competencies, ratings hang off KASBA items, the gap
rolls up per competency.

**A direct skill→knowledge link would be a THIRD mechanism saying what the second
already says** - the duplication this phase has spent itself removing: 15 copies
of `g2gActorId`, two identity resolvers, one competency concept wearing two names.

**WHAT I WOULD DO INSTEAD - and it is not a build:**

**`s_skill_knowledge_ability` is a MIGRATION SOURCE, not a mechanism to keep.**
Its **2,089 attitude + behaviour rows resolve 100%** and could seed real
`competency_kasba_item` rows the moment competencies exist to hang them on. The
other **166,449 are text that mostly does not resolve, and converting them would
manufacture exactly the dangling pointers the validation branch was just built to
prevent.**

**So: no new mechanism, no deletion, and only the resolvable 2,089 are worth
touching - after a customer has competencies to attach them to.**

---

# THE RATING WRITE PATH - **link 5 closed, 15/15 through the gap**

`POST` / `DELETE /competency/kasba-rating`, guarded `profile:admin,hr`. **New
routes, not repurposed** - the two assessment-cycle GETs keep their meaning.

**Proved through `ProficiencyService::rollUp`, never through the table it just
wrote:**

    BEFORE      level NULL (not 0), coverage 0
    RATE 201    level 4, coverage 0.5, second item still unmeasured
    RE-RATE     no duplicate row, level 2
    rating 0    REFUSED 422
    other tenant's item   REFUSED 404
    REMOVE 200  level NULL AGAIN, coverage 0 again
    fixtures removed; demo tenant ratings 160 -> 160

**UNMEASURED STAYS UNMEASURED, enforced in three places**: a rating of 0 is
refused so *unrated* and *rated badly* can never share a column; absence is the
row not existing; and DELETE exists so "we no longer have a view" can be said
without writing something false.

**Two items in the fixture on purpose** - with one item coverage can only be 0 or
1, and a partial-coverage bug would be invisible.

## THE SUITE CHECK THAT ASSERTED A FALSE BELIEF

Widening the pickers turned a check red: `RESOLVABLE_KASBA_TYPES === ['skill']`.
**The check was faithfully asserting G-SEED-01's premise**, which the row counts
and the 12/12 proof had just disproved.

**A check may only be changed when the claim it encodes has been disproved by
measurement, never because it went red.** What replaced it is **stricter**: it
now requires the UI list AND the server's `ITEM_TABLES` to agree, and fails if
the old `=== 'skill'` branch survives. **The move that would manufacture dangling
rows - widening the UI without the server - now fails in the suite instead of
shipping.**

**This is the second time a green was bought by a document being wrong rather
than the code**, and the first where the check itself was the document.

---

# FILED - **a 500 where a 422 belongs**

`CompetencyDefinitionController@store` does not validate `code`, and
`competency.code` is `NOT NULL`. **Omitting it returns a 500 with a raw SQL
message instead of a 422 naming the field.** Found by the resolution proof
failing on its first run. Not taken; one line.

---

# RETRACTION - **LINK 5 IS NOT A MODEL MISMATCH. IT IS A THIRD MISSING WRITER.**

**I reported last turn that ratings are of skills and requirements are of
competencies, and that the gap resolves across two models by luck. THAT WAS
WRONG, and it was wrong in the direction that would have cost the most** - Triz
was about to size a rebuild of the calculation.

## WHAT THE GAP ACTUALLY READS

`CompetencyGapController` takes every level from `ProficiencyService::rollUp`,
which reads:

    competency_kasba_item as i
      LEFT JOIN competency_kasba_rating as r ON r.kasba_item_id = i.id

**Keyed on `kasba_item_id`. That is the CORRECT model** - per KASBA item, exactly
what Q-A2 specifies. **The gap does not touch `s_skill_matrix`.**

`s_skill_matrix` (169 rows, keyed `user_id` + `skill_id`) is a **separate rating
surface** read by dashboards and reports - 12 files, 5 of them among the 50. **It
is not in the capability chain at all.** I found it by searching for "who rates
an employee" and took the first table that answered, without checking whether the
gap was the thing reading it.

**Ninth instance of a search whose scope was not the claim's scope** - and the
first where the wrong answer would have caused a REBUILD rather than a wasted
look. The correction came from reading `rollUp`'s body, which is the same move
that has worked every time: **ask what the consumer reads, not what the concept
is called.**

## THE REAL STATE OF LINK 5 - **worse in a different way**

    competency_kasba_rating    160 rows
    controllers touching it      0
    rating ROUTES                2, and BOTH ARE GET
    writers                      ProficiencyService LEFT JOINS it - it READS it

**No controller, no route and no service writes `competency_kasba_rating`.** The
160 rows are seed, like every other link.

**So THREE of the five links have no user-facing writer, not two:**

    3  job role task -> competency   jobrole_task_competency_map   0 rows,  no writer
    4  course        -> competency   course_competency_map        56 rows,  no writer
    5  employee      -> rating       competency_kasba_rating     160 rows,  NO WRITER

**The gap engine is correct, reads the right table, and nothing can put a rating
in it.** That is the same finding as the other four tables - **not a model
mismatch, a missing writer** - and it means the corrected chain statement is:

**EVERY LINK OF THE CAPABILITY CHAIN IS ON THE CORRECT MODEL, AND THREE OF FIVE
HAVE NO WAY FOR A USER TO FILL THEM.**

**What it does NOT need**: a rebuild of the calculation, per-competency re-keying,
or anything touching `s_skill_matrix`. **The composer's output is usable.** The
sizing Triz asked for is: **a rating write path**, not a migration.

---

# THE CHAIN - **every link exists as a table, almost none can be filled through the product**

**This is the four-tables finding said as a chain, and it is the clearest
statement of what is wrong.**

    KASBA items -> competency -> job role -> job role tasks -> employee ratings -> gap

## WHO CAN FILL EACH LINK TODAY

| # | Link | Table | Rows | **Who can fill it** |
|---|---|---|---:|---|
| 1 | KASBA item → competency | `competency_kasba_item` | 226 | **SCREEN, from today** — composer hosted, **menu row created, rights row NOT** → still dark. **Skills only** |
| 2 | competency → job role | `jobrole_competency_map` | 23 | **SCREEN, from today** — Role Requirements panel, proved 13/13. All 23 existing rows are the seed's |
| 3 | job role task → competency | `jobrole_task_competency_map` | **0** | **NOBODY.** No writer in any layer. Not a screen, not a script, not provisioning |
| 4 | course → competency | `course_competency_map` | 56 | **NOBODY.** Two readers, zero writers. The 56 rows are seed |
| 5 | employee → rating | `s_skill_matrix` | 169 | **SCREEN** — works |
| — | gap | computed | — | **Works, over empty tables** |

**Two links have no writer at all, in any layer.** Three are reachable only
because of work done in the last two turns, and one of those three is still
behind a rights row.

## CORRECTION TO LINK 5 - **it rates SKILLS, not KASBA items**

`s_skill_matrix` is keyed `user_id` + **`skill_id`** — it points at
`s_users_skills`, the 5,171-row flat library. **It does not rate a competency and
it does not rate a KASBA item.**

**So the chain's last link before the gap is on the pre-Q-A2 model too** —
**fourth known instance of G-RBAC-02b's family**, after `/competency/competencies`,
the Command Center quick-create and the role-mapping matrix. The gap is computed
from ratings of one population against requirements expressed in another.

---

# THE TAB MEASUREMENT - **STRUCTURALLY YES, FUNCTIONALLY NO, AND ONE BRANCH AWAY**

*Can `competency_kasba_item` point at what the four label-only tabs write?*

    competency_kasba_item.kasba_type   enum('skill','knowledge','ability','attitude','behaviour')
    competency_kasba_item.item_id      bigint, NULLABLE
    FOREIGN KEYS ON item_id            NONE - it references nothing

    the 226 rows          n     resolved   label-only
      skill              207        199          8
      knowledge            7          0          7
      ability              4          0          4
      attitude             3          0          3
      behaviour            5          1          4

**The single "resolved" non-skill row is DANGLING.** `item_id = 2645`, and
`s_user_behaviour #2645 does not exist`.

## WHY IT DANGLES - **the writer validates one type of five**

`CompetencyDefinitionController@store`:

    'items.*.item_id' => 'nullable|integer',        <- accepts an id for ANY type

    if ($itemId && $item['kasba_type'] === 'skill') {   <- validates ONLY skill
        ... exists in this tenant? no -> $itemId = null
    }
    DB::table('competency_kasba_item')->insert([... 'item_id' => $itemId ...]);

**For knowledge, ability, attitude and behaviour, whatever integer arrives is
stored unchecked.** That is not a missing feature — **it is an unvalidated write
that has already produced a dangling pointer**, and it would silently produce
more the moment a picker offered those types.

## THE ANSWER, AND WHAT IT DECIDES

**IT CAN.** The tables exist (`s_user_knowledge` 6,950 · `s_user_ability` 6,175 ·
`s_user_attitude` 655 · `s_user_behaviour` 694), the column holds any id, and the
enum already names all five types. **What is missing is a type→table map in ONE
branch, plus offering the four pickers in the composer** — the frontend caps
itself at `RESOLVABLE_KASBA_TYPES = ['skill']`.

**So: the composer bundles real things, the rights write is worth taking, and the
chain becomes fillable from link one.** The alternative case — *those tabs create
rows nothing can reference* — **is not what the evidence says.** The 14,474 rows
in the four tabs are referenceable; nothing has ever been pointed at them.

**But the one-branch fix is a WRITE-PATH fix, not a wiring fix**, and until it
lands the honest position is: **link 1 is fillable for skills only, and the other
four dimensions would store unvalidated ids.** Widening the composer's pickers
BEFORE that branch exists would manufacture dangling rows at scale.

### ORDER THIS IMPLIES

    1. the validation branch (type -> table map)   before any picker is widened
    2. the rights row for menu 227                 or link 1 stays dark
    3. links 3 and 4 have no writer at all         and neither is a wiring job

---

# THE TABLES THE CAPABILITY CHAIN RESOLVES AGAINST ARE FED BY PROVISIONING AND PARSERS, AND THE PRODUCT HAS NO USER-FACING WAY TO FILL ANY OF THEM

**ONE FINDING, NOT FOUR INCIDENTS.** It outranks every individual wiring item and
it is the best available explanation of what the frontend has been showing.

    s_library_map                 3,323 rows   read by the role detail panel
                                               written ONLY by the Gemini JD parser
    course_competency_map            56 rows   TWO readers, ZERO writers anywhere
    jobrole_task_competency_map       0 rows   no writer, not consulted by the task screen
    competency                      209 rows   two writers, NEITHER reachable from a screen
    jobrole_competency_map           23 rows   one writer, reachable from ONE quick-create menu
                                               and all 23 rows are in the demo tenant

**Every table the capability chain resolves against is in the same state.** The
readers are built, the screens render, the joins work - and **the only things
that have ever written to them are provisioning scripts, seed data and one AI
parser.**

## WHY THE SCREENS LOOK FINISHED

Because they are. **Nothing here is a broken screen or a missing join.** Gap
analysis reads its table correctly and finds nothing, because nothing put
anything there. **A correct reader over an unfillable table produces a clean,
confident, empty answer** - which is indistinguishable from "this organisation
has no gaps" until you ask who could ever have written the rows.

**That is what makes it one finding.** Four separate "wire this up" tickets would
each look small and each be true, and the shape - *the model's own tables have no
user-facing writer* - would never appear in any of them.

## AND IT EXPLAINS THE KASBA MEASURE ONE LEVEL UP

The KASBA measure ended at *every creation form writes its own table and only its
own table*. **This is the same fact from the other side**: the link tables have no
form at all. Not a form that writes one row instead of two - **no form.**

---

# THE COMPOSER MOUNTED - **and MOUNTED IS NOT REACHABLE**

`CmCompetencyComposer` was built, correct, called the right guarded endpoint, and
was absent from `hooks/content-map-m2.ts` and the domain barrel. **G-UI-01's
shape exactly.**

## IT NEEDED A HOST, NOT ONLY A MAP ROW

**The composer is a CONTROLLED SUB-COMPONENT** - `skills`, `onSubmit`,
`canCreate` - and the content map renders prop-less components. So it could never
have been mapped directly; `cm-competency-definitions.tsx` hosts it and lists
what already exists.

**G-UI-01 was "a component nothing mapped to". This is "a component nothing
COULD map to"** - a strictly harder case that looks identical from the outside,
and the difference only appears when you read the component's signature.

## THE MENU ROW - **derived from sibling 156, and the id was not guessed either**

    parent_id 2 · level 2 · page_type 'page' · status 1
    sub_institute_id '1,2,3,4,5,6,7,8,9,10,11'   <- copied exactly
    sort_order 12                                 <- max+1, deliberately NOT copied

**The content map said `submenuId: '225'` until the insert returned 227.**
AUTO_INCREMENT is not *highest id you can see plus one*. **The only reason the map
is right is that the change script prints the id it created and says to correct
the map if it differs** - a line written before the write, not after the mistake.

## X-21 - **THE ROW EXISTS AND THE SCREEN IS PROBABLY STILL NOT VISIBLE**

    rights row for menu 227 (new)      NO
    rights row for menu 156 (sibling)  YES

`displaySidebarMenu` builds `rightsByMenuId` from `tblgroupwise_rights_g2g` keyed
on `menu_id`. **Every sibling has a rights row. The new one does not.**

**So the honest state is: component built, host built, map wired, menu row
created - AND NOT YET REACHABLE.** Three of four layers done, and the fourth is a
rights row per profile per tenant.

**THIS IS EXACTLY WHAT X-21 EXISTS TO CATCH**, and it caught it: *a screen that
renders is not a screen that is reachable.* The temptation to report "composer
mounted" was real and would have been wrong in the way that matters - **the user
still cannot open it.**

**NOT TAKEN**: creating rights rows spans 11 tenants x N profiles. **That is a
bulk write and needs explicit approval**, and it touches the same rights tables
the held menu restore touches.

### THE PROBE THAT PROVED NOTHING, AND SAID SO

Two attempts to read the sidebar API returned 401 then 500. **In both, the
KNOWN-POSITIVE (`Employee Profiles`, sibling 156) was ALSO absent** - so the
probe was invalid, not the row. **The known-positive is the only reason those two
runs were not read as "the new row is missing".** R29 earning itself again, on a
run where the wrong reading was the convenient one.

---

# HOW MANY OTHER BUILT-AND-UNMOUNTED SCREENS? **NONE**

One pass against the content maps, as asked - not a sweep.

    components referenced by a content map   53   (across 8 module maps)
    cm-* screen components on disk           15
    NOT IN ANY MAP                            2

    cm-competency-composer   <- the one just hosted
    cm-my-capability         <- a SUB-COMPONENT of the mapped cm-my-capability-screen

**Zero genuinely unreachable screens remain**, and the two that scanned as
unmapped are both sub-components - **which is the same shape as the defect**: the
scan cannot tell "unreachable screen" from "sub-component with a host" without
reading each one's signature.

**So the four unfillable tables are NOT explained by more unmounted screens.**
They are explained by writers that were never built for a user at all -
`course_competency_map` and `jobrole_task_competency_map` have no writer in any
layer, mounted or not.

---

# ALL 23 ROLE-REQUIREMENT ROWS ARE IN THE DEMO TENANT

**NO REAL TENANT HAS EVER HAD A ROLE REQUIREMENT.**

    tenant 7 : competency=0   jobrole=120   map=0
    tenant 3 : competency=10  jobrole=347   map=23   <- every row on the platform

**The gap engine, the 9-box and the recommender have been resolving against a
table only the seed filled.** Every gap figure, every 9-box position and every
recommendation any real tenant has ever seen was computed against an empty
requirement set - which does not mean they were wrong, it means **they were never
about anything.** A tenant with no requirements has no gaps by construction, and
the screens said so without ever saying why.

**This is the headline. The wiring below is what makes it fixable.**

---

# jobrole_competency_map WIRED - **the table everything resolves against**

    tenant 7 : competency=0   jobrole=120   map=0
    tenant 3 : competency=10  jobrole=347   map=23   <- every row on the platform

**No real tenant has ever had a role requirement.** The gap engine, the 9-box and
the recommender have been resolving against a table only the demo tenant fills.

## WHAT WAS BUILT - `services/competency/role-requirements.ts` + `role-requirements-panel.tsx`

**NO NEW WRITER**, as ruled. A typed client for the existing guarded
`POST /competency/role-map`, and a panel mounted as a Framework tab beside the
matrix - where a person looks for *what does this role need*.

## THE MATRIX CANNOT BE WIRED - **a measurement, not a decision**

    Matrix.roles              string[]              <- job role NAMES, no ids
    MatrixCompetency.id       s_users_skills.id     <- the 5,171-row flat library
    jobrole_competency_map    jobrole_id + competency.id (209 rows, KASBA proper)

**The matrix's two axes are the wrong types on both sides.** Wiring it would mean
inventing a skill→competency resolution that does not exist anywhere in the
product, and guessing which of two populations each row belongs to. **The matrix
keeps writing `s_user_skill_jobrole`; the panel writes the map.**

**Reported rather than quietly narrowed**: the instruction was to wire the
Framework screen AND the matrix. Half of it is not buildable without inventing a
resolution, so half shipped and the other half is stated with its evidence.

### RECLASSIFIED - **this is G-RBAC-02b's family, not a wiring blocker**

**`MatrixCompetency.id` is an `s_users_skills.id` where the table needs a
`competency.id`.** That is not a missing adapter. **It is COMPETENCY STILL BEING
TREATED AS SKILL, in a live screen** - the same defect as the Command Center's
"Create Competency" writing a flat skill row, and the same defect the
`/competency/competencies` rename papered over.

**The matrix is built on the PRE-Q-A2 model.** It cannot be wired to the new one;
it has to be REBUILT on it. Filed as a rebuild, not a connection:

    G-RBAC-02b family, known instances
      /competency/competencies      writes s_users_skills, named competency
      Command Center quick-create   the screen behind the renamed endpoint
      THE ROLE MAPPING MATRIX       both axes on the pre-Q-A2 model   <- NEW

**Three instances, one cause**: the product renamed the concept and left the
screens on the old one. **A rename moves a label; it does not move a model.**

## PROVED AS L-14 WAS - **13 PASS / 0 FAIL**

    STORE 201 written=1 removed=0
    RE-READ    rows=1  level=3  mandatory=true
    RE-STORE   201, rows=1, SAME row id 557 -> 557     (idempotent)
    DESTROY    200
    RE-READ    rows=0  (had id 557)
    tenant 7 returned to its starting state             map 0->0  competency 0->0
    THE DEMO TENANT WAS NEVER TOUCHED                   tenant 3 map 23->23

**Safety stated before the first write**: tenant 7 not tenant 3; a fixture
competency created and removed in a `finally`; and the target job role chosen
**because it had no existing map rows**, so SYNC semantics could not destroy
anything that was already there.

## THREE THINGS THE RUN CAUGHT THAT READING DID NOT

**1. The first run 403'd, and the guard was right.** The default test identity is
an `employee`; the route carries `profile:admin,hr`. **My first search for an
admin was `role_key IN ('admin','hr')` - the ROUTE ARGUMENT, not the role_key it
aliases to** (`admin`→`administrator`, `hr`→`hr_manager|hr_executive`). It
returned zero and I nearly read that as "no admin exists". **Seventh instance of
searching for the wrong token and nearly believing the empty result.** The proof
now mints its own admin token and deletes it in the `finally`.

**2. TWO VACUOUS PASSES on the failed run.** *"RE-STORE kept the same row id"*
passed as `null === null`, and *"re-read after destroy is empty"* passed because
nothing had ever been written. **A comparison whose both sides are absent is not
agreement, it is silence.** Both checks now require the subject to have existed.

**3. THE SERVICE TYPE WAS WRONG AND ONLY THE RUN SAID SO.** The endpoint answers
**201**, not 200, and nests the counts under `data`. I had typed `SaveResult`
with `written`/`removed` top-level, so the panel would have shown
**"undefined removed from this role"** to a real user. **Corrected from the run.**
The three failing checks were MY checks; the code was right in all three.

## X-21 - THE HUMAN HALF, WHICH IS TRIZ'S

**A script calling the API is not the claim being made.** The claim is that a
person using the Framework screen moves the row count. X-21's automated half
cannot drive a browser - the platform boundary already recorded for C20.

**WALKTHROUGH, to be run by a person:**

    1. before:  SELECT COUNT(*) FROM jobrole_competency_map WHERE sub_institute_id = <t>;
    2. sign in as an ADMIN or HR user of that tenant (an employee gets 403 by design)
    3. Competency > Framework & Mapping > "Role Requirements"
    4. pick a job role; add a competency; set a level; Save
    5. expect: "1 requirement(s) saved."
    6. after:   the same COUNT, +1
    7. remove the row, Save again; expect "... 1 removed from this role."

**If step 6 does not move, the panel is not wired for a person even though it is
wired for a script** - and that gap is exactly what X-21 exists to catch.

### WHAT A CUSTOMER GETS FROM THIS

**Nothing, until they use it** - and that is the honest statement. The panel does
not create requirements; it lets someone create them. **A tenant with zero
competency rows still sees an empty picker**, and the panel says so in words
rather than rendering an empty dropdown with no explanation.

---

# THE EMPTY STATE - **and there is nowhere to send them**

X-03's ruling applied to work shipped minutes earlier: **a picker over an empty
table looks like a closed list.** Tenant 7 opens Role Requirements and sees an
empty dropdown that reads as *this organisation has nothing and cannot have
anything.*

**The empty state now says what is missing. IT DOES NOT LINK ANYWHERE, and that
is measured rather than lazy.**

## THERE IS NO REACHABLE UI PATH TO CREATE A COMPETENCY

    writers of the `competency` table            2
      CompetencyDefinitionController@store        POST /competency/definitions
      FrameworkImportController                   framework-import/dry-run + commit

    frontend callers of POST /competency/definitions   1  (cm-competency-composer)
      is the composer in the content map?              NO
    frontend callers of framework-import               0

**`CmCompetencyComposer` is fully built, calls the right endpoint, and is not in
`hooks/content-map-m2.ts`.** It is not exported from the domain barrel either.
**The framework importer has no frontend caller at all.**

**So the 209 competency rows arrived by provisioning, and a customer has no way
to add a 210th through the product.** The empty state says competencies are
defined separately and arrive by import or setup - **true, and it does not send
anyone to a page that does not exist.**

**A link to an unmounted screen would have been worse than no link**: it converts
"I don't know how" into "the product is broken", and the user's own premise -
that the create screen exists - is exactly what a plausible-looking link would
have confirmed.

### THIS IS THE FOURTH TABLE WITH READERS AND NO REACHABLE WRITER

    s_library_map                 read by the role detail panel; written only by the JD parser
    course_competency_map         two readers, ZERO writers
    jobrole_task_competency_map   0 rows, no writer
    competency                    two writers, NEITHER REACHABLE FROM A SCREEN

**The pattern named in the KASBA measure holds one level up**: it is not only
that forms write one table each - **it is that the model's own tables are fed by
provisioning and parsers, and the product has no user-facing way to fill them.**

---

# THE NEAR-MISS THAT WAS CAUGHT BEFORE IT WAS CLAIMED

**I nearly reported that the Framework screen is not mounted anywhere.**

Four searches for `CmFrameworkMapping` - in `app/`, in `components/shell/`, in
`**/*.tsx`, and for imports of the domain barrel - **all returned nothing but its
own definition.** The conclusion was sitting there and it was wrong.

**It is registered in `hooks/content-map-m2.ts`, lazily, through the `@/domain/...`
path alias** - not `@/components/domain/...`, which is what every one of my
searches assumed. Submenu 154, `framework-and-role-mapping`.

**EIGHTH instance of a search scope narrower than the claim it would have
supported** - and the first one caught BEFORE the claim was made rather than
after. The check that caught it was asking "how does ANY competency screen get
rendered", instead of "where is THIS component imported": **a question about the
mechanism rather than about the symbol.**

---

# THE ALIAS MAP - **asked, and the answer is clean**

Does anything else search by the route argument rather than the `role_key`?

    production code comparing role_key to 'admin' / 'hr'   : NONE outside RequireProfile
    frontend                                               : NONE

**`RequireProfile::ALIASES` is the only translator, and it is correct.** The four
turns this has cost were **all my own searches**, never a defect in the product.
**The map is not the problem; my habit of grepping the vocabulary I just read in a
route file is.**

---

# THE SEARCH THAT CAME BACK CLEAN - **the first one**

Two searches for `/competency/role-map` callers, different scopes - a whole-tree
grep and a `**/*.{ts,tsx}` glob - **returned the same set.** The narrower scope
was not hiding anything.

**Recorded as a CLEAN CHECK, not a finding.** Six instances this phase of a
search scope narrower than the claim it supported; **this is the one where
checking cost nothing and confirmed nothing was missed.** Worth having on the
record precisely because the other six are: a habit that only ever produces
findings starts to look like superstition, and this is the run that shows what it
costs when it is right.

---

# R30, FOURTH TIME - **and the new guard could not have caught it**

A heredoc turned two `\n` escapes into literal newlines in the proof script.
**Behaviourally identical output, and `php -l` passes** - so the parse-sweep
guard committed one turn earlier **would not have flagged it.**

**The guard catches mangling that breaks parsing. It does not catch mangling that
still parses.** That is a real limit, stated rather than discovered later: the
assertion covers the failure mode that has cost turns (broken regexes, dead
scripts), not every possible corruption.

---

# S-06 MEASURED - **786 write routes, and `api` DOES NOT AUTHENTICATE**

Measured before costed, as ordered. **NOT ONE DATABASE WRITE WAS MADE.** The
census is a static read of the route table and of controller source, because a
census that writes in order to find out what writes is the thing it is measuring,
and the database is shared and live.

## THE CENSUS

    WRITE ROUTES (POST/PUT/PATCH/DELETE)   786      <- the plan says 772
      of those, METHOD DOES NOT EXIST       51      cannot be tested; they fatal

    POST 394    PUT 198    DELETE 183    PATCH 11

    WHAT THE BODY DOES (static, a CANDIDATE split - R6)
      MUTATES              375
      DESTRUCTIVE          105    across 97 distinct methods
      NO MUTATION FOUND    255
      NO METHOD             51

**The plan's 772 is stale by 14, and 51 of the 786 cannot be called at all** -
they are part of the 197 missing-method finding from the same turn. **The real
testable write surface is 735, not 772.**

## THE FINDING - **`api` IS NOT AN AUTHENTICATION BOUNDARY**

    write routes with NO auth middleware   458
      of which URI prefix `api/`           438

    BY MIDDLEWARE STACK
      338  api                                    <- nothing else at all
       29  api,profile:admin,hr
       20  web
       15  api,api.token

**338 write routes carry the `api` group and nothing else.** Confirmed by running,
not by reading - the `jobrole-tasks` lesson, where a route that looked open turned
out to 401 and have zero callers. **Two POST routes chosen because their bodies
contain NO mutation verb**, so the question could be answered without a write:

    NO TOKEN  POST api/talent-acquisition/funnel   -> 200  180 bytes  {"success":true,...}
    NO TOKEN  POST api/talent-acquisition/dropoff  -> 200  281 bytes  {"status":true,...}

**Two hundred, no token, no session, no header.** The `api` middleware group does
not authenticate anything.

### WHAT THIS DOES AND DOES NOT ESTABLISH

**IT DOES**: route-level authentication is absent on 338 write routes. Nothing in
the routing layer stops an anonymous caller reaching them.

**IT DOES NOT**: prove those 338 accept anonymous *writes*. Many of those
controllers resolve identity themselves - `ResolvesApiIdentity` returns null
without a token, and the write may fail downstream. **Whether each refuses
depends entirely on each controller's own code, and finding out requires
writing**, which is the condition on this row.

**So it is filed as a CONFIRMED absent boundary and a CANDIDATE set of 338**, not
338 findings. **The stop-line's "unauthenticated write" is not yet proven for any
single route** - and it is now the first thing S-06's design must answer.

## THE TWO-TENANT QUESTION, ANSWERED BEFORE THE DESIGN

**C23's read half worked because a GET is repeatable and comparable.** Ask as
tenant A for tenant B's data, compare two responses, and a difference IS the leak.
**None of that survives contact with a write:**

    not repeatable    a second call is a second row, not a second reading
    not comparable    there is no "response" to diff - the evidence is in a table
    not reversible    the database is shared, live, and at 202.47.117.220
    not observable    a write that succeeds WRONGLY looks identical to one that
                      succeeds correctly, until you read the row back and check
                      WHICH TENANT OWNS IT

**So the write half cannot be C23 with different verbs.** It needs a different
instrument, and the shape follows from the four lines above:

1. **A write test must own its subject.** Tenant A creates a row; only then can
   tenant B be told to modify it. **That means the suite writes before it tests**,
   and every row it creates must be registered and removed - the tenant + row
   register already named as S-06's blocked-by.
2. **The assertion is on the STORED ROW, not the response.** A 200 proves nothing;
   the question is which `sub_institute_id` the row landed under.
3. **Refusal and silence must be told apart.** A write that 500s, a write that
   403s, and a write that succeeds against nothing are three different verdicts,
   and C23's read half needed exactly this split retrofitted - *a property with
   fewer verdicts than the world has outcomes*, learned twice already this phase.
4. **DESTRUCTIVE ROUTES ARE NOT IN THE FIRST PASS.** 105 routes across 97 methods
   delete. **No test calls them blind against a shared database**, and no design
   that includes them ships without Triz's explicit decision.

**C23's blind spots are inherited by default unless the instrument is built not
to** - and the specific inheritance is #3: the read half shipped with one verdict
where the world had five, and every one of those five was found by a route
behaving in a way the property could not express.

## STATUS

**S-06 is MEASURED, NOT COSTED.** The census, the auth finding and the two-tenant
answer are the inputs a cost needs; the cost itself depends on decisions that are
Triz's - destructive routes in or out, and whether a test tenant exists to write
into.

---

# THE BASELINE MOVED 51 -> 50 AND NOTHING CAN SAY WHICH FILE LEFT

Noticed at the end of this turn, not looked for.

    git status --porcelain filtered   50
    git diff --name-only              50      (both measures agree - it is real)

**NOT MINE, and that is checkable rather than asserted.** Every file in every
commit of this session was listed and read: the twenty G-SEC-29 controllers, the
phase3 docs, `routes/api.php`, `routes/hrms.php`. **No foreign file appears in
any of them.** `routes/hrms.php` was last touched at `17035b8e`, long before this
session, so it was never in the set.

**The likely cause is Triz resolving the 51, which they said they are doing.** A
file leaving the set is expected. **The problem is that I cannot NAME it.**

## THE BASELINE WAS A COUNT, NOT A LIST

The suite asserts `count === 51`. **A count can tell you that something moved and
never what.** So the one guard specifically built to notice foreign work changing
can report the change and cannot identify it.

**Same failure as everything else this turn, in the guard itself:**

    a fail list is a photograph, not a fact       - a snapshot outliving its moment
    the unit you count in decides what you find   - a method with no sites
    the plan and the register do not join         - a question needing two sources
    A COUNT CANNOT NAME WHAT IT COUNTED           - this

**FIXED HERE**: `_evidence/baseline-foreign-files.txt` now holds the list, 50
lines, path and status. **The next move is a diff, not a mystery.**

**THE SUITE'S NUMBER IS NOT CHANGED.** Editing `51` to `50` would be tuning a
check to clear its own red - the exact move the register forbids two sections
below, and the check's own comment says *"DO NOT REMOVE THIS FOR BEING
ANNOYING."* **It stays red until Triz confirms the new baseline**, because the
one thing worse than a red I cannot explain is a green I bought by editing the
expectation.

---

# THE PLAN TRACKS INTENT, THE REGISTER TRACKS WORK, AND THEY HAVE DRIFTED APART

**This outranks the overlap question it was found by.**

    occurrences of "G-SEC-29" in 08-connection-plan.md :  0

**The security work this engagement has been executing for days has no row in the
planning document.** Twenty confirmed leaks, sixteen fixes, twenty controllers,
seventy-eight sites - and the plan does not know it happened.

**So no overlap pass over the plan could ever have found the HrmsController
collision.** O-05 is a plan row; G-SEC-29 is a register finding; **nothing joins
the two documents.** The collision was not missed through carelessness - it was
**structurally invisible to any check that reads one document.**

## AND IT EXPLAINS THE MISSING SPANS

    open rows naming no file, table or class :  37 of 51

**That is a symptom, not a separate problem.** The plan records what was INTENDED
and at what cost. The register records what was DONE and against what evidence. A
row's span is a property of the work, so it lives in the register - **and rows
whose work has not started have no span anywhere, by construction.**

**NEITHER DOCUMENT IS WRONG.** Each is correct and complete for its own job. The
defect is that **there is no join**, and every question that needs both - *what
does this row touch, has any of it been done, does it collide with anything* -
falls into the gap between them.

## THE RULE

**THE PLAN TRACKS INTENT. THE REGISTER TRACKS WORK. A QUESTION ABOUT SCOPE NEEDS
BOTH, AND NOTHING MAKES YOU ASK BOTH.**

**Remedy, extended from the trait-scan remedy below:** *any question about a row's
scope greps BOTH documents - for the row id AND for its symbols - as a printed
step.* Not the implementation log alone, not the plan alone. **The step prints
what it searched and where**, so a zero is visibly a zero-in-both rather than a
clean answer from one.

---

# THREE INSTANCES OF "A RECORD EXISTED AND WAS NOT CONSULTED" IN ONE TURN

**This is structural, not a habit.**

    1. the ResolvesApiIdentity note   already in this register, ~120 lines below
                                      the closure note, naming jobroletaskcontroller
    2. O-05 -> S-03                   declared in the blocked-by column, in the
                                      words "S-03 scope", before the collision
    3. the correction itself          Triz found #1 by reading the register I
                                      had just written into

**Three in one turn, from three different documents, on three different questions.**
A habit fails occasionally. **Three in one turn is a system with no retrieval
step** - records are written diligently and read only when something reminds
somebody they exist.

**Writing is instrumented in this engagement; reading is not.** Every finding gets
a register entry, a commit message, and often a suite check. **Nothing anywhere
makes a scan look for prior art before it runs.**

---

# 197 ROUTES POINT AT METHODS THAT DO NOT EXIST

Asked as a cheap follow-on to O-05's residue. It was not cheap.

    routes registered      1709
    controller@method OK   1508
    CLASS MISSING             0
    METHOD MISSING          197

**Reflection, not grep** - `__call`, inheritance and traits all count as existing,
and a text search would score every one of them a false positive.

## SPLIT BY CAUSE - **197 is a pattern, not 197 defects (R6)**

    RESOURCE VERB   167   Route::resource registers all seven verbs whether or not
                          the controller implements them. ONE idiom, one fix
                          (`->only([...])`), 167 rows.
    BESPOKE NAME     30   somebody wrote a route MEANING a method

**The 30 are the finding.** Each is a deliberate route to a deliberate name that
was never written - `TalentOfferController@getOfferLetter`, `@getTemplates`,
`taskController@resyncTaskToGoogleCalendar`, `AJAXController@getDepEmployeeLists`,
ten on `ONetOnlineDataController` alone, and `HolidayController@getWeekdays`
routed **twice**, from two different route files.

**Every one fatals on every call, and has since it was written.**

**NOT MINE**: `getWeekdays` was absent at `HEAD~3`, before this session's edits,
and all four other controllers I touched have identical method counts before and
after. Checked because I had edited `HolidayController` hours earlier - **the
first thing to rule out when your name is on a recent commit to the file.**

## DELETED - **one, the one that was ordered**

`routes/hrms.php:105`, `earlyGoingHrmsAttendanceReportCreate`. Intention recorded
in place, R8, same treatment as the dead `jobrole-tasks` route. Unreferenced in
both repos - not by name, not by URI. Proven by the instrument that found it:

    1709 -> 1708 routes,  197 -> 196 missing

**The other 196 are filed, not taken.** 167 are one idiom; 29 are bespoke and each
needs its own intention read before anything is removed.

## THE LESSON IN THE DELETION

**COUNTING SITES MADE THE FILE LOOK 100% HANDLED. COUNTING ROUTES FOUND A METHOD
THAT DOES NOT EXIST.**

38 sites, 31 of 32 methods carrying a fix - by site count `HrmsController` was
finished. **A method that does not exist has no sites to count**, so the unit that
measured the fix was structurally blind to the defect.

**THE UNIT YOU COUNT IN DECIDES WHAT YOU CAN FIND.** Same family as *"a property
with fewer verdicts than the world has outcomes"* - there the verdicts were too
few, here the unit was wrong. **Both are the instrument limiting the finding.**

---

# R30, THIRD TIME - **the third was accepted in advance, and arrived immediately**

Last turn: *"for every standing rule that has cost a turn twice, either make it
unskippable or accept a third."* **The third came in the very next turn** - a
heredoc appending to `route-method-exists.php` turned `'\\'` into `'\'` and broke
the print. Same rule, same mechanism, third time, minutes after being named.

**It is no longer evidence that rules decay. It is evidence that this particular
rule cannot be held by intention at all.** The wrapper is owed.

---

# THE OVERLAP PASS - **a measure turn, no edits**

Ordered before S-03 because **the HrmsController collision had already happened
once**: 38 sites were G-SEC-29's and the same file was O-05's row, *and neither
row knew about the other* - found by accident, mid-work, too late to change
sequencing. Folding the check into S-03 would only have found overlaps WITH S-03.

## RESULT 1 - **THE PLAN CANNOT ANSWER THE QUESTION FOR MOST ROWS**

    item rows parsed in the tier tables      75
    still open by their status column        51
    OPEN ROWS NAMING NO SYMBOL AT ALL        37

**37 of 51 open rows record no file, table or class anywhere in their tier row.**
S-03, S-06, R-01..R-04, all eleven L-1x wiring rows, LM-*, M-*, O-* - none carries
a span. Widening to every line in the plan that names a row ID recovers spans for
only 31 rows, and most are a single column name.

**A check over a map only sees what the map mentions** - the readiness finding
again, now about the plan itself. **The overlap question is not answerable from
the planning document**; it is answerable only by resolving each row to files, and
the document does not hold that resolution.

## RESULT 2 - **ONE SHARED SYMBOL, AND IT IS ALREADY KNOWN**

    department_id                            L-01  L-02

That pair is already declared: both rows are "`department_id` written by the
library forms" and both are blocked by X-03. **No undeclared symbol overlap exists
among the open rows the plan can describe.**

## RESULT 3 - **THE DECLARED DEPENDENCY GRAPH IS DENSE AND WAS NEVER READ AS A WHOLE**

**41 declared edges** across the open rows, including the two that matter here:

    S-04 -> S-03   "S-03 scope"
    O-05 -> S-03   "S-03 scope"

**O-05's collision with the security work WAS DECLARED, in the register, in the
blocked-by column.** Not undetectable - unread. **Second instance of the same
shape as correction A above, discovered in the same turn.**

Also declared and worth having before sequencing:

    L-12 <-> L-13 <-> L-20     mutually blocking, a 3-cycle in the library block
    R-02, R-03, R-04 -> R-01   the whole reports block is one row deep
    R-03 -> M-02               reports reach outside their own block

**The reports block is not four rows. It is one row and three dependents.**

## RESULT 4 - **THE REAL REASON THE COLLISION WAS INVISIBLE**

    occurrences of "G-SEC-29" in 08-connection-plan.md :  0

**The security work being executed has no row in the plan at all.** G-SEC-29 is a
register finding; O-05 is a plan row. **No scan spans both documents, so no scan
could have intersected them.**

**This is the finding.** The collision was not a failure to intersect rows within
the plan - it was work living in one document and its collision partner in
another. **Two registers, one codebase, and nothing joins them.**

## O-05'S RESIDUE - **a number, not "partly absorbed"**

Routes are not sites, and this is the row that already caused a collision, so it
is counted in its own unit:

    route refs -> HrmsController          31   (30 distinct methods)
    methods G-SEC-29 actually changed     28
    ROUTED METHODS NOT CHANGED             2
    total methods in the file             32   (31 carry a fixed site)

**O-05's residue is 2 routed methods**, and both are already resolved:

    departmentAttendanceReport             READ and cleared - session-scoped,
                                           non-deterministic, correct code
    earlyGoingHrmsAttendanceReportCreate   DOES NOT EXIST

**O-05 is 28/30 absorbed and its remaining 2 are a clearance and a defect.** As a
reading row it is effectively finished; nothing in it needs S-03 first.

### NEW - **A ROUTE POINTING AT A METHOD THAT DOES NOT EXIST**

    routes/hrms.php:105
    Route::get('early-going-hrms-attendance-report/create',
        [HrmsController::class, 'earlyGoingHrmsAttendanceReportCreate'])

**No such method exists anywhere in `app/`.** Every call to that route fatals.
Found only because O-05's residue was counted in ROUTES - **the site count could
never have surfaced it, because a method that does not exist has no sites.**

**The unit you count in decides what you can find.** Counting sites made the file
look 100% handled; counting routes found a dead route reference.

## S-03 IS NOT TAKEABLE

    the 51, unchanged                     51
    of which app/Http/Controllers/Api/    23
    of which talent                        6

**S-03's span is `Api/` x23 and `talent/` x5 - it is inside the 51.** Confirmed
before assuming, as ordered. **S-03 is BLOCKED on the same release the matrix
guard, the identity consolidation, S-08 and O-04 are waiting for.**

That makes **three blocked rows, not two**: S-08, O-04, **and S-03** - and S-04
and O-05 both declare S-03 scope behind it.

---

# G-SEC-29 CLOSED - **16 confirmed leaks, all 16 fixed**

    CustomModuleController@menuLevel2            LEAK -> echoes the request (NOT a leak)
    HolidayController@index                      LEAK -> PASS   48b/48b
    discliplinaryManagementController@index      LEAK -> PASS   46b/46b
    jobroletaskcontroller@index                  LEAK -> PASS   54b/54b
    jobroletexonomycontroller@index              LEAK -> PASS  221b/221b

## THE "C27 TRAIT-PRESENT CLASS" WAS MY OWN ARTIFACT

I set these five aside as a distinct, harder class: *the trait is present and
something else defeats it.* **It was not true.**

    CustomModuleController          ResolvesG2gActor: 0    request-tenant sites: 2
    HolidayController               ResolvesG2gActor: 1    request-tenant sites: 6
    discliplinaryManagementController  ResolvesG2gActor: 1  request-tenant sites: 5
    jobroletaskcontroller           ResolvesG2gActor: 1    request-tenant sites: 6
    jobroletexonomycontroller       ResolvesG2gActor: 1    request-tenant sites: 7

**Four of the five carry `ResolvesG2gActor`.** They have `ResolvesApiIdentity`
**because I added it during the `g2gActorId` consolidation earlier in this same
engagement** - for an actor helper, not for tenant resolution.

**My scan asked "does this file mention `ResolvesApiIdentity`" and read the answer
as "this controller already resolves its tenant properly".** It did not. The
import was mine, put there for something else, three hours earlier.

**They were the same substitution as the other eleven all along** - 26 sites, and
they closed exactly like the rest.

### THE SHAPE OF THE ERROR

**A classifier that reads a signal it cannot attribute.** The trait's presence had
two possible causes - deliberate tenant resolution, or my own unrelated edit - and
the scan could not tell them apart, so it assumed the one that made the controllers
look harder than they were.

**Fifth instance of the same root**: `lacking a trait` read as `having a resolver`;
`can_view` read as a role's power; the alias map read as the whole authorisation
story; `no output` read as `nothing found`. **Every one is a proxy taken for the
thing it proxies.**

**And this one had a self-inflicted twist**: the signal was contaminated *by the
engagement's own prior work*.

**CORRECTED - the contamination WAS recorded, and I did not read the record.**
That claim is right about the codebase and wrong about this register. **A SYNTAX
CHECK AGREES WITH A SEMANTIC MISTAKE**, ~120 lines below, already prints the two
lines side by side and **names `jobroletaskcontroller`** - one of the five:

    use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;   <- an IMPORT
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;         <- WHERE MINE WENT

**Same fifteen files, same symbol, written down before the scan ran.** So the
finding was **RE-DERIVED, NOT UNDETECTABLE** - I paid a second time for something
already on the page.

**That makes it two findings, not one:**

1. **the fifth proxy-taken-for-the-thing** - a scan reading a signal it cannot
   attribute; and
2. **the second R30 / R18f(v) instance** - *a record that existed, was written
   down, and was not consulted at the moment it applied.*

**The second is the more expensive shape**, because it does not improve with more
care. R30 and R18f(v) were both known, both written, and both cost a further turn
after being written.

### REMEDY - STRUCTURAL, NOT REMEMBERED

**Any scan whose signal is a trait, helper, or import must first grep the
implementation log's Changed column for that symbol - as a PRINTED STEP INSIDE
THE SCAN, not a habit beside it.**

Had that step run, it would have printed the `g2gActorId` consolidation against
`ResolvesApiIdentity` and the five would never have been set aside. **This is the
same fix that has actually worked three times already**: `git status` printed as
a step rather than recalled, the known-negative inside the check rather than
beside it, the split in the harness rather than in the reader.

## `menuLevel2` ECHOES THE REQUEST - the fourth non-defect

    asked 7 -> {"token":"4554|...","type":"API","syear":"2025","sub_institute_id":"7"...
    asked 3 -> {"token":"4554|...","type":"API","syear":"2025","sub_institute_id":"3"...

**Identical to `getHolidays`**: an unimplemented API branch returning the Request
object. Not a leak; not scoped wrongly; **returns no menu data to any API caller.**

## FINAL

    20 scored LEAK by the property
    -4 non-defects   AuditController (self-mutating), departmentAttendanceReport
                     (non-deterministic), getHolidays and menuLevel2 (echo the request)
    --
    16 CONFIRMED cross-tenant reads
    -16 FIXED       3 request-only + 8 session-fallback + 1 generalSettingIndex
                    + 4 in the supposed C27 class
    --
     0 OPEN

**Every confirmed cross-tenant read found by the C23 re-run is closed**, each
probed before and after with the instrument that found it, none of the twenty
files among the 51.

**Four of the twenty were never defects** - and all four would have been "fixed" by
anyone working the list without splitting the property first.

### FILED SEPARATELY - two unimplemented API branches

`getHolidays` and `menuLevel2` both `return $request;`. **Neither has ever returned
data to an API caller and nobody noticed** - the same evidence shape as the dead
`jobrole-tasks` route. Not security items.

---

## HrmsController DONE - **38 sites, and getHolidays is a THIRD false positive**

    generalSettingIndex   LEAK 343b/857b  ->  PASS 343b/343b
    getHolidays           LEAK 114b/114b  ->  LEAK 114b/114b   <- still differs

**38 sites replaced, and `departmentAttendanceReport` asserted byte-identical
BEFORE the write** - the script refuses to save if that method changed. Untouched
by construction, because the substitution matches request-TENANT reads and its
only request read is `$request->type`, and asserted anyway.

### getHolidays ECHOES THE REQUEST BACK

Its API branch ends:

    return  $request;

**It returns the Request object.** Not holidays - the caller's own parameters:

    asked 7 -> {"token":"4554|...","type":"API","syear":"2025","sub_institute_id":"7"...
    asked 3 -> {"token":"4554|...","type":"API","syear":"2025","sub_institute_id":"3"...

**The responses differ because the INPUTS differed.** No data was scoped wrongly;
nothing was leaked. **It is not a leak - it is an unimplemented API branch**,
debug code that ships, returning no holiday data to anyone.

**A third false-positive shape, and a different one again:**

    AuditController       self-mutating       writes what it reads
    departmentAttendance  non-deterministic   embeds now()
    getHolidays           ECHOES THE INPUT    the response IS the request

**A differential property compares outputs while varying an input. An endpoint
that returns its input differs by construction** - the property is measuring the
thing it changed.

**Three correct-or-irrelevant routes were scored LEAK by one property**, each for a
distinct reason, and **each would have been "fixed" by someone working the list.**

### G-SEC-29 FINAL COUNT

    20 scored LEAK
    -1 AuditController@export                    self-mutating, correct
    -1 HrmsController@departmentAttendanceReport non-deterministic, correct
    -1 HrmsController@getHolidays                echoes the request; NOT a leak,
                                                 but an unimplemented API branch
    --
    17 confirmed cross-tenant reads
    -12 FIXED  (3 request-only, 8 session-fallback, 1 generalSettingIndex)
    --
     5 open - and they are exactly the five C27 trait-present cases

**Every leak reachable by substitution is closed.** What remains is the class where
the trait is already present and something else defeats it - **reads before edits,
as C27 requires.**

### FILED SEPARATELY - `getHolidays` returns no data

Not a security item. `return $request;` in a shipped API branch means **the
holidays endpoint has never returned holidays to an API caller.** Nobody noticed,
which says the endpoint has no API consumer - the same evidence shape as the dead
`jobrole-tasks` route.

---

## HrmsController@departmentAttendanceReport READ FIRST - **correct code, and a clock**

Read before touching any of the 38 sites in that file, because **an unjudgeable
route inside a controller you are about to edit 38 times is the worst place to
discover something the instrument cannot see.**

    the only request read in the method : $request->type
    the tenant                          : session()->get('sub_institute_id')
    why it varies                       : $res['end_date'] = now();

**It reads NO caller-controlled tenant at all.** The session is server-side. And
its response varies because it embeds the current time.

**NON-DETERMINISTIC, not self-mutating, and not a leak.** Nothing changes in the
data between calls; a clock does.

**This is why the fifth verdict had to become two.** Filed as SELF-MUTATING it
would have sent someone hunting a write that does not exist, in a 2,000-line
controller, in the file about to be edited 38 times. **The sub-split earned itself
on its first real use** - as the STABLE pre-clearing did for the eight.

**It is removed from G-SEC-29 entirely.** Not fixed, not deferred - **it was never
a defect.**

    G-SEC-29: 18 confirmed -> 17
      11 fixed, 6 open (5 trait-present C27 cases, 1 Hrms route)

---

## A FIX NAMED FOR WHAT IT REPLACES IS NOT A FIX MEASURED FOR WHAT IT DOES

**The session decision is the one that would have caused real damage.**

Eight controllers read `session() ?? $request`. The obvious substitution -
"replace the tenant resolution with the trait" - would have replaced the WHOLE
expression. **`resolveApiIdentity()` is TOKEN-ONLY. It does not consult the
session.** Every Blade caller has a session and no token, so the obvious fix would
have returned NULL for all of them.

**What caught it was checking what `apiTenantId()` RESOLVES FROM, rather than what
it is called.** The name says "tenant id". The body says "token, and nothing else".

**Same root as the resolver-versus-trait misclassification two turns earlier**,
where "lacks the trait" was read as "has a bespoke resolver". **A component's name
describes its role; only its body describes its behaviour, and a substitution is a
behaviour claim.**

Removing only the request half leaves G-SEC-27's precedence exactly as ruled:
**session, then token, and the request never.**

---

## TWO RULES EXISTED AND WERE NOT REACHED FOR

    R30                     write scripts to a file, never a heredoc
    R18f(v) stronger form   git status before editing a foreign file

**Both were written after they cost a turn. Both cost another turn afterwards.**

- **R30**, this turn: the first fix script went through a heredoc, which mangled
  the namespace backslashes into a bad regex escape. It crashed before writing
  anything.
- **R18f(v)**, earlier: `bootstrap/app.php` was edited and committed without the
  check, taking 36 lines of somebody else's work with it.

**Neither is a knowledge gap. Both are habits** - the rule was known, written down,
and not consulted at the moment it applied. **A rule that must be remembered at the
point of use has the same failure mode as a requirement carried in a document
rather than a payload:** it works exactly as long as somebody remembers it.

**What has actually worked is making the rule impossible to skip** - `git status`
printed as a step rather than recalled, the known-negative inside the check rather
than beside it, the split in the harness rather than in the reader.

---

## THE FIFTH VERDICT APPLIED TO THE WHOLE CONFIRMED SET - **and the three fixes were real**

**22 routes, same tenant, twice each.** The question the cross-tenant comparison
could not ask.

    STABLE         19   the cross-tenant verdict stands
    SELF-MUTATING   3   the property could not judge these

### THE THREE FIXES WENT ONTO REAL LEAKS

`DepartmentManagementController`, `organizationDetailsController` and
`tblmenumasterG2gController` are all in the **STABLE 19**. **They differ across
tenants and NOT across repeated calls** - which is exactly what a leak looks like
and exactly what a self-mutating endpoint does not. **Measured, not argued** - the
byte counts after the fix were the argument, and this is the measurement.

### THE THREE THE PROPERTY CANNOT JUDGE

    AuditController@export                      21,108b -> 21,609b   REAL MUTATION
    HrmsController@departmentAttendanceReport         84b ->     84b   varies, same length
    PayrollController@monthlyPayrollCreate       17,927b -> 17,927b   varies, same length

**Only the first is a state change.** `export` writes an audit row per call and
grows by one.

**The other two differ in CONTENT at the same LENGTH**, and `PayrollController`'s
is a **500 whose stack trace varies between calls** - `"function": "{closure}"`,
frame addresses shifting. **It is not mutating state; its error output is
non-deterministic.**

### SO THE FIFTH VERDICT IS REALLY TWO

    SELF-MUTATING       the endpoint changes the data it reads     (export)
    NON-DETERMINISTIC   the response varies without the data       (a varying
                        changing - timestamps, stack frames         500, a clock)

**Both defeat a differential property and for different reasons**, and a harness
that lumps them together will send someone to look for a state change that is not
there. **A fifth verdict was not enough; the world had six outcomes.**

**That is the same lesson a fourth time in three turns** - and this time it was the
*fix* for the lesson that came up short.

### G-SEC-29's CORRECTED COUNT

    20 scored LEAK
    -1 AuditController@export         self-mutating, correct code
    -1 HrmsController@departmentAttendanceReport   unjudgeable, needs reading
    ---
    18 confirmed leaks
    -3 fixed and re-verified STABLE
    ---
    15 open

---

## AuditController IS NOT A LEAK - **it mutates what it reads. G-SEC-29 is 19, not 20.**

Taken as a READ, not an edit, because it leaked with **zero tenant reads found** -
and an edit there would have been a guess.

`export()` resolves the tenant correctly: `competencyContext($request)`, then
`$sid = $context['sub_institute_id']`. **Identity, not the request.** So the
property's verdict had to come from somewhere else.

### THE DECISIVE TEST - **call it twice with the SAME tenant**

    tenant 7, call 1 : 33 rows, 17,072 bytes
    tenant 7, call 2 : 34 rows, 17,573 bytes    <- SAME tenant, one more row
    tenant 3 asked   : 35 rows, 18,074 bytes    <- one more again

    growth per call : exactly 1 row

**`export()` calls `logCompetencyActivity(...)`, which writes an audit entry into
the table it just exported.** Every call adds its own record of itself.

**So two calls can NEVER return the same response**, whatever tenant is asked for.
The differential property scores it LEAK forever, and it is correct code.

### THE FOURTH FAILURE MODE OF THE PROPERTY

Now four, all found in two turns:

    leaked / refused        collapsed - a correct 403 read as FAIL
    matched / examined nothing   collapsed - a zero-match grep read as clean
    same-and-working / same-and-broken  collapsed - 500/500 reads as PASS
    **stable / SELF-MUTATING**   collapsed - an endpoint that changes what it
                                 reads can never repeat itself

**A differential property assumes the world holds still between the two calls.**
Where the endpoint is the thing that moves it, the property measures its own
footprint.

**This one would have been "fixed".** Someone would have edited a controller that
resolves tenant from identity correctly, to satisfy a check that cannot be
satisfied.

### G-SEC-29 IS 19 CONFIRMED LEAKS

    20 scored LEAK
    -1 AuditController@export - self-mutating read, correct code
    ---
    19 confirmed, of which 3 are now fixed

**The harness needs a fifth verdict: SELF-MUTATING** - detectable by calling twice
with the SAME tenant before comparing across tenants. **That check costs one extra
request and would have saved this investigation.**

---

## AND THE WRAPPER CONDITION WAS WITHDRAWN - **recorded as the owner's**

The instruction was one private resolver per controller. **Withdrawn on the
counting**: thirteen identical private methods is how four identity resolvers and
fifteen copies of `g2gActorId` happened, and both cost turns to find and
consolidate. `$this->apiTenantId($request)` **is** the single point.

**The owner's words:** *"I generalised from `skillLibraryController`'s shape
without checking the shape held - the same error as quoting its 'eleven call
sites, zero edits' number."*

**Same root as four of my own errors this phase**: a property of one instance taken
as a property of the class. **It is not a reviewer's error or an implementer's - it
is what happens to anyone reasoning from a good example without re-measuring.**

---

## ⚠ G-SEC-29's FIX PATTERN DOES NOT TRANSFER - **78 sites, not 13 replacements**

**My own split was wrong and the correction is the same error a fourth time.** I
classified 13 controllers as "bespoke resolver, the pattern applies" **because they
lacked the trait.** *Lacking a trait* and *having a resolver method* are different
claims, and I measured the first and asserted the second.

**None of the 13 has a bespoke resolver body.** They read the tenant INLINE, at 78
separate sites.

    controller                       request sites   shape
    HrmsController                        38         session ?? request
    organizationDetailsController          6         REQUEST ONLY
    tblmenumasterG2gController             5         REQUEST ONLY
    ApplyLeaveController                   4         session ?? request
    DepartmentManagementController         4         REQUEST ONLY
    LeaveSummaryReportController           4         session ?? request
    questionmasterController               4         session ?? request
    taskController                         4         session ?? request
    LeaveTypeController                    3         session ?? request
    skillcontroller                        3         session ?? request
    sub_std_mapController                  2         session ?? request
    courseController                       1         session ?? request
    AuditController                        0         NEITHER - tenant from elsewhere
                                        ----
                                          78

### WHY THE PROVEN PATTERN DOES NOT APPLY

`skillLibraryController`'s fix was **one method body**, and the register records the
result exactly: *"Eleven call sites, zero edits."* That worked **because the eleven
call sites all went through one resolver.**

**Here there is no resolver to replace.** Each of the 78 sites is its own
expression, so it is 78 edits, not 13 - **and the pattern's headline number was the
thing that made it look cheap.**

### THREE SHAPES, NOT ONE

| shape | controllers | what it means |
|---|---|---|
| `session() ?? $request` | 9 | the request is a FALLBACK - a leak only when the session is absent, which is every API call |
| **REQUEST ONLY** | 3 | `DepartmentManagement`, `organizationDetails`, `tblmenumasterG2g` - **no session, no token, the caller's word is the only source.** The worst of the three |
| **neither** | 1 | `AuditController` leaks with **zero tenant reads found** - the tenant reaches it another way and that needs reading before anything else |

### AND ONE CONTROLLER IS HALF THE WORK

**`HrmsController` holds 38 of the 78.** It is also `O-05`'s row - *"read
`HrmsController` (31 routes)"* - which the classification listed as a separate
open BUILD. **They are the same work**, and neither row knew about the other.

### STOPPED, AND NOT STARTED

Per the standing condition: **if the resolver is not a bespoke body, stop and
report rather than force the pattern.** That condition now fires for **all 18**, not
five - the 13 for a different reason than the 5, but the same instruction.

**Nothing was edited.** `git status` was checked on all 13 first: **none is among
the 51**, so the work is unblocked - it is simply four times larger than the
pattern implied.

---

## G-SEC-29 SCOPED BEFORE TOUCHING A ROUTE - **the pattern applies to 13 of 18**

**The property is now split IN THE HARNESS**, not in whoever reads it:

    LEAK     20   both 200, different data          <- the real remainder
    REFUSED   1   one 200, one 4xx - CORRECT        <- credentialStatus
    ERROR     1   5xx for both - broken for everyone
    PASS     15   fixed since the 2026-08-06 sweep

**Three outcomes, three verdicts.** The old property answered one question and
scored a leak and a refusal identically.

### THE TWENTY ARE 18 CONTROLLERS, AND THEY SPLIT IN TWO

**BESPOKE RESOLVER - the proven `skillLibraryController` pattern applies (13):**

    ApplyLeaveController        AuditController          DepartmentManagementController
    HrmsController              LeaveSummaryReportController  LeaveTypeController
    courseController            organizationDetailsController questionmasterController
    skillcontroller             sub_std_mapController    taskController
    tblmenumasterG2gController

**TRAIT PRESENT AND STILL LEAKING - the pattern does NOT apply (5):**

    CustomModuleController      HolidayController        discliplinaryManagementController
    jobroletaskcontroller       jobroletexonomycontroller

### THE FIVE ARE C27's CLASS, AND C27 ALREADY SAYS WHY IT IS WORSE

> *"A controller with no trait is VISIBLY UNFINISHED. One with the trait LOOKS
> COMPLETE to every future reader and to every future checker."*

**Replacing a resolver that is already correct fixes nothing.** In these five the
trait is present and something else - a query reading the request directly, a call
site ignoring the resolved value - defeats it. **Forcing the pattern here would
produce a controller that passes every structural check and still leaks**, which is
precisely C27's illusion.

**Stopped on all five, as instructed. They need reading, not adoption.**

### AND THE ERROR ROUTE IS THE TURN'S THIRD BLIND-SPOT INSTANCE

`PayrollController@monthlyPayrollCreate` returns **500 for both tenants**. Filed
separately: it is not a leak, it is **broken for everyone**.

**A differential property cannot see past it** - identical failure is identical, so
a route that 5xx-es for both callers reads as PASS. It only surfaced here because
it was already on a FAIL list from when it behaved differently.

**Third instance in one turn of a property that cannot distinguish its own
outcomes:** the leak/refusal conflation, the zero-matches search, and now identical
failure reading as identical success.

---

# ⛔⛔ G-SEC-29 - **20 CONFIRMED CROSS-TENANT READS** - S1, 2026-08-12

**Confirmed by execution, not inferred.** These jump the queue under the standing
stop line: *no new security sub-item is taken unless it is a confirmed
cross-tenant leak.* **These are that.**

### THE MEASUREMENT

The C23 property re-run against the 40 actionable FAIL routes, then each survivor
split by WHY it differs:

    still failing                    22
      REFUSED a foreign tenant        1   <- correct behaviour, scored FAIL
      BOTH 200, DIFFERENT BODIES     20   <- CONFIRMED CROSS-TENANT READ
      500/500                         1   <- separate defect

### THE PROOF, on one of the twenty

`GET api/departments-management`, caller **user 198, tenant 7, holding their own
token**:

    asks sub_institute_id=7  ->  200, 2 items, all sub_institute_id: 7
    asks sub_institute_id=3  ->  200, 2 items, all sub_institute_id: 3

    "Admin & Maintenance Department"   sub_institute_id: 3
    "product supervisor"               sub_institute_id: 3

**The caller received rows they cannot get with their own tenant.** The route
honoured the request parameter over the token. Not a candidate - a read.

### THE TWENTY

    jobroletaskcontroller@index          jobroletexonomycontroller@index
    skillcontroller@index                AuditController@export
    DepartmentManagementController@index LeaveTypeController@index
    HolidayController@index              ApplyLeaveController@index
    LeaveSummaryReportController@...     sub_std_mapController@index
    CustomModuleController@menuLevel2    courseController@index
    questionmasterController@indexChapter taskController@taskAnalysisReport
    tblmenumasterG2gController@...       organizationDetailsController@index
    discliplinaryManagementController@index
    HrmsController@generalSettingIndex   HrmsController@departmentAttendanceReport
    HrmsController@getHolidays

**None is behind the 51.** All twenty are actionable now.

---

## ⚠ AND THE C23 PROPERTY HAS A FALSE-POSITIVE MODE

**"The response differed" flags a LEAK and a REFUSAL identically.**

`ExcelAutomationAgentController@credentialStatus` scores **FAIL** on this property
and is **correct**: it answers 200 for the caller's own tenant and **403 for
another's**. Different responses - because it refused.

**This is G-SEC-11's inverted signal again**, one layer down. There, "differs
across tenants" was read as suspicious when it was correct scoping. Here, the
property cannot separate:

    both 200, different bodies   ->  LEAKED
    200 then 403                 ->  REFUSED, and correct
    500 then 500                 ->  broken, unrelated

**A property that produces one verdict for three outcomes cannot be worked from
directly** - every FAIL needs the split before it means anything. **1 of 22 was a
correct refusal, and reading the list without splitting would have "fixed" a route
that was already right.**

**The split is now part of the re-run.**

---

## THE PHOTOGRAPH RULE, PROVEN WITH A NUMBER

    the sweep said        46 FAIL
    re-run, actionable    40 targets
      already fixed       15   (37.5%)
      still failing       22
      untestable/vacuous   3

**37.5% of the list had expired in six days.** Not a suspicion - a count.

**And re-running cost less than reading one controller.** The harness existed, the
property was executable, and 40 routes took under a minute. **Fifteen controllers
did not need to be read at all.**

---

## ⭐ A PROPERTY WITH FEWER VERDICTS THAN THE WORLD HAS OUTCOMES

> ## COUNTING THE WORLD'S OUTCOMES IS NOT A THING YOU DO ONCE.
>
> **The fix for this lesson came up short of this lesson.**
>
> The property had two verdicts and the world had five, so a fifth was added:
> SELF-MUTATING. **The world had six.** `PayrollController`'s varying stack trace
> is not a state change - it is non-deterministic output from a broken route, with
> nothing moving at all - and lumping it with `AuditController@export` would send
> someone hunting a mutation that is not there.
>
> **That does not undermine the rule. It says the rule has no terminal state.**
> Each new verdict makes the instrument sharper and reveals the next pair it was
> collapsing. **The question is not "have I counted the outcomes" but "what have I
> collapsed this time".**


**Three instances in one turn.** Named as one set, because they are one root and
the count is the evidence: **this is the dominant failure mode of every instrument
in this project.**

| the property | verdicts it had | outcomes the world had | what collapsed |
|---|---|---|---|
| C23 tenant guard | PASS / FAIL | leaked · **refused** · errored | a correct 403 scored FAIL - and 1 of 22 would have been "fixed" while already right |
| a grep over `Reports/` | matched / didn't | matched · didn't · **examined nothing** | zero matches read as a clean result; O-04 would have entered the escalation as OPEN |
| any differential check | same / different | same-and-working · different · **same-and-broken** | 500/500 reads as PASS |

**The instrument is not wrong. It is answering a question with fewer answers than
the situation has**, and every collapsed pair becomes a wrong verdict silently -
there is no error, no exception, no red. **The output looks exactly like a correct
result, because it is a correct answer to a smaller question.**

### THE TEST

**Before trusting a verdict, count the outcomes the world can produce and count
the verdicts the property can return.** If the second number is smaller, some pair
of real situations is being scored identically, and **you cannot tell from the
output which pair.**

### THE SHARPEST CASE - **the 500/500**

`PayrollController@monthlyPayrollCreate` errors for both tenants. **A differential
property can NEVER find it**: identical failure is identical, so it scores PASS
forever.

**It surfaced only because a STALE LIST preserved a moment when it behaved
differently.**

---

## AND THAT CUTS AGAINST THE PHOTOGRAPH RULE

The photograph rule says: **re-run the property, do not read the old list.**

**The 500/500 is the counter-example.** The live property scores it PASS. The
six-day-old list scored it FAIL, from a moment when it still differed. **The stale
artefact found something the live instrument cannot see.**

**So the rule is not "the list is worthless".** It is:

> **A re-run tells you what is true NOW. The old list tells you what was ONCE
> true - and a thing that changed from failing to erroring has not been fixed, it
> has moved somewhere the property cannot look.**

**Diff them rather than replacing one with the other.** 15 entries expired and one
entry was the only record of a route that has since broken differently - and both
facts came from comparing the photograph to the live shot, not from either alone.

---

## A FAIL LIST IS A PHOTOGRAPH, NOT A FACT

**Same shape as the map decay below, and the pair is the point.**

| | what decayed | how |
|---|---|---|
| **route-to-menu map** | coverage | **BY OMISSION** - the surface grew, the map did not |
| **C23 FAIL list** | accuracy | **BY RESOLUTION** - the surface was fixed, the list was not |

**An artefact built correctly against a moving surface, wrong later, with nothing
about it looking different.** Neither rotted. Neither was ever wrong when written.
**Both became untrue while sitting still.**

### THE EVIDENCE

    sweep executed        2026-08-06
    checked               2026-08-12, six days later
    already resolved      3 of 3 spot-checked

    ExcelAutomationAgentController@credentialStatus   O-03: probed, refuses
    ExcelAutomationAgentController@downloadTemplate   O-03: same
    AJAXController@getSkillCompetency                 audited, held, load-bearing

**Three of three checked had expired.** That does not suggest staleness, **it
demonstrates it** - and nothing in the list marks which entries have gone.

### THE RULE

**BEFORE WORKING ANY RECORDED FAIL LIST, RE-RUN THE PROPERTY THAT PRODUCED IT.**

**Reading a stale list is reading a photograph.** The routes it names may be fixed,
the ones it omits may have broken, and the document cannot tell you which. Where
the property is executable - and C23's is, it drove 912 routes in-process and
wrote nothing - **re-running is cheaper than reading a single controller.**

---

## A MAP BUILT ONCE AGAINST A MOVING SURFACE DECAYS SILENTLY

    live API routes                838
    enforceable today (conf >= 2)  185       -> 22%
    of those, added by Phase 3       0       -> 0%

**The headline understates it.** Not *"27% enforceable"* but **0% of what we want
enforced.** The route-to-menu map was built against the pre-Phase-3 tree, so the
185 enforceable routes are ALL LEGACY, and every route this phase added -
`readiness/gates/acknowledge`, `framework-import/commit`, `reporting-line/bulk`,
`competency/definitions`, `nine-box`, `terminology` - is absent.

**Nothing measured the map's coverage, so the decay was invisible.** It was built
correctly, it was never wrong, and it became useless for the thing it was built
for without a single failing check.

**The general form:** a map, a matrix, a fixture list or a threshold table built
once against a surface that keeps moving **will decay silently unless something
counts it.** The artefact does not rot - the world moves out from under it, and
nothing about the artefact itself looks different afterwards.

**Now measured:** the suite reports the coverage number every run
(`route-to-menu map coverage`). It **reports rather than fails** - a falling
number is information, and a threshold would be invented. What matters is that
the next drift is caught by a number instead of by someone happening to look.

---

## MATRIX-ENFORCED AUTHORIZATION - **SIZED 2026-08-11, NOT STARTED**

Decision taken: **the guard CONSULTS `tblgroupwise_rights_g2g`.** Not narrowed to
`profile:admin` - narrowing hardcodes the boundary in code where an administrator
can never change it, and the point is an admin screen that controls what HR and
employees can do.

Build order is fixed and not negotiable: **enforcement first, screen second.** A
screen editing rules nothing enforces is worse than no screen, because it looks
like it worked.

### PART 1 — ENFORCEMENT. **The map is the cost, and it is large.**

    live API routes                 838
    present in the route->menu map  528
    ENFORCEABLE TODAY (conf >= 2)   185
    NEED A DECLARATION              653

Confidence 0 and 1 count as UNMAPPED, as agreed. The existing map holds 739 rows:
208 at confidence 0, 330 at 1, 157 at 2, 43 at 3, 1 at 4.

**AND THE THREE ACTS THAT PROMPTED THE DECISION ARE ALL UNMAPPED.**

    /readiness/gates/acknowledge          NOT IN THE MAP
    /competency/framework-import/commit   NOT IN THE MAP
    /reporting-line/bulk                  NOT IN THE MAP
    also absent: /competency/definitions, /competency/nine-box, /terminology

**The map was built against the pre-Phase-3 route tree.** Every route this phase
added is missing from it, and those are precisely the routes worth enforcing -
the configuration acts an HR Manager can currently perform. The 185 enforceable
routes are all legacy.

**So Part 1 is not "wire up the guard".** It is:

1. **Declare menus for Phase 3's routes** - the ~20 that carry the configuration
   acts, first. This is authoring, and a route whose menu is guessed would be a
   permission decided by a guess.
2. Build the guard with the tri-state precedence already decided:
   **individual DENY > group DENY > individual ALLOW > group ALLOW > role default
   > deny.**
3. Prove it with **two roles, opposite outcomes, on the same route** - and the
   refusal must come from THE MATRIX, not a hardcoded list. The known-negative:
   flip the matrix row and the answer must flip with it. A guard that refuses HR
   because someone wrote `admin` in a route file has not been tested at all.

**A route with no menu declaration must DENY**, per the precedence's own tail.
That means turning enforcement on before declaring the 653 would break the
product - so it lands per-route or per-group, never globally.

### PART 2 — THE ADMIN SCREEN. **Smaller, and blocked on Part 1.**

    menu 15  Group wise right management
             /module/organizational-management/organization-setup/group-wise-right-management
    menu 16  Individual right management
             /module/organizational-management/organization-setup/individual-right-management

Both menus exist with `access_link`s; the individual-rights table exists. **No API
endpoint reads or writes rights** - one Blade-era controller
(`tblmenumasterG2gController`) touches the table. So Part 2 is: rights read/write
endpoints, two screens, X-21 browser verification.

The screen pattern is no longer first-of-its-kind - the readiness screen
established it (`readLaravelSession()`, `resolveApiBaseUrl()`).

### THE HONEST TOTAL

**Part 1 is the large one, and the authoring is most of it.** The guard itself is
small; declaring menus for 653 routes is not, and cannot be done by pattern - a
guessed menu is a guessed permission.

**Recommendation: scope Part 1 to the configuration acts only** - the ~20 routes
that prompted this - prove the guard there with two roles and opposite outcomes,
and leave the other 633 on their current guards until each is declared. That
closes the actual defect (an HR Manager committing a framework import) without a
653-row authoring project standing between the decision and any enforcement at
all.

---

## G-SEC-04 — The route-to-menu map is not reliable enough to enforce against · **S1** · `OPEN`

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


**Raised by Triz. Distinct from G-SEC-02:** that gap is *no* mapping; this is a
*wrong* mapping, which is more dangerous because it reads as enforced.

Confidence distribution across the 739 routes (confidence = count of shared path
tokens between route URI and menu slug):

| Confidence | Routes | Meaning |
|---:|---:|---|
| 0 | 208 | no menu matched |
| **1** | **~329** | **one shared token — unreliable** |
| 2 | ~156 | plausible |
| 3 | ~43 | strong |
| 4 | 1 | exact |

Sampled confidence-1 errors, all real:

| Route | Mapped to | Why it is wrong |
|---|---|---|
| `PATCH /agents/{id}/status` | Task → **Status Management** | Wrong module entirely — matched on "status" |
| `/lms/governance/permissions` | Org → **Role & Permissions** | Wrong module — matched on "permissions" |
| `/get-employee-tasks` | Org → **Suggestion for employee** | Target is on the DELETE list |
| many competency writes | **Competency Management** | A module **root**, far too coarse to govern a delete |

**Rules adopted:**

1. **Confidence 0 and 1 both count as UNMAPPED.** Only **confidence ≥ 2** may be
   auto-applied.
2. Everything else needs an explicit `->defaults('menu', '…')` in
   `routes/api.php`, **reviewed per controller**.
3. **A route may never map to a container or module-root menu row — leaf screens
   only.** A rights check against a module root cannot distinguish view from
   delete.

Effect: routes requiring manual declaration rise from 208 to **~537 of 739**. That
is the honest number, and it is the real size of the A3 task.

---

## G-SEC-05 — Executing the nav triage will create ungoverned routes · **S1** · `OPEN`

**Raised by Triz.** 50 API routes — **12 of them writes** — currently map to menu
rows on the DELETE or DEFER list, including *Task Assignment & Progress*,
*Suggestion for employee*, *Send SMS to User*, *Assessment List* and *Admin and
Configuration Module*. Delete the row and the route has nothing to check against:
a cleanup task silently manufactures the exact gap G-SEC-02 describes.

**Precondition rule — no nav row is deleted until its routes have a new home.**

For every row scheduled for deletion:

1. List every route mapped to it (from `_evidence/route-to-menu-map.csv`).
2. Decide **per route**: remap to the surviving owner screen, or retire the route
   with the row.
3. Only then execute the row deletion, inside the reversible-SQL + backup process.

To be added as a precondition in `08-connection-plan.md` and folded into the
**Q-A3 amendment 4** process.

---

## G-SEC-03 — No field-level access control · **S2** · `DESIGNED`

Screen-level rights cannot express "an Employee may open the directory but not see
salary", "a reviewee may not read manager private comments", or "360 feedback is
anonymised". Today a screen grant returns the whole record.

**Fix designed:** `03-rbac-matrix.md` §3.8, using named field groups plus an API
resource layer so restricted fields are **absent from the payload**, not merely
hidden by the UI.

---

## G-STR-01 — Four join tables required by the connection layer do not exist · **S1** · `DESIGNED`

`course_competency_map`, `jobrole_competency_map`, `competency` +
`competency_kasba_item`, `jobrole_task_competency_map`. Approved in Q-B4, Q-C1,
Q-C2, Q-C3. Golden threads 2 and 3 cannot be built before they exist.

**Fix:** one coherent schema change with a full ER diagram, per your instruction.
→ `02-domain-model.md`.

---

## G-STR-02 — ~~Three~~ **TWO** tables had migrations recorded as run but did not exist · **S1** · ✅ **CLOSED 2026-08-07** (`7df8c1c7`)

> ### ⚠️ ONE OF THE THREE WAS NEVER MISSING — the record dropped a prefix
>
> `s_competency_certification_requirements` **exists and holds 15 rows**, and
> `CertificationRequirementController` references it correctly. **This register
> recorded it as `competency_certification_requirements` — without the `s_`
> prefix — and it has read as missing since Gate A on that basis.**
>
> **Nothing ever broke.** The gap was recorded from a NAME, not from a QUERY.
>
> **A gap recorded from a name rather than from a query can be wrong for months** —
> this one was. The other two (`competency_evidence`, `s_skill_jobrole`) were
> genuinely absent and are now created.

### Original entry, retained

`competency_evidence`, ~~`competency_certification_requirements`~~, `s_skill_jobrole`.

> ⚠️ **CORRECTED 2026-08-07 (D-007): TWO of three, not three.**
> `s_competency_certification_requirements` — **with** the `s_` prefix — **exists
> and holds 15 rows**, and `CertificationRequirementController` references it
> correctly. **This record listed it without the prefix**, which is why it read as
> missing. **A naming error in the audit, not a missing table.** The other two were
> genuinely absent and are now created.
Controllers and models reference all three; `CertificationController`,
`CertificationRequirementController` and `EmployeeCompetencyProfileController`
break on the paths that touch them.

This is why Certifications appears connected to nothing.

**Fix approved (Q-B5):** restore all three, with root-cause analysis and a
recurrence guard. Root cause is almost certainly the shared database — two
migration systems over one schema (Q-C4). → `02-domain-model.md` §7.

---

## G-STR-03 — No reporting line exists · **S1** · `DESIGNED`

`tbluser` has no manager column; `hrms_departments` has no head. Consequently
*Team* and *Department* scope cannot be resolved, no approval flow can be modelled,
and seven modules have each invented their own local `manager_id`.

**Fix designed:** `03-rbac-matrix.md` §2.4 — `tbluser.reporting_manager_id`,
`hrms_departments.head_user_id`, with cycle validation and depth bounding as step 4.

---

## G-STR-04 — No event, listener or observer mechanism · **S1** · `OPEN`

`app/Events`, `app/Listeners` and `app/Observers` do not exist. `app/Jobs` holds one
unrelated job. Every cross-module flow in the brief §6 requires a mechanism that has
never been built.

**Fix:** build natively in G2G, harvesting the `hpbrain_event_store` design per
Q-C4. → `05-data-flow-contracts.md`.

---

## G-DATA-01 — Courses have no competency link · **S1** · `OPEN`

`sub_std_map` carries `jobrole` as **longtext holding a role name** (73 of 96 rows)
and `proficiency` (2 of 96). No skill or competency foreign key exists.

Without it there is nothing for auto-recommendation to point at, and golden threads
2 and 3 are unbuildable. Approved as the **highest-priority item in the connection
plan** (Q-B4).

---

## G-DATA-02 — Employee Directory has no competency mapping · **S2** · `OPEN`

The brief's stated biggest confusion. The screen has a `skills: []` array and one
"Skill Deficit" KPI counting employees with no skills *or* no profile name. The
chain employee → job role → framework → required KASBA → current proficiency → gap
→ development plan does not exist. It is not broken; it was never built.

---

## G-DATA-03 — Duplicate concepts, 10 pairs · **S2** · `PARTIAL`

D1–D10 in `01-inventory.md` §6. D1 (JobRole) and D2/D3 (Skill vs Competency)
resolved by Q-A1 and Q-A2. D4–D10 outstanding.

---

## G-NAV-01 — Task Permission menu points at the Priority screen · **S3** · ✅ `FIXED 2026-08-05`

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


Menu 219 "Permision" and menu 218 "Priority Management" shared one `access_link`.
The Permission screen was built but unreachable — the root cause of the original
report that Task → Permission does not work.

**Fixed** via the reversible process (Q-A3 amendment 4):

| Step | Artefact |
|---|---|
| Backup | `_changes/backup-tblmenumaster_g2g-2026-08-05.sql` — all 188 rows as replayable INSERTs, verified |
| Change + rollback | `_changes/G-NAV-01-fix-permission-menu-link.sql` |
| Applied by | `_changes/G-NAV-01-apply.php` — transactional, pre-conditions checked, auto-rollback on any mismatch |

Row 219 `access_link` → `/module/task-management/task-permission`, matching what
the frontend already registers (`hooks/content-map-m6.ts:42`). **Data only; no
application code changed**, which matters while Phase 3 is pre-Gate-D.

The path deliberately does **not** follow the `/administration/task-*` shape of its
siblings — that spelling would have required a frontend edit too. Tidying the URL
is recorded as a Gate D item.

**Verified:** the nav cross-reference now reports **zero duplicate `access_link`
values** (previously one), and broken-nav remains 0.
`_evidence/nav-crossref.txt` regenerated.

**G-SEC-05 pre-check applied:** no API route maps to menu 219, so nothing was
orphaned.

---

## G-PERF-01 — Skills matrix report takes ~50 seconds · **S2** · `OPEN`

`/api/reports/employee-directory/skills/matrix` measured at 49.4s and 55.3s. It is
correct and tenant-scoped, just extremely slow. No browser or load balancer waits
that long, so the view effectively never renders, and each call holds a PHP-FPM
worker and a DB connection for the better part of a minute.

Its neighbours return in well under a second, so this is one query. Needs `EXPLAIN`
and almost certainly an index. Relevant because its backend is being harvested into
the consolidated reporting home (Q-A3).

---

## G-FLOW-05 — LMS enrolment happens; learning does not · **S2** · `OPEN` (revised)

Investigated ahead of Gate C at Triz's request, because 1,426 enrolments and 0
certificates looked like it might be a live defect. **It is not.**

### The issuance chain is complete and correct

| Step | Evidence |
|---|---|
| UI | `learning-delivery-workspace.tsx:1138` — `onClaim={() => void claimCertificate()}` |
| Hook | `use-my-learning.ts:458` — `lmsCertificateService.issue(context, courseId)` |
| Service | `services/lms/learning.ts:430` — `POST /lms/learning/certificates` |
| Route | `routes/api.php:719` |
| Controller | `LmsLearningController::issueCertificate` → `insertGetId` on `lms_certificates` |

It is a **manual learner claim**, idempotent, and returns 422 until every content
item in the course is complete. There is also an admin `reissueCertificate` path.

### Why there are zero certificates

| Measure | Value |
|---|---:|
| `lms_course_enroll` — status `enrolled` | **1,425** |
| `lms_course_enroll` — status `completed` | **1** |
| `lms_content_progress` — total rows | **1** |
| — of which `in-progress` | 1 |
| — of which `completed` | **0** |
| Distinct users with any content progress | **1** |
| Courses that have content | 79 |

**Nobody has ever completed a single content item.** The certificate gate has
therefore never been satisfiable, and zero is the correct output.

### What this actually means

1. **No live defect. No customer can have been promised certificates by this
   system** — none were ever issuable. Nothing to fix on a separate timeline.
2. **The real finding is a funnel collapse.** 1,426 enrolments produced 1 progress
   row. Assignment works; consumption does not happen. Whether that is because the
   player is hard to reach, because courses have no content worth consuming, or
   because the product has not been used in earnest, cannot be told from the data
   alone.
3. **It changes how golden thread 3 must be judged.** "Learn → prove → level up"
   currently has no observed instance of even the first step. Any design that
   assumes completion data exists is designing against an empty table.
4. **Certificate claiming should probably not be manual.** A learner who finishes a
   course and is not offered the certificate will not go looking for a button.
   Recommend: issue automatically on completion, keep the manual claim as a
   fallback. → carry into `05-data-flow-contracts.md` as `course.completed`.

**Not Phase 3 scope to fix the funnel** — that is a product and content question.
Recorded so the connection plan does not assume completion data that is not there.

---

## G-QUAL-01 — No stable sort on report queries · **S4** · `OPEN`

`/reports/employee-directory/attrition` orders by a value with many ties and no
tiebreaker, so identical requests return rows in different orders. Cosmetic in the
UI, but it defeats response comparison and produced a false "cross-tenant leak"
during Phase 1 testing. Add a deterministic tiebreaker.

---

## Summary

| Severity | Count | IDs |
|---|---:|---|
| **S1** | **9** | G-SEC-01, G-SEC-02, **G-SEC-04**, **G-SEC-05**, G-STR-01, G-STR-02, G-STR-03, G-STR-04, G-DATA-01 |
| **S2** | 4 | G-SEC-03, G-DATA-02, G-DATA-03, G-PERF-01 |
| **S3** | 1 | G-NAV-01 |
| **S4** | 1 | G-QUAL-01 |
| **Total** | **15** | |

*(Correction **C1**: the previous summary said 6 while listing 7. Count and list
now agree, and both include the two findings Triz raised.)*

Five of the nine S1 items are access-control gaps that compound each other:
G-SEC-01 is the exposure, G-SEC-02 and G-SEC-04 are why the fix cannot simply be
switched on, and G-SEC-05 is a way the cleanup work could widen it.

### Whole-register count

The table above covers the **core register only**. Items found later are appended
below rather than renumbered, so the running total is:

| Section | S1 | S2 | S3 | S4 | Total |
|---|---:|---:|---:|---:|---:|
| Core register (above) | 9 | 4 | 1 | 1 | **15** |
| Appended during Gate B | 2 | 3 | 1 | 0 | **6** |
| Appended during Gate C — Libraries & Taxonomy (`G-LIB-01..08`) | 1 | 3 | 3 | 1 | **8** |
| Appended during Gate C — certifications (`G-CERT-01`) | 0 | 1 | 0 | 0 | **1** |
| Appended during Gate C — Competency Library (`G-COMP-01`) | 1 | 0 | 0 | 0 | **1** |
| **Running total** | **13** | **11** | **5** | **2** | **31** |

Rows and columns both sum to 29. *(First draft of this block said S1=11, S2=9,
S3=6, S4=3 — a breakdown that summed to 29 while disagreeing with every section
count. Recomputed from the section rows. Per **C2**, a severity table that does not
reconcile is the first thing a reviewer attacks.)*

More will be added as the Gate C audit proceeds, one write-up at a time.

---

## Appended during Gate B — one line each, written up at Gate C

*(Per Triz's sequencing instruction: log and continue, do not stop to elaborate.)*

| ID | Severity | One-line |
|---|---|---|
| G-SEC-06 | **S2** | **Approved as a real gap.** Rights value becomes **tri-state (ALLOW / DENY / INHERIT)**, INHERIT default, absent row also = inherit. Same shape on **both** `tblindividual_rights` and `tblgroupwise_rights_g2g`. Resolution order: **individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny.** Supersedes the 4-step order in `03-rbac-matrix.md` §5A/A6, which did not allow for a group-level DENY. |
| G-QUAL-02 | S3 | The audit tool's own regex silently dropped 52 routes (fully-qualified inline class refs). Fixed, but the class of defect — a checker that under-reports — warrants a second pair of eyes on every audit script before its numbers are quoted. |
| **G-FLOW-05** | **revised → S2 (adoption, not defect)** | **Investigated. Not a silent failure and not an unwired path.** The chain is complete: button → `claimCertificate()` → `lmsCertificateService.issue()` → `POST /lms/learning/certificates` → `issueCertificate()` → insert. It is a **manual learner claim**, gated on all content being complete. Zero certificates is the *correct* output of the data: of 1,426 enrolments, **1,425 are `enrolled` and 1 is `completed`**, and `lms_content_progress` holds **1 row, `in-progress`, for 1 user**. Nobody has ever completed a course's content, so the gate has never been satisfiable. **No customer can have been promised certificates by this system** — none were ever issuable. Real finding is a funnel collapse: enrolment happens (1,426), consumption does not (1). See detail below. |
| **G-DATA-04** | **S1** | **12 `s_skill_matrix` rows contain double-encoded character maps** — a JSON string re-encoded character by character (`{"0":"{","1":"\"","2":"C"...}`), producing 9,068 fake sub-items. 96% of all apparent KASBA "measurements" are this artefact. **The write path that produced them must be found before the normalisation migration**, or it will corrupt the new table too. **B3 — add a WRITE-TIME GUARD as well:** reject or flag any value that parses as JSON whose keys are sequential integers from zero (`{"0":…,"1":…,"2":…}`). That signature is unmistakable — no legitimate KASBA payload has it — and it stops the defect re-entering by a route nobody found. Applies to all four KASBA columns and to `skill_matrix_item` after normalisation. |
| **G-DATA-05** | **S2** | **Capability coverage is 3.0%, not ~11%** — 8 of 264 active users have any measurement. Earlier figure divided rows by total (not active) users. All 8 resolve cleanly, so the problem is reach, not quality. |
| **G-LIB-01** | **S1** | **CONFIRMED BREAK — job roles created in Competency never reach HR.** `JobroleApiController.php:27-28` joins `s_user_jobrole.department_id → hrms_departments.id`; the Job Role form writes `department` as a **string** and never `department_id` (`library-config.ts:214`), so the role has a NULL FK and is absent from HRIT's department listing. **The backend already accepts the column** (`LibraryController.php:81`) — the form simply never sends it. One of the cheapest high-value fixes found. Connection **L-01**. |
| **G-LIB-02** | **S2** | **Master data is unaddressable — nine fields name another entity and store free text.** Department, job level, related skills, job titles, tasks, learning resources, certifications, SME, proficiency levels. Nine fields, nine tables that already exist, **zero foreign keys**. Consequences: renaming a skill or role silently detaches its mappings and ratings (joins are on title/name); delete leaves orphans and the dialog **admits it** (`library-tab.tsx:1136`); each tab's category namespace is private and irreconcilable. Root-cause connection **L-11**. |
| **G-DATA-06** | **S2** | **S-1 QUANTIFIES L-11 for the first time.** Live-schema sweep: **49 populated text columns name an entity this product owns and carry no matching `*_id`**. The four largest relationship tables are joined **entirely by strings**: `s_user_jobrole_task.jobrole` (85,662 rows), `s_user_skill_jobrole.skill` + `.jobrole` (79,295 each), `s_jobrole_skills.skill` + `.jobrole` (62,208 each), `s_jobrole_task.jobrole` (55,961). **~283,000 relationship rows held together by string matching**, so every rename silently detaches data. Also found: **`s_library_map.skill_ids` stores ids AS TEXT** on 3,270 rows. ⚠️ **Known false positives in the 49** — the proxy cannot distinguish *a column that IS the entity's own name* (`hrms_departments.department`, `s_user_jobrole.jobrole`) from *a reference to it from elsewhere*, nor attributes that merely contain the word (`skill_status`, `skill_importance`, `skill_flow`). **Only cross-table references are the finding.** Evidence: `_evidence/sweeps/s1-result.json` |
| **G-LIB-03** | **S2** | **The task CATALOGUE has no competency link; the INSTANCE has one and it is weak.** `s_user_jobrole_task` carries no skill column in either direction, so there is no **role-level** statement of which competencies a task exercises. `task.skill_id` **is** populated — 1,514 of 2,271 (67%) — but hand-picked at creation, so capability can only be inferred from what an individual ticket creator happened to tick. **Already approved as Q-C3 → `jobrole_task_competency_map`** (`02-domain-model.md` §2.1, §10 step 3); logged here as the Library-side evidence, **not as new Gate D scope**. ⚠️ *An earlier draft of this line said "no task→skill link exists anywhere", which contradicted `manager.md` §1.2 and the §3 catalogue-wins rule built on it. Corrected; this miss is why **C6b** exists.* |
| **G-LIB-04** | **S2** | **No import on any of the 8 library tabs** (export on all 8). A customer cannot load their existing skill library; onboarding is manual re-typing. Elevates G-FLOW-03 / Q-C1. Connection **L-10**. |
| **G-LIB-05** | **S3** | **Status fields nothing honours.** Skill `Status` has no consumer (matrix, framework and summary filter on `approve_status='Approved'` only — `RoleMappingController.php:117`, `StudioController.php:293`); `skill_status` has **two writers and zero readers** (`SaveJDController.php:170` is the second); Job Role `Status` is filterable on-screen only. Retiring a skill does nothing. Connection **L-05**, gated on **Q-L2**. |
| **G-LIB-06** | **S3** | **366 lines of finished UI are unreachable.** `library-tab.tsx:1054` gates a `Sheet` on `Boolean(selected)`, but all four `setSelected` calls (400, 413, 422, 1054) pass **`null`**. `library-detail.tsx` is stranded. **Remedy is DELETION, not recovery** — the reachable popup (`library-detail-modal.tsx`, 681 lines, 8 cards) is strictly richer than the panel (366 lines, 2 tabs, associations on only 2 of 8 tabs). Reviving it would create a second parallel view of the same record. Connection **L-03R**; ⚠️ needs deletion approval. *(Supersedes L-03, which proposed recovery — sunk cost is not a reason.)* |
| **G-LIB-07** | **S3** | **Two proficiency vocabularies, mutually unaware.** The Skill tab stores a free-text blob (`library-config.ts:168`) and filters on DISTINCT values of it; the Framework Studio edits `s_proficiency_levels`, writable **only from the legacy Blade form**. Same for the responsibility ladder: `s_level_responsibility` is rendered inches from a *Job Level* field placeholdered `"e.g. L3"` that never references it. Connection **L-04**. |
| **G-LIB-08** | **S4** | **Vocabularies drift by design.** The OpenChoice *"+ Add a new …"* control (`library-form.tsx:54-112`) writes new values as free strings with no master record, while the Department filter reads DISTINCT values off the data itself (`LibraryController.php:1753-1764`) — so one misspelling becomes a **permanent filter option** splitting the dataset. Mirror-image bug: the Job Role filter drops every role with a blank department (`LibraryController.php:1738-1741`), so roles exist it can never show. Connections **L-01/L-02** (a real `department_id` removes both bugs at once). |
| **G-SEC-09** | **S1** · ✅ **FIXED 2026-08-06 (D-003), guard-verified 2→0** | **C15 ANSWERED — a live cross-tenant hole on the Competency Library.** `skillLibraryController::competencyLibraryContext()` (**12 API routes, 24 of the 64 hits**) validates that a token exists via `PersonalAccessToken::findToken()` and then **discards its owner**, taking `sub_institute_id` **and** `user_id` from the request body guarded only by `is_numeric`. That is *verbatim* the pattern `ResolvesApiIdentity`'s own docblock describes as the F-01 bug. **Any valid token from any tenant can read and write any other tenant's competency library by changing one number** — create, edit, archive, restore, export, bulk import. Phase 1 fixed 70 controllers and built the trait; **this one was never migrated onto it**, so remediation is adoption, not design. 3 further API-reachable controllers share the shape (`assignmentController` 11 routes, `jobroletaskcontroller`, `jobroletexonomycontroller`) — candidates, not yet read line by line (R6). Evidence: `_evidence/sweeps/c15-tenant-field.md`. ⚠️ **D-002 sits on top of this hole** — independent fix, both needed. |
| **G-SEC-10** | **S1** · ✅ **FIXED 2026-08-06 (D-004), guard-verified 9→0** | **C27 — "trait present, still reads from the request" is its own, HIGHER severity class.** `PayrollController` (**39 routes, `hrms.php`, salary data**) imports `ResolvesApiIdentity` at line 43, calls **`apiTenantId()` zero times**, and reads tenant from the request at ~18 sites. Its only trait usage is **`apiTokenIsValid()`** — *is this token valid*, not *whose is it*: **C22's proxy defect reproduced inside application code.** Lines 596–603 are explicit: `if ($type == 'API') { $sub_institute_id = $request->input('sub_institute_id'); } else { ...session... }` — **the code knows it is serving an API caller and chooses to trust that caller's tenant claim.** Any token holder can read another tenant's payroll. Four further controllers share the class (`contentLibraryController`, `LmsCourseEnrollController`, `talent_interviewschedules`, `talent_jobposting`) — candidates, unread (R6). **Ranks above the no-trait cases: a missing trait is visibly unfinished; an unused one passes every grep, every checker, and sits on the "done" list.** Evidence: `_evidence/sweeps/c27-trait-present-still-broken.md` |
| **G-MAP-01** | **S1** | **THE CAPABILITY MODEL'S CORE MAPPING TABLE HAS NO CREATION PATH.** `s_user_skill_jobrole` — **79,295 rows, one of the four tables in the 283,126 headline** — can only be edited **cell by cell** via `RoleMappingController::upsertCell`. There is no create flow. Command Center's *"Create Role Mapping"* button maps to `kind:'framework'` (`cm-command-center.tsx:56`) — **the same kind as the "Create Framework" button one line above it** — so it builds a framework and writes nothing to the mapping table. **Consequence: a new tenant cannot build a role→skill mapping through the UI at all.** That is a **GOLDEN-THREAD-1 BREAK** — a new customer cannot reach step one of the capability chain. *(Originally logged as F-3 "two buttons, identical behaviour", which understated it: the issue is not the duplication, it is the absence it reveals.)* **WHERE THE 79,295 ROWS CAME FROM — answered:** `SchoolSetupController.php:392-408` (`signup_api`) **bulk-inserts** them at tenant provisioning, copying `jobrole`, `skill`, `proficiency_level`, `skill_code` from the global seed libraries. **A working bulk-create mechanism already exists** — it is simply only reachable from tenant signup, never afterwards. **This is the same mechanism Q-C1's seed-library import needs.** M-03 re-costs from *"build a mapping UI"* to *"surface an existing bulk path"*. **The finding stands at S1** — a customer who adds a job role after signup still cannot map it — but the fix is materially cheaper than first estimated. |
| **G-SEC-11** | **S1** | **28 further controllers fail the tenant-isolation property under execution.** The C23 read-half guard called 912 GET routes twice as one tenant-7 user, varying only `sub_institute_id`; **48 routes across 30 controllers returned different data.** Beyond the two already known: `assignmentController` (6), `HrmsController` (3), `ExcelAutomationAgentController` (2), `HolidayController` (2), and 24 controllers with one each — spanning Payroll, Leave, Talent, LMS, Task, Org and Audit. **HEADLINE INSTANCE, quote this one:** a tenant-7 employee calling **`GET /api/skills`** with `sub_institute_id=3` receives **297,582 bytes of another organisation's skill library** — against **84,363 bytes** of their own. **3.5× more data from an organisation they have no relationship with.** One concrete example carries more weight with a buyer or a board than a count of 48. **48 is a FLOOR** — 454 GET routes were UNTESTABLE and 864 write routes are untested. **Each of the 37 not corroborated by source reading is a CANDIDATE (R6)**; one (`CompetencyDashboardController@getRoleSimilarity`, differing at identical length) is a likely false positive pending hand check. Worklist: `_evidence/sweeps/c23-worklist.md` |
| **G-CERT-01** | **S2** | **There is no certification TYPE entity — only a policy and a per-person instance.** `s_competency_certification_requirements` expresses *"certification Y is required for role/department/competency X"*; `s_competency_certifications` is the held credential (`user_id`, `issued_date`, `expiry_date`, `credential_id`). **Neither references a catalogue** — both carry their own free-text `name` and `issuing_body`, and the instance has no `certification_type_id`. So two employees holding the same real-world certification are two unrelated strings, no coverage or expiry roll-up can be trusted, and the competency mapping has nowhere correct to live (on the policy = wrong relation; on each instance = two employees could disagree about what one certification means). **Larger than L-09 and its prerequisite.** Design: `02-domain-model.md` §10.1, migration steps 3b and 9b. |
| **G-COMP-01** | **S1** | **The competency approval workflow is optional in four independent ways, and rejection is a dead end.** (1) Create takes `approve_status` **from the client**, defaulting to `Approved` (`skillLibraryController.php:2527`) — stronger than a default; there is no server-side constraint. (2) Edit writes `approve_status` straight through with no reviewer or role check (2587-2589), reachable from a dropdown on the form. (3) **Restore is unconditionally `Approved`** (2222-2223) — archive a Pending competency, restore it, and it is approved with no reviewer recorded. (4) On **reject**, `ApprovalController` marks the approval row rejected but leaves the subject `Pending` (316-332), while the UI hides *Submit for Approval* when status is Pending (`cm-competency-library.tsx:1223`) — so a rejected competency **can never be resubmitted**, and the only escape is bypass (2). The backend approval API works; the only UI that can approve/reject sits in the Audit & Activity Center, which `content-map-m2.ts:17-20` says has **no `tblmenumaster_g2g` row**. **Distinct from G-SEC-01: these bypasses would survive a perfect RBAC fix.** Connections C-01, C-02. |
| **G-SEC-07** | ~~S1~~ → **S3, SUBSTANTIALLY CORRECTED 2026-08-07** | **See the full correction above the provenance section. The original claim — that user roles carry no meaning — was measured on the wrong table and is WITHDRAWN.** |
