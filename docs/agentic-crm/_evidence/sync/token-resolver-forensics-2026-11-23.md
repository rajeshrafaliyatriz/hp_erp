# Forensics: `PersonalAccessTokenResolver` on main lets any bearer string sign in (found 23 Nov 2026 while merging main)

Status: **reported, NOT fixed** (the owner asked for the full detail first). Nothing in this repository changes this code. Evidence tags: *verified* (I ran it), *read in source* (file and line on `origin/main` `6eb925fa`).

## Who and when
| | |
|---|---|
| Commit | `23f7da09d8ac2ce40adcff4bfc8c310269dac32b` |
| Author / committer | Darshna `<darshnahirani02@gmail.com>` (same person) |
| Date | 19 Nov 2026 17:01:09 +0530 |
| Subject | "refactor: add secure API authentication traits and CRM controllers" (the subject says "secure"; the commit is a 19-file, +1,316 / -30 line mix of auth, a WhatsApp and an Email CRM controller, Delivery and Integration controllers, a migration, `routes/api.php`, and an empty root file `test_token.php`) |
| Branch it came from | `Darshna_HP_Dev` |
| Path into main | PR #569 `Darshna_HP_Dev` merged 17:01:36 (27 seconds after the commit) by Darshna, then PR #570 and #571 (Vivek Gajera), then PR #572 `harshit` merged 17:44 by harshitpipaliya-phd: all on 19 Nov. It is on `origin/main`, `origin/harshit`, `origin/vivek` and `origin/Darshna_HP_Dev`. |
| History of the file | one commit: the file `app/Support/PersonalAccessTokenResolver.php` (218 lines) was created in this commit and never changed since. *verified (git log, git blame: every line is `23f7da09`)* |

## Where (all `origin/main` `6eb925fa`)
- `app/Support/PersonalAccessTokenResolver.php:33-36` `findToken()`: when Sanctum does not know the token, it does not refuse; it calls `resolveOrProvision()`.
- `:92-166` `resolveOrProvision()`: if the table has no matching row it calls `resolveAdminUser()` (`:128`) and **inserts a new `personal_access_tokens` row for the unknown string** (`:134-150`): `tokenable_id` = that user, abilities `["*"]`, `expires_at` = now + 30 days; the numeric prefix of a `12|secret` string becomes the row `id` (`:97-100`, `:145`), so the caller picks the id. If the insert fails it returns an in-memory token with the user attached anyway (`:161-165`).
- `:171-217` `resolveAdminUser()`: picks the user to bind in this order: the web session's `user_id` (`:175`); **`request()->input('user_id')`, any user in the table** (`:183-187`); the tenant from `sub_institute_id` (input or header, `:191`) and then the first administrator, `adminuser`, an Administrator profile, any active user, and finally **the first row of `tbluser`** (`:198-205`).
- `app/Providers/AppServiceProvider.php:50` calls `ensureTable()` on **every request and every artisan boot** (a MySQL-only `information_schema` query, `PersonalAccessTokenResolver.php:60-87`); on any other driver it only logs a warning.

## What it changes in the callers (all switched in the same commit from `Sanctum\PersonalAccessToken::findToken` to the resolver)
`ResolvesApiIdentity` (used by the API controllers to decide who calls and which organisation), `RequireApiToken` (`api.token`), `RequireAnyAccess`, `RequireProfile`, `RequireTwoFactorEnrolment`, `TouchTokenActivity`, `AiAuth`, `authMiddleware`, `VerifyCsrfTokenExceptApi` (so a forged bearer also skips CSRF) and `AppServiceProvider` (`:69`, the simulation-upload limiter). *read in source*
The same commit also changed `ResolvesApiIdentity.php:69-76`: a caller whose user has no organisation and who sends no `sub_institute_id` used to get `400 "sub_institute_id is required"`; now it silently gets the first `school_setup` row. *read in source, diff*

## What it means (verified on a pristine copy of main, an in-memory test database)
Probe output (`token-resolver-probe-output-2026-11-23.txt`; the probe test lived only in a scratch worktree and was deleted):
- A. a string nobody ever issued, no other input: **signed in as an administrator**, and a new 30-day token row was written for it.
- B. the same with `user_id=2` in the request: **signed in as user #2, an ordinary employee** (impersonation of any user id).
- C. with `sub_institute_id=2`: **signed in as the administrator of organisation 2** (cross-organisation).
- D. `777|anything`: the row was created with **id 777**, chosen by the caller.
- every unknown string leaves a new row behind (the token table grows without limit).
Main's own test agrees: `FactsApiTest::test_a_caller_with_no_token_or_a_bad_one_is_refused` expects `401` and gets **`200`** on a clean copy of main (the baseline run, `FactsApiTest.php:132`). Our `ProductMatchApiTest` (a bad token must be 401) fails on the merge for the same reason.

## What I do not know
- Whether any deployed site runs this commit (no deployment pipeline was found earlier; push is not deploy). Live was never touched.
- Why it was written. The code comments say it heals sessions whose token row is missing ("resolve or provision it dynamically"), which suggests tokens issued by the older login are not in `personal_access_tokens` on some host; that needs the author's answer before anyone picks a fix.
- Whether other test failures on main (121 in the baseline) are connected; only the 401 ones are known to be.

## Not done on purpose
No fix, no revert, no change to the token code in any branch, no message sent. The merge branches keep main's code unchanged (our own tests that expect a 401 for a bad token stay red and say so).
