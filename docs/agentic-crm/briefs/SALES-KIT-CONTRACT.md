# Sales Kit + Portfolio grid view: the contract (27 Nov 2026)

One written contract for three tracks (A backend, B Sales Kit tab, C grid view). Owner's words: a **Sales Kit** tab on the Product page (demo presentations, documents, videos, images; seen by sales, managed by administrative / sales / HR; **managed from Role & Permissions**), connected to the platform **Document Library**; the ScholarClone deck "What is your school not seeing?" = the PowerPoint + the 28-slide web deck as two kit items (NOT a course); and a **List | Grid** view on `/signals/portfolio`. Plan: `C:\Users\MILAN\.claude\plans\fizzy-singing-lemur.md` (read it first). Step 0 (the "N of M sub-products have an ICP" badge) is already done and committed (g2gv0 3e0116c).

## Rules for every track
- Repos: backend `C:\Users\MILAN\Downloads\hp_erp` (branch Milan-2), front end `C:\Users\MILAN\Downloads\g2gv0` (branch milan1). **Do not commit, push, branch, stash or touch git state** (the integrator commits by explicit path). Touch only the files your track owns (below); if you need a change in another track's file, say so in your report.
- **Do not start sub-agents.** **Never write to the live database db3 (`--database=live`, the `live` connection)**; reads of db3 are not needed. Dev migrations only with `php artisan migrate --path=<that one file>` (never unscoped). Do not send any email/SMS. Do not start a dev server (the integrator runs the browser pass). Do not upload to the real Spaces bucket (tests use `Storage::fake`).
- Read the rules you touch: `Docs/agentic-crm/DB-RULES.md` (additive, guarded, no FK, no JSON, utf8mb4 stated, MariaDB 10.1), memory notes `g2g-rights-table-traps.md`, `g2g-menu-hide-wipes-rights.md`, `g2g-leaf-only-grant.md` in `C:\Users\MILAN\.claude\projects\c--Users-MILAN-Downloads-hp-erp\memory\`.
- Code style = the surrounding code (comment density, naming). Words in UI text: sentence case, plain, specific ("Download PowerPoint", "Open interactive deck", "Play video", "Add to Sales Kit"); an action keeps one name through the flow. No "item/offer/catalogue" in UI text on portfolio files (a vocabulary test bans them). No em-dash stuffing, no "→" in labels.
- Report: files changed (full paths), tests added and the exact command + result, mutation proofs you ran and what each broke, anything you could not do, anything you found that contradicts this contract. Tag claims verified / read in source / inferred.

## Data model (Track A)

New table `g2g_product_kit_items` (additive migration `2026_12_30_090000_create_g2g_product_kit_items_table.php`; check `ls database/migrations` first so the name sorts after the newest):

| column | type | notes |
|---|---|---|
| id | bigint unsigned auto | |
| sub_institute_id | bigint unsigned NOT NULL | the tenant; every read/write scoped by it. A SHARED product (master row tenant 0) takes per-tenant kit rows. |
| product_master_id | bigint unsigned NOT NULL | `g2g_product_master.id`, **kind = product only** (a sub_product id is refused with 422) |
| kind | varchar(12) NOT NULL | category: `presentation` \| `document` \| `video` \| `image` |
| title | varchar(191) NOT NULL | |
| description | varchar(500) NULL | "what it is for" |
| document_id | bigint unsigned NULL | pointer to `document_library.id` (file items) |
| url | varchar(500) NULL | link items: an `https://` URL or an app-relative path starting with `/` (the web deck) |
| sort_order | int NOT NULL default 0 | |
| created_by, updated_by | bigint unsigned NULL | |
| created_at, updated_at | timestamp NULL DEFAULT NULL | |
| deleted_at | timestamp NULL DEFAULT NULL | soft delete |

Index `(sub_institute_id, product_master_id, deleted_at, sort_order)`. **source** is derived: `document_id` set = file, else link. Exactly one of `document_id` / `url` is set. Raw guarded DDL for mysql/mariadb (like `2026_12_07_090000_create_g2g_product_master_table.php`), portable Schema for sqlite; `down()` drops only this table.

Files live in `document_library` (native row, `visibility='organization'`, `category='organization'`, `document_type='Sales Kit'`, history row, written exactly like `HasCrmDocuments::attachDocument`) AND get a `document_library_links` row (`linked_type='PRODUCT'`, `linked_id` = product master id, tenant stamped). Deleting a kit item soft-deletes the kit row and removes its link row; the library document stays.

