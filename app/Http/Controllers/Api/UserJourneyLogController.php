<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\TblUserJourneyLog;

class UserJourneyLogController extends Controller
{
    /**
     * Validate the API token
     */
    private function validateToken($request)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        return null;
    }

    /**
     * Store a newly created user journey log.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        // Validate the request data
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'sub_institute_id' => 'required|integer',
            'menu_id' => 'required|integer',
            'access_link' => 'required|string|max:255',
            'event_type' => 'required|string|in:page_visit,tour_step_view,tour_step_complete,tour_skipped',
            'step_key' => 'nullable|string|max:100',
            'timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create the user journey log
        $journeyLog = new TblUserJourneyLog();
        $journeyLog->user_id = $request->user_id;
        $journeyLog->sub_institute_id = $request->sub_institute_id;
        $journeyLog->menu_id = $request->menu_id;
        $journeyLog->access_link = $request->access_link;
        $journeyLog->event_type = $request->event_type;
        $journeyLog->step_key = $request->step_key;
        $journeyLog->timestamp = $request->timestamp ?? now();
        $journeyLog->save();

        return response()->json([
            'success' => true,
            'message' => 'User journey log created successfully',
            'data' => [
                'id' => $journeyLog->id,
                'user_id' => $journeyLog->user_id,
                'sub_institute_id' => $journeyLog->sub_institute_id,
                'menu_id' => $journeyLog->menu_id,
                'access_link' => $journeyLog->access_link,
                'event_type' => $journeyLog->event_type,
                'step_key' => $journeyLog->step_key,
                'timestamp' => $journeyLog->timestamp,
            ]
        ], 201);
    }

    /**
     * Store multiple user journey logs at once.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeBulk(Request $request)
    {
        $validationError = $this->validateToken($request);
        if ($validationError) {
            return $validationError;
        }

        // Validate the request data
        $validator = Validator::make($request->all(), [
            'logs' => 'required|array|min:1',
            'logs.*.user_id' => 'required|integer',
            'logs.*.sub_institute_id' => 'required|integer',
            'logs.*.menu_id' => 'required|integer',
            'logs.*.access_link' => 'required|string|max:255',
            'logs.*.event_type' => 'required|string|in:page_visit,tour_step_view,tour_step_complete,tour_skipped',
            'logs.*.step_key' => 'nullable|string|max:100',
            'logs.*.timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Prepare data for bulk insertion
        $currentTimestamp = now();
        $logsToInsert = [];

        foreach ($request->logs as $log) {
            $logsToInsert[] = [
                'user_id' => $log['user_id'],
                'sub_institute_id' => $log['sub_institute_id'],
                'menu_id' => $log['menu_id'],
                'access_link' => $log['access_link'],
                'event_type' => $log['event_type'],
                'step_key' => $log['step_key'] ?? null,
                'timestamp' => $log['timestamp'] ?? $currentTimestamp,
            ];
        }

        // Insert all records
        TblUserJourneyLog::insert($logsToInsert);

        return response()->json([
            'success' => true,
            'message' => 'User journey logs created successfully',
            'data' => [
                'count' => count($logsToInsert)
            ]
        ], 201);
    }
}
