<?php

namespace App\Http\Controllers\Api\Mobility;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Mobility\MobilityJob;
use App\Http\Controllers\Api\Mobility\Concerns\ResolvesMobilityContext;

class MobilityJobController extends Controller
{
    use ResolvesMobilityContext;

    public function index(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $paging = $this->mobilityPaging($request, 10); // Reuses max 200 clamp
        $sort = $this->mobilitySort($request, ['title', 'posted_on', 'deadline', 'vacancies'], 'posted_on', 'desc');

        $query = MobilityJob::where('sub_institute_id', $subInstituteId);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('job_id', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        /*
         * DEPARTMENT ARRIVES AS AN ID AND IS STORED AS A NAME.
         *
         * `/mobility/filters` serves options as `id as value`, so the client
         * sends `department=1868`, while `s_mobility_jobs.department` holds the
         * NAME. The comparison could never match. Executed before this fix:
         *
         *     /mobility/jobs                        total 1
         *     /mobility/jobs?department=1868        total 0   <- what the UI sends
         *     /mobility/jobs?department=Accountancy total 1   <- what this wanted
         *
         * Every department option returned an empty list, and no error was ever
         * raised - an empty result is a legitimate answer, so it read as "no
         * internal openings in that department" rather than a broken filter.
         *
         * A numeric value is resolved through hrms_departments, TENANT-SCOPED so
         * an id belonging to another organisation cannot name a department here.
         * A non-numeric value is still matched directly, so any caller that
         * always sent the name keeps working.
         */
        if ($dept = $request->input('department')) {
            if (strtolower($dept) !== 'all') {
                $name = ctype_digit((string) $dept)
                    ? DB::table('hrms_departments')
                        ->where('id', (int) $dept)
                        ->where('sub_institute_id', $subInstituteId)
                        ->value('department')
                    : $dept;

                /*
                 * An unresolvable id filters to NOTHING rather than being
                 * ignored. Silently dropping the filter would answer a question
                 * nobody asked - the full unfiltered list, presented as the
                 * contents of one department.
                 */
                $query->where('department', $name ?? '\0__no_such_department__');
            }
        }
        if ($loc = $request->input('location')) {
            if (strtolower($loc) !== 'all') {
                $query->where('location', $loc);
            }
        }
        if ($grade = $request->input('grade')) {
            if (strtolower($grade) !== 'all') {
                $query->where('grade', $grade);
            }
        }
        if ($jobType = $request->input('job_type')) {
            if (strtolower($jobType) !== 'all') {
                $query->where('job_type', $jobType);
            }
        }
        if ($status = $request->input('status')) {
            if (strtolower($status) !== 'all') {
                $query->where('status', $status);
            }
        }

        $total = $query->count();
        $items = $query->orderBy($sort[0], $sort[1])
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        // Top up with application counters
        foreach ($items as $item) {
            $item->applications_count = DB::table('s_mobility_applications')
                ->where('job_posting_id', $item->id)
                ->whereNull('deleted_at')
                ->count();
        }

        return $this->mobilityResponse($items, 'Success', 200, [
            'total' => $total,
            'page' => $paging['page'],
            'per_page' => $paging['per_page'],
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];
        $actorId = $context['user_id'];

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:191',
            'department_id' => 'nullable|integer',
            'location' => 'required|string|max:191',
            'grade' => 'required|string|max:50',
            'job_type' => 'required|string|in:Permanent,Contract,Temporary',
            'posted_on' => 'nullable|date',
            'deadline' => 'nullable|date',
            'hiring_manager_id' => 'nullable|integer',
            'current_incumbent' => 'nullable|string|max:191',
            'vacancies' => 'required|integer|min:1',
            'relocation_required' => 'required|string|in:Yes,No,Case-by-case',
            'description' => 'nullable|string',
            'status' => 'required|string|in:Open,In Review,Closed',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        /*
         * Auto-generate a job_id, e.g. INT-2026-001. Numbered per tenant, which
         * is what the code on screen is meant to mean - and the UNIQUE that
         * backs it is (sub_institute_id, job_id) as of the 2026_09_03_160000
         * migration. It used to be a global unique on job_id alone, so the first
         * tenant to post in a year took INT-<year>-001 for everybody and every
         * other tenant's first posting died on a duplicate key.
         *
         * The sequence is taken as a NUMBER, not by ordering the codes as text.
         * `orderByDesc('job_id')` sorted lexically, so with three-digit padding
         * 'INT-2026-999' ranked above 'INT-2026-1000' and the thousandth posting
         * of a year would have reused an existing number - which now that the
         * constraint works would be a hard failure rather than a silent clash.
         */
        $year = now()->year;
        $prefix = "INT-{$year}-";
        $highest = (int) MobilityJob::where('sub_institute_id', $subInstituteId)
            ->where('job_id', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(job_id, ?) AS UNSIGNED)) AS seq', [strlen($prefix) + 1])
            ->value('seq');
        $jobIdCode = $prefix . str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);

        // Fetch department name if department_id is passed
        $deptName = null;
        if ($deptId = $request->input('department_id')) {
            $deptName = DB::table('hrms_departments')->where('id', $deptId)->value('department');
        }

        // Fetch hiring manager name
        $hmName = null;
        if ($hmId = $request->input('hiring_manager_id')) {
            $hm = DB::table('tbluser')->where('id', $hmId)->first(['first_name', 'last_name']);
            if ($hm) {
                $hmName = trim(($hm->first_name ?? '') . ' ' . ($hm->last_name ?? ''));
            }
        }

        $postedOn = $request->input('posted_on') ?: now()->toDateString();

        $job = MobilityJob::create(array_merge($validator->validated(), [
            'sub_institute_id' => $subInstituteId,
            'job_id' => $jobIdCode,
            'department' => $deptName,
            'hiring_manager_name' => $hmName,
            'posted_on' => $postedOn,
            'created_by' => $actorId,
        ]));

        return $this->mobilityResponse($job, 'Job posting created successfully', 201);
    }

