<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class IngestFuturevarsityStaffCommand extends Command
{
    protected $signature = 'hrms:ingest-futurevarsity-staff 
                            {--file= : Path to staging JSON file}
                            {--apply : Write changes to live db3 (default is dry-run)}';

    protected $description = 'Idempotent, audited ingestion of Future Varsity staff records into live database (db3)';

    private const SUB_INSTITUTE_ID = 15;
    private const INDUSTRY = 'Institute of Management Studies & Research';

    public function handle()
    {
        $apply = (bool) $this->option('apply');
        $jsonPath = $this->option('file') ?: storage_path('app/futurevarsity_parsed.json');

        $this->info("==================================================");
        $this->info("HRMS STAFF INGESTION: FUTURE VARSITY (TENANT 15)");
        $this->info("Target: live connection (db3 / 128.199.17.97 / MariaDB 10.1)");
        $this->info("Mode: " . ($apply ? "LIVE COMMIT (--apply active)" : "DRY RUN (no database writes)"));
        $this->info("Staging file: " . $jsonPath);
        $this->info("==================================================");

        if (!file_exists($jsonPath)) {
            $this->error("Staging file not found: {$jsonPath}");
            return 1;
        }

        $employees = json_decode(file_get_contents($jsonPath), true);
        if (!$employees || !is_array($employees)) {
            $this->error("Invalid JSON content in {$jsonPath}");
            return 1;
        }

        $this->info("Loaded " . count($employees) . " staged employee records.");

        $db = DB::connection('live');

        // Verify connection
        $ver = $db->select("SELECT DATABASE() as db, VERSION() as ver");
        $this->info("Connected database: {$ver[0]->db} ({$ver[0]->ver})");

        // Verify tenant exists
        $org = $db->table('org_details')->where('sub_institute_id', self::SUB_INSTITUTE_ID)->first();
        if (!$org) {
            $this->error("Tenant sub_institute_id " . self::SUB_INSTITUTE_ID . " not found in org_details!");
            return 1;
        }
        $this->info("Verified Tenant: {$org->legal_name} | Industry: {$org->industry}");

        // Department Mapping
        $deptMap = [
            'Head of Institution' => 'Institutional Leadership',
            'Academics and Faculty' => 'Academics & Faculty',
            'Academics' => 'Academics & Faculty',
            'Admissions' => 'Admissions & Outreach',
            'Marketing' => 'Marketing',
            'Accounts' => 'Accounts & Finance',
            'Admin' => 'General Administration',
            'General Staff' => 'General Administration',
            'Career Service Cell' => 'Training & Placements',
            'Exam and Eligibility' => 'Examination & Records',
            'IT' => 'Information Technology',
            'Lab' => 'Technical & Laboratories',
            'MIS' => 'Operations & Systems',
            'HR' => 'Human Resources',
            'Talent Management' => 'Human Resources',
            'Support Team' => 'Campus Facilities'
        ];

        // Jobrole Mapping to s_jobrole
        $roleMap = [
            'Head of Institution' => 1810,
            'Academics and Faculty' => 1808,
            'Academics' => 1806,
            'Admissions' => 3322,
            'Marketing' => 1028,
            'Accounts' => 13,
            'Admin' => 2054,
            'General Staff' => 2054,
            'Career Service Cell' => 1049,
            'Exam and Eligibility' => 1804,
            'IT' => 1142,
            'Lab' => 2060,
            'MIS' => 2060,
            'HR' => 1035,
            'Talent Management' => 1050,
            'Support Team' => 1813
        ];

        $reversalSql = [];
        $insertedUserIds = [];
        $createdDeptIds = [];
        $createdRoleIds = [];

        if ($apply) {
            $db->beginTransaction();
        }

        try {
            // 1. Departments resolution/creation
            $departmentIds = [];
            foreach (array_unique(array_values($deptMap)) as $deptName) {
                $existing = $db->table('hrms_departments')
                    ->where('sub_institute_id', self::SUB_INSTITUTE_ID)
                    ->where('department', $deptName)
                    ->first();

                if ($existing) {
                    $departmentIds[$deptName] = $existing->id;
                } else {
                    if ($apply) {
                        $dId = $db->table('hrms_departments')->insertGetId([
                            'department' => $deptName,
                            'sub_institute_id' => self::SUB_INSTITUTE_ID,
                            'status' => 1,
                            'parent_id' => 0,
                            'is_calculated' => 0,
                            'created_by' => 479,
                            'updated_by' => 479,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now()
                        ]);
                        $departmentIds[$deptName] = $dId;
                        $createdDeptIds[] = $dId;
                        $reversalSql[] = "DELETE FROM hrms_departments WHERE id = {$dId};";
                    } else {
                        $departmentIds[$deptName] = 90000 + count($departmentIds);
                    }
                }
            }
            $this->info("Departments resolved: " . count($departmentIds) . " (new in this run: " . count($createdDeptIds) . ")");

            // 2. Job Roles resolution/creation in s_user_jobrole
            $jobRoleIds = [];
            foreach ($roleMap as $rawDesig => $catRoleId) {
                $existing = $db->table('s_user_jobrole')
                    ->where('sub_institute_id', self::SUB_INSTITUTE_ID)
                    ->where('jobrole', $rawDesig)
                    ->first();

                if ($existing) {
                    $jobRoleIds[$rawDesig] = $existing->id;
                } else {
                    $targetDept = $deptMap[$rawDesig] ?? 'Academics & Faculty';
                    $targetDeptId = $departmentIds[$targetDept] ?? null;

                    if ($apply) {
                        $jrId = $db->table('s_user_jobrole')->insertGetId([
                            'jobrole' => $rawDesig,
                            'industries' => self::INDUSTRY,
                            'department' => $targetDept,
                            'department_id' => $targetDeptId,
                            'sub_institute_id' => self::SUB_INSTITUTE_ID,
                            'catalogue_jobrole_id' => $catRoleId,
                            'status' => 'Active',
                            'created_by' => 479,
                            'updated_by' => 479,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now()
                        ]);
                        $jobRoleIds[$rawDesig] = $jrId;
                        $createdRoleIds[] = $jrId;
                        $reversalSql[] = "DELETE FROM s_user_jobrole WHERE id = {$jrId};";
                    } else {
                        $jobRoleIds[$rawDesig] = 80000 + count($jobRoleIds);
                    }
                }
            }
            $this->info("Job roles resolved: " . count($jobRoleIds) . " (new in this run: " . count($createdRoleIds) . ")");

            // 3. User ingestion
            $defaultPasswordHash = Hash::make('FutureVarsity@2026');
            $employeeProfileId = 58; // Standard Employee role for sub_institute_id = 15
            $adminProfileId = 63;    // Admin role for Head of Institution

            $now = Carbon::now();
            $skipped = 0;
            $prepared = 0;

            foreach ($employees as $emp) {
                $email = strtolower(trim($emp['email']));

                // Check if already exists in tbluser for this tenant
                $existingUser = $db->table('tbluser')
                    ->where('sub_institute_id', self::SUB_INSTITUTE_ID)
                    ->where('email', $email)
                    ->first();

                if ($existingUser) {
                    $skipped++;
                    continue;
                }

                $rawDesig = $emp['designation'];
                $deptName = $deptMap[$rawDesig] ?? 'General Administration';
                $deptId = !empty($departmentIds[$deptName]) ? $departmentIds[$deptName] : null;
                $jobRoleId = !empty($jobRoleIds[$rawDesig]) ? $jobRoleIds[$rawDesig] : null;
                $isHead = ($rawDesig === 'Head of Institution');

                $userRow = [
                    'user_name' => substr($email, 0, 191),
                    'email' => substr($email, 0, 191),
                    'password' => $defaultPasswordHash,
                    'first_name' => substr($emp['first_name'], 0, 191),
                    'middle_name' => substr($emp['middle_name'] ?? '', 0, 191),
                    'last_name' => substr($emp['last_name'], 0, 191),
                    'mobile' => substr($emp['personal_phone'] ?? ($emp['official_phone'] ?? ''), 0, 191),
                    'gender' => $emp['gender'] ?? null,
                    'birthdate' => $emp['dob'] ?? null,
                    'user_profile_id' => $isHead ? $adminProfileId : $employeeProfileId,
                    'sub_institute_id' => self::SUB_INSTITUTE_ID,
                    'is_admin' => $isHead ? 1 : 0,
                    'status' => 1,
                    'jobtitle_id' => $jobRoleId,
                    'department_id' => $deptId,
                    'employee_no' => 'FV-' . str_pad($emp['sr_no'], 4, '0', STR_PAD_LEFT),
                    'qualification' => substr($emp['qualification'] ?? '', 0, 191),
                    'pan_no' => substr($emp['pan'] ?? '', 0, 191),
                    'aadhar_no' => substr($emp['aadhar'] ?? '', 0, 191),
                    'amount' => $emp['salary'],
                    'joined_date' => $emp['doj'],
                    'created_by' => 479,
                    'updated_by' => 479,
                    'created_at' => $now,
                    'updated_at' => $now
                ];

                if ($apply) {
                    $uId = $db->table('tbluser')->insertGetId($userRow);
                    $insertedUserIds[] = $uId;
                    $reversalSql[] = "DELETE FROM tbluser WHERE id = {$uId};";
                }
                $prepared++;
            }

            if ($apply) {
                $db->commit();
                $this->info("==================================================");
                $this->info("COMMITTED TO LIVE: {$prepared} users inserted, {$skipped} skipped.");
                $this->info("==================================================");

                // Write Reversal SQL
                $reversalFile = base_path("_local-backups/REVERSAL-" . date('Y-m-d-His') . "-futurevarsity-staff.sql");
                file_put_contents($reversalFile, "-- REVERSAL SCRIPT FOR FUTURE VARSITY STAFF INGESTION\n-- Run date: " . date('Y-m-d H:i:s') . "\n\n" . implode("\n", array_reverse($reversalSql)) . "\n");
                $this->info("Reversal script written to: {$reversalFile}");

                // Write Evidence Log
                $evidencePath = base_path("Docs/agentic-crm/_evidence/s-futurevarsity-staff-live-apply-" . date('Y-m-d') . ".txt");
                $evidenceText = "HRMS STAFF INGESTION EVIDENCE LOG\n"
                    . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
                    . "Tenant: Future Varsity (sub_institute_id = 15)\n"
                    . "Industry: " . self::INDUSTRY . "\n"
                    . "Total records prepared: {$prepared}\n"
                    . "Total records inserted: " . count($insertedUserIds) . "\n"
                    . "Total records skipped: {$skipped}\n"
                    . "Departments created: " . count($createdDeptIds) . "\n"
                    . "Job roles created: " . count($createdRoleIds) . "\n"
                    . "Reversal script: {$reversalFile}\n";
                file_put_contents($evidencePath, $evidenceText);
                $this->info("Evidence log written to: {$evidencePath}");

            } else {
                $this->info("==================================================");
                $this->warn("DRY RUN FINISHED: {$prepared} users ready to insert, {$skipped} skipped.");
                $this->warn("No database modifications were made.");
                $this->info("==================================================");
            }

        } catch (\Throwable $e) {
            if ($apply) {
                $db->rollBack();
            }
            $this->error("FAILED with exception: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
