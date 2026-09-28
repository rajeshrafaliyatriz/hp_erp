<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first submodule-grain `ai_modules` row — decentralized AI Stack, reference implementation.
 *
 * ── WHY THIS IS A NEW ROW, NOT A CHANGE TO AN EXISTING ONE ───────────────────
 *
 * `ai_modules` currently has eight rows, one per top-level tblmenumaster_g2g module
 * (`lms`, `talent_management`, ...) — see the 2026_09_19_120000_create_ai_intelligence_tables
 * seed. `AiPolicyController::index` and `AiTemplateController::index` already filter by
 * `module_key` against exactly this table, exact match, so they need nothing new — only
 * a row at the grain a decentralized AI Stack panel actually wants: one submodule, not
 * one top-level module. LMS's `lms` row stays exactly as it is; this adds a second,
 * finer-grained row for the one submodule (Course Builder) that a code audit confirmed
 * has real, running AI usage behind it.
 *
 * ── WHY THIS SUBMODULE, FIRST ─────────────────────────────────────────────────
 *
 * Of the nine G2G submodules with genuine AI usage found in that audit, Course Builder
 * has the most mature evidence: AiCourseController (outline + presentation generation),
 * CourseQuizGenerator (quiz questions) and GammaService (deck generation), registered
 * under AiModuleRegistry's `lms_content_ai` capability key. It is the reference
 * implementation the other eight follow, not a one-off.
 *
 * ── WHAT THIS DOES NOT DO ─────────────────────────────────────────────────────
 *
 * No route changes, no controller changes, no new table. `ai_api_keys.ai_module` /
 * `ai_usage_events.ai_module` keep using `AiModuleRegistry`'s `lms_content_ai` —
 * a different, coarser grain, read as-is by the frontend AiStackModule descriptor
 * (lib/ai-stack/lms-course-builder.ts), filtered client-side. Nothing here changes
 * what any existing screen shows.
 */
return new class extends Migration
{
    private const MODULE_KEY = 'lms_course_builder';

    /** Course Builder — tblmenumaster_g2g id 84, LMS > Administration > Course Builder. */
    private const MENU_ID = 84;

    public function up(): void
    {
        if (!$this->tableExists('ai_modules')) {
            return;
        }

        $exists = DB::table('ai_modules')->where('module_key', self::MODULE_KEY)->exists();

        if ($exists) {
            return;
        }

        $nextSortOrder = (int) (DB::table('ai_modules')->max('sort_order') ?? 0) + 1;

        DB::table('ai_modules')->insert([
            'module_key' => self::MODULE_KEY,
            'label' => 'Course Builder',
            'domain' => 'g2g',
            'description' => 'AI-assisted course outline, slide deck and quiz generation for the LMS course authoring wizard.',
            'menu_id' => self::MENU_ID,
            'icon' => 'mdi mdi-auto-fix',
            'sort_order' => $nextSortOrder,
            'status' => 1,
            // Global, like every other ai_modules row (see 2026_09_19_120000's own
            // note) — a tenant id here would hide the row from every other organisation.
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_modules')->where('module_key', self::MODULE_KEY)->delete();
    }

    /** Schema::hasTable() throws on MariaDB 10.1 - information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
