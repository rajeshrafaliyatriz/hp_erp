<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CareerJourneyController extends Controller
{
    public function getCareerJourney(Request $request)
    {
        $userId = $request->user_id;
        $subInstituteId = $request->sub_institute_id;

        if (!$userId || !$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id and sub_institute_id are required',
            ], 400);
        }

        // 1. Get user
        $user = DB::table('tbluser')
            ->select('id', 'allocated_standards', 'sub_institute_id')
            ->where('id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ]);
        }

        $currentRoleId = $this->resolveCurrentJobRoleId($user->allocated_standards, $subInstituteId);

        if (!$currentRoleId) {
            return response()->json([
                'status' => false,
                'message' => 'Current jobrole could not be resolved for this user',
                'data' => []
            ], 404);
        }

        $currentJobRole = DB::table('s_user_jobrole')
            ->where('id', $currentRoleId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$currentJobRole) {
            return response()->json([
                'status' => false,
                'message' => 'Current jobrole record not found',
                'data' => []
            ], 404);
        }

        $result = [];
        $visited = []; // avoid infinite loop
        [$progressionTable, $progressionMap] = $this->resolveProgressionSchema();

        if (!$progressionTable) {
            return response()->json([
                'status' => false,
                'message' => 'Career progression table not found',
                'data' => []
            ], 500);
        }

        // 2. Start from the resolved current role and walk the progression chain
        while ($currentRoleId) {
            // prevent loop
            if (in_array($currentRoleId, $visited)) break;
            $visited[] = $currentRoleId;

            // get next journey step
            $journey = DB::table($progressionTable . ' as cj')
                ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.' . $progressionMap['to'])
                ->where('cj.sub_institute_id', $subInstituteId)
                ->where('cj.' . $progressionMap['from'], $currentRoleId)
                ->orderByRaw(
                    $progressionTable === 'role_progressions'
                        ? "CASE WHEN cj.type = 'vertical' THEN 0 ELSE 1 END"
                        : "CASE WHEN cj.vertical_lateral_movement = 1 THEN 0 ELSE 1 END"
                )
                ->orderBy('jr.sequence_order')
                ->orderBy('cj.id')
                ->first();

            if (!$journey) break;

            $result[] = [
                'id' => $journey->id,
                'from_jobrole_id' => $journey->{$progressionMap['from']},
                'to_jobrole_id' => $journey->{$progressionMap['to']},
                'role_name' => $journey->jobrole,
                'job_level' => $journey->job_level,
                'movement_type' => $progressionTable === 'role_progressions'
                    ? $journey->type
                    : ($journey->vertical_lateral_movement == 1 ? 'vertical' : 'lateral'),
                'status' => 'upcoming',
                'progress' => '0%'
            ];

            // move to next role
            $currentRoleId = $journey->{$progressionMap['to']};
        }

        return response()->json([
            'status' => true,
            'current_jobrole' => [
                'id' => $currentJobRole->id,
                'jobrole' => $currentJobRole->jobrole,
                'job_level' => $currentJobRole->job_level ?? null,
                'sequence_order' => $currentJobRole->sequence_order ?? null,
            ],
            'data' => $result
        ]);
    }

    private function resolveCurrentJobRoleId($allocatedStandards, $subInstituteId)
    {
        if ($allocatedStandards === null || $allocatedStandards === '') {
            return null;
        }

        $allocatedStandards = trim((string) $allocatedStandards);

        if (is_numeric($allocatedStandards)) {
            return (int) $allocatedStandards;
        }

        foreach (array_filter(array_map('trim', explode(',', $allocatedStandards))) as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->where('jobrole', $allocatedStandards)
            ->value('id');
    }

    private function resolveProgressionSchema(): array
    {
        if (
            Schema::hasTable('role_progressions') &&
            Schema::hasColumn('role_progressions', 'from_role_id') &&
            Schema::hasColumn('role_progressions', 'to_role_id')
        ) {
            return [
                'role_progressions',
                [
                    'from' => 'from_role_id',
                    'to' => 'to_role_id',
                    'movement' => 'type',
                ],
            ];
        }

        if (
            Schema::hasTable('career_journey') &&
            Schema::hasColumn('career_journey', 'jobrole_id') &&
            Schema::hasColumn('career_journey', 'to_jobrole_id')
        ) {
            return [
                'career_journey',
                [
                    'from' => 'jobrole_id',
                    'to' => 'to_jobrole_id',
                    'movement' => 'vertical_lateral_movement',
                ],
            ];
        }

        return [null, null];
    }
}
