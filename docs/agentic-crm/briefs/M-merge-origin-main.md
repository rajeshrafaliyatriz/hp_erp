# Track M: make the merge of `origin/main` into our branches pass, in scratch copies (brief for a continuation agent)

Read `COMMON-RULES.md` first (standing rules, how to work, report format). This brief only adds what is specific to this track. Written 4 Nov 2026 (00:10 IST) so that a replacement agent can continue from the files if a usage limit cuts this one off.

## Goal

The team keeps moving the Signals code on `origin/main`. Our branches (`Milan-2` in hp_erp, `milan1` in g2gv0) must merge with it, compile, and pass the suites, and the result must be handed to the caller as two PATCH FILES that apply cleanly on top of a real merge. You work ONLY in the two scratch worktrees below. You never touch the real working trees (`C:\Users\MILAN\Downloads\hp_erp`, `C:\Users\MILAN\Downloads\g2gv0`): another track (X) is editing them and runs the dev servers.

## Where things are (verified by the caller, 4 Nov 00:05)

| what | path | state |
|---|---|---|
| hp_erp scratch worktree | `D:\dev\merge-trial\hp_erp`, branch `merge-trial-3nov` | = `Milan-2` (53f79cb7) + a merge commit of `origin/main` (one conflict in `OpportunityController.php`, resolved by keeping both sides) + UNCOMMITTED: two reads made tolerant (`$row->region_scope ?? null`, `$row->icp_version ?? null`) |
| g2gv0 scratch worktree | `D:\dev\merge-trial\g2gv0`, branch `merge-trial-3nov` | = `milan1` (5f37ca9) + a merge commit (one conflict in `hooks/content-map-crm.ts`: kept our Signal Review routes, took the team's `signal-inbox` mapping, removed the unused `SignalInboxView` import) + UNCOMMITTED adaptation to the sub-product ICP rule (see below). `node_modules` is a junction to the real one (read-only use). |
| pristine `origin/main` worktree | `D:\dev\merge-trial\origin-main` (detached at 0126241a) | its own `vendor` copy; a FULL phpunit run is in progress, output `C:\Users\MILAN\AppData\Local\Temp\claude\c--Users-MILAN-Downloads-hp-erp\7e204d2f-9146-4ff7-82c9-07d5c31d4568\scratchpad\origin-main-full.txt` (ends with a line `exit=N` when done). This is the TEAM'S OWN BASELINE: a test that fails there is not ours. |
| outputs | `D:\dev\merge-trial\out\` (create it) | you write the two patches and `REPORT.md` here |

Traps already paid for (do not rediscover):
- `vendor` in each hp_erp worktree is a real COPY, not a junction: composer's autoloader resolves paths with `__DIR__`, which follows a junction to the main tree and would load the WRONG app code.
- No `.env` is copied (secrets): `ExampleTest` fails only for "No application encryption key". Ignore it, say so.
- `phpunit --filter '/(A|B)/'` with an alternation finds nothing here: pass test FILES, or a single class name per `--filter`.
- Git Bash on Windows: quote `cmd.exe` arguments carefully; use the PowerShell tool for robocopy or junction work. Never `rm -rf` a junction (it follows and deletes the target).
- Run ONE phpunit process per tree at a time. The two hp_erp worktrees have separate `storage/` so they may run side by side, but each full suite takes 8 to 12 minutes.
- Back up before any mutation run; a mutated file must be restored byte for byte.

## What the caller already found

1. The only git conflicts: `OpportunityController.php` (hp_erp), `hooks/content-map-crm.ts` (g2gv0). Both resolved in the scratch trees.
2. After the merge the FULL backend suite in the scratch tree ran 2,211 tests: 38 failures and 1 error. After the two tolerant reads, the eleven failing files show 18 failures (list below). `origin/main` ITSELF fails 8 tests in five of those files with the same `Undefined property: stdClass::$region_scope` (its own tests build an older schema than its own presenter reads), so some of the 38 are the team's, not ours. Your first job is to separate them with evidence (the baseline run).
3. The 18 that remain in the merged tree after the tolerant reads (tests/Feature): `SignalFeedFiltersTest::test_a_request_without_the_new_parameters_keeps_its_order_total_and_keys` (response now has more keys: probably the expected list must grow, check why), `SignalsDemoFixtureTest` (6 x HTTP 500), `RunCostTest` (4: one says "ScholarClone has no sub-products yet: add one in the Product Portfolio and give it an Individual ICP before discovery can run": the team's new rule, our test fixture must follow it), `ResearchDateHistoryTest` (2 x 500), `ResearchRunProgressTest` (1 x 500), `ProductDrivenDiscoveryTest` (2 x 500), `IcpPackResearchTest` (14 searches where at most 8: a KNOWN older failure), `IcpProfileApiTest` (201 expected, 422 received: probably the team's ICP refactor). `ScholarCloneIcpProposalTest` is also a known older failure.
4. Frontend: the merged g2gv0 compiled with 2 new type errors because the team removed `icpHref` from `lib/product-selection.ts` (the product-level ICP tab is gone: an ICP now belongs to a SUB-PRODUCT; `PRODUCT_TABS` is now `['overview','offers']`). The caller adapted our code in the scratch tree (uncommitted): `signal-generate-dialog.tsx` and `product-signals-icp-tab.tsx` use `offersHref`; a product LINE's ICP column says "N of M" sub-products with an active ICP (`lineIcpSummary` in `product-lines-model.ts`, used in `product-lines-view.tsx`); two of Y's tests and one of the Generate dialog's were updated, new tests added. After that: `tsc` shows only the known errors (`account-360.tsx` 7 = the old committed version that track X rewrites, `crm-ai-assistant.tsx` 4, `opportunity-ai-coach-tab.tsx` 2) and `lib/product-master`, `lib/signal-review`, `lib/signals-screen` tests are 597 of 597 green. NOT yet run on the adapted tree: the whole `npm test` (before the adaptation: 1,740 tests, 4 failures, all from `icpHref`).

## Your tasks, in order

1. **Baseline.** Wait for `origin-main-full.txt` to end with `exit=`. Make a table of every failing test of the pristine `origin/main` run (class, test, first line of the reason).
2. **Classify** every failure of the merged hp_erp tree against that table: (a) environment only, (b) fails on `origin/main` too (the team's; leave alone, list), (c) ours (introduced by our code meeting theirs). For (c) find the cause in the code, not only in the test.
3. **Fix (c)** in the scratch tree. Prefer making production code tolerant (`?? null` on a column an older schema lacks, the repo's rule "hosts differ") over weakening a test. Where a test of ours pins behaviour the team's new rule changed (the sub-product ICP rule), update the fixture or expectation and say why in a comment. A fixed test must still be able to fail: for each fix, break the production line once and show the test fails, then restore.
4. **Contracts beyond tests.** Run `php artisan route:list --path=api/signals` (or the whole list) in the scratch tree and check no route is declared twice after the auto-merge of `routes/api.php`; check `config/signals.php` has no duplicate key; run `PhpFilesParseTest` and `NoByteOrderMarkTest`.
5. **Full runs.** hp_erp scratch: the FULL phpunit suite once at the end; compare with the baseline: the only differences allowed are tests that fail on both. g2gv0 scratch: `node --import tsx --test "lib/**/*.test.ts" "lib/**/*.test.tsx" "packages/**/*.test.ts"` and `node node_modules/typescript/bin/tsc --noEmit -p .` and eslint on the files changed. Do not edit files outside the scope of the merge adaptation.
6. **Commit** in the scratch branches (small commits with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`), then write the patches: in `D:\dev\merge-trial\hp_erp` `git diff <merge commit> HEAD > D:\dev\merge-trial\out\backend-merge-fix.patch`; in `D:\dev\merge-trial\g2gv0` the same to `frontend-merge-fix.patch` (the diff of everything after the merge commit). Prove each patch applies: `git apply --check` against a clean checkout of the merge commit.
7. **Report** to `D:\dev\merge-trial\out\REPORT.md` and in your final message: the classification table; what you fixed and the line that proves each fix can fail; the final suite numbers of both trees against the baseline; anything that needs a human decision (for example a product-line ICP behaviour you think is wrong); what you could not verify.

## Limits

No push, no commit in the real repos, no database connection to dev or live (tests use in-memory sqlite; do not run `artisan migrate`, `agent:*` or any command that opens the dev database), no network calls except reading the repo. Write progress to `REPORT.md` as you go: a usage limit may cut you off and a replacement must be able to continue from it.
