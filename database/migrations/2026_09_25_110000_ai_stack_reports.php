<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports for the shared AI Stack's Templates tab.
 *
 * 1. `ai_generated_reports` — the saved documents a "Build report" produces, LMS_K12's
 *    schema exactly (`arguments` is longText rather than json because this estate's
 *    MariaDB 10.1 has no JSON type).
 *
 * 2. The `kind = 'template'` example rows seeded by 2026_09_24_140000 are replaced. LMS_K12's
 *    Templates tab is report layouts — `kind = 'report'`, an HTML layout filled from a
 *    read-only data source — and those rows were formatted prose prompts, not layouts, so
 *    they were the wrong kind of record for that screen. Each module instead gets one real
 *    report layout bound to its own verified source (App\Domain\AI\Reports\
 *    ModuleDataSourceCatalog). The prompt examples are untouched.
 *
 * Everything is platform-scoped (`sub_institute_id = NULL`), guarded on its key, and
 * labelled "(example)" like the other worked examples.
 */
return new class extends Migration
{
    /** module_key => [old template key to retire, new report key, name, description, source, heading] */
    private const LAYOUTS = [
        'lms_course_builder' => ['g2g.lms_course_builder.completion_notice', 'g2g.lms_course_builder.course_build_status', 'Course build status (example)', 'Every course with its department and how many modules and lessons it has.', 'lms.course_builder', 'Course build status'],
        'lms_assessments' => ['g2g.lms_assessments.results_summary', 'g2g.lms_assessments.cycle_summary', 'Assessment cycle summary (example)', 'Each assessment cycle with participants, completions, overdue and average score.', 'lms.assessment_cycles', 'Assessment cycle summary'],
        'lms_my_learning' => ['g2g.lms_my_learning.weekly_digest', 'g2g.lms_my_learning.enrolment_register', 'Learner enrolment register (example)', 'Enrolments with learner, department, course and status.', 'lms.my_enrolments', 'Learner enrolment register'],
        'lms_learning_catalog' => ['g2g.lms_learning_catalog.new_course_card', 'g2g.lms_learning_catalog.catalogue_overview', 'Course catalogue overview (example)', 'Active courses with category, department and learner counts.', 'lms.catalog', 'Course catalogue overview'],
        'capability_library' => ['g2g.capability_library.eso_review_sheet', 'g2g.capability_library.jobrole_register', 'Job role and task register (example)', 'Job roles with category, level, department, task and competency counts.', 'capability.jobroles', 'Job role and task register'],
        'capability_explorer' => ['g2g.capability_explorer.mapping_audit_sheet', 'g2g.capability_explorer.entity_mapping_audit', 'Entity mapping audit (example)', 'How source records map onto the knowledge graph\'s universal entities.', 'capability.entity_mappings', 'Knowledge graph entity mappings'],
        'talent_recruitment' => ['g2g.talent_recruitment.candidate_test_brief', 'g2g.talent_recruitment.pipeline_report', 'Candidate pipeline report (example)', 'Applications with candidate, job, stage, latest interview and offer status.', 'talent.pipeline', 'Candidate pipeline'],
        'talent_administration' => ['g2g.talent_administration.config_summary_sheet', 'g2g.talent_administration.workflow_register', 'Talent workflow register (example)', 'Talent workflows with process, status, version and stage count.', 'talent.workflows', 'Talent workflow register'],
        'task_my_tasks' => ['g2g.task_my_tasks.classification_note', 'g2g.task_my_tasks.task_register', 'Task register (example)', 'Tasks with date, priority, status, approval, assignee and department.', 'tasks.my_tasks', 'Task register'],
    ];

    public function up(): void
    {
        if (! $this->tableExists('ai_generated_reports')) {
            Schema::create('ai_generated_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->string('module_key', 60)->index();
                $table->unsignedBigInteger('layout_template_id')->nullable()->index();
                $table->string('title', 250);
                $table->longText('html_content');
                $table->text('question')->nullable();
                $table->string('source_tool', 120)->nullable();
                $table->longText('arguments')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['sub_institute_id', 'created_at'], 'ai_gr_institute_created');
            });
        }

        if (! $this->tableExists('ai_templates')) {
            return;
        }

        foreach (self::LAYOUTS as $module => [$oldKey, $newKey, $name, $description, $source, $heading]) {
            // Retire the prose "template" example — only the platform row this seeding wrote.
            if ($this->tableExists('ai_suggestions')) {
                DB::table('ai_suggestions')->where('action_ref', $oldKey)->whereNull('sub_institute_id')->delete();
            }
            DB::table('ai_templates')->where('template_key', $oldKey)->whereNull('sub_institute_id')->where('kind', 'template')->delete();

            if (DB::table('ai_templates')->where('template_key', $newKey)->exists()) {
                continue;
            }

            if (! DB::table('ai_modules')->where('module_key', $module)->whereNull('sub_institute_id')->exists()) {
                continue;
            }

            $id = DB::table('ai_templates')->insertGetId([
                'template_key' => $newKey,
                'name' => $name,
                'description' => $description . ' A worked example: open it, then save your own copy to change it.',
                'domain' => 'g2g',
                'module_key' => $module,
                'kind' => 'report',
                'category' => 'report',
                'version' => 1,
                'status' => 'published',
                'system_prompt' => null,
                'user_prompt' => '',
                'variables' => null,
                'output_schema' => null,
                'output_format' => 'text',
                'html_layout' => $this->layout($heading),
                'data_source' => $source,
                'data_arguments' => json_encode(['limit' => 200]),
                'provider' => null,
                'model' => null,
                'temperature' => null,
                'max_tokens' => null,
                'safety_rules' => json_encode(['Figures come only from the bound data source; nothing in this report is written by a model.']),
                'allow_as_evidence' => false,
                'requires_review' => false,
                'created_by' => null,
                'sub_institute_id' => null,
                'client_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->tableExists('ai_suggestions')) {
                DB::table('ai_suggestions')->insert([
                    'module_key' => $module,
                    'capability' => 'generative',
                    'label' => $heading,
                    'description' => $description,
                    'icon' => null,
                    'action_type' => 'report',
                    'action_ref' => $newKey,
                    'prompt' => null,
                    'payload' => null,
                    'requires_entity' => false,
                    'allowed_roles' => null,
                    'required_permissions' => null,
                    'sort_order' => 20,
                    'status' => 1,
                    'sub_institute_id' => null,
                    'client_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($this->tableExists('ai_audit_logs')) {
                DB::table('ai_audit_logs')->insert([
                    'event_type' => 'ai.template.created',
                    'actor_type' => 'system',
                    'actor_label' => 'migration',
                    'related_type' => 'ai_templates',
                    'related_id' => $id,
                    'outcome' => 'success',
                    'message' => "Example report layout seeded for {$module}.",
                    'sub_institute_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * A plain, printable layout: letterhead, heading, provenance line, the rows as a table.
     * Every figure is a placeholder the renderer fills from the source's rows.
     */
    private function layout(string $heading): string
    {
        return '<div style="font-family:Inter,Segoe UI,sans-serif;">'
            . '<p style="margin:0;font-size:12px;color:#64748b;"><<institute_name>></p>'
            . '<h2 style="margin:4px 0 2px;">' . e($heading) . '</h2>'
            . '<p style="margin:0 0 12px;font-size:12px;color:#64748b;"><<row_count>> record(s) · generated <<generated_at>></p>'
            . '<<rows_table>>'
            . '</div>';
    }

    public function down(): void
    {
        foreach (self::LAYOUTS as [$oldKey, $newKey]) {
            if ($this->tableExists('ai_suggestions')) {
                DB::table('ai_suggestions')->where('action_ref', $newKey)->whereNull('sub_institute_id')->delete();
            }
            if ($this->tableExists('ai_templates')) {
                DB::table('ai_templates')->where('template_key', $newKey)->whereNull('sub_institute_id')->delete();
            }
        }

        Schema::dropIfExists('ai_generated_reports');
    }

    /**
     * Schema::hasTable() throws on this estate's live MariaDB — its query asks
     * information_schema.columns for a `generation_expression` field that database does
     * not have. This crashed the migration on first deploy before the tables it guards
     * were ever created, so it is answered directly against information_schema instead,
     * the same fix already used throughout this migration's siblings.
     */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
