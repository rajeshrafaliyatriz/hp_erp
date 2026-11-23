# S15 P1-B front end: links retargeted, redirects, sidebar fold, guard (13 Nov 2026, g2gv0, branch milan1, uncommitted)

Commands were run from `C:\Users\MILAN\Downloads\g2gv0` (Node 22.14, tsc 5.7.3). No server was started. Claims are tagged *verified* (a command ran and its output is here), *read in source*, *inferred* or *unverified*.

## What changed (verified, `git status` / `git diff`)
| file | change |
|---|---|
| `lib/org-accounts/links.ts` | the separate Signal Review link is gone from `OrgLinks`, `ACCESS_LINKS`, `buildLinks`, `DEFAULT_LINKS`; `signalReviewHref(links, title)` now follows the Signals link: `${links.signals}?status=all&q=${encodeURIComponent(title)}`. The function keeps its older name on purpose (see "Left on purpose"). |
| `components/domain/crm/lead-evidence-tab.tsx` | link text "Open this signal in Company Opportunities" (was "...Signal Review"), comment updated. |
| `components/domain/crm/org-account-360-parts.tsx` | the sentence under Signals reads "Decisions are made in Company Opportunities." (the helper call at :208 needed no change). |
| `app/signal-review/page.tsx`, `app/module/crm/signal-review/page.tsx` | were client pages that mounted the shell; now async server pages: `redirect(signalsRedirectTarget(await searchParams))`. 307, type `replace`, no UI. |
| `lib/signal-review-redirect.ts` (new) | `signalsRedirectTarget(params)`: `/signals` plus the same query, each key and value through `encodeURIComponent` (a space is `%20` as in the links we write), repeated keys kept in order, undefined dropped, the path fixed. |
| `hooks/content-map-crm.ts` | the `SignalReviewView` import and the two route entries removed (-4 +2 lines, the 2 added are a comment). Nothing else imported the view from the map (*verified*: `git grep SignalReviewView` finds only the Review component files and their tests). |
| `lib/signals-nav.ts` | +42 -1. Signal Review row dropped from whichever tree `collapseSignalsNav` returns (a separate pass, so it also works for a person whose menu holds no Signals row), leaf rows only, matched by either address (case and trailing slash tolerant) or by the name "Signal Review"; `owners['/signal-review']` and `owners['/module/crm/signal-review']` point at the Market Signals entry. `SIGNALS_TABS` and every other line untouched. `hooks/use-sidebar-navigation.ts` needed no change (*verified* by the hook-level test below). |
| tests/fixtures re-pinned | `lib/org-accounts/model.test.ts`, `account-360.test.tsx`, `lib/leads/lead-detail.test.tsx`, `lib/leads/screen-kit.ts` (comment), `lib/ask/model.test.ts`, `lib/ask/fixtures.ts`, `lib/agent/model.test.ts`, `lib/agent/fixtures.ts`, `lib/agent/org-agent-panel.test.tsx`. `lib/ask/context.test.ts:26` named in the brief does not contain the old address (it lists `/signals` as a lookalike path), so it needed no change. |
| new tests | `lib/signal-review-redirect.test.ts` (19 tests), `lib/signals-nav-review.test.ts` (9), `lib/signal-review-address-gone.test.ts` (6) |

## Results (verified)
| command | result |
|---|---|
| `node node_modules/typescript/bin/tsc --noEmit -p .` | exit 0, no output (0 errors); my new files are in the program (`--listFilesOnly`) |
| `node node_modules/eslint/bin/eslint.js --max-warnings=0 <the 20 files I changed or added>` | exit 0. (First run found one real problem in my sidebar hook test, a probe reassigning an outer variable during render; fixed by rendering the findings as JSON and reading them back.) |
| `git diff --stat components/ui` | empty |
| `node --import tsx --test <my 9 test files>` | 248 tests, 248 pass, 0 fail (before and after the mutations) |
| `node --import tsx --test "lib/**/*.test.ts" "lib/**/*.test.tsx"` (whole suite, 2nd run) | 3,201 tests, 3,199 pass, 2 fail: `lib/expandable-run-notice.test.tsx` subtests 3 and 4, the known baseline, not mine |

**Late note (final run, same day):** `tsc` was 0 errors on three runs of mine. A fourth run, at the very end, reported ONE error, `lib/signals/opportunity-fixtures.ts(43,17) TS2352` (a fixture cast to `SignalAssessment`). That file is new and untracked, created by Track A (Company Opportunities) after my last clean run; none of my 20 files is involved (their checksums were unchanged, eslint was clean and their 248 tests passed in the same run). Track A owns the fix. *verified* (the error text names only that file).

The first whole-suite run (3,160 tests) also failed 4 pins of Track A's in-flight files (`lib/company-opportunities-wiring.test.ts` x2, `lib/signals-screen.test.ts` subtest 5 "the signal list asks the server for five per page", `lib/sub-product-run.test.ts` subtest 6). They read `components/domain/signals/*`, which Track A was rewriting; none of my files is read by them, and by the second run Track A had updated them. *verified* (locations in the TAP output; `signal-list.tsx` shows 168 insertions and 216 deletions in the working tree that I never touched).

