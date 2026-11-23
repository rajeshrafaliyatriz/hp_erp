# Track S15-P1A: Company Opportunities gets the Signal Review abilities (front end, g2gv0)

Read `COMMON-RULES.md` first (its section 2 now carries the 13 Nov exception to the Signals freeze), then `../DECISIONS.md` (entry 2026-11-13), `../slices/S15-product-portfolio-merge.md` (the approved plan; your phase is P1 steps 1.2 and 1.3). Written 13 Nov 2026. P1.1 is DONE (uncommitted in the g2gv0 working tree): `lib/signal-review/` was moved to `lib/signals/` and every import rewritten; tsc has 0 errors and 1,708 tests passed after the move.

**Known baseline at 13 Nov (not yours):** the whole front-end suite is 3,107 tests with 2 failing, both in `lib/expandable-run-notice.test.tsx` (subtests 3 and 4, from Harshit's 5 Nov commit `8941ec6`; they fail without any change of ours). Its subtest 1 pins that `company-opportunities.tsx` imports and renders `ExpandableRunNotice` with `notice={run.notice}`: keep that wiring intact.

## Goal
Everything a person could do on the Signal Review screen (`/signal-review`) can be done on the Company Opportunities tab (`/signals`), so the Review screen can be removed. **The four-tab header, Ingestion Engine, Portfolio and Partner Network are untouched.** Keep the CARD list (do not swap it for the Review table) and keep generating, manual add, source tabs, date presets and Advanced tools working exactly as today.

## What to build
**1.2 Queue controls, in the URL**
1. A product choice **All products** (today the selector has no All; a source-scan test pins `allowAll:false`: rewrite that pin on purpose, same strictness).
2. Filters kept in the address, using the Review's own names so old links keep working: `q`, `sort` (`recent|priority`), `page`, `product`, `status` (`all|new|contacted|reviewed|dismissed`), `freshness` (`all|last_30_days|last_90_days|older|undated`), `fit`. The Review's definitions are in `lib/signals/list-model.ts` (`SIGNAL_LIST_QUERY`, `queryToParams`) and `components/shared/scaffolds/list-query`. Reuse them rather than re-inventing.
3. **`product` accepts both** a product KEY (today's Signals meaning, for example `scholarclone`) and a numeric master id (the Review's meaning, sent as `product_master_id`). Read `components/domain/signals/product-selector.tsx:20-27`.
4. **Deep-link rule (the reason this matters):** Ask answers and Account 360 already contain `/signal-review?status=all&q=<signal title>`; a redirect will send them to `/signals?status=all&q=<signal title>`. When `product` is absent and any of `q`, `status`, `freshness`, `fit` is present, the screen opens on **All products** (not on a default product) and shows exactly the signals that match. With no parameters at all, behaviour is unchanged from today.
5. Server counts (total, need a decision, decided, high priority) from the list response replace counts computed from the visible rows (`signal-list.tsx:153-175`). The Review already reads `summary` from the response (`lib/signals/list-model.ts` `summaryTiles`).
6. A page-size choice (5 as today, 20, 50), kept in the URL as `per`.
7. Filters wrap at 390 px with no sideways scroll; empty, filtered-empty, error and loading states as in the Review (`listScreenState`).

**1.3 Decisions, history, fit, lead**
1. The three lenient buttons (review, dismiss, follow-up; `scholar-signals-view.tsx:668-686`, `services/signals/opportunities.ts:849`) are replaced by the Review's **decision dialog** (`components/domain/crm/signal-decision-dialog.tsx`, `signal-review-decision.ts`, `lib/signals/decision-actions.ts`, `decision-failure.ts`): nine reasons, note, `expected_status`, the "someone decided first" 409 text, Reopen, the 403 text. It calls `POST /api/signals/opportunities/{id}/decisions` (`services/signal-review/signal-review-service.ts`).
2. In the card's "Evidence and details" add **Decision history** (`signal-history-list.tsx`, `GET /signals/opportunities/{id}/reviews`) and **Fit & score** (`signal-review-fit.tsx`, `lib/signals/fit-score.ts`).
3. The lead button becomes **confirm, then once** (`signal-review-lead.tsx`, `lib/signals/lead-action.ts`): confirm dialog, idempotent, "Lead created" link, the 403/404 texts.
4. Move the components you reuse from `components/domain/crm/` into `components/domain/signals/` with `git mv` and drop the old module's name where it appears (`signal-review-fit.tsx` to `signal-fit.tsx`, `signal-review-lead.tsx` to `signal-lead.tsx`, `signal-review-decision.ts` to `signal-decision.ts`; keep `signal-decision-dialog.tsx` and `signal-history-list.tsx` names). Update every import and every test. **Do not delete the Review screen files** (`signal-review-view/list/drawer/parts.tsx`): they must keep compiling and passing until the caller removes them in step 1.7. Tests that cover moved behaviour move with it; none is deleted to turn green.

## Raw material (read, do not trust my summary)
`components/domain/signals/{company-opportunities,signal-list,scholar-signals-view,product-selector,signals-page-content}.tsx`; `lib/signals/*` (the moved helpers and their tests); the Review screen files named above; `services/signals/opportunities.ts` (:762-960); `services/signal-review/*`; backend (READ ONLY): `routes/api.php:3538-3567`, `app/Http/Controllers/Api/Signals/OpportunityController.php` (index :610, filters :648-650, act :756, decide :781, reviews :805, response `filters` :732-733), `config/signals.php` `review` block (:355-376), `tests/Feature/SignalReviewApiTest.php`. Pinned source-scan tests you will have to change on purpose: `lib/signals-screen.test.ts`, `lib/company-opportunities-wiring.test.ts` (list each changed pin in your report with the reason).

## Proofs
- A whole-screen jsdom test (use `lib/signals/router-harness.ts`) for each of: the deep link `?status=all&q=<title>` (All products, All statuses, the search applied, a request carrying `search` and no `review_status`); filters survive a re-render from the URL; a numeric `product` and a key `product` both select the right product; server counts shown; page size; the decision dialog sends `expected_status` and shows the 409 text; history and fit appear; the lead action asks first and a double click makes one request.
- Mutations (break, named test fails, restore byte for byte, `cmp`): drop `expected_status` from the decision request; make the URL reader ignore `status`; remove the lead confirm step; take counts from the visible rows again; make `product` ignore a numeric id.
- Contrast and axe: new components follow the tones method (`lib/signals/tones.ts`, text-foreground on a solid surface, only the border takes a status colour) and are added to the contrast scan (`lib/signals/contrast.test.ts` and the Signals scans). `tsc` (0 errors), eslint `--max-warnings=0` on your files, `git diff --stat components/ui` empty.

## Do not
Edit `components/ui`, `components/shell`, Ingestion, Partner, Portfolio files, `lib/signals-nav.ts`, `app/**`, `hooks/content-map-crm.ts`, `lib/org-accounts/links.ts`, `lead-evidence-tab.tsx` (track B owns those), or any backend file (report a needed backend change instead). No commit, no push, no server, never `npx`. Track B works in the same tree at the same time: never stage or touch its files.

## Report (COMMON-RULES section 7) plus
A parity table: every Review feature (facets, summary, list, drawer tabs, decisions with reasons, history, fit and score, lead action, Generate, Runs, Export) with where it now lives on `/signals` or "not yet (step 1.4)". Keep `../slices/S15-product-portfolio-merge.md` current (append a "P1-A" work log). Evidence under `../_evidence/s15/`.
