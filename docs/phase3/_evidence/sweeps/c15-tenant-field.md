# C15 — the 64 `sub_institute_id`-from-request hits

**Question:** are they inside or outside the Phase 1 F-01 fix?

**Answer: outside — and one of them is a live, verified cross-tenant hole on a
surface I edited this week. C15 is a security finding, not a design gap.**

---

## The method, and the two times it was wrong first

**R6:** the sweep produced 64 candidates. Every one was classified by hand.

| # | My check said | Actually |
|---:|---|---|
| 1 | *"0 hits are in trait-using controllers, so all 64 are legacy"* | **Wrong inference.** The regex looked for a `use SomeTrait` statement. `skillLibraryController` does not use a trait — it defines its **own** context method, and is very much API-reachable. "No trait" ≠ "not an API" |
| 2 | *"`jobroleLibrary1Controller` api=0 web=0, so unrouted"* | **Incomplete.** I grepped `routes/api.php` and `routes/web.php`. **There are seven route files** — `hrms.php`, `lms.php`, `settings.php`, `user.php` too. Three controllers I had called unrouted are routed in `lms.php` and `settings.php` |

R4's ninth and tenth cases. Both would have understated the finding.

---

## The finding

### 1. The F-01 trait itself is clean · **not a defect**

One hit is inside `ResolvesApiIdentity.php`. All three occurrences are the fix
doing its job:

| Line | What it is |
|---:|---|
| 15 | a **docblock example of the bad pattern**, quoted to describe the bug |
| 60 | a comment — *"sub_institute_id in the request is ignored rather than refused"* |
| 69 | reads the requested value **only to ignore it** |

### 2. `skillLibraryController` — **S1, live, and verbatim the F-01 bug**

**12 API routes. 24 of the 64 hits.** This is the Competency Library backend — the
same file whose create/update/restore I changed for D-002.

```php
private function competencyLibraryContext(Request $request)
{
    $token = $request->input('token');
    if (!$token) { ... 401 ... }
    if (!PersonalAccessToken::findToken($token)) { ... 401 ... }   // <- owner DISCARDED

    $subInstituteId = $request->input('sub_institute_id') ?? $request->header('sub_institute_id');
    if (!$subInstituteId || !is_numeric($subInstituteId)) { ... 400 ... }

    return [
        'sub_institute_id' => (int) $subInstituteId,                       // <- from the CALLER
        'user_id'          => is_numeric($request->input('user_id')) ? ... // <- from the CALLER
    ];
}
```

It **validates that a token exists and then throws away its owner** — `->tokenable`
is never touched — and takes both the tenant and the acting user from the request
body, guarded only by `is_numeric`.

`ResolvesApiIdentity`'s own docblock describes this exact shape as the defect it
was written to remove. **The fix was built and this controller was never migrated
onto it.**

**Consequence:** a valid token from **any** tenant can read and write **any other
tenant's** competency library by changing one number. Creation, edit, archive,
restore, export, bulk import — the whole CRUD surface.

**Not theoretical.** Verified by reading source, and the routes are declared.

### 3. Three more API-reachable controllers with the same shape

| Controller | API routes | Hits |
|---|---:|---:|
| `assignmentController` | **11** | 1 |
| `jobroletaskcontroller` | 2 | 4 |
| `jobroletexonomycontroller` | 2 | 4 |

Same pattern class; **not yet read line by line.** Candidates, not findings (R6).

### 4. Routed, but not on the API surface

`lmsActivityStreamController` (`lms.php`), `instituteDetailController` and
`organizationDetailsController` (`settings.php`) — session-authenticated Blade
screens. Lower severity: a browser session already fixes the tenant. **Still worth
fixing**, not urgent.

### 5. Dead code

`jobroleLibrary1Controller` — **14 hits, appears in none of the seven route
files.** Unreachable. Cheapest possible remediation: delete it, **under R8**.

---

## Classification of all 64

| Class | Hits | Files | Severity |
|---|---:|---:|---|
| **API-reachable, tenant from request** | **33** | 4 | **S1** |
| Routed but session-auth (Blade) | 5 | 4 | S3 |
| Dead code | 14 | 1 | S4 — delete |
| Legacy `jobroleLibraryController` (2 web routes) | 8 | 1 | S3 |
| **The F-01 trait itself — the fix, not the bug** | **3** | 1 | **none** |
| Remaining, unclassified | 1 | 3 | pending |

---

## Why this matters more than the count suggests

Phase 1 fixed F-01 across **70 controllers** and built `ResolvesApiIdentity` to make
the correct pattern easy. **The fix works.** What C15 shows is that a controller
which never adopted it kept the original vulnerability — and it happens to be one of
the most commercially important surfaces in the product.

**It also means my own D-002 change sits on top of an unfixed hole.** Making the
server own `approve_status` is correct and still leaves the tenant boundary open on
the same endpoints. The two fixes are independent and both are needed; D-002 is not
weakened, but it must not be mistaken for having secured that controller.

**Raised as `G-SEC-09` (S1).** Remediation is to migrate `competencyLibraryContext`
onto `ResolvesApiIdentity` — the trait already exists and already does exactly this
correctly, so the work is adoption, not design.

**R9 applies to the fix:** once the tenant stops coming from the request, every
frontend caller that *sends* it must be re-read. The Competency Library frontend
sends `sub_institute_id` today via `getLaravelContext(user)`.
