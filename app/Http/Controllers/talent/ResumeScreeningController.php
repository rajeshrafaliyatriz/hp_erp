<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A recruiter's read of a CV: the score they gave it, what matched, and why.
 *
 * ── WHY THIS IS NOT talent_screening_results ────────────────────────────────
 *
 * There are two screening tables and they are easy to confuse. They are not
 * duplicates and neither replaces the other:
 *
 *   talent_screening_results   keyed on candidate_id. The AI verdict -
 *                              competency match, cultural fit, predicted
 *                              success, DeepSeek's analysis. 285 rows, live,
 *                              already shown in the candidate panel.
 *
 *   talent_resume_screenings   keyed on application_id. A PERSON'S review of one
 *                              application - the score they gave, the keywords
 *                              they found, their comments, and their name
 *                              against a timestamp.
 *
 * One is what the model thought about the candidate; the other is what a
 * recruiter decided about a specific application, and is the part that explains
 * why somebody was shortlisted or turned down. That is why this sits beside the
 * AI block in the same Screening tab rather than replacing it.
 *
 * ── WHY IT EXISTS NOW ───────────────────────────────────────────────────────
 *
 * The table had 0 rows on both hosts, no controller, no route, no model and no
 * migration - it existed only on the databases. Audit F-59 flagged it as having
 * no tenant column and the proposal was to drop it. The decision was to keep it,
 * so this is the code that makes it worth keeping.
 *
 * ── TENANCY, TWICE OVER ─────────────────────────────────────────────────────
 *
 * The row now carries sub_institute_id AND hangs off application_id, which has a
 * real foreign key to talent_job_applications. Both are checked on every read,
 * so the column and the parent can never quietly disagree about who owns a row.
 * Another institute's application answers 404, not 403.
 */
class ResumeScreeningController extends Controller
{
    use ResolvesApiIdentity;

    /** decimal(5,2) with a percentage meaning: 0.00 to 100.00. */
    public const SCORE_MIN = 0;
    public const SCORE_MAX = 100;

    /** GET /api/talent/resume-screenings?application_id={id} */
    public function index(Request $request)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $applicationId = (int) $request->input('application_id');

        if (!$applicationId) {
            return response()->json(['status' => 0, 'message' => 'application_id is required'], 422);
        }

        if (!$this->applicationInTenant($tenant, $applicationId)) {
            return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
        }

        $rows = $this->baseQuery($tenant)
            ->where('s.application_id', $applicationId)
            ->orderByDesc('s.reviewed_at')
            ->orderByDesc('s.id')
            ->get();

