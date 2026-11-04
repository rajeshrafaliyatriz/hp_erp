# Track S9b: Campaigns to leads, FRONT END (brief for a build agent)

Read `COMMON-RULES.md` first, then `../slices/S09-campaigns-to-leads.md` (the CONTRACT, with the S9a work log at the bottom: it records what the backend really answers, including two things beyond the contract: the GTM campaign detail also returns `product` and `crm_campaign` by name, and a lead has `campaign: {id, name}|null`), `../DECISIONS.md` (no duplicate screens), `../00-PROGRESS.md`. Written 4 Nov 2026 (about 06:40 IST). The backend is built and committed (hp_erp `4dadf1ee`); read `app/Http/Controllers/Api/Crm/CrmCampaignController.php`, `app/Http/Controllers/Api/Gtm/CampaignController.php`, `tests/Feature/CampaignApiContractTest.php` and `GtmCampaignLinksTest.php` for the exact shapes.

## Goal

The EXISTING campaign screens show what the backend now knows, and nothing else changes: the CRM campaign list and detail (`campaign-list-view.tsx`, `campaign-detail-page.tsx`) show the campaign's PRODUCT and, on the detail, a FUNNEL (targets, sent, opened, replied, meetings, converted, opted out), the linked GTM campaign and the number of leads that came from it; the GTM campaign screen (`components/domain/gtm/campaigns-detail.tsx`) shows its product and its linked CRM campaign; the create and edit forms of a CRM campaign get an optional Product choice (the options endpoint `GET /api/product-master/options`, the way the Leads filter and the Products fold-in read it); the lead page (`lead-detail-page.tsx` header line, committed S6b work) names the campaign a lead came from. No new sidebar entry, no new route, no send or schedule button anywhere.

## Raw material

The files above and their tests; `services/crm/index.ts` and `types/crm.ts` (add OPTIONAL fields, every new field optional so an older server still renders: `product`, `funnel`, `gtmCampaign` / `gtm_campaign`, `leads`, on `Campaign`; `campaign` on `Lead`; the readers accept the contract's snake_case and the presenter's own camelCase, as `lib/leads/model.ts` does: read it and reuse its pattern); `lib/leads/*` (the Leads screen's pure rules, tones and screen kit: reuse, do not copy); the tones `lib/org-accounts/tones.ts`; `lib/agent/contrast.test.ts` (the scan list for status-colour patterns: add your new files).

## Tasks

1. Re-read the contract and the backend tests; list in your slice log every field you rely on, marked optional.
2. Types and service readers (optional fields, both casings); a pure model `lib/campaigns/model.ts`: the funnel as ordered steps with plain-English labels and the share of the previous step (never divide by zero, a step above its predecessor is shown as is and not hidden), "no product" wording, the empty funnel (no targets yet) wording, the linked-campaign sentence.
3. CRM campaign list: a Product column shown only when at least one campaign has a product. Detail: a Funnel section (words and numbers; bars are optional and must carry the numbers as text: no meaning in colour alone), the linked GTM campaign and the leads count (the leads count links to the Leads screen only if the Leads screen has a campaign filter: it does NOT yet, so show the count as plain text). Create / edit form: the optional Product select. GTM detail: product and linked CRM campaign as read-only lines with a link to the CRM campaign when the menu has it. Lead page: "Campaign: <name>" in the existing header line.
4. Tests: model tests, whole-screen jsdom tests over faked services (reuse `lib/leads/screen-kit.ts` if it fits) including axe, the contrast scan with your files added, a mutation proof for every rule (break, see the named test fail, restore byte for byte, `cmp`); `tsc` (only the known errors elsewhere), eslint `--max-warnings=0` on your files, `git diff --stat components/ui` empty.
5. Reason about 390 px in the code (the funnel must wrap; an `sr-only` element inside a scroll box needs a `relative` box); the caller runs the real browser.

## Do not

Edit `components/ui`, any Signals / Ingestion / Partner file, `components/shell/*`, the Facts, Agent, Ask and Leads files beyond the one header line and the optional `campaign` field you add to the lead page and type (read `lib/leads/*`, extend `lib/leads/model.ts` only by an additive reader if truly needed and say so), `contact-*`, `account-360.tsx`, `organization-*`, the backend. No push, no commit, no server, never `npx`. Other build agents work in this repo now (backend tracks in hp_erp; none in g2gv0): never stage or touch uncommitted files that are not yours.

## Report (COMMON-RULES section 7)

Files added or changed, deviations with reasons, test counts and mutation results, which backend fields you could not exercise against real data (dev has NO campaigns: the caller will seed some for the browser pass), what the caller must check in a browser. Keep `../slices/S09-campaigns-to-leads.md` current (append-only work log, an S9b section).
