# Two presses of "Run agent" at once cannot both start a run: the row lock, checked on the real MariaDB

- Why: the review found a gap between "is a run under way?" and the insert of the run (two presses at once could both pass). `OrgAgentService::run()` now does the checks and the insert in ONE transaction that locks the identity's row (`->lockForUpdate()`, `OrgAgentService.php:55`). sqlite, which the PHPUnit tests run on, has no row locks, so the tests prove the checks and the transaction but NOT the lock. This is the check of the lock itself.
- Where and when: the dev database (default connection `mysql`, host 202.47.117.220, MariaDB 10.11), 3 Nov 2026, about 21:42 IST. Dev only; no model was called; nothing was sent.
- Scripts (outside the repo, in the session scratchpad): `lock_holder.php` and `lock_contender.php`, run as two PHP processes at the same time.

## How

1. Process A takes the lock exactly as the service does (`OrgAccount::query()->where('sub_institute_id', 6)->lockForUpdate()->find(1)` inside a transaction), keeps it for 6 seconds, inserts a stand-in `running` run for identity 1 and commits.
2. Process B, started while A holds the lock, calls the REAL `OrgAgentService::run(6, 28, 1, 'cli')`.
3. With the lock, B must wait for A, then see A's running run and be refused `already_running` before any model call. Without the lock B would not wait, would find nothing running, and would start a second run (and call the model).

## Result (verbatim)

```
--- A
A: lock taken after 1.92 s on identity 1 (mysql)
A: inserted the running run 2 after 8.54 s
A: committed after 9.05 s
--- B
B: refused [already_running] after 5.48 s waiting: The agent is already working on this organisation. Wait for it to finish.
B: runs of the identity before 1, after 2
```

B waited 5.48 s on the lock (A held it for 6 s after B's start), then was refused `already_running`; it created no run (the 1 to 2 is A's stand-in). The lock holds on MariaDB for the exact query shape the service uses.

## Cleanup

The stand-in run (id 2) was deleted by id and by what the probe wrote (status running, trigger cli, 0 tool calls, no brief, no provider): `deleted rows: 1`. Afterwards the dev agent tables hold only the real run: 1 run (id 1, completed, INR 0.0750), 1 proposal, 4 tool calls.

## What this does not prove

A race inside the few microseconds between two requests' reads is not forced here: the holder simulated "the other request got the lock first". That is exactly the situation the lock exists for. The exactly-once CLAIM of a proposal (two accepts at once) is a different mechanism (a conditional UPDATE) and is tested by `AgentApplierClaimTest`.
