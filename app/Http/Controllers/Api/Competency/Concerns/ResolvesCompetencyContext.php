<?php

namespace App\Http\Controllers\Api\Competency\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared request context resolution for the Competency Management API.
 *
 * Mirrors ResolvesLeaveContext: every endpoint under /api/competency/* that
 * this trait guards is token authenticated (Sanctum personal access token
 * passed as `token`) and tenant scoped by sub_institute_id. Competency data is
 * not fiscal-year scoped, so there is no leave-year handling here.
 */
trait ResolvesCompetencyContext
{
    /**
     * @return array{sub_institute_id:int, user_id:int|null}|\Illuminate\Http\JsonResponse
     */
    protected function competencyContext(Request $request)
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['status' => 0, 'message' => 'Token not provided'], 401);
        }

        if (!PersonalAccessToken::findToken($token)) {
            return response()->json(['status' => 0, 'message' => 'Invalid token'], 401);
        }

        $subInstituteId = $request->input('sub_institute_id') ?? $request->header('sub_institute_id');

        if (!$subInstituteId || !is_numeric($subInstituteId)) {
            return response()->json(['status' => 0, 'message' => 'sub_institute_id is required'], 400);
        }

        return [
            'sub_institute_id' => (int) $subInstituteId,
            'user_id'          => is_numeric($request->input('user_id')) ? (int) $request->input('user_id') : null,
        ];
    }

    /**
     * The five command-center filter dimensions, normalised. Empty / 'all' / '0'
     * collapse to null so callers can skip them. Department and Job Role map to
     * columns that exist on the domain tables; Business Unit (industries),
     * Location and Job Family (jobrole_category) live on s_user_jobrole and are
     * resolved to a set of matching jobroles by the service.
     *
     * @return array{department_id:?string, jobrole:?string, location:?string, business_unit:?string, job_family:?string}
     */
    protected function competencyFilters(Request $request): array
    {
        return [
            'department_id' => $this->activeFilter($request->input('department_id')),
            'jobrole'       => $this->activeFilter($request->input('jobrole')),
            'location'      => $this->activeFilter($request->input('location')),
            'business_unit' => $this->activeFilter($request->input('business_unit')),
            'job_family'    => $this->activeFilter($request->input('job_family')),
        ];
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = array_values(array_filter($value, fn ($item) => $item !== null && $item !== '' && $item !== '0' && $item !== 'all'));
            return empty($value) ? null : implode(',', $value);
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    /**
     * Append a row to the competency activity feed. Resolves the actor's display
     * name from tbluser so the Recent Activity feed reads naturally.
     *
     * $subjectName and $changes are optional trailing parameters added for the
     * Audit & Activity Center: the first fills its "Record Name" column, the
     * second its "Change Summary" card / "Version History" tab. Both default to
     * null so every pre-existing call site keeps working unchanged.
     *
     * @param array<int, array{field:string, label:string, old:mixed, new:mixed}>|null $changes
     */
    protected function logCompetencyActivity(
        int $subInstituteId,
        ?int $userId,
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectName = null,
        ?array $changes = null
    ): void {
        $actorName = null;

        if ($userId) {
            $user = DB::table('tbluser')->where('id', $userId)->first();
            if ($user) {
                $actorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $actorName = $actorName !== '' ? $actorName : ($user->user_name ?? null);
            }
        }

        DB::table('s_competency_activity_log')->insert([
            'sub_institute_id' => $subInstituteId,
            'user_id'          => $userId,
            'actor_name'       => $actorName,
            'action'           => $action,
            'description'      => $description,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'subject_name'     => $subjectName !== null ? mb_substr($subjectName, 0, 191) : null,
            'changes'          => ($changes !== null && $changes !== []) ? json_encode(array_values($changes)) : null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /**
     * Build the field-level diff the audit centre renders as "Change Summary".
     *
     * $before is the row as it stood (object or array), $after the validated
     * update payload, $labels the human column names to show. Only columns that
     * are present in $after AND actually differ are returned, so an edit that
     * touched one field does not report the whole record as changed.
     *
     * @param  object|array<string, mixed>   $before
     * @param  array<string, mixed>          $after
     * @param  array<string, string>         $labels  column => display label
     * @return array<int, array{field:string, label:string, old:mixed, new:mixed}>
     */
    protected function diffChanges($before, array $after, array $labels): array
    {
        $before = is_object($before) ? (array) $before : $before;
        $changes = [];

        foreach ($after as $column => $newValue) {
            if (!array_key_exists($column, $labels)) {
                continue;
            }

            $oldValue = $before[$column] ?? null;

            // Loose-but-safe comparison: everything reaches the API as a string,
            // so 3 and '3' must not read as a change.
            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                continue;
            }

            $changes[] = [
                'field' => $column,
                'label' => $labels[$column],
                'old'   => $oldValue,
                'new'   => $newValue,
            ];
        }

        return $changes;
    }
}
