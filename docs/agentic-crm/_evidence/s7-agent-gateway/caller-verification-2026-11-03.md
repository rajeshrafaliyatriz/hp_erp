# S7 (thin): what the caller re-ran and checked, 3 Nov 2026 (22:00 to about 22:50 IST)

The track's report is a claim, not evidence (SESSION-RULES step 3: re-check earlier claims, including a subagent's, against the code or the database before building on them). Everything below was run or read by the caller, not taken from the report. Tags: **verified** = I ran or read it myself.

## 1. Tests re-run (verified)

| what | command | result |
|---|---|---|
| backend, the 17 files of the report | `php vendor/phpunit/phpunit/phpunit --colors=never <17 files>` (the command printed in `backend-tests-2026-11-03.md`) | `OK (206 tests, 73378 assertions)`, 1 min 4 s, started 22:02 |
| front end | `node --import tsx --test "lib/agent/*.test.ts" "lib/agent/*.test.tsx"` | 123 pass, 0 fail, 8 s |
| lint | `node node_modules/eslint/bin/eslint.js services/agent lib/agent components/domain/crm/{org-agent-panel,org-agent-parts,agent-run-detail,agent-runs-view}.tsx --max-warnings=0` | no findings |
| types | `node node_modules/typescript/bin/tsc --noEmit -p .` | 7 errors in 3 files (`crm-ai-assistant.tsx` 4, `opportunity-ai-coach-tab.tsx` 2, `signal-inbox-view.tsx` 1), none in the agent's files; the same files as the known baseline |

## 2. Deliberate breakages re-run (verified)

Three of the track's 62 backend breakages, with the track's own runner (`D:\dev\agentic-crm-research\scripts\mutation\s7-agent\run-mutations.cjs --only=P15,P20,P35`), 22:05 to 22:06: the baseline of each test set was green, **P15** (kill switch never on), **P20** (accept without "only if still pending") and **P35** (a task assigned to someone else) were each KILLED by the same named tests the report lists, and every mutated file was restored byte for byte.

## 3. The report's claims against the dev database (verified; read-only script, no write of any kind)

