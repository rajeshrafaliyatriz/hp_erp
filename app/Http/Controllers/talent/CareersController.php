<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

/**
 * The candidate's surface. Public, unauthenticated, deliberately narrow.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * The audit found the candidate had no surface at all: no screen, no login, no
 * way to apply. Every route in the product sat inside the authenticated layer,
 * so the only thing that ever reached a candidate was an emailed PDF they could
 * not reply to. Recruitment began with an application that somebody inside the
 * company had to type in on the candidate's behalf.
 *
 * ── HOW TENANCY WORKS HERE, AND WHY IT IS NOT THE USUAL DEFECT ──────────────
 *
 * Everywhere else in this codebase, taking the tenant from the request is the
 * bug: a token identifies its owner, so trusting a request parameter lets a
 * caller name somebody else's organisation.
 *
 * Here there is no token, by design - a candidate is not a user. The tenant is
 * resolved from the careers slug in the PATH, which is:
 *
 *   - the resource identifier itself, not a parameter alongside one;
 *   - uniquely indexed, so it resolves to exactly one organisation;
 *   - only ever used to SCOPE reads and to STAMP the tenant on a new
 *     application - never to widen access to anything already stored.
 *
 * What a caller can reach through it is bounded to: an organisation's display
 * name, its ACTIVE job postings, and the ability to add a row to
 * talent_job_applications. No candidate data is ever returned - not their own,
 * because without a login there is no "their own" to prove.
 *
 * Every route is rate limited. The application had no throttling anywhere before
 * this, so `throttle` on these routes is a new control, not an existing pattern.
 */
class CareersController extends Controller
{
    /** talent_candidates.source — VARCHAR + const, never ENUM. */
    public const CANDIDATE_SOURCES = ['careers', 'internal'];

    /** Columns a stranger may see. An allow-list, so a column added later stays private until named. */
    private const POSTING_PUBLIC = [
        'p.id', 'p.title', 'p.location', 'p.employment_type', 'p.experience',
        'p.education', 'p.skills', 'p.certifications', 'p.benefits',
        'p.description', 'p.min_salary', 'p.max_salary', 'p.positions',
        'p.deadline', 'p.priority_level', 'p.created_at',
    ];

    /**
     * GET /api/careers/{slug}
     * The organisation and its open roles.
     */
    public function organisation(Request $request, string $slug)
    {
        $org = $this->resolveOrganisation($slug);
        if (!$org) {
            return response()->json(['status' => 0, 'message' => 'Careers page not found.'], 404);
        }

        $postings = $this->openPostings($org->sub_institute_id)->get();

        return response()->json([
            'status' => 1,
            'data' => [
                'organisation' => [
                    'name'     => $org->organization_name,
                    'slug'     => $org->careers_slug,
                    'industry' => $org->industry_type,
                    'website'  => $org->organization_website,
                    'address'  => $org->address,
                ],
                'postings' => $postings->map(fn ($p) => $this->presentPosting($p))->values(),
                'total'    => $postings->count(),
            ],
        ], 200);
    }