## What the new tests prove
- Redirect (per page, both pages): no query gives `/signals` with status 307 and type `replace`; the query of an old link comes out byte for byte as the address the link makers write today (`/signals?status=all&q=A%20%26%20B%20plan`); all seven names the old screen read (`q status product freshness fit sort page`) keep value and order; a repeated key stays repeated; `%`, `+`, `#`, `?`, quotes, a backslash, a newline, Hindi text and an empty value survive a round trip; a hostile query (`https://evil.example`, `//evil.example`, a key containing `=` and `&`) cannot change the path or the origin; undefined values are dropped; the two pages answer identically. A link stored in an Ask answer or an agent run BEFORE the merge (`/module/crm/signal-review?status=all&q=...`) is still drawn as a link by `safeLink` and `safeInternalPath` and lands on the same signal search.
- Content map: `CRM_CONTENT` (imported as data) has no entry containing the old address and still routes `/signals`, `/module/crm/signals` and Signal Inbox.
- Sidebar (pure function, 8 tests): Review dropped for a person who holds the right; the same sidebar for one who does not; Market Signals still flat (`/signals`, no children), the four `SIGNALS_TABS` unchanged and their owners unchanged; dropped under another parent, two levels down, under Market Signals itself, as a top-level module, by the other address, with another capitalisation and a trailing slash, by name when the address is missing; dropped for a person with no Signals row at all (`tabs` is null); a row of that name with children is NOT dropped; owners for both addresses equal the owner of `/signals`; input not mutated and untouched branches keep their identity.
- Sidebar (real `useSidebarNavigation` hook, fed a menu through the browser's own storage as `lib/leads/screen-kit.ts` does): `sidebarModules` and `modules` hold no Review row, Market Signals is flat, and `parseRoutePath('/signal-review')` and `parseRoutePath('/module/crm/signal-review')` return the Market Signals entry, equal to `/signals`. This is the proof for "a visit to /signal-review highlights Market Signals" and for "`use-sidebar-navigation.ts` needs no change".
- Links: `buildLinks` asks the menu for no Review address under either spelling; the lead Evidence link and the Organization 360 title links go to `/signals?status=all&q=<title>` whether or not the person's menu still holds row 506; with no Signals link the 360 title is plain text and the evidence-page link stays.
- Guard (`lib/signal-review-address-gone.test.ts`): reads every source file of `app components hooks lib services` (about 1,700) and fails on either address as a path. `@/services/signal-review/...`, `./signal-review-drawer`, `data-signal-review-link` and `#signal-review-panel-...` are the Review screen's own file and element names, not links, and are ignored. Allowances, each one file and where possible one line: `lib/signals-nav.ts` (the constant naming the row), `lib/leads/screen-kit.ts` (menu fixture), `lib/signals/router-harness.ts` (Track A's test router default `pathname`), and the three new test files. The two redirect pages need no allowance.

## Mutation proofs (verified). Each: copy of the file taken first, file broken, named tests fail, copy put back, `cmp` silent, md5 equal to the first copy; at the end all 20 owned files matched their checksums and the 9 files were 248/248 green again
| # | mutation | tests that failed |
|---|---|---|
| F1 | `signalsRedirectTarget` ignores the query (`return SIGNALS_ADDRESS`) | 15 of 19 in `signal-review-redirect.test.ts`: for both pages "keeps the query of an old link exactly", "keeps every name the old screen read", "keeps a repeated key", "keeps a value with characters that could cut an address short", "cannot be used to send a person anywhere but /signals", "leaves out a key that has no value", plus "both go through the helper" and both stored-link tests (the no-query tests, "answer the same" and the content map still pass, as they should) |
| F2 | `app/signal-review/page.tsx` uses `permanentRedirect` (308) | "sends a visit with no query to /signals as a temporary (307) redirect" for that page, and "answer the same for the same query" |
| F3 | the sidebar fold deleted (`withoutSignalReview(...)` call removed) | 4 of 8 pure sidebar tests (right held; same sidebar; nested; no Signals row) and the real-hook test |
| F4 | the owner mapping for the old addresses removed | "sends a visit to either address to the Market Signals entry..." and the real-hook test |
| F5 | the leaf-only check removed | "does not drop a row of that name that has children" |
| F6 | the old address written into a component (`lead-evidence-tab.tsx`) | the guard, naming `components/domain/crm/lead-evidence-tab.tsx:102`; and "links to Company Opportunities for that signal..." in `lead-detail.test.tsx` |
| F7 | `signalReviewHref` builds `/module/crm/signal-review?...` again | `lead-detail.test.tsx` "links to Company Opportunities for that signal...", `account-360.test.tsx` "shows each signal...", `model.test.ts` "opens one signal in Company Opportunities..." and "keeps a title whole in the search", and the guard |
| F8 | `buildLinks` asks the menu for the retired row, with a fallback to the Signals link (text guard dodged on purpose) | `model.test.ts` "looks for no Signal Review menu row at all" (the lead page test still passed because of the fallback, which is why F8b exists) |
| F8b | the Signals link IS the retired row, no fallback (text guard dodged) | `lead-detail.test.tsx` "links to Company Opportunities for that signal... whether or not the person's menu still holds the retired Signal Review row", `model.test.ts` "looks for no Signal Review menu row at all" and "opens one signal..." |
| F9 | the content map routes `/signal-review` again | "has no entry for either address, and still routes /signals" and the guard |
| F10 | a second link on another line of the allow-listed `lib/signals-nav.ts` | the guard (names `lib/signals-nav.ts:16`) and "lets every allowance do exactly what it says" |
| F11 | the guard's own detector loses the `/module/crm/` way in | "sees both old addresses and ignores the Review screen's own file names" and "does see a file that names the old address when it is read from disk" |
| F12 | both components say "Signal Review" again | `lead-detail.test.tsx` (link text) and `account-360.test.tsx` (the sentence) |

