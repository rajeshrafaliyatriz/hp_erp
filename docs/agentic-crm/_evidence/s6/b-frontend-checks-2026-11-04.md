# S6b (Leads front end): the checks, 4 Nov 2026

Repo: `C:\Users\MILAN\Downloads\g2gv0`, branch `milan1`. Nothing committed, nothing pushed, no server started, no network call outside the fake `fetch` of the tests.

## Tests (verified, run from the repository root)

```
node --import tsx --test "lib/leads/*.test.ts" "lib/leads/*.test.tsx"
# tests 129  # suites 19  # pass 129  # fail 0
```

| file | tests | what it covers |
|---|---|---|
| `lib/leads/model.test.ts` | 52 | reading the new fields (snake_case and camelCase, absent vs null, junk dropped), the filters and their address round trip, the Product box, which columns show, the consent chip and its reason, the signal evidence (date, fit, quote kind, safe link), which fact writes which lead field, saved views |
| `lib/leads/leads-service.test.ts` | 8 | the request the filters make (names and values, nothing sent for "all"), the answers read for the list and for one lead |
| `lib/leads/leads-list.test.tsx` | 25 | the whole Leads screen in a fake browser (real sidebar hook, real service, only `fetch` replaced): the old look is unchanged against an older server, Product column and box, the address, paging reset, Clear filters, chips, marker, saved views (apply, apply an old view, save), axe |
| `lib/leads/lead-detail.test.tsx` | 21 | the whole lead page: tabs, header (product, organisation, consent with reasons, the signal button), the Evidence tab (signal card, honest empty and unreadable states, safe link, text not markup, Signal Review link, quote kinds, published-date fallback, an accepted fact that wrote the record, a 403 on facts), axe |
| `lib/leads/contrast.test.ts` | 23 | contrast of every chip and note from the real theme, light and dark, and a scan of the new components for status colours as text |

Neighbouring suites that import the shared CRM service or types, run after the change: `lib/org-accounts`, `lib/facts`, `lib/product-master`, `lib/scaffolds`: **682 tests, 682 pass, 0 fail** (the facts files are S4b's; they were green at that moment).

## Mutation proof

`b-frontend-mutations-2026-11-04.md`: 97 mutations, 97 killed, 0 survived; the sweeps found 4 weak spots and each was closed with a test and killed on re-run.

## tsc (verified)

```
node node_modules/typescript/bin/tsc --noEmit -p .
```
Before any change: 7 errors (6 in `components/domain/crm/crm-ai-assistant.tsx` and `opportunity-ai-coach-tab.tsx`, 1 in the generated `.next/dev/types/validator.ts`). After: the same 7, none in a lead file, `lib/leads/*`, `services/crm/index.ts` or `types/crm.ts`. (The brief says "exactly 14 known errors"; the baseline on this tree today is 7, taken before my first edit.)

## eslint (verified)

```
node node_modules/eslint/bin/eslint.js components/domain/crm/lead-parts.tsx components/domain/crm/lead-evidence-tab.tsx components/domain/crm/lead-list-view.tsx lib/leads services/crm/index.ts types/crm.ts
```
Exit 0, no output. `components/domain/crm/lead-detail-page.tsx` reports 2 errors; both are in the COMMITTED file too (checked by linting `git show HEAD:components/domain/crm/lead-detail-page.tsx`): `react-hooks/set-state-in-effect` at the tab-reload effect (old line 130, now 146) and `react/no-unescaped-entities` in the "contact person's name" paragraph (old line 250, now 267). Left alone on purpose ("byte for byte where the track does not need to change it").

## components/ui (verified)

`git diff --stat -- components/ui` is empty. No Signals, Ingestion or Partner file was edited; none of `fact-*.tsx`, `services/facts/*`, `lib/facts/*`, `contact-*`, `account-360.tsx` was edited (read only: `FactEvidenceSection` is imported by `lead-evidence-tab.tsx`, nothing else of S4b's).

## Files (g2gv0)

New: `lib/leads/model.ts`, `lib/leads/tones.ts`, `lib/leads/fixtures.ts`, `lib/leads/screen-kit.ts`, `lib/leads/model.test.ts`, `lib/leads/leads-service.test.ts`, `lib/leads/leads-list.test.tsx`, `lib/leads/lead-detail.test.tsx`, `lib/leads/contrast.test.ts`, `components/domain/crm/lead-parts.tsx`, `components/domain/crm/lead-evidence-tab.tsx`.
Changed: `types/crm.ts`, `services/crm/index.ts`, `components/domain/crm/lead-list-view.tsx`, `components/domain/crm/lead-detail-page.tsx`.
