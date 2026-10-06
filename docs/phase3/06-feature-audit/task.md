# Module write-up 4 — **TASK** (2 sub-modules)

**C18 format.** Sweep hits · hand-read of the primary controller · C35 payload
checklist (both directions) · §5.1 reconciliation · CONNECTIONS TO BUILD.

**Status:** `Analysis Done`. No code changed by this document.

Sub-modules: Dashboard / My Tasks / Projects / Dependencies / Calendar ·
Reports & Analysis + Administration.

---

## 1. Sweep hits landing in this module

| Sweep | Hits | Consequence |
|---|---|---|
| **S-1** (verified) | `s_user_jobrole_task.jobrole` — **85,662 rows, text key, no `*_id`** | **The single largest table in G-DATA-06.** Task owns the catalogue the whole capability chain reads |
| **S-6** (raw) | **`task` has 10 writing files** — the highest in the codebase | Candidate. With no shared write path, status semantics can diverge per writer |
| **C23 guard** (executed) | `taskController`, `TaskController`, `MyTasksController`, `ProjectController`, `DependencyController`, `WorkspaceController`, `BulkTaskController` | Several are `tokenable`-aware; **candidates, not findings** (R6) |

---

## 2. ⚠️ CORRECTION — G-FLOW-24 was wrong

The register states: *"`delay_category` 0 rows — **structural, not provenance**;
no code path writes it, so it would be 0 in production too."*

**A write path exists.** `MyTasksController.php:164` validates it
(`nullable|in:Dependency,Resource,Scope,Technical,Approval,External,Other`) and
`:205` writes it — **gated on the task being `ON HOLD`**:

```php
'delay_category' => $resolved['status'] === 'ON HOLD'
    ? $request->input('delay_category')
    : ($before['delay_category'] ?? null),
```

Measured:

| | |
|---|---:|
| `task` rows | 2,271 |
| `delay_category` populated | **0** |
| `delay_reason` populated | **1** |
| **Tasks ever `ON HOLD`** | **1** |

**So the correct reading is the opposite of what was recorded: it IS provenance.**
The field is 0 because **exactly one task has ever entered the only state that can
write it** — and even that one carries a reason without a category. In production,
where tasks go on hold routinely, this column would populate.

**G-FLOW-24 is reclassified from a structural defect to a never-exercised feature.**
This is the **first** finding in the phase to move in that direction — every prior
correction made things worse, not better. Recorded because a register that only
ever escalates is not being checked properly.

---

## 3. The task→competency link, measured

| | Rows | |
|---|---:|---|
| `task` | 2,271 | |
| `task.skill_id` populated | **1,514** | **66.7%** — matches `manager.md` §1.2 exactly |
| `task.observation_point` populated | 620 | 27.3% |

**The instance link is real and two-thirds populated. The catalogue link does not
exist** — `s_user_jobrole_task` has no competency column (G-LIB-03). That is the
asymmetry Q-C3 resolves with `jobrole_task_competency_map`, and it is why L-14 was
corrected from *"no task→skill column exists anywhere"* to *"the catalogue has
none; the instance has one, hand-picked and weak."*

**`task_status_history` does not exist.** Confirmed absent from both `app/` and
`database/migrations/`. It is a Gate B design item (§10 step 4) and the first
consumer of the event store — not a regression.

---

## 4. C35 checklist — payload vs validator vs insert, both directions

| Form | Files read | Verdict |
|---|---|---|
| Create task | `create-task-modal.tsx` · `taskController.php` validator · insert | ✅ The `catalogue` / `custom` split at `:505` is **carried forward as the foundation for golden thread 2** (recorded decision, 2026-08-05). No drop found |
| Status change / hold | `my-tasks-view.tsx` · `MyTasksController.php:150-210` · same | ✅ **Clean, and better than expected** — `delay_category` is validated against a closed enum *and* conditionally written. This is the pattern the rest of the codebase lacks |
| Bulk task | `BulkTaskController` | ⚠️ C23 candidate; payload not yet the issue |
| Dependency | `DependencyController` | ⚠️ C23 candidate |

**Inverse direction — a column accepted but never sent:** none found. As with LMS,
**the L-01 pattern remains Competency-specific.**

**Worth stating plainly:** `MyTasksController` is the **best-written controller
examined in this phase** — closed enum validation, conditional writes, an explicit
`$before` snapshot. When the register is full of defects it is worth recording
where the codebase is already right.

---

## 5. §5.1 — new work versus already-approved work

| Item | Verdict | Maps to |
|---|---|---|
| `jobrole_task_competency_map` | **ALREADY APPROVED** | Q-C3, §2.1, §10 step 3 |
| `s_user_jobrole_task.jobrole_id` FK | **ALREADY APPROVED** | §10 step 14 |
| `task_status_history` | **ALREADY APPROVED** | §10 step 4; `05-data-flow-contracts.md` §5 |
| Task → competency evidence on completion | **ALREADY APPROVED** | Q-B3 (never auto-lower; manager confirmation) |
| 10 writers of `task` | **NEW** — but see T-01 | S-6 |
| 7 C23 candidates | **ALREADY SCHEDULED** | part of the 46 |

**Tally: 1 new, 4 already approved.** **Third module in a row where the
substantive work was specified in Gate B.** That consistency is now itself
evidence the domain model was right.

---

## 6. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **T-01** | One write path for `task.status`, and one owner | within Task | **10 files write this table.** Status semantics diverging across writers is exactly the Command Center defect, in the module with the most writers | **M** | S-6 verification | S-6 |
| **T-02** | Surface `delay_category` where holds are managed | within Task | The mechanism is **already built and correct** — it is simply never reached, because holds are not part of the current flow. Cheapest available insight into *why* work stalls | **XS** | — | `MyTasksController.php:164,205` |

**Deliberately NOT proposed:** anything touching `task.skill_id`. The instance link
works at 66.7%; the catalogue link is Q-C3's and re-proposing it here would
double-count.

---

## 7. Status

`Analysis Done`. **2 sub-modules.** One register **correction** (G-FLOW-24
reclassified — the first to move in the reassuring direction), the task→competency
asymmetry measured, and one controller recorded as exemplary.

**Module count: 19 of 32.** Next: Talent (7) → 26, then Other (4) → 30, at which
point Gate C effectively closes and `08-connection-plan.md` is assembled from the
CONNECTIONS TO BUILD sections.
