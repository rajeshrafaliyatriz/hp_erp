# Generic Report Builder Engine — Task Management first, then CRM

> Durable copy of the approved plan, committed to the repo so it survives a
> session reset. The Claude-side ephemeral plan file this was written from no
> longer matters once this exists — **this file is the source of truth going
> forward.** Update the checkboxes below as phases land; do not let this file
> drift from reality.

## Context

The user asked to add CRM's full Reports area (All Reports, Activity,
Campaign, Email, Invoice, Lead, Opportunity, Organization and Contact,
Product, Purchase Order, Quote, Sales Order, Tickets, Triz, Shared With Me),
explicitly rejecting the "simple/KPI-card" design of today's CRM Reports and
demanding the same no-feature-left-behind rigor as the earlier Sales/
Marketing/Support CRM migrations.

Investigation (3 parallel Explore agents + a direct read of the actual legacy
CRM source at `C:\Users\MILAN\Downloads\crm`, a real vtiger install for this
same client, "Triz") found:

- **Today's CRM "Reports" is a 3-day-old, 2-file wrapper**
  (`crm-reports-view.tsx` → `ModuleAiStackConsole`) around the generic,
  admin-curated, table-only AI Stack report engine
  (`App\Domain\AI\Reports\ModuleDataSourceCatalog`), with only 5 of 15+
  needed data sources defined, and no chart rendering anywhere in that
  engine. This is the "too simple" result the user is reacting to — it was
  explicitly scoped that way at the time ("reuse the generic engine, no new
  report UI"), not an oversight.
- **The real vtiger Reports module**
  (full source at `C:\Users\MILAN\Downloads\crm\modules\Reports`) is a
  genuine end-user report builder: 4 report types (tabular/summary/pivot/
  chart), 4 chart types (Pie/Vertical Bar/Horizontal Bar/Line, max 3 data
  fields for bar/line), arbitrary column/filter/grouping selection across
  joined modules, and a real sharing model (owner, Private/Public, explicit
  share to users/groups/roles). "All Reports" and "Shared With Me" are
  **computed views**, not real folders — confirmed via
  `Folder.php::checkDuplicate()`, which explicitly reserves those two names
  as unsavable. "Triz Reports" is **not a stock vtiger concept at all** —
  it's an ordinary custom folder this client created themselves; it needs no
  special content, just to exist as a normal user-creatable folder.
- Asked directly, the user chose the **full generic engine** (not a
  curated-screens-only version), chose to **build the missing CRM Activity
  entity now** (not skip Activity Reports), and chose to build this **for
  Task Management first, then CRM**, as one combined, fully-detailed,
  durably-saved plan.

## Decisions locked in

1. **One new, genuinely generic report engine**, not a CRM-specific or
   Task-Management-specific one and not an extension of the existing
   admin-curated `ModuleDataSourceCatalog` (new namespace
   `App\Domain\Reports\*`, sibling to `App\Domain\AI\Reports`, deliberately
   separate — different engine, different purpose: user-configurable, not
   admin-curated-for-AI-templates).
2. **Module/field registry is hand-authored PHP metadata, not DB
   introspection** — same reasoning vtiger itself follows (an admin/
   install-time curated field list, not "expose every raw column"), and the
   only way to keep a user-configurable column/filter picker from being a
   SQL-injection-shaped surface or leaking internal columns (`deleted_by`,
   raw FK ids, etc.).
3. **Persistence is JSON-first, not over-normalized** — `reports.definition`
   (one JSON column) carries columns/filters/sort/group/pivot/chart config;
   only folders, the report header (type/owner/sharing), and the sharing
   pivot get real relational tables. This deliberately avoids vtiger's own
   10+ table sprawl (`vtiger_selectcolumn`, `vtiger_relcriteria`,
   `vtiger_relcriteria_grouping`, `vtiger_reportsortcol`, ...) for the same
   data.
4. **"All Reports" and "Shared With Me" are computed queries, never real
   folders** — matches vtiger exactly (`Folder.php` reserves both names).
   Sharing reuses this codebase's own existing pattern
   (`DocumentShareService`'s share/unshare/listShares/canEdit shape,
   `CalendarShareService`'s `outgoing()`/`incoming()` naming — `incoming()`
   *is* "Shared With Me").
5. **Custom fields (`tblcustom_fields`/`g2g_custom_field_values`) are
   included as display-only tabular columns in v1**, never as filter/group/
   aggregate inputs — the EAV value column is untyped `TEXT`, and casting it
   for aggregation risks silent wrong numbers (e.g. a bad `CAST` on an empty
   string corrupting a SUM). Merged into row results with one extra keyed
   lookup query per report run, not one join per field.
6. **"Activity Reports" has no new table — CORRECTED mid-implementation.**
   The original plan (below, kept for the record) assumed CRM had zero
   activity/calendar schema and called for a new `crm_activities` table.
   That table was actually built, migrated, and live-verified — then fully
   rolled back on discovering CRM already has a working "Activity" tab
   (`CrmScheduleTab`) that deliberately reuses `task_management_calendar_
   events` (via its own `linked_type`/`linked_id`) instead of a parallel
   CRM-only calendar. The real fix was two new reportable fields
   (`linked_type`/`linked_id`) on the already-registered
   `task_management.events` module — see §2/Phase 2 below for the full
   account.
7. **Phase order is Task Management first** (the proving ground: pivot joins
   via a real pivot table, date-filtered + picklist-grouped summaries, chart
   rendering), **then exposing `task_management.events` for CRM's reuse,
   then CRM's full module registration** — not built simultaneously, so
   neither dilutes the other's quality.
8. **No KPI-card grid anywhere in this feature.** Charts (recharts, already a
   dependency) are the primary visual for chart-type reports; summary/pivot
   reports are grouped/matrix tables; any contextual numbers are a compact
   caption, never a `KPICard` tile grid.
9. **CRM's existing `/module/crm/support/reports` AI Stack screen is left
   exactly as-is** — it's a different, legitimate feature (ad hoc AI-template
   reports), not something this replaces. The new engine's CRM entry point is
   its own **new, top-level menu item under CRM root** (sibling to Marketing/
   Sales/Support), not nested under Support — vtiger's own Reports is a
   top-level tab, and burying it under Support would undercut the prominence
   the user is asking for.
