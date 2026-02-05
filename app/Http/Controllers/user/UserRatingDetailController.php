<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\user\UserRatingDetail;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserRatingDetailController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        // return "hello";
        $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            // if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            // }
    
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
 public function store(Request $request)
{

    $token = $request->input('token');  // get token from input field 'token'

            // Check if token is provided
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            // If token is invalid
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            
    $validated = $request->validate([
        'user_id' => 'required|integer',
        'jobrole_id' => 'required|integer',
        'sub_institute_id' => 'nullable|integer',
      ]);

    // Add audit fields
    
    // Prepare data for upsert
    $data = [
        'user_id' => $validated['user_id'],
        'jobrole_id' => $validated['jobrole_id'],
        'sub_institute_id' => $validated['sub_institute_id'] ?? null,
    ];

  if($request->has('skill_ids') && $request->skill_ids!==null){
    $data['skill_ids'] = $request->input('skill_ids');
  }
  if($request->has('knowledge_ids') && $request->knowledge_ids!==null){
    $data['knowledge_ids'] = $request->input('knowledge_ids');
  }
  if($request->has('ability_ids') && $request->ability_ids!==null){
    $data['ability_ids'] = $request->input('ability_ids');
  }
  if($request->has('attitude_ids') && $request->attitude_ids!==null){
    $data['attitude_ids'] = $request->input('attitude_ids');
  }
  if($request->has('behavior_ids') && $request->behavior_ids!==null){
    $data['behavior_ids'] = $request->input('behavior_ids');
  }

    try {
        // Find or create
        $rating = UserRatingDetail::updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'jobrole_id' => $validated['jobrole_id'],
                'sub_institute_id' => $validated['sub_institute_id'] ?? null
            ],
            $data
        );

        // If this is a new record, set created_by
        if (!$rating->wasRecentlyCreated) {
            $rating->updated_by = $validated['user_id'];
            $rating->save();
        }else{
            $rating->created_by = $validated['user_id'];
            $rating->save();
        }

        return response()->json([
            'message' => $rating->wasRecentlyCreated 
                ? 'User rating created successfully' 
                : 'User rating updated successfully',
            'data' => $rating,
            'status' => 'success'
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to save rating',
            'error' => $e->getMessage(),
            'status' => 'error'
        ], 500);
    }
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
