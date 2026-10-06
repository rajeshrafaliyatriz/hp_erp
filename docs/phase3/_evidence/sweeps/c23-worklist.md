# C23 — tenant guard, read half · **THE WORKLIST**

**Executed 2026-08-06** against the real database, all six route files, via
Laravel's HTTP kernel in-process. Nothing was written.

**The property tested directly (not a proxy):** a route that resolves the tenant
from the **token** must return the **same response** no matter what
`sub_institute_id` the caller puts in the request.

Same user (198, tenant 7, Employee) called each route twice — once with
`sub_institute_id=7`, once with `sub_institute_id=3`. **A different response means
the route honoured the caller's tenant claim.**

---

## Result

| | Routes | Meaning |
|---|---:|---|
| **FAIL** | **48** | **Response changed when another tenant's id was supplied. Executed, not inferred.** |
| PASS | 321 | Identical JSON payload; the supplied tenant was ignored |
| VACUOUS | 89 | Identical, but the response carries no tenant data (HTML shell or trivial body). **Not counted as a pass** |
| UNTESTABLE | 454 | Path parameter, closure route, redirect, or 500. **Never scored clean** |
| **Total GET routes** | **912** | |

> **48 IS A FLOOR, NOT A TOTAL.** 454 routes — **half the read surface** — could
> not be called by this harness. And this is the **read half only**: 864 write
> routes are untested. Quoting 48 as "the number of vulnerable routes" would
> repeat the C25 error.

---

## The failure list, by controller

| Controller | Failing GET routes |
|---|---:|
| **`PayrollController`** | **9** |
| **`assignmentController`** | **6** |
| **`HrmsController`** | **3** |
| `skillLibraryController` | 2 |
| `ExcelAutomationAgentController` | 2 |
| `HolidayController` | 2 |
| `skillcontroller`, `jobroletaskcontroller`, `jobroletexonomycontroller`, `CompetencyDashboardController`, `AuditController`, `DepartmentManagementController`, `talent_interviewpanelController`, `EmployeeDirectoryAnalyticsController`, `TemplateController`, `LeaveTypeController`, `ApplyLeaveController`, `LeaveSummaryReportController`, `masterSetupController`, `sub_std_mapController`, `CustomModuleController`, `AJAXController`, `courseRecommandation`, `courseController`, `questionmasterController`, `taskController`, `tblmenumasterG2gController`, `organizationDetailsController`, `discliplinaryManagementController`, `HrmsLeaveController` | 1 each |
| **30 controllers** | **48** |

### The largest leaks by volume

| Route | Own tenant | Other tenant |
|---|---:|---:|
| `skillcontroller@index` · `api/skills` | 84,363 B | **297,582 B** |
| `skillLibraryController@competencyLibraryExport` | 37,716 B | **75,197 B** |
| `skillLibraryController@competencyLibraryIndex` | 5,025 B | 4,489 B |

`api/skills` returns **3.5× more data** for a tenant the caller does not belong to.

---

## R1 — two independent methods agree

This matters more than either result alone:

| Method | PayrollController | skillLibraryController |
|---|---|---|
| **Static** — reading the source (C27, C15) | trait imported, `apiTenantId()` never called, ~18 request reads, explicit `if ($type=='API')` branch | `competencyLibraryContext()` discards the token owner |
| **Dynamic** — this guard, executed | **9 routes leak** | **2 routes leak** |

**G-SEC-09 and G-SEC-10 are now confirmed by execution, not inference.** The static
reading predicted the failure; the guard reproduced it.

---

## R10 — what this checker measures, and its gaps

| | |
|---|---|
| **Property** | the acting tenant is resolved from the token, never from the request |
| **Proxy** | the response body is byte-identical across two values of `sub_institute_id` |
| ~~Passes proxy, fails property~~ | ~~a route that leaks identically for both tenants would score PASS~~ — **CLOSED by C28.** Every response is now scanned for strings that exist **only in tenant 3**. A marker in the *baseline* response (no impersonation attempted) is classified **`LEAK-NOSCOPE`** — the no-scoping case the differential test structurally could not see |
| **Fails proxy, passes property** | a response containing a timestamp, a random ordering, or a request echo would differ innocently. **One candidate already seen:** `CompetencyDashboardController@getRoleSimilarity` differs at **identical length (5,857 vs 5,857 bytes)** — needs a hand check before it is called a leak |
| **Cannot see at all** | the 454 UNTESTABLE, and every write route |

