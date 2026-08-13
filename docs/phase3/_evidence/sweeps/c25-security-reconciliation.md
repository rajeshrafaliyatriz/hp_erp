# C25 — security number reconciliation · one table, once

**No security figure from Phase 3 is quoted anywhere — including to Triz — until
this document exists. It now does.**

Old numbers are marked **SUPERSEDED**, never deleted: the correction history is
itself evidence of how the earlier figures were produced.

---

## 0. R10 — every checker tests a proxy. Here are the proxies.

> **R10:** any script producing a number must state (a) the property we care about,
> (b) the proxy actually measured, (c) the gap — how something passes the proxy and
> fails the property, **and vice versa**.

### The two route totals in this document are NOT the same measurement

| Figure | Property | Proxy | Gap |
|---:|---|---|---|
| **1,676** | "how many routes exist" | every `Route::verb(` declaration; `resource` counted as 7 | includes closure routes with no controller; `resource` rarely uses all 7 |
| **1,463** | "how many routes reach a controller method I can audit" | declarations resolvable to `[Class::class,'method']`; `resource` counted as 5 | excludes closures, so it **undercounts reachable surface** |

Neither is wrong. **Quoting one where the other belongs is.**

---

## 1. Routes per file — all seven

| File | Routes | Writes | Registered with | **Token-reachable?** |
|---|---:|---:|---|---|
| `api.php` | **811** | 434 | `api` group | **YES** |
| `lms.php` | **452** | 225 | `['web','auth','session','menu']` | **YES — see §2** |
| `web.php` | 194 | 96 | `web` group; **one** `['auth','session','menu']` group at line 104 | **PARTIAL** |
| `hrms.php` | 116 | 53 | `['web','auth','session','menu']` | **YES — see §2** |
| `user.php` | 82 | 44 | `['web','auth','session','menu']` | **YES — see §2** |
| `settings.php` | 21 | 12 | `['web','auth','session','menu']` | **YES — see §2** |
| `console.php` | 0 | 0 | CLI | n/a |
| **TOTAL** | **1,676** | **864** | | |

---

## 2. ⚠️ THE BLADE ASSUMPTION IS FALSE — verified, not assumed

**This was load-bearing for scoping G-SEC-09 to 35 controllers. It does not hold.**

The claim was: *"`lms/user/settings/hrms` are session-authenticated Blade screens,
so a browser session already fixes the tenant."* The first half is true. **The
conclusion is not.**

`app/Http/Middleware/authMiddleware.php` — the `auth` alias those four files use —
admits **either** credential:

```php
if ($this->hasSession() || $this->hasValidToken($request)) {
    return $next($request);
}
```

> Its own docblock says so: *"a valid Sanctum personal access token, which is how
> the Next.js frontends authenticate — they send it as the `token` query/body
> parameter, or as a normal Bearer header."*

**So four of the six route files are reachable with a bare token and no session
at all.** If the controller behind such a route then reads `sub_institute_id` from
the request body, the tenant boundary is breached exactly as in G-SEC-09.

### Corrected scope

| | Controllers | Hits |
|---|---:|---:|
| Previously claimed in scope (`api.php` only) | 35 | — |
| **Actually token-reachable** (`api.php` + `lms` + `hrms` + `user` + `settings`) | **66 of 77** | **460** |
| `web.php`-only, not token-reachable | 11 | — |

**The scope nearly doubled.** Named so it is not lost: `PayrollController`
(`hrms.php`, 39 routes, 30 hits, **has the trait**) is now in scope — **salary
data**. So are `lms_apiController` (20 hits), `contentLibraryController` (16),
`lmsActivityStreamController` (12), `contentController` (11),
`questionpaperController` (11).

**This is R4 case 11, and it understated the risk again** — the fourth consecutive
error in the reassuring direction.

---

## 3. Corrected security figures

### ⛔ SUPERSEDED

| Figure | Was | Why superseded |
|---|---|---|
| Total API routes | **739** | Counted `api.php` only. Actual reachable surface is **1,676 across six files**; `api.php` is **48%** of it |
| Total API routes | **687** | Regex dropped 52 fully-qualified refs (G-QUAL-02) |
| Unguarded writes | **279** | Counted `api.php` only, **and** used a proxy that fails in both directions — see below |

### G-SEC-01 — routes with no authorization check

**Status: CANNOT BE RESTATED YET. Deliberately left uncorrected.**

**R10:** property = *"only permitted users can perform this action on this data"*;
proxy = *"the route carries a role middleware"*.

**The gap runs both ways:**
- **Passes proxy, fails property:** a route carrying `profile:admin` and applying **no tenant or data scope** — permitted user, wrong rows. This is the G-SEC-09 shape.
- **Fails proxy, passes property:** a controller enforcing authorization in its own body without middleware.

A corrected count needs the C23 guard, not another regex. **Any number I produced
today would carry the same defect as the 279.**

### G-SEC-02 — routes whose controller class does not exist

**Unaffected.** `audit-route-controllers.py` globbed **all seven** route files
(verified at line 55). Its proxy — *"does this class file exist?"* — has essentially
no gap. **This is the one Phase 1 security number that survives C25 intact.**

### G-SEC-04 / the 279 unguarded writes

**Status: SUPERSEDED, replaced by a floor.**

**R10:** property = *"this write is authorized"*; proxy = *"a role middleware is
attached"*. Same two-way gap as G-SEC-01.

What can be said honestly: **864 write routes exist across six files.** 434 are in
`api.php`; **the other 430 were never audited for authorization at all.** That is
not a corrected count — it is the size of the unmeasured area.

### G-SEC-09 — the tenant-boundary breach

**R10:** property = *"the acting tenant and user come from the token"*; proxy =
*"the controller reads `sub_institute_id`/`user_id` from `$request`"*.
**Gap:** `user_id` as a *subject* ("act on user X") is legitimate and the proxy
cannot distinguish it from identity. **That is why C23 inverts the order** — the
guard tests the property directly.

| | |
|---|---:|
| **Verified** (read in source) | **1** — `skillLibraryController` |
| **Candidates**, token-reachable, unread | **65** |
| Candidates, `web.php`-only | 11 |

> **DO NOT REPORT "66 VULNERABLE CONTROLLERS."** One is verified; 65 are
> candidates with a known false-positive class. This instruction is written into
> the file so no downstream summary can restate it as a finding.

---

## 4. What unblocks quoting

| Figure | Quotable now? |
|---|---|
| Routes per file, §1 | ✅ **Yes**, with the R10 note on which total is which |
| Token-reachable scope: 66 of 77 | ✅ Yes, **as candidates** |
| G-SEC-02 | ✅ Yes, unchanged |
| G-SEC-01, G-SEC-04, the 279 | ❌ **No.** Awaiting the C23 guard's failure list |
| G-SEC-09 severity | ✅ Yes — **one verified breach**, scope pending the guard |

**The C23 guard is now the gating artefact for four figures, not one.** Writing it
before any fix — as instructed — is what converts every remaining number from an
estimate into a failure list with a completion criterion.
