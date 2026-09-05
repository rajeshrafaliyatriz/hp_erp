# Sprint 1 — Security

Blocking sprint. Closes the finding that carried the audit's RED verdict, plus three holes the
remediation brief did not list. Backend only — **zero frontend files changed**.

## Before / after, executed against a local backend

| Check | Before | After |
|---|---|---|
| Employee reads every candidate's PII (**F-53**) | **200**, 22 rows with email, mobile, expected_salary, resume_path | **403** |
| Cross-tenant read of AI screening verdict | **200** — tenant 6 read tenant 3's row | **403** (role gate) / **404** (wrong-tenant admin) |
| `talent-offer-letter/{id}` — route pointed at a method that did not exist | **500** | **302** to the stored letter |
| `talent-templates` — same | **500** | **200**, honest empty list |
| Cross-tenant offer letter | n/a (was 500 for everyone) | **404** |
| HR/admin can still do their job | 200 | **200** |

Both refusal codes are correct and mean different things: **403** is the role gate refusing an
employee; **404** is tenant scoping refusing an admin of the wrong organisation, because a 403 there
would confirm the row exists.

## What changed

**1. `talent_screening_results_controller` — a live cross-tenant breach.**
All four queries lacked a tenant filter and `store()` persisted a client-supplied `sub_institute_id`.
Now routed through `ResolvesApiIdentity`: the tenant predicate is inside each lookup, the application
is checked to belong to the caller before a verdict can be filed against it, and `sub_institute_id`
and `created_by` come from the token.

**2. `TalentOfferController::reject()` — two faults.**
It was `TalentOffer::find($id)` with no tenant filter, so any token from any organisation could
reject any offer and cascade that onto the application. It also wrote `'rejected'` lower-case into
`talent_job_applications.status`, whose enum is Title Case — and `sql_mode` carries
`STRICT_TRANS_TABLES`, so that raised a truncation error and the endpoint returned **500**. Both fixed.

**3. Two routes revived rather than removed.**
`getOfferLetter` and `getTemplates` were registered at `routes/api.php:212-213` but never existed.
All 68 offers already carry an `offer_letter_url`, and two live buttons call it, so the right answer
was to implement them, tenant-scoped.

**4. Role gates on candidate data.** `profile:admin,hr,recruiter` on job applications, candidate
list, shortlist, interview details and schedules, interview panels, feedback reads, screening
results, offers, offer letters and templates.

*Deliberately left open, and documented at the route:* `POST /evaluation` — a panellist submitting
their own interview feedback is an ordinary employee, so a role gate would stop the one thing they
are there to do. It still needs a panel-membership check, which belongs with the decision-payload fix
in Sprint 3 that already rewrites this contract.

*Also deliberately open:* `job-postings`. A posting is the thing a candidate is meant to read, and it
becomes the public careers page in Sprint 4a.

**5. Legacy ATS writes now take the tenant from the token.** Every routed method in all three legacy
controllers is now `token`, matching what the read methods already did:

```
talent_jobpostingcontroller        index store getHiringStatus update destroy   -> token
talent_jobapplicationcontroller    index store show update updateStatus ...     -> token
talent_interviewschedulescontroller index store update customUpdate ...         -> token
```

## Found while working, not in the brief

`app/Http/Controllers/talent_management/` is a **third** ATS generation — two controllers, six
request-sourced tenant assignments, and **zero routes bound to it**. Unreachable code is not a
security defect, so it is not fixed here; it is added to the Sprint 6 deletion list.

Also recorded for Sprint 3: **58 rows in `talent_job_applications` have an empty-string status** on
the application database. They are invisible to every status filter and every kanban column. This is
historical damage from invalid enum writes and needs a data-repair decision, not just a code fix.

## Gates

```
tsc --noEmit           7 errors (baseline 7)
route:list             1847 routes (baseline 1847)
git diff components/ui empty
frontend files changed 0
```

## How to demo this

Sign in as `milanbaldaniya29@gmail.com` (tenant 6, profile **Employee**) and open Recruitment — the
candidate list is refused. Sign in as `scholarclone@gmail.com` (tenant 6, **Admin**) and the same
screen works exactly as before. The point of the sprint is those two screenshots side by side: the
same URL, two people, two different answers.
