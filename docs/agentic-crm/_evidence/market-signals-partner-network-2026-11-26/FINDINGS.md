# Market Signals: rename two labels, remove the Partner Network (what is there today, read-only, 26 Nov 2026)

Nothing was changed when this was written. Numbers come from SELECTs on dev (`mysql`, 202.47.117.220) and live (`live` = db3, 128.199.17.97), and from reading the code. Both branches were merged with `origin/main` first (owner's instruction).

## The database footprint (verified, identical on dev and db3)

| what | value |
|---|---|
| `g2g_partners` (the partner registry) | 0 rows on both hosts, the only table with "partner" in its name |
| menu row 480 "Partner Network", `/partners`, parent 476 (Market Signals), status 1 | 29 rights rows |
| menu row 477 "Company Opportunities", `/signals`, parent 476 | 29 rights rows (the row behind the tab being renamed) |
| menu rows 476 Market Signals (30 rights), 478 Ingestion Engine, 479 Product Portfolio | untouched |
| `g2g_opportunity_matches` | dev 106 rows, **db3 431 rows, newest 2026-11-26 09:34**: the matching engine runs on live today |
| `g2g_opportunity_matches.partner_id` | 0 non-empty on both hosts |
| `g2g_product_offers.partner_sellable` | dev 25, db3 14 non-empty (a product readiness flag, calculated on save) |
| `g2g_company_opportunities.partner_route_note` | 6 non-empty on both hosts (text imported with a signal) |

## What the Partner Network is in the code (read in source)

- Screen: `app/partners/page.tsx`, `components/domain/portfolio/partners-view.tsx`, an entry in `portfolio-more-menu.tsx`, the tab in `lib/signals-nav.ts`, `/partners` in `hooks/use-sidebar-navigation.ts`, partner functions and types in `services/portfolio/*`.
- API: `PartnerController` and the `/partners*` routes in `routes/api.php` (about lines 3813-3860, gated by `ModuleGate::signals('/partners', ...)`), the model `App\Domain\Portfolio\Partner` (`g2g_partners`).
- It also reaches INTO the matching engine: `MatchingEngine` loads `Partner::forTenant()->active()` for every signal it evaluates and returns `partner_evaluations` per offer; `OpportunityIntelligenceController` saves a `partner_id` on a match; the Organization "business opportunity matching" modal shows those evaluations as "Step 3".
- **Who runs the engine:** `MarketImporter` (every signal import) and `OpportunityIntelligenceController`. So on db3, dropping `g2g_partners` BEFORE the new backend is deployed would make the old engine fail on a missing table during signal imports. The table drop therefore waits for the deploy; the menu changes do not.

## The two renames

- Tab "Company Opportunities": a constant in `lib/signals-nav.ts` (front end) AND the database row 477 (shown in Role and Permissions). Text elsewhere tells the user to go to that tab ("Open this signal in Company Opportunities", "Decisions are made in Company Opportunities", "Start research from Company Opportunities", "Go to Company Opportunities", "qualified company signals go to Company Opportunities").
- Filter "Generated": the source tabs of `components/domain/signals/signal-list.tsx` are All / Generated / Connectors / Manual; the internal value `generated` stays, only the word changes.

## Decisions taken as the expert (owner can overrule)

1. KEEP `partner_sellable` and `partner_route_note`: they are a product-readiness flag and imported signal text with real data, not the Partner Network. After this change `partner_sellable` has no partner registry to refer to; whether to retire it is a separate, later decision.
2. KEEP the nullable column `g2g_opportunity_matches.partner_id` (0 non-empty, on a 431-row production table; DB-RULES: no drops of columns of a live table in the same change).
3. The old `/partners` address redirects to `/signals` (a bookmark must not 404).
4. DB: one command does both menu changes (delete row 480 + rights, rename row 477) with a before-image; the `g2g_partners` drop is a migration that is NOT run on db3 until the backend is deployed.

## Tags

verified = SELECT run today; read in source = grep or file read today.
