# PHP mutation proofs of the folded Organisation 360 safeguards (track X, 3 Nov 2026)

Method (tag: verified, I ran it): a runner outside the repo (`mutate-php.cjs`, kept with the session scratch files) first runs every test group and requires it GREEN (baseline: `OrgAccountsApiTest` 45 tests, `OrgAccountsTenantSweepTest` 7, `OrgAccountsEnsureTest` 24, `OrgAccountsResolverTest` 20, `OrgAccountsLinkerTest` 19, `CrmAccount360Test` 4). For each mutation it replaces ONE exact snippet (the original must occur exactly once, else the mutation is skipped, not counted), runs the named test files with `php vendor/bin/phpunit --colors=never`, counts a KILL only when PHPUnit itself reports failed or errored tests (a command error would be a "runner error", never a kill), and restores the file from the bytes read before, comparing SHA-256. Run 16:02 to 16:09 UTC. Raw: `D:\dev\agentic-crm-research\scratch\x-php-mutations.json` and `.log`.

Result: **29 mutations, 29 killed, 0 survived, 0 skipped, every file restored byte-identical** (`git diff` afterwards shows only the intended changes and no "mutated" marker in any source file).

| # | Mutation (what was broken on purpose) | File | Result | First test that failed (and how many others) | Restored identical |
|---|---|---|---|---|---|
| M01 | rights: the Support Organizations row is dropped | `app/Domain/OrgAccounts/OrgAccess.php` | KILLED | test_every_route_needs_a_token_and_the_right_for_its_action_and_there_is_no_administrator_bypass (+3) | yes |
| M02 | rights: link confirm and edit are put behind the view right | `routes/org_accounts.php` | KILLED | the same rights-matrix test (+1: the route-middleware sweep) | yes |
| M03 | rights: creating the CRM organisation is put behind the view right | `routes/org_accounts.php` | KILLED | the same rights-matrix test (+1) | yes |
| M04 | the for-crm route is removed | `routes/org_accounts.php` | KILLED | the same rights-matrix test (+6: the tenant sweep, the endpoint tests) | yes |
| M05 | ensure: a deleted, missing or another tenant's CRM organisation is no longer refused | `OrgEnsure.php` | KILLED | test_another_organisations_or_a_deleted_or_a_missing_crm_organization_is_null_and_writes_nothing (+2) | yes |
| M06 | ensure: the exact-evidence plan is ignored (always its own identity) | `OrgEnsure.php` | KILLED | test_an_exact_name_joins_the_identity_of_the_gtm_account_and_raises_its_lifecycle_to_engaged (+4) | yes |
| M07 | ensure: having a CRM organisation no longer raises the lifecycle to engaged | `OrgEnsure.php` | KILLED | the same join test (+1) | yes |
| M08 | ensure: a lost race is no longer tried a second time | `OrgEnsure.php` | KILLED | test_a_lost_race_is_tried_once_more_and_a_second_loss_is_reported | yes |
| M09 | every route answers 503 on a host without the tables: the list's guard removed | `OrgAccountsController.php` | KILLED | test_every_route_answers_503_not_a_sql_error_on_a_host_without_the_identity_tables | yes |
| M10 | the same: the 360's guard removed | `OrgAccountsController.php` | KILLED | the same 503 test | yes |
| M11 | the suggestions of one organisation are no longer narrowed | `OrgAccountsController.php` | KILLED | test_suggestions_can_be_narrowed_to_the_pairs_of_one_organisation | yes |
| M12 | creation hook: the identity is no longer ensured on create | `CrmOrganizationController.php` | KILLED | test_nothing_that_goes_wrong_in_the_identity_step_can_fail_the_create (+1) | yes |
| M13 | creation hook: a failure in the identity step is no longer swallowed | `CrmOrganizationController.php` | KILLED | test_nothing_that_goes_wrong_in_the_identity_step_can_fail_the_create | yes |
| M14 | D2: a place conflict is never flagged | `OrgResolver.php` | KILLED | test_a_same_name_pair_in_two_different_places_says_so_and_names_both_places (+1) | yes |
| M15 | D2: the place of each record is not given | `OrgSources.php` | KILLED | the same place test | yes |
| M16 | D3: a pair that a confirm would refuse is still offered | `OrgResolver.php` | KILLED | test_rows_already_in_one_identity_are_not_offered (+3) | yes |
| M17 | D3: two records of one source are said to be confirmable | `OrgResolver.php` | KILLED | test_rows_already_in_one_identity_are_not_offered (+1) | yes |
| M18 | D3: confirming two rows of one source is allowed again | `OrgLinker.php` | KILLED | test_confirm_refuses_two_rows_of_one_source_whether_or_not_they_already_have_identities | yes |
| M19 | why now: dismissed signals count as open | `OrgAccountPresenter.php` | KILLED | test_why_now_counts_open_signals_the_next_event_open_deals_and_the_last_activity_over_all_the_rows | yes |
| M20 | why now: a dismissed signal can be the next event | `OrgAccountPresenter.php` | KILLED | the same why-now test | yes |
| M21 | the evidence link accepts any scheme (javascript:, data:) | `OrgAccountPresenter.php` | KILLED | test_a_signal_line_carries_its_evidence_link_only_when_it_is_a_web_address | yes |
| M22 | why now: closed CRM opportunities count as open deals | `OrgAccountPresenter.php` | KILLED | the same why-now test | yes |
| M23 | the GTM account does not say how many deals it has | `OrgAccountPresenter.php` | KILLED | test_the_gtm_account_says_how_many_deals_it_has_and_a_lone_gtm_row_none | yes |
| M24 | a CRM contact does not say whether the person agreed to be written to | `OrgAccountPresenter.php` | KILLED | test_a_crm_contact_says_whether_the_person_agreed_to_be_written_to_and_a_gtm_contact_says_nothing | yes |
| M25 | account360: campaigns of another organisation are shown | `CrmKnowledgeGraphController.php` | KILLED | test_it_answers_instead_of_failing_with_a_sql_error_and_every_section_has_its_real_data | yes |
| M26 | account360: a deleted campaign is shown | `CrmKnowledgeGraphController.php` | KILLED | the same test | yes |
| M27 | account360: the old closing-date column name is back | `CrmKnowledgeGraphController.php` | KILLED | the same test | yes |
| M28 | account360: the old campaign name column is back | `CrmKnowledgeGraphController.php` | KILLED | the same test (+1) | yes |
| M29 | account360: another organisation's or a deleted organisation is no longer a 404 | `CrmKnowledgeGraphController.php` | KILLED | test_another_organisations_or_a_deleted_or_a_missing_organization_is_a_404 | yes |