    /**
     * GET /api/careers/{slug}/postings/{id}
     * One role, in full.
     */
    public function posting(Request $request, string $slug, $id)
    {
        $org = $this->resolveOrganisation($slug);
        if (!$org) {
            return response()->json(['status' => 0, 'message' => 'Careers page not found.'], 404);
        }

        // The tenant predicate is inside the lookup, so a posting belonging to a
        // different organisation is indistinguishable from one that does not exist.
        $posting = $this->openPostings($org->sub_institute_id)->where('p.id', (int) $id)->first();

        if (!$posting) {
            return response()->json(['status' => 0, 'message' => 'This role is no longer open.'], 404);
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'organisation' => ['name' => $org->organization_name, 'slug' => $org->careers_slug],
                'posting'      => $this->presentPosting($posting, true),
            ],
        ], 200);
    }

    /**
     * POST /api/careers/{slug}/postings/{id}/apply
     * A candidate applies, with no account and no login.
     */
    public function apply(Request $request, string $slug, $id)
    {
        $org = $this->resolveOrganisation($slug);
        if (!$org) {
            return response()->json(['status' => 0, 'message' => 'Careers page not found.'], 404);
        }

        $posting = $this->openPostings($org->sub_institute_id)->where('p.id', (int) $id)->first();
        if (!$posting) {
            return response()->json(['status' => 0, 'message' => 'This role is no longer open.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name'       => 'required|string|max:100',
            'middle_name'      => 'nullable|string|max:100',
            'last_name'        => 'required|string|max:100',
            'email'            => 'required|email|max:255',
            'mobile'           => 'required|string|max:15',
            'current_location' => 'nullable|string|max:255',
            'employment_type'  => 'nullable|string|max:100',
            'experience'       => 'nullable|string|max:100',
            'education'        => 'nullable|string|max:255',
            'expected_salary'  => 'nullable|numeric|min:0',
            'skills'           => 'nullable|string|max:2000',
            'certifications'   => 'nullable|string|max:2000',
            'resume'           => 'required|file|mimes:pdf,doc,docx|max:5120',
            // Keeping someone's CV for roles they have not applied for is a
            // separate thing from processing this application, so it is asked
            // separately and defaults to no.
            'consent_to_retain' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Please check the highlighted fields.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // One application per person per role. Without a login this is the only
        // identity a candidate has, so it is also the only duplicate check
        // available - and it is friendlier than letting them apply twice and
        // wonder which one counted.
        $already = DB::table('talent_job_applications')
            ->where('sub_institute_id', $org->sub_institute_id)
            ->where('job_id', $posting->id)
            ->where('email', $request->input('email'))
            ->whereNull('deleted_at')
            ->exists();

        if ($already) {
            return response()->json([
                'status'  => 0,
                'message' => 'You have already applied for this role. We have your application.',
            ], 409);
        }

        $resumeUrl = null;
        try {
            $file = $request->file('resume');
            $name = 'resume_' . $org->sub_institute_id . '_' . $posting->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            Storage::disk('digitalocean')->putFileAs('public/hp_resume/', $file, $name, 'public');
            $resumeUrl = Storage::disk('digitalocean')->url('public/hp_resume/' . $name);
        } catch (\Throwable $e) {
            // The CV is required, so a failed upload has to fail the application -
            // silently storing a candidate with no CV would look like success and
            // leave a recruiter with nothing to read.
            return response()->json([
                'status'  => 0,
                'message' => 'We could not upload your CV. Please try again.',
            ], 503);
        }

        // The person, kept once per organisation. A returning applicant updates
        // their record rather than arriving as a stranger with an unreachable CV.
        $candidateId = $this->upsertCandidate($org->sub_institute_id, $request, $resumeUrl);

        $applicationId = DB::table('talent_job_applications')->insertGetId([
            'job_id'           => $posting->id,
            'candidate_id'     => $candidateId,
            'first_name'       => $request->input('first_name'),
            'middle_name'      => $request->input('middle_name'),
            'last_name'        => $request->input('last_name'),
            'email'            => $request->input('email'),
            'mobile'           => $request->input('mobile'),
            'current_location' => $request->input('current_location'),
            'employment_type'  => $request->input('employment_type'),
            'experience'       => $request->input('experience'),
            'education'        => $request->input('education'),
            'expected_salary'  => $request->input('expected_salary'),
            'skills'           => $request->input('skills'),
            'certifications'   => $request->input('certifications'),
            'resume_path'      => $resumeUrl,
            'applied_date'     => now(),
            // The vocabulary lives in one place; a public application enters the
            // pipeline exactly where an internally-typed one does.
            'status'           => talent_jobapplicationcontroller::STATUSES[0],
            // From the slug, which IS the resource - never from request input.
            'sub_institute_id' => $org->sub_institute_id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Thank you. Your application has been received.',
            'data'    => ['application_id' => $applicationId],
        ], 201);
    }

    /**
     * Record the person behind the application, or update the one already there.
     *
     * Keyed on (tenant, sha256 of the lowercased email) — the address itself is
     * varchar(255) and an index on it would exceed live's 767-byte prefix cap.
     *
     * Consent is only ever RAISED here, never lowered: someone who said yes on a
     * previous application has not withdrawn it by leaving the box unticked on
     * the next one. Withdrawal is a deliberate act and belongs on its own path.
     */
    private function upsertCandidate(int $tenantId, Request $request, ?string $resumeUrl): int
    {
        $email = trim((string) $request->input('email'));
        $key = hash('sha256', mb_strtolower($email));
        $consent = (bool) $request->boolean('consent_to_retain');

        $existing = DB::table('talent_candidates')
            ->where('sub_institute_id', $tenantId)
            ->where('email_key', $key)
            ->first(['id', 'consent_to_retain', 'consent_at', 'applications_count']);

        $shared = [
            'first_name'       => $request->input('first_name'),
            'middle_name'      => $request->input('middle_name'),
            'last_name'        => $request->input('last_name'),
            'mobile'           => $request->input('mobile'),
            'current_location' => $request->input('current_location'),
            'experience'       => $request->input('experience'),
            'education'        => $request->input('education'),
            'expected_salary'  => $request->input('expected_salary'),
            'skills'           => $request->input('skills'),
            'certifications'   => $request->input('certifications'),
            'resume_path'      => $resumeUrl,
            'last_applied_at'  => now(),
            'updated_at'       => now(),
        ];

        if ($existing) {
            DB::table('talent_candidates')->where('id', $existing->id)->update($shared + [
                'consent_to_retain' => $existing->consent_to_retain || $consent ? 1 : 0,
                'consent_at'        => $existing->consent_at ?: ($consent ? now() : null),
                'applications_count' => (int) $existing->applications_count + 1,
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('talent_candidates')->insertGetId($shared + [
            'sub_institute_id'   => $tenantId,
            'syear'              => (string) ($request->input('syear') ?: date('Y')),
            'email'              => $email,
            'email_key'          => $key,
            'consent_to_retain'  => $consent ? 1 : 0,
            'consent_at'         => $consent ? now() : null,
            'source'             => self::CANDIDATE_SOURCES[0],
            'applications_count' => 1,
            'created_at'         => now(),
        ]);
    }

    /** The organisation behind a careers slug, or null. */
    private function resolveOrganisation(string $slug)
    {
        return DB::table('institute_detail')
            ->where('careers_slug', $slug)
            ->whereNull('deleted_at')
            ->first(['sub_institute_id', 'organization_name', 'careers_slug', 'industry_type', 'organization_website', 'address']);
    }

    /**
     * Postings a stranger is allowed to see: active, not deleted, not past their
     * deadline. Everything else in the table stays invisible.
     */
    private function openPostings(int $tenantId)
    {
        return DB::table('talent_job_postings as p')
            ->leftJoin('hrms_departments as d', function ($join) use ($tenantId) {
                $join->on('p.department_id', '=', 'd.id')
                    ->where('d.sub_institute_id', '=', $tenantId);
            })
            ->where('p.sub_institute_id', $tenantId)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                $q->whereNull('p.deadline')->orWhere('p.deadline', '>=', now()->toDateString());
            })
            ->orderByDesc('p.created_at')
            ->select(array_merge(self::POSTING_PUBLIC, [DB::raw('d.department as department_name')]));
    }

    /** Shape a posting for public consumption. */
    private function presentPosting($p, bool $full = false): array
    {
        $row = [
            'id'              => (int) $p->id,
            'title'           => $p->title,
            'department'      => $p->department_name,
            'location'        => $p->location,
            'employment_type' => $p->employment_type,
            'experience'      => $p->experience,
            'positions'       => $p->positions !== null ? (int) $p->positions : null,
            'deadline'        => $p->deadline,
            'posted_at'       => $p->created_at,
            'skills'          => array_values(array_filter(array_map('trim', explode(',', (string) $p->skills)))),
            'salary_min'      => $p->min_salary !== null ? (float) $p->min_salary : null,
            'salary_max'      => $p->max_salary !== null ? (float) $p->max_salary : null,
        ];

        if ($full) {
            $row['description']    = $p->description;
            $row['education']      = $p->education;
            $row['certifications'] = $p->certifications;
            $row['benefits']       = $p->benefits;
            $row['priority']       = $p->priority_level;
        }

        return $row;
    }
}
