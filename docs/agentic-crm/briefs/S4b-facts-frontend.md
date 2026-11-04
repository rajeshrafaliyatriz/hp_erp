# Track S4b: contact facts ledger and consent, FRONT END (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S04-contact-facts-and-consent.md` (the CONTRACT and decisions; the backend track S4a keeps it current and may add detail: re-read it before each screen). Written 4 Nov 2026 (01:00 IST).

## Goal

Make the facts ledger and consent visible where people already work, with NO new sidebar entry (the owner's rule of 3 Nov: no duplicate screens; print the menu tree first, read `hooks/content-map-crm.ts`). Three places:
1. **Contacts** (`components/domain/crm/contact-list-view.tsx`, route `/module/crm/marketing/contacts` and the support twin): a "Suggestions" scope next to the list (the pattern of `product-scope-switch.tsx` and `org-scopes.tsx`: scope in the address, the existing list untouched inside it), showing the tenant's queue (`GET /api/facts/queue`) with Accept and Dismiss.
2. **Contact detail** (`contact-detail-page.tsx`): an Evidence section (the contact's facts: applied, proposed, dismissed, with each fact's evidence listed) and a consent line (`GET /api/consent/{type}/{id}`: per channel allowed or not, with the reason).
3. **Organization detail, Account 360 tab** (`account-360.tsx` / `org-account-360-parts.tsx`, committed by track X): an Evidence section for the organisation's facts, placed after Linked records; do not restructure the tab.

## How (lessons already paid for; follow them)

- Build against a FAKE service first (`lib/facts/fake-service.ts`, fixtures) exactly like `lib/agent/` and `lib/org-accounts/`: components take the service as a prop, whole-screen tests drive the real component with the fake. The real service goes in `services/facts/` using the shared client the other services use (read `services/agent/agent-service.ts`).
- Colour: text is `text-foreground` (or `text-muted-foreground` on the muted surface) on a SOLID theme surface, only borders take a status colour. Use the tones in `lib/org-accounts/tones.ts` and copy the pattern of `lib/agent/tones.ts` + `lib/agent/contrast.test.ts` (ratios computed from `app/globals.css`, light and dark, plus a scan of your components for `text-success|warning|destructive|primary` as text and `bg-*/NN` tints). jsdom computes no colour, so a real browser's axe run is the only other check; the caller runs it.
- Accessibility: every state in words (a colour only helps); one action in flight at a time; after an action the focus goes to a message or the next sensible control; Escape closes a reason form and returns focus to the button that opened it (`lib/agent/focus.ts`); late answers for a record that is no longer open are dropped; a refusal reads the state again.
- Everything a model or the web wrote (a fact's value, a quote, a source address) is TEXT: never `dangerouslySetInnerHTML`; a source address is a link only if it is `http` or `https` (see `webAddress` handling in the 360's evidence links) and then with `rel="noopener noreferrer"`; an internal path only if it starts with a single `/`.
- DOM assertions in tests: use `lib/agent/assertions.ts` (`absent`, `same`): a failing `assert.equal(element, ...)` runs the Node process out of memory.
- Tests: pure model tests, whole-screen tests (real list/detail with the fake service), states (opening, error with Retry, forbidden, not found, empty, view-only), the accept/dismiss flows incl. the 409, the reason form, focus behaviour. Mutation proofs (COMMON-RULES section 4): break each rule on purpose, the named test must fail, restore byte for byte; a runner template: `D:\dev\agentic-crm-research\scripts\mutation\y-products-fold-in\mutate_fe.mjs` with a spec file like `mutations_fe.mjs` there.
- Run only your own tests: `node --import tsx --test "lib/facts/*.test.ts" "lib/facts/*.test.tsx"`, plus before you finish `lib/org-accounts`, `lib/agent`, `lib/product-master` (they must stay green), `node node_modules/typescript/bin/tsc --noEmit -p .` (7 known errors: `crm-ai-assistant.tsx` 4, `opportunity-ai-coach-tab.tsx` 2, `signal-inbox-view.tsx` 1, plus a generated `.next/dev/types` line: yours must add none), `node node_modules/eslint/bin/eslint.js <your files> --max-warnings=0`. Never `npx` (wrong version here). No dev server, no browser (the caller runs the real-browser check).

## Files you own / may touch

Own: `services/facts/*`, `lib/facts/*`, `components/domain/crm/fact-*.tsx` (new). May touch, minimally and with a test each: `contact-list-view.tsx`, `contact-detail-page.tsx`, `account-360.tsx`, `org-account-360-parts.tsx`. Do NOT touch `components/ui/*`, `components/shell/*`, any Signals file (`components/domain/signals/*`, `lib/signals-nav.ts`, `app/signals|ingestion|portfolio|partners`), `lib/agent/*`, `lib/org-accounts/*` (import from them, do not edit), `hooks/content-map-crm.ts` (no new route is needed).

## Sequencing

The backend (track S4a) is being built at the same time: when your fake-service work is done, check whether `app/Http/Controllers/Api/Facts/` and `routes/facts.php` exist in hp_erp (read only) and compare their real response shapes with your types; report any mismatch to the caller instead of editing the backend.

## Limits and report

No push, no commit (the caller commits), no dev server or browser, no live anything, nothing sent. Save files and tests as you go and keep a short work log at the end of the slice file's work-log section (append-only, prefix `S4b`). Your final message is the report of COMMON-RULES section 7: files, design and deviations, tests with the test that killed each mutation, evidence, open defects with file:line, what you could not verify, what the caller must do next.
