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

        if (! $userId || ! $subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id and sub_institute_id are required',
            ], 400);
        }

        // 1. Get user
        $user = DB::table('tbluser')
            ->select('tbluser.id', 'tbluser.first_name', 'tbluser.middle_name', 'tbluser.last_name', 'tbluser.allocated_standards', 'tbluser.sub_institute_id', 'tbluser.department_id', 'd.department as department_name')
            ->leftJoin('hrms_departments as d', 'tbluser.department_id', '=', 'd.id')
            ->where('tbluser.id', $userId)
            ->where('tbluser.sub_institute_id', $subInstituteId)
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ]);
        }

        $currentRoleId = $this->resolveCurrentJobRoleId($user->allocated_standards, $subInstituteId);

        if (! $currentRoleId) {
            return response()->json([
                'status' => false,
                'message' => 'Current jobrole could not be resolved for this user',
                'data' => [],
            ], 404);
        }

        $currentJobRole = DB::table('s_user_jobrole')
            ->where('id', $currentRoleId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (! $currentJobRole) {
            return response()->json([
                'status' => false,
                'message' => 'Current jobrole record not found',
                'data' => [],
            ], 404);
        }

        [$progressionTable, $progressionMap] = $this->resolveProgressionSchema();

        if (! $progressionTable) {
            return response()->json([
                'status' => false,
                'message' => 'Career progression table not found',
                'data' => [],
            ], 500);
        }

        $verticalData = $this->buildJourneyData(
            $progressionTable,
            $progressionMap,
            $subInstituteId,
            $currentRoleId,
            'vertical'
        );

        $lateralData = $this->buildLateralJourneyData(
            $progressionTable,
            $progressionMap,
            $subInstituteId,
            $currentRoleId,
        );

        return response()->json([
            'status' => true,
            'user' => [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '').' '.($user->middle_name ?? '').' '.($user->last_name ?? '')),
                'department_id' => $user->department_id,
                'department_name' => $user->department_name,
            ],
            'current_jobrole' => [
                'id' => $currentJobRole->id,
                'jobrole' => $currentJobRole->jobrole,
                'job_level' => $currentJobRole->job_level ?? null,
                'sequence_order' => $currentJobRole->sequence_order ?? null,
            ],
            'data' => [
                'vertical' => $verticalData,
                'lateral' => $lateralData,
            ],
            'vertical_data' => $verticalData,
            'lateral_data' => $lateralData,
        ]);
    }

    private function buildJourneyData(string $progressionTable, array $progressionMap, int $subInstituteId, int $startRoleId, string $movementType): array
    {
        $result = [];
        $visited = [];
        $currentRoleId = $startRoleId;

        while ($currentRoleId) {
            if (in_array($currentRoleId, $visited, true)) {
                break;
            }

            $visited[] = $currentRoleId;

            $journeyQuery = DB::table($progressionTable.' as cj')
                ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.'.$progressionMap['to'])
                ->where('cj.sub_institute_id', $subInstituteId)
                ->where('cj.'.$progressionMap['from'], $currentRoleId);

            if ($progressionTable === 'role_progressions') {
                $journeyQuery->where('cj.type', $movementType);
            } else {
                $journeyQuery->where(
                    'cj.vertical_lateral_movement',
                    $movementType === 'vertical' ? 1 : 0
                );
            }

            $journey = $journeyQuery
                ->orderBy('jr.sequence_order')
                ->orderBy('cj.id')
                ->first();

            if (! $journey) {
                break;
            }

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
                'progress' => '0%',
            ];

            $currentRoleId = $journey->{$progressionMap['to']};
        }

        return $result;
    }

    private function buildLateralJourneyData(string $progressionTable, array $progressionMap, int $subInstituteId, int $currentRoleId): array
    {
        $journeyQuery = DB::table($progressionTable.' as cj')
            ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.'.$progressionMap['to'])
            ->where('cj.sub_institute_id', $subInstituteId)
            ->where('cj.'.$progressionMap['from'], $currentRoleId);

        if ($progressionTable === 'role_progressions') {
            $journeyQuery->where('cj.type', 'lateral');
        } else {
            $journeyQuery->where('cj.vertical_lateral_movement', 0);
        }

        $journeys = $journeyQuery
            ->orderBy('jr.sequence_order')
            ->orderBy('cj.id')
            ->get();

        $result = [];
        $seenTargetRoles = [];

        foreach ($journeys as $journey) {
            $toRoleId = $journey->{$progressionMap['to']};

            if (in_array($toRoleId, $seenTargetRoles, true)) {
                continue;
            }

            $seenTargetRoles[] = $toRoleId;

            $result[] = [
                'id' => $journey->id,
                'from_jobrole_id' => $journey->{$progressionMap['from']},
                'to_jobrole_id' => $journey->{$progressionMap['to']},
                'role_name' => $journey->jobrole,
                'job_level' => $journey->job_level,
                'movement_type' => $progressionTable === 'role_progressions'
                    ? $journey->type
                    : 'lateral',
                'status' => 'upcoming',
                'progress' => '0%',
            ];
        }

        return $result;
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
            $this->hasColumn('role_progressions', 'from_role_id') &&
            $this->hasColumn('role_progressions', 'to_role_id')
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
            $this->hasColumn('career_journey', 'jobrole_id') &&
            $this->hasColumn('career_journey', 'to_jobrole_id')
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

    private function hasColumn(string $table, string $column): bool
    {
        $result = DB::select('
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ', [$table, $column]);

        return $result[0]->count > 0;
    }
}
