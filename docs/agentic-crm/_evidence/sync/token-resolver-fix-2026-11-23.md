# The token resolver fix (23 Nov 2026), on top of the merge with main

Problem and forensics: `token-resolver-forensics-2026-11-23.md` (commit `23f7da09`, `app/Support/PersonalAccessTokenResolver.php`). The owner asked for the details first and then said "solve all problems", so the fix is made, in its own commit on `Milan-2` (`fix(auth): a bearer string is a caller only if a token was issued for it`), so a reviewer can read or revert it on its own.

## What the code does now (tag: read in source, verified by the tests below)
- `findToken()`: Sanctum's own lookup first. If Sanctum does not find the token, a lookup by the **hash of the secret** (and, for `12|secret`, the id as well, never the id alone). No row: `null`, **nothing written**.
- The token's owner must exist in `tbluser`; a token whose user is gone is no caller (before: it was handed to an administrator or the first user).
- Nothing in the request (`user_id`, `sub_institute_id`, header, session) chooses who a token belongs to. `resolveOrProvision()` and `resolveAdminUser()` are deleted (no other file called them).
- `ensureTable()` (still called from `AppServiceProvider::boot`) now runs **once per process** and only on MySQL/MariaDB; it no longer logs a warning on every request on other databases.
- Real sign-ins are untouched: `authController` (two places) and `GoogleAuthController` issue Sanctum tokens with `createToken`, which Sanctum finds by hash.

## Tests
- New `tests/Feature/PersonalAccessTokenResolverTest.php`, 7 tests, 28 assertions: an unissued string is nobody and writes no row (also `777|…`, `|`, empty, null); `user_id`, `sub_institute_id` and the header cannot make an unknown string somebody; an issued token resolves to its own user whatever the request says and a lookup writes nothing; a real id with a wrong secret is not that user's token; the private row lookup needs the hash and the id to agree (and a non-numeric prefix is no id); a token whose user is gone is no caller; the table check is quiet on a database without `information_schema` and runs once.
- Main's own failing test `FactsApiTest::test_a_caller_with_no_token_or_a_bad_one_is_refused` (it got 200 on a clean copy of main) now passes (20 of 20), and our `ProductMatchApiTest` (10 of 10) passes.
- **Mutations** (break one thing, a named test fails, restore byte for byte, cmp silent): R1 an existing row matched on its id OR its hash (the original bug) killed by the row-lookup test; R2 a token whose user is gone is handed to the first user: killed; R3 an unknown string provisioned for the user the request names (the original bug): killed by 2 tests; R4 the table checked on every lookup: killed; R5 the driver guard removed: killed; R6 a non-numeric prefix accepted by its secret: killed. Runner: `scratchpad/resolver-mut.php` (scratch, not kept).

## What I did NOT change, and what to watch
- `ResolvesApiIdentity` (same commit): a user with no organisation of their own and no `sub_institute_id` in the request now silently gets the first `school_setup` organisation instead of a 400 "sub_institute_id is required". That is a weaker tenant rule than before; it is not a token problem and its intent is the author's to say, so it is left as main has it and named in the PR.
- Anything that sent a made-up token and relied on it working (the empty `test_token.php` in the repository root suggests a dev shortcut) will now get a 401; real sessions are unaffected. The author should confirm no tool depends on it.
- No live system was touched; whether any deployed site already runs commit `23f7da09` is not known.
