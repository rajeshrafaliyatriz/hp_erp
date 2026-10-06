# Sprint 1b — the mobility hole

**This closes a hole I reported as fixed and had not fixed.** Sprint 1 gated the recruitment block;
its edits stopped at `routes/api.php:1646`. The `Route::prefix('mobility')` group begins at **:1734**
and was never touched. `SPRINT-1-SECURITY.md` should be read alongside this file, not instead of it.

## What was open

`POST /api/mobility/promotions` with `status:"Completed"` →
`MobilityPromotionController::completePromotionInProfile()` rewrites another employee's
`tbluser.allocated_standards` and their `org_designation` row — designation and grade.
`MobilityTransferController::completeTransferInProfile()` is the same shape against
`tbluser.department_id`. Both are one click on the live Mobility screen
(`mobility-center.tsx:1626` and `:1542`).

**No role gate. Three tables. No transaction. No tenant check on the subject.**

Proven without writing anything — an employee token was sent a deliberately invalid payload:

```
BEFORE  POST /api/mobility/promotions as employee -> 422 "The user id field is required."
```

A **422 means the request reached the validator**: the middleware let it through. A 403 would have
meant a gate stopped it. Row counts were identical before and after, so nothing was created to prove it.

## What changed

**1. Reads and writes separated.** All 10 reads stay open to the tenant — an internal job board is
meant to be browsed by the people who might apply to it. All **15 writes** now sit inside
`profile:admin,hr`.

```
AFTER   POST promotions   employee=403  admin=422 (reaches validation)
        POST transfers    employee=403  admin=422
        POST jobs         employee=403  admin=422
        POST successions  employee=403  admin=422
        GET  jobs         employee=200   ← unchanged, deliberately
        GET  overview     employee=200   ← unchanged, deliberately
```

**2. The subject is checked against the caller's tenant.** `user_id` was `required|integer` — that
proved a number was sent, nothing more. The tenant guard existed only in the `WHERE` of the profile
writes, so a foreign `user_id` produced a promotion row that updated **nobody**, then inserted an
`org_designation` row into the **caller's** tenant for a person not in it.

```
tenant-6 admin promotes user 6 (a TENANT-3 user)  ->  404 "Employee not found."
promotions 0 -> 0, org_designation(t6) 0 -> 0     ← refused before any write
tenant-6 admin promotes user 2576 (own tenant)    ->  201
```

**3. Transactions.** `Api/Mobility/*` contained **zero** `DB::transaction`. The promotion path writes
three tables and the transfer path two, both into the HR master, with no rollback. Both `store()` and
`update()` on each controller are now wrapped — the promotion row and the profile writes are one fact,
and committing some of them is worse than committing none.

**4. Department names were read without a tenant filter** (`MobilityTransferController`), so another
organisation's department name could be copied into a transfer record. Now scoped, with a 404 when the
destination department is not the caller's.

## Gates

```
route:list  1853 (unchanged — gating adds no routes)
mobility    15 writes, 15 gated · 10 reads, open to tenant
DB::transaction  0 → 2 in each of the two controllers
frontend files changed this sprint: 0 · components/ui diff empty
```

## Test rows

One `s_mobility_promotions` row (status `Pending`, user 2576) was created to prove the own-tenant path
still works, then deleted. Final counts match the starting counts exactly.

## How to demo

Sign in as the tenant-6 employee, open Mobility & Succession, and press **Complete** on a promotion —
refused. Sign in as admin and the same button works. The difference is the point: before this sprint
both worked.
