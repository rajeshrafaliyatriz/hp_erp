# WPD2 test map: the five dead Portfolio components and the seven test files that read them (23 Nov 2026)

Track S15-WPD2, g2gv0 working tree of branch `milan1` (clean at the start, HEAD `0d0d6da`). Written BEFORE any file was deleted or any test edited.

## 1. The five files are dead (verified by grep over the whole front end, node_modules, .next and .git excluded)

| File | Lines | Who mentions it |
|---|---|---|
| `components/domain/portfolio/portfolio-view.tsx` | 781 | only tests; it imports the other four |
| `components/domain/portfolio/product-form-dialog.tsx` | 515 | only `portfolio-view.tsx` and tests |
| `components/domain/portfolio/main-product-form-dialog.tsx` | 255 | only `portfolio-view.tsx` |
| `components/domain/portfolio/main-product-delete-dialog.tsx` | 209 | only `portfolio-view.tsx` |
| `components/domain/portfolio/new-product-dialog.tsx` | 123 | only `portfolio-view.tsx` and a test |
| total | 1,883 | |

`app/portfolio/page.tsx` renders `PortfolioList`; no file under `app/`, `components/` (other than the five), `lib/` (non-test), `hooks/` or `services/` imports a symbol of the five (`PortfolioView`, `ProductFormDialog`, `MainProductFormDialog(Props)`, `MainProductDeleteDialog(Props)`, `NewProductDialog`). `lib/portfolio/icp-state.ts` names `product-form-dialog.tsx` only in a comment (no import). Tag: verified.

## 2. Where the old features live now (read in source)

