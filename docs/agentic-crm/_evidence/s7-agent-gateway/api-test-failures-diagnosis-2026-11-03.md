# The four failures of AgentGatewayApiTest and the one of OrganisationReferencesScanTest: test bug or product bug, with proof

- Produced by: PHPUnit 11.5.55 on PHP 8.2.12, in-memory sqlite, run from `C:\Users\MILAN\Downloads\hp_erp`, 3 Nov 2026, 20:34 to 20:43 IST.
- Commands: `php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/AgentContextTest.php tests/Feature/AgentGatewayTest.php tests/Feature/AgentRunServiceTest.php tests/Feature/AgentGatewayApiTest.php` (the baseline) and `php vendor/phpunit/phpunit/phpunit --colors=never tests/Unit/OrganisationReferencesScanTest.php`.
- Baseline result: 70 tests, 618 assertions, 4 failures, all in `AgentGatewayApiTest`, 1 min 4 s. Scan test: 5 tests, 1 failure.

## 1. Rights matrix (`test_the_rights_matrix_view_edit_the_support_row_and_the_run_history_each_open_exactly_their_own_routes`): a TEST bug

```
edit without view
-    3 => 200,
+    3 => 409,
```
Cause (verified by reading the test and the controller): the matrix gave every user the SAME pending proposal. The first user who holds the edit right (`edit without view`) accepts it (200); the same user's next call is a dismiss of that now applied proposal, which the product correctly refuses with 409 `already_decided` (`ProposalDecisions::dismiss` answers `NOT_PENDING`). The expected 200 was wrong, and so would have been the support-row editor's `409, 409` (a second accept of an applied proposal answers 200 `already_applied`).
Fix: each user gets two proposals of their own (one to accept, one to dismiss) and the test asserts what became of them (applied and dismissed for the users who may decide; both still pending for everyone who may not). Result: green; 17 of 17.

## 2. `test_a_refused_request_changes_nothing`: a TEST bug

```
 'proposals' => Array &6 [
-    0 => stdClass Object #13282 (
+    0 => stdClass Object #13211 (
```
Cause: `assertSame` on two arrays of `stdClass` objects compares the objects by IDENTITY (`===`). Two queries return two different objects even when every field is equal: the diff shows the same fields and only the object numbers differ. The neighbouring `writes()` helper already cast its rows to arrays for this reason.
Fix: the state of the agent layer is compared as arrays (`agentState()`), and now also covers the runs and the tool calls. Green. It can fail: mutation P23 to P26 (rights) and the sweep catch a refused request that writes.

## 3 and 4. `test_running_the_agent_answers_with_the_run_and_the_work_it_did` and `test_the_runs_list_has_the_summary_filters_and_pages_and_only_this_organisations_runs`: a PRODUCT nit

```
 Array &0 [
+    'applied' => 2,
     'pending' => 0,
-    'applied' => 2,
```
Cause (read in source): `AgentPresenter::runSummary` wrote `$counts + ['pending' => 0, 'applied' => 0, ...]`, so the keys came in the order the database grouped them (`applied` first when a run has applied rows). JSON objects have no order, so no screen would break, but the contract is one shape and `assertSame` rightly checks it.
Fix (product): the presenter always writes `pending, applied, dismissed, failed`, in that order (mutation P41 proves the test catches the old behaviour).

## 5. `OrganisationReferencesScanTest::test_every_organisation_reference_in_the_migrations_is_registered_or_allow_listed`

```
g2g_agent_proposals.entity_type  (2026_12_10_090100_create_g2g_agent_proposals_table.php)
```
Only ONE column was flagged. It is the type column beside `entity_id`, which holds the id of the CRM organisation a note or task proposal was made for (`'organization'` plus the id). It is NOT the identity id (`subject_id` is, and its name matches no pattern). Verified in source: `ProposalApplier` never finds a record by `entity_id`; every tool re-derives its target from the subject identity when the action is applied, and `AgentPresenter` does not even output it. So a merge of CRM organisations need not move it: it records what the proposal was about when it was made.
Fix: one allow-list entry WITH that reason in `OrganisationReferencesScanTest::ALLOWED` (the brief's instruction). The scan test, `OrganisationMergeTest` and `OrganisationMergePropertyTest` pass: 20 tests, 71,721 assertions.
The question the brief also asked (what a merge does to the run and proposal rows that point at an identity that a merge removes) is answered in the slice file, "Decision: runs and proposals follow a merged identity".
