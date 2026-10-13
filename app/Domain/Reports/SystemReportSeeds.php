<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * A few real, ready-to-open starter reports per system folder — so a
 * freshly-registered module isn't just empty folders waiting for someone
 * to build something from scratch (the exact complaint this closes: "Task
 * Reports"/"Events Report"/"Assigned Tasks Report" had folders but nothing
 * in them).
 *
 * Same lazy, per-tenant creation shape as SystemReportFolders::ensure() and
 * for the same reason: a migration backfill only reaches tenants that
 * existed when it ran. Idempotent by (sub_institute_id, module_scope,
 * title) — re-running never duplicates.
 */
final class SystemReportSeeds
{
    public static function ensure(int $subInstituteId, int $userId, string $scope): void
    {
        $seeds = self::seedsFor($scope);

        if ($seeds === []) {
            return;
        }

        $folderIds = DB::table('report_folders')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->where('is_system', true)
            ->pluck('id', 'name');

        $existingTitles = DB::table('reports')
            ->where('sub_institute_id', $subInstituteId)
            ->where('module_scope', $scope)
            ->whereNull('deleted_at')
            ->pluck('title')
            ->all();

        $now = now();
        $rows = [];

        foreach ($seeds as $seed) {
            if (in_array($seed['title'], $existingTitles, true) || !isset($folderIds[$seed['folder']])) {
                continue;
            }

            $rows[] = [
                'sub_institute_id' => $subInstituteId,
                'module_scope' => $scope,
                'folder_id' => $folderIds[$seed['folder']],
                'report_type' => $seed['report_type'],
                'primary_module' => $seed['primary_module'],
                'title' => $seed['title'],
                'description' => $seed['description'] ?? null,
                'owner_id' => $userId,
                'sharingtype' => 'Public',
                'definition' => json_encode($seed['definition']),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('reports')->insert($rows);
        }
    }

    /** @return array<int, array{folder:string,report_type:string,primary_module:string,title:string,description?:string,definition:array}> */
    private static function seedsFor(string $scope): array
    {
        return match ($scope) {
            'task_management' => self::taskManagementSeeds(),
            'crm' => self::crmSeeds(),
            default => [],
        };
    }

    private static function taskManagementSeeds(): array
    {
        $col = fn (string $field, string $label, ?string $aggregate = null) => [
            'module' => 'task_management.tasks', 'field' => $field, 'label' => $label, 'visible' => true, 'aggregate' => $aggregate,
        ];

        return [
            [
                'folder' => 'Task Reports',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.tasks',
                'title' => 'All Open Tasks',
                'description' => 'Every task not yet completed, newest first.',
                'definition' => [
                    'columns' => [
                        $col('task_title', 'Task'),
                        $col('status', 'Status'),
                        $col('task_type', 'Priority'),
                        $col('task_date', 'Due Date'),
                        $col('task_allocated_to', 'Assignee'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'task_management.tasks', 'field' => 'status', 'operator' => 'not_equals', 'value' => 'COMPLETED'],
                        ],
                    ],
                    'sort_columns' => [['module' => 'task_management.tasks', 'field' => 'task_date', 'direction' => 'asc']],
                ],
            ],
            [
                'folder' => 'Task Reports',
                'report_type' => 'summary',
                'primary_module' => 'task_management.tasks',
                'title' => 'Tasks by Status',
                'description' => 'How many tasks are in each status right now.',
                'definition' => [
                    'group_by' => [['module' => 'task_management.tasks', 'field' => 'status']],
                ],
            ],
            [
                'folder' => 'Events Report',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.events',
                'title' => "Today's Events",
                'description' => "Everything scheduled for today — the same shape as the legacy CRM's own \"Today calendar\" report.",
                'definition' => [
                    'columns' => [
                        ['module' => 'task_management.events', 'field' => 'title', 'label' => 'Event', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'status', 'label' => 'Status', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'owner_id', 'label' => 'Owner', 'visible' => true],
                        ['module' => 'task_management.events', 'field' => 'start_at', 'label' => 'Start', 'visible' => true],
                    ],
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'today'],
                    'sort_columns' => [['module' => 'task_management.events', 'field' => 'start_at', 'direction' => 'asc']],
                ],
            ],
            [
                'folder' => 'Events Report',
                'report_type' => 'summary',
                'primary_module' => 'task_management.events',
                'title' => "This Month's Events by Status",
                'definition' => [
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'this_month'],
                    'group_by' => [['module' => 'task_management.events', 'field' => 'status']],
                ],
            ],
            [
                'folder' => 'Assigned Tasks Report',
                'report_type' => 'summary',
                'primary_module' => 'task_management.tasks',
                'title' => 'Tasks by Assignee',
                'description' => 'Who has how many tasks, right now.',
                'definition' => [
                    'group_by' => [['module' => 'task_management.tasks', 'field' => 'task_allocated_to']],
                ],
            ],
            [
                'folder' => 'Assigned Tasks Report',
                'report_type' => 'chart',
                'primary_module' => 'task_management.tasks',
                'title' => 'Workload by Assignee',
                'definition' => [
                    'chart' => [
                        'type' => 'HorizontalBar',
                        'group_by' => ['module' => 'task_management.tasks', 'field' => 'task_allocated_to'],
                        'data_fields' => [['module' => 'task_management.tasks', 'field' => 'task_title', 'aggregate' => 'count', 'label' => 'Tasks']],
                    ],
                ],
            ],
        ];
    }

    /**
     * CRM's canned reports, recovered from the real legacy CRM's own default
     * set (`PopulateReports.php`/`languages/en_us/Reports.php` — see
     * Docs/reports-engine/PLAN.md's own reference table) and reproduced
     * against this app's REAL registered modules/joins (CrmReportModules),
     * not the legacy schema. Two intentional substitutions where the
     * legacy name has no real equivalent data path here, both noted inline
     * rather than silently dropped or faked:
     *   - "Products by Contacts" → "Products by Category" (no products↔
     *     contacts relation exists outside the excluded line-item tables).
     *   - "Vendors Email Report" dropped entirely — Vendors is not one of
     *     the 5 entities crm_email_links can ever point at
     *     (HasCrmEmailLinks: LEAD/CONTACT/ORGANIZATION/OPPORTUNITY/TICKET
     *     only), so it would always return zero rows.
     */
    private static function crmSeeds(): array
    {
        $col = fn (string $module, string $field, string $label, ?string $aggregate = null) => [
            'module' => $module, 'field' => $field, 'label' => $label, 'visible' => true, 'aggregate' => $aggregate,
        ];
        $ref = fn (string $module, string $field) => ['module' => $module, 'field' => $field];

        return [
            // ── Organization and Contact Reports ──────────────────────
            [
                'folder' => 'Organization and Contact Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.contacts',
                'title' => 'Contacts by Organizations',
                'description' => 'How many contacts belong to each organization.',
                'definition' => [
                    'secondary_modules' => ['crm.organizations'],
                    'group_by' => [$ref('crm.organizations', 'name')],
                ],
            ],
            [
                'folder' => 'Organization and Contact Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.contacts',
                'title' => 'Contacts without Organizations',
                'description' => 'Contacts with no organization linked yet.',
                'definition' => [
                    'columns' => [
                        $col('crm.contacts', 'full_name', 'Contact'),
                        $col('crm.contacts', 'email', 'Email'),
                        $col('crm.contacts', 'phone', 'Phone'),
                        $col('crm.contacts', 'assigned_to', 'Assigned To'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.contacts', 'field' => 'organization_id', 'operator' => 'is_null'],
                        ],
                    ],
                ],
            ],
            [
                'folder' => 'Organization and Contact Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.contacts',
                'title' => 'Contacts by Opportunities',
                'description' => 'How many contacts are tied to each opportunity.',
                'definition' => [
                    'secondary_modules' => ['crm.opportunities'],
                    'group_by' => [$ref('crm.opportunities', 'name')],
                ],
            ],

            // ── Lead Reports ───────────────────────────────────────────
            [
                'folder' => 'Lead Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.leads',
                'title' => 'Lead by Source',
                'definition' => ['group_by' => [$ref('crm.leads', 'lead_source')]],
            ],
            [
                'folder' => 'Lead Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.leads',
                'title' => 'Lead Status Report',
                'definition' => ['group_by' => [$ref('crm.leads', 'lead_status')]],
            ],

            // ── Opportunity Reports ────────────────────────────────────
            [
                'folder' => 'Opportunity Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.opportunities',
                'title' => 'Opportunity Pipeline',
                'description' => 'Total open opportunity value by sales stage.',
                'definition' => [
                    'group_by' => [$ref('crm.opportunities', 'sales_stage')],
                    'columns' => [$col('crm.opportunities', 'amount', 'Amount', 'sum')],
                ],
            ],
            [
                'folder' => 'Opportunity Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.opportunities',
                'title' => 'Closed Opportunities',
                'definition' => [
                    'columns' => [
                        $col('crm.opportunities', 'name', 'Opportunity'),
                        $col('crm.opportunities', 'amount', 'Amount'),
                        $col('crm.opportunities', 'sales_stage', 'Stage'),
                        $col('crm.opportunities', 'closing_date', 'Closing Date'),
                        $col('crm.opportunities', 'assigned_to', 'Assigned To'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.opportunities', 'field' => 'sales_stage', 'operator' => 'in', 'value' => ['Closed Won', 'Closed Lost']],
                        ],
                    ],
                    'sort_columns' => [['module' => 'crm.opportunities', 'field' => 'closing_date', 'direction' => 'desc']],
                ],
            ],

            // ── Activity Reports — reuses task_management.events, NOT a
            // CRM module (Docs/reports-engine/PLAN.md, Phase 2 correction) ─
            [
                'folder' => 'Activity Reports',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.events',
                'title' => "This Month's CRM Activities",
                'description' => 'Activities scheduled this month against any Lead/Contact/Organization/Opportunity/Ticket.',
                'definition' => [
                    'columns' => [
                        $col('task_management.events', 'title', 'Activity'),
                        $col('task_management.events', 'status', 'Status'),
                        $col('task_management.events', 'linked_type', 'Linked To'),
                        $col('task_management.events', 'owner_id', 'Owner'),
                        $col('task_management.events', 'start_at', 'Start'),
                    ],
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'this_month'],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'task_management.events', 'field' => 'linked_type', 'operator' => 'in', 'value' => ['LEAD', 'CONTACT', 'ORGANIZATION', 'OPPORTUNITY', 'TICKET']],
                        ],
                    ],
                    'sort_columns' => [['module' => 'task_management.events', 'field' => 'start_at', 'direction' => 'asc']],
                ],
            ],
            [
                'folder' => 'Activity Reports',
                'report_type' => 'tabular',
                'primary_module' => 'task_management.events',
                'title' => "Last Month's CRM Activities",
                'definition' => [
                    'columns' => [
                        $col('task_management.events', 'title', 'Activity'),
                        $col('task_management.events', 'status', 'Status'),
                        $col('task_management.events', 'linked_type', 'Linked To'),
                        $col('task_management.events', 'owner_id', 'Owner'),
                        $col('task_management.events', 'start_at', 'Start'),
                    ],
                    'standard_filter' => ['module' => 'task_management.events', 'field' => 'start_at', 'preset' => 'last_month'],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'task_management.events', 'field' => 'linked_type', 'operator' => 'in', 'value' => ['LEAD', 'CONTACT', 'ORGANIZATION', 'OPPORTUNITY', 'TICKET']],
                        ],
                    ],
                    'sort_columns' => [['module' => 'task_management.events', 'field' => 'start_at', 'direction' => 'asc']],
                ],
            ],

            // ── Campaign Reports ───────────────────────────────────────
            [
                'folder' => 'Campaign Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.campaigns',
                'title' => 'Campaign Expectations and Actuals',
                'definition' => [
                    'columns' => [
                        $col('crm.campaigns', 'name', 'Campaign'),
                        $col('crm.campaigns', 'campaign_type', 'Type'),
                        $col('crm.campaigns', 'campaign_status', 'Status'),
                        $col('crm.campaigns', 'budget_cost', 'Budget Cost'),
                        $col('crm.campaigns', 'actual_cost', 'Actual Cost'),
                        $col('crm.campaigns', 'expected_revenue', 'Expected Revenue'),
                        $col('crm.campaigns', 'closing_date', 'Closing Date'),
                    ],
                ],
            ],

            // ── Quote Reports ──────────────────────────────────────────
            [
                'folder' => 'Quote Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.quotes',
                'title' => 'Open Quotes',
                'definition' => [
                    'columns' => [
                        $col('crm.quotes', 'quote_no', 'Quote No'),
                        $col('crm.quotes', 'subject', 'Subject'),
                        $col('crm.quotes', 'quote_stage', 'Stage'),
                        $col('crm.quotes', 'valid_till', 'Valid Till'),
                        $col('crm.quotes', 'total', 'Total'),
                        $col('crm.quotes', 'assigned_to', 'Assigned To'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.quotes', 'field' => 'quote_stage', 'operator' => 'in', 'value' => ['Draft', 'Sent', 'Revised']],
                        ],
                    ],
                ],
            ],
            [
                'folder' => 'Quote Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.quotes',
                'title' => 'Quotes Detailed Report',
                'definition' => [
                    'secondary_modules' => ['crm.organizations', 'crm.contacts'],
                    'columns' => [
                        $col('crm.quotes', 'quote_no', 'Quote No'),
                        $col('crm.quotes', 'subject', 'Subject'),
                        $col('crm.quotes', 'quote_stage', 'Stage'),
                        $col('crm.quotes', 'valid_till', 'Valid Till'),
                        $col('crm.quotes', 'total', 'Total'),
                        $col('crm.organizations', 'name', 'Organization'),
                        $col('crm.contacts', 'full_name', 'Contact'),
                        $col('crm.quotes', 'assigned_to', 'Assigned To'),
                    ],
                ],
            ],

            // ── Sales Order Reports ────────────────────────────────────
            [
                'folder' => 'Sales Order Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.sales_orders',
                'title' => 'SalesOrder Detailed Report',
                'definition' => [
                    'secondary_modules' => ['crm.organizations'],
                    'columns' => [
                        $col('crm.sales_orders', 'so_no', 'Sales Order No'),
                        $col('crm.sales_orders', 'subject', 'Subject'),
                        $col('crm.sales_orders', 'so_stage', 'Stage'),
                        $col('crm.sales_orders', 'due_date', 'Due Date'),
                        $col('crm.sales_orders', 'total', 'Total'),
                        $col('crm.organizations', 'name', 'Organization'),
                        $col('crm.sales_orders', 'assigned_to', 'Assigned To'),
                    ],
                ],
            ],

            // ── Purchase Order Reports ─────────────────────────────────
            [
                'folder' => 'Purchase Order Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.purchase_orders',
                'title' => 'PurchaseOrder by Contacts',
                'definition' => [
                    'secondary_modules' => ['crm.contacts'],
                    'group_by' => [$ref('crm.contacts', 'full_name')],
                ],
            ],
            [
                'folder' => 'Purchase Order Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.purchase_orders',
                'title' => 'PurchaseOrder Detailed Report',
                'definition' => [
                    'secondary_modules' => ['crm.vendors'],
                    'columns' => [
                        $col('crm.purchase_orders', 'po_no', 'Purchase Order No'),
                        $col('crm.purchase_orders', 'subject', 'Subject'),
                        $col('crm.purchase_orders', 'po_stage', 'Stage'),
                        $col('crm.purchase_orders', 'due_date', 'Due Date'),
                        $col('crm.purchase_orders', 'total', 'Total'),
                        $col('crm.vendors', 'name', 'Vendor'),
                        $col('crm.purchase_orders', 'assigned_to', 'Assigned To'),
                    ],
                ],
            ],

            // ── Invoice Reports ────────────────────────────────────────
            [
                'folder' => 'Invoice Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.invoices',
                'title' => 'Invoice Detailed Report',
                'definition' => [
                    'secondary_modules' => ['crm.organizations'],
                    'columns' => [
                        $col('crm.invoices', 'invoice_no', 'Invoice No'),
                        $col('crm.invoices', 'subject', 'Subject'),
                        $col('crm.invoices', 'invoice_stage', 'Stage'),
                        $col('crm.invoices', 'invoice_date', 'Invoice Date'),
                        $col('crm.invoices', 'due_date', 'Due Date'),
                        $col('crm.invoices', 'total', 'Total'),
                        $col('crm.organizations', 'name', 'Organization'),
                        $col('crm.invoices', 'assigned_to', 'Assigned To'),
                    ],
                ],
            ],

            // ── Tickets Reports ────────────────────────────────────────
            [
                'folder' => 'Tickets Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.tickets',
                'title' => 'Tickets by Products',
                'definition' => [
                    'secondary_modules' => ['crm.products'],
                    'group_by' => [$ref('crm.products', 'name')],
                ],
            ],
            [
                'folder' => 'Tickets Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.tickets',
                'title' => 'Tickets by Priority',
                'definition' => ['group_by' => [$ref('crm.tickets', 'priority')]],
            ],
            [
                'folder' => 'Tickets Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.tickets',
                'title' => 'Open Tickets',
                'definition' => [
                    'columns' => [
                        $col('crm.tickets', 'ticket_no', 'Ticket No'),
                        $col('crm.tickets', 'title', 'Title'),
                        $col('crm.tickets', 'status', 'Status'),
                        $col('crm.tickets', 'priority', 'Priority'),
                        $col('crm.tickets', 'assigned_to', 'Assigned To'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.tickets', 'field' => 'status', 'operator' => 'not_equals', 'value' => 'Closed'],
                        ],
                    ],
                ],
            ],

            // ── Product Reports ────────────────────────────────────────
            [
                'folder' => 'Product Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.products',
                'title' => 'Product Details',
                'definition' => [
                    'columns' => [
                        $col('crm.products', 'product_no', 'Product No'),
                        $col('crm.products', 'name', 'Product'),
                        $col('crm.products', 'sku', 'SKU'),
                        $col('crm.products', 'category', 'Category'),
                        $col('crm.products', 'unit_price', 'Unit Price'),
                        $col('crm.products', 'qty_in_stock', 'Qty In Stock'),
                        $col('crm.products', 'is_active', 'Active'),
                    ],
                ],
            ],
            // "Products by Contacts" (legacy name) has no real data path here —
            // no products<->contacts relation exists outside the excluded
            // line-item tables — substituted with a real, useful grouping.
            [
                'folder' => 'Product Reports',
                'report_type' => 'summary',
                'primary_module' => 'crm.products',
                'title' => 'Products by Category',
                'definition' => ['group_by' => [$ref('crm.products', 'category')]],
            ],

            // ── Email Reports ──────────────────────────────────────────
            [
                'folder' => 'Email Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.email_links',
                'title' => 'Contacts Email Report',
                'definition' => [
                    'secondary_modules' => ['crm.emails'],
                    'columns' => [
                        $col('crm.emails', 'subject', 'Subject'),
                        $col('crm.emails', 'status', 'Status'),
                        $col('crm.emails', 'to_address', 'To'),
                        $col('crm.emails', 'sent_at', 'Sent At'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.email_links', 'field' => 'linked_type', 'operator' => 'equals', 'value' => 'CONTACT'],
                        ],
                    ],
                ],
            ],
            [
                'folder' => 'Email Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.email_links',
                'title' => 'Organizations Email Report',
                'definition' => [
                    'secondary_modules' => ['crm.emails'],
                    'columns' => [
                        $col('crm.emails', 'subject', 'Subject'),
                        $col('crm.emails', 'status', 'Status'),
                        $col('crm.emails', 'to_address', 'To'),
                        $col('crm.emails', 'sent_at', 'Sent At'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.email_links', 'field' => 'linked_type', 'operator' => 'equals', 'value' => 'ORGANIZATION'],
                        ],
                    ],
                ],
            ],
            [
                'folder' => 'Email Reports',
                'report_type' => 'tabular',
                'primary_module' => 'crm.email_links',
                'title' => 'Leads Email Report',
                'definition' => [
                    'secondary_modules' => ['crm.emails'],
                    'columns' => [
                        $col('crm.emails', 'subject', 'Subject'),
                        $col('crm.emails', 'status', 'Status'),
                        $col('crm.emails', 'to_address', 'To'),
                        $col('crm.emails', 'sent_at', 'Sent At'),
                    ],
                    'advanced_filter' => [
                        'op' => 'AND',
                        'conditions' => [
                            ['module' => 'crm.email_links', 'field' => 'linked_type', 'operator' => 'equals', 'value' => 'LEAD'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