**Every FAIL is a candidate until read (R6).** The two `skillLibraryController` rows
and the nine `PayrollController` rows are corroborated by independent source
reading; **the other 37 are not yet.**

---

## The expected fix shape — proven on `skillLibraryController` (D-003)

**This is adoption, not a per-controller rewrite.** The whole fix was:

```php
// competencyLibraryContext() - entire body
return $this->resolveApiIdentity($request);
```

`ResolvesApiIdentity` returns `['user','user_id','sub_institute_id']` — a
**superset** of what these bespoke resolvers produce — so call sites that check
`is_array($context)` and read those keys need **no change at all**. Eleven call
sites, zero edits. Plus a `use` statement and an import.

**Expect the same shape wherever a controller has its own resolver method.**
`PayrollController` is the exception that proves it: it has **no single resolver**
— 18 inline sites in three styles — so it needs `apiTenantId($request)` substituted
per site, read individually. **Budget for that difference.**

## C28 — content-based detection · added 2026-08-06

The differential test asks *"did the response change?"*. That misses the worse
case: **a route with no tenant scoping at all returns everyone's rows to everyone,
produces two identical responses, and passes cleanly.** Per R11, that is exactly
where more would hide.

Every response is now also scanned for **long, distinctive strings that exist only
in tenant 3** — job-role and skill titles such as *"Assistant Director of Nursing
(Clinical)"* and *"Ambulance Readiness and Maintenance"*.

| Class | Meaning |
|---|---|
| **`LEAK-NOSCOPE`** | Tenant B's data present **with no impersonation attempted**. The route has no scoping at all |
| **`FAIL`** | Tenant B's data returned **when impersonating**, or the response changed |

**Personal first names were deliberately excluded from the markers.** Tenant 3
contains a user whose first name is literally **"Healthcare"**, which would match
any healthcare content and manufacture false positives (R4, before quoting).

This also collapses `VACUOUS` honestly: a static HTML shell contains no tenant
markers and passes **for the right reason**.

**Smoke test on the fixed `skillLibraryController`: `LEAK-NOSCOPE 0, FAIL 0`.**
The fix holds under the stronger test, not just the differential one.

### ⚠️ C28's FIRST RESULT WAS WRONG — and its correction is a finding in itself

The six-file run reported **4 `LEAK-NOSCOPE`** routes
(`buildwithAIController@index`, `lms_lessonplanController` ×3). **All four are
FALSE POSITIVES**, verified before reporting (R4, case 12 — and the **first case
that over-reports rather than under-reports**).

The marker *"Community Care Associate"* is not unique to tenant 3:

| Where it lives | |
|---|---|
| `s_user_jobrole` id 3154 | tenant **3** |
| `s_user_jobrole` id 917 | tenant **1** |
| `s_jobrole` id 918 | **`sub_institute_id` NULL — the GLOBAL reference library** |

My marker script asked only *"does this string exist in tenant 7?"*. It never
checked the other tenants, nor the global libraries. A route legitimately serving
the **global** job-role catalogue therefore tripped the marker.

#### The correction exposes something more useful

Re-selecting markers under the strict rule — *must appear in tenant 3 and
**nowhere** else, including `s_jobrole`, `master_skills` and `s_jobrole_skills`* —
leaves:

| | Surviving markers |
|---|---:|
| Job roles | **2** — and one is `ZZ QA Smoke Role`, someone's test artefact |
| Skills | **0** |

**Out of tenant 3's entire library, essentially no job-role or skill title is
unique to it.** Every tenant is seeded from the same global reference libraries, so
**content-based detection on entity titles cannot work in this dataset.** That is a
property of the data, not a flaw in the idea.

**Consequence:** `LEAK-NOSCOPE` is **retracted for now — 0 confirmed**, and the
no-scoping gap identified under R10 remains **OPEN**. The next marker class must be
genuinely tenant-unique data: **employee email addresses, credential ids, or
per-tenant record ids** — not names drawn from a shared catalogue.

### Standing numbers after the re-run

| | Before the fix | After D-003 + C28 |
|---|---:|---:|
| FAIL | 48 | **46** |
| PASS | 321 | 319 |
| VACUOUS | 89 | 89 |
| UNTESTABLE | 454 | 454 |
| LEAK-NOSCOPE | — | **0 confirmed** (4 reported, 4 refuted) |

