# Company Opportunities Region to ICP Region: rollout package

Written for the engineer who deploys this to staging and live. **Nothing here has been applied to any shared or live environment.** The change was developed and tested locally only (in-memory SQLite tests and frontend tests).

## What changes

- Backend: `app/Domain/Signals/Products/SubProductIcpRegionSync.php` and `RegionSyncRejected.php` (new); `app/Http/Controllers/Api/Signals/OpportunityController.php` (`run()`: the sync and the run record in one transaction, `data.icp_sync` in the response).
- Frontend: `lib/signals-generate.ts`, `services/signals/opportunities.ts`, `components/domain/signals/use-generate-signals.ts`, `components/domain/signals/company-opportunities.tsx`.
- **No migration.** It uses `g2g_icp_profiles`, `g2g_icp_profile_versions` and `g2g_product_offers.icp_config`, which already exist (migrations `2026_10_14_100000` and `2026_10_27_200000`). On live, confirm those two columns/tables exist before deploying (read-only `SHOW COLUMNS`/`SHOW TABLES`; do not use `Schema::hasColumn()` on the live MariaDB 10.1).
- Deploy the backend before (or with) the frontend. An older frontend works with the new backend (it ignores `icp_sync`); a new frontend against an older backend shows no ICP message and behaves as before.

## Behaviour to expect (see `docs/product-driven-signals.md`)

Specific sub-product + a Region: that sub-product's ICP for the chosen customer type gets those places (created if missing, only when it can be created validly). Whole Product and All Regions never touch an ICP. A sub-product with no ICP and no trigger signals of its own now answers 422 instead of running on the unsaved fallback: tell users to complete it in Product Portfolio or choose Whole Product.

## Before touching live

1. Identify the target database without printing credentials (name and host class only), and confirm it is the intended one.
2. Take, or confirm, a restorable backup of `g2g_icp_profiles`, `g2g_icp_profile_versions` and `g2g_product_offers`. Without one, stop.
3. Run the backend focused tests and the full suite on the release candidate; compare failures with the known baseline.
4. Know the rollback: redeploy the previous release (no schema change to undo). ICPs this feature already wrote stay; each change has a row in `g2g_icp_profile_versions` (note starts with "Region set from Company Opportunities" or "Created from Company Opportunities") so an earlier `regions` value can be read back and restored through the normal ICP screen.

## Verifying on staging or an authorized test tenant

Use a tenant and sub-product you own; never a customer's.

1. Sub-product + B2B + India, Generate: `icp_sync.status` is `created` (or `updated` if an ICP existed); the B2B ICP of that sub-product lists India; the B2C and B2G ICPs and other sub-products are unchanged.
2. Same again: `unchanged`, no new version row.
3. Change to USA: `updated`, same ICP id, a new version, other fields unchanged.
4. Repeat for B2C and B2G.
5. Whole Product with any region: `skipped` / `whole_product`; no ICP row changed (compare `g2g_icp_profiles.updated_at`).
6. A sub-product with no ICP and no trigger signals: 422 `icp_region_sync_failed`, no ICP row, no run row.
7. Another organisation's session using the same offer id: 422 `unknown_sub_product`, nothing changed.

Record date, who ran it, the environment, and the observed rows. Do not claim live verification without it.


## Country > State > City selector: what is added to the rollout

- **No database migration.** The location data is a set of JSON files shipped with the backend release (`resources/data/locations/`, about 6.5 MB, one file per country; `build.mjs` regenerates them from `@countrystatecity/countries`). Make sure the deployment artifact includes that directory and `NOTICE.md`. There is nothing to run against the live database for this change.
- **Backend files:** `app/Domain/Signals/Locations/LocationCatalog.php` (new), `app/Domain/Signals/Support/RegionScope.php` (resolves and validates; stored shape unchanged), `app/Domain/Signals/Icp/IcpProfileValidator.php` (a validated `locations` setting), `app/Domain/Signals/Products/SubProductIcpRegionSync.php` (writes `locations` with `regions`), `app/Http/Controllers/Api/Signals/LocationController.php` and `routes/api.php` (new endpoints).
- **Frontend files:** `components/domain/signals/searchable-select.tsx`, `location-picker.tsx` (new), `region-selector.tsx`, `company-opportunities.tsx`, `lib/location-api.ts` (new), `lib/region-scope.ts`, `services/signals/opportunities.ts`.
- **Order:** backend first. An older frontend keeps working against the new backend (names are still accepted and are now validated and canonicalised). A new frontend against an older backend cannot load the lists, and the control says so instead of falling back to a short list.
- **Behaviour change to tell users:** an invalid combination (a state in the wrong country, a city in the wrong state) is now refused with a reason instead of being accepted; a name such as `USA` is stored as `United States`.
- **Check on staging or a test tenant:** India > Gujarat > Surat saves `locations` with `IN`, `GJ` and Surat's id on a sub-product's ICP; switching to Singapore replaces it entirely (no Gujarat or Surat left); India + Texas is refused; Whole Product with the same place changes no ICP; the old `GET /signals/locations` still answers.
- **Licence:** ODbL-1.0 data. Confirm the share-alike question in `NOTICE.md` with the owner before the data is exposed outside the organisation.
