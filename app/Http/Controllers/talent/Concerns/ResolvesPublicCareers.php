<?php

namespace App\Http\Controllers\talent\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * What a stranger is allowed to see of an organisation's job adverts.
 *
 * ── WHY THIS IS A TRAIT AND NOT COPIED ──────────────────────────────────────
 *
 * These three methods were private on CareersController. The hiring poster
 * needs exactly the same rules, and copying them would put a SECURITY
 * PREDICATE - the one deciding what unauthenticated callers can read - in two
 * places that are free to drift.
 *
 * The specific hazard: POSTING_PUBLIC is an allow-list precisely so that a
 * column added to talent_job_postings later stays private until somebody names
 * it. A second copy silently defeats that, because the column would be added to
 * one list and not the other, and nothing would fail.
 *
 * So there is one list, one publish predicate, and one presenter.
 */
trait ResolvesPublicCareers
{
    /** Columns a stranger may see. An allow-list, so a column added later stays private until named. */
    private const POSTING_PUBLIC = [
        'p.id', 'p.title', 'p.location', 'p.employment_type', 'p.work_mode', 'p.experience',
        'p.education', 'p.skills', 'p.certifications', 'p.benefits',
        'p.description', 'p.min_salary', 'p.max_salary', 'p.positions',
        'p.start_date', 'p.deadline', 'p.priority_level', 'p.created_at',
    ];

    /** The organisation behind a careers slug, or null. */
    protected function resolveOrganisation(string $slug)
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
    protected function openPostings(int $tenantId)
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
            /*
             * A role scheduled to open later is not public yet.
             *
             * NULL means "already open", which is every posting that predates
             * the column - so nothing that is public today stops being public.
             * HR still sees it in the admin list; only the careers page waits.
             */
            ->where(function ($q) {
                $q->whereNull('p.start_date')->orWhere('p.start_date', '<=', now()->toDateString());
            })
            ->orderByDesc('p.created_at')
            ->select(array_merge(self::POSTING_PUBLIC, [DB::raw('d.department as department_name')]));
    }

    protected function presentPosting($p, bool $full = false): array
    {
        $row = [
            'id'              => (int) $p->id,
            'title'           => $p->title,
            'department'      => $p->department_name,
            'location'        => $p->location,
            'employment_type' => $p->employment_type,
            'work_mode'       => $p->work_mode,
            'experience'      => $p->experience,
            'positions'       => $p->positions !== null ? (int) $p->positions : null,
            'opens_on'        => $p->start_date,
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
