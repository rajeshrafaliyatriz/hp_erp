# Screen work shipped while G-UI-02 is open

> ## ⚠ 2026-08-11: G-UI-02 IS A HARNESS DEFECT, NOT A PRODUCT DEFECT.
>
> The employee sidebar renders all five modules against a concurrent server.
> **These five items may never have been unverified.** The list stands until the
> harness fix completes and X-21 runs over it - then it closes or it does not, on
> evidence rather than on assumption.
>
> **Item 8's SKIPPED - "Not yet assessed not reached" - is almost certainly the
> same cause.** The screen was reachable; the harness could not navigate to it.

**X-21 cannot reach screens while G-UI-02 is live** - the employee sidebar renders
nothing, so the harness cannot navigate to new UI. Every screen change shipped
after 2026-08-11 lands **unverified by browser**, and this is the defined set to
verify when G-UI-02 is fixed - rather than "everything since".

**AT FIVE ITEMS, G-UI-02 COMES BACK** with the new instrument (instrumentation
inside the hook: the call site, the `enabled` evaluation, the cache read). Not the
network boundary - that layer produced five eliminations and no answer.

| # | Item | Files | What a browser must confirm |
|---:|---|---|---|
| 1 | **C-10** | `library-config.ts` | `approve_status` renders in the drawer and **not** in the edit form (it is `readOnly`); `skill_maps` renders |
| 2 | **L-06** | `library-tab.tsx` | the delete dialog shows the count, its basis line "Counted by key", the breakdown, and the **failure state** when the request fails - not a zero |
| 3 | **G-UI-01** | `cm-my-capability-screen.tsx`, `content-map-m2.ts`, `index.ts` | the capability screen renders at menu 224 for all 8 granted roles; unmeasured shows "Not yet assessed" and no zero |

| 4 | **X-03** (partial) | `library-config.ts` | `proficiency_level` offers suggestions from `meta.proficiency_levels` and still accepts a new value |

**4 of 5.** T-02 was expected to add a fifth and does not: it is re-scoped to a finding (G-TASK-03), not a screen change.

## Why the cap is 5 and not a time limit

Unverified screen work compounds: each item is individually small, and the set as a
whole becomes something nobody can hold in their head. At five, verifying the
backlog costs more than fixing the blocker - so the blocker wins.

## What IS still verified on these

Tier 1 (API values) and Tier 2 (component source) run on every smoke invocation and
cover all three. **What is missing is only what a browser can see**: that the
element is visible rather than present, that the component does not throw on
render, and that the data arrives. Those are exactly the three residue items X-21
exists for - and exactly the class G-UI-02 belongs to.

---

## 1 of 1 — **X-07d readiness screen** — built, NOT browser-verified (2026-08-11)

`g2gv0/app/organization/readiness/page.tsx`. It type-checks and its API half is
proven by request. **It has never been rendered in a browser**, so it counts here.

**THE BLOCKER IS A CREDENTIAL, NOT THE CODE.** The X-21 harness
(`_evidence/x21-browser/readiness.js`) runs end to end - it starts, takes its
fixture, and restores it - but every login attempt is refused:

    GET /login?type=API   healthcare@gmail.com          -> Invalid User Id And Password
    GET /login?type=API   vikram.sethi@healthcare.g2g   -> Invalid User Id And Password

The tenant-3 seed's 9/9 role logins were verified when it was built, but **the
password was never written down** - not in the seed register, not in the
implementation log. Two attempts, then stopped (R26). One credential unblocks the
whole verification.

### ⚠ WHAT THE NEXT SCREEN ITEM MUST KNOW — **A LARAVEL-CALLING SCREEN IS FIRST OF ITS KIND**

**No page under `app/organization/` calls the Laravel API directly.** There is no
fetch pattern to copy. Discovering that mid-build costs more than finding it up
front, so:

- The per-user bundle comes from **`readLaravelSession()`** (`lib/laravel-session.ts`),
  written at login by `AuthProvider`. It carries `token`, `sub_institute_id`,
  `user_profile_name`, `syear`, `user_id`.
- The base URL comes from **`resolveApiBaseUrl()`** (`lib/api-config.ts`).
- The login route is **`GET /login?type=API`**, not `POST /api/auth/login`.
- **DO NOT REINTRODUCE `NEXT_PUBLIC_HP_*`.** Those fallbacks were removed because
  they pinned every browser to `sub_institute_id=1` behind one shared bearer
  token. If the session is absent, refuse - never fall back to a tenant.

### What IS verified

- The endpoint, by request: administrator 200, hr_manager 200, employee 403.
- The fixture discipline: the harness manufactures one `at_risk` gate and
  restores it in a `finally`. Confirmed after both runs - tenant 3
  `reporting_coverage` back to `blocked|null`, **0 gates left `at_risk` anywhere**.

