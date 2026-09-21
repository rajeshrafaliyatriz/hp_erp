<?php

namespace App\Domain\AI\Templates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The modules a template can belong to — the list behind the module selector.
 *
 * WHY IT READS `ai_modules` RATHER THAN A CONSTANT
 *
 * `AiModuleRegistry`, the other registry with a similar name, lists the *AI* modules
 * an administrator points at a provider: Assessment AI, ESO Intelligence, Agent
 * Reasoning. That is a different question. This one lists the *product* modules a
 * template is written for — Competency Management, Talent Management, LMS, Task
 * Management — and those are rows in `ai_modules`.
 *
 * WHERE THOSE ROWS COME FROM IN G2G
 *
 * `ai_modules` is seeded from `tblmenumaster_g2g` — the menu catalogue that already
 * is this product's list of modules. It is not a hand-written copy: the migration
 * reads the level-1 rows and writes one `ai_modules` row per module, so the selector
 * offers exactly the modules this deployment actually has. A module added to the
 * menu later is added to `ai_modules` by re-running the sync, and Template Management
 * does not change. A hard-coded list here would drift from the navigation within a
 * release, and an administrator could then write templates for a module that does
 * not exist.
 *
 * THE SHARED ENTRY
 *
 * `null` is a real choice, not a missing one. A template that analyses whatever is on
 * screen belongs to every module; filing it under one would either hide it from the
 * rest or need a copy per module. The catalogue surfaces that as an explicit
 * "Shared — every module" row so the selector can offer it.
 */
class TemplateModuleCatalog
{
    /** The value the API and the UI use for "not one module — all of them". */
    public const SHARED = '__shared__';

    /**
     * Every module a template can be filed under, shared first.
     *
     * @return array<int, array{key:string, label:string, description:?string, icon:?string, shared:bool}>
     */
    public function all(int|string|null $subInstituteId = null): array
    {
        $modules = [[
            'key' => self::SHARED,
            'label' => 'Shared — every module',
            'description' => 'Templates that apply across the product, such as page and record analysis.',
            'icon' => 'layers',
            'shared' => true,
        ]];

        if (! Schema::hasTable('ai_modules')) {
            return $modules;
        }

        $rows = DB::table('ai_modules')
            ->where('status', 1)
            ->where(function ($inner) use ($subInstituteId) {
                $inner->whereNull('sub_institute_id');

                if ($subInstituteId !== null && $subInstituteId !== '') {
                    $inner->orWhere('sub_institute_id', $subInstituteId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['module_key', 'label', 'description', 'icon', 'sub_institute_id']);

        // A tenant row and a platform row can name the same module. The tenant's
        // label wins, because it is the one that organisation chose; without this the
        // selector would show the same module twice under two names.
        $seen = [];

        foreach ($rows as $row) {
            $key = (string) $row->module_key;

            if (isset($seen[$key]) && $row->sub_institute_id === null) {
                continue;
            }

            $seen[$key] = [
                'key' => $key,
                'label' => (string) $row->label,
                'description' => $row->description === null ? null : (string) $row->description,
                'icon' => $row->icon === null ? null : (string) $row->icon,
                'shared' => false,
            ];
        }

        return array_merge($modules, array_values($seen));
    }

    /** @return array<int, string> Real module keys, excluding the shared sentinel. */
    public function keys(int|string|null $subInstituteId = null): array
    {
        return array_values(array_filter(
            array_column($this->all($subInstituteId), 'key'),
            fn (string $key) => $key !== self::SHARED
        ));
    }

    public function exists(string $key, int|string|null $subInstituteId = null): bool
    {
        return $key === self::SHARED || in_array($key, $this->keys($subInstituteId), true);
    }

    public function label(?string $key, int|string|null $subInstituteId = null): string
    {
        if ($key === null || $key === self::SHARED) {
            return 'Shared — every module';
        }

        foreach ($this->all($subInstituteId) as $module) {
            if ($module['key'] === $key) {
                return $module['label'];
            }
        }

        // A template filed under a module that has since been retired. Showing the raw
        // key is better than showing nothing: it is still findable and still editable.
        return $key;
    }

    /**
     * The sentinel maps to NULL in the column, and an empty string does too.
     *
     * One place does this conversion so the controller never has to remember which of
     * the three spellings of "no module" it is holding.
     */
    public function toColumn(?string $key): ?string
    {
        if ($key === null || $key === '' || $key === self::SHARED) {
            return null;
        }

        return $key;
    }

    /** The inverse: a NULL column reads back to the selector as the shared entry. */
    public function fromColumn(?string $column): string
    {
        return $column === null || $column === '' ? self::SHARED : $column;
    }
}
