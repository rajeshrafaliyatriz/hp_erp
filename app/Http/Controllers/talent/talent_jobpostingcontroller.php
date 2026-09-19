<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Models\talent\talent_jobposting;
use App\Models\talent\talent_jobapplication;
use App\Models\talent\talent_interviewschedules;
use App\Models\talent\TalentOffer;
use App\Models\talent\feedback\TalentEvaluationForm;


class talent_jobpostingcontroller extends Controller
{
    /**
     * Where the work happens. VARCHAR + const, never ENUM: adding a fourth mode
     * must be a deploy, not an ALTER on a live table.
     */
    public const WORK_MODES = ['On-site', 'Hybrid', 'Remote'];

    /**
     * The contract types, matching what the live host already holds as text:
     * Full-Time 38, Part-Time 32, Contract 29, Internship 27.
     *
     * Named here so the public apply form can be validated against the SAME
     * list the posting form offers - a candidate picking "Part-Time" and a
     * recruiter filtering on "Part Time" is how a filter quietly returns zero.
     */
    public const EMPLOYMENT_TYPES = ['Full-Time', 'Part-Time', 'Contract', 'Temporary', 'Internship'];

    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


    public function index(request $request)
    {
        try {
            $type = $request->type; // API or web

            if ($type == 'API') {
                // validate token
                $token = $request->input('token');
                if (!$token) {
                    return response()->json(['message' => 'Token not provided'], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                // validate required params
                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status_code' => 0,
                        'message' => $validator->errors()->first()
                    ], 400);
                }

                $sub_institute_id = $this->apiTenantId($request);

                /*
                 * Auto-close postings whose deadline has PASSED.
                 *
                 * now() is a datetime, so comparing a DATE deadline against it
                 * closed a posting at 00:00 on its own closing day - a role
                 * advertised "apply by 7 September" was gone for the whole of
                 * the 7th. The public careers page uses ,
                 * which keeps that day open, so the two disagreed and a posting
                 * the candidate had every right to see was already Inactive.
                 * toDateString() makes the sweep agree with the page it feeds.
                 */
                DB::table('talent_job_postings')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('status', 'active')
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', now()->toDateString())
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                // fetch jobrole data from table
                $talent = DB::table('talent_job_postings as a')
                            ->leftJoin('hrms_departments as d', 'a.department_id', '=', 'd.id')
                            ->select('a.*', 'd.department as department_name')
                            ->where('a.sub_institute_id',$sub_institute_id)
                            ->whereNull('a.deleted_at')
                            ->get();


                return response()->json([
                    'message' => ' fetched successfully',
                    'data'    => $talent
                ], 200);
            }
            $res['talent'] = DB::table('talent_job_postings')
                    ->select('id', 'sub_institute_id', 'status')
                    ->whereNull('deleted_at')
                    ->get();
            return is_mobile($type, 'talent.index', $res, 'view');

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
         $type = $request->type;

    if ($type == "API") {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // From the token, matching index() at :68. Taking it from the request let a
        // caller create a job posting inside another organisation's tenant, where it
        // would be invisible to its real owner.
        $sub_institute_id = $this->apiTenantId($request);
        if (!$sub_institute_id) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

          $validator = Validator::make($request->all(), [
            /*
             * EVERY FIELD IS OPTIONAL EXCEPT THIS ONE.
             *
             * A posting can now be saved as a draft with almost nothing filled
             * in - department, location, type, positions and status were all
             * `required` and blocked that. talent_job_postings allows NULL on
             * every one of them, so nothing here was protecting the database.
             *
             * `title` stays required because the COLUMN is NOT NULL with no
             * default: an empty one is rejected by MySQL, and a posting with no
             * title cannot be found or advertised anyway. That is a constraint,
             * not a preference.
             */
            'title'            => 'required|string|max:255',
            'department_id'    => 'nullable|integer',
            /*
             * THE COLUMN EXISTED AND NOTHING EVER WROTE IT.
             *
             * `talent_job_postings.jobrole_id` was NULL on all 127 postings in
             * the installation, because neither store() nor update() accepted
             * it. That is not cosmetic: candidate assessment resolves its exam
             * template through this column, so every invitation failed with
             * "No assessment blueprint matches this role" no matter how many
             * templates HR created. Optional, so existing callers still work.
             */
            'jobrole_id'       => 'nullable|integer|exists:s_jobrole,id',
            'location'         => 'nullable|string|max:255',
            'employment_type'  => 'nullable|string|max:100',
            /*
             * WHERE the work happens, which is a separate question from the
             * contract - a role can be Full-Time AND Remote. Kept out of
             * employment_type so "remote internship" stays representable.
             * Optional: NULL reads as "not stated", never as On-site.
             */
            'work_mode'        => 'nullable|string|in:' . implode(',', self::WORK_MODES),
            'experience'       => 'nullable|string|max:255',
            'education'        => 'nullable|string|max:255',
            'priority_level'   => 'nullable|string|max:100',
            'positions'        => 'nullable|integer|min:1',
            'min_salary'       => 'nullable|numeric|min:0',
            'max_salary'       => 'nullable|numeric|min:0',
            /*
             * The application window. start_date is when applications OPEN;
             * deadline is when they close, so one cannot follow the other.
             * Both nullable: a posting with neither is open from the moment it
             * is published, which is how all 127 existing ones behave.
             */
            'start_date'       => 'nullable|date',
            'deadline'         => 'nullable|date|after_or_equal:start_date',
            'skills'           => 'nullable|string',
            'certifications'   => 'nullable|string',
            'benefits'         => 'nullable|string',
            'description'      => 'nullable|string',
            'status'           => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
            $objtalent = new talent_jobposting();
            $objtalent->title = $request->title;
            $objtalent->department_id = $this->resolveDepartmentId($request, (int) $sub_institute_id);
            // Nullable: a posting without a role still saves, it just
            // cannot be assessed until one is chosen.
            $objtalent->jobrole_id = $this->resolveJobroleId($request);
            $objtalent->location = $request->location; 
            $objtalent->employment_type = $request->employment_type;
            $objtalent->work_mode = $request->filled('work_mode') ? $request->input('work_mode') : null;
            $objtalent->experience = $request->experience;
            $objtalent->education = $request->education;
            $objtalent->priority_level = $request->priority_level;
            $objtalent->positions = $request->positions;
            $objtalent->min_salary = $request->min_salary;
            $objtalent->max_salary = $request->max_salary;
            $objtalent->start_date = $request->filled('start_date') ? $request->input('start_date') : null;
            $objtalent->deadline = $request->deadline;
            $objtalent->skills = $request->skills;
            $objtalent->certifications = $request->certifications;
            $objtalent->benefits = $request->benefits;
            $objtalent->description = $request->description;
            // Omitted means a live posting, which is what Create means here.
            $objtalent->status = $request->filled('status') ? $request->status : 'active';
            $objtalent->sub_institute_id = $sub_institute_id;
            $objtalent->created_by = $request->user_id;

            if ($objtalent->save()) {
                return response()->json(['message' => 'added successfully !!','data' => $objtalent], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
    }
    public function getHiringStatus(Request $request)
    {
        try {
            $type = $request->type; // API or web

            if ($type == 'API') {
                // validate token
                $token = $request->input('token');
                if (!$token) {
                    return response()->json(['message' => 'Token not provided'], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                // validate required params
                $validator = Validator::make($request->all(), [
                    'sub_institute_id' => 'required',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status_code' => 0,
                        'message' => $validator->errors()->first()
                    ], 400);
                }

                $sub_institute_id = $this->apiTenantId($request);

                /*
                 * Auto-close postings whose deadline has PASSED.
                 *
                 * now() is a datetime, so comparing a DATE deadline against it
                 * closed a posting at 00:00 on its own closing day - a role
                 * advertised "apply by 7 September" was gone for the whole of
                 * the 7th. The public careers page uses ,
                 * which keeps that day open, so the two disagreed and a posting
                 * the candidate had every right to see was already Inactive.
                 * toDateString() makes the sweep agree with the page it feeds.
                 */
                DB::table('talent_job_postings')
                    ->where('sub_institute_id', $sub_institute_id)
                    ->where('status', 'active')
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', now()->toDateString())
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                // Execute the query
                $data = DB::select("
                    SELECT
                        d.department AS department_name,
                        COUNT(DISTINCT jp.id) AS total_positions,
                        COUNT(CASE WHEN ja.status = 'hired' THEN ja.id END) AS hired
                    FROM talent_job_postings jp
                    LEFT JOIN hrms_departments d
                        ON jp.department_id = d.id
                    LEFT JOIN talent_job_applications ja
                        ON ja.job_id = jp.id
                    WHERE jp.sub_institute_id = ?
                    GROUP BY d.id, d.department
                ", [$sub_institute_id]);

                // Fetch recent team updates dynamically
                $recentTeamUpdates = [];

                // Recent hires
                $recentHires = DB::select("
                    SELECT CONCAT(ja.first_name, ' ', ja.last_name) as candidate_name, d.department
                    FROM talent_job_applications ja
                    JOIN talent_job_postings jp ON ja.job_id = jp.id
                    JOIN hrms_departments d ON jp.department_id = d.id
                    WHERE ja.status = 'hired' AND ja.sub_institute_id = ? AND ja.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY ja.updated_at DESC
                    LIMIT 5
                ", [$sub_institute_id]);

                foreach ($recentHires as $hire) {
                    $recentTeamUpdates[] = $hire->candidate_name . ' joined ' . $hire->department . ' team';
                }

                // Open positions
                $openPositionsCount = DB::select("
                    SELECT COUNT(*) as count FROM talent_job_postings
                    WHERE status = 'active' AND sub_institute_id = ? AND deleted_at IS NULL
                ", [$sub_institute_id])[0]->count;

                if ($openPositionsCount > 0) {
                    $recentTeamUpdates[] = $openPositionsCount . ' position(s) still open';
                }

                // Positions needing attention (deadline within 7 days)
                $attentionPositions = DB::select("
                    SELECT title FROM talent_job_postings
                    WHERE status = 'active' AND sub_institute_id = ? AND deadline <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL
                    LIMIT 3
                ", [$sub_institute_id]);

                foreach ($attentionPositions as $pos) {
                    $recentTeamUpdates[] = $pos->title . ' role needs attention';
                }

                // Upcoming interviews
                $upcomingInterviews = DB::select("
                    SELECT COUNT(*) as count FROM talent_interview_schedules
                    WHERE sub_institute_id = ? AND interview_date >= CURDATE() AND status = 'scheduled'
                ", [$sub_institute_id])[0]->count;

                if ($upcomingInterviews > 0) {
                    $recentTeamUpdates[] = $upcomingInterviews . ' interview(s) scheduled';
                }

                // Tomorrow's interviews
                $tomorrowInterviews = DB::select("
                    SELECT jp.title FROM talent_interview_schedules tis
                    JOIN talent_job_postings jp ON tis.job_id = jp.id
                    WHERE tis.sub_institute_id = ? AND tis.interview_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND tis.status = 'scheduled'
                    LIMIT 3
                ", [$sub_institute_id]);

                foreach ($tomorrowInterviews as $interview) {
                    $recentTeamUpdates[] = $interview->title . ' candidate tomorrow';
                }

                return response()->json([
                    'message' => 'Hiring status fetched successfully',
                    'data' => $data,
                    'recent_team_updates' => $recentTeamUpdates
                ], 200);
            }

            // For web, perhaps return view or something, but since it's API focused, maybe just API
            return response()->json(['message' => 'Invalid type'], 400);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }


    /**
     * The department row this posting really belongs to.
     *
     * ── WHY THE SENT ID CANNOT BE TRUSTED ───────────────────────────────────
     *
     * The posting form builds its department dropdown from `s_user_jobrole`
     * grouped by department, and falls back to the ROLE ROW'S id when a role
     * carries no department_id. So `department_id` has been receiving job-role
     * ids: measured on tenant 6, 12 of 13 postings point at a row that is not a
     * department of that organisation at all - ids 131-144, which resolve to
     * another tenant's "Manufacturing" roles. The Careers page and the openings
     * table both render the department by joining on it, so they render blank.
     *
     * ── HOW IT IS RESOLVED INSTEAD ──────────────────────────────────────────
     *
     * A valid id wins. Otherwise the TITLE - which is a job role name chosen
     * from the same dropdown - is matched against this tenant's roles to get a
     * department NAME, and that name against hrms_departments. All 13 tenant-6
     * postings resolve this way.
     *
     * Falls back to whatever was sent rather than nulling it: this is a repair,
     * and a posting that saved yesterday must still save today.
     */
    private function resolveDepartmentId(Request $request, int $subInstituteId): ?int
    {
        $sent = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        if ($sent && DB::table('hrms_departments')
            ->where('id', $sent)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists()) {
            return $sent;
        }

        $title = trim((string) $request->input('title'));

        if ($title !== '') {
            $department = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->where('jobrole', $title)
                ->value('department');

            if ($department) {
                $resolved = DB::table('hrms_departments')
                    ->where('sub_institute_id', $subInstituteId)
                    ->where('department', $department)
                    ->whereNull('deleted_at')
                    ->value('id');

                if ($resolved) {
                    return (int) $resolved;
                }
            }
        }

        return $sent;
    }

    /**
     * The catalogue job role this posting is for.
     *
     * Prefers an explicit jobrole_id. Falls back to matching the TITLE against
     * the catalogue, because the posting form is already a job-role picker - it
     * just sends the role's NAME as the title and throws the id away.
     *
     * ── WHY IT CANNOT SIMPLY FORWARD THE PICKER'S ID ────────────────────────
     *
     * The picker reads `s_user_jobrole`, this column references `s_jobrole`, and
     * the two are different id spaces: 269 tenant rows against 3,347 catalogue
     * rows, with ids that collide meaninglessly. Forwarding the picker's id
     * would point every posting at an unrelated role. The NAMES do correspond -
     * all 269 tenant role names exist in the catalogue - so the name is the only
     * safe bridge between them.
     *
     * Null when nothing matches: a posting with no role still saves, it simply
     * cannot be assessed until one is chosen.
     */
    private function resolveJobroleId(Request $request): ?int
    {
        if ($request->filled('jobrole_id')) {
            return (int) $request->input('jobrole_id');
        }

        $title = trim((string) $request->input('title'));

        if ($title === '') {
            return null;
        }

        $id = DB::table('s_jobrole')->where('jobrole', $title)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $sub_institute_id = $this->apiTenantId($request);
    
        // Check if record exists
        $exists = talent_jobposting::where([
            'sub_institute_id' => $sub_institute_id,
            'id' => $id
        ])->exists();
    
        if(!$exists){
            return response()->json([
                'message' => 'No talent record found for this department',
                'data' => $id
            ], 404);
        }

        /*
         * Only the fields the caller actually sent are written (F-69b).
         *
         * This used to assign every column unconditionally from $request->x, so
         * a partial update - a status-only PUT, say - blanked the whole posting:
         * title, salary, description and the rest all became null. Executed by
         * accident on the 128.199 host during the live re-audit, it emptied
         * posting 216, which then showed a blank title on the public careers
         * page. Restored from the app-DB copy.
         *
         * `filled` covers the normal case; `has` lets a field be deliberately
         * cleared to an empty string. A field that is simply absent keeps its
         * stored value.
         */
        /*
         * update() validated NOTHING before this - it wrote whatever it was
         * handed. Harmless for free text, not for jobrole_id: a bad id is
         * accepted, stored, and then silently matches no exam template, which
         * reads on screen as "no template for this role" for a role that does
         * not exist. Only the referential fields are checked here; widening it
         * to every column would reject the partial updates F-69b exists to allow.
         */
        $validator = Validator::make($request->all(), [
            'jobrole_id'    => 'nullable|integer|exists:s_jobrole,id',
            'department_id' => 'nullable|integer',
            // Same window rule as store(). Only checked when BOTH are sent, so a
            // partial update that touches one of them still works (F-69b).
            'start_date'    => 'nullable|date',
            'deadline'      => 'nullable|date|after_or_equal:start_date',
            'work_mode'     => 'nullable|string|in:' . implode(',', self::WORK_MODES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $editable = [
            'title', 'location', 'employment_type', 'work_mode', 'experience', 'department_id',
            // jobrole_id joins this list for the same reason it joined store():
            // candidate assessment resolves its exam template through it.
            'jobrole_id',
            'education', 'priority_level', 'positions', 'min_salary', 'max_salary',
            'start_date', 'deadline', 'skills', 'certifications', 'benefits', 'description', 'status',
        ];

        $changes = ['updated_by' => $this->g2gActorId($request), 'updated_at' => now()];

        foreach ($editable as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->input($field);
            }
        }

        /*
         * If the caller sent a title but no jobrole_id, resolve one from the
         * title. Only when the title is actually changing, so a status-only PUT
         * still writes nothing else - the whole point of the $editable list.
         */
        if ($request->has('title') && !$request->filled('jobrole_id')) {
            $resolved = $this->resolveJobroleId($request);
            if ($resolved !== null) {
                $changes['jobrole_id'] = $resolved;
            }
        }

        // Same repair on edit, and only when department_id is in the payload -
        // a status-only PUT still writes nothing else (F-69b).
        if ($request->has('department_id')) {
            $changes['department_id'] = $this->resolveDepartmentId($request, (int) $sub_institute_id);
        }

        // Perform update
        $updated = talent_jobposting::where([
            'sub_institute_id' => $sub_institute_id,
            'id' => $id
        ])->update($changes);
    
        return response()->json([
            'message' => $updated ? 'Updated successfully' : 'Failed to update',
            'data' => $id
        ], $updated ? 200 : 400);
    }
    
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(request $request ,string $id)
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
            // From the token, matching index() at :68. The delete below is scoped by
            // sub_institute_id, so a request-supplied value chose which organisation's
            // posting was deleted.
            $sub_institute_id = $this->apiTenantId($request);
            if (!$sub_institute_id) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }

        try {
            // Check if the job posting exists and belongs to the sub_institute and not already deleted
            $exists = talent_jobposting::where([
                'id' => $id,
                'sub_institute_id' => $sub_institute_id
            ])->whereNull('deleted_at')->exists();

            if (!$exists) {
                return response()->json(['message' => 'Job posting not found or already deleted'], 404);
            }

            // Soft delete related records
            TalentEvaluationForm::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            talent_interviewschedules::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            talent_jobapplication::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            TalentOffer::where('job_id', $id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
            ]);

            $delete = talent_jobposting::where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->g2gActorId($request),
            ]);

            if ($delete) {
                return response()->json(['message' => 'Job posting and related data deleted successfully'], 200);
            }

            return response()->json(['message' => 'Failed to delete job posting'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


}
