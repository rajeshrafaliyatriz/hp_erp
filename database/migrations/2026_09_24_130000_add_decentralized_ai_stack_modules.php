<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The remaining eight submodule-grain `ai_modules` rows for the decentralized AI Stack.
 *
 * Continues 2026_09_24_120000_add_lms_course_builder_ai_module — same reasoning, same
 * pattern, applied to the other eight G2G submodules a code audit confirmed have
 * genuine, running AI usage behind them (see the module/submodule mapping: each row's
 * `description` cites the real backend consumer). Nothing here changes an existing
 * `ai_modules` row, an existing route, or an existing controller.
 *
 * WHY NINE MODULES BUT NOT A TENTH FOR "My Assessment"
 *
 * LMS > Learning > My Assessment (menu 301) runs on the same capability as
 * LMS > Assessments (menu 155, `lms_assessments` below) — CourseQuizGenerator, one
 * consumer. My Assessment is where an employee TAKES a test; it has no configuration
 * of its own to show, and an admin-only "AI Stack" toggle on a test-taking screen
 * would be the wrong surface for it. The configuration lives on the admin workspace
 * (Assessments) that already owns it.
 */
return new class extends Migration
{
    /**
     * module_key => [menu_id, label, description, icon].
     *
     * menu_id is the tblmenumaster_g2g id the audit resolved each submodule to —
     * see hooks/content-map-m2.ts through m6.ts in the frontend for the same ids.
     */
    private const MODULES = [
        'lms_assessments' => [
            155,
            'Assessments',
            'AI-generated quiz questions for course assessments — the same generator Course Builder uses, applied to an existing course.',
            'mdi mdi-clipboard-text-outline',
        ],
        'lms_my_learning' => [
            209,
            'My Learning',
            'Course recommendations surfaced to an employee, ranked by their own learning history.',
            'mdi mdi-book-open-variant',
        ],
        'lms_learning_catalog' => [
            182,
            'Learning Catalog',
            'AI-assisted quick-create: generate a course outline and deck straight from the catalogue.',
            'mdi mdi-view-grid-outline',
        ],
        'capability_library' => [
            223,
            'Capability Library',
            'Employee Skill Objective (ESO) generation, and the bridge that turns a capability into LMS course content.',
            'mdi mdi-bookshelf',
        ],
        'capability_explorer' => [
            43,
            'Capability Explorer',
            'Contributes the competency taxonomy and entity mappings the shared Knowledge Graph reads — a data contribution, not a model call.',
            'mdi mdi-graph-outline',
        ],
        'talent_recruitment' => [
            47,
            'Recruitment',
            'Job-description analysis and candidate assessment generation for open roles.',
            'mdi mdi-account-search-outline',
        ],
        'talent_administration' => [
            178,
            'Talent Administration',
            'Configures the assessment test types and scope Recruitment\'s generator offers.',
            'mdi mdi-cog-outline',
        ],
        'task_my_tasks' => [
            211,
            'My Tasks',
            'Classifies task execution against the expected standard output (ESO) an employee\'s duties define.',
            'mdi mdi-check-circle-outline',
        ],
    ];

    public function up(): void
    {
        if (!$this->tableExists('ai_modules')) {
            return;
        }

        $sortOrder = (int) (DB::table('ai_modules')->max('sort_order') ?? 0);

        foreach (self::MODULES as $moduleKey => [$menuId, $label, $description, $icon]) {
            if (DB::table('ai_modules')->where('module_key', $moduleKey)->exists()) {
                continue;
            }

            $sortOrder++;

            DB::table('ai_modules')->insert([
                'module_key' => $moduleKey,
                'label' => $label,
                'domain' => 'g2g',
                'description' => $description,
                'menu_id' => $menuId,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'status' => 1,
                'sub_institute_id' => null,
                'client_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_modules')->whereIn('module_key', array_keys(self::MODULES))->delete();
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
