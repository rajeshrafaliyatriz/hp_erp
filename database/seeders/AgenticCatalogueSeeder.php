<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The platform agent catalogue.
 *
 * These thirteen agents used to live in a hardcoded array inside the Agent
 * Library component, which meant the operator could not change a description, a
 * link or an endpoint without a frontend redeploy. Here they are rows: seeded
 * once, editable afterwards, and each with somewhere to record the HuggingFace
 * Space or n8n webhook that actually performs the work.
 *
 * `origin = platform` and a NULL sub_institute_id mean every tenant sees them
 * and nobody edits them in place — a tenant that wants a variant duplicates it,
 * which produces an ordinary tenant-owned agent.
 *
 * Idempotent: re-running updates the catalogue copy but never touches
 * endpoint_url, execution_mode or status, so wiring an agent up to a real
 * endpoint survives the next seed.
 *
 * Run with:  php artisan db:seed --class=AgenticCatalogueSeeder
 */
class AgenticCatalogueSeeder extends Seeder
{
    /**
     * Where each catalogue entry points in THIS app.
     *
     * Five of the legacy targets are screens that have not been rebuilt here
     * yet (Marketing Strategy, Content Automation, Newsletter, SEO, Excel
     * Automation). Rather than link to a dead route, those keep their live
     * legacy URL and are marked external — an honest link that works today,
     * and one field to change when the screen lands.
     */
    /**
     * Every agent now works inside this app. The workspace screen renders the
     * agent's own input schema, keeps its run history and shows past results,
     * so nothing has to hand the user off to the previous frontend.
     *
     * `%d` is the agent's id, filled in once the row exists.
     */
    private const WORKSPACE = '/module/m7/ag-agent-workspace/ag-agent-workspace?agent=%d';

