<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds realistic Competency Management data for a single tenant so the
 * Command Center dashboard renders meaningful numbers. Draws real job roles,
 * departments and employees from the tenant's existing tables. Idempotent:
 * it hard-deletes only the competency-module rows for the target tenant first,
 * so re-running never duplicates and never touches other tenants or tables.
 *
 * Target tenant is sub_institute_id = 1 by default (the main demo tenant);
 * override with:  php artisan db:seed --class=CompetencySeeder  after setting
 * COMPETENCY_SEED_TENANT in the environment.
 */
class CompetencySeeder extends Seeder
{
    public function run(): void
    {
        $sid = (int) (env('COMPETENCY_SEED_TENANT', 1));
        $now = Carbon::now();

        // --- Idempotency: clear this tenant's competency-module rows ---------
        foreach ([
            's_competency_activity_log',
            's_competency_evidence',
            's_competency_mapping_reviews',
            's_competency_framework_weights',
            's_competency_plan_actions',
            's_competency_development_plans',
            's_competency_certifications',
            's_competency_certification_requirements',
            's_competency_assessments',
            's_competency_framework_items',
            's_competency_assessment_cycles',
            's_competency_career_path_steps',
            's_competency_career_paths',
            's_competency_frameworks',
        ] as $table) {
            DB::table($table)->where('sub_institute_id', $sid)->delete();
        }

        // lms_assignments is SHARED with the LMS module - only ever touch the
        // rows this workspace owns (source='competency'), never the LMS's own.
        DB::table('lms_assignments')
            ->where('sub_institute_id', $sid)
            ->where('source', 'competency')
            ->delete();

        // --- Real source data ------------------------------------------------
        $roles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->select('jobrole', 'department_id', 'department', 'location', 'industries', 'jobrole_category')
            ->limit(60)
            ->get();

        if ($roles->isEmpty()) {
            $this->command?->warn("CompetencySeeder: no job roles for sub_institute_id={$sid}; nothing seeded.");
            return;
        }

        // Competencies are the existing approved skills catalog (s_users_skills)
        // - reference real skill ids for the competency_id links below rather
        // than seeding a duplicate competency table.
        $competencyIds = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('approve_status', 'Approved')
            ->limit(200)
            ->pluck('id')
            ->all();
        if (empty($competencyIds)) {
            $competencyIds = [null];
        }

        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $sid)
            ->select('id', 'first_name', 'last_name')
            ->limit(60)
            ->get();

        $pickRole = fn () => $roles[array_rand($roles->all())];
        $pickEmp = fn () => $employees->isNotEmpty() ? $employees[array_rand($employees->all())] : null;
        $empName = fn ($e) => $e ? trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')) : 'System';

        // --- Frameworks (24; ~18 active) -------------------------------------
        $frameworkRows = [];
        $deptNames = $roles->pluck('department')->filter()->unique()->values()->all();
        for ($i = 0; $i < 24; $i++) {
            $role = $pickRole();
            $dept = $deptNames[$i % max(count($deptNames), 1)] ?? 'Corporate';
            $status = $i < 18 ? 'active' : ($i < 22 ? 'draft' : 'archived');
            $frameworkRows[] = [
                'sub_institute_id' => $sid,
                'name'             => $dept . ' Competency Framework',
                'description'      => 'Competency framework covering the ' . $dept . ' function.',
                'version'          => 'v' . rand(1, 3) . '.0',
                'status'           => $status,
                'department_id'    => $role->department_id,
                'jobrole'          => $role->jobrole,
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(rand(10, 200)),
                'updated_at'       => $now,
            ];
        }
        DB::table('s_competency_frameworks')->insert($frameworkRows);
        $frameworkIds = DB::table('s_competency_frameworks')->where('sub_institute_id', $sid)->pluck('id')->all();

