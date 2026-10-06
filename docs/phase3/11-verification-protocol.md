# 11 — C20 verification protocol · **APPROVED 2026-08-06**, with the §3a amendment

**The problem this solves:** `tsc` and `php -l` prove a change *compiles*. They
prove nothing about whether it *works*. Without a named place and a named person,
**"Built, unverified" becomes the permanent state of the whole queue**, and in
three months nobody knows which changes were ever exercised.

Two items already sit in that state (D-001, D-002).

---

## 1. WHERE it runs

**Proposed: your machine, against the shared dev database.**

Honest reasoning rather than a preference:

| Option | Verdict |
|---|---|
| **Your local dev** | **Recommended.** Both repos are already there, and the app is already running against the shared DB. Nothing to provision |
| A shared staging instance | Better long-term and **required before a real customer exists**, but standing one up is its own project and would block the queue now |
| My environment | **Not possible.** See §2 |

**This is a stopgap, and it should be named as one.** Before the first paying
tenant, verification must move to an instance that is not someone's laptop.

---

## 2. WHO confirms — plainly, what I can and cannot do

### What I CAN execute

| | |
|---|---|
| Static analysis | `tsc --noEmit`, `php -l`, lint |
| Reading code | any file, either repo |
| Grep / sweep scripts | yes |
| **Direct database queries** | **yes** — used already for the two-tenant isolation proof and the `s_skill_matrix` blob analysis |
| Migration dry-runs against a scratch schema | yes |

### What I CANNOT execute

| | |
|---|---|
| **Start the Next.js app or the Laravel server** | no |
| **Click through a UI** | no |
| **See a rendered screen** | no |
| **Confirm "the button opens the panel"** | no |

**Therefore: I cannot mark my own work `Verified`.** Any acceptance test with a UI
step needs you. I will not claim otherwise, and a status of `Verified` in any
document means **you confirmed it**, not that I inferred it.

### The split that follows

| Test type | Who | Example |
|---|---|---|
| **API-level** — request in, database state out | **me**, via direct queries + `curl`-equivalent if a token is available | AT-D002a steps 1–6, AT-D002b steps 1–3, 6–7 |
| **UI-level** — a screen renders, a control appears | **you** | AT-L03R steps 1/4, AT-D002a step 7, AT-D002b steps 4–5 |

**This changes the estimate for D-002 favourably:** most of its acceptance tests are
API-level and I can run them, if you tell me the app is running and give me a token.
Only 3 of 14 steps genuinely need eyes on a screen.

---

## 3. The hard cap

> **No more than THREE items may sit in `Built-unverified` at once. At three, the
> build queue stops until they are confirmed.**

Accepted as proposed. Current count: **2 of 3** (D-001, D-002).

**Consequence, stated now rather than discovered later:** the next build item
(C19, the picker mechanism) would make it **3**, and the queue would then halt.
So either D-001 and D-002 get verified first, or C19 is the last thing built before
a stop. I would rather hit the cap deliberately than drift past it.

---

## 3a. AMENDMENT — the cap must not be blocked by your availability

**Approved amendment, applied.**

| Status | Counts against the cap? |
|---|---|
| `Built-unverified` — **nothing executed** | **YES** |
| `API-verified-UI-pending` — every API-level step passed, only visual confirmation outstanding | **NO** |

This is the right shape: it stops my inability to click a screen from stalling the
build queue, while keeping genuine unknowns capped. It also puts the incentive in
the right place — I clear items to `API-verified-UI-pending` by actually running
the API steps, not by waiting.

## 4. Status vocabulary — no blended claims

| Status | Means | Who may set it |
|---|---|---|
| `Planned` | specified, not written | me |
| **`Built`** | code written, **static checks pass**, acceptance tests **not yet run** | me |
| **`Built-unverified`** | identical to `Built` — used when it has been sitting long enough to count against the cap | me |
| **`Verified`** | **every** acceptance test executed and passed | **you only** |
| **`API-verified-UI-pending`** | API-level steps **executed and passed**; UI steps **named** and outstanding. **Does not count against the cap** | me, for the API part only |

**Never** "built and working", "should work", "verified locally", or `Verified`
with an asterisk. If some steps passed and others did not run, the status is
**`API-verified-UI-pending`** with the outstanding steps listed by name.

---

## 5. Exactly what to send — one round, not three

**Writing to test data is approved**, under the two stated conditions: one named
test tenant, and every created row recorded and removable. §6 is the register.

### Two Sanctum tokens, because D-002 needs two sides of a permission boundary

| # | Role needed | Why this specific role | Which steps it runs |
|---:|---|---|---|
| **1** | **An ORDINARY user** — can reach the Competency Library, is **not** an approver | This is the attacker in AT-D002a. The whole point is that a normal caller sending `status: "Approved"` gets a **Pending** row. A token with approval rights would pass the test for the wrong reason and prove nothing | AT-D002a 1–6 · AT-D002b 1 |
| **2** | **An APPROVER** — Admin or HR, whatever profile the approval queue actually accepts | This is AT-D002b: the legitimate path must still work. It must be a *different* identity from #1, or self-approval is untested | AT-D002b 2–3, 6–7 |

**Sanctum bearer tokens are what I need**, not credentials to exchange — this app
resolves tokens manually via `PersonalAccessToken::findToken()`, so a raw
`plainTextToken` works directly and I avoid guessing at a login flow.

### Also send

3. **The base URL** the Laravel API is served on (e.g. `http://localhost:8000`).
4. **The `sub_institute_id`** of the named test tenant, so every row I create is scoped to it and nothing lands in another tenant.
5. **Confirmation the Next.js app is running**, for your 3 UI steps. Not needed for mine.

### What I will do with them

Run the **11 API-level steps** for D-001 and D-002, report pass/fail per step with
the actual database state, and list every row created. Both items then move to
**`API-verified-UI-pending`** — which under §3a **frees the cap**, so C19 can
proceed without stalling.

**If a token arrives for only one role**, I will run what it covers and say
explicitly which steps could not be executed. I will not substitute one role for
the other.

---

## 6. Created-row register

Every row created during verification is listed here — table, id, tenant, and the
step that created it — so it is identifiable and removable afterwards.

*(Empty. Populated when the first verification run happens.)*

| Run | Table | Row id | Tenant | Created by step | Removed? |
|---|---|---|---|---|---|
| 2026-08-06 | `personal_access_tokens` | **4554** | 7 | C23 guard — ordinary-user token, `user_id=198` (Elakshi Seth, profile 20 = **Employee**) | no |
| 2026-08-06 | `personal_access_tokens` | **4555** | 7 | C23 guard — approver token, `user_id=44` (Education Admin, profile 19 = **Admin**) | no |

**Two rows created. Nothing else.** The C23 read-half guard issues only GET
requests and writes nothing.

### Test identities in use

| | Tenant | user_id | Profile |
|---|---:|---:|---|
| **A** — the caller | **7** (201 users) | 198 / 44 | Employee / Admin |
| **B** — the tenant being impersonated | **3** (108 users) | — | — |

Profiles in this schema live in `tbluserprofilemaster`: **19 = Admin, 20 =
Employee, 21 = HR**.
