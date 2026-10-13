<?php

namespace App\Domain\AI\Modules;

use Illuminate\Support\Facades\DB;

/**
 * Which `ai_modules` keys a top-level module's AI Stack should read from.
 *
 * A top-level module (LMS, Talent Management, …) owns every screen beneath it in the menu
 * tree, and rows saved under a screen's key (an agent for `lms_course_builder`, a template
 * for `talent_recruitment`) belong in its module-wide view. The tree is read from
 * `ai_modules.menu_id` and the `tblmenumaster_g2g.parent_id` chain — the same derivation
 * `ModuleDataSourceCatalog` uses — so a new screen rolls up without anybody listing it.
 *
 * Opt-in by design: callers pass the key through here only when the request asked for the
 * roll-up, so the central AI console keeps reading exactly one key.
 */
final class ModuleRollUp
{
    /**
     * The key itself, then every screen-level key beneath it. A screen-level key, or one
     * with no `ai_modules` row, returns just itself.
     *
     * @return array<int, string>
     */
    public function keysFor(string $moduleKey): array
    {
        $keys = [$moduleKey];

        $rows = DB::table('ai_modules')
            ->whereNull('sub_institute_id')
            ->whereNotNull('menu_id')
            ->get(['module_key', 'menu_id']);

        foreach ($rows as $row) {
            if ((string) $row->module_key !== $moduleKey && $this->rootKey((int) $row->menu_id) === $moduleKey) {
                $keys[] = (string) $row->module_key;
            }
        }

        return array_values(array_unique($keys));
    }

    /** The `ai_modules` key of the level-1 menu above this menu row, or null. */
    private function rootKey(int $menuId): ?string
    {
        for ($hops = 0; $hops < 6; $hops++) {
            $row = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['id', 'parent_id', 'level']);

            if ($row === null) {
                return null;
            }

            if ((int) $row->parent_id === 0 || (int) $row->level === 1) {
                $key = DB::table('ai_modules')->where('menu_id', (int) $row->id)->whereNull('sub_institute_id')->value('module_key');

                return $key === null ? null : (string) $key;
            }

            $menuId = (int) $row->parent_id;
        }

        return null;
    }
}