        // --- 3. Framework items (map competencies into frameworks) -----------
        $itemRows = [];
        foreach ($frameworkIds as $fid) {
            $count = min(count($competencyIds), rand(4, 8));
            $picks = (array) array_rand(array_flip($competencyIds), $count);
            foreach ($picks as $cid) {
                $itemRows[] = [
                    'sub_institute_id'     => $sid,
                    'framework_id'         => $fid,
                    'competency_id'        => $cid,
                    'required_proficiency' => 'Level ' . rand(1, 5),
                    'created_by'           => 1,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }
        }
        if ($itemRows) {
            DB::table('s_competency_framework_items')->insert($itemRows);
        }

        // --- 4. Assessment cycles (one active spanning today) ----------------
        $cycleRows = [
            [
                'sub_institute_id' => $sid,
                'name'             => 'Q3 Competency Assessment Cycle',
                'description'      => 'Organisation-wide competency assessment cycle.',
                'start_date'       => (clone $now)->subDays(10)->toDateString(),
                'end_date'         => (clone $now)->addDays(50)->toDateString(),
                'status'           => 'active',
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'sub_institute_id' => $sid,
                'name'             => 'Q4 Leadership Review',
                'description'      => 'Leadership competency review cycle.',
                'start_date'       => (clone $now)->addDays(70)->toDateString(),
                'end_date'         => (clone $now)->addDays(120)->toDateString(),
                'status'           => 'scheduled',
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'sub_institute_id' => $sid,
                'name'             => 'Q1 Annual Competency Assessment',
                'description'      => 'Closed annual competency assessment cycle.',
                'start_date'       => (clone $now)->subDays(160)->toDateString(),
                'end_date'         => (clone $now)->subDays(40)->toDateString(),
                'status'           => 'closed',
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(160),
                'updated_at'       => (clone $now)->subDays(40),
            ],
        ];
        DB::table('s_competency_assessment_cycles')->insert($cycleRows);
        $activeCycleId = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)->where('status', 'active')->value('id');
        $closedCycleId = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)->where('status', 'closed')->value('id');

        // --- 5. Assessments (140) -------------------------------------------
        $assessmentRows = [];
        for ($i = 0; $i < 140; $i++) {
            $role = $pickRole();
            $emp = $pickEmp();
            $assessor = $pickEmp();

            // ~58% completed, ~14% in_progress, ~16% open, ~12% overdue
            $r = $i % 50;
            if ($r < 29) {
                $status = 'completed';
            } elseif ($r < 36) {
                $status = 'in_progress';
            } elseif ($r < 44) {
                $status = 'open';
            } else {
                $status = 'overdue';
            }

            $completedAt = $status === 'completed' ? (clone $now)->subDays(rand(1, 40)) : null;
            $score = $status === 'completed' ? rand(55, 98) + (rand(0, 99) / 100) : null;

            // Due dates: overdue in the past; others spread, many within 60 days.
            if ($status === 'overdue') {
                $due = (clone $now)->subDays(rand(2, 25))->toDateString();
            } else {
                $due = (clone $now)->addDays(rand(-5, 58))->toDateString();
            }

            // ~22 completed assessments still awaiting review.
            $reviewStatus = ($status === 'completed' && $i % 6 === 0) ? 'pending_review' : null;

            // Put ~1 in 3 assessments into the closed cycle so the Closed
            // Campaigns tab has real history; the rest into the active cycle.
            $cycleId = ($closedCycleId && $i % 3 === 0) ? $closedCycleId : $activeCycleId;

            $assessmentRows[] = [
                'sub_institute_id' => $sid,
                'title'            => $role->jobrole . ' Assessment',
                'framework_id'     => $frameworkIds[array_rand($frameworkIds)],
                'cycle_id'         => $cycleId,
                'user_id'          => $emp->id ?? null,
                'assessor_id'      => $assessor->id ?? null,
                'department_id'    => $role->department_id,
                'jobrole'          => $role->jobrole,
                'status'           => $status,
                'review_status'    => $reviewStatus,
                'score'            => $score,
                'due_date'         => $due,
                'completed_at'     => $completedAt,
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(rand(1, 60)),
                'updated_at'       => $now,
            ];
        }
        DB::table('s_competency_assessments')->insert($assessmentRows);

        // --- 6. Certifications (220) ----------------------------------------
        // Each credential carries its real issuing body and a type, so the
        // Certification & Compliance Center's Type / Issuing Body filters and
        // the Overview panel have genuine values to work with.
        $certNotes = [
            'Original certificate sighted and filed.',
            'Renewal reminder sent to the holder.',
            'Awaiting the issuing body to confirm the credential id.',
            'Verified against the provider register.',
            'Holder has booked the recertification exam.',
        ];
        $certCatalog = [
            'PMP'                      => ['PMI', 'Industry Certification', 36],
            'AWS Solutions Architect'  => ['Amazon Web Services', 'Vendor Certification', 36],
            'CISSP'                    => ['(ISC)2', 'Industry Certification', 36],
            'Six Sigma Green Belt'     => ['ASQ', 'Industry Certification', null],
            'ITIL Foundation'          => ['AXELOS', 'Industry Certification', null],
            'CFA Level I'              => ['CFA Institute', 'Regulatory', null],
            'Scrum Master'             => ['Scrum Alliance', 'Industry Certification', 24],
            'Google Data Analytics'    => ['Google', 'Vendor Certification', null],
            'ISO 9001 Lead Auditor'    => ['BSI', 'Regulatory', 36],
            'Azure Fundamentals'       => ['Microsoft', 'Vendor Certification', 24],
        ];
        $certNames = array_keys($certCatalog);
        $certRows = [];
        for ($i = 0; $i < 220; $i++) {
            $role = $pickRole();
            $emp = $pickEmp();

            // ~74% valid, ~9% expiring, ~11% expired, ~6% revoked
            $r = $i % 100;
            if ($r < 74) {
                $status = 'valid';
            } elseif ($r < 83) {
                $status = 'expiring';
            } elseif ($r < 94) {
                $status = 'expired';
            } else {
                $status = 'revoked';
            }

            // Expiry: expired in past; expiring within 30 days; valid mostly far
            // future, but ~1 in 8 valid certs fall inside the 30-day window too.
            if ($status === 'expired') {
                $expiry = (clone $now)->subDays(rand(5, 200))->toDateString();
            } elseif ($status === 'expiring') {
                $expiry = (clone $now)->addDays(rand(1, 30))->toDateString();
            } elseif ($status === 'valid' && $i % 8 === 0) {
                $expiry = (clone $now)->addDays(rand(3, 29))->toDateString();
            } else {
                $expiry = (clone $now)->addDays(rand(60, 720))->toDateString();
            }

            $certName = $certNames[$i % count($certNames)];
            [$issuingBody, $certType] = $certCatalog[$certName];

            // ~25% still awaiting review (the "Pending Verification" KPI),
            // ~65% verified, ~10% rejected.
            $v = $i % 20;
            $verification = $v < 5 ? 'pending' : ($v < 18 ? 'verified' : 'rejected');

            $certRows[] = [
                'sub_institute_id'    => $sid,
                'name'                => $certName,
                'user_id'             => $emp->id ?? null,
                'competency_id'       => $competencyIds[array_rand($competencyIds)],
                'issuing_body'        => $issuingBody,
                'certification_type'  => $certType,
                'credential_id'       => 'CRED-' . strtoupper(substr(md5($i . $sid), 0, 8)),
                'department_id'       => $role->department_id,
                'jobrole'             => $role->jobrole,
                'status'              => $status,
                'verification_status' => $verification,
                'verified_by'         => $verification === 'pending' ? null : 1,
                'verified_at'         => $verification === 'pending' ? null : (clone $now)->subDays(rand(1, 120)),
                'issued_date'         => (clone $now)->subDays(rand(200, 900))->toDateString(),
                'expiry_date'         => $expiry,
                // Roughly one credential in four carries a reviewer note, in
                // the same "[date - author] text" format addNote() appends. It
                // fills the Overview panel's Notes block and is the record the
                // Audit Center's Comments tab reports.
                'notes'               => $i % 4 === 0
                    ? '[' . (clone $now)->subDays(rand(2, 90))->format('d M Y') . ' - Admin User] '
                        . $certNotes[$i % count($certNotes)]
                    : null,
                'created_by'          => 1,
                'updated_by'          => 1,
                'created_at'          => (clone $now)->subDays(rand(1, 200)),
                'updated_at'          => $now,
            ];
        }
        DB::table('s_competency_certifications')->insert($certRows);

        // --- 6b. Certification requirements ---------------------------------
        // The policy layer behind the compliance center: which credential a job
        // role / department / the whole organisation is expected to hold. Scoped
        // against the tenant's REAL job roles so the Requirements tab and the
        // Compliant Employees KPI resolve against live data.
        $requirementRows = [];

        // Two organisation-wide requirements everybody is measured against.
        foreach ([['ITIL Foundation', false], ['ISO 9001 Lead Auditor', true]] as [$name, $mandatory]) {
            [$issuingBody, $certType, $validity] = $certCatalog[$name];
            $requirementRows[] = [
                'sub_institute_id'      => $sid,
                'name'                  => $name,
                'certification_type'    => $certType,
                'issuing_body'          => $issuingBody,
                'description'           => $name . ' is expected across the organisation.',
                'department_id'         => null,
                'jobrole'               => null,
                'competency_id'         => null,
                'is_mandatory'          => $mandatory ? 1 : 0,
                'validity_months'       => $validity,
                'renewal_reminder_days' => 60,
                'grace_period_days'     => 30,
                'status'                => 'active',
                'created_by'            => 1,
                'updated_by'            => 1,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        // Role-scoped requirements over the first dozen real job roles.
        $requirementRoles = $roles->take(12);
        $index = 0;
        foreach ($requirementRoles as $role) {
            $name = $certNames[$index % count($certNames)];
            [$issuingBody, $certType, $validity] = $certCatalog[$name];

            $requirementRows[] = [
                'sub_institute_id'      => $sid,
                'name'                  => $name,
                'certification_type'    => $certType,
                'issuing_body'          => $issuingBody,
                'description'           => $name . ' is required for the ' . $role->jobrole . ' role.',
                'department_id'         => $role->department_id,
                'jobrole'               => $role->jobrole,
                'competency_id'         => null,
                // Two thirds mandatory, the rest recommended.
                'is_mandatory'          => $index % 3 === 2 ? 0 : 1,
                'validity_months'       => $validity,
                'renewal_reminder_days' => 60,
                'grace_period_days'     => $index % 2 === 0 ? 30 : null,
                'status'                => 'active',
                'created_by'            => 1,
                'updated_by'            => 1,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
            $index++;
        }
        DB::table('s_competency_certification_requirements')->insert($requirementRows);

        // Link held credentials back to the requirement they satisfy, so the
        // Requirements tab shows met/unmet against real rows rather than
        // relying on name matching alone.
        foreach (DB::table('s_competency_certification_requirements')
            ->where('sub_institute_id', $sid)->whereNotNull('jobrole')->get() as $requirement) {
            DB::table('s_competency_certifications')
                ->where('sub_institute_id', $sid)
                ->where('jobrole', $requirement->jobrole)
                ->where('name', $requirement->name)
                ->whereNull('deleted_at')
                ->update(['requirement_id' => $requirement->id]);
        }

        // --- 7. Development plans (160) -------------------------------------
        $planRows = [];
        for ($i = 0; $i < 160; $i++) {
            $role = $pickRole();
            $emp = $pickEmp();
            $approver = $pickEmp();

            // ~54% active, ~30% completed, ~10% overdue, ~6% on_hold
            $r = $i % 50;
            if ($r < 27) {
                $status = 'active';
            } elseif ($r < 42) {
                $status = 'completed';
            } elseif ($r < 47) {
                $status = 'overdue';
            } else {
                $status = 'on_hold';
            }

            $progress = match ($status) {
                'completed' => 100,
                'overdue'   => rand(10, 60),
                'on_hold'   => rand(0, 40),
                default     => rand(20, 90),
            };

            if ($status === 'overdue') {
                $due = (clone $now)->subDays(rand(3, 40))->toDateString();
            } elseif ($status === 'completed') {
                $due = (clone $now)->subDays(rand(1, 30))->toDateString();
            } else {
                $due = (clone $now)->addDays(rand(5, 120))->toDateString();
            }

            // ~1 in 4 active/on_hold plans await approval; approver cycles
            // through the seeded employees so approvers get real queues.
            $pendingApproval = in_array($status, ['active', 'on_hold'], true) && $i % 4 === 0;

            $planRows[] = [
                'sub_institute_id' => $sid,
                'title'            => 'Development Plan - ' . $role->jobrole,
                'user_id'          => $emp->id ?? null,
                'competency_id'    => $competencyIds[array_rand($competencyIds)],
                'framework_id'     => $frameworkIds[array_rand($frameworkIds)],
                'department_id'    => $role->department_id,
                'jobrole'          => $role->jobrole,
                'status'           => $status,
                'progress'         => $progress,
                'start_date'       => (clone $now)->subDays(rand(20, 120))->toDateString(),
                'due_date'         => $due,
                'completed_at'     => $status === 'completed' ? (clone $now)->subDays(rand(1, 25)) : null,
                'approver_id'      => $pendingApproval ? ($approver->id ?? null) : null,
                'approval_status'  => $pendingApproval ? 'pending_approval' : null,
                'mentor_id'        => $pickEmp()->id ?? null,
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(rand(1, 120)),
                'updated_at'       => $now,
            ];
        }
        DB::table('s_competency_development_plans')->insert($planRows);

        // --- 8. Activity feed ------------------------------------------------
        // Backfilled at the end of run(), once every domain record exists, so
        // each entry names a row that is genuinely in the database.

        // --- 9. Category weighting (default profile, framework_id = null) -----
        // Weights the tenant's real competency categories so the Weighting tab
        // renders a meaningful split. framework_id null = the default profile.
        $weightCats = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('approve_status', 'Approved')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category', DB::raw('COUNT(*) as c'))
            ->groupBy('category')
            ->orderByDesc('c')
            ->limit(6)
            ->pluck('category')
            ->all();

        if ($weightCats) {
            $presetWeights = [50, 20, 15, 10, 5, 0];
            $weightRows = [];
            foreach ($weightCats as $idx => $category) {
                $weightRows[] = [
                    'sub_institute_id' => $sid,
                    'framework_id'     => null,
                    'category'         => $category,
                    'weight'           => $presetWeights[$idx] ?? 0,
                    'created_by'       => 1,
                    'updated_by'       => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            DB::table('s_competency_framework_weights')->insert($weightRows);
        }

        // --- 10. Mapping-change reviews (18: pending/approved/rejected) -------
        // Reviewer notes per outcome. Every review carries one: a reviewer
        // leaving a remark is the module's main comment surface, and it is what
        // the Audit Center's Comments tab reads.
        $reviewNotes = [
            'pending'  => [
                'Waiting on the department head to confirm the level 4 entries.',
                'Please attach the role profile before this can be signed off.',
                'Holding until the framework re-publish lands next week.',
            ],
            'approved' => [
                'Levels line up with the global scale - approved.',
                'Checked against the role profile, no further changes needed.',
                'Approved. Good improvement on the technical cluster.',
            ],
            'rejected' => [
                'Levels need alignment with the global scale.',
                'Two competencies here belong to a different job family.',
            ],
        ];

        $reviewRows = [];
        for ($i = 0; $i < 18; $i++) {
            $role = $pickRole();
            $emp = $pickEmp();
            // ~55% pending, ~30% approved, ~15% rejected
            $r = $i % 20;
            $status = $r < 11 ? 'pending' : ($r < 17 ? 'approved' : 'rejected');
            $reviewed = $status === 'pending' ? null : (clone $now)->subDays(rand(1, 20));
            $notes = $reviewNotes[$status];

            $reviewRows[] = [
                'sub_institute_id'  => $sid,
                'jobrole'           => $role->jobrole,
                'department'        => $role->department,
                'department_id'     => $role->department_id,
                'framework_id'      => $frameworkIds[array_rand($frameworkIds)],
                'submitted_by'      => $emp->id ?? null,
                'submitted_by_name' => $empName($emp),
                'status'            => $status,
                'changes_count'     => rand(2, 14),
                'changes'           => 'Updated required proficiency for ' . rand(2, 14) . ' competencies.',
                'note'              => $notes[$i % count($notes)],
                'reviewer_id'       => $status === 'pending' ? null : 1,
                'reviewed_at'       => $reviewed,
                'created_by'        => $emp->id ?? 1,
                'updated_by'        => 1,
                'created_at'        => (clone $now)->subDays(rand(1, 30)),
                'updated_at'        => $now,
            ];
        }
        DB::table('s_competency_mapping_reviews')->insert($reviewRows);

        // --- 11. Evidence repository (per employee, a few items each) ---------
        $evidenceTemplates = [
            ['certification', 'Certificate of Completion'],
            ['project', 'Project Portfolio Submission'],
            ['endorsement', 'Peer Endorsement'],
            ['training', 'Training Attendance Record'],
            ['document', 'Assessment Report'],
        ];
        $evidenceRows = [];
        foreach ($employees as $emp) {
            $count = rand(2, 4);
            for ($e = 0; $e < $count; $e++) {
                $tpl = $evidenceTemplates[($emp->id + $e) % count($evidenceTemplates)];
                $evidenceRows[] = [
                    'sub_institute_id' => $sid,
                    'user_id'          => $emp->id,
                    'competency_id'    => $competencyIds[array_rand($competencyIds)],
                    'title'            => $tpl[1],
                    'evidence_type'    => $tpl[0],
                    'description'      => 'Supporting evidence for competency assessment.',
                    'link'             => null,
                    'status'           => $e % 3 === 0 ? 'pending' : 'verified',
                    'created_by'       => 1,
                    'updated_by'       => 1,
                    'created_at'       => (clone $now)->subDays(rand(5, 180)),
                    'updated_at'       => $now,
                ];
            }
        }
        if ($evidenceRows) {
            DB::table('s_competency_evidence')->insert($evidenceRows);
        }

        // --- 12. Role progression ladders (shared source for 13 + 14) --------
        // This tenant has NO job_level and NO sequence_order (all NULL), and
        // related_jobrole holds lateral peers rather than a ladder. The real
        // seniority signal is the role NAME, which this catalog encodes
        // consistently (Associate -> Senior -> Manager -> Partner/Director).
        $ladders = $this->buildLadders($sid);

        // --- 13. Career progression graph (career_journey) -------------------
        // career_journey is a SHARED table read by CareerJourneyController and
        // the old frontend's Succession screen, so this only ever populates a
        // tenant that has none - it must never overwrite a hand-configured
        // progression graph.
        $existingEdges = DB::table('career_journey')->where('sub_institute_id', $sid)->count();

        if ($existingEdges > 0) {
            $this->command?->warn(
                "CompetencySeeder: career_journey already has {$existingEdges} row(s) for sub_institute_id={$sid}; left untouched."
            );
        } else {
            $edgeRows = [];
            $seenEdges = [];
            $addEdge = function ($from, $to, $vertical) use ($sid, $now, &$edgeRows, &$seenEdges) {
                if (!$from || !$to || $from === $to) {
                    return;
                }
                $key = $from . ':' . $to;
                if (isset($seenEdges[$key])) {
                    return;
                }
                $seenEdges[$key] = true;
                $edgeRows[] = [
                    'jobrole_id'                => $from,
                    'to_jobrole_id'             => $to,
                    'vertical_lateral_movement' => $vertical ? 1 : 0,
                    'sub_institute_id'          => $sid,
                    'created_at'                => $now,
                ];
            };

            foreach ($ladders as $ladder) {
                $steps = array_values($ladder['steps']);

                // Vertical: each rung to the next one up.
                for ($i = 0; $i < count($steps) - 1; $i++) {
                    $addEdge($steps[$i]->id, $steps[$i + 1]->id, true);
                }

                // Lateral: same-rung peers in the department, plus any
                // related_jobrole that resolves to a real role in this tenant.
                foreach ($steps as $step) {
                    foreach (array_slice($ladder['peers'][$step->id] ?? [], 0, 2) as $peerId) {
                        $addEdge($step->id, $peerId, false);
                    }
                }
            }

            if ($edgeRows) {
                foreach (array_chunk($edgeRows, 500) as $chunk) {
                    DB::table('career_journey')->insert($chunk);
                }
            }
            $this->command?->info('CompetencySeeder: seeded ' . count($edgeRows) . ' career_journey edges.');
        }

        // --- 14. Named career paths (+ ordered steps) ------------------------
        // The 12 deepest ladders become published paths the workspace can link
        // development plans to.
        $deepest = $ladders;
        usort($deepest, fn ($a, $b) => count($b['steps']) <=> count($a['steps']));
        $deepest = array_slice($deepest, 0, 12);

        $pathIdByRole = [];   // jobrole name => career_path_id, for linking plans
        foreach ($deepest as $ladder) {
            $steps = array_values($ladder['steps']);
            $pathId = DB::table('s_competency_career_paths')->insertGetId([
                'sub_institute_id' => $sid,
                'name'             => $ladder['department'] . ' Career Path',
                'description'      => 'Progression ladder for the ' . $ladder['department'] . ' function.',
                'department_id'    => $ladder['department_id'],
                'department'       => $ladder['department'],
                'job_family'       => null,
                'status'           => 'active',
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(rand(5, 90)),
                'updated_at'       => $now,
            ]);

            $stepRows = [];
            foreach ($steps as $order => $step) {
                $pathIdByRole[$step->jobrole] = $pathId;
                $stepRows[] = [
                    'sub_institute_id' => $sid,
                    'career_path_id'   => $pathId,
                    'jobrole_id'       => $step->id,
                    'jobrole'          => $step->jobrole,
                    'job_level'        => $step->job_level,
                    'step_order'       => $order,
                    'step_type'        => $order === 0 ? 'current' : ($order === 1 ? 'next' : 'future'),
                    'description'      => null,
                    'created_by'       => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            DB::table('s_competency_career_path_steps')->insert($stepRows);
        }

        // --- 15. Plan objective / focus areas / linked path ------------------
        // Focus areas reference the competencies the plan's job role actually
        // requires (s_user_skill_jobrole), so the Gaps tab's "Focus" badges land
        // on real rows instead of invented labels.
        $plans = DB::table('s_competency_development_plans')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->get(['id', 'title', 'jobrole', 'status', 'progress']);

        $planJobroles = $plans->pluck('jobrole')->filter()->unique()->values()->all();
        $skillsByRole = [];
        if ($planJobroles) {
            $skillsByRole = DB::table('s_user_skill_jobrole')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->whereIn('jobrole', $planJobroles)
                ->get(['jobrole', 'skill'])
                ->groupBy('jobrole')
                ->map(fn ($group) => $group->pluck('skill')->unique()->values()->all())
                ->all();
        }

        foreach ($plans as $plan) {
            $skills = $skillsByRole[$plan->jobrole] ?? [];
            $focus = array_slice($skills, 0, rand(2, 4));

            DB::table('s_competency_development_plans')->where('id', $plan->id)->update([
                'objective'      => 'Close the competency gaps required for the '
                                    . ($plan->jobrole ?: 'target') . ' role and build readiness for the next step on the career path.',
                'focus_areas'    => $focus ? implode(',', $focus) : null,
                'career_path_id' => $pathIdByRole[$plan->jobrole] ?? null,
                'updated_at'     => $now,
            ]);
        }

        // --- 16. Plan action items -------------------------------------------
        // ~60% of plans get a real work list. The completed count is derived
        // from the plan's seeded progress and the progress is then written back
        // from the actions, so the two can never disagree in the UI.
        $actionTemplates = [
            ['project',   'Lead a cross-functional project'],
            ['training',  'Complete the required certification course'],
            ['mentoring', 'Fortnightly mentoring sessions with the plan owner'],
            ['reading',   'Work through the recommended reading list'],
            ['milestone', 'Present findings to the leadership team'],
            ['milestone', 'Shadow a senior colleague for one delivery cycle'],
        ];

        $actionRows = [];
        $progressUpdates = [];
        foreach ($plans as $index => $plan) {
            if ($index % 5 >= 3) {
                continue;   // leave ~40% of plans with no actions
            }

            $total = rand(3, 5);
            $done = (int) round(($plan->progress / 100) * $total);
            $done = max(0, min($done, $total));

            for ($a = 0; $a < $total; $a++) {
                $tpl = $actionTemplates[($plan->id + $a) % count($actionTemplates)];
                $isDone = $a < $done;

                $actionRows[] = [
                    'sub_institute_id' => $sid,
                    'plan_id'          => $plan->id,
                    'title'            => $tpl[1],
                    'description'      => null,
                    'action_type'      => $tpl[0],
                    'status'           => $isDone ? 'completed' : ($a === $done ? 'in_progress' : 'pending'),
                    'competency_id'    => $competencyIds[array_rand($competencyIds)],
                    'owner_id'         => null,
                    'due_date'         => (clone $now)->addDays(($a + 1) * rand(10, 25))->toDateString(),
                    'completed_at'     => $isDone ? (clone $now)->subDays(rand(1, 40)) : null,
                    'sequence'         => $a,
                    'created_by'       => 1,
                    'updated_by'       => 1,
                    'created_at'       => (clone $now)->subDays(rand(5, 100)),
                    'updated_at'       => $now,
                ];
            }

            $progressUpdates[(int) round($done / $total * 100)][] = $plan->id;
        }

        if ($actionRows) {
            foreach (array_chunk($actionRows, 500) as $chunk) {
                DB::table('s_competency_plan_actions')->insert($chunk);
            }
            // One UPDATE per distinct progress value rather than per plan.
            foreach ($progressUpdates as $progress => $ids) {
                DB::table('s_competency_development_plans')
                    ->whereIn('id', $ids)->update(['progress' => $progress, 'updated_at' => $now]);
            }
        }

        // --- 17. Learning assignments (lms_assignments, source='competency') --
        $courses = DB::table('sub_std_map')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->where('status', 1)
            ->whereNotNull('display_name')->where('display_name', '!=', '')
            ->limit(40)->get(['id', 'display_name']);

        if ($courses->isEmpty() || $employees->isEmpty()) {
            $this->command?->warn('CompetencySeeder: no courses or employees for sub_institute_id=' . $sid . '; learning assignments skipped.');
        } else {
            $planList = $plans->values();
            $learningRows = [];

            for ($i = 0; $i < 48; $i++) {
                $course = $courses[$i % $courses->count()];
                $emp = $pickEmp();
                if (!$emp) {
                    continue;
                }

                // ~30% completed, ~30% in progress, ~30% not started, ~10% overdue
                $r = $i % 10;
                if ($r < 3) {
                    $status = 'Completed';
                    $progress = 100;
                    $due = (clone $now)->subDays(rand(5, 60))->toDateString();
                } elseif ($r < 6) {
                    $status = 'In Progress';
                    $progress = rand(15, 85);
                    $due = (clone $now)->addDays(rand(5, 60))->toDateString();
                } elseif ($r < 9) {
                    $status = 'Not Started';
                    $progress = 0;
                    $due = (clone $now)->addDays(rand(10, 90))->toDateString();
                } else {
                    $status = 'Overdue';
                    $progress = rand(5, 55);
                    $due = (clone $now)->subDays(rand(3, 30))->toDateString();
                }

                // ~2 in 3 assignments hang off a development plan.
                $plan = ($i % 3 !== 0 && $planList->isNotEmpty()) ? $planList[$i % $planList->count()] : null;

                $learningRows[] = [
                    'sub_institute_id'    => $sid,
                    'user_id'             => $emp->id,
                    'course_id'           => $course->id,
                    'assignment_type'     => $i % 4 === 0 ? 'Recommended' : ($i % 7 === 0 ? 'Optional' : 'Mandatory'),
                    'status'              => $status,
                    'progress'            => $progress,
                    'approval_status'     => 'approved',
                    'due_date'            => $due,
                    'assigned_by'         => 'Admin User',
                    'assigned_by_id'      => 1,
                    'assigned_on'         => (clone $now)->subDays(rand(5, 120)),
                    'development_plan_id' => $plan->id ?? null,
                    'competency_id'       => $competencyIds[array_rand($competencyIds)],
                    'source'              => 'competency',
                    'created_at'          => (clone $now)->subDays(rand(5, 120)),
                    'updated_at'          => $now,
                ];
            }

            if ($learningRows) {
                DB::table('lms_assignments')->insert($learningRows);
            }
        }

        // --- 18. Employee profile notes --------------------------------------
        // One private note per employee (the Employee Profile's Notes tab, and
        // another comment source for the Audit Center). Cleared and rewritten
        // for this tenant only; the table is one row per tenant+employee.
        DB::table('s_competency_employee_notes')->where('sub_institute_id', $sid)->delete();

        $profileNotes = [
            'Strong on the technical cluster; development focus is stakeholder communication.',
            'Ready for a stretch assignment once the current plan closes.',
            'Discussed career path in the last 1:1 - keen on the specialist track.',
            'Needs support scheduling the recertification before the expiry window.',
        ];

        $noteRows = [];
        foreach ($employees as $i => $emp) {
            $noteRows[] = [
                'sub_institute_id' => $sid,
                'user_id'          => $emp->id,
                'note'             => $profileNotes[$i % count($profileNotes)],
                'created_by'       => 1,
                'updated_by'       => 1,
                'created_at'       => (clone $now)->subDays(rand(3, 120)),
                'updated_at'       => $now,
            ];
        }
        if ($noteRows) {
            DB::table('s_competency_employee_notes')->insert($noteRows);
        }

        // --- 19. Activity feed backfill (Audit & Activity Center) -------------
        $activityCount = $this->backfillActivityLog($sid, $now, $employees);

        $this->command?->info("CompetencySeeder: seeded competency module for sub_institute_id={$sid} ({$activityCount} activity entries).");
    }

    /**
     * Rebuild the competency activity feed from the records that actually exist.
     *
     * The feed table was added after most of the module's data, so the history
     * of the frameworks, assessments, certifications, plans, actions, evidence,
     * career paths, learning assignments and role mappings already in the
     * database was never captured - leaving the Audit & Activity Center with a
     * dozen rows to show. Every entry written here is derived from a real row:
     * its id, its name and its own created_at / updated_at timestamps. Nothing
     * is invented, so each line in the audit table opens a record that exists.
     *
     * Runs last (all sections above have committed) and only ever writes for
     * $sid, whose feed rows run() already cleared.
     *
     * @param  \Illuminate\Support\Collection $employees actors to attribute entries to
     */
    private function backfillActivityLog(int $sid, Carbon $now, $employees): int
    {
        if ($employees->isEmpty()) {
            return 0;
        }

        $actors = $employees->values();
        $actorCount = $actors->count();
        $index = 0;

        // Round-robin the actors so the User Actions Log has several people in
        // it, deterministically rather than at random.
        $nextActor = function () use ($actors, $actorCount, &$index) {
            $actor = $actors[$index % $actorCount];
            $index++;

            $name = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));

            return [
                'id'   => $actor->id ?? null,
                'name' => $name !== '' ? $name : ('User ' . ($actor->id ?? '?')),
            ];
        };

        $rows = [];
        $add = function (
            string $action,
            string $description,
            ?string $subjectType,
            $subjectId,
            ?string $subjectName,
            $at,
            ?array $changes = null
        ) use (&$rows, $sid, $nextActor, $now) {
            $actor = $nextActor();
            $stamp = $at ? Carbon::parse($at) : (clone $now);

            $rows[] = [
                'sub_institute_id' => $sid,
                'user_id'          => $actor['id'],
                'actor_name'       => $actor['name'],
                'action'           => $action,
                'description'      => $description,
                'subject_type'     => $subjectType,
                'subject_id'       => $subjectId ? (int) $subjectId : null,
                'subject_name'     => $subjectName !== null ? mb_substr($subjectName, 0, 191) : null,
                'changes'          => $changes ? json_encode($changes) : null,
                'created_at'       => $stamp,
                'updated_at'       => $stamp,
            ];
        };

        // 1. Frameworks: created, and published for the ones that went active.
        foreach (DB::table('s_competency_frameworks')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $fw) {
            $add('created_framework', 'Created framework "' . $fw->name . '"', 'framework', $fw->id, $fw->name, $fw->created_at);

            if ($fw->status === 'active') {
                $add(
                    'published_framework',
                    'Published framework "' . $fw->name . '"',
                    'framework',
                    $fw->id,
                    $fw->name,
                    $fw->updated_at,
                    [['field' => 'status', 'label' => 'Status', 'old' => 'draft', 'new' => 'active']]
                );
            }
        }

        // 2. Assessment cycles + the assessments inside them.
        foreach (DB::table('s_competency_assessment_cycles')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $cycle) {
            $add('created_assessment_cycle', 'Created assessment campaign "' . $cycle->name . '"', 'assessment_cycle', $cycle->id, $cycle->name, $cycle->created_at);

            if ($cycle->status === 'closed') {
                $add(
                    'completed_assessment_cycle',
                    'Closed assessment campaign "' . $cycle->name . '"',
                    'assessment_cycle',
                    $cycle->id,
                    $cycle->name,
                    $cycle->updated_at,
                    [['field' => 'status', 'label' => 'Status', 'old' => 'active', 'new' => 'closed']]
                );
            }
        }

        foreach (DB::table('s_competency_assessments')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $assessment) {
            $add('launched_assessment', 'Launched assessment "' . $assessment->title . '"', 'assessment', $assessment->id, $assessment->title, $assessment->created_at);

            if ($assessment->status === 'completed') {
                $add(
                    $assessment->review_status === 'reviewed' ? 'approved_assessment' : 'completed_assessment',
                    ($assessment->review_status === 'reviewed' ? 'Approved' : 'Completed') . ' assessment "' . $assessment->title . '"',
                    'assessment',
                    $assessment->id,
                    $assessment->title,
                    $assessment->updated_at,
                    [['field' => 'status', 'label' => 'Status', 'old' => 'in_progress', 'new' => 'completed']]
                );
            }
        }

        // 3. Certifications: recorded, plus verify / expiry state changes.
        foreach (DB::table('s_competency_certifications')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $cert) {
            $add('added_certification', 'Added certification "' . $cert->name . '"', 'certification', $cert->id, $cert->name, $cert->created_at);

            if ($cert->verification_status === 'verified') {
                $add(
                    'verified_certification',
                    'Verified certification "' . $cert->name . '"',
                    'certification',
                    $cert->id,
                    $cert->name,
                    $cert->verified_at ?: $cert->updated_at,
                    [['field' => 'verification_status', 'label' => 'Verification Status', 'old' => 'pending', 'new' => 'verified']]
                );
            } elseif ($cert->verification_status === 'rejected') {
                $add(
                    'rejected_certification',
                    'Rejected certification "' . $cert->name . '"',
                    'certification',
                    $cert->id,
                    $cert->name,
                    $cert->updated_at,
                    [['field' => 'verification_status', 'label' => 'Verification Status', 'old' => 'pending', 'new' => 'rejected']]
                );
            }

            if ($cert->status === 'revoked') {
                $add(
                    'updated_certification',
                    'Revoked certification "' . $cert->name . '"',
                    'certification',
                    $cert->id,
                    $cert->name,
                    $cert->updated_at,
                    [['field' => 'status', 'label' => 'Status', 'old' => 'valid', 'new' => 'revoked']]
                );
            }

            // A reviewer note on the credential is a comment on that record.
            if (!empty($cert->notes)) {
                // Strip the "[date - author] " prefix addNote() writes so the
                // feed shows the remark itself.
                $note = preg_replace('/^\[[^\]]*\]\s*/', '', (string) $cert->notes);

                $add('commented_certification', $note, 'certification', $cert->id, $cert->name, $cert->updated_at);
            }
        }

        foreach (DB::table('s_competency_certification_requirements')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $req) {
            $add('added_certification_requirement', 'Added certification requirement "' . $req->name . '"', 'certification_requirement', $req->id, $req->name, $req->created_at);
        }

        // 4. Development plans and their action items.
        $plans = DB::table('s_competency_development_plans')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get()->keyBy('id');

        foreach ($plans as $plan) {
            $add('created_development_plan', 'Created development plan "' . $plan->title . '"', 'development_plan', $plan->id, $plan->title, $plan->created_at);

            if ($plan->approval_status === 'pending_approval') {
                $add('submitted_development_plan', 'Submitted development plan "' . $plan->title . '" for approval', 'development_plan', $plan->id, $plan->title, $plan->updated_at);
            }

            if ($plan->status === 'completed') {
                $add(
                    'completed_development_plan',
                    'Completed development plan "' . $plan->title . '"',
                    'development_plan',
                    $plan->id,
                    $plan->title,
                    $plan->completed_at ?: $plan->updated_at,
                    [
                        ['field' => 'status',   'label' => 'Status',   'old' => 'active', 'new' => 'completed'],
                        ['field' => 'progress', 'label' => 'Progress', 'old' => '75',     'new' => '100'],
                    ]
                );
            }
        }

        foreach (DB::table('s_competency_plan_actions')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $action) {
            $planTitle = $plans[$action->plan_id]->title ?? ('Plan #' . $action->plan_id);

            $add('added_plan_action', 'Added action "' . $action->title . '" to plan "' . $planTitle . '"', 'development_plan', $action->plan_id, $planTitle, $action->created_at);

            if ($action->status === 'completed') {
                $add(
                    'completed_plan_action',
                    'Completed action "' . $action->title . '" on plan "' . $planTitle . '"',
                    'development_plan',
                    $action->plan_id,
                    $planTitle,
                    $action->completed_at ?: $action->updated_at,
                    [['field' => 'status', 'label' => 'Status', 'old' => 'in_progress', 'new' => 'completed']]
                );
            }
        }

        // 5. Evidence, career paths, learning assignments, mapping reviews.
        foreach (DB::table('s_competency_evidence')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $evidence) {
            $add('added_evidence', 'Added evidence "' . $evidence->title . '"', 'evidence', $evidence->id, $evidence->title, $evidence->created_at);
        }

        foreach (DB::table('s_competency_career_paths')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $path) {
            $add('created_career_path', 'Created career path "' . $path->name . '"', 'career_path', $path->id, $path->name, $path->created_at);
        }

        // Employee profile notes - the module's other first-class comment.
        $employeeNames = DB::table('tbluser')
            ->whereIn('id', DB::table('s_competency_employee_notes')->where('sub_institute_id', $sid)->pluck('user_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [
                $u->id => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ('User ' . $u->id),
            ]);

        foreach (DB::table('s_competency_employee_notes')->where('sub_institute_id', $sid)->get() as $note) {
            $add(
                'commented_employee_profile',
                $note->note,
                'employee_note',
                $note->user_id,
                $employeeNames[$note->user_id] ?? ('User ' . $note->user_id),
                $note->created_at
            );
        }

        $courseNames = DB::table('sub_std_map')->where('sub_institute_id', $sid)->pluck('display_name', 'id');

        foreach (DB::table('lms_assignments')->where('sub_institute_id', $sid)->where('source', 'competency')->whereNull('deleted_at')->get() as $assignment) {
            $courseName = $courseNames[$assignment->course_id] ?? 'course';

            $add('assigned_learning', 'Assigned "' . $courseName . '" to 1 employee(s)', 'learning_assignment', $assignment->id, $courseName, $assignment->created_at);

            if ($assignment->status === 'Completed') {
                $add(
                    'completed_learning',
                    'Completed learning assignment "' . $courseName . '"',
                    'learning_assignment',
                    $assignment->id,
                    $courseName,
                    $assignment->updated_at,
                    [
                        ['field' => 'status',   'label' => 'Status',   'old' => 'In Progress', 'new' => 'Completed'],
                        ['field' => 'progress', 'label' => 'Progress', 'old' => '60',          'new' => '100'],
                    ]
                );
            }
        }

        foreach (DB::table('s_competency_mapping_reviews')->where('sub_institute_id', $sid)->whereNull('deleted_at')->get() as $review) {
            $add('submitted_mapping_review', 'Submitted role mapping for "' . $review->jobrole . '" for review', 'mapping_review', $review->id, $review->jobrole, $review->created_at);

            if (in_array($review->status, ['approved', 'rejected'], true)) {
                $add(
                    $review->status . '_mapping_review',
                    ucfirst($review->status) . ' role mapping for "' . $review->jobrole . '"',
                    'mapping_review',
                    $review->id,
                    $review->jobrole,
                    $review->reviewed_at ?: $review->updated_at,
                    [['field' => 'status', 'label' => 'Review Status', 'old' => 'pending', 'new' => $review->status]]
                );
            }

            // The review's note is a genuine comment on that record.
            if (!empty($review->note)) {
                $add('commented_mapping_review', $review->note, 'mapping_review', $review->id, $review->jobrole, $review->updated_at);
            }
        }

        // 6. Role mappings: a sample of the tenant's real cells, so the Role
        // Mapping module is represented without writing 50k feed rows.
        $mappings = DB::table('s_user_skill_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('proficiency_level')
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'jobrole', 'skill', 'proficiency_level', 'created_at', 'updated_at']);

        foreach ($mappings as $cell) {
            $add(
                'mapped_role_competency',
                'Set "' . $cell->skill . '" to level ' . $cell->proficiency_level . ' for "' . $cell->jobrole . '"',
                'role_mapping',
                $cell->id,
                $cell->jobrole . ' - ' . $cell->skill,
                $cell->created_at ?: $cell->updated_at
            );
        }

        // 7. Competency library: the tenant's most recently added competencies.
        $competencies = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('approve_status', 'Approved')
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'title', 'created_at', 'updated_at']);

        foreach ($competencies as $competency) {
            $add('created_competency', 'Created competency "' . $competency->title . '"', 'competency', $competency->id, $competency->title, $competency->created_at);
        }

        // 8. Imports / Exports: the bulk operations that produced the catalog
        // above. One entry per batch, sized from what is actually in the table.
        $exportStamps = [7, 21, 44, 70];
        foreach ($exportStamps as $offset) {
            $add(
                'exported_certifications',
                'Exported ' . DB::table('s_competency_certifications')->where('sub_institute_id', $sid)->whereNull('deleted_at')->count() . ' certification records',
                'certification',
                null,
                'Certification Register',
                (clone $now)->subDays($offset)
            );
        }

        $add(
            'imported_competencies',
            'Imported ' . $competencies->count() . ' competencies from spreadsheet',
            'competency',
            null,
            'Competency_Library_Import.xlsx',
            (clone $now)->subDays(35)
        );

        $add(
            'imported_role_mappings',
            'Imported ' . $mappings->count() . ' role mapping rows',
            'role_mapping',
            null,
            'Role_Mapping_Matrix.csv',
            (clone $now)->subDays(18)
        );

        // Chunked: the feed can run to a few thousand rows on a full tenant.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('s_competency_activity_log')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Seniority rank implied by a job title. Ordered most-senior first and the
     * first match wins, so "Assistant Manager" is checked before "Manager" and
     * "Senior Manager" before either "Senior" or "Manager".
     */
    private function rankOf(string $name): ?int
    {
        $tiers = [
            7 => '/\b(chief|president|partner|director|vice\s*president|vp|head)\b/i',
            6 => '/\b(senior\s+manager|general\s+manager|principal)\b/i',
            4 => '/\b(assistant\s+manager|deputy|supervisor|team\s+lead|lead)\b/i',
            5 => '/\b(manager)\b/i',
            3 => '/\b(senior|specialist)\b/i',
            2 => '/\b(executive|associate|officer|analyst|engineer|consultant|coordinator|technician)\b/i',
            1 => '/\b(assistant|trainee|intern|apprentice|junior)\b/i',
        ];

        foreach ($tiers as $rank => $pattern) {
            if (preg_match($pattern, $name)) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Group the tenant's job roles into per-department seniority ladders,
     * keeping only departments that yield at least three distinct rungs.
     *
     * @return array<int, array{department:string, department_id:?int, steps:array, peers:array}>
     */
    private function buildLadders(int $sid): array
    {
        $roles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereNotNull('department')->where('department', '!=', '')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '')
            ->get(['id', 'jobrole', 'job_level', 'department', 'department_id', 'related_jobrole']);

        $roleIdByName = [];
        foreach ($roles as $role) {
            $roleIdByName[$role->jobrole] ??= (int) $role->id;
        }

        $byDepartment = [];
        foreach ($roles as $role) {
            $byDepartment[$role->department][] = $role;
        }

        $ladders = [];
        foreach ($byDepartment as $department => $departmentRoles) {
            $steps = [];
            $sameRank = [];

            foreach ($departmentRoles as $role) {
                $rank = $this->rankOf($role->jobrole);
                if ($rank === null) {
                    continue;
                }
                $sameRank[$rank][] = (int) $role->id;
                $steps[$rank] ??= $role;   // one representative role per rung
            }

            if (count($steps) < 3) {
                continue;
            }

            ksort($steps);

            // Lateral peers: same-rung colleagues, plus related_jobrole entries
            // that resolve to a real role in this tenant.
            $peers = [];
            foreach ($steps as $rank => $step) {
                $candidates = array_values(array_diff($sameRank[$rank], [(int) $step->id]));

                foreach (array_filter(array_map('trim', explode(',', (string) $step->related_jobrole))) as $name) {
                    if (isset($roleIdByName[$name]) && $roleIdByName[$name] !== (int) $step->id) {
                        $candidates[] = $roleIdByName[$name];
                    }
                }

                $peers[(int) $step->id] = array_values(array_unique($candidates));
            }

            $ladders[] = [
                'department'    => $department,
                'department_id' => $steps[array_key_first($steps)]->department_id,
                'steps'         => $steps,
                'peers'         => $peers,
            ];
        }

        return $ladders;
    }
}
