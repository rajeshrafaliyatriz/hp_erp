# Adding a module: how it gets an AI Stack

A G2G module is a level-1 row of `tblmenumaster_g2g`. Adding one needs no AI code.

## What happens by itself

1. **Registration.** The first time an administrator opens `/module/{menuId}/ai-stack` the front end calls
   `GET /api/ai/modules/resolve?menu_id=`. `ModuleRegistrar` creates the module's `ai_modules` row from the menu
   (key from its link: `/module/zz-demo` -> `zz_demo`; chat and agents on, generation and workflow off).
   `php artisan ai:sync-modules` does the same for every menu module and runs daily (`--no-enable`).
2. **Discovery.** For a module that is not hand-written (see `config/ai_modules.php` `curated`), the tables whose
   names start with a prefix its menu links imply (`/module/zz-demo/...` -> `zz_demo_`, `/gtm/...` -> `gtm_`) become
   read-only data sources `<module>.<table>` in `ModuleDataSourceCatalog`. Everything that reads the catalogue -
   chat grounding and tool selection, Knowledge Base, Templates, Automations, Examples - sees them with no change.
3. **The stack.** `useActiveModule` falls back to the backend's description, `lib/ai-stack/generic.ts` builds the same
   11 tabs from it, and `lib/agents/registry.ts` learns the module's tools at runtime. The shell chat sends the
   module key, so answers come from that module's rows only.

## What discovery will and will not read

| Read | Not read |
|---|---|
| Tables with their own `sub_institute_id` | Tables with no organisation column **and** no single owning parent (skipped, with the reason) |
| A child table (line items, history) **through** its one owning parent, which has `sub_institute_id` | A child with two equally plausible parents (a guess is not scoping) |
| Soft-deleted rows are excluded | Tables named like credentials/sessions/tokens |
| Parent record names via `<thing>_id` -> `<prefix><thing>(s)` or a real foreign key | Columns matching `sensitive_columns` or `pii_columns` (passwords, tokens, bank, e-mail, phone, address...), blob/JSON columns |

Non-administrators additionally need the `view` right on the module's own menu row; without it the source refuses
to run (it does not return an empty list).

## What still needs a person

`GET /modules/resolve` reports it (`support.cannot`, `support.needs_configuration`, `discovery.skipped`):

* **Tables not named after the menu.** Declare them in `config/ai_modules.php` `table_prefixes`. Until then the module is
  reported as having *nothing readable*, not given invented data.
* **Derived figures and business rules** (forecasts, scores, margins). Only stored columns are read; write a source in
  `ModuleDataSourceCatalog` for anything computed.
* **Write actions** in chat. None is defined for a discovered module.
* **Module-specific wording** (central risk, report disclaimer). A generic neutral text is used until the module is
  added to `lib/ai-stack/central.ts`; a hand-written descriptor always wins.
* **Table-to-page mapping** is by name only (`/module/x/deals` -> `x.deals`); ambiguous or unnamed pages are listed as
  unmapped in "Examples by page".

## Staying current

The table/column listing is re-read at most every `ai_modules.schema_ttl` seconds (default 60) and the spec is cached
under a hash of that listing plus the discovery rules, so a schema change or a rule change takes effect without a
deploy. In the window before the listing is re-read, a source built on a dropped column fails as "could not be
read" rather than answering from outdated metadata.
