<?php

namespace App\Http\Controllers\Api\signup_api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\school_setupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\tbluserprofileMasterModel;
use App\Models\user\tblgroupwise_rightsModel;
use App\Models\libraries\jobroleModel;
use App\Models\Industry;

class SchoolSetupController extends Controller
{
    public function store(Request $request)
    {
        // ✅ Validation
        $validatedData = $request->validate([
            'SchoolName' => 'required|string|max:255',
            'ShortCode' => 'nullable|string|max:50',
            'ContactPerson' => 'nullable|string|max:255',
            'Mobile' => 'nullable|string|max:20',
            'Email' => 'nullable|email|max:255',
            'syear' => 'nullable|string|max:50',
            'institute_type' => 'nullable|string|max:100',
            'sub_institute_id' => 'nullable|integer',
            'industries_type' => 'nullable|string|max:100',
        ]);

        // ✅ Auto ShortCode
        if (empty($validatedData['ShortCode'])) {
            $words = preg_split('/[\s\-_]+/', $validatedData['SchoolName']);
            $shortCode = '';

            foreach ($words as $word) {
                if (strlen($shortCode) >= 5) break;
                $shortCode .= strtoupper(substr($word, 0, 1));
            }

            if (empty($shortCode)) {
                $shortCode = strtoupper(substr($validatedData['SchoolName'], 0, 5));
            }

            $validatedData['ShortCode'] = $shortCode;
        }

        // ✅ Timestamp
        $validatedData['created_at'] = now();

        // ✅ Create Client
        $client = tblclientModel::create([
            'client_name' => $validatedData['SchoolName'],
            'created_at' => now(),
        ]);

        // ✅ Assign client_id
        $validatedData['client_id'] = $client->id;

        // ✅ Create School Setup
        $schoolSetup = school_setupModel::create($validatedData);

        // ✅ Expire Date (+1 Year)
        $schoolSetup->expire_date = now()->addYear();
        $schoolSetup->save();

        // ✅ Create Default Profiles
        $profiles = [
            ['name' => 'Admin', 'sort_order' => 1],
            ['name' => 'Employee', 'sort_order' => 2],
            ['name' => 'HR', 'sort_order' => 3],
        ];

        $profileIds = [];

        foreach ($profiles as $profile) {
            $newProfile = tbluserprofileMasterModel::create([
                'name' => $profile['name'],
                'description' => $profile['name'],
                'sort_order' => $profile['sort_order'],
                'status' => 1,
                'sub_institute_id' => $schoolSetup->id,
                'client_id' => $client->id,
            ]);

            // Store mapping (important for rights copy)
            $profileIds[$profile['name']] = $newProfile->id;
        }

        $sourceRights = tblgroupwise_rightsModel::where('sub_institute_id', 3)->get();

        if ($sourceRights->count() > 0) {

            // Get old profiles (for mapping)
            $oldProfiles = tbluserprofileMasterModel::where('sub_institute_id', 3)
                            ->pluck('id', 'name');

            $insertData = [];

            foreach ($sourceRights as $right) {

                // Map profile_id (IMPORTANT 🔥)
                $profileName = $oldProfiles->search($right->profile_id);
                $newProfileId = $profileIds[$profileName] ?? null;

                if (!$newProfileId) continue;

                $insertData[] = [
                    'menu_id' => $right->menu_id,
                    'profile_id' => $newProfileId,
                    'can_view' => $right->can_view,
                    'can_add' => $right->can_add,
                    'can_edit' => $right->can_edit,
                    'can_delete' => $right->can_delete,
                    'created_at' => now(),
                    'sub_institute_id' => $schoolSetup->id, // ✅ NEW ID
                ];
            }

            // ✅ Bulk Insert
            tblgroupwise_rightsModel::insert($insertData);
        }

        // ✅ Insert Default Job Roles for the Industry
        $industriesType = $validatedData['institute_type'];
        
        // Get authenticated user ID or use NULL if not authenticated
        $createdBy = Auth::check() ? Auth::id() : null;

        // Get job roles that match the industry type or are common
        $jobRoles = jobroleModel::where('common', 'Y')
            ->orWhere('sector', $industriesType)
            ->get();

        if ($jobRoles->count() > 0) {
            // Get industries from s_industries that match the industries_type
            $industries = Industry::where('industries', $industriesType)->get();

            $insertData = [];

            foreach ($jobRoles as $jobRole) {
                // Find matching industry for track/sector mapping
                $matchingIndustry = $industries->first(function ($ind) use ($jobRole) {
                    return $ind->sub_department === $jobRole->track && 
                           ($ind->industries === $jobRole->sector || $jobRole->common === 'Y');
                });

                // Determine department - if no matching industry, try to get from sector field
                $departmentValue = null;
                if ($matchingIndustry && isset($matchingIndustry->department)) {
                    $departmentValue = $matchingIndustry->department;
                } elseif (!empty($jobRole->sector)) {
                    // Fallback: use sector from jobrole if available
                    $departmentValue = $jobRole->sector;
                } elseif ($jobRole->common === 'Y') {
                    // For common job roles, use the industries_type as department
                    $departmentValue = $industriesType;
                }

                if ($matchingIndustry || $jobRole->common === 'Y' || !empty($jobRole->sector)) {
                    $record = [
                        'industries' => $industriesType,
                        'department' => $departmentValue,
                        'sub_department' => $matchingIndustry->sub_department ?? $jobRole->track ?? null,
                        'jobrole' => $jobRole->jobrole,
                        'description' => $jobRole->description,
                        'jobrole_category' => $jobRole->jobrole_category,
                        'related_jobrole' => $jobRole->related_jobrole,
                        'education' => $jobRole->education,
                        'experience' => $jobRole->experience,
                        'training' => $jobRole->training,
                        'performance_expectation' => $jobRole->performance_expectation,
                        'status' => 'Active',
                        'sub_institute_id' => $schoolSetup->id,
                        'created_at' => now(),
                    ];
                    
                    // Only add created_by if it has a valid value
                    if ($createdBy !== null) {
                        $record['created_by'] = $createdBy;
                    }
                    
                    $insertData[] = $record;
                }
            }

            // Bulk insert into s_user_jobrole
            if (!empty($insertData)) {
                DB::table('s_user_jobrole')->insert($insertData);
                
                // Force fresh data retrieval by clearing any query cache
                DB::connection('mysql')->reconnect();
            }
        }

        // ✅ Insert Default Skills for the Industry (similar to the SQL query provided)
        $industriesType = $validatedData['institute_type'];
        
        if (!empty($industriesType)) {
            // Get sub_departments that exist in s_user_jobrole for this school
            $existingSubDepartments = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $schoolSetup->id)
                ->whereNotNull('sub_department')
                ->distinct()
                ->pluck('sub_department');

            if ($existingSubDepartments->isNotEmpty()) {
                // Insert skills from master_skills based on the sub_departments in s_user_jobrole
                $skillInsertData = DB::table('master_skills as m')
                    ->select(
                        DB::raw("IFNULL(i.department, 'General') AS department"),
                        DB::raw("IFNULL(i.sub_department, 'General') AS sub_department"),
                        'm.category',
                        'm.sub_category',
                        'm.title',
                        'm.description',
                        'm.skill_code',
                        DB::raw("'Active' AS status"),
                        DB::raw("'Approved' AS approve_status"),
                        DB::raw($schoolSetup->id . ' AS sub_institute_id'),
                        DB::raw($createdBy ? $createdBy . ' AS created_by' : 'NULL AS created_by'),
                        DB::raw('NOW() AS created_at'),
                        'm.micro_category',
                        'm.related_skills',
                        'm.bussiness_links',
                        'm.custom_tags',
                        'm.proficiency_level',
                        'm.assesment_method',
                        'm.certification_qualifications',
                        'm.experience_project'
                    )
                    ->leftJoin('s_jobrole_skills as js', 'm.skill_code', '=', 'js.skill_code')
                    ->leftJoin('s_jobrole as j', 'j.jobrole', '=', 'js.jobrole')
                    ->leftJoin('s_industries as i', 'j.track', '=', 'i.sub_department')
                    ->whereIn('i.sub_department', $existingSubDepartments)
                    ->groupBy('m.id')
                    ->get();

                if ($skillInsertData->isNotEmpty()) {
                    // Prepare data for bulk insert
                    $skillsToInsert = [];
                    foreach ($skillInsertData as $skill) {
                        $skillRecord = [
                            'department' => $skill->department,
                            'sub_department' => $skill->sub_department,
                            'category' => $skill->category,
                            'sub_category' => $skill->sub_category,
                            'title' => $skill->title,
                            'description' => $skill->description,
                            'skill_code' => $skill->skill_code,
                            'status' => $skill->status,
                            'approve_status' => $skill->approve_status,
                            'sub_institute_id' => $schoolSetup->id,
                            'created_at' => now(),
                            'micro_category' => $skill->micro_category,
                            'related_skills' => $skill->related_skills,
                            'bussiness_links' => $skill->bussiness_links,
                            'custom_tags' => $skill->custom_tags,
                            'proficiency_level' => $skill->proficiency_level,
                            'assesment_method' => $skill->assesment_method,
                            'certification_qualifications' => $skill->certification_qualifications,
                            'experience_project' => $skill->experience_project,
                        ];
                        
                        // Only add created_by if it has a valid value
                        if ($createdBy !== null) {
                            $skillRecord['created_by'] = $createdBy;
                        }
                        
                        $skillsToInsert[] = $skillRecord;
                    }

                    // Bulk insert into s_users_skills
                    if (!empty($skillsToInsert)) {
                        // Check if skills already exist to avoid duplicates
                        $existingSkills = DB::table('s_users_skills')
                            ->where('sub_institute_id', $schoolSetup->id)
                            ->pluck('title')
                            ->toArray();

                        $filteredSkills = array_filter($skillsToInsert, function($skill) use ($existingSkills) {
                            return !in_array($skill['title'], $existingSkills);
                        });

                        if (!empty($filteredSkills)) {
                            DB::table('s_users_skills')->insert($filteredSkills);
                        }
                    }
                }
            }
        }

        // ✅ Refresh Data
        $schoolSetup->refresh();

        return response()->json([
            'success' => true,
            'message' => 'School setup created successfully',
            'data' => $schoolSetup
        ], 201);
    }
}