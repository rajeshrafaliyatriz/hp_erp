# Sprint 2 — The spine of the hire

Closes the joint the lifecycle was severed at: an offer could be **rejected but never accepted**, so
the hire stopped there and somebody retyped the candidate into the Employee Directory by hand.

Audit lifecycle stages 9 (`FE-MISSING`) and 10 (`NONE-BUILT`).

## Proven end to end

```
POST /api/talent-offers/27/accept   (tenant 6, admin token)
  -> 200  {"employee_id":2576,"created":true,"invite_sent":true}

  tbluser(t6)            21 -> 22
  talent_offer_acceptances 0 -> 1
  s_mobility_transfers(t6) 2 -> 3     department arrival row
  g2g_event employee.hired 2 -> 3     audit event

POST the same call again  (idempotency)
  -> 200  {"employee_id":2576,"created":false,"message":"This offer was already accepted."}
  every count unchanged
```

| Check | Result |
|---|---|
| New hire in the Employee Directory | **Rajesh Rafaliya** · dept Development · profile Employee · joined 2026-05-01 |
| Offers list presents the acceptance | offer 27 → `accepted`, `accepted_employee_id: 2576` |
| Cross-tenant accept (tenant 3 → tenant 6 offer) | **404** |
| Employee token accepting | **403** |

## What was built

**`App\Services\HRMS\EmployeeFactory`** — the one place a `tbluser` row is created from. Extracted
from `EmployeeDirectoryController::store()` rather than copied, so the directory form and the accept
path cannot drift. It owns the generated credential, `uniqueUserName()`, tenancy from the token, the
`allocated_standards` + `jobtitle_id` dual write, the department arrival row, the `employee.hired`
event and the invite. `EmployeeDirectoryController` now calls it and its two private duplicates are
deleted.

**`talent_offer_acceptances`** — a root table carrying its own `sub_institute_id` + `syear`, the
token hash, expiry, single-use marker, decision, and the employee the acceptance produced. Applied to
**both** databases and verified identical: 19 columns, 6 indexes, 1 FK, longest identifier 27 chars,
none over 64. `ROW_FORMAT` differs (Dynamic on dev, Compact on live) — that is the environment, not
drift.

**`POST /talent-offers/{id}/accept`**, gated `profile:admin,hr,recruiter`. Idempotent twice over: an
acceptance already marked accepted returns the employee it created; failing that, an existing
employee with the candidate's email in this tenant is adopted rather than duplicated.

**`index()` presents a derived status.** `talent_offers.status` is a real ENUM with no `accepted`
member, so acceptance is folded in from the new table at read time. No `ALTER` on a live table. The
frontend already mapped `accepted` → Accepted and already counted it, so the badge and KPI needed no
change.

**Frontend:** an "Accept offer" item beside Reject, through the confirmation dialog already wired on
that row. Two files, 19 lines.

## Found while building

**1. `tbluser_email_unique` is global, not per tenant.** Accepting offer 5 returned 422 — correctly:
that candidate's address already belongs to a **tenant 2** user. The same person cannot be an
employee of two organisations. This is real behaviour worth knowing about, not a bug, and the error
message says so.

**2. `tbluser.email` and `talent_job_applications.email` have incompatible collations** —
`utf8mb4_unicode_ci` against `utf8mb4_general_ci`. Comparing them throws
`SQLSTATE[HY000] 1267 Illegal mix of collations`. Any future code joining those two tables on email
will fail outright. `EmployeeFactory::findByEmail()` sidesteps it by binding a PHP string rather than
joining, but the schema needs aligning — added to Sprint 5 alongside the other charset work.

**3. Applications are marked `Hired` with no employee behind them.** Every tenant-6 offer sampled had
`status = 'Hired'` on its application while no `tbluser` row existed. The kanban has been moving
people to Hired for months without the hire ever happening — which is precisely the handoff this
sprint builds.

## Gates

```
tsc --noEmit    7 errors (baseline 7)
npm run build   exit 0
eslint          101 problems, identical with and without this sprint's changes;
                the two changed files produce zero output
git diff components/ui/   empty
route:list      1848 (1847 + the new accept route)
```

**Correction to the brief:** it states the lint baseline is "4 pre-existing problems". Measured today
it is **101** (81 errors, 20 warnings), and it is 101 both with and without these changes. The figure
in the brief is stale; reporting the real number rather than the one that was expected.

## How to demo this

Recruitment → **Offers** tab → row menu → **Accept offer**. One click changes five things already on
screen:

1. the row badge flips to **Accepted**
2. the Offers KPI subtitle count increments
3. **Start onboarding** appears in the same menu
4. the Talent Dashboard's offer-acceptance rate stops saying "No offers extended"
5. the person appears in the **Employee Directory** — not retyped

Then click Accept again on the same offer: nothing duplicates.
