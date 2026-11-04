# Track S6b: Leads, FRONT END (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S06-leads.md` (the CONTRACT section is what the backend track S6a builds at the same time; build against it, not against guesses), `../DECISIONS.md` (no duplicate screens), `../00-PROGRESS.md`. Written 4 Nov 2026 (02:40 IST).

## Goal

The EXISTING Leads screen (`components/domain/crm/lead-list-view.tsx`, `lead-detail-page.tsx`) shows what the lead is for and where it came from: a Product column and filter, a Consent chip, a source-signal link, and an Evidence tab on the lead page. No new sidebar entry, no new route. Everything the screen does today keeps working, byte for byte where the track does not need to change it.

## Raw material

- The files above and their tests; `services/crm/*` and `types/crm.ts` (add the new optional fields there); the S4b track's new Facts components in `components/domain/crm/fact-*.tsx` and `services/facts/*` (read them: the Evidence tab reuses them for entity type `lead`; S4b is being built right now by another agent, so READ ONLY, never edit those files, and if a component you need is missing or its props differ, build a thin local wrapper and say so in the report).
- The tones module for status colours: `lib/org-accounts/tones.ts` (CHIP_BASE / CHIP_TONE / NOTICE_TONE); contrast rule: text-foreground on a solid theme surface, only the border takes the status colour (`lib/theme-contrast.ts` computes it; add the new chips to a scan test like `lib/agent/contrast.test.ts`).
- The product options endpoint `GET /api/product-master/options` and how the Products fold-in (`product-lines-*`) reads it.

## Tasks

1. Re-read the contract; list in your slice log every field you rely on, marked optional (the backend may not be deployed yet: the screen must render when the new fields are absent).
2. Types and service: optional `product`, `organisation_account`, `source_signal_id`, `source_signal`, `consent` on the lead type; filters `product_id`, `consent`, `has_signal` in the list query (URL-synced with the scaffolds' `use-list-query`, as the other list screens do).
3. List: Product column and a Product filter; a Consent chip (blocked = opted out or suppressed: text, not colour alone); a small "from a signal" marker. Columns follow the "show only when there is something to show" rule the Products fold-in uses.
4. Lead page: an Evidence tab (the source signal's quote, URL, date and fit when there is one; the lead's facts through the S4b components; an honest empty state), the Consent chip with the reason in plain words, the Product and Organisation account in the header. Existing tabs untouched.
5. Tests: model tests for the rules (when columns show, how a consent reason reads, the filter's address round trip), component tests, the contrast scan for the new chips. A mutation proof for each rule test (break the code, see the named test fail, restore byte for byte and `cmp`).
6. `tsc` (exactly the known errors elsewhere, none yours), eslint on your files, the narrowed `node --import tsx --test` over your folders, `git diff --stat components/ui` empty.

## Do not

Edit `components/ui`, any Signals / Ingestion / Partner file, the files S4b owns (`fact-*.tsx`, `services/facts/*`, `lib/facts/*`, `contact-*`, `account-360.tsx`), the backend, or start a server (the caller verifies in a real browser). No push, no commit. Never use `npx`.

## Report (COMMON-RULES section 7)

Files added or changed, deviations with reasons, test counts and mutation results, which backend fields you could not exercise against real data (the caller will), and what the caller must check in a browser. Keep `../slices/S06-leads.md` current (append-only work log).
