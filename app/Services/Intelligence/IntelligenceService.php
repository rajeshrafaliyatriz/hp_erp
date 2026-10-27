<?php

namespace App\Services\Intelligence;

use App\Services\Dashboard\WorkforceMetricsService;
use App\Support\Competency\CertificationCompliance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE INTELLIGENCE OVERVIEW: what needs attention, why, on what evidence, and what to do.
 *
 * ── WHAT THIS IS, AND IS NOT ────────────────────────────────────────────────
 *
 * It is a READ-ONLY layer over data and rules that already exist. It writes nothing, schedules nothing, sends
 * nothing and calls no AI model. Every figure is a count or a comparison computed on request from the tenant's own
 * rows; nothing is stored, so nothing can go stale or contradict its source.
 *
 * It owns no business rule. Where a rule already exists it is CALLED, not copied:
 *
 *   who is an active employee ........ WorkforceMetricsService::activeEmployeeQuery() ("do not inline this rule")
 *   is a certificate compliant ....... CertificationCompliance::state() / ::daysToExpiry()
 *   what is an open capability gap ... capability_course_requests.status = 'open', exactly as the Gap Inbox lists it
 *
 * ── HONESTY RULES ───────────────────────────────────────────────────────────
 *
 *   - An empty denominator is "not_measurable", never 0% and never "healthy".
 *   - A source that fails is "unavailable" for that one item; the rest of the overview still answers. The exception
 *     message is logged, never returned (it carries SQL and table names).
 *   - Nothing here predicts. A "likely" or "will" claim is not made; an impact line states only what the existing
 *     product behaviour guarantees (for example that one course decision resolves every person on a gap).
 *   - Each insight carries a `class`: `measured` (a direct count) or `calculated` (a deterministic rule over rows).
 *     There is no AI-worded or inferred class here because nothing in this file is either.
 *
 * ── TENANCY ─────────────────────────────────────────────────────────────────
 *
 * Every query is bound to $sid, which the controller takes from the token owner (never from the request). The
 * service does not look at a request at all.
 */
final class IntelligenceService
{
    /** How many gap rows / certificates / departments to show as evidence. The totals are always complete. */
    private const EVIDENCE_ROWS = 5;

