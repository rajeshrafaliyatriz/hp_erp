# C21 — definitive enumeration · C22 — what Phase 1's method missed

---

# C22 first, because it explains C21's size

## The question

Did Phase 1's "tenant isolation verified from both sides" rest on the same
incomplete route enumeration my C15 checker just made?

## The answer: **partly — but route enumeration was NOT the main hole.**
## The main hole was the TEST CRITERION.

`scripts/audit-auth-sweep.py:181`:

```python
if GUARD_CALL.search(body) or FINDTOKEN.search(body):
    # -> classified as GUARDED
```

`FINDTOKEN` is `PersonalAccessToken::findToken`. **Any method that called
`findToken()` was counted as guarded.**

`competencyLibraryContext()` calls `findToken()`. It therefore **passed Phase 1's
auth sweep as correctly guarded** while taking `sub_institute_id` and `user_id`
straight from the request body.

> **The sweep tested for the PRESENCE OF AUTHENTICATION, not for CORRECT IDENTITY
> RESOLUTION.** A controller that validates a token and then throws away its owner
> passes an "is it authenticated?" test perfectly. That is precisely how G-SEC-09
> survived a phase whose entire purpose was to find it.

### The secondary hole — enumeration, as suspected

| Script | Scope |
|---|---|
| `audit-auth-sweep.py` | `routes/api.php` **only** |
| `audit-authorization.py` | `routes/api.php` **only** |
| `audit-route-controllers.py` | `glob(routes/*.php)` — **all seven** ✅ |

Two of three read one route file. C21 finds **1,463 routes across seven files**;
`api.php` holds roughly half. **The other six route files were never swept for
authentication at all.**

## Conclusions that inherit the blind spot — list only, no re-auditing

1. **Every "N routes are guarded / protected" figure from Phase 1.** Inflated by an unknown amount: any `findToken()`-only method was scored as guarded.
2. **Every conclusion scoped to `api.php`.** `hrms.php`, `lms.php`, `settings.php`, `user.php`, `web.php` were never auth-swept. That is not a wrong answer — it is *no answer*, previously presented as coverage.
3. **"Tenant isolation verified from both sides."** The live two-tenant test was real and its result stands — **for the controllers it exercised**. It proved isolation *where the fix had been applied*; it never enumerated where it had not. A passing test on fixed code says nothing about unfixed code.
4. **The 279 unguarded-write count.** Same criterion, same direction: understated.

**What does NOT inherit it:** schema findings, missing tables, absent join keys,
row counts, the duplication analysis. None depends on route classification.

---

# C21 — the definitive enumeration

**No sampling. All seven route files. Both directions.**
`_evidence/sweeps/c21-tenant-enum.py`, results in `c21-result.json`.

## Totals

| | |
|---|---:|
| Route files parsed | **7 of 7** |
| Routes found | **1,463** |
| Controllers referenced | 258 |
| **Controllers reachable by route AND resolving tenant/user from the request** | **77** |
| — of those, reachable via **`api.php`** (token auth) | **35** |
| — with a write verb reachable | **all 77** |

**Both controller reference forms are parsed** — short alias and fully-qualified
inline — because the earlier route audit lost 52 routes to a regex that matched
only the first (G-QUAL-02).

## The `api.php` set — 35 controllers · **the tenant boundary is the only guard here**

Ordered by hit count. **`trait`** = uses `ResolvesApiIdentity`/`ResolvesLmsIdentity`.
**`tokenable`** = the file references `->tokenable` anywhere.

| Controller | Trait | tokenable | Routes | Hits |
|---|---|---|---:|---:|
| **`skillLibraryController`** | ✗ | ✗ | **43** | **74** |
| **`HrmsController`** | ✗ | ✗ | **31** | **45** |
| `AJAXController` | ✗ | ✓ | 21 | 22 |
| `assignmentController` | ✗ | ✗ | 19 | 15 |
| `jobroletexonomycontroller` | ✗ | ✗ | 5 | 11 |
| `taskController` | ✗ | ✗ | 7 | 10 |
| `LmsCourseEnrollController` | **✓** | ✗ | 6 | 9 |
| `HrmsLeaveController` | ✗ | ✗ | 8 | 8 |
| `tbluserController` | ✗ | ✗ | 8 | 8 |
| `jobroletaskcontroller` | ✗ | ✗ | 5 | 8 |
| `talent_interviewschedulescontroller` | **✓** | ✗ | 8 | 6 |
| `DepartmentManagementController` | ✗ | ✗ | 5 | 6 |
| `talent_jobpostingcontroller` | **✓** | ✗ | 6 | 5 |
| `talent_interviewpanelController` | **✓** | ✗ | 5 | 5 |
| `talent_jobapplicationcontroller` | **✓** | ✗ | 8 | 4 |
| `skillcontroller` | ✗ | ✗ | 5 | 3 |
| …19 more with 1–2 hits | | | | |

