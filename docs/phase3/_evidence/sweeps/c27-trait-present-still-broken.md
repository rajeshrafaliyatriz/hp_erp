# C27 — "trait present, still reads from the request" · its own severity class

**Higher severity than the no-trait cases, for one reason:** a controller with no
trait is **visibly unfinished**. One with the trait **looks complete to every future
reader and to every future checker**. That is the same illusion that produced C22 —
and it is now confirmed to be operating in production code, not just in an audit
script.

---

## PayrollController — verified by close reading · **S1**

`app/Http/Controllers/Payroll/PayrollController.php` · **39 routes** (`hrms.php`) ·
**salary data**

| Evidence | Line(s) |
|---|---|
| `use ResolvesApiIdentity;` | **43** |
| Tenant read from the request | **75, 137, 208, 231, 264, 332, 563, 598, 682, 699, 784** … ~18 sites |
| Acting user read from the request | 138, 209 |
| `apiTenantId()` / `apiUserId()` called | **NEVER — zero times** |
| The only trait method used | `apiTokenIsValid()`, line **553** |

### The trait is imported and effectively unused

The single call is **`apiTokenIsValid()`** — which asks *"is this token valid?"*,
**not** *"who does it belong to?"*.

> **This is C22's defect reproduced inside application code.** Phase 1's audit
> script used "calls `findToken()`" as a proxy for correct identity resolution.
> PayrollController uses `apiTokenIsValid()` in exactly the same way: it confirms
> authentication and then resolves the tenant from the caller's own request body.
> The proxy and the property diverge in the code itself, not merely in the checker.

### The most explicit instance found anywhere so far

Lines **596–603**:

```php
$type = $request->input('type');
if ($type == 'API') {
    $sub_institute_id = $request->input('sub_institute_id');   // <- API callers supply their own tenant
    $syear            = $request->input('syear');
} else {
    $sub_institute_id = $request->session()->get('sub_institute_id');   // <- browsers do not
}
```

The code **knows it is serving an API caller and chooses to trust that caller's
tenant claim.** A browser gets the session value; an API client gets to name its
own tenant. There are **two** such branches in the file.

**Consequence:** any holder of a valid token can read **another tenant's payroll**
by setting `type=API` and `sub_institute_id=<theirs>`. `hrms.php` is
token-reachable (C25 §2), so no session is required.

### Mixed resolution is its own hazard

The file resolves tenant **three different ways** — `$request->get()`,
`$request->input()`, and `$request->session()->get()` — sometimes within a few lines.
Fixing it is therefore **not** a find-and-replace: each site needs reading to decide
whether it is an API path, a Blade path, or both.

---

## The five in this class

| Controller | Routes | Route file | Hits | Data sensitivity | Status |
|---|---:|---|---:|---|---|
| **`PayrollController`** | **39** | `hrms.php` | 30 | **Salary — the most sensitive data in the product** | **VERIFIED S1** |
| `contentLibraryController` | 8 | `lms.php` | 16 | Course content, tenant IP | candidate |
| `LmsCourseEnrollController` | 6 | `api.php` | 9 | Who is enrolled in what — per-employee | candidate |
| `talent_interviewschedulescontroller` | 8 | `api.php` | 6 | Candidate interview schedules | candidate |
| `talent_jobpostingcontroller` | 6 | `api.php` | 5 | Job postings, hiring plans | candidate |

**All five are token-reachable.** One verified, four candidates (R6).

> **DO NOT REPORT "FIVE COMPROMISED CONTROLLERS."** One is read and confirmed;
> four match the shape and have not been read. Written into this file so no
> downstream summary can restate it as a finding.

---

## Why this class ranks above the no-trait cases

| | No trait | **Trait present, unused** |
|---|---|---|
| Visible to a code reviewer? | **Yes** — obviously unmigrated | **No** — reads as migrated |
| Visible to a `use ResolvesApiIdentity` grep? | flagged | **passes** |
| Visible to Phase 1's auth sweep? | flagged if no `findToken` | **passes twice over** |
| Likely to be re-audited? | yes | **no — it is on the "done" list** |

A future checker written to find G-SEC-09 by searching for the missing trait would
return **clean** for PayrollController. That is why C23 must test the property, not
the trait.

**PayrollController is the single best argument for C23 as a property test**, and
it should be the first route set the guard is pointed at.
