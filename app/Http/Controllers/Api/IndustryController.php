<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class IndustryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(request $request)
    {

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        
        $industries = DB::table('s_industries')      
            ->groupBy('industries')           
            ->get();  
            
        return response()->json(['data' => $industries], 200);

        
        
    }

    

    public function departments(request $request,$id)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        


        $departments = DB::table('s_industries') 
            ->select('industries','department')  // only get department name
            ->where('industries', $id)    
            ->groupBy('department')           
            ->get();  


        return response()->json(['industry_id' => $id,'industry_departments' => $departments], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
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