md5 of the files after restore (equal to before): `lib/signal-review-redirect.ts` 612bad71ad4626eb1d003bedee81dfc9, `app/signal-review/page.tsx` a035092dfaecf8b2453710dca17833d3, `lib/signals-nav.ts` ca21dc32b4bbfdcfec54fd34e03a70bf, `hooks/content-map-crm.ts` b9f3527178f5511edc78c4181351c071, `lib/org-accounts/links.ts` 45b8fd2ee7f893bb967bac8e3b207a0c, `components/domain/crm/lead-evidence-tab.tsx` 8bdbef421252d1203b297684af6e506a, `components/domain/crm/org-account-360-parts.tsx` e5b7a15858786dce25590a58ccc45393, `lib/signal-review-address-gone.test.ts` 1067c60056ed21bbc5fcaa8564b83c55.

## Census: places that name the old address, outside the docs folders (verified, `p1b-census-script.mjs`)
Counts are of the ADDRESS as a path, not of the substring. A plain search for the substring `signal-review` finds 130 occurrences in g2gv0 before this track (178 after, because of the new tests' examples), nearly all of them `services/signal-review/` import paths and `signal-review-*` file names of the Review screen itself, which are not links; the caller moves or deletes those in step 1.7.

| kind | hp_erp before -> after | g2gv0 before -> after |
|---|---|---|
| LIVE link maker or route (code a person can follow) | 2 -> 0 | 8 -> 0 |
| test or fixture pinning a LINK | 3 -> 0 | 22 -> 0 |
| menu-row identity in code (left on purpose; one allowed line each) | 1 -> 1 (`SignalsGrantReviewCommand.php:27`) | 0 -> 2 (`lib/signals-nav.ts`, one line holding both spellings) |
| migration (history, left on purpose) | 5 -> 5 (3 files) | n/a |
| test pinning the retired MENU ROW (left on purpose) | 2 -> 2 (`SignalReviewMenuTest`, `SignalReviewMenuMoveTest`) | n/a |
| fixture / harness naming the menu row or a default path (left on purpose) | n/a | 2 -> 2 (`lib/leads/screen-kit.ts`, `lib/signals/router-harness.ts`) |
| NEW guard / redirect / sidebar tests (examples on purpose) | 0 -> 17 | 0 -> 39 |
| total | 13 -> 25 | 32 -> 43 |

Files: `p1b-census-before-*` and `p1b-census-after-*` hold the per-file lists.

## Left on purpose
- The exported name `signalReviewHref`, the data attribute `data-signal-review-link`, and the test option `signalReview` of `lib/leads/screen-kit.ts`: cosmetic names; the brief scoped the touch set to the link makers. A rename belongs with the removal of the Review files in step 1.7.
- Comments that say "Signal Review" without an address in `components/ai-stack/usage-cost-screen.tsx:40`, `components/domain/crm/org-account-parts.tsx:42`, `lib/theme-contrast.ts:7`, and the Review screen's own files.
- The `/signal-review` default `pathname` of `lib/signals/router-harness.ts:9` (Track A's folder; allow-listed in the guard with the reason; it goes with the Review tests).
- Menu row 506, its rights, its migrations and `signals:grant-review`: not touched.

## Not verified
- No HTTP request was made. The redirect pages were called as functions, the way Next calls a page; the 307 and `replace` come from the redirect error Next throws (`getRedirectStatusCodeFromError`, `getRedirectTypeFromError`). *inferred*: a real GET answers 307 with a `Location` header, because the redirect is thrown before anything is streamed.
- `next build` was not run (it writes `.next` and is heavy; `next.config.mjs` has `typescript.ignoreBuildErrors: true`, so `tsc` above is the type check that counts).
- `proxy.ts` sends a signed-out visit to anything under `/module` to `/login?redirect=<pathname>` and drops the query (existing behaviour, unchanged): a signed-out person opening an old `/module/crm/signal-review?status=all&q=x` link loses the query after logging in. `/signal-review` itself is not under `/module`.
- A real browser walk (390 px, axe, roles) is not part of this track.
