# S7 (thin) backend: the tests that were run

- Produced by: PHPUnit 11.5.55 on PHP 8.2.12, in-memory sqlite (it cannot touch the dev database), run from `C:\Users\MILAN\Downloads\hp_erp` (branch `Milan-2`), 3 Nov 2026, 20:34 to 22:40 IST. One phpunit process at a time. The full suite was NOT run (the caller runs it once per slice).

## The runs, in order

| when | command | result |
|---|---|---|
| 20:34 (baseline, as inherited) | the four agent files (`AgentContextTest`, `AgentGatewayTest`, `AgentRunServiceTest`, `AgentGatewayApiTest`) | 70 tests, 618 assertions, **4 failures** (all `AgentGatewayApiTest`) |
| 20:35 (baseline) | `tests/Unit/OrganisationReferencesScanTest.php` | 5 tests, **1 failure** (`g2g_agent_proposals.entity_type`) |
| 21:05 | `AgentGatewayApiTest` after the fixes | 17 tests OK |
| 21:10 | the five agent files with `AgentSubjectsTest` | 78 tests, 704 assertions OK |
| 21:20 | `OrganisationReferencesScanTest`, `OrganisationMergeTest`, `OrganisationMergePropertyTest` | 20 tests, 71,721 assertions OK |
| 22:38 (FINAL) | the 17 files below | **206 tests, 73,378 assertions, OK, 1 min 52 s** |

The final command:

```
php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/AgentContextTest.php tests/Feature/AgentGatewayTest.php tests/Feature/AgentRunServiceTest.php tests/Feature/AgentGatewayApiTest.php tests/Feature/AgentGatewayTenantSweepTest.php tests/Feature/AgentMigrationsTest.php tests/Feature/AgentSubjectsTest.php tests/Feature/AgentApplierClaimTest.php tests/Feature/AgentOrganisationRunCommandTest.php tests/Feature/CrmRecordsTest.php tests/Unit/OrganisationReferencesScanTest.php tests/Feature/OrganisationMergeTest.php tests/Feature/OrganisationMergePropertyTest.php tests/Unit/PhpFilesParseTest.php tests/Unit/NoByteOrderMarkTest.php tests/Unit/Gtm/GtmEventsTest.php tests/Feature/SignalReviewApiTest.php
OK (206 tests, 73378 assertions)
```

## This track's ten files (125 tests)

| file | tests | covers |
|---|---|---|
| `AgentContextTest` | 12 | what the agent may know: every fact a ref; nothing of another organisation even through a pointer; bounded; demo signals hidden; untrusted text stays on its own JSON line; the Organisation 360 keys the builder relies on are named; a thin 360 thins a fact instead of failing |
| `AgentGatewayTest` | 23 | the gateway: evidence refs, the three-tool registry (no send, email, sms, delete, merge), the hostile-signal test, target and steering fields, plain-text rules, caps, never-twice, the policy matrix, the kill switch, the autonomy fail-closed rules, the events in the catalogue |
| `AgentRunServiceTest` | 25 | a run end to end: brief, alone actions as the user, cost in rupees, unavailable and unpriced models, the daily cap, one run at a time and one transaction, accept applies once, dismiss never re-offers, a refusal after the fact (kill switch, one action switched off), a calendar refusal and an unexpected failure do not take the run down, `confirm_link` with its guards |
| `AgentGatewayApiTest` | 18 | the API: every route needs a token; the rights matrix (each role opens exactly its own routes, with proposals of their own per user); the panel's state; run, refusals before a run; accept and dismiss; the runs list with filters and paging; the key lists the screens depend on |
| `AgentGatewayTenantSweepTest` | 7 | cross-tenant: every id route (read from the router) answers another organisation's identity, proposal or run with the same 404 and changes nothing and never reaches the model; the caller cannot choose the organisation; the runs list; rows of another organisation that point into this one's ids show nothing of themselves; the CRM lookup; token, screen right, edit for writes and numeric ids on every route |
| `AgentMigrationsTest` | 12 | the five migrations: up, down, twice; columns and keys; the raw MariaDB DDL and the Blueprint branch describe the same columns and keys; "never offered twice" is a unique key; `created_via` keeps every existing note a person's |
| `AgentSubjectsTest` | 8 | runs and proposals follow a merged identity (the class, the agent's own `confirm_link`, a CRM merge through the real `OrganisationMerger`) |
| `AgentApplierClaimTest` | 2 | two accepts at once write once (the other request wins between this one's read and its claim) |
| `AgentOrganisationRunCommandTest` | 7 | the command: acts for the named person, owner or first administrator; refuses `live`; refuses what it cannot resolve; unavailable model; refusal before a run; restores the default connection |
| `CrmRecordsTest` | 11 | `RecordNote` and `RecordTask` on their own: another organisation's, a deleted and a missing record are the same not-found; text, dates, the duplicate guard, the calendar refusing |

## Not mine, not run

`ScholarCloneIcpProposalTest`, `IcpPackResearchTest` and `ProductDrivenDiscoveryTest` are known older failures of other work and were not run. The full suite is for the caller.
