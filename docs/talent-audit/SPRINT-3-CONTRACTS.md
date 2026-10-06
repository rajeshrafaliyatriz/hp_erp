# Sprint 3 — The broken contracts

Six things the UI offered that the backend could not honour. Small individually; together they are
most of what makes Recruitment feel unreliable.

## 1. Two of six kanban columns were decoration

Dragging a candidate to **Assessment** or **Offer** wrote `'Assessment'` / `'Offered'`. Neither value
was in the column ENUM, in `store()`'s `in:` rule, or in `update()`'s `$allowedStatuses`.

The last one is what actually mattered, and it is worse than the audit recorded. Line 411 was
`if (in_array($request->status, $allowedStatuses))` with **no else**, and line 414 had already
assigned `$application->status = $request->status ?? ...` **unconditionally**. So:

- an unknown stage was written anyway — `status = 'Banana'` was accepted and stored;
- the checked assignment below it was dead code;
- the card moved, the refresh put it back, and no error was ever raised.

Fixed in three places, from one vocabulary:

| Before | After |
|---|---|
| `enum(...)` column, 7 values | `varchar(30)`, migrated on **both** databases |
| three divergent status lists | one `talent_jobapplicationcontroller::STATUSES` |
| unconditional write at :414 | removed — status is written in exactly one checked place |
| unknown value silently stored | **422** with a field message |

Measured after:

```
status='Assessment'  -> 200, db = Assessment      (was a silent no-op)
status='Offered'     -> 200, db = Offered         (was a silent no-op)
status='Banana'      -> 422, db unchanged         (was 200, db = Banana)
status='Shortlisted' -> 422 once an offer exists  (correct: hasReachedOfferOrHiredStage)
```

The vocabulary now travels with the list as `options.statuses`, so the client stops keeping its own
copy — which is how the two lists drifted in the first place.

**The two databases disagree about invalid writes, which is how this stayed hidden.**
`sql_mode` on the application DB is `STRICT_TRANS_TABLES,...` → error. On live it is
`NO_ENGINE_SUBSTITUTION` → silently `''`. Both hosts hold **58 applications with an empty status** —
rows that match no filter and appear in no column. **Not repaired here; see the open decision below.**

## 2. The hiring decision screen was wrong in three ways at once

The frontend sends `{ decision: 'hired' }` to `POST /interviews/{id}/decision`. The backend
required a field named `status`, matched Title Case, and looked the path id up as a
**TalentEvaluationForm** rather than an interview — so a correct call would still 404.

Now: both field names accepted, either casing normalised, `{id}` resolved as an interview with a
fallback to the old shape, the whole thing in a transaction, and the application update
**tenant-scoped** (it had no tenant predicate at all).

```
{"decision":"hired"}     -> 200, application = Hired      (was 422, always)
{"status":"Rejected"}    -> 200, backward compatible
{"decision":"banana"}    -> 422 with a field message
tenant-3 token, tenant-6 interview -> 404
```

## 3. A Delete button with no route behind it

`DELETE /api/feedback/{id}` did not exist; the interview drawer has had a **confirmed, destructive**
Delete button pointed at it. The user confirmed, and got a 405.

Built it, tenant-scoped. `talent_evaluation_form` has carried a `deleted_at` column all along and the
model never used it, so `delete()` would have been permanent — `SoftDeletes` added. Interview feedback
is the evidence behind a hiring decision: removable, not destroyable.

Also fixed while there: `updateFeedback` used `TalentEvaluationForm::find($id)` with **no tenant
filter**, so one organisation could edit another's interview feedback by id.

## 4. Workflow detail 404'd on a controller method that already existed

One missing route line. `AdminWorkflowController::show()` has been implemented all along.

## 5. Five invented numbers above a live table (audit F-28)

The Administration screen rendered `Active Workflows 28 · Templates 56 · User Roles 18 ·
Integrations 12 · Audit Events 1,248` — hardcoded, directly above a real paginated table, which lent
the fiction credibility. Real values, tenant-scoped:

```
tenant 6  {active_workflows: 0, templates: 0, user_roles: 9, audit_events_30d: 19}
tenant 3  {active_workflows: 0, templates: 0, user_roles: 9, audit_events_30d: 9}
```

Four tiles now come from the server. The fifth, **Integrations**, has no table, no route and no
concept anywhere in the codebase — so the tile is **removed** rather than invented. `mockAdminKPIs`
is deleted.

## 6. F-55 — every validation message in the product was being thrown away

`api-client.ts` never sent `Accept: application/json`. Laravel decides between a 422 JSON body and a
302 redirect on `expectsJson()`, so a failed validation came back as a redirect to HTML, fetch
followed it, and `buildApiError()` fell through to the generic branch. The server generated exact
per-field messages and discarded them in transit.

One header. It fixes field-level errors across the **whole** product, not just Talent.

Also: `round_no` validated as `string|max:255` against a `tinyint(4)` that overflows at 127 — now
`integer|min:1|max:127`.

## Gates

```
tsc --noEmit  7 (baseline 7)     build exit 0     components/ui empty
routes  1850 (+2: feedback delete, workflow detail)
eslint  101 problems - unchanged; the brief's "4" is stale, verified by stashing
```

## Open decision for you — the 58 empty-status rows

Both databases hold 58 `talent_job_applications` rows with `status = ''`. They are invisible to every
filter and every kanban column, so those candidates have effectively vanished from the pipeline.

I have **not** rewritten them. Repairing production data is your call, not a side effect of a schema
migration. The column is now `varchar`, so the repair is possible whenever you decide. The options are
to set them to `Pending Review` (treat as un-triaged), infer from related offers/interviews, or leave
them and exclude them explicitly. Tell me which and I will do it in one reversible statement.
