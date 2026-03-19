<?php

namespace App\Http\Controllers\Api\signup_api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\school_setupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\tbluserprofileMasterModel;
use App\Models\user\tblgroupwise_rightsModel;

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

        // ✅ Refresh Data
        $schoolSetup->refresh();

        return response()->json([
            'success' => true,
            'message' => 'School setup created successfully',
            'data' => $schoolSetup
        ], 201);
    }
}