What the sweep found: nothing survived, so no new test was added because of it. The two safeguards that had no test before this sweep were found while writing it and got one first: the deleted-campaign filter of `account360` (M26; a deleted campaign is now part of the test's data) and the value (not only the keys) of every new 360 field (M19 to M24).

Not mutated, on purpose: `OrgBackfill::writeGroup` (the refactor shared by the backfill and `OrgEnsure`: the existing `OrgAccountsBackfillTest` exercises it and was green in the 165-test run that opened this track, and `OrgAccountsEnsureTest` exercises it through `OrgEnsure`), and the identity-table-missing path of `OrgEnsure` (covered by an existing test that drops the tables).

## Second sweep: the coordinator's two late requests (M30 to M34)

Same discipline, runner `mutate-php-extra.cjs`, baseline proven green first (`OrgAccountsLinkerTest` 20 tests, `OrgAccountsApiTest` 47 tests), raw result `D:\dev\agentic-crm-research\scratch\x-php-mutations-extra.json`. **5 mutations, 5 killed, every file restored byte-identical.**

| # | Mutation (what was broken on purpose) | File | Result | Tests that failed | Restored identical |
|---|---|---|---|---|---|
| M30 | a merge no longer carries the agent's runs and proposals to the survivor (the `AgentSubjects::repoint` call, Track Z's request, is removed) | `OrgLinker.php` | KILLED | test_a_waiting_agent_proposal_and_the_runs_about_the_removed_identity_follow_it_to_the_survivor | yes |
| M31 | last activity: a task planned ahead counts as activity again (the "not after now" bound is removed) | `OrgAccountPresenter.php` | KILLED | test_last_activity_is_the_newest_thing_that_has_already_happened_and_a_task_planned_ahead_is_not_activity_yet (+1: the cap test) | yes |
| M32 | last activity: the card goes back to reading the first line of the timeline (the finding itself) | `OrgAccountPresenter.php` | KILLED | the same two tests | yes |
| M33 | last activity: read from the capped timeline instead of from every row (tasks planned ahead beyond the cap hide the note) | `OrgAccountPresenter.php` | KILLED | test_tasks_planned_ahead_beyond_the_timelines_cap_cannot_hide_what_last_happened | yes |
| M34 | the timeline ITSELF is bounded at now (a task planned ahead would vanish from the Activity list: its order and content must not change) | `OrgAccountPresenter.php` | KILLED | the same two tests | yes |

What this sweep found about my own work: the first M34 I wrote moved the bound to the year 2099, which is an equivalent mutant (nothing is after 2099, so no test can tell); it SURVIVED, which is the right answer for a mutant that changes nothing, and it was replaced with the real one above (bounding the timeline at now), which is killed. Only a kill counts, and only by PHPUnit's own report.

Also run, not mutations: `tests/Feature/AgentSubjectsTest.php` (Track Z's own test of `repoint`, which the coordinator asked me to run next to mine) is green; `OrganisationReferencesScanTest` is RED for a reason that is Track Z's, not mine: it now finds `g2g_agent_proposals.entity_type` in the schema and has no allow-list entry for it (see the slice log).
