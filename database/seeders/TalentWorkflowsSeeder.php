<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TalentWorkflowsSeeder extends Seeder
{
    public function run(): void
    {
        // First clear out existing
        DB::table('talent_workflow_approvers')->truncate();
        DB::table('talent_workflow_stages')->truncate();
        DB::table('talent_workflows')->truncate();

        // Get a default sub institute ID to use
        $subInstituteId = DB::table('school_setup')->value('id');
        if (!$subInstituteId) {
            $subInstituteId = 1;
        }

        // Get an admin user ID for created/updated_by
        $adminId = DB::table('tbluser')->where('is_admin', 1)->value('id') ?? 1;

        $now = Carbon::now();

        $workflows = [
            [
                'name' => 'Job Requisition Approval',
                'module' => 'Recruitment',
                'status' => 'Active',
                'version' => '1.3',
                'description' => 'Workflow for raising, routing and approving job requisitions.',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(78),
                'updated_at' => $now->copy()->subDays(78),
            ],
            [
                'name' => 'Interview Scheduling',
                'module' => 'Recruitment',
                'status' => 'Active',
                'version' => '2.1',
                'description' => 'Interview scheduling and coordination',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(82),
                'updated_at' => $now->copy()->subDays(82),
            ],
            [
                'name' => 'Offer Approval',
                'module' => 'Recruitment',
                'status' => 'Active',
                'version' => '1.2',
                'description' => 'Offer letter approval workflow',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(88),
                'updated_at' => $now->copy()->subDays(88),
            ],
            [
                'name' => 'Onboarding Checklist',
                'module' => 'Onboarding',
                'status' => 'Active',
                'version' => '1.5',
                'description' => 'New hire onboarding workflow',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(90),
                'updated_at' => $now->copy()->subDays(90),
            ],
            [
                'name' => 'Probation Confirmation',
                'module' => 'Onboarding',
                'status' => 'Active',
                'version' => '1.1',
                'description' => 'Probation tracking and confirmation',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(95),
                'updated_at' => $now->copy()->subDays(95),
            ],
            [
                'name' => 'Performance Review Cycle',
                'module' => 'Performance',
                'status' => 'Draft',
                'version' => '1.0',
                'description' => 'Performance review process workflow',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(75),
                'updated_at' => $now->copy()->subDays(75),
            ],
            [
                'name' => 'Exit Clearance',
                'module' => 'Offboarding',
                'status' => 'Active',
                'version' => '1.4',
                'description' => 'Employee exit clearance workflow',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(80),
                'updated_at' => $now->copy()->subDays(80),
            ],
            [
                'name' => 'Promotion Approval',
                'module' => 'Mobility',
                'status' => 'Active',
                'version' => '1.0',
                'description' => 'Promotion approval workflow',
                'sub_institute_id' => $subInstituteId,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now->copy()->subDays(92),
                'updated_at' => $now->copy()->subDays(92),
            ],
        ];

        DB::table('talent_workflows')->insert($workflows);
        
        // Let's get the ID of 'Job Requisition Approval' to insert stages and approvers
        $wf1 = DB::table('talent_workflows')->where('name', 'Job Requisition Approval')->first();

        if ($wf1) {
            DB::table('talent_workflow_stages')->insert([
                ['workflow_id' => $wf1->id, 'step' => 1, 'label' => "Requisition\nRaised"],
                ['workflow_id' => $wf1->id, 'step' => 2, 'label' => "Manager\nApproval"],
                ['workflow_id' => $wf1->id, 'step' => 3, 'label' => "HR\nReview"],
                ['workflow_id' => $wf1->id, 'step' => 4, 'label' => "Finance\nApproval"],
                ['workflow_id' => $wf1->id, 'step' => 5, 'label' => "Approved"],
            ]);

            DB::table('talent_workflow_approvers')->insert([
                ['workflow_id' => $wf1->id, 'role' => 'Manager', 'title' => 'Immediate Manager', 'approval_type' => 'Mandatory', 'escalation' => 'After 2 Days', 'sort_order' => 1],
                ['workflow_id' => $wf1->id, 'role' => 'HR', 'title' => 'HR Manager', 'approval_type' => 'Mandatory', 'escalation' => 'After 1 Day', 'sort_order' => 2],
                ['workflow_id' => $wf1->id, 'role' => 'Finance', 'title' => 'Finance Controller', 'approval_type' => 'Mandatory', 'escalation' => 'After 1 Day', 'sort_order' => 3],
            ]);
        }
    }
}