**`HrmsController` is the finding I did not expect** — 31 routes, 45 hits, no trait,
no `->tokenable` anywhere in the file. Same shape as `skillLibraryController` and
comparable size. **Not yet read line by line.**

## The second direction — trait present, still reading from the request

| Controller | Routes | Hits | Route file |
|---|---:|---:|---|
| `PayrollController` | 39 | 30 | `hrms.php` |
| `contentLibraryController` | 8 | 16 | `lms.php` |
| `LmsCourseEnrollController` | 6 | 9 | `api.php` |
| `talent_interviewschedulescontroller` | 8 | 6 | `api.php` |
| `talent_jobpostingcontroller` | 6 | 5 | `api.php` |

These matter because **the trait's presence looks like safety.** A reviewer
skimming for `use ResolvesApiIdentity` would tick them off.

---

## ⚠️ R6 — WHAT THIS IS AND IS NOT

**These 77 are CANDIDATES. Exactly one is a verified finding.**

| Status | Controller |
|---|---|
| **VERIFIED — read in source** | `skillLibraryController` (G-SEC-09) |
| **CANDIDATE — not yet read** | the other 76 |

**Two known false-positive sources, stated before any number is used:**

1. **`user_id` as a SUBJECT is legitimate.** *"Act on user X"* is a valid parameter; *"I am user X"* is the bug. My regex cannot tell them apart. Any controller whose hits are all subject parameters is clean.
2. ~~**Route file governs severity.** `web.php`/`hrms.php`/`settings.php` are session-authenticated, so only the 35 `api.php` controllers carry the severity class.~~ **❌ REFUTED by C25 §2 — do not rely on this.** `authMiddleware` accepts **a session OR a bare Sanctum token**, so `lms/hrms/user/settings` are token-reachable too. **The real scope is 66 of 77 controllers, not 35.** Left visible rather than deleted: this was a scope-*shrinking* assumption adopted before it was tested, which is exactly what **R11** now forbids.

**I will not report "77 vulnerable controllers", and neither should any summary of
this document.** The honest headline, **updated after C25 and the C23 guard**:
*two confirmed tenant-boundary breaches (`skillLibraryController` — now **fixed**,
D-003; `PayrollController` — G-SEC-10), **46 routes across 30 controllers failing
the executed guard**, and 64 further token-reachable controllers matching the shape
and awaiting line-by-line reading.*

**Next:** `PayrollController` (9 failing routes, salary data), then the remaining
guard failures ordered by data sensitivity.


---

# C33 — do tenants share the global reference rows? · **ANSWERED: COPY AT SEED. Closed.**

**The feared cross-tenant WRITE does not exist.** Two checks, both clean.

### 1. The global libraries have no tenant column at all

| Table | Rows | `sub_institute_id` |
|---|---:|---|
| `s_jobrole` | 3,347 | **column does not exist** |
| `master_skills` | 5,640 | **column does not exist** |
| `s_jobrole_skills` | 62,208 | **column does not exist** |
| `s_user_jobrole` | 4,610 | present · **0 NULL, 0 zero**, 11 tenants |
| `s_users_skills` | 3,976 | present · **0 NULL, 0 zero**, 11 tenants |

Global by construction; tenant tables fully owned. **Every tenant row belongs to a
tenant** — there is no shared row hiding in a tenant table.

### 2. Nothing writes the global libraries

Every reference to all three is a read — `select`, `where`, `value`, `get`,
`pluck`, `join`. **No `insert`, `update`, `upsert` or `delete` anywhere in `app/`.**
The one Eloquent model pointing at `s_jobrole` (`app/Models/jobrole.php`) is
**never instantiated**: its sole mention in the codebase is a commented-out line.

### Verdict

**Copy at seed time.** A tenant editing a job role edits **its own row** in
`s_user_jobrole`. One customer's rename cannot reach another customer's data, and
the G-DATA-06 interaction Triz raised — an in-place edit silently detaching other
tenants' 283,126 string-joined relationships — **cannot occur.**

### ⚠️ Correcting my own earlier statement

I previously wrote that *"`s_jobrole` id 918 has a **NULL tenant** — a shared
global reference row."* **The reasoning was wrong.** `s_jobrole` has **no
`sub_institute_id` column**; my query returned null because the column is absent,
not because the value is null. The conclusion (the table is global) happened to be
right, but it was inferred from a null that meant "no such column". Corrected here
because the same mistake on a table that *does* have the column would have read a
tenant-owned row as global.
