<?php

namespace App\Domain\Reports\TaskManagement;

use App\Domain\Reports\ReportFieldDefinition;
use App\Domain\Reports\ReportJoinDefinition;
use App\Domain\Reports\ReportModuleDefinition;

/**
 * Task Management's reportable modules — the proving ground for the
 * generic report engine (Docs/reports-engine/PLAN.md, Phase 1), registered
 * here and nowhere else; ReportModuleRegistry only merges this array in.
 *
 * Schema verified directly against the real migrations before writing
 * this (not assumed from an earlier research pass):
 * `database/migrations/2025_06_21_050744_create_task_table.php` +
 * `2026_08_04_130000_add_planning_columns_to_task_table.php` (tasks),
 * `2026_10_10_090000_create_task_management_calendar_events_table.php`
 * (events — its own table, deliberately not a type column on `task`),
 * `2026_07_28_000000_create_task_management_project_tables.php` (projects
 * + the `task_management_project_tasks` pivot). `task_management_projects`
 * has NO soft-delete column (`archived_at` is a different, domain-specific
 * concept, not a generic delete) — confirmed, not assumed.
 */
final class TaskManagementReportModules
{
    /** @return ReportModuleDefinition[] */
    public static function definitions(): array
    {
        return [
            self::tasks(),
            self::events(),
            self::projects(),
        ];
    }

    private static function tasks(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'task_management.tasks',
            label: 'Tasks',
            table: 'task',
            alias: 't',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('task_title', 'Task', 't.task_title', groupable: false),
                ReportFieldDefinition::date('task_date', 'Due Date', 't.task_date'),
                ReportFieldDefinition::date('planned_start_date', 'Planned Start', 't.planned_start_date'),
                ReportFieldDefinition::varchar('status', 'Status', 't.status'),
                ReportFieldDefinition::varchar('task_type', 'Priority', 't.task_type'),
                ReportFieldDefinition::varchar('kra', 'KRA', 't.kra'),
                ReportFieldDefinition::varchar('kpa', 'KPA', 't.kpa'),
                ReportFieldDefinition::numeric('estimated_hours', 'Estimated Hours', 't.estimated_hours'),
                ReportFieldDefinition::numeric('actual_hours', 'Actual Hours', 't.actual_hours'),
                ReportFieldDefinition::numeric('remaining_hours', 'Remaining Hours', 't.remaining_hours'),
                ReportFieldDefinition::varchar('delay_category', 'Delay Category', 't.delay_category'),
                ReportFieldDefinition::userRef('task_allocated_to', 'Assignee', "TRIM(CONCAT_WS(' ', u_assignee.first_name, u_assignee.middle_name, u_assignee.last_name))"),
                ReportFieldDefinition::userRef('task_allocated', 'Assigned By', "TRIM(CONCAT_WS(' ', u_allocator.first_name, u_allocator.middle_name, u_allocator.last_name))"),
            ],
            joins: [
                'task_management.projects' => ReportJoinDefinition::pivot(
                    'task_management.projects',
                    'task_management_project_tasks',
                    'task_id',
                    'project_id',
                ),
            ],
            alwaysJoins: [
                ['table' => 'tbluser', 'alias' => 'u_assignee', 'localColumn' => 'task_allocated_to', 'foreignColumn' => 'id'],
                ['table' => 'tbluser', 'alias' => 'u_allocator', 'localColumn' => 'task_allocated', 'foreignColumn' => 'id'],
            ],
            colorByUserColumn: 't.task_allocated_to',
        );
    }

    private static function events(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'task_management.events',
            label: 'Events',
            table: 'task_management_calendar_events',
            alias: 'e',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('title', 'Event Title', 'e.title', groupable: false),
                ReportFieldDefinition::date('start_at', 'Start', 'e.start_at'),
                ReportFieldDefinition::date('end_at', 'End', 'e.end_at'),
                ReportFieldDefinition::varchar('location', 'Location', 'e.location'),
                ReportFieldDefinition::varchar('status', 'Status', 'e.status'), // Planned | Held | Cancelled
                ReportFieldDefinition::userRef('owner_id', 'Owner', "TRIM(CONCAT_WS(' ', u_owner.first_name, u_owner.middle_name, u_owner.last_name))"),
                /*
                 * What this event is attached to (LEAD|CONTACT|ORGANIZATION|
                 * OPPORTUNITY|TICKET|PROJECT|WORKSTREAM|TASK|BACKLOG_ITEM|
                 * null) - this is CRM's real "Activity Reports" data
                 * source, NOT a separate crm_activities table. CRM already
                 * has a working "Activity" tab (CrmScheduleTab) that reads
                 * and writes events here filtered by linked_type/linked_id
                 * (CalendarController::linkedTargetExists()), built
                 * specifically to reuse this table instead of a parallel
                 * CRM-only calendar. A prior pass of this same engine
                 * (since reverted) built crm_activities as a new table
                 * before discovering this — Phase 3's CRM "Activity
                 * Reports" folder filters this same module to the 5 CRM
                 * linked_type values via an advanced_filter, nothing more.
                 */
                ReportFieldDefinition::varchar('linked_type', 'Linked To', 'e.linked_type'),
                ReportFieldDefinition::varchar('linked_id', 'Linked Record Id', 'e.linked_id', groupable: false),
            ],
            alwaysJoins: [
                ['table' => 'tbluser', 'alias' => 'u_owner', 'localColumn' => 'owner_id', 'foreignColumn' => 'id'],
            ],
            colorByUserColumn: 'e.owner_id',
        );
    }

    private static function projects(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'task_management.projects',
            label: 'Projects',
            table: 'task_management_projects',
            alias: 'p',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null, // confirmed: no deleted_at — archived_at is a different concept, not a generic delete
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('code', 'Code', 'p.code', groupable: false),
                ReportFieldDefinition::varchar('name', 'Project', 'p.name', groupable: false),
                ReportFieldDefinition::varchar('category', 'Category', 'p.category'),
                ReportFieldDefinition::varchar('priority', 'Priority', 'p.priority'),
                ReportFieldDefinition::varchar('status', 'Status', 'p.status'),
                ReportFieldDefinition::date('start_date', 'Start Date', 'p.start_date'),
                ReportFieldDefinition::date('due_date', 'Due Date', 'p.due_date'),
                ReportFieldDefinition::numeric('budget_estimate', 'Budget Estimate', 'p.budget_estimate'),
                ReportFieldDefinition::userRef('manager_id', 'Manager', "TRIM(CONCAT_WS(' ', u_manager.first_name, u_manager.middle_name, u_manager.last_name))"),
            ],
            alwaysJoins: [
                ['table' => 'tbluser', 'alias' => 'u_manager', 'localColumn' => 'manager_id', 'foreignColumn' => 'id'],
            ],
        );
    }
}