The 2-route drop in FAIL is exactly `skillLibraryController`, now fixed. **The 319
PASSes remain untrusted** until a working marker class exists.

## Next actions, in order

1. ~~Fix `skillLibraryController`~~ — ✅ **DONE (D-003). Guard re-run: 2 FAIL → 0.** The loop that validates the instrument is now closed.
2. **Fix `PayrollController`** — ~18 sites, three resolution styles, read individually. **Check the `$type=='API'` branch's callers first** — it is deliberate, so it earns one check that a mistake would not.
3. **Hand-verify the other 37 FAILs** before fixing, starting with `assignmentController` (6) and `HrmsController` (3).
4. **Reduce the 454 UNTESTABLE.** Most need a valid path parameter; supplying real ids from tenant A would convert a large share into real tests. **This is the single highest-value improvement to the guard** — half the read surface is currently invisible to it.
5. **Then the write half**, per controller, opt-in, rows registered.

**The guard is now the completion criterion.** "Green" replaces "we think we got
them all", which is exactly what C23 was inverted to achieve.


---

# C34 — structural no-scoping test · **114 candidates, NOT QUOTABLE**

Called every parameterless GET route twice: as tenant 7 with id 7, and as tenant 3
with id 3. **Identical non-trivial bodies mean neither tenant is being scoped.**
No unique string required, so the marker problem that defeated C28 does not apply.

| | Routes |
|---|---:|
| **NO-SCOPING** (candidates) | **114** across 67 controllers |
| SCOPED | 206 |
| VACUOUS | 93 |
| UNTESTABLE | 192 |
| Total | 605 |

## ⛔ R16 gate: FAILED — and the failure was my error, not the tool's

I named **`GET /api/skills`** as the known-positive. **Wrong class.** Its bodies
*differ* (84,363 vs 297,582), so it **is** scoped; C23 flagged it because supplying
a **foreign** id changed the response.

| Defect | Detector | Confirmed |
|---|---|---:|
| **Wrong scope** — honours the caller's tenant claim | C23 differential | **46** |
| **No scope** — serves everyone to everyone | C34 structural | **0 at the time of writing** |

C34 targets a class **not yet proven to exist here**, so it has no pre-existing
known-positive. **R16 is satisfied differently: the first hand-confirmed hit becomes
the calibration retroactively.** Until then the 114 is **not quoted.**

## Hand-verification begun — 2 of 114

### 1. `IndustryController@index` → **CONFIRMED FALSE POSITIVE**

`s_industries` (317 rows) has **no `sub_institute_id` column**. It is a global
reference table in exactly the class **C33** established. Returning the same body
to every tenant is **correct**. This is the documented R10 gap firing as predicted.

### 2. `CompetencyDashboardController@index` → **structural finding, not yet a verdict**

The controller **does** filter by `sub_institute_id` (lines 27, 39, 46), so the
identical 7,045-byte response is not simple negligence. Investigating it surfaced
something worth its own line:

> **`s_skill_matrix` — the per-employee capability measurement store, 169 rows —
> has NO `sub_institute_id` column.**

Capability data is therefore tenant-scoped **only indirectly, through `user_id` →
`tbluser.sub_institute_id`**. That is defensible *if every query joins to
`tbluser`* — and silently global if any query counts or aggregates the matrix
directly. That is the most plausible explanation for a tenant-filtered controller
returning an identical body, but **it is a hypothesis, not a verdict**: confirming
it means reading the dashboard's panel queries, which is not done.

**Recorded as `G-DATA-08` (candidate).** It matters beyond this route: the
normalisation migration (§10 step 12) rebuilds this table, and **whether the new
`skill_matrix_item` carries a tenant column is a decision that should be made
deliberately rather than inherited.**

## What the 114 needs before it means anything

Each candidate is one of three things, and only the third is a defect:

1. **A global reference table** — correct (`s_industries` proven).
2. **Empty or error-identical for both tenants** — vacuous; the VACUOUS filter catches only some.
3. **Genuine tenant data served unscoped** — the finding. **Zero confirmed so far.**

**Next:** work down the 114 by controller, starting where tenant data is certain —
`PayrollController` (6 hits, salary) and `AssessmentCycleController` (4).