10. **Task Management's existing flat Reports screen (`tm-reports.tsx` —
    Productivity/Delays/Learning tabs) is superseded** by the new shell,
    scoped to 3 folders: Task Reports, Events Report, Assigned Tasks Report
    (vtiger itself has no structural difference between "Task Reports" and
    "Assigned Tasks Report" beyond which default reports/grouping ship in
    each — this is a folder/seed-data distinction, not a registry one).

## Architecture

### 1. Module/field registry

`App\Domain\Reports\ReportModuleRegistry` — merges per-domain `definitions()`
arrays (mirrors how `ModuleDataSourceCatalog::definitions()` concatenates
`$core` + `SourceRegistry::definitions()`).

```php
final class ReportModuleRegistry
{
    public function all(): array;                                    // module key => ReportModuleDefinition
    public function get(string $moduleKey): ReportModuleDefinition;   // throws if unknown
    public function joinableFrom(string $primaryModuleKey): array;    // array<string> module keys
    public function fieldsFor(string $moduleKey, int $subInstituteId): array;       // + tenant's custom fields folded in
    public function dateFieldsFor(string $moduleKey, int $subInstituteId): array;
    public function groupableFieldsFor(string $moduleKey, int $subInstituteId): array;
    public function numericFieldsFor(string $moduleKey, int $subInstituteId): array; // aggregatable
}
```

