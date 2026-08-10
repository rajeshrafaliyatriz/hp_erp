# 09 — Implementation log

**Gate D.** One entry per change. Analysis lives in `06-feature-audit/`; this file
records only what was actually done to the code.

---

## D-001 · L-03R — delete the unreachable library detail panel

**Date:** 2026-08-06 · **Status:** `Connection Implemented` — built and typechecked,
**not yet `Verified`** (AT-L03R steps 1 and 4 need a running app).
**Approval:** explicit, 2026-08-06. **Repo:** `g2gv0` (frontend only).

### Premise re-verified before deleting (AT-L03R step 2)

| Check | Result |
|---|---|
| Every `setSelected` call site | **4 of 4 passed `null`** (lines 399, 412, 421 and the `Sheet`'s own `onOpenChange`). Nothing ever passed a row |
| Who imports `library-detail.tsx` | **exactly one file** — `library-tab.tsx:66` |
| What `detail` / `detailLoading` fed | **only** the dead panel (used at 1063–1064 and nowhere else) |
| Which view is live | `LibraryDetailModal`, driven by separate `richDetail` state |

**A comment at the old line 207 asserted an intent that was never wired:**
*"Competencies get the full popup … the other libraries … keep the side panel."*
The side panel was never reachable on **any** tab, so that design existed only in
the comment. Replaced with a comment stating what is actually true.

### Two dependencies found before deleting, not after

1. **`dash()`** — exported from `library-detail.tsx:21` and used at **4 live sites** in `library-tab.tsx` (857, 939, 944, 1134). Moved into `library-tab.tsx`, its only consumer. **Deleting the file without this would have broken the table.**
2. **`useLibraryDetail`** — the hook call existed solely to feed the panel. Its call site and import were removed. **The hook definition in `hooks/use-competency-libraries.ts:282` was deliberately left in place** — see below.

### Changes

| File | Change |
|---|---|
| `components/domain/competency/libraries/library-detail.tsx` | **DELETED** (366 lines) |
| `components/domain/competency/libraries/library-tab.tsx` | Removed the `Sheet` block, the `selected` state, the `useLibraryDetail` call and import, and 3 dead `setSelected(null)` calls. Absorbed `dash()`. Corrected two stale comments |

```
2 files changed, 11 insertions(+), 404 deletions(-)
```

### Verification

| Check | Result |
|---|---|
| `tsc --noEmit` | **0 errors in `library-tab` or `library-detail`** |
| Pre-existing errors elsewhere | 3 in `services/talent/offboarding-service.ts` — **pre-existing, untouched by this change**, confirmed by `git status` showing only 2 modified paths |
| Residual references | `grep` for `setSelected`, `LibraryDetail`, `useLibraryDetail`, `detailLoading` in `library-tab.tsx` → **none** |
| Working tree | exactly 2 paths. `next-env.d.ts`, touched by the `tsc` run, was reverted |

**Outstanding — AT-L03R steps 1 and 4:** click a row on all 8 tabs before and after,
and confirm identical behaviour. Requires a running app; **this change is not
`Verified` until that is done.**

### Step 5 — backend untouched, as required

`useLibraryDetail` and its endpoint are **still live in the repo**. The hook is now
unused by any component, but a deletion commit must not quietly remove a working
read model. **Reported, not deleted.** If the endpoint is genuinely orphaned it is
its own decision, with its own approval.

### Blast radius and rollback

**Blast radius:** one tab component and one deleted file. No shared component, no
backend, no schema, no data. The other seven tabs render through the same component
and are affected only by the removal of a branch that never executed.

**Rollback:** `git revert` this commit. It restores a file nothing could reach.

---

## D-002 · G-COMP-01 + G-SEC-08 — the server owns approval and verification state

**Date:** 2026-08-06 · **Status:** `Connection Implemented` — built, PHP and TS
clean, **not `Verified`** (needs a running app).
**Repos:** `hp_erp` (3 files) + `g2gv0` (1 file).

### What changed

| # | Site | Was | Now |
|---:|---|---|---|
| 1 | `skillLibraryController.php` create | `approve_status => $request->input('status', 'Approved')` | **`'Pending'`** |
| 2 | `skillLibraryController.php` update | `if ($request->filled('status')) $update['approve_status'] = …` | **block removed** |
| 3 | `skillLibraryController.php` restore | `$restore ? 'Approved' : 'Cancelled'` | **`$restore ? 'Pending' : 'Cancelled'`** |
| 4 | `CompetencyController.php` create | `status === 'published' ? 'Approved' : 'Pending'` | **`'Pending'`** |
| 5 | `CertificationController.php` create | `verification_status => $request->input(…, 'pending')` | **`'pending'`** |
| 6 | `CertificationController.php` create validator | validated `verification_status` | **rule removed** — validating a field the server ignores advertises a capability that does not exist |
| 7 | `cm-competency-library.tsx` edit form | Status dropdown writing `approve_status` | **removed** |

### Why the frontend was touched in a "backend-only" item

Item 7 was **not optional**. Once the server ignores `status` on update, the edit
form's Status dropdown becomes a control that looks like it works and silently does
nothing — **the exact defect catalogued as §2.1 of `competency-library.md`**
(Command Center's silently-dropped fields). Fixing a bypass by creating a silent
no-op would have traded one defect for another.

`STATUS_FORM_OPTIONS`, left unused by that removal, was deleted too.

### ⚠️ BEHAVIOUR CHANGE — visible, and deliberate

**New competencies are now born `Pending` instead of `Approved`.** Downstream
consumers filter on `approve_status='Approved'` (`RoleMappingController.php:117`,
`StudioController.php:293`), so a newly created competency **will not appear in the
matrix, framework or summary until it is approved.**

This is the correct behaviour and the entire point of the fix — but it is
**not invisible**, contrary to the general assumption that Gate D foundation work
is unseen. Two consequences:

1. **Existing rows are untouched.** Nothing was migrated; only new writes change.
2. **The approval queue must be reachable before this is comfortable to use.** `content-map-m2.ts:17-20` states the Audit & Activity Center has no `tblmenumaster_g2g` row — so today the only approve/reject UI may be unreachable from the sidebar. **This raises the priority of C-11**, which was estimated XS.

Restore returning to `Pending` rather than `Approved` is the conservative choice:
housekeeping must never grant approval. The deeper flaw — `approve_status` carrying
**both** lifecycle (archived) and review state — is a schema change and stays with
the Gate D migration.

### What was deliberately NOT done

- **`CertificationController` update still accepts `verification_status`** and stamps `verified_by`/`verified_at`. That is the *legitimate* verification action. It is **not role-gated** — but that is **G-SEC-01**, Gate D item 7, not this fix. Logged, not silently widened.
- **No data migration.** Rows already `Approved` stay approved. Re-reviewing existing test data is a decision, not a side effect.

### Verification

| Check | Result |
|---|---|
| `php -l` × 3 | **No syntax errors** |
| `tsc --noEmit` | **0 errors** in `cm-competency-library` or `library-tab` |
| Scope, `hp_erp` | `git diff --stat` on the 3 target files: **+32 / −9**. *(The wider `hp_erp` working tree carries many unrelated modifications from the Phase 1/2 security work already on this branch — an earlier draft of this line wrongly implied only 3 paths were dirty. Corrected.)* |
| Scope, `g2gv0` | 3 paths, all intended. `next-env.d.ts` reverted |

**Outstanding:** create a competency and confirm it is `Pending`; confirm the edit
form no longer offers Status; confirm archive→restore yields `Pending`; confirm an
uploaded certification is born `pending`.

### Completing requirement 2 — the rejected-competency dead end

**The first pass of D-002 closed the bypasses without fixing the dead end.** That
was wrong: rejection left the subject `Pending`, the Library hides *Submit for
Approval* for pending items, and the only escape was the bypass being closed. It
would have converted a security defect into a stuck workflow. Fixed in the same
change, before commit.

| Site | Change |
|---|---|
| `ApprovalController::SUBJECTS` | added a **`rejected`** value per subject — `'Rejected'` for competencies, `'draft'` for frameworks (a framework's pre-submission state is already `draft`, so it is resubmittable there) |
| `ApprovalController` decision branch | the subject now moves on **either** decision. Was `if ($approve) { … }` — the subject was touched only when approving |
| `cm-competency-library.tsx` | `'Rejected'` added to `STATUS_VALUES` so the state is filterable and labelled |

**No frontend gate change was needed.** The Submit-for-Approval condition is
already `statusLabel(item) !== 'Pending' && !isArchived(item)`, so a subject in
`Rejected` offers resubmission automatically. `statusDisplay()` falls through to
the raw value for unknown states, so it renders as *"Rejected"* safely.

### Two further defects the backend change exposed

Found by re-reading the frontend after changing the server — neither was in the
original plan:

1. **The payload still sent `status`** (`cm-competency-library.tsx:437`). The server now ignores it, so this was the **silent-data-loss pattern this audit exists to remove**. Removed, per requirement 1: the server *ignores* rather than rejects, so nothing 422s, and the field is gone from the payload.
2. **The optimistic restore update lied.** Line 824 set `approve_status: restoring ? 'Approved' : 'Cancelled'`, but the server now restores to `Pending`. The panel would have shown **Approved while the database held Pending**. Corrected to mirror the server.

The second is the more instructive: a server-side state change silently invalidated
an optimistic UI update elsewhere in the file. Nothing would have caught it except
re-reading the consumer.

### AT-D002 — acceptance tests

**AT-D002a — approval state cannot be set by the caller**

| Step | Action | Expected |
|---:|---|---|
| 1 | `POST /skill_library/competency` with `status: "Approved"` | **201/200, not 422.** Requirement 1: ignore, do not reject |
| 2 | Read the row | `approve_status = 'Pending'` |
| 3 | Create from Command Center with `status: "published"` | `approve_status = 'Pending'` |
| 4 | `PUT` the competency with `status: "Approved"` | Succeeds; `approve_status` **unchanged** |
| 5 | Archive it, then restore it | `Cancelled` → **`Pending`**, never `Approved` |
| 6 | `POST` a certification with `verification_status: "verified"` | Stored as **`pending`** |
| 7 | Open the Library edit form | **No Status dropdown** |

**AT-D002b — the legitimate approve/reject path still works (before AND after)**

| Step | Action | Expected |
|---:|---|---|
| 1 | Submit a competency for approval | approval row created; subject `Pending` |
| 2 | `PUT /api/competency/approvals/{id}` `decision=approve` | approval `approved`; **subject `Approved`** |
| 3 | Submit another; `decision=reject` | approval `rejected`; **subject `Rejected`** — *this is the fix; it was `Pending` before* |
| 4 | Open the rejected competency | **"Submit for Approval" is OFFERED** — the dead end is gone |
| 5 | Resubmit it | subject returns to `Pending`; a new approval row exists |
| 6 | Bulk-approve | still works; subjects `Approved` |
| 7 | Reject a **framework** | subject returns to `draft`, editable and resubmittable |

**Run step 2 and step 6 BEFORE the change as well**, to prove the legitimate path
was not broken rather than merely assuming it.

### Rollback

`git revert`. Reverting restores the four bypasses, so it should be paired with a
decision about the approval queue's reachability rather than done reflexively.

---

## D-003 · G-SEC-09 — `skillLibraryController` resolves identity from the token

**Date:** 2026-08-06 · **Status:** **`API-verified-UI-pending`** — the C23 guard
executed the defect before and after. **Does not count against the cap (C20 §3a).**

### The change — one method body, eleven call sites untouched

`competencyLibraryContext()` used to validate that a token *existed* and then
discard its owner, taking `sub_institute_id` and `user_id` from the request with
only an `is_numeric()` check. Its body is now:

```php
return $this->resolveApiIdentity($request);
```

`ResolvesApiIdentity` returns `['user', 'user_id', 'sub_institute_id']` — a
**superset** of the shape this method produced, so all **eleven** call sites, which
test `is_array($context)` and read those two keys, are unaffected. Plus the class
`use` statement and the import. **Three lines of behaviour.**

### C29 — the fix-and-reverify loop, closed

| C23 guard on `skillLibraryController` | FAIL | PASS | VACUOUS | UNTESTABLE |
|---|---:|---:|---:|---:|
| Before | **2** | 12 | 1 | 23 |
| After | **0** | **14** | 1 | 23 |

**The instrument is now validated.** It had produced FAILs, and PASSes that turned
out vacuous, but had never watched a route go **FAIL → PASS because the defect was
actually repaired**. It has now. "Green" means something.

### R9 — consumer and regression check

The frontend sends `sub_institute_id` via `getLaravelContext(user)`. The server now
ignores it and derives the tenant from the token — **for a legitimate user those are
the same value**, so no screen changes what it returns.

Measured against the live token table rather than assumed:

| Risk | Count | Verdict |
|---|---:|---|
| Tokens whose owner has NULL/0 `sub_institute_id` (the trait's request-fallback branch) | **0** | The fallback never fires. The fix is total for this controller |
| Expired tokens (the trait rejects, the old code accepted) | **0** | No regression |
| Tokens whose `tokenable_id` matches no `tbluser` row | **3** of 4,520 | They will now 401. **Correct** — the owner no longer exists |

### Still open on this controller

The guard's 23 UNTESTABLE routes here are **not proven safe**; they need path
parameters. And **the write half is untested** — but per the standing instruction a
read leak is the breach, and the read leak is closed.

---

## D-004 · G-SEC-10 — PayrollController resolves tenant from token or session, never the request

**Date:** 2026-08-06 · **Status:** **`API-verified-UI-pending`** — the C23 guard
executed the defect before and after. **39 routes, salary data.**

### C23 guard: **9 FAIL → 0**

| | FAIL | PASS | VACUOUS | UNTESTABLE |
|---|---:|---:|---:|---:|
| Before | **9** | 5 | 1 | 10 |
| After | **0** | **13** | 1 | 11 |

### The `$type == 'API'` branch — callers checked first, as required

It was a **deliberate** choice, so it earned the check. The callers are the Next.js
frontend via `withLaravelParams` (`lib/laravel-context.ts:60-72`), which sends
`type: 'API'` **together with the user's own `sub_institute_id`**.

**So legitimate callers already pass their own tenant, which equals the token's.**
Removing the trust changes nothing for them. The branch itself survives — it still
selects the source for `syear` — but both of its arms now derive the tenant from
server state.

### ⚠️ The estimate was wrong: FOUR resolution styles, not three

The guard caught it. After substituting the 17 sites I knew about, **3 routes still
leaked** — because a **fourth style was invisible to my analysis**:

```php
$sub_institute_id = $request->sub_institute_id;   // magic property access
```

Neither `->get('sub_institute_id')` nor `->input('sub_institute_id')` matches it,
so it never appeared in any count. Worse, at `monthlyPayrollReport` and
`monthlyPayrollCreate` it sits **two lines after** a correctly-resolved value and
**overwrites it**.

| Style | Sites |
|---|---:|
| `$request->get('sub_institute_id')` | 12 |
| `$request->input('sub_institute_id')` | 5 |
| **`$request->sub_institute_id`** *(missed)* | **9** |
| `$request->session()->get(...)` — already server-established, left alone | 7 |
| **Total substituted** | **26**, not 17 |

**R4 case 13, and it under-reported.** The lesson is R7's: an estimate that names
sites must enumerate **every syntax** that reaches the value, not the two that came
to mind. A magic-property read is the same defect wearing different punctuation.

### The fix shape — a helper, because this controller serves both surfaces

`skillLibraryController` took a one-line body swap. **This one could not.** 40 of
its routes are Blade screens in `routes/hrms.php`, authenticated by session with no
token at all, so `apiTenantId()` alone would return null and silently show them
nothing.

```php
private function payrollTenantId(Request $request): ?int
{
    $fromToken = $this->apiTenantId($request);
    if ($fromToken) return $fromToken;
    $fromSession = $request->session()->get('sub_institute_id');
    return is_numeric($fromSession) ? (int) $fromSession : null;
}
```

**Token, then session, never the request body.** Both sources are things the server
established; the request body is a claim by the caller.

### R9 — measured, not asserted

| Failure mode | Measured | Verdict |
|---|---|---|
| Blade caller with a session and no token | `apiTenantId()` returns **`null`**, not a `JsonResponse` (`ResolvesApiIdentity.php:98-103`) — so the session fallback fires | **No regression** |
| Token caller whose owner has NULL/0 tenant | **0 of 4,520 tokens** | Fallback never needed |
| Expired tokens | **0** | No regression |
| Caller with neither token nor session | returns null → `where sub_institute_id = null` matches nothing | **Fails closed**, which is correct |

### Outstanding

11 UNTESTABLE routes on this controller are **not proven safe** (path parameters),
and the **write half is untested**. The read leak — the breach — is closed.

---

## D-005 · S-01 — `talent_interviewpanelController` tenant resolution

**2026-08-07** · `API-verified-UI-pending` · commit **`15791bca`**

| | |
|---|---|
| **Changed** | Five `if ($type == "API")` branches validated that a token existed, then took `sub_institute_id` from the request body — the G-SEC-09 defect. Added `panelTenantId()` (token first, session fallback, mirroring `payrollTenantId`) and substituted all five |
| **Files** | `app/Http/Controllers/talent/interview_panel/talent_interviewpanelController.php` |
| **Guard** | **1 FAIL → 0.** `LEAK-NOSCOPE 0 · FAIL 0 · PASS 2 · UNTESTABLE 4` |
| **Acceptance** | API-level green. **Not `Verified`** — 4 UNTESTABLE routes need path parameters; the write half is untested |

**First by data class**, ahead of controllers with four times the route count:
interview panel records cover **candidates** — people outside the company who never
agreed to be in the system.

---

## D-006 · S-02 — G-SEC-12, the acting user resolved from identity

**2026-08-07** · `API-verified-UI-pending` · commit **`d70a204c`**

| | |
|---|---|
| **Changed** | **76 provenance sites across 16 files** took `created_by` / `updated_by` / `verified_by` / `reviewer_id` from request input. Added `g2gActorId()` per file (token first, session fallback, mirroring `payrollActorId`) and substituted every site |
| **Files** | 16 — `skillLibraryController` (29 sites), `jobroleLibrary1Controller` (16), `jobroleLibraryController` (11), `jobroletexonomycontroller` (4), `HolidayController` (2), `jobroletaskcontroller` (2), `LmsCourseEnrollController` (2), `talent_jobpostingcontroller` (2), plus 8 with one each |
| **Guard** | Re-scan: **0 provenance-from-request sites remain.** C23 on previously fixed controllers **unchanged at 0 FAIL** — no regression |
| **Acceptance** | `php -l` clean on all 16. **Not `Verified`** — no UI path exercised |

### Classification — the rule held completely

**76 IDENTITY, 0 AMBIGUOUS.** The proven rule — *provenance columns fed from input
are always IDENTITY; a field naming who the operation is ABOUT is SUBJECT* —
cleared **every one mechanically. None needed a hand read.**

⚠️ **Scope was larger than estimated: 76 sites, not 33.** S-3's figure counted only
`created_by`; this covers all provenance columns. **The estimate was low by 2.3×**,
and it was marked ESTIMATE PENDING for exactly this reason.

**75 substituted by script; 1 by hand** — a CRLF line ending defeated the anchor
regex in `SuggestedCourseController`. Same class of defect as earlier in the phase.

### ⛔ This unblocks the event store

`05-data-flow-contracts.md` §1.9 recorded that everything downstream assumes
`actor_id` is trustworthy. **It now is.** X-04 is no longer blocked by S-02.

---

## D-007 · F-01..F-04, F-07, F-09 — the foundation migration, as ONE change

**2026-08-07** · **APPLIED** · commit **`7df8c1c7`**

| | |
|---|---|
| **Changed** | **12 tables created, 3 nullable columns added.** Strictly additive — no drops, no inferred backfill, no existing column altered |
| **Files** | `database/migrations/2026_08_07_100000_phase3_foundation_join_tables.php` |
| **Verification** | **12 of 12 tables created · 3 of 3 columns added · all twelve carry `sub_institute_id` · all new tables empty** |
| **Acceptance** | AT-F01..F04, F07, F09 structural half **PASSED** (schema verified by query). **Not `Verified`** — no application code reads these yet |

### The six confirmations, answered before running

| # | Question | Answer |
|---:|---|---|
| 1 | Tenancy on every tenant-owned table | **Two were genuinely missing.** `competency_kasba_item` and `library_map_skill` had no `sub_institute_id`; both now do. **The diagram was brevity for ten of twelve and wrong for two** — the check was worth making |
| 2 | Can a tenant own a `certification_type`? | **Yes.** `sub_institute_id` NULLABLE — NULL = global seed, non-null = tenant-authored, which is where §10.0's gated inline create writes |
| 3 | Why did the controller break if the table exists? | **It did not.** `CertificationRequirementController` references `s_competency_certification_requirements` — **with** the `s_` prefix — and that table exists with 15 rows. **The audit recorded it without the prefix.** A naming error in the record, not a missing table |
| 4 | Does `skill_matrix_item` keep `item_label`? | **Yes**, and `item_id` is **nullable** — so an unmatched row keeps what was meant and is reported, never inferred |
| 5 | UNIQUE on every map table's natural key | **All five verified present** before running |
| 6 | Does `competency_evidence` carry direction and dismissal? | **Yes** — `direction` (positive/negative/neutral, default neutral), plus `dismissed_reason`, `dismissed_by`, `dismissed_at` |

### One failure during application, and its fix

The first run failed on MySQL's **64-character identifier limit**: the
auto-generated index name on `s_competency_certification_requirements` came to 66.
Replaced with an explicit `idx_ccr_cert_type`. **The migration is idempotent
(`if (!Schema::hasTable)`), so re-running after the fix was safe** and no partial
state persisted.

### Rollback

`down()` drops exactly what `up()` created, in reverse dependency order, plus the
three columns. **Every new table starts empty and every new column starts NULL, so
reverting cannot lose data.**

### What this does NOT do

No backfill. `s_user_jobrole_task.jobrole_id` and the two `certification_type_id`
columns are **nullable, indexed and unread**. The backfill, its unmatched report,
and the eventual text-key drops are a separate reviewed change (R8).

---

## D-008 · 4a — tri-state rights columns (G-SEC-06)

**2026-08-07** · **APPLIED** · commit **`5e302651`**

| | |
|---|---|
| **Changed** | `right_view/add/edit/delete/dashboard` added to **both** rights tables as nullable `ENUM('allow','deny')`. **NULL = INHERIT, never deny** — absence is not a decision |
| **Files** | `database/migrations/2026_08_07_110000_add_tristate_rights_columns.php` |
| **Verification** | 5 of 5 columns on both tables · **4,879 legacy `can_view` rows untouched** · all 4,879 `right_view` values NULL |
| **Acceptance** | Structural half passed. **No behaviour change** — nothing reads these yet; the legacy `can_*` columns stay authoritative until the resolver is live (§10 step 11) |

Precedence stated in the header and to be implemented exactly:
**individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny.**

---

## D-009 · 4b — populate the rights matrix · ⛔ **NOT APPLIED — REVIEW GATE**

**2026-08-07** · `_changes/X-01-REVIEW-GATE.md`

**Backup taken first:** 4,879 rows dumped to `_changes/`, with a restore script.

**Admin lockout: asserted SAFE.** `Role & Permissions` (menu 23) has Admin
`can_view=1` in 11 of 11 tenants, and §3.1 grants Admin `V C E D` on it.

**STOPPED on two blockers:**

1. **§3.1–3.7 specifies 8 roles; 3 exist.** `Reporting Mgr`, `Dept Head`, `Executive`, `Auditor` and the `HR Exec`/`HR Mgr` split have no profiles. Applying today means collapsing eight columns into three — and **choosing between HR Exec and HR Mgr for the single `HR` profile is re-deriving a decided permission**, which the instruction forbids.
2. **No screen→menu mapping exists.** §3.x names screens; the table keys on `menu_id`. 157 menus in the rights table vs 188 in the master, names not one-to-one.

**Recommendation: do item 5 first** — it creates the role model §3.1–3.7 is written
against, and it is schema-only and invisible.

### ⚠️ A correction to how G-SEC-07 is quoted

*"Employee 1,657 vs Admin 1,500"* is an **aggregate across 11 tenants**. Per
profile — what **one user** sees — it is **Employee 151 · HR 150 · Admin 136**.
**The inversion is real and survives**, but those are the numbers to quote.

---

## D-010 · Item 5 — reporting line, role keys, cycle validation

**2026-08-07** · **APPLIED** · commit **`f293edb0`**

| | |
|---|---|
| **Changed** | `tbluser.reporting_manager_id` · `hrms_departments.head_user_id` · `tbluserprofilemaster.role_key` / `data_scope` / `is_system` · new `tenant_setting` table |
| **Files** | `database/migrations/2026_08_07_120000_add_reporting_line_and_role_keys.php` · `app/Services/Org/ReportingLineValidator.php` |
| **Verification** | 6 of 6 columns added · `tenant_setting` created · **0 of 387 users have a manager** (all NULL, as expected) · **0 cycles in existing data** |
| **Acceptance** | Validator behaviour tested: **self-reference rejected · NULL manager allowed · clean assignment allowed · 0 existing cycles · default depth 1**. **Not `Verified`** — no UI path exercised |

### The guarantee the schema cannot make

MySQL has **no recursive CHECK**, so "this reporting graph has no cycles" cannot be
a constraint. It lives in `ReportingLineValidator`, and **the migration header says
so** rather than letting a later reader assume the schema enforces it:

- **`canAssign()`** walks **up** from the proposed manager and refuses if it reaches the user. Rejects self-reference (the degenerate one-node cycle). **Refuses to extend a pre-existing cycle** rather than silently absorbing it.
- **`teamOf()`** is bounded by `team_scope_depth` (A5, default **1 = direct reports only**) and carries a seen-set, so **even a corrupt graph terminates**.
- **`findCycles()`** for the periodic check — same shape as the polymorphic-integrity check for `competency_kasba_item`.

### `role_key` — why it exists

The resolver keys on `role_key`, **never on `name`**. Renaming a role in a
customer's UI must not break access. `data_scope` lives on the role because **scope
is never individually overridable** (A6).

### Ordered before 4b deliberately

§3.1–3.7 is written against **nine** roles; three exist. This migration creates the
model that matrix needs, so 4b can apply it faithfully rather than collapsing nine
columns into three.

---

## D-011 · 4b-prep (a) and (b)

**2026-08-07** · commit **`dd25e450`**

### (a) Does §3.1–3.7 carry a Recruiter column? — **NO, and no derivation is needed**

**All seven §3.x tables carry eight columns.** Recruiter is absent from every one.

**But its permissions are already decided** — `03-rbac-matrix.md` **Q-D1**
(line 736) holds a complete Recruiter table. It is **module-level, not
screen-level**, which is why it never merged into §3.1–3.7:

| Module | Recruiter |
|---|---|
| Talent → Recruitment | **V C E D A** |
| Talent → Onboarding | V *(handover of hired candidates only)* |
| Organization → Employee Directory | V *(basic fields only, per A1)* |
| Competency → Framework & Role Mapping | V *(read required competencies for a requisition)* |
| Competency → Employee Profiles / ratings | **–** |
| Talent → Performance | **–** |
| HRIT → Payroll | **–** |
| Everything else | – |

**So the gap is a FORMAT gap, not a decision gap.** Recruiter needs expanding from
8 module rows to per-screen rows for the seed — mechanical, since *"everything else
= –"*. **No permission is being re-derived.**

⚠️ **For approval before 4b runs:** the expansion itself. Every screen inside the
four granted modules inherits that module's mark; every other screen is `–`.

### (b) The nine canonical roles — **APPLIED**

| | |
|---|---|
| **Changed** | `role_key` + `data_scope` + `is_system` stamped on the three existing profiles; six missing roles created, empty, per tenant |
| **Files** | `database/seeders/Phase3RoleSeeder.php` |
| **Verification** | **9 role_keys × 11 tenants** · 99 of 103 profiles keyed · **user assignment unchanged** (employee 238 · administrator 76 · hr_manager 72) |
| **Acceptance** | Idempotent, re-runnable. **Touches no rights rows** — that is 4b |

Mapping applied as directed: Employee → `employee` (self) · HR → `hr_manager`
(organization) · Admin → `administrator` (organization). Created empty:
`reporting_manager` (team), `department_head` (department), `hr_executive`
(department), `executive`, `auditor`, `recruiter`.

**4 profiles remain unkeyed** — `ZZ Audit Role v2` ×2, `Organization Administrator`,
`Deparment Administrator` *(sic)*. Left alone deliberately: they are not among the
nine, and one has a live user. **Flagged, not touched.**

### (c) The screen→menu mapping — **NOT STARTED**

The reviewable deliverable. Next turn.

---

## R7 applied retroactively — which costs move

**R7: an estimate that does not name the files it touches is a guess.** Every
existing cost was re-checked against that test. **The pattern in the L-01 miss was
skipping a pipeline** — so the re-check asked, for each connection, *what has to
change between the form and the column?*

### Costs that move

| # | Was | Now | Files the old estimate skipped |
|---|---|---|---|
| **L-01, L-02** | XS | **XS again — but only after C19** | Standalone they are S–M (§5.5). As configuration on the generic picker they return to XS |
| **L-04** Job Level → `s_level_responsibility` | S | **XS after C19** | **Same picker pipeline.** Estimated as if binding a field were local. It is the identical mechanism as L-01 |
| **L-15** Compliance Relevance → boolean + regulation ref | S | **M** | Named no migration. A type change on a live column needs migration + backfill + both consumers |
| **L-16** Risk Implications → severity enum | S | **M** | As above, plus `competency.criticality` must exist first |
| **L-17** `assessment_method` enum (substitution) | S | **M** | As above, **plus dropping two columns** — a data decision, not a config change |
| **L-19** Experience → numeric | S | **M** | As above. "Typing change" hid a migration on a populated column |
| **C-02** reject → resubmittable | XS | **S** | Named one file; needs `ApprovalController.php` **and** `cm-competency-library.tsx` — two repos |

### Costs that hold

| # | Verdict |
|---|---|
| **L-03R** | **XS confirmed — and now built.** 2 files, one repo, no schema |
| **L-06, L-10, L-11, L-14, C-05, C-07** | Already M/L with named tables |
| **C-01** | **S holds.** `skillLibraryController.php` (3 sites) + `CompetencyController.php`. Backend only, no schema |
| **L-21, L-22, L-23, C-10** (display tier) | **⚠️ NOT CONFIRMED.** Each claims "show text on the screen where the work happens" but **none names the screen file**, and none verifies the item record even reaches that screen. If it does not, each needs a payload change and is **S, not display**. Flagged rather than silently re-tiered |

### R7 follow-up — the four S→M costs re-checked against ACTUAL ROWS (R3)

**Not inferred from table names.** Every column was counted and three sample values
read.

| Connection | Column | Rows | Populated | A real sample |
|---|---|---:|---:|---|
| L-15 | `s_user_knowledge.compliance_relevance` | 6,950 | **804** (11.6%) | *"Patient Safety Regulations, Duty of Care"* |
| L-16 | `s_user_behaviour.risk_implications` | 694 | **39** (5.6%) | *"Increased medication errors, patient harm during care transitions…"* |
| L-17 | `s_user_ability.cognitive_elements` | 6,175 | **1,015** (16.4%) | *"Visualization, Memory, Reasoning, Information Processing"* |
| L-17 | `s_user_ability.psychomotor_elements` | 6,175 | **1,015** (16.4%) | *"Coordination, Precision, Speed & Accuracy"* |
| L-19 | `s_user_jobrole.experience` | 4,610 | **1,914** (41.5%) | *"2-3 years in trade processing, fund settlement, or treasury operations"* |

**The hypothesis that some would be throwaway is NOT borne out. All four are
curated reference content** — real, domain-specific, human-authored text in the
KASBA and job-role libraries, not transactional residue. **M stands on all four**,
but the rows changed two estimates in ways the table names could never have shown:

#### L-16 → **S–M**, cheaper than M

Only **39 rows** are populated. Backfilling 39 prose values into a severity enum is
an hour of human judgement, not a migration problem. The cost is the schema change
and the consumers, not the data.

#### L-19 → **L**, more expensive than M · **my framing was wrong twice**

`experience` does **not** hold a mis-typed number. It holds a *sentence containing*
a number: *"2-3 years in trade processing, fund settlement, or treasury
operations."* Converting it to a numeric minimum would **destroy the domain
qualifier**, which is the more useful half.

So this is **not a type change at all.** It is: add `min_years_experience` as a new
numeric column, derive a candidate value where a range can be parsed, **keep the
text**, and report the rows where no number can be extracted. 1,914 rows to review.

#### L-17 → **the SUBSTITUTION IS WRONG. Flagging rather than building it**

The proposal was to replace *Cognitive Elements* and *Psychomotor Elements* with one
`assessment_method` enum, on the grounds that this is "the real idea underneath
them". **The rows say otherwise.** They contain a *taxonomy of ability elements* —
`Visualization, Memory, Reasoning`, `Coordination, Precision, Speed & Accuracy` —
**not assessment methods.** No enum of `test / observed_demonstration / portfolio /
manager_rating` can express "Visualization, Memory, Reasoning".

The enum is still a **good idea**, and the *inference* behind it holds: a cognitive
ability wants a test, a psychomotor one wants observed demonstration. But it is
**additive, not a substitution** — and dropping the two columns would destroy
**1,015 rows of curated taxonomy**, which is a data decision, not a schema tidy.

**Revised L-17:** add `assessment_method`, **derive** its default from which of the
two element columns is populated, and **keep both columns**. Cost **M**.

#### L-17 follow-up — DISTINCT counts settle it: **it IS a library**

| Column | Populated | Distinct terms | Top 10 terms cover | Terms used exactly once |
|---|---:|---:|---:|---:|
| `cognitive_elements` | 1,015 | 448 | **78%** of 2,711 occurrences | **364 (81%)** |
| `psychomotor_elements` | 1,015 | 459 | **54%** of 1,787 occurrences | **395 (86%)** |

**A controlled vocabulary of ~10-25 terms, plus a long tail of ~380 one-offs.**
Ten terms cover three-quarters of cognitive usage. That is a library with noise,
not free prose.

**Casing drift is already present — and I nearly mis-stated its size.** By distinct
terms the collapse is only **1%** (453→448); just 5-6 terms have variants. But
those are the *highest-volume* terms: `Information Ordering` **514** vs
`Information ordering` **460**; `Deductive Reasoning` **404** vs
`Deductive reasoning` **218**. **By row volume the most common term in the table is
split almost 50/50 across two spellings**, so any count or filter on it is already
wrong. Quoting either number alone would have misled (R1).

`psychomotor_elements` also carries **null-as-text in three spellings** — `N/A`
(113), `None (primarily cognitive)` (96), `None (primarily cognitive/social)` (36).

**Verdict: propose as a candidate vocabulary alongside Q-L3's shared category
structure.** Keep both columns; add `assessment_method` **additively**, defaulting
from which column is populated. Cost **M**.

*(Aside, offered as an observation not a claim: `Persuasion` (102) and
`Team Coordination` (130) are the top psychomotor terms. Those read as social
skills. If real, it is a content-quality issue for whoever curates the library, not
a schema one.)*

#### The other two columns — NOT libraries

| Column | Evidence | Verdict |
|---|---|---|
| `risk_implications` | **39 distinct whole values from 39 rows**; 154 terms, nearly all frequency 1 | **Notes.** Matter closed. L-16's severity enum stays correct and additive |
| `compliance_relevance` | **792 distinct from 804 rows** | **Notes**, with a faint `… Standards` pattern in the tail. L-15 unchanged |

#### L-19 revised again — **M, not L**

Do not require a human pass over 1,914 rows before shipping. Parse the clear
patterns (`N-M years`, `N+ years`), **leave the rest NULL, report coverage**, and
let eligibility filtering declare its own completeness — the readiness-gate pattern
already in the design. Remaining rows get cleaned during a customer's onboarding,
or never. **Keep the text column.**

#### Costs that were already right

`skill_importance` (L-18) is populated on 938 rows and its values are already
`High` / etc. — effectively an enum in a text column. **S confirmed**, and the
cheapest of the group.

### Display trio resolved — 3 of 4 move to S

Two facts each, as required: the screen that would show the text, and whether the
record already reaches it.

| # | Screen | Does the record reach it? | Verdict |
|---|---|---|---|
| **L-21** Performance Metrics | assessor rating — `cm-assessment-workspace.tsx` | **NO.** `performance_metrics` appears nowhere in `AssessmentCycleController.php` | **S** |
| **L-22** Measurement Metrics | rating-scale anchor, same screen | **NO.** `measurement_metrics` likewise absent | **S** |
| **L-23** Development Methods | dev-plan creation — `cm-development-career.tsx` | **NO.** `development_methods` absent from `DevelopmentPlanController.php` | **S** |
| **C-10** Sub Category, Department, Business Link, Related Competencies, Tags | Library drawer — `cm-competency-library.tsx` | **YES.** All five are in the detail `select()` (`skillLibraryController.php:70,222`) — returned and simply not rendered | **display — CONFIRMED** |

**The suspicion was right.** Three of the four assumed a payload that does not
exist; each needs the API to join and return the field before anything can be
shown. Only C-10 is genuinely free, and it is free precisely because the data is
**already on the wire and thrown away** — which makes it the best value in the set.

### The generalisation

Every cost that moved shares one shape: **a "typing change" or a "field binding"
that quietly assumed the plumbing already existed.** Four became M because a live
column needs a migration; two collapse to XS *only* once C19 builds the mechanism
once.

**The C19 decision is worth more than it looked.** It converts L-01, L-02 and L-04
from three bespoke S–M changes into one M–L mechanism plus three configuration
entries — and every later entity binding after that is configuration too.


---

## D-012 · F-6 CANCELLED — Taxonomy Ontology (menu 43) is KEPT

**Decision, 2026-08-10: we want the ontology screen.** F-6 is **cancelled, not
deferred** — there is no pending removal and no scheduled revisit.

**Consequences, all three applied:**

1. The instruction to drop menu 43 from the seed is **withdrawn**. The grant
   stands **as §3.x specifies** — Employee holds `V` on menu 43 in the applied
   4b seed. Nothing else about 4b changed.
2. **R8's pre-deletion checklist for F-6 is closed out.** A checklist that guards
   a deletion has nothing to guard once the deletion is cancelled.
3. The registered replacement is **re-filed as an ENHANCEMENT of this screen**,
   not a rebuild of a deleted one. See C-T3-ONT below.

### Check A — what the iframe loads

`components/domain/competency/cm-taxonomy-ontology.tsx:28`

| Question | Answer |
|---|---|
| Host | **`https://skill-ontology-neo4j.vercel.app` — EXTERNAL**, a third-party Vercel deployment, not ours |
| Employee identifier passed? | **No** |
| Tenant identifier passed? | **YES** — `:50` builds `?sub_institute_id=${encodeURIComponent(subInstituteId)}` |

**So a tenant identifier leaves our origin, in the URL, to a third-party host on
every load of this screen.** That host also sees the customer's IP. **This is the
same question already answered for the Skills and Pal links under Q-A5, and a
corporate security review will ask it.**

**What is already right**, and should not be re-litigated: the iframe carries
`sandbox` **without** `allow-same-origin`, so it cannot reach our cookies or DOM,
and `referrerPolicy="no-referrer"`, so our own URLs do not leak. The exposure is
the tenant id and the request itself, not the session.

### Check B — the original concern survives the decision

**Keeping the screen is fine. Keeping it UNLABELLED is the risk.** It shows
adjacency **not derived from our mapping data**, so a user may reasonably believe
it reflects their organisation when it does not.

**Interim, now: label it plainly as a reference/example view.** One component,
one banner — **S**.

**The real fix is registered as C-T3-ONT.**


---

> **The twelve security-stream entries below were shipped and recorded in
> `07-gap-register.md` and in git, but were missing from this log for ~8 turns.
> Added 2026-08-10 when the queue/log reconciliation was introduced (R18).**

## D-013 · 4b APPLIED — the rights matrix populated

`5af9b26a` — 5,621 rows across 99 profiles, 0 orphans, backup taken. Nine roles render through the REAL request path (R9). Administrator retains 1/8/23.

## D-014 · G-COMP-SEC-01 — competency profile ownership

`27f3ab10` — competencySubject() on all 13 methods. Own 200 / colleague 403 / cross-tenant 404. The write claim was overstated and is corrected in the register.

## D-015 · G-AUTH-01 — exact role_key matching

`f9cd7ede` — RequireProfile matched display-name SUBSTRINGS. Differenced old vs new across all 112 profiles and both arg-sets: exactly 1 profile changes, 0 users. Competency 154-158 re-granted.

## D-016 · G-LMS-SEC-01 — assignment endpoints were UNAUTHENTICATED

`18b3147b` — if ($type == "API") made auth optional. Proven: 200 and 20,777 bytes with no token. The first unauthenticated exposure of the phase.

## D-017 · G-SEC-15 — anonymous memory exhaustion

`9fc3a42f` — getSkillCompetency: no auth, unbounded ->get(), and a hardcoded `?? 2` tenant. Auth AND a bound, because either alone leaves it exploitable.

## D-018 · G-ATT-SEC-01 — punch as a colleague

`33f45571` — punchSubject() resolves the subject from the token at all three sites. Colleague 403 / self 200. Test row read before deletion (R8).

## D-019 · G-SEC-17 — hardcoded tenant and role literals

`6dfdd2a2` — Nine sites across seven LMS controllers. Exposed C23's third-tenant blind spot, recorded against C24.

## D-020 · G-SEC-19a — SQL injection

`24d63869` — lmsmappingController: chapter_id concatenated into raw SQL. Payload 1' OR '1'='1 returned 10 rows against an honest 0. Found by running the fail-closed check.

## D-021 · G-SEC-19b — the injection sweep

`7458b4a1` — Two more concatenation sites bound. ORDER BY / LIMIT ruled out BY TEST, not omission - class closed.

## D-022 · G-LEAVE-SEC-01 — request-first with a safe-looking fallback

`acfcf4d1` — leaveSubject(). The defect was in store(), so it was a WRITE: filing leave as a colleague. Row scope remains open.

## D-023 · G-SEC-20 — second-order injection, arbitrary table drop

`17fa3b2f` — table_name stored unvalidated, concatenated into DROP TABLE, and DB::statement executes multiple statements. Existing data read first: 1 row, clean.

## D-024 · G-SEC-21 — dynamic table names validated at the point of use

`80ea67a7` — The structural fix: validation existed ONLY at creation. assertSafeTable() on every DynamicModel entry point. Throws loudly.

## D-025 · G-SEC-22 — tableDelete auth and tenant scoping

`c3dd4b71` — No auth, no tenant, no user check. Anonymous 401 / foreign id 404 / legitimate row untouched.


## D-026 · The {id} read probe

Employee token (user 198, tenant 7), GET only, real ids from tenants 7 and 3,
chunked. **1,819 requests over 113 of 113 `api/*` routes with one `{param}`.**
**REACHABLE 23 routes** (200, body > 60 bytes, other-tenant id). 192 HTTP 500s
left deliberately unclassified. `_evidence/id-probe.php`.

## D-027 · G-SEC-23 — cross-tenant read, verified and FIXED by chain

**Verification:** 114 route+id pairs, each asserting the tenant-3 row's own
identifying field appears in the body. **3 DISCLOSING, 20 NOT, 0 INDETERMINATE.**

**Fixes, keyed on the reach chain rather than per route:**
chain A (`api` group, no auth) - `api/feedback/{id}` gained auth **and** the
tenant clause its own resolved `$subInstituteId` was never used for;
`api/competency/audit/user-actions/{userId}` gained a tenant clause on the NAME
lookup at `:556` (its activity queries were already scoped).
Chain B (authenticated, unscoped) - `api/user-signup/{id}` gained the tenant
clause; **the fix was not "add auth"**, it is G-SEC-09's missing layer in a route
that looks guarded.

**Re-verified after the fix: DISCLOSING 3 -> 0, the other 20 unchanged.**

## D-028 · Harness correction inside the verification

The first verifier kept only the largest response per route and tested that id
against markers from whichever table it was pooled from. **It would have published
"1 of 23" - an under-count presented as complete.** Caught only because a
known-positive disagreed (R16). `_evidence/id-verify.php`.


## D-029 · G-AUTH-02 — the last two substring matchers

`guardLmsProfile` and `canAuthor` moved to one shared `lmsRoleMatches()` with
exact `role_key` comparison and the **same mapping as `RequireProfile`**, so all
three gates agree by construction. Neither was failure-open;
`assignmentController`'s failure-open clause was confirmed already fixed under
G-LMS-SEC-01.

**Verified to G-AUTH-01's standard:** old differenced against new across all 112
profiles and the only argument set in use — **1 profile differs, 0 users**
(id 38 "Deparment Administrator", the same deliberate denial).

**5 files** (R18d): 2 controllers/traits + 3 docs.


## D-030 · Item 6, slice 1 — the event store

`g2g_event` + `g2g_event_delivery`, built exactly to `05-data-flow-contracts.md`
§1. **Nothing adapted** - that document is the contract for six other items.

**Confirmed as built, not as intended:**

- **`sub_institute_id` NOT NULL on every event.** This is the one table that will
  hold every tenant's history, so it is the worst place for G-DATA-08 to recur.
- **`actor_id` nullable, where NULL means SYSTEM** - a real value, not "unknown".
  Any non-null value comes from the resolved identity, never a request field.
  **G-SEC-12 is what makes that true, and it was this item's precondition (§1.9).**
- **`occurred_at` and `recorded_at` separate**, both `DATETIME(3)`.
- **Delivery state per CONSUMER**, not a column on the event.
- All five indexes and both unique keys present, verified against the DDL.

**One representation note, not a contract change:** MariaDB stores `JSON` as
`LONGTEXT` with a check constraint, so `payload`/`metadata` report as `longtext`.
The column type in the contract is honoured; the engine's storage differs.

**Deliberately NOT built in this slice:** the projections. `05`'s §1 names
`g2g_audit_log`, which does not exist, while `task_management_audit_logs` (6 rows)
and `tbl_user_journey_logs` (5,234 rows) are **live independent writers the
document does not mention**. **Raised, not decided** - and the store is required
under every resolution, so it was safe to build while that is open.

**3 files** (R18d): 1 migration + 2 docs.


## D-031 · Item 6, slice 2 — g2g_audit_log as a projection, TaskAuditService converted

**§1 amendments A1/A2/A3 recorded in `05-data-flow-contracts.md`, dated, because
that document is the contract for six other items.**

**The deliverable was the WRITER, not the six rows.** `TaskAuditService` inserted
into `task_management_audit_logs` directly; it now calls `EventRecorder::record()`.
**No direct write path remains** - the only surviving mention of the old table in
`app/` is the comment explaining the change.

Built: `EventRecorder` (the only writer of `g2g_event`), `g2g_audit_log`
(projection, UNIQUE on `event_id` so re-projection cannot duplicate),
`AuditLogProjector` (kind = **P**, pure, `catchUp()` and `rebuild()`).

`configChanged` now emits `entity_type='task_config'` instead of the `task_id = 0`
sentinel - a workaround for a table with no way to say "this is configuration".

**Acceptance test, end to end:**

| Check | Result |
|---|---|
| emit → `g2g_event` | `task.status_changed`, tenant 7, actor 198, entity `task/999999` |
| project | 1 event → **1 audit row** |
| **re-project the same event** | **still 1 row** — idempotent by construction |
| **rebuild** (truncate + clear ledger + re-derive) | **1 row re-derived** |
| **tenant guard** | `sub_institute_id = 0` **REFUSED** — *"An event requires a tenant."* |

Test data removed afterwards; this is a shared database.

**Not yet built:** the catalogue with `kind` per row (slice 3),
`task_status_history` and §6.2's replay procedure (slice 4). **No reactor exists
yet, so replay-mode reactor dispatch is untested** - it is specified and unbuilt,
not assumed working.

**6 files** (R18d): 1 migration + 3 app + 2 docs.


## D-032 · Item 6, slice 3 — the event catalogue

`EventCatalogue` encodes §2.1 and §2.2 as code. It is a class, not a table,
because these are **design decisions, not tenant data**: a tenant cannot make a
reactor replayable.

**15 shipped events · 32 consumer rows · 0 blank `kind` · 5 projectors · 9 reactors.**

**Three invariants enforced by `assertInvariants()`, not by review** - ALL PASS:

1. **`kind` on every consumer row.** P or R, never blank.
2. **Named-consumer test.** 0 shipped events with no consumer.
3. **No reactor downstream of a projector.** `AuditLogProjector` is the only built
   projector and was checked directly: it touches nothing outside its own table.

A fourth invariant emerged while writing it and is enforced too: **a consumer
cannot be P in one event and R in another.** Replay safety is a property of the
CONSUMER, not of the pairing - and a split kind would make rebuild safe for one
event and unsafe for another under the same name.

**Failed the named-consumer test, kept visible so nobody re-proposes them:**

| Event | Verdict | Trigger |
|---|---|---|
| `task.assigned` | DEFERRED | readiness gate: **task_hygiene** |
| `task.overdue` | DEFERRED | readiness gate: **task_hygiene** - would fire **2,245 times today** |
| `task.completed` | DROPPED | no consumer DOES anything |
| `competency.gap_detected` | DROPPED | gaps are DERIVED; the gap is a query, not an event |

**Every deferred event carries a trigger, and the invariant check fails the build
if one does not** - an event deferred without a trigger is one nobody will
remember to enable.

**2 files** (R18d): 1 app + 1 doc.


## D-033 · Reactor/plan reconciliation — five items added to 08

**Reframed per Triz: an UNDERSTATED PLAN, not scope creep.** All six missing
consumers serve threads the plan already commits to; it carried the threads and
missed the machinery.

3 of 9 reactors were carried (`NotificationDispatcher` = X-06, `AccessRevoker` and
`TaskReassigner` = the line-336 offboarding item). 6 were not.

**Assigner decision, from the write path rather than preference:**
`MandatoryLearningAssigner` + `LearningAssigner` **collapse** - both write
`lms_assignments` with the same record shape, differing only in trigger.
`RemediationRecommender` does **not** - its primary act is to SHOW a
competency-derived course, not to write one. **Five new items, not six or four.**

**Reverse check run, and its limit recorded:** `08` names no events at all, so it
**cannot** imply a missing one by name. **The empty result is not a clean bill.**

Plan item count **~40 → ~45**.

**3 files** (R18d): 2 docs + 1 count update.


## D-034 · Item 6, slice 4 — first consumer, replay procedure, first reactor, REAL REBUILD

**ITEM 6 CLOSES.** All four claims RUN, not summarised.

### 1. `task_status_history` — F2 is DETECTABLE, not describable

`TaskStatusProjector` (kind **P**). A reopen is a transition INTO an active status
FROM a terminal one, and **it cannot be seen from the task row at all** - by the
time a task is reopened, the row that said "completed" has been overwritten. The
transition exists only in the event stream.

| from | to | from_terminal | to_active | is_reopen |
|---|---|---|---|---|
| OPEN | COMPLETED | no | no | no |
| COMPLETED | IN_PROGRESS | yes | yes | **YES** |
| IN_PROGRESS | IN_REVIEW/approved | no | no | no |
| IN_REVIEW/approved | IN_PROGRESS | yes | yes | **YES** |

**2 reopens detected of 2 expected** - including the approval-withdrawn case,
where `approve_status='approved'` is the terminal marker rather than the status.

### 2. §6.2 as code — `ReplayRunner`, with its refusals verified

| Attempt | Result |
|---|---|
| replay mode not passed | **REFUSED** |
| no recorded store `max(id)` | **REFUSED** |
| **a REACTOR as the target** | **REFUSED** |

**Dry run: shadow=4, live=4, verdict PROCEED — and the live table was NOT touched**
(4 rows before and after). Projectors were made target-table aware so the shadow
run writes nowhere near live; the first version moved rows through the live table
and that was fixed, not documented as acceptable.

Runbook written to `_changes/REPLAY-RUNBOOK.md`. **Rollback is RESTORE, and
`ReplayRunner` has no `rollback()` method** - offering one would imply replay can
undo itself.

### 3. First reactor — THROW-ON-REPLAY, exception shown

```
RuntimeException: Reactor [notification_dispatcher] was dispatched during replay.
Reactors never run on replay (05-data-flow-contracts.md §2.0). This means a
reactor is registered as a projector, or a projector invoked a reactor.
```

A **throw**, not a no-op: a no-op would let a rebuild complete while silently
skipping every side effect, and the operator would see success.

### 4. A REAL REBUILD — the last unproven claim, now VERIFIED

| Ledger | before | after | |
|---|---:|---:|---|
| **projector** (`task_status_projector`) | 4 | 4 | **cleared, then re-derived** |
| **reactor** (`notification_dispatcher`) | **1** | **1** | **PERMANENT — SURVIVED** |

4 rows re-derived; **F2 reopens still 2 after the rebuild** - the projection is
identical, so the projector is idempotent under a full rebuild (precondition 2,
proven by running it rather than by inspection).

**"Specified" and "verified" have been kept distinct through this whole build.
This is the item that distinction was for, and it is now verified.**

Test data removed - shared database. Events 0, history 0, audit 0, delivery 0.

**One discrepancy reported, not silently fixed:** §6.2 precondition 1 says
`g2g_event_store`; the table built to §1's DDL is `g2g_event`. §1 is
authoritative. **Not edited** - `05` is the contract for six other items.

**8 files** (R18d): 1 migration + 5 app + 2 docs... plus the runbook and evidence = 10.


## D-035 · F-07b — the unmatched report (READ-ONLY; nothing backfilled or dropped)

**The deliverable, in front of Triz before any write.** Report at
`_changes/F-07b-UNMATCHED-REPORT.md`; generator at
`_evidence/f07b-unmatched-report.php`.

**Number defined before quoting (R10):** 424,630 is **resolutions**, not rows -
6 column mappings across 4 tables (283,127 rows). Quoting it as rows would
overstate by 50%.

| Reason | Distinct | Resolutions | Share |
|---|---:|---:|---:|
| EXACT | 19,531 | 394,288 | 92.9% |
| CASE | 6 | 392 | 0.1% |
| WHITESPACE | 0 | 0 | 0.0% |
| NEAR-MISS | 1 | 25 | 0.0% |
| **NO COUNTERPART** | **5,088** | **29,925** | **7.0%** |

**The prediction did not hold, and is reported rather than reconciled.** The
unmatched set IS large, but **only 417 resolutions are recoverable** - the failure
is not messy spelling, it is **references to things that do not exist**.
`s_skill_matrix`, which set the expectation, has **169 rows with `skill_id` 100%
populated** and is not part of this backfill.

**SECOND FINDING: three of six mappings have NO `sub_institute_id` on the source.**
`s_jobrole_skills` and `s_jobrole_task` carry no tenant column, so "match within
tenant" is impossible for them. They were matched across ALL tenants, so **their
match rates are UPPER BOUNDS, not measurements** - affecting **125,314 rows, 44%
of the four tables**. Those two tables are **BLOCKED**: establishing tenant is
prior work, not backfill.

**2 files** (R18d) + this log entry.


## D-036 · F-07b — Q-C1 answered, orphans profiled, backfill applied

**Q-C1:** the two tenantless tables resolve to **TENANT-OWNED** canonical rows,
and the same string matches up to **10 tenants** (785 / 785 / 617 ambiguous
values). **STOP on Triz's criterion** - a match there is a choice among ten ids,
not a resolution. Blocked for a better-founded reason than "establishing tenant is
prior work".

**Orphan shape differs BY MAPPING:** jobrole orphans **CONCENTRATED** in tenant 9
(89.9% / 76.4%) = seed junk from one import; skill orphans **SPREAD** across six
tenants = genuine incompleteness. Both hypotheses true, of different mappings.

**Backfill applied, three tenant-scoped mappings:** 244,253 rows, **229,316 exact
+ 365 recovered = 229,681 populated, 14,572 HELD as NULL.** Every figure matches
the report's prediction exactly.

**Verification:** 5-row sample all IDENTICAL with tenants matching; **cross-tenant
foreign keys 0 / 0 / 0 across the whole population**, not a sample.

**Corrected (R19):** my recommendation said 240,900 / 13,777 / 417. Actual
**244,253 / 14,572 / 365** (417 was the all-six total).

**Nothing created, nothing deleted, no text column dropped.**

**3 files** (R18d): 1 migration + 1 evidence + 1 report addendum, plus this log.


## D-037 · Slice 1, items 0 and 1

### Item 0 — the blocker, cleared

`competency_kasba_item.item_id` was **NOT NULL** with **no `item_label`**, against
D-007's record of "item_label kept + item_id nullable". **Caught by checking the
CREATED SCHEMA, not the migration source - that rule has now caught three things.**

Without it, **four of the five KASBA dimensions are UNCOMPOSABLE**: knowledge,
ability, attitude and behaviour have no canonical table, so a competency could
only ever bundle skills, contradicting Q-A2 outright.

**THE RULE, recorded so `item_label` does not become the free-text problem this
phase exists to remove:**

| State | Meaning |
|---|---|
| `item_id` populated | **THE TARGET STATE** - resolved by key |
| `item_label` alone | **A HOLDING STATE** - counted as unresolved, feeds capability coverage |

**A label is never treated as a key.** Same shape as F-07b's held orphans: honest
about what is unresolved rather than guessing an id. A row naming *neither* is
refused at validation.

Table was empty, so relaxing NOT NULL orphaned nothing. Verified against the
created schema: `item_id null=YES`, `item_label varchar(191) null=YES`.

### Item 1 — competency definitions, as a bundle

**A finding first:** `routes/api.php:360` serves `CompetencyCrudController`, which
is an **alias for `CompetencyController`**, whose `store()` inserts into
**`s_users_skills`** - it creates a FLAT SKILL ROW and calls it a competency.
That is the skill library, a different concept; it is left alone because the
library screen reads it.

New `CompetencyDefinitionController` writes `competency` + `competency_kasba_item`
in one transaction. **Nothing wrote to either table before this.**

A `skill` item whose `item_id` is not in the caller's own tenant is **held by
label rather than pointed at another tenant's row.**

**Verified through the real request path:**

| Request | Result |
|---|---|
| POST as **employee** | **403** - `profile:admin,hr` (exact `role_key`, G-AUTH-02) |
| POST as **admin** | **201**, competency id 1 |
| stored composition | skill -> `item_id=1` **TARGET**; knowledge and attitude -> label only, **HOLDING** |
| coverage metric | **2 of 3 unresolved**, surfaced by the API |

Test rows removed afterwards - shared database.

**5 files** (R18d): 2 migrations/controllers + routes + 2 docs.


## D-038 · Slice 1, item 2 — the competency composer UI

`services/competency/definitions.ts` + `components/domain/competency/cm-competency-composer.tsx`,
plus a validation fix in `CompetencyDefinitionController`.

### The two constraints, built in rather than described

**1. TARGET vs HOLDING is visible WHILE COMPOSING.** Every item row carries a
badge - **"Resolved by key"** (green, key icon) or **"Held as label"** (amber, tag
icon) - and a running banner states *"N of M items will be held as labels ...
they count as unresolved in capability coverage"*. **Not a hidden field and not a
later report.**

**2. No picker without a list behind it.** Only `skill` renders a dropdown.
Knowledge, ability, attitude and behaviour render **free text, explicitly labelled**
*"...have no central list yet, so this is recorded as a label"*. **An empty
dropdown would imply a list that does not exist.** Switching an item to one of
those four **drops any id it was carrying**, because it could not have meant
anything there.

**No canonical tables were added for the four dimensions. Slice 1 did not grow.**

### Verified through the real request path

| Case | Result |
|---|---|
| POST as **employee** | **403** — `profile:admin,hr`, exact `role_key` (G-AUTH-02) |
| item with **neither id nor label** | **422** — *"Item 1 needs an item_id or an item_label."* |
| **skill id from ANOTHER tenant** | **201, and HELD BY LABEL** — `item_id=NULL`. The safeguard fired |
| valid competency | **201** — skill `item_id=1` **TARGET**, behaviour **HOLDING** |
| duplicate code | **422** with a readable message |

### A defect found by testing, not by review

The happy path first returned a **500 leaking `SQLSTATE[23000]`**: `competency`
carries `uq_competency_tenant_code (sub_institute_id, code)`, and a duplicate code
escaped as an integrity-constraint error. **A constraint the user can trip is a
validation rule from their side**, so it is now a clean 422. Found only because
the test reused a code.

Test rows removed - shared database.

**3 files** (R18d): 1 controller + 2 frontend, plus docs.


## D-039 · Slice 1, items 3-4 — the mapping, and G-MAP-01 reinstated

### The constraint sweep (S), first

Asked after the duplicate-code 500. **One more user-trippable constraint found:**
`uq_ck_item (competency_id, kasba_type, item_id)` - the same skill listed twice
under one dimension. Now a 422 naming the row. The `g2g_*` unique keys are
system-generated and cannot be tripped by a caller. `uq_jcm` was built with its
guard from the start rather than added after.

### Item 3 — `RoleCompetencyMapController`, everything BY KEY

Bulk upsert into `jobrole_competency_map`. **Upsert, not insert**: re-saving a
role's requirements is the ordinary case, and making the caller delete first
would lose the mapping on any failure between the two calls.

**Verified through the real request path:**

| Case | Result |
|---|---|
| POST as **employee** | **403** |
| job role from **another tenant** | **404 Job role not found** |
| same competency twice | **422** naming the row |
| competency that does not exist | **422** listing the ids |
| valid | **201** |

**THE BY-KEY PROPERTY, PROVEN NOT ASSERTED.** Every column of a stored row was
printed: `id, sub_institute_id, jobrole_id, competency_id, required_proficiency,
is_mandatory, created_by, created_at, updated_at`. **Text-bearing columns: 0.**

**Rename preview (item 9, early):** renamed the job role to *"… (RENAMED)"*, and
the mapping **still resolved**. Name restored afterwards.

### Item 4 — G-MAP-01 reinstated

The button had been **REMOVED, not mis-wired** - it carried `kind:'framework'` and
silently created a framework, so it was taken out with a comment saying it stays
gone until M-03 exists. **M-03 now exists**, so it returns as **"Map Role
Requirements"** → `role-map` → `/competency/role-map`.

**The type system caught an incomplete change:** adding a `QuickCreateKind` broke
`Record<QuickCreateKind, …>` until `'role-map'` was given an entry. It gets an
**empty** status list - a role mapping is a requirement, not a record with a
lifecycle - **empty rather than invented**.

`CREATE_ENDPOINTS.competency` now carries a warning that it writes a flat skill
row (G-RBAC-02b).

Test rows removed.

**hp_erp 4 files · g2gv0 2 files** (R18d).


## D-040 · Slice 1, items 5-7 and 9 — the chain closes

### The upsert question, answered from the code: IT DID NOT HANDLE REMOVAL

`store()` looped the payload calling `updateOrInsert` and **never touched rows
absent from it**. A competency dropped from a role's list would have **survived
forever**, and every later gap would have included a requirement nobody asked for.

**Fixed in item 3, not discovered in item 7.** `store()` is now a **SYNC**: rows
absent from the payload are removed, scoped to that role and tenant, never wider.
`removed` is returned in the response - **a silent deletion is worse than none.**

### Item 5 — `competency_kasba_rating`

Per KASBA item, per employee. **Tenant column from the start** (G-DATA-08): this
is measurement data and it decides readiness.

**No row means UNMEASURED.** There is deliberately no "not measured" value -
absence is the state, because **0 is a score and absence is not**.

### Item 6 — `ProficiencyService`, THE ONE NAMED ROLL-UP

**Verified, not assumed.** Only two other files mention the KASBA tables and
**both mentions are comments**. Nothing else reads ratings; nothing else averages.

**Unmeasured items are EXCLUDED from the weighted average** - averaging them as
zero understates, treating them as met overstates. The service returns
`measured_weight`, `total_weight` and `coverage` alongside the level, because
**a level without its coverage is not interpretable**. Nothing measured returns
**level NULL, not 0**.

### Item 7 — the gap, TWO NUMBERS

1. the weighted roll-up; **2.** the list of **mandatory ITEMS** below required.
An average of 3.4 against a required 3 reads as met while an item inside it sits
at 1 - **one number would lose that.**

Three states kept apart: `met`, `gap`, **`unmeasured`**. Unmeasured is **not a
gap** (asserting a shortfall nobody measured) and **not met** (asserting a pass
nobody earned).

### Item 9 — THE PROOF, run end to end

```
employee 2, job role 15 = "Finance Manager"
1. define competency        HTTP 201
2. map to role (required 3) HTTP 201  written=1 removed=0
3. gap BEFORE rating        state=unmeasured  level=NULL  gap=NULL  coverage=0
   unmeasured count         1 of 1
4. rate the SKILL item at 1 (other two items stay UNRATED)
5. gap AFTER rating         required=3  measured=1  GAP=2  state=gap
   coverage=0.5            <- the level speaks for half the competency
   mandatory items below required: 1  (skill rated 1, required 3)
6. RENAMED the job role to "Finance Manager — RENAMED"
   (a) mapping holds         YES
   (b) gap still computes    YES  required=3 measured=1 gap=2
   (c) rating still resolves YES
```

**All three legs, not just the mapping.** Name restored, test rows removed.

### A defect found by running it

`CompetencyGapController` first resolved the employee's role from
**`s_user_jobrole_map` - a table that does not exist.** The real link is
`tbluser.allocated_standards` (287 of 387 populated). **Caught by executing, not
by review.**

**8 files** (R18d): 1 migration + 3 app + 1 evidence + routes + 2 docs.


## D-041 - C-SEP-01: the cross-write removed, and a cross-product READ LEAK closed

**Both directions in one change.** Write: `LmsGovernanceController::audit()` emits
a `governance.{entity}.{action}` event instead of inserting into
`hpbrain_audit_logs`; `AuditLogProjector` writes the projection. Read: all four
sites moved to `g2g_audit_log`.

**The more serious half was the READ.** A G2G tenant-1 admin was shown **141 HP
Brain rows** (G-XPROD-01) - `scopeAuditToTenant` matched `t{id}`, correct within
G2G and meaningless across products.

**The cross-write had never fired**: zero overlap between what G2G writes
(`user`, `role`, `permission_matrix`) and what is stored (`Person`, `Department`,
`Organization`, `Capability`, `Authorization`).

**Verified:** `hpbrain_audit_logs` **342 before, 342 after**. Audit tab returns 1
G2G row and **0 HP Brain rows**. Filters derive from G2G events only.

**`hpbrain_*` untouched - not copied, not cleaned, not deleted.** C-SEP-02 stays
flagged, not scheduled.

**4 files** (R18d): 1 controller + 3 docs.


## D-042 - the skill library says Skill (labels only)

**13 user-facing strings** across `cm-competency-library.tsx` and
`cm-command-center.tsx`. The screen has always created a flat skill row in
`s_users_skills`; the labels said "Competency" (G-RBAC-02b). Now the product has
both concepts, so the distinction is worth showing a user.

**LABELS AND VOCABULARY ONLY.** No identifier, storage key, endpoint, type name or
behaviour moved - **renaming `SAVED_VIEWS_KEY` would silently discard every saved
view a user already has.** An explanatory note was added at the top of the library
screen so the next reader knows why the vocabulary and the identifiers differ.

**Deliberately NOT renamed:** "Competency Command Center" (`:385`) - that heading
names the MODULE, which really is competency management.

**A miss worth recording:** my extraction pattern matched quoted strings and
`>text<`, and **missed a JSX label at `:960`** that had neither. Caught by a
broader grep afterwards, then a third pass found two more in the command centre.
**Three passes on a change I had called one-pass** - the proxy was narrower than
the property.

**2 files** (R18d).


## D-043 - Slice 1b item 8: the employee's own capability view

`services/competency/gap.ts` + `components/domain/competency/cm-my-capability.tsx`.
Menu 156, re-granted in 4b.

### The security property, verified with a COLLEAGUE'S id

| Employee 2 asks for | Result |
|---|---|
| their OWN gap | **200** |
| **a colleague's gap** | **403** - *"You may only access your own competency profile."* |
| a stranger's id | **404** |

Verified through the real request path, not from the UI. **The screen does not
police this; the server does.**

### Unmeasured is neither zero nor pass - and it matters most here

A person reading their own record **must never see "0" and take it for a failing
score when it means nobody has assessed them.**

Unmeasured rows render **"Not yet assessed"** - no number, no bar, no red, and a
neutral slate rather than amber. The coverage column shows an em-dash rather than
0%. Only a MEASURED shortfall is amber.

### Coverage travels with the level

Coverage is **a column, not a footnote**. A level is never shown without how much
of the competency's weight it speaks for - a 4 measured on 20% of a competency is
not a 4.

### Tone, since coverage is 2.7% today

Most employees will see mostly unmeasured, so the banner states it plainly and
neutrally: *"N of M competencies have not been assessed yet. That is not a score
of zero and not a shortfall."* The empty state says the requirements are not set
up **rather than implying the person is lacking**.

### The second number is listed, not folded in

`mandatory_below_required` renders as its own section: an average can sit above
the bar while an item inside it does not.

**No arithmetic in the client.** Every number comes from the API, which gets it
from ProficiencyService - still the one named roll-up.

**2 files** (R18d), frontend only.


## D-044 - the orphan re-import, applied, then resolved

**One track, as one defect.** An import had created RELATIONSHIPS without the
tenant's own CANONICAL COPIES; this creates those copies **from the library row**,
never from the orphan text. **Nothing deleted, no delete path in the script.**

### Step 1 - canonical rows created, against prediction

| Table | Predicted | Created | |
|---|---:|---:|---|
| `s_user_jobrole` | 119 | **119** | exact |
| `s_users_skills` | 1,195 | **1,195** | exact (39 + 1,156 across two runs) |

**No divergence.** The first run was cut short by my own `timeout 170`; the script
skips rows that already exist, so re-running completed it and the second pass
reported `CREATED 0` for job roles - **idempotent, as designed**.

### Step 2 - the backfill pass. LINK RESOLUTION before and after

| Column | Before | After | |
|---|---:|---:|---:|
| `s_user_skill_jobrole.jobrole_id` | 77,630 (97.9%) | **79,294** | **100.0%** |
| `s_user_skill_jobrole.skill_id` | 69,238 (87.3%) | **79,294** | **100.0%** |
| `s_user_jobrole_task.jobrole_id` | 82,813 (96.7%) | **85,662** | **100.0%** |

**14,569 of 14,572 orphans resolved.**

> **THIS MOVES LINK RESOLUTION, NOT CAPABILITY COVERAGE.** They are different
> measures: link resolution is text-to-key integrity; capability coverage (2.7%)
> is how much of the workforce has been ASSESSED. This created skills and job
> roles, **not ratings**, so 2.7% is unchanged. Neither number may be quoted for
> the other.

### Step 3 - what remains, and why. THREE ROWS

| Row | Reason |
|---|---|
| `s_user_skill_jobrole` id 62338, tenant 1 | `jobrole` is **NULL** |
| `s_user_skill_jobrole` id 62338, tenant 1 | `skill = "1"` - a stray digit, in no library |
| `s_user_jobrole_task` id 74104, tenant 3 | `jobrole` is **NULL** |

**Two are NULL and one is the string "1".** None names anything a library could
supply, so none was created and none was guessed. **All three remain, text intact,
ids NULL** - the honest record that they name nothing.

### Step 4 - spot-checks

**Five new canonical rows against their library source:** all **EXACT**, and each
carried the library's own attributes (`skill_code` ACC-TAX-3002-1.1, sector
"Human Resource"), which is the evidence they were copied from the library rather
than fabricated from the orphan string.

**Five newly resolved links:** all **IDENTICAL text-to-canonical, same tenant on
both sides** (5/5, 11/11, 2/2, 9/9, 10/10).

**Whole-population integrity, not a sample: cross-tenant foreign keys 0 / 0 / 0.**

### One inefficiency worth recording

The apply ran at ~1.4 rows/sec because it calls `DESCRIBE` **inside the row
loop**. It completed correctly; it should be hoisted before this script is used at
larger scale. Same class as the `BINARY`-in-the-ON-clause defect: correct output,
avoidable cost.

**2 files** (R18d): 1 evidence script + backup, plus this log.


## D-045 - the shape of what is left, and two corrections of my own numbers

**Nothing built. Analysis and housekeeping only.**

### CORRECTION 1: the plan has 59 rows, not ~45

My "~40 -> ~45" applied +5 to a figure I had never verified. **59 rows, 16 done,
43 remaining.** (R19, mine.)

### CORRECTION 2: L-11 is 12 join sites, not 45

I reported "45 code sites still join by text". **That counted REFERENCES to the
tables, not JOINS.** Validated against a known positive
(`CompetencyDashboardController:871`) before recounting, per R16's extension.

**The real set - 12 name-to-name joins across 6 files:**

| File | Sites |
|---|---|
| `Api/CompetencyDashboard/CompetencyDashboardController` | 218, 397, 857, 871 |
| `AJAXController` | 834, 1095, 1099 |
| `Api/jobrolecontroller` | 56, 91 |
| `Api/signup_api/SchoolSetupController` | 316, 373 |
| `Services/Competency/CommandCenterService` | 72 (`whereColumn`) |

**12 is a materially different item from 45** - M rather than L - and the
correction is in the plan.

### THE FINDING THAT REORDERS THE QUEUE

**100% link resolution is NOT G-DATA-06 closed.** The backfill made a rename
**survivable, not safe**: the data resolves by key, the queries do not. Slice 1's
rename proof was real but **tested the path Slice 1 built** - the legacy sites
were never in it.

**L-11 goes before any new connection item**, because a connection built on a
text-joined query is a connection that breaks on rename.

### Six stale plan rows fixed, and R18c extended

F-06, F-07b, X-01, X-04, X-05, M-03 all read "Not started" for shipped work.
**Third instance of one pattern**, so `08-connection-plan.md` now joins the
queue-log reconciliation as a third number.

### Section 5 decisions

| Item | Decision |
|---|---|
| **F-08 `portal_identity`** | **DROPPED** - no consumer; Q-D4 deferred the candidate portal deliberately. Fails the named-consumer test |
| **L-06** | **REFRAMED to "show what depends on this"** - under the no-deletion rule an impact count is MORE valuable: it shows what a deletion would break WITHOUT deleting. Block-don't-cascade stays |
| **S-05** | **RESTATED, not retired.** C37 is definable from three places in the record: hand-verify TEN of C34's 114 candidates by data sensitivity, then calibrate or close as a proven negative. NINE remain |

**3 files** (R18d).


## D-046 - the regression suite, and its BASELINE

`_evidence/phase3-smoke.php`. One command, **21 checks, 88 seconds**, PASS/FAIL
per check and one verdict.

### BASELINE 2026-08-10: **21 PASS, 0 FAIL, 0 SKIPPED - GREEN**

Nothing has regressed across 44 shipped items. **That is the answer to the
question that prompted it**, and it was not knowable before this ran.

| Group | Checks |
|---|---|
| SECURITY | G-SEC-23 not disclosing · G-SEC-15 401 + bounded · G-LMS-SEC-01 401 · G-XPROD-01 zero HP Brain rows |
| PERMISSIONS | nine roles non-empty · Administrator 1->8->23 · Employee **18** leaves |
| EVENT STORE | emit->project->re-project idempotent · **reactor THROWS on replay** · catalogue invariants |
| DATA | link resolution 100/100/100 · cross-tenant fks 0/0/0 · `hpbrain_audit_logs` **342, untouched** |
| SLICE 1 | colleague's gap **403** · full chain required 3 / measured 1 / gap 2, **surviving a rename** |
| STATIC | `php -l` across 29 files |

### WHAT THE SUITE DOES NOT COVER - stated, not implied

- **C23's full read half (912 routes) is NOT in it.** It takes far longer than the
  10-minute budget. It remains a separate command and **the suite does not stand
  in for it.**
- **G-SEC-23 covers 2 of its 3 routes** - `user-signup` and `feedback`.
  `competency/audit/user-actions` is not checked.
- **The anonymous probe covers 2 routes, not the 4** previously reachable
  (`api/kpis`, `DeepSeekChat`, `api/candidate`,
  `api/ai-generated-assessment/question/index` are unchecked).
- **No frontend `tsc`** - it is a separate repo and a separate command.

**These are gaps in coverage, not passes.** Recorded so the GREEN verdict is read
for exactly what it tests.

**3 files** (R18d).


## D-047 - G-SEC-24b: the defect I wrote, and the check that now catches it

**I introduced C27's class while fixing G-SEC-24** - resolved the identity, then
read the tenant from the request five lines later. Fixed: both guarded methods
take the tenant from `$identity`.

**The static check is the real deliverable.** No method that resolves an identity
may read `sub_institute_id` or `user_profile_name` from the request. It needed two
refinements, both found by its own false positives: **`user_id` excluded** (8 of 9
hits were legitimate SUBJECT reads - G-SEC-12's own distinction) and **comments
stripped** (the 9th matched prose describing an already-fixed defect).

**Smoke: 25 checks, GREEN.**

### The wider-class scope, resolved from 29 candidates

- **2 excluded**: commented-out joins in `skillLibraryController`.
- Remaining sites still need both-sides-tenant-scoped confirmation before the
  count is a finding rather than a candidate set (R6). **Not yet done.**

**Rules added:** R18f(v) - check `git status` before any revert; R16(iii) - the
known-positive rule applies to restores, and a zero result must announce itself.

**3 files** (R18d).

## D-048 - X-06: the notification service, and the recipient that does not exist

**The first reactor with a real send.** Until this item `NotificationDispatcher`
wrote a log line, so the replay guard and the permanent ledger were correct in
principle and untested in practice. They now stand between a rebuild and a real
person's inbox.

### What shipped

| Piece | What it is |
|---|---|
| `g2g_terminology` | tenant-substitutable nouns; `sub_institute_id = 0` is the global default |
| `g2g_notification_template` | fixed wording, **no tenant column** - the schema enforces Q-F1 |
| `g2g_notification` | the inbox: one row per **(event, recipient, channel)** |
| `TerminologyService` | two-layer resolution; also serves screen labels and report headings |
| `NotificationComposer` | terminology pass, then payload pass |
| `RecipientResolver` | the named-consumer test as code - who, and found how |
| `NotificationSender` | in-app live; email built and **gated OFF** |
| `GET /api/notifications`, `GET /api/terminology` | + mark-read, + tenant override (admin/hr) |

### THE MEASUREMENT THAT SHAPED IT: **THERE IS NO EMPLOYEE -> MANAGER EDGE**

| Source | Populated |
|---|---|
| `tbluser.reporting_manager_id` | **0 of 387** |
| `tbluser.supervisor_opt` | a FLAG - 4 "Supervisor", 57 "Subordinate", **no link between them** |
| the other 15 manager-ish columns | **per-CASE, not per-person** (`talent_offboarding_cases.manager_id` 3/3, `task_management_projects.manager_id` 3/3, `s_performance_reviews.manager_id` 16/228) |

**Foundation 5 shipped `reporting_manager_id` and nothing ever filled it.** X-06 is
the first item that NEEDED it, and that is how the emptiness surfaced - the column
existing made every design review assume the relationship existed too.

**Consequence, applied rather than noted:** a recipient comes from the EVENT or
from the CASE the event references. There is no org-chart fallback because there
is no org chart.

### SIX EVENTS, NOT NINE

| Event | Recipient | Verdict |
|---|---|---|
| `task.rejected` | assignee (`task.task_allocated_to`) | **SHIP** |
| `assessment.completed` | the assessed employee | **SHIP** |
| `certification.expiring` | the holder - **39 expire within 90 days, 37 already expired** | **SHIP** |
| `development_plan.approved` | the plan owner - 25 pending approval | **SHIP** |
| `employee.offboarded` | the **case** manager (3/3 populated) | **SHIP** |
| `rights.changed` | the affected user - "report it if you did not expect it" | **SHIP** |
| `capability.flag_raised` | the employee's manager | **DEFERRED** - no such edge |
| `certification.issued` | the holder | **DEFERRED** - X-11 does not emit it yet |
| `readiness_gate.changed` | nobody | **DROPPED** - FeatureGateApplier already acts |

Recorded in `EventCatalogue::NOT_NOTIFIED` with triggers, and a new invariant
fails if a deferred event is quietly re-wired.

### THE DEFECT MY OWN PROOF CAUGHT

`NotificationComposer` substituted **payload first, terminology second** - while
its docblock argued at length for the opposite. A task titled literally
`{term:competency}` would have had its title expanded by the terminology pass.

**Harmless in that example, and the wrong shape in general: it is tenant DATA
reaching a template engine.** Fixed to terminology-then-payload, so by the time
any payload value exists in the string every pass that could interpret it has
finished.

> **The docblock was right and the code was wrong, and only running it told me
> which.** The prose I write beside a change is not evidence about the change.

Second defect in the same run: the proof's own detail string printed *"rendered as
literal text"* on **both** branches, so the FAIL line described a pass. **A detail
string that cannot be wrong is not evidence.**

### The email channel is BUILT and OFF

386 of 387 users carry a real address and `MAIL_MAILER` is live Gmail SMTP.
`G2G_NOTIFY_EMAIL=false` is the default and the smoke suite FAILS if it flips.
**Flipping it is Triz's decision to take deliberately, not one to inherit from a
default** - a backfill, a test or a replay bug would otherwise mail real people at
real companies.

### The bell was a picture of a bell

`gtg-header.tsx` and `gtg-header-base.tsx` each carried their own copy of a
notification menu that **rendered "You're all caught up" and a hardcoded "New"
badge simultaneously, with no request behind either**. Two contradictory claims,
neither measured, and dead in two places at once. Now one shared component reading
the real endpoint - and a failed fetch says so rather than reporting a connection
error as an empty inbox.

**Evidence:** `docs/phase3/_evidence/x06-notification-proof.php` - **18/18 GREEN**,
all rows cleaned up. Smoke **25 -> 29 checks, GREEN**. Frontend `tsc`: 9 errors,
**none in X-06's files** (pre-existing, in `admin-center`, `gtg-nav-visibility`,
`offboarding-service`).

## D-049 - X-12: the learning assigner, and two entry points in different health

**One class, absorbing `MandatoryLearningAssigner`.** They differed only in where
the course list came from, and two classes meant two places to get idempotency
wrong.

### THE TWO PATHS ARE NOT IN THE SAME STATE, AND THE DIFFERENCE IS MEASURED

| | ROLE -> COURSE | PLAN -> COURSE |
|---|---|---|
| key table | `course_jobrole_map` **0 rows** | `course_competency_map` **0 rows** |
| text fallback | **`sub_std_map.jobrole`: 72 of 95 courses carry a role NAME** | **none exists** |
| resolves | **73 join rows** by (name, tenant) | - |
| verdict | **WORKS TODAY, by name** | **cannot work** - `s_competency_plan_actions` has 377 rows, 377 with a `competency_id`, and **no `course_id` column at all** |

**73 resolving rows from 72 named courses** means one course name matches two job
roles. That fan-out is the text join's own argument against itself.

### THE COURSE TABLE IS CALLED `sub_std_map`

Two guesses (`lms_courses`, `courses`) returned *table missing*. Found by reading
the join in `LmsCourseController` - `->on('e.course_id', '=', 's.id')`.
**My first version of the class docblock said "almost nothing to assign" for BOTH
paths.** That was written after reading the two empty bridge tables and before
finding the course table, which no search for "course" returns. **R20 again: I had
read the empty tables and stopped there.**

### DECISIONS TAKEN AND SAID OUT LOUD

- **Text fallback is used knowingly**, tagged `source='jobrole_text'` on every row
  so it is never mistaken for the key path, and **both sides carry the tenant
  condition** - the L-11 failure mode cannot occur.
- **`skipped` is not `done`.** An empty bridge records *"no course mapped"* with
  its reason. `done` with zero assignments would make an empty table and a broken
  assigner look identical.
- **Nothing was seeded in tenant data.** The proof inserts ONE bridge row, proves
  the plan path works, and removes it. **A fabricated mapping is a lie that
  survives the demo.**

### IDEMPOTENCY - AND THE CONSTRAINT I COULD NOT HAVE

The natural key is (user, course, plan). **`lms_assignments` already holds 4 rows
that violate it** and 11 duplicate (user, course) pairs, all with
`source='competency'` - no NULL to hide behind. Enforcing it would mean editing
existing rows, **which the no-deletion rule forbids and rightly**.

So `origin_event_id` was added, NULL on all 49 existing rows, and the unique index
is `(user_id, course_id, origin_event_id)`. MySQL treats NULLs as distinct, so the
pre-existing duplicates sit outside the constraint **by construction** and the
index built without touching one of them.

> The same NULL-distinctness that made NULL the WRONG choice for
> `g2g_terminology`'s global sentinel is exactly the property wanted here.

**Guaranteed:** one assignment per (person, course, originating event).
**NOT guaranteed:** that two different events cannot assign the same course. That
is guarded by a query, **and is stated as weaker rather than described as if it
were an index.**

---

## D-050 - X-11: the certificate issuer. THE LOOP CLOSES.

`course.completed` -> certificate -> `certification.issued` -> **the holder is
told**. Proven end to end on the one real completed enrolment in the database
(tenant 3, user 6, course 103, certificate `G2G-3-10297`).

### IDEMPOTENCY ON AN IRREVERSIBLE ACT - THREE OVERLAPPING LAYERS

1. `g2g_event_delivery` - this event, this consumer, once.
2. **UNIQUE index on `lms_certificates.enrollment_id`** - added while the table was
   still empty, so X-11 got the strong constraint X-12 could not have.
3. **The certificate number and verification code are DERIVED, not random.**

Layer 3 is the one worth explaining. A random UUID makes every retry produce a NEW
number that the unique index cannot recognise as a duplicate. Deriving both from
(tenant, enrolment) means **a retry computes the same certificate and collides on
its own uniqueness** - the identifier carries the idempotency. Proven by clearing
the ledger and re-dispatching: still one certificate.

### THE ENROLMENT WINS OVER THE EVENT

An event saying `course.completed` is **not** evidence that the enrolment says so.
X-11 re-reads the enrolment and refuses if it is not `completed`. Tested with a
deliberately lying event: **0 certificates minted.**

### NO GUESSED EXPIRY

`sub_std_map.certificate_validity_months` is the right source and is populated on
**0 of 95** courses, so every certificate issued today is open-ended. **A guessed
expiry is worse than none** - `certification.expiring` would later tell a real
person to renew something that never lapsed.

### `certification.issued` CAME BACK

X-06 deferred it with the trigger *"X-11 CertificateIssuer ships"*. **The trigger
fired.** It is out of `NOT_NOTIFIED`, into `NOTIFIES`, with a resolver and a
template. **That is what writing triggers down is for**, and it is the first one in
this phase to actually fire.

---

## D-051 - G-NOTIF-02: I shipped six notifications whose action link 404s

**X-06 deferred `certification.issued` partly because "its action link would point
at a certificate screen that has not been built". THAT REASON APPLIED TO ALL SIX
EVENTS I DID SHIP, and I checked none of them.**

Every path was invented from the shape of the domain rather than read from the
router: `/tasks/{id}`, `/competency/my-capability`,
`/competency/development-plan/{id}`, `/talent/offboarding/{id}`,
`/settings/my-access`. **There is no `/competency` route at all.**

**They cannot simply be corrected.** The competency, task and talent screens are
reached through `/module/[moduleId]/[menuId]/[submenuId]`, and those ids come from
`tblmenumaster_g2g` **at runtime, per tenant**. There is no static path to
hardcode; a correct deep link needs a resolver that does not exist.

**Fixed by setting them NULL**, except `development_plan.approved`, which keeps
`/lms/training-records/assignment` - a route that genuinely exists and is genuinely
where X-12 writes. A message that says what happened is worth having; a link that
breaks is not. **A smoke check now compares every `action_path` against a route
list verified from `g2gv0/app/**/page.tsx`.**

### AND THE HARNESS FAILED R23 AGAIN, ONE ITEM LATER

The loop proof's `check()` did `$ok ? 'PASS' : 'FAIL'`. Checks opting out returned
the **string** `'SKIPPED'`, which is truthy - so *"plan path WORKS the moment the
bridge is populated"* printed **PASS** beside the detail *"no plan+course pair in
this tenant"*. **A check that never ran was counted as evidence that it
succeeded.**

Same class as X-06's both-branches detail string: **the verdict and the detail
disagreed and the verdict won.** Now three states, SKIPPED counted separately, and
a non-boolean verdict is itself a FAIL. The check was then re-scoped to search
across tenants so it actually runs - **and it passes.**

**Evidence:** `docs/phase3/_evidence/x11-x12-loop-proof.php` - **15/15 GREEN, 0
skipped**, all rows removed, `lms_assignments` back to 49 and coverage unchanged.
Smoke **29 -> 31 checks, GREEN**.