    private function agents(): array
    {
        return [
            [
                'slug' => 'task-management',
                'input_schema' => [
                    [
                        'name'        => 'objective',
                        'label'       => 'What needs to be done?',
                        'type'        => 'textarea',
                        'required'    => true,
                        'rows'        => 3,
                        'placeholder' => 'e.g. Prepare the Q3 compliance training rollout for the Operations department',
                        'help'        => 'Describe the outcome, not the steps - the agent breaks it down.',
                    ],
                    [
                        'name'    => 'scope',
                        'label'   => 'Assign Across',
                        'type'    => 'select',
                        'default' => 'team',
                        'options' => [
                            [
                                'value' => 'department',
                                'label' => 'Department',
                            ],
                            [
                                'value' => 'team',
                                'label' => 'Team',
                            ],
                            [
                                'value' => 'individual',
                                'label' => 'Individual',
                            ],
                        ],
                    ],
                    [
                        'name'        => 'target',
                        'label'       => 'Department / Team / Person',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Operations',
                    ],
                    [
                        'name'  => 'due_date',
                        'label' => 'Target Completion',
                        'type'  => 'date',
                    ],
                    [
                        'name'    => 'priority',
                        'label'   => 'Priority',
                        'type'    => 'select',
                        'default' => 'medium',
                        'options' => [
                            [
                                'value' => 'low',
                                'label' => 'Low',
                            ],
                            [
                                'value' => 'medium',
                                'label' => 'Medium',
                            ],
                            [
                                'value' => 'high',
                                'label' => 'High',
                            ],
                            [
                                'value' => 'urgent',
                                'label' => 'Urgent',
                            ],
                        ],
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Task Management Agent',
                'icon' => 'CheckSquare',
                'module' => 'Organization Management',
                'sub_module' => 'Task Assignment',
                'description' => 'Intelligently supports task creation, allocation, and prioritization using organizational context',
                'function_text' => 'This agent intelligently supports task creation, allocation, and prioritization by analyzing organizational context, roles, and workload distribution.',
                'workflow' => [
                    'User initiates task creation or assignment',
                    'Agent analyzes task intent and complexity',
                    'Maps tasks to relevant roles or users',
                    'Suggests priority, dependencies, and timelines',
                ],
                'outputs' => [
                    'Smart Task Assignments',
                    'Priority & Effort Recommendations',
                    'Role-Aligned Task Distribution',
                    'Reduced Manual Planning Overhead',
                ],
                'cta_label' => 'Go to Task Assignment',
                'cta_link' => '/module/m6/tm-tasks/tm-tasks',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'sql_query'],
                'system_prompt' => 'You assign and prioritise tasks using role, workload and dependency context. Return an assignee, a priority, an effort estimate and any blocking dependencies.',
            ],
            [
                'slug' => 'skill-generator',
                'input_schema' => [
                    [
                        'name'        => 'job_role',
                        'label'       => 'Job Role',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Quality Assurance Engineer',
                    ],
                    [
                        'name'        => 'department',
                        'label'       => 'Department',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Manufacturing',
                    ],
                    [
                        'name'        => 'industry',
                        'label'       => 'Industry Context',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Automotive components',
                    ],
                    [
                        'name'    => 'skill_count',
                        'label'   => 'Skills to Generate',
                        'type'    => 'number',
                        'default' => 10,
                        'min'     => 1,
                        'max'     => 50,
                    ],
                    [
                        'name'    => 'proficiency_model',
                        'label'   => 'Proficiency Scale',
                        'type'    => 'select',
                        'default' => '5',
                        'options' => [
                            [
                                'value' => '4',
                                'label' => '4 levels',
                            ],
                            [
                                'value' => '5',
                                'label' => '5 levels (Novice to Expert)',
                            ],
                        ],
                        'help'    => 'Must match the scale your Competency Library already uses.',
                    ],
                    [
                        'name'    => 'include_behavioural',
                        'label'   => 'Include behavioural competencies',
                        'type'    => 'boolean',
                        'default' => true,
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Skill Generator Agent',
                'icon' => 'Award',
                'module' => 'Competency Management',
                'sub_module' => 'Competency Library',
                'description' => 'Dynamically creates standardized skill definitions aligned with industry frameworks',
                'function_text' => 'The Skill Generator Agent dynamically creates standardized skill definitions aligned with industry frameworks, job roles, and organizational needs.',
                'workflow' => [
                    'User inputs domain, role, or competency requirement',
                    'Agent references internal frameworks and standards',
                    'Generates structured skill taxonomy',
                    'Skills are published to the competency library',
                ],
                'outputs' => [
                    'Skill Name & Description',
                    'Category & Sub-Category Mapping',
                    'Proficiency Level Definitions',
                    'Reusable Skill Objects',
                ],
                'cta_label' => 'Open Competency Library',
                'cta_link' => '/module/m2/cm-competency-library/cm-competency-library',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'web_search'],
                'system_prompt' => 'You generate structured skill definitions. Always return category, sub-category, description and a proficiency scale.',
            ],
            [
                'slug' => 'job-role-generator',
                'input_schema' => [
                    [
                        'name'        => 'role_title',
                        'label'       => 'Role Title',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Maintenance Supervisor',
                    ],
                    [
                        'name'        => 'department',
                        'label'       => 'Department',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Plant Operations',
                    ],
                    [
                        'name'    => 'seniority',
                        'label'   => 'Seniority',
                        'type'    => 'select',
                        'default' => 'mid',
                        'options' => [
                            [
                                'value' => 'entry',
                                'label' => 'Entry',
                            ],
                            [
                                'value' => 'junior',
                                'label' => 'Junior',
                            ],
                            [
                                'value' => 'mid',
                                'label' => 'Mid',
                            ],
                            [
                                'value' => 'senior',
                                'label' => 'Senior',
                            ],
                            [
                                'value' => 'lead',
                                'label' => 'Lead',
                            ],
                            [
                                'value' => 'manager',
                                'label' => 'Manager',
                            ],
                            [
                                'value' => 'director',
                                'label' => 'Director',
                            ],
                        ],
                    ],
                    [
                        'name'  => 'industry',
                        'label' => 'Industry Context',
                        'type'  => 'text',
                    ],
                    [
                        'name'    => 'responsibility_count',
                        'label'   => 'Responsibilities to Draft',
                        'type'    => 'number',
                        'default' => 8,
                        'min'     => 3,
                        'max'     => 25,
                    ],
                    [
                        'name'        => 'reports_to',
                        'label'       => 'Reports To',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Plant Manager',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Job Role Generator Agent',
                'icon' => 'Briefcase',
                'module' => 'Competency Management',
                'sub_module' => 'Libraries & Taxonomy',
                'description' => 'Automates creation of detailed job role definitions with skill and responsibility mappings',
                'function_text' => 'This agent automates the creation of detailed job role definitions using industry-aligned skill and responsibility mappings.',
                'workflow' => [
                    'User specifies role intent or industry context',
                    'Agent analyzes benchmark data and competencies',
                    'Generates role structure with expectations',
                    'Role is added to the competency ecosystem',
                ],
                'outputs' => [
                    'Job Role Description',
                    'Required Skills & Proficiency Levels',
                    'Responsibility & Outcome Mapping',
                    'Career Path Alignment',
                ],
                'cta_label' => 'Manage Job Roles',
                'cta_link' => '/module/m2/cm-libraries-taxonomy/cm-libraries-taxonomy',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'web_search'],
                'system_prompt' => 'You draft job role definitions: responsibilities, required skills with a proficiency level each, and career path adjacencies.',
            ],
            [
                'slug' => 'sanity-check',
                'input_schema' => [
                    [
                        'name'     => 'scope',
                        'label'    => 'Check Across',
                        'type'     => 'select',
                        'default'  => 'department',
                        'required' => true,
                        'options'  => [
                            [
                                'value' => 'department',
                                'label' => 'Department',
                            ],
                            [
                                'value' => 'jobrole',
                                'label' => 'Job Role',
                            ],
                            [
                                'value' => 'employee',
                                'label' => 'Single Employee',
                            ],
                        ],
                    ],
                    [
                        'name'        => 'target',
                        'label'       => 'Department / Role / Employee',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Production',
                    ],
                    [
                        'name'    => 'rating_window',
                        'label'   => 'Ratings From',
                        'type'    => 'select',
                        'default' => 'current-cycle',
                        'options' => [
                            [
                                'value' => 'last-30',
                                'label' => 'Last 30 days',
                            ],
                            [
                                'value' => 'last-90',
                                'label' => 'Last 90 days',
                            ],
                            [
                                'value' => 'current-cycle',
                                'label' => 'Current assessment cycle',
                            ],
                        ],
                    ],
                    [
                        'name'    => 'deviation_threshold',
                        'label'   => 'Flag Gaps Wider Than',
                        'type'    => 'number',
                        'default' => 2,
                        'min'     => 1,
                        'max'     => 5,
                        'help'    => 'Proficiency levels between an employee self-rating and their manager rating.',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Sanity Check Agent',
                'icon' => 'ShieldCheck',
                'module' => 'Competency Management',
                'sub_module' => 'Employee Profiles',
                'description' => 'Validates user self-assessments by identifying inconsistencies and rating gaps',
                'function_text' => 'The Sanity Check Agent validates user self-assessments by identifying inconsistencies, overstatements, or gaps in skill ratings.',
                'workflow' => [
                    'User submits self-rated skills',
                    'Agent cross-validates ratings against benchmarks',
                    'Flags anomalies or unrealistic scores',
                    'Provides corrective guidance',
                ],
                'outputs' => [
                    'Skill Rating Validation',
                    'Confidence & Gap Indicators',
                    'Rating Adjustment Suggestions',
                    'Improved Data Accuracy',
                ],
                'cta_label' => 'Review Skill Self-Rating',
                'cta_link' => '/module/m2/cm-employee-profiles/cm-employee-profiles',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'sql_query'],
                'system_prompt' => 'You validate skill self-assessments against role benchmarks. Flag each anomaly with the evidence and a suggested corrected rating.',
            ],
            [
                'slug' => 'course-generator',
                'input_schema' => [
                    [
                        'name'        => 'topic',
                        'label'       => 'Course Topic',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Lockout / Tagout Safety Procedures',
                    ],
                    [
                        'name'        => 'job_role',
                        'label'       => 'Target Job Role',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Maintenance Technician',
                    ],
                    [
                        'name'  => 'department',
                        'label' => 'Department',
                        'type'  => 'text',
                    ],
                    [
                        'name'    => 'content_type',
                        'label'   => 'Generate',
                        'type'    => 'select',
                        'default' => 'course',
                        'options' => [
                            [
                                'value' => 'course',
                                'label' => 'Course only',
                            ],
                            [
                                'value' => 'jobrole',
                                'label' => 'Course + Assessment',
                            ],
                        ],
                    ],
                    [
                        'name'    => 'slide_count',
                        'label'   => 'Slides',
                        'type'    => 'number',
                        'default' => 10,
                        'min'     => 3,
                        'max'     => 60,
                    ],
                    [
                        'name'    => 'difficulty',
                        'label'   => 'Difficulty',
                        'type'    => 'select',
                        'default' => 'intermediate',
                        'options' => [
                            [
                                'value' => 'beginner',
                                'label' => 'Beginner',
                            ],
                            [
                                'value' => 'intermediate',
                                'label' => 'Intermediate',
                            ],
                            [
                                'value' => 'advanced',
                                'label' => 'Advanced',
                            ],
                        ],
                    ],
                    [
                        'name'        => 'learning_objectives',
                        'label'       => 'Learning Objectives',
                        'type'        => 'textarea',
                        'rows'        => 3,
                        'placeholder' => 'One per line. Leave blank to have the agent propose them.',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Build with AI – Course Generator Agent',
                'icon' => 'BookOpen',
                'module' => 'LMS',
                'sub_module' => 'Course Builder',
                'description' => 'Creates structured, modular learning courses tailored to specific skills and roles',
                'function_text' => 'This agent creates structured, modular learning courses tailored to specific skills, roles, or competency gaps.',
                'workflow' => [
                    'User selects skill or role',
                    'Agent designs learning objectives',
                    'Generates course structure and modules',
                    'Content is published for learning delivery',
                ],
                'outputs' => [
                    'Course Outline & Modules',
                    'Learning Objectives',
                    'Skill-to-Content Mapping',
                    'AI-Generated Learning Assets',
                ],
                'cta_label' => 'Create AI Course',
                'cta_link' => '/module/m4/lms-administration/create-course',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'file_operations'],
                'system_prompt' => 'You design modular course outlines: learning objectives, modules, and an assessment per module.',
            ],
            [
                'slug' => 'pal-agent',
                'input_schema' => [
                    [
                        'name'        => 'question',
                        'label'       => 'Your Question',
                        'type'        => 'textarea',
                        'required'    => true,
                        'rows'        => 4,
                        'placeholder' => 'Ask about a policy, a process, or your own development plan',
                    ],
                    [
                        'name'        => 'context',
                        'label'       => 'Extra Context',
                        'type'        => 'textarea',
                        'rows'        => 3,
                        'placeholder' => 'Anything the agent should know before answering',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Personalized Adaptive Learning (PAL) Agent',
                'icon' => 'Brain',
                'module' => 'Agentic AI',
                'sub_module' => 'PAL',
                'description' => 'Delivers adaptive learning journeys with real-time content adjustment based on performance',
                'function_text' => 'PAL delivers adaptive learning journeys by continuously adjusting content based on user performance, behavior, and progress.',
                'workflow' => [
                    'Agent analyzes user skill profile',
                    'Maps learning goals and gaps',
                    'Dynamically adjusts learning path',
                    'Continuously optimizes recommendations',
                ],
                'outputs' => [
                    'Personalized Learning Paths',
                    'Real-Time Adaptation',
                    'Skill Progress Insights',
                    'Outcome-Oriented Learning',
                ],
                'cta_label' => 'Open PAL Agent',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base'],
                'system_prompt' => 'You adapt a learner’s path from their skill profile and recent performance. Return the next best module and why.',
            ],
            [
                'slug' => 'marketing-strategy',
                'input_schema' => [
                    [
                        'name'        => 'business_type',
                        'label'       => 'Business Type',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Online Clothing Store, Fitness Gym, Restaurant',
                    ],
                    [
                        'name'        => 'target_audience',
                        'label'       => 'Target Audience',
                        'type'        => 'textarea',
                        'required'    => true,
                        'rows'        => 3,
                        'placeholder' => 'e.g. Women aged 18-35 interested in fashion, Working professionals aged 25-40',
                    ],
                    [
                        'name'        => 'goal',
                        'label'       => 'Marketing Goal',
                        'type'        => 'textarea',
                        'required'    => true,
                        'rows'        => 2,
                        'placeholder' => 'e.g. Boost online sales through social media marketing',
                    ],
                ],
                'config_schema' => [
                    [
                        'name'        => 'agent_id',
                        'label'       => 'Marketing Agent ID',
                        'type'        => 'text',
                        'placeholder' => 'a6b36706-3fa3-4146-98c2-ff05321e8123',
                        'help'        => 'The agent id registered with your marketing service.',
                    ],
                ],
                'launch_component' => null,
                'name' => 'Marketing Strategy Agent',
                'icon' => 'TrendingUp',
                'module' => 'Marketing',
                'sub_module' => 'Strategy & Planning',
                'description' => 'Generates comprehensive marketing strategies aligned with business goals and target audience insights',
                'function_text' => 'The Marketing Strategy Agent generates data-driven marketing strategies by analyzing market trends, competitor insights, and business objectives to create actionable go-to-market plans.',
                'workflow' => [
                    'User defines business goals and target audience',
                    'Agent analyzes market trends and competitor landscape',
                    'Generates strategic recommendations and channels',
                    'Creates detailed marketing plan with timelines',
                ],
                'outputs' => [
                    'Marketing Strategy Document',
                    'Target Audience Personas',
                    'Channel Recommendations',
                    'Campaign Timeline & Budget',
                ],
                'cta_label' => 'Open Marketing Strategy',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['web_search', 'knowledge_base', 'data_viz'],
                'system_prompt' => 'You produce go-to-market strategies: audience personas, channel mix, timeline and budget.',
            ],
            [
                'slug' => 'smart-recruitment',
                'input_schema' => [
                    [
                        'name'        => 'job_role',
                        'label'       => 'Role Being Hired',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Production Line Supervisor',
                    ],
                    [
                        'name'  => 'department',
                        'label' => 'Department',
                        'type'  => 'text',
                    ],
                    [
                        'name'    => 'experience_years',
                        'label'   => 'Minimum Experience (years)',
                        'type'    => 'number',
                        'default' => 2,
                        'min'     => 0,
                        'max'     => 40,
                    ],
                    [
                        'name'        => 'required_skills',
                        'label'       => 'Must-Have Skills',
                        'type'        => 'tags',
                        'placeholder' => 'Type a skill and press Enter',
                    ],
                    [
                        'name'  => 'location',
                        'label' => 'Location',
                        'type'  => 'text',
                    ],
                    [
                        'name'        => 'screening_criteria',
                        'label'       => 'Screening Notes',
                        'type'        => 'textarea',
                        'rows'        => 3,
                        'placeholder' => 'Anything that should rule a candidate in or out',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Smart Recruitment Agent',
                'icon' => 'Users',
                'module' => 'Talent Management',
                'sub_module' => 'Talent Acquisition',
                'description' => 'Enhances hiring through intelligent candidate-job matching using competency alignment',
                'function_text' => 'The Smart Recruitment Agent enhances hiring by matching candidates with job roles using skill, experience, and competency alignment.',
                'workflow' => [
                    'Job role and requirements are defined',
                    'Agent evaluates candidate profiles',
                    'Performs intelligent matching',
                    'Ranks candidates by fitment score',
                ],
                'outputs' => [
                    'Candidate Fit Scores',
                    'Skill Match Analysis',
                    'Hiring Recommendations',
                    'Reduced Screening Time',
                ],
                'cta_label' => 'Go to Talent Acquisition',
                'cta_link' => '/module/m3/recruitment/recruitment',
                'cta_target' => 'internal',
                'tools' => ['knowledge_base', 'sql_query', 'data_viz'],
                'system_prompt' => 'You score candidate-to-role fit on skills, experience and competency alignment. Return a ranked list with the reason for each score.',
            ],
            [
                'slug' => 'content-automation',
                'input_schema' => [
                    [
                        'name'     => 'file',
                        'label'    => 'Content Plan Spreadsheet',
                        'type'     => 'file',
                        'required' => true,
                        'accept'   => '.xlsx,.xls',
                        'help'     => 'One row per item. Columns: Department Name, Job Role, Chapter/Topic, Content Type, Slides.',
                    ],
                    [
                        'name'    => 'content_type',
                        'label'   => 'Default Content Type',
                        'type'    => 'select',
                        'default' => 'course',
                        'options' => [
                            [
                                'value' => 'course',
                                'label' => 'Course only',
                            ],
                            [
                                'value' => 'jobrole',
                                'label' => 'Course + Assessment',
                            ],
                        ],
                        'help'    => 'Used for rows that leave the Content Type column blank.',
                    ],
                    [
                        'name'    => 'default_slide_count',
                        'label'   => 'Default Slides Per Item',
                        'type'    => 'number',
                        'default' => 10,
                        'min'     => 3,
                        'max'     => 60,
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Content Automation System',
                'icon' => 'Zap',
                'module' => 'Content Management',
                'sub_module' => 'Automation',
                'description' => 'Automates content creation, curation, and distribution across multiple channels using AI',
                'function_text' => 'The Content Automation System streamlines content workflows by automatically generating, organizing, and distributing content based on defined rules and AI-powered insights.',
                'workflow' => [
                    'User defines content requirements and targets',
                    'System analyzes existing content and trends',
                    'AI generates or curates relevant content',
                    'Content is automatically formatted and distributed',
                ],
                'outputs' => [
                    'Automated Content Generation',
                    'Multi-Channel Distribution',
                    'Content Analytics',
                    'Workflow Optimization',
                ],
                'cta_label' => 'Open Content Automation',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['web_search', 'file_operations', 'n8n'],
                'system_prompt' => 'You generate and route content across channels according to the supplied rules.',
            ],
            [
                'slug' => 'newsletter-agent',
                'input_schema' => [
                    [
                        'name'        => 'topic',
                        'label'       => 'Newsletter Topic',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'e.g. Q3 safety performance and upcoming training',
                    ],
                    [
                        'name'        => 'audience_segment',
                        'label'       => 'Audience Segment',
                        'type'        => 'text',
                        'placeholder' => 'e.g. All plant staff, Managers only',
                    ],
                    [
                        'name'    => 'tone',
                        'label'   => 'Tone',
                        'type'    => 'select',
                        'default' => 'professional',
                        'options' => [
                            [
                                'value' => 'professional',
                                'label' => 'Professional',
                            ],
                            [
                                'value' => 'friendly',
                                'label' => 'Friendly',
                            ],
                            [
                                'value' => 'concise',
                                'label' => 'Concise',
                            ],
                            [
                                'value' => 'enthusiastic',
                                'label' => 'Enthusiastic',
                            ],
                        ],
                    ],
                    [
                        'name'    => 'section_count',
                        'label'   => 'Sections',
                        'type'    => 'number',
                        'default' => 4,
                        'min'     => 1,
                        'max'     => 12,
                    ],
                    [
                        'name'        => 'cta_text',
                        'label'       => 'Call To Action',
                        'type'        => 'text',
                        'placeholder' => 'e.g. Book your refresher session',
                    ],
                    [
                        'name'        => 'send_test_to',
                        'label'       => 'Send Test To',
                        'type'        => 'email',
                        'placeholder' => 'you@company.com',
                        'help'        => 'Leave blank to generate a draft without sending anything.',
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Newsletter Agent',
                'icon' => 'Mail',
                'module' => 'Agentic AI',
                'sub_module' => 'Newsletter',
                'description' => 'Create and manage AI-powered newsletters with intelligent content generation and distribution',
                'function_text' => 'The Newsletter Agent helps you create, manage, and distribute AI-powered newsletters. It assists with content generation, template selection, subscriber management, and automated delivery scheduling.',
                'workflow' => [
                    'User selects newsletter type or template',
                    'Agent generates content based on user preferences',
                    'User reviews and edits the generated content',
                    'Agent schedules and distributes the newsletter',
                ],
                'outputs' => [
                    'AI-Generated Newsletter Content',
                    'Customizable Templates',
                    'Subscriber Management',
                    'Automated Scheduling',
                ],
                'cta_label' => 'Open Newsletter Agent',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['email', 'web_search'],
                'system_prompt' => 'You write concise internal newsletters in a warm, professional tone, and prepare them for scheduled delivery.',
            ],
            [
                'slug' => 'seo-agent',
                'input_schema' => [
                    [
                        'name'        => 'url',
                        'label'       => 'Website URL',
                        'type'        => 'url',
                        'required'    => true,
                        'placeholder' => 'https://www.example.com',
                    ],
                    [
                        'name'    => 'mode',
                        'label'   => 'Analysis Mode',
                        'type'    => 'select',
                        'default' => 'basic',
                        'options' => [
                            [
                                'value' => 'basic',
                                'label' => 'Basic - Quick Analysis',
                            ],
                            [
                                'value' => 'advanced',
                                'label' => 'Advanced - AI Recommendations',
                            ],
                        ],
                    ],
                    [
                        'name'     => 'analysis_type',
                        'label'    => 'Analysis Type',
                        'type'     => 'select',
                        'default'  => 'audit',
                        'required' => true,
                        'options'  => [
                            [
                                'value' => 'audit',
                                'label' => 'Full Audit - score, issues and AI recommendations',
                            ],
                            [
                                'value' => 'score',
                                'label' => 'Quick Score - 0-100 score and grade',
                            ],
                            [
                                'value' => 'crawl',
                                'label' => 'Website Crawl - raw crawl data, no analysis',
                            ],
                            [
                                'value' => 'issues',
                                'label' => 'Issues List - issues only',
                            ],
                            [
                                'value' => 'recommend',
                                'label' => 'AI Recommendations - recommendations only',
                            ],
                            [
                                'value' => 'full-report',
                                'label' => 'Complete Report - everything',
                            ],
                        ],
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'SEO Agent',
                'icon' => 'Search',
                'module' => 'Marketing',
                'sub_module' => 'SEO Optimization',
                'description' => 'Optimize website content and structure to improve search engine rankings and organic visibility',
                'function_text' => 'The SEO Agent helps optimize website content for search engines by analyzing keywords, improving content structure, meta tags, and building SEO strategies to increase organic traffic and visibility.',
                'workflow' => [
                    'User provides website URL or content to optimize',
                    'Agent analyzes current SEO performance and identifies gaps',
                    'Generates keyword recommendations and content suggestions',
                    'Provides actionable SEO improvements and rankings',
                ],
                'outputs' => [
                    'Keyword Recommendations',
                    'Content Optimization Tips',
                    'SEO Score Analysis',
                    'Competitor Analysis',
                ],
                'cta_label' => 'Open SEO Agent',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['web_search', 'data_viz'],
                'system_prompt' => 'You audit page content for search visibility and return concrete, prioritised improvements.',
            ],
            [
                'slug' => 'social-media-automation',
                'input_schema' => [
                    [
                        'name'     => 'file',
                        'label'    => 'Content Plan Excel File',
                        'type'     => 'file',
                        'required' => true,
                        'accept'   => '.xlsx',
                        'help'     => 'Column headers must match the connected sheet template exactly.',
                    ],
                ],
                'config_schema' => [
                    [
                        'name'        => 'google_sheet_url',
                        'label'       => 'Google Sheet URL or ID',
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'https://docs.google.com/spreadsheets/d/... or the sheet ID',
                    ],
                    [
                        'name'     => 'service_account_key_file',
                        'label'    => 'Service Account Key File',
                        'type'     => 'file',
                        'required' => true,
                        'accept'   => '.json',
                        'secret'   => true,
                        'help'     => 'The JSON key for a service account with edit access to that sheet. Encrypted at rest and never displayed again.',
                    ],
                ],
                'launch_component' => 'excel-automation',
                'name' => 'Social Media Content Automation',
                'icon' => 'Workflow',
                'module' => 'Marketing',
                'sub_module' => 'Social Media',
                'description' => 'Transforms Google Sheet content rows into LinkedIn posts, branded infographics, and SEO-optimized blog content for multi-platform publishing',
                'function_text' => 'The agent acts as a contextual translator. It parses a single row from your Google Sheet (Topic/Thesis) and simultaneously reasons how to adapt that message for professional social feeds versus long-form SEO content.',
                'workflow' => [
                    'Trigger: detects a status change (e.g. "Ready to Post") in a Google Sheet row',
                    'LinkedIn worker drafts copy and triggers an image API to overlay sheet data onto a branded infographic template',
                    'Blog worker expands the topic into a structured, SEO-optimised narrative',
                    'Both outputs are returned for review and publishing',
                ],
                'outputs' => [
                    'A 200-word professional LinkedIn post with hook, value bullets and CTA',
                    'A 1080x1350 branded infographic of the row’s key insights',
                    'An SEO-optimised long-form blog draft',
                    'Multi-platform publishing queue',
                ],
                'cta_label' => 'Open Excel Automation',
                'cta_link' => 'workspace',
                'cta_target' => 'internal',
                'tools' => ['file_operations', 'web_search', 'n8n'],
                'system_prompt' => 'You adapt one content row into a LinkedIn post and a long-form SEO blog draft, keeping the thesis identical across both.',
            ],
            [
                'slug' => 'website-analyzer',
                'input_schema' => [
                    [
                        'name'        => 'website_url',
                        'label'       => 'Website URL',
                        'type'        => 'url',
                        'required'    => true,
                        'placeholder' => 'https://www.example.com',
                    ],
                    [
                        'name'    => 'depth',
                        'label'   => 'Crawl Depth',
                        'type'    => 'select',
                        'default' => 'homepage',
                        'options' => [
                            [
                                'value' => 'homepage',
                                'label' => 'Homepage only',
                            ],
                            [
                                'value' => 'key-pages',
                                'label' => 'Key pages',
                            ],
                            [
                                'value' => 'full',
                                'label' => 'Full site crawl',
                            ],
                        ],
                    ],
                    [
                        'name'    => 'focus',
                        'label'   => 'Extract',
                        'type'    => 'multiselect',
                        'default' => [
                            'services',
                            'branding',
                        ],
                        'options' => [
                            [
                                'value' => 'services',
                                'label' => 'Products & services',
                            ],
                            [
                                'value' => 'branding',
                                'label' => 'Branding & positioning',
                            ],
                            [
                                'value' => 'tone',
                                'label' => 'Tone of voice',
                            ],
                            [
                                'value' => 'contact',
                                'label' => 'Contact details',
                            ],
                            [
                                'value' => 'structure',
                                'label' => 'Organisation structure',
                            ],
                        ],
                    ],
                ],
                'config_schema' => [],
                'launch_component' => null,
                'name' => 'Website Analyzer Agent',
                'icon' => 'Globe',
                'module' => 'Organization Management',
                'sub_module' => 'Organization Profile',
                'description' => 'Evaluates organization websites to extract business intelligence and capability indicators',
                'function_text' => 'The Website Analyzer Agent evaluates an organization\'s website to extract business intelligence, operational signals, and capability indicators. It supports automated understanding of an organization\'s industry presence, maturity, and functional focus.',
                'workflow' => [
                    'User provides or confirms the organization website URL',
                    'Agent crawls and analyzes publicly available content',
                    'NLP and semantic models identify industry, offerings, roles, and skills',
                    'Insights are structured and stored within the organization profile',
                ],
                'outputs' => [
                    'Identified Industry & Sub-Industry',
                    'Extracted Business Functions',
                    'Role & Skill Indicators',
                    'Website Quality & Content Signals',
                ],
                'cta_label' => 'View in Organization Management',
                'cta_link' => '/module/m1/org-setup/org-profile',
                'cta_target' => 'internal',
                'tools' => ['web_search', 'knowledge_base'],
                'system_prompt' => 'You analyse a company website and extract industry, offerings, functions and capability signals as structured data.',
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->agents() as $agent) {
            $existing = DB::table('agentic_agents')
                ->whereNull('sub_institute_id')
                ->where('origin', 'platform')
                ->where('slug', $agent['slug'])
                ->first();

            // Catalogue copy is owned by this seeder and refreshed on each run.
            $content = [
                'name'          => $agent['name'],
                'icon'          => $agent['icon'],
                'module'        => $agent['module'],
                'sub_module'    => $agent['sub_module'],
                'description'   => $agent['description'],
                'function_text' => $agent['function_text'],
                'workflow'      => json_encode($agent['workflow']),
                'outputs'       => json_encode($agent['outputs']),
                'cta_label'     => $agent['cta_label'],
                'cta_link'      => $agent['cta_link'],
                'cta_target'    => $agent['cta_target'],
                'tools'         => json_encode($agent['tools']),
                'system_prompt' => $agent['system_prompt'],
                // The typed contract: what to ask on every run, and what to
                // ask once and remember per tenant.
                'input_schema'     => json_encode($agent['input_schema']),
                'config_schema'    => json_encode($agent['config_schema']),
                'launch_component' => $agent['launch_component'],
                'updated_at'    => now(),
            ];

            // 'workspace' is a placeholder: the link needs the agent's own id,
            // which is only known once the row exists.
            if ($content['cta_link'] === 'workspace' && $existing) {
                $content['cta_link'] = sprintf(self::WORKSPACE, $existing->id);
            }

            if ($existing) {
                // Deliberately NOT touched: execution_mode, endpoint_url,
                // endpoint_headers and status. Wiring an agent to a real
                // HuggingFace Space or n8n webhook must survive a re-seed.
                DB::table('agentic_agents')->where('id', $existing->id)->update($content);
                continue;
            }

            $content['cta_link'] = $content['cta_link'] === 'workspace' ? null : $content['cta_link'];

            $id = DB::table('agentic_agents')->insertGetId($content + [
                'sub_institute_id' => null,
                'origin'           => 'platform',
                'slug'             => $agent['slug'],
                'role'             => 'catalogue',
                'model'            => 'gpt-4o',
                'temperature'      => 0.60,
                'max_tokens'       => 3000,
                // Catalogue entries are live capabilities, not drafts.
                'status'           => 'deployed',
                // No endpoint yet: a run is recorded and reported into until one
                // is configured. Set execution_mode = 'http' plus endpoint_url
                // to have the platform call HuggingFace / n8n directly.
                'execution_mode'   => 'none',
                'endpoint_method'  => 'POST',
                'endpoint_timeout' => 60,
                'created_at'       => now(),
            ]);

            // A freshly inserted workspace agent gets its link now that it has an id.
            if ($agent['cta_link'] === 'workspace') {
                DB::table('agentic_agents')
                    ->where('id', $id)
                    ->update(['cta_link' => sprintf(self::WORKSPACE, $id)]);
            }
        }

        $total = DB::table('agentic_agents')->where('origin', 'platform')->count();
        $this->command?->info("Agentic catalogue seeded: {$total} platform agents.");
    }
}