        return response()->json([
            'status' => 1,
            'data' => [
                'screenings' => $rows->map(fn ($r) => $this->present($r))->all(),
                'latest' => $rows->isNotEmpty() ? $this->present($rows->first()) : null,
            ],
        ]);
    }

    /** POST /api/talent/resume-screenings */
    public function store(Request $request)
    {
        $tenant = $this->apiTenantId($request);
        $actor = $this->apiUserId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'application_id' => 'required|integer',
            'ai_score' => 'required|numeric|min:' . self::SCORE_MIN . '|max:' . self::SCORE_MAX,
            'keywords_matched' => 'nullable|string|max:2000',
            'comments' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        if (!$this->applicationInTenant($tenant, (int) $data['application_id'])) {
            return response()->json(['status' => 0, 'message' => 'Application not found'], 404);
        }

        /*
         * `reviewed_by` comes from the token, never from the payload.
         *
         * This is a sign-off: it says a named person read this CV and reached
         * this conclusion. If the caller could set it, one recruiter could
         * record a decision under another's name, and the field would be worth
         * nothing precisely when it mattered.
         */
        $id = DB::transaction(fn () => DB::table('talent_resume_screenings')->insertGetId([
            'sub_institute_id' => $tenant,
            'application_id' => (int) $data['application_id'],
            'ai_score' => round((float) $data['ai_score'], 2),
            'keywords_matched' => $data['keywords_matched'] ?? null,
            'comments' => $data['comments'] ?? null,
            'reviewed_by' => $actor,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'status' => 1,
            'message' => 'Resume screening recorded',
            'data' => $this->find($tenant, $id),
        ], 201);
    }

    /** PUT /api/talent/resume-screenings/{id} */
    public function update(Request $request, $id)
    {
        $tenant = $this->apiTenantId($request);
        $actor = $this->apiUserId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $row = DB::table('talent_resume_screenings')
            ->where('sub_institute_id', $tenant)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first(['id']);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Screening not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'ai_score' => 'sometimes|required|numeric|min:' . self::SCORE_MIN . '|max:' . self::SCORE_MAX,
            'keywords_matched' => 'nullable|string|max:2000',
            'comments' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $changes = ['updated_at' => now()];

        if (array_key_exists('ai_score', $data)) {
            $changes['ai_score'] = round((float) $data['ai_score'], 2);
        }

        foreach (['keywords_matched', 'comments'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        // Re-signed by whoever changed it, for the same reason store() sets it.
        $changes['reviewed_by'] = $actor;
        $changes['reviewed_at'] = now();

        DB::transaction(fn () => DB::table('talent_resume_screenings')->where('id', $row->id)->update($changes));

        return response()->json([
            'status' => 1,
            'message' => 'Resume screening updated',
            'data' => $this->find($tenant, (int) $row->id),
        ]);
    }

    /** DELETE /api/talent/resume-screenings/{id} */
    public function destroy(Request $request, $id)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $row = DB::table('talent_resume_screenings')
            ->where('sub_institute_id', $tenant)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first(['id']);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Screening not found'], 404);
        }

        // Soft delete: a hiring decision's evidence should not vanish.
        DB::table('talent_resume_screenings')->where('id', $row->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Resume screening removed']);
    }

    /* ------------------------------------------------------------------ */

    /** Both tenant predicates at once - the column and the parent. */
    private function baseQuery(int $tenant)
    {
        return DB::table('talent_resume_screenings as s')
            ->join('talent_job_applications as a', 'a.id', '=', 's.application_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 's.reviewed_by')
            ->where('s.sub_institute_id', $tenant)
            ->where('a.sub_institute_id', $tenant)
            ->whereNull('s.deleted_at')
            ->select([
                's.id', 's.application_id', 's.ai_score', 's.keywords_matched', 's.comments',
                's.reviewed_by', 's.reviewed_at', 's.created_at',
                'u.first_name', 'u.last_name',
                'a.first_name as candidate_first_name', 'a.last_name as candidate_last_name',
            ]);
    }

    private function applicationInTenant(int $tenant, int $applicationId): bool
    {
        return DB::table('talent_job_applications')
            ->where('sub_institute_id', $tenant)
            ->where('id', $applicationId)
            ->exists();
    }

    private function find(int $tenant, int $id): ?array
    {
        $row = $this->baseQuery($tenant)->where('s.id', $id)->first();

        return $row ? $this->present($row) : null;
    }

    private function present($row): array
    {
        $reviewer = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));

        /*
         * keywords_matched is a free-text column, and what has been written into
         * it historically is unknown - the table was empty, so there is no
         * precedent to follow. Comma-separated is what this feature writes; a
         * JSON array is accepted on read so a row from anywhere else still
         * renders instead of showing raw JSON to a recruiter.
         */
        $keywords = [];
        $raw = trim((string) ($row->keywords_matched ?? ''));

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $keywords = is_array($decoded)
                ? array_values(array_filter(array_map('strval', $decoded)))
                : array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        }

        return [
            'id' => (int) $row->id,
            'application_id' => (int) $row->application_id,
            'candidate_name' => trim(($row->candidate_first_name ?? '') . ' ' . ($row->candidate_last_name ?? '')) ?: null,
            'ai_score' => $row->ai_score !== null ? (float) $row->ai_score : null,
            'keywords_matched' => $keywords,
            'comments' => $row->comments,
            'reviewed_by' => $row->reviewed_by ? (int) $row->reviewed_by : null,
            'reviewer_name' => $reviewer !== '' ? $reviewer : null,
            'reviewed_at' => $row->reviewed_at,
            'reviewed_on' => $row->reviewed_at ? substr((string) $row->reviewed_at, 0, 10) : null,
        ];
    }
}