| claim | what the database says |
|---|---|
| five migrations, batches 560 to 564 | `migrations`: 090000 to 090400 are batches 560 to 564; `2026_12_10_120000` (the caller's menu move) is 565 |
| table shapes | `g2g_agent_runs` 24 columns / 1 row, `g2g_agent_proposals` 22 / 1, `g2g_agent_tool_calls` 13 / 4, `g2g_agent_autonomy` 6 / 0; `crm_organization_comments.created_via` varchar(12) NULL |
| "never offered twice" is a unique key | `g2g_ap_never_reoffer` on (tenant, subject_type, subject_id, action, payload_hash) |
| one real run | `g2g_agent_runs` id 1: tenant 6, identity 1, completed, trigger `cli`, deepseek / deepseek-flash, 1232 in / 343 out, cost 0.0750 INR, 4 tool calls |
| its cost is the metering row | `ai_usage_events` 827: `estimated_cost` 0.074995 INR, `related_type` `g2g_agent_run`, `related_id` 1 |
| one proposal applied alone | `g2g_agent_proposals` id 1: `add_task`, `applied_via` policy, `result_ref` `task_management_calendar_events:470` |
| the only record outside the agent tables | task 470: CRM organisation 135, 2026-11-17 09:00, status Planned, owner and creator user 28; 0 notes with `created_via = 'agent'` |
| events | 11057 `agent.action.applied`, 11058 `agent.run.finished`, and no other `agent.*` event |
| menu 503 rights (the report's open question) | tenant 6: profile 16 full, profiles 18 and 71 view/add/edit, profiles 69 and 72 view only: they can read every agent brief of the organisation once real runs are listed there (owner decision, open) |

## 4. The report's source claims (verified)

- No send, email, SMS, delete or merge anywhere in `app/Domain/AgentGateway`: a search for mail, HTTP, curl, notification, delete, SMS, send, dispatch and process calls matches only a comment in `ToolRegistry.php:13`.
- `AiModelClient.php:118-121`: the saved per-key ceiling wins over the caller's `max_tokens`, as the report says.
- `OrgLinker.php:166` is `$loser->delete();` (the line the report asks track X to follow with `AgentSubjects::repoint`); the route `POST /api/org-accounts/for-crm/{id}` that the front end calls exists in `routes/org_accounts.php`.
- The four shared files the track touched (`OrganisationMerger.php`, `EventCatalogue.php`, `bootstrap/app.php`, `OrganisationReferencesScanTest.php`) contain only the track's 16 added lines (diff read).
- A scan of the track's files for key-like strings (API keys, bearer tokens, private keys): none.

## 5. The real run's brief against the data (verified; read-only scripts `verify_z_brief.php` and `verify_z_cols.php`)

The brief and the proposal's reason, sentence by sentence (the run is `real-run-dev-2026-11-03.md`):

| sentence | what the rows say | verdict |
|---|---|---|
| an engaged education account | identity 1 `lifecycle = engaged`; CRM organisation 135 `industry = Education / Training` | true |
| in Ahmedabad | the signal's own text ("Vasana, Ahmedabad"); the CRM record has no city | true, from the signal |
| one contact; a CRM record; an open opportunity in Prospecting; a GTM deal in discovery | 1 live contact (a Principal); `crm_organizations` 135; `crm_opportunities` 256 `sales_stage = Prospecting`; `gtm_deals` 125 `stage = discovery` | true |
| last active 2026-10-22 | the newest entry on the 360's timeline is the signal decision of 2026-10-22 10:57; the opportunity and the GTM deal were changed on 2026-10-30 06:34 | true to the timeline, **misleading** as "last active" (F1) |
| the only signal is a dismissed one | signal 39 `review_status = Dismissed`, reviewed 2026-10-22 | true |
| no contact details | the contact HAS an email address and a phone number; the context sends only a count, so the model was not shown them | **false** (F2) |
| no deal value | `amount` is NULL on both deals | true |
| no next step recorded | `next_step` is NULL on both deals, and there are 0 notes and 0 tasks; but the context never sent `next_step` at all | true by luck (F2) |
| the proposal's reason: "no activity for ~2 weeks" | as the first row | **misleading** (F1) |

Also found in the same read: the refs stored with the run carry `/module/crm/organisation-360/1` (a page track X removed) and `/gtm/deals/125` (a page that never existed: `app/gtm/deals` has only a list page, nothing links to a deal id) (F3).

## 6. Findings and what was done (a follow-up commit; the track's own work was committed first, unchanged)

| # | finding | cause | fix | proof |
|---|---|---|---|---|
| F1 | "last active" and "no activity" were wrong or misleading | the context's `last_activity_at` was `activity[0].at` of the Organisation 360, whose timeline holds only notes, tasks (at their SCHEDULED time), signal decisions and leads: a deal change is not on it, and a task due next week would be reported as the latest thing that happened | the key is `latest_logged_entry_at` (never a future date, `none logged` when empty) with `logged_entries_are` saying what it counts; the prompt asks for "no note, task or signal decision logged since <date>", never "no activity" | `AgentContextTest::test_the_latest_logged_entry_is_never_a_future_task_and_the_organisation_says_what_it_counts`; prompt assertions in `AgentRunServiceTest` |
| F2 | an absence was claimed about things the model was never shown (contact details, next step) | the context sends a count of contacts, not their details (a privacy rule that stays), and no deal `next_step` | counts `contacts_with_email` / `contacts_with_phone` (never the values), the deal's `next_step` (and `next_step_due`), `none recorded` for an empty amount, close date or next step, `not_shown_to_you` naming what is withheld; a field the host or the 360 does not give is left OUT, never printed as `none recorded` or 0; the prompt: say something is missing only when the data says so | `AgentContextTest::test_what_is_missing_is_said_by_the_data_and_what_is_withheld_is_named_without_its_values`, `::test_a_host_that_cannot_tell_leaves_the_claim_out_instead_of_saying_none` |
| F3 | evidence addresses pointed at pages that do not exist | the builder still used the removed `organisation-360` page and invented `/gtm/deals/{id}` | `org_account` and `link_suggestion` carry the organisation page (`/module/crm/marketing/organizations/{crm id}`, none when there is no CRM record); a GTM deal carries none; the front end never links a `gtm_deal` even when an old run stored an address | `AgentContextTest::test_every_address_a_fact_carries_is_a_page_that_exists_and_a_record_without_a_page_carries_none`; front end `lib/agent/model.test.ts` |
| F4 | the Account 360 card has the same flaw | `OrgAccountPresenter::threeSixty()` `why_now.last_activity_at` is `activity[0].at` | not changed here (track X's file); sent to X with the test to add | open until X reports |

After the fixes: the track's ten backend files pass, the 123 front-end tests pass, eslint is clean. The first real run was NOT redone: it stays in the history as it was (an audit trail is not rewritten); the next real run, in the combined browser check, will show the corrected brief.

## 7. Deliberate breakages of the caller's own fixes

Method as for the track's own breakages: the baseline is proved green first; the find text must occur exactly once; the code is broken on purpose; `AgentContextTest` and `AgentRunServiceTest` are run; a KILL counts only when PHPUnit reports failed tests (a parse error is "broken", not a kill); the original bytes are put back and compared. Tool: `scratchpad/mutate-context.cjs` (outside the repo). Result: **11 broken on purpose, 11 killed, both files byte-identical to the copies taken before the run** (22:14 to 22:19).

| id | safeguard broken on purpose | first test that caught it |
|---|---|---|
| C1 | a task scheduled in the future may be reported as the latest logged entry | `test_the_latest_logged_entry_is_never_a_future_task_and_the_organisation_says_what_it_counts` |
| C2 | an empty deal field is not said to be empty | `test_what_is_missing_is_said_by_the_data_and_what_is_withheld_is_named_without_its_values` |
| C3 | a key the 360 never gave is reported as "none recorded" | `test_a_host_that_cannot_tell_leaves_the_claim_out_instead_of_saying_none` |
| C4 | deleted contacts are counted as having an address | `test_what_is_missing_is_said_by_the_data_and_what_is_withheld_is_named_without_its_values` |
| C5 | the organisation page address is the removed Organisation 360 page | `test_every_fact_has_a_ref_with_a_type_an_id_a_label_and_an_address_and_the_organisation_comes_first`, `test_every_address_a_fact_carries_is_a_page_that_exists_...` |
| C6 | a GTM deal gets an address again | `test_every_address_a_fact_carries_is_a_page_that_exists_and_a_record_without_a_page_carries_none` |
| C7 | the next step of a CRM deal is not read | `test_what_is_missing_is_said_by_the_data_and_what_is_withheld_is_named_without_its_values` |
| C8 | the contact counts are not sent | the same test and `test_a_host_that_cannot_tell_leaves_the_claim_out_instead_of_saying_none` |
| C9 | the list of what is withheld is not sent | `test_what_is_missing_is_said_by_the_data_and_what_is_withheld_is_named_without_its_values` |
| C10 | the prompt no longer says an absence is claimed only when the data says so | `AgentRunServiceTest::test_the_prompt_carries_the_facts_as_data_and_asks_for_json` |
| C11 | the prompt no longer forbids "no activity" | the same test |

Not covered by a breakage: the tenant filter inside `nextSteps()` (the deal ids come from the 360, which is already tenant-scoped, so a cross-tenant pointer cannot reach it; the filter is defence in depth).

## 8. Not verified

- Anything in a real browser: the panel mounted on the Organization page, the run history screen, keyboard use, dark mode, colour contrast.
- A 403 with a real second user's token over HTTP.
- MariaDB 10.1 (live): the raw DDL ran on 10.11 only; the row lock was proved on 10.11 only.
- The real model producing a note or a `confirm_link` proposal (the one real run produced a task).
- That the corrected context makes the model's next brief accurate: the context now says what is and is not known, but only a real run shows what the model does with it.