## Rights (Track A)

A new leaf menu row **Sales Kit**, `access_link = '/portfolio/sales-kit'`: migration `2026_12_30_090100_add_sales_kit_menu_row.php` inserts it (idempotent on `access_link`; sibling of the `/portfolio` row: same `parent_id` and `level`, sort_order + 1, `status 1`, `sub_institute_id` NULL, icon `FolderOpen`; do not pin an id; same style as `2026_08_26_140000_add_my_assessment_menu.php`). The migration grants nothing. Rights are `tblgroupwise_rights_g2g` rows (`menu_id`, `profile_id`, `sub_institute_id`, `can_view/can_add/can_edit/can_delete`; read the table's real columns with `SHOW COLUMNS` on dev first; an individual `right_*` can only DENY, never widen: `MenuRight.php:56-93`). Every route is guarded `platformright:/portfolio/sales-kit,<view|add|edit|delete>` (see `routes/product_master.php:75`), plus tenant scoping in code.

Grants are a separate artisan command `crm:grant-sales-kit-rights {--tenant=6} {--apply}` (class next to `CrmRetireMasterFieldsMenuCommand`): dry run by default; resolves profiles by NAME inside the tenant via `tbluserprofilemaster.sub_institute_id = tenant` (never scopes the rights table on its text/CSV `sub_institute_id`); proposed matrix: **Admin, HR, HR Executive = view+add+edit+delete; Reporting Manager, Employee = view**; leaf only (never an ancestor); never overwrites an existing row; prints each profile with its ACTIVE user count (`tbluser.status = 1`, `deleted_at` null) so the audience is visible; `--apply` writes in one transaction and saves a before-image + summary JSON under `Docs/agentic-crm/_evidence/sales-kit-2026-11-27/`; a re-run changes nothing. Dev only until the owner says go.

## API (Track A builds, B consumes). All under `/api/product-master`, middleware `api`, `api.token`. JSON envelope `{ "status": 1, "data": ... }`; errors `{ "status": 0, "message": "..." }` with 401/403/404/422/503. A host without the table answers 503 `not_set_up` with a plain sentence (the Outreach pattern).

`KitItem` = `{ id, kind: 'presentation'|'document'|'video'|'image', title, description: string|null, source: 'file'|'link', documentId: number|null, fileName: string|null, mimeType: string|null, size: number|null, url: string|null, sortOrder: number, createdBy: number|null, createdByName: string|null, createdAt: string, updatedAt: string }`

| route | right | body / query | answer |
|---|---|---|---|
| `GET /{id}/kit` | view | | `data: { product: {id,name}, items: KitItem[] (sort_order, then newest), counts: {all,presentation,document,video,image}, can: {view,add,edit,delete}, limits: {maxUploadKb, extensions: string[]} }` |
| `POST /{id}/kit/files` | add | multipart `file` (required), `title?`, `description?`, `kind?` | 201 `data: KitItem`. Kit-only validation: extensions `pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png,webp,gif,mp4,webm,mov`, max `config('documents.kit_max_upload_kb')` (default 102400 = 100 MB; env `DOCUMENTS_KIT_MAX_UPLOAD_KB`); the library's own `documents.allowed_extensions` / `max_upload_kb` are NOT changed. Kind from the extension (ppt/pptx -> presentation; pdf -> document; doc..csv -> document; images -> image; video -> video); `kind` may override only pdf/pptx/ppt to presentation or document. Title defaults to the file name without extension. No duplicate-content refusal (the same deck may sit in two products). |
| `POST /{id}/kit/links` | add | JSON `{ title, url, kind, description? }` | 201 `data: KitItem`. `url` must be `https://...` or start with a single `/`; otherwise 422. |
| `POST /{id}/kit/from-library` | add | JSON `{ documentId, title?, description?, kind?, makeShared?: bool }` | 201 `data: KitItem`. The document must be visible to the caller (`DocumentAccess::canView`) in the same tenant. If its visibility is not `organization`: 422 `{message, code:'not_shared'}` unless `makeShared:true` AND the caller owns it (then set `visibility='organization'`). |
| `GET /{id}/kit/library-search?q=` | add | `q` min 2 chars | `data: [{ id, title, fileName, mimeType, size, visibility, ownedByMe }]` max 20, only documents the caller may see, newest first, excluding ones already in this product's kit |
| `PATCH /{id}/kit/{itemId}` | edit | `{ title?, description?, kind?, sortOrder?, url? }` (`url` only for link items) | `data: KitItem` |
| `DELETE /{id}/kit/{itemId}` | delete | | `data: { id }` |
| `GET /{id}/kit/{itemId}/download` | view | `?inline=1` | the file bytes (stream via the document storage, `Content-Type` from the row, `Content-Disposition` attachment or inline); 404 for a link item |

`GET /api/product-master/meta` gets four more booleans inside `can`: `kitView, kitAdd, kitEdit, kitDelete` (from `MenuRight::can` on the Sales Kit row; false when the row or table is absent).

Counts and the list never include soft-deleted rows or rows of another tenant; a kit row whose document was deleted from the library is returned with `fileName:null` and a flag `missing:true` (add `missing: boolean` to KitItem) instead of failing the whole list.

## Front end contract (Track B owns; Track C does not touch these)

- `services/product-master/kit-types.ts` (types above), `services/product-master/kit-service.ts` (`SalesKitApi` interface + real service using the same fetch/auth helper the other product-master services use), `services/product-master/kit-fake-service.ts` (in-memory fake for tests, like `fakeOutreachService`).
  `SalesKitApi`: `get(productId)`, `uploadFile(productId, file, {title?,description?,kind?}, onProgress?)`, `addLink(productId, {title,url,kind,description?})`, `fromLibrary(productId, {documentId,title?,description?,kind?,makeShared?})`, `searchLibrary(productId, q)`, `update(productId, itemId, patch)`, `remove(productId, itemId)`, `fetchFile(productId, itemId, {inline?}): Promise<Blob>`.
- Pure model `lib/portfolio/sales-kit-model.ts` (+ test): filter chips All / Demo presentations / Documents / Videos / Images with counts, kind labels, file-size text, type tile choice, sort, the empty-state wording for a viewer vs a manager, `acceptedExtensions` text.
- Tab: id `kit`, label **Sales Kit**, in `lib/portfolio/page-model.ts` (`ProductTabId`, `PRODUCT_TAB_LABELS`, `PRODUCT_TAB_IDS`, `resolveProductTab('kit')`), shown on a Product page only when `meta.can.kitView`; count badge = total items. Component `components/domain/portfolio/product-sales-kit.tsx` (+ small siblings named `product-sales-kit-*.tsx`) mounted in `product-detail-view.tsx` like the other panels. Portfolio-only products (the short tab list) get no kit tab.
- Neutral deck route: `app/signals/portfolio/deck/[product]/page.tsx` + `layout.tsx` re-using the existing client-presentation player and font layout from `app/module/lms/learning/client-presentation/` (the param must be named `product`); the old LMS route stays untouched. The kit's web-deck link item is then `url: '/signals/portfolio/deck/scholarclone'`.
- Hide the new menu row from the sidebar in code (`lib/signals-nav.ts`, the same fold pattern as the Outreach rows: the row's `access_link` `/portfolio/sales-kit`; it must stay `status 1` in the database).
- Does NOT change `components/ui/**`, and no cross-domain imports (copy patterns, do not import across domains).

## Grid view contract (Track C owns; B does not touch these)

Files: `components/domain/portfolio/portfolio-list.tsx` and new `portfolio-list-grid*.tsx` / `portfolio-layout-toggle.tsx`, `lib/portfolio-list/*`, and the tests that pin the list design (listed in the plan, Step 4). `useUrlTab(['list','grid'], 'layout')` for the address; remembered in localStorage (try/catch). List stays default; filters/sort/page survive the switch. See the plan, Step 4.

## Design brief (all FE work; from the official `frontend-design` skill, read whole 27 Nov 2026)
Ground it in the subject: the Sales Kit is the shelf a salesperson opens before a call; the Portfolio grid is the catalogue a manager scans. Inside the app's own tokens (`docs/frontend/design-tokens.md`: HSL tokens, never edit `components/ui`). Avoid the generated-page tells: identical shadowed rounded cards with an ALL-CAPS eyebrow on each, middle-dot meta strings ("A · B · C"), "WORD — fragment" labels, a "→" on links, gradient washes, fade-and-slide-up on every card, hover transitions on everything. Spend boldness in ONE place per screen (Sales Kit: a featured presentation at the top with its primary action; grid: the Product card carries its Sub-products inside it, so cards differ in what matters) and keep the rest quiet. Quality floor: 390 px and 1440 px, visible focus, reduced motion, status by text + icon not colour alone, sentence case, empty and error states that say what to do next. Motion only in answer to an action.