Value objects: `ReportModuleDefinition` (key, label, table, alias,
tenantColumn, softDeleteColumn, primaryKey, fields[], joins[]);
`ReportFieldDefinition` (key, label, column [fully-aliased SQL fragment, e.g.
`t.task_date`], type [varchar|numeric|date|email|picklist], groupable,
aggregatable, picklistModule/picklistFieldKey, isCustomField/customFieldId)
with static constructors `::varchar()/::numeric()/::date()/::picklist()/
::userRef()`; `ReportJoinDefinition` supporting **direct FK**, **pivot**
(viaPivot/pivotLocalKey/pivotForeignKey — needed for Task↔Project), and
**polymorphic** (polymorphicTypeColumn/polymorphicIdColumn/
polymorphicTypeValue — needed for Campaign targets, Email links, and the new
CRM Activity's `related_type`/`related_id`) joins.

Custom fields: `fieldsFor()` queries `tblcustom_fields` for the module's base
table (tenant-scoped + `common_to_all`), appends each as
`isCustomField: true`, `type: 'varchar'` (display-only regardless of its real
declared type — no cast risk), `groupable/aggregatable: false`. At execution
time, custom-field columns are resolved via one extra
`g2g_custom_field_values` lookup keyed by the primary rows' ids, merged in
PHP — never joined.

### 2. Persistence (new migrations)

**`report_folders`**: id, sub_institute_id, module_scope
(`task_management`|`crm`), name, is_system (bool), owner_id (nullable — null
for system folders), sort_order, created_by/updated_by/deleted_by,
timestamps+softDeletes. No row ever represents "All Reports" or "Shared With
Me".

**`reports`**: id, sub_institute_id, module_scope, folder_id (nullable FK),
report_type (`tabular`|`summary`|`pivot`|`chart`), primary_module (registry
key), title, description, owner_id, sharingtype (`Private`|`Public`, default
Private), **definition (json)**, last_run_at,
created_by/updated_by/deleted_by, timestamps+softDeletes. Indexes:
`(sub_institute_id, module_scope, folder_id)`, `(owner_id)`, `(sharingtype)`.

`definition` JSON shape — **every field reference is a `{module, field}` pair,
never a single concatenated string**: module keys are themselves dotted
(`task_management.tasks`) and so are custom-field keys (`custom.
estimated_value`), so "split on the last dot" is ambiguous the moment a
custom field is involved. Discovered and fixed during Phase 0
implementation (`ReportModuleRegistry::resolve(moduleKey, fieldKey, ...)`
takes two explicit arguments, not one combined string):
```json
{
  "secondary_modules": ["task_management.projects"],
  "columns": [{"module": "task_management.tasks", "field": "status", "label": "Status", "visible": true, "aggregate": null}],
  "standard_filter": {"module": "task_management.tasks", "field": "task_date", "preset": "this_month", "from": null, "to": null},
  "advanced_filter": {"op": "AND", "conditions": [{"op": "OR", "conditions": [...]}, {"module": "...", "field": "...", "operator": "equals", "value": "..."}]},
  "sort_columns": [{"module": "...", "field": "...", "direction": "asc"}],
  "group_by": [{"module": "task_management.tasks", "field": "task_allocated_to"}],
  "pivot": {"rows": [{"module": "...", "field": "..."}], "columns": [{"module": "...", "field": "..."}], "values": [{"module": "...", "field": "id", "aggregate": "count", "label": "Tasks"}]},
  "chart": {"type": "VerticalBar", "group_by": {"module": "...", "field": "..."}, "data_fields": [{"module": "...", "field": "id", "aggregate": "count", "label": "Count"}]}
}
```
Server-side validation on every create/update (never trust the client
payload as execute-safe): every `field` string must resolve via the registry
for `primary_module`/`secondary_modules`; `chart.data_fields` capped at 3
(mirrors vtiger's own bar/line cap); `summary` requires non-empty `group_by`;
`pivot` requires `rows`/`columns`/`values` all present.

**`report_shares`** (modeled exactly on `document_library_shares`): id,
sub_institute_id, report_id (FK CASCADE), viewer_user_id (FK), can_edit
(bool), created_by, timestamps, unique(report_id, viewer_user_id).

**No new `crm_activities` table — CORRECTED during Phase 2 implementation.**
A first pass built exactly that (polymorphic `related_type`/`related_id`,
picklist-backed `activity_type`/`status`/`priority`), ran its migration on
both connections, live-verified it, then discovered — too late to have
checked first — that CRM already has a **working** "Activity" tab
(`CrmScheduleTab`, rendered on Lead/Contact/Organization/Opportunity/
Ticket detail pages) that reuses `task_management_calendar_events` via its
own `linked_type`/`linked_id` columns (`LinkedRecordType = 'LEAD' |
'CONTACT' | 'ORGANIZATION' | 'OPPORTUNITY' | 'TICKET'`,
`CalendarController::linkedTargetExists()`), built specifically **to avoid
a parallel CRM-only calendar** — its own docblock says so. The new table,
controller, and routes were fully rolled back (migration rolled back on
both connections, git commit reset before it ever reached the remote) —
nothing of it shipped. **"Activity Reports" is `task_management.events`
(already registered in Phase 1) filtered to CRM's 5 `linked_type` values**,
not a new entity. The only real change: `linked_type`/`linked_id` added as
reportable fields on that module (see Phase 1's `TaskManagementReportModules::events()`).

### 3. Query execution engine

`App\Domain\Reports\ReportQueryExecutionService::execute(object $report, int
$subInstituteId): array` — builds the base query from the registry (tenant
scope + soft-delete filter applied unconditionally from registry metadata,
**never** from the JSON — a report definition cannot express cross-tenant
access), applies joins (only registry-declared join paths, 3 fixed code
paths for direct/pivot/polymorphic — never user-supplied join SQL), applies
the standard date filter (presets resolved server-side via Carbon, never
trusting a client-sent range for a preset) and the advanced AND/OR filter
tree (recursive, each leaf's `field` resolved through the registry to a
trusted column, `operator` mapped from a fixed whitelist to parameterized
Builder calls), then shapes the result per type:

- **tabular** → `{rows, total, page, per_page}`, paginated, hard-capped at
  2,000 rows.
- **summary** → `{groups: [{label, count, ...aggregates}]}` — `GROUP BY` the
  group-by fields, `COUNT(*)` always included plus one `selectRaw` per
  aggregate column.
- **pivot** → one grouped SQL query over `(rows[0], columns[0])` (never a SQL
  `CROSSTAB` — MySQL has none), pivoted **in PHP** into `{row_labels,
  column_labels, matrix, row_totals, column_totals}`. v1 supports exactly one
  row dimension and one column dimension (matches vtiger).
- **chart** → summary-style grouped query, shaped as `{labels, series:
  [{name, data}]}` (≤3 series) — directly consumable by Recharts.

### 4. API surface

Prefix is `/api/report-builder/*`, **not** `/api/reports/*` — that prefix
already belongs to an unrelated, pre-existing HR/Talent analytics namespace
(`App\Http\Controllers\Reports\*`: kpi, hiring-analytics, employee
lifecycle, department distribution, ...), discovered via `route:list` during
Phase 0 implementation. No route actually conflicts, but two unrelated
"Reports" systems sharing one URL prefix is exactly the kind of thing that
confuses the next person reading the route list.

```
GET/POST/PUT/DELETE  /api/report-builder/folders[/{id}]
GET  /api/report-builder?scope={task_management|crm}&view={folder|all|shared}[&folder_id={id}]
POST/GET/PUT/DELETE  /api/report-builder[/{id}]
POST /api/report-builder/preview               # run an unsaved definition (builder live preview)
GET  /api/report-builder/{id}/run
GET  /api/report-builder/{id}/export?format=csv
GET/POST/DELETE  /api/report-builder/{id}/shares[/{shareId}]
GET  /api/report-builder/modules?scope={..}            # registry introspection for the builder
GET  /api/report-builder/modules/{moduleKey}/fields
```
`view=all` → every report in scope where `sharingtype='Public' OR
owner_id=me OR exists(report_shares WHERE viewer_user_id=me)`. `view=shared`
→ `sharingtype='Private' AND owner_id!=me AND exists(report_shares WHERE
viewer_user_id=me)` — directly mirrors `CalendarShareService::incoming()`.
`canEdit` = owner OR a share row with `can_edit=true` (same boolean shape as
`DocumentShareService::canEdit()`).

### 5. Frontend (g2gv0)

```
components/domain/reports/
  reports-shell.tsx          # FolderSidebar + ReportListTable + "New Report"
  builder/
    report-builder-wizard.tsx   # ModulePicker → ColumnPicker → FilterBuilder → TypeAndChartPicker → SaveDialog
  report-viewer.tsx          # switches on report_type
  tabular-view.tsx / summary-view.tsx / pivot-view.tsx / chart-view.tsx
lib/
  export-csv.ts              # NEW shared util (toCsv/downloadCsv) — no shared one exists today; cm-audit.tsx inlines its own, extract it properly
  # report-chart-colors.ts NOT built — lib/chart-colors.ts already has
  # categoricalColors/categoricalColor, an accessibility-vetted palette for
  # exactly this (colour by identity, CVD-tested, "never cycle" semantics
  # past the 5th group). ChartView reuses it directly instead of a near-
  # duplicate file.
components/ui/
  confirm-dialog.tsx         # promote the ConfirmDialog already in components/settings/sections/section-primitives.tsx to a true shared primitive here, rather than building a second one
```
Reused as-is: `components/ui/table.tsx`, `components/ui/searchable-select.tsx`,
`recharts` (chart patterns straight from
`components/domain/hrms/hrit/leave-management/leave-dashboard/components/{DepartmentChart,LineChart,LeaveTypeChart}.tsx`).
`ChartView` chart-type mapping: Pie→`<PieChart><Pie/></PieChart>` (single
data field only, matches vtiger), VerticalBar/HorizontalBar/Line→
`<BarChart>`/`<BarChart layout="vertical">`/`<LineChart>` with ≤3 series.

## Phasing

- [x] **Phase 0 (backend) — Engine foundation.** *(commit `c6268d84`, hp_erp)*
  - [x] Step 0: write this plan to `hp_erp/Docs/reports-engine/PLAN.md` and commit it.
  - [x] Migrations: `report_folders`, `reports` (longText `definition`, not json() — live's MariaDB predates JSON support), `report_shares`. Run on both `default` and `live`.
  - [x] Classes: `ReportModuleRegistry` (skeleton, empty `all()`), the 3 value objects, `ReportQueryExecutionService`, `ReportShareService`.
  - [x] Controllers/routes — prefix is `/api/report-builder/*`, NOT `/api/reports/*` (that's an unrelated pre-existing HR/Talent analytics namespace, found via `route:list`).
  - [x] Live-verified: empty-registry endpoints respond cleanly, unknown-module create returns a clean 422.
  - [x] **Phase 0 (frontend, g2gv0) — done.** *(commit `32a1c5f`, g2gv0)* `ReportsShell`, `ReportBuilderWizard` (flat AND/OR filter list only — nested-group UI deferred to Phase 4, see the file's own docblock), `ReportViewer` + 4 sub-views, `export-csv.ts`, promoted `confirm-dialog.tsx`. No `report-chart-colors.ts` built — reused the existing `categoricalColors`/`categoricalColor` in `lib/chart-colors.ts` instead. eslint + full project typecheck clean; dev server verified serving without error. No module content registered yet.

**ALL PHASES (0–4) ARE FULLY DONE, backend + frontend.** The generic report-builder engine — module/field registry, 4 report types × 4 chart types, folders/sharing, custom-field display columns, drill-down, result caching, and scheduled/emailed delivery — is live for both Task Management and all 15 CRM modules. See Phase 4 below for its own full breakdown; nothing in this plan remains open except the explicitly-out-of-scope items each phase's own notes already named (e.g. a nested-group filter-builder UI, multi-dimension summary drill-down).

- [x] **Phase 1 — Task Management (the proving ground). DONE.** *(hp_erp `85f93a10`, g2gv0 `82d8596`)*
  - [x] Registered `task_management.tasks` (table `task`), `task_management.events` (table `task_management_calendar_events`), `task_management.projects` (joined via pivot `task_management_project_tasks`) — schema verified directly against the real migrations, not assumed.
  - [x] System folders (Task Reports, Events Report, Assigned Tasks Report) — built as LAZY per-tenant creation (`SystemReportFolders::ensure()`, called from the folder-list endpoint) instead of a migration backfill, so future tenants get them automatically too.
  - [x] Replaced `tm-reports.tsx`'s menu entry (`content-map-m6.ts`, access_link `/module/task-management/reports-and-analysis`, menuId `215`) with `TmReportsShell` → the generic `ReportsShell`. Old screen/endpoints left in place, just unreferenced (grace period, not deleted).
  - [x] **Real bug found and fixed via live testing, not assumed correct:** secondary-module joins used INNER JOIN, silently returning zero rows whenever the joined record didn't exist (e.g. adding a "Project" column to a Tasks report dropped every task with no linked project). Fixed to LEFT JOIN for all join kinds + the target's own soft-delete filter applied safely. Confirmed this matches the real vtiger reference's own behavior ("Contacts by Accounts" vs "Contacts WITHOUT Accounts" are different reports — the join itself must never filter).
  - [x] Live-verified against tenant 7's real 1005 tasks: modules/fields/folders list correctly; tabular (with the Assignee column showing a real joined name, not a raw id), summary (grouped by assignee), pivot (status × priority matrix, totals correct), and chart (count by status) all return correct real data; the LEFT JOIN fix confirmed by re-running the same secondary-module test before/after.
  - [x] **Follow-up, user-reported: "view report option?" + "task/activity report not migrated properly" + "view should be as page".** *(hp_erp `1e505034`, g2gv0 `ecc2b2c`)*
    - [x] Added `SystemReportSeeds::ensure()` (same lazy per-tenant pattern as `SystemReportFolders`) — seeds 6 real, immediately-runnable starter reports into the 3 Task Management system folders instead of leaving them empty: "All Open Tasks" (tabular), "Tasks by Status" (summary), "Today's Events" (tabular, modeled directly on the real vtiger "Today calendar" reference), "This Month's Events by Status" (summary), "Tasks by Assignee" (summary), "Workload by Assignee" (chart, HorizontalBar). Idempotent by `(sub_institute_id, module_scope, title)`. Wired into `ReportFolderController::index()` alongside `SystemReportFolders::ensure()`. Live-verified: all 6 run successfully against tenant 7's real data, re-running `index()` does not duplicate them.
    - [x] Fixed two CSV export bugs found during that verification: `flattenForCsv()` was leaking the internal `__primary_id` key as a literal first column (now excluded), and was emitting raw dotted field keys (`task_management.tasks.task_title`) instead of the report's own human-readable column labels (now mapped via a `$labelFor` closure reading `definition.columns[].label`).
    - [x] Built `components/domain/reports/report-view-page.tsx` — a genuine full PAGE (not a modal/dialog) for viewing a saved report's results, matching the real vtiger reference's layout: title + type/sharing badges, action row (Generate now/Customize/Duplicate/Share/Export CSV/Delete), a read-only filter summary with an "Edit filters" button that opens the builder rather than a second parallel filter editor, record count, and the rendered result. `reports-shell.tsx` now opens this page on row click instead of reopening the builder; row actions trimmed to just Delete since Edit/Duplicate/Share/Export moved to the view page.

- [x] **Phase 2 — CORRECTED, much smaller than planned. DONE.**
  - [x] ~~Build a new `crm_activities` table~~ — built, migrated on both connections, live-verified, then **fully rolled back** on discovering CRM already has a working Activity tab (`CrmScheduleTab`) built specifically to reuse `task_management_calendar_events` instead of a parallel CRM-only calendar. Nothing of the new table shipped (migration rolled back both connections; the commit never reached the remote, so a clean `git reset` was enough — no revert-commit needed).
  - [x] Added `linked_type`/`linked_id` as reportable fields on the already-registered `task_management.events` module (`TaskManagementReportModules::events()`) — the real "Activity Reports" data source. Confirmed via the registry directly: both fields resolve correctly.
  - [x] No new UI needed — `CrmScheduleTab` already exists and already works on Lead/Contact/Organization/Opportunity/Ticket detail pages.

- [x] **Phase 3 — CRM module registration (engine unchanged, reused as-is). DONE.** *(hp_erp `ac9a7af1`, g2gv0 `92450f4`)*
  - [x] Registered all 15 CRM modules (`CrmReportModules.php`: leads, organizations, contacts, opportunities, quotes, sales_orders, purchase_orders, invoices, campaigns, tickets, products, vendors, service_contracts, assets, emails) + `crm.email_links` (the polymorphic link table Email Reports actually needs), with real joins between them verified against the actual migrations, not guessed — schema mapped via a dedicated research pass before writing any code. "Activity Reports" does NOT register its own module — it reuses `task_management.events`, filtered to `linked_type IN ('LEAD','CONTACT','ORGANIZATION','OPPORTUNITY','TICKET')` via an advanced_filter on the seeded canned reports (per Phase 2's own decision).
  - [x] **Scope decision, documented transparently rather than silently dropped:** line-item tables (quote/sales-order/purchase-order/invoice line items), `crm_opportunity_products`/`crm_opportunity_contacts`, `crm_campaign_targets`, `crm_vendor_contacts`, `crm_product_bundle_items`, and every `*_status_history`/`*_comments` table are NOT registered as standalone modules — none carry their own `sub_institute_id`, and the execution engine always scopes a PRIMARY module's query by its own tenant column. Registering one anyway would mean either a fake column (silent SQL error if ever selected as primary) or skipping the tenant check (a real cross-tenant leak). Neither is acceptable, so "Quotes/SalesOrder/PurchaseOrder/Invoice Detailed Report" are richer tabular reports on the parent entity + a join to Organization/Vendor/Contact instead of a line-item explosion — the same display-only compromise already made for custom fields in Phase 0.
  - [x] Seeded CRM's 12 real system folders (`SystemReportFolders`) and 24 canned reports (`SystemReportSeeds::crmSeeds()`) reproducing the legacy vtiger reference set below, built against the real registered modules/joins. Two honest substitutions, not silent drops: "Products by Contacts" → "Products by Category" (no products↔contacts relation exists outside the excluded line-item tables); "Vendors Email Report" dropped entirely (Vendors is not one of the 5 entities `crm_email_links` can ever point at — would always return zero rows).
  - [x] "Triz Reports" needs no code at all — confirmed not a stock vtiger concept, just an ordinary custom folder a user creates themselves via the existing "New folder" button, already fully supported.
  - [x] Added new top-level CRM menu leaf `/module/crm/reports` (`2026_11_28_100300_create_crm_top_level_reports_menu.php`, parent = menu id 199, sibling to Marketing/Sales/Support — run on both `default` and `live`, confirmed present on both afterward) + `CrmReportsShell` wired into `content-map-crm.ts`.
  - [x] Left `/module/crm/support/reports` (AI Stack screen, `CrmReportsView`) completely untouched — confirmed via `route:list` that `/api/report-builder/*` registers with zero collisions against it or any other `*report*` route.
  - [x] **Live-verified, not just "resolves":** a static check confirmed all 30 seed reports (6 Task Management + 24 CRM) resolve every field/join reference against the registry; all 30 then actually executed via `ReportQueryExecutionService` against the live dev database with zero SQL errors. Since the dev database's only pre-existing CRM rows turned out to be soft-deleted (zero live CRM rows anywhere, confirmed by direct query), join correctness was separately proven against real throwaway fixture data (one organization, two contacts — one linked, one not — two leads) inserted directly, confirmed "Contacts by Organizations" groups correctly (1 unlinked under "—", 1 under "Verify Org"), "Contacts without Organizations" returns exactly the unlinked one with its assignee name correctly resolved through the display join, and "Lead by Source"/"Lead Status Report" group correctly — then the fixture rows were deleted, confirmed clean.
  - [ ] Not yet done: a full end-to-end pass with substantial real CRM data once some exists in this environment (the dev database currently has none at all — every tenant's CRM rows are either absent or soft-deleted test fixtures from earlier phases). The structural/correctness verification above stands in for it for now.

- [x] **Phase 4 — Polish/stretch. ALL FOUR ITEMS DONE.**
  - [x] **Custom-field report columns beyond display-only. DONE.** *(hp_erp `aa39db8e`, g2gv0 `0b2bb7d`)* Turned out to be a pre-existing gap one step earlier than the name implies: a custom field column was excluded from the SQL select but nothing ever merged its value back in, so picking one as a tabular column failed the whole report rather than just being display-only. Fixed: `runTabular()` does one extra `g2g_custom_field_values` lookup keyed by the page's own primary ids; `validateDefinition()` rejects a custom field everywhere except a tabular report's own display columns on the PRIMARY module; the Columns step hides custom fields outside tabular mode (inert there, would otherwise falsely block saving). Live-verified against a real throwaway custom field + two answered/one-unanswered real tasks.
  - [x] **Drill-down from a summary group to its member rows. DONE, backend + frontend.** *(hp_erp `54c1bedc`/`1cc0a7d5`, g2gv0 `acd989d`)* `POST /api/report-builder/{id}/drilldown` reruns the report as a synthetic tabular query, reusing its own filters + one extra equality/is_null condition per group-by field, and now also returns its own auto-generated column list (no report-authored columns exist for a drill-down). Frontend: a new `DrilldownDialog` opens on clicking a summary table row, a chart bar/pie slice, or a pivot cell, reusing `TabularView` to render the result. Scoped to single-group-by-field reports (every report this engine seeds today — see `ReportViewer`'s own docblock for why a multi-dimension summary can't reconstruct its per-field values from one joined label string). Live-verified: clicking a real "PENDING" group on a 1005-row summary opened a dialog titled "PENDING" showing "Rows 1-100 of 1005" of correctly-filtered real rows.
  - [x] **Caching expensive pivot/summary runs. DONE.** *(hp_erp `f9fe3334`)* `ReportQueryExecutionService::execute()` caches summary/pivot/chart results (tabular and drill-down excluded) for 180s, keyed by a content-addressed hash of (tenant, report type, primary module, full definition) — editing a report is automatically a cache miss, no separate invalidation path to maintain. "Generate now" passes `fresh=true` to bypass the read while still refreshing the cache for the next viewer. Live-verified: second identical call ~4x faster and byte-identical to the first; different definitions and different tenants each get their own entry (checked explicitly for cross-tenant leakage, given the security-sensitive nature of a tenant-keyed cache).
  - [x] **Scheduled/emailed report runs. DONE.** *(hp_erp `05cceca2`, g2gv0 `c60c9c4`)* A `report_schedules` table (daily/weekly/monthly, plain-email recipients — same precedent as `crm_emails.to_address`, not a `tbluser` FK) + owner-only CRUD (`ReportScheduleController`, same sensitivity posture as `ReportShareController`) + `reports:send-scheduled` (every 15 minutes, `routes/console.php` — NOT `app/Console/Kernel.php`, confirmed dead code in this Laravel 12 app) which atomically claims each due schedule (`last_sent_at` + a "not sent today" `WHERE` guard) BEFORE dispatching `SendScheduledReportJob`, so a queued send can't double-fire even across overlapping ticks. The actual send (`ScheduledReportMailer`) mirrors `App\Services\Crm\CrmOutreachMailer`'s own gate-check shape (`MailGate::allowedForTenant`, mail driver sanity) simplified for admin-configured internal recipients (no unsubscribe link/opt-out/daily-cap to carry over). `ReportCsvExporter` was extracted from `ReportController`'s own flatten/csvCell methods so the interactive export button and this feature share one CSV implementation, not two that could drift. Live-verified without ever triggering a real send: full CRUD round-trip over real HTTP (caught and fixed a real undefined-array-key bug in weekly/monthly validation); the command found a real due schedule, claimed it, and a re-run immediately after dispatched zero (idempotency confirmed); the job correctly invoked the mailer, which correctly refused via `MailGate` for a real gate-disabled tenant (confirmed in the log).

- [x] **Unplanned follow-up — tabular design + date-range filters, requested directly by the user after seeing the shipped report view.** *(hp_erp `7a898447`, g2gv0 `c194396`)*
  - [x] `resolveDatePreset()` expanded: `yesterday`, `next_week`, `last_7_days`, `last_30_days`, and a general `last_n_days`/`next_n_days` pair reading a caller-supplied `n` (explicitly closes the user's own "last 10 days" / "calendar range" ask) — `last_7_days`/`last_30_days` are thin labeled wrappers over the same `last_n_days` math, not new branches.
  - [x] `report-builder-wizard.tsx`'s Filters step: DATE_PRESETS now lists all of the above plus `custom`; `last_n_days`/`next_n_days` reveal a numeric "N days" input; `custom` opens a real two-month `Calendar` (shadcn, `mode="range"`) in a `Popover`, not two bare date-text inputs — no custom-range UI existed at all before this.
  - [x] `runTabular()` defaults to `ORDER BY <primary key> DESC` when a report has no author-chosen `sort_columns` (previously left to the database's own unspecified order) and returns `latest_date` (`MAX()` of the report's own first date column, over every filtered row) — both responses to "the report is not have the latest date is up side". Confirmed live this is a REAL, reproducible problem in this environment: the seeded test data's own dates max out around 2026-04-02, well before the server's actual current date, so every relative-date preset silently returned zero rows with nothing explaining why.
  - [x] `report-view-page.tsx` moves "Last generated"/"Data as of" up next to the title (was a small line near the bottom).
  - [x] `ReportController::run()` accepts optional `page`/`per_page` query params overriding the saved definition's own values for that one view. This closes a REAL, previously-unnoticed gap: `TabularView`'s own pagination controls existed since Phase 0 but were never wired to anything live — every tabular report was silently capped at its first 50 rows, with page 2+ unreachable, until this pass.
  - [x] `tabular-view.tsx` redesigned: real `Card` boundary with header/footer bars (row range, rows-per-page selector, page controls), sticky header over a fixed-height scrollable body, sticky+truncated first column, a `latest_date`-aware empty state. One real bug caught and fixed during THIS session's own live verification, not after the fact: the first column's initial `whitespace-nowrap` treatment pushed every other column off-screen for any report with a long free-text first field (e.g. task titles) — fixed to a `max-w` + `truncate` + `title` tooltip instead.
  - [x] Live-verified end-to-end via an authenticated headless-browser session (Playwright via the globally-installed `playwright-core` + the system Chrome install, cookie/localStorage session injection per the project's own established pattern) against the real dev backend/data — not just typechecked. Caught and fixed 2 real bugs this way (the column-width regression above, and a sticky-header/sticky-column z-index stacking bug) before considering the work done.

- [x] **Unplanned follow-up #2 — real interactivity + a frontend-library research pass, requested directly by the user.** *(g2gv0 `8e32ca4`)* Deep research first (confirmed via direct source-checking, not assumed): TanStack Table is the current shadcn/ui-documented pairing for exactly the interactivity this engine was missing (sort/filter/column-visibility) — the LIVE shadcn docs now use **v9**, not v8, a correction the research itself caught; and Recharts was independently confirmed as the right charting choice over Nivo/Tremor, so the chart work below extends it rather than replacing it. The WAI-ARIA research also simplified the accessibility plan: a read-only report table should stay a plain `<table>` (`aria-sort` on sortable headers is enough), NOT the `role="grid"`/roving-tabindex pattern, which the APG itself reserves for cell-level keyboard navigation or editing.
  - [x] New dependency `@tanstack/react-table@9.3.1` (peer-compatible with the app's React 19). New `data-table-features.ts` — one shared `tableFeatures()` composition (sorting + column-visibility only; pagination deliberately excluded, since it's already server-driven here) reused by both `tabular-view.tsx` and the drill-down dialog embedded inside it.
  - [x] `tabular-view.tsx`: click a column header to sort the current page client-side; a "Columns" dropdown to show/hide columns without re-running the report; a quick-filter input that substring-matches the rows already on screen.
  - [x] **Real bug caught and fixed before shipping:** `accessorKey` with a dotted string (this engine's own row keys, e.g. `"task_management.tasks.task_title"`) is TanStack's NESTED-PATH syntax, not a literal property name — every cell silently resolved to `undefined` (headers rendered, every body cell blank) until switched to `accessorFn`.
  - [x] `chart-view.tsx`: clicking a legend entry now shows/hides that series (Recharts' own `hide` prop + `Legend`'s `onClick`/`formatter` — no new dependency), with a strikethrough on the hidden entry's label.
  - [x] Live-verified end-to-end against real 1005-row data: sort toggles correctly across clicks, quick-filter narrowed 25 rows to the 7 actually matching a real value, hiding a column removed it from the header row, the drill-down dialog (same `TabularView`) renders correctly with the same interactivity, and the chart legend toggle correctly hides/shows a series with zero console/page errors.

### Reference: vtiger's real default folders + canned reports (for Phase 3 seeding)

| Folder (our label) | Canned reports to reproduce |
|---|---|
| Organization and Contact Reports | Contacts by Organizations, Contacts without Organizations, Contacts by Opportunities |
| Lead Reports | Lead by Source, Lead Status Report |
| Opportunity Reports | Opportunity Pipeline, Closed Opportunities |
| Activity Reports | Last Month Activities, This Month Activities — primary_module `task_management.events` (NOT a CRM module), `advanced_filter: linked_type IN ('LEAD','CONTACT','ORGANIZATION','OPPORTUNITY','TICKET')`, standard_filter on `start_at` for the month preset |
| Tickets Reports | Tickets by Products, Tickets by Priority, Open Tickets |
| Product Reports | Product Details, Products by Contacts |
| Quote Reports | Open Quotes, Quotes Detailed Report |
| Purchase Order Reports | PurchaseOrder by Contacts, PurchaseOrder Detailed Report |
| Sales Order Reports | SalesOrder Detailed Report |
| Invoice Reports | Invoice Detailed Report |
| Campaign Reports | Campaign Expectations and Actuals |
| Email Reports | Contacts Email Report, Organizations Email Report, Leads Email Report, Vendors Email Report (structure unconfirmed in legacy source — labels only; build from `crm_emails`+`crm_email_links` grouped by linked entity type) |
| Triz Reports | (none — ordinary empty custom folder) |
| All Reports | computed view, not a folder |
| Shared With Me | computed view, not a folder |

## Verification

- `php -l` every new/changed PHP file; `php artisan route:list` to confirm
  the new `/api/reports/*` routes register with no collisions.
- Migration run on **both** `default` and `live` connections per phase (not
  all at the end) — this session's established discipline, since `live`'s
  older MariaDB has repeatedly needed MariaDB-compatibility fixes other
  migrations didn't.
- Phase 1 live-verification: create a real Task report (tabular), an Events
  summary grouped by status, and an Assigned-Tasks chart grouped by assignee
  — confirm real data, confirm tenant scoping (a different tenant's token
  sees none of it), confirm CSV export.
- Phase 3 live-verification: spot-check at least one seeded report per
  folder actually returns real rows against live CRM data (not just that the
  screen renders), confirm "All Reports" aggregates across folders
  correctly, confirm "Shared With Me" only shows a report after an explicit
  share (and not before).
- `node node_modules/eslint/bin/eslint.js` + a full project `tsc --noEmit` on
  every new/changed frontend file (never `npx` — confirmed wrong version in
  this repo).
