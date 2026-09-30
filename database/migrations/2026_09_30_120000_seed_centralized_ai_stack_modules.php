<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the six top-level modules an AI Stack of their own — the Centralized AI Stack.
 *
 * WHY THIS IS NEEDED
 *
 * The AI Stack was reachable only from the nine screens that had a per-screen `ai_modules`
 * row with `capabilities` set. `AiToolAgentController::stackModuleKeys()` (and the Models
 * and Guardrails tabs) treat "has `capabilities`" as "has an AI Stack", so Organisation and
 * HRMS had none and Talent, LMS, Capability and Task only had it on a couple of screens.
 * The six top-level rows already exist (2026_09_19); they were simply never switched on.
 *
 * WHAT IS DERIVED, AND WHAT IS NOT
 *
 * For each module, `capabilities` is the union of the flags its child screens declare, and
 * `registry_keys` the union of their consumers — read from the rows already there, through
 * the menu tree, not restated here. `conversational` and `agent` are always on: every
 * module has read-only data sources in `ModuleDataSourceCatalog`, and those are what the
 * conversational and tool-agent paths run on. `generative`, `workflow` and `ontology` are
 * on only where a child screen genuinely has them, so Organisation and HRMS — which have no
 * AI consumer behind them — honestly report none rather than borrowing another module's.
 *
 * Idempotent and non-destructive: a row that already carries `capabilities` is left alone.
 * Rows are never inserted or deleted, and no child row is touched.
 */
return new class extends Migration
{
    /** The six modules the Platform Services strip scopes to, by `ai_modules.module_key`. */
    private const MODULES = [
        'organizational_management',
        'hrit_management',
        'talent_management',
        'lms',
        'capability_intelligence',
        'task_management',
    ];

    public function up(): void
    {
        if (! $this->columnExists('ai_modules', 'capabilities') || ! $this->columnExists('ai_modules', 'registry_keys')) {
            return;
        }

        foreach (self::MODULES as $key) {
            $parent = DB::table('ai_modules')->where('module_key', $key)->whereNull('sub_institute_id')->first();

            if ($parent === null || $parent->capabilities !== null) {
                continue;
            }

            $capabilities = ['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => false, 'ontology' => false];
            $registry = [];

            foreach ($this->children((int) $parent->menu_id) as $child) {
                foreach ((array) json_decode((string) ($child->capabilities ?? ''), true) as $flag => $on) {
                    if ($on && array_key_exists($flag, $capabilities)) {
                        $capabilities[$flag] = true;
                    }
                }
                foreach ((array) json_decode((string) ($child->registry_keys ?? ''), true) as $consumer) {
                    if (is_string($consumer)) {
                        $registry[$consumer] = true;
                    }
                }
            }

            DB::table('ai_modules')->where('id', $parent->id)->update([
                'capabilities' => json_encode($capabilities),
                'registry_keys' => json_encode(array_keys($registry)),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_modules')
            ->whereIn('module_key', self::MODULES)
            ->whereNull('sub_institute_id')
            ->update(['capabilities' => null, 'registry_keys' => null, 'updated_at' => now()]);
    }

    /**
     * Every `ai_modules` row whose menu sits anywhere beneath one level-1 menu.
     *
     * @return array<int, object>
     */
    private function children(int $rootMenuId): array
    {
        $rows = DB::table('ai_modules')->whereNull('sub_institute_id')->whereNotNull('menu_id')->get();
        $found = [];

        foreach ($rows as $row) {
            $menuId = (int) $row->menu_id;

            for ($hops = 0; $hops < 6 && $menuId > 0; $hops++) {
                $menu = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['id', 'parent_id']);

                if ($menu === null) {
                    break;
                }

                if ((int) $menu->parent_id === $rootMenuId && (int) $row->menu_id !== $rootMenuId) {
                    $found[] = $row;
                    break;
                }

                $menuId = (int) $menu->parent_id;
            }
        }

        return $found;
    }

    /** Schema::hasColumn() throws on this estate's MariaDB — information_schema does not. */
    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }
};
