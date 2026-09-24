<?php

/*
|--------------------------------------------------------------------------
| Platform services registry — modules, components, and what each offers
|--------------------------------------------------------------------------
|
| THE ONE IDEA. Every configurable thing is addressed as `module.component`.
| `hrms` is a module; `hrms.leave` is a component. Under a component sit the things
| the platform services configure:
|
|   `hrms.leave.approval`      a workflow point
|   `events.project`           a scheduled task
|
| WHY THIS FILE IS THE SOURCE OF TRUTH AND NOT THE FRONTEND.
| The screens must not be able to offer a setting the backend has no hook for, and the
| API must refuse a key nobody declared. Both follow from the catalogue living here and
| being served at GET /api/platform/registry. A port of this list into TypeScript was
| considered and rejected: two copies of a contract drift, and the copy that drifts is
| always the one doing the validating.
|
| WHY A DECLARED REGISTRY AND NOT THE LIVE MENU.
| `tblmenumaster_g2g` describes SCREENS A ROLE MAY OPEN. A screen is not a thing that
| needs a sign-off or runs on a timer. What these services configure is a component's
| BEHAVIOUR, and behaviour has to be declared by whoever builds the component. So a
| module appears here because somebody wrote it here, and a component gains a workflow
| point the day its owner adds one.
|
| THIS FILE GRANTS NOTHING. It names what exists, never who may change it. Access is
| `profile:admin` on the whole route group in routes/platform.php.
|
| NO CLOSURES, NO OBJECTS. `php artisan config:cache` serialises this file; a closure
| here makes that command fail, and the failure is confusing because everything works
| until somebody caches config on a deployment.
|
| ADDING TO IT. A new module needs an entry under `modules`; a new component needs one
| under `components` whose key starts with its module; a new workflow point needs one
| under `workflows` with a key starting with its component. Nothing else — no migration,
| no frontend change.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | G2G's own, written from what this codebase actually has. These are deliberately
    | NOT LMS K-12's sixteen: that product's `fees`, `admissions` and `hostel` have no
    | counterpart here, and declaring them would offer settings for screens nobody can
    | open.
    |
    */
    'modules' => [
        'organization' => [
            'label' => 'Organisation',
            'description' => 'Departments, employees, roles and readiness.',
            'icon' => 'Building2',
        ],
        'hrms' => [
            'label' => 'HRMS',
            'description' => 'Attendance, leave and compliance.',
            'icon' => 'CalendarCheck',
        ],
        'talent' => [
            'label' => 'Talent',
            'description' => 'Recruitment, onboarding, performance and offboarding.',
            'icon' => 'Users',
        ],
        'lms' => [
            'label' => 'Learning',
            'description' => 'Courses, assessments and records.',
            'icon' => 'GraduationCap',
        ],
        'competency' => [
            'label' => 'Capability',
            'description' => 'Frameworks, assessments and certifications.',
            'icon' => 'Target',
        ],
        'task' => [
            'label' => 'Task management',
            'description' => 'Projects, tasks and execution evidence.',
            'icon' => 'ListChecks',
        ],
        'events' => [
            'label' => 'Platform',
            'description' => 'The event store and the jobs that drain it.',
            'icon' => 'Waypoints',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Components
    |--------------------------------------------------------------------------
    |
    | A component is the part of a module that owns a behaviour. The key must start
    | with its module's key — `PlatformRegistry::problems()` reports any that does not
    | rather than silently dropping it.
    |
    */
    'components' => [
        'organization.roles' => [
            'label' => 'Roles & access',
            'description' => 'Who may open which screen.',
        ],
        'organization.departments' => [
            'label' => 'Departments',
            'description' => 'The reporting structure.',
        ],
        'hrms.leave' => [
            'label' => 'Leave',
            'description' => 'Requests, balances and approvals.',
        ],
        'hrms.attendance' => [
            'label' => 'Attendance',
            'description' => 'Punches, regularisation and reports.',
        ],
        'talent.recruitment' => [
            'label' => 'Recruitment',
            'description' => 'Requisitions, candidates and offers.',
        ],
        'talent.onboarding' => [
            'label' => 'Onboarding',
            'description' => 'Journeys, tasks and documents for a new joiner.',
        ],
        'talent.offboarding' => [
            'label' => 'Offboarding',
            'description' => 'Exit cases and clearance.',
        ],
        'talent.mobility' => [
            'label' => 'Mobility',
            'description' => 'Internal moves and succession.',
        ],
        'lms.enrolment' => [
            'label' => 'Enrolment',
            'description' => 'Who is assigned which course.',
        ],
        'competency.assessment' => [
            'label' => 'Assessment',
            'description' => 'Capability ratings and their review.',
        ],
        'competency.certification' => [
            'label' => 'Certification',
            'description' => 'Issue, renewal and expiry.',
        ],
        'task.execution' => [
            'label' => 'Task execution',
            'description' => 'Submission, approval and evidence.',
        ],
        'events.store' => [
            'label' => 'Event store',
            'description' => 'Recording, projection and reaction.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow points
    |--------------------------------------------------------------------------
    |
    | A POINT IS NOT A CHAIN. A point is a place in the product where an action CAN
    | pause for a sign-off — that is ours, declared here. A chain is the ladder of
    | approvers a particular organisation puts at that point — that is theirs, stored in
    | `g2g_platform_workflows`. Most points will have no chain, and that is the thing an
    | administrator opens the screen to see.
    |
    | `subject` is what the record is called in an approval inbox, so a step can read
    | "approve this leave request" rather than "approve this record".
    |
    | `suggested_steps` is a starting ladder offered when somebody adds the first chain
    | at a point. It is a suggestion and never applied on its own.
    |
    */
    'workflows' => [
        'hrms.leave.approval' => [
            'label' => 'Leave approval',
            'description' => 'Sign-off before a leave request is granted.',
            'subject' => 'Leave request',

            /*
             * ═══════════════════════════════════════════════════════════════
             * THE ONLY POINT IN THIS LIST THAT IS ACTUALLY ENFORCED
             * ═══════════════════════════════════════════════════════════════
             *
             * `LeaveApprovalWorkflow::chainFor()` reads the chain in force here
             * and freezes it onto `hrms_leave_approval_steps` when a request is
             * submitted. Every decision, escalation and notification downstream
             * then reads those frozen rows.
             *
             * Every OTHER point below is declared and read by nothing. A chain
             * saved against one of them is a plan, not a gate — and the console
             * must say so rather than showing all eight with the same green pill.
             * That was the defect this key exists to close: a screen that claims
             * a sign-off which will not happen is worse than no screen.
             *
             * The class is named rather than a bare `true` so `problems()` can
             * report a point that claims enforcement by a class nobody kept.
             */
            'enforced_by' => \App\Services\Leave\LeaveApprovalWorkflow::class,
            'enforced_note' => 'Enforced when a request is submitted. Editing or deleting a chain '
                . 'does not change requests already in flight — they keep the ladder they '
                . 'were submitted under.',
            'suggested_steps' => [
                ['name' => 'Reporting manager', 'approver_type' => 'reporting_manager', 'sla_hours' => 24, 'on_breach' => 'remind'],
                ['name' => 'HR', 'approver_type' => 'role', 'approver' => 'hr_manager', 'sla_hours' => 48, 'on_breach' => 'escalate'],
            ],
        ],
        'hrms.attendance.regularisation' => [
            'label' => 'Attendance regularisation',
            'description' => 'Sign-off before a corrected punch is accepted.',
            'subject' => 'Regularisation request',
            'suggested_steps' => [
                ['name' => 'Reporting manager', 'approver_type' => 'reporting_manager', 'sla_hours' => 24, 'on_breach' => 'remind'],
            ],
        ],
        'talent.recruitment.requisition' => [
            'label' => 'Job requisition',
            'description' => 'Sign-off before a role is opened for hiring.',
            'subject' => 'Job requisition',
            'suggested_steps' => [
                ['name' => 'Department head', 'approver_type' => 'role', 'approver' => 'department_head', 'sla_hours' => 48, 'on_breach' => 'remind'],
                ['name' => 'HR', 'approver_type' => 'role', 'approver' => 'hr_manager', 'sla_hours' => 48, 'on_breach' => 'escalate'],
            ],
        ],
        'talent.recruitment.offer' => [
            'label' => 'Offer approval',
            'description' => 'Sign-off before an offer is sent to a candidate.',
            'subject' => 'Offer',
            'suggested_steps' => [
                ['name' => 'HR', 'approver_type' => 'role', 'approver' => 'hr_manager', 'sla_hours' => 24, 'on_breach' => 'remind'],
            ],
        ],
        'talent.offboarding.clearance' => [
            'label' => 'Exit clearance',
            'description' => 'Sign-off before an exit case is closed.',
            'subject' => 'Exit case',
            'suggested_steps' => [
                ['name' => 'Reporting manager', 'approver_type' => 'reporting_manager', 'sla_hours' => 72, 'on_breach' => 'remind'],
                ['name' => 'HR', 'approver_type' => 'role', 'approver' => 'hr_manager', 'sla_hours' => 72, 'on_breach' => 'escalate'],
            ],
        ],
        'talent.mobility.transfer' => [
            'label' => 'Internal transfer',
            'description' => 'Sign-off before an employee moves team.',
            'subject' => 'Transfer request',
            'suggested_steps' => [
                ['name' => 'Current manager', 'approver_type' => 'reporting_manager', 'sla_hours' => 48, 'on_breach' => 'remind'],
                ['name' => 'HR', 'approver_type' => 'role', 'approver' => 'hr_manager', 'sla_hours' => 48, 'on_breach' => 'none'],
            ],
        ],
        'competency.assessment.review' => [
            'label' => 'Capability mapping review',
            'description' => 'Sign-off before a capability mapping change takes effect.',
            'subject' => 'Mapping change',
            'suggested_steps' => [
                ['name' => 'Department head', 'approver_type' => 'role', 'approver' => 'department_head', 'sla_hours' => 72, 'on_breach' => 'remind'],
            ],
        ],
        'task.execution.approval' => [
            'label' => 'Task execution approval',
            'description' => 'Sign-off before submitted task evidence is accepted.',
            'subject' => 'Task submission',
            'suggested_steps' => [
                ['name' => 'Reporting manager', 'approver_type' => 'reporting_manager', 'sla_hours' => 48, 'on_breach' => 'remind'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approver types
    |--------------------------------------------------------------------------
    |
    | `needs_value` is the distinction that matters: `role` and `user` are named by the
    | person configuring the chain, so they carry a value. The rest are DERIVED from the
    | record being approved at the moment it is approved — the requester's own reporting
    | manager, not a manager chosen now — so a value on them would be ignored, and the
    | validator blanks it rather than storing something the engine will never read.
    |
    */
    'approver_types' => [
        'reporting_manager' => [
            'label' => "The requester's reporting manager",
            'description' => 'Resolved from the record when the approval is raised.',
            'needs_value' => false,
        ],
        'department_head' => [
            'label' => "The requester's department head",
            'description' => 'Resolved from the record when the approval is raised.',
            'needs_value' => false,
        ],
        'role' => [
            'label' => 'Anybody with a role',
            'description' => 'Names a role key, such as hr_manager.',
            'needs_value' => true,
        ],
        'user' => [
            'label' => 'A named person',
            'description' => 'Names one user id. Consider a role instead — a named person leaves when they leave.',
            'needs_value' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation actions
    |--------------------------------------------------------------------------
    |
    | What happens when a step's SLA passes. `auto_approve` is offered because some
    | approvals are genuinely a formality and a stalled one blocks a person's leave —
    | but it is the one entry here that can approve something nobody looked at, so the
    | screen should say as much rather than list it as a neutral option.
    |
    */
    'escalation_actions' => [
        'none' => ['label' => 'Nothing', 'description' => 'The step simply waits.'],
        'remind' => ['label' => 'Remind the approver', 'description' => 'Notify them again.'],
        'escalate' => ['label' => 'Escalate', 'description' => 'Notify the next step up.'],
        'auto_approve' => ['label' => 'Approve automatically', 'description' => 'Approves without anybody reading it.'],
        'auto_reject' => ['label' => 'Reject automatically', 'description' => 'Rejects without anybody reading it.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled tasks
    |--------------------------------------------------------------------------
    |
    | EVERY KEY IS BOUND TO A REAL ARTISAN COMMAND. LMS K-12's equivalent list
    | carries a label, a description and a cron expression and NO command, handler
    | or job of any kind — so even if somebody wrote the dispatcher its own
    | migration promises, there would be nothing for it to dispatch. A task key
    | that names no executable is a row, not a task.
    |
    | `tenant_scoped` IS THE FIELD THAT KEEPS THIS SCREEN HONEST.
    |
    | Only two of these six commands accept a tenant. `events:project` drains
    | EVERY organisation's events in one pass; there is no way to run it on a
    | different schedule for one of them. Offering a per-tenant override on a task
    | that cannot honour one would be a control that silently does nothing — which
    | is the whole class of defect this work exists to remove.
    |
    | So a tenant may override the two that are genuinely per-tenant, and the
    | screen shows the other four as installation-wide with the reason.
    |
    | `command` is the signature's name, which is also the key the run ledger
    | (`g2g_platform_task_runs`) records, so the three agree without translation.
    |
    */
    'tasks' => [
        'leave.escalate' => [
            'label' => 'Escalate overdue leave approvals',
            'description' => 'Widens who may decide a leave step that has waited too long, and '
                . 'applies any per-step SLA rule configured on the workflow console.',
            'command' => 'leave:escalate',
            'module' => 'hrms',
            'component' => 'hrms.leave',
            // Takes --tenant. Escalation thresholds are already per-tenant, so a
            // per-tenant schedule is meaningful here.
            'tenant_scoped' => true,
        ],
        'readiness.recompute' => [
            'label' => 'Recompute readiness gates',
            'description' => 'Advances the sustained-period counters behind each readiness gate.',
            'command' => 'readiness:recompute',
            'module' => 'organization',
            'component' => 'organization.departments',
            // Takes --tenant.
            'tenant_scoped' => true,
        ],
        'events.project' => [
            'label' => 'Drain the event store into its projections',
            'description' => 'Delivers recorded events to the audit log, capability evidence and '
                . 'task status history.',
            'command' => 'events:project',
            'module' => 'events',
            'component' => 'events.store',
            // NO --tenant option: one pass covers the whole installation, so it
            // cannot run on a different schedule for one organisation.
            'tenant_scoped' => false,
            'estate_reason' => 'One pass drains every organisation together.',
        ],
        'events.react' => [
            'label' => 'Run event reactors',
            'description' => 'Issues certificates, assigns learning and sends notifications in '
                . 'response to recorded events.',
            'command' => 'events:react',
            'module' => 'events',
            'component' => 'events.store',
            'tenant_scoped' => false,
            'estate_reason' => 'One pass covers every organisation together.',
        ],
        'certifications.scan_expiry' => [
            'label' => 'Scan for expiring certifications',
            'description' => 'Emits certification.expiring as a renewal window is crossed.',
            'command' => 'certifications:scan-expiry',
            'module' => 'competency',
            'component' => 'competency.certification',
            'tenant_scoped' => false,
            'estate_reason' => 'Sweeps a whole database connection rather than one organisation.',
        ],
        'sync.data' => [
            'label' => 'Nightly data sync',
            'description' => 'The pre-existing overnight synchronisation job.',

            /*
             * ═══════════════════════════════════════════════════════════════
             * THIS NAMES WHAT THE SCHEDULER INVOKES, WHICH DOES NOT EXIST
             * ═══════════════════════════════════════════════════════════════
             *
             * `routes/console.php` schedules `sync:data`. The command's actual
             * signature is `app:sync-data-cron` (see SyncDataCron), and
             * `php artisan list` knows only that one. So the nightly 18:00 entry
             * has been running `php artisan sync:data` — a command that is not
             * defined — and failing, silently, every night.
             *
             * It is the same class of fault as the one `routes/console.php`
             * documents about `app/Console/Kernel.php`: a schedule entry that
             * reads as configuration and does nothing.
             *
             * The key here deliberately matches what is SCHEDULED rather than
             * what exists, so the Scheduler console lists the entry that actually
             * runs. With the run ledger in place it will now record `failed` every
             * night, which is how somebody finds out — the fix is a one-word
             * change to routes/console.php and is left for whoever owns that job,
             * because making a long-broken nightly sync start working is a
             * behaviour change, not a typo fix.
             */
            'command' => 'sync:data',
            'module' => 'events',
            'component' => 'events.store',
            'tenant_scoped' => false,
            'estate_reason' => 'Installation-wide job with no organisation parameter.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fields Configuration — the tables a custom field may be added to
    |--------------------------------------------------------------------------
    |
    | AN ALLOWLIST, AND THE REASON IS A DEFECT IN THE PRODUCT THIS WAS PORTED FROM.
    |
    | LMS K-12's `CustomFieldApiController::ensureColumnExists()` runs
    | `ALTER TABLE <table_name> ADD COLUMN ...` where `table_name` is validated only as
    | `required|string|max:50`. There is no allowlist, so any authorised caller can add a
    | column to ANY table in the schema — including the ones holding credentials and
    | rights — and the column is global while the field row is tenant-scoped.
    |
    | This is that list. A table not named here cannot receive a custom field, whatever
    | the request says.
    |
    */
    'custom_field_tables' => [
        'tbluser' => 'Employee record',
    ],

];
