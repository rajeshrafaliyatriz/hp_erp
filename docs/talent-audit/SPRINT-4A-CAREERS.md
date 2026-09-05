# Sprint 4a — The public careers page

The audit's Part D finding: *"the candidate has no screen, no login and no assessment anywhere in the
codebase; the only thing that reaches them is an emailed PDF they cannot reply to."* Recruitment
began with an application somebody inside the company had to type in.

It now begins with the candidate.

## Proven end to end, with no token at any point

```
HR posts a role       POST /api/job-postings          (authenticated, existing screen)
                          ↓  appears instantly
Candidate browses     GET  /api/careers/{slug}        200, no token
Candidate opens it    GET  /api/careers/{slug}/postings/{id}
Candidate applies     POST .../apply                  201, application_id 969
                          ↓
HR sees them          GET  /api/job-applications      "Asha Patel", Pending Review, CV attached
```

| Check | Result |
|---|---|
| Public read, no token | **200** |
| Unknown slug | **404** |
| Application accepted | **201** |
| Same person applies twice | **409** — "You have already applied for this role." |
| Missing / invalid fields | **422** with per-field messages the form maps onto its inputs |
| Three attempts, rows added | **exactly 1** |
| Tenant 3 can see the tenant-6 application | **no** |
| Stranger reads `/api/job-applications` | **401** |
| Rate limiting | live — `X-RateLimit-Limit: 30` on reads, `5/min` on apply |

## How tenancy works here, and why it is not the usual defect

Everywhere else in this codebase, taking the tenant from the request **is** the bug — a token
identifies its owner, so trusting a request parameter lets a caller name someone else's organisation.

Here there is no token, by design: a candidate is not a user. The tenant comes from the careers slug
in the **path**, which is the resource identifier itself, uniquely indexed, and used only to scope
reads and to stamp a new application. It never widens access to anything already stored. What a
stranger can reach is bounded to: an organisation's display name, its **active, non-expired** job
postings, and the ability to add one row to `talent_job_applications`. No candidate data is ever
returned — without a login there is no "their own" to prove.

Every public route is rate limited. The application had **no throttling anywhere**, so this is a new
control rather than an existing pattern.

## The slug

`institute_detail.careers_slug varchar(64)`, uniquely indexed as `inst_careers_slug_unique`, applied
to **both** databases and backfilled from the organisation name.

`organization_code` was the obvious candidate and could not be used: it is `varchar(191)` (the house
rule forbids indexing one), it is **not unique on live** — 5 rows, 4 distinct values — and it is
operational data an administrator may edit, so a public URL would break when someone fixed a typo.

Collision handling is deterministic (`fiber-valley-1000018`, `scholar-clone-pvt-ltd-6`), so re-running
produces the same slug and a new tenant cannot take a name that changes an existing URL.

**Note:** the two deployments hold different organisation names, so a tenant's careers URL differs per
deployment. Tenant 6 is `/careers/scholar-clone-pvt-ltd` on the application database.

Tenants with no `institute_detail` row simply have no careers page — correct, rather than inventing a
public identity for an organisation whose name we do not know.

## Design

New surface, built to the newest conventions. **No existing Talent screen was modified.**

- `@container/careers` and `@container/job`, never viewport breakpoints — the pages are correct at any
  width because they measure their own container, not the window.
- The radius ladder: `rounded-xl` outer frames, `rounded-lg` nested, `rounded-md` controls.
- `text-[11px] font-bold uppercase tracking-wider` eyebrows; `tabular-nums` on every number — salary,
  openings, dates, counts.
- Semantic tokens only. No raw colours.
- **Form errors render inside the form, above the fields**, plus a per-field message under each input.
  No toasts. Server-side validation errors are mapped back onto the fields they belong to, so a
  candidate is told which answer to change rather than "Request failed".
- Loading skeletons, a real empty state ("No open roles right now"), and a success state that replaces
  the form rather than a banner beside it.

The pages deliberately do **not** use `GtgAppShell` or `GtgPageShell`: both mount
`useSidebarNavigation()`, which needs a Laravel token, and both render a header assuming a signed-in
user. `/careers` is also a top-level segment, so `proxy.ts` never guards it.

## Demo data created

Real rows, listed so they can be removed if unwanted:

- **Job posting 353** — "Senior Laravel Engineer", tenant 6, created through the *existing*
  authenticated HR API, not inserted by hand. All 126 pre-existing postings are `Inactive` (auto-expired
  by the deadline sweep in `jobposting@index`), so the careers page correctly showed zero roles until a
  live one existed.
- **Application 969** — "Asha Patel", submitted through the public form.

## Gates

```
tsc --noEmit  7 (baseline 7)     build exit 0, /careers/[slug] and /careers/[slug]/jobs/[id] compiled
components/ui empty              routes 1853 (+3 public careers routes)
```

## How to demo this

1. Open an **incognito window** — no session at all — and visit
   `/careers/scholar-clone-pvt-ltd`.
2. Browse the open role, open it, and apply with a CV.
3. Sign in as HR (`scholarclone@gmail.com`) and open Recruitment. The candidate is in the pipeline
   under **Pending Review**, with their CV attached.

Nobody retyped anything, and the candidate never logged in.
