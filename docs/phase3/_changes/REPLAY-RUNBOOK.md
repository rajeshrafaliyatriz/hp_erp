# Replay runbook — the operating procedure, as run

**Source: `05-data-flow-contracts.md` §6.2.** This is the operator's copy. Where
the two differ, §6.2 is the contract and this file is wrong.

> Replay is the property that makes the event store worth having, **and the single
> most destructive command in the system** — step 1 truncates a table users are
> reading. **Written down it is routine. Undocumented it is the outage.**

---

## Is replay the right tool?

| Situation | Replay? |
|---|---|
| Projector logic was wrong; the store is correct | **Yes** — this is the case it exists for |
| A new projection needs back-filling | **Yes** — same mechanism, empty starting table |
| A projection drifted for unknown reasons | **Yes, but DIAGNOSE FIRST.** Drift means a writer bypassed the store. Replay hides the symptom; the bypass keeps writing |
| **The events themselves are wrong** | **No.** Replay faithfully reproduces bad events. Emit corrective events — the store is append-only |
| A side effect needs re-running (resend, reissue) | **No.** Replay cannot do this by construction. Use the reactor's own re-dispatch path, per subject |
| Something looks broken and nobody knows why | **No.** Replay is not a diagnostic |

## Preconditions — all four, every time

1. **The store is intact.** Record `g2g_event` row count and `max(id)` before starting. *If the store is damaged, replay is not recovery — restore is.*
2. **The projector is idempotent under a full rebuild**, proven by the dry run — **not by inspection**.
3. **The projection has no independent writer.** Truncation destroys anything the store cannot regenerate. The *justified independent writers* in §1 are **never targets**.
4. **A dated backup of the target table exists**, and its row count is in the change record.

## Who may run it

**A human, with a change record. Never a schedule, a deploy hook, or a self-healing job.**
One projection per run — failures must be attributable.

## The procedure

| Step | Action |
|---|---|
| **0 RECORD** | Open a change record: target projection, projector class, store `max(id)`, target row count, backup filename, operator, reason |
| **1 DRY RUN** | `ReplayRunner::dryRun($consumer, $storeMaxId)` — rebuilds into `<table>_shadow`. **Store untouched, live untouched, no delivery-ledger rows written** |
| **2 DIFF** | identical → proceed · differs **as intended** → proceed, paste the diff into the record · differs **unexpectedly** → **STOP** (unknown writer, or a non-deterministic projector — both worse than the bug being fixed) · **empty or short** → **STOP** (usually a filter or tenant scope silently excluding events) |
| **3 WINDOW** | Announce. Read-only on affected screens if the tenant is live — *a user reading a half-rebuilt projection sees data that never existed* |
| **4 EXECUTE** | `ReplayRunner::execute($consumer, $storeMaxId, replayMode: true)`. Explicit projector, recorded `max(id)`, replay mode ON — **three arguments, no defaults** |
| **5 VERIFY** | **a.** row count == step 1's shadow count · **b.** **reactor ledger UNCHANGED** (counted before and after — this is what catches a Reactor mistyped as a Projector) · **c.** spot-check **five actual rows by eye** (R3) |
| **6 REOPEN** | Lift read-only. Close the record with final counts |

## Rollback

**Rollback is RESTORE THE BACKUP, not "replay again."** If the projector is what
went wrong, running it a second time reproduces the same result. Restore, reopen,
then fix the projector offline.

`ReplayRunner` has **no `rollback()` method**, deliberately — offering one would
imply replay can undo itself.

**Events arriving during the window are not lost:** they are in the store, and the
delivery ledger picks them up on the next ordinary pass. The one hazard the
append-only store removes for free.

## The runner's refusals — verified, not asserted

| Attempt | Result |
|---|---|
| replay mode not passed | **REFUSED** — *"Replay mode must be ON. The runner does not default it."* |
| no recorded store `max(id)` | **REFUSED** — *"A recorded store max(id) is required."* |
| **a REACTOR as the target** | **REFUSED** — *"[notification_dispatcher] is not a registered projector."* |

---

## One naming discrepancy, reported not silently fixed

**§6.2 precondition 1 says `g2g_event_store`. The table built to §1's DDL is
`g2g_event`.** §1 is the DDL and is authoritative; §6.2's prose uses the older
name. **Flagged rather than edited** — `05` is the contract for six other items
and its wording is not mine to change without a decision.
