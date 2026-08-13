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



---

## ✅ CLEARED 2026-08-11 — **X-07d readiness screen is browser-verified. Tracker back to 0.**

    PASS 9   FAIL 0   UNSTABLE 0
    admin runs:    rendered:5 | rendered:5 | rendered:5
    employee runs: refused | refused | refused

Both roles, three identical runs each. The dialog states the loss, the reason and
the warning period, and is not a generic are-you-sure. Cancel writes nothing.

**The screen was broken and the old harness could not say so.** A doubled `api/`
prefix produced `api/api/readiness/gates` -> 404. The previous version counted
error blocks, so a 404 and a 403 were the same observation.

**IT REPORTED THAT 404 AS "employee is refused" - a PASS.** The role guard would
have been certified on the strength of a typo. That is why the tracker's previous
entry said the employee refusal was verified: it was not.

## ⚠ PLATFORM BOUNDARY — **EVERY SCREEN ITEM INHERITS THIS**

**X-21 cannot reliably verify a screen on Windows.** `php artisan serve` is
single-threaded; `PHP_CLI_SERVER_WORKERS` is the documented fix and is a POSIX
fork feature that **measured 4.5 vs 4.4 on this machine - it does nothing here.**
A page firing several requests on mount starves itself and the browser sees a
screen that never finished loading.

**This is G-UI-02's cause**, and it cost X-07d a turn because the harness comment
said *"not optional"* and the measurement said *"does nothing here"* **and the two
notes lived apart.** They are together now, in `x21-browser/readiness.js`'s
header and here.

A properly threaded server (WSL, Docker, a real dev environment) is the durable
fix and belongs to the owner. Until then X-21 verifies what it can.

### WHAT THE HARNESS NOW GUARANTEES

- **Three states, never collapsed:** `rendered` / `refused` / `loading` / `blank`,
  plus `broken` for an error that is not the refusal. "Still loading when we
  looked" is its own answer - collapsing it into FAIL is what would have reported
  a starved server as a product defect.
- **It repeats and compares.** Every observation runs `X21_REPEATS` times (3 by
  default). Disagreement is reported as **UNSTABLE - not PASS, not FAIL.** Three
  identical runs producing three different results is a verdict about the
  harness, and it now says so itself.
- **It prints the error TEXT and the stored session**, never a count.
- **The refusal is a specific sentence**, matched on `Admin and HR only`. Anything
  else is `broken`, and `broken` never passes for anyone.
