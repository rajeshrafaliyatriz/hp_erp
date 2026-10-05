<?php

/*
|--------------------------------------------------------------------------
| Department Process Builder
|--------------------------------------------------------------------------
|
| Step types, categories and starter templates for the Process tab's
| builder. All three are editable fixtures, not tenant data - the same
| palette and starting points for every tenant - so they live here rather
| than in a table, the same choice config/documents.php made for document
| types. A department is never limited to this list: `category` on
| department_processes is an open string column (see the migration), and
| the builder always offers a blank/custom process alongside these
| templates.
|
| Adding a step type: add it to `step_types` (controls what the canvas
| palette offers and how a node is colored) and, if application logic needs
| to branch on it (e.g. "does this step raise a task"), teach
| DepartmentProcessController/DepartmentProcessRunController about the new
| key. Adding a category or template never needs a migration - just a new
| entry below.
*/

return [

    'step_types' => [
        'start'             => ['label' => 'Start',             'color' => '#64748b'],
        'end'               => ['label' => 'End',                'color' => '#64748b'],
        'task'              => ['label' => 'Task',                'color' => '#2563eb'],
        'approval'          => ['label' => 'Approval',            'color' => '#d97706'],
        'decision'          => ['label' => 'Decision',            'color' => '#9333ea'],
        'milestone'         => ['label' => 'Milestone',           'color' => '#16a34a'],
        'wait_delay'        => ['label' => 'Wait / Delay',        'color' => '#64748b'],
        'notification'      => ['label' => 'Notification',       'color' => '#0891b2'],
        'sop_reference'     => ['label' => 'SOP Reference',      'color' => '#0d9488'],
        'policy_reference'  => ['label' => 'Policy Reference',   'color' => '#0d9488'],
        'rule_reference'    => ['label' => 'Rule Reference',     'color' => '#0d9488'],
        'sub_process'       => ['label' => 'Sub-Process',        'color' => '#475569'],
    ],

    // Grouped for the template picker's UI only. `key` is what is actually
    // stored in department_processes.category.
    'categories' => [
        ['key' => 'recruitment',           'label' => 'Recruitment & Hiring',                     'group' => 'People & HR'],
        ['key' => 'onboarding',            'label' => 'Onboarding',                               'group' => 'People & HR'],
        ['key' => 'offboarding',           'label' => 'Offboarding',                              'group' => 'People & HR'],
        ['key' => 'performance_review',    'label' => 'Performance Review & Appraisal',           'group' => 'People & HR'],
        ['key' => 'task_evaluation',       'label' => 'Task Evaluation & QA Review',              'group' => 'People & HR'],
        ['key' => 'learning_development',  'label' => 'Learning & Development',                   'group' => 'People & HR'],
        ['key' => 'assessment',            'label' => 'Assessment & Certification',               'group' => 'People & HR'],
        ['key' => 'promotion_transfer',    'label' => 'Promotion & Transfer',                     'group' => 'People & HR'],
        ['key' => 'grievance_handling',    'label' => 'Disciplinary & Grievance Handling',        'group' => 'People & HR'],
        ['key' => 'succession_planning',   'label' => 'Succession Planning',                      'group' => 'People & HR'],
        ['key' => 'employee_escalation',   'label' => 'Employee Escalation (Reach-out to Seniors)', 'group' => 'People & HR'],

        ['key' => 'lead_qualification',    'label' => 'Lead Qualification',                       'group' => 'Sales & Revenue'],
        ['key' => 'sales_pipeline',        'label' => 'Sales Pipeline / Deal Closure',            'group' => 'Sales & Revenue'],
        ['key' => 'quotation_approval',    'label' => 'Quotation & Proposal Approval',            'group' => 'Sales & Revenue'],
        ['key' => 'contract_renewal',      'label' => 'Contract Renewal',                         'group' => 'Sales & Revenue'],

        ['key' => 'customer_onboarding',   'label' => 'Customer Onboarding',                      'group' => 'Customer & Support'],
        ['key' => 'customer_outreach',     'label' => 'Customer Outreach / Account Management',   'group' => 'Customer & Support'],
        ['key' => 'complaint_escalation',  'label' => 'Complaint & Escalation Handling',          'group' => 'Customer & Support'],
        ['key' => 'service_request',       'label' => 'Service Request Fulfillment',              'group' => 'Customer & Support'],

        ['key' => 'project_kickoff',       'label' => 'Project Kickoff',                          'group' => 'Operations & Delivery'],
        ['key' => 'vendor_onboarding',     'label' => 'Vendor / Supplier Onboarding',             'group' => 'Operations & Delivery'],
        ['key' => 'procurement_approval',  'label' => 'Procurement & Purchase Approval',          'group' => 'Operations & Delivery'],
        ['key' => 'incident_management',   'label' => 'Incident Management',                      'group' => 'Operations & Delivery'],
        ['key' => 'change_request',        'label' => 'Change Request Approval',                  'group' => 'Operations & Delivery'],

        ['key' => 'expense_approval',      'label' => 'Expense Approval',                         'group' => 'Finance & Compliance'],
        ['key' => 'invoice_approval',      'label' => 'Invoice / Payment Approval',               'group' => 'Finance & Compliance'],
        ['key' => 'compliance_audit',      'label' => 'Compliance Audit',                         'group' => 'Finance & Compliance'],
        ['key' => 'policy_review_cycle',   'label' => 'Policy Review Cycle',                      'group' => 'Finance & Compliance'],
        ['key' => 'risk_assessment',       'label' => 'Risk Assessment',                          'group' => 'Finance & Compliance'],

        ['key' => 'meeting_governance',    'label' => 'Meeting / Decision Governance',            'group' => 'Governance & Internal Comms'],
        ['key' => 'knowledge_transfer',    'label' => 'Knowledge Transfer',                       'group' => 'Governance & Internal Comms'],
        ['key' => 'internal_announcement', 'label' => 'Internal Announcement Rollout',            'group' => 'Governance & Internal Comms'],

        ['key' => 'custom',                'label' => 'Custom',                                   'group' => 'Custom'],
    ],

    // Starter step lists, keyed by category. Each becomes a tiny linear
    // start->...->end graph the builder lays out and the user then edits
    // freely - a head start, not a constraint. Any category without an
    // entry here (including a tenant's own free-typed category) just opens
    // a blank Start->End canvas.
    'templates' => [
        'recruitment' => [
            ['type' => 'start', 'title' => 'Requisition opened'],
            ['type' => 'task', 'title' => 'Screen resumes'],
            ['type' => 'task', 'title' => 'Phone screening'],
            ['type' => 'task', 'title' => 'Technical interview'],
            ['type' => 'approval', 'title' => 'Offer approval'],
            ['type' => 'task', 'title' => 'Send offer letter'],
            ['type' => 'end', 'title' => 'Candidate hired'],
        ],
        'onboarding' => [
            ['type' => 'start', 'title' => 'Offer accepted'],
            ['type' => 'task', 'title' => 'Prepare workstation & access'],
            ['type' => 'sop_reference', 'title' => 'Share onboarding SOP'],
            ['type' => 'task', 'title' => 'Day-one orientation'],
            ['type' => 'milestone', 'title' => 'First week check-in'],
            ['type' => 'end', 'title' => 'Onboarding complete'],
        ],
        'offboarding' => [
            ['type' => 'start', 'title' => 'Resignation / termination recorded'],
            ['type' => 'task', 'title' => 'Knowledge transfer handover'],
            ['type' => 'task', 'title' => 'Revoke access & collect assets'],
            ['type' => 'approval', 'title' => 'Full and final settlement approval'],
            ['type' => 'notification', 'title' => 'Exit confirmation'],
            ['type' => 'end', 'title' => 'Offboarding complete'],
        ],
        'performance_review' => [
            ['type' => 'start', 'title' => 'Review cycle opens'],
            ['type' => 'task', 'title' => 'Self-assessment submitted'],
            ['type' => 'task', 'title' => 'Manager review'],
            ['type' => 'decision', 'title' => 'Rating calibration'],
            ['type' => 'approval', 'title' => 'Final rating sign-off'],
            ['type' => 'notification', 'title' => 'Share results with employee'],
            ['type' => 'end', 'title' => 'Review closed'],
        ],
        'task_evaluation' => [
            ['type' => 'start', 'title' => 'Task marked complete'],
            ['type' => 'task', 'title' => 'QA review against acceptance criteria'],
            ['type' => 'decision', 'title' => 'Meets quality bar?'],
            ['type' => 'task', 'title' => 'Return for rework'],
            ['type' => 'end', 'title' => 'Task accepted'],
        ],
        'learning_development' => [
            ['type' => 'start', 'title' => 'Learning need identified'],
            ['type' => 'task', 'title' => 'Enroll in course / training'],
            ['type' => 'milestone', 'title' => 'Course completed'],
            ['type' => 'task', 'title' => 'Assessment'],
            ['type' => 'end', 'title' => 'Competency recorded'],
        ],
        'assessment' => [
            ['type' => 'start', 'title' => 'Assessment scheduled'],
            ['type' => 'task', 'title' => 'Candidate/employee attempts assessment'],
            ['type' => 'task', 'title' => 'Evaluator scores submission'],
            ['type' => 'decision', 'title' => 'Pass / fail'],
            ['type' => 'notification', 'title' => 'Share result'],
            ['type' => 'end', 'title' => 'Assessment closed'],
        ],
        'promotion_transfer' => [
            ['type' => 'start', 'title' => 'Promotion/transfer proposed'],
            ['type' => 'approval', 'title' => 'Department head approval'],
            ['type' => 'approval', 'title' => 'HR approval'],
            ['type' => 'task', 'title' => 'Update records & compensation'],
            ['type' => 'notification', 'title' => 'Announce change'],
            ['type' => 'end', 'title' => 'Effective'],
        ],
        'grievance_handling' => [
            ['type' => 'start', 'title' => 'Grievance / complaint filed'],
            ['type' => 'task', 'title' => 'Acknowledge receipt'],
            ['type' => 'task', 'title' => 'Investigate'],
            ['type' => 'approval', 'title' => 'Resolution approval'],
            ['type' => 'notification', 'title' => 'Communicate outcome'],
            ['type' => 'end', 'title' => 'Case closed'],
        ],
        'succession_planning' => [
            ['type' => 'start', 'title' => 'Key role identified'],
            ['type' => 'task', 'title' => 'Identify successor candidates'],
            ['type' => 'task', 'title' => 'Readiness assessment'],
            ['type' => 'approval', 'title' => 'Leadership sign-off'],
            ['type' => 'end', 'title' => 'Succession plan recorded'],
        ],
        'employee_escalation' => [
            ['type' => 'start', 'title' => 'Employee raises an issue to a senior'],
            ['type' => 'task', 'title' => 'Senior acknowledges'],
            ['type' => 'decision', 'title' => 'Can be resolved directly?'],
            ['type' => 'task', 'title' => 'Escalate further up the chain'],
            ['type' => 'notification', 'title' => 'Close the loop with employee'],
            ['type' => 'end', 'title' => 'Resolved'],
        ],
        'lead_qualification' => [
            ['type' => 'start', 'title' => 'New lead captured'],
            ['type' => 'task', 'title' => 'Initial outreach'],
            ['type' => 'decision', 'title' => 'Meets qualification criteria?'],
            ['type' => 'task', 'title' => 'Hand off to sales'],
            ['type' => 'end', 'title' => 'Lead qualified'],
        ],
        'sales_pipeline' => [
            ['type' => 'start', 'title' => 'Opportunity created'],
            ['type' => 'task', 'title' => 'Discovery call'],
            ['type' => 'task', 'title' => 'Proposal sent'],
            ['type' => 'decision', 'title' => 'Won / lost'],
            ['type' => 'task', 'title' => 'Contract signed'],
            ['type' => 'end', 'title' => 'Deal closed'],
        ],
        'quotation_approval' => [
            ['type' => 'start', 'title' => 'Quotation requested'],
            ['type' => 'task', 'title' => 'Prepare quotation'],
            ['type' => 'approval', 'title' => 'Pricing approval'],
            ['type' => 'notification', 'title' => 'Send to customer'],
            ['type' => 'end', 'title' => 'Quotation sent'],
        ],
        'contract_renewal' => [
            ['type' => 'start', 'title' => 'Contract nears expiry'],
            ['type' => 'task', 'title' => 'Review terms with customer'],
            ['type' => 'approval', 'title' => 'Renewal terms approval'],
            ['type' => 'task', 'title' => 'Execute renewed contract'],
            ['type' => 'end', 'title' => 'Renewed'],
        ],
        'customer_onboarding' => [
            ['type' => 'start', 'title' => 'New customer signed'],
            ['type' => 'task', 'title' => 'Kickoff call'],
            ['type' => 'task', 'title' => 'Provision account/access'],
            ['type' => 'milestone', 'title' => 'First value delivered'],
            ['type' => 'end', 'title' => 'Onboarded'],
        ],
        'customer_outreach' => [
            ['type' => 'start', 'title' => 'Outreach cadence due'],
            ['type' => 'task', 'title' => 'Check-in call / email'],
            ['type' => 'decision', 'title' => 'Needs attention?'],
            ['type' => 'task', 'title' => 'Escalate to account manager'],
            ['type' => 'end', 'title' => 'Logged'],
        ],
        'complaint_escalation' => [
            ['type' => 'start', 'title' => 'Complaint received'],
            ['type' => 'task', 'title' => 'Acknowledge customer'],
            ['type' => 'task', 'title' => 'Investigate root cause'],
            ['type' => 'approval', 'title' => 'Resolution / compensation approval'],
            ['type' => 'notification', 'title' => 'Resolve with customer'],
            ['type' => 'end', 'title' => 'Closed'],
        ],
        'service_request' => [
            ['type' => 'start', 'title' => 'Request logged'],
            ['type' => 'task', 'title' => 'Triage & assign'],
            ['type' => 'task', 'title' => 'Fulfill request'],
            ['type' => 'notification', 'title' => 'Confirm with requester'],
            ['type' => 'end', 'title' => 'Fulfilled'],
        ],
        'project_kickoff' => [
            ['type' => 'start', 'title' => 'Charter approved'],
            ['type' => 'task', 'title' => 'Assign project owner'],
            ['type' => 'task', 'title' => 'Break down into first tasks'],
            ['type' => 'milestone', 'title' => 'Team aligned'],
            ['type' => 'end', 'title' => 'Project in flight'],
        ],
        'vendor_onboarding' => [
            ['type' => 'start', 'title' => 'Vendor selected'],
            ['type' => 'task', 'title' => 'Collect compliance documents'],
            ['type' => 'approval', 'title' => 'Vendor approval'],
            ['type' => 'task', 'title' => 'Set up in procurement system'],
            ['type' => 'end', 'title' => 'Vendor onboarded'],
        ],
        'procurement_approval' => [
            ['type' => 'start', 'title' => 'Purchase requested'],
            ['type' => 'approval', 'title' => 'Budget owner approval'],
            ['type' => 'approval', 'title' => 'Finance approval'],
            ['type' => 'task', 'title' => 'Raise purchase order'],
            ['type' => 'end', 'title' => 'Order placed'],
        ],
        'incident_management' => [
            ['type' => 'start', 'title' => 'Incident reported'],
            ['type' => 'task', 'title' => 'Triage severity'],
            ['type' => 'task', 'title' => 'Contain & investigate'],
            ['type' => 'milestone', 'title' => 'Resolved'],
            ['type' => 'task', 'title' => 'Post-incident review'],
            ['type' => 'end', 'title' => 'Closed'],
        ],
        'change_request' => [
            ['type' => 'start', 'title' => 'Change requested'],
            ['type' => 'task', 'title' => 'Impact assessment'],
            ['type' => 'approval', 'title' => 'Change approval'],
            ['type' => 'task', 'title' => 'Implement change'],
            ['type' => 'end', 'title' => 'Change deployed'],
        ],
        'expense_approval' => [
            ['type' => 'start', 'title' => 'Expense submitted'],
            ['type' => 'approval', 'title' => 'Manager approval'],
            ['type' => 'decision', 'title' => 'Above policy threshold?'],
            ['type' => 'approval', 'title' => 'Finance approval'],
            ['type' => 'task', 'title' => 'Reimburse'],
            ['type' => 'end', 'title' => 'Settled'],
        ],
        'invoice_approval' => [
            ['type' => 'start', 'title' => 'Invoice received'],
            ['type' => 'task', 'title' => 'Match against PO/goods receipt'],
            ['type' => 'approval', 'title' => 'Approval for payment'],
            ['type' => 'task', 'title' => 'Schedule payment'],
            ['type' => 'end', 'title' => 'Paid'],
        ],
        'compliance_audit' => [
            ['type' => 'start', 'title' => 'Audit cycle begins'],
            ['type' => 'task', 'title' => 'Gather evidence'],
            ['type' => 'task', 'title' => 'Review against checklist'],
            ['type' => 'decision', 'title' => 'Findings raised?'],
            ['type' => 'task', 'title' => 'Remediate findings'],
            ['type' => 'approval', 'title' => 'Sign-off'],
            ['type' => 'end', 'title' => 'Audit closed'],
        ],
        'policy_review_cycle' => [
            ['type' => 'start', 'title' => 'Policy review due'],
            ['type' => 'policy_reference', 'title' => 'Review existing policy'],
            ['type' => 'task', 'title' => 'Draft updates'],
            ['type' => 'approval', 'title' => 'Approve updated policy'],
            ['type' => 'notification', 'title' => 'Publish & notify department'],
            ['type' => 'end', 'title' => 'Policy updated'],
        ],
        'risk_assessment' => [
            ['type' => 'start', 'title' => 'Risk assessment triggered'],
            ['type' => 'task', 'title' => 'Identify risks'],
            ['type' => 'task', 'title' => 'Score likelihood & impact'],
            ['type' => 'approval', 'title' => 'Mitigation plan approval'],
            ['type' => 'end', 'title' => 'Risk register updated'],
        ],
        'meeting_governance' => [
            ['type' => 'start', 'title' => 'Meeting scheduled'],
            ['type' => 'task', 'title' => 'Circulate agenda'],
            ['type' => 'task', 'title' => 'Hold meeting & record decisions'],
            ['type' => 'task', 'title' => 'Distribute minutes & action items'],
            ['type' => 'end', 'title' => 'Closed'],
        ],
        'knowledge_transfer' => [
            ['type' => 'start', 'title' => 'Handover triggered'],
            ['type' => 'task', 'title' => 'Document key knowledge'],
            ['type' => 'task', 'title' => 'Walkthrough session'],
            ['type' => 'milestone', 'title' => 'Receiver confirms readiness'],
            ['type' => 'end', 'title' => 'Transferred'],
        ],
        'internal_announcement' => [
            ['type' => 'start', 'title' => 'Announcement drafted'],
            ['type' => 'approval', 'title' => 'Approve content'],
            ['type' => 'notification', 'title' => 'Publish to department'],
            ['type' => 'end', 'title' => 'Announced'],
        ],
    ],
];
