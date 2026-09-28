<?php

use App\Services\Ai\AiPolicyResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One worked policy and two worked templates (a `prompt` and a `template`) for each
 * of the nine decentralized AI Stack modules — the same pattern as
 * 2026_09_21_120000_seed_example_ai_template and 2026_09_21_130000_seed_example_ai_policy,
 * repeated across every module the rollout added, instead of leaving eight of the nine
 * AI Stack panels' Policies/Guardrails/Prompts/Templates tabs empty.
 *
 * WHY A `prompt` ROW AND A `template` ROW, NOT TWO OF THE SAME
 *
 * Every prior template in this table was `kind: 'prompt'` — free-form system/user
 * instructions. LMS_K12's AI Stack tab vocabulary names Prompts and Templates
 * separately, and G2G's schema already carries the column to mean it (`ai_templates.kind`,
 * `varchar(20)`, never constrained to one value — only ever written as one). So each
 * module below also gets one `kind: 'template'` row: a structured, `html`-output
 * document format (a notice, a summary sheet) — genuinely a different shape of AI
 * output from a `prompt` row's free text, not a relabelled duplicate.
 *
 * WHY NO Models OR Usage & Cost EXAMPLE
 *
 * `ai_api_keys` and `ai_usage_events` are both empty in this deployment, platform-wide
 * — not a gap specific to these nine modules. G2G's legacy AI callers
 * (CourseQuizGenerator, EsoGenerator, DeepSeekService, the Gemini controllers) predate
 * `AiConfigurationResolver`/`AiModelClient` and call their providers directly, so no
 * provider has ever been configured centrally and no call has ever been metered
 * centrally, for any module. Inserting a fake credential or a fake usage row here
 * would be exactly the fabricated record this rollout was told not to add — those two
 * tabs stay honestly empty until an administrator configures a real provider.
 *
 * SAFE TO RE-RUN. Every insert is guarded on its own key, at platform scope
 * (`sub_institute_id = NULL`) — a second run is a no-op, and nothing an organisation
 * has written under the same module is ever touched.
 *
 *   php artisan migrate --path=database/migrations/2026_09_24_140000_seed_decentralized_ai_stack_examples.php
 */
