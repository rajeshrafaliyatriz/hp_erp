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