    private const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];

    /** Sources that threw during the current overview() call, by human-readable name. */
    private array $unavailable = [];

    public function __construct(private WorkforceMetricsService $workforce)
    {
    }

    /**
     * @param  array<string,string|null>  $links  link key => path, or null when this tenant has no such screen
     * @return array<string,mixed>
     */
    public function overview(int $sid, array $links = []): array
    {
        $this->unavailable = [];
        $measures = $this->readinessMeasures($sid);

        $insights = array_values(array_filter([
            $this->gapInsight($sid),
            $this->certificationInsight($sid),
            $this->readinessInsight($measures),
        ]));

        // Attach the destination. A key the tenant has no menu row for resolves to null and the client renders the
        // link disabled with this reason, instead of a dead link.
        $insights = array_map(function (array $i) use ($links) {
            $key = $i['link_key'] ?? null;
            $i['link'] = $key !== null ? ($links[$key] ?? null) : null;
            $i['link_reason'] = $key !== null && ($links[$key] ?? null) === null
                ? 'This screen is not available to your organisation.'
                : null;

            return $i;
        }, $insights);

        usort($insights, fn ($a, $b) => (self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']])
            ?: strcmp($a['key'], $b['key']));

        $measures = array_map(function (array $m) use ($links) {
            $key = $m['link_key'] ?? null;
            $m['link'] = $key !== null ? ($links[$key] ?? null) : null;

            return $m;
        }, $measures);

        $bySeverity = array_count_values(array_column($insights, 'severity'));

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'insight_count' => count($insights),
                'high' => $bySeverity['high'] ?? 0,
                'medium' => $bySeverity['medium'] ?? 0,
                'low' => $bySeverity['low'] ?? 0,
                'measures_needing_attention' => count(array_filter($measures, fn ($m) => $m['state'] === 'attention')),
                'measures_unavailable' => count(array_filter($measures, fn ($m) => $m['state'] === 'unavailable')),
                'sources_unavailable' => count(array_unique($this->unavailable)),
            ],
            'insights' => $insights,
            'readiness' => $measures,
            // A source that failed is listed here so its absence from `insights` is never read as "all clear".
            'unavailable_sources' => array_values(array_unique($this->unavailable)),
            'limits' => [
                'Counts come from this organisation\'s own records at the time of the request; nothing is stored or estimated.',
                'Certificate risk uses the same 60-day rule as the Certifications screen, so it can differ from the 30-day count on the Home Action Center.',
                'Capability coverage by role and "mandatory competency unmeasured" are not shown yet: they need a role-to-employee link that is not reliable enough to count.',
                'Sales, hiring, attendance and task indicators are not included: they belong to other audiences or are not yet reliable enough to rank.',
            ],
        ];
    }

    // ── readiness measures (measured) ───────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function readinessMeasures(int $sid): array
    {
        $defs = [
            [
                'key' => 'roles_mapped',
                'label' => 'Job roles with competencies assigned',
                'explanation' => 'Without a role-to-competency mapping the product cannot say what a role requires, so no gap can be found for it.',
                'link_key' => 'competency_framework',
                'calc' => function () use ($sid) {
                    $total = (int) DB::table('s_user_jobrole')->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();
                    $complete = (int) DB::table('s_user_jobrole as r')
                        ->where('r.sub_institute_id', $sid)->whereNull('r.deleted_at')
                        ->whereExists(function ($q) use ($sid) {
                            $q->select(DB::raw(1))->from('jobrole_competency_map as m')
                                ->whereColumn('m.jobrole_id', 'r.id')->where('m.sub_institute_id', $sid);
                        })->count();

                    return [$complete, $total];
                },
            ],
            [
                'key' => 'employees_rated',
                'label' => 'Active employees with at least one competency rating',
                'explanation' => 'An unrated employee is "unmeasured", which is neither a pass nor a gap. Ratings are what turn the mapping into gap findings.',
                'link_key' => 'employee_directory',
                'calc' => function () use ($sid) {
                    $total = (int) $this->workforce->activeEmployeeQuery($sid)->count();
                    $complete = (int) $this->workforce->activeEmployeeQuery($sid)
                        ->whereExists(function ($q) use ($sid) {
                            $q->select(DB::raw(1))->from('competency_kasba_rating as k')
                                ->whereColumn('k.user_id', 'tbluser.id')->where('k.sub_institute_id', $sid);
                        })->count();

                    return [$complete, $total];
                },
            ],
            [
                'key' => 'managers_assigned',
                'label' => 'Active employees with a reporting manager',
                'explanation' => 'Team views and approval routing rely on the reporting line.',
                'link_key' => 'employee_directory',
                'calc' => function () use ($sid) {
                    $total = (int) $this->workforce->activeEmployeeQuery($sid)->count();
                    $complete = (int) $this->workforce->activeEmployeeQuery($sid)->whereNotNull('tbluser.reporting_manager_id')->count();

                    return [$complete, $total];
                },
            ],
            [
                'key' => 'assessments_with_employee',
                'label' => 'Assessments linked to an employee',
                'explanation' => 'An assessment with no employee cannot produce a rating for anyone.',
                'link_key' => 'assessments',
                'calc' => function () use ($sid) {
                    $base = fn () => DB::table('s_competency_assessments')->where('sub_institute_id', $sid)->whereNull('deleted_at');

                    return [(int) $base()->whereNotNull('user_id')->count(), (int) $base()->count()];
                },
            ],
            [
                'key' => 'courses_linked',
                'label' => 'Active courses linked to a competency',
                'explanation' => 'A course with no competency link cannot be offered as the answer to a capability gap.',
                'link_key' => 'course_builder',
                'calc' => function () use ($sid) {
                    $base = fn () => DB::table('sub_std_map as s')->where('s.sub_institute_id', $sid)->whereNull('s.deleted_at')->where('s.status', 1);
                    $complete = (int) $base()->whereExists(function ($q) use ($sid) {
                        $q->select(DB::raw(1))->from('course_competency_map as c')
                            ->whereColumn('c.course_id', 's.id')->where('c.sub_institute_id', $sid);
                    })->count();

                    return [$complete, (int) $base()->count()];
                },
            ],
            [
                'key' => 'certificate_status_current',
                'label' => 'Certificates whose stored status matches their expiry date',
                'explanation' => 'A certificate can be past its expiry date while its stored status still says valid. The Intelligence verdicts use the date, so they stay correct, but other screens that read the status label may not.',
                'link_key' => 'certifications',
                'calc' => function () use ($sid) {
                    $base = fn () => DB::table('s_competency_certifications')->where('sub_institute_id', $sid)->whereNull('deleted_at');
                    $total = (int) $base()->count();
                    $stale = (int) $base()->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString())
                        ->whereNotIn('status', ['expired', 'revoked'])->count();

                    return [$total - $stale, $total];
                },
            ],
        ];

        $out = [];
        foreach ($defs as $d) {
            $result = $this->guard('readiness.' . $d['key'], $sid, $d['calc']);
            $base = [
                'key' => $d['key'], 'label' => $d['label'], 'explanation' => $d['explanation'],
                'link_key' => $d['link_key'], 'class' => 'measured',
            ];

            if ($result === null) {
                $out[] = $base + ['complete' => null, 'total' => null, 'percent' => null, 'state' => 'unavailable'];
                continue;
            }

            [$complete, $total] = $result;
            $out[] = $base + [
                'complete' => $complete,
                'total' => $total,
                // 0 of 0 is "not measurable", not 0% and not 100%.
                'percent' => $total > 0 ? round(100 * $complete / $total, 1) : null,
                'state' => $total === 0 ? 'not_measurable' : ($complete === $total ? 'ok' : 'attention'),
            ];
        }

        return $out;
    }

    /** One roll-up insight over the measures, so the page leads with "can I trust the numbers". */
    private function readinessInsight(array $measures): ?array
    {
        $attention = array_values(array_filter($measures, fn ($m) => $m['state'] === 'attention'));
        if ($attention === []) {
            return null;
        }

        $rolesMapped = collect($measures)->firstWhere('key', 'roles_mapped');
        // The one case that blocks the product's core loop outright: roles exist but none has any competency.
        $blocked = $rolesMapped && $rolesMapped['state'] === 'attention' && ($rolesMapped['complete'] ?? 0) === 0;

        $lines = array_map(
            fn ($m) => ['label' => $m['label'], 'value' => $m['complete'] . ' of ' . $m['total'] . ' (' . $m['percent'] . '%)'],
            $attention
        );

        return [
            'key' => 'data_readiness',
            'area' => 'Data readiness',
            'class' => 'measured',
            'severity' => $blocked ? 'high' : 'medium',
            'title' => count($attention) . ' of ' . count($measures) . ' data-readiness checks are incomplete',
            'detected' => $blocked
                ? 'Job roles exist but none has a competency assigned, so required levels cannot be determined for any role.'
                : 'Some records the capability analysis depends on are missing links or owners.',
            'why_it_matters' => 'Gap, compliance and learning findings are only as complete as these links. Missing links show up as "unmeasured", not as a problem, so they are easy to overlook.',
            'evidence' => ['metrics' => $lines, 'records' => []],
            'recommended_action' => 'Work through the incomplete checks below, starting with role-to-competency mapping, then ratings.',
            'expected_impact' => 'Each completed link lets more employees and roles be included in gap and compliance findings. The size of that change is not estimated here.',
            'link_key' => $blocked ? 'competency_framework' : ($attention[0]['link_key'] ?? null),
        ];
    }

    // ── capability gaps (calculated) ────────────────────────────────────────

    private function gapInsight(int $sid): ?array
    {
        return $this->guard('capability gaps', $sid, function () use ($sid) {
            $open = fn () => DB::table('capability_course_requests as r')
                ->where('r.sub_institute_id', $sid)->whereNull('r.deleted_at')->where('r.status', 'open');

            $openCount = (int) $open()->count();
            if ($openCount === 0) {
                return null;
            }

            $mandatory = (int) $open()->where('r.is_mandatory', 1)->count();
            $people = (int) DB::table('capability_course_request_subjects as s')
                ->join('capability_course_requests as r', 'r.id', '=', 's.request_id')
                ->where('r.sub_institute_id', $sid)->where('s.sub_institute_id', $sid)
                ->whereNull('r.deleted_at')->where('r.status', 'open')
                ->distinct()->count('s.user_id');

            // Mandatory first, then the most people affected, then the longest-waiting: the order is stated on the
            // page so nobody has to guess why one gap outranks another.
            $top = $open()
                ->leftJoin('s_user_jobrole as jr', function ($j) use ($sid) {
                    $j->on('jr.id', '=', 'r.jobrole_id')->where('jr.sub_institute_id', '=', $sid);
                })
                ->orderByDesc('r.is_mandatory')->orderByDesc('r.affected_count')->orderBy('r.first_raised_at')
                ->limit(self::EVIDENCE_ROWS)
                ->get(['r.id', 'r.competency_name', 'r.required_proficiency', 'r.is_mandatory', 'r.affected_count',
                    'r.first_raised_at', 'jr.jobrole as jobrole_name']);

            $avgGap = DB::table('capability_course_request_subjects')
                ->where('sub_institute_id', $sid)->whereIn('request_id', $top->pluck('id')->all())
                ->groupBy('request_id')->selectRaw('request_id, AVG(gap) as avg_gap')
                ->pluck('avg_gap', 'request_id');

            $records = $top->map(fn ($r) => [
                'competency' => $r->competency_name ?: 'Unnamed competency',
                'role' => $r->jobrole_name,
                'mandatory' => (bool) $r->is_mandatory,
                'required_level' => $r->required_proficiency !== null ? (int) $r->required_proficiency : null,
                'people_affected' => (int) $r->affected_count,
                'average_shortfall' => isset($avgGap[$r->id]) ? round((float) $avgGap[$r->id], 1) : null,
                'days_waiting' => $r->first_raised_at ? (int) round(abs(Carbon::parse($r->first_raised_at)->startOfDay()->diffInDays(now()->startOfDay()))) : null,
            ])->all();

            $first = $records[0];

            return [
                'key' => 'capability_gaps',
                'area' => 'Skills and competency gaps',
                'class' => 'calculated',
                'severity' => $mandatory > 0 ? 'high' : 'medium',
                'title' => $openCount . ($openCount === 1 ? ' capability gap is' : ' capability gaps are') . ' waiting for a course decision',
                'detected' => $openCount . ' open ' . ($openCount === 1 ? 'gap' : 'gaps') . ' (' . $mandatory . ' mandatory) affecting '
                    . $people . ' ' . ($people === 1 ? 'person' : 'people') . '. Highest priority: ' . $first['competency']
                    . ($first['role'] ? ' for ' . $first['role'] : '') . ', ' . $first['people_affected'] . ' affected.',
                'why_it_matters' => 'People on these gaps are below the level their role requires and no course has been mapped or created to close the gap.',
                'evidence' => [
                    'metrics' => [
                        ['label' => 'Open gaps', 'value' => (string) $openCount],
                        ['label' => 'Mandatory', 'value' => (string) $mandatory],
                        ['label' => 'People affected', 'value' => (string) $people],
                    ],
                    'records' => $records,
                    'ranking' => 'Ordered by mandatory first, then people affected, then time waiting.',
                ],
                'recommended_action' => 'Open the Capability Gap Inbox and map an existing course, or create one, for the top gap.',
                'expected_impact' => 'One course decision resolves everyone on that gap at once, which is how the inbox is built. Top gap: ' . $first['people_affected'] . ' ' . ($first['people_affected'] === 1 ? 'person' : 'people') . '.',
                'link_key' => 'gap_inbox',
            ];
        });
    }

    // ── certification risk (calculated) ─────────────────────────────────────

    private function certificationInsight(int $sid): ?array
    {
        return $this->guard('certification risk', $sid, function () use ($sid) {
            $departments = DB::table('hrms_departments')->where('sub_institute_id', $sid)->pluck('department', 'id');

            $counts = ['non_compliant' => 0, 'revoked' => 0, 'expiring' => 0, 'compliant' => 0];
            $byDept = [];
            $soonest = [];
            $affectedPeople = [];

            DB::table('s_competency_certifications')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->select('id', 'name', 'user_id', 'department_id', 'status', 'expiry_date')
                ->chunkById(500, function ($rows) use (&$counts, &$byDept, &$soonest, &$affectedPeople, $departments) {
                    foreach ($rows as $row) {
                        $state = CertificationCompliance::state($row)['key'];
                        $counts[$state === 'non_compliant' && $row->status === 'revoked' ? 'revoked' : $state]++;
                        if ($state === 'compliant') {
                            continue;
                        }
                        $dept = ($row->department_id && isset($departments[$row->department_id])) ? $departments[$row->department_id] : 'No department recorded';
                        $byDept[$dept][$state] = ($byDept[$dept][$state] ?? 0) + 1;
                        if ($row->user_id) {
                            $affectedPeople[$row->user_id] = true;
                        }
                        if ($state === 'expiring') {
                            $soonest[] = ['certificate' => $row->name, 'expires' => CertificationCompliance::humanDate($row->expiry_date),
                                'days' => CertificationCompliance::daysToExpiry($row->expiry_date), 'department' => $dept];
                        }
                    }
                });

            $total = array_sum($counts);
            $atRisk = $counts['non_compliant'] + $counts['revoked'] + $counts['expiring'];
            if ($total === 0 || $atRisk === 0) {
                return null;
            }

            usort($soonest, fn ($a, $b) => $a['days'] <=> $b['days']);
            $deptRows = [];
            foreach ($byDept as $name => $c) {
                $deptRows[] = ['department' => $name, 'expired_or_revoked' => $c['non_compliant'] ?? 0, 'expiring' => $c['expiring'] ?? 0];
            }
            usort($deptRows, fn ($a, $b) => ($b['expired_or_revoked'] + $b['expiring']) <=> ($a['expired_or_revoked'] + $a['expiring']));

            $window = CertificationCompliance::EXPIRING_WINDOW_DAYS;
            $lapsed = $counts['non_compliant'] + $counts['revoked'];

            return [
                'key' => 'certification_risk',
                'area' => 'Certification and compliance',
                'class' => 'calculated',
                'severity' => $lapsed > 0 ? 'high' : 'medium',
                'title' => $atRisk . ' of ' . $total . ' certificates are expired, revoked or expiring within ' . $window . ' days',
                'detected' => $lapsed . ' expired or revoked and ' . $counts['expiring'] . ' expiring within ' . $window . ' days'
                    . (count($affectedPeople) > 0 ? ', involving ' . count($affectedPeople) . ' ' . (count($affectedPeople) === 1 ? 'employee' : 'employees') : '') . '.',
                'why_it_matters' => 'An expired or revoked credential is non-compliant even if the stored status still says valid; credentials about to expire become non-compliant on their expiry date unless renewed.',
                'evidence' => [
                    'metrics' => [
                        ['label' => 'Expired', 'value' => (string) $counts['non_compliant']],
                        ['label' => 'Revoked', 'value' => (string) $counts['revoked']],
                        ['label' => 'Expiring within ' . $window . ' days', 'value' => (string) $counts['expiring']],
                        ['label' => 'Compliant', 'value' => (string) $counts['compliant']],
                    ],
                    'records' => array_slice($soonest, 0, self::EVIDENCE_ROWS),
                    'by_department' => array_slice($deptRows, 0, self::EVIDENCE_ROWS),
                    'ranking' => 'Soonest expiry first. Verdicts use CertificationCompliance, the same rule as the Certifications screen.',
                ],
                'recommended_action' => 'Open Certifications, filter to Expiring Soon, and arrange renewals for the earliest dates; review expired credentials with their holders.',
                'expected_impact' => $counts['expiring'] > 0
                    ? $counts['expiring'] . ' ' . ($counts['expiring'] === 1 ? 'credential stays' : 'credentials stay') . ' compliant if renewed before ' . ($soonest[0]['expires'] ?? 'its expiry date') . ' (the earliest).'
                    : 'Expired credentials return to compliant once renewed or replaced.',
                'link_key' => 'certifications',
            ];
        });
    }

    // ── shared ──────────────────────────────────────────────────────────────

    /**
     * Run one source; on failure return null for that source only.
     *
     * The message is logged here and NEVER returned to the client: it would carry SQL fragments and table names.
     */
    private function guard(string $name, int $sid, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // Readiness measures report their own "unavailable" state on their own row; only insight sources are listed.
            if (!str_starts_with($name, 'readiness.')) {
                $this->unavailable[] = $name;
            }
            Log::warning('intelligence.' . $name . ' failed', ['sub_institute_id' => $sid, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
