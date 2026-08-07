<?php

namespace App\Http\Controllers\talent\interview_panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\talent\interview_panel\TalentInterviewPanel;   
use App\Models\talent\talent_interviewschedules;              

class talent_interviewpanelController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * The caller's organisation, resolved from the TOKEN, never from the request.
     *
     * This controller serves both the token-authenticated API and the
     * session-authenticated Blade screens, so the token is tried first and the
     * session is the fallback - the same shape as payrollTenantId (D-004).
     *
     * G-SEC-11 / the C27 class. Every `if ($type == "API")` branch below used to
     * validate that a token EXISTED and then take sub_institute_id from the
     * request body, which is the G-SEC-09 defect: the token's owner was
     * discarded. Confirmed by execution, not inference - the C23 guard recorded
     * `api/interview-panel/list` returning another tenant's data when
     * impersonating.
     *
     * Interview panel records cover CANDIDATES - people outside the company who
     * never agreed to be in the system - which is why this was fixed first by
     * data class rather than by route count.
     */
    private function panelTenantId(Request $request): ?int
    {
        $fromToken = $this->apiTenantId($request);
        if ($fromToken) {
            return $fromToken;
        }

        $fromSession = $request->session()->get('sub_institute_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }


    
public function getInterviewPanel(Request $request)
{
    $type = $request->type;
    $sub_institute_id = session()->get('sub_institute_id');

    if ($type == "API") {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $this->panelTenantId($request);
    }

    // 👉 Fetch Panel + Interview Date & Time
    $panels = TalentInterviewPanel::with([
        'schedules:id,panel_id,interview_date,time'
    ])
    ->where('sub_institute_id', $sub_institute_id)
    ->get();

    return response()->json([
        'status' => true,
        'data' => $panels
    ]);
}

    public function getInterviewers(Request $request)
    {
         $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        if ($type == "API") {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $sub_institute_id = $this->panelTenantId($request);
        }
        $users = DB::table('tbluser')
                ->leftJoin('s_user_jobrole', 'tbluser.allocated_standards', '=', 's_user_jobrole.id')
                ->leftJoin('s_users_skills', 's_user_jobrole.department_id', '=', 's_users_skills.department_id')
                ->select(
                    'tbluser.id',
                    DB::raw("CONCAT(tbluser.first_name, ' ', tbluser.last_name) as name"),
                    's_user_jobrole.jobrole as job_role',
                    's_users_skills.title as skill'
                )
                ->where('tbluser.status', 1)
                ->where('tbluser.sub_institute_id', $this->apiTenantId($request))
                ->get()
                ->groupBy('id')
                ->map(function ($group) {
                    $user = $group->first();
                    $user->skills = $group->pluck('skill')->filter()->values()->take(3)->toArray();
                    unset($user->skill);
                    return $user;
                })
                ->values();

        return response()->json([
            'status' => true,
            'data' => $users
        ]);
    }

    public function storeinterviewer(Request $request)
{
    $type = $request->type;
    $sub_institute_id = session()->get('sub_institute_id');

    if ($type == "API") {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $this->panelTenantId($request);
        $user_id = $accessToken->tokenable_id;
    } else {
        $user_id = auth()->id();
    }

    $request->validate([
        'sub_institute_id'       => 'required|integer',
        'panel_name'             => 'required|string',
        'target_positions'       => 'nullable|string',
        'description'            => 'nullable|string',
        'available_interviewers' => 'nullable',
        'status'                 => 'nullable|string|in:available,assigned,inactive,active'
    ]);

    // 👉 Convert available_interviewers to JSON array
    $availableInterviewers = null;

    if (is_array($request->available_interviewers)) {
        $availableInterviewers = json_encode($request->available_interviewers);
    } elseif (is_string($request->available_interviewers)) {
        $availableInterviewers = json_encode(
            array_map('trim', explode(',', $request->available_interviewers))
        );
    }
    $target_positions = $request->target_positions;
    $panel = TalentInterviewPanel::create([
        'sub_institute_id'       => $this->apiTenantId($request),
        'panel_name'             => $request->panel_name,
        'target_positions'       => $target_positions,
        'description'            => $request->description,
        'available_interviewers' => $availableInterviewers,
        'status'                 => $request->status ?? 'available'
    ]);

    return response()->json([
        'message' => 'Interview Panel Created Successfully',
        'data'    => $panel
    ], 201);
}


   public function update(Request $request, $id)
{
    $type = $request->type;

    if ($type == "API") {
        $token = $request->input('token');
        if (!$token) return response()->json(['message' => 'Token not provided'], 401);

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) return response()->json(['message' => 'Invalid token'], 401);

        $sub_institute_id = $this->panelTenantId($request);
    }

    $panel = TalentInterviewPanel::find($id);
    if (!$panel) {
        return response()->json(['message' => 'Interview Panel Not Found'], 404);
    }

    // Validation
    $request->validate([
        'sub_institute_id'       => 'nullable|integer',
        'panel_name'             => 'nullable|string',
        'target_positions'       => 'nullable|string',
        'description'            => 'nullable|string',
        'available_interviewers' => 'nullable',
        'status'                 => 'nullable|string|in:available,assigned,inactive,active'
    ]);

    // Convert available_interviewers
    $availableInterviewers = null;
    if (is_array($request->available_interviewers)) {
        $availableInterviewers = json_encode($request->available_interviewers);
    } elseif (is_string($request->available_interviewers)) {
        $availableInterviewers = json_encode(
            array_map('trim', explode(',', $request->available_interviewers))
        );
    }

    // Update DB record
    $panel->update([
        'panel_name'             => $request->panel_name,
        'target_positions'       => $request->target_positions,
        'description'            => $request->description,
        'available_interviewers' => $availableInterviewers,
        'status'                 => $request->status ?? 'available',
        'updated_at'             => now()
    ]);

    return response()->json([
        'message' => 'Interview Panel Updated Successfully',
        'data'    => $panel
    ], 200);
}


    
public function destroy(Request $request, $id)
{
    $type = $request->type;
    $sub_institute_id = session()->get('sub_institute_id');
    if ($type == "API") {
        $token = $request->input('token');  // get token from input field 'token'

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        $sub_institute_id = $this->panelTenantId($request);
        $user_id = $accessToken->tokenable_id;
    } else {
        $user_id = auth()->id();
    }
    $panel = TalentInterviewPanel::find($id);

    if (!$panel) {
        return response()->json(['message' => 'Interview Panel Not Found'], 404);
    }

    $panel->update(['deleted_by' => $user_id]);
    $panel->delete();

    return response()->json([
        'message' => 'Interview Panel Deleted Successfully'
    ], 200);
}

    
}
