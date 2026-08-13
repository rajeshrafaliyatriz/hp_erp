<?php

namespace App\Http\Controllers\Api\signup_api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\auth\tbluserModel;
use App\Models\auth\academicSectionModel;

class UserSignupController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

    /**
     * Store a newly created user and academic year.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // ✅ Get all input data
        $data = $request->all();
        
        // ✅ Convert to proper types
        $user_name = trim($data['user_name'] ?? '');
        $password = $data['password'] ?? '';
        $first_name = trim($data['first_name'] ?? '');
        $last_name = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $mobile = trim($data['mobile'] ?? '');
        $user_profile_id = intval($data['user_profile_id'] ?? 0);
        $sub_institute_id = intval($data['sub_institute_id'] ?? 0);
        // $client_id = intval($data['client_id'] ?? 0); // Removed - not in tbluser table
        $is_admin = intval($data['is_admin'] ?? 0);
        $status = intval($data['status'] ?? 1);
        $allocated_standard = $data['allocated_standards'] ?? null;
        $department_id = intval($data['department_id'] ?? 0);
        $employee_id = !empty($data['employee_id']) ? intval($data['employee_id']) : null;
        $syear = trim($data['syear'] ?? '');

        // ✅ Basic validation
        if (empty($user_name) || empty($password) || empty($first_name) || empty($email) || empty($user_profile_id) || empty($sub_institute_id) || empty($syear)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required fields'
            ], 422);
        }

        try {
            // ✅ Insert using DB directly
            $userId = DB::table('tbluser')->insertGetId([
                'user_name' => $user_name,
                'password' => Hash::make($password),
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'mobile' => $mobile,
                'user_profile_id' => $user_profile_id,
                'sub_institute_id' => $sub_institute_id,
                // 'client_id' => $client_id, // Removed - not in tbluser table
                'is_admin' => $is_admin,
                'status' => $status,
                'allocated_standards' => $allocated_standard,
                'department_id' => $department_id > 0 ? $department_id : null,
                'employee_id' => $employee_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ✅ Insert academic year
            DB::table('academic_year')->insert([
                'syear' => $syear,
                'sub_institute_id' => $sub_institute_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User and academic year created successfully',
                'data' => [
                    'user_id' => $userId,
                    'user_name' => $user_name,
                    'email' => $email,
                    'syear' => $syear
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * G-SEC-23, CHAIN B — AUTHENTICATED AND THEN NOT TENANT-SCOPED.
     *
     * This route carries `api.token` (routes/api.php:967), so a reviewer
     * skimming the route file would call it guarded - and it IS authenticated.
     * It simply never asked which organisation the caller belongs to: the method
     * did not even receive the Request, and `find($id)` returned any user in the
     * database. An Employee in tenant 7 read tenant 3's full user record,
     * 4,224 bytes with profile, organization and client relations attached.
     *
     * THE FIX IS NOT "ADD AUTH". It is the missing layer of G-SEC-09, in a place
     * that looks protected.
     */
    public function show(Request $request, $id)
    {
        try {
            $subInstituteId = $this->apiTenantId($request);

            if (!$subInstituteId) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $user = tbluserModel::with('userProfile', 'organization', 'client', 'yearData')
                ->where('id', $id)
                ->where('sub_institute_id', $subInstituteId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = tbluserModel::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // ✅ Validation for tbluser update
        $validatedData = $request->validate([
            // tbluser fields
            'user_name' => 'sometimes|string|max:255|unique:tbluser,user_name,' . $id,
            'password' => 'sometimes|string|min:6',
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|max:255|unique:tbluser,email,' . $id,
            'mobile' => 'nullable|string|max:20',
            'user_profile_id' => 'sometimes|integer',
            'sub_institute_id' => 'sometimes|integer',
            // 'client_id' => 'sometimes|integer', // Removed - not in tbluser table
            'is_admin' => 'nullable|integer|in:0,1',
            'status' => 'nullable|integer|in:0,1',
            'allocated_standard' => 'nullable|string|max:255',
            'department_id' => 'nullable|integer',
            'employee_id' => 'nullable|string|max:255',
            
            // academic_year fields
            'syear' => 'sometimes|string|max:50',
        ]);

        try {
            DB::beginTransaction();

            // ✅ Update User
            $userData = [
                'first_name' => $validatedData['first_name'] ?? $user->first_name,
                'last_name' => $validatedData['last_name'] ?? $user->last_name,
                'mobile' => $validatedData['mobile'] ?? $user->mobile,
                'user_profile_id' => $validatedData['user_profile_id'] ?? $user->user_profile_id,
                'sub_institute_id' => $validatedData['sub_institute_id'] ?? $user->sub_institute_id,
                // 'client_id' => $validatedData['client_id'] ?? $user->client_id, // Removed - not in tbluser table
                'is_admin' => $validatedData['is_admin'] ?? $user->is_admin,
                'status' => $validatedData['status'] ?? $user->status,
                'allocated_standard' => $validatedData['allocated_standard'] ?? $user->allocated_standard,
                'department_id' => $validatedData['department_id'] ?? $user->department_id,
                'employee_id' => $validatedData['employee_id'] ?? $user->employee_id,
            ];

            // Only update user_name if provided
            if (isset($validatedData['user_name'])) {
                $userData['user_name'] = $validatedData['user_name'];
            }

            // Only update email if provided
            if (isset($validatedData['email'])) {
                $userData['email'] = $validatedData['email'];
            }

            // Only update password if provided
            if (isset($validatedData['password'])) {
                $userData['password'] = Hash::make($validatedData['password']);
            }

            $user->update($userData);

            // ✅ Update Academic Year
            if (isset($validatedData['syear'])) {
                academicSectionModel::updateOrCreate(
                    ['sub_institute_id' => $user->sub_institute_id],
                    ['syear' => $validatedData['syear']]
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User and academic year updated successfully',
                'data' => $user->fresh()
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = tbluserModel::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // ✅ Delete Academic Year
            academicSectionModel::where('sub_institute_id', $user->sub_institute_id)->delete();

            // ✅ Delete User
            $user->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User and academic year deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
