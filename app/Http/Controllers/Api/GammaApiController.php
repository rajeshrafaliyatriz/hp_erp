<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GammaApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class GammaApiController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $gammaApis = GammaApi::all();

        return response()->json(['data' => $gammaApis], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $request->validate([
            'account' => 'required|string',
            'key' => 'required|string',
            'status' => 'required|integer',
            'limit' => 'required|integer',
            'sub_institute_id' => 'required|integer',
        ]);

        $gammaApi = GammaApi::create($request->only(['account', 'key', 'status', 'limit', 'sub_institute_id']));

        return response()->json(['data' => $gammaApi], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $gammaApi = GammaApi::find($id);

        if (!$gammaApi) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $gammaApi], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $gammaApi = GammaApi::find($id);

        if (!$gammaApi) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'account' => 'sometimes|required|string',
            'key' => 'sometimes|required|string',
            'status' => 'sometimes|required|integer',
            'limit' => 'sometimes|required|integer',
            'sub_institute_id' => 'sometimes|required|integer',
        ]);

        $gammaApi->update($request->only(['account', 'key', 'status', 'limit', 'sub_institute_id']));

        return response()->json(['data' => $gammaApi], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $gammaApi = GammaApi::find($id);

        if (!$gammaApi) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $gammaApi->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }

    /**
     * Get records by sub_institute_id.
     */
    public function getBySubInstituteId(Request $request, string $subInstituteId)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $gammaApis = GammaApi::where('sub_institute_id', $subInstituteId)->get();

        return response()->json(['data' => $gammaApis], 200);
    }
}