# S15 P1-B backend: link makers retargeted, PHP guard added (13 Nov 2026, hp_erp, branch Milan-2, dev only)

All claims below are tagged. Commands were run from `C:\Users\MILAN\Downloads\hp_erp`, one phpunit process at a time, in-memory sqlite (the suite cannot touch a database).

## What changed (verified, `git status`)
- `app/Domain/Ask/AskSignals.php:159` and `app/Domain/AgentGateway/OrgAgentContextBuilder.php:328`: the signal link is now `/signals?status=all&q=` + `rawurlencode(mb_substr($title, 0, 80))` (same encoding as before; only the path changed).
- `tests/Feature/AskToolsTest.php:514`, `AgentGatewayTest.php:117`, `AgentRunServiceTest.php:89`: re-pinned. The old assertions were prefix matches (`assertStringStartsWith`); the new ones are `assertSame` on the whole address, `'/signals?status=all&q=' . rawurlencode('<the signal title>')`, so a wrong encoding or a wrong path also fails.
- New guard `tests/Unit/SignalReviewAddressGoneTest.php` (5 tests, 33 assertions): reads every file of `app`, `config`, `routes`, `database/seeders`, `resources` (about 1,700 files) and fails on any mention of `/signal-review` or `/module/crm/signal-review` (as a path: `services/signal-review/` and `/signal-review-list` are not the address). The only allowance is ONE line of ONE file: `private const MENU_LINK = '/signal-review';` in `app/Console/Commands/SignalsGrantReviewCommand.php` (the menu row 506 keeps `status 1` and is found by `access_link`, never by id). Migrations and tests are not scanned (a migration names the retired menu row by its access_link on purpose).

## Results (verified)
| command | result |
|---|---|
| `php vendor/bin/phpunit --colors=never tests/Feature/AskToolsTest.php` | OK (41 tests, 401 assertions) |
| `... tests/Feature/AgentGatewayTest.php` | OK (24 tests, 197 assertions) |
| `... tests/Feature/AgentRunServiceTest.php` | OK (25 tests, 222 assertions) |
| `... tests/Unit/SignalReviewAddressGoneTest.php` | OK (5 tests, 33 assertions) |

## Mutation proofs (verified). Each: copy taken first, file broken, named test fails, copy put back, `cmp` silent, md5 equal, baseline green again
| # | mutation | tests that failed |
|---|---|---|
| P1 | `AskSignals.php:159` back to `/module/crm/signal-review?status=all&q=` | `AskToolsTest::test_list_signals_filters_by_product_status_and_dates_and_a_dismissal_carries_its_reason` (expected `/signals?status=all&q=Alpha%20hires%20a%20principal`, got the old address) and the guard `SignalReviewAddressGoneTest::test_nothing_the_back_end_writes_points_at_the_retired_screen` (names `AskSignals.php:159`) |
| P2 | `OrgAgentContextBuilder.php:328` back to the old address | `AgentGatewayTest::test_the_evidence_stored_is_the_contexts_own_label_and_address_not_the_models`, `AgentRunServiceTest::test_a_run_keeps_the_brief_applies_the_note_and_the_task_alone_as_the_user_and_records_the_cost_in_rupees`, and the guard |
| P3 | a second line `private const LINK_FOR_PEOPLE = '/signal-review?status=all';` added to the ALLOW-LISTED `SignalsGrantReviewCommand.php` | the guard (names `SignalsGrantReviewCommand.php:28`): the allowance is the one menu-row line, not the file |
| P4 | the guard's own detector weakened (the `/module/crm` way in removed) | the guard's self-tests: `test_the_two_old_addresses_are_seen_and_lookalikes_are_not`, `test_the_allowance_is_one_line_of_one_file_not_the_file`, `test_the_check_does_see_a_file_that_names_the_old_address` |

md5 after restore (equal to before): AskSignals.php `34d572cafe1514e8c9cebeb56cd4b06a`, OrgAgentContextBuilder.php `1044b71d8f0311596c0c29c5fb3c6626`, SignalsGrantReviewCommand.php `165efd550b9408ff88fa2d4f6d76f7fa`, SignalReviewAddressGoneTest.php `5617f765a2c914fb538fd585775c6239`.

## Left on purpose (verified by the grep census, `p1b-census-*`)
- `app/Console/Commands/SignalsGrantReviewCommand.php:27` (the menu row's access_link; allowed by the guard on that one line).
- The three migrations that name the menu row by access_link: `2026_12_08_090300_create_signal_review_menu_row.php`, `2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root.php`, `2026_12_11_170000_reorder_crm_lifecycle_sidebar_and_cleanup_icons.php`. History; the brief says no migration changes.
- `tests/Feature/SignalReviewMenuTest.php` and `SignalReviewMenuMoveTest.php` (they pin the menu row `/signal-review` and must stay green untouched).
- Past Ask answers and agent runs keep the OLD link inside stored rows. Nothing rewrites them; the front end redirects `/module/crm/signal-review` and `/signal-review` to `/signals` for ever. *read in source*: an Ask message keeps its citations, each with its `url`, as stored JSON that `AskConversations.php:78-82` reads back unchanged; an agent proposal's `evidence` (`Gateway.php:166`) and a run's `brief_evidence` (`AgentPresenter.php:161-165`) are stored JSON with the url too. Not checked against dev rows (no rows were queried in this track).

## Not verified
- No HTTP-level check of any link (no server may be started in this track); the redirect pages are tested as functions in the front-end evidence file.