    public function show(Request $request, $id)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $job = MobilityJob::where('sub_institute_id', $context['sub_institute_id'])
            ->find($id);

        if (!$job) {
            return $this->mobilityError('Job posting not found', 404);
        }

        $job->applications_count = DB::table('s_mobility_applications')
            ->where('job_posting_id', $job->id)
            ->whereNull('deleted_at')
            ->count();

        return $this->mobilityResponse($job);
    }

    public function update(Request $request, $id)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $job = MobilityJob::where('sub_institute_id', $context['sub_institute_id'])
            ->find($id);

        if (!$job) {
            return $this->mobilityError('Job posting not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:191',
            'department_id' => 'nullable|integer',
            'location' => 'sometimes|required|string|max:191',
            'grade' => 'sometimes|required|string|max:50',
            'job_type' => 'sometimes|required|string|in:Permanent,Contract,Temporary',
            'posted_on' => 'sometimes|required|date',
            'deadline' => 'nullable|date|after_or_equal:posted_on',
            'hiring_manager_id' => 'nullable|integer',
            'current_incumbent' => 'nullable|string|max:191',
            'vacancies' => 'sometimes|required|integer|min:1',
            'relocation_required' => 'sometimes|required|string|in:Yes,No,Case-by-case',
            'description' => 'nullable|string',
            'status' => 'sometimes|required|string|in:Open,In Review,Closed',
        ]);

        if ($validator->fails()) {
            return $this->mobilityError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        // Update department name if changed
        if ($request->has('department_id')) {
            $deptId = $request->input('department_id');
            $data['department'] = $deptId ? DB::table('hrms_departments')->where('id', $deptId)->value('department') : null;
        }

        // Update hiring manager name if changed
        if ($request->has('hiring_manager_id')) {
            $hmId = $request->input('hiring_manager_id');
            if ($hmId) {
                $hm = DB::table('tbluser')->where('id', $hmId)->first(['first_name', 'last_name']);
                $data['hiring_manager_name'] = $hm ? trim(($hm->first_name ?? '') . ' ' . ($hm->last_name ?? '')) : null;
            } else {
                $data['hiring_manager_name'] = null;
            }
        }

        $job->update(array_merge($data, [
            'updated_by' => $context['user_id'],
        ]));

        return $this->mobilityResponse($job, 'Job posting updated successfully');
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $job = MobilityJob::where('sub_institute_id', $context['sub_institute_id'])
            ->find($id);

        if (!$job) {
            return $this->mobilityError('Job posting not found', 404);
        }

        $job->update(['deleted_by' => $context['user_id']]);
        $job->delete();

        return $this->mobilityResponse(null, 'Job posting deleted successfully');
    }
}
