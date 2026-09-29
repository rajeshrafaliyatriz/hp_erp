# 05. Menu System Audit

## Two parallel systems, not a migration-in-progress — both live.

## Who uses which model

| File | Model used | Path |
|---|---|---|
| `MenuMiddleware.php` | **`tblmenumasterModel`** (`tblmenumaster` table) | Session-based web middleware (`web`,`auth`,`session`,`menu` group). Runs on every non-API/JSON request, builds `menuMaster`/`submenuMaster`/`subChildmenuMaster` and stashes them in the session for Blade views. |
| `tblmenumasterG2gController.php` | **`tblmenumaster_g2gModel`** (`tblmenumaster_g2g` table) | Token-authenticated JSON API (`routes/user-api.php`, `api.token`/`profile:admin` middleware), registered *before* `routes/user.php` so it wins the same `/user/...` prefix. Feeds `displaySidebarMenu`, `displayGroupwiseRightsG2g`, `storeGroupwiseRightsG2g`, `displayUserProfilesG2g`. |

- `tblmenumasterModel` is a bare Eloquent model, no scopes, no soft-deletes — used only by `MenuMiddleware`.
- `tblmenumaster_g2gModel` has `SoftDeletes` and a `visibleToTenant()` scope (global-unless-restricted, fixed per the model's own changelog comment: absence of `sub_institute_id` now means "available to all tenants," not "none").
- No file mixes the two models. They are fully separate code paths reading two separate tables, both against the same "live" connection.

## DB3 evidence [DB3 / live / 128.199.17.97]

```
tblmenumaster      count = 196
tblmenumaster_g2g  count = 199
```

(Counted via `DB::connection('live')->table(...)->count()`, per DB3 access rules — not `information_schema`. Saved to `Docs/cross-repo-audit/_evidence/menu-system-db3.txt`.)

Both tables hold live data on DB3 — row count alone doesn't settle which one is "the" active system; that's determined by which code path actually gets called end-to-end (below). Note DB3 is a separate, differently-migrated host from whatever the app's default `DB_*` connection uses in production; this says nothing about that other host.

## g2gv0 → backend wiring

- `services/navigation/sidebar.ts` calls `GET /user/ajax_sidebar_menu_g2g` → `tblmenumasterG2gController::displaySidebarMenu`.
- `services/navigation/menu-rights.ts` calls `GET /user/ajax_user_profiles_g2g`, `GET /user/ajax_groupwiserights_g2g`, `POST /user/save_groupwiserights_g2g`, `POST /user/save_user_profile_g2g` — all four routed to `tblmenumasterG2gController`.

## Conclusion

g2gv0's entire navigation and Role & Permissions UI consumes `tblmenumaster_g2g` via `tblmenumaster_g2gModel` exclusively. `tblmenumasterModel` / `tblmenumaster` and `MenuMiddleware.php` belong to the legacy server-rendered Blade app's session-based sidebar and are never touched by the g2gv0 frontend — they're a live but disconnected parallel system, not dead code (still runs on every web request for the old UI) and not what the Next.js app reads.

## Files touched/read

- `C:\Users\MILAN\Downloads\hp_erp\app\Models\tblmenumasterModel.php`
- `C:\Users\MILAN\Downloads\hp_erp\app\Models\tblmenumaster_g2gModel.php`
- `C:\Users\MILAN\Downloads\hp_erp\app\Http\Controllers\user\tblmenumasterG2gController.php`
- `C:\Users\MILAN\Downloads\hp_erp\app\Http\Middleware\MenuMiddleware.php`
- `C:\Users\MILAN\Downloads\hp_erp\routes\user.php`, `routes\user-api.php`
- `C:\Users\MILAN\Downloads\hp_erp\config\database.php`
- `C:\Users\MILAN\Downloads\g2gv0\services\navigation\menu-rights.ts`, `sidebar.ts`
- Evidence: `C:\Users\MILAN\Downloads\hp_erp\Docs\cross-repo-audit\_evidence\menu-system-db3.txt`