| Old (dead) | Live home |
|---|---|
| "Select Product" dropdown | the Product filter of `portfolio-list-toolbar.tsx` |
| Add Product (an offer) and Edit offer: `ProductFormDialog` | `product-form-drawer.tsx` (mode create-sub-product / edit), opened by `portfolio-list.tsx` and `sub-product-page.tsx` |
| `parseIcpDataForModel` (the dialog's own ICP parse) | `lib/portfolio/icp-state.ts` (`parseIcpForModel`, `icpStatesFromOffer`), already moved there |
| New Product (administrators): `NewProductDialog` | `product-form-drawer.tsx` mode create-product (`AddProductForm`), button "Add product" in `portfolio-list.tsx` |
| Offer hard delete, delete own Product | Archive (owner decisions D-8 and D-9): `sub-product-page.tsx`, `product-detail-view.tsx` |
| Readiness confirmation dialog | `sub-product-overview.tsx` |
| Edit Product (rename, category, description, status): `MainProductFormDialog` edit mode | none (see section 5, G2) |
| Delete Product with dependency counts: `MainProductDeleteDialog` | Archive this product (no dependency counts) |
| `/portfolio?product=<key>` redirect | none (see section 5, G1) |

## 3. The test map: every `it(` of the seven files (88 before)

Words: KEPT = untouched. NARROWED = kept, but one assertion that read a dead file is dropped (named). RE-POINTED = the same intent, now read from or run on live code (named). DELETED = asserts only something about a dead file, with the live home named.

### 3.1 `lib/company-opportunities-wiring.test.ts` (26)

| # | Test | Verdict |
|---|---|---|
| 1 | opens that one product's own page, not the whole Product Portfolio | KEPT |
| 2 | points at a route that exists, and the old ?product= link still lands on that product | NARROWED, renamed "points at a route that exists". The two `existsSync` route checks stay. Dropped: the regex `router.replace(configureHref(linked))` on `portfolio-view.tsx`. That redirect has no live home (section 5, G1) |
| 3 | has a link to the product's sub-products (where each ICP is set)... | KEPT |
| 4 | reads a product's ICP state only from what the server says about each of its sub-products | KEPT |
| 5 | enables universal signal generation on Company opportunities without blocking ICP requirements | KEPT |
| 6 | keeps the four routes | KEPT |
| 7 | /signals renders Company opportunities | KEPT |
| 8 | offers "All products" for reading, and never passes "all" to a run | KEPT |
| 9 | keeps the secondary tools reachable under Advanced tools | KEPT |
| 10 | does not enable automatic generation anywhere in the frontend | KEPT |
| 11 | has no Sales cards / Full detail toggle and shows one list | KEPT |
| 12 | offers dates via recent-5 dropdown, calendar date picker, and an All Signals view | KEPT |
| 13 | takes its dates from the backend history, not from the clock | KEPT |
| 14 | choosing a date only reads | KEPT |
| 15 | starts research only from a click | KEPT |
| 16 | keeps the review actions on each signal, now the Signal Review's | KEPT |
| 17 | wraps all four module pages in the scoped premium theme | KEPT |
| 18 | keeps the theme scoped to light mode ... and respects reduced motion | KEPT |
| 19 | shows failures inline: no native alert() in the four modules | RE-POINTED: `portfolio-view.tsx` replaced by the live Portfolio screen files `portfolio-list.tsx`, `portfolio-more-menu.tsx`, `product-form-drawer.tsx` (the other three modules unchanged). **Found and fixed while proving it:** the committed regex held a literal backspace byte (0x08) where `\b` was meant (`/<BS>alert\(/`), so the test could never fail, for any of its files. It is now `/\balert\(/`, and the mutation "a native `alert('failed')` added to `portfolio-more-menu.tsx`" is caught. All six files really are clean |
| 20 | starts a new partner blank | KEPT |
| 21 | has one guided path from configuration to signals on the Product Portfolio | KEPT |
| 22 | gives the page header a dark-mode variant | KEPT |
| 23 | the Partner Type select has an explicit empty choice | KEPT |
| 24 | the partner capacity field shows no invented number and allows 0 | KEPT |
| 25 | double-submit guards are synchronous refs | KEPT |
| 26 | the partner "Try again" really reloads the filters and totals | KEPT |

### 3.2 `lib/customer-model.test.ts` (14)

| # | Test | Verdict |
|---|---|---|
| 1 | offers B2B, B2C, B2G and Both, each with a note about signals | KEPT |
| 2 | is saved and read back, and left out of an ICP that never set it | KEPT |
| 3 | a new product ICP starts as B2B | KEPT |
| 4 | the sub-product ICP form shows the choice | KEPT |
| 5 | the sub-product ICP form uses a multi-line box with no maxLength on the ideal-customer field | KEPT |
| 6 | checks the ICP on the server first and stops with the problems, saving nothing | RE-POINTED: `product-form-drawer.tsx`: `productIcpService.checkConfig` comes before `productRecordService.create(body)` / `update(record.id, body)`; text now "Fix the Ideal customer section. Nothing was saved." Behavioural twin that stays: product-form-drawer.test.tsx "stops before saving when the ideal customer check fails" |
| 7 | a sentence with commas is one item; "a, b, c" is three | KEPT |
| 8 | a trigger sentence with commas stays one trigger | KEPT |
| 9 | sub-products are saved with their individual ICP directly without creating a top-level product | RE-POINTED: the drawer sends `buildPayload({ ..., icp: built.configs })` (the old `toPayload(form, mode, icpConfig)`); form-model.test.ts "sends both sections together, and the ICP when given" is the behavioural twin |
| 10 | is kept whole up to 500 characters, and cut at a whole word beyond that | KEPT |
| 11 | sub-products belong to their parent product and never create a master product | RE-POINTED: the drawer's Sub-product form never calls `productIcpService.createProduct` |
| 12 | guidance allows 5,000 characters in the sub-product ICP form | KEPT |
| 13 | asks first, says what is kept, and only your own products get the button | NARROWED, renamed. The pure `deleteProductConfirmation` text and the service's DELETE call stay. Dropped: the two regexes on `portfolio-view.tsx` (admin-only "Delete" button for a custom product, `window.confirm`). Live home of that behaviour: Archive (D-8, D-9), proved by sub-product-page.test.tsx "asks before archiving, keeps the record when the answer is no, and archives when it is yes" and sub-product-page-carried.test.tsx "says what the server answered when archiving is refused, and the record stays as it was". `deleteProductConfirmation` and `productIcpService.deleteProduct` have no live caller any more (section 6) |
| 14 | opens the edit form with the full offer, and fills the form from the saved ICP | RE-POINTED: the drawer reads `portfolioService.getOffer(context, record.offer.id)` and `icpStatesFromOffer(...)`; the trigger and freshness parse is now pinned by running `lib/portfolio/icp-state.ts` itself (3 new tests, section 4) instead of a regex on the dialog |

### 3.3 `lib/product-key.test.ts` (6)

| # | Test | Verdict |
|---|---|---|
| 1 | suggests a valid key from the name | KEPT (the product-key rule) |
| 2 | refuses invalid keys, reserved words and bad names before asking the server | KEPT (the product-key rule) |
| 3 | shows the server's reasons next to the right field | KEPT (the product-key rule) |
| 4 | New Product is its own admin-only action and Add Product still adds an offer | RE-POINTED: `portfolio-list.tsx`: "Add product" (`can.createProduct`, mode create-product) and "Add sub-product" (`can.createSubProduct`, mode create-sub-product) are separate buttons; PLA "shows Add product to an administrator only..." is the behavioural twin |
| 5 | creating a product calls only the product-creation API and then opens that product's sub-products | RE-POINTED and NARROWED, renamed "creating a product calls only the product-creation API": the drawer's `AddProductForm` calls `productRecordService.create({ kind: 'product', ... })` and never `portfolioService`. Dropped: `router.push(offersHref(key))` (after creating, the old dialog opened the new product's sub-products; the list now reloads and says "Saved."). Section 5, G3 |
| 6 | the signal card offers the comparison, which only reads | KEPT |

### 3.4 `lib/product-page.test.ts` (4)

| # | Test | Verdict |
|---|---|---|
| 1 | every built-in product (and any key) has a sub-products link that points at the dynamic route that exists | KEPT |
| 2 | the portfolio has a product dropdown and no product-level ICP badge or Edit ICP button | RE-POINTED: the toolbar has the Product filter (`<Facet label="Product"`); none of `icpHref`, `IcpStatusBadge`, `Edit ICP`, `View ICP` appears in the list screen, table, toolbar or More menu. PLS "offers the Products and the readiness words the server knows" is the behavioural twin |
| 3 | an unknown key shows a not-found state, not an error | KEPT |
| 4 | the page has no ICP tab or editor ... and shows every stored offer detail | KEPT |

### 3.5 `lib/product-portfolio.test.ts` (24)

| # | Test | Verdict |
|---|---|---|
| 1-13 | "product form": requires an ID...; does not ask for the ID when editing; rejects an ID the server would reject; sends blank optional fields as null; round-trips an existing product; serializes individual ICP into icp_config; extracts existing individual ICP from an offer; shows the server's field errors; toggles a taxonomy code; splits legacy need codes; handles custom "Other" entries; validates free-text customer problems limits; asks before deleting, names the product | all 13 KEPT: pure functions of `lib/product-form.ts`, which is a live module (`addCustom`, `customEntries`, `removeEntry`, `splitNeeds`, `problemLines`, `nextProductId` are still used by live code; the rest have no live caller now, section 6) |
| 14-17 | "the ICP's product list": adds a product once...; offers only products not already chosen; keeps "none chosen"...; never adds a product by itself | all 4 KEPT |
| 18-20 | "how a comparison reads": says "Insufficient data"...; groups scores...; says what the comparison is of | all 3 KEPT |
| 21 | Add Product and Edit open a real form (they used to set state that nothing rendered) | RE-POINTED: `portfolio-list.tsx` renders `<ProductFormDrawer open={drawer !== null}` and has "Add product" / "Add sub-product"; `sub-product-page.tsx` renders `<ProductFormDrawer ... mode="edit"`; the drawer calls `productRecordService.create` and `.update` |
| 22 | Delete is a button that confirms first and shows the server's reason when a product is in use | DELETED. It asserted only the dead view's offer hard delete (`window.confirm(deleteConfirmation(offer.name))`, `setActionError(apiErrorText(...))`). The hard delete was replaced by Archive (owner decisions D-8, D-9), proved with behaviour, not regexes: sub-product-page.test.tsx "asks before archiving, keeps the record when the answer is no, and archives when it is yes"; sub-product-page-carried.test.tsx "says what the server answered when archiving is refused, and the record stays as it was"; portfolio-list-actions.test.tsx "asks before it deletes, says where the rows go, and sends nothing when the person says no" (bulk delete to the Recycle Bin) |
| 23 | a change to the portfolio refreshes the list and the products, on the portfolio and on the product page | RE-POINTED: the list's `onSaved` closes the drawer, says "Saved." and calls `void load()`; the Product page assertion on `ProductFormDrawer onSaved ... reload() ... void loadSubs(1)` is unchanged |
| 24 | the main-product ICP editor, its product picker and its comparison are gone | KEPT, extended: the list of files that must not exist now also names the five retired components, so a copy cannot come back |

### 3.6 `lib/add-product-form.test.ts` (9)

| # | Test | Verdict |
|---|---|---|
| 1 | has every one of the 15 product fields | RE-POINTED to `product-form-about.tsx`, under the live words (Category is Product, Status is Readiness, Product ID is ID, Description is What it does): every `label="..."` and the two tick lists with their two "Other ..., then Add" boxes |
| 2 | is one form again (no steps), with the Individual ICP below the product details | RE-POINTED: the drawer has no `WIZARD_STEPS`, `STEP_FIELDS` or `setStep`; its sections run About, Sell, Ideal customer in that order; the ICP section still carries "Individual Ideal Customer Profile (ICP)" |
| 3 | requires the four required fields, and nothing else | KEPT (pure, `lib/product-form.ts`; the live twin is form-model.test.ts "needs a name, and for a new About a Product, an ID and a readiness") |
| 4 | takes the dropdown options from the server taxonomy, not from hard-coded lists | RE-POINTED to `product-form-about.tsx`: `taxonomy?.readiness_statuses`, `deployment_models`, `segments`, `signal_families`; the Products come from the server's list (`choices`), not from `taxonomy.parent_products` |
| 5 | an untouched ICP section creates nothing | KEPT |
| 6 | is linked to this product only and carries its own part, triggers, searches and exclusions | KEPT |
| 7 | invents nothing: blank boxes give no triggers... | KEPT |
| 8 | makes valid product keys from product ids | KEPT |
| 9 | saves the sub-product with its individual ICP directly and never creates a top-level product in the master product list | RE-POINTED: the drawer saves through `productRecordService.(create\|update)` with the `icp` configs, no `productIcpService.createProduct`, no `localStorage` or `sessionStorage` |

### 3.7 `lib/product-id-suggestion.test.ts` (5)

| # | Test | Verdict |
|---|---|---|
| 1 | is the category code with the highest number in use plus one, two digits at least | KEPT (the ID suggestion rule) |
| 2 | ignores IDs that are not CODE-number, other categories, and is case-insensitive | KEPT |
| 3 | suggests nothing for a category without a code or an empty choice | KEPT |
| 4 | shows Category and Status before Product ID and Name | RE-POINTED: `product-form-about.tsx` shows Product before ID before Name (the Product decides the suggested ID). The Status/Readiness position is no longer pinned: it comes after Name in the live form, by design |
| 5 | fills the ID from the category in add mode, leaves a typed ID alone, and the ID stays editable | RE-POINTED: the drawer's `suggestId` uses `nextProductId(product.portfolioName, ...)`, `setProduct` suggests only when `!editing && !idTouched`, `setOfferId` marks a typed ID, the ID input is disabled when the record already has an About. Behavioural twin: product-form-drawer.test.tsx "opens under a Product with its next ID suggested, and three sections" |

## 4. Tests added (3)

`lib/customer-model.test.ts`, block "The ICP state of a saved sub-product (lib/portfolio/icp-state.ts)". The dialog's parse moved to `lib/portfolio/icp-state.ts` earlier, but nothing tested it directly (it was pinned only by regexes on the dead dialog and, indirectly, by drawer tests). They replace those regexes:
1. `parseIcpForModel` reads a stored ICP the way the dead dialog did: lists joined by newlines, a trigger shown in full (`pain` before its phrases), freshness from the first trigger's `decay`, the source, queries by `q`, exclusions by `label`, a missing name defaults to "<product> B2B ICP".
2. `icpStatesFromOffer` finds the three models in `individual_icps`, in a single `individual_icp` / `icp_config` by its `customer_model` (no model means B2B), and gives three empty models for nothing.
3. `changedIcpModels`, `icpModelsToSend` and `buildIcpConfigs`: an unchanged model is not sent, a changed and filled one is, an empty one is not, and a model with no name is a problem on its tab, not a config.

## 5. Behaviour that lived only in the dead files and has no live home (reported, NOT fixed here: this track does not touch live components)

- G1. `/portfolio?product=<key>` (an older link) used to redirect to `/portfolio/<key>` (`portfolio-view.tsx` lines 236-241). The live list reads `product` as a Product's number (`productIdOf`), so a key there shows the whole list. Not in the 13 Nov audit (B5) or the WPD parity checklist. Fix, if wanted: in `PortfolioList`, when `query.filters.product` is not a whole number and looks like a key (`^[a-z][a-z0-9_]{2,19}$`), `router.replace(configureHref(key))`.
- G2. Edit Product (rename, category, description, status of a Product): `MainProductFormDialog` edit mode. The live form says a Product's name and key cannot be changed there, and the Product page has only Add sub-product and Archive. `productIcpService.updateProduct` has no caller. Not in the audit either.
- G3. After "Add product", the old dialog opened the new product's sub-products; the live list reloads and says "Saved.".
- G4 and G5 (decided, listed for completeness): hard delete of an offer and of one's own Product, and the dependency-count dialog before deleting a Product, are replaced by Archive (D-8, D-9).

## 6. Exports with no live caller after the deletion (kept; deleting them is a separate decision)

- `lib/product-form.ts`: `emptyProductForm`, `fromOffer`, `toPayload`, `validateProductForm`, `serverErrors`, `toggle`, `deleteConfirmation`, `deleteProductConfirmation` and their types (tests only).
- `services/signals/product-icp.ts`: `createProduct`, `updateProduct`, `deleteProduct`, `getProductDependencies`, type `ProductDependencies` (the tests in 3.2 #13 and 3.5 #24 still read the service's shape).
- `services/portfolio/portfolio-service.ts`: `createOffer`, `updateOffer`, `deleteOffer`, `getOffers`.

## 7. Arithmetic and proof (verified, 23 Nov 2026)

| | Tests |
|---|---|
| In the seven files before | 88 (verified: all pass) |
| Kept as they were (one of them extended) | 69 |
| Narrowed (kept, one dead-file assertion dropped) | 2 (company-opportunities-wiring #2, customer-model #13) |
| Re-pointed at live code | 16 |
| Deleted | 1 (product-portfolio "Delete is a button that confirms first...") |
| Added | 3 (the ICP state helpers) |
| In the seven files after | 88 - 1 + 3 = 90 (verified) |
| Whole front-end suite before | 3,726 (given) |
| Whole front-end suite after | 3,726 - 1 + 3 = 3,728 (verified: 3,728 tests, 3,728 pass, 0 fail, 671 suites, `node --import tsx --test "lib/**/*.test.ts" "lib/**/*.test.tsx" "packages/**/*.test.ts"`) |

- `tsc --noEmit -p .` exit 0 (the edited test files are inside its include list). `eslint --max-warnings=0` on the seven test files and `lib/portfolio/icp-state.ts`: exit 0.
- The address guard (`lib/portfolio-address-gone.test.ts`, 3 tests) and the vocabulary scans (`lib/portfolio-list/vocabulary.test.ts`, `lib/portfolio/scans.test.ts`) pass in the full run.
- **Mutation proofs of the re-pointed pins (verified; each mutation was applied to the live file, the seven files run, the file restored at once; 18 of 18 caught):** ICP freshness dropped; "no customer model means B2B" removed; untouched models sent; trigger phrase before pain; refusal sentence changed; create call renamed; ID suggested even after typing; ID editable after the first save; "Add product" opening the Sub-product form; no reload after a save; `localStorage` in the drawer; an "Edit ICP" string in the table; the About "Notes" label renamed; a native `alert(` in the More menu; Edit opening the create form; a Product kind inside the Sub-product form; a save placed before the ICP check; and the Add product form calling the Portfolio API. Two first attempts survived (the `alert` one, because of the backspace byte in the original test, now fixed; a badly built "save before check" mutation, rebuilt) and are not counted as caught until the rerun caught them.
- Not run: a browser walk (this track changes no screen); live database (none touched).