return new class extends Migration
{
    /**
     * module_key => policy + two templates. Every string here is written against
     * what that module's real backend consumer actually does (see
     * 2026_09_24_130000's own citations) — nothing is a placeholder.
     */
    private function definitions(): array
    {
        return [
            'lms_course_builder' => [
                'policy_name' => 'Course content generation (example)',
                'policy_description' => "A worked example. AI may draft a course outline, a slide deck and quiz questions from the\ntopic and level an author supplies. An author must read and edit every generated\nlesson before publishing — nothing generated here reaches a learner unreviewed.\n\nAnyone who used AI to draft a lesson must say so in the course's change notes.",
                'prompt' => [
                    'key' => 'g2g.lms_course_builder.outline_draft',
                    'name' => 'Course outline draft (example)',
                    'description' => 'Drafts a module-by-module outline for a new course from its title, audience and objectives.',
                    'category' => 'generation',
                    'system' => "You are an instructional designer drafting a course outline for a corporate learning\nplatform. Work only from the course details supplied below. Do not invent a topic,\nprerequisite or duration the author did not ask for.",
                    'user' => "Draft an outline for this course.\n\nTitle: {{page_title}}\nAudience / department: {{filters}}\nRecord count for context: {{record_count}}\n\nReturn 4-8 modules, each with a one-line objective. Flag anywhere the brief is too\nthin to outline confidently, instead of guessing.",
                ],
                'template' => [
                    'key' => 'g2g.lms_course_builder.completion_notice',
                    'name' => 'Course completion notice (example)',
                    'description' => 'A short, formatted notice an author can send when a learner finishes this course.',
                    'category' => 'notice',
                    'system' => "You are writing a short completion notice for a course platform. Plain, factual,\nno marketing language.",
                    'user' => "Write a two-sentence completion notice for {{page_title}}, congratulating the learner\nand naming what they completed. Use only the figures in {{metrics}}.",
                ],
            ],
            'lms_assessments' => [
                'policy_name' => 'Assessment question generation (example)',
                'policy_description' => "A worked example. AI may draft candidate quiz questions from a course's own content.\nIt may not decide a learner's pass mark or grade a written answer — scoring stays\nrule-based (QuizScoringService), never model-judged.\n\nEvery generated question must be reviewed before it is added to a live assessment.",
                'prompt' => [
                    'key' => 'g2g.lms_assessments.question_draft',
                    'name' => 'Quiz question draft (example)',
                    'description' => 'Drafts multiple-choice questions from a course module\'s own lesson content.',
                    'category' => 'generation',
                    'system' => "You write multiple-choice quiz questions strictly from the lesson content supplied.\nNever introduce a fact the content does not contain. Mark exactly one option correct.",
                    'user' => "Lesson content:\n{{records}}\n\nWrite {{rows_shown}} multiple-choice questions, four options each, testing recall of\nthe material above only.",
                ],
                'template' => [
                    'key' => 'g2g.lms_assessments.results_summary',
                    'name' => 'Assessment results summary (example)',
                    'description' => 'A formatted summary sheet of one assessment\'s results for the course administrator.',
                    'category' => 'summary',
                    'system' => "You summarise assessment results for an LMS administrator. State only figures\npresent in the data.",
                    'user' => "Summarise the results in {{metrics}}, covering {{rows_shown}} of {{record_count}}\nattempts. Note the pass rate and the single most-missed question, if the data names one.",
                ],
            ],
            'lms_my_learning' => [
                'policy_name' => 'Course recommendation transparency (example)',
                'policy_description' => "A worked example. AI may rank which of an employee's assigned or eligible courses to\nsurface first, based on their own learning history. It may not enrol them in anything\nwithout their action, and the reason for a ranking must be visible on request.",
                'prompt' => [
                    'key' => 'g2g.lms_my_learning.recommendation_reason',
                    'name' => 'Why this course, next (example)',
                    'description' => 'Explains in one sentence why a recommended course was ranked first for this employee.',
                    'category' => 'recommendation',
                    'system' => "You explain a single course recommendation to the employee it was made for, in one\nplain sentence. Base it only on the learning history supplied.",
                    'user' => "Learning history: {{records}}\nRecommended course: {{page_title}}\n\nIn one sentence, say why this course was suggested next.",
                ],
                'template' => [
                    'key' => 'g2g.lms_my_learning.weekly_digest',
                    'name' => 'Weekly learning digest (example)',
                    'description' => 'A short formatted digest of an employee\'s in-progress and recommended courses.',
                    'category' => 'summary',
                    'system' => "You write a brief weekly learning digest for one employee. Friendly, factual, no\ninvented deadlines.",
                    'user' => "In-progress and recommended courses: {{records}}\n\nWrite a 3-line digest: what's in progress, what's next, and one encouraging line.",
                ],
            ],
            'lms_learning_catalog' => [
                'policy_name' => 'Catalogue quick-create (example)',
                'policy_description' => "A worked example. AI may generate a full draft course (outline, deck and quiz) from\na short description entered in the catalogue's Build-with-AI sheet. The draft\npublishes only after the author reviews and confirms it - the same review step as\nCourse Builder itself.",
                'prompt' => [
                    'key' => 'g2g.lms_learning_catalog.quick_create_brief',
                    'name' => 'Quick-create course brief (example)',
                    'description' => 'Expands a one-line course idea entered in the catalogue into a structured brief.',
                    'category' => 'generation',
                    'system' => "You turn a short course idea into a structured brief: audience, objectives, and\nsuggested module count. Do not invent constraints the author did not state.",
                    'user' => "Idea: {{page_title}}\nContext: {{filters}}\n\nExpand this into a brief an author can approve before generation continues.",
                ],
                'template' => [
                    'key' => 'g2g.lms_learning_catalog.new_course_card',
                    'name' => 'New course announcement (example)',
                    'description' => 'A short formatted announcement for a newly published catalogue course.',
                    'category' => 'notice',
                    'system' => "You write a one-paragraph announcement for a newly published course, for the\ncatalogue's what's-new feed.",
                    'user' => "Course: {{page_title}}\nDetails: {{metrics}}\n\nWrite a short, inviting announcement using only these details.",
                ],
            ],
            'capability_library' => [
                'policy_name' => 'ESO generation (example)',
                'policy_description' => "A worked example. AI may draft an Employee Skill Objective (ESO) - the expected\nstandard a job-role task is measured against - from the task and competency data\nsupplied. It may not finalise one: an HR administrator must approve a generated ESO\nbefore it governs anyone's task execution.",
                'prompt' => [
                    'key' => 'g2g.capability_library.eso_draft',
                    'name' => 'ESO draft (example)',
                    'description' => 'Drafts an Employee Skill Objective from a job-role task\'s own description and linked competencies.',
                    'category' => 'generation',
                    'system' => "You draft an Employee Skill Objective: the observable standard a task is performed\nto. Work only from the task and competency data supplied. Never invent a proficiency\nlevel not implied by the data.",
                    'user' => "Task: {{page_title}}\nLinked competencies and levels: {{records}}\n\nDraft the expected standard of execution for this task in 2-3 sentences.",
                ],
                'template' => [
                    'key' => 'g2g.capability_library.eso_review_sheet',
                    'name' => 'ESO review sheet (example)',
                    'description' => 'A formatted sheet an HR administrator reviews before approving a generated ESO.',
                    'category' => 'summary',
                    'system' => "You lay out a generated ESO and its source data for a human reviewer, plainly, with\nnothing added beyond what was generated.",
                    'user' => "Generated ESO: {{page_title}}\nSource data: {{records}}\n\nPresent both side by side so a reviewer can approve or reject in one read.",
                ],
            ],
            'capability_explorer' => [
                'policy_name' => 'Taxonomy contribution (example)',
                'policy_description' => "A worked example. This module contributes G2G's own competency taxonomy to the\nshared entity graph (hpbrain_entity_mappings) - a data mapping, not a generative\ncall. No AI-generated text is published from here without a human authoring it\nfirst; this policy exists to say plainly that this module makes no generative claim.",
                'prompt' => [
                    'key' => 'g2g.capability_explorer.mapping_note',
                    'name' => 'Entity mapping note (example)',
                    'description' => 'Explains in one sentence what a taxonomy entity maps to in the shared graph, for an auditor.',
                    'category' => 'explanation',
                    'system' => "You explain one entity-mapping row to an auditor in one plain sentence. State only\nthe source field, the universal field, and nothing else.",
                    'user' => "Mapping: {{records}}\n\nExplain what this row maps, and to what, in one sentence.",
                ],
                'template' => [
                    'key' => 'g2g.capability_explorer.mapping_audit_sheet',
                    'name' => 'Mapping audit sheet (example)',
                    'description' => 'A formatted list of this organisation\'s entity mappings, for a periodic audit.',
                    'category' => 'summary',
                    'system' => "You list entity mappings for an audit sheet. No commentary beyond what the rows state.",
                    'user' => "Mappings: {{records}} ({{rows_shown}} of {{record_count}})\n\nList each as: source field -> universal field.",
                ],
            ],
            'talent_recruitment' => [
                'policy_name' => 'Recruitment AI use (example)',
                'policy_description' => "A worked example. AI may analyse a job description for clarity and bias, and draft\ncandidate assessment questions from the role's required skills. It may not screen out\na candidate or produce a hiring recommendation - every generated assessment is scored\nand reviewed by a person before any decision is made.\n\nA candidate must be told when an assessment question was AI-generated.",
                'prompt' => [
                    'key' => 'g2g.talent_recruitment.jd_analysis',
                    'name' => 'Job description analysis (example)',
                    'description' => 'Flags unclear requirements or biased language in a job description before it is posted.',
                    'category' => 'analysis',
                    'system' => "You review a job description for a recruiter. Flag vague requirements and\nexclusionary or biased language. Do not rewrite the JD - only flag, with a reason\nfor each flag.",
                    'user' => "Job description:\n{{records}}\n\nList each issue found, quoting the exact phrase and why it is a problem.",
                ],
                'template' => [
                    'key' => 'g2g.talent_recruitment.candidate_test_brief',
                    'name' => 'Candidate assessment brief (example)',
                    'description' => 'A formatted brief sent to a candidate explaining their assessment before they start it.',
                    'category' => 'notice',
                    'system' => "You write a short, welcoming brief for a candidate about to take a role assessment.\nState only the format and duration given - never the questions or scoring.",
                    'user' => "Role: {{page_title}}\nAssessment format: {{metrics}}\n\nWrite the candidate-facing brief.",
                ],
            ],
            'talent_administration' => [
                'policy_name' => 'Assessment configuration integrity (example)',
                'policy_description' => "A worked example. This screen configures which test types and scopes Recruitment's\nassessment generator may offer - it does not itself generate anything. Only an\nadministrator may change a test type's availability, and every change is audited.",
                'prompt' => [
                    'key' => 'g2g.talent_administration.test_type_note',
                    'name' => 'Test type change note (example)',
                    'description' => 'Drafts a one-line audit note explaining why a test type\'s availability was changed.',
                    'category' => 'explanation',
                    'system' => "You write a one-sentence audit note for a configuration change. State only what\nchanged and to what value.",
                    'user' => "Change: {{page_title}} -> {{metrics}}\n\nWrite the audit note.",
                ],
                'template' => [
                    'key' => 'g2g.talent_administration.config_summary_sheet',
                    'name' => 'Assessment configuration summary (example)',
                    'description' => 'A formatted summary of the currently enabled test types and scopes.',
                    'category' => 'summary',
                    'system' => "You summarise the current assessment configuration for an administrator reviewing\nit. List only what is enabled.",
                    'user' => "Enabled test types and scopes: {{records}}\n\nList them, grouped by test type.",
                ],
            ],
            'task_my_tasks' => [
                'policy_name' => 'Task execution classification (example)',
                'policy_description' => "A worked example. AI may classify how closely a completed task matches the\nExpected Skill Objective (ESO) its duty defines. It may not close a task, assign a\nperformance rating, or take any action - the classification is advisory, shown to the\nemployee and their manager, and a person decides what follows.",
                'prompt' => [
                    'key' => 'g2g.task_my_tasks.execution_classification',
                    'name' => 'Task execution classification (example)',
                    'description' => 'Classifies a completed task\'s execution against the ESO its duty defines.',
                    'category' => 'classification',
                    'system' => "You classify task execution against a defined standard (ESO). Work only from the\ntask record and the ESO text supplied. Never assign a numeric score the ESO itself\ndoes not define a scale for.",
                    'user' => "Task: {{page_title}}\nCompletion notes: {{records}}\nExpected standard (ESO): {{metrics}}\n\nState whether execution met, partly met, or did not meet the standard, and why.",
                ],
                'template' => [
                    'key' => 'g2g.task_my_tasks.classification_note',
                    'name' => 'Classification note for manager (example)',
                    'description' => 'A short formatted note a manager sees alongside a classified task.',
                    'category' => 'notice',
                    'system' => "You write a one-paragraph note for a manager explaining a task's execution\nclassification. Plain, no judgement beyond what the classification states.",
                    'user' => "Task: {{page_title}}\nClassification: {{metrics}}\n\nWrite the note.",
                ],
            ],
        ];
    }

    public function up(): void
    {
        if (!$this->tableExists('ai_modules') || !$this->tableExists('ai_policies') || !$this->tableExists('ai_templates')) {
            return;
        }

        foreach ($this->definitions() as $moduleKey => $def) {
            $module = DB::table('ai_modules')
                ->where('module_key', $moduleKey)
                ->whereNull('sub_institute_id')
                ->first();

            // The module must genuinely exist on this deployment before anything is
            // seeded against it - an example bound to a missing module would
            // demonstrate a broken state.
            if ($module === null) {
                continue;
            }

            $this->seedPolicy($moduleKey, (int) $module->id, (string) $module->label, $def);
            $this->seedTemplate($moduleKey, (string) $module->label, $def['prompt'], 'prompt');
            $this->seedTemplate($moduleKey, (string) $module->label, $def['template'], 'template');
        }
    }

    public function down(): void
    {
        foreach ($this->definitions() as $moduleKey => $def) {
            if ($this->tableExists('ai_policies')) {
                $policyIds = DB::table('ai_policies')
                    ->where('name', $def['policy_name'])
                    ->whereNull('sub_institute_id')
                    ->pluck('id');

                foreach (['ai_policy_rules', 'ai_policy_assignments'] as $table) {
                    if ($this->tableExists($table) && $policyIds->isNotEmpty()) {
                        DB::table($table)->whereIn('policy_id', $policyIds)->delete();
                    }
                }

                if ($policyIds->isNotEmpty()) {
                    DB::table('ai_policies')->whereIn('id', $policyIds)->delete();
                }
            }

            if ($this->tableExists('ai_templates')) {
                foreach ([$def['prompt']['key'], $def['template']['key']] as $templateKey) {
                    if ($this->tableExists('ai_suggestions')) {
                        DB::table('ai_suggestions')->where('action_ref', $templateKey)->delete();
                    }

                    DB::table('ai_templates')
                        ->where('template_key', $templateKey)
                        ->whereNull('sub_institute_id')
                        ->delete();
                }
            }
        }
    }

    private function seedPolicy(string $moduleKey, int $moduleId, string $moduleLabel, array $def): void
    {
        $exists = DB::table('ai_policies')
            ->where('name', $def['policy_name'])
            ->whereNull('sub_institute_id')
            ->exists();

        if ($exists) {
            return;
        }

        $policyId = DB::table('ai_policies')->insertGetId([
            'sub_institute_id' => null,
            'name' => $def['policy_name'],
            'description' => trim($def['policy_description']),
            'policy_type' => 'ai_assisted',
            'status' => 1,
            'require_disclosure' => 1,
            'require_acknowledgement' => 0,
            'ai_detection_required' => 0,
            'plagiarism_check_required' => 0,
            'detection_provider' => null,
            'detection_threshold' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->tableExists('ai_policy_rules') && class_exists(AiPolicyResolver::class)) {
            $rows = [];
            foreach ((new AiPolicyResolver())->ruleCatalogue() as $rule) {
                $rows[] = [
                    'policy_id' => $policyId,
                    'rule_key' => $rule['key'],
                    'rule_value' => $rule['default'] ? '1' : '0',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows !== []) {
                DB::table('ai_policy_rules')->insert($rows);
            }
        }

        if ($this->tableExists('ai_policy_assignments')) {
            DB::table('ai_policy_assignments')->insert([
                'policy_id' => $policyId,
                'scope_type' => 'module',
                'scope_id' => $moduleId,
                'sub_institute_id' => null,
                'status' => 1,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit('ai.policy.created', 'ai_policies', $policyId, "Example policy seeded for {$moduleLabel}.");
    }

    private function seedTemplate(string $moduleKey, string $moduleLabel, array $t, string $kind): void
    {
        if (DB::table('ai_templates')->where('template_key', $t['key'])->exists()) {
            return;
        }

        $templateId = DB::table('ai_templates')->insertGetId([
            'template_key' => $t['key'],
            'name' => $t['name'],
            'description' => $t['description'],
            'domain' => 'g2g',
            'module_key' => $moduleKey,
            'kind' => $kind,
            'category' => $t['category'],
            'version' => 1,
            'status' => 'published',
            'system_prompt' => $t['system'],
            'user_prompt' => $t['user'],
            'variables' => null,
            'output_schema' => null,
            'output_format' => $kind === 'template' ? 'html' : 'markdown',
            'html_layout' => null,
            'data_source' => null,
            'data_arguments' => null,
            'provider' => null,
            'model' => null,
            'temperature' => null,
            'max_tokens' => null,
            'safety_rules' => json_encode(
                ['Work only from the data the caller supplies.', 'Never state a figure not present in that data.'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'allow_as_evidence' => false,
            'requires_review' => true,
            'created_by' => null,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->tableExists('ai_suggestions')) {
            DB::table('ai_suggestions')->insert([
                'module_key' => $moduleKey,
                'capability' => 'generative',
                'label' => $t['name'],
                'description' => $t['description'],
                'icon' => null,
                'action_type' => 'generate',
                'action_ref' => $t['key'],
                'prompt' => null,
                'payload' => null,
                'requires_entity' => false,
                'allowed_roles' => null,
                'required_permissions' => null,
                'sort_order' => $kind === 'prompt' ? 10 : 20,
                'status' => 1,
                'sub_institute_id' => null,
                'client_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit('ai.template.created', 'ai_templates', $templateId, "Example {$kind} template seeded for {$moduleLabel}.");
    }

    private function audit(string $eventType, string $relatedType, int $relatedId, string $message): void
    {
        if (!$this->tableExists('ai_audit_logs')) {
            return;
        }

        DB::table('ai_audit_logs')->insert([
            'event_type' => $eventType,
            'actor_type' => 'system',
            'actor_label' => 'migration',
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'outcome' => 'success',
            'message' => $message,
            'sub_institute_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
